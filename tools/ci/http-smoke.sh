#!/usr/bin/env bash
#
# Calls the module's HTTP entry point the way a client does.
#
# The unit suite proves the code is right and the integration suite proves it
# is right against a real MetaModel. Neither one goes through a web server, and
# that is where an entry point fails in the field: a request that never reaches
# the code, or a fatal before it does.
#
# Two halves, the same split as itop-smoke.php and checks/module-smoke.php:
#
#   generic   here. iTop answers, and both URLs the module's index.php is
#             served from answer without a server error.
#   specific  tools/ci/checks/http-smoke.sh, sourced if present. What *this*
#             entry point must do - refuse an anonymous caller, accept a token,
#             answer its own protocol - is the extension's to say. It runs with
#             BASE, ENDPOINT, ALT_ENDPOINT, ITOP_TOKEN (empty unless
#             checks/module-smoke.php minted one) and fail() in scope.
#
# This file used to be one extension's MCP smoke - a JSON-RPC initialize and
# tools/list - run for any extension with an index.php, so a console page or a
# REST endpoint failed it for not being an MCP server.
#
# Usage: ITOP_DIR=... [ITOP_TOKEN=...] tools/ci/http-smoke.sh
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

: "${ITOP_DIR:?set ITOP_DIR to the installed iTop}"
ITOP_TOKEN="${ITOP_TOKEN:-}"

MODULE_SRC="${MODULE_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
MODULE_CODE=$(sed -n 's#.*<extension_code>\(.*\)</extension_code>.*#\1#p' "$MODULE_SRC/extension.xml" | head -1)

HOST="${SMOKE_HOST:-127.0.0.1}"
PORT="${SMOKE_PORT:-8080}"
BASE="http://${HOST}:${PORT}"
ITOP_ENV="${ITOP_ENV:-production}"

# Two URLs serve the same file on a default install: the compiled copy and the
# one in extensions/. Only the first is the one to publish to clients, but both
# are reachable, and the second has a failure mode the first cannot have: an
# index.php that requires __DIR__.'/vendor/autoload.php' before booting iTop
# loads the same file iTop's startup requires when served from env-<env>/, and
# a second copy of it when served from extensions/ - a fatal on a redeclared
# class, and a 500 on every call to that URL. From the wire that bug is a
# status code, which is what the generic half reads.
ENDPOINT="${BASE}/env-${ITOP_ENV}/${MODULE_CODE}/index.php"
ALT_ENDPOINT="${BASE}/extensions/${MODULE_CODE}/index.php"

# PHP's built-in server, not Apache: this checks the application, and pulling
# in a web server would mean checking its configuration instead. The
# .htaccess/web.config rules that hide src/ and vendor/ are the web server's
# job, and php -S ignores them, so no conclusion about them is drawn here.
#
# Started in a session of its own, and stopped by process group rather than by
# process: PHP_CLI_SERVER_WORKERS makes the server fork workers, and they do not
# die with their parent - killing it orphans four processes that go on holding
# the port. On Actions that is invisible, the machine being discarded with them.
# Anywhere the machine is reused it is worse than a leak: the next run's server
# cannot bind, curl reaches the *previous* server, and the checks below pass or
# fail against a tree and a database that are not the ones under test. setsid
# execs in place here rather than forking, so $! is the leader of the new group
# and -"$SERVER_PID" names every worker in it.
setsid env PHP_CLI_SERVER_WORKERS=4 php -S "${HOST}:${PORT}" -t "$ITOP_DIR" >"$ITOP_DIR/ci-httpd.log" 2>&1 &
SERVER_PID=$!
trap 'kill -TERM -"$SERVER_PID" 2>/dev/null || kill -TERM "$SERVER_PID" 2>/dev/null || true' EXIT INT TERM

for _ in $(seq 1 30); do
  if curl -fsS -o /dev/null "${BASE}/index.php"; then break; fi
  sleep 1
done

fail() { echo "::error::$1"; echo "--- server log ---"; tail -40 "$ITOP_DIR/ci-httpd.log"; exit 1; }

echo "1. the console answers"
curl -fsS -o /dev/null -w '   HTTP %{http_code}\n' "${BASE}/index.php" \
  || fail "iTop itself did not answer over HTTP"

echo "2. the entry point answers at both URLs, without a server error"
for URL in "$ENDPOINT" "$ALT_ENDPOINT"; do
  PATH_ONLY="${URL#"$BASE"}"
  STATUS=$(curl -s -o /dev/null -w '%{http_code}' "$URL" || true)
  echo "   HTTP $STATUS   $PATH_ONLY"
  case "$STATUS" in
    000) fail "no answer from $PATH_ONLY" ;;
    5*)  fail "$PATH_ONLY answered $STATUS - a fatal before or inside the entry point" ;;
  esac
done

SPECIFIC="$MODULE_SRC/tools/ci/checks/http-smoke.sh"
if [ -f "$SPECIFIC" ]; then
  echo "3. this extension's own checks ($SPECIFIC)"
  # shellcheck source=/dev/null
  . "$SPECIFIC"
else
  echo "3. no tools/ci/checks/http-smoke.sh: nothing extension-specific to ask the entry point"
fi

echo "the entry point is reachable at both URLs"
