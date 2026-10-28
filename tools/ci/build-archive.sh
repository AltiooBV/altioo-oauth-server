#!/usr/bin/env bash
#
# Builds the release archive from this working copy, and checks it.
#
# One script for every place an archive is built - ci.yml's package job on
# every change, release.yml at a tag, and the template's own self-test - so
# that what CI proves buildable and what a release publishes are the same
# thing. They used to be two inline lists that disagreed: CI checked five
# files, the release checked eighteen, and the eighteen were one extension's
# (an HTTP entry point, its guard files, a client document). Every other
# extension built from this template passed CI and then could not publish.
#
# Expects vendor/ to be the production tree already:
#
#   composer install --no-dev --optimize-autoloader && tools/ci/build-archive.sh
#
# Leaves at the repository root: <module>-<version>.zip, its .sha256,
# sbom.cyclonedx.json and licenses.json. When GITHUB_OUTPUT is set it also
# writes name=<module>-<version>, module=<module> and sha256=<the zip's
# digest> there.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

fail() { echo "::error::$1" >&2; exit 1; }

[ -f extension.xml ] || fail "no extension.xml at $ROOT - there is no extension to package"
module=$(sed -n 's#.*<extension_code>\(.*\)</extension_code>.*#\1#p' extension.xml | head -1)
version=$(sed -n 's#.*<version>\(.*\)</version>.*#\1#p' extension.xml | head -1)
[ -n "$module" ] && [ -n "$version" ] || fail "extension.xml declares no extension_code or no version"
name="${module}-${version}"

# 1. exclude.txt says what it means.
#
# rsync matches an entry with no leading slash at any depth: "tools" also drops
# src/tools/ and vendor/<package>/tools/. Only the two macOS junk names are
# meant that way. And the documentation and the tests ship (§9.1, §8.2), so
# excluding either whole directory is a mistake whatever the reason given.
sUnanchored=$(grep -vE '^[[:space:]]*(#|$)' exclude.txt | grep -vE '^/' | grep -vxE '\.DS_Store|\._\*' || true)
[ -z "$sUnanchored" ] \
	|| fail "exclude.txt entries without a leading / also match at any depth: $(echo "$sUnanchored" | tr '\n' ' ')"
if grep -qxE '/?(README\.md|doc/?|LICENSE|SECURITY\.md|CHANGELOG\.md|tests/?)' exclude.txt; then
	fail "exclude.txt lists a file or directory that must ship; remove it from exclude.txt"
fi

# 2. vendor/ is the one that ships.
[ -f vendor/autoload.php ] || fail "vendor/ is missing - run composer install --no-dev first"
[ ! -d vendor/phpunit ] || fail "require-dev is installed in vendor/ - run composer install --no-dev first"

# 3. The bill of materials and the licence inventory. Reproducible: the same
#    lock produces the same document. SOURCE_DATE_EPOCH is assigned and
#    exported separately so a failing `git log` is not masked by export.
if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
	SOURCE_DATE_EPOCH=$(git log -1 --pretty=%ct 2>/dev/null || date +%s)
fi
export SOURCE_DATE_EPOCH
composer sbom > sbom.cyclonedx.json
composer licenses --no-dev --format=json > licenses.json
# Literal PHP passed to `php -r`; $a is a PHP variable, not a shell one.
# shellcheck disable=SC2016
php -r '
	$a = json_decode(file_get_contents("sbom.cyclonedx.json"), true, 512, JSON_THROW_ON_ERROR);
	json_decode(file_get_contents("licenses.json"), true, 512, JSON_THROW_ON_ERROR);
	// An extension with no production dependency has an empty list, which is
	// a correct SBOM; a missing list is a broken one.
	if (!is_array($a["components"] ?? null)) { fwrite(STDERR, "the SBOM has no components list\n"); exit(1); }
	echo count($a["components"]), " production dependencies inventoried\n";
'

# 4. The archive. It has to unzip as extensions/<module code>/, so the
#    directory inside it is the module code and nothing else. exclude.txt is
#    the single source of truth for what stays out; rsync reads it directly.
rm -rf build "${name}.zip" "${name}.zip.sha256"
mkdir -p "build/${module}"
rsync -a --exclude-from=exclude.txt \
	--exclude '/sbom.cyclonedx.json' --exclude '/licenses.json' \
	./ "build/${module}/"
# The archive carries its own inventory: an instance found in a year's time
# can answer what it is running without reaching the internet.
cp sbom.cyclonedx.json licenses.json "build/${module}/"

# 5. What it must contain. Generic first: what every extension ships (§9.1,
#    §9.3, §12.4). Then what follows from the tree: the guard files, and both
#    of them as soon as the tree has an entry point or either guard. They are
#    the module's whole HTTP protection whether or not it has an entry point,
#    and a guard missing from the archive does not fail closed - the compiled
#    copy in env-<env>/ sits under a configuration that allows PHP for the
#    whole subtree, so tests/ and vendor/ would be served, quietly, from an
#    instance that installed cleanly. Each guard covers one web server, so one
#    without the other leaves the module open on the other server.
aRequired=(README.md SECURITY.md CHANGELOG.md CONTRIBUTING.md LICENSE
	extension.xml "module.${module}.php" vendor/autoload.php
	sbom.cyclonedx.json licenses.json)
[ ! -f index.php ] || aRequired+=(index.php)
if [ -f index.php ] || [ -f .htaccess ] || [ -f web.config ]; then
	aRequired+=(.htaccess web.config)
fi
# NOTICE, where it exists, is what scopes LICENSE; without it the archive
# claims a single licence for files it does not cover.
[ ! -f NOTICE ] || aRequired+=(NOTICE)
[ ! -d tests/php-unit-tests ] || aRequired+=(tests/php-unit-tests/bootstrap.php)
# Every datamodel file at the root, and every dictionary in whichever language
# and directory the module keeps it. Dot-directories are skipped:
# .github/template-selftest/ carries a stand-in extension's dictionaries that
# never ship.
for f in datamodel.*.xml; do
	[ -e "$f" ] && aRequired+=("$f")
done
while IFS= read -r f; do
	aRequired+=("${f#./}")
done < <(find . \( -path ./vendor -o -path ./build -o -path ./tools -o -path './.*' \) -prune \
	-o -type f \( -name '*.dict.*.xml' -o -name '*.dict.*.php' \) -print | sort -u)
# Every file the module names by its installed path, <module code>/<path>: a
# stylesheet or logo in the datamodel, an SCSS import, a script a page loads.
# Nothing else lists them, and an exclude.txt entry that drops one installs
# cleanly and breaks only the page that loads it. Only a name that is a file
# here counts, so a version string ("<module code>/1.0.0") is not a path.
while IFS= read -r f; do
	[ -f "$f" ] && aRequired+=("$f")
done < <(find . \( -path ./vendor -o -path ./build -o -path ./tools -o -path './.*' \) -prune \
	-o -type f \( -name '*.xml' -o -name '*.php' -o -name '*.scss' -o -name '*.css' \
	-o -name '*.js' -o -name '*.twig' -o -name '*.html' \) -print0 \
	| xargs -0 -r grep -hoE "(^|[^A-Za-z0-9_.-])${module}/[A-Za-z0-9_./-]*[A-Za-z0-9_-]" \
	| sed -E "s#^.?${module}/##" | sort -u)

cd "build/${module}"
aMissing=()
for f in "${aRequired[@]}"; do
	[ -e "$f" ] || aMissing+=("$f")
done
[ ${#aMissing[@]} -eq 0 ] || fail "missing from the package: ${aMissing[*]}"
[ ! -d vendor/phpunit ] || fail "require-dev leaked into the package"
[ ! -e .git ] || fail ".git leaked into the package"
[ ! -d tools ] || fail "tools/ leaked into the package"

# The package declares one licence. The doc/ guides and the branch notes are
# Creative Commons and would ship by omission if exclude.txt lost them.
if grep -rlE 'https?://(www\.)?creativecommons\.org/licenses/' . \
	--include='*.md' --include='*.php' --include='*.xml'; then
	fail "the files above are not AGPL-only and must not ship; list them in exclude.txt"
fi
cd "$ROOT"

( cd build && zip -qr "../${name}.zip" "${module}" )
sha256sum "${name}.zip" > "${name}.zip.sha256"
cat "${name}.zip.sha256"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{
		echo "name=${name}"
		echo "module=${module}"
		echo "sha256=$(cut -d' ' -f1 "${name}.zip.sha256")"
	} >> "$GITHUB_OUTPUT"
fi
