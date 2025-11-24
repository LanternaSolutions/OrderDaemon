<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

/**
 * Order Meta Manager - HPOS Compatible Order Metadata Operations
 *
 * Provides a unified interface for order metadata operations that automatically
 * detects whether WooCommerce HPOS is enabled and uses the appropriate methods.
 *
 * This abstraction layer ensures backwards compatibility with legacy WordPress
 * post meta while supporting modern HPOS custom order tables.
 *
 * @since 1.1.25
 */
class OrderMetaManager
{
    /**
     * Order object cache to minimize database queries
     *
     * @var array<int, \WC_Order|false>
     */
    private static array $order_cache = [];

    /**
     * HPOS enabled status cache
     *
     * @var bool|null
     */
    private static ?bool $hpos_enabled = null;

    /**
     * Get order metadata value
     *
     * @param int $order_id Order ID
     * @param string $key Metadata key
     * @param bool $single Whether to return a single value (default: true)
     * @return mixed Metadata value or null if not found
     */
    public static function get_meta(int $order_id, string $key, bool $single = true)
    {
        if ($order_id <= 0 || empty($key)) {
            return $single ? null : [];
        }

        try {
            if (self::is_hpos_enabled()) {
                $order = self::get_order_cached($order_id);
                if (!$order) {
                    return $single ? null : [];
                }
                
                $value = $order->get_meta($key, $single);
                return $value !== '' ? $value : ($single ? null : []);
            } else {
                $value = get_post_meta($order_id, $key, $single);
                return $single && empty($value) ? null : $value;
            }
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::get_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage());
            return $single ? null : [];
        }
    }

    /**
     * Update order metadata value
     *
     * @param int $order_id Order ID
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return bool True on success, false on failure
     */
    public static function update_meta(int $order_id, string $key, $value): bool
    {
        if ($order_id <= 0 || empty($key)) {
            return false;
        }

        try {
            if (self::is_hpos_enabled()) {
                $order = self::get_order_cached($order_id);
                if (!$order) {
                    error_log("OrderMetaManager: Could not load order #{$order_id} for metadata update");
                    return false;
                }
                
                $order->update_meta_data($key, $value);
                $order->save();
                return true;
            } else {
                return update_post_meta($order_id, $key, $value) !== false;
            }
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::update_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete order metadata
     *
     * @param int $order_id Order ID
     * @param string $key Metadata key
     * @return bool True on success, false on failure
     */
    public static function delete_meta(int $order_id, string $key): bool
    {
        if ($order_id <= 0 || empty($key)) {
            return false;
        }

        try {
            if (self::is_hpos_enabled()) {
                $order = self::get_order_cached($order_id);
                if (!$order) {
                    error_log("OrderMetaManager: Could not load order #{$order_id} for metadata deletion");
                    return false;
                }
                
                $order->delete_meta_data($key);
                $order->save();
                return true;
            } else {
                return delete_post_meta($order_id, $key) !== false;
            }
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::delete_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add order metadata (allows multiple values for same key)
     *
     * @param int $order_id Order ID
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @param bool $unique Whether the key should be unique (default: false)
     * @return bool True on success, false on failure
     */
    public static function add_meta(int $order_id, string $key, $value, bool $unique = false): bool
    {
        if ($order_id <= 0 || empty($key)) {
            return false;
        }

        try {
            if (self::is_hpos_enabled()) {
                $order = self::get_order_cached($order_id);
                if (!$order) {
                    error_log("OrderMetaManager: Could not load order #{$order_id} for metadata addition");
                    return false;
                }
                
                if ($unique && $order->get_meta($key) !== '') {
                    return false; // Key already exists and unique is required
                }
                
                $order->add_meta_data($key, $value, $unique);
                $order->save();
                return true;
            } else {
                return add_post_meta($order_id, $key, $value, $unique) !== false;
            }
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::add_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get WooCommerce order object
     *
     * @param int $order_id Order ID
     * @return \WC_Order|null Order object or null if not found
     */
    public static function get_order(int $order_id): ?\WC_Order
    {
        if ($order_id <= 0) {
            return null;
        }

        try {
            $order = wc_get_order($order_id);
            return $order instanceof \WC_Order ? $order : null;
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::get_order failed for order #{$order_id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if WooCommerce HPOS is enabled
     *
     * @return bool True if HPOS is enabled, false otherwise
     */
    public static function is_hpos_enabled(): bool
    {
        // Cache the result to avoid repeated checks
        if (self::$hpos_enabled !== null) {
            return self::$hpos_enabled;
        }

        try {
            self::$hpos_enabled = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                                  \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        } catch (\Throwable $e) {
            // If we can't determine HPOS status, assume legacy for safety
            error_log("OrderMetaManager: Could not determine HPOS status, defaulting to legacy: " . $e->getMessage());
            self::$hpos_enabled = false;
        }

        return self::$hpos_enabled;
    }

    /**
     * Get cached order object to minimize database queries
     *
     * @param int $order_id Order ID
     * @return \WC_Order|null Order object or null if not found
     */
    private static function get_order_cached(int $order_id): ?\WC_Order
    {
        if (isset(self::$order_cache[$order_id])) {
            $cached_order = self::$order_cache[$order_id];
            return $cached_order instanceof \WC_Order ? $cached_order : null;
        }

        $order = self::get_order($order_id);
        self::$order_cache[$order_id] = $order ?: false;

        return $order;
    }

    /**
     * Clear order cache for memory management
     *
     * @param int|null $order_id Specific order ID to clear, or null for all
     * @return void
     */
    public static function clear_cache(?int $order_id = null): void
    {
        if ($order_id !== null) {
            unset(self::$order_cache[$order_id]);
        } else {
            self::$order_cache = [];
        }
    }

    /**
     * Get cache statistics for debugging
     *
     * @return array Cache statistics
     */
    public static function get_cache_stats(): array
    {
        $total_entries = count(self::$order_cache);
        $valid_orders = 0;
        $invalid_entries = 0;

        foreach (self::$order_cache as $cached_item) {
            if ($cached_item instanceof \WC_Order) {
                $valid_orders++;
            } else {
                $invalid_entries++;
            }
        }

        return [
            'total_entries' => $total_entries,
            'valid_orders' => $valid_orders,
            'invalid_entries' => $invalid_entries,
            'hpos_enabled' => self::$hpos_enabled,
        ];
    }

    /**
     * Batch update multiple metadata keys for the same order
     * More efficient for HPOS as it saves only once at the end
     *
     * @param int $order_id Order ID
     * @param array $meta_updates Associative array of key => value pairs
     * @return bool True if all updates succeeded, false otherwise
     */
    public static function batch_update_meta(int $order_id, array $meta_updates): bool
    {
        if ($order_id <= 0 || empty($meta_updates)) {
            return false;
        }

        try {
            if (self::is_hpos_enabled()) {
                $order = self::get_order_cached($order_id);
                if (!$order) {
                    error_log("OrderMetaManager: Could not load order #{$order_id} for batch metadata update");
                    return false;
                }
                
                // Update all metadata fields
                foreach ($meta_updates as $key => $value) {
                    if (is_string($key) && !empty($key)) {
                        $order->update_meta_data($key, $value);
                    }
                }
                
                // Save once at the end for efficiency
                $order->save();
                return true;
            } else {
                // For legacy, update each field individually
                $all_success = true;
                foreach ($meta_updates as $key => $value) {
                    if (is_string($key) && !empty($key)) {
                        if (update_post_meta($order_id, $key, $value) === false) {
                            $all_success = false;
                        }
                    }
                }
                return $all_success;
            }
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::batch_update_meta failed for order #{$order_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find order ID by metadata key and value
     * HPOS Compatible: Uses appropriate WooCommerce functions for both storage systems
     * 
     * @param string $meta_key Metadata key to search for
     * @param string $meta_value Metadata value to search for
     * @param array $additional_args Additional arguments for the search (optional)
     * @return int|null Order ID if found, null otherwise
     */
    public static function find_order_by_meta(string $meta_key, string $meta_value, array $additional_args = []): ?int
    {
        if (empty($meta_key) || empty($meta_value)) {
            return null;
        }

        if (!function_exists('wc_get_orders')) {
            return null;
        }

        try {
            $search_args = array_merge([
                'meta_key'   => $meta_key,
                'meta_value' => $meta_value,
                'limit'      => 1,
                'status'     => 'any',
                'return'     => 'ids'
            ], $additional_args);

            $orders = wc_get_orders($search_args);

            if (!empty($orders) && is_array($orders)) {
                return (int) $orders[0];
            }
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::find_order_by_meta failed for key '{$meta_key}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Find order IDs by metadata key and value (multiple results)
     * HPOS Compatible: Uses appropriate WooCommerce functions for both storage systems
     * 
     * @param string $meta_key Metadata key to search for
     * @param string $meta_value Metadata value to search for
     * @param int $limit Maximum number of results (default: 10)
     * @param array $additional_args Additional arguments for the search (optional)
     * @return int[] Array of order IDs
     */
    public static function find_orders_by_meta(string $meta_key, string $meta_value, int $limit = 10, array $additional_args = []): array
    {
        if (empty($meta_key) || empty($meta_value)) {
            return [];
        }

        if (!function_exists('wc_get_orders')) {
            return [];
        }

        try {
            $search_args = array_merge([
                'meta_key'   => $meta_key,
                'meta_value' => $meta_value,
                'limit'      => max(1, $limit),
                'status'     => 'any',
                'return'     => 'ids'
            ], $additional_args);

            $orders = wc_get_orders($search_args);

            if (!empty($orders) && is_array($orders)) {
                return array_map('intval', $orders);
            }
        } catch (\Throwable $e) {
            error_log("OrderMetaManager::find_orders_by_meta failed for key '{$meta_key}': " . $e->getMessage());
        }

        return [];
    }

    /**
     * Find order by transaction ID from various payment gateway meta fields
     * HPOS Compatible: Searches multiple common transaction ID fields
     * 
     * @param string $transaction_id Transaction ID to search for
     * @return int|null Order ID if found, null otherwise
     */
    public static function find_order_by_transaction_id(string $transaction_id): ?int
    {
        if (empty($transaction_id)) {
            return null;
        }

        // Common transaction ID meta keys used by payment gateways
        $transaction_meta_keys = [
            '_transaction_id',           // Standard WooCommerce transaction ID
            '_stripe_charge_id',         // Stripe charge ID
            '_stripe_payment_intent_id', // Stripe payment intent ID
            '_paypal_transaction_id',    // PayPal transaction ID
            '_gateway_transaction_id',   // Generic gateway transaction ID
        ];

        foreach ($transaction_meta_keys as $meta_key) {
            $order_id = self::find_order_by_meta($meta_key, $transaction_id);
            if ($order_id) {
                return $order_id;
            }
        }

        return null;
    }

    /**
     * Find subscription by gateway subscription ID
     * HPOS Compatible: Searches for subscriptions using WooCommerce Subscriptions functions
     * Note: Subscriptions remain as custom posts even with HPOS enabled for orders
     * 
     * @param string $gateway_subscription_id Gateway subscription identifier
     * @return int|null Subscription ID if found, null otherwise
     */
    public static function find_subscription_by_gateway_id(string $gateway_subscription_id): ?int
    {
        if (empty($gateway_subscription_id) || !function_exists('wcs_get_subscriptions')) {
            return null;
        }

        // Common gateway subscription ID meta keys
        $subscription_meta_keys = [
            '_paypal_subscription_id',
            '_stripe_subscription_id',
            '_gateway_subscription_id'
        ];

        foreach ($subscription_meta_keys as $meta_key) {
            try {
                $subscriptions = wcs_get_subscriptions([
                    'meta_key'            => $meta_key,
                    'meta_value'          => $gateway_subscription_id,
                    'posts_per_page'      => 1,
                    'subscription_status' => 'any'
                ]);

                if (!empty($subscriptions)) {
                    foreach ($subscriptions as $subscription) {
                        if ($subscription && method_exists($subscription, 'get_id')) {
                            return (int) $subscription->get_id();
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("OrderMetaManager::find_subscription_by_gateway_id failed for key '{$meta_key}': " . $e->getMessage());
                continue;
            }
        }

        return null;
    }
}
