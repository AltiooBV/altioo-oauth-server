# 0006 — An account with only the portal chooses read, or everything it may do there

- Date: 2026-11-02
- Decided by: Guy Couronne
- Status: accepted

A portal-only account was offered the same grades as a console account, for altioo-mcp:
read only; read and change; read, change and delete; everything. For such an account this
module cannot tell writing from deleting, and altioo-mcp's portal mode has no delete yet. What
the account may actually do there is the stricter of its profiles and its portal, which the
resource applies at every call. The profile alone overstates it: iTop's standard *Portal user*
profile allows delete on the `Portal` group, which the portal does not offer.

The decision:

- A portal-only account is offered two grades: **read only**, or **everything I can do in my
  portal**. The second is the umbrella scope (`MCP`). What it covers follows the account's
  profiles and portal at every call, so a change there needs no new consent. If the portal
  mode gains delete one day, this grade covers it with no new consent either.
- This is generic: a resource may set `portal_grades`. When it is set, an account without the
  console is offered those grades and the resource's limits, and nothing else. Without it,
  every account is offered the same. Console accounts are unchanged.
- A connection is held to the kind of account it was made for. It ends at its next refresh
  when its account gains the console (as before) or loses it (new), so that the person
  consents again and is offered that kind's grades.

Rejected: narrowing the offer to what each account's iTop rights allow (`UserRights` per
action). For a portal account the profile allows more than the portal does, and only the
resource knows which iTop action a scope stands for.
Rejected: a separate offered-scopes list per account kind, with the console grades kept for
portal accounts. It would still show "read, change and delete" to an account that cannot
delete.

Enforced by: `ProtectedResourceTest` (a portal account is offered only the portal grades and
the limits) and `AccountsTest` (a change of account kind in either direction is detected).
`tools/ci/checks/http-smoke.sh` refreshes a connection made portal-only whose account has the
console, and expects it to end. The opposite direction needs a portal-only account allowed
personal tokens, which the smoke instance does not have.
