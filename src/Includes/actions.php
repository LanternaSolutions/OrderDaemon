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
 * Asynchronously logs an event by scheduling a background job.
 *
 * This function is the universal entry point for triggering a log event from anywhere
 * in the plugin. It's designed to be lightweight and fast, immediately handing off the
 * logging request to Action Scheduler without delaying any user-facing operations.
 *
 * Canonical narrative-first mode:
 * - Pass event_data with keys: 'canonical' => true, 'status', 'event_type', 'summary', and optional 'data' array
 *   where 'data' may include 'order_id' and 'details' (payload array).
 * - The worker will route it to AuditTrailLogger::record() to ensure a single narrative payload entry.
 *
 * @param array $event_data The event data to be logged.
 * @return void
 * @since 1.0.0
 */
function odcm_log_event(array $event_data) {
    // Guard clause to ensure Action Scheduler is available
    if (!function_exists('as_enqueue_async_action')) {
        return;
    }

    // Add process ID for lifecycle events
    if (!function_exists('odcm_maybe_add_process_id')) {
        // Ensure helper is available
        require_once __DIR__ . '/functions.php';
    }
    $event_data = odcm_maybe_add_process_id($event_data);

    // Schedule the background task
    as_enqueue_async_action(
        'odcm_process_log_entry',  // Hook name
        ['event_data' => $event_data],  // Arguments
        'odcm-logs'  // Group
    );
}

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

    // Enhanced argument handling to support multiple Action Scheduler argument structures
    $event_data = null;

    // Method 1: Standard nested structure ['event_data' => [...]]
    if (isset($args['event_data']) && is_array($args['event_data'])) {
        $event_data = $args['event_data'];
    }
    // Method 2: Direct structure where $args IS the event_data
    elseif (isset($args['summary']) && isset($args['event_type'])) {
        $event_data = $args;
    }
    // Method 3: Check if first element is the event_data array
    elseif (is_array($args) && count($args) === 1 && is_array(reset($args))) {
        $first_element = reset($args);
        if (isset($first_element['summary']) && isset($first_element['event_type'])) {
            $event_data = $first_element;
        }
    }

    // If we still don't have valid event_data, log the issue and return
    if (!is_array($event_data) || !isset($event_data['summary']) || !isset($event_data['event_type'])) {
        // Enhanced error logging for debugging
        error_log("ODCM: Log processing failed - Could not extract valid event_data from arguments");
        error_log("ODCM: Arguments received: " . print_r($args, true));
        error_log("ODCM: Extracted event_data: " . print_r($event_data, true));

        // Only log to database if we're not already in a logging error loop
        static $error_logged = false;
        if (!$error_logged) {
            $error_logged = true;
            $wpdb->insert(
                $wpdb->prefix . 'odcm_audit_log',
                [
                    'summary' => 'Log processing failed: Could not extract valid event_data',
                    'event_type' => 'log_processing_error',
                    'status' => 'error',
                    'timestamp' => current_time('mysql'),
                    'is_test' => 0
                ]
            );
        }
        return;
    }

    // Canonical narrative-first adapter: if flagged, route to AuditTrailLogger::record() and return
    try {
        if (!empty($event_data['canonical']) && isset($event_data['status'], $event_data['event_type'], $event_data['summary'])) {
            $status     = sanitize_text_field((string) $event_data['status']);
            $event_type = sanitize_key((string) $event_data['event_type']);
            $summary    = sanitize_text_field((string) $event_data['summary']);
            $data       = isset($event_data['data']) && is_array($event_data['data']) ? $event_data['data'] : [];

            // Ensure order_id is absint if provided
            if (isset($data['order_id'])) {
                $data['order_id'] = absint($data['order_id']);
            }
            // Forward optional canonical metadata if present
            if (isset($event_data['process_id']) && !isset($data['process_id'])) {
                $data['process_id'] = sanitize_text_field((string)$event_data['process_id']);
            }
            if (isset($event_data['log_category']) && !isset($data['log_category'])) {
                $data['log_category'] = sanitize_text_field((string)$event_data['log_category']);
            }
            if (isset($event_data['is_test']) && !isset($data['is_test'])) {
                $data['is_test'] = (bool)$event_data['is_test'];
            }
            if (isset($event_data['source']) && !isset($data['source'])) {
                $data['source'] = sanitize_text_field((string)$event_data['source']);
            }

            // Write via canonical writer (single source of truth)
            $log_id = \OrderDaemon\CompletionManager\Includes\AuditTrailLogger::record($status, $event_type, $summary, $data);
            if ($log_id === false) {
                // Minimal error record to avoid recursion
                $wpdb->insert(
                    $wpdb->prefix . 'odcm_audit_log',
                    [
                        'summary' => 'AuditTrailLogger::record failed in async adapter',
                        'event_type' => 'logging_error',
                        'status' => 'error',
                        'timestamp' => current_time('mysql'),
                        'is_test' => 0
                    ]
                );
            }
            return; // Do not continue to legacy path
        }
    } catch (\Throwable $adapter_e) {
        // Log adapter failure minimally and continue to legacy path
        error_log('ODCM: Canonical adapter failed: ' . $adapter_e->getMessage());
    }

    // Validate required fields
    if (!isset($event_data['summary']) || !isset($event_data['event_type'])) {
        // Direct database logging to avoid recursion
        error_log("ODCM: Log processing failed - Missing required fields: " . print_r($event_data, true));
        $wpdb->insert(
            $wpdb->prefix . 'odcm_audit_log',
            [
                'summary' => 'Log processing failed: Missing required fields (summary or event_type)',
                'event_type' => 'log_processing_error',
                'status' => 'error',
                'timestamp' => current_time('mysql'),
                'is_test' => 0
            ]
        );
        return;
    }

    // Extract payload, default to empty array if not set
    $payload = isset($event_data['payload']) ? $event_data['payload'] : [];

    // Sanitize payload
    $sanitized_payload = odcm_sanitize_payload_for_logging($payload);

    // Save payload to database
    $payload_insert = $wpdb->insert(
        $wpdb->prefix . 'odcm_audit_log_payloads',
        [
            'payload' => json_encode($sanitized_payload)
        ]
    );

    // Check if payload insert failed
    if ($payload_insert === false) {
        // Direct database logging to avoid recursion
        error_log("ODCM: Failed to insert payload into database: " . $wpdb->last_error);
        $wpdb->insert(
            $wpdb->prefix . 'odcm_audit_log',
            [
                'summary' => 'Failed to insert payload into database: ' . $wpdb->last_error,
                'event_type' => 'database_insert_error',
                'status' => 'error',
                'timestamp' => current_time('mysql'),
                'is_test' => 0
            ]
        );
        return;
    }

    // Get the payload ID
    $payload_id = $wpdb->insert_id;

    // Prepare log entry data
    $log_data = [
        'summary' => $event_data['summary'],
        'event_type' => $event_data['event_type'],
        'payload_id' => $payload_id,
        'timestamp' => current_time('mysql')
    ];

    // Add optional fields if they exist
    if (isset($event_data['status'])) {
        $log_data['status'] = $event_data['status'];
    }

    if (isset($event_data['order_id'])) {
        $log_data['order_id'] = (int) $event_data['order_id'];
    }

    if (isset($event_data['is_test'])) {
        $log_data['is_test'] = (bool) $event_data['is_test'] ? 1 : 0;
    }

    // Persist process_id if provided (enables UI consolidation)
    if (isset($event_data['process_id']) && is_string($event_data['process_id']) && $event_data['process_id'] !== '') {
        $log_data['process_id'] = sanitize_text_field($event_data['process_id']);
    }
    // Optional: persist source and log_category when provided
    if (isset($event_data['source']) && is_string($event_data['source']) && $event_data['source'] !== '') {
        $log_data['source'] = sanitize_text_field($event_data['source']);
    }
    if (isset($event_data['log_category']) && is_string($event_data['log_category']) && $event_data['log_category'] !== '') {
        $log_data['log_category'] = sanitize_text_field($event_data['log_category']);
    }

    // IDEMPOTENT OPERATIONS: Use INSERT IGNORE to let database handle duplicates
    // This approach is more reliable than application-level duplicate checking
    // and eliminates race conditions between multiple processes

    // Add a hash field for duplicate detection based on key fields
    $duplicate_signature = md5(serialize([
        'summary' => $log_data['summary'],
        'event_type' => $log_data['event_type'],
        'status' => $log_data['status'] ?? 'info',
        'order_id' => $log_data['order_id'] ?? null,
        'is_test' => $log_data['is_test'] ?? 0,
        'timestamp_minute' => date('Y-m-d H:i', strtotime($log_data['timestamp']))
    ]));

    $log_data['duplicate_hash'] = $duplicate_signature;

    // Use INSERT IGNORE to make the operation idempotent
    // If a duplicate exists (based on unique constraint), it will be silently ignored
    $query = "INSERT IGNORE INTO {$wpdb->prefix}odcm_audit_log (";
    $query .= implode(', ', array_keys($log_data));
    $query .= ") VALUES (";
    $query .= implode(', ', array_fill(0, count($log_data), '%s'));
    $query .= ")";

    $log_insert = $wpdb->query($wpdb->prepare($query, array_values($log_data)));

    // Check if insertion was successful or silently ignored
    if ($log_insert === false) {
        // Log database errors (but not duplicate key errors)
        if (strpos($wpdb->last_error, 'Duplicate entry') === false) {
            error_log("ODCM: Failed to insert log entry into database: " . $wpdb->last_error);
            // Create a simple error log entry without recursion risk
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}odcm_audit_log 
                 (summary, event_type, status, timestamp, is_test, duplicate_hash) 
                 VALUES (%s, %s, %s, %s, %d, %s)",
                'Failed to insert log entry into database: ' . $wpdb->last_error,
                'database_insert_error',
                'error',
                current_time('mysql'),
                0,
                md5('database_error_' . time())
            ));
        } else {
            // Duplicate detected and prevented by database - this is expected behavior
            // Clean up the payload we created since we're not using it
            $wpdb->delete(
                $wpdb->prefix . 'odcm_audit_log_payloads',
                ['payload_id' => $payload_id]
            );

            // Only log to debug if ODCM_DEBUG is enabled
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM: Prevented duplicate log entry via database constraint - Summary: {$log_data['summary']}, Event: {$log_data['event_type']}");
            }
        }
    } elseif ($log_insert === 0) {
        // INSERT IGNORE returned 0 rows affected - duplicate was silently ignored
        // Clean up the payload we created since we're not using it
        $wpdb->delete(
            $wpdb->prefix . 'odcm_audit_log_payloads',
            ['payload_id' => $payload_id]
        );

        // Only log to debug if ODCM_DEBUG is enabled
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM: Duplicate log entry silently ignored by database - Summary: {$log_data['summary']}, Event: {$log_data['event_type']}");
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
        // Use new registry-based logging for argument validation errors
        \odcm_log_custom_event(
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
        // Use new registry-based logging for order ID validation errors
        \odcm_log_custom_event(
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

    // Create an instance of the Executor class with required logger
    $logger = new \OrderDaemon\CompletionManager\Includes\AuditTrailLogger();
    $executor = new \OrderDaemon\CompletionManager\Core\Executor($logger);

    // Call the process_order_check method with the extracted order_id
    $executor->process_order_check($order_id);
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
        // Log error using existing logging system
        \odcm_log_custom_event(
            'Universal event processing failed: Invalid arguments',
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
        // Log error using existing logging system
        \odcm_log_custom_event(
            'Universal event processing failed: Invalid event data',
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
            \odcm_log_custom_event(
                $result ? 'Universal event processed successfully' : 'Universal event processing completed with no action',
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
        \odcm_log_custom_event(
            'Universal event processing failed with exception: ' . $e->getMessage(),
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
