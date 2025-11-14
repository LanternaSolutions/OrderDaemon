# Development Practices and Tooling (A11)

Audience: internal development team (core + pro)
Last updated: 2025-11-15
Scope: code-accurate to this repository.

---

## 1) Project structure

Top-level highlights:
- src/ — PSR-4 autoloaded PHP sources (namespace: `OrderDaemon\CompletionManager\`). See composer.json.
  - Core — rule engine, events, logging, security guards, components
  - Admin — menus, Rule Builder UI scaffolding, dashboards, AJAX
  - API — REST controllers (AuditLogEndpoint, RuleBuilderApiController, WebhookController, Timeline helpers)
  - Diagnostics — diagnostics APIs and frontend helpers
  - Includes — global helpers (odcm_can_use, config, DependencyChecker, Installer, etc.)
  - View — rendering helpers for admin/dashboard payloads
- assets/ — admin CSS/JS (bundled artifacts). No package.json in this repo; treat as prebuilt assets.
- languages/ — translation .mo/.po files (text domain `order-daemon`).
- docs/ — internal and public documentation.
- order-daemon.php — plugin bootstrap file.
- update-version.sh — helper script for version bumping in release workflows.
- phpunit.xml.dist — PHPUnit configuration file (see §4 Testing).
- composer.json — PSR-4 autoload config only; no runtime dependencies listed.

PSR-4 mapping:
- `OrderDaemon\\CompletionManager\\` → `src/`

---

## 2) Coding standards

- PHP version targets: WordPress-compatible PHP 7.4+ (code uses type hints and strict_types; validate against supported WP/PHP matrix during release).
- Standards: Align with WordPress Coding Standards (WPCS) and general PSR-12 style.
  - Recommended setup (TODO): add PHPCS with `wp-coding-standards/wpcs` and a `phpcs.xml` at repo root.
  - Naming:
    - Classes: StudlyCaps (e.g., `RuleBuilderApiController`).
    - Files: One class per file where practical; mirrors namespace path.
    - Functions (global helpers): snake_case with `odcm_` prefix (e.g., `odcm_can_use`).
    - Constants: UPPER_SNAKE_CASE.
  - Internationalization:
    - Always use the `order-daemon` text domain.
    - Prefer structured translation keys (e.g., `api.rule_builder.*`) instead of raw English strings.
  - Security:
    - Use the Guard system (NonceGuard, CapabilityGuard, CompositeGuard) for state-changing actions and admin AJAX/REST.
    - Sanitize and validate all input parameters in REST controllers.

Backward compatibility guidelines:
- Avoid breaking public REST response shapes and parameter names without versioning (`odcm/v1`).
- Maintain component IDs (`get_id()`) and capability keys; treat them as API.
- When deprecating, leave shims in place and log via audit (see A7) with clear i18n deprecation notices.

---

## 3) Local development environment

Prerequisites:
- PHP and Composer
- A WordPress site with WooCommerce installed and activated

Suggested setups:
- Local WP stack (Local, wp-env, Lando, or Docker). Ensure pretty permalinks and REST working.

Steps:
1) Clone the repo into `wp-content/plugins/order-daemon`.
2) Run Composer to generate the autoloader:
   - `composer install` (or `php composer.phar install` in this repo)
3) Activate the plugin in WP admin. Ensure WooCommerce is active; otherwise core aborts bootstrap and shows an admin notice.
4) Optional debug flags in wp-config.php:
   - `define('WP_DEBUG', true);`
   - `define('ODCM_DEBUG', true);` // enables certain diagnostic routes/logging
   - `define('ODCM_IS_PREMIUM_DEBUG', true);` // unlocks Pro capabilities for local testing of entitlement paths

Assets:
- Bundled JS/CSS live in assets/. There is no npm tooling in this repo; if you need to modify assets, coordinate with the frontend build pipeline or Pro repo (TODO: document build process when available).

---

## 4) Testing strategy

- PHPUnit is configured via `phpunit.xml.dist`.
- Running tests:
  - Ensure Composer autoloader is installed.
  - From the plugin directory, run: `./vendor/bin/phpunit` (or the appropriate path depending on your environment).
- Kinds of tests:
  - Unit tests where possible for pure PHP helpers and registries.
  - Integration tests against a WP/WC test environment are recommended but not yet codified in this repo (TODO).

Test data and fixtures:
- For REST controllers, prefer mocking WP functions where practical or use WP test suite bootstrap in a local integration environment.

---

## 5) Debug workflows

- i18n loading diagnostics:
  - `Plugin::load_text_domain()` logs to error_log the paths and load status. Use when translations appear missing.
- Security checks visibility:
  - `Core\Security\GuardChecker::check()` writes success/failure audit entries with user/request context.
- Rule engine visibility:
  - Use the Insight dashboard and AuditLogEndpoint to view ProcessLogger timelines for events/rules.
- REST diagnostics:
  - Audit endpoint exposes a debug diagnostic route only when `ODCM_DEBUG` is true (public `__return_true` permission). Never enable in production.
- Webhooks:
  - WebhookController always returns HTTP 200; use `process_id` in responses to correlate with audit logs.

---

## 6) Release process

Versioning and changelog:
- Bump versions consistently in `order-daemon.php` header and any constants; use `update-version.sh` helper as needed.
- Maintain `README.txt` with a changelog that complies with WordPress.org.

Packaging (Core):
- Ensure vendor/autoload files are present (`composer install --no-dev`).
- Remove dev-only files if necessary from the zip (tests, tooling configs) according to WP.org guidelines.
- Validate text domain strings and load paths; test on a clean site with WooCommerce.

Packaging (Pro):
- Pro is a separate plugin that depends on Core. Suppress upgrade prompts and unlock capabilities when active.
- Integrate licensing (wpsoftwarelicense.com) in Pro; keep all licensing network calls and storage within Pro.

Compatibility and preflight:
- Verify minimum versions of PHP/WP/WC; ensure Installer routines are idempotent.
- Smoke-test REST endpoints, Rule Builder save flows, and dashboard views with translations enabled.

---

## 7) Automation and CI (recommended)

- Add CI workflows to run PHPCS (WPCS ruleset) and PHPUnit on PRs and main branch (TODO: not present yet).
- Consider static analysis (PHPStan/Psalm) with a level appropriate for WordPress codebases (TODO).

---

## 8) Open TODOs for Dev Tooling

- Add `phpcs.xml` with WPCS and a Composer script to run `phpcs` and `phpcbf`.
- Document or add the frontend build pipeline (npm/webpack/Vite) if assets are to be rebuilt by contributors.
- Provide WP test suite bootstrap for integration tests and example factories/fixtures.
- Add GitHub Actions workflow for CI (lint + unit tests) and a release workflow for packaging zips.
