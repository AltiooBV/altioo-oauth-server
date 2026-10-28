# Template checklist

`doc/itop-extension-guide.md` §9.1 warns about scaffold leftovers before a
first release. This file is how that warning gets actioned for what this
template hands you. Work through it once, then delete this file along with
`tools/instantiate.sh` - both describe the templating step, not the extension
you are building.

## 1. Run the substitution

```bash
tools/instantiate.sh \
  --module-code my-extension \
  --github-org MyOrg \
  --security-email security@example.com \
  --conduct-email conduct@example.com \
  --itop-branch 3.2 \
  --php-floor 8.2 \
  --php-ceiling 8.4
```

It rewrites every `{{PLACEHOLDER}}` token it knows about and then greps for
any it does not - if that grep finds something, a file was added to the
template after this script was last touched, and needs the same treatment.

## 2. What the substitution cannot do for you

- **Write the extension itself.** This template deliberately carries no
  `extension.xml`, `module.<code>.php`, datamodel XML, `src/`, `index.php` or
  `register.php` - those are what makes your extension *this* extension, not
  boilerplate to template over. Use iTop's own extension-creation tooling or
  `doc/itop-extension-guide.md` §1-§2 to start them, and read the whole guide
  before the first line of datamodel.

  **`tools/ci/*` needs `extension.xml` to exist before any of it runs.**
  Every script under `tools/ci/` (and `local/run.sh`) resolves the module
  code from `extension.xml`'s `<extension_code>` at run time rather than
  from a literal - that is what lets this harness carry over unchanged - but
  that means CI is red, not silently skipped, until `extension.xml` exists
  with the right code in it. Write it before the first push, not after the
  first red run.
- **Fill in `CONTRIBUTING.md`'s "How this extension is built" section** and
  `SECURITY.md`'s extension-specific sections (marked with `TEMPLATE NOTE`
  comments in each file). These are facts about *your* repository - its own
  history, its own threat model - not something a sibling repository's answer
  can be copied into. Copying another repository's dates, signing-key history
  or squash decision here would be the first inaccurate provenance claim in
  this one's history; `doc/itop-extension-guide.md` explains why that
  matters and `CONTRIBUTING.md`'s comment says what is policy (reusable) vs.
  fact (yours to state).
- **Write `README.md` and `CHANGELOG.md`.** Deliberately not templated -
  see `doc/itop-extension-guide.md`'s guidance on what a README must cover
  (§9.3) and start `CHANGELOG.md` from its first entry, in Keep a Changelog
  format, the day you cut `0.1.0` or `1.0.0`.
- **Populate `.github/ISSUE_TEMPLATE/feature_request.yml` and `config.yml`**
  if this extension has its own extension point (a plugin mechanism, a tool
  pack convention) - both carry a `TEMPLATE NOTE` where that link goes, and
  are fine to leave generic if there is no such split.
- **Adapt `doc/ci-itop-matrix.md`, `doc/ci-upgrade.md` and
  `doc/release-checklist.md` to what this extension has.** They ship generic,
  genericised from `altioo-mcp`'s, so the links to them from `CONTRIBUTING.md`,
  the branch notes and `tools/` resolve from the first commit. What they
  cannot say for you: what `checks/http-smoke.sh` and `checks/module-smoke.php` assert
  for this module, what its upgrade fixture seeds, what "a real install"
  exercises, and who the publishers are. Delete a row that does not apply
  rather than leave it describing a module this is not.
- **Write `doc/security-summary.md`** before the first public release:
  `doc/itop-extension-guide.md` §9.8 lists what an approver asks every vendor
  (bill of materials, provenance, vulnerability process, footprint, data
  processing), and this is where it is answered once instead of per client.
  The release workflow produces the evidence - the checksum, the provenance
  and SBOM attestations - but not the page that tells an approver where it
  is and how to verify it.

## 3. Decide `exclude.txt` for every file you add

Not optional, and easy to forget precisely because forgetting is silent: a
new root-level or `doc/` file that nobody classified ships to a customer
instance by default. Write each entry with a leading `/`: rsync matches an
entry without one at any depth, so `build` would also drop `src/build/` and
every `vendor/<package>/build/`. `tools/ci/build-archive.sh` refuses an
unanchored entry. `doc/itop-extension-guide.md` §9.1 and `AGENTS.md` §4
(if you copy one, see below) say why.

## 4. Nothing to delete for what this extension does not have

The harness adapts to the tree rather than the other way round, so a theme or
a datamodel-only package keeps every file here unchanged.
`doc/ci-itop-matrix.md`, "What runs only when the tree has it", lists what is
skipped and what detects it - there rather than here, because this file is
deleted after instantiation and that one stays.

An extension that does have an entry point: `http-smoke.sh` requests
`env-<env>/<module code>/index.php` and `extensions/<module code>/index.php`
and checks only that both answer without a server error, and
`tools/ci/build-archive.sh` then requires `.htaccess` and `web.config` in the
archive - as it does for a module without one that ships either guard. What the entry point itself must do goes in
`tools/ci/checks/http-smoke.sh` (section 5).

The PHP versions follow `.github/itop-support.json` too: every workflow, and
`run.sh`'s defaults, take the floor, the ceiling and the unit matrix from it
through `tools/ci/php-range.sh`. Keep `composer.json`'s `"php"` constraint and
its `config.platform.php` pin on the same floor; nothing else needs editing
when the range moves.

## 5. Write `tools/ci/checks/module-smoke.php`, `tools/ci/checks/http-smoke.sh` and `tools/ci/upgrade-fixture.php`

Deliberately not included: they are this extension's own post-setup checks
and upgrade fixture, the way `itop-smoke.php`'s split in `altioo-mcp` (see
`AGENTS.md` there) separates the generic harness from the module's own
checks. `itop-smoke.php` in this template already includes
`tools/ci/checks/module-smoke.php` if present - write that file with your
own settings/class/scope checks, following the shape of the one in
`altioo-mcp` (`tools/ci/checks/module-smoke.php` there). `upgrade-fixture.php`
needs the same kind of extension-specific rewrite; there is no generic
version to copy. Both are optional for an extension that stores nothing: the
upgrade workflow then checks the upgraded instance with `itop-smoke.php`
alone.

`tools/ci/checks/http-smoke.sh` is the same split for an HTTP entry point.
`http-smoke.sh` sources it, after its generic checks, with `BASE`,
`ENDPOINT`, `ALT_ENDPOINT`, `ITOP_TOKEN` and `fail` in scope. Write in it what
this entry point must do: refuse a call without a credential (§6.3), accept
one with the token `module-smoke.php` minted - by setting `$sHttpSmokeToken` -
and answer its own protocol. Without it, nothing checks that the entry point
refuses an anonymous caller.

## 6. Point your own harness's instruction file at your own `AGENTS.md`

If you adopt `AGENTS.md` as the repository brief the way `altioo-mcp` does,
write your own - it is deliberately not part of this template because it
names *this* repository's own layout and conventions. Point whichever
harness-specific file you use (`CLAUDE.md`, `GEMINI.md`,
`.github/copilot-instructions.md`, ...) at it with a one-line import, never a
copy - `AGENTS.md` §5 in `altioo-mcp` explains why.

## 7. Add the branch once it is actually exercised

`.github/itop-support.json`'s `branches` array ships with one entry from
`--itop-branch`. Add a second branch only once you have actually installed
on it - the whole matrix, floor and ceiling of the PHP range - never before.
The file's own `_comment` says why.

## 8. Add this extension's own review passes

A review runs the guide's passes - the personas every iTop extension faces -
plus the ones in `review/review-personas.md`, which ships with none. Add the
ones that come from what this extension is - an endpoint an AI agent calls, a
theme people use with a screen reader, an SLA computation a contractor checks
- each with its question, what to open here, what it blocks, and the guide
pass it follows. Add nothing that every extension could answer: that is a
change to the guide, for all of them. The file never lists the guide's own
passes, so a change to the guide reaches it without an edit.

If you write an `AGENTS.md` (section 6), give it a `## Agent skills` section
whose **Review** key points at this file, and say there that findings go
beside it, gitignored. Only the list is tracked: `.gitignore` ignores
everything else in `review/`, and `exclude.txt` keeps the folder out of the
archive.
