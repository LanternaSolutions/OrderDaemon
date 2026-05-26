<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleActions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ActionInterface;
use WC_Order;

/**
 * An action that changes the order status to 'completed'.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Actions
 * @since   1.0.0
 */
class CompleteOrderAction implements ActionInterface
{
    public function get_id(): string
    {
        return 'change_status_to_completed';
    }

    public function get_label(): string
    {
        return __('rule_component.action.complete_order.label', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('rule_component.action.complete_order.description', 'order-daemon');
    }

    public function get_icon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
    }

    public function get_settings_schema(): ?array
    {
        // This action has no settings.
        return null;
    }

    public function execute(WC_Order $order, array $settings): void
    {
        $order->update_status('completed', __('rule_component.action.complete_order.note_message', 'order-daemon'));
    }

    /**
     * Indicates this is the default/free action.
     *
     * @return bool
     */
    public function is_default(): bool
    {
        return true;
    }

    /**
     * Gets the priority for ordering (lower = higher priority).
     *
     * @return int
     */
    public function get_priority(): int
    {
        return 1; // Highest priority as the default action
    }
}
