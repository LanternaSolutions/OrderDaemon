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
        return __('core.log_registries.event_type.order_processing', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('rule_component.trigger.order_processing.description', 'order-daemon');
    }

    public function get_icon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16.5 9.4-9-5.19"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12 20.73 6.96"/><line x1="12" x2="12" y1="22" y2="12"/></svg>';
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
