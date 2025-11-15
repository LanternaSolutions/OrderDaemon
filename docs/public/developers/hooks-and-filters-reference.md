# Hooks & Filters Reference

Audience: Developers (public developer docs)
Last updated: 2025-11-15

This page lists the public WordPress hooks (actions and filters) exposed by Order Daemon Core that are intended for safe use by third‑party plugins and site customizations. Each entry describes when it fires, the arguments, expected return (for filters), and an example snippet.

Notes and guidelines
- All examples assume the order-daemon text domain for i18n where relevant.
- Do not rely on undocumented internal hooks. The hooks listed here are stable and designed for extension.
- Security and entitlements: hooks that affect admin/REST behavior should still observe capability checks and odcm_can_use() for premium‑gated features as appropriate.

---

## Component registration and rule model hooks

### Action: odcm_register_triggers
- Purpose: Register custom Trigger components with the Rule Component Registry.
- Fires: During options/registry load (plugin init) when Core is assembling the available components.
- Arguments:
  - $registry (OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry) — registry instance to register into.
- Example:
```php
add_action('odcm_register_triggers', function ($registry) {
    // $registry->register_trigger( new \Vendor\Plugin\Rules\MyTrigger() );
});
```

### Action: odcm_register_conditions
- Purpose: Register custom Condition components.
- Arguments: same as odcm_register_triggers.
- Example:
```php
add_action('odcm_register_conditions', function ($registry) {
    // $registry->register_condition( new \Vendor\Plugin\Rules\MyCondition() );
});
```

### Action: odcm_register_actions
- Purpose: Register custom Action components.
- Arguments: same as above.
- Example:
```php
add_action('odcm_register_actions', function ($registry) {
    // $registry->register_action( new \Vendor\Plugin\Rules\MyAction() );
});
```

---

## Rule Builder and rule persistence hooks

### Filter: odcm_rule_builder_config
- Purpose: Adjust Rule Builder UI configuration before it is passed to the frontend.
- Applies: In Admin\RuleBuilder when preparing the editor configuration.
- Signature:
  - $config = apply_filters('odcm_rule_builder_config', array $config)
- Returns: array modified config.
- Example:
```php
add_filter('odcm_rule_builder_config', function (array $config): array {
    $config['ui'] = $config['ui'] ?? [];
    $config['ui']['my_plugin_enabled'] = true;
    return $config;
});
```

### Filter: odcm_before_rule_validation
- Purpose: Last chance to adjust a rule payload before server‑side validation runs.
- Applies: In API\RuleBuilderApiController when saving a rule.
- Signature:
  - $rule_data = apply_filters('odcm_before_rule_validation', array $rule_data, int $rule_id|null, WP_Post|null $post)
- Returns: array adjusted payload.
- Example:
```php
add_filter('odcm_before_rule_validation', function (array $rule, $rule_id, $post) {
    // Ensure a default title if missing
    if (empty($rule['title'])) {
        $rule['title'] = __('rules.default_title', 'order-daemon');
    }
    return $rule;
}, 10, 3);
```

### Filter: odcm_before_rule_save
- Purpose: Sanitize/transform rule data right before persistence.
- Applies: In API\RuleBuilderApiController after validation.
- Signature:
  - $sanitized = apply_filters('odcm_before_rule_save', array $sanitized, int $rule_id|null, WP_Post|null $post)
- Returns: array sanitized payload.
- Example:
```php
add_filter('odcm_before_rule_save', function (array $rule) {
    // Drop any experimental field your plugin may have added
    unset($rule['experimental']);
    return $rule;
}, 10, 3);
```

### Action: odcm_after_rule_save
- Purpose: React after a rule has been saved.
- Applies: In API\RuleBuilderApiController after persistence succeeds.
- Signature:
  - do_action('odcm_after_rule_save', array $data, int $rule_id, WP_Post $post)
- Example:
```php
add_action('odcm_after_rule_save', function (array $data, int $rule_id) {
    // Emit an audit event or reindex caches
    if (function_exists('odcm_log_event')) {
        odcm_log_event(
            __('dev.rule_saved', 'order-daemon'),
            [ 'rule_id' => $rule_id ],
            null,
            'info',
            'custom'
        );
    }
}, 10, 3);
```

---

## Webhooks and adapters

### Action: odcm_register_gateway_adapters
- Purpose: Register webhook gateway adapters with the Event Router.
- Applies: In Core\Events\EventRouter during setup.
- Signature:
  - do_action('odcm_register_gateway_adapters', EventRouter $router)
- Example:
```php
use OrderDaemon\CompletionManager\Core\Events\Adapters\AbstractAdapter;
add_action('odcm_register_gateway_adapters', function ($router) {
    // $router->register_adapter('my_gateway', new class extends AbstractAdapter {/* ... */});
});
```

### Filter: odcm_webhook_test_event_types
- Purpose: Alter the list of test event types available in admin test tools.
- Applies: In API\WebhookTestPayloads when enumerating types.
- Signature:
  - $event_types = apply_filters('odcm_webhook_test_event_types', array $event_types, string $gateway)
- Returns: array of event type slugs/labels.
- Example:
```php
add_filter('odcm_webhook_test_event_types', function (array $types, string $gateway) {
    if ($gateway === 'custom-app') {
        $types['delivered'] = __('custom_app.event.delivered', 'order-daemon');
    }
    return $types;
}, 10, 2);
```

### Filter: odcm_webhook_test_payload
- Purpose: Provide a custom payload for a given gateway+event type when using admin test tools.
- Applies: In API\WebhookTestPayloads before falling back to built‑ins.
- Signature:
  - $payload = apply_filters('odcm_webhook_test_payload', mixed $payloadOrNull, string $gateway, string $event_type)
- Returns: array|string|null payload; return null to let Core provide defaults.
- Example:
```php
add_filter('odcm_webhook_test_payload', function ($payload, string $gateway, string $event) {
    if ($gateway === 'custom-app' && $event === 'delivered') {
        return [ 'order_id' => 1234, 'event' => 'shipment_delivered' ];
    }
    return $payload;
}, 10, 3);
```

---

## Insight (Audit) dashboard and diagnostics

### Filter: odcm_insight_dashboard_accordion_state
- Purpose: Control the open/closed state of sections in the Insight dashboard UI.
- Applies: In Admin\InsightDashboard when rendering.
- Signature:
  - $state = apply_filters('odcm_insight_dashboard_accordion_state', array $state)
- Returns: array UI state.

### Filter: odcm_debug_source_labels
- Purpose: Adjust labels used for debug source badges or listings in the Insight UI.
- Applies: In Admin\InsightDashboard.
- Signature:
  - $labels = apply_filters('odcm_debug_source_labels', array $labels)
- Returns: array of slug => label.

### Action: odcm_insight_dashboard_settings_sections
- Purpose: Inject additional settings sections into the Insight dashboard settings area.
- Applies: In Admin\InsightDashboard when building settings UI.
- Signature:
  - do_action('odcm_insight_dashboard_settings_sections')

---

## Evaluation context, attribution, and lifecycle

### Filter: odcm_enable_context_cache
- Purpose: Enable/disable caching for context building to balance performance vs freshness.
- Applies: In Core\AttributionTracker.
- Signature:
  - $enabled = (bool) apply_filters('odcm_enable_context_cache', bool $defaultTrue)
- Returns: bool.

### Filter: odcm_attribution_context
- Purpose: Inspect/alter the attribution context array built for evaluations/logging.
- Applies: In Core\AttributionTracker.
- Signature:
  - $context = (array) apply_filters('odcm_attribution_context', array $context)
- Returns: array.

### Filter: odcm_enable_deep_attribution
- Purpose: Toggle more expensive call‑stack/backtrace attribution.
- Applies: In Core\AttributionTracker (multiple sites in codepath).
- Signature:
  - $enabled = (bool) apply_filters('odcm_enable_deep_attribution', bool $defaultTrue)
- Returns: bool.

### Filter: odcm_attribution_backtrace_limit
- Purpose: Limit the number of frames inspected during deep attribution.
- Applies: In Core\AttributionTracker.
- Signature:
  - $limit = (int) apply_filters('odcm_attribution_backtrace_limit', int $default20)
- Returns: int.

### Filter: odcm_attribution_time_budget_ms
- Purpose: Millisecond budget for deep attribution work.
- Applies: In Core\AttributionTracker.
- Signature:
  - $ms = (int) apply_filters('odcm_attribution_time_budget_ms', int $default25)
- Returns: int.

### Filter: odcm_process_lifecycle_families
- Purpose: Alter process lifecycle family definitions (groupings used for timeline correlation).
- Applies: In Core\ProcessLifecycleDiscovery.
- Signature:
  - $families = apply_filters('odcm_process_lifecycle_families', array $families)
- Returns: array.

### Dynamic filter name (advanced): Checkout context builder
- Purpose: Provide gateway‑specific context shaping during checkout/webhook processing.
- Applies: In Core\CheckoutContextBuilder with a dynamic filter name constructed at runtime.
- Signature:
  - $filtered = apply_filters($filter_name, array $context, WC_Order $order|null, $gateway_instance)
- Notes: Inspect runtime logs or the builder to determine the exact filter name your gateway receives; prefer using the generic `odcm_attribution_context` when possible if you cannot target the dynamic name.

---

## Entitlements and premium detection

### Filter: odcm_is_premium_user
- Purpose: Allow Pro (or site policy) to short‑circuit entitlement checks globally.
- Applies: In src/Includes/functions.php within odcm_can_use().
- Signature:
  - $is_premium = apply_filters('odcm_is_premium_user', bool $defaultFalse)
- Returns: bool — treat with care; intended for Pro/licensing to override.

---

## Best practices
- Hook priorities: prefer default priority (10) unless you need to run earlier/later; document your assumptions.
- Return types: match exactly (e.g., return array for filters declared to return arrays).
- i18n: pass labels/messages through translation functions with the `order-daemon` text domain.
- Security: do not weaken permission checks; for REST‑facing changes, continue to use nonces and capability guards. Do not bypass server‑side entitlement checks.

---

## See also
- Creating Custom Rule Components: /docs/developers/extending-rules/
- Settings Schemas & UI: /docs/developers/settings-schema-and-ui/
- Entitlements & Premium: /docs/developers/entitlements-and-premium/
- Audit Log Extensions: /docs/developers/audit-log-extensions/
- REST & Webhooks: /docs/developers/rest-api-and-webhooks/
