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
        'timestamp_minute' => date('Y-m-d H:i', strtotime($log_data['timestamp']))
    ]));
    $log_data['duplicate_hash'] = $duplicate_hash;

    $query = "INSERT IGNORE INTO {$wpdb->prefix}odcm_audit_log (" . 
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

        // Log the result for debugging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_event(
                $result ? 'Order check processed successfully' : 'Order check completed with no matching rules',
                [
                    'order_id' => $order_id,
                    'processing_result' => $result,
                    'order_status' => $order->get_status(),
                    'payment_method' => $order->get_payment_method(),
                    'order_total' => $order->get_total()
                ],
                $order_id,
                $result ? 'success' : 'info',
                'order_check_completed'
            );
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
    // Validate arguments
    if (!is_array($args) || !isset($args['event'])) {
        // Log error using unified logging system
        odcm_log_event(
            'Payment gateway event processing error: Missing data',
            [
                'args' => $args,
                'error' => 'Missing event data in arguments'
            ],
            null,
            'error',
            'universal_event_argument_error'
        );
        return;
    }

    $event_data = $args['event'];

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
                
            odcm_log_event(
                $message,
                [
                    'event_type' => $event_data['eventType'] ?? 'unknown',
                    'source_gateway' => $event_data['sourceGateway'] ?? 'unknown',
                    'result' => $result,
                    'idempotency_key' => $event_data['idempotencyKey'] ?? 'unknown'
                ],
                null,
                $result ? 'success' : 'info',
                'universal_event_processing_result'
            );
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
 * Processes checkout completion in the background.
 *
 * This handler function is executed by Action Scheduler when a checkout completion is processed.
 * It handles the heavy processing that was moved from the synchronous checkout hooks to protect revenue.
 *
 * @param mixed $args The arguments passed to the action (can be array or int).
 * @return void
 * @since 1.0.0
 */
function odcm_handle_checkout_completion_processing($args) {
    // Handle Action Scheduler calling convention
    if (is_array($args)) {
        $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
        $checkout_type = isset($args['checkout_type']) ? sanitize_text_field($args['checkout_type']) : 'standard';
    } else {
        // Action Scheduler passes order ID directly
        $order_id = (int) $args;
        $checkout_type = 'standard';
    }

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
        // TEMPORARY DEBUG: Log background processing execution
        error_log("ODCM_DEBUG_TRACE: Background Handler - Processing checkout completion for Order #{$order_id}");
        error_log("ODCM_DEBUG_TRACE: Background Handler - Order status: " . $order->get_status());
        error_log("ODCM_DEBUG_TRACE: Background Handler - Payment method: " . $order->get_payment_method());

        // Create a universal event for checkout completion
        $universal_event_data = [
            'eventType' => 'checkout_processed',
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
            'idempotencyKey' => 'checkout_processed_' . $order_id . '_' . time(),
            'rawData' => [
                'order_status' => $order->get_status(),
                'payment_method' => $order->get_payment_method(),
                'customer_id' => $order->get_customer_id(),
                'checkout_type' => $checkout_type,
                'source' => 'checkout_completion',
                'trigger' => 'background_processing'
            ]
        ];

        error_log("ODCM_DEBUG_TRACE: Background Handler - Created checkout completion event data");

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        error_log("ODCM_DEBUG_TRACE: Background Handler - About to call processEvent() for checkout");
        $result = $processor->processEvent($universal_event_data);
        error_log("ODCM_DEBUG_TRACE: Background Handler - Checkout processEvent() returned: " . ($result ? 'TRUE' : 'FALSE'));

        // Log successful background processing
        error_log("ODCM_BACKGROUND: Checkout completion processed successfully for order #{$order_id}");

        // Log the processing result for debugging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_event(
                $result ? 'Checkout completion processed successfully' : 'Checkout completion completed with no matching rules',
                [
                    'order_id' => $order_id,
                    'processing_result' => $result,
                    'checkout_type' => $checkout_type,
                    'order_status' => $order->get_status(),
                    'payment_method' => $order->get_payment_method(),
                    'order_total' => $order->get_total()
                ],
                $order_id,
                $result ? 'success' : 'info',
                'checkout_completion_processed'
            );
        }

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
