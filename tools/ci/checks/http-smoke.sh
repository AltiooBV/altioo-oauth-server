# This extension's own HTTP checks, sourced by tools/ci/http-smoke.sh with
# BASE, ENDPOINT, ALT_ENDPOINT, ITOP_TOKEN and fail() in scope.
#
# Plays an OAuth client against the installed module: discovery, registration
# and what the token and authorize endpoints refuse without anyone signed in, among it the
# registration rate limit, tripped from a loopback address of its own with the limit lowered,
# and the sessions that anonymous callers leave behind.
# With ITOP_ADMIN_USER and ITOP_ADMIN_PWD set it then goes through the whole
# flow - login, consent, code exchange, a REST call with the minted token,
# refresh in place (ADR 0004), the refresh grace period, revocation - first as a public
# client registered by itself, then as the confidential client
# checks/module-smoke.php created: no PKCE, no resource parameter, the secret in a
# Basic header. ITOP_TOKEN carries that client and the other fixtures module-smoke.php
# made, as "<client_id>:<secret>:<old refresh token>:<login>:<password>:<portal-era refresh token>:<withdrawn client_id>".
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
# ADR 0005: no client ID metadata documents in 0.1.0, so never tell a client to use one.
case "$sMeta" in *client_id_metadata_document_supported*) rm -f "$JAR"; fail "server metadata advertises client ID metadata documents, which nothing implements (ADR 0005)" ;; esac
echo "   ok  server metadata does not advertise client ID metadata documents"

sPrm=$(curl -sS "$ENDPOINT?route=protected-resource&for=rest")
expect "REST/JSON resource metadata" "$sPrm" "\"resource\":\"$REST\""
expect "and it names no bearer method, since rest.php reads Auth-Token" "$sPrm" '"bearer_methods_supported":[]'
sResource=$(printf '%s' "$sPrm" | json_field resource)

sReg=$(curl -sS -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' \
	-d '{"client_name":"Smoke","redirect_uris":["https://evil.example/cb"]}' "$ENDPOINT?route=register")
expect "a redirect URI off the allowlist is refused" "$sReg" 'invalid_redirect_uri'
expect "the refusal names the URI, so an administrator can allow it" "$sReg" 'https://evil.example/cb'
sReg=$(curl -sS -X POST -H 'Content-Type: application/json' \
	-d '{"client_name":"ChatGPT","redirect_uris":["https://chatgpt.com/connector/oauth/smoke_123"]}' "$ENDPOINT?route=register")
expect "ChatGPT's per-integration callback registers" "$sReg" '"client_id"'
# Claude registers as a confidential client: it asks for a secret and must get one.
sReg=$(curl -sS -X POST -H 'Content-Type: application/json' \
	-d "{\"client_name\":\"Claude\",\"redirect_uris\":[\"$CALLBACK\"],\"token_endpoint_auth_method\":\"client_secret_post\"}" "$ENDPOINT?route=register")
expect "a client asking for a secret gets one" "$sReg" '"client_secret":"'
expect "and is registered with the method it asked for" "$sReg" '"token_endpoint_auth_method":"client_secret_post"'
sReg=$(curl -sS -X POST -H 'Content-Type: application/json' \
	-d "{\"client_name\":\"Smoke\",\"redirect_uris\":[\"$CALLBACK\"],\"token_endpoint_auth_method\":\"none\"}" "$ENDPOINT?route=register")
sClient=$(printf '%s' "$sReg" | json_field client_id)
[ -n "$sClient" ] || fail "registration returned no client_id: $sReg"
echo "   ok  a client on the allowlist registers"

# A second public client, to stand for another caller at the token endpoint below.
sClient2=$(curl -sS -X POST -H 'Content-Type: application/json' \
	-d "{\"client_name\":\"Other\",\"redirect_uris\":[\"$CALLBACK\"],\"token_endpoint_auth_method\":\"none\"}" "$ENDPOINT?route=register" | json_field client_id)
[ -n "$sClient2" ] || fail "the second registration returned no client_id"

sOut=$(token -d grant_type=authorization_code -d code=1.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa -d "client_id=$sClient")
expect "an unknown code is invalid_grant" "$sOut" '"error":"invalid_grant"'
sOut=$(token -d grant_type=refresh_token -d refresh_token=garbage -d "client_id=$sClient")
expect "an unknown refresh token is invalid_grant" "$sOut" '"error":"invalid_grant"'
sOut=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' -G "$ENDPOINT" --data-urlencode route=authorize \
	--data-urlencode client_id=0123456789abcdef0123456789abcdef --data-urlencode redirect_uri=https://evil.example/cb)
expect "authorize refuses an unknown client on a page, not a redirect" "$sOut" '400 '
[ "${sOut#400 }" = "" ] || fail "authorize redirected an unknown client to: ${sOut#400 }"

# The registration rate limit (README: registration_rate_limit, 300 an hour per address). The window
# is fixed at an hour in RegistrationController and only the count is a setting, so waiting is out
# and the count is lowered instead: through the instance's config file, put back below, because
# the instance outlives this script (the integration loop reuses it) and its defaults are checked
# by module-smoke.php. The caller is a loopback address of this run's own - the whole 127/8 block
# is local on Linux, and php -S reports the peer's address as REMOTE_ADDR - so neither the
# registrations above nor an earlier run within the hour count against it, and a second address
# stands for everybody else.
RATE_LIMIT=5
CONF="$ITOP_DIR/conf/$ITOP_ENV/config-itop.php"
sRateAddr="127.$((RANDOM % 200 + 20)).$((RANDOM % 250 + 1)).$((RANDOM % 250 + 1))"
sOtherAddr="127.$((RANDOM % 200 + 20)).$((RANDOM % 250 + 1)).$((RANDOM % 250 + 1))"
register_from() { # <address> -> the headers, then the body, then the status
	curl -sS -D - -w '\n%{http_code}' --interface "$1" -X POST -H 'Content-Type: application/json' \
		-d "{\"client_name\":\"RateLimit\",\"redirect_uris\":[\"$CALLBACK\"],\"token_endpoint_auth_method\":\"none\"}" "$ENDPOINT?route=register"
}
[ -w "$CONF" ] || chmod u+w "$CONF" 2>/dev/null || true
cp -p "$CONF" "$CONF.smoke-orig"
php -r '$s = file_get_contents($argv[1]); $s = preg_replace("/\?>\s*\z/", "", $s);
	file_put_contents($argv[1], $s."\n\$MyModuleSettings[\"altioo-oauth-server\"][\"registration_rate_limit\"] = ".(int) $argv[2].";\n?>\n");' "$CONF" "$RATE_LIMIT"
sAccepted=0
for _ in $(seq 1 "$RATE_LIMIT"); do
	case "$(register_from "$sRateAddr")" in *$'\n201') sAccepted=$((sAccepted + 1)) ;; esac
done
sRefused=$(register_from "$sRateAddr")
sAgain=$(register_from "$sRateAddr")
sOther=$(register_from "$sOtherAddr")
mv -f "$CONF.smoke-orig" "$CONF"
[ "$sAccepted" -eq "$RATE_LIMIT" ] || { rm -f "$JAR"; fail "only $sAccepted of the first $RATE_LIMIT registrations from one address were accepted"; }
echo "   ok  $RATE_LIMIT registrations from one address are accepted"
expect "the one past the limit is a 429" "$sRefused" $'\n429'
expect "and an OAuth error body, not a page" "$sRefused" '"error":"invalid_request"'
expect "that says why and what to do" "$sRefused" 'too many registrations from this address; try again later'
expect "and is never cached" "$sRefused" 'Cache-Control: no-store'
# RFC 7591 does not ask for it and the controller sends none: said here so that a client author
# who looks for one is not surprised, and so that adding one later is a conscious change.
case "$sRefused" in
	*[Rr]etry-[Aa]fter:*) echo "   ok  the refusal carries a Retry-After" ;;
	*) echo "   --  the refusal carries no Retry-After: the client is told to try again later, not when" ;;
esac
expect "the refusal holds on a second try" "$sAgain" $'\n429'
expect "while another address registers right after" "$sOther" $'\n201'
expect "and gets its client" "$sOther" '"client_id"'

# What anonymous callers leave behind. iTop opens a session for every request, and nobody is
# signed in at register, authorize or token, so each call sets a cookie nobody sends back and
# leaves a session file nothing will ever read again (review/2026-11-03-red.md). The module
# deletes none of them - CleanUpProcess does not touch sessions - so the bound is PHP's own
# collector, and this checks the two halves of that on a server of its own, whose session
# directory is this script's: one file per call, never more, and gone again once PHP's
# collector runs. Whether a production host's PHP runs it at all (Debian's packages replace
# it with a cron job) is not something a CI instance can say.
SESSION_ROUNDS=10
sSessions=$(mktemp -d)
sSessPort=$((PORT + 10))
sSessEndpoint="http://${HOST}:${sSessPort}/env-${ITOP_ENV}/${MODULE_CODE}/index.php"
session_files() { find "$sSessions" -name 'sess_*' | wc -l; }
session_server() { # <ini>... - started like the main one, in a group of its own
	setsid env PHP_CLI_SERVER_WORKERS=4 php -S "${HOST}:${sSessPort}" -d session.save_path="$sSessions" "$@" -t "$ITOP_DIR" >>"$ITOP_DIR/ci-httpd-sessions.log" 2>&1 &
	iSessionServer=$!
	for _ in $(seq 1 30); do curl -sS -o /dev/null "http://${HOST}:${sSessPort}/index.php" 2>/dev/null && break; sleep 1; done
}
session_burst() {
	local i
	for i in $(seq 1 "$SESSION_ROUNDS"); do
		curl -sS -o /dev/null -X POST -H 'Content-Type: application/json' \
			-d "{\"client_name\":\"Sessions\",\"redirect_uris\":[\"$CALLBACK\"]}" "$sSessEndpoint?route=register"
		curl -sS -o /dev/null -d grant_type=refresh_token -d refresh_token=garbage -d "client_id=$sClient" "$sSessEndpoint?route=token"
		curl -sS -o /dev/null -G "$sSessEndpoint" --data-urlencode route=authorize \
			--data-urlencode client_id=0123456789abcdef0123456789abcdef --data-urlencode redirect_uri=https://evil.example/cb
	done
}
stop_session_server() { kill -TERM -"$iSessionServer" 2>/dev/null || true; wait "$iSessionServer" 2>/dev/null || true; }

# Collector off, so that what is counted is what the calls made.
session_server -d session.gc_probability=0
iBefore=$(session_files)
session_burst
iAfter=$(session_files)
stop_session_server
iCalls=$((SESSION_ROUNDS * 3))
iGrowth=$((iAfter - iBefore))
# Collector on every request and after two seconds, then one more call to run it.
session_server -d session.gc_maxlifetime=2 -d session.gc_probability=1 -d session.gc_divisor=1
sleep 3
curl -sS -o /dev/null -d grant_type=refresh_token -d refresh_token=garbage -d "client_id=$sClient" "$sSessEndpoint?route=token"
iCollected=$(session_files)
stop_session_server
rm -rf "$sSessions"
[ "$iGrowth" -gt 0 ] || { rm -f "$JAR"; fail "$iCalls anonymous calls left no session file: the check is not looking where iTop writes sessions"; }
[ "$iGrowth" -le "$iCalls" ] || { rm -f "$JAR"; fail "$iCalls anonymous calls left $iGrowth session files: a call opens more than one session"; }
echo "   ok  $iCalls anonymous register, token and authorize calls leave $iGrowth session files, at most one each"
[ "$iCollected" -le 2 ] || { rm -f "$JAR"; fail "$iGrowth session files were still there ($iCollected) after PHP's collector ran: something keeps them"; }
echo "   ok  and PHP's collector reclaims them ($iCollected left)"

# shellcheck source=conformance.sh
. "$(dirname "${BASH_SOURCE[0]}")/conformance.sh"

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
expect "the consent page names the resource in words" "$sConsent" 'REST/JSON API'
expect "and gives its address, which the token is held to" "$sConsent" "$REST"
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

# A code belongs to the client it was issued for. Another client presenting it is refused,
# and - refused before the code is claimed - must not burn it for its real client.
sOut=$(token -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient2" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" --data-urlencode "resource=$sResource")
expect "a code presented by another client is invalid_grant" "$sOut" '"error":"invalid_grant"'

sTok=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" --data-urlencode "resource=$sResource" \
	"$ENDPOINT?route=token")
sAccess1=$(printf '%s' "$sTok" | json_field access_token)
sRefresh1=$(printf '%s' "$sTok" | json_field refresh_token)
[ -n "$sAccess1" ] && [ -n "$sRefresh1" ] || fail "the code exchange issued no tokens: ${sTok:0:300}"
echo "   ok  the code exchange issues an access and a refresh token, even after another client tried it"
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
# Whoever used the previous token in the grace period must not have dropped the legitimate
# client's: it is now the previous one, accepted within the grace period, and a reuse after it.
sTok=$(curl -sS -d grant_type=refresh_token --data-urlencode "refresh_token=$sRefresh2" -d "client_id=$sClient" "$ENDPOINT?route=token")
expect "the legitimate client's refresh token survives a use of the previous one" "$sTok" '"access_token"'
sAccess3=$(printf '%s' "$sTok" | json_field access_token)
sRefresh3=$(printf '%s' "$sTok" | json_field refresh_token)

# Two refreshes with one refresh token at once, on a connection of their own. Whether they race
# or run one after the other, no refresh token either of them hands out may be left matching
# nothing: each is still accepted afterwards, in turn, inside the grace period. Under a race
# without the claim, the last write wins and the other one is refused as a plain invalid_grant.
sLoc=$(authorize)
sBack=$(allow "$sLoc" "$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")")
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
sRaceRefresh=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" "$ENDPOINT?route=token" | json_field refresh_token)
RACE_A=$(mktemp); RACE_B=$(mktemp)
curl -sS -d grant_type=refresh_token --data-urlencode "refresh_token=$sRaceRefresh" -d "client_id=$sClient" "$ENDPOINT?route=token" >"$RACE_A" &
iRaceA=$!
curl -sS -d grant_type=refresh_token --data-urlencode "refresh_token=$sRaceRefresh" -d "client_id=$sClient" "$ENDPOINT?route=token" >"$RACE_B" &
iRaceB=$!
# By process: a bare wait would also wait for the PHP server this script runs under.
wait "$iRaceA" "$iRaceB"
iIssued=0
for sAnswer in "$(cat "$RACE_A")" "$(cat "$RACE_B")"; do
	case "$sAnswer" in
		*'"refresh_token"'*) iIssued=$((iIssued + 1)) ;;
		*'refreshed concurrently'*) ;;
		*) rm -f "$JAR" "$RACE_A" "$RACE_B"; fail "a concurrent refresh answered neither tokens nor a retry: ${sAnswer:0:300}" ;;
	esac
done
[ "$iIssued" -ge 1 ] || { rm -f "$JAR" "$RACE_A" "$RACE_B"; fail "neither of two concurrent refreshes issued tokens"; }
for sRaceFile in "$RACE_A" "$RACE_B"; do
	sIssued=$(json_field refresh_token <"$sRaceFile")
	[ -n "$sIssued" ] || continue
	sTok=$(curl -sS -d grant_type=refresh_token --data-urlencode "refresh_token=$sIssued" -d "client_id=$sClient" "$ENDPOINT?route=token")
	expect "a refresh token issued by one of two concurrent refreshes is still accepted" "$sTok" '"access_token"'
done
rm -f "$RACE_A" "$RACE_B"

# The client ends the connection itself (RFC 7009), on a connection of its own.
revoke() { curl -sS -w '\n%{http_code}' "$@" "$ENDPOINT?route=revoke"; }
sLoc=$(authorize)
sBack=$(allow "$sLoc" "$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")")
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
sTok=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" "$ENDPOINT?route=token")
sRevAccess=$(printf '%s' "$sTok" | json_field access_token)
sRevRefresh=$(printf '%s' "$sTok" | json_field refresh_token)
expect "the server metadata names the revocation endpoint" "$sMeta" '"revocation_endpoint":"'
sOut=$(revoke -d "client_id=$sClient2" --data-urlencode "token=$sRevRefresh")
expect "another client's refresh token is answered as revoked" "$sOut" '200'
expect "and its connection is left as it is" "$(rest_get "$sRevAccess")" '"code":0'
sOut=$(revoke -d "client_id=$sClient2" --data-urlencode "token=$sRevAccess")
expect "another client's access token is answered as revoked" "$sOut" '200'
expect "and its connection is left as it is, too" "$(rest_get "$sRevAccess")" '"code":0'
sOut=$(revoke -d "client_id=$sClient" --data-urlencode "token=$sRevRefresh")
expect "revoking a refresh token answers 200" "$sOut" '200'
expect "revoking it ends its access token too" "$(rest_get "$sRevAccess")" '"code":1'
sOut=$(token -d grant_type=refresh_token --data-urlencode "refresh_token=$sRevRefresh" -d "client_id=$sClient")
expect "a revoked refresh token is invalid_grant" "$sOut" '"error":"invalid_grant"'
expect "revoking it again still answers 200" "$(revoke -d "client_id=$sClient" --data-urlencode "token=$sRevRefresh")" '200'

# A client may revoke the access token instead, with no hint: that ends the connection too.
sLoc=$(authorize)
sBack=$(allow "$sLoc" "$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")")
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
sTok=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" "$ENDPOINT?route=token")
sRevAccess=$(printf '%s' "$sTok" | json_field access_token)
sRevRefresh=$(printf '%s' "$sTok" | json_field refresh_token)
sOut=$(revoke -d "client_id=$sClient" --data-urlencode "token=$sRevAccess")
expect "revoking an access token without a hint answers 200" "$sOut" '200'
expect "and the access token is refused" "$(rest_get "$sRevAccess")" '"code":1'
sOut=$(token -d grant_type=refresh_token --data-urlencode "refresh_token=$sRevRefresh" -d "client_id=$sClient")
expect "and its refresh token is invalid_grant" "$sOut" '"error":"invalid_grant"'

if [ -z "$ITOP_TOKEN" ] || [ "${ITOP_TOKEN#*:}" = "$ITOP_TOKEN" ]; then
	rm -f "$JAR"
	fail "module-smoke.php passed no confidential client"
fi
IFS=: read -r sCcId sCcSecret sOldRefresh sNoRestLogin sNoRestPassword sPortalRefresh sWithdrawnClient <<< "$ITOP_TOKEN"
CC_CALLBACK='https://smoke.example/callback'

# A client that registered itself with a callback since dropped from the allowlist: refused on a
# page, as an unknown client is, though the callback is still on its own list.
sOut=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" -G "$ENDPOINT" \
	--data-urlencode route=authorize --data-urlencode response_type=code \
	--data-urlencode "client_id=$sWithdrawnClient" --data-urlencode redirect_uri=https://withdrawn.example/callback \
	--data-urlencode "code_challenge=$sChallenge" --data-urlencode code_challenge_method=S256)
expect "authorize refuses a callback the allowlist dropped since registration" "$sOut" '400 '
[ "${sOut#400 }" = "" ] || { rm -f "$JAR"; fail "authorize redirected a withdrawn callback to: ${sOut#400 }"; }

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

# The person disconnects from the portal's My profile, which posts back to its own page with
# the transaction id that page hands out. The template sees only the tab's own data, so a
# missing id there is an empty field and a refused post, not an error.
PORTAL="$BASE/pages/exec.php/user?exec_module=itop-portal-base&exec_page=index.php&portal_id=itop-portal&sTab=altioo-oauth-server-connections"
sLoc=$(authorize)
sBack=$(allow "$sLoc" "$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")")
sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
sTok=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
	--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" "$ENDPOINT?route=token")
sProfileAccess=$(printf '%s' "$sTok" | json_field access_token)
sProfileGrant=$(printf '%s' "$sTok" | json_field refresh_token)
sProfileGrant=${sProfileGrant%%.*}
sPage=$(curl -sS -c "$JAR" -b "$JAR" "$PORTAL")
expect "My profile lists the connection" "$sPage" "name=\"grant_id\" value=\"$sProfileGrant\""
sTransaction=$(printf '%s' "$sPage" | sed -n 's/.*name="transaction_id" value="\([^"]*\)".*/\1/p' | head -1)
[ -n "$sTransaction" ] || { rm -f "$JAR"; fail "My profile's Disconnect carries no transaction id"; }
echo "   ok  My profile's Disconnect carries a transaction id"
sOut=$(curl -sS -c "$JAR" -b "$JAR" --data-urlencode sTab=altioo-oauth-server-connections \
	--data-urlencode operation=altioo_oauth_server_disconnect --data-urlencode "transaction_id=$sTransaction" \
	--data-urlencode "grant_id=$sProfileGrant" "$PORTAL")
expect "My profile says the connection is ended" "$sOut" 'Smoke is disconnected'
expect "after My profile's Disconnect the connection's token is refused" "$(rest_get "$sProfileAccess")" '"code":1'

# A connection made 200 days ago, its stored end still ahead: today's max_grant_days ends it.
sOut=$(token -u "$sCcId:$sCcSecret" -d grant_type=refresh_token --data-urlencode "refresh_token=$sOldRefresh")
expect "a lower max_grant_days ends a connection made before it" "$sOut" '"error":"invalid_grant","error_description":"the connection has expired"'

# Allowed as portal-only, the account now has the console: consent again.
sOut=$(token -u "$sCcId:$sCcSecret" -d grant_type=refresh_token --data-urlencode "refresh_token=$sPortalRefresh")
expect "a connection made portal-only ends once the account has the console" "$sOut" 'the account was given the console'

# CORS: nothing on the token endpoint for an origin nobody listed, which by default is
# every origin; the discovery documents are readable from any origin, without credentials.
sOut=$(curl -sS -D - -o /dev/null -H 'Origin: https://evil.example' -d grant_type=refresh_token "$ENDPOINT?route=token")
case "$sOut" in *[Aa]ccess-[Cc]ontrol-*) rm -f "$JAR"; fail "a CORS header was sent for an unlisted origin" ;; esac
echo "   ok  no CORS header for an origin nobody listed"
sOut=$(curl -sS -D - -o /dev/null -H 'Origin: https://evil.example' "$ENDPOINT?route=openid-configuration")
expect "the server metadata is readable from any origin" "$sOut" 'Access-Control-Allow-Origin: *'
expect "the server metadata may be kept a few minutes, by the client only" "$sOut" 'Cache-Control: private, max-age=300'
case "$sOut" in *[Aa]llow-[Cc]redentials*) rm -f "$JAR"; fail "the discovery documents allow credentials" ;; esac
sOut=$(curl -sS -D - -o /dev/null -X OPTIONS -H 'Origin: https://evil.example' -H 'Access-Control-Request-Method: GET' "$ENDPOINT?route=protected-resource&for=mcp")
expect "a preflight for the resource metadata is answered" "$sOut" ' 204'

# An account the REST/JSON resource would refuse is refused before consent, with a reason.
NOREST_JAR=$(mktemp)
sLoc=$(curl -sS -o /dev/null -w '%{redirect_url}' -c "$NOREST_JAR" -b "$NOREST_JAR" -G "$ENDPOINT" \
	--data-urlencode route=authorize --data-urlencode response_type=code \
	--data-urlencode "client_id=$sClient" --data-urlencode "redirect_uri=$CALLBACK" \
	--data-urlencode "code_challenge=$sChallenge" --data-urlencode code_challenge_method=S256 \
	--data-urlencode "resource=$sResource")
curl -sS -o /dev/null -c "$NOREST_JAR" -b "$NOREST_JAR" "$sLoc"
sOut=$(curl -sS -w '\n%{http_code}' -c "$NOREST_JAR" -b "$NOREST_JAR" --data-urlencode loginop=login \
	--data-urlencode "auth_user=$sNoRestLogin" --data-urlencode "auth_pwd=$sNoRestPassword" "$sLoc")
rm -f "$NOREST_JAR"
expect "an account the resource would refuse is told so before consent" "$sOut" 'Not available for your account'
expect "and the page is a 403" "$sOut" '403'

rm -f "$JAR"
