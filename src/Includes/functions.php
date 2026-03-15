<?php

declare(strict_types=1);

// Prevent direct access to this file
if ( ! defined( 'ABSPATH' ) ) exit;

// Include DatabaseHelper for database operations
require_once __DIR__ . '/Utils/DatabaseHelper.php';

/**
 * Global Helper Functions - Core Utilities
 *
 * This file contains globally available helper functions that power the
 * Order Daemon For Woocommerce plugin. It provides essential utilities used
 * throughout the codebase.
 *
 * ARCHITECTURE PRINCIPLES:
 * =======================
 *
 * 1. Performance First: Minimal overhead for core operations
 * 2. Future-Proof: Easy to extend for new functionality
 * 3. Developer-Friendly: Clear naming and comprehensive documentation
 *
 * INTEGRATION POINTS:
 * ==================
 *
 * - OptionRegistry: Central hub for all triggers, conditions, and actions
 * - MetaBox UI: Dynamic rendering based on component availability
 * - Core Logic: Feature execution with proper validation
 * - Admin Interface: User-friendly management of completion rules
 *
 * @package OrderDaemon\CompletionManager\Includes
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://drderdaemon.com/docs
 */

/**
 * Helper function to check user capability and handle permission denied responses.
 *
 * This function provides a centralized way to check user capabilities and handle
 * permission denied scenarios consistently across the plugin.
 *
 * @since 1.0.0
 *
 * @param  string $capability The capability to check (default: 'manage_woocommerce').
 * @param  string $context    The context for the check ('ajax', 'admin_page', 'form_handler').
 * @param  string $message    Optional custom permission denied message.
 * @return boolean True if user has capability, false otherwise (and handles response based on context).
 */
function odcm_check_user_capability(string $capability='manage_woocommerce', string $context='admin_page', string $message=''): bool
{
    if (current_user_can($capability)) {
        return true;
    }

    // Set default message if none provided
    if (empty($message)) {
        $message = __('security.permission_denied', 'order-daemon');
    }

    // Handle response based on context
    switch ($context) {
        case 'ajax':
            wp_send_json_error(['message' => $message]);
            wp_die();
        break;

        case 'admin_page':
            wp_die(esc_html($message));
        break;

        case 'form_handler':
            // For form handlers, just return false and let the caller handle it
        return false;
    }

    return false;

}//end odcm_check_user_capability()

/**
 * Helper function to schedule Action Scheduler tasks with duplicate prevention.
 *
 * This function provides a centralized way to schedule Action Scheduler tasks
 * with built-in duplicate prevention and debug mode support.
 *
 * @since 1.0.0
 *
 * @param  integer       $order_id         The order ID to process.
 * @param  string        $hook             The hook name for the scheduled action.
 * @param  boolean       $check_duplicates Whether to check for existing scheduled actions.
 * @param  callable|null $debug_callback   Optional callback to execute in debug mode.
 * @return boolean True if action was scheduled, false otherwise.
 */
function odcm_schedule_action(int $order_id, string $hook='odcm_process_order_check', bool $check_duplicates=false, ?callable $debug_callback=null): bool
{
    // Ensure Action Scheduler functions exist
    if (!function_exists('as_schedule_single_action')) {
        return false;
    }

    $args = ['order_id' => $order_id];

    // Check for duplicates if requested
    if ($check_duplicates) {
        $scheduled_actions = as_get_scheduled_actions(
            [
                'hook'   => $hook,
                'args'   => $args,
                'status' => 'pending',
            ]
        );

        if (!empty($scheduled_actions)) {
            return false;
            // Action already scheduled
        }
    }

    // Schedule the action
    as_schedule_single_action(
        time(),
        $hook,
        $args,
        'completion-manager'
    );

    // Execute immediately in debug mode
    if (defined('ODCM_DEBUG') && ODCM_DEBUG && $debug_callback && is_callable($debug_callback)) {
        call_user_func($debug_callback, $order_id);
    }

    return true;

}//end odcm_schedule_action()


/**
 * Helper function to create debug-gated log messages with consistent formatting.
 *
 * This function provides a centralized way to create log messages with
 * consistent formatting and debug-gating across the plugin.
 *
 * @since 1.0.0
 *
 * @param  string $message The log message.
 * @param  string $level   The log level ('error', 'success', 'notice', 'info').
 * @return void
 */
function odcm_log_message(string $message, string $level='notice'): void
{
    if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
        return;
    }

    $level_prefixes = [
        'error'   => '[ODCM ERROR]',
        'success' => '[ODCM SUCCESS]',
        'notice'  => '[ODCM NOTICE]',
        'info'    => '[ODCM INFO]',
        'debug'   => '[ODCM DEBUG]',
        'warning' => '[ODCM WARNING]',
    ];

    $prefix = ($level_prefixes[$level] ?? '[ODCM NOTICE]');

    // Use WordPress debug log function if available
    if (function_exists('wp_debug_log')) {
        wp_debug_log("{$prefix} {$message}");
        return;
    }

    // Use WordPress action hook if available for centralized error handling
    if (function_exists('do_action')) {
        do_action('odcm_log_' . $level, $message);
        return;
    }

    // If WP_DEBUG_LOG is enabled, write directly to the debug.log file using safe file operation
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $debug_file = odcm_get_safe_debug_file_path();
        odcm_safe_file_put_contents($debug_file, '[' . gmdate('Y-m-d H:i:s') . '] ' . $prefix . ' ' . $message . PHP_EOL, FILE_APPEND);
        return;
    }

}//end odcm_log_message()

/**
 * Log critical errors that must be recorded regardless of debug mode
 *
 * This function ensures critical errors are always logged but uses
 * WordPress-compliant methods rather than direct error_log() calls.
 *
 * @since 1.0.0
 * @param string $message The error message to log
 * @return void
 */
function odcm_critical_log(string $message): void
{
    // Format the message for visibility
    $formatted_message = "[ODCM CRITICAL] {$message}";

    // Use WordPress logging function if available
    if (function_exists('wp_debug_log')) {
        wp_debug_log($formatted_message);
        return;
    }

    // Use WordPress action hook if available for centralized error handling
    if (function_exists('do_action')) {
        do_action('odcm_log_error', $message);
        return;
    }

    // If WP_DEBUG_LOG is enabled, write directly to the debug.log file using safe file operation
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $debug_file = odcm_get_safe_debug_file_path();
        odcm_safe_file_put_contents($debug_file, '[' . gmdate('Y-m-d H:i:s') . '] ' . $formatted_message . PHP_EOL, FILE_APPEND);
        return;
    }
}//end odcm_critical_log()


/**
 * Get the Global OptionRegistry Instance - Central Hub for Options
 *
 * This function provides global access to the OptionRegistry singleton instance,
 * which serves as the central hub for all triggers, conditions, and actions.
 * It implements the singleton pattern to ensure all parts of the plugin work
 * with the same registry data.
 *
 * ARCHITECTURAL ROLE:
 * ==================
 *
 * The OptionRegistry is the cornerstone of the plugin's architecture. It bridges
 * the gap between:
 *
 * 1. Option Registration (options.php) - Where features are defined
 * 2. UI Rendering (MetaBox.php) - Where features are displayed
 * 3. Business Logic (Executor.php) - Where features are executed
 *
 * SINGLETON PATTERN:
 * =================
 *
 * Uses static variable to ensure only one registry instance exists throughout
 * the plugin lifecycle. This guarantees:
 * - Consistent data across all plugin components
 * - No duplicate registrations
 * - Efficient memory usage
 * - Predictable behavior
 *
 * USAGE PATTERNS:
 * ==============
 *
 * 1. Option Registration (typically in options.php):
 *    $registry = odcm_get_registry_instance();
 *    $registry->register_condition([...]);
 *
 * 2. UI Rendering (typically in MetaBox.php):
 *    $registry = odcm_get_registry_instance();
 *    $conditions = $registry->get_conditions();
 *
 * 3. Feature Validation (anywhere in the plugin):
 *    $registry = odcm_get_registry_instance();
 *    $triggers = $registry->get_triggers();
 *
 * PERFORMANCE CONSIDERATIONS:
 * ==========================
 *
 * - Singleton pattern prevents multiple instantiations
 * - Registry data is stored in memory (no database queries)
 * - Options are registered once during plugin initialization
 * - Retrieval operations are simple array access (O(1))
 *
 * @since 1.0.0
 *
 * @return \OrderDaemon\CompletionManager\Core\OptionRegistry {
 *     The singleton OptionRegistry instance containing all registered options.
 *
 *     The returned instance provides these methods:
 *     - register_trigger(array $args): void
 *     - register_condition(array $args): void
 *     - register_action(array $args): void
 *     - get_triggers(): array
 *     - get_conditions(): array
 *     - get_actions(): array
 * }
 *
 * @example
 * ```php
 * // Basic usage - get registry and register an option
 * $registry = odcm_get_registry_instance();
 * $registry->register_condition([
 *     'id'              => 'my_condition',
 *     'label'           => __('My Condition', 'domain'),
 *     'description'     => __('A custom condition.', 'domain'),
 *     'render_callback' => [$this, 'render_my_condition'],
 * ]);
 *
 * // UI rendering - get options and render
 * $registry = odcm_get_registry_instance();
 * $conditions = $registry->get_conditions();
 *
 * foreach ($conditions as $condition) {
 *     // User can access this condition
 *     echo '<input type="radio" value="' . esc_attr($condition['id']) . '">';
 *     echo esc_html($condition['label']);
 * }
 *
 * // Validation - check if a specific option exists
 * $registry = odcm_get_registry_instance();
 * $triggers = $registry->get_triggers();
 *
 * if (isset($triggers['my_trigger'])) {
 *     // Trigger is registered and available
 *     $trigger_data = $triggers['my_trigger'];
 * }
 *
 * // Multiple calls return the same instance (singleton)
 * $registry1 = odcm_get_registry_instance();
 * $registry2 = odcm_get_registry_instance();
 * // $registry1 === $registry2 (same object)
 * ```
 */
function odcm_get_registry_instance(): \OrderDaemon\CompletionManager\Core\OptionRegistry
{
    static $instance = null;

    if ($instance === null) {
        $instance = new \OrderDaemon\CompletionManager\Core\OptionRegistry();
    }

    return $instance;

}//end odcm_get_registry_instance()


/**
 * Get the Global FilterRegistry Instance - Central Hub for Audit Log Filters
 *
 * This function provides global access to the FilterRegistry singleton instance,
 * which serves as the central hub for all audit log filters.
 * It implements the singleton pattern to ensure all parts of the plugin
 * work with the same registry data.
 *
 * ARCHITECTURAL ROLE:
 * ==================
 *
 * The FilterRegistry is a specialized component of the plugin's architecture. It bridges the gap between:
 *
 * 1. Filter Registration (audit-filters.php) - Where filters are defined
 * 2. UI Rendering - Where filters are displayed
 * 3. Query Processing (get_logs()) - Where filters are applied
 *
 * SINGLETON PATTERN:
 * =================
 *
 * Uses static variable to ensure only one registry instance exists throughout
 * the plugin lifecycle. This guarantees:
 * - Consistent filter data across all plugin components
 * - No duplicate filter registrations
 * - Efficient memory usage
 * - Predictable behavior
 *
 * USAGE PATTERNS:
 * ==============
 *
 * 1. Filter Registration (typically in audit-filters.php):
 *    $registry = odcm_get_filter_registry_instance();
 *    $registry->register_filter([...]);
 *
 * 2. UI Rendering:
 *    $registry = odcm_get_filter_registry_instance();
 *    $filters = $registry->get_filters();
 *
 * 3. Feature Validation:
 *    $registry = odcm_get_filter_registry_instance();
 *    $date_filter = $registry->get_filter('date_range');
 *
 * PERFORMANCE CONSIDERATIONS:
 * ==========================
 *
 * - Singleton pattern prevents multiple instantiations
 * - Registry data is stored in memory (no database queries)
 * - Filters are registered once during plugin initialization
 * - Retrieval operations are simple array access (O(1))
 *
 * @since 1.0.0
 *
 * @return \OrderDaemon\CompletionManager\Core\FilterRegistry {
 *     The singleton FilterRegistry instance containing all registered filters.
 *
 *     The returned instance provides these methods:
 *     - register_filter(array $args): void
 *     - get_filters(): array
 *     - get_filter(string $filter_id): ?array
 *     - has_filter(string $filter_id): bool
 *     - get_filters_by_tier(string $tier): array
 * }
 *
 * @example
 * ```php
 * // Basic usage - get registry and register a filter
 * $registry = odcm_get_filter_registry_instance();
 * $registry->register_filter([
 *     'id'              => 'date_range',
 *     'label'           => __('Date Range', 'domain'),
 *     'render_callback' => [$this, 'render_date_range_filter'],
 * ]);
 *
 * // UI rendering - get filters and render
 * $registry = odcm_get_filter_registry_instance();
 * $filters = $registry->get_filters();
 *
 * foreach ($filters as $filter) {
 *     echo '<div class="filter-container">';
 *     echo '<label>' . esc_html($filter['label']) . '</label>';
 *     // Call the render callback
 *     call_user_func($filter['render_callback']);
 *     echo '</div>';
 * }
 *
 * // Validation - check if a specific filter exists
 * $registry = odcm_get_filter_registry_instance();
 *
 * if ($registry->has_filter('date_range')) {
 *     // Date range filter is available
 *     $filter = $registry->get_filter('date_range');
 * }
 *
 * // Multiple calls return the same instance (singleton)
 * $registry1 = odcm_get_filter_registry_instance();
 * $registry2 = odcm_get_filter_registry_instance();
 * // $registry1 === $registry2 (same object)
 * ```
 */
function odcm_get_filter_registry_instance(): \OrderDaemon\CompletionManager\Core\FilterRegistry
{
    static $instance = null;

    if ($instance === null) {
        $instance = new \OrderDaemon\CompletionManager\Core\FilterRegistry();
    }

    return $instance;

}//end odcm_get_filter_registry_instance()


/**
 * Internal Registry-Based Logging Function - Core Plugin Event Logger
 *
 * This function serves as the primary logging mechanism for the Order Daemon plugin's
 * internal events. It implements the Registry Pattern to provide structured, consistent,
 * and maintainable logging for all known event types defined in the event registry.
 *
 * REGISTRY INTEGRATION:
 * ====================
 *
 * This function works exclusively with events defined in odcm_get_log_event_types().
 * It validates the event slug against the registry and uses the event's metadata
 * to generate consistent log entries with proper categorization and formatting.
 *
 * DEBUG MODE INTEGRATION:
 * ======================
 *
 * Events with category 'debug' are only logged when ODCM_DEBUG is true. This
 * prevents verbose developer logging from cluttering production audit trails
 * while maintaining full debugging capabilities during development.
 *
 * DYNAMIC SUMMARY GENERATION:
 * ===========================
 *
 * Uses sprintf() with the event's summary_template to generate dynamic summaries
 * from context data. This ensures consistent messaging while allowing for
 * contextual information like order IDs, rule names, etc.
 *
 * ASYNCHRONOUS PROCESSING:
 * =======================
 *
 * Delegates to the existing odcm_log_event() function to maintain compatibility
 * with the current Action Scheduler-based asynchronous logging architecture.
 * This preserves performance while adding the new registry-based structure.
 *
 * SECURITY CONSIDERATIONS:
 * =======================
 *
 * - Event slugs are validated against a known registry (no injection risk)
 * - Context data is passed through existing sanitization in odcm_log_event()
 * - Debug mode check prevents information leakage in production
 * - Function is internal-only (not exposed to third-party code)
 *
 * @since 1.0.0
 *
 * @param string $event_slug {
 *     The unique event identifier that must exist in the event registry.
 *     Must match a key in the array returned by odcm_get_log_event_types().
 *
 *     Examples:
 *     - 'order_completed'
 *     - 'rule_matched'
 *     - 'invalid_order'
 *     - 'process_order_check_start' (debug event)
 * }
 * @param array $context_data {
 *     Associative array of data used to populate the summary template and
 *     provide additional context for the log entry.
 *
 *     Common keys:
 *     - 'order_id': (int) WooCommerce order ID
 *     - 'rule_name': (string) Name of the completion rule
 *     - 'error_message': (string) Error details for failure events
 *     - 'user_id': (int) WordPress user ID
 *     - 'payload': (array) Additional structured data
 *
 *     The array values are used with sprintf() to populate the summary template.
 *     Order matters - values are used positionally with template placeholders.
 * }
 *
 * @return bool True if the event was successfully queued for logging, false on failure.
 *              Returns false immediately for debug events when debug mode is disabled.
 *
 * @example
 * ```php
 * // Log a successful order completion
 * odcm_log_event(
 *     'Order #123 completed successfully',
 *     ['completion_time' => time()],
 *     123,
 *     'success',
 *     'order_completed'
 * );
 *
 * // Log a rule match with context
 * odcm_log_event(
 *     'Order #456 matched completion rule: Virtual Products Auto-Complete',
 *     ['rule_id' => 789],
 *     456,
 *     'info',
 *     'rule_matched'
 * );
 *
 * // Debug event
 * odcm_log_event(
 *     'Starting order check process for order #789',
 *     ['trigger' => 'woocommerce_order_status_processing'],
 *     789,
 *     'debug',
 *     'process_order_check_start'
 * );
 *
 * // Basic event logging
 * $result = odcm_log_event('Custom plugin action completed');
 * // Result: true (if Action Scheduler is available)
 * ```
 */

/**
 * Public Custom Event Logging API - Third-Party Developer Interface
 *
 * This function provides a simple, flexible, and well-documented API for third-party
 * developers to log custom events to the Order Daemon audit trail system. It offers
 * full access to the status registry while maintaining the 'custom' log category
 * for proper categorization and UI treatment.
 *
 * DESIGN PHILOSOPHY:
 * =================
 *
 * This function is designed to be the public face of the logging system for
 * external developers. It prioritizes:
 * - Simplicity: Clear, intuitive parameter structure
 * - Flexibility: Support for all status types and optional parameters
 * - Validation: Robust input validation with sensible defaults
 * - Integration: Full access to the existing status registry
 * - Documentation: Comprehensive examples and usage patterns
 *
 * STATUS REGISTRY INTEGRATION:
 * ===========================
 *
 * Unlike the internal logging function, this API validates the status parameter
 * against the full status registry (odcm_get_log_statuses()). This ensures:
 * - Third-party events get proper UI styling and treatment
 * - Consistent status handling across all log entries
 * - Automatic fallback to 'info' for invalid statuses
 * - Full access to all available status types
 *
 * CATEGORIZATION:
 * ==============
 *
 * All events logged through this function are automatically assigned the
 * 'custom' log category. This enables:
 * - Clear distinction between plugin and third-party events
 * - Proper filtering and UI treatment
 * - Consistent audit trail organization
 * - Future extensibility for custom event management
 *
 * ASYNCHRONOUS PROCESSING:
 * =======================
 *
 * Like the internal logging function, this API delegates to odcm_log_event()
 * to maintain compatibility with the existing Action Scheduler-based
 * asynchronous logging architecture. This ensures consistent performance
 * and reliability regardless of the logging source.
 *
 * SECURITY CONSIDERATIONS:
 * =======================
 *
 * - All parameters are validated and sanitized
 * - Status validation prevents invalid CSS class injection
 * - Summary text is passed through existing sanitization
 * - No direct database access (uses existing secure pipeline)
 * - Payload data is handled by existing sanitization functions
 *
 * @since 1.0.0
 *
 * @param string $summary {
 *     A brief, human-readable summary of the event.
 *     This will be displayed as the main log entry text in the audit trail.
 *
 *     Guidelines:
 *     - Keep concise but descriptive (recommended: 50-100 characters)
 *     - Use active voice ("User updated settings" vs "Settings were updated")
 *     - Include relevant context (order IDs, user names, etc.)
 *     - Avoid sensitive information (passwords, API keys, etc.)
 *
 *     Examples:
 *     - "Custom integration processed order #123"
 *     - "Third-party plugin updated customer data"
 *     - "External API sync completed successfully"
 * }
 * @param array|null $payload {
 *     Optional associative array of additional structured data related to the event.
 *     This data is stored separately and can be viewed in the audit trail details.
 *
 *     Common use cases:
 *     - API response data
 *     - Configuration changes
 *     - Error details and stack traces
 *     - Performance metrics
 *     - Integration-specific metadata
 *
 *     The payload will be JSON-encoded and stored in the payloads table.
 *     Avoid including large binary data or circular references.
 * }
 * @param int|null $order_id {
 *     Optional WooCommerce order ID to associate with this event.
 *     When provided, the event will appear in order-specific audit trail views
 *     and can be filtered by order ID in the admin interface.
 *
 *     Use cases:
 *     - Order processing events
 *     - Payment gateway interactions
 *     - Shipping integrations
 *     - Customer communication events
 * }
 * @param string $status {
 *     The status/severity level of the event. Must be a valid status from the
 *     status registry (odcm_get_log_statuses()). Invalid statuses will be
 *     automatically converted to 'info' with a warning logged.
 *
 *     Available statuses:
 *     - 'success': Successful operations, completions
 *     - 'error': Failures, exceptions, critical issues
 *     - 'warning': Non-critical issues, deprecation notices
 *     - 'info': General information, status updates
 *     - 'notice': Important notifications, reminders
 *     - 'debug': Development/troubleshooting information
 *     - 'critical': System-critical failures
 *     - 'pending': Operations in progress
 *     - 'skipped': Intentionally bypassed operations
 *     - 'completed': Finished processes, final states
 * }
 * @param string|null $event_type {
 *     Optional custom event type identifier for categorization and filtering.
 *     If not provided, defaults to 'custom_event' for generic third-party events.
 *
 *     Naming conventions:
 *     - Use lowercase with underscores: 'my_plugin_sync'
 *     - Include plugin/integration name: 'mailchimp_subscriber_update'
 *     - Be descriptive but concise: 'payment_gateway_webhook'
 *
 *     This field is used for:
 *     - Filtering events in the admin interface
 *     - Grouping related events
 *     - Integration-specific reporting
 *     - Debugging and troubleshooting
 * }
 *
 * @return bool True if the event was successfully queued for logging, false on failure.
 *              Failure can occur due to missing Action Scheduler or system errors.
 *
 * @example
 * ```php
 * // Basic usage - log a simple event
 * odcm_log_event('My plugin performed an action');
 *
 * // Log with status and order association
 * odcm_log_event(
 *     'Payment gateway webhook processed',
 *     null,
 *     123, // order_id
 *     'success'
 * );
 *
 * // Full usage with all parameters
 * odcm_log_event(
 *     'External API sync completed',
 *     [
 *         'api_endpoint' => 'https://api.example.com/sync',
 *         'records_processed' => 150,
 *         'duration_ms' => 2500,
 *         'response_code' => 200
 *     ],
 *     456, // order_id
 *     'success',
 *     'external_api_sync'
 * );
 *
 * // Error logging with details
 * odcm_log_event(
 *     'Failed to connect to external service',
 *     [
 *         'service' => 'inventory_api',
 *         'error_code' => 'CONNECTION_TIMEOUT',
 *         'retry_count' => 3,
 *         'last_attempt' => time()
 *     ],
 *     null, // no specific order
 *     'error',
 *     'inventory_sync_error'
 * );
 *
 * // Integration-specific event
 * odcm_log_event(
 *     'MailChimp subscriber updated',
 *     [
 *         'subscriber_email' => 'customer@example.com',
 *         'list_id' => 'abc123',
 *         'tags_added' => ['customer', 'vip'],
 *         'merge_fields' => ['FNAME' => 'John', 'LNAME' => 'Doe']
 *     ],
 *     789,
 *     'success',
 *     'mailchimp_subscriber_update'
 * );
 *
 * // Warning with custom event type
 * odcm_log_event(
 *     'Deprecated API endpoint used',
 *     [
 *         'endpoint' => '/api/v1/orders',
 *         'replacement' => '/api/v2/orders',
 *         'deprecation_date' => '2024-12-31'
 *     ],
 *     null,
 *     'warning',
 *     'api_deprecation_notice'
 * );
 * ```
 */
function odcm_log_event(
    string $summary,
    array $data = [],
    ?int $order_id = null,
    string $status = 'info',
    string $event_type = 'event',
    bool $is_test = false,
    ?string $process_id = null,
    ?string $parent_event_type = null
): bool {
    global $wpdb;

    // Guard clause - ensure Action Scheduler is available
    if (!function_exists('as_enqueue_async_action')) {
        return false;
    }

    // Validate and sanitize summary
    if (empty($summary) || !is_string($summary)) {
        $summary = 'Event logged';
    }

    // Validate status against registry
    $available_statuses = odcm_get_log_statuses();
    if (!array_key_exists($status, $available_statuses)) {
        $status = 'info';
    }

    // If rich 'components' array is already provided in the data, use it directly.
    // Otherwise, create a default wrapper component for backward compatibility.
    if (isset($data['components']) && is_array($data['components']) && !empty($data['components'])) {
        $components = $data['components'];
    } else {
        $level = in_array($status, ['error','warning','info','debug','success'], true) ? $status : 'info';
        if ($level === 'success') {
            $level = 'info';
        }

        $components = [[
            'k' => odcm_component_key(),
            'event_type' => $event_type,
            'ts' => time(),
            'label' => $summary,
            'level' => $level,
            'data' => $data,
        ]];
    }

    // If rawData is already provided in the data, use it directly.
    // Otherwise, check if it's nested somewhere we can extract it from.
    $rawData = null;
    if (isset($data['rawData']) && is_array($data['rawData']) && !empty($data['rawData'])) {
        $rawData = $data['rawData'];
    }

    $envelope = [
        'type' => 'event',
        'cid' => ($order_id ? (string)$order_id : 'na') . ':' . time(),
        'oid' => $order_id,
        'actor' => [
            'id' => get_current_user_id() ?: null,
            'role' => null,
            'name' => null
        ],
        'ts' => time(),
        'status' => $status,
        'summary' => $summary,
        'components' => $components,
    ];

    // Add rawData to envelope if present
    if ($rawData !== null) {
        $envelope['rawData'] = $rawData;
    }

    // Prepare full event data
    $event_data = [
        'summary' => $summary,
        'status' => $status,
        'event_type' => $event_type,
        'order_id' => $order_id,
        'is_test' => $is_test,
        'envelope' => $envelope,
        'source' => 'logger',
        'timestamp' => current_time('mysql'),
        'data' => $data,
        'parent_event_type' => $parent_event_type,
    ];

    // Add process ID if provided or auto-detect
    if ($process_id) {
        $event_data['process_id'] = $process_id;
    } else {
        $event_data = odcm_maybe_add_process_id($event_data);
    }

    // Generate unique queue ID
    $queue_id = uniqid('odcm_log_', true);

    // Check if this event has already been queued to prevent duplicates
    $duplicate_prevention_key = 'odcm_event_queue_' . md5($queue_id . wp_json_encode($event_data));

    // Try to get from cache first
    $already_queued = wp_cache_get($duplicate_prevention_key);

    // If not already queued or cached, proceed with storing in queue table
    if (false === $already_queued) {
        // Set a short-lived lock to prevent duplicate inserts during high concurrency
        wp_cache_set($duplicate_prevention_key, 'queuing', '', 60); // 1 minute lock

        // PHASE 1: Store in queue table
        $queue_result = odcm_insert_audit_log_queue_entry(
            $queue_id,
            wp_json_encode($event_data),
            $event_data['timestamp'],
            'pending'
        );

        if ($queue_result === false) {
            // Release lock on failure
            wp_cache_delete($duplicate_prevention_key);
            odcm_log_message("Failed to queue log entry: " . $wpdb->last_error, 'error');
            return false;
        }

        // Cache the successfully queued event to prevent duplicates
        // Use a longer cache time to prevent duplicate processing during high load
        wp_cache_set($duplicate_prevention_key, 'queued', '', defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);
    } else {
        // This event is already being processed or was recently queued
        $debug_enabled = (defined('ODCM_DEBUG') && ODCM_DEBUG) || get_option('odcm_dev_debug_override', 0);
        if ($debug_enabled) {
            odcm_log_message("Duplicate log entry detected for {$queue_id}, skipping queue insertion", 'debug');
        }
        return true; // Return true as this is not a failure case
    }

    // PHASE 2: Schedule background processing
    $action_id = as_enqueue_async_action(
        'odcm_process_queued_log_entry',
        ['queue_id' => $queue_id],  // Tiny! Always under 180 bytes
        'odcm-logs'
    );

    if (!$action_id) {
        odcm_log_message("Failed to schedule queue processing for {$queue_id}", 'error');
        // Data is still in queue, will be picked up by cleanup job
        return false;
    }

    // Debug logging
    $debug_enabled = (defined('ODCM_DEBUG') && ODCM_DEBUG) || get_option('odcm_dev_debug_override', 0);
    if ($debug_enabled) {
        odcm_log_message("Queued log entry {$queue_id} for processing (Action ID: {$action_id})", 'info');
    }

    return true;
}

/**
 * Get Log Event Types Registry - Global Wrapper Function
 *
 * This function provides global access to the log event types registry defined
 * in the LogRegistries.php file. It serves as a bridge between the namespaced
 * registry functions and the global logging API.
 *
 * @since 1.0.0
 * @return array Array of event type definitions
 */
function odcm_get_log_event_types(): array
{
    return \OrderDaemon\CompletionManager\Core\odcm_get_log_event_types();
}

/**
 * Get Log Status Registry - Global Wrapper Function
 *
 * This function provides global access to the log status registry defined
 * in the LogRegistries.php file. It serves as a bridge between the namespaced
 * registry functions and the global logging API.
 *
 * @since 1.0.0
 * @return array Array of status definitions
 */
function odcm_get_log_statuses(): array
{
    return \OrderDaemon\CompletionManager\Core\odcm_get_log_statuses();
}

/**
 * Encode status string to integer code - Global Wrapper Function
 *
 * @since 1.0.0
 * @param string $status Status string
 * @return int Status code
 */
function odcm_encode_status(string $status): int
{
    return \OrderDaemon\CompletionManager\Core\odcm_encode_status($status);
}

/**
 * Decode status code to string - Global Wrapper Function
 *
 * @since 1.0.0
 * @param int $code Status code
 * @return string Status string
 */
function odcm_decode_status(int $code): string
{
    return \OrderDaemon\CompletionManager\Core\odcm_decode_status($code);
}

/**
 * Encode source string to integer code - Global Wrapper Function
 *
 * @since 1.0.0
 * @param string $source Source string
 * @return int Source code
 */
function odcm_encode_source(string $source): int
{
    return \OrderDaemon\CompletionManager\Core\odcm_encode_source($source);
}

/**
 * Decode source code to string - Global Wrapper Function
 *
 * @since 1.0.0
 * @param int $code Source code
 * @return string Source string
 */
function odcm_decode_source(int $code): string
{
    return \OrderDaemon\CompletionManager\Core\odcm_decode_source($code);
}

/**
 * Validate custom summary against character limits
 *
 * Enforces character limits for custom event summaries to ensure
 * they fit within Action Scheduler payload constraints.
 *
 * @since 1.0.0
 * @param string $summary The custom summary to validate
 * @param int $max_length Maximum allowed character length (default: 60)
 * @return string Validated and potentially truncated summary
 */
function odcm_validate_custom_summary(string $summary, int $max_length = 60): string
{
    if (strlen($summary) <= $max_length) {
        return $summary;
    }

    // Truncate with ellipsis, ensuring we don't break in the middle of a word
    $truncated = substr($summary, 0, $max_length - 3);
    $last_space = strrpos($truncated, ' ');

    if ($last_space !== false && $last_space > $max_length * 0.8) {
        // If we have a space in the last 20% of the string, break there
        $truncated = substr($truncated, 0, $last_space);
    }

    return $truncated . '...';
}

/**
 * Get the plugin's uploads directory with fallback
 *
 * This function retrieves the path to the WordPress uploads directory. It uses
 * wp_upload_dir() as the primary method. If that fails, it constructs the path
 * using wp_content_dir() or the WP_CONTENT_DIR constant as fallbacks.
 *
 * @since 1.0.0 (Enhanced in 2.0.5)
 * @return string The uploads directory path.
 */
function odcm_get_uploads_dir(): string {
    static $cached_dir = null;
    if ($cached_dir !== null) {
        return $cached_dir;
    }

    $uploads = wp_upload_dir();
    $basedir = '';

    if (!empty($uploads['basedir'])) {
        $basedir = $uploads['basedir'];
    } else {
        // Fallback to WordPress standard uploads directory
        if (function_exists('wp_content_dir')) {
            $content_dir = wp_content_dir();
        } elseif (defined('WP_CONTENT_DIR')) {
            $content_dir = WP_CONTENT_DIR;
        } else {
            // Last resort fallback
            $content_dir = ABSPATH . 'wp-content';
        }
        $basedir = rtrim($content_dir, '/\\') . '/uploads';
    }
    
    $cached_dir = wp_normalize_path($basedir);
    
    return $cached_dir;
}

/**
 * Helper function to retrieve a backtrace with a limit and time budget.
 *
 * This function replaces direct calls to `debug_backtrace()` in performance‑critical
 * code paths. It respects the configured `$limit` (maximum number of frames) and
 * `$budget` (maximum execution time in milliseconds). If the backtrace generation
 * exceeds the budget, the function returns `null` and logs a warning.
 *
 * @param int $limit  Maximum number of stack frames to capture.
 * @param int $budget Maximum allowed time in milliseconds.
 * @return array|null Backtrace array if within budget, otherwise `null`.
 */
function odcm_get_backtrace(int $limit, int $budget): ?array
{
    // Input validation
    if (!is_int($limit) || $limit < 1 || $limit > 100) {
        odcm_log_message('Invalid limit parameter for backtrace', 'error');
        return null;
    }

    if (!is_int($budget) || $budget < 1 || $budget > 1000) {
        odcm_log_message('Invalid budget parameter for backtrace', 'error');
        return null;
    }

    // Use WordPress error logging
    $error_log = odcm_get_database_logs();

    if (empty($error_log)) {
        return null;
    }

    // Filter recent errors within budget timeframe
    $recent_errors = array_filter($error_log, function($log) use ($budget) {
        $timestamp = strtotime($log['timestamp']);
        $elapsed_ms = (microtime(true) - $timestamp) * 1000.0;
        return $elapsed_ms <= $budget;
    });

    // Return limited results
    return array_slice($recent_errors, 0, $limit);
}

/**
 * Get the plugin's base directory
 *
 * @return string The plugin base directory path
 */
function odcm_get_plugin_dir(): string {
    return plugin_dir_path(__FILE__);
}

/**
 * Get the plugin's base URL
 *
 * @return string The plugin base URL
 */
function odcm_get_plugin_url(): string {
    return plugin_dir_url(__FILE__);
}

/**
 * Efficiently retrieves metadata for multiple posts using a single database query and caches the result.
 *
 * This function is designed to be highly performant. It first checks for cached data in a transient
 * to avoid database queries altogether on subsequent requests for the same set of post IDs.
 * If no transient is found, it uses `update_meta_cache()` to warm up the WordPress object cache
 * for all requested post IDs in a single, optimized database query. It then retrieves the meta
 * for each post (from the now-primed cache) and stores the consolidated results in a transient
 * for future requests.
 *
 * HPOS Compatibility: For shop_order post types, this function automatically uses OrderMetaManager
 * to ensure compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * @param array $post_ids An array of post IDs.
 * @return array An associative array where keys are post IDs and values are their metadata.
 */
function odcm_get_post_meta_by_ids(array $post_ids): array
{
    if (empty($post_ids)) {
        return [];
    }

    // Create a unique cache key based on the post IDs to avoid collisions.
    $cache_key   = 'odcm_meta_for_posts_' . md5(wp_json_encode($post_ids));
    $cached_meta = get_transient($cache_key);

    // If a valid transient exists, return the cached data immediately.
    if (false !== $cached_meta) {
        return $cached_meta;
    }

    // Separate order IDs from other post IDs for HPOS compatibility
    $order_ids = [];
    $other_post_ids = [];

    foreach ($post_ids as $post_id) {
        if (\OrderDaemon\CompletionManager\Includes\Utils\OrderTypeDetector::is_processable_order($post_id)) {
            $order_ids[] = $post_id;
        } else {
            $other_post_ids[] = $post_id;
        }
    }

    $all_meta = [];

    // Handle orders through OrderMetaManager for HPOS compatibility
    if (!empty($order_ids)) {
        // Import OrderMetaManager if not already available
        if (!class_exists('OrderDaemon\\CompletionManager\\Includes\\Utils\\OrderMetaManager')) {
            require_once __DIR__ . '/Utils/OrderMetaManager.php';
        }

        foreach ($order_ids as $order_id) {
            // For orders, get all meta keys. Since OrderMetaManager doesn't have a get_all_meta method,
            // we'll need to get the order object and extract all meta data
            $order = \OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager::get_order($order_id);
            if ($order) {
                // Get all meta data from the order object
                $order_meta = [];
                $meta_data = $order->get_meta_data();
                foreach ($meta_data as $meta) {
                    $key = $meta->get_data()['key'];
                    $value = $meta->get_data()['value'];
                    // Store as array to match get_post_meta format (which returns arrays)
                    $order_meta[$key] = [$value];
                }
                $all_meta[$order_id] = $order_meta;
            } else {
                $all_meta[$order_id] = [];
            }
        }
    }

    // Handle other post types using traditional method
    if (!empty($other_post_ids)) {
        // Prime the WordPress object cache for non-order post IDs in a single database query.
        update_meta_cache('post', $other_post_ids);

        foreach ($other_post_ids as $post_id) {
            // This call will now hit the pre-warmed object cache, not the database.
            $all_meta[$post_id] = get_post_meta($post_id);
        }
    }

    // Cache the consolidated result for 1 hour.
    set_transient($cache_key, $all_meta, (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600));

    return $all_meta;
}
/**
 * Action Scheduler Args Column Readability Enhancement
 *
 * Improves the readability of the 'Args' column for our plugin's scheduled actions
 * in the Action Scheduler admin interface. This function intercepts the column output,
 * extracts smart summaries from event_data, and creates collapsible details with
 * pretty-printed JSON for enhanced UX.
 *
 * REQUIREMENTS ANALYSIS:
 * =====================
 *
 * Target Output Format:
 * <details>
 *   <summary>Order #96 completed successfully. event_data (click to expand)</summary>
 *   <pre><code>{
 *     "summary": "Order #96 completed successfully",
 *     "status": "success",
 *     "event_type": "order_completed",
 *     // ... formatted JSON payload
 *   }</code></pre>
 * </details>
 *
 * SMART SUMMARY EXTRACTION:
 * ========================
 *
 * 1. For event_data arrays: Extract event_data['summary'] field
 * 2. For simple args: Generate contextual summary (e.g., "Order #96")
 * 3. Fallback: Return original output unchanged (fail-safe)
 *
 * COMPATIBILITY DESIGN:
 * ====================
 *
 * This implementation is designed to play well with other plugins and Action Scheduler:
 * - Only processes actions that belong to our plugin (odcm_ prefix check)
 * - Returns original output unchanged for all other plugins' actions
 * - Uses defensive coding with proper row array validation
 * - Implements graceful fallbacks for edge cases
 * - No external dependencies (pure PHP + WordPress functions)
 *
 * SECURITY CONSIDERATIONS:
 * =======================
 *
 * - All output is properly escaped using esc_html() before rendering
 * - No user input is processed (only Action Scheduler internal data)
 * - Uses WordPress core functions for JSON encoding and escaping
 * - Inline styles are minimal and safe (no user-controlled content)
 *
 * PERFORMANCE CONSIDERATIONS:
 * ==========================
 *
 * - Early return for non-plugin actions (minimal overhead for other plugins)
 * - Efficient string prefix checking using strpos() === 0
 * - JSON formatting only applied when necessary
 * - No database queries or external API calls
 *
 * PLUGIN PREFIX DETECTION:
 * =======================
 *
 * The function checks for the 'odcm_' prefix which is used consistently
 * throughout the plugin for all scheduled actions:
 * - odcm_process_order_check (main order processing)
 * - odcm_reprocess_orders_batch (batch reprocessing)
 * - odcm_process_log_entry (log processing)
 *
 * @since 1.0.0
 *
 * @param string $output The original HTML output for the Args column from Action Scheduler.
 * @param array $row The Action Scheduler row array containing hook, args, and other data.
 *
 * @return string The modified HTML output with collapsible details for our plugin's actions,
 *                or the original output unchanged for other plugins' actions.
 *
 * @example
 * Before: <ul><li><code>'event_data' => array(...)</code></li></ul>
 * After:  <details>
 *           <summary>Order #96 completed successfully. event_data (click to expand)</summary>
 *           <pre><code>{ "summary": "Order #96 completed successfully", ... }</code></pre>
 *         </details>
 */
function odcm_format_as_args_column($output, $row) {
    // Defensive check: Ensure we have a valid row array
    if (!is_array($row) || !isset($row['hook']) || !isset($row['args'])) {
        return $output;
    }

    // Get the hook name from the row
    $hook = $row['hook'];

    // Early return if hook is not a string or is empty
    if (!is_string($hook) || empty($hook)) {
        return $output;
    }

    // Define the prefixes for our plugin's action hooks
    $plugin_prefixes = ['odcm_'];
    $is_our_action = false;

    // Check if this action belongs to our plugin
    foreach ($plugin_prefixes as $prefix) {
        if (strpos($hook, $prefix) === 0) {
            $is_our_action = true;
            break;
        }
    }

    // If this action does not belong to our plugin, return original output unchanged
    if (!$is_our_action) {
        return $output;
    }

    // Get the arguments from the row
    $args = $row['args'];

    // If args are empty or not an array, return original output
    if (empty($args) || !is_array($args)) {
        return $output;
    }

    // Smart Summary Extraction Logic
    $summary_text = '';
    $key_name = '';

    // Check for event_data structure (audit logging system)
    if (isset($args['event_data']) && is_array($args['event_data']) && isset($args['event_data']['summary'])) {
        $summary_text = $args['event_data']['summary'];
        $key_name = 'event_data';
    }
    // Check for simple order_id structure
    elseif (isset($args['order_id']) && is_numeric($args['order_id'])) {
        $summary_text = "Order #{$args['order_id']}";
        $key_name = 'order_id';
    }
    // Fallback: Unknown structure - return original output unchanged
    else {
        return $output;
    }

    // Format the arguments into a human-readable, indented JSON string
    $formatted_args = json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // If JSON encoding failed, return original output
    if ($formatted_args === false) {
        return $output;
    }

    // Escape the string for security before outputting
    $escaped_args = esc_html($formatted_args);
    $escaped_summary = esc_html($summary_text);
    $escaped_key_name = esc_html($key_name);

    // Create collapsible details with smart summary
    $new_output = sprintf(
        '<details><summary>%s. %s (click to expand)</summary><pre style="white-space: pre-wrap; word-break: break-all; margin: 0; font-family: monospace; font-size: 12px; line-height: 1.4; max-width: 100%%; overflow-wrap: break-word;"><code>%s</code></pre></details>',
        $escaped_summary,
        $escaped_key_name,
        $escaped_args
    );

    return $new_output;
}

// Hook the function to the Action Scheduler args column filter
// Priority 10 ensures it runs at the default priority level
// 2 parameters: $output and $action object
add_filter('action_scheduler_list_table_column_args', 'odcm_format_as_args_column', 10, 2);




/**
 * Add process ID to event data if it's an order lifecycle event
 *
 * Ensures registry-based canonical events (with nested data.order_id) receive
 * a shared process_id as well.
 *
 * @param array $event_data
 * @return array
 */
function odcm_maybe_add_process_id(array $event_data): array
{
    // Must have an event_type
    if (empty($event_data['event_type'])) {
        return $event_data;
    }

    // Resolve order_id from top-level or nested canonical data
    $order_id = null;
    if (!empty($event_data['order_id'])) {
        $order_id = (int) $event_data['order_id'];
    } elseif (!empty($event_data['data']) && is_array($event_data['data']) && !empty($event_data['data']['order_id'])) {
        $order_id = (int) $event_data['data']['order_id'];
    }

    if (empty($order_id) || $order_id <= 0) {
        return $event_data;
    }

    // Discover lifecycle family
    if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
        require_once __DIR__ . '/../Core/ProcessLifecycleDiscovery.php';
    }
    $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
    $families = $discovery->get_process_families();
    $lifecycle_types = isset($families['order_lifecycle']['process_types']) && is_array($families['order_lifecycle']['process_types'])
        ? $families['order_lifecycle']['process_types']
        : [];

    // Known main-table lifecycle slugs used by our logging pipeline
$main_table_lifecycle = [
    'checkout_processing',
    'block_checkout_processed',
    'status_change_processing',
    'manual_status_change',
    'rule_execution',
    'order_completion',
    'process_started',
    'no_rules_matched',  // Debug
];
    $lifecycle_union = array_values(array_unique(array_merge($lifecycle_types, $main_table_lifecycle)));

    $event_type = (string) $event_data['event_type'];
    if (!in_array($event_type, $lifecycle_union, true)) {
        return $event_data;
    }

    // Get or create process ID for this order
    if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessIdManager')) {
        require_once __DIR__ . '/../Core/ProcessIdManager.php';
    }
    $process_manager = \OrderDaemon\CompletionManager\Core\ProcessIdManager::instance();
    $process_id = $process_manager->get_or_create_process_id((int) $order_id);

    // Add process ID to event data (both top-level and nested canonical data)
    $event_data['process_id'] = $process_id;
    if (!empty($event_data['data']) && is_array($event_data['data'])) {
        $event_data['data']['process_id'] = $process_id;
        if (empty($event_data['data']['order_id'])) {
            $event_data['data']['order_id'] = (int) $order_id;
        }
    }

    return $event_data;
}

/**
 * Get current ISO 8601 timestamp in site timezone.
 *
 * Uses wp_date('c') to respect WordPress site timezone settings configured under Settings > General.
 *
 * @since 1.0.0
 * @return string ISO 8601 formatted date-time.
 */
function odcm_iso8601_now(): string
{
    return wp_date('c');
}

/**
 * Format a UNIX timestamp to ISO 8601 in the site timezone.
 *
 * @since 1.0.0
 * @param int $timestamp UNIX timestamp (seconds since epoch).
 * @return string ISO 8601 formatted date-time.
 */
function odcm_iso8601_from_timestamp(int $timestamp): string
{
    return wp_date('c', $timestamp);
}

/**
 * Generate a unique component key.
 * Uses optimized format: c{timestamp}{random}[-{suffix}]
 *
 * @param string|null $suffix
 * @return string
 */
function odcm_component_key(string $suffix = null): string
{
    return 'c' . time() . wp_rand(10, 99) . ($suffix ? '-' . $suffix : '');
}

/**
 * WordPress-compliant wrapper function for inserting audit log queue entries.
 *
 * This function replaces direct database queries with a WordPress-compliant approach
 * that follows best practices for database operations. It provides proper error
 * handling, sanitization, and logging.
 *
 * @since 1.2.1
 *
 * @param string $queue_id     The unique queue ID for the log entry
 * @param string $event_data   JSON-encoded event data
 * @param string $created_at   MySQL-formatted timestamp
 * @param string $status       The status of the queue entry (e.g., 'pending')
 * @return int|false The number of rows inserted, or false on error
 */
function odcm_insert_audit_log_queue_entry(string $queue_id, string $event_data, string $created_at, string $status = 'pending'): int|false
{
    global $wpdb;
    // Prepare data for insertion with proper sanitization
    $data = [
        'queue_id' => $queue_id,
        'event_data' => $event_data,
        'created_at' => $created_at,
        'status' => $status
    ];

    // Use DatabaseHelper for database operations with proper error handling
    $database_helper = \OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper::get_instance();
    $result = $database_helper->insert(
        $wpdb->prefix . 'odcm_audit_log_queue',
        $data,
        ['%s', '%s', '%s', '%s'] // Format: string, string, string, string
    );

    // Handle errors appropriately
    if ($result === false) {
        // Log the error using the plugin's logging system
        odcm_log_message("Failed to insert audit log queue entry: " . $wpdb->last_error, 'error');

        // Return false to indicate failure
        return false;
    }

    // Return the number of rows inserted (should be 1 for successful insert)
    return $result;
}

/**
 * Validate and sanitize JSON data
 *
 * @param string $json_string JSON string to validate
 * @param bool $assoc Whether to return associative array
 * @return array|object Validated and sanitized data
 * @throws InvalidArgumentException If JSON is invalid
 */
function odcm_validate_and_sanitize_json(string $json_string, bool $assoc = true) {
    // Validate JSON structure
    if (!wp_json_validate($json_string)) {
        throw new InvalidArgumentException('Invalid JSON data provided');
    }

    // Decode JSON
    $data = json_decode($json_string, $assoc);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('JSON decoding error: ' . esc_html(json_last_error_msg()));
    }

    // Sanitize decoded data
    return odcm_sanitize_data($data);
}

/**
 * Sanitize data recursively
 *
 * @param mixed $data Data to sanitize
 * @return mixed Sanitized data
 */
function odcm_sanitize_data($data) {
    if (is_array($data)) {
        return array_map('odcm_sanitize_data', $data);
    } elseif (is_string($data)) {
        return sanitize_text_field($data);
    } elseif (is_int($data)) {
        return absint($data);
    } elseif (is_float($data)) {
        return floatval($data);
    } elseif (is_bool($data)) {
        return (bool) $data;
    } else {
        return $data;
    }
}

/**
 * Validate and sanitize specific parameters
 *
 * @param array $params Parameters to validate
 * @param array $rules Validation rules
 * @return array Validated and sanitized parameters
 * @throws InvalidArgumentException If validation fails
 */
function odcm_validate_and_sanitize_params(array $params, array $rules): array {
    $validated = [];

    foreach ($rules as $param => $rule) {
        if (!isset($params[$param])) {
            if (isset($rule['required']) && $rule['required']) {
                throw new InvalidArgumentException("Required parameter missing: $param");
            }
            continue;
        }

        $value = $params[$param];

        // Type validation
        switch ($rule['type']) {
            case 'string':
                if (!is_string($value)) {
                    throw new InvalidArgumentException("Parameter $param must be a string");
                }
                $validated[$param] = sanitize_text_field($value);
                break;

            case 'integer':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException("Parameter $param must be an integer");
                }
                $validated[$param] = absint($value);
                break;

            case 'boolean':
                $validated[$param] = (bool) $value;
                break;

            case 'array':
                if (!is_array($value)) {
                    throw new InvalidArgumentException("Parameter $param must be an array");
                }
                $validated[$param] = odcm_sanitize_data($value);
                break;

            default:
                throw new InvalidArgumentException("Unknown validation type: " . esc_html($rule['type']));
        }

        // Additional validation rules
        if (isset($rule['min']) && $validated[$param] < $rule['min']) {
            /* translators: %s: parameter name, %s: minimum value */
            throw new InvalidArgumentException("Parameter $param must be at least {$rule['min']}");
        }

        if (isset($rule['max']) && $validated[$param] > $rule['max']) {
            /* translators: %s: parameter name, %s: maximum value */
            throw new InvalidArgumentException("Parameter $param must be at most {$rule['max']}");
        }
    }

    return $validated;
}

/**
 * File Operation Utilities
 * 
 * These functions provide secure, validated file operations for the plugin.
 * They replace direct file operations with proper validation and error handling.
 */

/**
 * Validate a file path for security
 *
 * Ensures the path is safe for file operations by checking for directory traversal
 * and other security issues.
 *
 * @since 2.0.5
 * @param string $path The file path to validate
 * @return bool True if path is safe, false otherwise
 */
function odcm_validate_file_path(string $path): bool {
    // Normalize the path
    $normalized = wp_normalize_path($path);
    
    // Check for directory traversal attempts
    if (strpos($normalized, '../') !== false || strpos($normalized, '..\\') !== false) {
        return false;
    }
    
    // Check for absolute paths outside allowed directories
    $allowed_dirs = [
        wp_upload_dir()['basedir'],
        wp_content_dir(),
        ABSPATH,
        plugin_dir_path(__FILE__),
    ];
    
    foreach ($allowed_dirs as $allowed_dir) {
        $allowed_dir = wp_normalize_path($allowed_dir);
        if (strpos($normalized, $allowed_dir) === 0) {
            return true;
        }
    }
    
    return false;
}

/**
 * Safe file put contents with validation
 *
 * A wrapper for file_put_contents that includes proper validation and error handling.
 * Follows WordPress security best practices.
 *
 * @since 2.0.5
 * @param string $filename The filename to write to
 * @param mixed $data The data to write
 * @param int $flags Flags for file_put_contents
 * @param resource $context Stream context
 * @return int|false The number of bytes written, or false on failure
 */
function odcm_safe_file_put_contents(string $filename, $data, int $flags = 0, $context = null) {
    // $context is retained for backward‑compatibility but is ignored because WP_Filesystem does not support it.
    // Validate the file path
    if (!odcm_validate_file_path($filename)) {
        odcm_critical_log("Invalid file path attempted: " . esc_html($filename));
        return false;
    }
    
    // Ensure the directory exists and is writable
    $directory = dirname($filename);
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    if (!is_dir($directory) || !$wp_filesystem->is_writable($directory)) {
        odcm_critical_log("Directory not writable or doesn't exist: " . esc_html($directory));
        return false;
    }
    
    // Use WordPress error logging for failures
    // $context is retained for backward‑compatibility but is ignored because WP_Filesystem does not support it.
    $result = $wp_filesystem->put_contents($filename, $data, $flags);
    
    if ($result === false) {
        odcm_critical_log("Failed to write to file: " . esc_html($filename));
        return false;
    }
    
    return $result;
}

/**
 * Ensure a directory exists and is writable
 *
 * Creates the directory if it doesn't exist and verifies it's writable.
 * Uses WordPress directory creation functions.
 *
 * @since 2.0.5
 * @param string $directory The directory path
 * @return bool True if directory exists and is writable, false otherwise
 */
function odcm_ensure_directory_writable(string $directory): bool {
    // Normalize the path
    $directory = wp_normalize_path($directory);
    
    // Check if directory exists
    if (!is_dir($directory)) {
        // Try to create the directory
        if (!wp_mkdir_p($directory)) {
            odcm_critical_log("Failed to create directory: " . esc_html($directory));
            return false;
        }
    }
    
    // Initialize WP_Filesystem if needed
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    
    // Check if directory is writable using WP_Filesystem
    if (! $wp_filesystem->is_writable($directory)) {
        odcm_critical_log("Directory is not writable: " . esc_html($directory));
        return false;
    }
    
    return true;
}

/**
 * Get a safe debug file path
 *
 * Returns a validated debug file path within the uploads directory.
 * Ensures the directory exists and is writable.
 *
 * @since 2.0.5
 * @return string The safe debug file path
 */
function odcm_get_safe_debug_file_path(): string {
    $uploads_dir = odcm_get_uploads_dir();
    $debug_dir = $uploads_dir . '/order-daemon-debug';
    
    // Ensure the debug directory exists and is writable
    if (!odcm_ensure_directory_writable($debug_dir)) {
        // Fallback to uploads directory
        return $uploads_dir . '/debug.log';
    }
    
    return $debug_dir . '/debug.log';
}

/**
 * Get the WordPress content directory using WordPress function
 *
 * Wrapper for wp_content_dir() with proper fallback handling using our helper methods.
 *
 * @since 2.0.5
 * @return string The content directory path
 */
function odcm_get_content_dir(): string {
    if (function_exists('wp_content_dir')) {
        return wp_normalize_path(trailingslashit(wp_content_dir()));
    }
    
    // Fallback to uploads directory parent (since uploads is typically in wp-content)
    $uploads_dir = odcm_get_uploads_dir();
    $content_dir = dirname($uploads_dir);
    
    // If uploads is not in wp-content, fall back to ABSPATH
    if (basename($content_dir) !== 'wp-content') {
        $content_dir = wp_normalize_path(trailingslashit(ABSPATH . 'wp-content'));
    }
    
    return $content_dir;
}

/**
 * Get the plugin's cache directory
 *
 * Returns a dedicated cache directory for the plugin with proper validation.
 *
 * @since 2.0.5
 * @return string The cache directory path
 */
function odcm_get_cache_directory(): string {
    static $cached_dir = null;
    
    if ($cached_dir !== null) {
        return $cached_dir;
    }
    
    $uploads_dir = odcm_get_uploads_dir();
    $cache_dir = $uploads_dir . '/order-daemon-cache';
    
    // Ensure the cache directory exists and is writable
    if (odcm_ensure_directory_writable($cache_dir)) {
        $cached_dir = $cache_dir;
    } else {
        // Fallback to uploads directory
        $cached_dir = $uploads_dir;
    }
    
    return $cached_dir;
}