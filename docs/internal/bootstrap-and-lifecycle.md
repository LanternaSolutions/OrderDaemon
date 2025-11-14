# Plugin Bootstrapping and Lifecycle — Order Daemon (Core)

Audience: Internal (engineering, support). Code-accurate to this repository snapshot as of 2025‑11‑15. Primary source: src/Plugin.php (main bootstrap), plus referenced classes.


## Scope and sources

This document explains how the core plugin boots inside WordPress, the exact hooks and priorities used, and the safeguards that prevent race conditions or partial initialization. It mirrors the implementation in src/Plugin.php and notes integration points for Admin, Core, REST, i18n, and premium/security helpers.


## Fast overview (tl;dr)

- On load, Installer::install() runs (idempotent; version‑guarded).
- If WooCommerce is missing, an admin notice is displayed and bootstrap aborts.
- i18n is ensured very early (text domain + JS translations) with an absolute‑path .mo fallback.
- Deterministic init ordering on the init hook prevents “invalid post type” errors:
  - Priority 5: register_post_type() — register odcm_order_rule for all contexts.
  - Priority 6: load_options() — register triggers/conditions/actions, audit filters, payload registry.
  - Priority 10: initialize_core() — Core::init(), ManualStatusTracker, PremiumComponentFallback, Security Guard.
  - Priority 15: initialize_admin_components() — Admin UI (admin only).
- On rest_api_init (priority 10), REST routes are registered (Audit Log, Rule Builder, Webhooks).


## Detailed boot sequence

1) Installer / upgrade routines
- Called at the very beginning of Plugin::bootstrap():
  - Installer::install()
  - Safe to call on every load (no‑op if already up‑to‑date).

2) WooCommerce dependency guard
- Before initializing anything else, Plugin::bootstrap() checks:
  - if (!class_exists('WooCommerce')) { hook admin_notices and return; }
- Behavior when WooCommerce is missing:
  - Outputs esc_html__('core.plugin.dependency.woocommerce_required', 'order-daemon') in an admin notice.
  - Returns early — NO CPT, NO options, NO Core/Admin/REST initialization.

3) Internationalization (i18n) strategy
- ensure_i18n() executes early in bootstrap:
  - If the 'order-daemon' textdomain is already loaded, do nothing.
  - If we are already in/past init (did_action('init')), call load_text_domain() immediately.
  - Else, add_action('init', [$this, 'load_text_domain'], 0) to run at the earliest priority.
- load_text_domain() performs, in this order:
  - load_plugin_textdomain('order-daemon', false, <plugin-dir>/languages)
  - If still not loaded, derive locale and attempt absolute path load:
    - ODCM_PLUGIN_DIR . 'languages/order-daemon-<locale>.mo' via load_textdomain().
  - Registers JSON script translations (if available):
    - wp_set_script_translations('order-daemon-admin-js', 'order-daemon')
    - wp_set_script_translations('order-daemon-rule-builder', 'order-daemon')
    - wp_set_script_translations('order-daemon-insight-dashboard', 'order-daemon')

4) Hook-based initialization (race-condition hardened)
- The plugin wires a strict order on the init hook to ensure CPT and registrations exist before any admin handlers or queries:
  - Priority 5 — register_post_type()
    - Delegates to Admin::register_completion_rule_post_type().
    - Registers odcm_order_rule in ALL contexts (admin, CLI, frontend, cron/background).
  - Priority 6 — load_options()
    - Requires:
      - src/Core/options.php (component registrations: triggers/conditions/actions)
      - src/Core/audit-filters.php (audit log filter registrations)
      - src/Core/PayloadComponentRegistry.php (payload composition)
  - Priority 10 — initialize_core()
    - $core = new Core(); $core->init();
    - ManualStatusTracker::init(); (chain-of-custody logging for manual changes)
    - initialize_premium_fallback_system():
      - If Core\PremiumComponentFallback exists, call ::init(); failures are logged and non‑fatal.
    - initialize_security_system():
      - If Core\Security\GuardChecker exists, instantiate and store in $GLOBALS['odcm_guard_checker'] for shared access.
  - Priority 15 — initialize_admin_components() (admin only)
    - if (is_admin()) { Admin::init(); InsightDashboard::init(); DiagnosticDashboard::init(); }
    - Optionally instantiate Includes\UpgradePrompts if the class exists (WordPress.org‑compliant upgrade messaging).
- REST API initialization
  - On rest_api_init (priority 10): initialize_api_endpoints() registers routes for:
    - API\AuditLogEndpoint
    - API\RuleBuilderApiController
    - API\WebhookController (universal event ingress)


## Execution contexts and guarantees

- odcm_order_rule MUST exist before any admin handlers or early queries; hence CPT registration at init prio 5.
- The options/registrations are guaranteed to be available before Core/Admin init.
- REST endpoints are registered after Core is ready; endpoints should consult guard/capability checks.
- i18n is loaded before any UI rendering to avoid untranslated labels and to enable JS translations.


## Components initialized during bootstrap

- Core engine (src/Core/Core.php): orchestration of rule evaluation and event intake.
- ManualStatusTracker: logs manual status changes to maintain a chain of custody.
- PremiumComponentFallback (optional): protects behavior when rules reference premium components but Pro is not active; initialization is wrapped in try/catch.
- Security Guard system (optional): central GuardChecker instance placed into $GLOBALS['odcm_guard_checker'] to standardize permission checks.
- Admin UI (admin only): menus/screens via Admin, plus InsightDashboard and DiagnosticDashboard.
- REST API: AuditLogEndpoint, RuleBuilderApiController, WebhookController.


## Edge cases and resilience

- WooCommerce inactive: admin notice + early return; no other components initialize.
- Premium fallback or guard failures: caught and logged; do not prevent core functionality from working.
- Pro active but free missing: behavior is defined in Pro (not present here). See TODO below.
- Multisite activation: no special handling visible in this repo; investigate Installer for network‑wide paths if/when required.


## Operational notes and troubleshooting

- If you see “Invalid post type” for odcm_order_rule in admin flows, verify that:
  - register_post_type() remains hooked at init priority 5, and no other plugin reorders init hooks.
  - Admin code that queries rules does not run before init or at a lower priority than 5.
- If strings are not translated:
  - Confirm that load_text_domain() runs (either immediately after init or at init prio 0).
  - Check languages/ paths and that the .mo for the current locale exists/readable.
  - Confirm script handles match those registered for wp_set_script_translations().
- If REST endpoints 404:
  - Ensure rest_api_init ran (site isn’t fataling earlier) and that initialization didn’t abort on the WooCommerce check.


## Pro bootstrapping (notes to confirm in Pro)

The Pro plugin is not part of this repository. The expected (to be verified) behaviors include:
- Detecting/depending on the free plugin and showing an admin notice if free is missing.
- Enabling premium capabilities (both UI unlocks and server‑side enforcement) without duplicating core logic.
- Licensing manager wiring and caching; network interactions and failure modes.
- CLI command registrar for Pro‑only automation.
- Additional admin extensions (e.g., webhook configuration UI if Pro‑only, log exporter, Rule Builder enhancements).


## TODOs

- Pro dependency validation and boot sequence: confirm in the Pro repository and document exact hooks/priorities.
- Multisite specifics (network activation pathways, Installer behavior): investigate and document if/when required.
