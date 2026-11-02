# 0007 — A protected resource asks which resource a token was granted for

- Date: 2026-11-02
- Decided by: Guy Couronne
- Status: accepted

authent-token knows a `PersonalToken`'s scopes, not the resource a person consented to. Two
resources that are one endpoint told apart by a query (altioo-mcp's `?portal_id=`, one resource
per portal) would each accept a token granted for the other. The connection already records
its resource, so this module answers the question for any resource that asks:

- `TokenAudience::Of($iPersonalTokenId)` gives the key and URL of the resource the owning
  connection was granted for. It answers `null` when no connection owns the token, such as one
  an administrator made by hand. Whether to accept that token is the resource's own rule.
- `IsFor($sUrl)` compares exactly, as the authorization request's `resource` did. A resource
  since removed from the `resources` setting has no URL, and matches none, so the check fails
  closed.
- It is generic and names no resource (ADR 0003). It is the module's first `@api` class. A
  resource that may run without this module installed checks `class_exists()` first.

Rejected: an attribute on `PersonalToken` naming the resource. The connection already holds it,
and the binding belongs where the consent is (altioo-mcp ADR 0007 rejected it too).

Enforced by: `TokenAudienceTest`, and `tools/ci/checks/module-smoke.php` on an installed iTop:
a live connection's token, a revoked one's, a token no connection owns, and one whose resource
is no longer configured.
