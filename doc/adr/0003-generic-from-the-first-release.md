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

Still open: which REST/JSON clients 1.0 targets, and which redirect URIs that adds to the
allowlist. REST/JSON is a single scope, so its consent screen offers no grade.

Rejected: MCP only in 1.0, with REST/JSON later.

Enforced by: `tools/ci/checks/http-smoke.sh`, for REST/JSON. It mints REST/JSON grants, for a
public client naming the resource and for a confidential client relying on its default one,
and calls `rest.php` with each token. The MCP grant is not exercised: CI does not install
altioo-mcp.
