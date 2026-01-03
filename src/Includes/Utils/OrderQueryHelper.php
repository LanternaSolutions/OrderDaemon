<?php
/**
 * Order Query Helper - HPOS-Aware Order Query Utilities
 *
 * Provides HPOS-compatible order query helpers that automatically detect
 * whether WooCommerce HPOS is enabled and use the appropriate query methods.
 *
 * This utility ensures consistent order querying across the plugin while
 * maintaining full compatibility with both legacy and HPOS order storage systems.
 *
 * @package OrderDaemon\CompletionManager\Includes\Utils
 * @since   1.1.40
 */

declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

/**
 * Order Query Helper Class
 *
 * Provides unified order query methods that work seamlessly with both
 * legacy WooCommerce orders and HPOS custom table orders.
 */
class OrderQueryHelper
{
    /**
     * Get orders using HPOS-aware query
     *
     * This method provides a consistent interface for retrieving orders
     * regardless of whether HPOS is enabled or not.
     *
     * @since 1.1.40
     *
     * @param array $args Query arguments
     * @return array Order IDs
     */
    public static function get_order_ids(array $args = []): array
    {
        $defaults = [
            'status' => ['processing', 'on-hold'],
            'limit' => -1,
            'return' => 'ids',
        ];

        $query_args = wp_parse_args($args, $defaults);

        // Use wc_get_orders() which is HPOS-aware
        return wc_get_orders($query_args);
    }

    /**
     * Find orders by metadata with HPOS support
     *
     * This method provides a consistent interface for finding orders by metadata
     * that works with both legacy and HPOS order storage systems.
     *
     * @since 1.1.40
     *
     * @param string $meta_key Metadata key
     * @param string $meta_value Metadata value
     * @param array $additional_args Additional query args
     * @return array Order IDs
     */
    public static function find_orders_by_metadata(string $meta_key, string $meta_value, array $additional_args = []): array
    {
        return OrderMetaManager::find_orders_by_meta($meta_key, $meta_value, -1, $additional_args);
    }

    /**
     * Get orders by status with HPOS support
     *
     * Provides a consistent way to retrieve orders by status that works
     * with both legacy and HPOS order storage systems.
     *
     * @since 1.1.40
     *
     * @param array $statuses Array of order statuses to query
     * @param int $limit Maximum number of orders to return (-1 for no limit)
     * @return array Order IDs
     */
    public static function get_orders_by_status(array $statuses, int $limit = -1): array
    {
        return self::get_order_ids([
            'status' => $statuses,
            'limit' => $limit,
            'return' => 'ids'
        ]);
    }

    /**
     * Get recent orders with HPOS support
     *
     * Retrieves recently created/modified orders using HPOS-aware queries.
     *
     * @since 1.1.40
     *
     * @param int $limit Maximum number of orders to return
     * @param string $orderby Field to order by ('date', 'modified', 'id')
     * @param string $order Order direction ('ASC' or 'DESC')
     * @return array Order IDs
     */
    public static function get_recent_orders(int $limit = 10, string $orderby = 'date', string $order = 'DESC'): array
    {
        $args = [
            'limit' => $limit,
            'orderby' => $orderby,
            'order' => $order,
            'return' => 'ids'
        ];

        return self::get_order_ids($args);
    }
}
