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
function odcm_handle_log_processing(array $args) {
    global $wpdb;

    // Extract event_data from args
    $event_data = $args['event_data'] ?? null;
    if (!is_array($event_data) || !isset($event_data['summary'], $event_data['event_type'])) {
        error_log("ODCM: Invalid event_data in log processing");
        return;
    }

    // Extract envelope for payload
    $envelope = $event_data['envelope'] ?? [];
    $sanitized_envelope = odcm_sanitize_payload_for_logging($envelope);

    // Insert payload first
    $payload_insert = $wpdb->insert(
        $wpdb->prefix . 'odcm_audit_log_payloads',
        ['payload' => json_encode($sanitized_envelope)]
    );

    if ($payload_insert === false) {
        error_log("ODCM: Failed to insert payload: " . $wpdb->last_error);
        return;
    }

    $payload_id = $wpdb->insert_id;

    // Prepare log data
    $log_data = [
        'summary'    => sanitize_text_field($event_data['summary']),
        'event_type' => sanitize_key($event_data['event_type']),
        'status'     => sanitize_key($event_data['status'] ?? 'info'),
        'payload_id' => $payload_id,
        'timestamp'  => current_time('mysql'),
        'is_test'    => !empty($event_data['is_test']) ? 1 : 0,
    ];

    // Add optional fields
    if (!empty($event_data['order_id'])) {
        $log_data['order_id'] = (int) $event_data['order_id'];
    }
    if (!empty($event_data['process_id'])) {
        $log_data['process_id'] = sanitize_text_field($event_data['process_id']);
    }
    if (!empty($event_data['source'])) {
        $log_data['source'] = sanitize_text_field($event_data['source']);
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

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        $result = $processor->processEvent($universal_event_data);

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
