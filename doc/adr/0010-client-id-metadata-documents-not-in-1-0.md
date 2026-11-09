# 0010 — Client ID metadata documents: not in 1.0, with the conditions to revisit

- Date: 2026-11-09
- Decided by: pending — Guy Couronne
- Status: proposed

Answers the "for 1.0" half of [ADR 0005](0005-no-client-id-metadata-documents-in-0-1.md).

## What was read, on 2026-11-09

- **The MCP specification** (the `latest` pages, whose links all point at the `2026-07-28`
  revision; the pages themselves show no date, so that is an inference). Clients and servers
  SHOULD support client ID metadata documents (CIMD). Dynamic client registration (DCR) is a MAY
  and carries a warning: it "is deprecated" and "remains available for backwards compatibility".
  **No removal date is given.** A client SHOULD try pre-registered credentials, then CIMD if the
  server advertises `client_id_metadata_document_supported`, then DCR, then ask the person. For a
  server that fetches a document: SHOULD fetch, MUST check that the document's `client_id` equals
  its URL and that redirect URIs match it, SHOULD respect cache headers, SHOULD consider SSRF.
  The security page also asks that the redirect URI's host be shown on the consent page.
- **The IETF draft** is now `-03`, dated 2 November 2026, still an active working-group draft
  with no intended RFC status. ADR 0005's list of the draft's MUSTs was taken from `-02`. It must
  be read again against `-03` before anything is built.
- **Clients**, from each vendor's own documentation (no changelogs were readable, so *whether a
  client's fallback changed over time* is not established; these are today's statements):

| Client | CIMD | Falls back to DCR | Source |
|---|---|---|---|
| Claude (web, Desktop, mobile, Claude Code) | Yes. Client IDs: `https://claude.ai/oauth/mcp-oauth-client-metadata` (hosted apps; observed, not named in the docs), `https://claude.ai/oauth/claude-code-client-metadata` (named) | **Yes, stated.** It chooses CIMD only when the server advertises `client_id_metadata_document_supported: true` **and** `none` among `token_endpoint_auth_methods_supported`, "and otherwise falls back to DCR" | [Claude, connector authentication](https://claude.com/docs/connectors/building/authentication.md) |
| ChatGPT | Yes, and it "prioritizes CIMD when it is available". Client IDs `https://chatgpt.com/oauth/client.json`, or `https://chatgpt.com/oauth/{callback_id}/client.json` | Implied: it "can register dynamically when ... CIMD is not available" | [OpenAI, Authentication](https://developers.openai.com/plugins/build/auth) |
| Cursor | Not mentioned | DCR is the documented default; a static client ID otherwise | [Cursor, MCP](https://cursor.com/docs/mcp) |
| Gemini CLI | Not mentioned | DCR "if supported" is its only automatic path | [Gemini CLI, MCP servers](https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md) |
| VS Code | Not mentioned | Not mentioned: its documentation requires a static `clientId` | [VS Code, MCP configuration](https://code.visualstudio.com/docs/agents/reference/mcp-configuration) |

Two things in that table are not what the README assumes. VS Code's documentation names no
dynamic registration at all, so the default allowlist's `vscode.dev` entry is for a flow this
module has not seen it use. And no client in the table is documented as having *dropped* DCR.

## Options

**A. Not in 1.0; revisit on stated conditions. (Recommended.)**
1.0 keeps ADR 0005's behaviour and still advertises no `client_id_metadata_document_supported`.
- For: every client this module targets works today by dynamic registration or by an
  administrator-made client. The specification keeps DCR with no removal date. It spares the
  first stable release its first outbound request and the defences that go with it, which
  `doc/security-summary.md` says today it does not have ("Outbound connections: None"). The draft
  is still moving.
- Against: Claude and ChatGPT are already CIMD clients, so a CIMD-capable server gets a stable
  client identity and no registration rows (the unused-registration clean-up and its flood limit
  exist because of DCR). A server that stays DCR-only is on the deprecated path, and the day a
  vendor stops falling back, a documented client stops connecting until CIMD ships.

**B. CIMD in 1.0.**
- For: the direction of the specification, and no registration flood for the clients that use it.
- Against: it is a design change with its own review (ADR 0005 says so), the draft is not final,
  and none of the five clients needs it.

### Revisit when any of these is true

1. A client this module targets, in the table above, documents that it no longer falls back to
   DCR, or ships a release that does not.
2. The MCP specification dates DCR's removal, or drops it to a note.
3. The IETF draft is approved for publication as an RFC.
4. A target client appears that supports CIMD and not DCR.
5. The registration flood, or the unused-registration clean-up, becomes a recurring cost for the
   operators.

Condition 1 can be checked cheaply: once a quarter, read the five vendor pages again and the
dated release notes where there are any. Writing that check into the release procedure, not into
CI, is the part nobody can automate.

## Design sketch, for whoever builds it

Every constraint in ADR 0005 holds; this is how they are met, not a replacement for reading `-03`.

- **Fetch only from an administrator's list.** A `client_id_metadata_urls` setting beside
  `redirect_uris`, defaulting to the vendors' published URLs above, exact strings. An unlisted
  `client_id` URL is refused without any request. This answers ADR 0005's "the administrator
  stays the allowlist" and the guide's rule that outbound URLs come from an admin-configured
  allowlist (§6.4), and it removes most of the SSRF surface: the host is the vendor's, not the
  caller's.
- **The fetch itself**, still defended as if the list were open: resolve the host and refuse a
  result in an RFC 6890 special-use range (outside a loopback test setup), connect to the address
  that was checked rather than resolving again (DNS rebinding), follow no redirect, accept only
  `200`, a short timeout, read at most about 5 KB, cache neither an error nor an invalid
  document, and respect cache headers with a ceiling.
- **The document**: `client_id` equal to its URL, as strings; redirect URIs matched exactly
  against it; **public only**, with no secret and `token_endpoint_auth_method: none`, bound by PKCE
  as a self-registered client is. Claude's second, confidential document (`private_key_jwt`) is not
  supported.
- **A native client's loopback redirect**, which Claude Code's document gives as
  `http://localhost/callback` without a port, needs the any-port rule, so it is accepted only
  under `allow_loopback`, as now.
- **Advertising**: `client_id_metadata_document_supported: true`, kept next to `none` in
  `token_endpoint_auth_methods_supported`, which Claude requires and the metadata already lists.
  DCR stays advertised: it is the fallback for every client that is not a CIMD client.
- **The consent page** shows the `client_id`'s host, as the specification asks.
- **Documents to update**: `doc/security-summary.md` (outbound connections, to the list and the
  fetch), the README's requirements (the outbound access the instance needs), and
  `tools/ci/checks/http-smoke.sh`, whose ADR 0005 check (metadata must not name
  `client_id_metadata_document_supported`) is inverted. A test double for the fetch is needed in
  CI: no CI job should call claude.ai.

Enforced by: unchanged under A: `tools/ci/checks/http-smoke.sh` fails if the metadata names
`client_id_metadata_document_supported`. The revisit conditions are not machine-checkable.
