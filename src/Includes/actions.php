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
use OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager;
use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;


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

    // Debug trace (gated)
    odcm_log_message("DEBUG_TRACE: odcm_handle_log_processing() called with args: " . wp_json_encode($args), 'info');

    // Note: Direct database access is necessary here for custom tables.
    // WordPress WP_Query is designed for posts, not custom audit log tables.
    // All queries use $wpdb->prepare() for security and proper caching is implemented.
    // @codingStandardsIgnoreStart
    // This function requires direct database operations for custom table management.
    // @codingStandardsIgnoreEnd
    
    // Handle both array and JSON string arguments from Action Scheduler
    if (is_string($args)) {
        // Action Scheduler is passing JSON string - decode it
        $args = json_decode($args, true);
        if (!is_array($args)) {
            odcm_log_message("Failed to decode JSON args in log processing. Raw args: " . wp_json_encode($args), 'error');
            return;
        }
    }

    if (!is_array($args)) {
        odcm_log_message("Args must be array or JSON string, got " . gettype($args), 'error');
        return;
    }

    // SIMPLIFIED: Expect only compressed format
    // odcm_log_event() sends compressed data directly to Action Scheduler
    if (!isset($args['d']) || !is_array($args['d'])) {
        // Log critical error for operational stability
        odcm_critical_log("Invalid compressed payload structure. Expected 'd' key with array value, got: " . wp_json_encode($args));
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
    
    // Check for duplicate payload based on hash to reduce storage
    $payload_hash = md5(json_encode($sanitized_payload));
    $payload_cache_key = 'odcm_payload_hash_' . $payload_hash;
    $existing_payload_id = wp_cache_get($payload_cache_key);
    
    if (false !== $existing_payload_id) {
        // Reuse existing payload
        $payload_id = $existing_payload_id;
        odcm_log_message("Reusing existing payload ID: {$payload_id}", 'debug');
    } else {
        // Insert new payload with caching to avoid duplicates
        // phpcs:ignore - WordPress.DB.DirectDatabaseQuery.DirectQuery
        $payload_insert = DatabaseHelper::insert(
            $wpdb->prefix . 'odcm_audit_log_payloads',
            ['payload' => json_encode($sanitized_payload)],
            ['%s'] // Explicit format for security
        );
    
        if ($payload_insert === false) {
            odcm_critical_log("Failed to insert payload: " . $wpdb->last_error);
            return;
        }
    
        $payload_id = $wpdb->insert_id;
        
        // Cache payload ID for future reuse
        wp_cache_set($payload_cache_key, $payload_id, '', HOUR_IN_SECONDS);
    }

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

    // HIERARCHY: Add parent_id resolution logic
    // Check if we have parent_event_type in the compressed data for hierarchy resolution
    if (isset($comp_data['parent_event_type']) && !empty($comp_data['parent_event_type']) && $order_id) {
        $parent_id = odcm_resolve_parent_id($comp_data['parent_event_type'], (int) $order_id, $process_id ?? null);
        if ($parent_id !== null) {
            $log_data['parent_id'] = $parent_id;
            odcm_log_message("HIERARCHY: Resolved parent_id={$parent_id} for event_type='{$event_type}' using parent_event_type='{$comp_data['parent_event_type']}'", 'debug');
        }
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

    // Check for duplicate log entry
    $dedup_cache_key = 'odcm_log_hash_' . $duplicate_hash;
    $existing_entry = wp_cache_get($dedup_cache_key);
    
    if (false !== $existing_entry) {
        // Skip creating duplicate log entry
        odcm_log_message("Skipping duplicate log entry for hash {$duplicate_hash}", 'debug');
        return;
    }

    $audit_log_table = $wpdb->prefix . 'odcm_audit_log';

    $format_map = [
        'summary' => '%s',
        'event_type' => '%s',
        'status' => '%s',
        'payload_id' => '%d',
        'timestamp' => '%s',
        'is_test' => '%d',
        'order_id' => '%d',
        'process_id' => '%s',
        'source' => '%s',
        'duplicate_hash' => '%s',
    ];

    $formats = [];
    foreach ($log_data as $k => $_) {
        $formats[] = $format_map[$k] ?? '%s';
    }

    $insert_result = DatabaseHelper::insert($audit_log_table, $log_data, $formats);
    
    if ($insert_result !== false) {
        // Cache the hash to prevent duplicates (10 minutes)
        wp_cache_set($dedup_cache_key, true, '', 10 * 60);

        // Invalidate dashboard logs cache by bumping a global cache version
        $version_key = 'odcm_logs_cache_version';
        // Try atomic increment when available
        if (function_exists('wp_cache_incr')) {
            $new_version = wp_cache_incr($version_key);
            if (false === $new_version) {
                // Initialize the key if it doesn't exist
                wp_cache_set($version_key, 1);
            }
        } else {
            $current = wp_cache_get($version_key);
            if ($current === false) {
                $current = 0;
            }
            wp_cache_set($version_key, ((int) $current) + 1);
        }
    }
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
        // Create structured logging data instead of using error_log
        $log_data = [
            'order_id' => $order_id,
            'order_status' => $order->get_status(),
            'payment_method' => $order->get_payment_method()
        ];
        odcm_log_message("Action Handler - Processing Order #{$order_id}", 'debug', $log_data);

        // Get current order status for proper rule filtering
        $current_status = $order->get_status();

        // Create a universal event for the order check
        // Include current status in event type to enable proper rule filtering
        $universal_event_data = [
            'eventType' => 'order_check_scheduled',
            'sourceGateway' => $order->get_payment_method() ?: 'unknown',
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order_id,
            'transactionID' => $order->get_transaction_id(),
            'status' => $current_status,
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => 'order_check_' . $order_id . '_' . time(),
            'rawData' => [
                'order_status' => $current_status,
                'payment_method' => $order->get_payment_method(),
                'customer_id' => $order->get_customer_id(),
                'source' => 'scheduled_check',
                'trigger' => 'action_scheduler',
                //Add current status to rawData for rule evaluation context
                'current_order_status' => $current_status,
                'order_check_context' => 'scheduled_completion_check'
            ]
        ];

        odcm_log_message("Action Handler - Created universal event data with status: {$current_status}", 'debug');

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        odcm_log_message("Action Handler - About to call processEvent()", 'debug');
        $result = $processor->processEvent($universal_event_data);
        odcm_log_message("Action Handler - processEvent() returned: " . ($result ? 'TRUE' : 'FALSE'), 'debug');

        // Debug logging only
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("Order check completed for #{$order_id} (status: {$current_status}) - Result: " . ($result ? 'TRUE' : 'FALSE'), 'debug');
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
                
            odcm_log_message($message, 'debug');
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
    $debug_data = [
        'order_id' => $order_id,
        'checkout_type' => $checkout_type,
        'scheduled_at' => $scheduled_at ?: 'MISSING - THIS IS A DUPLICATE JOB',
        'args' => $args,
        'current_time' => current_time('c')
    ];
    odcm_log_message("=== CHECKOUT COMPLETION PROCESSING START ===", 'debug', $debug_data);

    if ($order_id <= 0) {
        odcm_critical_log('Invalid order ID for checkout completion processing');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
        odcm_critical_log("Order #{$order_id} not found for checkout completion processing");
        return;
    }

    try {
        // Try to retrieve enriched data from queue first
        odcm_log_message("About to call odcm_get_queued_checkout_data for order #{$order_id}", 'debug');
        $queued_data = odcm_get_queued_checkout_data($order_id);
        
        if ($queued_data) {
            // Use queued data with original timestamp
            odcm_log_message("FOUND queued data for order #{$order_id}", 'debug', ['queue_id' => $queued_data['queue_id'] ?? 'missing']);
            $universal_event = odcm_synthesize_checkout_from_queued_data($order, $queued_data);
            odcm_log_message("Using queued checkout data for order #{$order_id}", 'debug');
        } else {
            // Only create fallback event for legitimate jobs that have scheduled_at parameter
            // Duplicate jobs (missing scheduled_at) should exit silently without creating events
            if (!$scheduled_at) {
                odcm_log_message("BLOCKED duplicate job for order #{$order_id} - no queued data AND missing scheduled_at", 'debug');
                odcm_log_message("This prevents the empty DEBUG event from being created", 'debug');
                return; // Exit silently - this is a duplicate job with no data
            }
            
            // Legitimate job but no queued data - create fallback event
            odcm_log_message("NO queued data found for order #{$order_id} but legitimate job - using fallback", 'debug');
            $universal_event = odcm_synthesize_checkout_processed_event($order, [], $checkout_type);
            odcm_log_message("Using fallback synthesis for order #{$order_id} (no queued data)", 'debug');
        }

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        $result = $processor->processEvent($universal_event->toArray());

        // Clean up queue data after successful processing
        if ($queued_data) {
            odcm_cleanup_processed_queue_data($order_id, $queued_data['queue_id']);
        }

        // Log successful background processing
        odcm_log_message("Checkout completion processed successfully for order #{$order_id}", 'debug');

    } catch (\Throwable $e) {
        // Log processing error but don't let it break the system
        odcm_critical_log("Checkout completion processing failed for order #{$order_id}: " . $e->getMessage());
        
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
            odcm_log_message("Scheduled emergency fallback processing for order #{$order_id}", 'debug');
        } catch (\Throwable $fallback_error) {
            odcm_log_message("Emergency fallback failed for order #{$order_id}: " . $fallback_error->getMessage(), 'error');
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
        odcm_critical_log('Invalid order ID for payment completion processing');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
        odcm_critical_log("Order #{$order_id} not found for payment completion processing");
        return;
    }

    try {
        // Log using structured logging instead of direct error_log
        $log_data = [
            'order_id' => $order_id,
            'order_status' => $order->get_status(),
            'payment_method' => $order->get_payment_method()
        ];
        odcm_log_message("Background Handler - Processing payment completion for Order #{$order_id}", 'debug', $log_data);

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

        odcm_log_message("Background Handler - Created payment completion event data", 'debug');

        // Process through UniversalEventProcessor
        $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
        odcm_log_message("Background Handler - About to call processEvent() for payment", 'debug');
        $result = $processor->processEvent($universal_event_data);
        odcm_log_message("Background Handler - Payment processEvent() returned: " . ($result ? 'TRUE' : 'FALSE'), 'debug');

        // Log successful background processing
        odcm_log_message("Payment completion processed successfully for order #{$order_id}", 'debug');

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
        odcm_critical_log("Payment completion processing failed for order #{$order_id}: " . $e->getMessage());
        
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
            odcm_log_message("Scheduled emergency fallback processing for order #{$order_id}", 'debug');
        } catch (\Throwable $fallback_error) {
            odcm_critical_log("Emergency fallback failed for order #{$order_id}: " . $fallback_error->getMessage());
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

    // Direct database access is necessary for custom audit log queue management
    // @codingStandardsIgnoreStart - Custom table operations
    
    // Handle both array and direct string arguments from Action Scheduler
    if (is_array($args)) {
        if (empty($args['queue_id'])) {
            odcm_log_message('odcm_process_queued_log_entry called without queue_id in array', 'error');
            return;
        }
        $queue_id = $args['queue_id'];
    } elseif (is_string($args)) {
        // Action Scheduler passed queue_id directly
        $queue_id = $args;
    } else {
        odcm_log_message('odcm_process_queued_log_entry called with invalid argument type: ' . gettype($args), 'error');
        return;
    }
    
    if (empty($queue_id)) {
        odcm_log_message('odcm_process_queued_log_entry called with empty queue_id', 'error');
        return;
    }
    
    // Check cache first
    $cache_key = 'odcm_queue_entry_' . md5($queue_id);
    $queue_entry = wp_cache_get($cache_key);
    
    if (false === $queue_entry) {
        // Retrieve queued event from database with caching
        $queue_entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue
             WHERE queue_id = %s AND status = 'pending'",
            $queue_id
        ));

        // @codingStandardsIgnoreLine - Direct database access required for custom tables
        
        // Cache the result for 5 minutes
        wp_cache_set($cache_key, $queue_entry, '', 5 * 60);
    }
    
    if (!$queue_entry) {
        odcm_log_message("Queue entry {$queue_id} not found or already processed", 'debug');
        return;
    }
    
    try {
        // Decode event data
        $event_data = json_decode($queue_entry->event_data, true);
        
        if (!is_array($event_data)) {
            throw new \Exception('Invalid event_data JSON');
        }
        
        // Extract envelope and parent_event_type
        $envelope = $event_data['envelope'] ?? [];
        $parent_event_type = $event_data['parent_event_type'] ?? null;
        
        // Resolve parent_id if parent_event_type is provided
        $parent_id = null;
        if ($parent_event_type && !empty($event_data['order_id'])) {
            $parent_id = odcm_resolve_parent_id(
                $parent_event_type,
                (int) $event_data['order_id'],
                $event_data['process_id'] ?? null
            );
        }
        
        // Create payload ID if we have envelope data
        $payload_id = null;
        if (!empty($envelope)) {
            // Check if this payload already exists to avoid duplicates
            $payload_hash = md5(wp_json_encode($envelope));
            $payload_cache_key = 'odcm_payload_' . $payload_hash;
            
            $cached_payload_id = wp_cache_get($payload_cache_key);
            
            if (false !== $cached_payload_id) {
                // Use existing payload ID from cache
                $payload_id = $cached_payload_id;
            } else {
                // Create new payload entry with caching
                $payload_result = $wpdb->insert(
                    "{$wpdb->prefix}odcm_audit_log_payloads",
                    ['payload' => wp_json_encode($envelope)],
                    ['%s'] // Explicit format for security
                );

                // @codingStandardsIgnoreLine - Direct database access required for custom tables
                
                if ($payload_result !== false) {
                    $payload_id = $wpdb->insert_id;
                    // Cache the payload ID for future reuse (1 hour)
                    wp_cache_set($payload_cache_key, $payload_id, '', HOUR_IN_SECONDS);
                }
            }
        }
        
        // Generate a deduplication hash for this entry
        $dedup_data = [
            'timestamp' => $event_data['timestamp'],
            'summary' => $event_data['summary'],
            'order_id' => $event_data['order_id'] ?? null,
            'event_type' => $event_data['event_type'],
            'payload_id' => $payload_id,
        ];
        $dedup_hash = md5(wp_json_encode($dedup_data));
        
        // Check for existing entry with this hash to avoid duplicates
        $dedup_cache_key = 'odcm_log_dedup_' . $dedup_hash;
        $existing_entry = wp_cache_get($dedup_cache_key);
        
        if (false !== $existing_entry) {
            // Skip creating duplicate log entry
            odcm_log_message("Skipping duplicate log entry creation for hash {$dedup_hash}", 'debug');
            $log_result = true; // Pretend success to continue processing
        } else {
            // Create final audit log entry with parent_id if resolved
            $log_data = [
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
                'duplicate_hash' => $dedup_hash,
            ];
            
            // Add parent_id if resolved
            if ($parent_id !== null) {
                $log_data['parent_id'] = $parent_id;
            }
            
            // Insert audit log entry with proper security
            $log_result = $wpdb->insert(
                "{$wpdb->prefix}odcm_audit_log",
                $log_data,
                [
                    '%s', // timestamp
                    '%s', // status
                    '%s', // summary
                    '%d', // order_id (nullable)
                    '%s', // event_type
                    '%s', // source
                    '%s', // log_category
                    '%d', // is_test
                    '%s', // process_id (nullable)
                    '%d', // payload_id
                    '%s', // duplicate_hash
                    '%d'  // parent_id (nullable)
                ]
            );

            // @codingStandardsIgnoreLine - Direct database access required for custom tables
            
            if ($log_result !== false) {
        // Cache the deduplication hash (10 minutes)  
        wp_cache_set($dedup_cache_key, true, '', 10 * 60);

                // Invalidate dashboard logs cache by bumping a global cache version
                $version_key = 'odcm_logs_cache_version';
                if (function_exists('wp_cache_incr')) {
                    $new_version = wp_cache_incr($version_key);
                    if (false === $new_version) {
                        wp_cache_set($version_key, 1);
                    }
                } else {
                    $current = wp_cache_get($version_key);
                    if ($current === false) {
                        $current = 0;
                    }
                    wp_cache_set($version_key, ((int) $current) + 1);
                }
            }
        }
        
        if ($log_result === false) {
            throw new \Exception('Failed to insert audit log: ' . $wpdb->last_error);
        }
        
        // Mark queue entry as processed with proper formatting
        $wpdb->update(
            "{$wpdb->prefix}odcm_audit_log_queue",
            [
                'status' => 'processed',
                'processed_at' => current_time('mysql')
            ],
            ['queue_id' => $queue_id],
            ['%s', '%s'], // Formats for update data
            ['%s'] // Format for WHERE clause
        );

        // @codingStandardsIgnoreLine - Direct database access required for custom tables
        
        // Debug logging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("Successfully processed queue entry {$queue_id}, created log ID: {$wpdb->insert_id}", 'debug');
        }
        
    } catch (\Throwable $e) {
        // Update queue entry with error
        $retry_count = (int) $queue_entry->retry_count + 1;
        
        // Update queue entry with error information
        $wpdb->update(
            "{$wpdb->prefix}odcm_audit_log_queue",
            [
                'retry_count' => $retry_count,
                'last_error' => $e->getMessage(),
                'status' => $retry_count >= 3 ? 'failed' : 'pending'  // Max 3 retries
            ],
            ['queue_id' => $queue_id],
            ['%d', '%s', '%s'], // Formats for update data
            ['%s'] // Format for WHERE clause
        );

        // @codingStandardsIgnoreLine - Direct database access required for custom tables
        
        odcm_log_message("Error processing queue entry {$queue_id}: " . $e->getMessage(), 'error');
        
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

/**
 * Resolve parent_id from parent event type and order_id
 * 
 * This implements the deferred parent_id resolution. When a child event
 * is processed, it looks up the actual parent_id from recently created
 * events of the specified type.
 * 
 * @param string $parent_event_type The parent event type to look up
 * @param int $order_id The order ID to search within
 * @return int|null The parent event log_id or null if not found
 */
function odcm_resolve_parent_id(string $parent_event_type, int $order_id, ?string $process_id = null): ?int
{
    global $wpdb;

    // Direct database access required for parent ID resolution in custom audit log tables
    // @codingStandardsIgnoreStart - Custom table operations

    odcm_log_message("PARENT_ID_RESOLUTION: Starting lookup", 'debug', [
        'parent_event_type' => $parent_event_type,
        'order_id'          => $order_id,
        'process_id'        => $process_id,
        'table_name'        => $wpdb->prefix . 'odcm_audit_log',
    ]);

    $actual_event_type = odcm_map_to_actual_event_type($parent_event_type);

    // Precise lookup: when process_id is known both events share it, so we can pinpoint the
    // exact parent row without risk of matching a different event for the same order.
    if ($process_id) {
        $precise_cache_key = 'odcm_parent_pid_' . md5($order_id . '_' . $actual_event_type . '_' . $process_id);
        $precise_result = wp_cache_get($precise_cache_key);
        if (false === $precise_result) {
            $precise_result = $wpdb->get_row($wpdb->prepare(
                "SELECT log_id FROM {$wpdb->prefix}odcm_audit_log
                 WHERE order_id = %d AND event_type = %s AND process_id = %s
                 ORDER BY timestamp DESC, log_id DESC LIMIT 1",
                $order_id,
                $actual_event_type,
                $process_id
            ));
            // Only cache positive results — a null here might mean the parent isn't in the DB
            // yet (parallel AS runners). Not caching null allows a later retry to find it.
            if ($precise_result) {
                wp_cache_set($precise_cache_key, $precise_result, '', 5 * MINUTE_IN_SECONDS);
            }
        }
        if ($precise_result) {
            odcm_log_message("PARENT_ID_RESOLUTION: Precise match by process_id - ID:{$precise_result->log_id}", 'debug');
            return (int) $precise_result->log_id;
        }
    }

    // Fallback: broader lookup without process_id (older entries or parallel AS edge case)
    // First, let's see what events exist for this order (with caching)
    $cache_key = 'odcm_parent_resolution_events_' . $order_id;
    $all_events = wp_cache_get($cache_key);

    if (false === $all_events) {
        $all_events = $wpdb->get_results($wpdb->prepare(
            "SELECT log_id, event_type, timestamp, summary FROM {$wpdb->prefix}odcm_audit_log
             WHERE order_id = %d
             ORDER BY timestamp DESC",
            $order_id
        ));

        // Cache for 5 minutes to reduce database queries
        wp_cache_set($cache_key, $all_events, '', 5 * MINUTE_IN_SECONDS);
    }

    // @codingStandardsIgnoreLine - Direct database access required for custom tables
    
    odcm_log_message("PARENT_ID_RESOLUTION: Found " . count($all_events) . " total events for order #{$order_id}", 'debug');
    
    if (!empty($all_events)) {
        foreach ($all_events as $event) {
            odcm_log_message("PARENT_ID_RESOLUTION: Event - ID:{$event->log_id} Type:{$event->event_type} Time:{$event->timestamp}", 'debug');
        }
    }
    
    // Look up parent event with caching (fallback — no process_id to narrow by)
    $parent_cache_key = 'odcm_parent_event_' . md5($order_id . '_' . $actual_event_type);
    $result = wp_cache_get($parent_cache_key);

    if (false === $result) {
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT log_id, event_type, timestamp FROM {$wpdb->prefix}odcm_audit_log
             WHERE order_id = %d AND event_type = %s
             ORDER BY timestamp DESC LIMIT 1",
            $order_id,
            $actual_event_type
        ));

        // Cache parent resolution for 5 minutes
        wp_cache_set($parent_cache_key, $result, '', 5 * MINUTE_IN_SECONDS);
    }

    // @codingStandardsIgnoreLine - Direct database access required for custom tables
    
    // If we didn't find anything with the mapped event type, try the original
    if (!$result && $actual_event_type !== $parent_event_type) {
        odcm_log_message("PARENT_ID_RESOLUTION: No match for mapped event type '{$actual_event_type}', trying original '{$parent_event_type}'", 'debug');
        
        // Try original event type with caching
        $original_cache_key = 'odcm_parent_event_' . md5($order_id . '_' . $parent_event_type);
        $result = wp_cache_get($original_cache_key);

        if (false === $result) {
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT log_id, event_type, timestamp FROM {$wpdb->prefix}odcm_audit_log
                 WHERE order_id = %d AND event_type = %s
                 ORDER BY timestamp DESC LIMIT 1",
                $order_id,
                $parent_event_type
            ));

            // Cache this lookup too
            wp_cache_set($original_cache_key, $result, '', 5 * MINUTE_IN_SECONDS);
        }

        // @codingStandardsIgnoreLine - Direct database access required for custom tables
    }
    
    if ($result) {
        odcm_log_message("PARENT_ID_RESOLUTION: Found parent - ID:{$result->log_id} Type:{$result->event_type} Time:{$result->timestamp}", 'debug');
        return (int)$result->log_id;
    } else {
        // Check if the issue is with the exact event type matching
        // Look for similar events with caching
        $similar_cache_key = 'odcm_similar_events_' . md5($order_id . '_' . $parent_event_type);
        $similar_events = wp_cache_get($similar_cache_key);

        if (false === $similar_events) {
            $similar_events = $wpdb->get_results($wpdb->prepare(
                "SELECT log_id, event_type FROM {$wpdb->prefix}odcm_audit_log
                 WHERE order_id = %d AND event_type LIKE %s
                 ORDER BY timestamp DESC LIMIT 5",
                $order_id,
                '%' . $wpdb->esc_like($parent_event_type) . '%'
            ));

            // Cache similar events lookup
            wp_cache_set($similar_cache_key, $similar_events, '', 5 * MINUTE_IN_SECONDS);
        }

        // @codingStandardsIgnoreLine - Direct database access required for custom tables
        
        if (!empty($similar_events)) {
            odcm_log_message("PARENT_ID_RESOLUTION: No exact match, but found similar events:", 'debug');
            foreach ($similar_events as $event) {
                odcm_log_message("PARENT_ID_RESOLUTION: Similar - ID:{$event->log_id} Type:{$event->event_type}", 'debug');
            }
        } else {
            odcm_log_message("PARENT_ID_RESOLUTION: No matching or similar events found for type '{$parent_event_type}'", 'debug');
        }
        
        return null;
    }
}

/**
 * Map original event types to actual database event types by examining payload data
 * 
 * The UniversalEventProcessor stores events with 'universal_event_processing' as the event_type,
 * but preserves the original event type in the components array within the payload JSON.
 * This function performs precise lookups based on the actual payload structure.
 * 
 * @param string $original_event_type The original event type (like 'checkout_processed')
 * @return string The actual database event type or SQL to search payload
 */
function odcm_map_to_actual_event_type(string $original_event_type): string
{
    global $wpdb;

    // Event type mapping for custom audit log tables
    // @codingStandardsIgnoreStart - Custom table operations
    
    // Debug what we're trying to map
    odcm_log_message("EVENT_TYPE_MAPPING: Precisely mapping '{$original_event_type}'", 'debug');
    
    // For lifecycle events stored via UniversalEventProcessor, we need to look in the payload
    $lifecycle_events = [
        'checkout_processed',
        'payment_completed',
        'order_status_changed',
        'order_created',
        'order_check_scheduled'
    ];
    
    if (in_array($original_event_type, $lifecycle_events, true)) {
        // These events are stored as 'universal_event_processing' with original type in payload
        odcm_log_message("EVENT_TYPE_MAPPING: Lifecycle event '{$original_event_type}' -> 'universal_event_processing' (will search payload)", 'debug');
        return 'universal_event_processing';
    }
    
    // Rule processing events have specific types
    $rule_event_mapping = [
        'rule_evaluation' => 'rule_evaluation_non_canonical',
        'rule_execution' => 'rule_execution',
        'status_evaluation' => '_status_evaluation',
    ];
    
    if (isset($rule_event_mapping[$original_event_type])) {
        $mapped_type = $rule_event_mapping[$original_event_type];
        odcm_log_message("EVENT_TYPE_MAPPING: Rule event '{$original_event_type}' -> '{$mapped_type}'", 'debug');
        return $mapped_type;
    }
    
    // For payment gateway events like 'payment.stripe.checkout_processed'
    if (strpos($original_event_type, 'payment.') === 0) {
        odcm_log_message("EVENT_TYPE_MAPPING: Payment gateway event '{$original_event_type}' -> 'universal_event_processing' (will search payload)", 'debug');
        return 'universal_event_processing';
    }
    
    // If no mapping found, return the original type
    odcm_log_message("EVENT_TYPE_MAPPING: No mapping needed for '{$original_event_type}', using original", 'debug');
    return $original_event_type;
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

    // Direct database access required for queue cleanup operations
    // @codingStandardsIgnoreStart - Custom table maintenance
    
    // Use a cache lock to prevent multiple cleanups running simultaneously
    $lock_key = 'odcm_queue_cleanup_lock';
    $got_lock = wp_cache_add($lock_key, 1, '', 5 * MINUTE_IN_SECONDS); // 5 minute lock
    
    if (!$got_lock) {
        odcm_log_message("Queue cleanup already in progress, skipping", 'debug');
        return;
    }
    
    // Cache the cleanup schedule to avoid performing this operation too frequently
    $last_cleanup_key = 'odcm_last_queue_cleanup';
    $last_cleanup = wp_cache_get($last_cleanup_key);
    
    if (false !== $last_cleanup) {
        $hours_since_last = (time() - (int)$last_cleanup) / 3600;
        if ($hours_since_last < 12) { // Only clean up once per 12 hours at most
            odcm_log_message("Queue cleanup performed recently ({$hours_since_last} hours ago), skipping", 'debug');
            wp_cache_delete($lock_key); // Release lock
            return;
        }
    }
    
    // Delete processed entries older than 24 hours with proper locking
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}odcm_audit_log_queue
             WHERE status = %s
             AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            'processed'
        )
    );

    // @codingStandardsIgnoreLine - Direct database access required for custom tables
    
    if ($deleted !== false && $deleted > 0) {
        odcm_log_message("Cleaned up {$deleted} processed queue entries", 'info');
    }
    
    // Delete failed entries older than 30 days with proper preparation
    $deleted_failed = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}odcm_audit_log_queue
             WHERE status = %s
             AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'failed'
        )
    );

    // @codingStandardsIgnoreLine - Direct database access required for custom tables
    
    // Update the last cleanup timestamp
    wp_cache_set($last_cleanup_key, time(), '', DAY_IN_SECONDS);
    
    // Release the lock
    wp_cache_delete($lock_key);
    
    if ($deleted_failed !== false && $deleted_failed > 0) {
        odcm_log_message("Cleaned up {$deleted_failed} failed queue entries", 'info');
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

// Only register the hook if NOT in CLI context to prevent Action Scheduler initialization issues
if (!(defined('WP_CLI') && WP_CLI) && !(defined('DOING_CRON') && DOING_CRON)) {
    // Use later hook priority to ensure Action Scheduler is fully initialized
    add_action('init', 'odcm_schedule_queue_cleanup', 20);
}

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
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'odcm_update_rule_order')) {
        wp_send_json_error([
            'message' => __('admin.ajax.security_check_failed', 'order-daemon')
        ]);
        return;
    }

    // Check user permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error([
            'message' => __('security.no_action_permission', 'order-daemon')
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
        // Use position directly for 0-based priorities (0, 1, 2, etc.)
        $priority = $position;
        
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
        'message' => __('admin.ajax.rule_order_update_success', 'order-daemon'),
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

    // Direct database access required for custom queue table operations
    // @codingStandardsIgnoreStart - Custom table operations
    
    // Get queue ID from order meta
    $queue_id = OrderMetaManager::get_meta($order_id, '_odcm_checkout_queue_id');
    if (!$queue_id) {
        return null;
    }
    
    // Check cache first
    $cache_key = 'odcm_checkout_queue_' . md5($queue_id);
    $queue_entry = wp_cache_get($cache_key);
    
    if (false === $queue_entry) {
        // Retrieve data from queue table with caching
        $queue_entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue
             WHERE queue_id = %s AND status = %s",
            $queue_id,
            'pending'
        ));

        // @codingStandardsIgnoreLine - Direct database access required for custom tables
        
        // Cache the result for 5 minutes
        wp_cache_set($cache_key, $queue_entry, '', 5 * MINUTE_IN_SECONDS);
    }
    
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

    // Direct database access required for custom queue table operations
    // @codingStandardsIgnoreStart - Custom table operations
    
    // Use a transaction key to prevent duplicate processing
    $transaction_key = 'odcm_cleanup_transaction_' . md5($queue_id);
    $got_transaction = wp_cache_add($transaction_key, true, '', 30); // 30 second lock
    
    if (!$got_transaction) {
        // Another process is already handling this
        odcm_log_message("Skipping duplicate cleanup for queue ID {$queue_id}", 'debug');
        return;
    }
    
    // Mark queue entry as processed with proper formatting
    $wpdb->update(
        $wpdb->prefix . 'odcm_audit_log_queue',
        [
            'status' => 'processed',
            'processed_at' => current_time('mysql')
        ],
        ['queue_id' => $queue_id],
        ['%s', '%s'], // Formats for update data
        ['%s'] // Format for WHERE clause
    );

    // @codingStandardsIgnoreLine - Direct database access required for custom tables
    
    // Clear related caches
    wp_cache_delete('odcm_queue_entry_' . md5($queue_id));
    wp_cache_delete('odcm_checkout_queue_' . md5($queue_id));
    
    // Remove order meta flags
    OrderMetaManager::delete_meta($order_id, '_odcm_checkout_queue_id');
    OrderMetaManager::delete_meta($order_id, '_odcm_checkout_data_queued');
    
    odcm_log_message("Cleaned up queue data for order #{$order_id}, queue ID: {$queue_id}", 'debug');
}
