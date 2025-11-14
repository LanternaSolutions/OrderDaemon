# Pro Entitlements and Capability System

Audience: Internal (core + pro developers, support, QA)
Last updated: 2025-11-15

Overview
- Order Daemon uses a capability-based entitlement model that is independent of WordPress user caps. These are feature-tier keys (e.g., trigger_basic, condition_multi_category, unlimited_rules) consumed by the UI and API to enable/disable features for Free vs Pro users.
- Core exposes a single source of truth function odcm_can_use(feature_key) in src/Includes/functions.php. All gating (UI and server) should defer to this function.
- The Pro add-on unlocks capabilities by short-circuiting odcm_can_use() (via filters/overrides) and by hiding upgrade prompts. Core remains fully functional without Pro.

Key code touch points
- src/Includes/functions.php
  - odcm_can_use(string $feature_key): bool — central entitlement function with extensive inline documentation. Implements tier logic, naming conventions, and a dev/debug override (ODCM_IS_PREMIUM_DEBUG) for testing premium features.
  - Other helpers reference the entitlement model in comments and examples.
- src/Core/RuleComponents/Interfaces/ComponentInterface.php
  - get_capability(): string — every Trigger/Condition/Action declares a capability ID that the UI/API must check with odcm_can_use() before offering/using the component.
- src/API/RuleBuilderApiController.php
  - validate_rule_entitlements(): enforces entitlement server-side when saving rules. Prevents bypassing UI restrictions via direct REST calls.
  - validate_component_entitlement(): loads the declared component and verifies odcm_can_use($component->get_capability()). Returns localized WP_Error on violations.
  - Defensive block for premium “Any Status Change” trigger (order_status_any_change) tied to capability trigger_premium.
  - filter_schema_by_entitlement() and filter_property_by_entitlement(): strip or annotate JSON Schema properties in REST responses based on entitlements.
- src/Core/OptionRegistry.php and src/Core/FilterRegistry.php
  - Registration payloads include capability keys. Callers/UI are expected to run odcm_can_use() on these keys to show/hide options (documented in their phpdoc).
- Admin UI
  - src/Admin/RuleBuilder.php: uses odcm_can_use() to decide which components and UX affordances to show. Example flags in code: condition_multi_category, premium_features.
  - src/Admin/InsightDashboard.php and src/API/AuditLogEndpoint.php: check capabilities like insight_dashboard and audit_log_filter_advanced to gate premium dashboard features and filters.
- Bootstrap and prompts
  - src/Plugin.php: optionally initializes Includes\UpgradePrompts when present; core keeps this optional and compliant with WP.org guidelines.
  - src/Includes/UpgradePrompts.php and src/Includes/DependencyChecker.php: show educational, non-sales prompts only when Pro is not active and the user can manage settings. DependencyChecker::should_show_upgrade_prompts() and get_wordpress_org_compliant_message() govern behavior.
- Premium Component Fallback
  - src/Plugin.php::initialize_premium_fallback_system(): If Core detects \OrderDaemon\CompletionManager\Core\PremiumComponentFallback, it initializes it. This protects existing rules that reference now-missing premium components (e.g., when Pro is deactivated) by providing safe fallbacks instead of breaking rule evaluation.

Capability model
- Capability IDs are feature-tier keys; not WordPress roles/caps.
- Naming convention: {type}_{feature}[_{modifier}]
  - type: trigger_*, condition_*, action_*, or a top-level feature name (e.g., unlimited_rules, premium_features, insight_dashboard, audit_log_filter_advanced)
- Examples present in code:
  - unlimited_rules — enables more than one active rule (Pro)
  - trigger_premium — enables premium triggers like order_status_any_change (Pro)
  - condition_multi_category — allows selecting multiple categories in a condition (Pro)
  - audit_log_filter_advanced — unlocks advanced Insight/Audit filters (Pro)
  - insight_dashboard — unlocks access to the Insight dashboard (Pro-gated feature as referenced)
- Component-level capability: each component class declares get_capability(); the UI/API must check it with odcm_can_use() prior to rendering or saving.

Enforcement layers
1) Client/UI
- PHP renders component registries with capability metadata; the JS UI uses odcm_can_use() (exposed via localized script state) to decide which controls are enabled/disabled and which show Pro badges/tooltips.
- Upgrade prompts are injected only when DependencyChecker::should_show_upgrade_prompts() is true.

2) Server/API
- RuleBuilderApiController guards persistence:
  - validate_rule_entitlements() assembles a list of violations and returns a 403 WP_Error ('odcm_premium_blocked') with a localized message and violations array.
  - validate_component_entitlement() checks odcm_can_use($component->get_capability()) and validates nested settings.
  - Known premium-only trigger (order_status_any_change) is explicitly blocked without trigger_premium.
- Audit endpoints and dashboards gate advanced filters/operations with odcm_can_use('audit_log_filter_advanced') and similar checks.

3) Fallback/Resilience
- PremiumComponentFallback (when present) ensures configurations referencing missing premium parts do not fatal. This maintains continuity when Pro is removed; rules either degrade gracefully or are skipped with diagnostic logging.

Pro detection and UX
- DependencyChecker::is_pro_plugin_active(): checks plugin activation lists and a compatibility constant to detect Pro.
- When Pro is active:
  - UpgradePrompts are suppressed (DependencyChecker::should_show_upgrade_prompts() returns false).
  - odcm_can_use() should return true for Pro capabilities (implementation detail resides in functions.php and/or Pro filters).
- When Pro is inactive:
  - odcm_can_use() returns false for Pro-only capability keys.
  - UI shows disabled controls with educational, localized tooltips/messages. No direct sales links; wording derives from translation keys in UpgradePrompts/DependencyChecker.

Internationalization (i18n)
- All entitlement and prompt messages are wrapped in translation calls using structured keys. The text domain is order-daemon; loading strategy is documented in A2 and i18n docs.
- REST errors include translator notes for placeholders where applicable.

Development and testing
- Dev flag: define('ODCM_IS_PREMIUM_DEBUG', true) in wp-config.php to simulate Pro during development. Use only in local/test environments.
- Test matrix:
  - Free, Pro inactive: ensure UI shows Pro affordances as disabled; REST save blocks premium; prompts appear where appropriate.
  - Pro active: ensure features unlock; prompts suppressed; REST accept premium rules.
  - Pro activated then removed: ensure PremiumComponentFallback protects existing rules; evaluate upgrade prompt reappearance.

Extension guidance
- Adding a new premium component or feature:
  1. Choose a capability key following conventions.
  2. Return that key from the component’s get_capability() or reference it where the feature is checked.
  3. Ensure odcm_can_use() recognizes the key (Core: default false; Pro: true or via filters).
  4. UI: surface disabled state with proper messaging when false; avoid hiding completely unless confusing to users.
  5. API: add explicit server-side checks if introducing new endpoints or payload fields.

Security notes
- Entitlement is not security by obscurity; server-side checks are mandatory. The Guard security subsystem (initialized in Plugin::initialize_security_system()) can be consulted for authorization decisions orthogonal to entitlements (e.g., user can manage rules), while odcm_can_use() handles licensing/tier gating. Use both where appropriate.

Open TODOs
- Confirm full list of capability keys in Core vs Pro to ensure docs don’t drift from implementation. Keep examples aligned with src/Includes/functions.php and registration payloads.
- Document PremiumComponentFallback behavior in detail once the class is available in this repo (currently class_exists check only).