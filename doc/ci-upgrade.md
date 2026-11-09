# Checking what an upgrade does to an instance that already exists

The [iTop matrix](ci-itop-matrix.md) installs this module into an empty database. That run
cannot lose anything, because there is nothing there to lose. The question it leaves
unanswered is the one every client past their first install has: **what happens to the
instance I already have.**

[`upgrade.yml`](https://github.com/AltiooBV/altioo-oauth-server/blob/main/.github/workflows/upgrade.yml)
answers it by doing what they do — install an older version, use it, drop the new files over
it, re-run the setup — and then looking for what should still be there. What an upgrade may and
may not do to a client's data is the development guide's
[§9.7](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/itop-extension-guide.md#97-upgrading-a-client-from-an-earlier-extension-version);
this file is how this repository checks it.

## Why an upgrade needs its own test

A module upgrade in iTop is a recompilation. The datamodel is rebuilt from the XML and the
schema is altered to match, and **that is a destructive operation with no confirmation step**:
an attribute that stopped being declared takes its column with it, a renamed class leaves its
table behind and starts an empty one. The setup reports success either way, because from where
it stands both outcomes are correct — it cannot tell an attribute removed on purpose from one
removed by accident.

Neither can CI, unless something was put into the instance first and looked for afterwards.
That is all the fixture is.

## The three runs

| Baseline | Why |
|---|---|
| The **oldest** release tag | The skip-version path. A client going from the first release to the latest calls the installer **once**, with the oldest version — the jump nobody tests and everybody eventually makes |
| The **newest** release tag | The ordinary one-step upgrade |
| The **same version, twice** | A failing setup aborts half way; the administrator fixes the cause and re-runs. Every migration therefore executes twice, so the second run has to pass as cleanly as the first |

The third is not a separate job: every baseline runs `upgrade-module.sh` twice and verifies the
fixture after each. With a single release tag the first two are the same release and run once.

All of it runs on one iTop — the oldest supported branch at the floor PHP — rather than the
matrix: what is under test is this module's own version-to-version path, and that is the
instance a client who upgrades extensions but not iTop is actually running.

## The fixture

`tools/ci/upgrade-fixture.php` is this extension's own, and the template deliberately ships
none: what is worth seeding depends entirely on what the module stores. Called with `seed`
before the upgrade and `verify` after each run of it, it is where the module states what an
upgrade must not lose. Without one, the seed and verify steps skip and the upgraded instance is
checked by `itop-smoke.php` and the integration suite alone — which is the whole of it for an
extension that stores nothing.

This extension's fixture seeds what its header lists, and both its modes then use the connection over HTTP,
as its client would: a change that leaves every row in place and refuses every refresh fails here too.

What tends to earn a row, and what its loss would mean:

| Seeded | What its loss would mean |
|---|---|
| Rows in each class the module declares | The data did not survive the schema alter |
| A value with an accent and an apostrophe, one at a column's length limit | A column recreated with a different charset or length; a value that went through unescaped SQL |
| Each class's list of attribute codes | An attribute was removed between the two versions — reported **by name**, because "some data is missing" is not an actionable failure |
| A core object carrying a value the module adds by delta (an enum value, a scope, a profile) | The delta stopped declaring it, so everything that relied on that value silently lost it |
| A module setting changed from its default | The administrator's tuning reverted (see `<previous_configuration_file>` below) |

## What the upgrade script asserts

[`upgrade-module.sh`](https://github.com/AltiooBV/altioo-oauth-server/blob/main/tools/ci/upgrade-module.sh),
after the setup:

- the setup exited `0` and printed `installed!` — necessary, and on its own worth nothing (guide
  §12.3 says why);
- `priv_module_install` now reads the working copy's version;
- **it holds at least two rows** for the module. An upgrade adds to that history; if the older
  row is gone, the instance was reinstalled rather than upgraded — which would also explain any
  fixture data that survived, and would quietly turn this whole workflow into a second
  fresh-install test;
- `priv_extension_install` still says `source = extensions`;
- `env-production/<module code>/` exists and its `extension.xml` agrees with the database.

Three details of the setup call matter enough that the script's header explains each: **no
`--clean`**, which would drop the data under test; **`<previous_configuration_file>`**, without
which module settings revert to their defaults; and **`<sample_data>0</sample_data>`**. The
module files are copied over the old ones **without `--delete`**, on purpose: that is what a
client unzipping into `extensions/` does, leftovers and all.

## Running it

Until the first release tag exists, the scheduled and push runs have no baseline and the jobs
**skip** — visibly, with a summary line saying why, rather than passing green on nothing. Drive
it by hand from any earlier commit instead:

```bash
gh workflow run upgrade.yml -f baseline_ref=<sha-or-branch>
```

Locally, against a MariaDB of your own — the same scripts CI calls:

```bash
composer install --no-dev --optimize-autoloader

# the baseline: an older checkout, installed the ordinary way
git worktree add /tmp/baseline <older-ref>
composer install --no-dev --optimize-autoloader -d /tmp/baseline
ITOP_ZIP_URL=$(php tools/ci/resolve-itop-versions.php --zip=<branch>) \
  ITOP_TAG=<matching tag> ITOP_DIR=/tmp/itop MODULE_SRC=/tmp/baseline DB_PWD=root \
  tools/ci/install-itop.sh

# use the instance (if there is a fixture), upgrade it, look for what you left there
ITOP_DIR=/tmp/itop DB_PWD=root php tools/ci/upgrade-fixture.php /tmp/itop seed
ITOP_DIR=/tmp/itop DB_PWD=root tools/ci/upgrade-module.sh
ITOP_DIR=/tmp/itop DB_PWD=root php tools/ci/upgrade-fixture.php /tmp/itop verify
```

`upgrade-module.sh` refuses to run against a tree that is not an installed iTop, and against an
instance where this module is not installed at all — both are the same mistake, made early.

## What this still does not check

- **A client's own data.** The fixture is what this module writes plus whatever core objects it
  touches. An instance with years of tickets and a dozen other extensions is a different
  upgrade.
- **A migration, while there is none.** A working copy at the baseline's version, as on the first
  run from `v0.1.0`, re-applies that version: `upgrade-module.sh` says so in a notice. That run
  shows the fixture works and that the setup keeps the data; what a schema or version change does
  is first tested by the change that makes one.
- **Downgrade.** Say in the README whether it is supported; if it is not, nothing tests it
  because the answer is "restore the backup".
- **The browser.** That the console still shows what the module added — its menus, profiles,
  settings — after an upgrade is a step in the [release checklist](release-checklist.md), not a
  job here.
