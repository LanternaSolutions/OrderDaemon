<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Registry for mapping event types to appropriate display adapters
 *
 * Provides WordPress-compliant adapter registration and selection for timeline events.
 * Implements error handling, input validation, and extensibility hooks according to 
 * WordPress plugin development standards.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
class AdapterRegistry
{
    /**
     * Cache for adapter instances to improve performance
     *
     * @var array<string, DisplayAdapter>
     * @since 1.2.0
     */
    private static array $adapter_cache = [];

    /**
     * SINGLE SOURCE OF TRUTH: Internal-only events that should NEVER be displayed
     * 
     * These are system implementation details, not debugging information.
     * They are filtered at all levels: database queries, component extraction, and rendering.
     * 
     * IMPORTANT: To add a new internal-only event, add it here and it will be filtered everywhere.
     * 
     * @since 1.2.1
     */
    private const INTERNAL_ONLY_EVENTS = [
        'rule_no_match',              // Individual rule evaluation failures (verbose noise)
        '_status_evaluation',         // Status change evaluation telemetry
        'process_started',            // Internal process lifecycle
        'order_loaded',               // Technical loading events
        'order_check_scheduled',      // Internal scheduling events
        'rule_evaluation_non_canonical', // Debug traces for rule evaluation
    ];

    /**
     * WordPress-compatible logger instance
     *
     * @var object|null
     * @since 1.2.0
     */
    private static $logger = null;

    /**
     * Check if debug mode is enabled (static version)
     */
    private static function isDebugMode(): bool
    {
        return (defined('WP_DEBUG') && WP_DEBUG) || (defined('ODCM_DEBUG') && ODCM_DEBUG);
    }

    /**
     * Get appropriate adapter for event payload with WordPress compliance
     *
     * Implements proper input validation, error handling, and WordPress hooks
     * for extensibility. Uses caching for performance optimization.
     *
     * @since 1.2.0
     * 
     * @param array $payload The event payload containing event data.
     * @return DisplayAdapter The appropriate adapter instance.
     * 
     * @example
     * $adapter = AdapterRegistry::getAdapterForEvent($payload);
     * $display_data = $adapter->extractDisplayData($payload);
     */
    public static function getAdapterForEvent(array $payload): DisplayAdapter
    {
        // Input validation - ensure payload is valid
        if (empty($payload) || !is_array($payload)) {
            self::logDebugMessage('Invalid payload provided to getAdapterForEvent', 'warning');
            return self::getFallbackAdapter();
        }

        // Sanitize event type extraction with defensive check
        $event_type = $payload['event_type'] ?? '';
        if (empty($event_type) && isset($payload['data']['event_type'])) {
            $event_type = $payload['data']['event_type'];
        }
        // Apply basic sanitization if WordPress function is available
        if (function_exists('sanitize_text_field')) {
            try {
                $event_type = sanitize_text_field($event_type);
            } catch (\Throwable $e) {
                // Fallback: basic sanitization
                $event_type = preg_replace('/[^a-zA-Z0-9_\-]/', '', $event_type);
            }
        } else {
            // Fallback: basic sanitization
            $event_type = preg_replace('/[^a-zA-Z0-9_\-]/', '', $event_type);
        }
        if (empty($event_type)) {
            $event_type = 'unknown';
        }

        // Memory usage check (WordPress performance best practice)
        if (function_exists('memory_get_usage') && memory_get_usage() > (128 * 1024 * 1024)) {
            self::logDebugMessage('High memory usage detected during adapter selection', 'warning');
        }

        // Check cache first (performance optimization)
        $cache_key = 'adapter_' . md5($event_type);
        if (isset(self::$adapter_cache[$cache_key])) {
            return self::$adapter_cache[$cache_key];
        }

        // Apply WordPress filter for extensibility with defensive check
        if (function_exists('apply_filters')) {
            try {
                $event_type = apply_filters('odcm_timeline_adapter_event_type', $event_type, $payload);
            } catch (\Throwable $e) {
                // Fallback: continue with original event type
            }
        }

        // Check if this might be an incomplete rule event
        $event_type = $payload['event_type'] ?? '';
        $isPotentialIncompleteRule = (strpos($event_type, 'rule_execution') !== false) &&
                                    empty($payload['rule_execution']['rule_name']) &&
                                    empty($payload['rule_name']) &&
                                    empty($payload['data']['rule_name']);

        if ($isPotentialIncompleteRule) {
            // In non-debug mode, return fallback adapter which will return empty fields
            if (!self::isDebugMode()) {
                self::logDebugMessage("ODCM ADAPTER DEBUG: Skipping incomplete rule event in production mode", 'debug');
                return self::getFallbackAdapter();
            }

            self::logDebugMessage("ODCM ADAPTER DEBUG: Processing incomplete rule event in debug mode", 'debug');
        }

        // Moved debug filtering to rendering stage to preserve process grouping
        // Only filter out events that should never be processed at all
        // Debug events should get proper adapter processing for correct process_id handling
        if (self::shouldFilterEventFromDisplay($event_type, $payload)) {
            self::logDebugMessage("ODCM ADAPTER DEBUG: Excluding event entirely: {$event_type}", 'debug');
            return self::getFallbackAdapter();
        }

        // Priority-based adapter selection with error handling
        $adapter = null;

        // Log the event type being processed
        self::logDebugMessage("ODCM ADAPTER DEBUG: Processing event type: {$event_type}", 'debug');

        try {
            // Rule execution events
            if (strpos($event_type, 'rule_execution') !== false) {
                self::logDebugMessage("ODCM ADAPTER DEBUG: Selecting RuleExecutionAdapter for event type: {$event_type}", 'debug');
                $adapter = self::createAdapter('RuleExecutionAdapter', $event_type);
            }
        // Order-related events (including subscription events)
        elseif (strpos($event_type, 'order_') !== false ||
                strpos($event_type, 'status_changed') !== false ||
                strpos($event_type, 'status change') !== false ||
                strpos($event_type, 'status_evaluation') !== false ||
                strpos($event_type, 'subscription_') !== false ||
                strpos($event_type, 'renewal_payment_') !== false ||
                $event_type === 'trial_ending') {
            self::logDebugMessage("ODCM ADAPTER DEBUG: Selecting OrderEventAdapter for event type: {$event_type}", 'debug');
            $adapter = self::createAdapter('OrderEventAdapter', $event_type);
        }
            // Payment and checkout events
            elseif (strpos($event_type, 'payment') !== false ||
                    strpos($event_type, 'checkout') !== false ||
                    $event_type === 'checkout_processed' ||
                    strpos($event_type, 'payment.') !== false) {
                self::logDebugMessage("ODCM ADAPTER DEBUG: Selecting PaymentEventAdapter for event type: {$event_type}", 'debug');
                $adapter = self::createAdapter('PaymentEventAdapter', $event_type);
            }
            // Default to generic adapter
            else {
                self::logDebugMessage("ODCM ADAPTER DEBUG: Selecting GenericEventAdapter for event type: {$event_type}", 'debug');
                $adapter = self::createAdapter('GenericEventAdapter', $event_type);
            }

            // Log the adapter that was created
            if ($adapter !== null) {
                self::logDebugMessage("ODCM ADAPTER DEBUG: Successfully created adapter: " . get_class($adapter), 'debug');
            } else {
                self::logDebugMessage("ODCM ADAPTER DEBUG: Adapter creation returned null for event type: {$event_type}", 'warning');
            }

            // Allow WordPress filters to override adapter selection with defensive check
            if (function_exists('apply_filters')) {
                try {
                    $originalAdapter = $adapter;
                    $adapter = apply_filters('odcm_timeline_adapter_selection', $adapter, $event_type, $payload);
                    if ($adapter !== $originalAdapter) {
                        self::logDebugMessage("ODCM ADAPTER DEBUG: Adapter was overridden by filter to: " . get_class($adapter), 'debug');
                    }
                } catch (\Throwable $e) {
                    // Fallback: continue with current adapter
                    self::logDebugMessage("ODCM ADAPTER DEBUG: Filter override failed: " . $e->getMessage(), 'warning');
                }
            }

        } catch (\Throwable $e) {
            // WordPress-compliant error handling
            self::logDebugMessage('Adapter creation failed: ' . $e->getMessage(), 'error');
            self::logDebugMessage('Exception trace: ' . $e->getTraceAsString(), 'error');
            $adapter = self::getFallbackAdapter();
        }

        // Ensure we have a valid adapter
        if (!($adapter instanceof DisplayAdapter)) {
            self::logDebugMessage('Invalid adapter returned, using fallback', 'warning');
            $adapter = self::getFallbackAdapter();
        }

        // Cache the adapter for performance
        self::$adapter_cache[$cache_key] = $adapter;

        // WordPress action hook for extensibility with defensive check
        if (function_exists('do_action')) {
            try {
                do_action('odcm_timeline_adapter_selected', $adapter, $event_type, $payload);
            } catch (\Throwable $e) {
                // Fallback: silently continue
            }
        }

        return $adapter;
    }

    /**
     * Create adapter instance with class existence checks
     *
     * Implements WordPress-compliant error handling and class loading.
     *
     * @since 1.2.0
     *
     * @param string $adapter_class The adapter class name (without namespace).
     * @param string $event_type The event type for logging.
     * @return DisplayAdapter|null The adapter instance or null on failure.
     */
    private static function createAdapter(string $adapter_class, string $event_type): ?DisplayAdapter
    {
        $full_class_name = __NAMESPACE__ . '\\' . $adapter_class;

        // Check if class exists (WordPress best practice)
        if (!class_exists($full_class_name)) {
            self::logDebugMessage("Adapter class {$full_class_name} does not exist for event type: {$event_type}", 'error');
            return null;
        }

        // Attempt to instantiate with error handling
        try {
            $adapter = new $full_class_name();
            
            if (!($adapter instanceof DisplayAdapter)) {
                self::logDebugMessage("Class {$full_class_name} is not a valid DisplayAdapter", 'error');
                return null;
            }

            return $adapter;

        } catch (\Throwable $e) {
            self::logDebugMessage("Failed to instantiate {$full_class_name}: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Get fallback adapter for error cases
     *
     * Provides graceful degradation when primary adapters fail.
     *
     * @since 1.2.0
     *
     * @return DisplayAdapter A basic functional adapter.
     */
    private static function getFallbackAdapter(): DisplayAdapter
    {
        // Use anonymous class as last resort fallback
        return new class extends DisplayAdapter {
            protected function extractSpecializedFields(array &$payload): array
            {
                $fields = [];

                // Extract basic event information safely with defensive check
                $event_type = $payload['event_type'] ?? 'unknown_event';
                if (function_exists('sanitize_text_field')) {
                    try {
                        $event_type = sanitize_text_field($event_type);
                    } catch (\Throwable $e) {
                        // Fallback: basic sanitization
                        $event_type = preg_replace('/[^a-zA-Z0-9_\-]/', '', $event_type);
                    }
                } else {
                    // Fallback: basic sanitization
                    $event_type = preg_replace('/[^a-zA-Z0-9_\-]/', '', $event_type);
                }
                $fields['event_description'] = [
                    'label' => $this->translate('Event'),
                    'value' => ucfirst(str_replace('_', ' ', $event_type)),
                    'section' => 'primary'
                ];

                // Extract status if available with defensive check
                if (!empty($payload['status'])) {
                    $status_value = $payload['status'];
                    if (function_exists('sanitize_text_field')) {
                        try {
                            $status_value = sanitize_text_field($status_value);
                        } catch (\Throwable $e) {
                            // Fallback: basic sanitization
                            $status_value = preg_replace('/[^a-zA-Z0-9_\-]/', '', $status_value);
                        }
                    } else {
                        // Fallback: basic sanitization
                        $status_value = preg_replace('/[^a-zA-Z0-9_\-]/', '', $status_value);
                    }
                    $fields['status'] = [
                        'label' => $this->translate('Status'),
                        'value' => ucfirst($status_value),
                        'section' => 'primary'
                    ];
                }

                return $fields;
            }
        };
    }

    /**
     * Event filtering logic for adapter selection phase
     *
     * This method filters events that should never be processed at all during adapter selection.
     * Debug events should get proper adapter processing for correct process_id handling
     * and are filtered later during the rendering phase based on user preferences.
     *
     * @param string $eventType The event type to check
     * @param array $payload The event payload
     * @return bool True if the event should be excluded entirely from processing
     */
    private static function shouldFilterEventFromDisplay(string $eventType, array $payload): bool
    {
        // Events to exclude entirely from processing (never shown, even in debug mode)
        $excludedEvents = [
            // Analysis events
            'refund_analysis',
            'woocommerce_analysis',
            'dedup',
            // System events
            'process_started',
            'process_event',
            'lifecycle_event',
            'action_scheduled',
            // Universal events
            'universal_event_processing',
            'universal_event_processing_debug',
            // Rule evaluation events
            'rule_no_match',
        ];

        // Note: custom_event is intentionally NOT excluded as it serves as both
        // a fallback mechanism for unknown events and a legitimate way for users
        // to add custom events to their timelines

        // Check if event is in the exclusion list
        if (in_array($eventType, $excludedEvents)) {
            return true;
        }

        // Debug-only event handling for metrics
        if ($eventType === 'metrics' && !self::isDebugMode()) {
            return true;
        }

        // Important: Debug events like _status_evaluation, rule_evaluation_non_canonical, etc.
        // are NOT filtered here. They need to be processed to maintain proper process_id
        // grouping and timeline structure. Filtering happens later in the renderer.

        return false;
    }

    /**
     * Get the list of internal-only events (single source of truth)
     * 
     * Internal-only events are system implementation details that should NEVER be displayed,
     * regardless of debug settings. These are different from debug events which can be
     * shown when the user enables debug mode and 'show debug logs' filter.
     *
     * @since 1.2.1
     * @return array List of internal-only event types
     */
    public static function getInternalOnlyEvents(): array
    {
        return self::INTERNAL_ONLY_EVENTS;
    }

    /**
     * Check if an event type is internal-only (should never be displayed)
     *
     * @since 1.2.1
     * @param string $eventType The event type to check
     * @return bool True if the event should never reach the frontend
     */
    public static function isInternalOnlyEvent(string $eventType): bool
    {
        return in_array($eventType, self::INTERNAL_ONLY_EVENTS, true);
    }

    /**
     * Check if an event should be filtered during rendering based on debug preferences
     *
     * This method implements TWO-TIER filtering:
     * 1. Internal-only events: ALWAYS filtered, regardless of debug preferences
     * 2. Debug events: Filtered only when includeDebug=false
     *
     * @param string $eventType The event type to check
     * @param bool $includeDebug Whether the user wants to see debug events
     * @return bool True if the event should be filtered during rendering
     */
    public static function shouldFilterForRendering(string $eventType, bool $includeDebug): bool
    {
        // FIRST: Always filter internal-only events, regardless of debug setting
        // These are system noise that should never reach the frontend
        if (self::isInternalOnlyEvent($eventType)) {
            return true;
        }

        // SECOND: If user wants to see debug events, don't filter anything else
        if ($includeDebug) {
            return false;
        }

        // THIRD: Filter debug events when includeDebug=false
        // These are events that ARE useful for debugging but hidden by default
        $debugOnlyEvents = [
            'universal_event_processing_debug',  // Shows "no rules matched" outcome
        ];

        // Check if this is a debug-only event type
        if (in_array($eventType, $debugOnlyEvents, true)) {
            return true;
        }

        // Special handling for incomplete rule execution events
        if ($eventType === 'rule_execution') {
            // These are considered debug events when they lack complete rule data
            return true;
        }

        return false;
    }

    /**
     * WordPress-compatible debug logging
     *
     * Uses WordPress debug functions when available, falls back to error_log.
     *
     * @since 1.2.0
     *
     * @param string $message The message to log.
     * @param string $level The log level (debug, info, warning, error).
     * @return void
     */
    private static function logDebugMessage(string $message, string $level = 'debug'): void
    {
        // Only log if debug mode is enabled (check both WP_DEBUG and ODCM_DEBUG)
        $debugEnabled = (defined('WP_DEBUG') && WP_DEBUG) || (defined('ODCM_DEBUG') && ODCM_DEBUG);
        if (!$debugEnabled) {
            return;
        }

        // Sanitize message for security with defensive check
        if (function_exists('sanitize_text_field')) {
            try {
                $message = sanitize_text_field($message);
            } catch (\Throwable $e) {
                // Fallback: basic sanitization
                $message = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', $message);
            }
        } else {
            // Fallback: basic sanitization
            $message = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', $message);
        }
        $formatted_message = sprintf('[ODCM Timeline] %s: %s', strtoupper($level), $message);

        // Try WordPress/WooCommerce logging first with defensive check
        if (function_exists('wc_get_logger') && self::$logger === null) {
            try {
                self::$logger = wc_get_logger();
            } catch (\Throwable $e) {
                self::$logger = false; // Mark as unavailable
            }
        }

        if (self::$logger && is_object(self::$logger)) {
            try {
                self::$logger->{$level}($message, ['source' => 'order-daemon-timeline']);
                return;
            } catch (\Throwable $e) {
                // Fall through to WordPress debug log
            }
        }

        // Use WordPress debug log function if available with defensive check
        if (function_exists('wp_debug_log')) {
            try {
                wp_debug_log($formatted_message);
                return;
            } catch (\Throwable $e) {
                // Fallback: continue to error_log
            }
        }

    }

    /**
     * Clear adapter cache
     *
     * Useful for testing and memory management.
     *
     * @since 1.2.0
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$adapter_cache = [];
        // WordPress action hook for cache clearing with defensive check
        if (function_exists('do_action')) {
            try {
                do_action('odcm_timeline_adapter_cache_cleared');
            } catch (\Throwable $e) {
                // Fallback: silently continue
            }
        }
    }

    /**
     * Get cache statistics for debugging
     *
     * @since 1.2.0
     *
     * @return array Cache statistics.
     */
    public static function getCacheStats(): array
    {
        return [
            'cached_adapters' => count(self::$adapter_cache),
            'memory_usage' => function_exists('memory_get_usage') ? memory_get_usage() : 0,
            'cache_keys' => array_keys(self::$adapter_cache)
        ];
    }
}
