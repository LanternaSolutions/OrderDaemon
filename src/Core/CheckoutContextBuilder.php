<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use WC_Order;

/**
 * Checkout Context Builder
 * 
 * Shared utility class to build rich context data for both
 * standard and block checkout flows.
 *
 * This allows us to maintain a single source of truth for context building
 * logic that can be used by both traditional checkout and block checkout
 * flows, ensuring consistent data structures and renderer behavior.
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 */
class CheckoutContextBuilder
{
    /**
     * Build comprehensive checkout context for any checkout type
     *
     * @param WC_Order $order Order object
     * @param string $checkout_type The checkout type ('standard' or 'block_checkout')
     * @return array<string, mixed> Comprehensive checkout context
     */
    public static function buildCheckoutContext(WC_Order $order, string $checkout_type = 'standard'): array
    {
        $context = [
            'checkout_type'     => $checkout_type,
            'capture_timestamp' => wp_date('c'),
            'order_id'          => $order->get_id(),
        ];

        // Cart analysis
        try {
            $context['cart_analysis'] = self::analyzeCartComposition($order);
        } catch (\Throwable $e) {
            $context['cart_analysis'] = [
                'error' => 'cart_analysis_failed: ' . $e->getMessage(),
            ];
        }

        // Payment context
        try {
            $context['payment_context'] = self::capturePaymentContext($order);
        } catch (\Throwable $e) {
            $context['payment_context'] = [
                'error' => 'payment_context_failed: ' . $e->getMessage(),
            ];
        }

        // Shipping analysis
        try {
            $context['shipping_analysis'] = self::analyzeShippingRequirements($order);
        } catch (\Throwable $e) {
            $context['shipping_analysis'] = [
                'error' => 'shipping_analysis_failed: ' . $e->getMessage(),
            ];
        }

        // Customer context
        try {
            $context['customer_context'] = self::captureCustomerContext($order);
        } catch (\Throwable $e) {
            $context['customer_context'] = [
                'error' => 'customer_context_failed: ' . $e->getMessage(),
            ];
        }

        // Technical context
        try {
            $context['technical_context'] = self::captureTechnicalContext($checkout_type);
        } catch (\Throwable $e) {
            $context['technical_context'] = [
                'error' => 'technical_context_failed: ' . $e->getMessage(),
            ];
        }

        return $context;
    }

    /**
     * Analyze cart composition for automation insights.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Cart analysis
     */
    public static function analyzeCartComposition(WC_Order $order): array
    {
        $items = $order->get_items();
        $analysis = [
            'total_items'             => is_array($items) ? count($items) : 0,
            'product_types'           => [],
            'requires_shipping'       => false,
            'has_virtual_products'    => false,
            'has_downloadable_products' => false,
            'mixed_cart'              => false,
        ];

        foreach ($items as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if ($product) {
                $analysis['product_types'][] = $product->get_type();
                if ($product->is_virtual()) {
                    $analysis['has_virtual_products'] = true;
                }
                if ($product->is_downloadable()) {
                    $analysis['has_downloadable_products'] = true;
                }
                if (!$product->is_virtual()) {
                    $analysis['requires_shipping'] = true;
                }
            }
        }

        $analysis['mixed_cart']   = $analysis['has_virtual_products'] && $analysis['requires_shipping'];
        $analysis['product_types'] = array_values(array_unique($analysis['product_types']));
        return $analysis;
    }

    /**
     * Capture payment method and gateway context.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Payment context
     */
    public static function capturePaymentContext(WC_Order $order): array
    {
        return [
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'payment_status'       => $order->get_status(),
            'transaction_id'       => $order->get_transaction_id(),
            'currency'             => $order->get_currency(),
            'total_amount'         => (float) $order->get_total(),
            'gateway_context'      => self::captureGatewaySpecificContext($order),
        ];
    }

    /**
     * Capture gateway-specific context using dynamic discovery and filters.
     *
     * Strategy:
     * - Discover the WC gateway instance for the order's payment method via WC()->payment_gateways()
     *   or WC_Payment_Gateways::instance().
     * - Provide a filter hook per-gateway: odcm_capture_gateway_context_{$gateway_id}
     *   allowing gateways or site code to add precise context without core changes.
     * - Fallback: scan common transaction-related order meta keys to provide useful context
     *   even if the gateway doesn't implement a filter.
     * - Gracefully handle cases where WooCommerce is not loaded or gateways are unavailable.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Gateway-specific context
     */
    public static function captureGatewaySpecificContext(WC_Order $order): array
    {
        $gateway_id = (string) $order->get_payment_method();
        $context = [
            'payment_method' => $gateway_id,
            'gateway_id'     => $gateway_id,
        ];

        $gateway_instance = null;
        // Dynamically discover gateway instance
        try {
            if (function_exists('WC') && is_object(WC()) && method_exists(WC(), 'payment_gateways')) {
                $pg = \WC()->payment_gateways();
                if ($pg && method_exists($pg, 'get_available_payment_gateways')) {
                    $available = $pg->get_available_payment_gateways();
                    if (is_array($available) && isset($available[$gateway_id])) {
                        $gateway_instance = $available[$gateway_id];
                    }
                }
            }
            if (!$gateway_instance && class_exists('WC_Payment_Gateways')) {
                $inst = \WC_Payment_Gateways::instance();
                if ($inst && method_exists($inst, 'payment_gateways')) {
                    $all = $inst->payment_gateways();
                    if (is_array($all) && isset($all[$gateway_id])) {
                        $gateway_instance = $all[$gateway_id];
                    }
                }
            }
        } catch (\Throwable $e) {
            $context['discovery_error'] = 'gateway_discovery_failed: ' . $e->getMessage();
        }

        if (is_object($gateway_instance)) {
            // Enrich with generic gateway info
            try {
                if (property_exists($gateway_instance, 'title') && !empty($gateway_instance->title)) {
                    $context['gateway_title'] = (string) $gateway_instance->title;
                }
                $context['gateway_class'] = get_class($gateway_instance);
                if (method_exists($gateway_instance, 'supports')) {
                    $context['supports'] = (array) $gateway_instance->supports;
                }
            } catch (\Throwable $e) {
                $context['gateway_info_error'] = 'gateway_info_failed: ' . $e->getMessage();
            }
        }

        // Allow gateways/plugins to provide their own detailed context
        if (function_exists('apply_filters')) {
            $filter_name = 'odcm_capture_gateway_context_' . sanitize_key($gateway_id);
            try {
                $filtered = apply_filters($filter_name, $context, $order, $gateway_instance);
                if (is_array($filtered)) {
                    $context = $filtered;
                }
            } catch (\Throwable $e) {
                $context['filter_error'] = 'gateway_filter_failed: ' . $e->getMessage();
            }
        }

        // Generic meta scanning fallback for common transaction keys
        $meta_keys = [
            '_transaction_id',
            '_intent_id',
            '_charge_id',
            '_authorization_id',
            '_capture_id',
            '_payment_id',
            '_payment_token',
            '_paypal_transaction_id',
            '_ppcp_order_id',
            '_stripe_intent_id',
            '_stripe_charge_id',
            '_wcpay_intent_id',
        ];
        $meta_context = [];
        foreach ($meta_keys as $key) {
            try {
                $val = $order->get_meta($key, true);
                if ($val !== '' && $val !== null) {
                    $meta_context[$key] = $val;
                }
            } catch (\Throwable $e) {
                // ignore per-key errors
            }
        }
        if (!empty($meta_context)) {
            if (!isset($context['meta']) || !is_array($context['meta'])) {
                $context['meta'] = $meta_context;
            } else {
                // Merge without overwriting existing keys
                foreach ($meta_context as $k => $v) {
                    if (!array_key_exists($k, $context['meta'])) {
                        $context['meta'][$k] = $v;
                    }
                }
            }
        }

        // Ensure transaction_id surfaced if available
        try {
            $tx = $order->get_transaction_id();
            if (!empty($tx) && empty($context['transaction_id'])) {
                $context['transaction_id'] = $tx;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $context;
    }

    /**
     * Analyze shipping requirements including chosen methods and address presence.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Shipping analysis
     */
    public static function analyzeShippingRequirements(WC_Order $order): array
    {
        $needs_shipping = $order->get_shipping_total() > 0 || $order->needs_shipping_address();

        $shipping_methods = [];
        foreach ($order->get_shipping_methods() as $method) {
            $shipping_methods[] = [
                'id'    => $method->get_id(),
                'method_id' => method_exists($method, 'get_method_id') ? $method->get_method_id() : null,
                'total' => (float) $method->get_total(),
            ];
        }

        $shipping_address = [
            'country' => $order->get_shipping_country(),
            'state'   => $order->get_shipping_state(),
            'postcode'=> $order->get_shipping_postcode(),
            'city'    => $order->get_shipping_city(),
        ];

        return [
            'requires_shipping' => $needs_shipping,
            'shipping_methods'  => $shipping_methods,
            'has_shipping_address' => !empty($order->get_shipping_address_1()),
            'shipping_address'  => $shipping_address,
        ];
    }

    /**
     * Capture customer context during checkout.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Customer context
     */
    public static function captureCustomerContext(WC_Order $order): array
    {
        $user_id = (int) $order->get_user_id();
        return [
            'is_guest'      => $user_id === 0,
            'user_id'       => $user_id,
            'email'         => $order->get_billing_email(),
            'first_name'    => $order->get_billing_first_name(),
            'last_name'     => $order->get_billing_last_name(),
            'billing_phone' => $order->get_billing_phone(),
        ];
    }

    /**
     * Capture technical context: platform versions and indicators.
     *
     * @param string $checkout_type The checkout type ('standard' or 'block_checkout')
     * @return array<string, mixed> Technical context
     */
    public static function captureTechnicalContext(string $checkout_type = 'standard'): array
    {
        $wc_version = function_exists('get_option') ? get_option('woocommerce_version') : null;
        $wp_version = function_exists('get_bloginfo') ? get_bloginfo('version') : null;

        $blocks_version = null;
        if (class_exists('Automattic\\WooCommerce\\Blocks\\Package')) {
            try {
                $blocks_version = \Automattic\WooCommerce\Blocks\Package::get_version();
            } catch (\Throwable $e) {
                $blocks_version = null;
            }
        }

        return [
            'wp_version'     => $wp_version,
            'wc_version'     => $wc_version,
            'wc_blocks_version' => $blocks_version,
            'checkout_type'  => $checkout_type,
            'is_store_api'   => $checkout_type === 'block_checkout' ? (did_action('woocommerce_store_api_init') > 0) : false,
            'theme'          => function_exists('wp_get_theme') ? wp_get_theme()->get('Name') : null,
        ];
    }
}
