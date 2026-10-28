# Contributing

Issues and pull requests are welcome on
[AltiooBV/altioo-oauth-server](https://github.com/AltiooBV/altioo-oauth-server).

**Do not open a public issue for a suspected vulnerability.** [SECURITY.md](SECURITY.md) has
the private channels and what we commit to.

**This project runs on the [Contributor Covenant](https://github.com/AltiooBV/altioo-oauth-server/blob/main/CODE_OF_CONDUCT.md),
version 2.1**, across the issues, the pull requests and the review comments. It is the standard
text rather than a house rewrite, so that nobody has to read a page to find out whether the
usual terms apply: they do. Conduct reports go to <conduct@altioo.com>, which is a different
mailbox from the security one and carries none of that file's timetable.

## The rules every change is written to

[**doc/itop-extension-guide.md**](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/itop-extension-guide.md)
is the development guide this repository follows, and it is worth reading before the first
change rather than after the first review comment. It covers what an iTop extension may and
may not do — never modifying core, the supported extension points,
`UserRights` on every read and write, dictionaries in XML, the coding standard, packaging and
the release gate. Most review comments here are a pointer into one of its sections.

**It governs documentation as much as code**, which is the half that gets missed: its §0 makes
listing `doc/` and `.github/` a precondition for adding *any* document to this repository, so
that a new one does not duplicate a document already there. A change that touches no PHP at all
is still a change this guide is about.

Two things about it are worth knowing up front:

- **It has no shelf life on purpose.** No branch number, no version, no date in the guidance
  itself — only the copyright notice at the foot carries a year. Everything
  perishable — which PHP goes with which iTop patch, which extension points are deprecated on
  which branch, where a constant lives in the core source — is in
  [doc/itop-branch-notes.md](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/itop-branch-notes.md),
  which carries its own verification date. If you need to state a version to make a rule clear,
  the rule goes in the guide and the version goes in the branch notes.
- **When it is wrong, fix it in the same pull request as the work that revealed it.** That is
  the intended way to change it, and corrections are as welcome as code. A quietly wrong guide
  costs more than no guide.

Both are linked to the repository rather than by relative path on purpose, and so is the code
of conduct above: `exclude.txt` keeps all three out of the release archive, being Creative
Commons — CC BY-SA 4.0 for the two guides, CC BY 4.0 for the Covenant — in an
AGPL-3.0-or-later package. A copy of this file unpacked on an instance therefore has none of
them beside it, and a relative link would point at nothing.

It is a guide, not a permission slip: it describes how this project works, and it never
overrides what the iTop source actually does. Where a repository file and the guide disagree,
the file wins — `composer.json`, `phpcs.xml.dist` and `.github/itop-support.json` are the
project's real answers on dependencies, formatting and supported versions.

## Getting a change in

1. **Open an issue first for anything non-obvious** — a new public class, a new module
   parameter, a change to an identifier a client may have depended on. It is cheaper to find
   out there that a change belongs somewhere else than in this base extension.
2. **Branch from `main`**, named for what it does: `fix/…`, `feat/…`, `docs/…`, `test/…`.
   Nobody pushes to `main` directly.
3. **Commit as you go**, in the shape described under [Commits](#commits) below.
4. **Open the pull request against `main`.** Describe what changed and why; if it changes
   anything an operator or a downstream consumer can see, say so in the description and add the
   [CHANGELOG.md](CHANGELOG.md) entry in the same pull request — the changelog is what an
   administrator reads before scheduling a maintenance window, and an entry written later is
   an entry written from a diff.
5. **CI has to be green.** It runs the lint, the unit suite across the PHP range, and installs
   the module into every supported iTop branch —
   [doc/ci-itop-matrix.md](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/ci-itop-matrix.md)
   explains what each stage is actually asking. Run `tools/ci/local/run.sh unit` before opening
   a pull request and you will not learn it from CI.
6. **Review is by a maintainer**, and the merge is a maintainer's. History is not rewritten
   after review — see [How this extension is built](#how-this-extension-is-built) for why that
   matters here.

Changes to the public surface — any `@api` class and identifier this extension declares stable —
carry versioning consequences. [CHANGELOG.md](CHANGELOG.md) states what this project treats as
breaking.

## Before a pull request

```bash
composer install
composer test:unit
```

The unit suite needs neither iTop nor a database, so there is no excuse for a red one. The
integration suite needs a live iTop with the module installed *and* iTop's own
`ItopDataTestCase`, which is in the source tree at `tests/php-unit-tests/` and not in the
packaged releases; without it every test that needs it skips, which reads as green. Run it if
your change touches `MetaModel`, `UserRights` or the request pipeline:

```bash
ITOP_ROOT=/path/to/itop/web composer test:integration
```

If you would rather not keep an iTop of your own, `tools/ci/install-itop.sh` downloads a
packaged release, adds the harness from the matching tag, and installs this module into it with
iTop's unattended setup — the same script CI uses.
[doc/ci-itop-matrix.md](https://github.com/AltiooBV/altioo-oauth-server/blob/main/doc/ci-itop-matrix.md)
has the commands, and `tools/ci/local/run.sh` runs them in containers so nothing is installed
on your machine.

New behaviour comes with a test. A fix comes with the test that would have caught it.

Run the linter too. It is Combodo's coding standard, and the configuration is in the
repository, so there is nothing to decide:

```bash
composer lint
```

`composer lint:fix` applies the half of it that is mechanical. Keep that pass in a commit of
its own — a reformat mixed into a logic change hides the logic change.

## Commits

Small, and made as the work is done rather than swept up at the end. Each of these is one
commit:

| Unit | Commit |
|---|---|
| A class or attribute added to the datamodel | with the dictionary entries that label it, in both languages |
| One PHP class or listener | alone |
| A module parameter | with the code that reads it and the README row that documents it |
| A test file | alone |
| A lint or reformat pass | **alone — never with a logic change** |
| A version bump | with its changelog entry |

**Stage explicit paths.** `git commit -- path/a path/b`. Not `git add -A`, not `git add .`,
not `git commit -a`: those sweep whatever else is in the tree into your commit under a message
that describes only your change, and a commit message that lies about its contents makes the
history untrustworthy for whoever bisects it later. Read `git diff --cached --stat` before
committing. If a file you need also carries changes you did not make, commit without it.

Never commit `env-production/`, `conf/`, or anything else the iTop setup generates.

Messages in English, saying what changed and — when it is not obvious — why. The subject line
of the commits here names the problem rather than the patch, because six months later the
question being asked of the history is always "what was wrong", never "what did you type".

Every commit carries the trailer described in the next section — every commit a person or a
model wrote. Dependabot's do not, and should not: nothing authored them, and a trailer
claiming otherwise would be the first inaccurate one in the history.

**Before you call it done, run `git status`.** Either nothing of yours is outstanding, or say
plainly in the pull request what you left out and why.

## AI-assisted contributions

**They are welcome, and they must be disclosed.** Say so in the pull request — a line is
enough, naming the tool and what it wrote. This project is not in a position to ask otherwise:
see [How this extension is built](#how-this-extension-is-built) below.

Disclosure is not a formality, and it is not a disclaimer. Three things come with it:

- **You take responsibility for the code as if you had typed it.** "The model wrote it" is not
  an answer to a review comment, a bug report or a licence question.
- **You have read it, and you can explain it.** A pull request whose author cannot say why a
  branch is there does not get merged, regardless of who or what produced it.
- **You have the right to submit it.** Generated code can reproduce fragments of its training
  data. If you recognise a block as coming from somewhere else, say where.

The bar for the code itself is the same either way. It is the provenance record that differs,
and the record is the point: it is what lets a licence question asked in three years be
answered with something better than a guess.

## How this extension is built

{{HOW_THIS_EXTENSION_IS_BUILT}}

<!--
TEMPLATE NOTE, remove this comment on instantiation: keep a section under this
heading if a model wrote any of this extension's code, and describe your own
actual provenance here rather than copying another repository's dates,
signing-key history or squash decision - those are facts about that repository
on that date, and templated into a new one they become the first inaccurate
provenance claim in its history. The paragraphs worth keeping as policy
(explained in doc/itop-extension-guide.md and worth reusing verbatim) are: what
the `Co-Authored-By:` trailer records and why history is not rewritten to make
it uniform; why signing only starts at publication rather than being
backdated; and the Munich-decision / AGPL-enforceability reason this is
load-bearing rather than a disclaimer. State your own dates and decisions
alongside them.
-->

## Licence

Contributions are accepted under [AGPL-3.0-or-later](LICENSE), matching the extension and iTop
itself. Every source file carries the header; a new file needs one too. By opening a pull
request you confirm you may license your contribution on those terms.
