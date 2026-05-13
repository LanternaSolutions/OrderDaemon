<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleConditions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use WC_Order;

/**
 * A condition that checks the total amount of the order.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Conditions
 * @since   1.0.0
 */
class OrderTotalAmountCondition implements ConditionInterface
{
    public function get_id(): string
    {
        return 'order_total_amount';
    }

    public function get_label(): string
    {
        return __('rule_component.condition.order_total.label', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('rule_component.condition.order_total.description', 'order-daemon');
    }


    public function get_settings_schema(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                'amount_source' => [
                    'type'    => 'string',
                    'title'   => __('rule_component.condition.order_total.amount_source_label', 'order-daemon'),
                    'enum'    => [
                        'order_total'    => __('rule_component.condition.order_total.amount_source.order_total', 'order-daemon'),
                        'order_discount' => __('rule_component.condition.order_total.amount_source.order_discount', 'order-daemon'),
                    ],
                    'default' => 'order_total',
                ],
                'operator' => [
                    'type' => 'string',
                    'title' => __('rule_component.condition.order_total.operator_label', 'order-daemon'),
                    'description' => __('rule_component.condition.order_total.operator_description', 'order-daemon'),
                    // Radio group with inline number input mapping to 'amount'
                    'enum' => [
                        'amount_gt' => __('rule_component.condition.order_total.operator.greater_than', 'order-daemon'),
                        'amount_lt' => __('rule_component.condition.order_total.operator.less_than', 'order-daemon'),
                        'amount_eq' => __('rule_component.condition.order_total.operator.equal_to', 'order-daemon'),
                        'amount_ne' => __('rule_component.condition.order_total.operator.not_equal_to', 'order-daemon'),
                        'amount_gte' => __('rule_component.condition.order_total.operator.greater_than_equal', 'order-daemon'),
                        'amount_lte' => __('rule_component.condition.order_total.operator.less_than_equal', 'order-daemon'),
                    ],
                    'ui:radio_inputs' => [
                        'amount_gt' => 'amount_gt_value',
                        'amount_lt' => 'amount_lt_value',
                        'amount_eq' => 'amount_eq_value',
                        'amount_ne' => 'amount_ne_value',
                        'amount_gte' => 'amount_gte_value',
                        'amount_lte' => 'amount_lte_value',
                    ],
                    'default' => 'amount_gt',
                ],
                // Individual amount fields for each operator
                'amount_gt_value' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'default' => 100,
                ],
                'amount_lt_value' => [
                    'type' => 'number', 
                    'minimum' => 0,
                    'default' => 50,
                ],
                'amount_eq_value' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'default' => 75,
                ],
                'amount_ne_value' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'default' => 75,
                ],
                'amount_gte_value' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'default' => 100,
                ],
                'amount_lte_value' => [
                    'type' => 'number',
                    'minimum' => 0, 
                    'default' => 50,
                ],
            ],
            'required' => ['operator'],
        ];
    }

    public function evaluate(WC_Order $order, array $settings): bool
    {
        $operator = $settings['operator'] ?? 'amount_gt';
        $amount_source = $settings['amount_source'] ?? 'order_total';

        // Get the amount value based on the selected operator
        $amount_field = $operator . '_value';
        $amount = isset($settings[$amount_field]) ? (float) $settings[$amount_field] : 0.0;
        $order_total = $amount_source === 'order_discount'
            ? (float) $order->get_discount_total()
            : (float) $order->get_total();

        // Use WooCommerce decimals and epsilon for equality
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        $epsilon = pow(10, -$decimals);
        $order_total = round($order_total, $decimals);
        $amount = round($amount, $decimals);

        switch ($operator) {
            case 'amount_gt':
                return $order_total > $amount;
            case 'amount_lt':
                return $order_total < $amount;
            case 'amount_eq':
                return abs($order_total - $amount) <= $epsilon;
            case 'amount_ne':
                return abs($order_total - $amount) > $epsilon;
            case 'amount_gte':
                return $order_total >= $amount;
            case 'amount_lte':
                return $order_total <= $amount;
            default:
                return false;
        }
    }
}
