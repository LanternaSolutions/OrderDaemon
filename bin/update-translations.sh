#!/usr/bin/env bash
# =============================================================================
# update-translations.sh
#
# Regenerates the translation template (POT), merges new strings into the
# English PO file without losing existing translations, and compiles the
# binary MO file.
#
# Requirements: xgettext, msgmerge, msgfmt  (GNU gettext-tools)
#   Ubuntu/Debian: sudo apt install gettext
#   macOS:         brew install gettext
#
# Usage:
#   bin/update-translations.sh               # run from plugin root
#   bin/update-translations.sh --check       # report untranslated strings only
#   bin/update-translations.sh --fill-raw    # auto-fill raw English msgstr (msgstr = msgid)
# =============================================================================
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LANGUAGES_DIR="$PLUGIN_DIR/languages"
TEXT_DOMAIN="order-daemon"
LOCALE="en_US"

POT_FILE="$LANGUAGES_DIR/$TEXT_DOMAIN.pot"
PO_FILE="$LANGUAGES_DIR/$TEXT_DOMAIN-$LOCALE.po"
MO_FILE="$LANGUAGES_DIR/$TEXT_DOMAIN-$LOCALE.mo"
L10N_PHP="$LANGUAGES_DIR/$TEXT_DOMAIN-$LOCALE.l10n.php"

MODE="${1:-}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
yellow(){ printf '\033[33m%s\033[0m\n' "$*"; }
red()   { printf '\033[31m%s\033[0m\n' "$*"; }

require_tool() {
    if ! command -v "$1" &>/dev/null; then
        red "ERROR: '$1' is not installed."
        echo "  Ubuntu/Debian: sudo apt install gettext"
        echo "  macOS:         brew install gettext && brew link gettext --force"
        exit 1
    fi
}

# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------
require_tool xgettext
require_tool msgmerge
require_tool msgfmt

cd "$PLUGIN_DIR"

PLUGIN_VERSION=$(grep -m1 '^\s*\* Version:' order-daemon.php | sed 's/.*Version:\s*//' | tr -d ' \r')

if [[ "$MODE" == "--fill-raw" ]]; then
    bold "=== Auto-filling raw English strings (msgstr = msgid) ==="
    echo "  This sets msgstr equal to msgid for any untranslated string whose"
    echo "  msgid is a raw English phrase rather than a dot-notation key."
    echo "  Run 'make translations' afterward to recompile the .mo."
    echo ""
    require_tool python3
    FILLED=$(python3 - "$PO_FILE" << 'PYEOF'
import re, sys

po_file = sys.argv[1]
with open(po_file, 'r', encoding='utf-8') as f:
    content = f.read()

# Split on blank lines while preserving structure
entries = re.split(r'\n\n', content)
fixed = 0
result = []

for entry in entries:
    msgid_match = re.search(r'^msgid (.+)$', entry, re.MULTILINE)
    msgstr_empty = re.search(r'^msgstr ""$', entry, re.MULTILINE)

    if msgid_match and msgstr_empty:
        raw = msgid_match.group(1).strip('"')
        # Auto-fill only raw English (not a dot-notation key, not the header blank msgid)
        if raw and not re.match(r'^[a-z][a-z0-9_]*\.', raw):
            new_msgstr = f'msgstr {msgid_match.group(1)}'
            entry = re.sub(r'^msgstr ""$', new_msgstr, entry, flags=re.MULTILINE)
            fixed += 1

    result.append(entry)

with open(po_file, 'w', encoding='utf-8') as f:
    f.write('\n\n'.join(result))

print(fixed)
PYEOF
)
    green "  Filled $FILLED raw English strings."
    echo ""
    echo "  Next: open $PO_FILE and fill in the dot-notation keys that still have"
    echo "  empty msgstr, then run 'make translations' to recompile the .mo."
    exit 0
fi

if [[ "$MODE" == "--check" ]]; then
    bold "=== Translation Status Check ==="
    echo ""
    # Subtract 1 to exclude the header entry (which always has an empty msgstr)
    UNTRANSLATED=$(grep -c '^msgstr ""$' "$PO_FILE" || true)
    UNTRANSLATED=$((UNTRANSLATED - 1))
    TRANSLATED_COUNT=$(msgfmt --statistics "$PO_FILE" 2>&1 | grep -oE '^[0-9]+' || echo "0")
    if [[ "$UNTRANSLATED" -gt 0 ]]; then
        echo "$TRANSLATED_COUNT translated messages, $UNTRANSLATED untranslated messages."
    else
        echo "$TRANSLATED_COUNT translated messages."
    fi
    echo ""
    if [[ "$UNTRANSLATED" -gt 0 ]]; then
        yellow "  $UNTRANSLATED string(s) have empty msgstr (need English text)."
        echo ""
        echo "  First 10 untranslated:"
        SAMPLE=$(awk '
            /^msgid ""/  { skip=1; next }
            skip && /^msgstr ""$/ { skip=0; next }
            /^msgid /    { id=$0 }
            /^msgstr ""$/ { if (++n <= 10) print "  " id }
        ' "$PO_FILE" || true)
        echo "$SAMPLE"
    else
        green "  All strings are translated."
    fi
    exit 0
fi

# ---------------------------------------------------------------------------
# Step 1: Extract all translatable strings from PHP into POT template
# ---------------------------------------------------------------------------
bold "=== Step 1/3: Extracting strings from PHP source ==="

# Build list of PHP files to scan (exclude vendor and deploy dirs)
PHP_FILES=$(find "$PLUGIN_DIR" -name "*.php" \
    -not -path "*/vendor/*" \
    -not -path "*/node_modules/*" \
    -not -path "*/.deploy/*" \
    -not -path "*/assets/css/vendor/*" \
    -not -path "*/assets/js/vendor/*" \
    | sort)

FILE_COUNT=$(echo "$PHP_FILES" | wc -l | tr -d ' ')
echo "  Scanning $FILE_COUNT PHP files..."

echo "$PHP_FILES" | xgettext \
    --from-code=UTF-8 \
    --language=PHP \
    --keyword=__ \
    --keyword=_e \
    --keyword=_n:1,2 \
    --keyword=_x:1,2c \
    --keyword=_ex:1,2c \
    --keyword=_nx:4c,1,2 \
    --keyword=esc_attr__ \
    --keyword=esc_attr_e \
    --keyword=esc_attr_x:1,2c \
    --keyword=esc_html__ \
    --keyword=esc_html_e \
    --keyword=esc_html_x:1,2c \
    --keyword=_n_noop:1,2 \
    --keyword=_nx_noop:3c,1,2 \
    --keyword=__ngettext_noop:1,2 \
    --add-comments=translators: \
    --sort-output \
    --no-wrap \
    --package-name="Order Daemon for WooCommerce" \
    --package-version="$PLUGIN_VERSION" \
    --msgid-bugs-address="support@orderdaemon.com" \
    --copyright-holder="Order Daemon" \
    --files-from=- \
    -o "$POT_FILE"

# Fix the placeholder comment header that xgettext always generates
sed -i \
    -e 's/^# SOME DESCRIPTIVE TITLE\./# Order Daemon for WooCommerce translation template./' \
    -e 's/^# Copyright (C) YEAR THE PACKAGE'\''S COPYRIGHT HOLDER/# Copyright (C) Order Daemon/' \
    -e 's/^# FIRST AUTHOR <EMAIL@ADDRESS>, YEAR\./# Translators, see https:\/\/translate.wordpress.org\//' \
    "$POT_FILE"

NEW_STRINGS=$(grep -c '^msgid ' "$POT_FILE" || true)
green "  POT generated: $NEW_STRINGS strings found → $POT_FILE"

# ---------------------------------------------------------------------------
# Warn about English strings that bypass the key-based pattern
# (These should be converted to dot-notation keys)
# ---------------------------------------------------------------------------
BAD_STRINGS=$(grep '^msgid ' "$POT_FILE" \
    | grep -v '^msgid ""$' \
    | grep -v '^msgid "[a-z][a-z0-9_]*\.[a-z]' \
    | grep -v '^msgid "%' \
    | wc -l | tr -d ' ' || true)

if [[ "$BAD_STRINGS" -gt 0 ]]; then
    echo ""
    yellow "  WARNING: $BAD_STRINGS string(s) use raw English text instead of dot-notation keys."
    yellow "  These should be converted to keys like 'module.component.action'."
    echo ""
    # Collect first 10 into a variable to avoid SIGPIPE from head closing the pipe
    BAD_SAMPLE=$(grep '^msgid ' "$POT_FILE" \
        | grep -v '^msgid ""$' \
        | grep -v '^msgid "[a-z][a-z0-9_]*\.[a-z]' \
        | grep -v '^msgid "%' \
        || true)
    echo "$BAD_SAMPLE" | head -10 | sed 's/^/    /'
    if [[ "$BAD_STRINGS" -gt 10 ]]; then
        echo "    ... and $((BAD_STRINGS - 10)) more."
    fi
    echo ""
fi

# ---------------------------------------------------------------------------
# Step 2: Merge new strings into en_US.po (preserve existing translations)
# ---------------------------------------------------------------------------
bold "=== Step 2/3: Merging into $LOCALE.po (preserving existing translations) ==="
echo "  Using --no-fuzzy-matching to prevent key mismatches..."

msgmerge \
    --no-fuzzy-matching \
    --update \
    --no-wrap \
    --backup=none \
    "$PO_FILE" \
    "$POT_FILE" 2>&1 | sed 's/^/  /'

# Count untranslated (empty msgstr), subtract 1 for the header entry
UNTRANSLATED=$(grep -c '^msgstr ""$' "$PO_FILE" || true)
UNTRANSLATED=$((UNTRANSLATED - 1))

# Report statistics after merge (adjusted to exclude header entry)
TRANSLATED_COUNT=$(msgfmt --statistics "$PO_FILE" 2>&1 | grep -oE '^[0-9]+' || echo "0")
if [[ "$UNTRANSLATED" -gt 0 ]]; then
    green "  $TRANSLATED_COUNT translated messages, $UNTRANSLATED untranslated messages."
else
    green "  $TRANSLATED_COUNT translated messages."
fi

if [[ "$UNTRANSLATED" -gt 0 ]]; then
    echo ""
    yellow "  $UNTRANSLATED new string(s) need English translations in $PO_FILE"
    yellow "  Search for: msgstr \"\"  (followed by a blank line — not the header)"
    echo ""
    echo "  First 5 untranslated:"
    SAMPLE=$(awk '
        /^msgid ""/  { skip=1; next }
        skip && /^msgstr ""$/ { skip=0; next }
        /^msgid /    { id=$0 }
        /^msgstr ""$/ { if (++n <= 5) print "    " id }
    ' "$PO_FILE" || true)
    echo "$SAMPLE"
fi

# ---------------------------------------------------------------------------
# Step 3: Compile PO → MO binary
# ---------------------------------------------------------------------------
bold "=== Step 3/3: Compiling $LOCALE.po → .mo binary ==="

msgfmt -o "$MO_FILE" "$PO_FILE"
green "  MO compiled → $MO_FILE"

# Remove any Poedit-generated PHP cache (stale cache breaks translations)
if [[ -f "$L10N_PHP" ]]; then
    rm -f "$L10N_PHP"
    yellow "  Removed stale Poedit cache: $L10N_PHP"
fi

echo ""
bold "=== Done ==="

if [[ "$UNTRANSLATED" -gt 0 ]]; then
    echo ""
    yellow "Next steps:"
    echo "  1. Open languages/$TEXT_DOMAIN-$LOCALE.po"
    echo "  2. Search for empty translations: msgstr \"\""
    echo "  3. Add English text for each new string key"
    echo "  4. Re-run this script to recompile the .mo"
else
    green "All strings are translated. Commit languages/$TEXT_DOMAIN.pot, $TEXT_DOMAIN-$LOCALE.po, and $TEXT_DOMAIN-$LOCALE.mo"
fi
