# Developer Overview

Audience: Developers extending or integrating with Order Daemon (public developer docs)
Last updated: 2025-11-15

This page orients developers to the architecture, main extension points, and compatibility expectations of Order Daemon for WooCommerce. It links to deeper topics you can explore later (components, REST, and webhooks). All details here are aligned with the current Core plugin.

---

## What Order Daemon is (developer view)

Order Daemon is a rules-driven automation layer on top of WooCommerce. It listens to order and external events, evaluates configurable rules (triggers + conditions), and executes actions (e.g., mark order Completed). It records structured timelines so store owners can see what happened and why.

Key properties for developers:
- Hook-safe bootstrap with a defined order to avoid post type race conditions (custom post type `odcm_order_rule` is available early).
- Rule components are regular PHP classes discovered by a registry. Each component declares a capability key used by the plugin’s entitlement system (free vs Pro).
- REST endpoints expose Rule Builder CRUD, the Audit/Insight timeline, and inbound webhooks.
- A Guard-based security layer complements native WordPress permissions and logs security checks.

---

## High-level object model and key classes

- Bootstrap and wiring
  - Plugin (src/Plugin.php): installs/bootstraps, registers CPT, loads options, initializes Core/admin/REST, loads i18n early.
- Rule engine
  - Core (src/Core/Core.php): orchestrates WooCommerce hook listeners and universal event processing.
  - UniversalEvent + EvaluationContext (src/Core/Events/*): normalized event envelope and enriched evaluation context.
  - Evaluator and UniversalEventProcessor (src/Core/*): rule matching, condition evaluation, and action execution.
- Components (extensibility)
  - RuleComponentRegistry (src/Core/RuleComponents/RuleComponentRegistry.php): discovers triggers, conditions, and actions by scanning src/Core/RuleComponents/*.
  - Interfaces (src/Core/RuleComponents/Interfaces/*): contracts you implement to add components:
    - ComponentInterface: get_id, get_label, get_description, get_capability, get_settings_schema
    - TriggerInterface: should_trigger(context, settings)
    - ConditionInterface: evaluate(WC_Order $order, settings)
    - Actions follow ComponentInterface (concrete execute signature documented per action)
- Admin/UI and REST
  - Admin, RuleBuilder, InsightDashboard (src/Admin/*): UI surfaces using REST.
  - REST controllers (src/API/*):
    - RuleBuilderApiController: components discovery, rule get/save, search for pickers
    - AuditLogEndpoint: list/filter timelines, render components, batch ops
    - WebhookController: inbound webhook receiver, health, admin test tools, gateway discovery
- Security and diagnostics
  - GuardChecker (src/Core/Security/GuardChecker.php): central guard execution + audit logging.
  - ProcessLogger and odcm_log_event (src/Core/Logging/*, src/Includes/functions.php): structured audit/timeline entries used by Insight.

See also: internal docs A2 (Bootstrapping), A7 (Logging), A8 (REST) for deeper design notes.

---

## Compatibility and requirements

- PHP: The codebase targets modern PHP (8.x recommended). Follow your site’s supported PHP versions; use strict types and avoid deprecated WP APIs where possible.
- WordPress and WooCommerce: Keep to the latest stable versions for best results. The plugin depends on WooCommerce; if it is inactive the plugin won’t initialize most features.
- Multisite: The plugin stores rules per site. Pro/network-wide specifics may vary; treat rule configurations and logs as site-local unless documented otherwise.
- i18n: All user-facing strings use the `order-daemon` text domain. Script translations are registered for admin bundles.

---

## Free vs Pro for developers

- Entitlements (licensing tiers): Each component declares a capability key via get_capability(). The global helper odcm_can_use($capability) decides whether a feature is allowed.
- Core shows premium components for discoverability but disables them without Pro; the REST API also enforces entitlements on save.
- Pro (separate add-on) unlocks premium capabilities and may add more components, filters, and tools. Your custom components can participate by using clear capability keys and honoring odcm_can_use() in UI and server logic.
- Safety: When Pro is removed but rules reference Pro components, a guarded fallback system may prevent fatal errors.

---

## Where to extend

- Create custom components
  - Add new classes under appropriate namespaces (Triggers, Conditions, Actions) implementing the interfaces above.
  - Provide a stable get_id() and a get_settings_schema() for the Rule Builder UI.
  - Use get_capability() to gate premium/entitled features as needed.
- Respond to events
  - Use WooCommerce hooks or the webhook controller to inject external events. Normalize to a universal event when integrating deeply.
- REST integrations
  - Use the AuditLog and Rule Builder endpoints to build tools or dashboards. Follow permission callbacks and handle i18n’d WP_Error responses.
- Security
  - Prefer the Guard pattern for admin/AJAX actions. Always perform capability/nonce checks on state-changing operations.

---

## Testing and troubleshooting (developer tips)

- Verify CPT registration order if you customize boot hooks; register the rule CPT early (init priority 5) to avoid “invalid post type” admin errors.
- Use the Insight dashboard and AuditLog REST to inspect rule evaluation and webhook timelines; correlate with process_id where available.
- If a REST save returns 403 with odcm_premium_blocked, your payload included a premium-only component without entitlement.
- For i18n, confirm text domain is loaded and JSON script translations are registered for your admin scripts.

---

## Links and next steps

- REST API and integrations (internal reference): see A8 summary in this repository’s docs. A public API reference may be published separately.
- User docs for context:
  - Rules & Automation: /docs/rules-automation/
  - Using the Rule Builder: /docs/using-the-rule-builder/
  - Audit Log: /docs/audit-log/
- Pro overview (high-level): /docs/pro-overview/
