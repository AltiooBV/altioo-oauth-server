# 0008 — A resource is named by its label wherever a connection appears, its URL on consent

- Date: 2026-11-02
- Decided by: Guy Couronne
- Status: accepted

With one resource per portal (altioo-mcp ADR 0007), a resource's URL differs from another's by a
query a person does not read. The resource's optional `label`, a dictionary key or plain text,
is therefore what names it:

- on the consent page, followed by the URL in a note beneath it (`Its address: …`). The label
  is an administrator's words, and two resources may share one by mistake; the URL is what the
  token is held to (ADR 0007);
- under *Connected applications*, in My Account and in the portal's *My profile*, in an
  *Access to* column, so that two connections of one application can be told apart;
- under *OAuth connections* in the console, as "label (key)", through a `GetAsHTML` overload as
  authent-token makes for its own token. A resource no longer configured shows its key.

A resource's `url` may carry a query when the query is what tells two resources apart, as
RFC 8707 allows.

Enforced by: `tools/ci/checks/http-smoke.sh` (the consent page gives the resource's URL under its
name) and `tools/ci/checks/module-smoke.php` (the console shows the label and key, and the key
alone for a resource no longer configured).
