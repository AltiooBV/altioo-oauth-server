# 0003 — Generic from the first release: MCP and REST/JSON in 1.0

- Date: 2026-10-28
- Decided by: Guy Couronne
- Status: accepted

The first release protects two resources: altioo-mcp's endpoint (scope family `MCP*`) and
iTop's REST/JSON API (`webservices/rest.php`, scope `REST/JSON`). Resources are listed in the
`resources` setting, each with its scope family, and nothing in the code is specific to
altioo-mcp. A flow's `resource` parameter (RFC 8707) fixes which scope family may be granted,
so a flow for one resource can never mint another's scopes.

A call against iTop 3.2.3-3 on 2026-10-30 settled what `rest.php` does with a client:

- It does not announce the server. A call without credentials, or with a wrong token, answers
  `200` with `{"code":1,"message":"Error: Invalid login"}` and no `WWW-Authenticate`:
  `webservices/rest.php` turns every failed login into a REST result. A REST/JSON client
  therefore cannot discover the server from the resource. It is configured by hand, or given
  the metadata URL.
- It reads the token from the `Auth-Token` header or the `auth_token` parameter only
  (authent-token's `TokenLoginExtension`). `Authorization: Bearer`, as RFC 6750 sends it, is
  read as a Basic header and refused. A client that can send only that cannot use its token on
  REST/JSON.

Which REST/JSON clients 1.0 targets, and what that adds to the allowlist, is settled by
[ADR 0009](0009-rest-json-clients-in-1-0.md): none is a default target, and nothing is added.
REST/JSON is a single scope, so its consent screen offers no grade.

Rejected: MCP only in 1.0, with REST/JSON later.

Enforced by: `tools/ci/checks/http-smoke.sh`, for REST/JSON. It mints REST/JSON grants, for a
public client naming the resource and for a confidential client relying on its default one,
and calls `rest.php` with each token.

The MCP grant is exercised against a stand-in, not against altioo-mcp: CI installs
`tools/ci/fixtures/altioo-mcp/`, a module with altioo-mcp's code that registers the `MCP*` token
scopes, the `MCP Services User` profile and a Bearer endpoint, and nothing else
([doc/ci-itop-matrix.md](../ci-itop-matrix.md), "The MCP stand-in"). On it, `module-smoke.php`
holds the `mcp` resource's URL, grades, limit, portal grades and required profiles, and
`http-smoke.sh` runs the flow: the grades on the consent page, the refusal of an account without
a required profile, the portal-only account's grades, a token held to its URL through
`TokenAudience` (refused at another `?portal_id=` URL, and a REST/JSON token refused at the MCP
endpoint), and the resource's metadata. What this does not show is how the real altioo-mcp reads
a token or whether the two modules still agree. A scheduled job that installs a released
altioo-mcp is a follow-up and not built.
