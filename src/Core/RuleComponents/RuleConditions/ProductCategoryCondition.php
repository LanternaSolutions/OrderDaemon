<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleConditions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use WC_Order;

/**
 * A condition that checks if the order contains products from specific categories.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Conditions
 * @since   1.0.0
 */
class ProductCategoryCondition implements ConditionInterface
{
    public function get_id(): string
    {
        return 'product_category';
    }

    public function get_label(): string
    {
        return __('rule_component.condition.product_category.label', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('rule_component.condition.product_category.description', 'order-daemon');
    }


    public function get_settings_schema(): ?array
    {
        // Get available product categories
        $categories = [];
        if (function_exists('get_terms')) {
            $terms = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            ]);

            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[$term->term_id] = $term->name;
                }
            }
        }

        // Add fallback if no categories found
        if (empty($categories)) {
            $categories = [
                '0' => __('rule_component.condition.product_category.no_categories_found', 'order-daemon'),
            ];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'operator' => [
                    'type' => 'string',
                    'title' => __('rule_component.condition.product_category.operator_label', 'order-daemon'),
                    'description' => __('rule_component.condition.product_category.operator_description', 'order-daemon'),
                    'enum' => [
                        'in' => __('rule_component.condition.product_category.operator.in', 'order-daemon'),
                        'not_in' => __('rule_component.condition.product_category.operator.not_in', 'order-daemon'),
                        'all_in' => __('rule_component.condition.product_category.operator.all_in', 'order-daemon'),
                    ],
                    'default' => 'in',
                ],
                'category' => [
                    'type' => 'string',
                    'title' => __('rule_component.condition.product_category.label', 'order-daemon'),
                    'description' => __('rule_component.condition.product_category.field_description', 'order-daemon'),
                    'enum' => $categories,
                    'default' => '0',
                    'ui:widget' => 'select',
                ],
            ],
            'required' => ['category'],
        ];

        return $schema;
    }

    public function evaluate(WC_Order $order, array $settings): bool
    {
        $selected_category = $settings['category'] ?? '';
        $operator = $settings['operator'] ?? 'in';

        if (empty($selected_category)) {
            return true; // No category selected means match all
        }

        $order_items = $order->get_items();
        if (empty($order_items)) {
            return false;
        }

        switch ($operator) {
            case 'all_in':
                return $this->evaluate_all_in($order_items, $selected_category);

            case 'not_in':
                return $this->evaluate_not_in($order_items, $selected_category);

            case 'in':
            default:
                return $this->evaluate_in($order_items, $selected_category);
        }
    }

    /**
     * Evaluate 'in' operator: returns true if ANY product in the order belongs to the selected category.
     */
    private function evaluate_in(array $order_items, string $selected_category): bool
    {
        foreach ($order_items as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $check_product_id = $variation_id ? $variation_id : $product_id;

            $product_categories = wp_get_post_terms($check_product_id, 'product_cat', ['fields' => 'ids']);
            if (is_wp_error($product_categories)) {
                continue;
            }

            foreach ($product_categories as $category_id) {
                if ((string)$category_id === (string)$selected_category) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Evaluate 'not_in' operator: returns true if NO products in the order belong to the selected category.
     */
    private function evaluate_not_in(array $order_items, string $selected_category): bool
    {
        foreach ($order_items as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $check_product_id = $variation_id ? $variation_id : $product_id;

            $product_categories = wp_get_post_terms($check_product_id, 'product_cat', ['fields' => 'ids']);
            if (is_wp_error($product_categories)) {
                continue;
            }

            foreach ($product_categories as $category_id) {
                if ((string)$category_id === (string)$selected_category) {
                    return false; // Found a product in the selected category, so condition fails
                }
            }
        }

        return true; // No products found in the selected category
    }

    /**
     * Evaluate 'all_in' operator: returns true if ALL products in the order belong to the selected category.
     */
    private function evaluate_all_in(array $order_items, string $selected_category): bool
    {
        if (empty($order_items)) {
            return false;
        }

        foreach ($order_items as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $check_product_id = $variation_id ? $variation_id : $product_id;

            $product_categories = wp_get_post_terms($check_product_id, 'product_cat', ['fields' => 'ids']);
            if (is_wp_error($product_categories)) {
                continue;
            }

            // Check if this product belongs to the selected category
            $product_in_category = false;
            foreach ($product_categories as $category_id) {
                if ((string)$category_id === (string)$selected_category) {
                    $product_in_category = true;
                    break;
                }
            }

            // If any product is not in the selected category, the condition fails
            if (!$product_in_category) {
                return false;
            }
        }

        return true; // All products are in the selected category
    }
}
