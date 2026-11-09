#!/usr/bin/env bash
#
# Refuse the first edit of a session until the development guide has been read.
#
# The development guide, doc/itop-extension-guide.md, asks to be read - §0
# first - before the first change, and an AGENTS.md brief, where a repository
# has one, repeats it. Nothing enforced that,
# and an assistant that decided the guide was about code rather than about this
# task edited the repository without it - which is the failure this exists to
# make impossible rather than merely discouraged.
#
# Two modes, wired to two hooks in .claude/settings.json:
#
#   record   PostToolUse on Read. Marks the session when the guide is the file
#            that was read. Always exits 0: it observes, it never blocks.
#   gate     PreToolUse on Write|Edit. Denies, with the reason, until that mark
#            exists. Files outside this repository are never gated - a
#            scratchpad write is nobody's business but the caller's.
#
# What it cannot check is whether enough of the guide was read. A Read with a
# small limit satisfies it. That is the honest limit of a filesystem gate: it
# makes skipping the guide deliberate rather than accidental, which is the
# difference that was missing.
#
# The marker lives outside the repository, keyed by session id, so it never
# reaches a commit and never leaks between sessions.

set -uo pipefail

readonly MODE="${1:-}"

# The repository root, derived from this script's own location rather than from
# an environment variable: tools/hooks/ -> two levels up. A hook runs with a
# working directory nobody promised us.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly ROOT
readonly GUIDE="$ROOT/doc/itop-extension-guide.md"
readonly MARKER_DIR="${TMPDIR:-/tmp}/altioo-oauth-server-guide-gate"

sPayload="$(cat)"

# jq absent, or a payload that is not JSON: allow, and say so on stderr. A gate
# that fails closed on its own tooling stops the session dead over a missing
# dependency, which is a worse failure than the one it guards against.
if ! command -v jq >/dev/null 2>&1; then
	echo "guide-gate: jq is not installed, so the guide gate is not enforcing." >&2
	exit 0
fi

sSessionId="$(printf '%s' "$sPayload" | jq -r '.session_id // empty' 2>/dev/null)"
sFilePath="$(printf '%s' "$sPayload" | jq -r '.tool_input.file_path // empty' 2>/dev/null)"
[ -n "$sSessionId" ] || sSessionId='no-session'
readonly sMarker="$MARKER_DIR/$sSessionId"

# Absolute path, symlinks resolved where the file exists, so a relative
# file_path and a path through a link both compare correctly.
absolute() {
	local sPath="$1"
	[ -n "$sPath" ] || return 1
	case "$sPath" in
		/*) ;;
		*) sPath="$ROOT/$sPath" ;;
	esac
	if [ -e "$sPath" ]; then
		readlink -f "$sPath" 2>/dev/null || printf '%s' "$sPath"
	else
		printf '%s' "$sPath"
	fi
}

# Every working tree of this repository: the main checkout and each linked
# worktree, which may sit inside it (.claude/worktrees/<name>) or elsewhere. The
# hook runs from the project directory of the session that started it, so its
# ROOT is whichever checkout that was; a session working in another worktree
# reads, and edits, that worktree's copy of the files. Without this list the
# guide read there never matched ROOT's, and the gate stayed shut for good.
# Falls back to ROOT alone when git cannot say.
worktree_roots() {
	printf '%s\n' "$ROOT"
	git -C "$ROOT" worktree list --porcelain 2>/dev/null | sed -n 's/^worktree //p'
}

case "$MODE" in
record)
	sRead="$(absolute "$sFilePath")" || exit 0
	# Only the guide counts, in any worktree: no other file, and no path that
	# merely ends in the same name.
	while IFS= read -r sTree; do
		[ -n "$sTree" ] || continue
		if [ "$sRead" = "$(readlink -f "$sTree/doc/itop-extension-guide.md" 2>/dev/null || printf '%s' "$sTree/doc/itop-extension-guide.md")" ]; then
			mkdir -p "$MARKER_DIR" && : > "$sMarker"
			break
		fi
	done < <(worktree_roots)
	exit 0
	;;
gate)
	[ -f "$sMarker" ] && exit 0

	sTarget="$(absolute "$sFilePath")" || exit 0
	bInside=0
	while IFS= read -r sTree; do
		[ -n "$sTree" ] || continue
		case "$sTarget" in
			"$sTree"/*) bInside=1; break ;;
		esac
	done < <(worktree_roots)
	[ "$bInside" -eq 1 ] || exit 0

	jq -n --arg reason "$(
		cat <<-REASON
		Read doc/itop-extension-guide.md before editing this repository - §0
		("Repository context - MUST read first") and "Using this guide", rules 1
		to 8. Nothing loads it for you, so this hook asks instead. It governs documentation-only changes as much as code:
		§0 makes listing doc/ and .github/ a precondition for adding any
		document here. Read it, then repeat this edit - the gate opens for the
		rest of the session.
		REASON
	)" '{
		hookSpecificOutput: {
			hookEventName: "PreToolUse",
			permissionDecision: "deny",
			permissionDecisionReason: $reason
		}
	}'
	exit 0
	;;
*)
	echo "guide-gate: expected 'record' or 'gate' as the first argument, got '${MODE}'." >&2
	exit 0
	;;
esac
