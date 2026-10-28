# 0004 — One `PersonalToken` per grant, renewed in place

- Date: 2026-10-28
- Decided by: Guy Couronne
- Status: accepted

Each connection (an `AltiooOAuthGrant`) owns exactly one `PersonalToken`. A refresh gives that token a
new value and a new `expiration_date` in place, the way the console's rebuild button does
through authent-token's `AuthentTokenService`. It does not create a new row. The person then
sees one token per connected application under My Account, the way Jira, Entra or GitHub show
one entry per authorised app, and deleting it ends the connection. The token's history gains
one entry per refresh instead of a creation and a deletion every hour, and no expired rows pile
up. The local spike of 2026-10-28 showed that authent-token accepts this, with the change
history naming the client and this module.

The previous value stops working at the moment of the refresh, not when it expires. That is
stricter than OAuth requires, and is allowed.

The refresh token is rotated on every use. To keep a lost response from ending a connection,
the previous refresh token is accepted again for a short grace period after rotation
(`refresh_grace_seconds`, 30 by default), and a new pair is issued. After that period, any use
of a rotated refresh token ends the grant and deletes its token.

A connection ends after `grant_idle_days` without a refresh, or after `max_grant_days` in all,
whichever comes first. The second limit bounds how long a connection outlives the person's
removal from an identity provider while their iTop `User` stays enabled.

Rejected: a new `PersonalToken` on every refresh, with the previous one deleted (the design
note's first version). It gives OAuth's usual overlap between old and new access tokens. The
cost is a row created and deleted every hour per connection, a deletion record in the history
each time, and a My Account list that no longer maps to connections.
Rejected: one long-lived `PersonalToken` per grant, never renewed. A leaked access token would
live for weeks.

Enforced by: `tools/ci/checks/http-smoke.sh`. After a refresh it checks that the old access
token is refused and the new one works on REST/JSON, and that the previous refresh token is
accepted once within the grace period.
