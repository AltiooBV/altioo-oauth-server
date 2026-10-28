#!/usr/bin/env bash
#
# Runs what CI runs, here, in containers.
#
# The workflows need three things this machine is unlikely to have all of at
# once: a PHP inside the range composer.json declares, a MariaDB, and a
# throwaway filesystem to install an iTop into. This gets all three from Docker
# and nothing from the host, so the answer it gives is the answer Actions would
# give - and it gives it without spending Actions minutes, which matters most
# for the question that costs the most there: "does this still install on the
# iTop Combodo published last week".
#
# Nothing here is a second implementation of CI. Every step below runs the same
# script from tools/ci/ that the workflow step runs; what this file adds is the
# machine to run it on.
#
#   tools/ci/local/run.sh unit                  ci.yml's unit, lint and Psalm jobs, on the floor
#   tools/ci/local/run.sh unit 8.4              the same, on another PHP
#   tools/ci/local/run.sh matrix                itop-matrix.yml, first declared branch on the floor
#   tools/ci/local/run.sh matrix 3.2 8.4        a given branch, on a given PHP
#   tools/ci/local/run.sh integration           just the integration suite, against
#                                               the instance matrix already installed
#   tools/ci/local/run.sh shell 8.2             a prompt inside the runner
#   tools/ci/local/run.sh down                  remove everything this created
#
# The defaults - "the floor", "the first declared branch" - are read from
# .github/itop-support.json (tools/ci/php-range.sh), which needs jq on this
# machine. Name both versions and it is not needed.
#
# `matrix` only takes a branch .github/itop-support.json names: the release is
# resolved through `resolve-itop-versions.php --matrix`, which iterates that
# file, so an unclaimed branch has no zip URL and the run stops before it
# downloads anything. To try one - 3.3, say - add the entry, run it, and take
# the entry back out; doc/ci-itop-matrix.md says why the last step is not
# optional.
#
# NEVER edit this file while a run of it is in progress. bash reads a script
# incrementally by byte offset, so inserting a line ahead of the interpreter
# shifts everything under it and the run dies on a syntax error that is not
# there once it has stopped.
#
# The iTop stays in a Docker volume between runs, which is the point of the
# `integration` subcommand: installing costs minutes, re-running the suite
# against what is already installed costs seconds, and that is the loop a test
# gets written in.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
MODULE_CODE=$(sed -n 's#.*<extension_code>\(.*\)</extension_code>.*#\1#p' "$REPO/extension.xml" | head -1)

# Shared by every subcommand, and deliberately not namespaced per branch: one
# database server and one network serve every version under test, and the
# volume keeps each installed iTop under a directory of its own.
NET=itop-ci-net
DB_CONTAINER=itop-ci-db
VOLUME=itop-ci-work
DB_PWD=itop

# A throwaway instance on a throwaway machine, same as the workflow's env block.
ITOP_ADMIN_USER='admin'
ITOP_ADMIN_PWD='Admin*2026!'

say() { printf '\n\033[1m>>> %s\033[0m\n' "$*"; }

# What the workflows run on when nobody names a version: the floor of the
# declared PHP range, and the first declared iTop branch.
declared_floor() { "$REPO/tools/ci/php-range.sh" | sed -n 's/^floor=//p'; }
declared_branch() { jq -r '.branches[0].branch' "$REPO/.github/itop-support.json"; }

# The image is built once per PHP version and per version of the Dockerfile,
# and then reused. It used to be one tag per PHP version, reused whatever the
# Dockerfile said since, with a note to rebuild by hand: a tool added there
# (Psalm, the first time) was then simply absent on every machine that had built
# the image before, and the step failed with "not found". The tag now carries a
# hash of the Dockerfile, so an edited one gets its own image - and two working
# copies on different Dockerfiles each keep theirs, rather than rebuilding the
# one shared tag every time a run switches between them.
dockerfile_hash() { sha256sum "$REPO/tools/ci/local/Dockerfile" | cut -c1-12; }
image_for() { echo "altioo-ci:php$1-$(dockerfile_hash)"; }
runner_name() { echo "itop-ci-php${1//./}"; }

need_image() {
	local sPhp=$1 sImage
	[ -n "$sPhp" ] || { echo "no PHP version given, and none could be read from .github/itop-support.json" >&2; exit 1; }
	sImage=$(image_for "$sPhp")
	if [ -z "$(docker images -q "$sImage")" ]; then
		say "building $sImage for this tools/ci/local/Dockerfile (once; a few minutes)"
		docker build --build-arg PHP_VERSION="$sPhp" -t "$sImage" "$REPO/tools/ci/local"
	fi
}

need_volume() {
	docker volume inspect "$VOLUME" >/dev/null 2>&1 || docker volume create "$VOLUME" >/dev/null
}

need_net() {
	docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
}

# Scripts are written into the volume and then run, never piped to `bash -s`.
# A script arriving on stdin shares that stdin with everything it starts, and
# phpcs reads stdin when it is not a terminal: it takes the remainder of the
# script as the file to lint, reports "1 / 1", and the steps below it never run
# at all - silently, with a zero exit. Anything that reads stdin does this.
put_script() {
	docker exec -i "$(runner_name "$1")" bash -c "cat > /work/$2"
}

# GITHUB_TOKEN, when the caller has one, is passed per run rather than when
# the runner is created: a runner lives for days and is reused, so a token set
# at creation would be ignored once it changed, and would sit in the
# container's configuration - readable with `docker inspect` - long after the
# run it was for. resolve-itop-versions.php sends it to the GitHub API, which
# otherwise allows 60 anonymous calls an hour per IP.
run_script() {
	local sPhp=$1 sName=$2; shift 2
	docker exec -e GITHUB_TOKEN "$(runner_name "$sPhp")" bash "/work/$sName" "$@"
}

# MariaDB 10.11, pinned exactly as itop-matrix.yml pins it: a database that
# changed under us would turn every run red at once with a cause nobody would
# look for here.
need_db() {
	need_net
	need_volume

	if [ -z "$(docker ps -q -f "name=^${DB_CONTAINER}$")" ]; then
		if [ -n "$(docker ps -aq -f "name=^${DB_CONTAINER}$")" ]; then
			docker start "$DB_CONTAINER" >/dev/null
		else
			say "starting MariaDB 10.11"
			docker run -d --name "$DB_CONTAINER" --network "$NET" \
				-e MARIADB_ROOT_PASSWORD="$DB_PWD" \
				--health-cmd="healthcheck.sh --connect --innodb_initialized" \
				--health-interval=5s --health-timeout=5s --health-retries=20 \
				public.ecr.aws/docker/library/mariadb:10.11 >/dev/null
		fi
	fi

	for _ in $(seq 1 60); do
		[ "$(docker inspect -f '{{.State.Health.Status}}' "$DB_CONTAINER")" = healthy ] && return
		sleep 2
	done
	echo "MariaDB did not become healthy; see: docker logs $DB_CONTAINER" >&2
	exit 1
}

# One long-lived container per PHP version. The repository is mounted read-only:
# a run must not be able to write to the working copy it is testing, and every
# step copies what it needs into the volume instead.
need_runner() {
	local sPhp=$1 sName
	sName=$(runner_name "$sPhp")
	if [ -n "$(docker ps -q -f "name=^${sName}$")" ]; then
		# http-smoke.sh stops its own server by process group and leaves nothing
		# behind. This is for the runs it never got to: an interrupt that kills
		# the container's shell outright skips the trap, and the workers then
		# hold 8080 for as long as the container lives - which, here, is across
		# sessions. A server from the previous run does not fail the next one
		# visibly; it answers it, from the previous tree.
		docker exec "$sName" pkill -f 'php -S' >/dev/null 2>&1 || true

		# And the same trap one level up. A runner outlives the checkout it was
		# started for, and the name carries the PHP version but nothing about
		# where /src points. Invoked from a second working copy - a worktree on
		# another branch, most likely - the name matches, the container is
		# reused, and /src still points at the first copy: the run answers, in
		# full, from the wrong tree. Every number it prints is real, which is
		# what makes it worse than a crash.
		local sMounted
		sMounted=$(docker inspect "$sName" \
			--format '{{range .Mounts}}{{if eq .Destination "/src"}}{{.Source}}{{end}}{{end}}' 2>/dev/null) || sMounted=
		# And the image: a container keeps the one it was started from, so a
		# runner started for another Dockerfile - an older one, or another
		# working copy's - lacks whatever this one adds.
		local sRunning sCurrent
		sRunning=$(docker inspect "$sName" --format '{{.Image}}' 2>/dev/null) || sRunning=
		sCurrent=$(docker image inspect "$(image_for "$sPhp")" --format '{{.Id}}' 2>/dev/null) || sCurrent=
		# The same path is not always the same directory: one deleted and
		# recreated under the same name leaves the bind on the old, empty one,
		# and every step then reports "composer.json not found".
		# A runner created by an earlier run.sh may hold GITHUB_TOKEN in its
		# configuration (see run_script); drop such a runner rather than keep it.
		local sStoredToken
		sStoredToken=$(docker inspect "$sName" \
			--format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | grep -c '^GITHUB_TOKEN=') || true
		if [ "$sMounted" = "$REPO" ] && [ "$sRunning" = "$sCurrent" ] && [ "${sStoredToken:-0}" = 0 ] \
			&& docker exec "$sName" test -f /src/extension.xml; then
			return
		fi
		if [ "$sMounted" != "$REPO" ]; then
			say "$sName is bound to ${sMounted:-nothing}, not $REPO - recreating it"
		elif [ "${sStoredToken:-0}" != 0 ]; then
			say "$sName holds a GITHUB_TOKEN from when it was created - recreating it without"
		elif [ "$sRunning" = "$sCurrent" ]; then
			say "$sName no longer sees $REPO (recreated since it started) - recreating it"
		else
			say "$sName runs another image than $(image_for "$sPhp") - recreating it"
		fi
		docker rm -f "$sName" >/dev/null 2>&1 || true
	fi
	need_net
	need_volume
	docker rm -f "$sName" >/dev/null 2>&1 || true
	docker run -d --name "$sName" --network "$NET" \
		-v "$VOLUME":/work \
		-v "$REPO":/src:ro \
		-e DB_HOST="$DB_CONTAINER" -e DB_PORT=3306 -e DB_USER=root -e DB_PWD="$DB_PWD" \
		-e ITOP_ADMIN_USER="$ITOP_ADMIN_USER" -e ITOP_ADMIN_PWD="$ITOP_ADMIN_PWD" \
		-e HOME=/work/.home \
		"$(image_for "$sPhp")" sleep infinity >/dev/null
}

cmd_build() {
	local sPhp=${1:-$(declared_floor)}
	need_image "$sPhp"
	say "$(image_for "$sPhp") ready"
}

# ci.yml's `tests` job, its `lint` job and its Psalm job: everything that needs
# neither a database nor an iTop, which is everything that fails while a change
# is being written. Psalm runs as ci.yml runs it - --php-version=<floor> whichever
# PHP this container has, since that is the target the baseline was built for. An ephemeral container, because none of it is worth keeping.
cmd_unit() {
	local sPhp=${1:-$(declared_floor)}
	need_image "$sPhp"; need_runner "$sPhp"

	put_script "$sPhp" unit.sh <<'INNER'
set -euo pipefail
sDest=$1; sFloor=$2
rm -rf "$sDest"; mkdir -p "$sDest"
# The working copy, as a checkout of it would look. The excludes are not an
# optimisation: CI checks out from git and therefore has none of these, while a
# copy of a working tree has whichever of them the last local run left behind.
rsync -a \
  --exclude=.git/ \
  --exclude=/vendor/ \
  --exclude=/.phpunit.result.cache \
  --exclude=/.phpunit.cache/ \
  --exclude=/build/ \
  /src/ "$sDest/"

cd "$sDest"
php -v | head -1
composer validate --strict --no-check-publish
composer install --no-interaction --no-progress
composer check-platform-reqs
phpcs
# As in ci.yml: Psalm reads src/, and an extension without one has nothing to analyse.
if [ -n "$(find src -name '*.php' 2>/dev/null | head -1)" ]; then
	psalm --php-version="$sFloor"
else
	echo "no PHP under src/: Psalm skipped"
fi
composer test:unit
INNER
	run_script "$sPhp" unit.sh "/work/unit-$sPhp" "$(declared_floor)"
}

# itop-matrix.yml's install job, end to end: the dry run that answers "would
# this extension be installed at all", the install itself, iTop's own module
# validation, this module's integration suite, and the two smokes.
#
# Steps record a verdict and the run carries on, the way fail-fast:false lets
# the matrix finish - see "An upstream test that does not exist" in
# doc/ci-itop-matrix.md for the one step that tolerates part of a release
# rather than failing on it, and why.
cmd_matrix() {
	local sBranch=${1:-$(declared_branch)} sPhp=${2:-$(declared_floor)}
	need_image "$sPhp"; need_db; need_runner "$sPhp"

	say "iTop $sBranch on PHP $sPhp"
	put_script "$sPhp" matrix.sh <<'INNER'
set -uo pipefail
sDest=$1; sItopDir=$2; sDb=$3; sBranch=$4; sModule=$5; sVolume=$6
export ITOP_DIR="$sItopDir" DB_NAME="$sDb"

aResults=()
step() {
	local sName="$1"; shift
	printf '\n=== %s ===\n' "$sName"
	if "$@"; then aResults+=("PASS  $sName"); else aResults+=("FAIL  $sName"); fi
}

rm -rf "$sDest"; mkdir -p "$sDest"
rsync -a \
  --exclude=.git/ \
  --exclude=/vendor/ \
  --exclude=/.phpunit.result.cache \
  --exclude=/.phpunit.cache/ \
  --exclude=/build/ \
  /src/ "$sDest/" || exit 1
cd "$sDest"
php -v | head -1
echo "database $sDb, instance $sItopDir"

# --no-dev, because what gets installed into iTop has to be what ships.
step "vendor tree that ships" composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# One call to the resolver, read twice: the newest packaged release of the
# branch, and the git tag carrying the test harness that a packaged release
# does not. Same JSON the versions job feeds the matrix from.
sMatrix=$(php tools/ci/resolve-itop-versions.php --matrix) || exit 1
read_field() {
	printf '%s' "$sMatrix" | php -r '
		$aJson = json_decode(stream_get_contents(STDIN), true);
		foreach ($aJson["include"] as $aEntry) {
			if ($aEntry["itop_branch"] === $argv[1]) { echo $aEntry[$argv[2]]; return; }
		}' "$sBranch" "$1"
}
ITOP_ZIP_URL=$(read_field zip_url)
ITOP_TAG=$(read_field itop_tag)
export ITOP_ZIP_URL ITOP_TAG
[ -n "$ITOP_ZIP_URL" ] || { echo "branch $sBranch is not in .github/itop-support.json" >&2; exit 1; }
echo "release: $ITOP_ZIP_URL"
echo "harness tag: ${ITOP_TAG:-none - the integration suite would skip itself}"

step "would the setup install this module" env DRY_RUN_ONLY=1 tools/ci/install-itop.sh
step "unattended install"                 tools/ci/install-itop.sh

step "iTop's module validation suite" tools/ci/module-validation.sh

module_integration() {
	cd "$ITOP_DIR/tests/php-unit-tests" || return 1
	php vendor/bin/phpunit --no-configuration --bootstrap unittestautoload.php --testdox \
		"$ITOP_DIR/env-production/$sModule/tests/php-unit-tests/Integration"
}
if [ -d tests/php-unit-tests/Integration ]; then
	step "the module's integration suite" module_integration
fi

smoke() {
	cd "$sDest" || return 1
	local sOut sToken
	sOut=$(php tools/ci/itop-smoke.php "$ITOP_DIR" "$ITOP_ADMIN_USER") || { printf '%s\n' "$sOut"; return 1; }
	printf '%s\n' "$sOut" | grep -v '^http-smoke-token: '
	sToken=$(printf '%s\n' "$sOut" | sed -n 's/^http-smoke-token: //p')
	# Only an extension with an entry point has an endpoint to request.
	if [ -f index.php ]; then
		ITOP_TOKEN="$sToken" tools/ci/http-smoke.sh
	else
		echo "no index.php: no HTTP entry point to smoke"
	fi
}
step "datamodel checks, then the endpoint over HTTP if there is one" smoke

printf '\n%s\n' '=============================================================='
printf '%s\n' "${aResults[@]}"
printf '\nthe instance is kept at %s in volume %s\n' "$sItopDir" "$sVolume"

# A summary a person reads is not a status a script can act on, and this one is
# printed by a run that has already swallowed each step's exit code to get here.
# Any FAIL row leaves non-zero, the way the workflow's job would.
printf '%s\n' "${aResults[@]}" | grep -q '^FAIL' && exit 1
exit 0
INNER
	run_script "$sPhp" matrix.sh \
		"/work/module-$sPhp" "/work/itop-$sBranch-$sPhp" "itop_${sBranch//./}_${sPhp//./}" \
		"$sBranch" "$MODULE_CODE" "$VOLUME"
}

# The fast loop. No download, no install: the suite against the iTop a previous
# `matrix` left in the volume.
cmd_integration() {
	local sBranch=${1:-$(declared_branch)} sPhp=${2:-$(declared_floor)}
	need_image "$sPhp"; need_db; need_runner "$sPhp"

	put_script "$sPhp" integration.sh <<'INNER'
set -euo pipefail
sItopDir=$1; sModule=$2
sTests="$sItopDir/env-production/$sModule/tests/php-unit-tests"
[ -d "$sTests" ] || { echo "no iTop installed at $sItopDir - run 'matrix' first" >&2; exit 1; }
[ -d /src/tests/php-unit-tests/Integration ] || { echo "this extension has no integration suite"; exit 0; }

# The tests are a plain copy inside the compiled environment, so refreshing them
# from the working tree is the whole update: nothing under tests/ is compiled.
# The module's own code is not refreshed here on purpose - changing that means
# re-running the setup, which is what `matrix` is for.
rsync -a /src/tests/php-unit-tests/ "$sTests/"

cd "$sItopDir/tests/php-unit-tests"
php vendor/bin/phpunit --no-configuration --bootstrap unittestautoload.php --testdox "$sTests/Integration"
INNER
	run_script "$sPhp" integration.sh "/work/itop-$sBranch-$sPhp" "$MODULE_CODE"
}

cmd_shell() {
	local sPhp=${1:-$(declared_floor)}
	need_image "$sPhp"; need_db; need_runner "$sPhp"
	docker exec -it "$(runner_name "$sPhp")" bash
}

cmd_down() {
	say "removing the containers, the volume and the network"
	local sName
	for sName in $(docker ps -aq -f 'name=^itop-ci-php'); do docker rm -f "$sName" >/dev/null; done
	docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
	docker volume rm "$VOLUME" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
	echo "the altioo-ci:* images are kept, one per PHP version and Dockerfile - docker rmi the ones you no longer need"
}

case "${1:-}" in
	build)       shift; cmd_build "$@" ;;
	unit)        shift; cmd_unit "$@" ;;
	matrix)      shift; cmd_matrix "$@" ;;
	integration) shift; cmd_integration "$@" ;;
	shell)       shift; cmd_shell "$@" ;;
	down)        shift; cmd_down "$@" ;;
	*)
		sed -n '/^#   tools/,/^#$/p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'
		exit 1
		;;
esac
