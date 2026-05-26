<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;

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
     * Log a debug message using WordPress-compatible logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    private static function logDebugMessage(string $message, string $level = 'debug'): void
    {
        // Only log if debug mode is enabled
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }
        
        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($message, $level);
            return;
        }
        
        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
            return;
        }
        
        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_' . $level, $message);
            return;
        }
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file using safe file operation
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $debug_file = odcm_get_safe_debug_file_path();
            odcm_safe_file_put_contents($debug_file, '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
            return;
        }
    }
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
            self::logDebugMessage("OrderMetaManager::get_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage(), 'error');
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
                    self::logDebugMessage("OrderMetaManager: Could not load order #{$order_id} for metadata update", 'error');
                    return false;
                }
                
                $order->update_meta_data($key, $value);
                $order->save();
                return true;
            } else {
                return update_post_meta($order_id, $key, $value) !== false;
            }
        } catch (\Throwable $e) {
            self::logDebugMessage("OrderMetaManager::update_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage(), 'error');
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
                    self::logDebugMessage("OrderMetaManager: Could not load order #{$order_id} for metadata deletion", 'error');
                    return false;
                }
                
                $order->delete_meta_data($key);
                $order->save();
                return true;
            } else {
                return delete_post_meta($order_id, $key) !== false;
            }
        } catch (\Throwable $e) {
            self::logDebugMessage("OrderMetaManager::delete_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage(), 'error');
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
                    self::logDebugMessage("OrderMetaManager: Could not load order #{$order_id} for metadata addition", 'error');
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
            self::logDebugMessage("OrderMetaManager::add_meta failed for order #{$order_id}, key '{$key}': " . $e->getMessage(), 'error');
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
            self::logDebugMessage("OrderMetaManager::get_order failed for order #{$order_id}: " . $e->getMessage(), 'error');
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
            self::logDebugMessage("OrderMetaManager: Could not determine HPOS status, defaulting to legacy: " . $e->getMessage(), 'error');
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
                    self::logDebugMessage("OrderMetaManager: Could not load order #{$order_id} for batch metadata update", 'error');
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
            self::logDebugMessage("OrderMetaManager::batch_update_meta failed for order #{$order_id}: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Find order ID by metadata key and value
     * HPOS Compatible: Uses appropriate WooCommerce functions for both storage systems
     * 
     * Implements caching to avoid repeated searches for the same key/value pairs
     * Optimized to use direct meta key/value parameters for better performance
     * 
     * @param string $meta_key Metadata key to search for
     * @param string $meta_value Metadata value to search for
     * @param array $additional_args Additional arguments for the search (optional)
     * @return int|null Order ID if found, null otherwise
     */
    public static function find_order_by_meta(string $meta_key, string $meta_value, array $additional_args = []): ?int
    {
        global $wpdb;

        if (empty($meta_key) || empty($meta_value)) {
            return null;
        }

        if (!function_exists('wc_get_orders')) {
            return null;
        }

        // Use DatabaseHelper for optimized caching
        $cache_key = DatabaseHelper::get_var(
            "SELECT cache_key FROM {$wpdb->prefix}options
             WHERE option_name = %s AND option_value = %s 
             LIMIT 1",
            ['odcm_meta_cache_key', $meta_key . '|' . $meta_value]
        );

        if ($cache_key) {
            $cached_result = wp_cache_get($cache_key, 'odcm_meta_cache');
            if ($cached_result !== false) {
                return $cached_result;
            }
        }

        // Check persistent cache for expensive searches using DatabaseHelper
        $should_persist = !isset($additional_args['no_cache']) && strlen($meta_value) > 5;
        $persist_key = 'odcm_meta_search_' . DatabaseHelper::get_var(
            "SELECT cache_key FROM {$wpdb->prefix}options 
             WHERE option_name = %s AND option_value = %s 
             LIMIT 1",
            ['odcm_meta_cache_key', $meta_key . '|' . $meta_value]
        );

        if ($should_persist && $persist_key) {
            $cached_result = wp_cache_get($persist_key, 'odcm_meta_cache');
            if ($cached_result !== false) {
                // Store in static cache and return
                $meta_search_cache[$cache_key] = (null === $cached_result) ? null : (int)$cached_result;
                return $meta_search_cache[$cache_key];
            }
        }

        try {
            // For common transaction ID keys, try HPOS direct query first
            if (self::is_hpos_enabled() && in_array($meta_key, ['_transaction_id', '_stripe_charge_id', '_paypal_transaction_id'])) {
                $result = self::find_order_by_meta_hpos_optimized($meta_key, $meta_value, $additional_args);
                if ($result !== null) {
                    $meta_search_cache[$cache_key] = $result;
                    if ($should_persist) {
                        wp_cache_set($persist_key, $result, '', 15 * MINUTE_IN_SECONDS);
                    }
                    return $result;
                }
            }
            
            // Use DatabaseHelper for optimized direct database queries
            $wpdb = DatabaseHelper::get_wpdb();
            $table = self::is_hpos_enabled() ? $wpdb->prefix . 'wc_orders' : $wpdb->prefix . 'posts';
            $meta_table = self::is_hpos_enabled() ? $wpdb->prefix . 'wc_ordermeta' : $wpdb->prefix . 'postmeta';

            $query = "SELECT pm.post_id FROM {$meta_table} pm
                      JOIN {$table} p ON pm.post_id = p.ID
                      WHERE pm.meta_key = %s AND pm.meta_value = %s
                      AND p.post_type = 'shop_order'
                      LIMIT 1";

            $result = DatabaseHelper::get_var($query, [$meta_key, $meta_value]);
            
            // Cache the result
            $meta_search_cache[$cache_key] = $result;
            
            // Persist expensive searches
            if ($should_persist) {
                wp_cache_set($persist_key, $result, '', 15 * MINUTE_IN_SECONDS);
            }
            
            return $result;
        } catch (\Throwable $e) {
            self::logDebugMessage("OrderMetaManager::find_order_by_meta failed for key '{$meta_key}': " . $e->getMessage(), 'error');
        }

        // Cache negative result too to avoid repeated failed searches
        $meta_search_cache[$cache_key] = null;
        if ($should_persist) {
            wp_cache_set($persist_key, null, '', 5 * MINUTE_IN_SECONDS); // Shorter cache for misses
        }
        
        return null;
    }

    /**
     * Find order IDs by metadata key and value (multiple results)
     * HPOS Compatible: Uses appropriate WooCommerce functions for both storage systems
     * 
     * Implements caching to avoid repeated searches for the same key/value pairs
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
        
        // Use DatabaseHelper for optimized caching
        $cache_key = DatabaseHelper::get_var(
            "SELECT cache_key FROM {$wpdb->prefix}options 
             WHERE option_name = %s AND option_value = %s 
             LIMIT 1",
            ['odcm_meta_cache_key', $meta_key . '|' . $meta_value . '|' . $limit]
        );

        if ($cache_key) {
            $cached_result = wp_cache_get($cache_key, 'odcm_meta_cache');
            if ($cached_result !== false) {
                return $cached_result;
            }
        }

        // Check persistent cache for expensive searches
        $should_persist = !isset($additional_args['no_cache']) && strlen($meta_value) > 5 && $limit > 1;
        $persist_key = 'odcm_multi_meta_search_' . DatabaseHelper::get_var(
            "SELECT cache_key FROM {$wpdb->prefix}options 
             WHERE option_name = %s AND option_value = %s 
             LIMIT 1",
            ['odcm_meta_cache_key', $meta_key . '|' . $meta_value . '|' . $limit]
        );

        if ($should_persist && $persist_key) {
            $cached_result = wp_cache_get($persist_key, 'odcm_meta_cache');
            if ($cached_result !== false) {
                // Store in static cache and return
                $multi_meta_search_cache[$cache_key] = $cached_result;
                return $cached_result;
            }
        }

        try {
            // Use DatabaseHelper for optimized direct database queries
            $wpdb = DatabaseHelper::get_wpdb();
            $table = self::is_hpos_enabled() ? $wpdb->prefix . 'wc_orders' : $wpdb->prefix . 'posts';
            $meta_table = self::is_hpos_enabled() ? $wpdb->prefix . 'wc_ordermeta' : $wpdb->prefix . 'postmeta';

            $query = "SELECT pm.post_id FROM {$meta_table} pm
                      JOIN {$table} p ON pm.post_id = p.ID
                      WHERE pm.meta_key = %s AND pm.meta_value = %s
                      AND p.post_type = 'shop_order'
                      LIMIT %d";

            $result = DatabaseHelper::get_col($query, [$meta_key, $meta_value, $limit]);

            // Cache the result
            $multi_meta_search_cache[$cache_key] = $result;

            // Persist expensive searches
            if ($should_persist) {
                wp_cache_set($persist_key, $result, '', 15 * MINUTE_IN_SECONDS);
            }

            return $result;
        } catch (\Throwable $e) {
            self::logDebugMessage("OrderMetaManager::find_orders_by_meta failed for key '{$meta_key}': " . $e->getMessage(), 'error');
        }

        // Cache empty result too to avoid repeated failed searches
        $empty_result = [];
        $multi_meta_search_cache[$cache_key] = $empty_result;
        if ($should_persist) {
            wp_cache_set($persist_key, $empty_result, '', 5 * MINUTE_IN_SECONDS); // Shorter cache for misses
        }

        return [];
    }

    /**
     * Find order by transaction ID from various payment gateway meta fields
     * HPOS Compatible: Searches multiple common transaction ID fields
     * 
     * Implements multi-level caching for high performance transaction lookups
     * 
     * @param string $transaction_id Transaction ID to search for
     * @return int|null Order ID if found, null otherwise
     */
    public static function find_order_by_transaction_id(string $transaction_id): ?int
    {
        if (empty($transaction_id)) {
            return null;
        }
        
        // Transaction IDs are often used in webhooks, so implement dedicated caching
        static $transaction_cache = [];
        $cache_key = 'txn_' . md5($transaction_id);
        
        if (isset($transaction_cache[$cache_key])) {
            return $transaction_cache[$cache_key];
        }
        
        // Check persistent cache for transaction ID lookups
        $persist_key = 'odcm_order_txn_' . $cache_key;
        $cached_result = wp_cache_get($persist_key);
        if (false !== $cached_result) {
            // Store in static cache and return
            $transaction_cache[$cache_key] = (null === $cached_result) ? null : (int)$cached_result;
            return $transaction_cache[$cache_key];
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
            // Pass no_cache to prevent redundant sub-caching in find_order_by_meta
            $order_id = self::find_order_by_meta($meta_key, $transaction_id, ['no_cache' => true]);
            if ($order_id) {
                // Cache the successful result
                $transaction_cache[$cache_key] = $order_id;
                wp_cache_set($persist_key, $order_id, '', DAY_IN_SECONDS); // Long cache - transaction IDs are permanent
                return $order_id;
            }
        }
        
        // Cache negative result to prevent repeated lookups
        $transaction_cache[$cache_key] = null;
        wp_cache_set($persist_key, null, '', HOUR_IN_SECONDS); // Cache negative results for less time

        return null;
    }

    /**
     * Find subscription by gateway subscription ID
     * HPOS Compatible: Searches for subscriptions using WooCommerce Subscriptions functions
     * Note: Subscriptions remain as custom posts even with HPOS enabled for orders
     * 
     * Uses multi-level caching to avoid repeated searches for the same gateway ID
     * 
     * @param string $gateway_subscription_id Gateway subscription identifier
     * @return int|null Subscription ID if found, null otherwise
     */
    public static function find_subscription_by_gateway_id(string $gateway_subscription_id): ?int
    {
        if (empty($gateway_subscription_id) || !function_exists('wcs_get_subscriptions')) {
            return null;
        }
        
        // Use DatabaseHelper for optimized caching
        $cache_key = DatabaseHelper::get_var(
            "SELECT cache_key FROM {$wpdb->prefix}options 
             WHERE option_name = %s AND option_value = %s 
             LIMIT 1",
            ['odcm_subscription_cache_key', $gateway_subscription_id]
        );

        if ($cache_key) {
            $cached_result = wp_cache_get($cache_key, 'odcm_subscription_cache');
            if ($cached_result !== false) {
                return $cached_result;
            }
        }

        // Check persistent cache
        $persist_key = 'odcm_subscription_' . DatabaseHelper::get_var(
            "SELECT cache_key FROM {$wpdb->prefix}options 
             WHERE option_name = %s AND option_value = %s 
             LIMIT 1",
            ['odcm_subscription_cache_key', $gateway_subscription_id]
        );

        if ($persist_key) {
            $cached_result = wp_cache_get($persist_key, 'odcm_subscription_cache');
            if ($cached_result !== false) {
                // Store in static cache and return
                $subscription_cache[$cache_key] = (null === $cached_result) ? null : (int)$cached_result;
                return $subscription_cache[$cache_key];
            }
        }

        // Common gateway subscription ID meta keys
        $subscription_meta_keys = [
            '_paypal_subscription_id',
            '_stripe_subscription_id',
            '_gateway_subscription_id'
        ];

        foreach ($subscription_meta_keys as $meta_key) {
            try {
                // Use DatabaseHelper for optimized direct database queries
                $wpdb = DatabaseHelper::get_wpdb();
                $table = self::is_hpos_enabled() ? $wpdb->prefix . 'wc_orders' : $wpdb->prefix . 'posts';
                $meta_table = self::is_hpos_enabled() ? $wpdb->prefix . 'wc_ordermeta' : $wpdb->prefix . 'postmeta';

                $query = "SELECT pm.post_id FROM {$meta_table} pm
                          JOIN {$table} p ON pm.post_id = p.ID
                          WHERE pm.meta_key = %s AND pm.meta_value = %s
                          AND p.post_type = 'shop_subscription'
                          LIMIT 1";

                $result = DatabaseHelper::get_var($query, [$meta_key, $gateway_subscription_id]);

                if ($result) {
                    // Cache the successful result
                    $subscription_cache[$cache_key] = $result;
                    wp_cache_set($persist_key, $result, '', HOUR_IN_SECONDS); // Longer cache for subscriptions which change less frequently

                    return $result;
                }
            } catch (\Throwable $e) {
                self::logDebugMessage("OrderMetaManager::find_subscription_by_gateway_id failed for key '{$meta_key}': " . $e->getMessage(), 'error');
                continue;
            }
        }

        // Cache the negative result to prevent repeated searches
        $subscription_cache[$cache_key] = null;
        wp_cache_set($persist_key, null, '', 5 * MINUTE_IN_SECONDS); // Shorter cache for misses

        return null;
    }
}
