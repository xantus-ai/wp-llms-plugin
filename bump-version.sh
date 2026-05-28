#!/usr/bin/env bash
#
# Bump WP LLMS version across all source-of-truth files.
#
# Updates:
#   - plugin/wp-llms.php  (Plugin Header "Version:" + WPLLMS_VERSION constant)
#   - plugin/readme.txt       (Stable tag)
#   - plugin/CHANGELOG.md     (rotates [Unreleased] into a new versioned section)
#
# Usage:
#   ./bump-version.sh 0.2.0
#

set -euo pipefail

if [ -z "${1:-}" ]; then
    echo "Usage: $0 <new-version>"
    echo "Example: $0 0.2.0"
    exit 1
fi

NEW_VERSION="$1"

if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$ ]]; then
    echo "Error: version must be MAJOR.MINOR.PATCH (with optional -prerelease)"
    echo "Got: $NEW_VERSION"
    exit 1
fi

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_FILE="$PROJECT_ROOT/plugin/wp-llms.php"
README_FILE="$PROJECT_ROOT/plugin/readme.txt"
CHANGELOG_FILE="$PROJECT_ROOT/plugin/CHANGELOG.md"

for f in "$PLUGIN_FILE" "$README_FILE" "$CHANGELOG_FILE"; do
    if [ ! -f "$f" ]; then
        echo "Error: required file not found: $f"
        exit 1
    fi
done

CURRENT=$(grep -E "^\s*\*\s*Version:" "$PLUGIN_FILE" \
    | head -1 \
    | sed -E 's/.*Version:[[:space:]]*([0-9.a-zA-Z\-]+).*/\1/')

if [ "$CURRENT" = "$NEW_VERSION" ]; then
    echo "Already at version $NEW_VERSION. Nothing to do."
    exit 0
fi

echo "Bumping $CURRENT -> $NEW_VERSION"

# ---- Plugin file: header line and PHP constant ----

perl -i -pe 's/(\*\s*Version:\s*)[0-9.a-zA-Z\-]+/${1}'"$NEW_VERSION"'/' "$PLUGIN_FILE"
perl -i -pe "s/(define\('WPLLMS_VERSION',\s*')[^']+('\))/\${1}$NEW_VERSION\${2}/" "$PLUGIN_FILE"

# ---- readme.txt: Stable tag ----

perl -i -pe 's/^(Stable tag:\s*).+/${1}'"$NEW_VERSION"'/' "$README_FILE"

# ---- CHANGELOG.md: rotate [Unreleased] into [VERSION] - DATE ----

TODAY=$(date +%Y-%m-%d)
if grep -q "^## \[Unreleased\]" "$CHANGELOG_FILE"; then
    perl -i -0pe "s/## \[Unreleased\]/## [Unreleased]\n\n## [$NEW_VERSION] - $TODAY/" "$CHANGELOG_FILE"
else
    echo "Warning: no [Unreleased] section in CHANGELOG.md - skipping changelog rotation."
fi

# ---- Verify ----

NEW_HEADER=$(grep -E "^\s*\*\s*Version:" "$PLUGIN_FILE" | head -1 | sed -E 's/.*Version:[[:space:]]*([0-9.a-zA-Z\-]+).*/\1/')
NEW_CONST=$(grep "WPLLMS_VERSION" "$PLUGIN_FILE" | head -1 | sed -E "s/.*'([0-9.a-zA-Z\-]+)'.*/\1/")
NEW_TAG=$(grep -E "^Stable tag:" "$README_FILE" | sed -E 's/Stable tag:[[:space:]]*(.+)/\1/')

if [ "$NEW_HEADER" != "$NEW_VERSION" ] || [ "$NEW_CONST" != "$NEW_VERSION" ] || [ "$NEW_TAG" != "$NEW_VERSION" ]; then
    echo "Warning: post-bump verification mismatch:"
    echo "  Plugin header: $NEW_HEADER"
    echo "  Constant:      $NEW_CONST"
    echo "  readme.txt:    $NEW_TAG"
    echo "  Expected:      $NEW_VERSION"
    exit 1
fi

echo ""
echo "Bumped to $NEW_VERSION:"
echo "  $PLUGIN_FILE     (Version header + WPLLMS_VERSION constant)"
echo "  $README_FILE     (Stable tag)"
echo "  $CHANGELOG_FILE  (rotated [Unreleased] -> [$NEW_VERSION] - $TODAY)"
echo ""
echo "Review:  git diff plugin/wp-llms.php plugin/readme.txt plugin/CHANGELOG.md"
echo "Build:   ./build.sh"
