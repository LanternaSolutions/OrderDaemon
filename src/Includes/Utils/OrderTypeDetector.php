<?php
/**
 * Order Type Detector - HPOS-Aware Order Type Detection Utility
 *
 * Provides HPOS-aware order type detection to support both legacy WooCommerce
 * orders and High-Performance Order Storage (HPOS) orders.
 *
 * This utility enables the plugin to work seamlessly with both order storage systems
 * by providing unified order type detection that automatically adapts to the
 * active WooCommerce configuration.
 *
 * @package OrderDaemon\CompletionManager\Includes\Utils
 * @since   1.1.40
 */

declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

/**
 * Order Type Detector Class
 *
 * Handles detection and classification of WooCommerce orders, supporting both
 * legacy post-based orders and HPOS custom table orders.
 */
class OrderTypeDetector
{
    /**
     * Check if an order ID represents a processable order (HPOS or legacy)
     *
     * This method determines whether a given order ID should be processed by the
     * Order Daemon plugin, supporting both traditional WooCommerce orders and
     * HPOS (High-Performance Order Storage) orders.
     *
     * @since 1.1.40
     *
     * @param int $order_id Order ID to check
     * @return bool True if this is a processable order, false otherwise
     */
    public static function is_processable_order(int $order_id): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        $post_type = get_post_type($order_id);

        // Traditional WooCommerce orders
        if ($post_type === 'shop_order') {
            return true;
        }

        // HPOS placeholder orders
        if ($post_type === 'shop_order_placehold') {
            return self::is_hpos_enabled();
        }

        return false;
    }

    /**
     * Check if HPOS is enabled
     *
     * Determines whether WooCommerce High-Performance Order Storage is active
     * by checking the OrderMetaManager's HPOS detection.
     *
     * @since 1.1.40
     *
     * @return bool True if HPOS custom order tables are in use, false otherwise
     */
    public static function is_hpos_enabled(): bool
    {
        return OrderMetaManager::is_hpos_enabled();
    }

    /**
     * Get order type for logging/debugging purposes
     *
     * Returns a human-readable string indicating the order type, useful for
     * logging, debugging, and administrative interfaces.
     *
     * @since 1.1.40
     *
     * @param int $order_id Order ID
     * @return string Order type: 'legacy', 'hpos', or 'unknown'
     */
    public static function get_order_type(int $order_id): string
    {
        $post_type = get_post_type($order_id);

        if ($post_type === 'shop_order') {
            return 'legacy';
        }

        if ($post_type === 'shop_order_placehold' && self::is_hpos_enabled()) {
            return 'hpos';
        }

        return 'unknown';
    }
}
