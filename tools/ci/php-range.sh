#!/usr/bin/env bash
#
# The PHP range this extension claims, read from .github/itop-support.json -
# the file that is already the single source of truth for what is tested - so
# that no workflow names a PHP version of its own. Written as GitHub step
# outputs:
#
#   floor=8.2                  the lowest PHP any supported branch is tested on
#   ceiling=8.4                the highest
#   matrix=["8.2","8.3","8.4"] every minor from floor to ceiling, for the unit
#                              suite; the declared versions themselves when the
#                              range crosses a major
#
#   - id: php
#     run: tools/ci/php-range.sh >> "$GITHUB_OUTPUT"
#
# The workflows used to carry 8.2 and 8.2-8.4 as literals, while
# tools/instantiate.sh wrote --php-floor and --php-ceiling into the support
# file only: an extension instantiated with another floor never had it tested.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

SUPPORT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.github/itop-support.json"

command -v jq >/dev/null 2>&1 || { echo "php-range.sh needs jq" >&2; exit 1; }

jq -r '
	def v: split(".") | map(tonumber);
	[.branches[].php[]] | unique | sort_by(v) as $all
	| if ($all | length) == 0 then error("no PHP version declared in .github/itop-support.json") else . end
	| ($all[0]) as $floor | ($all[-1]) as $ceiling
	| (if ($floor | v)[0] == ($ceiling | v)[0]
	   then [range(($floor | v)[1]; ($ceiling | v)[1] + 1)] | map("\(($floor | v)[0]).\(.)")
	   else $all end) as $matrix
	| "floor=\($floor)", "ceiling=\($ceiling)", "matrix=\($matrix | tojson)"
' "$SUPPORT"
