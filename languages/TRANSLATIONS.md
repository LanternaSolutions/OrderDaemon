# Translation Guide

Order Daemon uses **string keys** as `msgid` values (e.g. `admin.rule_builder.save_error`)
instead of English sentences. The English text lives in `languages/order-daemon-en_US.po`.
WordPress loads the compiled binary `order-daemon-en_US.mo` at runtime to serve those strings.

This system keeps PHP source code readable, stable, and language-neutral. Translators work
from a clean, consistent source and never need to touch PHP files.

---

## File overview

| File | Purpose | Edit? |
|------|---------|-------|
| `order-daemon.pot` | Template — generated from PHP source. Contains all string keys. | Never — auto-generated |
| `order-daemon-en_US.po` | English translations — maps every key to its English `msgstr`. | Yes — source of truth |
| `order-daemon-en_US.mo` | Compiled binary — loaded by WordPress at runtime. | Never — auto-compiled |

The `.pot` and `.po` are human-readable text files. The `.mo` is a binary that must be
re-compiled after any `.po` change.

---

## Requirements

GNU gettext tools (`xgettext`, `msgmerge`, `msgfmt`):

```bash
# Ubuntu/Debian
sudo apt install gettext

# macOS
brew install gettext && brew link gettext --force
```

---

## The full update process

Run from the plugin root:

```bash
make translations
```

That is the only command needed. It runs `bin/update-translations.sh`, which does three
steps in sequence:

### Step 1 — Extract (`xgettext`)

Scans all PHP source files and extracts every translatable string — every argument to
`__()`, `_e()`, `esc_html__()`, and the other WordPress i18n functions — into a fresh
`languages/order-daemon.pot` template file. The `.pot` has empty `msgstr ""` for every
entry; it is a pure list of keys, not translations.

### Step 2 — Merge (`msgmerge --no-fuzzy-matching`)

Merges the updated `.pot` into `languages/order-daemon-en_US.po`:

| String state | Result |
|---|---|
| Key exists in `.po` with English `msgstr` | **Preserved unchanged** |
| Key is new (in `.pot` but not in `.po`) | **Added with empty `msgstr ""`** |
| Key was removed from PHP source | **Marked obsolete** (`#~ msgid …`) and left in file |

`--no-fuzzy-matching` is critical. Without it, `msgmerge` may guess that two similar-looking
keys are the same thing and silently remap a translation to the wrong key. With string keys
(not English phrases), fuzzy matching has no value and only causes corruption.

After this step the script prints how many strings have an empty `msgstr` — those need
English text added before the plugin will display them correctly.

### Step 3 — Compile (`msgfmt`)

Compiles `order-daemon-en_US.po` → `order-daemon-en_US.mo`. WordPress reads the `.mo`
file at runtime; it contains only entries with a non-empty `msgstr`, so any string without
an English translation will fall back to displaying its raw key in the UI.

The script also deletes any `order-daemon-en_US.l10n.php` present — see
[Why not Poedit?](#why-not-poedit) below.

---

## After running the script — filling in missing strings

After `make translations`, check how many strings still need English text:

```bash
make translations-check
```

There are two distinct categories of missing strings:

### Category A — Dot-notation keys without English text

These already follow the correct pattern. They were added to PHP code but their English
`msgstr` was never written in the `.po`.

Find them by searching for `msgstr ""` in `order-daemon-en_US.po`. Entries that look like:

```po
msgid "admin.insight_dashboard.filter_pane"
msgstr ""
```

Fix by adding the English text:

```po
msgid "admin.insight_dashboard.filter_pane"
msgstr "Filter Pane"
```

Then run `make translations` again to recompile the `.mo`.

### Category B — Raw English strings (wrong pattern)

These are PHP strings where the developer used an English sentence directly as the `msgid`
instead of a dot-notation key:

```php
// Wrong — bypasses the key system
__( 'Action Failed', 'order-daemon' )
```

They appear in the `.po` as:

```po
msgid "Action Failed"
msgstr ""
```

There are two ways to resolve them:

**Quick fix (unblock the plugin, do it now):**
Copy the English string into `msgstr`. The plugin displays correctly, and any future
translator can override it.

```po
msgid "Action Failed"
msgstr "Action Failed"
```

**Proper fix (convert to a key, do it eventually):**
1. Choose a dot-notation key: `admin.rule_builder.action_failed`
2. Update the PHP call: `__( 'admin.rule_builder.action_failed', 'order-daemon' )`
3. Add the key+English to the `.po`
4. Run `make translations`

The update script warns about raw English strings each time it runs, so they are easy
to track down. The goal is to have zero Category B strings — `make translations` should
report "0 string(s) use raw English text".

---

## Adding a new string to the plugin (developer workflow)

1. Pick a dot-notation key that describes where and what it is:
   `module.component.description_of_string`

2. Use it in PHP:

   ```php
   __( 'admin.rule_builder.condition_added', 'order-daemon' )
   ```

3. Run `make translations` — the key is extracted, added to `.pot`, and merged into `.po`
   with an empty `msgstr`.

4. Open `languages/order-daemon-en_US.po`, find the new key, and add the English text.

5. Run `make translations` again to compile the updated `.mo`.

6. Commit `order-daemon.pot`, `order-daemon-en_US.po`, and `order-daemon-en_US.mo` together.

---

## Adding a new locale

1. Copy the template as the starting point for the new locale:

   ```bash
   cp languages/order-daemon.pot languages/order-daemon-fr_FR.po
   ```

2. Edit the header block at the top of the new `.po`: set `Language:`, `Language-Team:`,
   and `Plural-Forms:` for the target locale.

3. Translate each `msgstr ""` to the target language.

4. Compile:

   ```bash
   msgfmt -o languages/order-daemon-fr_FR.mo languages/order-daemon-fr_FR.po
   ```

5. When PHP code changes in the future, run `make translations` — the script currently
   only merges `en_US`. For other locales, run `msgmerge` directly:

   ```bash
   msgmerge --no-fuzzy-matching --update --no-wrap --backup=none \
       languages/order-daemon-fr_FR.po \
       languages/order-daemon.pot
   msgfmt -o languages/order-daemon-fr_FR.mo languages/order-daemon-fr_FR.po
   ```

---

## String key conventions

All msgid values use dot-notation: `module.component.action`

```
admin.rule_builder.save_error
audit.logs.delete.error.no_valid_log_ids_found
api.orders.fetch.timeout
```

Rules:
- All lowercase
- Dots separate segments: `module` → `component` → `action_or_description`
- Underscores within a segment, never hyphens
- Be specific enough that the key is unambiguous without reading the translation

**Never** use raw English sentences as `msgid`:

```php
// Wrong
__( 'Failed to save rule', 'order-daemon' )

// Correct
__( 'admin.rule_builder.save_error', 'order-daemon' )
```

The update script warns about raw English strings every time it runs.

---

## Why not Poedit?

Poedit generates a `order-daemon-en_US.l10n.php` file alongside the `.mo`. WordPress
loads `.l10n.php` in preference to `.mo` when it exists. The problem: that file is only
updated when you use Poedit — if you compile the `.mo` any other way, the `.l10n.php`
silently wins and the new translations are invisible. This is why strings appeared broken
after every manual attempt.

The automated script compiles the `.mo` without Poedit and deletes any `.l10n.php` it
finds. Never commit `*.l10n.php` to the repository.

---

## What NOT to commit

```
languages/*.l10n.php     — Poedit cache, breaks translations
```

Everything else in `languages/` should be committed: `.pot`, `.po`, and `.mo`.

---

## Quick reference

| Task | Command |
|------|---------|
| Update everything (extract → merge → compile) | `make translations` |
| Check how many strings are untranslated | `make translations-check` |
| Compile `.po` to `.mo` only (after hand-editing `.po`) | `msgfmt -o languages/order-daemon-en_US.mo languages/order-daemon-en_US.po` |
