<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

use WC_Order;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;
use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;
use OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager;

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
     * Captures rich checkout data and triggers unified processing.
     * CONSOLIDATED ARCHITECTURE: This captures rich block checkout context, stores 
     * it in the queue, then calls the unified processor to schedule Action Scheduler job.
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
        
        // QUEUE-BASED: Store rich checkout data for async Universal Event creation
        try {
            $this->queue_block_checkout_data($order, $checkout_context);
            
            // CONSOLIDATED: Call the Core.php unified processor to schedule job
            $this->trigger_unified_checkout_processing($order_id, $order);
            
            odcm_log_message("Block checkout data queued and unified processing triggered for order #{$order_id}", 'info');
        } catch (\Throwable $e) {
            odcm_log_message('Block checkout processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }
        
        // Observation/diagnostics for compatibility
        $this->schedule_checkout_observation($order_id, $checkout_context);
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
        OrderMetaManager::update_meta($order_id, '_odcm_block_checkout_observed', '1');
        OrderMetaManager::update_meta($order_id, '_odcm_block_checkout_observed_at', sanitize_text_field(wp_date('c')));

        // Optionally save a compact summary of context
        $summary = [
            'payment_method' => $context['payment_context']['payment_method'] ?? null,
            'requires_shipping' => $context['shipping_analysis']['requires_shipping'] ?? null,
            'total_items' => $context['cart_analysis']['total_items'] ?? null,
        ];
        OrderMetaManager::update_meta($order_id, '_odcm_block_checkout_summary', wp_json_encode($summary));
    }

    /**
     * Log Block Checkout event with narrative components to the audit trail.
     *
     * Creates a user-friendly "Checkout Completed" entry that appears at the top
     * of the order timeline, with properly structured component data for the renderer.
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

        // Get payment method and total for display
        $payment_method = $order->get_payment_method_title();
        $order_total = (float) $order->get_total();
        $currency = $order->get_currency();
        
        // Format total similar to how Payment Completed shows it
        $total_formatted = '';
        if ($order_total > 0) {
            // Use raw amount rather than HTML for better display
            $total_formatted = number_format($order_total, 2);
            if ($currency) {
                $total_formatted = $currency . ' ' . $total_formatted;
            }
        }

        // Get gateway for hierarchical event naming
        $gateway = $this->normalize_gateway_name($order->get_payment_method());
        
        $components = [
            [
                'k'     => odcm_component_key(),
                'event_type'  => 'info',
                'ts'    => time(),
                'label' => 'Block Checkout Processed',
                'level' => 'info',
                'data'  => [
                    'checkout_type' => 'woocommerce_blocks',
                    'order_id'      => $order->get_id(),
                    'order_status'  => $order->get_status(),
                    'payment_method' => $payment_method,
                    'total'         => $total_formatted,
                ],
            ],
            [
                'k'     => odcm_component_key(),
                'event_type'  => 'payment.' . $gateway . '.checkout_processed',
                'ts'    => time(),
                'label' => 'Payment Event',
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
            'Block Checkout processed',
            [
                'type'            => 'block_checkout_observation',
                // Use the shared process id for correlation if available
                'cid'             => $shared_pid ?: ($order->get_id() . ':' . time()),
                'order_id'        => $order->get_id(),
                'ts'              => time(),
                'status'          => 'info',
                'summary'         => 'Block Checkout processed',
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
     * Synthesize block checkout event from WooCommerce order data.
     * 
     * This creates an enhanced universal event with combined data to
     * ensure the checkout completed event has all necessary information.
     *
     * @param \WC_Order $order WooCommerce order object
     * @param array $checkout_context Captured checkout context
     * @return UniversalEvent
     */
    private function synthesize_block_checkout_event(\WC_Order $order, array $checkout_context): UniversalEvent
    {
        // Get all immediately available data
        $payment_method = $order->get_payment_method_title();
        $gateway = $this->normalize_gateway_name($order->get_payment_method());
        $order_total = (float) $order->get_total();
        $currency = $order->get_currency();
        $order_id = $order->get_id();
        $order_status = $order->get_status();
        
        // Create process ID for correlation of all events
        $process_id = null;
        try {
            // Correct the class loading to ensure it works
            if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessIdManager')) {
                require_once dirname(__FILE__) . '/ProcessIdManager.php';
            }
            $process_id = ProcessIdManager::instance()->get_or_create_process_id($order_id);
        } catch (\Throwable $e) {
            // Fallback if process ID creation fails
            $process_id = $order_id . ':' . time();
        }
        
        $total = $order_total;
        
        // Create the components array - this is what drives the "Checkout Completed" UI display
        $components = [
            [
                'k' => odcm_component_key(),
                'event_type' => 'checkout_processed',
                'ts' => time(),
                'label' => 'Checkout Completed',
                'level' => 'info',
                'data' => [
                    // Simplify to essential fields with explicit type casting
                    'order_id' => (int) $order_id,           // Force integer type
                    'status' => (string) $order_status,      // Force string type
                    'payment_method' => (string) $payment_method,
                    'total' => (float) $total,               // Force float type for proper formatting
                    'currency' => (string) $currency,        // Force string type
                    'checkout_type' => 'woocommerce_blocks', // Standard value for checkout type
                ]
            ],
            [
                'k' => odcm_component_key(),
                'event_type' => 'payment.' . $gateway . '.checkout_processed',
                'ts' => time(),
                'label' => 'Payment Event',
                'level' => 'info',
                'data' => $checkout_context['payment_context'] ?? []
            ]
        ];

        // Only include technical data in rawData, not UI rendering information
        $technical_data = [
            'checkout_type' => 'block_checkout',
            'source' => $this->determine_change_source(),
            'process_id' => $process_id,
        ];
        
        // Include original checkout context for technical reference
        if (!empty($checkout_context)) {
            $technical_data['checkout_context'] = $checkout_context;
        }

        return new UniversalEvent([
            'eventType' => 'checkout_processed',
            'sourceGateway' => $gateway,
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order_id,
            'transactionID' => $order->get_transaction_id(),
            'status' => $order_status,
            'amount' => $order_total,
            'currency' => $currency,
            'occurredAt' => current_time('c'),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => 'checkout_processed_' . $order_id . '_' . time(),
            'components' => $components, // Components at top level for UI rendering
            'rawData' => $technical_data // Only technical data, not duplicated UI info
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
     * Queue block checkout data for async Universal Event creation.
     * 
     * Stores rich checkout context with real timestamp in the queue database.
     * The async processor will use this data to create the Universal Event.
     *
     * @param WC_Order $order WooCommerce order object
     * @param array $checkout_context Rich checkout context from capture
     * @return void
     */
    private function queue_block_checkout_data(\WC_Order $order, array $checkout_context): void
    {
        global $wpdb;
        
        $order_id = $order->get_id();
        $checkout_timestamp = (float) $order->get_date_created()->getTimestamp();
        
        // Prepare queue data with rich context and original timestamp
        $queue_data = [
            'order_id' => $order_id,
            'checkout_type' => 'block_checkout',
            'checkout_timestamp' => $checkout_timestamp,
            'checkout_context' => $checkout_context,
            'order_data' => [
                'status' => $order->get_status(),
                'total' => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'transaction_id' => $order->get_transaction_id(),
                'customer_id' => $order->get_customer_id(),
            ],
            'source' => $this->determine_change_source(),
            'queued_at' => current_time('c'),
        ];
        
        // Generate unique queue ID
        $queue_id = 'checkout_' . $order_id . '_' . time() . '_' . wp_rand(1000, 9999);
        
        // Check cache first to avoid duplicate inserts
        $cache_key = 'odcm_queue_' . md5($queue_id);
        $cached_result = wp_cache_get($cache_key);
        
        if (false === $cached_result) {
            // Insert into queue table - with additional caching to ensure better performance
        // Use a transaction key to ensure duplicate prevention across processes
        $transaction_key = 'odcm_queue_transaction_' . md5($queue_id . '_' . $order_id);
        $transaction_lock = wp_cache_get($transaction_key);
        
        if (false === $transaction_lock) {
            // Set transaction lock
            wp_cache_set($transaction_key, true, '', 30); // 30 second lock

            // Insert the record
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert(
                $wpdb->prefix . 'odcm_audit_log_queue',
                [
                    'queue_id' => $queue_id,
                    'event_data' => wp_json_encode($queue_data),
                    'status' => 'pending',
                    'created_at' => current_time('mysql'),
                    'retry_count' => 0
                ]
            );
            
            // Release transaction lock
            wp_cache_delete($transaction_key);
        } else {
            // Another process is handling this insert or recently completed it
            $result = true; // Assume success to prevent duplicate attempts
        }
            
            // Cache the result to prevent duplicate inserts
            if ($result !== false) {
                wp_cache_set($cache_key, true, '', 300); // Cache for 5 minutes
            }
        } else {
            $result = true; // Already cached, assume success
        }
        
        if ($result === false) {
            throw new \Exception('Failed to queue block checkout data: ' . esc_html($wpdb->last_error));
        }
        
        // Set marker to indicate this order has queued data
        OrderMetaManager::update_meta($order_id, '_odcm_checkout_queue_id', $queue_id);
        OrderMetaManager::update_meta($order_id, '_odcm_checkout_data_queued', '1');
        
        odcm_log_message("Block checkout data queued with ID: {$queue_id} for order #{$order_id}", 'info');
    }

    /**
     * Trigger unified checkout processing by calling Core.php unified processor
     * 
     * This connects block checkout to the unified processor to ensure single 
     * Action Scheduler job scheduling and prevent duplicates.
     *
     * @param int $order_id Order ID
     * @param WC_Order $order WooCommerce order object
     * @return void
     */
    private function trigger_unified_checkout_processing(int $order_id, WC_Order $order): void
    {
        try {
            // Get the Core instance - this requires proper access to the Core class
            // Since we can't directly instantiate Core, we'll use the global approach
            // that mimics what Core.php would do
            
            // Create a simulated call to the unified processor with block checkout context
            $this->schedule_unified_checkout_completion($order_id);
            
        } catch (\Throwable $e) {
            odcm_log_message('Failed to trigger unified checkout processing for order #' . $order_id . ': ' . $e->getMessage(), 'error');
            
            // Fallback: schedule directly if unified processor fails
            $this->fallback_schedule_checkout_completion($order_id);
        }
    }

    /**
     * Schedule unified checkout completion processing directly
     * 
     * FIXED: Uses same reliable database query logic as Core.php
     *
     * @param int $order_id Order ID
     * @return void
     */
    private function schedule_unified_checkout_completion(int $order_id): void
    {
        // Check for recent scheduling to prevent duplicates (same logic as Core.php)
        $unified_key = "odcm_unified_checkout_scheduled_{$order_id}";
        if (get_transient($unified_key)) {
            odcm_log_message("Block checkout skipping order #{$order_id} - unified processor already scheduled", 'info');
            return;
        }
        
        // Use direct database query for reliable job detection
        global $wpdb;
        
        // Construct query with prepared statement. Table name uses trusted $wpdb->prefix directly
        // since SQL prepared statements cannot parameterize table identifiers.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table reference uses trusted $wpdb->prefix
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}actionscheduler_actions` WHERE hook = %s AND status IN ('pending', 'in-progress') AND hook_arguments LIKE %s",
            'odcm_process_checkout_completion',
            '%"order_id":' . intval($order_id) . '%'
        );
        
        // Cache key for existing job count check
        $job_count_cache_key = 'odcm_job_count_' . $order_id;
        $job_count = wp_cache_get($job_count_cache_key);
        
        if (false === $job_count) {
            // Execute the properly prepared query with caching
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above
            $existing_count = (int) $wpdb->get_var($query);
            
            // Cache the job count result for 60 seconds
            wp_cache_set($job_count_cache_key, $existing_count, '', 60);
        } else {
            $existing_count = (int) $job_count;
        }
        
        if ($existing_count > 0) {
            odcm_log_message("Block checkout skipping order #{$order_id} - found {$existing_count} existing jobs via database query", 'info');
            set_transient($unified_key, 1, 60); // 1 minute
            return;
        }
        
        // Set flag to prevent duplicate processing
        set_transient($unified_key, 1, 300); // 5 minutes
        
        // Check cache for recent scheduling
        $schedule_cache_key = 'odcm_schedule_checkout_' . $order_id;
        $schedule_cached = wp_cache_get($schedule_cache_key);
        
        if (false === $schedule_cached) {
            // Schedule the Action Scheduler job with unified processor arguments
            as_enqueue_async_action('odcm_process_checkout_completion', [
                'order_id' => $order_id,
                'unified_processor' => true,
                'block_checkout' => true,
                'scheduled_at' => current_time('c')
            ], 'odcm-checkout-processing');
            
            // Cache scheduling status to prevent duplicate jobs
            wp_cache_set($schedule_cache_key, true, '', 180); // Cache for 3 minutes
        } else {
            odcm_log_message("Block checkout used cached scheduling status for order #{$order_id}", 'info');
        }
        
        odcm_log_message("Block checkout scheduled unified completion processing for order #{$order_id}", 'info');
    }

    /**
     * Fallback scheduling if unified processor fails
     *
     * @param int $order_id Order ID
     * @return void
     */
    private function fallback_schedule_checkout_completion(int $order_id): void
    {
        try {
            // Last resort: schedule basic order check
            as_enqueue_async_action('odcm_process_order_check', [
                'order_id' => $order_id
            ], 'odcm-emergency-processing');
            
            odcm_log_message("Block checkout scheduled fallback processing for order #{$order_id}", 'info');
        } catch (\Throwable $e) {
            odcm_log_message('Block checkout fallback scheduling failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Process universal event from hook through the universal event pipeline
     * 
     * This method is responsible for the business logic processing of checkout events,
     * sending them to the rule evaluation system. Note that this does NOT handle
     * the UI/timeline rendering - that's done by log_block_checkout_event().
     *
     * @param UniversalEvent $event Universal event to process
     * @return void
     */
    private function process_universal_event_from_hook(UniversalEvent $event): void
    {
        try {
            // Prevent duplicate processing with event caching
            $event_data = $event->toArray();
            $cid = $event_data['primaryObjectID'] ?? '';
            $event_type = $event_data['eventType'] ?? '';
            
            if ($cid && $event_type) {
                $event_cache_key = 'odcm_event_' . md5($event_type . '_' . $cid . '_' . time());
                $cached_event = wp_cache_get($event_cache_key);
                
                if (false !== $cached_event) {
                    odcm_log_message("Skipped duplicate event processing: {$event_type} for {$cid}", 'info');
                    return;
                }
                
                // Set cache to prevent duplicate processing
                wp_cache_set($event_cache_key, true, '', 60); // Cache for 1 minute
            }
            
            // Schedule universal event processing through Action Scheduler
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'odcm_process_lifecycle_event',
                    ['event' => $event_data],
                    'odcm-universal-events'
                );
            } else {
                // Fallback: process directly if Action Scheduler not available
                $processor = UniversalEventProcessor::instance();
                $processor->processEvent($event_data);
            }
        } catch (\Throwable $e) {
            // Log error but don't let it break the block checkout process
            odcm_log_message('Payment gateway event processing error: ' . $e->getMessage(), 'error');
            odcm_log_message('Payment gateway event processing error details: ' . $e->getFile() . ':' . $e->getLine(), 'error');
            // Continue execution without throwing the exception
        }
    }
}
