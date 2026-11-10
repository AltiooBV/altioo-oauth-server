# Testing a hand-configured client on a real instance

0.1.0 was tested on a real instance with Claude Code, Claude Desktop and claude.ai, which
connect themselves. This is the procedure for a client configured by hand, for the two
candidates [ADR 0009](adr/0009-rest-json-clients-in-1-0.md) names: **Postman** and **a script
over a fixed loopback port**. The automated HTTP checks play such a client too
(`tools/ci/checks/http-smoke.sh`). The results table at the end records what was run: the
script on 2026-11-10, Postman not yet.

Run it on a real instance (the maintainer's is itop.altioo.com), against REST/JSON, with a
throwaway account. About half an hour. `doc/` ships in the release archive, so keep what is
written here true for any instance.

## Before you start

- **A test account** that is not an administrator, with the *REST Services User* profile, or on
  an instance where `allow_rest_services_via_tokens` is set. An administrator needs the profile
  too: `rest.php` asks for it whenever `secure_rest_services` is on, whatever else the account
  has. The consent page refuses an account `rest.php` would refuse, with a reason: that refusal
  is part of what to look at once (step 6).
- **The redirect URI of a hand-made client matches exactly**, byte for byte: scheme, host, port,
  path, case, a trailing slash. An administrator-created client skips the `redirect_uris`
  allowlist because *it is its own allowlist*, which also means no tolerance: a different port
  on a loopback address, `localhost` for `127.0.0.1`, or `/callback/` for `/callback` is another
  address and is refused. Only a client that registered itself gets the any-port loopback rule,
  and only with `allow_loopback`. So a script needs a **fixed** port, typed into the client.
- `rest.php` reads the token from the `Auth-Token` header or an `auth_token` parameter, **not**
  from `Authorization: Bearer` ([ADR 0003](adr/0003-generic-from-the-first-release.md)). A
  client that can only send Bearer cannot use its token; that is what these two tests work
  around. Prefer the header: a token in a query string reaches access logs.
- Two URLs, with `<itop>` your instance's address:

  | | |
  |---|---|
  | Authorization URL | `https://<itop>/env-production/altioo-oauth-server/index.php?route=authorize` |
  | Token URL | `https://<itop>/env-production/altioo-oauth-server/index.php?route=token` |
  | Revocation URL | `https://<itop>/env-production/altioo-oauth-server/index.php?route=revoke` |

## 1. Create the two clients

*Admin tools*, *OAuth clients*, *New*. For each, copy the secret before you save, since it is
stored as a one-way hash, and the generated **Client ID** after. The two secret fields start
with `*****` in them: empty both before pasting. Use a hex secret: a Basic header carries the ID
and secret form-encoded (RFC 6749 §2.3.1), so a `+` from base64 would arrive as a space from any
client that skips the encoding.

| | Postman | Script |
|---|---|---|
| Name | `Manual test - Postman` | `Manual test - script` |
| Redirect URIs | `https://oauth.pstmn.io/v1/browser-callback` | `http://127.0.0.1:8765/callback` |
| Authentication | Secret, in an HTTP Basic header | Secret, in an HTTP Basic header |
| Client secret | `openssl rand -hex 32` | `openssl rand -hex 32` |
| PKCE | Required | Required |
| Default resource | `rest` | `rest` |

Check: both appear in the list with source *Created by an administrator* and status *Active*.

## 2. Postman

Postman's documentation names a header prefix for the token but not a header name, so the
token is sent as `Auth-Token` by a script. Settings below are Postman's own, as its
[OAuth 2.0 page](https://learning.postman.com/docs/sending-requests/authorization/oauth-20/)
describes them: check the labels against the version you run.

1. A collection `iTop REST`. On the collection, *Authorization*: type **OAuth 2.0**,
   grant type **Authorization Code (With PKCE)**, callback URL
   `https://oauth.pstmn.io/v1/browser-callback`, the two URLs from the table above, the client
   ID and secret, *Code challenge method* **SHA-256**, scope `REST/JSON`, *Client
   authentication* **Send as Basic Auth header**.
2. *Get New Access Token*. A browser opens on iTop's sign-in page: sign in as the test account.
   **Look at the consent page**: the client's name (and the sentence saying the client chose
   it), where it returns (`oauth.pstmn.io`), *REST/JSON API* with its address beneath. *Allow*.
3. Postman shows an access token and a refresh token. *Use Token* is not enough: Postman would
   send it as `Authorization: Bearer`, which `rest.php` refuses (that is worth confirming once:
   step 7).
4. Save the access token in a collection variable `itop_token`, and add this **pre-request
   script** to a request `POST https://<itop>/webservices/rest.php`, with the request's own
   authorization set to *No Auth*:

   ```javascript
   pm.request.headers.upsert({ key: 'Auth-Token', value: pm.collectionVariables.get('itop_token') });
   ```

   Body, `x-www-form-urlencoded`: `version` = `1.3`, `json_data` =
   `{"operation":"core/get","class":"Organization","key":"SELECT Organization","output_fields":"name","limit":"1"}`.
5. *Send*. Expect `"code": 0` and an organization.

Whether Postman lets a script read the token the helper holds is not documented: if it does not
on your version, copying the token by hand into the variable is the method, and a refresh is the
same copy again. Note which it was.

## 3. The script

Standard library only; PKCE, the loopback listener on the registered port, the code exchange,
the call with `Auth-Token`, a refresh and a revocation. Save it as `itop_oauth_check.py`.

```python
import base64, hashlib, http.server, json, os, secrets, threading, time, urllib.error, urllib.parse, urllib.request, webbrowser

ITOP = os.environ["ITOP"].rstrip("/")            # https://itop.altioo.com
CLIENT_ID, SECRET = os.environ["CLIENT_ID"], os.environ["CLIENT_SECRET"]
REDIRECT = "http://127.0.0.1:8765/callback"       # exactly as typed in the client
BASE = f"{ITOP}/env-production/altioo-oauth-server/index.php"
q = lambda v: urllib.parse.quote(v, safe="")     # RFC 6749 §2.3.1: form-encoded before joining
BASIC = "Basic " + base64.b64encode(f"{q(CLIENT_ID)}:{q(SECRET)}".encode()).decode()

def post(url, fields, headers=None):
    req = urllib.request.Request(url, urllib.parse.urlencode(fields).encode(), headers or {})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()

def rest(token):
    return post(f"{ITOP}/webservices/rest.php", {
        "version": "1.3",
        "json_data": '{"operation":"core/get","class":"Organization","key":"SELECT Organization","output_fields":"name","limit":"1"}',
    }, {"Auth-Token": token})[1][:200]

verifier = secrets.token_urlsafe(48)
challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
state = secrets.token_urlsafe(8)
got = {}

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        got.update(urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query))
        self.send_response(200); self.end_headers(); self.wfile.write(b"You can close this tab.")
    def log_message(self, *a): pass

server = http.server.HTTPServer(("127.0.0.1", 8765), Handler)
threading.Thread(target=server.handle_request, daemon=True).start()
webbrowser.open(f"{BASE}?" + urllib.parse.urlencode({
    "route": "authorize", "response_type": "code", "client_id": CLIENT_ID, "redirect_uri": REDIRECT,
    "scope": "REST/JSON", "state": state, "code_challenge": challenge, "code_challenge_method": "S256"}))
while "code" not in got and "error" not in got:
    time.sleep(0.2)
assert got.get("state") == [state], got
print("iss:", got.get("iss"))
status, body = post(f"{BASE}?route=token", {"grant_type": "authorization_code", "code": got["code"][0],
    "redirect_uri": REDIRECT, "code_verifier": verifier}, {"Authorization": BASIC})
tokens = json.loads(body); print("exchange:", status, sorted(tokens))
print("rest:", rest(tokens["access_token"]))
status, body = post(f"{BASE}?route=token", {"grant_type": "refresh_token", "refresh_token": tokens["refresh_token"]}, {"Authorization": BASIC})
new = json.loads(body); print("refresh:", status, "rotated:", new["refresh_token"] != tokens["refresh_token"])
print("old access token:", rest(tokens["access_token"]))
print("new access token:", rest(new["access_token"]))
input("Press Enter to revoke the connection (look at it in the console first)... ")
print("revoke:", post(f"{BASE}?route=revoke", {"token": new["refresh_token"]}, {"Authorization": BASIC})[0])
print("after revoke, access token:", rest(new["access_token"]))
print("after revoke, refresh:", post(f"{BASE}?route=token", {"grant_type": "refresh_token", "refresh_token": new["refresh_token"]}, {"Authorization": BASIC}))
```

```bash
ITOP=https://<itop> CLIENT_ID=<id> CLIENT_SECRET=<secret> python3 itop_oauth_check.py
```

On 2026-11-10 its requests were run one at a time from a version split into steps, with
consent given in a browser signed in to the instance; this listing has not been run end to end.
If it fails for a reason of its own, fix the script, and do not count that against the module.

## 4. What to look at, for each client

| # | Do | Expect |
|---|---|---|
| 1 | Consent page | Client name, with the note that the client chose it; where it returns; *REST/JSON API* with its address; no grade to choose (REST/JSON has one scope) |
| 2 | Allow | Returns to the client with a code, `state` and `iss` |
| 3 | Call `rest.php` with `Auth-Token` | `"code": 0` |
| 4 | Refresh | A new access and refresh token; **the previous access token now answers `"code": 1`**; the connection still owns one token (*My Account*, *Personal Tokens*: one entry for the client, not a new one) |
| 5 | Refresh again with the *previous* refresh token, at once | Accepted (the grace period, 30 seconds); later, `invalid_grant`, "refresh token reused", and the connection ends. Only the token just before the current one counts as a reuse: an older one gets a plain `invalid_grant` and the connection stays |
| 6 | Sign in as an account `rest.php` refuses | A page saying why, before consent |
| 7 | Call `rest.php` with `Authorization: Bearer <token>` | `"code": 1`, as ADR 0003 says. If it works, an iTop release has changed and the README is out of date |
| 8 | Change the redirect URI by one character (port, a trailing slash, `localhost` for `127.0.0.1`) | A page refusing the client, no redirect. Nothing is sent to the address |
| 9 | Console, *Admin tools*, *OAuth connections* | One *Active* connection for the client, with the resource's name |
| 10 | The token's history (*Personal Tokens*) | A change entry per refresh, naming the client and this module |

## 5. Ending a connection, three ways

Make a fresh connection for each, and after each check that `rest.php` answers `"code": 1` and
that a refresh gives `invalid_grant`.

1. **The client:** the revocation URL, with the secret in a Basic header (the script's last step).
2. **The person:** *My Account*, *Personal Tokens*, *Connected applications*, *Disconnect*.
3. **An administrator:** *Admin tools*, *OAuth connections*, open the connection, *Revoke*; then
   the same connection's token is gone from the person's list, and the connection reads *Ended*,
   *Revoked*. Also disable the client under *OAuth clients* and see its next connection refused.

## 6. Write down what happened

Fill this in, with the date, the iTop and altioo-oauth-server versions and the Postman version.
Then put what was proved, and only that, in the CHANGELOG and the README; ADR 0009's table is
vendor documentation until this table says otherwise.

**Script, 2026-11-10**: itop.altioo.com, iTop 3.2.3-2-20678, altioo-oauth-server 0.1.0. Python's
standard library, the requests above run one step at a time; consent in a browser signed in as an
administrator given *REST Services User* for the test. **Postman: not run.**

| Check | Postman | Script |
|---|---|---|
| Consent page as expected (1) | not run | Yes: the name with the note that the client chose it, `127.0.0.1`, *iTop's REST/JSON API* and its address, no grade |
| Code returned with `state` and `iss` (2) | not run | Yes, `iss` = the issuer |
| `rest.php` with `Auth-Token` (3) | not run | `"code": 0`; also with the `auth_token` parameter |
| Refresh rotates, old access token refused, one token (4) | not run | Yes: both tokens new, the old access token `"code": 1`, one *Personal Token* row |
| Grace period (5) | not run | Previous refresh token accepted within 30 s; 36 s later `invalid_grant`, "refresh token reused", and the current tokens refused |
| Refused account explained (6) | not run | Yes: an administrator without *REST Services User* got "Not available for your account", HTTP 403, before consent |
| Bearer refused (7) | not run | `"code": 1`, "Invalid login" |
| Near-miss redirect URI refused (8) | not run | Port 8766, `/callback/`, `localhost`: "Unknown application", HTTP 400, no redirect, signed in or not |
| Connection and history in the console (9, 10) | not run | One *Active* connection naming *iTop's REST/JSON API (rest)*; the token's history has its creation and one entry per refresh, by "OAuth: Manual test - script" |
| Ended by the client, the person, an administrator (5) | not run | All three: `"code": 1` and `invalid_grant` after each; the administrator's left *Ended*, *Revoked* and no token row. The disabled client's next request: "Unknown application" |
| How the token was sent as `Auth-Token` (script, copy by hand, other) | not run | n/a |

## What the automated checks already cover

`tools/ci/checks/http-smoke.sh` plays the same client against a CI instance: a confidential
client without PKCE, the code exchange and refresh with the secret in a Basic header or the
body, `rest.php` with a header and with an `auth_token` parameter, Bearer refused, revocation by
the client, a callback that must match exactly (another port, host, path, case, a trailing slash)
and a fixed-port loopback callback accepted. What it cannot say is whether Postman or a real
script behaves as this page expects, which is why this page exists.
