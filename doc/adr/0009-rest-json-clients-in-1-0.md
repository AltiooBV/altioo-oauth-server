# 0009 — Which REST/JSON clients 1.0 targets, and what that adds to the allowlist

- Date: 2026-11-09
- Decided by: Guy Couronne
- Status: accepted

Decided on 2026-11-09: option A below, in the maintainer's words "Accept 0009 A".

Closes the open question in [ADR 0003](0003-generic-from-the-first-release.md): which REST/JSON
clients 1.0 targets, and which redirect URIs that adds to the allowlist.

**This ADR covers REST/JSON clients only.** MCP clients such as Claude, ChatGPT and Cursor keep
registering dynamically against `redirect_uris`, where Claude's callbacks are already defaults,
and Claude Code also needs `allow_loopback`. Nothing here changes that list.

## What the research found

Read on 2026-11-09, vendor documentation only, with what was not established listed as such.
ADR 0003 already settles the constraint: `rest.php` reads the token from an `Auth-Token` header
or an `auth_token` parameter, never from `Authorization: Bearer`, and does not announce the
server, so no client discovers it.

**The allowlist is the wrong place to look.** `redirect_uris` binds only a client that
registered itself (`RedirectUriPolicy::AllowsForClient`, `source = dcr`). A client an
administrator creates is its own allowlist (README, *Clients configured by hand*). A REST/JSON
client cannot discover this server, so it is never a self-registering one: every candidate
below needs a hand-made client, and none needs an allowlist entry. The question "which
redirect URIs does 1.0 add" has the answer *none*, unless a client is found that both registers
dynamically and can send `Auth-Token`. None was found.

What decides whether a client works is where it can put the token:

| Client | Authorization code + PKCE | Token placement | Callback | Source |
|---|---|---|---|---|
| Postman | Yes, with PKCE | A header or the URL. The header is `Authorization`, with a configurable prefix; a custom header name or parameter name is **not documented**, so `Auth-Token` needs a pre-request script or a variable | `https://oauth.pstmn.io/v1/browser-callback` | [Postman, OAuth 2.0](https://learning.postman.com/docs/sending-requests/authorization/oauth-20/) |
| Bruno | Authorization code; PKCE not confirmed | Header (prefix configurable) **or a query parameter whose name is configurable**, so `auth_token` is reachable | not confirmed | [Bruno, collection-level OAuth 2.0](https://docs.usebruno.com/auth/oauth2-2.0/collection-level-configuration.md) |
| n8n | Yes, a separate PKCE grant type | Not documented for the OAuth2 credential; custom header or query names exist on its Header Auth and Query Auth credentials, which take a static value | "the OAuth Redirect URL in n8n", exact path not confirmed | [n8n, HTTP Request credentials](https://docs.n8n.io/integrations/builtin/credentials/httprequest.md) |
| Zapier | Yes, PKCE is a switch | Zapier adds the token itself; the access token is available to code as `bundle.authData.access_token`, so a request can carry it as `Auth-Token` | shown in the Zapier UI, not confirmed | [Zapier, OAuth](https://docs.zapier.com/platform/build/oauth) |
| A script or `curl` | The script's own business | Anything | RFC 8252 loopback: `http://127.0.0.1:{port}/...` | [RFC 8252](https://www.rfc-editor.org/rfc/rfc8252) |

Not established: Insomnia (its OAuth 2.0 page covered neither grant types nor placement),
Make, Node-RED, Grafana Infinity, Home Assistant (nothing on a third-party flow), and Power
Automate's custom connectors (the OAuth page returned 404; its per-connector redirect URI is
confirmed, its placement is not). "Not documented" is not "impossible": it means the
documentation does not promise it.

Two facts about this module bear on the table:

- **A loopback redirect does not work for a hand-made client unless the exact URI is typed.**
  An administrator-created client is matched with `in_array`, byte for byte; only a client that
  registered itself gets the any-port loopback rule, and then only with `allow_loopback`. A
  script's callback therefore has to use a fixed port, written into the client.
- **A token in a query string is logged.** `auth_token` as a URL parameter lands in web-server
  access logs and proxies. The header, or a POST body, does not. Bruno's parameter is a way to
  work, not one to recommend.

## Options

**A. No REST/JSON client is a default target; document the hand-made path. (Recommended.)**
1.0 adds nothing to `DEFAULT_REDIRECT_URIS`. The README's *Clients configured by hand* section
gains a short REST/JSON paragraph: the client must be able to send `Auth-Token` in a header,
what Postman, Bruno and a script do for that (as far as the vendors document it), that a
loopback callback needs a fixed port, and that the query parameter is the last resort. ADR 0003's
"still open" line is closed by this one.
- For: nothing new to maintain, no new phishing surface, and it is true to what is known: the
  clients that exist need an administrator anyway.
- Against: a person who wants "REST/JSON from Postman" follows a manual procedure, and the
  documentation can only be as exact as the vendors'.

**B. Name a set (Postman, Bruno, scripts) and add their callbacks to the default list.**
Would put `https://oauth.pstmn.io/v1/browser-callback` in the allowlist.
- Against: the entry serves no client that registers itself, so it protects nothing and
  permits something: any Postman user, or a phishing link, could register a client against it.
  The README already treats a shared forwarder as worth more to an attacker than an ordinary
  callback.

**C. Make `rest.php` accept `Authorization: Bearer`**, so that every OAuth client works.
- Against: that is a login extension on a core entry point, which the guide puts on the
  stop-and-ask list (§6.3, §6.9), and it is a different decision from this one. It would also
  change what `rest.php` accepts for every token on the instance. Rejected for 1.0; revisit if
  hand-made REST/JSON clients turn out to be common.

## Recommendation

A. It needs no code. It does need the README paragraph and, to be honest about what is claimed,
a real test: the maintainer's manual procedure (the hand-configured client, item 4) is where
Postman or a script is actually tried against itop.altioo.com. Until then the table above is
vendor documentation, not an observation, and the README says "none has been tested" as it does
today.

If the maintainer prefers a stated target for 1.0, **Postman** and **a script over a fixed
loopback port** are the two worth testing first: one is what an administrator is likely to have,
the other proves the contract without a vendor in the way.

Enforced by: nothing yet. Under A the binary part is that `DEFAULT_REDIRECT_URIS` contains no
REST/JSON client's callback; a test could assert the list against the README table. Not written.
