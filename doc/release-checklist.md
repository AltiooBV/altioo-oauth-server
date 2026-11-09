# Release checklist

What has to be true before an archive is published, and what the release run has to prove.
The development guide's
[§12](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/itop-extension-guide.md#12-maintenance)
says why each of these matters; this file is the order to do them in.

## Before the first publication

Done once. Tick each item with the date and what was actually done, rather than deleting it:
a finished checklist is worth reading afterwards only for that record. A second release does
not repeat these — *Every release* below is the recurring list.

- [x] **Make the repository public**, if it is to be. 2026-11-04: public. The URLs in `extension.xml`
      (`more_info_url`), `composer.json` (`homepage`, `support.*`), the module declaration's
      `doc.*` entries and the README only resolve for a reader once it is.
- [x] **Enable private vulnerability reporting**, in the same sitting (2026-11-04, enabled): it is
      public-repository-only, and it is the channel [SECURITY.md](../SECURITY.md) names first.
      Until it answers, the response windows SECURITY.md commits to have no route to arrive on.
- [x] **Create the security mailbox** SECURITY.md names as the alternative to GitHub, and
      confirm somebody reads it. 2026-11-04: confirmed by the maintainer.
- [x] **Decide when Actions run.** 2026-11-04: from the first push, the repository being public. Minutes are metered on a private repository and free on a
      public one, and three workflows carry a `schedule` — scheduled workflows run from the
      **default branch**, so a tree landed on `main` while private starts installing a full iTop
      per PHP version weekly with nobody pushing anything. Either disable Actions until the
      repository is public, or keep the tree off the default branch until then.
- [x] **Rehearse the release.** 2026-11-04: run 41874173647, on `66fc12b`. Run
      [release.yml](https://github.com/AltiooBV/altioo-oauth-server/blob/main/.github/workflows/release.yml)
      by `workflow_dispatch`: it builds and checksums the archive **without publishing
      anything** — the one rehearsal of a path that otherwise runs for the first time on the
      tag itself.
- [x] **Name the publishers.** 2026-11-04: Guy Couronné (GitHub `GurneyHallack`). Who can push a `v*` tag is who can publish a release; an
      approver asking "who can publish" is asking about people, not about a workflow file
      (guide §9.8). Write the names down where the audit evidence lives, and revisit them
      whenever that stops being true rather than at the next release.

- [x] **Write the extension's own checks.** 2026-10-28: `tools/ci/checks/module-smoke.php`
      (classes, settings, resources, discovery of the clean-up process and the My Account
      section, Revoke, the clean-up) and `tools/ci/checks/http-smoke.sh` (the whole flow, as a
      public client and as a confidential one, refresh in place, the grace period, Disconnect).
      Adapt [ci-itop-matrix.md](ci-itop-matrix.md), [ci-upgrade.md](ci-upgrade.md) and this
      file to what they assert. Delete a row that does not apply.
- [x] **Write `tools/ci/upgrade-fixture.php`** once 0.1.0 is tagged: the upgrade workflow
      installs the previous release and upgrades it, and until there is one there is nothing
      to seed. It must seed a client, an active connection and its token, and check after the
      upgrade that the connection still refreshes. Until then `upgrade.yml` proves the setup, not
      the data: on 2026-11-04, run 41875576197 upgraded from `3c3b97f` to `66fc12b` and skipped
      its seeding and *Is everything still there* steps. The maintainer saw connections from
      earlier builds still active in the console of itop.altioo.com.
      2026-11-09: written and run locally, in Docker, on iTop 3.2.3-3 / PHP 8.2: `v0.1.0`
      installed, seeded, upgraded to the working copy, verified, upgraded again, verified
      again — all checks green, the connection refreshing over HTTP each time. With a row
      edited by hand the fixture fails and names it. **Not proved:** a run of `upgrade.yml` on
      Actions with it, and any upgrade across a real migration — `v0.1.0` and the working copy
      are the same version, so the setup only re-applied it. Re-open this line when a
      change first alters the schema or the version.
- [x] **Clean up after the flow.** 2026-10-28: `src/Service/CleanUpProcess.php`, hourly.
- [x] **Let an administrator revoke a connection** from the console, and the person from My
      Account. 2026-10-28: the Revoke stimulus on `AltiooOAuthGrant`, and *Connected
      applications* under *Personal Tokens*.
- [x] **Connect claude.ai** to a test instance over HTTPS, and one hand-configured client,
      as the release gate in the design note says; record what was tested in the changelog.
      2026-11-04: narrowed by the maintainer. Tested on itop.altioo.com with Claude Code, Claude
      Desktop and claude.ai on the web; the hand-configured client is recorded as untested.
- [x] **Complete the README** against the guide's §9.3. 2026-10-28, before any release: check
      it again against what 0.1.0 actually ships, the upgrade procedure in particular.
- [x] **Write `doc/security-summary.md`**, the page an approver reads (guide §9.8).
      2026-11-02: written for 0.1.0. Its *Who can publish* row is still empty until the
      publishers are named above; refresh the whole page at every release.
- [x] **Start `CHANGELOG.md`** with the first version, in Keep a Changelog format, and give
      SECURITY.md's *Supported versions* its first row, both dated the day of the tag. 2026-11-04.

## Every release

### Version

The same `x.y.z` in every place this repository declares it — at least `extension.xml` and
`module.<code>.php`; guide §12.1 lists the usual set and says to establish the actual one —
with a [CHANGELOG.md](../CHANGELOG.md) entry under a dated heading, not under `[Unreleased]`.
`release.yml` refuses a tag that disagrees with `extension.xml`; a test that fails when the
declarations drift from each other catches it before the tag.

The number moves with the first change that needs it, not on the day of the tag: the change
that makes a release minor or major sets the version, and its entries go under
`## [x.y.z] - Unreleased` instead of `[Unreleased]`, so a build deployed for testing already
says which version it is on its way to. The date stays out of the heading until the tag.

### Dates

On the day of the tag and not before. Two files carry one:

1. **[CHANGELOG.md](../CHANGELOG.md)** — fold everything under `[Unreleased]` into the version
   heading, and replace its `Unreleased` with the date: `## [x.y.z] - YYYY-MM-DD`. Anything left
   under `[Unreleased]` ships in the tag without appearing in its notes, which is how a reader
   ends up unable to tell what a version contains. `release.yml` refuses the tag on either
   count, so this is checked rather than remembered.
2. **[SECURITY.md](../SECURITY.md)** — set the release date and the end date in *Support
   period*, and the row in *Supported versions*. This is the one date with an outside commitment
   attached: dated early, it promises less than the period SECURITY.md commits to.

Write both from the tag's own date. A date guessed in advance is wrong by however long the
release then slips, and nothing downstream will notice.

### `@since` tags

For an extension with PHP under `src/`:
[`tools/reconcile-since.py`](https://github.com/AltiooBV/altioo-oauth-server/blob/main/tools/reconcile-since.py)
rewrites every `@since` there to the version the symbol actually first appeared in, deriving the
answer from git rather than from the previous run, so it converges however the tags have been
edited in between. Nothing calls it — it rewrites source, so a person runs it and reads the
diff.

For the first release there is no baseline revision to compare against — its tag is what this
release creates — and the answer is known anyway: every symbol first appeared in it. Pass the
commit being tagged as the baseline, so everything present is written with the first version
and nothing is newer:

```bash
tools/reconcile-since.py . HEAD <first version> <next version>
```

From then on, the baseline is the tag being replaced:

```bash
tools/reconcile-since.py . v<released> <released> <next>
```

### Branch notes

Re-verify
[itop-branch-notes.md](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/itop-branch-notes.md)
against the branches this release actually claims, and update the date at its head. It is the
one document that holds facts with a shelf life, and guide §12.5 makes this a release gate.

### Green CI

The linter, the unit suite across the PHP range, `composer validate --strict`, `composer
check-platform-reqs` and `composer audit --locked` — which `release.yml` also runs on the tag
build, since a tag ref matches neither of `ci.yml`'s triggers.

### Green iTop matrix

`itop-matrix.yml` installs the module with iTop's unattended setup on the newest patch of every
branch in `.github/itop-support.json`. Check the run resolved the versions you mean to claim —
it prints them in the job summary — and that its `installable` and `install` jobs are green for
every one of them.

Read the prerelease jobs individually rather than trusting the overall colour: `allow_prerelease`
entries run `continue-on-error`. [ci-itop-matrix.md](ci-itop-matrix.md) explains what each check
is for.

### The archive is built by CI, not by hand

Pushing a `v*` tag runs `release.yml`, in three jobs that never share a machine:

1. **`tests`** runs the unit suite with the development dependencies, which means running
   their code. It can read the repository and nothing else.
2. **`build`** refuses a tag that disagrees with `extension.xml` or the changelog, audits the
   lock, builds the production vendor tree on the floor PHP and runs
   `tools/ci/build-archive.sh`: the zip through `exclude.txt`, checked for what it must and
   must not carry, with its `.sha256`, a CycloneDX `sbom.cyclonedx.json` and `licenses.json`.
   Also read-only.
3. **`publish`**, on a tag only, is the one job that can sign and write. It runs none of this
   repository's code and none of its dependencies: it downloads what `build` produced, checks
   the zip against the digest `build` reported as a job output (not against the `.sha256`
   travelling beside it, which anything able to replace the zip could replace too) and the SBOM and licence
   inventory against their copies inside it, adds the build-provenance and SBOM attestations, and attaches
   everything to the release.

The split is the point: a compromised development dependency runs in `tests`, which holds
nothing worth taking, and cannot reach the archive the attestation vouches for. The SBOM and licence inventory also go *inside* the archive, so an instance found
in a year's time can answer what it is running without reaching the internet.

Publish the SHA-256 wherever the download is announced. `vendor/` ships, so "the file I
downloaded is the file CI built" has to be a question with an answer.

### A real install

CI installs on every supported branch; what it cannot do is use the thing. This is the part
that needs hands — start from the published archive, not from a build tree:

1. Download the archive the release workflow built and check it against the published SHA-256,
   or with `gh attestation verify <archive>.zip --repo AltiooBV/altioo-oauth-server`.
2. Unzip it into a clean iTop at `<itop>/extensions/<module code>/`.
3. Run the setup, tick the extension, complete it.
4. Use what the module adds, the way the README tells an administrator to — and for anything
   that writes, confirm the write is attributed in the object's history.
5. If the module ships `.htaccess` and `web.config`: confirm that `src/`, `tests/` and
   `vendor/` under `<itop>/env-production/<module code>/` are **not** reachable over HTTP,
   while whatever the guard grants (`index.php`, if there is one) is. Check the compiled tree
   first: the environment root
   grants PHP for its whole subtree, so that is where this fails. Then repeat it under
   `<itop>/extensions/<module code>/`, which holds the same files.
6. Record which iTop patch and which PHP this ran on, in the changelog entry for this version.
   One home for that answer: the README points at it and any listing is copied from it.

### Upgrade path

Automated: `upgrade.yml` installs the previous release, seeds data if the module has a fixture,
upgrades to the working copy and checks nothing was lost — see [ci-upgrade.md](ci-upgrade.md).
Before a release, run it once from the tag being replaced (`workflow_dispatch`, `baseline_ref`)
and read its summary. Before the first release there is no tag to replace, so drive it by hand
from an earlier commit and record which one you used.

What stays manual is the part that needs a browser: log into the upgraded instance and confirm
the console still shows everything the module added — menus, profiles, settings, objects.

### Archive contents

`vendor/` present and built with `--no-dev`; `README.md`, `SECURITY.md`, `CHANGELOG.md`,
`CONTRIBUTING.md`, `LICENSE` and `doc/` present; `tests/` present (iTop's own Extensions
testsuite scans `env-production/`); `sbom.cyclonedx.json` and `licenses.json` present; no
`.git`, no `tools/`, nothing Creative Commons. `tools/ci/build-archive.sh` checks all of it on
the archive it builds — including every guard file, `NOTICE`, dictionary and asset named as
`<module code>/<path>` that the source tree has — and the same script runs in `ci.yml`'s `package`
job on every pull request and in `release.yml` on the tag, so the release cannot find a problem CI
did not.

### What else the release changed

Re-read whatever this repository keeps for readers other than developers — a Hub listing, an
audit summary (guide §9.8) — against what the release actually changed: the supported-versions
line, the tested-on line, the dependency licences, and anything added to the HTTP surface. In a
quiet release there is usually nothing to do; confirm that rather than assume it.
