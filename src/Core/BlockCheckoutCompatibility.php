<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use WC_Order;

/**
 * Block Checkout Compatibility layer (observation-only).
 *
 * Hooks into WooCommerce Blocks (Store API) checkout lifecycle to capture early checkout
 * context for diagnostics without interfering with payment processing or order creation.
 *
 * Security & Standards:
 * - Namespaced under OrderDaemon\\CompletionManager as required.
 * - Uses odcm_ prefix for custom hooks and Action Scheduler group.
 * - Observation-only: No status changes or rule execution.
 * - Full DocBlocks for all methods.
 */
final class BlockCheckoutCompatibility
{
    /**
     * Initialize Block Checkout compatibility by registering hooks.
     *
     * @return void
     */
    public function init(): void
    {
        // Primary Store API (Blocks) event after checkout creates/updates an order
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'handle_block_checkout_processed'], 10, 1);
        // Back-compat alias used by some versions of Woo Blocks
        add_action('woocommerce_blocks_checkout_order_processed', [$this, 'handle_blocks_checkout_processed'], 10, 1);
        // Earlier point where order is updated from Store API request
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'handle_checkout_order_update'], 10, 2);

        // Async observation processing (Action Scheduler)
        add_action('odcm_process_block_checkout_observation', [$this, 'process_checkout_observation'], 10, 1);
    }

    /**
     * Handle Store API checkout order processed event (primary Blocks flow).
     * Observation-only: capture context, schedule async task, and log.
     *
     * @param WC_Order $order Newly created/processed order from Blocks checkout.
     * @return void
     */
    public function handle_block_checkout_processed($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $order_id = $order->get_id();

        if (!$this->should_process_block_checkout($order_id)) {
            return;
        }

        $checkout_context = $this->capture_block_checkout_context($order);
        $this->schedule_checkout_observation($order_id, $checkout_context);
        $this->log_block_checkout_event($order, $checkout_context);
    }

    /**
     * Back-compat handler for older Blocks hook name.
     *
     * @param WC_Order $order Order object
     * @return void
     */
    public function handle_blocks_checkout_processed($order): void
    {
        $this->handle_block_checkout_processed($order);
    }

    /**
     * Handle order update from Store API request during Blocks checkout.
     * Observation-only: capture a lightweight context snapshot and schedule.
     *
     * @param WC_Order   $order   Order being updated
     * @param \WP_REST_Request $request Request object (Store API)
     * @return void
     */
    public function handle_checkout_order_update($order, $request): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $order_id = $order->get_id();

        // Do not deduplicate here aggressively; allow processed hook to run as primary.
        // Still avoid flooding by checking a short-lived transient.
        $flag = get_transient("odcm_block_checkout_observe_{$order_id}");
        if ($flag) {
            return;
        }
        set_transient("odcm_block_checkout_observe_{$order_id}", 1, 60);

        $context = $this->capture_block_checkout_context($order);
        $context['technical_context']['hook'] = 'woocommerce_store_api_checkout_update_order_from_request';
        $this->schedule_checkout_observation($order_id, $context);
    }

    /**
     * Capture comprehensive Block Checkout context for diagnostics.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Comprehensive checkout context
     */
    private function capture_block_checkout_context(WC_Order $order): array
    {
        $context = [
            'checkout_type'     => 'block_checkout',
            'capture_timestamp' => wp_date('c'),
            'order_id'          => $order->get_id(),
        ];

        // Cart analysis
        try {
            $context['cart_analysis'] = $this->analyze_cart_composition($order);
        } catch (\Throwable $e) {
            $context['cart_analysis'] = [
                'error' => 'cart_analysis_failed: ' . $e->getMessage(),
            ];
        }

        // Payment context
        try {
            $context['payment_context'] = $this->capture_payment_context($order);
        } catch (\Throwable $e) {
            $context['payment_context'] = [
                'error' => 'payment_context_failed: ' . $e->getMessage(),
            ];
        }

        // Shipping analysis
        try {
            $context['shipping_analysis'] = $this->analyze_shipping_requirements($order);
        } catch (\Throwable $e) {
            $context['shipping_analysis'] = [
                'error' => 'shipping_analysis_failed: ' . $e->getMessage(),
            ];
        }

        // Customer context
        try {
            $context['customer_context'] = $this->capture_customer_context($order);
        } catch (\Throwable $e) {
            $context['customer_context'] = [
                'error' => 'customer_context_failed: ' . $e->getMessage(),
            ];
        }

        // Technical context
        try {
            $context['technical_context'] = $this->capture_technical_context();
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
    private function analyze_cart_composition(WC_Order $order): array
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
    private function capture_payment_context(WC_Order $order): array
    {
        return [
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'payment_status'       => $order->get_status(),
            'transaction_id'       => $order->get_transaction_id(),
            'currency'             => $order->get_currency(),
            'total_amount'         => (float) $order->get_total(),
            'gateway_context'      => $this->capture_gateway_specific_context($order),
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
    private function capture_gateway_specific_context(WC_Order $order): array
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
    private function analyze_shipping_requirements(WC_Order $order): array
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
    private function capture_customer_context(WC_Order $order): array
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
     * @return array<string, mixed> Technical context
     */
    private function capture_technical_context(): array
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
            'is_store_api'   => did_action('woocommerce_store_api_init') > 0,
            'theme'          => function_exists('wp_get_theme') ? wp_get_theme()->get('Name') : null,
        ];
    }

    /**
     * Prevent duplicate processing between Block and traditional checkout handlers.
     *
     * @param int $order_id Order ID
     * @return bool True if this order should be processed by Block Checkout handler
     */
    private function should_process_block_checkout(int $order_id): bool
    {
        // If a traditional path already flagged this order, skip
        if (get_transient("odcm_checkout_processing_{$order_id}")) {
            return false;
        }
        // If we've already handled via Blocks path recently, skip to avoid duplicates
        if (get_transient("odcm_block_checkout_processing_{$order_id}")) {
            return false;
        }
        set_transient("odcm_block_checkout_processing_{$order_id}", 1, 300);
        return true;
    }

    /**
     * Schedule lightweight observation task for later processing.
     *
     * @param int   $order_id         Order ID
     * @param array $checkout_context Captured context
     * @return void
     */
    private function schedule_checkout_observation(int $order_id, array $checkout_context): void
    {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }
        as_enqueue_async_action(
            'odcm_process_block_checkout_observation',
            [
                'order_id'         => $order_id,
                'checkout_context' => $checkout_context,
                'scheduled_at'     => wp_date('c'),
            ],
            'odcm-block-checkout'
        );
    }

    /**
     * Background processing of checkout observation.
     *
     * @param array $args Arguments from Action Scheduler
     * @return void
     */
    public function process_checkout_observation($args): void
    {
        // Extract args defensively
        $order_id = 0;
        $context  = [];
        if (is_array($args)) {
            $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
            if (isset($args['checkout_context']) && is_array($args['checkout_context'])) {
                $context = $args['checkout_context'];
            }
        }
        if ($order_id <= 0) {
            return;
        }

        // Mark order meta with indicator (safe, minimal footprint)
        update_post_meta($order_id, '_odcm_block_checkout_observed', '1');
        update_post_meta($order_id, '_odcm_block_checkout_observed_at', sanitize_text_field(wp_date('c')));

        // Optionally save a compact summary of context
        $summary = [
            'payment_method' => $context['payment_context']['payment_method'] ?? null,
            'requires_shipping' => $context['shipping_analysis']['requires_shipping'] ?? null,
            'total_items' => $context['cart_analysis']['total_items'] ?? null,
        ];
        update_post_meta($order_id, '_odcm_block_checkout_summary', wp_json_encode($summary));
    }

    /**
     * Log Block Checkout event with narrative components to the audit trail.
     *
     * @param WC_Order $order Order object
     * @param array    $checkout_context Captured context
     * @return void
     */
    private function log_block_checkout_event(WC_Order $order, array $checkout_context): void
    {
        if (!function_exists('odcm_log_event')) {
            return;
        }

        $payload_components = [
            [
                'key'   => 'block-checkout-' . uniqid('', true),
                'kind'  => 'info',
                'ts'    => wp_date('c'),
                'label' => 'Block Checkout Processed',
                'level' => 'info',
                'data'  => [
                    'checkout_type' => 'woocommerce_blocks',
                    'order_id'      => $order->get_id(),
                    'order_status'  => $order->get_status(),
                ],
            ],
            [
                'key'   => 'cart-analysis-' . uniqid('', true),
                'kind'  => 'fallback',
                'ts'    => wp_date('c'),
                'label' => 'Cart Analysis',
                'level' => 'info',
                'data'  => $checkout_context['cart_analysis'] ?? [],
            ],
            [
                'key'   => 'payment-context-' . uniqid('', true),
                'kind'  => 'fallback',
                'ts'    => wp_date('c'),
                'label' => 'Payment Context',
                'level' => 'info',
                'data'  => $checkout_context['payment_context'] ?? [],
            ],
        ];

        // Pre-seed a shared process_id for this order to ensure grouping across lifecycle events
        try {
            if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessIdManager')) {
                require_once __DIR__ . '/../ProcessIdManager.php';
            }
            $shared_pid = \OrderDaemon\CompletionManager\Core\ProcessIdManager::instance()->get_or_create_process_id((int)$order->get_id());
        } catch (\Throwable $e) {
            $shared_pid = null;
        }

        odcm_log_event([
            'summary'     => sprintf('Block Checkout processed for order #%d', $order->get_id()),
            'event_type'  => 'block_checkout_processed',
            'status'      => 'info',
            'order_id'    => $order->get_id(),
            // Explicitly pass process_id to avoid reliance on discovery timing
            'process_id'  => $shared_pid ?: null,
            'payload'     => [
                'type'            => 'block_checkout_observation',
                // Use the shared process id for correlation if available
                'correlation_id'  => $shared_pid ?: ('odcm:blkco:' . $order->get_id() . ':' . uniqid('', true)),
                'order_id'        => $order->get_id(),
                'started_at'      => wp_date('c'),
                'finished_at'     => wp_date('c'),
                'status'          => 'info',
                'summary'         => sprintf('Block Checkout observation for order #%d', $order->get_id()),
                'payload_components' => $payload_components,
                'checkout_context'   => $checkout_context,
            ],
            'log_category' => 'core',
            'is_test'     => false,
        ]);
    }
}
