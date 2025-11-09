<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleConditions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use WC_Order;

/**
 * A condition that checks the type of products in the order.
 *
 * This is the core feature of the plugin, supporting both free tier
 * (virtual/downloadable) and premium tier (all other product types).
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Conditions
 * @since   1.0.0
 */
class ProductTypeCondition implements ConditionInterface
{
    public function get_id(): string
    {
        return 'product_type';
    }

    public function get_label(): string
    {
        return __('rule_component.condition.product_type.label', 'order-daemon');
    }

    public function get_description(): string
    {
        return __('rule_component.condition.product_type.description', 'order-daemon');
    }

    public function get_capability(): string
    {
        return 'condition_product_type'; // Free tier - but premium types require higher capability
    }

    public function get_settings_schema(): ?array
    {
        // Get all available product types dynamically
        $product_types = $this->get_available_product_types();
        
        // In the free plugin, premium product types are shown but disabled in UI.
        $can_access_premium = false;
        
        // Get the list of premium product types to mark them properly
        // but don't filter them out - we want to show all types
        $premium_types = $this->get_premium_product_types();
        
        return [
            'type' => 'object',
            'properties' => [
                'types' => [
                    'type' => 'array',
                    'title' => __('rule_component.condition.product_type.field_label', 'order-daemon'),
                    'description' => $can_access_premium 
                        ? __('rule_component.condition.product_type.field_description', 'order-daemon')
                        : __('rule_component.condition.product_type.field_description_free', 'order-daemon'),
                    'items' => [
                        'type' => 'string',
                        'enum' => $product_types, // Include ALL product types, even premium ones
                    ],
                    'ui:widget' => 'searchable_checkboxes',
                    'ui:searchable' => true,
                    'ui:placeholder' => __('rule_component.condition.product_type.search_placeholder', 'order-daemon'),
                    'ui:premium_options' => $can_access_premium ? [] : $this->get_premium_product_types(), // Mark premium options
                    'ui:show_all_but_disable_premium' => true, // Flag to show all but disable premium options
                    'default' => ['virtual', 'downloadable'], // Default to free options
                ],
                'match_mode' => [
                    'type' => 'string',
                    'title' => __('rule_component.condition.product_type.match_mode_label', 'order-daemon'),
                    'description' => __('rule_component.condition.product_type.match_mode_description', 'order-daemon'),
                    'enum' => [
                        'all' => __('rule_component.condition.product_type.match_mode.all', 'order-daemon'),
                        'any' => __('rule_component.condition.product_type.match_mode.any', 'order-daemon'),
                        'none' => __('rule_component.condition.product_type.match_mode.none', 'order-daemon'),
                    ],
                    'default' => 'all',
                ],
            ],
            'required' => ['types'],
        ];
    }

    public function evaluate(WC_Order $order, array $settings): bool
    {
        $required_types = $settings['types'] ?? [];
        $match_mode = $settings['match_mode'] ?? 'all';
        
        if (empty($required_types)) {
            return true; // No types selected, condition passes
        }

        $order_items = $order->get_items();
        if (empty($order_items)) {
            return false; // No items in order
        }

        $matching_products = 0;
        $total_products = 0;

        foreach ($order_items as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $total_products++;
            $product_matches = $this->product_matches_types($product, $required_types);
            
            if ($product_matches) {
                $matching_products++;
            }

            // Early exit optimizations
            if ($match_mode === 'any' && $product_matches) {
                return true; // Found at least one match
            }
            
            if ($match_mode === 'all' && !$product_matches) {
                return false; // Found a non-match when all must match
            }
            
            if ($match_mode === 'none' && $product_matches) {
                return false; // Found a match when none should match
            }
        }

        // Final evaluation based on match mode
        switch ($match_mode) {
            case 'all':
                return $matching_products === $total_products;
            case 'any':
                return $matching_products > 0;
            case 'none':
                return $matching_products === 0;
            default:
                return false;
        }
    }

    /**
     * Get all available product types with proper labels.
     *
     * @return array Associative array of product type ID => label
     */
    private function get_available_product_types(): array
    {
        $types = [];

        // Free tier types (always available)
        $types['virtual'] = __('rule_component.condition.product_type.option.virtual', 'order-daemon');
        $types['downloadable'] = __('rule_component.condition.product_type.option.downloadable', 'order-daemon');

        // Get WooCommerce product types (premium tier)
        if (function_exists('wc_get_product_types')) {
            $wc_types = wc_get_product_types();
            
            foreach ($wc_types as $type_id => $type_label) {
                // Skip virtual/downloadable as they're handled separately
                if (in_array($type_id, ['virtual', 'downloadable'])) {
                    continue;
                }
                
                $types[$type_id] = $type_label;
            }
        } else {
            // Fallback for standard WooCommerce types
            $types['simple'] = __('rule_component.condition.product_type.option.simple', 'order-daemon');
            $types['variable'] = __('rule_component.condition.product_type.option.variable', 'order-daemon');
            $types['grouped'] = __('rule_component.condition.product_type.option.grouped', 'order-daemon');
            $types['external'] = __('rule_component.condition.product_type.option.external', 'order-daemon');
        }

        // Check for additional product types from plugins
        $custom_types = $this->get_custom_product_types();
        $types = array_merge($types, $custom_types);

        return $types;
    }


    /**
     * Get premium product types (excluding free ones).
     *
     * @return array List of premium product type IDs
     */
    private function get_premium_product_types(): array
    {
        $premium_types = [];
        
        // Define free types - only virtual and downloadable are free
        $free_types = ['virtual', 'downloadable'];
        
        // Get WooCommerce product types (premium tier)
        if (function_exists('wc_get_product_types')) {
            $wc_types = wc_get_product_types();
            
            foreach ($wc_types as $type_id => $type_label) {
                // Skip virtual/downloadable as they're free
                if (!in_array($type_id, $free_types)) {
                    $premium_types[] = $type_id;
                }
            }
        } else {
            // Fallback for standard WooCommerce types (these are premium)
            $premium_types = ['simple', 'variable', 'grouped', 'external'];
        }

        // Add custom product types from plugins (these are premium)
        $custom_types = array_keys($this->get_custom_product_types());
        $premium_types = array_merge($premium_types, $custom_types);
        
        return array_unique($premium_types);
    }

    /**
     * Get custom product types from plugins.
     *
     * @return array Custom product types
     */
    private function get_custom_product_types(): array
    {
        $custom_types = [];

        // Check for popular plugin product types
        $plugin_checks = [
            // WooCommerce Subscriptions
            'subscription' => __('rule_component.condition.product_type.option.subscription', 'order-daemon'),
            'variable-subscription' => __('rule_component.condition.product_type.option.variable_subscription', 'order-daemon'),
            
            // WooCommerce Bookings
            'booking' => __('rule_component.condition.product_type.option.booking', 'order-daemon'),
            
            // WooCommerce Memberships
            'membership' => __('rule_component.condition.product_type.option.membership', 'order-daemon'),
            
            // WooCommerce Product Bundles
            'bundle' => __('rule_component.condition.product_type.option.bundle', 'order-daemon'),
            
            // WooCommerce Composite Products
            'composite' => __('rule_component.condition.product_type.option.composite', 'order-daemon'),
        ];

        foreach ($plugin_checks as $type_id => $type_label) {
            if (function_exists('wc_get_product_types')) {
                $wc_types = wc_get_product_types();
                if (isset($wc_types[$type_id])) {
                    $custom_types[$type_id] = $wc_types[$type_id];
                }
            }
        }

        return $custom_types;
    }

    /**
     * Check if a product matches any of the required types.
     *
     * @param \WC_Product $product The product to check
     * @param array $required_types Array of required type IDs
     * @return bool True if product matches any required type
     */
    private function product_matches_types(\WC_Product $product, array $required_types): bool
    {
        // Check virtual/downloadable properties
        if (in_array('virtual', $required_types) && $product->is_virtual()) {
            return true;
        }
        
        if (in_array('downloadable', $required_types) && $product->is_downloadable()) {
            return true;
        }

        // Check product type
        $product_type = $product->get_type();
        if (in_array($product_type, $required_types)) {
            return true;
        }

        return false;
    }
}
