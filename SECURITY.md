# Security

{{EXTENSION_ONE_LINE_RISK_SUMMARY}}

<!-- TEMPLATE NOTE, remove on instantiation: state in one or two sentences what makes this
     extension worth a security page of its own - a new HTTP entry point, a new set of
     permissions, direct MetaModel/UserRights access, an outbound connection, or "nothing beyond
     what installing any iTop extension already implies" if that is honestly the case. -->

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

<!-- TEMPLATE NOTE, remove on instantiation: add your own extension-specific out-of-scope and
     in-scope examples here, the way altioo-mcp's SECURITY.md names a caller exceeding their own
     iTop permissions as squarely in scope. Generic boilerplate is not a substitute for stating
     what this extension actually guards. -->

## Supported versions

| Version | Status |
|---|---|
| {{VERSION}}.x | **Supported.** Released {{RELEASE_DATE}}, security fixes until {{SUPPORT_END_DATE}} |

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

{{WHAT_THE_EXTENSION_DOES_SECURITY_SECTION}}

<!-- TEMPLATE NOTE, remove on instantiation: this section, and Threat model / Hardening the
     deployment / Dependencies below, are the extension-specific half of this file and cannot be
     templated - see altioo-mcp/SECURITY.md for the shape a thorough version takes (~500 lines,
     covering every entry point, every permission check, and the deployment hardening that
     follows from them). Do not ship this file with only the generic sections filled in; an
     extension that adds no new attack surface should say so explicitly rather than leave this
     section as a placeholder. -->

## Threat model

{{THREAT_MODEL}}

## Hardening the deployment

{{HARDENING_THE_DEPLOYMENT}}

## Dependencies

{{DEPENDENCIES}}
