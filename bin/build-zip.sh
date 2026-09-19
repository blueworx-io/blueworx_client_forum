#!/usr/bin/env bash
#
# Build the deployable plugin zip from an explicit allowlist, then verify the
# artifact it just built.
#
#   bash bin/build-zip.sh [output-dir]      # default output-dir: parent of the repo
#
# WHY THIS EXISTS
# The zip used to be assembled by hand, and nothing enforced what went into it.
#
# WHAT ENFORCES THIS NOW
# CI does not run this script. The foundation checks what would ship on every
# pull request, and again against the built artifact at release time, from
# scripts/plugin-zip-excludes.txt plus a separate forbidden-content list. That
# is the enforcing copy. This script stays for building a zip by hand locally —
# a first install on a site, before the release workflow has run for it. Its
# allowlist is a convenience, NOT a second source of truth: if the two ever
# disagree, the foundation's list is right and this one is stale.
#
# WHY NOT Compress-Archive / GNU tar
# PowerShell's Compress-Archive writes backslash entry paths on Windows, and
# WordPress (Linux) then reports "Plugin file does not exist." on activate. GNU
# tar cannot write zip format at all. This script insists on a tool that
# produces correct forward-slash zip entries, and proves it afterwards.

set -euo pipefail

# The folder the plugin installs into on a site. It is deliberately not the name
# of the main PHP file: renaming that file would change the plugin basename
# WordPress stores, and the plugin would deactivate on the shop's next update.
SLUG="blueworx_client_forum"
MAIN_FILE="external-product-images.php"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$(cd "$ROOT/.." && pwd)}"

# ---------------------------------------------------------------------------
# THE ALLOWLIST — what ships. Allowlist, not denylist: a new dev directory is
# excluded by default rather than shipped because nobody remembered to add it.
# ---------------------------------------------------------------------------
INCLUDE=(
	"$MAIN_FILE"
	"uninstall.php"
	"readme.txt"
	"CHANGELOG.md"
	"includes"
	"assets"
	# The vendored update checker. The main plugin file requires it unguarded,
	# so a zip without it fatals the moment WordPress activates the plugin.
	"plugin-update-checker"
	# The vendored page editor library. The main plugin file requires it
	# unguarded, so a zip without it fatals on activate.
	"blueworx-page-editor"
)

# Belt and braces. The allowlist alone already excludes these, so a hit here
# means one is nested inside a shipped directory — exactly the case a human
# misses. "vendor" is deliberately absent: the update checker ships its own
# vendor/ directory of runtime classes, and the foundation's forbidden list
# does not treat a nested vendor as forbidden either.
FORBIDDEN_SEGMENTS=( "preview" "tests" "docs" "node_modules" ".superpowers" ".github" ".git" ".claude" )
FORBIDDEN_FILES=( "*.spec.js" "phpunit.xml*" "phpcs.xml*" "composer.json" "composer.lock" "package.json" "package-lock.json" "approved-deps.json" "playwright.config.js" "CLAUDE.md" "AGENTS.md" ".gitignore" "*.zip" )

say() { printf '%s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# --- pick an archiver that writes real zip entries with forward slashes -------
ZIP_TOOL=""
for candidate in "/c/Windows/System32/tar.exe" "$(command -v bsdtar || true)" "$(command -v tar || true)"; do
	[ -n "$candidate" ] && [ -x "$candidate" ] || continue
	if "$candidate" --version 2>&1 | grep -qi 'bsdtar\|libarchive'; then
		ZIP_TOOL="bsdtar:$candidate"
		break
	fi
done
if [ -z "$ZIP_TOOL" ] && command -v zip >/dev/null 2>&1; then
	# Info-ZIP on Linux/CI: GNU tar cannot write zip, but zip(1) can, and writes
	# forward slashes natively.
	ZIP_TOOL="zip:$(command -v zip)"
fi
[ -n "$ZIP_TOOL" ] || die "no zip-capable archiver found (need bsdtar or zip; GNU tar cannot write zip)"

TOOL_KIND="${ZIP_TOOL%%:*}"
TOOL_BIN="${ZIP_TOOL#*:}"
say "Archiver : $TOOL_KIND ($TOOL_BIN)"

VERSION="$(grep -oE "define\( 'EPI_VERSION', '[^']+'" "$ROOT/$MAIN_FILE" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")"
[ -n "$VERSION" ] || die "could not read the plugin version from $MAIN_FILE"
say "Version  : $VERSION"

# The version lives in the FILENAME only. The folder inside the archive stays
# "$SLUG/" — WordPress identifies a plugin by that folder, so putting the
# version there would make every release look like a different plugin.
ZIP="$OUT_DIR/$SLUG-$VERSION.zip"

# --- stage -------------------------------------------------------------------
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
for item in "${INCLUDE[@]}"; do
	[ -e "$ROOT/$item" ] || die "allowlisted path is missing from the repo: $item"
	cp -R "$ROOT/$item" "$STAGE/$SLUG/"
done

# --- build -------------------------------------------------------------------
mkdir -p "$OUT_DIR"
# Exactly one zip per plugin is ever present: an older build left beside the new
# one is how the wrong version reaches the shop.
rm -f "$OUT_DIR/$SLUG.zip" "$OUT_DIR"/"$SLUG"-*.zip
case "$TOOL_KIND" in
	bsdtar) ( cd "$STAGE" && "$TOOL_BIN" -a -c -f "$ZIP" "$SLUG" ) ;;
	zip)    ( cd "$STAGE" && "$TOOL_BIN" -q -r -X "$ZIP" "$SLUG" ) ;;
esac
[ -f "$ZIP" ] || die "no zip was produced at $ZIP"

# --- verify the artifact, not the intent -------------------------------------
if command -v unzip >/dev/null 2>&1; then
	ENTRIES="$(unzip -Z1 "$ZIP")"
elif [ "$TOOL_KIND" = "bsdtar" ]; then
	ENTRIES="$("$TOOL_BIN" -tf "$ZIP")"
else
	die "need unzip (or bsdtar) to list the zip — refusing to ship an unverified artifact"
fi

NL="
"

fail=0
check() { # check <description> <offending-entries>
	if [ -n "$2" ]; then
		printf 'FAIL: %s\n%s\n' "$1" "$(printf '%s\n' "$2" | sed 's/^/    /')" >&2
		fail=1
	else
		say "  ok: $1"
	fi
}

say "Verifying $ZIP"

# A backslash entry mis-extracts on a Linux host: WordPress then reports
# "Plugin file does not exist." on activate. This is the Compress-Archive bug.
check "every entry uses forward slashes" "$(printf '%s\n' "$ENTRIES" | grep -F '\' || true)"
check "every entry is nested under $SLUG/" "$(printf '%s\n' "$ENTRIES" | grep -vE "^$SLUG/" || true)"

offenders=""
for seg in "${FORBIDDEN_SEGMENTS[@]}"; do
	hit="$(printf '%s\n' "$ENTRIES" | grep -E "(^|/)$(printf '%s' "$seg" | sed 's/\./\\./g')(/|$)" || true)"
	[ -n "$hit" ] && offenders="$offenders$hit$NL"
done
check "no development directories ship" "$(printf '%s' "$offenders" | sed '/^$/d')"

offenders=""
for pat in "${FORBIDDEN_FILES[@]}"; do
	hit="$(printf '%s\n' "$ENTRIES" | grep -E "(^|/)${pat//\*/[^/]*}$" || true)"
	[ -n "$hit" ] && offenders="$offenders$hit$NL"
done
check "no development files ship" "$(printf '%s' "$offenders" | sed '/^$/d')"

check "the main plugin file sits directly inside $SLUG/" \
	"$(printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/$MAIN_FILE" && true || echo "missing $SLUG/$MAIN_FILE")"

# The allowlist above is a list of names, and a name is easy to forget. This
# reads what the plugin actually requires on boot and insists the zip carries
# it — so the next vendored directory is caught here rather than by the shop
# seeing a fatal error on activate.
REQUIRED="$(grep -oE "require(_once)? +(__DIR__|plugin_dir_path\( __FILE__ \)) *\. *'[^']+'" "$ROOT/$MAIN_FILE" | grep -oE "'[^']+'\$" | tr -d "'" | sed 's|^/||')"
offenders=""
while IFS= read -r required; do
	[ -n "$required" ] || continue
	printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/$required" || offenders="$offenders$required$NL"
done <<REQ
$REQUIRED
REQ
check "everything the plugin requires on boot is in the zip" "$(printf '%s' "$offenders" | sed '/^$/d')"

[ "$fail" -eq 0 ] || die "the zip is not shippable — see the failures above"

say ""
say "Built $ZIP ($SLUG $VERSION, $(printf '%s\n' "$ENTRIES" | grep -c . ) entries)"
