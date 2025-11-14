# REST API and External Integrations (Core + Pro)

Audience: internal development team
Last updated: 2025-11-15
Scope: code-accurate to Core as of this date. Primary references:
- src/Plugin.php (rest_api_init registration)
- src/API/AuditLogEndpoint.php (audit/timeline APIs)
- src/API/RuleBuilderApiController.php (Rule Builder CRUD + search)
- src/API/WebhookController.php (inbound webhooks, test tools, gateway discovery)
- src/Core/Security/GuardChecker.php and A5 security doc (permissions model)
- src/Includes/functions.php (odcm_can_use and i18n error messages)

---

## 1. Registration and Namespaces

- Endpoints are registered from Plugin::initialize_api_endpoints() on the `rest_api_init` hook (priority 10):
  - API\AuditLogEndpoint
  - API\RuleBuilderApiController
  - API\WebhookController
- Namespaces and bases (as implemented):
  - AuditLogEndpoint: namespace constant `odcm/v1`, base route `audit-logs` (derived from class constants). See register_routes().
  - RuleBuilderApiController: `odcm/v1`, rest_base `rule-builder`.
  - WebhookController: `odcm/v1`, base route `webhooks`.
- JSON translations: All REST error messages and labels use the `order-daemon` text domain. Ensure Plugin::load_text_domain() runs before admin/API usage.

---

## 2. Rule Builder API (CRUD and discovery)

Class: src/API/RuleBuilderApiController.php

- Base: `/odcm/v1/rule-builder`
- Routes (register_routes):
  - GET `/odcm/v1/rule-builder/components`
    - Returns available components grouped into `triggers`, `conditions`, `primaryActions`, `secondaryActions` (also `actions` for legacy).
    - Permission: `get_items_permissions_check` (admin capability, see A5).
    - Behavior: Uses RuleComponentRegistry to discover components, categorizes actions, optionally inspects current rule via `rule_id` to mark selected items.
  - GET `/odcm/v1/rule/(?P<id>\d+)`
    - Loads a specific rule’s configuration (CPT `odcm_order_rule`).
    - Permission: `get_item_permissions_check`.
    - Args: `id: integer` (i18n description key `api.rule_builder.rule_id_description`).
  - POST/PATCH `/odcm/v1/rule/(?P<id>\d+)`
    - Saves a rule’s configuration.
    - Permission: `update_item_permissions_check`.
    - Body: JSON containing `rule` and optional `audit` metadata.
    - Server-side validation:
      - validate_rule_entitlements($rule_data) — composes violations; returns WP_Error `odcm_premium_blocked` (403) with localized messages when premium components/settings are included without entitlement.
      - Defensive block: trigger id `order_status_any_change` requires `trigger_premium` capability; blocked otherwise.
      - validate_component_entitlement($component, $type) — resolves component by id via RuleComponentRegistry and checks odcm_can_use($component->get_capability()); also validates provided settings against the component schema.
      - sanitize_rule_data($rule) — normalizes structure before persistence.
    - Persistence: updates post modified timestamps, stores JSON under `_odcm_rule_data` meta.
    - Responses: success `{ success: true, rule_id, audit_logged }`; errors are WP_Error with translated messages (e.g., `api.rule_builder.invalid_rule_id`, `api.rule_builder.entitlement.*`).
  - GET `/odcm/v1/rule-builder/search-content`
    - Dynamic search for UI pickers (products, categories, posts/pages, users, order_statuses, payment_methods, shipping_methods, product_tags).
    - Args: `source` (enum), `search` (string), `limit` (1–100, default 50).
    - Permission: `get_items_permissions_check`.
- Performance/observability: endpoints call `log_api_performance()` and `log_api_error()` with context; use A7 for audit details.
- Security: permission callbacks enforce admin capabilities; use Guard system where applicable. Entitlement checks are separate from authZ (see A5 and A4 docs).

---

## 3. Audit/Insight API (logs, timelines, batch ops)

Class: src/API/AuditLogEndpoint.php

- Base: `/odcm/v1/audit-logs`
- Routes (register_routes):
  - GET `/odcm/v1/audit-logs`
    - Lists logs/timeline entries with filters. Args provided by `get_logs_args()`; includes pagination and filter fields.
    - Permission: `check_permissions` (admin capability checks, see A5).
  - POST `/odcm/v1/audit-logs/render-components`
    - Renders timeline components for a single log id; args: `log_id: integer`.
    - Permission: `check_permissions`.
  - POST `/odcm/v1/audit-logs/render-components/batch`
    - Renders components for multiple log ids; args: `log_ids: int[]` (1–50), `include_debug: bool` (default false).
    - Permission: `check_permissions`.
  - GET `/odcm/v1/audit-logs/filter-options`
    - Returns dynamic filter option data for the Insight dashboard.
    - Permission: `check_permissions`.
  - DELETE `/odcm/v1/audit-logs/batch-delete`
    - Batch-deletes audit log rows; args: `log_ids: int[]` (1–100).
    - Permission: `check_delete_permissions` (stricter).
  - GET `/odcm/v1/audit-logs/process/(?P<process_id>[^/]+)`
    - Fetches all logs for a given process id (correlation).
    - Permission: `check_permissions`.
  - GET `/odcm/v1/audit-logs/diagnostic` (only when `ODCM_DEBUG` is true)
    - Diagnostic ping route. Permission: `__return_true` (public) under debug-only build flag; do not enable in production.
- Rendering model:
  - Uses TimelineBuilderInterface (default DatabaseTimelineBuilder + ProcessLoggerComponentExtractor) and TimelineRendererInterface (default RegistryTimelineRenderer) for consistent UI component output.
  - Filters can exclude debug-only components via `include_debug` flag; see `filter_debug_components()` and `is_debug_component()`.
- i18n and formatting: all user-facing strings are translated via the text domain; status labels use format_* helpers.
- Observability: performance/error logs include endpoint name, execution time, and counts; see A7.

---

## 4. Webhook API (inbound integrations)

Class: src/API/WebhookController.php

- Base: `/odcm/v1/webhooks`
- Routes (register_routes):
  - POST `/odcm/v1/webhooks/(?P<gateway>[a-zA-Z0-9_-]+)` → handle_webhook()
    - Public entry guarded by `webhook_permissions_check()` which currently returns true to accept inbound traffic; authentication must be provided by the gateway-specific adapter or shared-secret/signature checks within handler logic.
    - Body: accepts JSON payloads and form-encoded data; extracted by `extract_webhook_data()`.
    - Processing: delegates to Core\Events\EventRouter::processWebhook($gateway, $input_data) which returns normalized UniversalEvent(s) for the engine.
    - Response: always HTTP 200 to discourage gateway retries; includes `{success, message|error, events_processed, process_id, execution_time}`.
    - Logging: `log_webhook_reception`, `log_webhook_success`, `log_webhook_error` create structured audit entries.
  - GET `/odcm/v1/webhooks/health` → health_check()
    - Public health probe. Returns basic readiness info.
  - POST `/odcm/v1/webhooks/test/(?P<gateway>[a-zA-Z0-9_-]+)` → test_webhook()
    - Admin-only test tool (permission_callback `test_permissions_check`). Generates synthetic payloads and routes them through the same processing path.
  - GET `/odcm/v1/webhooks/test/(?P<gateway>[a-zA-Z0-9_-]+)/events` → get_test_event_types()
    - Lists supported synthetic event types for a gateway. Admin-only.
  - GET `/odcm/v1/webhooks/gateways` → get_available_gateways()
    - Admin-only discovery of available gateway adapters.

Security considerations (see A5):
- The generic webhook endpoint is intentionally public; do not rely on WP nonces. Implement shared-secret headers and/or HMAC signatures per gateway within EventRouter adapters. Rate limiting and replay protection are recommended at the edge (e.g., CDN/WAF) and within adapters using idempotency keys.
- Test/admin endpoints use capability checks (e.g., manage_woocommerce) via `test_permissions_check`.
- All routes sanitize and validate path params; request bodies are treated as untrusted input and must be validated within adapters before generating UniversalEvents.

Operational guidance:
- Return 200 for application errors to prevent excessive third-party retries; include a process_id in responses to correlate with logs.
- Prefer small, focused payloads; large, multi-event webhooks should be batched thoughtfully to avoid timeouts.

---

## 5. Integration touchpoints and cross-cutting concerns

- Security guards: For admin-only routes and AJAX, compose appropriate CapabilityGuard/NonceGuard and, when invoking guards directly, call Plugin::get_guard_checker()->check($guard) to log outcomes.
- Entitlements: Server-side entitlement gating is primarily in RuleBuilderApiController. REST endpoints unrelated to licensing should still avoid leaking Pro-only internals in responses.
- i18n: All error/info strings returned from REST should use the `order-daemon` text domain and structured keys. The Rule Builder and Audit endpoints already comply.
- Performance: Use existing performance logging helpers; ensure filters like `include_debug` are respected to avoid excessive payloads.
- Script translations: Admin bundles that call these endpoints must load JSON translations via wp_set_script_translations (handled in Plugin::load_text_domain()).

---

## 6. Outbound webhooks and CLI (Pro notes / TODOs)

- Outbound webhooks: No concrete outbound webhook dispatchers are present in Core at this time. If/when introduced (likely in Pro), document:
  - Event types emitted, destination configuration, signing, retries/backoff, and dead-letter strategies.
  - Entitlement gating and guard requirements for configuration UIs and dispatch endpoints.
- CLI commands (Pro): Not defined in Core. Pro may register WP-CLI commands for bulk reprocessing, diagnostics, or maintenance. If present, document:
  - Namespace, command names, args, permissions, and safety guards.

---

## 7. Error handling and status codes

- WP_Error responses include localized messages; permission/entitlement failures typically return 401/403 (`RuleBuilderApiController`) while webhook endpoints return 200 with `{ success: false }` to avoid retries.
- Validation errors return 400 series codes where appropriate (e.g., invalid rule id → 404; invalid rule data → 400; meta save failures → 500).
- Always sanitize inputs and provide translator notes for placeholders in messages.

---

## 8. Testing and troubleshooting

- Verify that Plugin::initialize_api_endpoints() ran (rest_api_init hook) when routes appear missing.
- Use the Audit diagnostic route only when `ODCM_DEBUG` is enabled; never ship with public debug endpoints active.
- For Rule Builder:
  - If 403 `odcm_premium_blocked` occurs, confirm odcm_can_use() returns the expected values (Pro active or debug flag) or remove premium components from the payload.
  - Confirm permission callbacks are passing (current_user_can) when 401/403 is encountered.
- For Webhooks:
  - Validate gateway key formatting; confirm adapter availability in EventRouter.
  - Inspect audit logs created by log_webhook_* helpers using the Insight dashboard. Use `process_id` to correlate.

---

## 9. Open TODOs

- Document exact filter names/keys supported by AuditLogEndpoint::get_logs_args() for Insight filters once stabilized.
- Expand on EventRouter gateway adapters and expected request headers for signature verification (per gateway) with code samples.
- Add formal API reference (paths/params/examples) to public developer docs once endpoints are stable and versioned.
