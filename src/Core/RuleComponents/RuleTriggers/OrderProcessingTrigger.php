<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleTriggers;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\TriggerInterface;

/**
 * A trigger that fires when an order status changes to 'processing'.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Triggers
 * @since   1.0.0
 */
class OrderProcessingTrigger implements TriggerInterface
{
    /**
     * Priority used for UI ordering.
     * Lower numbers appear earlier.
     *
     * @return int
     */
    public function get_priority(): int
    {
        // Ensure "Order Processing" is always shown at the very top of triggers
        return 1;
    }
    public function get_id(): string
    {
        return 'order_processing';
    }

    public function get_label(): string
    {
        return __('Order Processing', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('rule_component.trigger.order_processing.description', 'order-daemon');
    }

    public function get_capability(): string
    {
        return 'trigger_basic'; // Corresponds to the free tier
    }

    public function get_settings_schema(): ?array
    {
        // This trigger has no settings.
        return null;
    }

    public function should_trigger(array $context, array $settings = []): bool
    {
        // Simple triggers always return true - they don't need custom evaluation logic
        return true;
    }
}
