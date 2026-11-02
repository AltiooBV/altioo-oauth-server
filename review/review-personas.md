# Review personas — altioo-oauth-server

A review here runs the guide's passes — [`doc/itop-extension-guide.md`](../doc/itop-extension-guide.md),
"Review procedure — persona passes" — in the guide's order, with the passes below inserted where
each says. The guide's passes are the personas every iTop extension faces, and are not repeated
here; this file holds only what this extension adds.

**Why these.** The guide's red team starts from a logged-in low-privilege user. This module
answers people who are not logged in at all: anyone can register a client, the token endpoint
takes no session, and the consent page is where someone else's link lands in a victim's browser.
Its main callers are programs (Claude and other OAuth clients) whose conformance decides whether
anyone can connect. And what it issues is a credential that outlives the sign-in, so whoever runs
the identity provider in front of iTop has a question the guide's auditor does not ask.
[`SECURITY.md`](../SECURITY.md), "Threat model", is to answer most of it once written; these
passes check that it still does.

**Findings** go beside this file, one per review, named `<date>-<kind>.md`. They are gitignored —
they name real gaps — and only this file is tracked. `exclude.txt` keeps the folder out of the
release archive.

## Added passes

### Outsider with a browser and a link — red, after the guide's red team

- **Question:** someone with no account here, who can register a client, send a person an
  authorize link and call `register` and `token` as often as they like — can they get a code, a
  token or a refresh token that was not meant for them, make the consent page lie, or turn an
  endpoint into a redirect or a flood?
- **Open:** `register` (the redirect-URI allowlist, exact matching, the loopback rule, the rate
  limit, what a self-declared `client_name` and `logo_uri` can do on the consent page);
  `authorize` (every refusal before the client and redirect URI are checked renders a page and
  never redirects; PKCE S256 required with no plain fallback; the request id is all that crosses
  the identity-provider round trip; the consent form's transaction id; `X-Frame-Options` and
  `frame-ancestors`); `token` (code single use, expiry and binding to client, redirect URI and
  resource; refresh rotation and reuse ending the grant); how codes and refresh tokens are stored
  (a salted hash, nothing usable in the database); and every log line and URL, for a secret in it.
- **Blocks:** release.

### Grant that grows — red, after the outsider above

- **Question:** a person who consented legitimately, or the client holding their grant — can a
  token come out stronger, wider or longer-lived than the consent screen showed: another
  resource's scopes, a grade above the admin cap, past the grant's end, or after the person lost
  the right to it?
- **Open:** how the `resource` parameter fixes the scope family, on `authorize` and again on
  `token`; the consent POST, for scopes the screen did not offer; the administrator's caps
  (`offered_scopes`, `forced_scopes`, `max_grant_days`) applied on the server, not only in the
  form, and on refresh as well as at consent; what a refresh
  re-checks (account enabled, console access, `personal_tokens_allowed_profiles`, the
  resource's profile gate, the current `PersonalToken` still existing); the `expiration_date`
  set on every minted token, so iTop refuses it even if this module is broken or removed; and
  what altioo-mcp and `rest.php` do with a minted token compared with a hand-made one, which
  must be nothing different.
- **Blocks:** release.

### OAuth client — purple, after the guide's installing client / operator

- **Question:** Claude, and any client that follows the MCP authorization spec — can it discover,
  register, connect and stay connected with nothing typed by hand, and does every failure answer
  with what makes it do the right thing next?
- **Open:** the metadata documents against RFC 8414, RFC 9728 and the MCP authorization spec
  (`resource` equal to the endpoint URL byte for byte, `code_challenge_methods_supported`,
  `token_endpoint_auth_methods_supported`); the discovery forms the README tells the admin to
  serve, on Apache, IIS and nginx; the token endpoint's errors (`invalid_grant` for a dead
  refresh token, never `invalid_request`), `Cache-Control: no-store` and its response time; the
  REST/JSON resource, which cannot announce its server with a `401` (ADR 0003) and so depends on
  the documentation; and the release gate's manual run with claude.ai and the MCP Inspector.
- **Blocks:** release when Claude cannot connect, or cannot reconnect after a refresh fails;
  otherwise delivery.

### Person who connects — purple, after the OAuth client above

- **Question:** the iTop user who clicks Connect — do they know who is asking, where they will be
  sent back, and what they are handing over; does a refusal tell them what to do; and can they
  see and end the connection themselves, at once?
- **Open:** the consent page as the only explanation they get (the self-declared name flagged as
  such, the return host in large type, each grade in words they know, the default); each refusal
  page (portal-only account, missing profile, `personal_tokens_allowed_profiles`, an expired
  request), for whether it says what to ask the administrator; the `application` label of the
  minted token as it appears under My Account, Personal Tokens; and that deleting it there ends
  the connection on the next call and the next refresh.
- **Blocks:** release when the consent page can mislead or deleting the token does not end the
  connection; otherwise backlog.

### Identity administrator — purple, after the guide's auditor

- **Question:** whoever runs the sign-in in front of iTop (Entra, Okta, LDAP, the MFA policy) —
  does a connection made here respect what they enforce, and does it end when they end the
  person's access?
- **Open:** that consent is an ordinary iTop login, so every login mode and MFA the instance has
  applies, with no path around it; what happens to a grant when the person is disabled or removed
  in the identity provider but their iTop `User` stays enabled — the usual case with SAML,
  hybridauth or LDAP sign-in — and how long a refresh keeps working then; whether the README says
  so and how the admin shortens it (`max_grant_days`, disabling the account, revoking grants);
  and the change history, for whether a token's creation, refresh and deletion name the person,
  the client and this module.
- **Blocks:** delivery when a grant outlives deprovisioning without the documentation saying so
  and saying how to limit it; otherwise backlog.

The guide's blue team reviews the findings of these passes like any other's.
