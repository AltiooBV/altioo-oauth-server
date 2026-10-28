# 0003 — Generic from the first release: MCP and REST/JSON in 1.0

- Date: 2026-10-28
- Decided by: Guy Couronne
- Status: accepted

The first release protects two resources: altioo-mcp's endpoint (scope family `MCP*`) and
iTop's REST/JSON API (`webservices/rest.php`, scope `REST/JSON`). Resources are listed in the
`resources` setting, each with its scope family, and nothing in the code is specific to
altioo-mcp. A flow's `resource` parameter (RFC 8707) fixes which scope family may be granted,
so a flow for one resource can never mint another's scopes.

Two questions this decision opened are still unanswered: whether `rest.php` answers an
unauthenticated call with `401` and `WWW-Authenticate`, so REST/JSON clients can discover the
server; and which REST/JSON clients 1.0 targets, and which redirect URIs that adds to the
allowlist. REST/JSON is a single scope, so its consent screen offers no grade.

Rejected: MCP only in 1.0, with REST/JSON later.

Enforced by: not yet. The integration suite is to mint a REST/JSON grant and call `rest.php`
with it, next to the MCP grant.
