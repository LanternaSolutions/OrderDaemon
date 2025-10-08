<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleConditions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use OrderDaemon\CompletionManager\Core\Events\EvaluationContext;
use WC_Order;

/**
 * Event Type Condition
 * 
 * Evaluates whether a universal event matches specific event types.
 * This condition is designed specifically for universal events and
 * allows rules to trigger based on payment, subscription, or other
 * lifecycle events from external gateways.
 * 
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Conditions
 * @since   next
 */
class EventTypeCondition implements ConditionInterface
{
    /**
     * {@inheritdoc}
     */
    public function get_id(): string
    {
        return 'event_type';
    }

    /**
     * {@inheritdoc}
     */
    public function get_label(): string
    {
        return __('Event Type', 'order-daemon');
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string
    {
        return __('Check if the universal event matches specific event types (e.g., payment_completed, subscription_cancelled).', 'order-daemon');
    }

    /**
     * {@inheritdoc}
     */
    public function get_settings_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_types' => [
                    'type' => 'array',
                    'title' => __('Event Types', 'order-daemon'),
                    'description' => __('Select the event types that should trigger this rule.', 'order-daemon'),
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            // Payment events
                            'payment_created',
                            'payment_completed',
                            'payment_denied',
                            'payment_pending',
                            'payment_refunded',
                            'payment_reversed',
                            
                            // Subscription events
                            'subscription_created',
                            'subscription_approved',
                            'subscription_cancelled',
                            'subscription_suspended',
                            'subscription_reactivated',
                            'subscription_completed',
                            
                            // Renewal events
                            'renewal_payment_processing',
                            'renewal_payment_completed',
                            'renewal_payment_failed',
                            'renewal_payment_pending',
                            
                            // Trial events
                            'trial_started',
                            'trial_ended',
                            
                            // Dispute events
                            'dispute_opened',
                            'dispute_resolved',
                            'dispute_won',
                            'dispute_lost',
                        ]
                    ],
                    'default' => ['payment_completed'],
                    'minItems' => 1,
                ],
                'operator' => [
                    'type' => 'string',
                    'title' => __('Operator', 'order-daemon'),
                    'description' => __('How to match the event types.', 'order-daemon'),
                    'enum' => ['in', 'not_in'],
                    'enumNames' => [
                        __('Is one of', 'order-daemon'),
                        __('Is not one of', 'order-daemon'),
                    ],
                    'default' => 'in',
                ],
            ],
            'required' => ['event_types'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(WC_Order $order, array $settings): bool
    {
        // This condition is designed for universal events only
        // Return false for legacy order-only evaluation
        return false;
    }

    /**
     * Evaluate condition against universal event context
     * 
     * @param EvaluationContext $context Universal event context
     * @param array $settings Sanitized condition settings
     * @return bool True if condition passes
     */
    public function evaluateUniversalEvent(EvaluationContext $context, array $settings): bool
    {
        $event_types = $settings['event_types'] ?? [];
        $operator = $settings['operator'] ?? 'in';
        
        if (empty($event_types)) {
            return false;
        }

        $current_event_type = $context->event->eventType;
        $is_match = in_array($current_event_type, $event_types, true);

        return $operator === 'in' ? $is_match : !$is_match;
    }

    /**
     * {@inheritdoc}
     */
    public function get_category(): string
    {
        return 'universal_events';
    }

    /**
     * {@inheritdoc}
     */
    public function is_premium(): bool
    {
        return false; // Available in free version
    }

    /**
     * {@inheritdoc}
     */
    public function get_capability(): string
    {
        return 'manage_woocommerce';
    }
}
