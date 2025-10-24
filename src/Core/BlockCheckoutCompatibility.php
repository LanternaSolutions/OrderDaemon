<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use WC_Order;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;
use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;

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
        
        // Generate UniversalEvent for block checkout
        try {
            $universal_event = $this->synthesize_block_checkout_event($order, $checkout_context);
            $this->process_universal_event_from_hook($universal_event);
            odcm_log_message("Block checkout processed for order #{$order_id}, processed as universal event", 'info');
        } catch (\Throwable $e) {
            odcm_log_message('Block checkout universal event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }
        
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
        // Use the shared context builder for consistency with legacy checkout
        return CheckoutContextBuilder::buildCheckoutContext($order, 'block_checkout');
    }

    /**
     * Analyze cart composition for automation insights.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Cart analysis
     */
    /**
     * @deprecated Use CheckoutContextBuilder::analyzeCartComposition() instead
     */
    private function analyze_cart_composition(WC_Order $order): array
    {
        return CheckoutContextBuilder::analyzeCartComposition($order);
    }

    /**
     * Capture payment method and gateway context.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Payment context
     */
    /**
     * @deprecated Use CheckoutContextBuilder::capturePaymentContext() instead
     */
    private function capture_payment_context(WC_Order $order): array
    {
        return CheckoutContextBuilder::capturePaymentContext($order);
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
    /**
     * @deprecated Use CheckoutContextBuilder::captureGatewaySpecificContext() instead
     */
    private function capture_gateway_specific_context(WC_Order $order): array
    {
        return CheckoutContextBuilder::captureGatewaySpecificContext($order);
    }

    /**
     * Analyze shipping requirements including chosen methods and address presence.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Shipping analysis
     */
    /**
     * @deprecated Use CheckoutContextBuilder::analyzeShippingRequirements() instead
     */
    private function analyze_shipping_requirements(WC_Order $order): array
    {
        return CheckoutContextBuilder::analyzeShippingRequirements($order);
    }

    /**
     * Capture customer context during checkout.
     *
     * @param WC_Order $order Order object
     * @return array<string, mixed> Customer context
     */
    /**
     * @deprecated Use CheckoutContextBuilder::captureCustomerContext() instead
     */
    private function capture_customer_context(WC_Order $order): array
    {
        return CheckoutContextBuilder::captureCustomerContext($order);
    }

    /**
     * Capture technical context: platform versions and indicators.
     *
     * @return array<string, mixed> Technical context
     */
    /**
     * @deprecated Use CheckoutContextBuilder::captureTechnicalContext() instead
     */
    private function capture_technical_context(): array
    {
        return CheckoutContextBuilder::captureTechnicalContext('block_checkout');
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

        $components = [
            [
                'k'     => 'c' . time() . rand(10,99),
                'event_type'  => 'info',
                'ts'    => time(),
                'label' => 'Block Checkout Processed',
                'level' => 'info',
                'data'  => [
                    'checkout_type' => 'woocommerce_blocks',
                    'order_id'      => $order->get_id(),
                    'order_status'  => $order->get_status(),
                ],
            ],
            [
                'k'     => 'c' . time() . rand(10,99),
                'event_type'  => 'order_loaded',
                'ts'    => time(),
                'label' => 'Cart Analysis',
                'level' => 'info',
                'data'  => $checkout_context['cart_analysis'] ?? [],
            ],
            [
                'k'     => 'c' . time() . rand(10,99),
                'event_type'  => 'stripe_event',
                'ts'    => time(),
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

        odcm_log_event(
            sprintf('Block Checkout processed for order #%d', $order->get_id()),
            [
                'type'            => 'block_checkout_observation',
                // Use the shared process id for correlation if available
                'cid'             => $shared_pid ?: ($order->get_id() . ':' . time()),
                'order_id'        => $order->get_id(),
                'ts'              => time(),
                'status'          => 'info',
                'summary'         => sprintf('Block Checkout observation for order #%d', $order->get_id()),
                'components' => $components,
                'checkout_context'   => $checkout_context,
                'process_id'      => $shared_pid ?: null,
            ],
            $order->get_id(),
            'info',
            'block_checkout_processed',
            false
        );
    }

    /**
     * Synthesize block checkout event from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @param array $checkout_context Captured checkout context
     * @return UniversalEvent
     */
    private function synthesize_block_checkout_event(\WC_Order $order, array $checkout_context): UniversalEvent
    {
        return new UniversalEvent([
            'eventType' => 'checkout_processed', // Changed to match traditional checkout event type
            'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => $order->get_status(),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => $checkout_context // Use the entire context as rawData for consistency
        ]);
    }

    /**
     * Normalize gateway name to standard format
     *
     * @param string $payment_method WooCommerce payment method ID
     * @return string Normalized gateway name
     */
    private function normalize_gateway_name(string $payment_method): string
    {
        $gateway_mapping = [
            'paypal' => 'paypal',
            'ppcp-gateway' => 'paypal',
            'ppcp-credit-card-gateway' => 'paypal',
            'stripe' => 'stripe',
            'stripe_cc' => 'stripe',
            'stripe_sepa' => 'stripe',
            'bacs' => 'bank_transfer',
            'cheque' => 'check',
            'cod' => 'cash_on_delivery',
        ];

        return $gateway_mapping[$payment_method] ?? $payment_method;
    }

    /**
     * Determine the source of the change
     *
     * @return string Change source
     */
    private function determine_change_source(): string
    {
        try {
            if (class_exists('OrderDaemon\\CompletionManager\\Core\\AttributionTracker')) {
                $attr = \OrderDaemon\CompletionManager\Core\AttributionTracker::instance()->capture_context();
                $request_type = is_array($attr) ? sanitize_key((string)($attr['request_type'] ?? '')) : '';
                $external_service_name = (is_array($attr) && isset($attr['external_service']['name'])) ? sanitize_key((string)$attr['external_service']['name']) : null;

                if (is_user_logged_in()) {
                    return 'manual';
                } elseif ($request_type === 'webhook' || !empty($external_service_name)) {
                    return 'webhook';
                } elseif ($request_type === 'rest' || $request_type === 'ajax') {
                    return 'api';
                } elseif (in_array($request_type, ['action_scheduler','cron','cli','wp_cli'], true)) {
                    return 'scheduled';
                } else {
                    return 'system';
                }
            }
        } catch (\Throwable $e) {
            // Fall back to basic detection
        }

        // Basic fallback detection for block checkout
        if (did_action('woocommerce_store_api_init') > 0) {
            return 'api';
        } elseif (is_user_logged_in()) {
            return 'manual';
        } else {
            return 'system';
        }
    }

    /**
     * Process universal event from hook through the universal event pipeline
     *
     * @param UniversalEvent $event Universal event to process
     * @return void
     */
    private function process_universal_event_from_hook(UniversalEvent $event): void
    {
        try {
            // Schedule universal event processing through Action Scheduler
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'odcm_process_lifecycle_event',
                    ['event' => $event->toArray()],
                    'odcm-universal-events'
                );
            } else {
                // Fallback: process directly if Action Scheduler not available
                $processor = UniversalEventProcessor::instance();
                $processor->processEvent($event->toArray());
            }
        } catch (\Throwable $e) {
            // Log error but don't let it break the block checkout process
            odcm_log_message('Payment gateway event processing error: ' . $e->getMessage(), 'error');
            odcm_log_message('Payment gateway event processing error details: ' . $e->getFile() . ':' . $e->getLine(), 'error');
            // Continue execution without throwing the exception
        }
    }
}
