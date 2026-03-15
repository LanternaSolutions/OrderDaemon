# Contributing to Order Daemon

Thank you for your interest in contributing!

## How this repository works

This repository is a public mirror of our private development repository. We welcome contributions, but because of this setup, **pull requests cannot be merged directly** — we apply them manually to our private repo and credit you in the commit.

## Reporting bugs

Please open an issue. Include:
- WordPress and WooCommerce versions
- PHP version
- Steps to reproduce
- What you expected vs. what happened

## Suggesting features

Open an issue describing the use case and what you'd like to see.

## Submitting code changes

1. Fork this repository
2. Make your changes on a branch
3. Open a pull request with a clear description of what it does and why
4. We will review it, apply it to our private repo, and close the PR with a reference to the commit it was merged in

We aim to review PRs within a few business days. Your contribution will be credited in the changelog.

## Code standards

This plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). Run [Plugin Checker](https://wordpress.org/plugins/plugin-check/) before submitting.

## Translations

This plugin uses a **string key system**: all i18n calls use dot-notation keys as the
first argument (e.g. `__('admin.ui.active', 'order-daemon')`), and the English text
lives in `languages/order-daemon-en_US.po`.

### Adding a new string

1. Use a dot-notation key in your PHP code: `__('module.component.action', 'order-daemon')`
2. Run `make translations` (requires `gettext-tools` — see below)
3. Open `languages/order-daemon-en_US.po` and add the English `msgstr` for your new key
4. Run `make translations` again to compile the `.mo` binary

### Updating translations after code changes

**Requirements:** GNU gettext tools
```bash
# Ubuntu/Debian
sudo apt install gettext

# macOS
brew install gettext && brew link gettext --force
```

Then run:
```bash
make translations
```

This script:
1. Scans all PHP files and regenerates `languages/order-daemon.pot`
2. Merges new strings into `languages/order-daemon-en_US.po` (existing translations are preserved)
3. Compiles `languages/order-daemon-en_US.mo`

Check translation status without modifying files:
```bash
make translations-check
```

### Rules

- **Never use raw English strings** in i18n calls — always use a dot-notation key
- **Never commit `*.l10n.php`** files — these are Poedit cache files that override `.mo` and can break translations
- **Never create a new `.po` from scratch** — always update the existing one; creating from scratch loses all English translations
- **File naming is critical:** `order-daemon-en_US.mo` (dash before locale) — dots break WordPress auto-loading
