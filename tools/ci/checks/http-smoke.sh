# This extension's own HTTP checks, sourced by tools/ci/http-smoke.sh with
# BASE, ENDPOINT, ALT_ENDPOINT, ITOP_TOKEN and fail() in scope.
#
# Plays an OAuth client against the installed module: discovery, registration
# and what the token and authorize endpoints refuse without anyone signed in.
# With ITOP_ADMIN_USER and ITOP_ADMIN_PWD set it then goes through the whole
# flow - login, consent, code exchange, a REST call with the minted token,
# refresh in place (ADR 0004), the refresh grace period - first as a public
# client registered by itself, then as the confidential client
# checks/module-smoke.php created, passed in ITOP_TOKEN as "<client_id>:<secret>":
# no PKCE, no resource parameter, the secret in a Basic header.
#
# PHP's built-in server ignores .htaccess, so the discovery rewrite is not
# exercised here: index.php?route=... is.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

CALLBACK='https://claude.ai/api/mcp/auth_callback'
REST="$BASE/webservices/rest.php"
JAR=$(mktemp)

json_field() { php -r '$a = json_decode(stream_get_contents(STDIN), true); echo is_array($a) ? ($a[$argv[1]] ?? "") : "";' "$1"; }
b64url() { base64 -w0 | tr '+/' '-_' | tr -d '='; }
expect() { # <what> <haystack> <needle>
	case "$2" in *"$3"*) echo "   ok  $1" ;; *) rm -f "$JAR"; fail "$1: expected $3 in: ${2:0:300}" ;; esac
}
rest_get() {
	curl -sS -X POST -H "Auth-Token: $1" --data-urlencode 'version=1.3' \
		--data-urlencode 'json_data={"operation":"core/get","class":"Organization","key":"SELECT Organization","output_fields":"name","limit":"1"}' "$REST"
}
token() { curl -sS -w '\n%{http_code}' "$@" "$ENDPOINT?route=token"; }

sMeta=$(curl -sS "$ENDPOINT?route=openid-configuration")
expect "server metadata names its issuer" "$sMeta" "\"issuer\":\"$BASE/env-production/altioo-oauth-server\""
expect "server metadata requires S256" "$sMeta" '"code_challenge_methods_supported":["S256"]'
expect "server metadata takes public and confidential clients" "$sMeta" '"token_endpoint_auth_methods_supported":["none","client_secret_basic","client_secret_post"]'

sPrm=$(curl -sS "$ENDPOINT?route=protected-resource&for=rest")
expect "REST/JSON resource metadata" "$sPrm" "\"resource\":\"$REST\""
sResource=$(printf '%s' "$sPrm" | json_field resource)

sReg=$(curl -sS -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' \
	-d '{"client_name":"Smoke","redirect_uris":["https://evil.example/cb"]}' "$ENDPOINT?route=register")
expect "a redirect URI off the allowlist is refused" "$sReg" 'invalid_redirect_uri'
sReg=$(curl -sS -X POST -H 'Content-Type: application/json' \
	-d "{\"client_name\":\"Smoke\",\"redirect_uris\":[\"$CALLBACK\"],\"token_endpoint_auth_method\":\"none\"}" "$ENDPOINT?route=register")
sClient=$(printf '%s' "$sReg" | json_field client_id)
[ -n "$sClient" ] || fail "registration returned no client_id: $sReg"
echo "   ok  a client on the allowlist registers"

sOut=$(token -d grant_type=authorization_code -d code=1.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa -d "client_id=$sClient")
expect "an unknown code is invalid_grant" "$sOut" '"error":"invalid_grant"'
sOut=$(token -d grant_type=refresh_token -d refresh_token=garbage -d "client_id=$sClient")
expect "an unknown refresh token is invalid_grant" "$sOut" '"error":"invalid_grant"'
sOut=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' -G "$ENDPOINT" --data-urlencode route=authorize \
	--data-urlencode client_id=0123456789abcdef0123456789abcdef --data-urlencode redirect_uri=https://evil.example/cb)
expect "authorize refuses an unknown client on a page, not a redirect" "$sOut" '400 '
[ "${sOut#400 }" = "" ] || fail "authorize redirected an unknown client to: ${sOut#400 }"

if [ -z "${ITOP_ADMIN_USER:-}" ] || [ -z "${ITOP_ADMIN_PWD:-}" ]; then
	echo "   --  no ITOP_ADMIN_USER / ITOP_ADMIN_PWD: the signed-in flow is not exercised"
	rm -f "$JAR"
	return 0
fi

sVerifier=$(head -c 48 /dev/urandom | b64url)
sChallenge=$(printf '%s' "$sVerifier" | openssl dgst -sha256 -binary | b64url)
authorize() {
	curl -sS -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" -G "$ENDPOINT" \
		--data-urlencode route=authorize --data-urlencode response_type=code \
		--data-urlencode "client_id=$sClient" --data-urlencode "redirect_uri=$CALLBACK" \
		--data-urlencode "code_challenge=$sChallenge" --data-urlencode code_challenge_method=S256 \
		--data-urlencode state=smoke --data-urlencode "resource=$sResource"
}
allow() { # <consent url> <consent page>
	local sTransaction
	sTransaction=$(printf '%s' "$2" | sed -n 's/.*name="transaction_id" value="\([^"]*\)".*/\1/p')
	curl -sS -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" \
		--data-urlencode "transaction_id=$sTransaction" --data-urlencode decision=allow \
		--data-urlencode 'scopes[]=REST/JSON' "$1"
}

sLoc=$(authorize)
curl -sS -o /dev/null -c "$JAR" -b "$JAR" "$sLoc"
sConsent=$(curl -sS -c "$JAR" -b "$JAR" --data-urlencode loginop=login \
	--data-urlencode "auth_user=$ITOP_ADMIN_USER" --data-urlencode "auth_pwd=$ITOP_ADMIN_PWD" "$sLoc")
expect "the consent page shows where it returns" "$sConsent" 'claude.ai'
sBack=$(allow "$sLoc" "$sConsent")
expect "consent returns to the client with a code" "$sBack" "$CALLBACK?code="
expect "consent returns the state" "$sBack" '&state=smoke&'
expect "consent names the issuer (RFC 9207)" "$sBack" '&iss='
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')

sOut=$(token -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=x$sVerifier" --data-urlencode "resource=$sResource")
expect "a wrong PKCE verifier is invalid_grant" "$sOut" '"error":"invalid_grant"'

sLoc=$(authorize)
sBack=$(allow "$sLoc" "$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")")
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
sTok=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" --data-urlencode "resource=$sResource" \
	"$ENDPOINT?route=token")
sAccess1=$(printf '%s' "$sTok" | json_field access_token)
sRefresh1=$(printf '%s' "$sTok" | json_field refresh_token)
[ -n "$sAccess1" ] && [ -n "$sRefresh1" ] || fail "the code exchange issued no tokens: ${sTok:0:300}"
echo "   ok  the code exchange issues an access and a refresh token"
expect "the minted token works on REST/JSON" "$(rest_get "$sAccess1")" '"code":0'

sOut=$(token -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier")
expect "a code is single use" "$sOut" '"error":"invalid_grant"'
expect "the old access token died with its code's second use" "$(rest_get "$sAccess1")" '"code":1'

# The second use above ended that grant; start a third connection for the refresh checks.
sLoc=$(authorize)
sBack=$(allow "$sLoc" "$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")")
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
sTok=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" "$ENDPOINT?route=token")
sAccess1=$(printf '%s' "$sTok" | json_field access_token)
sRefresh1=$(printf '%s' "$sTok" | json_field refresh_token)

sTok=$(curl -sS -d grant_type=refresh_token --data-urlencode "refresh_token=$sRefresh1" -d "client_id=$sClient" "$ENDPOINT?route=token")
sAccess2=$(printf '%s' "$sTok" | json_field access_token)
sRefresh2=$(printf '%s' "$sTok" | json_field refresh_token)
[ -n "$sAccess2" ] && [ "$sRefresh2" != "$sRefresh1" ] || fail "refresh issued no new pair: ${sTok:0:300}"
expect "after a refresh the old access token is refused" "$(rest_get "$sAccess1")" '"code":1'
expect "after a refresh the new access token works" "$(rest_get "$sAccess2")" '"code":0'

sTok=$(curl -sS -d grant_type=refresh_token --data-urlencode "refresh_token=$sRefresh1" -d "client_id=$sClient" "$ENDPOINT?route=token")
expect "within the grace period the previous refresh token is accepted once more" "$sTok" '"access_token"'
sAccess3=$(printf '%s' "$sTok" | json_field access_token)
sRefresh3=$(printf '%s' "$sTok" | json_field refresh_token)
expect "the token renewed in the grace period works" "$(rest_get "$sAccess3")" '"code":0'

if [ -z "$ITOP_TOKEN" ] || [ "${ITOP_TOKEN#*:}" = "$ITOP_TOKEN" ]; then
	rm -f "$JAR"
	fail "module-smoke.php passed no confidential client"
fi
sCcId=${ITOP_TOKEN%%:*}
sCcSecret=${ITOP_TOKEN#*:}
CC_CALLBACK='https://smoke.example/callback'

# Signed in already, through the jar: the authorize request lands on the consent page.
sLoc=$(curl -sS -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" -G "$ENDPOINT" \
	--data-urlencode route=authorize --data-urlencode response_type=code \
	--data-urlencode "client_id=$sCcId" --data-urlencode "redirect_uri=$CC_CALLBACK" \
	--data-urlencode 'scope=REST/JSON' --data-urlencode state=classic)
sConsent=$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")
expect "a confidential client without PKCE or resource reaches consent" "$sConsent" 'smoke.example'
sBack=$(allow "$sLoc" "$sConsent")
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
[ -n "$sCode" ] || fail "the confidential client got no code: $sBack"

sOut=$(token -u "$sCcId:wrong" -d grant_type=authorization_code --data-urlencode "code=$sCode" --data-urlencode "redirect_uri=$CC_CALLBACK")
expect "a wrong client secret is invalid_client" "$sOut" '"error":"invalid_client"'
expect "a wrong client secret is a 401" "$sOut" '401'

# The wrong secret above was refused before the code was looked at, so it is still unused.
sTok=$(curl -sS -u "$sCcId:$sCcSecret" -d grant_type=authorization_code --data-urlencode "code=$sCode" \
	--data-urlencode "redirect_uri=$CC_CALLBACK" "$ENDPOINT?route=token")
sAccess1=$(printf '%s' "$sTok" | json_field access_token)
sRefresh1=$(printf '%s' "$sTok" | json_field refresh_token)
[ -n "$sAccess1" ] && [ -n "$sRefresh1" ] || fail "client_secret_basic issued no tokens: ${sTok:0:300}"
echo "   ok  client_secret_basic exchanges a code without PKCE"
expect "the classic client's token works on its default resource" "$(rest_get "$sAccess1")" '"code":0'

sOut=$(token -d grant_type=refresh_token --data-urlencode "refresh_token=$sRefresh1" -d "client_id=$sCcId")
expect "a confidential client cannot refresh without its secret" "$sOut" '"error":"invalid_client"'
sTok=$(curl -sS -d grant_type=refresh_token --data-urlencode "refresh_token=$sRefresh1" \
	-d "client_id=$sCcId" --data-urlencode "client_secret=$sCcSecret" "$ENDPOINT?route=token")
expect "client_secret_post refreshes too" "$sTok" '"refresh_token"'

# The person disconnects from My Account. The form there posts a transaction id of their
# session; any page of the session hands one out, and an unanswered consent page is one.
sLoc=$(authorize)
sTransaction=$(curl -sS -c "$JAR" -b "$JAR" "$sLoc" | sed -n 's/.*name="transaction_id" value="\([^"]*\)".*/\1/p')
sGrant=${sRefresh3%%.*}
curl -sS -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "transaction_id=$sTransaction" \
	--data-urlencode "grant_id=$sGrant" "$ENDPOINT?route=disconnect"
expect "after Disconnect the connection's token is refused" "$(rest_get "$sAccess3")" '"code":1'
sOut=$(token -d grant_type=refresh_token --data-urlencode "refresh_token=$sRefresh3" -d "client_id=$sClient")
expect "after Disconnect its current refresh token is invalid_grant" "$sOut" '"error":"invalid_grant"'

rm -f "$JAR"
