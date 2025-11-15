# Audit Log Extensions

Audience: Developers (public developer docs)
Last updated: 2025-11-15

This guide explains how to extend the Audit/Insight system: register custom filters for the Insight UI, add new event types to the logging taxonomy, and emit structured audit events from your code. It focuses on stable, public surfaces exposed by Core and is safe for use by third‑party plugins.

---

## What the Audit Log is (developer view)

Order Daemon emits structured audit events whenever rules run, webhooks arrive, security checks pass/fail, etc. These entries power the Insight timeline UI and the REST API used by the dashboards. As an integrator you can:
- Register additional filter controls for the Insight UI.
- Define and use new event type IDs so your entries render consistently.
- Emit events using the global odcm_log_event() helper.

All user‑visible strings must be internationalized with the order-daemon text domain.

---

## Registering custom Insight filters

Core ships an entitlement‑aware registry you can use from your plugin to declare new filters.
- Class: OrderDaemon\CompletionManager\Core\FilterRegistry (src/Core/FilterRegistry.php)
- Pattern: create or retrieve a singleton instance, then call register_filter([...]).

Required arguments for register_filter
- id: unique slug (lowercase, snake_case)
- label: translatable label for the UI
- tier: 'free' or 'premium' (affects badges/UX)
- capability: capability key used by odcm_can_use() for entitlement checks
- render_callback: callable that renders your filter input

Example: register a free “Source” select filter
```php
use OrderDaemon\CompletionManager\Core\FilterRegistry;

add_action('init', function () {
    // Acquire the registry instance. If your project exposes a helper like
    // odcm_get_filter_registry_instance(), prefer that. Otherwise instantiate.
    static $registry = null;
    if ($registry === null) {
        $registry = new FilterRegistry();
    }

    $registry->register_filter([
        'id'              => 'source',
        'label'           => __('insight.filters.source', 'order-daemon'),
        'tier'            => 'free',
        'capability'      => 'audit_log_basic_search',
        'render_callback' => function (bool $enabled): void {
            $disabled = $enabled ? '' : ' disabled';
            echo '<select name="odcm_filter_source"' . $disabled . '>';
            echo '<option value="">' . esc_html__('insight.filters.source.any', 'order-daemon') . '</option>';
            echo '<option value="rules">' . esc_html__('insight.filters.source.rules', 'order-daemon') . '</option>';
            echo '<option value="webhook">' . esc_html__('insight.filters.source.webhook', 'order-daemon') . '</option>';
            echo '</select>';
            if (!$enabled) {
                echo '<span class="odcm-upgrade-hint">' . esc_html__('insight.filters.premium_hint', 'order-daemon') . '</span>';
            }
        },
    ]);
});
```

Notes
- Entitlements: In the UI, check odcm_can_use($capability) to decide whether to enable your control; Core follows this pattern. Premium filters should still render but appear disabled in Core.
- i18n: Use stable string IDs; do not hardcode English.
- Security: The filter control affects queries for listing audit entries. Ensure you sanitize input server‑side if you also implement a query hook.

---

## Adding custom event types

To make your events render with consistent titles and statuses, define event types using the registry in src/Core/LogRegistries.php. This file centralizes known event type IDs and display metadata. Adding new types allows UI and tools to present them with friendly labels and default statuses.

Recommended approach (compatibility‑safe)
- Use existing event types when possible; only introduce new IDs if needed.
- If your integration ships inside a separate plugin, maintain your own mapping and use your IDs in odcm_log_event(). If Core later exposes filter/hooks for augmenting its registry, adopt them.

When defining your own mapping, keep this structure
- id: unique slug (e.g., 'my_integration_sync')
- label: translatable title for admin UI
- summary_template: sprintf‑style template for concise summaries
- default_status: one of your statuses (e.g., 'success', 'warning', 'error', 'info')
- category: 'custom' for third‑party events (Core also uses 'core', 'debug', 'premium')

Example mapping and helper in your plugin
```php
function my_odcm_event_types(): array {
    return [
        'my_integration_sync' => [
            'id'               => 'my_integration_sync',
            'label'            => __('my_integration.sync.label', 'order-daemon'),
            'summary_template' => __('my_integration.sync.summary', 'order-daemon'),
            'default_status'   => 'success',
            'category'         => 'custom',
        ],
        'my_integration_error' => [
            'id'               => 'my_integration_error',
            'label'            => __('my_integration.error.label', 'order-daemon'),
            'summary_template' => __('my_integration.error.summary', 'order-daemon'),
            'default_status'   => 'error',
            'category'         => 'custom',
        ],
    ];
}
```

---

## Emitting audit events from your code

Use the global helper odcm_log_event() provided by Core (src/Includes/functions.php). It persists an event asynchronously and is used across the plugin (webhooks, evaluator, guard checks, diagnostics).

Signature (summary)
```php
odcm_log_event(
    string $message,
    array $details = [],
    $object_id = null,          // e.g., WC order ID
    string $level = 'info',     // 'success' | 'warning' | 'error' | 'info'
    string $event_type = 'custom',
    array $extra = []           // Optional structured fields (e.g., process_id)
);
```

Examples
- Simple log line
```php
odcm_log_event(
    __('my_integration.sync.summary', 'order-daemon'),
    [ 'synced' => 3 ],
    $order_id,
    'success',
    'my_integration_sync'
);
```

- Error with context
```php
odcm_log_event(
    __('my_integration.error.summary', 'order-daemon'),
    [ 'reason' => 'signature_mismatch', 'webhook_id' => $id ],
    $order_id,
    'error',
    'my_integration_error',
    [ 'gateway' => 'acme_pay' ]
);
```

Tips
- Keep messages short; put details in the context array. The Insight detail view can render structured payloads.
- Correlation: If you have a process or request ID, include it in $extra (e.g., ['process_id' => 'abc123']).
- i18n: Message and label keys must be translatable using the order-daemon domain.

---

## Process timelines and correlation

Order Daemon groups related audit entries into a single process timeline whenever a unit of work runs (for example: a webhook is received, a rule evaluates, or a batch job executes). You can help the system consolidate your custom entries by including a stable correlation id in the `$extra` argument when calling `odcm_log_event()`.

What to include
- process_id: a UUID, upstream webhook event id, or a deterministic request/run id
- Optional context: source_event_id, gateway, batch_id — helpful for debugging and search

Examples
- Webhook adapter
```php
$event_id = $headers['x-event-id'] ?? null; // correlation from upstream
odcm_log_event(
    __('my_integration.received', 'order-daemon'),
    [ 'bytes' => strlen($raw) ],
    $order_id,
    'info',
    'my_integration_sync',
    [ 'process_id' => $event_id, 'gateway' => 'my_gateway' ]
);
```

- WooCommerce status change handler
```php
$process_id = sprintf('status-change-%d-%s', $order_id, gmdate('YmdHis'));
odcm_log_event(
    __('dev.order_status.transition', 'order-daemon'),
    [ 'from' => $from, 'to' => $to ],
    $order_id,
    'info',
    'custom',
    [ 'process_id' => $process_id ]
);
```

How to inspect
- Admin UI: Insight dashboard will group entries by process and link to the order when `object_id` is set.
- REST API: Use the by‑process endpoint to retrieve correlated entries: `GET /wp-json/odcm/v1/audit/by-process/{process_id}`.

Troubleshooting
- Entries not grouped: ensure the exact same `process_id` value is used across all related `odcm_log_event()` calls.
- Cannot find entries: verify you passed the correct Woo order id in `object_id`; then query by process id via REST.

---

## Entitlements and premium awareness

- Filters: Choose capability keys like audit_log_basic_search (free) vs audit_log_filter_advanced (premium). The UI should check odcm_can_use() to enable controls. Premium filters render disabled in Core and unlock with Pro.
- Event types: Logging is not gated by entitlements; however, you can categorize events as 'premium' in your own mapping if they relate to Pro‑only features in your plugin.

---

## Querying results (REST)

The Insight UI uses REST endpoints exposed by Core to list/filter audit entries. When you add a filter control, wire it into your UI layer and ensure the server interprets the incoming parameter securely. Follow WordPress best practices: sanitize_text_field, absint, etc. Permission checks are enforced via the plugin’s Guard and WordPress caps.

For reference, the AuditLogEndpoint controller in Core handles listing, rendering, filter options, and batch deletes.

---

## Troubleshooting

- My filter control renders but is disabled: You marked it 'premium' and your site lacks entitlement. Install/activate Pro or change the capability to a free key during development.
- My events appear but have generic labels: Provide event‑type specific labels in your mapping and use those IDs in the event_type argument.
- 403 when calling REST endpoints: Permission callbacks enforce admin caps; ensure you are authenticated and have the required nonce/capabilities.
- Translations not showing: Ensure the order-daemon text domain is loaded and JSON script translations are registered for admin bundles.

---

## See also

- Internal reference (this repo): docs/internal/logging-diagnostics.md
- Webhooks & Integrations (user view): /docs/webhooks-and-integrations/
- Developer: Entitlements & Premium: /docs/developers/entitlements-and-premium/
- Developer: Creating Custom Rule Components: /docs/developers/extending-rules/
