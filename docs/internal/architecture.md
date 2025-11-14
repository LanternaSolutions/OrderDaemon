# High‑Level Architecture — Order Daemon (Core + Pro)

Audience: Internal (engineering, support). This document summarizes what the plugin is for, its boundaries and dependencies, and a map of the main components as implemented in the repository as of 2025‑11‑15.


## Purpose and Positioning

Order Daemon is an automation engine for WooCommerce orders. It evaluates configurable rules (triggers + conditions + actions) in response to order lifecycle events and performs actions such as automatically marking orders as completed. Core aims to cover essential automation for common stores; Pro adds advanced rule components, integrations, diagnostics, and workflow tooling.

Key goals:
- Automate order completion and related workflows with clear, predictable behavior.
- Preserve auditability: record what happened, when, and why.
- Provide a secure, capability-aware foundation that can be extended by Pro and third parties.

Core vs Pro positioning:
- Core provides the foundational rule engine, the odcm_order_rule custom post type, essential UI, base diagnostics, and REST endpoints used by the dashboards and the Rule Builder API.
- Pro builds on top of Core to unlock premium components and advanced UX, plus optional features like webhook integrations, export tooling, CLI, and deeper diagnostics. The Pro codebase is not part of this repository; the architecture anticipates Pro by keeping premium awareness in Core.


## System Boundaries and Dependencies

Primary platform:
- WordPress (plugin system, hooks, admin UI).
- WooCommerce (order objects, status transitions, checkout lifecycle, payment events). The plugin refuses to initialize if WooCommerce is missing (admin notice + early return) — see Plugin::bootstrap().

Runtime services and integration points:
- WP Cron / async processing: background tasks, order reprocessing and scheduling are handled within Core (see Core methods like schedule_completion_check, reprocess_pending_orders). If Action Scheduler is present through WooCommerce, background jobs piggy‑back on that ecosystem indirectly via Woo.
- REST API: endpoints are registered on rest_api_init for internal dashboards and the Rule Builder API (API\AuditLogEndpoint, API\RuleBuilderApiController, API\WebhookController).
- Admin UI: Rule Builder and dashboards live inside the WP Admin and consume REST endpoints and PHP-rendered scaffolding.
- i18n: text domain order-daemon is loaded early with a fallback to absolute .mo path and JSON script translations for admin JS bundles (see Plugin::ensure_i18n() and load_text_domain()).
- WP‑CLI: anticipated in Pro (not present in this repository). Core is written to keep post types and bootstrap universally available in CLI contexts.
- External webhooks: a WebhookController exists in Core to accept inbound events; configuration UX and advanced behaviors may be Pro (confirm in Pro).

Integration with other WooCommerce extensions:
- The engine listens to standard WooCommerce events (order status changes, payment complete, order created). Compatibility with extensions like Subscriptions or Bookings generally flows through those events. Where specialized lifecycles exist, Core provides synthesizers (e.g., synthesize_subscription_event) to normalize incoming signals into a universal event model. Any deep, extension‑specific components should be implemented as dedicated triggers/conditions in Core or Pro as appropriate.


## Component Overview (by responsibility)

1) Plugin bootstrap and lifecycle (src/Plugin.php)
- Single entry point responsible for installer routines, dependency checks, i18n, and deterministic hook registration.
- Critical init ordering on the init hook:
  - Priority 5: register_post_type() via Admin::register_completion_rule_post_type() to register odcm_order_rule for all contexts (admin, CLI, frontend, cron).
  - Priority 6: load_options() to register rule components and audit filters (includes: src/Core/options.php, src/Core/audit-filters.php, src/Core/PayloadComponentRegistry.php).
  - Priority 10: initialize_core() to init Core, ManualStatusTracker, premium fallback system, and the Guard security system.
  - Priority 15: initialize_admin_components() to initialize admin UI (Admin, InsightDashboard, DiagnosticDashboard, optional UpgradePrompts) in admin only.
  - On rest_api_init (prio 10): initialize_api_endpoints().

2) Rule Engine (src/Core)
- Core class is the central orchestrator for evaluating automation in response to order lifecycle changes.
- Event intake: hooks for payment completion, order status transitions, order creation, and subscription‑related events (where present) feed into universal events (see synthesize_* methods) so downstream evaluation uses a consistent model.
- Evaluation flow: Core identifies active rules, matches relevant triggers/conditions, and executes actions. The exact component interfaces live under src/Core/RuleComponents (Triggers, Conditions, Actions, Interfaces). Registration happens through options.php so components are discoverable by UI and services.
- Idempotency and safety: utilities like has_specific_status_processed, is_duplicate_status_transition, and chain‑of‑custody tracking via ManualStatusTracker reduce duplicates and provide observability.
- Resilience: methods like emergency_fallback_processing and circuit breaker checks help avoid runaway failures and provide controlled degradation.

3) Admin UI (src/Admin, src/View, assets)
- Admin entry point Admin::init() wires menus and screens for managing rules and dashboards.
- Rule Builder UI is a JS-driven experience that consumes server‑provided schemas and entitlements. UI assets are translation‑enabled via wp_set_script_translations.
- InsightDashboard and DiagnosticDashboard provide operational and support tooling; they fetch data via the REST API and internal services.

4) Audit Log and Diagnostics (src/Core/Logging, src/Diagnostics, API)
- Audit logging records evaluation steps and results for traceability (e.g., log_rule_evaluation_started, log_rule_evaluation_result, log_no_rules_matched). Log filters and registry are loaded in load_options().
- Diagnostics include admin‑facing checks and API endpoints under src/Diagnostics and src/Diagnostics/API. The DiagnosticDashboard surfaces environment and status checks.

5) REST API layer (src/API)
- AuditLogEndpoint: exposes audit log queries and aggregates for the admin dashboards.
- RuleBuilderApiController: provides CRUD/validation endpoints for rules, schemas, and related configuration used by the Rule Builder UI.
- WebhookController: provides universal event ingress for external systems. Authentication, rate limiting, and advanced mapping are to be confirmed per feature level; ensure guards and capability checks are used.

6) Security / Guard System (src/Core/Security)
- A GuardChecker service can be initialized globally (stored as $GLOBALS['odcm_guard_checker']) to centralize permission checks. Components should consult the guard instead of embedding ad‑hoc capability logic. Initialization is safe‑guarded (class_exists checks) and failures are non‑fatal by design.

7) Licensing / Entitlement / Premium Fallback (Core + Pro)
- Entitlement model: components (conditions, triggers, actions, filters) can declare capability keys that map to feature tiers. UI reflects premium availability (disabled controls, badges, prompts) while server‑side checks enforce behavior.
- PremiumComponentFallback (Core) initializes defensively if present. It protects rule evaluation when rules reference premium components but Pro is not active (e.g., migrate, ignore with clear logging, or apply safe defaults). Initialization errors are logged and non‑fatal.
- Pro responsibilities (outside this repo) include licensing, entitlement resolution, UI unlocks, and CLI/advanced features. Core must never duplicate Pro logic; it exposes feature detection points and guards instead.

8) Payload Rendering & Composite Components
- Payload component registry (src/Core/PayloadComponentRegistry.php) supports composing complex payloads for UI/diagnostics/webhooks. Registered early during load_options() to be available to both API and UI layers.


## Execution Contexts and Ordering Guarantees

- Custom Post Type odcm_order_rule must be registered before any admin handlers or queries attempt to use it. The plugin enforces this by registering at init priority 5 across all contexts, avoiding “invalid post type” errors during early admin_init or REST requests.
- Options and filter registrations are loaded immediately after CPT registration to ensure schemas and UI metadata exist before Core and admin/UI initialization.
- REST endpoints are attached after Core init; endpoints themselves should rely on guards and capability checks.
- i18n is ensured very early; if init already ran, load_text_domain() is called immediately, otherwise it is hooked to init priority 0.


## External Dependencies and Version Notes

- Requires WooCommerce to be active; otherwise the plugin posts an admin notice (esc_html__('core.plugin.dependency.woocommerce_required', 'order-daemon')) and aborts bootstrap.
- Text domain: order-daemon. Translations loaded from languages/ relative path, with an absolute .mo fallback by locale, and JSON script translations for admin scripts: order-daemon-admin-js, order-daemon-rule-builder, order-daemon-insight-dashboard.
- WordPress and PHP version constraints are enforced by the hosting environment and (for Pro) by its own dependency validator. This repository does not include Pro’s validator.


## Known Integration Points and Extension Strategy

- Hooks and filters: Core exposes actions/filters to register additional rule components and adjust behavior (see options.php, audit-filters.php, and docs plan B11.8 for reference targets). Third‑party developers can extend the rule system by implementing interfaces under src/Core/RuleComponents/Interfaces and registering their components.
- Webhooks: Universal ingress is provided via WebhookController; outbound behavior and UI are subject to Pro confirmation.
- Diagnostics: Extend diagnostic categories and checks under src/Diagnostics where appropriate; ensure any new checks surface in the DiagnosticDashboard.


## Pro Extension Architecture (to confirm in Pro)

The following aspects are designed for but not present in this repository snapshot. They should be validated against the Pro codebase:
- Dependency on the free plugin and detection strategy; graceful admin notices when Core is missing.
- Entitlement resolver and caching; how feature tiers map to capability keys.
- Licensing manager and network failure modes; UX for expired/unreachable states.
- Pro‑only component registrations (advanced triggers/conditions/actions) and how they are surfaced to the Rule Builder UI.
- CLI command registrar and supported operations (bulk reprocessing, export, diagnostics).
- Admin extensions (webhook configuration UI, advanced audit filters/export, additional dashboards).


## Non‑Goals and Out‑of‑Scope

- Replacing WooCommerce order storage or status models.
- Implementing payment gateways.
- Providing a generic workflow engine disconnected from orders — the engine centers on WooCommerce order lifecycles.


## Open TODOs

- Pro boot sequence, licensing, and entitlement resolution: confirm implementation details from the Pro repository and update this document accordingly.
- Multisite specifics (network activation, site‑level vs network‑level settings): not covered by current code, investigate Installer behavior if multisite support is required.
- Public developer docs will mirror many of these concepts with stable extension points — keep internal docs authoritative and deeply tied to code while ensuring public docs remain stable and non‑breaking for integrators.
