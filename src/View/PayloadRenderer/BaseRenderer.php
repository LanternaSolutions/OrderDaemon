<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Base Renderer Class
 *
 * Clean, simple base class implementing Template Method pattern for payload rendering.
 * Provides shared functionality while allowing specialized renderers to focus on their
 * specific event types.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class BaseRenderer
{
    /**
     * Log a debug message using WordPress-compatible logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    protected function logDebugMessage(string $message, string $level = 'debug'): void
    {
        // Only log if debug mode is enabled
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }
        
        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($message, $level);
            return;
        }
        
        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
            return;
        }
        
        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_' . $level, $message);
            return;
        }
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
            $debug_file = WP_CONTENT_DIR . '/debug.log';
            @file_put_contents(
                $debug_file,
                '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
                FILE_APPEND
            );
            return;
        }
    }
    /**
     * Component theme
     *
     * @var string|null
     */
    protected $theme = null;

    /**
     * Constructor
     *
     * Initializes the renderer with theme as null. Specialized renderers will set their
     * specific themes in their constructors, or it will fall back to 'default' in render().
     */
    public function __construct() {}

    /**
     * Render Component
     *
     * Template Method that defines the rendering algorithm. Specialized renderers
     * implement the abstract methods to provide their specific behavior.
     *
     * This method handles both regular rendering and timeline rendering, using the
     * same code path for maintainability and consistency.
     *
     * @param array  $payload    The full event payload including rawData and other context.
     * @param string $event_type The type of event being rendered.
     * @param array  $timeline   Optional timeline data (label, ts, level).
     * @return string Complete HTML component.
     */
    public function render(array $payload, string $event_type, array $timeline = []): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        
        // Debug log the event type and renderer class
        $this->logDebugMessage(sprintf(
            "ODCM Debug - Rendering event: type=%s, renderer=%s",
            $event_type,
            get_class($this)
        ));
        
        // Get renderer-specific content
        $content = $this->renderContent($payload, $event_type);
        
        // Get component metadata - use provided label but allow override
        $label = $timeline['label'] ?? null;
        $label = $this->getLabel($payload, $event_type) ?: $label;
        
        $statusPill = $this->getStatusPill($payload, $event_type);
        
        // Theme resolution logging and fallback
        $this->logDebugMessage(sprintf(
            "ODCM Debug - Theme resolution start: event_type=%s, current_theme=%s, renderer=%s",
            $event_type,
            $this->theme ?? 'null',
            get_class($this)
        ));

        if ($this->theme === null) {
            $this->theme = 'default';
            $this->logDebugMessage(sprintf(
                "ODCM Debug - Using default theme: event_type=%s, renderer=%s",
                $event_type,
                get_class($this)
            ));
        } else {
            $this->logDebugMessage(sprintf(
                "ODCM Debug - Using specialized theme: event_type=%s, theme=%s, renderer=%s",
                $event_type,
                $this->theme,
                get_class($this)
            ));
        }
        
        // Build options array
        $options = [];
        
        // Add timeline-specific options if provided
        if (!empty($timeline)) {
            $options['timestamp'] = $timeline['ts'] ?? null;
            $options['level'] = $timeline['level'] ?? null;
            
            // Debug log timeline data
            $this->logDebugMessage(sprintf(
                "ODCM Debug - Timeline data: ts=%s, level=%s",
                $options['timestamp'] ?? 'null',
                $options['level'] ?? 'null'
            ));
        }
        
        if ($statusPill !== null) {
            $options['status_pill'] = $statusPill;
        }
        
        // Add event_type for debugging display in header
        $options['event_type'] = $event_type;
        
        // Debug log the final HTML classes that will be used
        $finalClasses = sprintf('odcm-component odcm-component--%s', esc_attr($this->theme));
        $this->logDebugMessage(sprintf(
            "ODCM Debug - Final HTML classes: %s",
            $finalClasses
        ));
        
        // Assemble final component
        $result = $toolkit->render_component_shell(
            $label,
            $this->theme,
            $content,
            $options
        );
        
        // Debug log the final rendered HTML (truncated for log readability)
        $this->logDebugMessage(sprintf(
            "ODCM Debug - Rendered HTML (truncated): %s",
            substr($result, 0, 150) . (strlen($result) > 150 ? '...' : '')
        ));
        
        return $result;
    }

    /**
     * Render Content
     *
     * Renders the inner content of the component. Must be implemented by specialized
     * renderers to handle their specific event types.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    /**
     * Render Content - Template Method
     *
     * This is the template method that defines the rendering algorithm.
     * Child classes MUST NOT override this method directly.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    final public function renderContent(array $payload, string $event_type): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $content = '';
        
        // 1. Call to specialized content rendering - implemented by child classes
        $content .= $this->renderSpecificContent($payload, $event_type, $toolkit);
        
        // 2. Collect all debug-like data for a unified debug section
        $debug_data = [];
        
        // Add metrics to debug data if they exist
        if (!empty($payload['metrics'])) {
            $debug_data = array_merge($debug_data, $payload['metrics']);
        }
        
        // Add correlation ID to debug data if it exists
        if (!empty($payload['correlation_id'])) {
            $debug_data['correlation_id'] = $payload['correlation_id'];
        }
        
        // Add component counts to debug data if they exist
        if (!empty($payload['component_count'])) {
            $debug_data['component_count'] = $payload['component_count'];
        }
        
        // Add processing time to debug data if it exists
        if (!empty($payload['processing_time'])) {
            $debug_data['processing_time'] = $payload['processing_time'];
        }
        
        // Add technical details to debug data if they exist
        if (!empty($payload['technical_details'])) {
            foreach ($payload['technical_details'] as $key => $value) {
                $debug_data[$key] = $value;
            }
        }
        
        // 3. Render the unified debug section if we have debug data
        if (!empty($debug_data)) {
            $content .= $this->renderDebugSection($debug_data, $toolkit);
        }
        
        return $content;
    }

    /**
     * Render Specific Content
     *
     * This method should be implemented by child classes to provide
     * event-specific rendering logic.
     *
     * @param array                    $data       The payload data to render
     * @param string                   $event_type The type of event being rendered
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @return string HTML content
     */
    protected function renderSpecificContent(array $payload, string $event_type, PayloadComponentUIToolkit $toolkit): string
    {
        // Default implementation for base renderer
        return $toolkit->render_text_block(
            sprintf('Event type: %s', $event_type)
        );
    }

    /**
     * Get Label
     *
     * Gets the component label/title. Can be overridden by specialized renderers
     * to provide event-specific labels.
     *
     * @param array  $payload    The payload data
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $payload, string $event_type): string
    {
        // Map technical event types to business-friendly terms
        $event_type_mapping = [
            'rule_execution' => 'Rule Evaluation',
            'order_status_changed' => 'Order Status Changed',
            'payment_completed' => 'Payment Completed',
            'payment_failed' => 'Payment Failed',
            'block_checkout_processed' => 'Checkout Completed',
            'manual_status_change' => 'Status Updated',
            'process_started' => 'Process Started',
            'process_ended' => 'Process Completed'
        ];
        
        if (isset($event_type_mapping[$event_type])) {
            return $event_type_mapping[$event_type];
        }
        
        // Use rule name directly if available for rule events
        if ($event_type === 'rule_execution' && !empty($payload['rule_name'])) {
            return 'Rule: ' . $payload['rule_name'];
        }
        
        // Fall back to generic formatting
        return ucwords(str_replace('_', ' ', $event_type));
    }

    /**
     * Check if an event is a debug event
     *
     * Examines payload data to determine if this is a debug event.
     * Debug events are identified by specific flags in their data
     * or by their classification in the event registry.
     *
     * @param array $payload The payload data to check
     * @return bool True if this is a debug event
     */
    protected function isDebugEvent(array $payload): bool
    {
        // Check for explicit debug flag
        if (isset($payload['is_debug']) && $payload['is_debug'] === true) {
            return true;
        }

        // Check for debug level
        if (isset($payload['level']) && strtolower($payload['level']) === 'debug') {
            return true;
        }

        // Check for debug context
        if (isset($payload['context']['debug']) && $payload['context']['debug'] === true) {
            return true;
        }

        // Check for debug source
        if (isset($payload['source']) && strpos(strtolower($payload['source']), 'debug_') === 0) {
            return true;
        }

        // Check for debug event type
        if (isset($payload['event_type']) && strpos(strtolower($payload['event_type']), 'debug_') === 0) {
            return true;
        }

        // Check for debug components
        if (isset($payload['components']) && is_array($payload['components'])) {
            foreach ($payload['components'] as $component) {
                if (is_array($component) && isset($component['level']) && strtolower($component['level']) === 'debug') {
                    return true;
                }
            }
        }
        
        // Check event registry for events categorized as debug
        if (isset($payload['event_type']) && function_exists('odcm_get_log_event_types')) {
            $event_types = odcm_get_log_event_types();
            $event_type = $payload['event_type'];
            
            if (isset($event_types[$event_type]) && 
                isset($event_types[$event_type]['category']) && 
                $event_types[$event_type]['category'] === 'debug') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get Status Pill
     *
     * Gets status pill configuration. Debug events always get a DEBUG pill.
     * Otherwise, falls back to event-specific status pills.
     *
     * @param array  $payload    The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config with 'label' and 'type', or null for no pill
     */
    protected function getStatusPill(array $payload, string $event_type): ?array
    {
        // Debug events always get a DEBUG pill
        if ($this->isDebugEvent($payload)) {
            return ['label' => 'DEBUG', 'type' => 'debug'];
        }

        // Check for explicit status
        if (isset($payload['status'])) {
            $status = strtolower($payload['status']);
            return ['label' => ucfirst($status), 'type' => $status];
        }

        // Check for level-based status
        if (isset($payload['level'])) {
            $level = strtolower($payload['level']);
            return ['label' => ucfirst($level), 'type' => $level];
        }

        return null;
    }

    /**
     * Format Currency
     *
     * Formats currency values with a clean format without HTML wrapping.
     *
     * @param float|string $amount   Amount to format
     * @param string       $currency Currency code
     * @return string Formatted amount with currency
     */
    protected function formatCurrency($amount, string $currency): string
    {
        // Get the currency symbol without HTML entities
        $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol($currency));
        // Format the amount with the currency symbol, no HTML wrapping
        return $currency_symbol . number_format((float)$amount, 2);
    }

    /**
     * Format Bytes
     *
     * Formats byte values into human-readable format.
     *
     * @param int $bytes Number of bytes
     * @return string Formatted size
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format Metric Value
     *
     * Formats metric values with appropriate units.
     *
     * @param float|int $value Metric value
     * @param string    $unit  Unit of measurement
     * @return string Formatted value with unit
     */
    /**
     * Render Debug Section
     *
     * Renders a unified debug section that can include various types of debug data:
     * - Performance metrics
     * - Correlation IDs
     * - Technical implementation details
     * - Component counts
     * - Other debug information
     *
     * @param array                    $debug_data  The debug data to render (metrics, correlation IDs, etc)
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @param string                   $title      Optional section title, defaults to 'Technical Details'
     * @return string HTML content
     */
    protected function renderDebugSection(array $debug_data, PayloadComponentUIToolkit $toolkit, string $title = 'Technical Details'): string
    {
        // Group technical details into logical categories for better organization
        $grouped_data = [
            'Performance Metrics' => [],
            'System Context' => [],
            'Event Details' => [],
            'Process Information' => [],
            'Other Details' => [] // Fallback for ungrouped items
        ];
        
        // Define keys for each category
        $performance_keys = [
            'execution_time_ms',
            'processing_time',
            'evaluation_time',
            'loading_time',
            'query_time',
            'render_time'
        ];
        $system_keys = [
            'wp_version',
            'wc_version',
            'plugin_version',
            'theme',
            'checkout_type',
            'is_store_api'
        ];
        $event_keys = [
            'event_type',
            'event_source',
            'event_channel',
            'event_id',
            'idempotency_key',
            'canonical_event'
        ];
        $process_keys = [
            'process_id',
            'correlation_id',
            'component_count',
            'source',
            'origin'
        ];
        
        // Sort data into groups
        foreach ($debug_data as $key => $value) {
            $formattedKey = ucwords(str_replace('_', ' ', $key));
            
            // Format millisecond values to be more readable
            if (is_float($value) && strpos($key, '_ms') !== false) {
                $value = number_format($value, 4) . ' ms';
            }
            // Format correlation IDs to be more readable
            elseif (is_string($value) && (strpos($key, 'correlation') !== false || strpos($key, 'process_id') !== false)) {
                // Truncate long correlation IDs for readability if needed
                if (strlen($value) > 20) {
                    $value = substr($value, 0, 8) . '...' . substr($value, -8);
                }
            }
            // Format complex data (arrays/objects) more intelligently
            elseif (is_array($value)) {
                if (count($value) > 3) {
                    // For larger arrays, use a structured display
                    $value = $this->formatComplexData($value);
                } else {
                    // For small arrays, keep as comma-separated list
                    $value = implode(', ', array_map('strval', $value));
                }
            }
            
            // Determine which group this item belongs to
            if ($this->keyMatchesAny($key, $performance_keys)) {
                $grouped_data['Performance Metrics'][$formattedKey] = (string)$value;
            } elseif ($this->keyMatchesAny($key, $system_keys)) {
                $grouped_data['System Context'][$formattedKey] = (string)$value;
            } elseif ($this->keyMatchesAny($key, $event_keys)) {
                $grouped_data['Event Details'][$formattedKey] = (string)$value;
            } elseif ($this->keyMatchesAny($key, $process_keys)) {
                $grouped_data['Process Information'][$formattedKey] = (string)$value;
            } else {
                $grouped_data['Other Details'][$formattedKey] = (string)$value;
            }
        }
        
        // Combine all non-empty groups into a single organized output
        $content = '';
        foreach ($grouped_data as $group_title => $group_data) {
            if (!empty($group_data)) {
                $content .= $toolkit->render_key_value_list($group_data, $group_title);
            }
        }
        
        // If no content was generated, fall back to simpler rendering
        if (empty($content)) {
            $formatted_data = [];
            foreach ($debug_data as $key => $value) {
                $formattedKey = ucwords(str_replace('_', ' ', $key));
                $formatted_data[$formattedKey] = is_array($value) ? json_encode($value) : (string)$value;
            }
            $content = $toolkit->render_key_value_list($formatted_data, $title);
        }
        
        return $content;
    }
    
    /**
     * Format complex data for display
     * 
     * @param array $data The complex data to format
     * @return string Formatted representation
     */
    private function formatComplexData(array $data): string
    {
        // For small arrays with simple values, use comma-separated representation
        if (count($data) <= 5 && !$this->hasNestedArrays($data)) {
            return implode(', ', array_map(function($item) {
                return is_scalar($item) ? (string)$item : '[complex]';
            }, $data));
        }
        
        // For more complex data, return a structured summary
        $count = count($data);
        $types = $this->getValueTypes($data);
        
        return sprintf(
            '%d items [%s]', 
            $count, 
            implode(', ', $types)
        );
    }
    
    /**
     * Check if data has nested arrays
     * 
     * @param array $data The data to check
     * @return bool True if contains nested arrays
     */
    private function hasNestedArrays(array $data): bool
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get summary of value types in an array
     * 
     * @param array $data The data to analyze
     * @return array Array of type strings
     */
    private function getValueTypes(array $data): array
    {
        $types = [];
        foreach ($data as $key => $value) {
            $type = gettype($value);
            if (!in_array($type, $types)) {
                $types[] = $type;
            }
        }
        return $types;
    }
    
    /**
     * Check if a key matches any string in an array
     * 
     * @param string $key The key to check
     * @param array $possibilities Possible matches
     * @return bool True if matches any
     */
    private function keyMatchesAny(string $key, array $possibilities): bool
    {
        foreach ($possibilities as $possibility) {
            if (strpos($key, $possibility) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function formatMetricValue($value, string $unit = ''): string
    {
        switch ($unit) {
            case 'ms':
            case 'milliseconds':
                return number_format((float)$value, 2) . ' ms';
                
            case 's':
            case 'seconds':
                return number_format((float)$value, 2) . ' s';
                
            case 'bytes':
            case 'b':
                return $this->formatBytes((int)$value);
                
            case '%':
            case 'percent':
                return number_format((float)$value, 2) . '%';
                
            case 'count':
            case 'items':
                return number_format((int)$value);
                
            default:
                // If unit provided but not special-cased
                if (!empty($unit)) {
                    return number_format((float)$value, 2) . ' ' . $unit;
                }
                
                // No unit, just format the number
                if (is_int($value) || $value == (int)$value) {
                    return number_format((int)$value);
                } else {
                    return number_format((float)$value, 2);
                }
        }
    }

    /**
     * Get User Name
     *
     * Gets user display name from ID.
     *
     * @param int $user_id WordPress user ID
     * @return string User display name
     */
    protected function getUserName(int $user_id): string
    {
        $user = get_userdata($user_id);
        if ($user) {
            return $user->display_name;
        }
        return 'User #' . $user_id;
    }

    /**
     * Get Status Type
     *
     * Maps WooCommerce status to appropriate status type.
     *
     * @param string $status WooCommerce status
     * @return string UI status type
     */
    protected function getStatusType(string $status): string
    {
        $status_map = [
            'completed' => 'success',
            'processing' => 'info',
            'on-hold' => 'warning',
            'cancelled' => 'error',
            'refunded' => 'warning',
            'failed' => 'error',
            'pending' => 'notice',
        ];
        
        return $status_map[strtolower($status)] ?? 'info';
    }
}
