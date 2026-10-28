# AGENTS.md — working in this repository as an assistant

For any AI assistant, whichever harness it runs in. `CLAUDE.md` imports this file for the one
tool that looks elsewhere. It is deliberately thin: almost everything below is a pointer.

`altioo-oauth-server` is an iTop extension that makes iTop its own OAuth 2.1 authorization
server, so that Claude web and other OAuth clients can reach altioo-mcp and iTop's REST/JSON
API with an ordinary iTop `PersonalToken`. The repository was created from the
[altioo-itop-extension](https://github.com/AltiooBV/altioo-itop-extension) template on
2026-10-28 and holds no extension code yet.

## 1. Read the development guide before the first edit

[`doc/itop-extension-guide.md`](doc/itop-extension-guide.md), §0 and "Using this guide",
documentation-only changes included. It is the generic guide, the same in every repository that
adopts it; what can expire is in [`doc/itop-branch-notes.md`](doc/itop-branch-notes.md),
dated. For Claude Code, `tools/hooks/guide-gate.sh` refuses the first edit until it is read.

## 2. Things nothing else states yet

- **The design lives outside the repository.** It is the draft "iTop OAuth extension — design
  note" (2026-10-28), kept privately by the maintainer. Ask for it before designing anything,
  and do not restate it here: when it is settled, it moves into `doc/` and this line goes.
- **A throwaway spike comes before production code.** The design note lists what it must
  prove; its code is not the start of the extension.
- **The template is not finished.** [`TEMPLATE-CHECKLIST.md`](TEMPLATE-CHECKLIST.md) lists
  what instantiation left open: `extension.xml` (CI is red without it), the `{{...}}` sections
  of `CONTRIBUTING.md` and `SECURITY.md`, the README, the changelog. Delete the checklist once
  it is done.

## 3. Decisions an agent could undo

Each line points at its record in `doc/adr/`. Read the record before arguing with the line.

- The module code, compiled directory, issuer path and settings key are all
  `altioo-oauth-server`, never the working name `altioo-oauth` (ADR 0001).
- Free and AGPL-3.0, like altioo-mcp: no paid edition, no licence check in the code (ADR 0002).
- Protected resources come from the `resources` setting. 1.0 ships two, altioo-mcp and
  REST/JSON, and no code path is specific to altioo-mcp (ADR 0003).

## 4. If your harness has its own instruction file

Add a pointer to this file, never a copy.

## 5. Agent skills

Settings agent skills read here.

- **Guide:** [`doc/itop-extension-guide.md`](doc/itop-extension-guide.md) — §1 above; the
  review passes are in it.
- **Review:** [`review/review-personas.md`](review/review-personas.md) — none added yet.
  Findings go beside it and are gitignored; only the list is tracked.
- **Decisions:** `doc/adr/`, numbered; each one an agent could undo also has a line in §3.
- **Tests:** `CONTRIBUTING.md`, "Before a pull request"; `tools/ci/local/run.sh` for the PHP
  floor and ceiling. Nothing runs until `extension.xml` exists.
