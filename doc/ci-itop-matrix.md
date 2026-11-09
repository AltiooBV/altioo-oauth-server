# Checking the extension against real iTop installations

Unit tests answer "is this code right about itself". They cannot answer the question an
administrator actually has, which is "will this install on my iTop and still work". That one
is only answered by installing it — and by installing it on every version this repository
claims, because the version that breaks is rarely the one being developed against.

[`itop-matrix.yml`](https://github.com/AltiooBV/altioo-oauth-server/blob/main/.github/workflows/itop-matrix.yml)
does that on every pull request, and on a schedule, which matters more: nothing in this
repository changes when Combodo publishes a patch, and that is exactly when the claim quietly
stops being true.

**Why** each stage exists, and why they run in this order, is the development guide's
[§12.3](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/itop-extension-guide.md#123-ci-pipeline--the-actual-compatibility-claim);
the upstream iTop facts the scripts depend on are in
[branch notes §9](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/itop-branch-notes.md#9-ci--unattended-setup--itop-extension-guidemd-123).
This file says where each stage lives *here*, how to run it, and what it does not cover. The
links are absolute because this file ships in the release archive and those do not.

## What is checked, and where

| Step | Run by | What it would catch |
|---|---|---|
| A dry run (`--install=0`) selects the module | `install-itop.sh` with `DRY_RUN_ONLY=1`, job `installable` — no database | The setup **silently** dropping the extension: an unsatisfiable dependency removes a checkbox, it does not fail a setup |
| iTop's unattended setup completes | `install-itop.sh`, job `install` | A module declaration that no longer parses, an XML delta the compiler rejects |
| A row in `priv_module_install`, at the working copy's version | `install-itop.sh` | The setup completing without installing the module, or installing a stale copy an earlier run left in `extensions/` |
| A row in `priv_extension_install`, `source = extensions` | `install-itop.sh` | The module arriving by some route other than the one an administrator's install takes |
| `env-production/<module code>/` exists | `install-itop.sh` | Recorded as installed, but not compiled to where iTop loads it from |
| iTop's `ModuleIntegration` suite | `module-validation.sh` | Dictionary entries that do not resolve in the compiled environment — Combodo's test, run against this module |
| The module's own `Integration` suite | the workflow step, against `env-production/` | Everything that needs a live `MetaModel`, `UserRights` and a database |
| Post-setup checks | `itop-smoke.php`, then `checks/module-smoke.php` if present | The declared version against what compiled; then whatever this module adds — settings, classes, profiles — checked in the instance rather than in a fixture |
| The entry point over HTTP | `http-smoke.sh`, then `checks/http-smoke.sh` if present | A fatal before or inside the entry point (a 5xx, or no answer) on the compiled URL or the one under `extensions/`; then whatever this entry point must do — refuse an anonymous caller, accept a token, answer its own protocol |

Everything from the third row on exists because **"the setup succeeded" and "the module was
installed" are different statements** — guide §12.3 says why, and why the verdict is read from
`priv_module_install` and `priv_extension_install` rather than from the setup's output. The
scripts are under
[`tools/ci/`](https://github.com/AltiooBV/altioo-oauth-server/tree/main/tools/ci), each with
its own account at its head.

## What runs only when the tree has it

The harness adapts to the extension rather than the other way round, so a theme or a
datamodel-only package keeps every workflow unchanged and is not failed for what it does not
have:

| When the extension has no... | Skipped | Detected by |
|---|---|---|
| hand-written PHP under `src/` | Psalm and Progpilot (`ci.yml`) | `src/**/*.php` |
| integration suite | that step (`itop-matrix.yml`, `upgrade.yml`, `run.sh`) | `tests/php-unit-tests/Integration/` |
| HTTP entry point | `http-smoke.sh` | `index.php` at the root |
| module checks of its own | everything after the version check in `itop-smoke.php` | `tools/ci/checks/module-smoke.php` |
| entry-point checks of its own | everything after the generic half of `http-smoke.sh` | `tools/ci/checks/http-smoke.sh` |
| protocol conformance | (this module only) | `tools/ci/checks/conformance.sh`, sourced by the one above: RFC 8414, 9728, 7591, 6749 §5 and 7009, field by field, on every run of this workflow — the Monday schedule included. The OpenID Foundation suite is not used: this is not an OpenID provider |
| upgrade fixture | the seed and verify steps (`upgrade.yml`) | `tools/ci/upgrade-fixture.php` |
| production dependency | nothing — an empty SBOM is accepted | `composer sbom` |
| `index.php`, `.htaccess` or `web.config` (any one requires both guards), `NOTICE`, dictionaries, `tests/php-unit-tests/`, a file the module names as `<module code>/<path>` (a datamodel stylesheet or logo, an SCSS import) | their check in the archive (`tools/ci/build-archive.sh`, run by `ci.yml`'s `package` job and by `release.yml`) | the file in the source tree |

The last row runs the other way too: once the source tree *has* one of those files, the archive
must carry it, so an `exclude.txt` entry that drops a guard file, a dictionary or an asset fails CI on the
pull request rather than the release on the tag. Every `exclude.txt` entry starts with `/`: rsync
matches one without it at any depth, and `build-archive.sh` refuses it.

An extension that does have an entry point gets only the generic half from `http-smoke.sh`: both
URLs answer without a server error. Whether the entry point refuses a call without a credential
is not something a generic script can know, so nothing checks it until
`tools/ci/checks/http-smoke.sh` does. `http-smoke.sh` sources that file with `BASE`, `ENDPOINT`,
`ALT_ENDPOINT`, `ITOP_TOKEN` and `fail` in scope; the token is the one `checks/module-smoke.php`
minted, if it set `$sHttpSmokeToken`, and is empty otherwise.

## Which versions

[`.github/itop-support.json`](https://github.com/AltiooBV/altioo-oauth-server/blob/main/.github/itop-support.json)
declares **branches**, never patches, and its own `_comment` explains each field. It is the
single source of truth for the claim: the matrix is computed from it, and every prose copy of
the claim — README, Hub listing — should be checked against it rather than kept beside it.

`resolve-itop-versions.php` turns each branch into the newest patch of that branch at run
time, so the scheduled run installs whatever Combodo released last week without anybody
editing a workflow. To see what CI will use:

```bash
php tools/ci/resolve-itop-versions.php
```

The PHP versions come from the same file: `tools/ci/php-range.sh` reads the floor, the ceiling
and every minor between them, and every workflow - and `run.sh`'s defaults - takes its PHP from
there. No workflow names a version of its own; keep `composer.json`'s `"php"` constraint and its
`config.platform.php` pin on the same floor.

A `pin` on a branch selects the packaged release that is installed, not only the label the job
is named after: pinned to a patch, the run installs that patch, and a pin that matches no
packaged release fails the run instead of falling back to the newest.

Two consequences worth knowing when reading a run:

- **`allow_prerelease` jobs are `continue-on-error`.** A branch resolving to a beta or rc
  reports green whether it passed or not, so read those jobs individually.
- **The PHP ceiling may be ahead of what Combodo validates.** iTop's setup *warns* rather than
  fails on a PHP at or above its not-yet-validated version (branch notes §2 has the values). A
  green job there is this repository's claim about the module on that PHP, not Combodo's claim
  about iTop on it — the setup log of the run says which case applies.

## The order of the jobs

| Job | What it needs | What it answers |
|---|---|---|
| `unit` | PHP | is this tree worth installing |
| `versions` | PHP, the GitHub API | which iTop releases are we talking about |
| `installable` | PHP, the iTop archive — **no database** | would the setup select this extension at all |
| `install` | + MariaDB | does the setup compile and install it, and does it then work |

`unit` repeats, on one PHP version, the unit suite `ci.yml` already runs across the range. The
duplication is deliberate: `ci.yml` is a different workflow, so its result cannot gate a job in
this one, and without the gate every install job would start on a tree whose unit suite is red.

## The packaged release, never a git tree

The iTop under test is always the packaged release — what an administrator downloads. Source
tags do not carry every module a release ships (branch notes §9 names the ones known to be
missing), so a dependency on one of them is unsatisfiable on a git tree and the extension is
dropped without a failure.

The matching tag is still fetched, for one directory: `tests/php-unit-tests/`, which packaged
releases do not carry and which holds the `ItopDataTestCase` integration tests extend.
`install-itop.sh` overlays it onto the release and checks `ItopDataTestCase.php` arrived —
without it the integration suite would skip itself and report green. iTop's own code is left
exactly as published.

The module itself is copied in through `exclude.txt`, the same list the release archive is
built from, so what gets installed is what ships.

## The MCP stand-in

The `mcp` resource exists only where something registers the `MCP*` token scopes, which is
altioo-mcp's datamodel. CI does not install altioo-mcp, so without help the resource would simply
not exist on a CI instance and nothing about it would be checked. `install-itop.sh` therefore
also places every module found under `tools/ci/fixtures/<module code>/` in `extensions/`, beside
this one, and fails if the setup did not install it. There is one, `tools/ci/fixtures/altioo-mcp/`:

- **What it is.** Not altioo-mcp: a module with its code (the default `mcp` resource is
  `<modules root>/altioo-mcp/index.php` and reads that module's settings, so another code would
  test a configuration nobody runs), the five `MCP*` scopes the resource offers, the `MCP Services
  User` profile it requires, and `index.php`, which answers a Bearer token the way altioo-mcp asks
  this module about it (`TokenAudience`, `ResourceMetadata`) and speaks no MCP. It can never be
  installed beside the real module.
- **It never ships.** `exclude.txt` keeps `tools/` out of the archive, `build-archive.sh` is the
  check, and the baseline of `upgrade.yml` has no such directory, so upgrades install none.
- **What it lets CI check.** `module-smoke.php`: the resource's URL, grades, limit, portal grades
  and required profiles. `http-smoke.sh`: the consent page's grades, the refusal of an account
  without a required profile, the grades of an account with the portal and no console, a token
  accepted at its URL and refused at another portal's (`?portal_id=`), a REST/JSON token refused
  at the MCP endpoint and the reverse, and the resource's metadata.
- **What it does not.** How the real altioo-mcp reads a token, and whether the two modules still
  agree after either one changes. A scheduled job that installs a released altioo-mcp instead of
  the stand-in would, and is a follow-up: it needs a public, checksummed altioo-mcp archive to
  pin, and it fails for altioo-mcp's changes as well as this module's.

## An upstream test that does not exist

`module_integration.xml.dist` is Combodo's, and the step is worth having *because* it is theirs:
the one check here this repository did not write and cannot accidentally make agree with itself.
It names its test files one at a time, and a branch can name one it does not ship — PHPUnit then
fails at config-load time and the tests that do exist never run (branch notes §9 records where).

`module-validation.sh` runs the entries that exist, under an otherwise untouched copy of
Combodo's config, and names the ones that do not. Its header says what it still fails on — a
config naming no test files, or none of them existing — and why editing their config or letting
the step stay red would each have been worse. It needs no attention the day the file appears:
it is present, so it runs.

## Running it on a laptop

Everything CI does is in `tools/ci/`, and none of it is GitHub-specific beyond the `::group::`
markers. What is not portable is the machine underneath: a PHP inside the range `composer.json`
declares, a MariaDB, and somewhere disposable to install an iTop into.
[`tools/ci/local/run.sh`](https://github.com/AltiooBV/altioo-oauth-server/blob/main/tools/ci/local/run.sh)
supplies all three from Docker and needs nothing else installed. Its header lists the
subcommands — `unit`, `matrix <branch> <php>`, `integration`, `shell`, `down` — and it runs the
same `tools/ci/` scripts the workflow steps run; the only thing it adds is the machine. Without
versions it uses the first declared branch and the floor PHP, read with `jq`.

`matrix` records a verdict per step and carries on, the way `fail-fast: false` lets the real
matrix finish, then exits non-zero if any step failed. The installed iTop is kept in a Docker
volume, which is what makes `integration` a seconds-long loop rather than a full install.

**Trying a branch the matrix does not claim** takes one more step, and it is not optional.
`matrix` resolves its release through `resolve-itop-versions.php --matrix`, which iterates
`.github/itop-support.json`, so a branch absent from that file has no zip URL and the run stops
before it downloads anything. Add the entry, run it, and **take the entry back out**: that file
is the supported-versions claim, so a branch left in it after an experiment is how an untested
branch becomes a published promise. Naming the branch is what makes it testable; reverting is
what keeps it from being a claim.

On a machine that does have a PHP in range and a MariaDB, the scripts run directly:

```bash
composer install --no-dev --optimize-autoloader

# just the question "would it install", no database needed
ITOP_ZIP_URL=$(php tools/ci/resolve-itop-versions.php --zip=<branch>) \
  DRY_RUN_ONLY=1 ITOP_DIR=/tmp/itop tools/ci/install-itop.sh

# the whole thing, against a local MariaDB
ITOP_ZIP_URL=$(php tools/ci/resolve-itop-versions.php --zip=<branch>) \
  ITOP_TAG=<matching tag> ITOP_DIR=/tmp/itop DB_PWD=root tools/ci/install-itop.sh
```

Then, from `/tmp/itop/tests/php-unit-tests` (`composer install` there once):

```bash
php vendor/bin/phpunit --no-configuration --bootstrap unittestautoload.php --testdox \
  /tmp/itop/env-production/<module code>/tests/php-unit-tests/Integration
```

## What this does not check

- **The real altioo-mcp.** The MCP grant is exercised against the stand-in above, which agrees
  with this module by construction. See that section for what that leaves.
- **A web server's configuration.** The HTTP smoke uses PHP's built-in server, so it checks the
  application, not Apache or IIS. That `.htaccess` and `web.config` keep `src/`, `tests/` and
  `vendor/` unreachable is a manual step in the [release checklist](release-checklist.md).
- **Upgrades.** A separate workflow — [ci-upgrade.md](ci-upgrade.md). It costs two installs per
  baseline and answers a different question: not "does this install" but "what happens to the
  instance somebody already has".
- **Using it.** CI proves the module installs and answers; whether it does what an
  administrator installed it for is the "A real install" step of the
  [release checklist](release-checklist.md).
