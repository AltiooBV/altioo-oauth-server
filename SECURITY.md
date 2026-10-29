# Security

This extension adds endpoints that answer callers who are **not signed in**: anyone can
register a client, and the token endpoint takes no session. What it issues are credentials
that act as an iTop user and outlive that user's sign-in. It is an authorization server, and
deserves to be reviewed as one.

**Nothing is released yet.** The sections below that are specific to this extension state the
design that release must meet. They are checked against the code by the review passes in
[`review/review-personas.md`](review/review-personas.md) before the first archive is published.

## Reporting a vulnerability

Report it privately, through either channel:

- **GitHub** — [Report a vulnerability](https://github.com/AltiooBV/altioo-oauth-server/security/advisories/new)
  on `AltiooBV/altioo-oauth-server`. Preferred: it keeps the report, the discussion and the
  eventual advisory in one place, and it works without you having to trust an email route.
- **Email** — <security@altioo.com>, if you would rather not use GitHub, or if you cannot
  reach it.

What we commit to:

| | |
|---|---|
| Acknowledgement | Within **5 working days** |
| Assessment, with a severity and a plan | Within **10 working days** of acknowledgement |
| Fix or documented mitigation, confirmed high severity | Within **90 days** of acknowledgement |
| Credit | In the CHANGELOG entry and the advisory, unless you ask us not to |

If 90 days pass without a fix or an agreed extension, publish. We will not ask you to sit on a
report indefinitely, and a deadline that only one side can move is not coordinated disclosure.

Please do not open a public issue for a suspected vulnerability. Include the iTop version, the
extension version, the PHP version, and whatever reproduces it.

**Out of scope**, because they are decisions rather than defects: iTop's own permissions letting
a user reach something you did not expect through this extension's own module settings; and
anything reachable only by an administrator, who can already edit the datamodel.

**In scope, squarely:** a code, access token or refresh token reaching anyone other than the
person who consented and the client they consented to; a token wider, stronger or longer-lived
than the consent page showed; the consent page misleading a person about who is asking or where
they will be sent back; any way past the instance's login or MFA; a connection that survives the
person deleting its token under *My Account*; and any secret written to a log, a URL or the
change history.

**Out of scope, too:** what a client does with a token it was legitimately given, within what
the person consented to. That is the client's behaviour, not this extension's defect.

## Supported versions

| Version | Status |
|---|---|
| — | No version has been released. The first release adds its row here, dated the day it is published. |

Fixes are issued as a new patch of every minor still inside its support period below, not only
of the newest one. The extension follows iTop's own branch policy: a version supported here runs
on the iTop branches named in the README, and a branch that Combodo has retired is not tested
against.

### Support period

**Five years of security fixes from the release date of a minor version.**

The date is the day the archive actually became installable, not the day the work finished: a
commitment dated from anything else runs short by however long the release slipped, and the five
years is a floor rather than a target — not a number to spend on a delay in the repository.

Five years is the floor the EU [Cyber Resilience Act](https://eur-lex.europa.eu/eli/reg/2024/2847/oj)
sets for a product with digital elements, and it is worth committing to here whether or not this
extension ends up inside that Act's scope. Whether it does turns on whether it is supplied in
the course of a commercial activity — a question about how it is offered, not about anything in
this repository.

Ending support for a minor earlier than that would be announced in the CHANGELOG and in the
GitHub releases **six months** ahead. It will not happen quietly.

### Single point of contact

<security@altioo.com>, alongside the GitHub advisory channel above. The same address should be in
the README, in this file, and in `composer.json` under `support.email` — and all three ship
inside the release archive, so it travels with the extension instead of living only on a web
page, which is the point of the requirement. (`support.security` is a URL to this file on
GitHub, which is the field's meaning and is not a contact address.)

Where the CRA's 24-hour and 72-hour reporting duties for an *actively exploited* vulnerability
apply, they are met from that address, and they run in parallel with the timetable above rather
than replacing it: a reporter still gets an acknowledgement within 5 working days.

### What ships beside the archive

| File | What it answers |
|---|---|
| `<archive>.zip.sha256` | "is the file I downloaded the file CI built" |
| `sbom.cyclonedx.json` | CycloneDX inventory of every production dependency |
| `licenses.json` | the licence of each of those dependencies |

The SBOM is what makes a same-day vulnerability answer possible. When a CVE lands on something
under `vendor/`, "does this extension ship it, and at which version" has to be answerable in
minutes. Regenerate both with `composer sbom` and `composer licenses`.

## What the extension does, in security terms

- **Endpoints, all unauthenticated by design:** the authorization server metadata, the
  protected resource metadata for each resource, client registration, and the token endpoint.
  The authorize endpoint sends the person through iTop's own login before it shows anything.
- **What it stores:** registered clients; pending authorization requests and codes, which live
  for seconds; and grants. A client secret, code or refresh token is stored only as a salted
  hash.
- **What it issues:** access tokens, which are iTop `PersonalToken` objects owned by the person.
  Each has an `expiration_date`, so iTop refuses one past its date even if this module is
  broken or removed. Refresh tokens are rotated on every use.
- **Who can be a client:** any client that registers itself, as a public client bound by
  PKCE, if its redirect URIs are on the administrator's allowlist; and clients an
  administrator creates in the console, which may be confidential, with a secret, and may be
  allowed to skip PKCE.
- **What it does not do:** grant any right the person lacks. The token is checked by
  `authent-token` and the resource like any other personal token, and reaches only what that
  user's profiles allow.

## Threat model

| Who | Can | Defence |
|---|---|---|
| Anyone on the internet | Register a client, send a person a crafted authorize link, call the token endpoint at will | Redirect URIs matched exactly against the administrator's allowlist, at registration and again at every authorize; no redirect before the client and its redirect URI are checked; PKCE S256 required with no plain fallback; codes single-use, short-lived and bound to client, redirect URI and resource; the consent page cannot be framed and its form carries iTop's transaction id |
| A legitimate client | Ask for more than it was given, keep a token after consent is withdrawn | Scopes fixed by the `resource` parameter and the administrator's caps, enforced on the server; every refresh re-checks the account, its profiles and that the token still exists; reuse of a rotated refresh token ends the grant |
| Someone holding a confidential client's secret | Exchange that client's codes and refresh its grants | The secret alone gets nothing: a code is issued only after a person signs in and consents, and only to a redirect URI the administrator entered. The secret is stored as a one-way hash; a public client presenting a secret is refused rather than believed |
| Someone holding a stolen refresh token | Use it alongside the client | The first of the two to refresh invalidates the other's; reuse ends the grant for both |
| A person removed from the identity provider whose iTop `User` stays enabled | Keep a connection made before | Grants have a maximum lifetime the administrator sets. The README states that the connection outlives deprovisioning until then, and how to end it sooner. |

## Hardening the deployment

- Serve iTop over **HTTPS** only. OAuth over plain HTTP hands every code and token to the network.
- Keep the **redirect URI allowlist** to the clients the instance actually uses.
- Set the **maximum grant lifetime** to what the organisation's offboarding can tolerate.
- Restrict `personal_tokens_allowed_profiles` if not everyone who signs in should be able to
  connect a client.

## Dependencies

None beyond iTop. The extension depends on the `authent-token` module that ships with iTop
and on PHP's own functions. If a Composer dependency is ever added, the SBOM above is where it
shows up.
