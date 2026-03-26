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

        return [
            'type' => 'object',
            'properties' => [
                'operator' => [
                    'type'        => 'string',
                    'title'       => __('rule_component.condition.product_category.operator_label', 'order-daemon'),
                    'description' => __('rule_component.condition.product_category.operator_description', 'order-daemon'),
                    'enum'        => [
                        'in'     => __('rule_component.condition.product_category.operator.in', 'order-daemon'),
                        'not_in' => __('rule_component.condition.product_category.operator.not_in', 'order-daemon'),
                        'all_in' => __('rule_component.condition.product_category.operator.all_in', 'order-daemon'),
                    ],
                    'default' => 'in',
                    'ui:widget' => 'button_radio_group',
                ],
                'categories' => [
                    'type'        => 'array',
                    'title'       => __('rule_component.condition.product_category.label', 'order-daemon'),
                    'description' => __('rule_component.condition.product_category.field_description', 'order-daemon'),
                    'items'       => [
                        'type' => 'string',
                        'enum' => $categories,
                    ],
                    'ui:widget'     => 'searchable_checkboxes',
                    'ui:searchable' => true,
                    'ui:placeholder' => __('rule_component.condition.product_category.search_placeholder', 'order-daemon'),
                    'default' => [],
                ],
            ],
            'required' => ['categories'],
        ];
    }

    public function evaluate(WC_Order $order, array $settings): bool
    {
        $selected_categories = array_filter((array) ($settings['categories'] ?? []));
        $operator            = $settings['operator'] ?? 'in';

        if (empty($selected_categories)) {
            return true; // No categories selected means match all
        }

        $order_items = $order->get_items();
        if (empty($order_items)) {
            return false;
        }

        switch ($operator) {
            case 'all_in':
                return $this->evaluate_all_in($order_items, $selected_categories);

            case 'not_in':
                return $this->evaluate_not_in($order_items, $selected_categories);

            case 'in':
            default:
                return $this->evaluate_in($order_items, $selected_categories);
        }
    }

    /**
     * 'in': at least one product in the order belongs to any of the selected categories.
     */
    private function evaluate_in(array $order_items, array $selected_categories): bool
    {
        foreach ($order_items as $item) {
            if ($this->item_in_any_category($item, $selected_categories)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 'not_in': no product in the order belongs to any of the selected categories.
     */
    private function evaluate_not_in(array $order_items, array $selected_categories): bool
    {
        foreach ($order_items as $item) {
            if ($this->item_in_any_category($item, $selected_categories)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 'all_in': every product in the order belongs to at least one of the selected categories.
     */
    private function evaluate_all_in(array $order_items, array $selected_categories): bool
    {
        if (empty($order_items)) {
            return false;
        }

        foreach ($order_items as $item) {
            if (!$this->item_in_any_category($item, $selected_categories)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if the order line-item's product belongs to at least one of $category_ids.
     */
    private function item_in_any_category(\WC_Order_Item $item, array $category_ids): bool
    {
        $product_id   = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $check_id     = $variation_id ?: $product_id;

        $product_categories = wp_get_post_terms($check_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($product_categories)) {
            return false;
        }

        foreach ($product_categories as $cat_id) {
            if (in_array((string) $cat_id, array_map('strval', $category_ids), true)) {
                return true;
            }
        }

        return false;
    }
}
