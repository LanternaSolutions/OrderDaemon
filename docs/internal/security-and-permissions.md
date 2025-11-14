# Security and Permissions (Core + Pro)

Audience: internal development team
Scope: code-accurate to Core as of 2025-11-15

---

## 1) Overview

Order Daemon employs a layered security model:
- WordPress capabilities and authentication remain the foundation (current_user_can, REST permission_callback).
- A Guard-based security service centralizes higher-level authorization checks and emits structured audit logs.
- Server-side validation prevents misuse of premium-only features through direct REST calls or POST submissions.

Security responsibilities are intentionally separated from licensing/entitlements:
- Authorization (can this user perform the action?) is handled by WordPress caps and the Guard system.
- Licensing (is this feature unlocked for this site/tier?) is handled by odcm_can_use() and capability keys on components. Both must pass for an operation to succeed.

---

## 2) Guard-based security system

Central service: Core\Security\GuardChecker
- Initialization: Plugin::initialize_security_system() creates an instance when the class exists and stores it in $GLOBALS['odcm_guard_checker'].
- Accessor: Plugin::get_guard_checker(): ?Core\Security\GuardChecker returns the global instance for use across subsystems.
- Purpose: Execute Guard::verify() checks and log security outcomes (success/failure) with user/request context for auditability.

Behavior (see Core/Security/GuardChecker.php):
- check(Guard $guard, array $context = []): void
  - Calls $guard->verify().
  - On success: logs a "security_check_passed" event via odcm_log_event with guard details, user context, request context, and execution time.
  - On failure: catches SecurityException, logs "security_check_failed" with error context and stack trace, then rethrows.
- Context enrichment:
  - User context: user_id, roles, IP (via AttributionTracker::instance()->detect_ip())
  - Request context: method, user agent, referer, request URI

Guard types (referenced in GuardChecker’s instanceof checks):
- NonceGuard: Verifies WP nonces. Details captured: action, ajax_context.
- CapabilityGuard: Wraps current_user_can checks with optional object context. Details captured: capability, context, object_id.
- CompositeGuard: Groups multiple guards; all must pass. Details captured: guard_count, guard_types.

Failure modes and resilience:
- If AttributionTracker is unavailable, GuardChecker still runs and logs IP as unknown.
- If the Guard system cannot initialize (class missing or error), Plugin::initialize_security_system() fails silently to avoid breaking core; WordPress capability checks and REST permission_callback continue to protect endpoints.

Usage guidance:
- Prefer composing explicit Guard instances near sensitive operations (e.g., rule saving, bulk deletions, diagnostics) and pass them to GuardChecker::check().
- Do not rely on client-side checks; always enforce on the server.

---

## 3) WordPress capabilities and compatibility

We continue to rely on standard WP roles/caps for administrative actions:
- Typical capabilities: manage_woocommerce and manage_options.
- Example: Includes\DependencyChecker::should_show_upgrade_prompts() returns true only when Pro is inactive, in admin, and the current user can manage_woocommerce or manage_options.
- Admin UI actions (rule management, dashboards) should gate access via these caps and/or the Guard system’s CapabilityGuard.

Best practices:
- Use dedicated capability for rule management if introduced in the future, otherwise default to manage_woocommerce.
- When both Guard and WP caps are present, checks should be consistent: CapabilityGuard should reflect the same underlying capability logic.

---

## 4) REST API protection and permissions

Controllers register routes with explicit permission callbacks:
- RuleBuilderApiController: routes use permission_callback handlers (get_items_permissions_check, get_item_permissions_check, update_item_permissions_check) to restrict access to authorized admins.
- AuditLogEndpoint: routes check permissions via check_permissions() and check_delete_permissions(); there is also a debug/diagnostic route annotated as public for debugging in code (permission_callback => __return_true). Treat such endpoints as development-time only and guard behind environment checks or feature toggles as appropriate.
- WebhookController: inbound webhook create/receive routes are public (permission_callback => __return_true) by design; they should implement their own shared secret or signature verification if/when applicable. Test/diagnostic endpoints use webhook_permissions_check/test_permissions_check.

Authentication and nonces:
- Admin-facing REST interactions (from wp-admin screens) should leverage cookie auth and REST nonces. Pair these with NonceGuard for sensitive mutations when performed via AJAX.
- Public endpoints must not rely on nonces; use signatures/secrets and strict validation instead.

Error codes and i18n:
- Permission failures return WP_Error with 401/403 as appropriate, responses use the 'order-daemon' text domain keys.

---

## 5) Admin access controls

Areas and expected access:
- Rule Builder (CRUD): restricted to authorized store managers/admins; enforce via REST permission callbacks and Guard checks at save time.
- Insight & Diagnostic dashboards: restricted to admins/managers; filter premium-only UI affordances via entitlement checks but keep core viewing gated by caps.
- Audit log deletions: require elevated capability (e.g., manage_woocommerce) and should use a CompositeGuard of CapabilityGuard + NonceGuard to mitigate CSRF.

Nonce usage guidelines:
- For any state-changing admin endpoints or AJAX actions, require a nonce tied to the action and verify via NonceGuard. Ensure nonces are printed into admin pages using wp_create_nonce and are sent via headers or request params.

---

## 6) Data integrity and validation

Defense-in-depth on rule saving (RuleBuilderApiController):
- validate_rule_entitlements($rule_data): Blocks saving of components the user/site isn’t entitled to. Includes an explicit defensive block for the premium trigger id 'order_status_any_change' unless odcm_can_use('trigger_premium') returns true.
- validate_component_entitlement($component_data, $type): Resolves the component by id from RuleComponentRegistry and checks odcm_can_use($component->get_capability()). Also validates provided settings against the component’s schema.
- sanitize_rule_data($rule_data): Sanitizes and validates the structure before persisting. Any WP_Error from these phases halts the save with a 4xx/5xx response.

Prevention of premium bypass:
- Even if UI is modified or requests are forged, server-side checks prevent saving premium components or premium-only settings without entitlement.

---

## 7) Logging and observability for security

- GuardChecker logs both successful and failed security checks via odcm_log_event with rich context (user, request, timing).
- Rule save API logs exceptions and validation failures with consistent error codes.
- Consider correlating security events with process/audit logs where relevant (e.g., bulk deletions), using shared correlation identifiers if available.

---

## 8) Open TODOs and follow-ups

- Formalize and document Guard policies and their mapping to common admin actions (create, update, delete rules; purge logs; run diagnostics) in a single reference.
- Review public/debug endpoints annotated as __return_true and ensure they are disabled or gated outside of development contexts.
- Multisite: clarify capability expectations for network admins vs site admins when managing rules/logs; ensure capability checks are correct under network activation.
- Confirm consistent NonceGuard coverage for all state-changing admin AJAX flows.
- Evaluate adding a dedicated capability (e.g., 'odcm_manage_rules') for finer-grained access control in the future, mapped by default to manage_woocommerce.
