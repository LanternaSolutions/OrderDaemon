You are a coding/documentation agent working on the Order Daemon documentation set.  
The goal of this document is to produce:

1. **Internal documentation** for the Order Daemon development team (core + pro).
2. **Public documentation** for store owners/managers and developers (website docs, developer guide, etc.).

The outline that follows is the **single source of truth** for the structure of the documentation.  
Your job is to:

- **Respect the structure:**  
  - Keep the separation between **A. Internal Documentation** and **B. Public Documentation**.  
  - When you flesh out a section, write it under the correct heading and in the correct voice/audience.
- **Be code-accurate and up to date:**  
  - Before writing or changing any section, you **must review all relevant code and assets** for that section (PHP, JS, REST endpoints, schemas, i18n wrappers, admin/UI code, etc.).  
  - Do not rely solely on this outline or on memory. Re-check the implementation so the docs never describe behavior that no longer exists.
  - When behavior is conditional (e.g., free vs pro, capability-dependent UI, upgrade prompts, diagnostics), document those conditions explicitly.
- **Avoid speculation:**  
  - Do not invent features, APIs, or behaviors.  
  - If something is unclear from the code, mark it clearly in the draft as “TODO – to confirm from implementation” rather than guessing.
- **Audience-appropriate language:**
  - For **internal sections (A.\*)**, it is acceptable (and expected) to use implementation details, class names, hooks, and architectural notes.  
  - For **user-facing sections (B1–B9)**, use plain, non-technical language and explain concepts in terms of store operations and outcomes.  
  - For **developer-facing sections (B10.\*)**, be explicit about APIs, extension points, contracts, and error handling.
- **Document i18n and entitlement accurately:**
  - When documenting UI labels or messages, reflect that they are translation keys resolved by the plugin; don’t hardcode English wording that contradicts the keys.
  - When a feature is free vs pro or entitlement-based (capabilities, premium components, upgrade prompts), explain how that is enforced both in UI and server-side.
- **Keep this file iterative and self-contained:**
  - When you complete or substantially draft a subsection, remove any “TODO” markers for it (if present) and keep any remaining TODOs clearly marked.  
  - If you need to add new subsections or reorder items, update the outline in this file so that future agents can follow the new structure.
  - If you mention that code or behavior has changed, briefly note the version or context if it is user-facing or important for internal maintenance.

When you start a new editing session with this file:

1. Read this prompt and the existing outline/sections to understand current coverage.
2. Identify the **next logical section or subsection** to work on (or the section the human author asks you to focus on).
3. Locate all **relevant code and assets** for that section in the project and review them carefully before writing.
4. Write or refine the documentation for that section, keeping to the audience and style requirements above.
5. If you discover discrepancies between the outline and the actual implementation, adjust the outline and/or add short notes so the next agent doesn’t repeat the same investigation.

Do not remove this prompt. Update the rest of the document beneath it.

---

# Order Daemon Documentation Plan

Below is a structured outline of the concepts and topics that the documentation should cover, split into:

- **A. Internal documentation** (Order Daemon development team)
- **B. Public documentation** (store owners/managers + extension developers)
- For public docs, this outline also notes **proposed URLs** and the **core vs Pro** split (hybrid model).

---

## A. Internal Documentation – Order Daemon (Core + Pro)

Internal docs live under `docs/internal/` and can be organized by audience (backend devs, frontend, support).

### A1. High-Level Architecture

- Overall purpose of Order Daemon
  - What problem it solves (order automation, completion rules, auditability, etc.)
  - Positioning of free vs pro plugin
- System boundaries
  - Dependencies: WordPress, WooCommerce, WP Cron, WP-CLI, external webhooks, etc.
  - Integration points with other WooCommerce extensions (subscriptions, bookings, memberships, bundles, etc.)
- Component overview
  - Rule engine (triggers, conditions, actions)
  - Admin UI (Rule Builder, dashboards)
  - Audit log and diagnostics
  - REST API layer
  - Security / guard system
  - Licensing / entitlement / premium fallback
  - Pro extension architecture (extending free plugin vs duplicating features)

### A2. Plugin Bootstrapping and Lifecycle

This section documents the concrete boot sequence implemented in the core plugin at src/Plugin.php. It reflects the exact hooks, priorities, and safeguards observed in code as of 2025-11-14.

- Free core plugin bootstrapping sequence
  - Installer / upgrade routines and versioning
    - On every load, Installer::install() is called from Plugin::bootstrap() (no-op if up-to-date; version-guarded and idempotent).
  - WooCommerce dependency checks and admin notices
    - Before initializing components, Plugin::bootstrap() checks class_exists('WooCommerce').
    - If missing, it hooks an admin_notices callback that outputs esc_html__('core.plugin.dependency.woocommerce_required', 'order-daemon') and returns early. No other components are initialized in this state.
  - i18n/text-domain loading strategy and timing
    - Plugin::ensure_i18n() runs early in bootstrap. If the 'order-daemon' textdomain is not already loaded, it either:
      - Calls load_text_domain() immediately when already in/past 'init', or
      - Adds an 'init' action at priority 0 to call load_text_domain().
    - Plugin::load_text_domain():
      - Attempts load_plugin_textdomain('order-daemon', false, <plugin-dir>/languages).
      - Fallback: If is_textdomain_loaded() is still false, it determines the locale and attempts load_textdomain() with an absolute .mo path at ODCM_PLUGIN_DIR . 'languages/order-daemon-<locale>.mo'.
      - Registers JSON script translations via wp_set_script_translations for:
        - order-daemon-admin-js
        - order-daemon-rule-builder
        - order-daemon-insight-dashboard
  - Hook-based initialization sequence (race-condition hardened)
    - The plugin establishes a strict order on the 'init' hook to avoid "invalid post type" errors when admin handlers run:
      - Priority 5: Plugin::register_post_type()
        - Delegates to Admin::register_completion_rule_post_type() to register the odcm_order_rule post type.
        - Must be registered in all contexts (admin, CLI, frontend, cron/background) — explicitly noted in docblocks.
      - Priority 6: Plugin::load_options()
        - Requires registrations: src/Core/options.php (triggers/conditions/actions), src/Core/audit-filters.php, and src/Core/PayloadComponentRegistry.php.
      - Priority 10: Plugin::initialize_core()
        - Instantiates Core and calls Core::init().
        - Initializes ManualStatusTracker::init() for chain-of-custody logging.
        - Initializes Premium Component Fallback via Plugin::initialize_premium_fallback_system() if the class exists; failures are logged and non-fatal.
        - Initializes the Guard-based security system via Plugin::initialize_security_system():
          - If Core\Security\GuardChecker exists, instantiate and store instance in $GLOBALS['odcm_guard_checker'].
      - Priority 15: Plugin::initialize_admin_components() (admin-only)
        - In admin context (is_admin()):
          - Admin::init()
          - InsightDashboard::init()
          - DiagnosticDashboard::init()
          - Optionally Includes\UpgradePrompts::init() if class exists (WordPress.org-compliant upgrade messaging).
    - REST API initialization:
      - On 'rest_api_init' (priority 10): Plugin::initialize_api_endpoints() registers routes for:
        - API\AuditLogEndpoint
        - API\RuleBuilderApiController
        - API\WebhookController (universal event ingress)

- Pro plugin bootstrapping sequence
  - TODO – to confirm from implementation: The Pro plugin code is not present in this repository snapshot. The following are expected but must be verified in Pro:
    - Dependency on the free plugin and detection strategy.
    - Pro hooks that enable premium capabilities (UI unlocks, server-side enforcement) without duplicating core logic.
    - Licensing system wiring and caching, network interactions, and failure modes.
    - CLI command registrar for Pro-only automation.
    - Pro dependency validator (core version, PHP, WooCommerce) and admin notices.
    - Admin extensions (webhooks UI if Pro-only, log exporter, Rule Builder enhancements).

- Edge cases
  - WooCommerce inactive
    - Core displays an admin notice and aborts bootstrap early; CPT, options, core, admin, and API are not initialized.
  - Pro active but free missing
    - TODO – to confirm from implementation in the Pro plugin (not visible here) how detection and messaging are handled.
  - Multi-site activation considerations
    - TODO – not present in current code; investigate Installer behavior and any network-wide hooks if multisite support is required.

### A3. Rule System Architecture

- Conceptual model
  - Rules, triggers, conditions, actions
  - Rule evaluation flow (e.g. when an order event occurs)
  - Relationship between rules and WooCommerce orders
- Rule storage & custom post type
  - `odcm_order_rule` post type: structure and purpose
  - Custom meta fields / configuration schema storage
  - Post type registration: priorities, contexts, and race-condition mitigation
- Rule components
  - Triggers (e.g. order status changes)
    - Interface responsibilities
    - Registration model (how they’re discovered and registered)
    - How priorities affect UI ordering
  - Conditions (e.g. product category, product type, order total)
    - Interface responsibilities
    - Evaluation patterns and access to `WC_Order`
    - Settings schemas for the Rule Builder UI
      - JSON-schema style definitions
      - UI-specific metadata (`ui:*` properties, widgets, placeholders, premium flags, etc.)
  - Actions (e.g. change order status to completed)
    - Interface responsibilities
    - Execution semantics (idempotency, error handling, order notes)
    - Default/free actions vs premium actions (capability levels, priority)

### A4. Entitlement / Capability / Premium System

Status: Drafted in docs/internal/pro-entitlements-capabilities.md (2025-11-15)

- Capability model
  - What “capability” means within the plugin (not WP user caps, but feature-tier keys, e.g. `trigger_basic`, `condition_order_total`, etc.)
  - Naming conventions and examples used in code (unlimited_rules, trigger_premium, condition_multi_category, audit_log_filter_advanced)
  - Mapping between capabilities and product tiers (free, pro)
- Premium component handling (UI)
  - How free users see premium components in UI (badges, disabled options, tooltips with educational, i18n strings)
  - Premium items remain visible for discoverability (no sales links)
  - Pro plugin’s way of enabling premium access:
    - odcm_can_use() short-circuit via Pro and debug flag
    - How Pro suppresses upgrade prompts and unlocks disabled options
- Server-side enforcement
  - REST save validation in RuleBuilderApiController (validate_rule_entitlements, validate_component_entitlement)
  - Schema/property filtering by entitlement in REST responses
  - Defensive blocks for known premium triggers (e.g., order_status_any_change)
- Premium component fallback system (core)
  - Purpose (handle rules referencing premium components when Pro is disabled)
  - Initialization via Plugin::initialize_premium_fallback_system(), error resilience, and behavior
- Pro detection and upgrade prompts (core)
  - DependencyChecker::is_pro_plugin_active(), should_show_upgrade_prompts()
  - UpgradePrompts educational messaging
- Security & i18n notes
  - Use Guard for authZ and odcm_can_use() for licensing; both must pass
  - All messages/labels use the 'order-daemon' text domain and structured keys

### A5. Security and Permissions

Status: Drafted in docs/internal/security-permissions.md (2025-11-15)

- Guard-based security system
  - Guard checker service (centralized permission checks); initialized in Plugin::initialize_security_system(); global accessor via Plugin::get_guard_checker() ✓
  - How other components are expected to use the guard checker (NonceGuard, CapabilityGuard, CompositeGuard patterns) ✓
  - Compatibility with WordPress capabilities; CapabilityGuard should reflect manage_woocommerce/manage_options checks ✓
- Admin access controls
  - Who can manage rules, dashboards, and audit logs; prefer manage_woocommerce, allow manage_options fallback ✓
  - Protection around REST API endpoints and nonce usage; permission_callback on all admin routes; NonceGuard for state-changing AJAX ✓
- Data integrity and validation
  - Server-side validation of rule configurations; entitlement checks in RuleBuilderApiController::validate_rule_entitlements/validate_component_entitlement ✓
  - Preventing bypass of premium-only features via direct POST / REST, including explicit block on 'order_status_any_change' without trigger_premium ✓
- REST endpoints nuances
  - WebhookController has public routes by design; ensure shared-secret/signature strategy; test endpoints guarded by test_permissions_check ✓
- Open TODOs
  - Review debug/public routes using __return_true; gate or disable in production contexts ✓
  - Multisite capability expectations and potential dedicated capability (e.g., 'odcm_manage_rules') to be evaluated ✓

### A6. Admin UI and User Experience

Status: Drafted in docs/internal/admin-ui-ux.md (2025-11-15)

- Rule Builder UI
  - Architecture: Metabox-based editor backed by PHP schemas; assets enqueued conditionally; i18n via 'order-daemon' JSON translations ✓
  - Configuration schema interpretation: ComponentInterface::get_settings_schema() → RuleBuilder prepares fields (type/defaults/ui hints) → JS renders dynamic form ✓
  - Free vs Pro UI capabilities:
    - Premium flags, disabled controls, educational prompts using DependencyChecker messaging ✓
    - Server-side blocking of known premium trigger order_status_any_change without trigger_premium ✓
- Dashboards
  - Insight dashboard: menu/page registration, assets (order-daemon-insight-dashboard), filters/settings tabs, log stream and detail pane using Audit/Timeline APIs ✓
  - Diagnostic dashboard: initialized from Plugin; provides environment/debug operations (see Admin\DiagnosticDashboard) ✓
- Audit log interface
  - What is surfaced: timeline/log entries with components and statuses; filters map to API parameters; pagination/per-page persisted ✓
  - Permission and entitlement gating for advanced filters (audit_log_filter_advanced) ✓
- Pro admin extensions
  - Premium components unlock automatically when odcm_can_use() permits; upgrade prompts suppressed when Pro active ✓
  - TODO: Document any Pro-only export or webhook configuration UIs in the Pro repo

### A7. Logging, Diagnostics, and Observability

Status: Drafted in docs/internal/logging-diagnostics.md (2025-11-15)

- Logging strategy
  - Two-layer approach: transient PHP error_log traces for development vs structured audit logs via odcm_log_event for dashboards ✓
  - i18n diagnostics in Plugin::load_text_domain() and limited ODCM_DEBUG_TRACE in UniversalEventProcessor ✓
  - Major subsystems that log: rule evaluation (ProcessLogger/Evaluator/UniversalEventProcessor), security guard checks, diagnostics routines, licensing/upgrade prompts where applicable ✓
- ProcessLogger timelines
  - Core\Logging\ProcessLogger start/add_component/finish lifecycle; recursion/universal-event context guards; canonical-event wiring ✓
  - API\Timeline\ProcessLoggerComponentExtractor renders ProcessLogger payloads for the Insight timeline ✓
- Audit logs and access
  - odcm_log_event() contract, event_type taxonomy helpers, and correlation with object IDs ✓
  - API\AuditLogEndpoint routes for listing/filtering/detail; legacy vs ProcessLogger-aware extraction ✓
- Manual status tracking
  - ManualStatusTracker::init() provides chain-of-custody context for distinguishing manual vs automated changes ✓
- Diagnostics dashboard
  - Admin\DiagnosticDashboard initialization and scope; environment/i18n/dependency checks; guard/capability expectations ✓
  - Guidance for remediation steps and safe operation (nonce + capability guards) ✓
- Performance and maintenance
  - Pagination and per-page limits; avoid overly verbose payloads; LogCleanup usage where present ✓
- Troubleshooting
  - Missing translations, missing timeline entries (canonical path and universal-event context), excessive logs, entitlement blocks — with suggested checks ✓
- Open TODOs
  - Storage backend and retention policy documentation; event_type taxonomy expansion; correlation IDs; gate public/debug diagnostic routes ✓

### A8. REST API and External Integrations

Status: Drafted in docs/internal/rest-api-and-integrations.md (2025-11-15)

- REST API endpoints
  - Audit/Insight endpoints (API/AuditLogEndpoint): list/filter logs; render components (single/batch); filter-options; batch-delete; fetch by process; debug diagnostic (ODCM_DEBUG only). Permission callbacks enforce admin caps; diagnostic route is public only under debug builds. ✓
  - Rule Builder API (API/RuleBuilderApiController): components discovery; get/save rule by id; dynamic search for UI pickers. Includes entitlement enforcement (validate_rule_entitlements, validate_component_entitlement), schema filtering, and server-side sanitization. ✓
  - Webhook Controller (API/WebhookController): generic inbound POST /webhooks/{gateway}; public health; admin-only test tools and gateway discovery. Processing is delegated to Core\\Events\\EventRouter; responses return 200 to discourage retries. ✓
- Webhook system
  - Inbound webhooks: body extraction from JSON/form; gateway param sanitized/validated; adapter-level auth expected (shared secret/HMAC). Log helpers emit reception/success/error with process_id for correlation. Rate limiting/replay protection recommended at edge and adapter layer. ✓
  - Outbound webhooks (if applicable): Not present in Core; expected to live in Pro. Document event types, signing, retries/backoff, and dead-letter queues when implemented. TODO
- CLI commands (Pro)
  - No CLI in Core. If Pro registers WP-CLI commands (bulk reprocessing, diagnostics), document namespace, args, permissions, and guards. TODO

### A9. Licensing, Pro Dependency, and Upgrade Prompts

Status: Drafted in docs/internal/licensing-and-pro-dependency.md (2025-11-15)

- Licensing system (Pro)
  - Uses wpsoftwarelicense.com (WSL) on the sales site; Pro integrates via an adapter to activate/validate licenses. ✓
  - License checks and local caching (options/transients) with background refresh and manual "Refresh status"; graceful degradation and i18n notices. ✓
  - Failure modes: valid/expired/revoked/unreachable with grace-period policy and audit logging (license_status_changed). TODO (Pro specifics)
- Pro dependency validator
  - Checks Core compatibility, PHP/WordPress/WooCommerce minimums, and conflicts; shows admin notices and structured logs (licensing_dependency_issue). ✓
- Upgrade prompts (Core)
  - Core shows educational prompts when Pro inactive using DependencyChecker; messages are WP.org‑compliant and i18n. ✓
  - Pro suppresses prompts when active/licensed by short‑circuiting odcm_can_use() and/or removing prompt hooks. ✓

### A10. Internationalization (i18n) & Localization

- Text domain loading strategy
  - When and how the text domain is loaded
  - Handling of .mo files and fallback loading by absolute path
- Translation key conventions (string IDs vs plain English)
- Script translations
  - Which scripts get translations and how they are registered
- Translation workflow
  - How new strings are added
  - How translators should work with the plugin (PO/MO, GlotPress, etc.)
- Internal reference: docs/internal/i18n.md — Draft complete (2025-11-14)

### A11. Development Practices and Tooling

Status: Drafted in docs/internal/development-practices-and-tooling.md (2025-11-15)

- Project structure in the repo ✓
  - Breakdown of `src` sub-namespaces (Core, Admin, Includes, etc.) ✓
  - Placement of Pro vs core files ✓
- Coding standards ✓
  - Use of PHPCS and WordPress Coding Standards — recommended; PHPCS config TODO ✓
  - Naming and file organization conventions ✓
- Testing strategy ✓
  - PHPUnit configuration (phpunit.xml.dist); how to run ✓
  - Types of tests (unit, integration; current coverage + TODOs) ✓
- Dev tooling ✓
  - Local environment setup (WP+WC required) ✓
  - Script commands (Composer only in this repo; no npm) ✓
  - Debug workflows (i18n diagnostics, Guard logs, REST/webhook notes) ✓
- Release process ✓
  - Versioning and changelog conventions ✓
  - Build and packaging steps for WordPress.org and Pro distributions ✓
  - Backward compatibility guidelines ✓
- Open TODOs
  - Add PHPCS/WPCS config and CI
  - Document/introduce frontend asset build pipeline if needed
  - Provide WP integration test bootstrap and fixtures

---

## B. Public Documentation – Website (Users + Developers)

Public docs live under `docs/public/` and are published at `/docs/...`. We use a **hybrid model**:

- **Shared pages** where both core and Pro behavior are described with clear Pro callouts.
- A **small Pro section** (`/docs/pro/...`) for Pro overview and features that are overwhelmingly Pro-specific.

### B1. Overview & Key Concepts (User-Facing)

Status: Drafted in docs/public/index.md (2025-11-15)

**URL(s):**

- `/docs/` – Landing / overview
- Possibly `/docs/key-concepts/` if the landing page needs to stay very short

**Content:**

- What Order Daemon is
  - High-level description: automated order completion / workflow engine for WooCommerce
  - Typical use cases (digital products, complex fulfillment, hybrid stores)
- Free vs Pro overview
  - Feature comparison summary table
  - Who should use which version (small store vs complex store)
- “How it works” in plain language
  - Rules: “If this happens, and conditions are met, then do that”
  - Examples:
    - Auto-complete digital orders
    - Complete orders above a certain total
    - Treat specific categories differently
- Links to:
  - Getting Started
  - Rules & Automation
  - Pro Overview

### B2. Installation & Setup (Core & Pro)

Status: Drafted in docs/public/getting-started.md (2025-11-15)

**URL:**

- `/docs/getting-started/`

**Content covered:**

- Requirements (PHP, WordPress, WooCommerce; translations note)
- Installing the free plugin from the dashboard (search → install → activate)
- Installing the Pro add‑on (upload ZIP → activate); notes about Core dependency and licensing
- First‑time setup checklist (Woo active, menus visible, rule editor loads, Insight dashboard accessible, translations)
- Troubleshooting (missing menus/caps, Woo inactive, Pro not unlocking, translations not loading, rules not running)
- Links to next steps: Using the Rule Builder, Rules & Automation, Audit Log, Pro Overview

### B3. Conceptual User Guide: Rules, Triggers, Conditions, Actions

Status: Drafted in docs/public/rules-automation.md (2025-11-15)

**URL:**

- `/docs/rules-automation/`

**Content covered:**

- Plain-language explanation of Rules, Triggers, Conditions, Actions and where to manage them (Orders → Completion Rules)
- How rules are evaluated in simple terms and where to see results (Insight dashboard timeline)
- Core examples:
  - Auto-complete digital orders (payment completed + digital-only conditions → Completed)
  - High-value orders (processing + total threshold → Completed)
  - Category-specific handling (selected categories → Completed)
- Conditions users can configure in Core: product categories, product types, order total thresholds (combined with logical AND)
- Actions in Core: change order status to Completed; secondary actions noted where applicable
- Free vs Pro callout: premium items shown with badges and disabled until Pro is active; discoverability without sales links
- Tips and troubleshooting basics; links to Using the Rule Builder, Audit Log, and Getting Started

### B4. Using the Rule Builder UI

Status: Drafted in docs/public/using-the-rule-builder.md (2025-11-15)

**URL:**

- `/docs/using-the-rule-builder/`

**Content covered:**

- Navigating to the Rule Builder
  - Menu paths in WordPress admin
- Creating a new rule (step-by-step)
  - Choose a trigger
  - Add conditions
  - Choose action(s)
  - Save and activate
- Understanding the configuration fields
  - Category dropdowns, product type multi-select, order total operator/amount inputs
- Pro-specific notes (inline callouts)
  - Premium components shown but disabled in core; unlock with Pro
  - Upgrade badges/prompts vs unlocked behavior in Pro
- Testing a rule
  - Place a test order and verify
  - Check the audit log (Insight dashboard) to see whether the rule fired
- Managing existing rules
  - Editing, duplicating, deleting rules
  - Best practices (naming conventions, grouping strategies)

### B5. Audit Log (User-Level) – Separate page

Status: Drafted in docs/public/audit-log.md (2025-11-15)

**URL:**

- `/docs/audit-log/`

**Content covered:**

- What the audit log is and where to find it in the admin (Orders → Insight)
- How to read entries (status/result, event type, source, order/ID, time) with examples:
  - Successful rule run (conditions passed, action executed)
  - Rule didn’t run (which condition failed and why)
  - Manual actions (clearly labeled)
  - Webhook received (gateway/source, parsed data summary)
- Filters in Core (free): basic search across entries
- Filters in Pro (clearly marked): date range, status, event type, source; badges shown in Core and unlocked in Pro; server-side enforcement prevents URL param bypass
- Troubleshooting with the audit log: find why a rule didn’t run; confirm automation ran as expected; tips for clean testing
- Links to:
  - `/docs/pro/overview/` for Pro features (advanced filters/webhooks)
  - Developer docs for audit log/Timeline APIs (see internal A7/A8)

### B6. Diagnostics Dashboard (User-Level) – Separate page

Status: Drafted in docs/public/diagnostics-dashboard.md (2025-11-15)

**URL:**

- `/docs/diagnostics-dashboard/`

**Content covered:**

- What the diagnostics dashboard is
  - Purpose: technical health check (environment, compatibility, configuration)
  - Where it appears in the admin menu
- Running diagnostics
  - Run all diagnostics
  - Run only critical tests
  - Run specific categories/tests
- Understanding the results
  - Overall status (healthy/warning/critical)
  - Issues vs critical issues
  - Category breakdown (core, API, performance, frontend)
- Acting on recommendations
  - Example recommendations and what to do
  - When to contact support and what info to include (screenshots, copied results)
- Link to:
  - `/docs/troubleshooting/`
  - Developer diagnostics (if any internal tooling is exposed publicly in future)

### B7. Webhooks & External Integrations (User-Level)

Status: Drafted in docs/public/webhooks-and-integrations.md (2025-11-15)

**URL:**

- `/docs/webhooks-and-integrations/` (public, with Pro callouts where applicable)

**Content covered:**

- What webhooks are (simple explanation in plain language)
- Typical use cases (payments, automation tools, internal systems)
- How to configure:
  - Where to configure (in the external service)
  - Webhook URL pattern and health check URL
  - Admin test tools (admin-only) for verification
- Understanding webhook-related logs
  - Use the Audit Log (Insight dashboard) to see arrivals and rule outcomes
  - How to verify and common “no entry” causes
- Security tips (non-technical): shared secrets/signatures, keep URLs private, retries
- Troubleshooting: failed webhooks, rules not running, translations
- Pro-specific callouts
  - Outbound/advanced integrations may require Pro; link to Pro overview

### B8. Pro Features Deep Dive (User-Level, Pro section)

Status: Drafted in docs/public/pro/overview.md (2025-11-15)

**URLs (Pro section hub + feature-focused pages):**

- `/docs/pro/overview/` (hub)
- Optionally:
  - `/docs/pro/advanced-log-filters/` (if audit-related features are deep/complex)
  - `/docs/pro/webhooks-and-integrations/`
  - `/docs/pro/log-exporter/` (if warranted)

**/docs/pro/overview/**

Content covered (overview page):
- What Pro adds over Core (high-level highlights; no tech details)
- When to choose Pro (scenarios)
- Licensing & activation (where to enter key, what happens if inactive/expired)
- How to verify Pro is working (short checklist)
- Links to shared docs (Getting Started, Rule Builder, Audit Log)
- Notes: translations, privacy, no sales links on this page

TODOs (future Pro pages):
- Advanced audit log filters → `/docs/audit-log/` (section) or separate Pro page
- Webhooks and integrations (Pro-specific) → `/docs/pro/webhooks-and-integrations/`
- Log export → `/docs/pro/log-exporter/` (if warranted)
- Any additional Pro-only triggers/conditions/actions

**/docs/pro/log-exporter/** (if separate)

- Where to access the log exporter
- Export formats and configuration options
- Example use cases (sharing with accounting, dev teams, auditors)

### B9. Security, Privacy & Compliance (User-Level)

Status: Drafted in docs/public/security-and-privacy.md (2025-11-15)

**URL:**

- `/docs/security-and-privacy/`

**Content covered:**

- What data the plugin stores
  - Rules configuration, audit/timeline entries, and high-level diagnostics outcomes (admin-only)
- Where data is stored
  - WordPress CPT + post meta for rules; audit entries in the site database; options/user preferences for settings
- GDPR & privacy considerations
  - Focus on operational data; avoid unnecessary personal data; exports/erasure rely on standard WP/WC tools; retention guidance and batch delete tools
- Access control
  - Recommended roles (Shop Manager/Administrator); REST/API considerations; webhook endpoints security notes (use secrets/signatures, keep URLs private)
- Differences in Pro
  - Additional filters/options follow the same privacy principles; licensing checks do not send order data externally
- Good practices and troubleshooting
  - Keep software updated, limit admin access, treat webhook URLs as secrets, translations note; housekeeping (deleting old logs)

### B10. Troubleshooting & FAQ

Status: Drafted in docs/public/troubleshooting.md (2025-11-15)

**URL:**

- `/docs/troubleshooting/`

**Content covered:**

- Quick checks: WooCommerce active, correct user role, rule enabled, test with a new order
- Common installation issues
  - WooCommerce missing or outdated
  - Pro active, Core missing
  - Menus not visible due to permissions
- Rule not firing
  - Checklist (trigger happened, conditions match, rule enabled, new test order)
  - How to use the Audit Log to investigate with examples
- Orders not completing as expected
  - Interaction with gateways, other plugins, and conflicting rules
- Translation and language issues
  - Site Language settings, staying updated, JSON script translations
- Performance & scaling
  - Large number of rules, checkout impact, cron/background processing
- Diagnostics Dashboard
  - When to use, where to find, link to guide
- Pro features disabled in Core
  - Expected behavior, how Pro unlocks features, link to Pro overview
- FAQ section
  - Multiple rules, past orders, checkout speed, where logs live
- Getting support
  - What to include (diagnostics summary, order numbers/times, versions, relevant log entries) and helpful links

---

## B11. Developer Guide (Public – Extensibility & Integration)

Developer docs live under `docs/public/developers/` and on the site under `/docs/developers/...`.

### B11.1. Developer Overview

**URL:**

- `/docs/developers/overview/`

**Content:**

- Architectural summary for developers
  - Rule engine concepts and lifecycle in technical terms
  - High-level object model and key classes (without diving into internal-only details)
- Compatibility considerations
  - PHP version compatibility
  - WordPress & WooCommerce versions
  - Multi-site behavior
- How free vs Pro affects extension points
  - Entitlements, premium-aware components, fallback behavior

### B11.2. Creating Custom Rule Components

Status: Drafted in docs/public/developers/extending-rules.md (2025-11-15)

**URL:**

- `/docs/developers/extending-rules/`

**Content covered:**

- Discovery model
  - Components are discovered by RuleComponentRegistry scanning classes that implement the proper interfaces; ensure autoloading is set up.
- Shared contract (ComponentInterface)
  - get_id, get_label, get_description, get_capability, get_settings_schema (i18n guidance and stable IDs)
- Custom triggers (TriggerInterface)
  - should_trigger(context, settings) contract; keep fast; example skeleton included
- Custom conditions (ConditionInterface)
  - evaluate(WC_Order, settings) contract; schema example; performance tips (minimize DB calls)
- Custom actions
  - Execute semantics with evaluation context; idempotency guidance; WooCommerce API usage (update_status, add_order_note)
- Settings schema and UI hints
  - JSON-like schema fields and optional ui:* hints for the Rule Builder
- Entitlements and Pro awareness
  - Choosing capability keys; odcm_can_use gating; graceful degradation when Pro is inactive
- Testing and troubleshooting
  - Autoload/interface checks; using Insight (Audit Log); REST 403 odcm_premium_blocked meaning

### B11.3. Working with Settings Schemas & Rule Builder UI

Status: Drafted in docs/public/developers/settings-schema-and-ui.md (2025-11-15)

**URL:**

- `/docs/developers/settings-schema-and-ui/`

**Content:**

- Schema format
  - Basic field types (string, number, array, object)
  - Titles, descriptions, enums
- UI metadata
  - `ui:widget` (select, radio, complex widgets, searchable checkboxes)
  - `ui:premium_options`, disabled states, placeholders
- Adding custom UI behavior (if supported)
  - Hooks/filters for injecting custom JS or schema transformers
- How Pro affects UI capabilities
  - Enabling premium options, removing upgrade prompts

### B11.4. Entitlement & Premium Awareness (for Integrators)

Status: Drafted in docs/public/developers/entitlements-and-premium.md (2025-11-15)

**URL:**

- `/docs/developers/entitlements-and-premium/`

**Content covered:**

- Declaring capabilities in components
  - Implement `ComponentInterface::get_capability()`; stable, snake_case keys
  - Use clear tiering (e.g., trigger_basic, trigger_premium, condition_order_total)
- UI entitlement hints in schemas
  - `ui:capability` to gate fields/groups; `ui:premium_options` for enum values
  - Core shows premium items visible-but-disabled; Pro unlocks the same controls
- Server-side enforcement (REST)
  - `RuleBuilderApiController` validates rules and filters schemas by entitlement
  - Defensive block on known premium trigger (e.g., universal any-status change) unless `trigger_premium` is allowed
  - Error shape: HTTP 403 with code `odcm_premium_blocked` (i18n message)
- Using `odcm_can_use()` and separation from Guard
  - Licensing/entitlement via `odcm_can_use()`; permissions via Guard/WordPress caps — both must pass
- Premium Component Fallback
  - Core initializes fallback to avoid fatals when Pro is removed; design components to no-op safely
- Best practices
  - Graceful degradation, no hard Pro dependencies, idempotent actions, helpful audit logs
- Testing & troubleshooting
  - UI state checks (Core vs Pro), REST 403 verification, fallback behavior after removing Pro, i18n checks

### B11.5. Audit Log Extensions

Status: Drafted in docs/public/developers/audit-log-extensions.md (2025-11-15)

**URL:**

- `/docs/developers/audit-log-extensions/`

**Content covered:**

- Registering custom audit filters
  - Filter registration structure (id, label, tier, capability, render_callback) ✓
  - Entitlement-aware rendering and UI disabled state guidance ✓
  - Security note: sanitize incoming parameters when wiring server-side queries ✓
- Adding custom event types
  - Recommended mapping fields (id, label, summary_template, default_status, category) ✓
  - Guidance to reuse existing types and keep third-party types under 'custom' ✓
- Emitting events via helper
  - Using odcm_log_event(message, details, object_id, level, event_type, extra) with examples ✓
- Process timelines and correlation (Process Logger surface)
  - Why and how to include process_id and related correlation fields in `$extra` so entries consolidate into one timeline ✓
  - Webhook and order status change examples ✓
  - How to query by process via REST: GET /wp-json/odcm/v1/audit/by-process/{process_id} ✓
- Interaction with Pro features
  - Filters use odcm_can_use() for capability gating; premium filters remain visible-but-disabled in Core and unlock with Pro ✓

### B11.6. REST API & Webhooks for Integrations

Status: Drafted in docs/public/developers/rest-api-and-webhooks.md (2025-11-15)

**URL:**

- `/docs/developers/rest-api-and-webhooks/`

**Content covered:**

- REST endpoints documentation
  - Base URL structure, authentication (cookie + nonce, application passwords), permissions callbacks
  - Rule Builder API: components discovery, get/save rule, search helpers; 403 `odcm_premium_blocked` for premium without entitlement; example request/response
  - Audit/Insight API: list/filter, render, batch delete, by-process; pagination and i18n notes
  - Rate limits and recommended usage patterns
- Webhooks for integrators
  - Inbound endpoints: POST /webhooks/{gateway}, health check, admin test tools, gateway discovery
  - Security: HTTPS required, shared secret/HMAC in headers, replay protection, edge rate limiting
  - Behavior: content types, always-200 response strategy, idempotency header tips; verify via Audit Log
- Core vs Pro endpoints/features
  - Pro unlocks additional filters/options but route shapes are the same; Core enforces entitlements on Rule Builder POSTs

### B11.7. CLI & Automation (Pro)

Status: Drafted in docs/public/developers/cli-and-automation.md (2025-11-15)

**URL:**

- `/docs/developers/cli-and-automation/`

**Content covered:**

- Scope and prerequisites
  - No CLI in Core; Pro may register commands under the `odcm` namespace ✓
  - WP-CLI setup notes and environment expectations ✓
- Commands (illustrative; Pro decides exact names)
  - List/get/update rules; order reprocess (single/batch); diagnostics; log cleanup ✓
  - Usage patterns and --format=json for scripts; non-zero exit codes on failure ✓
- Automation
  - Cron examples and idempotent batch guidance; prefer queues for long jobs ✓
- Logging & observability
  - Emit odcm_log_event() with correlation fields; respect ODCM_DEBUG ✓
- Security & entitlements
  - Safe operation under WP-CLI, capability intent, secrets handling ✓
  - Gate premium behaviors via odcm_can_use(); friendly errors when locked ✓
- Pro maintainer notes (implementation TODOs)
  - WP_CLI::add_command usage, dependency guards, thin handlers, tests ✓

### B11.8. Hooks & Filters Reference

Status: Drafted in docs/public/developers/hooks-and-filters-reference.md (2025-11-15)

**URL:**

- `/docs/developers/hooks-and-filters-reference/`

**Content covered:**

- Component registration actions: `odcm_register_triggers`, `odcm_register_conditions`, `odcm_register_actions` ✓
- Rule Builder lifecycle filters/actions: `odcm_rule_builder_config`, `odcm_before_rule_validation`, `odcm_before_rule_save`, `odcm_after_rule_save` ✓
- Webhook adapter + test helpers: `odcm_register_gateway_adapters`, `odcm_webhook_test_event_types`, `odcm_webhook_test_payload` ✓
- Insight/Audit dashboard hooks: `odcm_insight_dashboard_accordion_state`, `odcm_insight_dashboard_settings_sections`, `odcm_debug_source_labels` ✓
- Evaluation/attribution filters: `odcm_enable_context_cache`, `odcm_attribution_context`, `odcm_enable_deep_attribution`, `odcm_attribution_backtrace_limit`, `odcm_attribution_time_budget_ms`, `odcm_process_lifecycle_families`; plus dynamic checkout context filter naming note ✓
- Entitlements override filter: `odcm_is_premium_user` (for Pro/licensing override only) ✓
- For each hook entry: name, purpose, when it fires, arguments, return (if filter), example snippet ✓