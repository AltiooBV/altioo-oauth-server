# What the specs a client leans on require of the documents and errors this server sends, sourced
# by checks/http-smoke.sh with BASE, ENDPOINT, REST, CALLBACK, JAR, sMeta, sPrm, sClient and the
# expect() and token() helpers in scope.
#
# The OpenID Foundation's conformance suite is not run: this is deliberately not an OpenID provider
# (MetadataController: no jwks_uri, no id_token), which is what its plans test, and its FAPI plans
# test a profile this server does not claim. What Claude and the MCP authorization spec do ask of
# an authorization server is RFC 8414 (its metadata), RFC 9728 (the resource's metadata), RFC 7591
# (registration), RFC 6749 section 5 (token responses) and RFC 7009 (revocation), and those are
# checked here, by field, against the instance CI installed. Because it is sourced, it runs where
# the HTTP smoke does: on every pull request and on itop-matrix.yml's Monday schedule, when it
# is the newest iTop that answers.
#
# A rule is checked as the RFC states it - REQUIRED fields, absolute URLs, no query on an issuer -
# and nothing here is a style opinion of ours.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

# <what> <document> <php condition over $a>  -  the condition is PHP, true when the rule holds
rule() {
	local sResult
	# shellcheck disable=SC2016
	sResult=$(printf '%s' "$2" | php -r '$a = json_decode(stream_get_contents(STDIN), true);
		$url = fn($u) => is_string($u) && preg_match("#^https?://[^/?\#]+#", $u) === 1;
		echo is_array($a) && ('"$3"') ? "ok" : "no";')
	[ "$sResult" = ok ] || { rm -f "$JAR"; fail "$1: ${2:0:300}"; }
	echo "   ok  $1"
}

echo "   RFC 8414, the authorization server metadata"
rule "the issuer is an https-or-http URL without a query or a fragment (section 2)" "$sMeta" \
	'$url($a["issuer"] ?? null) && !str_contains($a["issuer"], "?") && !str_contains($a["issuer"], "#")'
rule "every endpoint it names is an absolute URL" "$sMeta" \
	'$url($a["authorization_endpoint"] ?? null) && $url($a["token_endpoint"] ?? null) && $url($a["registration_endpoint"] ?? null) && $url($a["revocation_endpoint"] ?? null)'
rule "response_types_supported is there, since it is REQUIRED" "$sMeta" 'is_array($a["response_types_supported"] ?? null) && $a["response_types_supported"] !== []'
rule "every endpoint sits under the issuer, so a client that trusts one trusts them all" "$sMeta" \
	'array_reduce(["authorization_endpoint","token_endpoint","registration_endpoint","revocation_endpoint"], fn($c, $k) => $c && str_starts_with($a[$k], $a["issuer"]."/"), true)'
rule "what it offers is what it implements: code and refresh_token, S256, nothing implicit" "$sMeta" \
	'$a["grant_types_supported"] === ["authorization_code","refresh_token"] && $a["response_types_supported"] === ["code"] && $a["code_challenge_methods_supported"] === ["S256"]'

echo "   RFC 9728, the protected resource metadata"
rule "it names the resource and an authorization server, the issuer of the document above" "$sPrm" \
	'$url($a["resource"] ?? null) && ($a["authorization_servers"] ?? null) === ["'"$(printf '%s' "$sMeta" | json_field issuer)"'"]'
rule "and the resource has no fragment, as section 2 forbids" "$sPrm" '!str_contains($a["resource"], "#")'

echo "   RFC 7591, registration"
sReg=$(curl -sS -X POST -H 'Content-Type: application/json' \
	-d "{\"client_name\":\"Conformance\",\"redirect_uris\":[\"$CALLBACK\"],\"token_endpoint_auth_method\":\"client_secret_basic\"}" "$ENDPOINT?route=register")
rule "the response carries client_id, and the metadata registered, as section 3.2.1 says" "$sReg" \
	'is_string($a["client_id"] ?? null) && $a["redirect_uris"] === ["'"$CALLBACK"'"] && $a["token_endpoint_auth_method"] === "client_secret_basic"'
rule "a secret comes with client_secret_expires_at, which is REQUIRED beside it" "$sReg" \
	'is_string($a["client_secret"] ?? null) && array_key_exists("client_secret_expires_at", $a)'
sOut=$(curl -sS -X POST -H 'Content-Type: application/json' -d '{"client_name":"No URIs"}' "$ENDPOINT?route=register")
rule "a registration with no redirect URI is invalid_redirect_uri or invalid_client_metadata (section 3.2.2)" "$sOut" \
	'in_array($a["error"] ?? "", ["invalid_redirect_uri", "invalid_client_metadata"], true)'

echo "   RFC 6749 section 5, the token endpoint's responses"
sRaw=$(curl -sS -D - -o /dev/null -d grant_type=refresh_token -d refresh_token=garbage -d "client_id=$sClient" "$ENDPOINT?route=token")
expect "an error is a 400" "$sRaw" ' 400'
expect "is JSON" "$sRaw" 'Content-Type: application/json'
expect "is not cacheable (Cache-Control)" "$sRaw" 'Cache-Control: no-store'
expect "and not cacheable by an old proxy either (Pragma)" "$sRaw" 'Pragma: no-cache'
sOut=$(token -d grant_type=client_credentials -d "client_id=$sClient")
expect "a grant type it does not support is unsupported_grant_type" "$sOut" '"error":"unsupported_grant_type"'
sOut=$(token -d grant_type=authorization_code -d code=1.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa -d client_id=0123456789abcdef0123456789abcdef)
expect "an unknown client is invalid_client, not invalid_grant" "$sOut" '"error":"invalid_client"'

echo "   RFC 7009, revocation"
sOut=$(curl -sS -w '\n%{http_code}' -d token=1.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa -d "client_id=$sClient" "$ENDPOINT?route=revoke")
expect "revoking a token nobody holds is a 200, so a client cannot probe for live ones (section 2.2)" "$sOut" $'\n200'
