<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleConditions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use OrderDaemon\CompletionManager\Core\Events\EvaluationContext;
use WC_Order;

/**
 * Source Gateway Condition
 * 
 * Evaluates whether a universal event originates from specific payment gateways.
 * This condition allows rules to trigger based on the source gateway of the event,
 * enabling gateway-specific automation workflows.
 * 
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Conditions
 * @since   next
 */
class SourceGatewayCondition implements ConditionInterface
{
    /**
     * {@inheritdoc}
     */
    public function get_id(): string
    {
        return 'source_gateway';
    }

    /**
     * {@inheritdoc}
     */
    public function get_label(): string
    {
        return __('Source Gateway', 'order-daemon');
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string
    {
        return __('Check if the universal event originates from specific payment gateways (e.g., PayPal, Stripe).', 'order-daemon');
    }

    /**
     * {@inheritdoc}
     */
    public function get_settings_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gateways' => [
                    'type' => 'array',
                    'title' => __('Payment Gateways', 'order-daemon'),
                    'description' => __('Select the payment gateways that should trigger this rule.', 'order-daemon'),
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            'paypal',
                            'stripe',
                            'square',
                            'authorize_net',
                            'braintree',
                            'woocommerce_payments',
                            'generic',
                        ]
                    ],
                    'enumNames' => [
                        __('PayPal', 'order-daemon'),
                        __('Stripe', 'order-daemon'),
                        __('Square', 'order-daemon'),
                        __('Authorize.Net', 'order-daemon'),
                        __('Braintree', 'order-daemon'),
                        __('WooCommerce Payments', 'order-daemon'),
                        __('Generic/Other', 'order-daemon'),
                    ],
                    'default' => ['paypal'],
                    'minItems' => 1,
                ],
                'operator' => [
                    'type' => 'string',
                    'title' => __('Operator', 'order-daemon'),
                    'description' => __('How to match the gateways.', 'order-daemon'),
                    'enum' => ['in', 'not_in'],
                    'enumNames' => [
                        __('Is one of', 'order-daemon'),
                        __('Is not one of', 'order-daemon'),
                    ],
                    'default' => 'in',
                ],
                'include_null' => [
                    'type' => 'boolean',
                    'title' => __('Include Unknown Gateways', 'order-daemon'),
                    'description' => __('Whether to match events from unknown or unspecified gateways.', 'order-daemon'),
                    'default' => false,
                ],
            ],
            'required' => ['gateways'],
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
        $gateways = $settings['gateways'] ?? [];
        $operator = $settings['operator'] ?? 'in';
        $include_null = $settings['include_null'] ?? false;
        
        if (empty($gateways)) {
            return false;
        }

        $source_gateway = $context->event->sourceGateway;
        
        // Handle null/empty gateway
        if (empty($source_gateway)) {
            return $include_null;
        }

        $is_match = in_array($source_gateway, $gateways, true);

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
