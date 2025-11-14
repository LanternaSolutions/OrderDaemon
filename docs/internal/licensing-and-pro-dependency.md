# Licensing, Pro Dependency, and Upgrade Prompts (A9)

Audience: internal development team (Core + Pro)
Last updated: 2025-11-15
Scope: Code-accurate to Core as of this date; Pro licensing integration notes reference the wpsoftwarelicense.com plugin and outline intended integration points for the Pro addon.

---

## 1) Purpose and scope

This document explains how licensing and the Pro dependency are handled across Core and Pro, how entitlements are enforced in UI and server layers, and how upgrade prompts behave when Pro is not active. It also provides a blueprint for integrating the Pro plugin with the wpsoftwarelicense.com licensing system.

Key Core references:
- src/Includes/functions.php — odcm_can_use(): central entitlement gate (supports debug flag ODCM_IS_PREMIUM_DEBUG)
- src/Includes/DependencyChecker.php — detection of Pro add-on, missing dependencies messaging, and WP.org-compliant prompts
- src/Plugin.php — bootstrap, PremiumComponentFallback initialization (guarded), and security guard initialization
- API/RuleBuilderApiController.php — server-side enforcement of entitlements

Pro-specific licensing will live in the Pro add-on and is described here as a plan/TODO where concrete code is not in this repo.

---

## 2) Core vs Pro responsibilities

Core (this repo):
- Provides entitlement function odcm_can_use() and capability keys used by components and UI.
- Detects whether Pro is active via DependencyChecker::is_pro_plugin_active().
- Shows WordPress.org-compliant educational prompts when users interact with premium UI and Pro is not installed/active (DependencyChecker::should_show_upgrade_prompts(); Includes\UpgradePrompts if present).
- Enforces entitlements server-side in REST (RuleBuilderApiController::validate_rule_entitlements() and ::validate_component_entitlement()).
- Initializes PremiumComponentFallback (if class exists) to protect existing rules when Pro becomes inactive.

Pro (separate plugin):
- Integrates with licensing (wpsoftwarelicense.com plugin) to determine if the site has a valid license and what tier/capabilities are enabled.
- Short-circuits odcm_can_use() to allow premium capabilities when license is valid (see Section 4 for hook surface).
- Suppresses Core upgrade prompts while Pro is active to avoid mixed messaging.
- Performs dependency/version checks (Core version compatibility, PHP, WordPress, WooCommerce) and communicates issues via admin notices and logs.

---

## 3) Entitlement enforcement overview

- UI: Components and fields are gated by capability keys from ComponentInterface::get_capability(). The UI should consult odcm_can_use() to enable/disable or badge premium options. Educational copy originates from DependencyChecker::get_wordpress_org_compliant_message().
- REST: RuleBuilderApiController validates every submitted rule:
  - validate_rule_entitlements() blocks premium triggers/conditions/actions not allowed by odcm_can_use().
  - Explicit defensive block for trigger id 'order_status_any_change' unless odcm_can_use('trigger_premium') returns true.
- Engine execution: Premium components missing at runtime should be guarded or safely skipped; PremiumComponentFallback prevents fatals where available.

---

## 4) How Pro enables premium features

Integration surface (Pro implements one or more of these):
- Filter-based override (preferred): Pro hooks a high-priority filter around odcm_can_use() result (e.g., add_filter('odcm_can_use_capability', ...)) to return true for premium capabilities when the license is valid. If such a filter does not exist yet, Pro can provide a compatibility function that wraps/augments odcm_can_use(), or Pro can preload before Core’s checks and define constants used by odcm_can_use().
- Constant flag for development: Core respects the ODCM_IS_PREMIUM_DEBUG flag; Pro should not rely on it in production.
- Schema filtering: When Pro is active, UI and API schemas that mark premium fields can be exposed as enabled based on odcm_can_use().

Notes:
- Keep licensing separate from authorization. odcm_can_use() is for licensing/entitlements; WordPress capability checks (manage_woocommerce/manage_options) are still required.
- All user-visible messages remain i18n’d in the 'order-daemon' domain and sales-link free in the Core UI.

---

## 5) Pro licensing integration (wpsoftwarelicense.com)

Pro uses the WP Software License Manager (WSL, https://wpsoftwarelicense.com/) on the sales site to manage license keys and activations.

Intended architecture in Pro:
- Storage/settings
  - Admin settings page for entering the license key and viewing status.
  - Options stored securely (use hashed/salted storage for secrets when feasible; avoid logging raw keys).
- Activation/validation
  - On save or “Activate” action, Pro calls WSL endpoints with the license key and site URL to activate or validate.
  - Cache the license state locally (e.g., as a transient or option with timestamp) to avoid frequent remote calls.
  - Provide a background refresh via WP-Cron (e.g., daily) and a manual “Refresh status” button.
- Status model (examples)
  - valid — license is active and permits Pro capabilities
  - expired — display non-blocking admin notice; features may degrade based on policy
  - disabled/revoked — show admin notice and disable premium capabilities
  - unreachable — keep last-known-good for a grace period; log diagnostics
- Error handling and UX
  - All notices must be i18n’d and WordPress.org-compliant in tone (no direct purchase links in Core; Pro UI may link to docs/support pages).
  - Avoid breaking store operations. On validation failures, degrade gracefully by disabling premium-only features and preserving rule integrity (fallback placeholders where appropriate).
- Security & privacy
  - Never send unnecessary PII. Use site URL and hashed identifiers where acceptable by WSL.
  - Avoid storing raw API responses; persist only necessary fields and timestamps.

Dependency on WSL plugin:
- Detect presence/availability of WSL (class/function exists). If missing, degrade to “unlicensed” state and show an admin notice with guidance.
- Encapsulate WSL calls behind an adapter service in Pro, so Core remains agnostic.

TODO (for Pro repo):
- Enumerate exact option names, hook names, and service class names for the WSL adapter.
- Define the caching keys and grace-period policy.
- Define i18n keys for notices and errors.

---

## 6) Pro dependency validator

What to check in Pro during bootstrap:
- Core dependency: Ensure the free Core plugin is active and at a compatible version range; otherwise, show admin notice and self-deactivate to avoid fatal drift.
- Environment: PHP minimum, WordPress minimum, and WooCommerce minimum versions.
- Conflicts: Detect old Pro builds or conflicting add-ons.

Communication channels:
- Admin notices rendered only for capable users (manage_woocommerce/manage_options) and i18n’d via 'order-daemon'.
- Log to audit/diagnostics using odcm_log_event where appropriate (type: 'licensing_dependency_issue').

---

## 7) Upgrade prompts (Core)

- Core may display educational upgrade prompts when users encounter premium UI and Pro is inactive.
- DependencyChecker::should_show_upgrade_prompts() centralizes the conditions (must be admin and user must have capability to manage plugin/rules; suppressed when Pro is active).
- Messaging is composed via DependencyChecker::get_wordpress_org_compliant_message(<context>). Do not include sales links in Core.
- When Pro is active (and/or licensed), these prompts should be suppressed by virtue of Pro detection; Pro can also harden this by removing related hooks if needed.

---

## 8) Testing and diagnostics

Manual testing checklist:
- Core-only: Verify premium components are visible but disabled, with educational tooltips. Attempt to save a rule using a premium trigger and confirm REST returns odcm_premium_blocked with violations.
- Pro active, unlicensed: Confirm odcm_can_use() continues to return false for premium capabilities until license is validated; prompts may remain until licensed.
- Pro active, licensed (WSL valid): Confirm odcm_can_use() returns true for premium capabilities; UI unlocks and REST saves succeed.
- License state changes: Expire/revoke/unreachable — verify admin notices, caching, and that premium features are gated appropriately without fatals; confirm PremiumComponentFallback prevents fatal rule loads.
- Dependency failures: Simulate missing Core or incompatible versions in Pro and ensure clear messaging and safe deactivation.

Diagnostics hooks and logs:
- Use odcm_log_event for licensing/dependency issues and state transitions (e.g., 'license_status_changed').
- Keep PHP error_log minimal; prefer structured logs surfaced in the Insight dashboard.

---

## 9) Open TODOs (Pro repo)

- Implement WSL adapter service with clear contracts (activate, validate, deactivate, get_status, get_capabilities).
- Decide grace period for unreachable licensing server and UI indicators for grace state.
- Provide filters/actions for third-party extensions to react to license state changes.
- Multisite policy: network vs site-level license storage and validation frequency.
- Telemetry/metrics (if any) must be opt-in and documented; none in Core.
