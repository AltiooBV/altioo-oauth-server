# Security summary — altioo-oauth-server

The page an approver reads before this module reaches an instance: the questions security,
compliance and procurement ask every vendor, answered once (guide §9.8). Each answer is short and
points at the file that holds the detail, so that this page cannot drift from it.

**State:** 0.1.0, not released. Refresh this page with every release: the answers below describe
the tree it ships in, and a row that no longer holds is a release blocker, not a typo.

## What it is

An OAuth 2.1 authorization server inside iTop. It lets Claude and other OAuth clients obtain an
ordinary iTop `PersonalToken` for a person who signs in to iTop and consents. It adds endpoints
that answer callers who are not signed in. [README.md](../README.md) says what it does;
[SECURITY.md](../SECURITY.md) gives its threat model and hardening advice.

## Software bill of materials and licences

| | |
|---|---|
| Runtime dependencies | None beyond iTop and its bundled `authent-token` module. `composer.json` requires only PHP and Composer's runtime API; the shipped `vendor/` holds the autoloader alone |
| SBOM | `sbom.cyclonedx.json`, CycloneDX, beside each release archive, built from `composer.lock` (`composer sbom`) |
| Licence inventory | `licenses.json`, beside each release archive (`composer licenses`) |
| The module's own licence | AGPL-3.0-or-later, in [LICENSE](../LICENSE), `composer.json` and `extension.xml` alike. No paid edition and no licence check ([ADR 0002](adr/0002-free-under-agpl-3.md)) |

## Provenance

| | |
|---|---|
| Build | By CI from a `v*` tag only ([release.yml](../.github/workflows/release.yml)): unit suite against the tagged tree, `composer audit --locked`, then the archive (`tools/ci/build-archive.sh`) |
| Checksum | `<archive>.zip.sha256` beside the archive, checked by the workflow against the build job's own digest before publishing |
| Attestation | GitHub build provenance (`actions/attest-build-provenance`), verifiable with `gh attestation verify` |
| Who can publish | **Not named yet.** Whoever can push a `v*` tag publishes a release. The names are a first-publication item in [release-checklist.md](release-checklist.md), and this row is filled in from it |

## Vulnerability process

[SECURITY.md](../SECURITY.md): two private channels (GitHub's advisory form and
<security@altioo.com>), acknowledgement within 5 working days, a fix or mitigation for a
confirmed high-severity issue within 90 days, five years of security fixes per minor version, and
which reports are in scope. `composer audit --locked` runs in CI on every change and on a
schedule ([ci.yml](../.github/workflows/ci.yml)). **Before the first publication**, the advisory
channel has to be enabled and the mailbox confirmed (release checklist). Until then, the response
windows have no route to arrive on.

## Footprint and least privilege

| | |
|---|---|
| To install | An iTop administrator, through setup. Setup adds three tables and two menus under *Admin tools* |
| To administer | The *Administrator* profile. The module's three classes are outside `grant_by_profile`, so no other profile reads or changes them. The module adds no profile |
| To connect | Any account allowed personal tokens by authent-token (`personal_tokens_allowed_profiles`) and accepted by the resource (README, *Which accounts can connect*). A token never grants more than the account's own rights |
| HTTP entry points | `env-production/altioo-oauth-server/index.php` and two discovery paths. The module's `.htaccess` and `web.config` refuse every other file (README, *What the module's `.htaccess` and `web.config` allow*) |
| Outbound connections | **None.** The module makes no network request of its own: no call to a client, an identity provider, Altioo or anyone else |
| Filesystem writes | **None** of its own. It writes to the iTop database, the PHP session iTop opens, and iTop's error log |
| Background work | One process, hourly from iTop's cron: ends connections past their lifetime, deletes spent codes, old ended connections and registrations never used (README, *What it adds*) |

## Data processing

| | |
|---|---|
| Personal data | The iTop user each connection belongs to; the IP address a client registered from and a connection was last refreshed from; when each connection was made, renewed and ended. Client names and redirect URIs are the client's, not a person's |
| Secrets | Codes, refresh tokens and client secrets are stored only as salted one-way hashes. The access token is authent-token's `PersonalToken`, stored as authent-token stores it. No secret is written to a log, a URL or the change history |
| Storage | Three tables in the iTop database: `priv_altioo_oauth_client`, `priv_altioo_oauth_grant`, `priv_altioo_oauth_code` |
| Retention | Codes: one day after they expire. Registrations never used: 24 hours. Ended connections: `ended_grant_retention_days`, 90 by default. Active connections end after `grant_idle_days` unused or `max_grant_days` in all, 30 and 90 by default (README, *Settings*) |
| What leaves the instance | **Only the tokens a person consents to**, sent to the client they consented to, at the address the administrator allowed. Through those tokens, the client reads what that person may read in iTop: that is the purpose of the module, and its limits are the consent page and the person's own rights. **There is no telemetry**: nothing is sent to Altioo or any third party |

## Attribution of automated changes

Every change the module makes on its own is recorded in iTop's change history with origin
*custom-extension* and a user info naming it: "OAuth: <client>" for a token created, renewed or
deleted for a client; "OAuth: registration from <address>" for a self-registration; "OAuth:
hourly clean-up" for what the clean-up deletes. A change a person makes (*Disconnect*, *Revoke*)
names the person (src/Service/PersonalTokens.php, `Track` and `AsPerson`).
