# AGENTS.md — working in this repository as an assistant

For any AI assistant, whichever harness it runs in. `CLAUDE.md` imports this file for the one
tool that looks elsewhere. It is deliberately thin: almost everything below is a pointer.

`altioo-oauth-server` is an iTop extension that makes iTop its own OAuth 2.1 authorization
server, so that Claude web and other OAuth clients can reach altioo-mcp and iTop's REST/JSON
API with an ordinary iTop `PersonalToken`. [`README.md`](README.md) says what it is for
its users.

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
- **The module is at its first release, 0.1.0.** The spike it replaces lives on
  `spike/claude-connect`. What is still open from the first publication is in
  [`doc/release-checklist.md`](doc/release-checklist.md), "Before the first publication".

## 3. Decisions an agent could undo

Each line points at its record in `doc/adr/`. Read the record before arguing with the line.

- The module code, compiled directory, issuer path and settings key are all
  `altioo-oauth-server`, never the working name `altioo-oauth` (ADR 0001).
- Free and AGPL-3.0, like altioo-mcp: no paid edition, no licence check in the code (ADR 0002).
- Protected resources come from the `resources` setting. 1.0 ships two, altioo-mcp and
  REST/JSON, and no code path is specific to altioo-mcp (ADR 0003).
- A grant owns one `PersonalToken`, and a refresh renews its value in place. Never create a new
  row per refresh (ADR 0004).
- Clients register dynamically or are created by an administrator; 0.1.0 has no client ID
  metadata documents and never advertises them. Adding them is a design change, not a fix
  (ADR 0005).
- An account with only the portal is offered a resource's `portal_grades` and limits, nothing
  else: for altioo-mcp, read only or everything it may do in its portal. A connection ends when
  its account gains or loses the console (ADR 0006).
- `TokenAudience` and `ResourceMetadata` are the generic, `@api` ways a resource asks which
  resource a token was granted for and where its metadata is; `null` means no connection owns
  the token, and a removed resource matches no URL (ADR 0007).
- A resource is named by its `label` wherever a connection appears, and the consent page also
  gives its URL (ADR 0008).
- 1.0 has no client ID metadata documents either, on ADR 0005's line. Revisit only on the
  conditions listed in ADR 0010, not because a client prefers them (ADR 0010).

## 4. If your harness has its own instruction file

Add a pointer to this file, never a copy.

## 5. Agent skills

Settings agent skills read here.

- **Guide:** [`doc/itop-extension-guide.md`](doc/itop-extension-guide.md) — §1 above; the
  review passes are in it.
- **Review:** [`review/review-personas.md`](review/review-personas.md) — the guide's passes plus
  five this extension adds: the outsider with a link, the grant that grows, the OAuth client, the
  person who connects, the identity administrator. Findings go beside it and are gitignored;
  only the list is tracked.
- **Decisions:** `doc/adr/`, numbered; each one an agent could undo also has a line in §3.
- **Tests:** `CONTRIBUTING.md`, "Before a pull request"; `tools/ci/local/run.sh` for the PHP
  floor and ceiling. Nothing runs until `extension.xml` exists.
