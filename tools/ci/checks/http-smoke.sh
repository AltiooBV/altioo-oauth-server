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
# made, as "<client_id>:<secret>:<old refresh token>:<login>:<password>:<portal-era refresh token>:<withdrawn client_id>",
# then the login and password of an account the MCP resource refuses and of a portal-only one it
# offers portal grades, both "-" where the MCP stand-in (tools/ci/fixtures/altioo-mcp) is not installed.
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
IFS=: read -r sCcId sCcSecret sOldRefresh sNoRestLogin sNoRestPassword sPortalRefresh sWithdrawnClient \
	sNoMcpLogin sNoMcpPassword sPortalLogin sPortalPassword <<< "$ITOP_TOKEN"
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

# What a hand-configured REST/JSON client (README, "Clients configured by hand") relies on.
# rest.php takes a token as an Auth-Token header or an auth_token parameter and, today, not as
# Authorization: Bearer (ADR 0003). The last one is a canary: when an iTop starts reading it,
# the README's account of what a client must be able to send is out of date.
sCcAccess=$(printf '%s' "$sTok" | json_field access_token)
sCcRefresh=$(printf '%s' "$sTok" | json_field refresh_token)
REST_BODY='json_data={"operation":"core/get","class":"Organization","key":"SELECT Organization","output_fields":"name","limit":"1"}'
expect "rest.php takes the token as an auth_token parameter" \
	"$(curl -sS -X POST --data-urlencode "auth_token=$sCcAccess" --data-urlencode version=1.3 --data-urlencode "$REST_BODY" "$REST")" '"code":0'
expect "and does not take it as Authorization: Bearer" \
	"$(curl -sS -X POST -H "Authorization: Bearer $sCcAccess" --data-urlencode version=1.3 --data-urlencode "$REST_BODY" "$REST")" '"code":1'

# client_secret_basic, the method the README tells an administrator to choose, on a refresh and
# on a revocation (the checks above use the secret in the body for both).
sTok=$(curl -sS -u "$sCcId:$sCcSecret" -d grant_type=refresh_token --data-urlencode "refresh_token=$sCcRefresh" "$ENDPOINT?route=token")
expect "client_secret_basic refreshes" "$sTok" '"refresh_token"'
expect "after that refresh the previous access token is refused" "$(rest_get "$sCcAccess")" '"code":1'
sCcAccess=$(printf '%s' "$sTok" | json_field access_token)
sCcRefresh=$(printf '%s' "$sTok" | json_field refresh_token)
expect "and the new one works" "$(rest_get "$sCcAccess")" '"code":0'
sOut=$(revoke -u "$sCcId:$sCcSecret" --data-urlencode "token=$sCcRefresh")
expect "client_secret_basic revokes" "$sOut" '200'
expect "revoking ends the hand-made client's access token" "$(rest_get "$sCcAccess")" '"code":1'

# A redirect URI of a hand-made client matches exactly, byte for byte: a longer path, another
# port or another loopback name is not the registered one. The fixed-port loopback URI is what
# a script uses (ADR 0009); only a client that registered itself gets the any-port rule.
cc_authorize() { # <redirect uri> -> "<status> <redirect url>"
	curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" -G "$ENDPOINT" \
		--data-urlencode route=authorize --data-urlencode response_type=code \
		--data-urlencode "client_id=$sCcId" --data-urlencode "redirect_uri=$1" \
		--data-urlencode 'scope=REST/JSON' --data-urlencode state=exact
}
sOut=$(cc_authorize 'http://127.0.0.1:8765/callback')
expect "a hand-made client's fixed-port loopback callback is accepted" "$sOut" '302 http'
for sUri in 'http://127.0.0.1:8766/callback' 'http://localhost:8765/callback' 'http://127.0.0.1:8765/callback/x' \
	'https://smoke.example/callback/' 'https://smoke.example/callbackx' 'https://smoke.example/Callback'; do
	sOut=$(cc_authorize "$sUri")
	[ "$sOut" = '400 ' ] || { rm -f "$JAR"; fail "authorize did not refuse $sUri for a hand-made client: $sOut"; }
done
echo "   ok  a hand-made client's callback must match exactly: another port, host, path, case or a trailing slash is refused"

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

# The MCP resource, against the stand-in tools/ci/fixtures/altioo-mcp, which registers the MCP
# scopes and answers a Bearer token as altioo-mcp asks this module about it. Whether this
# instance has it at all is what module-smoke.php decides; a CI instance without it is a failure
# there, so the half below is not silently skipped here: ITOP_TOKEN says whether it ran.
if [ "$sNoMcpLogin" != '-' ]; then
	MCP="$BASE/env-production/altioo-mcp/index.php"
	mcp_authorize() { # <jar> <resource> -> the redirect, to sign in or to consent
		curl -sS -o /dev/null -w '%{redirect_url}' -c "$1" -b "$1" -G "$ENDPOINT" \
			--data-urlencode route=authorize --data-urlencode response_type=code \
			--data-urlencode "client_id=$sClient" --data-urlencode "redirect_uri=$CALLBACK" \
			--data-urlencode "code_challenge=$sChallenge" --data-urlencode code_challenge_method=S256 \
			--data-urlencode state=mcp --data-urlencode "resource=$2"
	}
	mcp_sign_in() { # <jar> <login> <password> <consent url> -> the consent page and its status
		curl -sS -o /dev/null -c "$1" -b "$1" "$4"
		curl -sS -w '\n%{http_code}' -c "$1" -b "$1" --data-urlencode loginop=login \
			--data-urlencode "auth_user=$2" --data-urlencode "auth_pwd=$3" "$4"
	}
	mcp_allow() { # <jar> <consent url> <consent page> <grade> -> the token endpoint's answer
		local sTransaction sBackTo sMcpCode
		sTransaction=$(printf '%s' "$3" | sed -n 's/.*name="transaction_id" value="\([^"]*\)".*/\1/p')
		sBackTo=$(curl -sS -o /dev/null -w '%{redirect_url}' -c "$1" -b "$1" \
			--data-urlencode "transaction_id=$sTransaction" --data-urlencode decision=allow \
			--data-urlencode "grade=$4" "$2")
		sMcpCode=$(printf '%s' "$sBackTo" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
		curl -sS -d grant_type=authorization_code --data-urlencode "code=$sMcpCode" -d "client_id=$sClient" \
			--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" \
			--data-urlencode "resource=$MCP" "$ENDPOINT?route=token"
	}
	# The stand-in answers a GET with the token's login and scopes. The real altioo-mcp
	# (MCP_UNDER_TEST=release, the weekly contract job) speaks MCP: a JSON-RPC POST, and whether a
	# token was let in reads from an initialize that succeeds, the grade from what tools/list serves.
	MCP_UNDER_TEST="${MCP_UNDER_TEST:-stand-in}"
	MCP_INDEX="$ITOP_DIR/env-production/altioo-mcp/index.php"
	case "$MCP_UNDER_TEST" in
		stand-in) grep -q STAND_IN_AUDIENCE "$MCP_INDEX" || { rm -f "$JAR"; fail "MCP_UNDER_TEST=stand-in, but $MCP_INDEX is not the stand-in"; } ;;
		release) ! grep -q STAND_IN_AUDIENCE "$MCP_INDEX" || { rm -f "$JAR"; fail "MCP_UNDER_TEST=release, but $MCP_INDEX is the stand-in"; } ;;
		*) rm -f "$JAR"; fail "MCP_UNDER_TEST is '$MCP_UNDER_TEST': stand-in or release" ;;
	esac
	echo "   --  the MCP endpoint under test is the $MCP_UNDER_TEST"
	mcp_rpc() { # <url> <access token> <method> [curl options] -> the status and the body
		local sUrl=$1 sToken=$2 sMethod=$3; shift 3
		curl -sS "$@" -w '\n%{http_code}' -H "Authorization: Bearer $sToken" -H 'Content-Type: application/json' \
			-H 'Accept: application/json, text/event-stream' \
			--data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$sMethod\",\"params\":{\"protocolVersion\":\"2025-06-18\",\"capabilities\":{},\"clientInfo\":{\"name\":\"altioo-oauth-server-ci\",\"version\":\"0\"}}}" "$sUrl"
	}
	mcp_tools() { # <url> <access token> -> the status and the body of tools/list, in a session of its own
		local sHeaders sSession
		sHeaders=$(mktemp)
		mcp_rpc "$1" "$2" initialize -D "$sHeaders" >/dev/null
		sSession=$(sed -n 's/^[Mm]cp-[Ss]ession-[Ii]d: *\([^[:space:]]*\).*/\1/p' "$sHeaders" | head -1)
		rm -f "$sHeaders"
		mcp_rpc "$1" "$2" tools/list -H "Mcp-Session-Id: $sSession"
	}
	mcp_call() { # <url> <access token> -> the status and the body
		if [ "$MCP_UNDER_TEST" = release ]; then
			mcp_rpc "$1" "$2" initialize
		else
			curl -sS -w '\n%{http_code}' -H "Authorization: Bearer $2" "$1"
		fi
	}
	if [ "$MCP_UNDER_TEST" = release ]; then MCP_ACCEPTED='"protocolVersion"'; else MCP_ACCEPTED='"ok":true'; fi

	sPrm=$(curl -sS "$ENDPOINT?route=protected-resource&for=mcp")
	expect "the MCP resource's metadata names its URL" "$sPrm" "\"resource\":\"$MCP\""
	expect "and offers its scopes" "$sPrm" '"MCP-read"'
	expect "and says it reads the Bearer header" "$sPrm" '"bearer_methods_supported":["header"]'
	sOut=$(curl -sS -D - -o /dev/null "$MCP")
	expect "a call without a token is a 401" "$sOut" ' 401'
	expect "that names the resource's metadata" "$sOut" "resource_metadata=\"$ENDPOINT?route=protected-resource&for=mcp\""
	sOut=$(curl -sS -D - -o /dev/null "$MCP?portal_id=itop-portal")
	if [ "$MCP_UNDER_TEST" = release ]; then
		# Found by the first run against the real module: altioo-mcp falls back to the default
		# metadata URL when ResourceMetadata::UrlFor knows none for the URL called
		# (MCPHelper::protectedResourceMetadataUrl), where the stand-in sends no metadata at all.
		expect "another URL of the same endpoint is sent to the default metadata, altioo-mcp's fallback" "$sOut" "resource_metadata=\"$ENDPOINT?route=protected-resource&for=mcp\""
	else
		case "$sOut" in *resource_metadata*) rm -f "$JAR"; fail "a URL no resource is configured for was given a metadata URL: $sOut" ;; esac
		echo "   ok  another URL of the same endpoint is sent to no metadata"
	fi

	# An administrator, who has the console: the four grades and the limit, then read only.
	sLoc=$(mcp_authorize "$JAR" "$MCP")
	sConsent=$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")
	expect "the MCP consent page names the resource in words" "$sConsent" 'its MCP server'
	expect "and gives the address the token is held to" "$sConsent" "$MCP"
	expect "it offers read only" "$sConsent" 'Read only'
	expect "and read and change, never delete" "$sConsent" 'Read and change, never delete'
	expect "and read, change and delete" "$sConsent" 'Read, change and delete'
	expect "and everything" "$sConsent" 'Everything I can do'
	expect "and the advisory limit, under a heading of its own" "$sConsent" 'Only propose changes, never make them'
	sTok=$(mcp_allow "$JAR" "$sLoc" "$sConsent" 0)
	sMcpAccess=$(printf '%s' "$sTok" | json_field access_token)
	[ -n "$sMcpAccess" ] || { rm -f "$JAR"; fail "the MCP grant issued no token: ${sTok:0:300}"; }
	sOut=$(mcp_call "$MCP" "$sMcpAccess")
	expect "the MCP token is accepted at its URL" "$sOut" "$MCP_ACCEPTED"
	if [ "$MCP_UNDER_TEST" = release ]; then
		# The grade shows in what is served: a read-only token lists the reading tools and none that writes.
		sOut=$(mcp_tools "$MCP" "$sMcpAccess")
		expect "and carries the grade chosen, read: it is served the reading tools" "$sOut" 'core_object_get'
		case "$sOut" in *core_object_create*|*core_object_delete*) rm -f "$JAR"; fail "a read-only MCP token is served a tool that writes: ${sOut:0:300}" ;; esac
		echo "   ok  and none that writes"
	else
		expect "and carries the grade chosen, read" "$sOut" '"scopes":["MCP-read"]'
	fi
	sOut=$(mcp_call "$MCP?portal_id=itop-portal" "$sMcpAccess")
	expect "the same token is refused at another portal's URL" "$sOut" '401'
	expect "with the reason" "$sOut" 'another URL of this endpoint'
	expect "a token minted for MCP does not reach REST/JSON" "$(rest_get "$sMcpAccess")" '"code":1'
	sLoc=$(authorize)
	sBack=$(allow "$sLoc" "$(curl -sS -c "$JAR" -b "$JAR" "$sLoc")")
	sCode=$(printf '%s' "$sBack" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
	sTok=$(curl -sS -d grant_type=authorization_code --data-urlencode "code=$sCode" -d "client_id=$sClient" \
		--data-urlencode "redirect_uri=$CALLBACK" --data-urlencode "code_verifier=$sVerifier" \
		--data-urlencode "resource=$sResource" "$ENDPOINT?route=token")
	sRestAccess=$(printf '%s' "$sTok" | json_field access_token)
	sOut=$(mcp_call "$MCP" "$sRestAccess")
	expect "and a token minted for REST/JSON is refused at the MCP endpoint" "$sOut" '401'

	# The two accounts below are not administrators, and authent-token lets only the profiles it
	# lists own a personal token (Administrator, by default): an instance with altioo-mcp tells its
	# administrator to add MCP Services User there. They are let in the way that is done, through
	# the configuration file for the length of these calls, and the file is put back before
	# anything is checked, as it is for the rate limit above. Configuration Manager is added so
	# that the account the MCP resource refuses is refused for its own reason and not for this.
	cp -p "$CONF" "$CONF.mcp-orig"
	php -r '$s = file_get_contents($argv[1]); $s = preg_replace("/\?>\s*\z/", "", $s);
		file_put_contents($argv[1], $s."\n\$MyModuleSettings[\"authent-token\"][\"personal_tokens_allowed_profiles\"] = [\"Administrator\", \"MCP Services User\", \"Configuration Manager\"];\n?>\n");' "$CONF"

	# An account holding neither profile the resource requires.
	NOMCP_JAR=$(mktemp)
	sLoc=$(mcp_authorize "$NOMCP_JAR" "$MCP")
	sNoMcpOut=$(mcp_sign_in "$NOMCP_JAR" "$sNoMcpLogin" "$sNoMcpPassword" "$sLoc")
	rm -f "$NOMCP_JAR"

	# An account with the portal and no console: its own grades, and nothing wider.
	PORTAL_JAR=$(mktemp)
	sLoc=$(mcp_authorize "$PORTAL_JAR" "$MCP")
	sConsent=$(mcp_sign_in "$PORTAL_JAR" "$sPortalLogin" "$sPortalPassword" "$sLoc")
	sTok=$(mcp_allow "$PORTAL_JAR" "$sLoc" "$sConsent" 1)
	rm -f "$PORTAL_JAR"
	# The real altioo-mcp logs the token in through iTop, which asks authent-token again at every
	# call whether the account's profile may own a personal token; the stand-in decodes the token
	# itself and never asks. So the call is made before the file is put back, and judged after.
	sPortalAccess=$(printf '%s' "$sTok" | json_field access_token)
	sPortalCall= sPortalTools=
	if [ -n "$sPortalAccess" ]; then
		sPortalCall=$(mcp_call "$MCP" "$sPortalAccess")
		[ "$MCP_UNDER_TEST" != release ] || sPortalTools=$(mcp_tools "$MCP" "$sPortalAccess")
	fi
	mv -f "$CONF.mcp-orig" "$CONF"

	expect "an account without a profile the MCP resource requires is told so before consent" "$sNoMcpOut" 'Not available for your account'
	expect "and the page is a 403" "$sNoMcpOut" '403'
	expect "a portal-only account is offered read only" "$sConsent" 'Read only'
	expect "or everything in its portal" "$sConsent" 'Everything I can do in my portal'
	for sWider in 'Read and change, never delete' 'Read, change and delete'; do
		case "$sConsent" in *"$sWider"*) rm -f "$JAR"; fail "a portal-only account was offered '$sWider'" ;; esac
	done
	echo "   ok  and nothing wider than that"
	expect "and the limit it may still apply" "$sConsent" 'Only propose changes, never make them'
	[ -n "$sPortalAccess" ] || { rm -f "$JAR"; fail "the portal-only MCP grant issued no token: ${sTok:0:300}"; }
	sOut=$sPortalCall
	expect "its token is accepted at the MCP URL" "$sOut" "$MCP_ACCEPTED"
	if [ "$MCP_UNDER_TEST" = release ]; then
		expect "and carries the whole-portal scope: it is served tools" "$sPortalTools" '"tools"'
	else
		expect "and carries the whole-portal scope" "$sOut" '"scopes":["MCP"]'
	fi
fi

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
