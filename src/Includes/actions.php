<?php
declare(strict_types=1);

/**
 * Global action functions for Order Daemon for WooCommerce.
 *
 * This file contains global functions that handle asynchronous actions
 * using WooCommerce Action Scheduler.
 *
 * @package OrderDaemon\CompletionManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Import required classes
use function OrderDaemon\CompletionManager\Utils\odcm_sanitize_payload_for_logging;


/**
 * Processes log entries in the background.
 *
 * This handler function is executed by Action Scheduler when a log event is processed.
 * It validates the log data, sanitizes the payload, and writes the records to the
 * audit log database tables.
 *
 * @param array $args The arguments passed by Action Scheduler containing event_data.
 * @return void
 * @since 1.0.0
 */
function odcm_handle_log_processing($args) {
    global $wpdb;
    
    // TEMPORARY DEBUG: Log that function is being called
    error_log("ODCM_DEBUG_TRACE: odcm_handle_log_processing() called with args: " . wp_json_encode($args));
    
    // Handle both array and JSON string arguments from Action Scheduler
    if (is_string($args)) {
        // Action Scheduler is passing JSON string - decode it
        $args = json_decode($args, true);
        if (!is_array($args)) {
            error_log("ODCM: Failed to decode JSON args in log processing. Raw args: " . var_export($args, true));
            return;
        }
    }

    if (!is_array($args)) {
        error_log("ODCM: Args must be array or JSON string, got " . gettype($args));
        return;
    }

    // SIMPLIFIED: Expect only compressed format
    // odcm_log_event() sends compressed data directly to Action Scheduler
    if (!isset($args['d']) || !is_array($args['d'])) {
        error_log("ODCM: Invalid compressed payload structure. Expected 'd' key with array value, got: " . wp_json_encode($args));
        return;
    }

    $comp_data = $args['d'];
    
    // Decode compressed fields using registry helper functions
    $status = odcm_decode_status($comp_data['st'] ?? 4);
    $event_type = $comp_data['t'] ?? 'event';
    $order_id = $comp_data['o'] ?? null;
    $is_test = !empty($comp_data['test']);
    $process_id = $comp_data['pid'] ?? null;
    $source = odcm_decode_source($comp_data['src'] ?? 7);
    
    // TEMPLATE-BASED SUMMARY RECONSTRUCTION
    $event_types = odcm_get_log_event_types();
    $has_template = isset($event_types[$event_type]['summary_template']);
    
    if ($has_template) {
        // Reconstruct from template + variables
        $template = $event_types[$event_type]['summary_template'];
        $data = $comp_data['data'] ?? [];
        
        // Build variables array for sprintf
        $variables = [];
        if ($order_id) {
            $variables[] = $order_id; // Most templates use order_id as first variable
        }
        
        // Add additional variables from data array in common order
        if (!empty($data['rule_name'])) {
            $variables[] = $data['rule_name'];
        }
        if (!empty($data['error_message'])) {
            $variables[] = $data['error_message'];
        }
        if (!empty($data['user_name'])) {
            $variables[] = $data['user_name'];
        }
        
        try {
            // Reconstruct full summary from template
            $summary = sprintf($template, ...$variables);
        } catch (Exception $e) {
            // Fallback if sprintf fails
            $summary = "Event: {$event_type}";
            if ($order_id) {
                $summary .= " (Order #{$order_id})";
            }
        }
        
    } else {
        // Custom event - use stored summary (already validated length)
        $summary = $comp_data['s'] ?? 'Custom event processed';
    }
    
    // Store compressed format as payload (70%+ space savings!)
    $payload_to_store = $args;

    // Sanitize and insert payload
    $sanitized_payload = odcm_sanitize_payload_for_logging($payload_to_store);
    
    $payload_insert = $wpdb->insert(
        $wpdb->prefix . 'odcm_audit_log_payloads',
        ['payload' => json_encode($sanitized_payload)]
    );

    if ($payload_insert === false) {
        error_log("ODCM: Failed to insert payload: " . $wpdb->last_error);
        return;
    }

    $payload_id = $wpdb->insert_id;

    // Prepare log data from extracted values
    $log_data = [
        'summary'    => sanitize_text_field($summary),
        'event_type' => sanitize_key($event_type),
        'status'     => sanitize_key($status),
        'payload_id' => $payload_id,
        'timestamp'  => current_time('mysql'),
        'is_test'    => $is_test ? 1 : 0,
    ];

    // Add optional fields if available
    if ($order_id) {
        $log_data['order_id'] = (int) $order_id;
    }
    if ($process_id) {
        $log_data['process_id'] = sanitize_text_field($process_id);
    }
    if ($source) {
        $log_data['source'] = sanitize_text_field($source);
    }

    // Insert log entry with duplicate protection
    $duplicate_hash = md5(serialize([
        'summary' => $log_data['summary'],
        'event_type' => $log_data['event_type'],
        'status' => $log_data['status'],
        'order_id' => $log_data['order_id'] ?? null,
        'timestamp_minute' => gmdate('Y-m-d H:i', strtotime($log_data['timestamp']))
    ]));
    $log_data['duplicate_hash'] = $duplicate_hash;

    $query = "INSERT IGNORE INTO " . $wpdb->prefix . "odcm_audit_log (" . 
             implode(', ', array_keys($log_data)) . ") VALUES (" . 
             implode(', ', array_fill(0, count($log_data), '%s')) . ")";

    $wpdb->query($wpdb->prepare($query, array_values($log_data)));
}

// Hook the handler to the action scheduled by the Feeder
add_action('odcm_process_log_entry', 'odcm_handle_log_processing', 10, 1);


/**
 * Processes an order check in the background.
 *
 * This handler function is executed by Action Scheduler when an order check is processed.
 * It creates an instance of the Executor class and calls its process_order_check method.
 *
 * @param mixed $args The arguments passed to the action (can be array or int).
 * @return void
 * @since 1.0.0
 */
function odcm_handle_order_check_processing($args) {
    // Handle both array and direct integer arguments
    if (is_array($args)) {
        // Standard array format: ['order_id' => 123]
        $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
    } elseif (is_numeric($args)) {
        // Direct integer format: 123
        $order_id = (int) $args;
    } else {
        // Use unified logging for argument validation errors
        odcm_log_event(
            'Order check processing failed: Invalid argument type',
            [
                'args' => $args,
                'type' => gettype($args)
            ],
            null,
            'error',
            'order_check_argument_error'
        );
        return;
    }

    if ($order_id <= 0) {
        // Use unified logging for order ID validation errors
        odcm_log_event(
            'Order check processing failed: Invalid order ID',
            [
                'args' => $args,
                'extracted_order_id' => $order_id
            ],
            null,
            'error',
            'order_check_invalid_id'
        );
        return;
    }

    // Get the WooCommerce order
    $order = wc_get_order($order_id);
    if (!$order) {
        odcm_log_event(
            'Order check processing failed: Order not found',
            [
                'order_id' => $order_id,
                'error' => 'WooCommerce order not found or invalid'
            ],
            $order_id,
            'error',
            'order_check_not_found'
        );
        return;
    }

    try {
        // TEMPORARY DEBUG: Log action handler execution
        error_log("ODCM_DEBUG_TRACE: Action Handler - Processing Order #{$order_id}");
        error_log("ODCM_DEBUG_TRACE: Action Handler - Order status: " . $order->get_status());
        error_log("ODCM_DEBUG_TRACE: Action Handler - Payment method: " . $order->get_payment_method());
        
        // Create a universal event for the order check
        $universal_event_data = [
            'eventType' => 'order_check_scheduled',
            'sourceGateway' => $order->get_payment_method() ?: 'unknown',
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order_id,
            'transactionID' => $order->get_transaction_id(),
            'status' => $order->get_status(),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => 'order_check_' . $order_id . '_' . time(),
            'rawData' => [
                'order_status' => $order->get_status(),
                'payment_method' => $order->get_payment_method(),
                'customer_id' => $order->get_customer_id(),
                'source' => 'scheduled_check',
                'trigger' => 'action_scheduler'
            ]
        ];

        error_log("ODCM_DEBUG_TRACE: Action Handler - Created universal event data");

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        error_log("ODCM_DEBUG_TRACE: Action Handler - About to call processEvent()");
        $result = $processor->processEvent($universal_event_data);
        error_log("ODCM_DEBUG_TRACE: Action Handler - processEvent() returned: " . ($result ? 'TRUE' : 'FALSE'));

        // Debug logging only
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM_DEBUG: Order check completed for #{$order_id} - Result: " . ($result ? 'TRUE' : 'FALSE'));
        }

    } catch (\Throwable $e) {
        // Log processing error but don't let it break the system
        odcm_log_event(
            'Order check processing failed with exception: ' . $e->getMessage(),
            [
                'order_id' => $order_id,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'order_status' => $order ? $order->get_status() : 'unknown'
            ],
            $order_id,
            'error',
            'order_check_exception'
        );
    }
}

// Hook the order check processing handler
add_action('odcm_process_order_check', 'odcm_handle_order_check_processing', 10, 1);

/**
 * Processes a universal event in the background.
 *
 * This handler function is executed by Action Scheduler when a universal event is processed.
 * It creates an instance of the UniversalEventProcessor class and calls its processEvent method.
 *
 * @param array $args The arguments passed to the action containing event data.
 * @return void
 * @since 1.0.0
 */
function odcm_handle_universal_event_processing(array $args) {
    // Action Scheduler can pass args in two formats:
    // 1. Wrapped: ['event' => $event_data] (when explicitly structured)
    // 2. Unwrapped: $event_data directly (when Action Scheduler optimizes single-element arrays)
    
    if (isset($args['event'])) {
        // Format 1: Wrapped in 'event' key
        $event_data = $args['event'];
    } elseif (isset($args['eventType'])) {
        // Format 2: Event data passed directly (Action Scheduler unwrapped it)
        $event_data = $args;
    } else {
        // Invalid format - neither wrapped nor valid event structure
        odcm_log_event(
            'Payment gateway event processing error: Missing data',
            [
                'args' => $args,
                'args_keys' => array_keys($args),
                'error' => 'Missing event data - no event key and no eventType'
            ],
            null,
            'error',
            'universal_event_argument_error'
        );
        return;
    }

    // Validate event data structure
    if (!is_array($event_data) || !isset($event_data['eventType'])) {
        // Log error using unified logging system
        odcm_log_event(
            'Payment gateway event processing error: Invalid data format',
            [
                'event_data' => $event_data,
                'error' => 'Missing eventType in event data'
            ],
            null,
            'error',
            'universal_event_invalid_data'
        );
        return;
    }

    try {
        // Get UniversalEventProcessor instance
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();

        // Process the event
        $result = $processor->processEvent($event_data);

        // Log success/failure at debug level
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $gateway = isset($event_data['sourceGateway']) ? ucfirst($event_data['sourceGateway']) : 'Payment gateway';
            $event_type = isset($event_data['eventType']) ? $event_data['eventType'] : 'event';
            $message = $result ? 
                "{$gateway} {$event_type} processed successfully" : 
                "{$gateway} {$event_type} completed with no action";
                
            error_log("ODCM_DEBUG: {$message}");
        }

    } catch (\Throwable $e) {
        // Log processing error
        odcm_log_event(
            'Payment gateway event processing error: ' . $e->getMessage(),
            [
                'event_type' => $event_data['eventType'] ?? 'unknown',
                'source_gateway' => $event_data['sourceGateway'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'event_data' => $event_data
            ],
            null,
            'error',
            'universal_event_processing_exception'
        );
    }
}

// Hook the universal event processing handler
add_action('odcm_process_lifecycle_event', 'odcm_handle_universal_event_processing', 10, 1);

/**
 * Processes checkout completion in the background using queued data.
 *
 * This handler function is executed by Action Scheduler when a checkout completion is processed.
 * It retrieves rich checkout data from the queue and creates Universal Events with accurate timestamps.
 * This ensures single Universal Event creation with preserved chronology.
 *
 * @param mixed $args The arguments passed to the action (can be array or int).
 * @return void
 * @since 1.0.0
 */
function odcm_handle_checkout_completion_processing($args) {
    global $wpdb;
    
    // Handle Action Scheduler calling convention
    if (is_array($args)) {
        $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
        $checkout_type = isset($args['checkout_type']) ? sanitize_text_field($args['checkout_type']) : 'standard';
    } else {
        // Action Scheduler passes order ID directly
        $order_id = (int) $args;
        $checkout_type = 'standard';
    }

    // Extract scheduled_at parameter to identify legitimate vs duplicate jobs
    $scheduled_at = null;
    if (is_array($args) && isset($args['scheduled_at'])) {
        $scheduled_at = sanitize_text_field($args['scheduled_at']);
    }

    // DEBUG: Log entry with full context
    error_log("ODCM_DUPLICATE_DEBUG: === CHECKOUT COMPLETION PROCESSING START ===");
    error_log("ODCM_DUPLICATE_DEBUG: Order ID: {$order_id}");
    error_log("ODCM_DUPLICATE_DEBUG: Checkout Type: {$checkout_type}");
    error_log("ODCM_DUPLICATE_DEBUG: Scheduled At: " . ($scheduled_at ?: 'MISSING - THIS IS A DUPLICATE JOB'));
    error_log("ODCM_DUPLICATE_DEBUG: Args: " . wp_json_encode($args));
    error_log("ODCM_DUPLICATE_DEBUG: Current time: " . current_time('c'));

    if ($order_id <= 0) {
        error_log('ODCM_BACKGROUND: Invalid order ID for checkout completion processing');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
        error_log("ODCM_BACKGROUND: Order #{$order_id} not found for checkout completion processing");
        return;
    }

    try {
        // Try to retrieve enriched data from queue first
        error_log("ODCM_DUPLICATE_DEBUG: About to call odcm_get_queued_checkout_data for order #{$order_id}");
        $queued_data = odcm_get_queued_checkout_data($order_id);
        
        if ($queued_data) {
            // Use queued data with original timestamp
            error_log("ODCM_DUPLICATE_DEBUG: FOUND queued data for order #{$order_id}");
            error_log("ODCM_DUPLICATE_DEBUG: Queue ID: " . ($queued_data['queue_id'] ?? 'missing'));
            $universal_event = odcm_synthesize_checkout_from_queued_data($order, $queued_data);
            error_log("ODCM_BACKGROUND: Using queued checkout data for order #{$order_id}");
        } else {
            // Only create fallback event for legitimate jobs that have scheduled_at parameter
            // Duplicate jobs (missing scheduled_at) should exit silently without creating events
            if (!$scheduled_at) {
                error_log("ODCM_DUPLICATE_DEBUG: BLOCKED duplicate job for order #{$order_id} - no queued data AND missing scheduled_at");
                error_log("ODCM_DUPLICATE_DEBUG: This prevents the empty DEBUG event from being created");
                return; // Exit silently - this is a duplicate job with no data
            }
            
            // Legitimate job but no queued data - create fallback event
            error_log("ODCM_DUPLICATE_DEBUG: NO queued data found for order #{$order_id} but legitimate job - using fallback");
            $universal_event = odcm_synthesize_checkout_processed_event($order, [], $checkout_type);
            error_log("ODCM_BACKGROUND: Using fallback synthesis for order #{$order_id} (no queued data)");
        }

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        $result = $processor->processEvent($universal_event->toArray());

        // Clean up queue data after successful processing
        if ($queued_data) {
            odcm_cleanup_processed_queue_data($order_id, $queued_data['queue_id']);
        }

        // Log successful background processing
        error_log("ODCM_BACKGROUND: Checkout completion processed successfully for order #{$order_id}");

    } catch (\Throwable $e) {
        // Log processing error but don't let it break the system
        error_log("ODCM_BACKGROUND: Checkout completion processing failed for order #{$order_id}: " . $e->getMessage());
        
        odcm_log_event(
            'Checkout completion processing failed with exception: ' . $e->getMessage(),
            [
                'order_id' => $order_id,
                'checkout_type' => $checkout_type,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'order_status' => $order ? $order->get_status() : 'unknown'
            ],
            $order_id,
            'error',
            'checkout_completion_exception'
        );
        
        // Emergency fallback: schedule traditional order check
        try {
            as_enqueue_async_action('odcm_process_order_check', [
                'order_id' => $order_id
            ], 'odcm-emergency-processing');
            error_log("ODCM_BACKGROUND: Scheduled emergency fallback processing for order #{$order_id}");
        } catch (\Throwable $fallback_error) {
            error_log("ODCM_BACKGROUND: Emergency fallback failed for order #{$order_id}: " . $fallback_error->getMessage());
        }
    }
}

/**
 * Synthesize checkout processed event from WooCommerce order data with rich data
 * 
 * Creates comprehensive checkout events with the same rich data format as block checkouts.
 * Ensures consistent user experience regardless of checkout type.
 *
 * @param \WC_Order $order WooCommerce order object
 * @param array $posted_data Posted checkout data (unused but kept for compatibility)
 * @param string $checkout_type Type of checkout (standard, block, etc.)
 * @return \OrderDaemon\CompletionManager\Core\Events\UniversalEvent
 */
function odcm_synthesize_checkout_processed_event(\WC_Order $order, array $posted_data = [], string $checkout_type = 'standard'): \OrderDaemon\CompletionManager\Core\Events\UniversalEvent {
    // GET REAL CHECKOUT TIMESTAMP from WooCommerce order creation date
    $checkout_timestamp = odcm_get_real_checkout_timestamp($order);
    
    // Get comprehensive checkout context for rich data (same as block checkout)
    $checkout_context = [];
    if (class_exists('OrderDaemon\\CompletionManager\\Core\\CheckoutContextBuilder')) {
        try {
            $checkout_context = \OrderDaemon\CompletionManager\Core\CheckoutContextBuilder::buildCheckoutContext($order, $checkout_type);
        } catch (\Throwable $e) {
            // Fallback to basic context if CheckoutContextBuilder fails
            $checkout_context = [
                'cart_analysis' => ['total_items' => count($order->get_items())],
                'payment_context' => [
                    'payment_method' => $order->get_payment_method(),
                    'payment_method_title' => $order->get_payment_method_title(),
                    'total_amount' => $order->get_total(),
                    'currency' => $order->get_currency(),
                ]
            ];
        }
    }

    // Get gateway name for payment events
    $gateway = odcm_normalize_gateway_name($order->get_payment_method());
    $payment_method = $order->get_payment_method_title();
    $order_total = (float) $order->get_total();
    $currency = $order->get_currency();
    $order_id = $order->get_id();
    $order_status = $order->get_status();

    // Create rich components array matching block checkout format
    $components = [
        [
            'k' => 'checkout_complete_' . str_replace('.', '_', (string)$checkout_timestamp),
            'event_type' => 'checkout_processed',
            'ts' => $checkout_timestamp, // REAL checkout timestamp from order creation
            'label' => 'Checkout Completed',
            'level' => 'info',
            'data' => [
                // Match BlockCheckoutCompatibility data format exactly
                'order_id' => (int) $order_id,
                'status' => (string) $order_status,
                'payment_method' => (string) $payment_method,
                'total' => (float) $order_total,
                'currency' => (string) $currency,
                'checkout_type' => $checkout_type, // 'standard' for traditional checkout
            ]
        ],
        [
            'k' => 'cart_analysis_' . str_replace('.', '_', (string)$checkout_timestamp),
            'event_type' => 'order_loaded',
            'ts' => $checkout_timestamp,
            'label' => 'Cart Analysis',
            'level' => 'info',
            'data' => $checkout_context['cart_analysis'] ?? []
        ],
        [
            'k' => 'payment_event_' . str_replace('.', '_', (string)$checkout_timestamp),
            'event_type' => 'payment.' . $gateway . '.checkout_processed',
            'ts' => $checkout_timestamp,
            'label' => 'Payment Event',
            'level' => 'info',
            'data' => $checkout_context['payment_context'] ?? []
        ]
    ];

    // Technical data for rawData (not duplicated in UI)
    $technical_data = [
        'checkout_type' => $checkout_type,
        'source' => odcm_determine_change_source(),
        'real_checkout_timestamp' => $checkout_timestamp,
        'date_created' => $order->get_date_created()->format('c'),
    ];
    
    // Include original checkout context for technical reference
    if (!empty($checkout_context)) {
        $technical_data['checkout_context'] = $checkout_context;
    }

    return new \OrderDaemon\CompletionManager\Core\Events\UniversalEvent([
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
        'receivedAt' => current_time('c'), // Required for validation
        'idempotencyKey' => 'checkout_processed_' . $order_id . '_' . time(), // Required for validation and deduplication
        'components' => $components, // Rich components for timeline rendering
        'rawData' => $technical_data // Technical data only
    ]);
}

/**
 * Get real checkout timestamp from WooCommerce order data
 *
 * @param \WC_Order $order WooCommerce order object
 * @return float Checkout timestamp
 */
function odcm_get_real_checkout_timestamp(\WC_Order $order): float {
    // Checkout events should use order creation time
    return (float) $order->get_date_created()->getTimestamp();
}

/**
 * Normalize gateway name to standard format
 *
 * @param string $payment_method WooCommerce payment method ID
 * @return string Normalized gateway name
 */
function odcm_normalize_gateway_name(string $payment_method): string {
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
function odcm_determine_change_source(): string {
    try {
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
    } catch (\Throwable $e) {
        return 'system';
    }
}

// Hook the checkout completion processing handler
add_action('odcm_process_checkout_completion', 'odcm_handle_checkout_completion_processing', 10, 1);

/**
 * Processes payment completion in the background.
 *
 * This handler function is executed by Action Scheduler when a payment completion is processed.
 * It handles the heavy processing that was moved from the synchronous payment hooks to protect revenue.
 *
 * @param mixed $args The arguments passed to the action (can be array or int).
 * @return void
 * @since 1.0.0
 */
function odcm_handle_payment_completion_processing($args) {
    // Handle Action Scheduler calling convention
    if (is_array($args)) {
        $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
        $payment_gateway = isset($args['payment_gateway']) ? sanitize_text_field($args['payment_gateway']) : '';
    } else {
        // Action Scheduler passes order ID directly
        $order_id = (int) $args;
        $payment_gateway = '';
    }

    if ($order_id <= 0) {
        error_log('ODCM_BACKGROUND: Invalid order ID for payment completion processing');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
        error_log("ODCM_BACKGROUND: Order #{$order_id} not found for payment completion processing");
        return;
    }

    try {
        // TEMPORARY DEBUG: Log background processing execution
        error_log("ODCM_DEBUG_TRACE: Background Handler - Processing payment completion for Order #{$order_id}");
        error_log("ODCM_DEBUG_TRACE: Background Handler - Order status: " . $order->get_status());
        error_log("ODCM_DEBUG_TRACE: Background Handler - Payment method: " . $order->get_payment_method());

        // Use provided gateway or get from order
        $gateway = $payment_gateway ?: $order->get_payment_method();

        // Create a universal event for payment completion
        $universal_event_data = [
            'eventType' => 'payment_completed',
            'sourceGateway' => $gateway ?: 'unknown',
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order_id,
            'transactionID' => $order->get_transaction_id(),
            'status' => 'completed',
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => 'payment_completed_' . $order_id . '_' . time(),
            'rawData' => [
                'order_status' => $order->get_status(),
                'payment_method' => $gateway,
                'customer_id' => $order->get_customer_id(),
                'source' => 'payment_completion',
                'trigger' => 'background_processing'
            ]
        ];

        error_log("ODCM_DEBUG_TRACE: Background Handler - Created payment completion event data");

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        error_log("ODCM_DEBUG_TRACE: Background Handler - About to call processEvent() for payment");
        $result = $processor->processEvent($universal_event_data);
        error_log("ODCM_DEBUG_TRACE: Background Handler - Payment processEvent() returned: " . ($result ? 'TRUE' : 'FALSE'));

        // Log successful background processing
        error_log("ODCM_BACKGROUND: Payment completion processed successfully for order #{$order_id}");

        // Log the processing result for debugging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_event(
                $result ? 'Payment completion processed successfully' : 'Payment completion completed with no matching rules',
                [
                    'order_id' => $order_id,
                    'processing_result' => $result,
                    'payment_gateway' => $gateway,
                    'order_status' => $order->get_status(),
                    'payment_method' => $order->get_payment_method(),
                    'order_total' => $order->get_total()
                ],
                $order_id,
                $result ? 'success' : 'info',
                'payment_completion_processed'
            );
        }

    } catch (\Throwable $e) {
        // Log processing error but don't let it break the system
        error_log("ODCM_BACKGROUND: Payment completion processing failed for order #{$order_id}: " . $e->getMessage());
        
        odcm_log_event(
            'Payment completion processing failed with exception: ' . $e->getMessage(),
            [
                'order_id' => $order_id,
                'payment_gateway' => $payment_gateway,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'order_status' => $order ? $order->get_status() : 'unknown'
            ],
            $order_id,
            'error',
            'payment_completion_exception'
        );
        
        // Emergency fallback: schedule traditional order check
        try {
            as_enqueue_async_action('odcm_process_order_check', [
                'order_id' => $order_id
            ], 'odcm-emergency-processing');
            error_log("ODCM_BACKGROUND: Scheduled emergency fallback processing for order #{$order_id}");
        } catch (\Throwable $fallback_error) {
            error_log("ODCM_BACKGROUND: Emergency fallback failed for order #{$order_id}: " . $fallback_error->getMessage());
        }
    }
}

// Hook the payment completion processing handler
add_action('odcm_process_payment_completion', 'odcm_handle_payment_completion_processing', 10, 1);

/**
 * Process queued audit log entry (async handler)
 * 
 * @param mixed $args Contains 'queue_id' or queue_id string directly
 * @return void
 */
function odcm_process_queued_log_entry($args): void
{
    global $wpdb;
    
    // Handle both array and direct string arguments from Action Scheduler
    if (is_array($args)) {
        if (empty($args['queue_id'])) {
            error_log('ODCM: odcm_process_queued_log_entry called without queue_id in array');
            return;
        }
        $queue_id = $args['queue_id'];
    } elseif (is_string($args)) {
        // Action Scheduler passed queue_id directly
        $queue_id = $args;
    } else {
        error_log('ODCM: odcm_process_queued_log_entry called with invalid argument type: ' . gettype($args));
        return;
    }
    
    if (empty($queue_id)) {
        error_log('ODCM: odcm_process_queued_log_entry called with empty queue_id');
        return;
    }
    
    // Retrieve queued event
    $queue_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue 
         WHERE queue_id = %s AND status = 'pending'",
        $queue_id
    ));
    
    if (!$queue_entry) {
        error_log("ODCM: Queue entry {$queue_id} not found or already processed");
        return;
    }
    
    try {
        // Decode event data
        $event_data = json_decode($queue_entry->event_data, true);
        
        if (!is_array($event_data)) {
            throw new \Exception('Invalid event_data JSON');
        }
        
        // Extract envelope
        $envelope = $event_data['envelope'] ?? [];
        
        // Create payload ID if we have envelope data
        $payload_id = null;
        if (!empty($envelope)) {
            $payload_result = $wpdb->insert(
                "{$wpdb->prefix}odcm_audit_log_payloads",
                ['payload' => wp_json_encode($envelope)]
            );
            
            if ($payload_result !== false) {
                $payload_id = $wpdb->insert_id;
            }
        }
        
        // Create final audit log entry
        $log_result = $wpdb->insert(
            "{$wpdb->prefix}odcm_audit_log",
            [
                'timestamp' => $event_data['timestamp'],
                'status' => $event_data['status'],
                'summary' => $event_data['summary'],
                'order_id' => $event_data['order_id'] ?? null,
                'event_type' => $event_data['event_type'],
                'source' => $event_data['source'] ?? 'system',
                'log_category' => 'custom',
                'is_test' => $event_data['is_test'] ? 1 : 0,
                'process_id' => $event_data['process_id'] ?? null,
                'payload_id' => $payload_id,
            ]
        );
        
        if ($log_result === false) {
            throw new \Exception('Failed to insert audit log: ' . $wpdb->last_error);
        }
        
        // Mark queue entry as processed
        $wpdb->update(
            "{$wpdb->prefix}odcm_audit_log_queue",
            [
                'status' => 'processed',
                'processed_at' => current_time('mysql')
            ],
            ['queue_id' => $queue_id]
        );
        
        // Debug logging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM: Successfully processed queue entry {$queue_id}, created log ID: {$wpdb->insert_id}");
        }
        
    } catch (\Throwable $e) {
        // Update queue entry with error
        $retry_count = (int) $queue_entry->retry_count + 1;
        
        $wpdb->update(
            "{$wpdb->prefix}odcm_audit_log_queue",
            [
                'retry_count' => $retry_count,
                'last_error' => $e->getMessage(),
                'status' => $retry_count >= 3 ? 'failed' : 'pending'  // Max 3 retries
            ],
            ['queue_id' => $queue_id]
        );
        
        error_log("ODCM: Error processing queue entry {$queue_id}: " . $e->getMessage());
        
        // Re-schedule if under retry limit
        if ($retry_count < 3) {
            as_schedule_single_action(
                time() + (60 * $retry_count),  // Exponential backoff
                'odcm_process_queued_log_entry',
                ['queue_id' => $queue_id],
                'odcm-logs'
            );
        }
    }
}

// Register the handler
add_action('odcm_process_queued_log_entry', 'odcm_process_queued_log_entry', 10, 1);

/**
 * Clean up old processed queue entries
 * 
 * Runs daily via Action Scheduler
 * 
 * @return void
 */
function odcm_cleanup_audit_log_queue(): void
{
    global $wpdb;
    
    // Delete processed entries older than 24 hours
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->prefix}odcm_audit_log_queue 
         WHERE status = 'processed' 
         AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    
    if ($deleted !== false && $deleted > 0) {
        error_log("ODCM: Cleaned up {$deleted} processed queue entries");
    }
    
    // Delete failed entries older than 30 days
    $deleted_failed = $wpdb->query(
        "DELETE FROM {$wpdb->prefix}odcm_audit_log_queue 
         WHERE status = 'failed' 
         AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    
    if ($deleted_failed !== false && $deleted_failed > 0) {
        error_log("ODCM: Cleaned up {$deleted_failed} failed queue entries");
    }
}

// Schedule daily cleanup
add_action('odcm_cleanup_audit_log_queue', 'odcm_cleanup_audit_log_queue');

/**
 * Schedule the queue cleanup recurring action
 * 
 * @return void
 */
function odcm_schedule_queue_cleanup(): void
{
    if (!function_exists('as_next_scheduled_action')) {
        return;
    }
    
    if (!as_next_scheduled_action('odcm_cleanup_audit_log_queue')) {
        as_schedule_recurring_action(
            time(),
            86400, // 24 hours in seconds
            'odcm_cleanup_audit_log_queue',
            [],
            'odcm-maintenance'
        );
    }
}
add_action('init', 'odcm_schedule_queue_cleanup');

/**
 * AJAX handler for updating the order of rules.
 *
 * This function processes the AJAX request sent when a user changes the order of rules
 * via drag and drop. It updates the menu_order field for each rule to reflect the new priority.
 *
 * @since 1.0.0
 * @return void
 */
function odcm_update_rule_order_handler() {
    // Verify nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'odcm_update_rule_order')) {
        wp_send_json_error([
            'message' => __('actions.ajax.security_check_failed', 'order-daemon')
        ]);
        return;
    }

    // Check user permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error([
            'message' => __('actions.ajax.no_action_permission', 'order-daemon')
        ]);
        return;
    }

    // Validate rule IDs
    if (!isset($_POST['rule_ids']) || !is_array($_POST['rule_ids'])) {
        wp_send_json_error([
            'message' => __('actions.validation.invalid_data', 'order-daemon')
        ]);
        return;
    }

    $rule_ids = array_map('absint', $_POST['rule_ids']);

    if (empty($rule_ids)) {
        wp_send_json_error([
            'message' => __('actions.validation.no_valid_rule_ids', 'order-daemon')
        ]);
        return;
    }

    // Update the priority (menu_order) for each rule
    $priority_map = [];
    foreach ($rule_ids as $position => $rule_id) {
        // Position is zero-based, but we want priority to start at 1
        $priority = $position + 1;
        
        wp_update_post([
            'ID' => $rule_id,
            'menu_order' => $priority
        ]);
        
        // Add to priority map for client-side update
        $priority_map[] = [
            'id' => $rule_id,
            'priority' => $priority
        ];
    }

    // Send success response with updated priorities
    wp_send_json_success([
        'message' => __('actions.ajax.rule_order_update_success', 'order-daemon'),
        'priority_map' => $priority_map
    ]);
}

// Register the AJAX handler for logged-in users
add_action('wp_ajax_odcm_update_rule_order', 'odcm_update_rule_order_handler');

/**
 * Retrieve queued checkout data for an order
 *
 * @param int $order_id Order ID
 * @return array|null Queued data or null if not found
 */
function odcm_get_queued_checkout_data(int $order_id): ?array {
    global $wpdb;
    
    // Get queue ID from order meta
    $queue_id = get_post_meta($order_id, '_odcm_checkout_queue_id', true);
    if (!$queue_id) {
        return null;
    }
    
    // Retrieve data from queue table
    $queue_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue 
         WHERE queue_id = %s AND status = 'pending'",
        $queue_id
    ));
    
    if (!$queue_entry) {
        return null;
    }
    
    // Decode event data
    $event_data = json_decode($queue_entry->event_data, true);
    if (!is_array($event_data)) {
        return null;
    }
    
    // Verify this is actually checkout data by checking the JSON payload
    if (!isset($event_data['checkout_type'])) {
        return null;
    }
    
    // Add queue metadata
    $event_data['queue_id'] = $queue_id;
    $event_data['queue_created_at'] = $queue_entry->created_at;
    
    return $event_data;
}

/**
 * Synthesize checkout Universal Event from queued data with original timestamp
 *
 * @param \WC_Order $order WooCommerce order object
 * @param array $queued_data Queued checkout data
 * @return \OrderDaemon\CompletionManager\Core\Events\UniversalEvent
 */
function odcm_synthesize_checkout_from_queued_data(\WC_Order $order, array $queued_data): \OrderDaemon\CompletionManager\Core\Events\UniversalEvent {
    // Extract data from queue
    $order_id = $order->get_id();
    $checkout_type = $queued_data['checkout_type'] ?? 'standard';
    $checkout_timestamp = $queued_data['checkout_timestamp'] ?? (float) $order->get_date_created()->getTimestamp();
    $checkout_context = $queued_data['checkout_context'] ?? [];
    $order_data = $queued_data['order_data'] ?? [];
    
    // Use queued order data when available, fallback to current order data
    $payment_method = $order_data['payment_method_title'] ?? $order->get_payment_method_title();
    $order_total = (float) ($order_data['total'] ?? $order->get_total());
    $currency = $order_data['currency'] ?? $order->get_currency();
    $order_status = $order_data['status'] ?? $order->get_status();
    
    // Get gateway name for payment events
    $gateway = odcm_normalize_gateway_name($order_data['payment_method'] ?? $order->get_payment_method());
    
    // Create rich components array using ORIGINAL TIMESTAMP from queue
    $components = [
        [
            'k' => 'checkout_complete_' . str_replace('.', '_', (string)$checkout_timestamp),
            'event_type' => 'checkout_processed',
            'ts' => $checkout_timestamp, // ORIGINAL checkout timestamp from queue!
            'label' => 'Checkout Completed',
            'level' => 'info',
            'data' => [
                'order_id' => (int) $order_id,
                'status' => (string) $order_status,
                'payment_method' => (string) $payment_method,
                'total' => (float) $order_total,
                'currency' => (string) $currency,
                'checkout_type' => $checkout_type,
            ]
        ],
        [
            'k' => 'cart_analysis_' . str_replace('.', '_', (string)$checkout_timestamp),
            'event_type' => 'order_loaded',
            'ts' => $checkout_timestamp, // ORIGINAL timestamp
            'label' => 'Cart Analysis',
            'level' => 'info',
            'data' => $checkout_context['cart_analysis'] ?? []
        ],
        [
            'k' => 'payment_event_' . str_replace('.', '_', (string)$checkout_timestamp),
            'event_type' => 'payment.' . $gateway . '.checkout_processed',
            'ts' => $checkout_timestamp, // ORIGINAL timestamp
            'label' => 'Payment Event',
            'level' => 'info',
            'data' => $checkout_context['payment_context'] ?? []
        ]
    ];

    // Technical data for rawData
    $technical_data = [
        'checkout_type' => $checkout_type,
        'source' => $queued_data['source'] ?? 'system',
        'real_checkout_timestamp' => $checkout_timestamp,
        'queued_at' => $queued_data['queued_at'] ?? null,
        'processed_from_queue' => true,
    ];
    
    // Include original checkout context for technical reference
    if (!empty($checkout_context)) {
        $technical_data['checkout_context'] = $checkout_context;
    }

    return new \OrderDaemon\CompletionManager\Core\Events\UniversalEvent([
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
        'idempotencyKey' => 'checkout_processed_' . $order_id . '_' . (int)$checkout_timestamp,
        'components' => $components, // Rich components with ORIGINAL timestamps
        'rawData' => $technical_data
    ]);
}

/**
 * Clean up processed queue data
 *
 * @param int $order_id Order ID
 * @param string $queue_id Queue ID
 * @return void
 */
function odcm_cleanup_processed_queue_data(int $order_id, string $queue_id): void {
    global $wpdb;
    
    // Mark queue entry as processed
    $wpdb->update(
        $wpdb->prefix . 'odcm_audit_log_queue',
        [
            'status' => 'processed',
            'processed_at' => current_time('mysql')
        ],
        ['queue_id' => $queue_id]
    );
    
    // Remove order meta flags
    delete_post_meta($order_id, '_odcm_checkout_queue_id');
    delete_post_meta($order_id, '_odcm_checkout_data_queued');
    
    error_log("ODCM_BACKGROUND: Cleaned up queue data for order #{$order_id}, queue ID: {$queue_id}");
}
