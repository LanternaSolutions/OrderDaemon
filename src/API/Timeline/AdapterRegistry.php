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
     * WordPress-compatible logger instance
     *
     * @var object|null
     * @since 1.2.0
     */
    private static $logger = null;

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
            // Order-related events
            elseif (strpos($event_type, 'order_') !== false || strpos($event_type, 'status_changed') !== false) {
                self::logDebugMessage("ODCM ADAPTER DEBUG: Selecting OrderEventAdapter for event type: {$event_type}", 'debug');
                $adapter = self::createAdapter('OrderEventAdapter', $event_type);
            }
            // Payment and checkout events
            elseif (strpos($event_type, 'payment') !== false || strpos($event_type, 'checkout') !== false) {
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
            protected function extractSpecializedFields(array $payload): array
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

        // Fallback to PHP error log
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($formatted_message);
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
