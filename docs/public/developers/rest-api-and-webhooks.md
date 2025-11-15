# REST API & Webhooks for Integrations

Audience: Developers (public developer docs)
Last updated: 2025-11-15

This guide documents the public integration surface of Order Daemon for WooCommerce: REST endpoints used by the admin UI (which you can call from external tooling) and the inbound Webhook receiver you can post to from third‑party services.

It focuses on stable request/response shapes, authentication, entitlement behavior (Core vs Pro), and best practices. Internal implementation details are intentionally omitted; see internal A8 for maintainers.

---

## Base URL and authentication

- Namespace and base:
  - Rule Builder and Audit/Insight endpoints are registered during `rest_api_init` by the core plugin.
  - Webhooks endpoints are public by design (see security below).
- WordPress REST base: https://your-site.example/wp-json/
- Authentication options (server defaults apply):
  - Logged‑in admin via cookies + REST nonce (wp_rest nonce). Recommended for admin tools and local development.
  - Application Passwords / Basic Auth plugins if you need programmatic access. Ensure you scope permissions to an admin/shop‑manager account.
- Permissions model:
  - Endpoints that mutate or expose admin data have `permission_callback`s requiring suitable capabilities (typically `manage_woocommerce` with fallback to `manage_options`).
  - Webhook receive endpoint is intentionally unauthenticated but should be protected at the adapter level (shared secret/signature), and by transport controls (IP allowlists, rate limiting).

---

## Rule Builder API

Use these endpoints to discover components and create/update rules programmatically. Entitlements (free vs Pro) are enforced server‑side: premium‑gated payloads are rejected in Core.

Common error:
- HTTP 403 with code `odcm_premium_blocked` indicates your request includes a premium‑only component/option without entitlement. The message is localized using the `order-daemon` text domain.

Typical endpoints
- List components available to the Rule Builder (triggers, conditions, actions):
  - GET /wp-json/odcm/v1/rules/components
  - Response includes component metadata (id, label, description, capability, settings schema filtered by entitlement).
- Get a rule by ID:
  - GET /wp-json/odcm/v1/rules/{id}
  - Returns the stored configuration for the rule CPT (post type `odcm_order_rule`).
- Save (create/update) a rule:
  - POST /wp-json/odcm/v1/rules
  - Body: JSON rule definition containing trigger, conditions, actions, and settings. Server performs validation, entitlement checks, and sanitization.
  - Response: the saved rule object with id and status.
- Dynamic search helpers for UI pickers:
  - GET /wp-json/odcm/v1/search?type=category&query=shoes
  - Returns options for async selects (categories, products, etc.). Types vary; responses are arrays of id/label pairs.

Example: create/update a rule
```
POST /wp-json/odcm/v1/rules
X-WP-Nonce: <wp_rest nonce>
Content-Type: application/json

{
  "title": "Auto-complete digital",
  "enabled": true,
  "trigger": { "id": "payment_completed" },
  "conditions": [
    {
      "id": "product_type",
      "settings": { "types": ["virtual", "downloadable"] }
    }
  ],
  "actions": [
    { "id": "set_status_completed" }
  ]
}
```

Possible errors
- 400 invalid_schema: payload shape invalid; check required fields or enum values.
- 403 odcm_premium_blocked: premium component/option without entitlement.
- 401/403 permission_denied: user lacks required admin caps or nonce.

---

## Audit/Insight API

Provides read (and limited admin actions) for the audit timeline used by the Insight dashboard.

Typical endpoints
- List/filter audit entries:
  - GET /wp-json/odcm/v1/audit?search=abc&page=1&per_page=20
  - Pro may unlock additional filters (date range, status, event type) server‑side; Core returns only basic filters.
- Render components (server‑rendered snippets):
  - POST /wp-json/odcm/v1/audit/render
  - Body: { "event_id": 123 } or batch form. Returns HTML/structured payloads for UI.
- Batch delete (admin only):
  - DELETE /wp-json/odcm/v1/audit?ids=1,2,3
- Find by process/correlation:
  - GET /wp-json/odcm/v1/audit/by-process/{process_id}

Notes
- Pagination: use page and per_page; responses include totals/headers if supported by WP REST conventions.
- Permissions: read operations require admin/shop‑manager privileges; mutation endpoints are admin‑only with nonces.
- i18n: messages in responses are localized; do not hardcode English expectations.
- Correlation: include a stable `process_id` in the `$extra` argument when calling `odcm_log_event()` so entries can be grouped and discovered via the by‑process endpoint.

Example: list entries
```
GET /wp-json/odcm/v1/audit?page=1&per_page=20
X-WP-Nonce: <wp_rest nonce>
```

---

## Webhook Receiver (Inbound)

Order Daemon exposes a universal ingress endpoint you can POST to from external systems (payment gateways, automation tools, internal apps). The controller normalizes payloads and hands off to the event router.

Endpoints
- Receive webhook (public):
  - POST /wp-json/odcm/v1/webhooks/{gateway}
  - `{gateway}` is a short slug identifying the source (e.g., `stripe`, `zapier`, `custom-app`). The adapter resolves how to parse and authenticate.
- Health check (public, lightweight):
  - GET /wp-json/odcm/v1/webhooks/health
  - Returns a 200 OK with a minimal body so you can test connectivity.
- Gateway discovery (admin‑only):
  - GET /wp-json/odcm/v1/webhooks/gateways
  - Returns registered adapters/slugs; useful in admin tools and for validation.
- Admin test tools:
  - POST /wp-json/odcm/v1/webhooks/test
  - Admin‑only; helps simulate or verify adapter behavior during setup.

Security
- Transport: always use HTTPS.
- Authentication: the receive endpoint is unauthenticated; protect with:
  - Shared secret in a header (e.g., X-ODCM-Signature) with HMAC over the raw body; adapters should verify before processing.
  - Replay protection (timestamps + nonce in headers) and edge rate limiting (WAF or gateway features).
- Privacy: send only data required for your automation; avoid personal data unless necessary.

Request/response behavior
- Content types: application/json is preferred; form‑encoded payloads are accepted by many adapters.
- Response: the endpoint generally returns HTTP 200 even when the downstream process records an error. Use the Audit Log to verify outcomes.
- Idempotency: include an event id in a header (e.g., X-Event-Id) so adapters can deduplicate.

Example: POST a custom webhook
```
POST /wp-json/odcm/v1/webhooks/custom-app
Content-Type: application/json
X-ODCM-Signature: sha256=abcdef123456

{
  "order_id": 12345,
  "event": "shipment_delivered",
  "delivered_at": "2025-11-15T12:34:56Z"
}
```

Verify in Audit Log
- Use the Insight dashboard or call the Audit API to confirm a corresponding entry exists (search for event type/source or use a process/correlation id if your adapter sets one).

---

## Rate limits and best practices

- Back off on 429/5xx and retry with exponential backoff when calling admin REST endpoints.
- Keep webhook payloads minimal; prefer references (order id) and let the server enrich context.
- Validate and sanitize inputs server‑side; never trust inbound data. Emit clear audit events on parse/auth failures.
- Timeouts: set HTTP client timeouts to a few seconds; the webhook receiver is quick and logs internally.

---

## Core vs Pro

- Core exposes all endpoints documented here. Pro may unlock additional filter options for the Audit API and additional components surfaced via the Rule Builder API but does not change the basic route shapes.
- Entitlements are enforced server‑side on Rule Builder POSTs; do not attempt to bypass with direct REST calls.

---

## Troubleshooting

- 401/403 from admin endpoints: missing/invalid nonce or insufficient capabilities. Use an account with `manage_woocommerce` (or `manage_options`).
- 403 `odcm_premium_blocked`: your rule payload contains a premium component/option without entitlement.
- Webhook POST returns 200 but nothing happens: check the Audit Log for parse/auth failures and verify your adapter slug is correct. Ensure signatures match and headers are forwarded through any proxies.
- Translations missing: ensure the site language is set and JSON script translations are present for admin bundles.

---

## See also

- Developer overview: /docs/developers/overview/
- Creating components: /docs/developers/extending-rules/
- Settings schemas & UI: /docs/developers/settings-schema-and-ui/
- Entitlements: /docs/developers/entitlements-and-premium/
- User guide for webhooks: /docs/webhooks-and-integrations/