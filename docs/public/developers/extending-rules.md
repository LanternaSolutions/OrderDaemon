# Creating Custom Rule Components

Audience: Developers (public developer docs)
Last updated: 2025-11-15

This guide shows how to extend Order Daemon by adding your own rule components: Triggers, Conditions, and Actions. It focuses on stable contracts and best practices, and avoids internal-only details.

What you’ll learn
- Where component classes live and how they are discovered
- Which PHP interfaces to implement and what each method should return
- How entitlement (free vs Pro) interacts with your components
- Performance, safety, and i18n tips

Prerequisites
- WordPress + WooCommerce basics
- PHP 7.4+ (PHP 8.x recommended)
- Familiarity with WooCommerce orders (WC_Order)

---

## How components are discovered

Order Daemon discovers components via a registry:
- Class: src/Core/RuleComponents/RuleComponentRegistry.php
- It scans the component namespaces/paths for Triggers, Conditions, and Actions.
- Any concrete class that implements the correct interface is available to the Rule Builder, the REST API, and the engine.

You do not need to “register” your class manually in code if you place it in a loadable location and it is autoloaded. If you ship an add-on, make sure your plugin’s composer autoload or classloader is set up so your classes are available.

---

## Shared interface for all components

Implement ComponentInterface for metadata used by the UI and REST:
- Namespace: OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces
- Methods you must implement:
  - get_id(): string — unique, lowercase, snake_case id (stable; used in DB and APIs)
  - get_label(): string — human readable label (translatable)
  - get_description(): string — longer help text (translatable)
  - get_capability(): string — entitlement key (decides free vs premium availability)
  - get_settings_schema(): ?array — JSON-like schema (PHP array) for your settings form; return [] or null if no settings

i18n tip: Return string IDs wrapped in translation functions using the text domain order-daemon. Example: __( 'components.my_condition.label', 'order-daemon' ).

Entitlement tip: Choose a clear capability key. Core checks odcm_can_use($capability) in UI and REST. In Core, premium-only items appear disabled; Pro unlocks them.

---

## Triggers

Implement TriggerInterface in addition to ComponentInterface:
- Namespace: Interfaces\TriggerInterface
- Contract: should_trigger(array $context, array $settings = []): bool

Notes
- Simple triggers can return true and let the engine decide based on configured metadata.
- Complex triggers can inspect the $context or $settings to decide whether to wake the rule.

Skeleton
```php
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ComponentInterface;
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\TriggerInterface;

final class OrderStatusProcessingTrigger implements TriggerInterface
{
    public function get_id(): string { return 'order_status_to_processing'; }
    public function get_label(): string { return __('components.trigger.processing.label', 'order-daemon'); }
    public function get_description(): string { return __('components.trigger.processing.desc', 'order-daemon'); }
    public function get_capability(): string { return 'trigger_basic'; }
    public function get_settings_schema(): ?array { return []; }

    public function should_trigger(array $context, array $settings = []): bool
    {
        // Example: expect from->to in $context
        return ($context['to_status'] ?? '') === 'processing';
    }
}
```

Best practices
- Keep should_trigger() fast; avoid heavy queries here.
- Use clear, stable IDs. They are persisted with rules.
- For premium triggers, return a capability like trigger_premium so Core/Pro can gate it.

---

## Conditions

Implement ConditionInterface in addition to ComponentInterface:
- Namespace: Interfaces\ConditionInterface
- Contract: evaluate(WC_Order $order, array $settings): bool

Skeleton
```php
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ComponentInterface;
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use WC_Order;

final class OrderTotalAtLeastCondition implements ConditionInterface
{
    public function get_id(): string { return 'order_total_at_least'; }
    public function get_label(): string { return __('components.condition.total_at_least.label', 'order-daemon'); }
    public function get_description(): string { return __('components.condition.total_at_least.desc', 'order-daemon'); }
    public function get_capability(): string { return 'condition_order_total'; }
    public function get_settings_schema(): ?array {
        return [
            'type' => 'object',
            'properties' => [
                'amount' => [ 'type' => 'number', 'minimum' => 0, 'title' => __('components.fields.amount', 'order-daemon') ],
            ],
            'required' => ['amount'],
        ];
    }

    public function evaluate(WC_Order $order, array $settings): bool
    {
        $amount = (float) ($settings['amount'] ?? 0);
        return (float) $order->get_total() >= $amount;
    }
}
```

Best practices
- Minimize DB calls; re-use data available on WC_Order.
- Validate and sanitize settings defensively; the REST layer also validates against your schema.
- Log sparingly; prefer the engine’s ProcessLogger for detailed traces.

---

## Actions

Actions implement ComponentInterface. The concrete execute signature is provided by the engine when invoking actions, passing the current evaluation context and your action’s settings. Keep the execute method idempotent where possible (running twice should not cause harm).

Common patterns for actions include:
- Changing order status using WooCommerce APIs
- Adding an order note
- Tagging/flagging orders (via meta)

Skeleton (illustrative)
```php
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ComponentInterface;
use WC_Order;

final class MarkCompletedAction implements ComponentInterface
{
    public function get_id(): string { return 'set_status_completed'; }
    public function get_label(): string { return __('components.action.complete.label', 'order-daemon'); }
    public function get_description(): string { return __('components.action.complete.desc', 'order-daemon'); }
    public function get_capability(): string { return 'action_basic'; }
    public function get_settings_schema(): ?array { return []; }

    // Pseudocode: the engine will call your execute-like method with an order/context.
    public function execute(WC_Order $order, array $settings, array $context = []): array
    {
        if ($order->get_status() !== 'completed') {
            $order->update_status('completed', __('components.action.complete.note', 'order-daemon'));
        }
        // Return a structured result for logging (shape may vary by engine version)
        return [ 'status' => 'success', 'message' => 'Order set to completed' ];
    }
}
```

Best practices
- Use WooCommerce APIs (update_status, add_order_note) rather than direct DB writes.
- Be idempotent: if the order is already in the desired state, return a neutral/success outcome without duplicating work.
- Include short, translatable notes/messages where user-facing.

---

## Settings schema tips (for the Rule Builder UI)

- Return a compact JSON-like schema that the Rule Builder can render.
- Supported concepts include: type, properties, title/description, enum/options, defaults. You can add UI hints such as ui:widget or ui:placeholder if supported by the current UI.
- Keep labels/descriptions translatable via order-daemon.

Example snippet
```php
return [
  'type' => 'object',
  'properties' => [
    'categories' => [
      'type' => 'array',
      'items' => [ 'type' => 'integer' ],
      'title' => __('components.fields.categories', 'order-daemon'),
      'ui:widget' => 'category-picker',
    ],
  ],
];
```

---

## Entitlements (free vs Pro)

- Choose a capability key for your component via get_capability(). Examples: trigger_basic, trigger_premium, condition_order_total, action_basic.
- Core and Pro call odcm_can_use($capability) to decide availability. The UI disables premium items when Pro is not active; the REST API blocks saving such components.
- Design for graceful degradation: if a site deactivates Pro, your component should not fatal. Consider exposing a fallback behavior or allow rules to save without executing premium effects.

---

## Performance and safety

- Avoid heavy queries in should_trigger/evaluate/execute. If you need lookups, cache results for the duration of the request.
- Validate settings server-side even if the UI enforces them. The REST layer validates against your schema, but your code should still defend against malformed data.
- Use i18n for any user-facing strings with the order-daemon text domain.
- For admin/AJAX tools in your own add-on, prefer the Guard pattern (capability + nonce) and standard WP permission checks.

---

## Testing and troubleshooting

- Verify your class is autoloaded and implements the correct interface; the registry ignores abstract classes and non-matching types.
- Use the Insight (Audit Log) dashboard to confirm when your component runs and what result it returns.
- If a rule save returns 403 with odcm_premium_blocked, your component’s capability is considered premium and Pro is not active on that site.

---

## What’s next

- Learn about schemas and UI hints in more depth: /docs/developers/settings-schema-and-ui/
- Review entitlements: /docs/developers/entitlements-and-premium/
- REST and webhooks for integrators: /docs/developers/rest-api-and-webhooks/
