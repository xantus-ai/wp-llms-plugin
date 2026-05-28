#!/usr/bin/env bash
#
# Build a WordPress-ready zip of the llms.txt for WordPress plugin.
# Output: llms-txt.zip in the project root, ready for WP admin upload.
#
# Usage:
#   ./build.sh
#

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
SOURCE_DIR="$PROJECT_ROOT/plugin"
BUILD_DIR="$PROJECT_ROOT/build"
STAGE_DIR="$BUILD_DIR/llms-txt"
OUTPUT_ZIP="$PROJECT_ROOT/llms-txt.zip"

# ---- Preflight ----

if ! command -v composer >/dev/null 2>&1; then
    echo "Error: composer is not installed."
    echo "Install from https://getcomposer.org/"
    exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
    echo "Error: zip is not installed."
    exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
    echo "Error: rsync is not installed."
    exit 1
fi

if [ ! -d "$SOURCE_DIR" ]; then
    echo "Error: plugin source not found at $SOURCE_DIR"
    exit 1
fi

VERSION=$(grep -E "^\s*\*\s*Version:" "$SOURCE_DIR/llms-txt.php" \
    | head -1 \
    | sed -E 's/.*Version:[[:space:]]*([0-9.]+).*/\1/')

if [ -z "$VERSION" ]; then
    echo "Error: could not parse Version from $SOURCE_DIR/llms-txt.php"
    exit 1
fi

echo "Building llms.txt for WordPress v$VERSION..."

# ---- Clean ----

rm -rf "$BUILD_DIR" "$OUTPUT_ZIP"
mkdir -p "$STAGE_DIR"

# ---- Stage source ----

rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='.phpunit.result.cache' \
    --exclude='.DS_Store' \
    --exclude='.idea' \
    --exclude='.vscode' \
    --exclude='composer.lock' \
    --exclude='package-lock.json' \
    --exclude='vendor' \
    "$SOURCE_DIR/" "$STAGE_DIR/"

# ---- Install production deps ----

echo "Installing composer dependencies (no dev)..."
( cd "$STAGE_DIR" && composer install --no-dev --optimize-autoloader --quiet --no-progress )

if [ ! -d "$STAGE_DIR/vendor" ]; then
    echo "Warning: vendor/ directory not created. Plugin will run in degraded mode (no league/html-to-markdown)."
fi

# ---- Syntax check ----

echo "Verifying PHP syntax..."
SYNTAX_OUTPUT=$(find "$STAGE_DIR/src" "$STAGE_DIR/llms-txt.php" "$STAGE_DIR/uninstall.php" \
    -name "*.php" -exec php -l {} \; 2>&1 || true)
SYNTAX_ERRORS=$(echo "$SYNTAX_OUTPUT" | grep -v "No syntax errors detected" | grep -v "Failed loading" | grep -v "opcache" || true)
if [ -n "$SYNTAX_ERRORS" ]; then
    echo "PHP syntax errors found:"
    echo "$SYNTAX_ERRORS"
    exit 1
fi

# ---- Build zip ----

echo "Creating zip..."
( cd "$BUILD_DIR" && zip -rq "$OUTPUT_ZIP" llms-txt )

# ---- Report ----

SIZE=$(du -h "$OUTPUT_ZIP" | awk '{print $1}')
FILE_COUNT=$(unzip -l "$OUTPUT_ZIP" | awk 'END {print $2}')

rm -rf "$BUILD_DIR"

echo ""
echo "Built: $OUTPUT_ZIP"
echo "Size:  $SIZE"
echo "Files: $FILE_COUNT"
echo ""
echo "Inside the zip:"
echo "  llms-txt/"
echo "    llms-txt.php"
echo "    composer.json"
echo "    readme.txt"
echo "    uninstall.php"
echo "    CHANGELOG.md"
echo "    vendor/  (composer deps)"
echo "    src/     (plugin code)"
echo ""
echo "Next: WP admin -> Plugins -> Add New -> Upload Plugin -> $OUTPUT_ZIP"
