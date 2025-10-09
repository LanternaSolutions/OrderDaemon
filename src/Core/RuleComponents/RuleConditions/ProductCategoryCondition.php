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
        return __('Product Category', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('Checks if the order contains products from specific categories.', 'order-daemon');
    }

    public function get_capability(): string
    {
        return 'condition_single_category'; // Free tier
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
                '0' => __('No categories found', 'order-daemon'),
            ];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'title' => __('Product Category', 'order-daemon'),
                    'description' => __('Select one product category to match. Pro unlocks multiple categories and advanced logic.', 'order-daemon'),
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
        if (empty($selected_category)) {
            return true; // No category selected means match all
        }

        $order_items = $order->get_items();
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

            foreach ($product_categories as $category_id) {
                if ((string)$category_id === (string)$selected_category) {
                    return true; // Any item matching the single selected category passes
                }
            }
        }

        return false;
    }
}
