# Admin UI and User Experience (Core + Pro)

Audience: Internal (backend + frontend devs, QA, support)
Last updated: 2025-11-15
Scope: Code-accurate to Core as of this date. Primary references:
- src/Plugin.php (boot + asset i18n handles)
- src/Admin/Admin.php (menus, CPT UI, assets, AJAX)
- src/Admin/RuleBuilder.php (metabox UI, schema→UI mapping, enqueues)
- src/Admin/InsightDashboard.php (dashboard UI, filters, logs, AJAX)
- src/API/RuleBuilderApiController.php (CRUD, validation, i18n’d errors)
- src/Includes/functions.php (odcm_can_use: entitlement checks)
- docs/internal/pro-entitlements-capabilities.md, security-permissions.md, bootstrap-and-lifecycle.md

---

## 1. High-level UI structure

Admin features are split into:
- Rule Builder UI (metabox-driven editor for CPT `odcm_order_rule`).
- Insight Dashboard (analytics/log viewing, filters, details panes).
- Diagnostic Dashboard (environment/debug operations; initialized in Plugin but defined under Admin namespace).
- List Table view for rules with custom columns and AJAX actions.

Boot/integration:
- Plugin::initialize_admin_components() wires Admin, InsightDashboard, DiagnosticDashboard (admin-only, at init priority 15).
- Text domain is loaded early (see A2). Script JSON translations are registered for:
  - order-daemon-admin-js
  - order-daemon-rule-builder
  - order-daemon-insight-dashboard

Entitlement and security:
- Entitlement (licensing/tier) is enforced in UI via odcm_can_use() and server-side via RuleBuilderApiController::validate_rule_entitlements().
- Authorization (WordPress permissions) is enforced via Guard system and WP caps; endpoints/buttons should verify current_user_can('manage_woocommerce') or fall back to manage_options.

---

## 2. Rule Builder UI (CPT editor)

Primary code: src/Admin/RuleBuilder.php

Responsibilities:
- Registers the Rule Builder metabox for post type `odcm_order_rule`.
- Loads the current rule configuration and available components (triggers, conditions, actions) via the registry.
- Renders a dynamic form based on each component’s JSON-like schema.
- Enqueues the dedicated JS/CSS bundle and localizes state for i18n and entitlements.

Key methods and flows:
- init(): hooks metabox registration, save handlers, and enqueue logic.
- add_rule_builder_metabox(): attaches the UI to the rule CPT edit screen.
- enqueue_assets($hook_suffix): enqueues the rule builder assets conditionally for the CPT screen and ensures dependencies (see section 5: Asset loading and i18n).
- load_rule_data(int $rule_id): retrieves saved rule meta (likely under `_odcm_rule_data`) and prepares a normalized structure for the UI.
- load_components_data(array $rule_data) → format_components():
  - Pulls available components from RuleComponentRegistry.
  - Aligns with the current rule config to mark selected/active components.
- prepare_all_fields()/prepare_component_fields():
  - Converts component settings schema into UI fields, handling default values and widgets.
- get_widget_type()/get_default_value():
  - Interprets UI hints from the schema and provides appropriate defaults.
- render_rule_builder(\WP_Post $post):
  - Outputs the metabox HTML scaffold that the JS enhances. All user-facing strings are wrapped for translation using the 'order-daemon' text domain (keys are structured/i18n-aware).

Schema → UI mapping:
- ComponentInterface::get_settings_schema() returns an array resembling JSON Schema, potentially with UI-specific properties (e.g., ui:widget, ui:placeholder).
- RuleBuilder translates this into field definitions (type, title/label, description/help, enum/options, defaults) and passes to the frontend via localized data attributes or script state.
- Capability/entitlement metadata from ComponentInterface::get_capability() is preserved so the UI can disable/hide or badge premium-only controls.

Persistence and validation:
- Client-side submits to REST endpoints handled by API\RuleBuilderApiController.
- Server-side validation ensures entitlement compliance and reports localized errors (see validate_rule_entitlements(), validate_component_entitlement()). This prevents bypass via direct REST calls.

Entitlement UX (Free vs Pro):
- When odcm_can_use() returns false for a component/field capability, the UI:
  - Disables the option or component selection.
  - Shows an educational tooltip or inline note (no sales links; i18n strings).
  - May display a Pro badge for discoverability.
- Known premium trigger guard: The "Any Status Change" trigger (id: order_status_any_change) is blocked server-side without trigger_premium; the UI should reflect this by disabling the option when Pro is inactive.

Autosave and ordering:
- Admin.php provides AJAX endpoints for reordering rules and toggling rule status in the List Table (see below). RuleBuilder save flow integrates with standard WP post updates plus meta storage for rule structure.

---

## 3. Rules List Table and inline actions

Primary code: src/Admin/Admin.php and src/Admin/CompletionRulesListTable.php

Capabilities:
- Custom columns displaying rule status, trigger summary, last modified, etc. (add_custom_columns(), render_custom_columns()).
- AJAX actions:
  - ajax_toggle_rule_status(): enable/disable a rule with capability checks and nonces.
  - ajax_update_rule_order(): persist new priorities after drag/drop or position controls.
- Admin bar and navigation affordances via add_order_rule_to_admin_bar().

Security and UX:
- AJAX endpoints should be guarded by Capability/Nonce guards (see A5). On failure, return localized error messages.
- Inline toasts/notices use i18n keys; avoid hardcoded English strings.

---

## 4. Insight Dashboard (analytics/logs)

Primary code: src/Admin/InsightDashboard.php

Menu and routing:
- init(): registers menu and hooks; applies debug overrides in dev contexts.
- register_menu_page(): registers an admin page with translated menu/page titles using 'order-daemon' text domain.
- remove_duplicate_submenu(): cleans up duplicate menu entries introduced by WP menu APIs.

Assets and framing:
- enqueue_assets($hook_suffix): loads the insight dashboard bundle (handle: order-daemon-insight-dashboard) only on the dashboard page; injects localized state (filters, i18n, capability flags).
- enqueue_custom_menu_icon(): adds a custom icon via inline SVG/CSS for improved UX.

UI composition:
- render_unified_header(): renders a header with tabs/actions.
- render_filter_pane() + render_filters_tab_content(): render filter controls (date range, status, components), mapping to server-understood filter keys.
- render_settings_tab_content(): surfaces preferences like per-page and debug toggles.
- render_log_stream(): lists recent log entries with lazy loading or pagination; ties to REST endpoints (Audit/Timeline APIs).
- render_detail_pane(): shows details for the selected log item.

Behavior and AJAX:
- handle_update_per_page_ajax(): persists user preference for list size.
- handle_debug_settings_ajax() / update_global_debug_mode(): toggles diagnostic verbosity globally in a guarded way; logs changes (log_debug_mode_change()).
- handle_reprocess_pending_orders_ajax(): triggers reprocessing flows for pending items (guarded; logs using log_reprocess_action_ajax()).

Entitlement and permissions:
- Some advanced filters/features are gated by capability keys like insight_dashboard and audit_log_filter_advanced. The UI should read localized flags (from PHP) to disable advanced controls when odcm_can_use() is false and show educational messages from DependencyChecker when appropriate.

Performance:
- get_user_per_page_setting(): stores per-user pagination to avoid expensive queries in the default view.

---

## 5. Asset loading and i18n

Script handles (registered for JSON translations in Plugin::load_text_domain()):
- order-daemon-admin-js — generic admin helpers.
- order-daemon-rule-builder — Rule Builder UI.
- order-daemon-insight-dashboard — Insight Dashboard UI.

Guidelines:
- Only enqueue bundles on relevant screens (check $hook_suffix using helper predicates like is_dashboard_page() or CPT screen checks in RuleBuilder/Admin).
- Always call wp_set_script_translations(handle, 'order-daemon') for bundles so that JSON translation files are loaded.
- Localize script data:
  - REST base URLs and nonces
  - Capability flags derived from odcm_can_use()
  - Current user permissions (manage_woocommerce/manage_options) for UI gating
  - Any feature flags/debug overrides applied by InsightDashboard::apply_debug_override()

Styles:
- Ensure CSS is scoped to plugin pages to avoid admin global bleed. Admin.php includes enqueue_frontend_styles() for minimal frontend footprints when necessary.

---

## 6. REST/API touchpoints used by UI

- RuleBuilderApiController: CRUD and validation for rules; returns localized WP_Error for entitlement/security violations. The UI should surface these messages verbatim.
- AuditLogEndpoint and API\Timeline: data sources for Insight Dashboard lists and details. Filter keys used in the UI must map exactly to server parameters.
- WebhookController: not directly used by admin UI, but visible in diagnostics and test panels when present; do not expose secrets in UI.

Security:
- All state-changing requests must include nonces and be guarded by NonceGuard and/or CapabilityGuard (see A5). Treat any __return_true test callbacks as dev-only.

---

## 7. UX guidelines (internal)

- Discoverability vs clutter: Show premium items disabled with clear labels instead of hiding them entirely; use short educational tooltips from DependencyChecker::get_wordpress_org_compliant_message('rule_builder'|'insight_filters').
- Consistent wording: Use translation keys and centralize copy; avoid hardcoded English in PHP/JS.
- Error presentation: Surface REST error strings as returned; they are localized and contain translator notes for placeholders.
- Responsiveness: List tables and dashboards should respect user-per-page and lazy load where possible.
- Accessibility: Ensure focus order is logical; button labels are programmatically associated; avoid color-only state cues.

---

## 8. Pro-specific admin extensions (overview)

- Premium components: Pro registers additional triggers/conditions/actions. RuleBuilder will automatically render them; capability checks unlock interactivity.
- Premium dashboards: Pro may add filters or export actions. Gate with odcm_can_use() and standard guards.
- Upgrade prompts: When Pro inactive, core may initialize Includes\UpgradePrompts; messages must remain educational and link-light (WP.org compliant). See DependencyChecker::should_show_upgrade_prompts().

---

## 9. Troubleshooting

- Missing translations in UI:
  - Confirm Plugin::ensure_i18n()/load_text_domain() ran; check script translation calls for the specific handle.
- Rule Builder shows unknown component:
  - Verify component exists in RuleComponentRegistry and implements get_id(); ensure IDs match saved rule data.
- REST save blocked (403 odcm_premium_blocked):
  - User attempted to save Pro-only items; confirm odcm_can_use() returns true (Pro active) or remove premium parts from the rule.
- Insight Dashboard empty or errors:
  - Check permission_callback for endpoints; ensure current user has manage_woocommerce or manage_options.
  - Verify filter keys sent by UI match server expectations.
- AJAX actions fail in list table:
  - Confirm nonce fields and guard checks; inspect security logs via GuardChecker event logging.

---

## 10. Open TODOs

- Document exact REST route paths and schemas for RuleBuilderApiController and AuditLogEndpoint in a future API-focused section (see A8). Keep this section UI-centric.
- Confirm and enumerate all UI-specific schema hints supported by RuleBuilder (ui:widget variants, placeholders, conditional fields) and reflect in frontend documentation.
- Audit NonceGuard coverage for all AJAX endpoints in Admin.php and InsightDashboard.php.
- Multisite admin UX: confirm behavior of menu pages and per-site settings.
