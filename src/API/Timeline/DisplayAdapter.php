<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Base display adapter for extracting structured data from event payloads
 *
 * This class implements the display adapter system described in the timeline redesign.
 * It provides a standardized way to extract and organize data from different event types
 * while preserving all original data.
 *
 * **SINGLE SOURCE OF TRUTH - SUMMARIES AND STATUS PILLS**
 *
 * This class contains the canonical methods for generating event summaries and status pills
 * that are used by BOTH the log stream (AuditLogEndpoint) and timeline (RegistryTimelineRenderer).
 * Any changes to how summaries or status pills are generated should be made HERE to ensure
 * consistency across all views.
 *
 * Key unified methods:
 * - `generateUnifiedEventData()` - Returns both summary and status pill data for any event
 * - `generateUnifiedEventSummary()` - Returns the event title/summary string
 * - `extractPrimaryStatusForUnifiedUse()` - Returns status pill label and type
 *
 * Usage:
 * - Log Stream (AuditLogEndpoint::extractConsistentEventData): Calls generateUnifiedEventData()
 * - Timeline (RegistryTimelineRenderer::renderPrimaryInfo): Calls generateUnifiedEventData()
 *
 * This ensures that the same event displays EXACTLY the same title and status pill
 * whether viewed in the log stream list or the timeline detail view.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
abstract class DisplayAdapter
{
    /**
     * Extract display data from payload
     *
     * This method implements the two-layer data storage approach:
     * - Display Layer: Structured, organized data optimized for display
     * - Raw Layer: Complete, unfiltered data payload (nothing is discarded)
     *
     * @param array $payload The event payload to extract data from
     * @return array Extracted display data
     */
    public function extractDisplayData(array $payload): array
    {
        // Extract standard fields common to all events
        $standardFields = $this->extractStandardFields($payload);

        // Extract adapter-specific fields
        $specializedFields = $this->extractSpecializedFields($payload);

        // Look for any additional interesting fields
        $additionalFields = $this->detectAdditionalFields($payload);

        // Organize into display sections
        $result = $this->organizeIntoSections($standardFields, $specializedFields, $additionalFields);

        // If we have display sections from the adapter logic, use them (priority)
        if (!empty($result['display_sections'])) {
            return $result;
        }

        // Fallback: Check if payload has pre-formatted display sections and use them if available
        // This allows events to explicitly define their display presentation if the adapter didn't extract anything
        if (!empty($payload['display_sections']) && is_array($payload['display_sections'])) {
            $first = reset($payload['display_sections']);
            
            // Case 1: Modern flat format ['key' => ['label' => '...', 'value' => '...']]
            if (isset($first['label']) && isset($first['value'])) {
                return [
                    'display_sections' => $payload['display_sections'],
                    'detail_sections' => [],
                    'tech_data' => []
                ];
            }
            
            // Case 2: Nested sections format [{'title' => '...', 'items' => [...]}]
            // We need to flatten this for the unified renderer
            if (isset($first['items']) || isset($first['title'])) {
                $flattened = [];
                foreach ($payload['display_sections'] as $section) {
                    if (!empty($section['items']) && is_array($section['items'])) {
                        foreach ($section['items'] as $item) {
                            if (isset($item['key']) && isset($item['value'])) {
                                $slug = function_exists('sanitize_key') ? sanitize_key($item['key']) : strtolower(preg_replace('/[^a-z0-9_]/i', '_', $item['key']));
                                
                                // Ensure unique keys
                                if (isset($flattened[$slug])) {
                                    $slug .= '_' . uniqid();
                                }
                                
                                $flattened[$slug] = [
                                    'label' => $item['key'],
                                    'value' => $item['value']
                                ];
                            }
                        }
                    }
                }
                
                if (!empty($flattened)) {
                    return [
                        'display_sections' => $flattened,
                        'detail_sections' => [],
                        'tech_data' => []
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Extract standard fields common to all events
     *
     * @param array $payload The event payload
     * @return array Extracted standard fields
     */
    protected function extractStandardFields(array $payload): array
    {
        $fields = [];

        // Extract order ID from multiple possible locations
        $order_id = $this->extractOrderId($payload);
        if ($order_id > 0) {
            $fields['order_id'] = $order_id;
        }

        // Extract event type
        $fields['event_type'] = $payload['event_type'] ?? 'unknown';

        // Extract timestamp - use 'ts' field (Unix timestamp) as primary source
        // Note: Timestamp formatting is handled by JavaScript, so we just pass the raw value
        $timestamp = $payload['ts'] ?? $payload['timestamp'] ?? null;

        if ($timestamp !== null) {
            // Store the raw timestamp for JavaScript formatting
            $fields['timestamp'] = $timestamp;
        } else {
            // If no timestamp is available, explicitly show "no timestamp"
            $fields['timestamp'] = 'no timestamp';
        }

        // Extract process ID
        if (!empty($payload['process_id'])) {
            $fields['process_id'] = $payload['process_id'];
        }

        // Extract correlation ID if available
        if (!empty($payload['correlation_id'])) {
            $fields['correlation_id'] = $payload['correlation_id'];
        }

        return $fields;
    }

    /**
     * Extract specialized fields - to be implemented by specific adapters
     *
     * @param array $payload The event payload
     * @return array Extracted specialized fields
     */
    abstract protected function extractSpecializedFields(array &$payload): array;

    /**
     * Auto-detect potentially useful fields
     *
     * This method scans the payload for fields that might be useful for display
     * but aren't explicitly handled by the adapter.
     *
     * @param array $payload The event payload
     * @return array Detected additional fields
     */
    protected function detectAdditionalFields(array $payload): array
    {
        $fields = [];
        $interestingPatterns = [
            'id', 'code', 'reference', 'number', 'email', 'address',
            'status', 'state', 'type', 'method', 'error', 'message',
            'amount', 'total', 'fee', 'price', 'currency'
        ];

        $this->recursiveScan($payload, $fields, $interestingPatterns);

        return $fields;
    }

    /**
     * Recursively scan payload for interesting fields
     *
     * @param array $data The data to scan
     * @param array &$result The result array to populate
     * @param array $patterns The patterns to look for
     * @param string $prefix The current path prefix
     */
    protected function recursiveScan(array $data, array &$result, array $patterns, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            // Convert key to string to handle numeric keys
            $keyString = is_int($key) ? (string)$key : $key;
            $currentPath = empty($prefix) ? $keyString : $prefix . '.' . $keyString;

            // Skip if this is a complex object that should be handled by specialized methods
            if (is_array($value) && $this->isComplexObject($value)) {
                continue;
            }

            // Check if this key matches any interesting pattern
            $matchesPattern = false;
            foreach ($patterns as $pattern) {
                if (strpos($keyString, $pattern) !== false) {
                    $matchesPattern = true;
                    break;
                }
            }

            // If it matches a pattern and is a scalar value, add it
            if ($matchesPattern && is_scalar($value) && !empty($value)) {
                $result[$currentPath] = $value;
            }
            // If it's an array, recurse
            elseif (is_array($value)) {
                $this->recursiveScan($value, $result, $patterns, $currentPath);
            }
        }
    }

    /**
     * Check if an array represents a complex object that should be handled by specialized methods
     *
     * @param array $data The data to check
     * @return bool True if it's a complex object
     */
    protected function isComplexObject(array $data): bool
    {
        // Check for common complex object indicators
        $complexIndicators = [
            'rule_execution', 'order_evaluation_context', 'trigger_event_context',
            'action_execution', 'condition_evaluation', 'rule_configuration'
        ];

        foreach ($complexIndicators as $indicator) {
            if (isset($data[$indicator]) && is_array($data[$indicator])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Organize extracted fields into unified business data structure
     * with proper field filtering and deduplication.
     *
     * @param array $standardFields Standard fields
     * @param array $specializedFields Specialized fields
     * @param array $additionalFields Additional fields
     * @return array Organized display data with unified business structure
     */
    protected function organizeIntoSections(array $standardFields, array $specializedFields, array $additionalFields): array
    {
        $display_sections = [];
        $tech_data = [];

        // Detect debug mode
        $debugMode = $this->isDebugMode();

        // Business-relevant fields that should be shown in main display
        $business_relevant_fields = [
            'timestamp', 'customer', 'payment_method', 'amount', 'currency',
            'order_id', 'status', 'status_change', 'new_status', 'previous_status',
            'payment_status', 'rule', 'execution_status', 'change_type', 'explanation',
            'debug_explanation', 'context', 'debug_info', 'captured_event', 'processed_event',
            'result', 'checkout_type', 'transaction_id', 'gateway', 'event_description',
            'trigger', 'actions_taken'
        ];

        // Technical fields that should only appear in raw data section
        $technical_fields = [
            'event_type', 'process_id', 'correlation_id', 'idempotency_key',
            'source','attribution', 'metrics', 'component_count', 'actor',
            'real_occurrence_timestamp', 'processing_timestamp',
            'queued_at', 'processed_from_queue', 'technical_context',
            'rule_execution', 'trigger_event_context', 'condition_evaluation',
            'action_execution', 'order_evaluation_context', 'execution_metrics',
            'full_evaluation_trace', 'rawData', 'data'
        ];

        // Track which fields we've already added to avoid duplicates
        $added_fields = [];

        // Helper function to check if a field should be filtered out
        $should_filter_field = function($key, $value) use ($debugMode, $technical_fields) {
            // Filter out technical fields from business display
            if (in_array($key, $technical_fields)) {
                return true;
            }

            // Filter out empty or null values, but allow "no timestamp" and "error" messages
            if (empty($value) && $value !== 0 && $value !== '0' && $value !== 'no timestamp' && $value !== 'error') {
                return true;
            }

            // Filter out event_type in non-debug mode
            if ($key === 'event_type' && !$debugMode) {
                return true;
            }

            // Filter out raw data fields
            if (strpos($key, 'rawData.') === 0 || strpos($key, 'data.') === 0) {
                return true;
            }

            return false;
        };

        // Add standard fields to display sections (filtered for business relevance)
        foreach ($standardFields as $key => $value) {
            if ($should_filter_field($key, $value)) {
                continue;
            }

            // Only add business-relevant standard fields to display
            if (in_array($key, $business_relevant_fields)) {
                $label = $this->formatFieldLabel($key);
                $display_sections[$key] = [
                    'label' => $label,
                    'value' => $value
                ];
                $added_fields[$key] = true;
            }
        }

        // Add specialized fields to display sections (filtered for business relevance)
        foreach ($specializedFields as $key => $config) {
            if (isset($config['label']) && isset($config['value'])) {
                $label = $config['label'];
                $value = $config['value'];

                if ($should_filter_field($key, $value)) {
                    continue;
                }

                // Only add business-relevant specialized fields to display
                if (in_array($key, $business_relevant_fields) ||
                    ($debugMode && in_array($key, ['explanation', 'context', 'debug_info'])) ||
                    strpos($key, 'status') !== false ||
                    strpos($key, 'amount') !== false ||
                    strpos($key, 'customer') !== false ||
                    strpos($key, 'payment') !== false ||
                    strpos($key, 'order') !== false ||
                    strpos($key, 'rule') !== false) {

                    // Skip event_description if we already have it from standard fields
                    if ($key === 'event_description' && isset($display_sections['event_description'])) {
                        continue;
                    }

                    // Skip duplicates
                    if (!isset($added_fields[$key])) {
                        $display_sections[$key] = [
                            'label' => $label,
                            'value' => $value
                        ];
                        $added_fields[$key] = true;
                    }
                }
            }
        }

        // Add select additional fields that might be business-relevant
        foreach ($additionalFields as $key => $value) {
            if ($should_filter_field($key, $value)) {
                continue;
            }

            // Only add fields that look like they might be business-relevant
            if (strpos($key, 'status') !== false ||
                strpos($key, 'amount') !== false ||
                strpos($key, 'customer') !== false ||
                strpos($key, 'payment') !== false ||
                strpos($key, 'order') !== false ||
                strpos($key, 'total') !== false ||
                strpos($key, 'method') !== false) {

                // Avoid duplicates
                if (!isset($added_fields[$key])) {
                    $label = $this->formatFieldLabel($key);
                    $display_sections[$key] = [
                        'label' => $label,
                        'value' => $value
                    ];
                    $added_fields[$key] = true;
                }
            }
        }

        return [
            'display_sections' => $display_sections,
            'detail_sections' => [], // No longer used - all business data is in display_sections
            'tech_data' => $tech_data
        ];
    }

    /**
     * Format field label for display
     *
     * @param string $key The field key
     * @return string Formatted label
     */
    protected function formatFieldLabel(string $key): string
    {
        $labelMappings = [
            'data.order_id' => $this->translate('Order', 'order-daemon'),
            'data.status' => $this->translate('Status', 'order-daemon'),
            'data.amount' => $this->translate('Amount', 'order-daemon'),
            'data.currency' => $this->translate('Currency', 'order-daemon'),
            'data.customer_id' => $this->translate('Customer', 'order-daemon'),
            'data.payment_method' => $this->translate('Payment Method', 'order-daemon'),
            'rawData.from_status' => $this->translate('Previous Status', 'order-daemon'),
            'rawData.to_status' => $this->translate('New Status', 'order-daemon'),
            'rawData.attribution.request_type' => $this->translate('Triggered By', 'order-daemon'),
            'rawData.order_total' => $this->translate('Order Total', 'order-daemon'),
        ];

        // Check for exact match first
        if (isset($labelMappings[$key])) {
            return $labelMappings[$key];
        }

        // Remove technical prefixes and format
        $cleaned = preg_replace('/^(data\.|rawData\.|RawData\s|Data\s)/', '', $key);
        return ucwords(str_replace(['_', '.'], ' ', $cleaned));
    }

    /**
     * Format section label for display
     *
     * @param string $key The section key
     * @return string Formatted label
     */
    protected function formatSectionLabel(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Extract order ID from multiple possible locations in the payload
     *
     * This method implements the enhanced order ID extraction described in the redesign plan
     * to solve the "Order #0" issue.
     *
     * @param array $payload The event payload
     * @return int Order ID or 0 if not found
     */
    protected function extractOrderId(array $payload): int
    {
        // EXPANDED sources list for reliable order ID extraction
        $sources = [
            // Priority 1: Rule execution context (most reliable for rule events)
            $payload['rule_execution']['order_evaluation_context']['order_id'] ?? null,
            $payload['rule_execution']['trigger_event_context']['order_id'] ?? null,

            // Priority 2: Direct in payload
            $payload['order_id'] ?? null,
            $payload['primary_object_id'] ?? null,
            $payload['oid'] ?? null,

            // Priority 3: Nested data structure
            ($payload['data'] ?? [])['order_id'] ?? null,
            ($payload['data'] ?? [])['primary_object_id'] ?? null,
            ($payload['data'] ?? [])['oid'] ?? null,

            // Priority 4: Look in technical_details
            ($payload['technical_details'] ?? [])['order_id'] ?? null,

            // Priority 5: Event data summary
            ($payload['event_data_summary'] ?? [])['order_id'] ?? null,
            ($payload['event_data_summary'] ?? [])['primary_object_id'] ?? null
        ];

        foreach ($sources as $source) {
            if (is_numeric($source) && (int)$source > 0) {
                return (int)$source;
            }
        }

        return 0;
    }

    /**
     * Extract value by dot notation path
     *
     * @param array $data The data array
     * @param string $path The dot notation path
     * @return mixed|null The value or null if not found
     */
    protected function extractValueByPath(array $data, string $path)
    {
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * WordPress-compatible translation function with defensive checks
     *
     * This method provides safe translation fallbacks when WordPress functions
     * are not available, preventing adapter exceptions that cause fallback views.
     *
     * @param string $text The text to translate
     * @param string $domain The text domain (optional)
     * @return string Translated text or original text as fallback
     */
    protected function translate(string $text, string $domain = 'order-daemon'): string
    {
        // Check if WordPress translation function is available
        if (function_exists('__')) {
            try {
                // Use string literals for WordPress i18n compliance
                if ($domain === 'order-daemon') {
                    // Map common text strings to string literals
                    $textMap = [
                        'Order' => __('Order', 'order-daemon'),
                        'Status' => __('Status', 'order-daemon'),
                        'Amount' => __('Amount', 'order-daemon'),
                        'Currency' => __('Currency', 'order-daemon'),
                        'Customer' => __('Customer', 'order-daemon'),
                        'Payment Method' => __('Payment Method', 'order-daemon'),
                        'Previous Status' => __('Previous Status', 'order-daemon'),
                        'New Status' => __('New Status', 'order-daemon'),
                        'Triggered By' => __('Triggered By', 'order-daemon'),
                        'Order Total' => __('Order Total', 'order-daemon'),
                    ];

                    return $textMap[$text] ?? $text;
                } else {
                    // For other domains, we need to handle them differently
                    // This maintains the original functionality while being i18n compliant
                    return $text;
                }
            } catch (\Throwable $e) {
                // If translation fails, return original text
                return $text;
            }
        }

        // Fallback: return original text when WordPress not available
        return $text;
    }

    /**
     * WordPress-compatible pluralization function with defensive checks
     *
     * @param string $single Singular form
     * @param string $plural Plural form  
     * @param int $count Count to determine singular/plural
     * @param string $domain The text domain
     * @return string Appropriate singular/plural form
     */
    protected function pluralize(string $single, string $plural, int $count, string $domain = 'order-daemon'): string
    {
        // Check if WordPress pluralization function is available
        if (function_exists('_n')) {
            try {
                // Use string literals for WordPress i18n compliance
                if ($domain === 'order-daemon') {
                    // Map common plural strings to string literals
                    $pluralMap = [
                        'item' => ['single' => __('item', 'order-daemon'), 'plural' => __('items', 'order-daemon')],
                        'order' => ['single' => __('order', 'order-daemon'), 'plural' => __('orders', 'order-daemon')],
                        'payment' => ['single' => __('payment', 'order-daemon'), 'plural' => __('payments', 'order-daemon')],
                        'rule' => ['single' => __('rule', 'order-daemon'), 'plural' => __('rules', 'order-daemon')],
                        'event' => ['single' => __('event', 'order-daemon'), 'plural' => __('events', 'order-daemon')],
                    ];

                    // Check if we have a predefined plural mapping
                    foreach ($pluralMap as $key => $forms) {
                        if ($single === $key && $plural === $key . 's') {
                            return $count === 1 ? $forms['single'] : $forms['plural'];
                        }
                    }

                    // Fallback to simple logic for non-mapped strings
                    return $count === 1 ? $single : $plural;
                } else {
                    // For other domains, we need to handle them differently
                    // This maintains the original functionality while being i18n compliant
                    return $count === 1 ? $single : $plural;
                }
            } catch (\Throwable $e) {
                // Fallback to simple logic
                return $count === 1 ? $single : $plural;
            }
        }

        // Fallback: simple pluralization logic
        return $count === 1 ? $single : $plural;
    }

    /**
     * WordPress-compatible HTML escaping with defensive checks
     *
     * @param string $text Text to escape
     * @return string Escaped text
     */
    protected function escapeHtml(string $text): string
    {
        // Check if WordPress escaping function is available
        if (function_exists('esc_html')) {
            try {
                return esc_html($text);
            } catch (\Throwable $e) {
                // Fallback to PHP's htmlspecialchars
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        
        // Fallback: use PHP's built-in escaping
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * WordPress-compatible attribute escaping with defensive checks
     *
     * @param string $text Text to escape for attributes
     * @return string Escaped text
     */
    protected function escapeAttr(string $text): string
    {
        // Check if WordPress escaping function is available
        if (function_exists('esc_attr')) {
            try {
                return esc_attr($text);
            } catch (\Throwable $e) {
                // Fallback to PHP's htmlspecialchars
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }

        // Fallback: use PHP's built-in escaping
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool True if debug mode is enabled
     */
    protected function isDebugMode(): bool
    {
        return defined('ODCM_DEBUG') && ODCM_DEBUG;
    }

    /**
     * Generate user-friendly title for universal_event_processing_debug events
     *
     * @param array $payload The event payload
     * @return string User-friendly title
     */
    public static function generateDebugEventTitle(array $payload): string
    {
        // Check if this is a debug event with underlying event data
        $eventType = $payload['event_type'] ?? '';
        $data = $payload['data'] ?? [];

        if ($eventType === 'universal_event_processing_debug') {
            // Check the underlying event type
            $underlyingEventType = $data['event_type'] ?? '';

            if ($underlyingEventType === 'order_check_scheduled') {
                // Simple, clean title without amount information
                return 'Scheduled Check: No rules triggered';
            }

            // Handle other underlying event types
            switch ($underlyingEventType) {
                case 'payment_completed':
                    return 'Payment Processing: No matching rules';
                case 'checkout_processed':
                    return 'Checkout Processing: No matching rules';
                case 'order_created':
                    return 'Order Creation: No matching rules';
                default:
                    // Generic fallback for other event types
                    if (!empty($underlyingEventType)) {
                        return 'Event Processing: ' . ucfirst(str_replace('_', ' ', $underlyingEventType));
                    }
                    return 'Event Processing Debug';
            }
        }

        // Handle _universal_event_debug
        if ($eventType === '_universal_event_debug') {
            $capturedType = $data['eventType'] ?? 'event';
            return sprintf('Captured %s', $capturedType);
        }

        // Fallback for non-debug events
        return ucwords(str_replace('_', ' ', $eventType));
    }

    /**
     * Check if this is a rule trace event
     *
     * @param array $payload The event payload
     * @param string $eventType The event type
     * @return bool True if this is a rule trace event
     */
    public static function isRuleTrace(array $payload, string $eventType): bool
    {
        $summary = $payload['summary'] ?? '';
        $ruleKeywords = ['rule', 'condition', 'evaluation', 'match', 'decision'];
        $text = strtolower($summary . ' ' . $eventType);

        foreach ($ruleKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }

        return strpos($eventType, 'rule_evaluation') !== false ||
               strpos($summary, 'rule evaluated') !== false;
    }

    /**
     * Generate unified event summary for both log stream and timeline
     *
     * **SINGLE SOURCE OF TRUTH FOR EVENT SUMMARIES**
     *
     * This method generates the canonical event summary/title that is displayed in:
     * - Log Stream list view (via AuditLogEndpoint::extractConsistentEventData)
     * - Timeline detail view (via RegistryTimelineRenderer::renderPrimaryInfo)
     *
     * Any changes to how event summaries are generated should be made HERE to ensure
     * both views display EXACTLY the same title for the same event.
     *
     * Priority order for summary generation:
     * 1. Debug events (universal_event_processing_debug) - use simplified titles
     * 2. Rule traces - apply consistent prefixing
     * 3. Rule execution events - use adapter to extract event_description
     * 4. Default - use original summary from payload/log entry, or format event type
     *
     * @param array $payload The event payload (from JSON payload column)
     * @param array $logEntry The log entry context (from audit_log row)
     * @return string Unified event summary string
     */
    public static function generateUnifiedEventSummary(array $payload, array $logEntry): string
    {
        $eventType = $payload['event_type'] ?? $logEntry['event_type'] ?? 'unknown';
        $originalSummary = $payload['summary'] ?? $logEntry['summary'] ?? '';

        // Case 1: Debug events - use simplified titles in both views
        if ($eventType === 'universal_event_processing_debug' || $eventType === '_universal_event_debug') {
            return self::generateDebugEventTitle($payload);
        }

        // Case 2: Rule traces - apply consistent prefixing
        if (self::isRuleTrace($payload, $eventType)) {
            if ($originalSummary === $eventType) {
                return 'Rule Evaluation: ' . ucfirst(str_replace('_', ' ', $eventType));
            }
            if (!empty($originalSummary)) {
                return $originalSummary;
            }
        }

        // Case 3: Rule execution events - use adapter to extract proper summary
        if ($eventType === 'rule_execution' || strpos($eventType, 'rule_execution') === 0) {
            try {
                $adapter = AdapterRegistry::getAdapterForEvent($payload);
                $displayData = $adapter->extractDisplayData($payload);
                $adapterSummary = $displayData['display_sections']['event_description']['value'] ?? '';
                if (!empty($adapterSummary)) {
                    return $adapterSummary;
                }
            } catch (\Throwable $e) {
                // Fallback to original summary or default
            }
        }

        // Case 4: Default - use original summary or format from event type
        if (!empty($originalSummary)) {
            return $originalSummary;
        }

        return ucfirst(str_replace('_', ' ', $eventType));
    }

    /**
     * Extract primary status for unified use (both log stream and timeline)
     *
     * **SINGLE SOURCE OF TRUTH FOR STATUS PILLS**
     *
     * This method generates the canonical status pill data that is displayed in:
     * - Log Stream list view (via AuditLogEndpoint::extractConsistentEventData)
     * - Timeline detail view (via RegistryTimelineRenderer::renderPrimaryInfo)
     *
     * Any changes to how status pills are generated should be made HERE to ensure
     * both views display EXACTLY the same status pill for the same event.
     *
     * Key behaviors:
     * - For status change events: Shows only the NEW status (not "from → to")
     * - For other events: Extracts status from display sections or raw payload
     * - Maps status values to appropriate pill CSS classes (success, error, warning, etc.)
     *
     * @param array $payload The event payload (from JSON payload column)
     * @param array $logEntry The log entry context (from audit_log row)
     * @return array|null Array with 'label' (display text) and 'type' (CSS class), or null if no status
     */
    public static function extractPrimaryStatusForUnifiedUse(array $payload, array $logEntry): ?array
    {
        $mergedData = array_merge($payload, $logEntry);
        $eventType = $mergedData['event_type'] ?? 'unknown';

        // Special handling for status change events - extract ONLY the resulting status
        $currentStatus = $mergedData['data']['to'] ??
                       $mergedData['rawData']['to_status'] ??
                       $mergedData['to_status'] ??
                       null;

        // If this is a status change event and we found the new status, use it
        if ($currentStatus && self::isStatusChangeEvent($eventType)) {
            $pillType = self::mapStatusToPillType($eventType, $currentStatus);
            return [
                'label' => $currentStatus,  // Only show the NEW status
                'type' => $pillType
            ];
        }

        // For non-status-change events
        $displayData = [];
        if (isset($payload['display_sections'])) {
            $displayData = ['display_sections' => $payload['display_sections']];
        }

        $statusData = self::extractPrimaryStatus($displayData, $mergedData);

        // Ensure we always have a status for log stream compatibility
        if (!$statusData && isset($logEntry['status'])) {
            $statusData = [
                'label' => $logEntry['status'],
                'type' => self::mapStatusToPillType($eventType, $logEntry['status'])
            ];
        }

        return $statusData;
    }

    /**
     * Check if this is a status change event
     *
     * @param string $eventType The event type
     * @return bool True if this is a status change event
     */
    private static function isStatusChangeEvent(string $eventType): bool
    {
        return strpos($eventType, 'status_changed') !== false ||
               strpos($eventType, 'status_change') !== false ||
               strpos($eventType, 'order_status_changed') !== false;
    }

    /**
     * Generate unified event data (summary + status) for both log stream and timeline
     *
     * **SINGLE SOURCE OF TRUTH FOR BOTH SUMMARIES AND STATUS PILLS**
     *
     * This is the primary entry point that should be called by both:
     * - Log Stream (AuditLogEndpoint::extractConsistentEventData)
     * - Timeline (RegistryTimelineRenderer::renderPrimaryInfo)
     *
     * It combines generateUnifiedEventSummary() and extractPrimaryStatusForUnifiedUse()
     * to return a complete, consistent data structure for displaying any event.
     *
     * @param array $payload The event payload (from JSON payload column)
     * @param array $logEntry The log entry context (from audit_log row)
     * @return array {
     *     @type string $summary The event title/summary for display
     *     @type array|null $status {
     *         @type string $label The status text to display in the pill
     *         @type string $type The CSS class type (success, error, warning, info, etc.)
     *     }
     * }
     */
    public static function generateUnifiedEventData(array $payload, array $logEntry): array
    {
        $summary = self::generateUnifiedEventSummary($payload, $logEntry);
        $status = self::extractPrimaryStatusForUnifiedUse($payload, $logEntry);

        return [
            'summary' => $summary,
            'status' => $status
        ];
    }


    /**
     * Format currency with amount and currency combined
     *
     * @param mixed $amount The currency amount
     * @param string $currency The currency code
     * @return string Formatted currency string
     */
    protected function formatCleanCurrency($amount, string $currency): string
    {
        if (is_numeric($amount)) {
            return number_format((float)$amount, 2, '.', '') . ' ' . strtoupper($currency);
        }
        return (string)$amount;
    }

    /**
     * Format customer reference with name and ID
     *
     * @param string $customerId The customer ID
     * @param string|null $firstName The customer first name
     * @param string|null $lastName The customer last name
     * @param string|null $email The customer email
     * @return string Formatted customer reference
     */
    protected function formatCleanCustomerReference($customerId, ?string $firstName, ?string $lastName, ?string $email): string
    {
        $nameParts = [];
        if ($firstName) $nameParts[] = $firstName;
        if ($lastName) $nameParts[] = $lastName;

        if (!empty($nameParts)) {
            $name = implode(' ', $nameParts);
            return sprintf('%s (ID: %s)', $name, $customerId);
        }

        if ($email) {
            return sprintf('%s (ID: %s)', $email, $customerId);
        }

        return sprintf('Customer ID: %s', $customerId);
    }

    /**
     * Format status change as "from → to"
     *
     * @param string $fromStatus The from status
     * @param string $toStatus The to status
     * @return string Formatted status change
     */
    protected function formatStatusChange(string $fromStatus, string $toStatus): string
    {
        return sprintf('%s → %s', ucfirst($fromStatus), ucfirst($toStatus));
    }

    /**
     * Generate status pill HTML for timeline component headers
     *
     * @param string $label Display text for the status pill
     * @param string $status_type Status type for CSS theming
     * @return string HTML status pill element
     */
    public static function renderStatusPill(string $label, string $status_type): string
    {
        // Map semantic types to existing pill variants
        $pill_variant_map = [
            'error' => 'error',
            'warning' => 'warning',
            'success' => 'success',
            'info' => 'info',
            'completed' => 'completed',
            'pending' => 'pending',
            'skipped' => 'skipped',
            'debug' => 'debug'
        ];

        // Get the appropriate pill variant, default to 'info' for unknown types
        $pill_class = $pill_variant_map[strtolower($status_type)] ?? 'info';

        return '<span class="odcm-status-pill odcm-status-pill--' . esc_attr($pill_class) . '">' .
               esc_html($label) . '</span>';
    }

    /**
     * Map status value to appropriate status pill type
     *
     * @param string $eventType The event type
     * @param string $statusValue The status value
     * @return string Status pill type
     */
    public static function mapStatusToPillType(string $eventType, string $statusValue): string
    {
        $statusMap = [
            // Order statuses
            'pending' => 'info',
            'processing' => 'info',
            'on-hold' => 'warning',
            'completed' => 'success',
            'cancelled' => 'warning',
            'refunded' => 'info',
            'failed' => 'error',

            // Payment statuses
            'paid' => 'success',
            'completed' => 'success',
            'failed' => 'error',
            'pending' => 'info',
            'processing' => 'info',
            'refunded' => 'info',
            'cancelled' => 'warning',

            // Rule execution statuses
            'success' => 'success',
            'failed' => 'error',
            'skipped' => 'info',
            'executed' => 'success',

            // Generic statuses
            'error' => 'error',
            'warning' => 'warning',
            'info' => 'info',
            'debug' => 'debug'
        ];

        // Special handling for debug events - use debug status pill
        if (in_array($eventType, ['rule_evaluation_non_canonical', 'debug', '_universal_event_debug', 'universal_event_processing_debug'])) {
            return 'debug';
        }

        return $statusMap[strtolower($statusValue)] ?? 'info';
    }

    /**
     * Extract primary status from display data for status pill
     *
     * @param array $displayData The display data from adapter
     * @param array $rawPayload The original event payload
     * @return array|null Array with 'label' and 'type' for status pill, or null if no status
     */
    public static function extractPrimaryStatus(array $displayData, array $rawPayload): ?array
    {
        $eventType = $rawPayload['event_type'] ?? 'unknown';

        // Special handling for status change events - extract only the resulting status
        $currentStatus = null;
        if (strpos($eventType, 'status_changed') !== false ||
            strpos($eventType, 'status_change') !== false ||
            strpos($eventType, 'order_status_changed') !== false) {

            // Try to get the current status directly from payload fields
            $currentStatus = $rawPayload['data']['to'] ??
                           $rawPayload['rawData']['to_status'] ??
                           $rawPayload['to_status'] ??
                           null;
        }

        // If we found a current status for status change events, use it
        if ($currentStatus) {
            // Map status to pill type based on event type
            $pillType = self::mapStatusToPillType($eventType, $currentStatus);

            return [
                'label' => $currentStatus,
                'type' => $pillType
            ];
        }

        // Try to extract status from display sections first (for all events)
        $statusFields = ['status', 'order_status', 'payment_status', 'execution_status', 'status_change'];

        foreach ($statusFields as $field) {
            if (isset($displayData['display_sections'][$field])) {
                $statusValue = $displayData['display_sections'][$field]['value'] ?? '';

                // Map status to pill type based on event type
                $pillType = self::mapStatusToPillType($eventType, $statusValue);

                return [
                    'label' => $statusValue,
                    'type' => $pillType
                ];
            }
        }

        // Fallback: try to extract from raw payload
        if (isset($rawPayload['status'])) {
            $pillType = self::mapStatusToPillType($eventType, $rawPayload['status']);
            return [
                'label' => $rawPayload['status'],
                'type' => $pillType
            ];
        }

        // Force debug status for debug events if no explicit status found
        if (in_array($eventType, ['universal_event_processing_debug', 'debug', 'rule_evaluation_non_canonical'])) {
            return [
                'label' => 'Debug',
                'type' => 'debug'
            ];
        }

        return null;
    }

    /**
     * Get event type configuration
     *
     * @param string $event_type The event type
     * @return array Event configuration
     */
    public static function getEventTypeConfig(string $event_type): array
    {
        $event_configs = [
            // Order events
            'order_created' => [
                'dashicon' => 'dashicons-plus-alt',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'pending',
                'priority' => 4,
                'category' => 'Order Lifecycle'
            ],
            'order_updated' => [
                'dashicon' => 'dashicons-update',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'updated',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_completed' => [
                'dashicon' => 'dashicons-yes',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'completed',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_cancelled' => [
                'dashicon' => 'dashicons-no',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'cancelled',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_refunded' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refunded',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_processing' => [
                'dashicon' => 'dashicons-update',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'processing',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_on_hold' => [
                'dashicon' => 'dashicons-pause',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'on hold',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_pending' => [
                'dashicon' => 'dashicons-cart',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'pending',
                'priority' => 4,
                'category' => 'Order Lifecycle'
            ],
            'order_failed' => [
                'dashicon' => 'dashicons-warning',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'failed',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'status_changed' => [
                'dashicon' => 'dashicons-migrate',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => '→ completed',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            // Payment events
            'checkout_processed' => [
                'dashicon' => 'dashicons-cart',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'purple-700',
                'status_display' => 'checkout-draft',
                'priority' => 3,
                'category' => 'Payment'
            ],
            'payment_completed' => [
                'dashicon' => 'dashicons-money-alt',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'completed',
                'priority' => 3,
                'category' => 'Payment'
            ],
            'payment_failed' => [
                'dashicon' => 'dashicons-warning',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'red-700',
                'status_display' => 'failed',
                'priority' => 3,
                'category' => 'Payment'
            ],
            // Rule execution events
            'rule_execution' => [
                'dashicon' => 'dashicons-yes-alt',
                'theme_class' => 'odcm-component--rule', // Default to rule styling
                'primary_color' => 'blue-700',
                'status_display' => 'success',
                'priority' => 2,
                'category' => 'Rule'
            ],
            'rule_evaluation_non_canonical' => [
                'dashicon' => 'dashicons-controls-play',
                'theme_class' => 'odcm-component--debug',
                'primary_color' => 'yellow-700',
                'status_display' => 'debug',
                'priority' => 1,
                'category' => 'Debug'
            ],
            // System events
            'admin_action' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'admin',
                'priority' => 1,
                'category' => 'System'
            ],
            'process_started' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'started',
                'priority' => 1,
                'category' => 'System'
            ],
            'info' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'info',
                'priority' => 1,
                'category' => 'System'
            ],
            'metrics' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'metrics',
                'priority' => 1,
                'category' => 'System'
            ],
            'system_info' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'system',
                'priority' => 1,
                'category' => 'System'
            ],
            // Refund events
            'refund_created' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refunded',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'refund_deleted' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refund deleted',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'refund_analysis' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refund analysis',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            // Subscription events
            'subscription_created' => [
                'dashicon' => 'dashicons-calendar',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'recurring',
                'priority' => 2,
                'category' => 'System'
            ],
            // Webhook events
            'webhook_received' => [
                'dashicon' => 'dashicons-networking',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'webhook',
                'priority' => 1,
                'category' => 'System'
            ],
            // Condition events
            'condition_passed' => [
                'dashicon' => 'dashicons-yes-alt',
                'theme_class' => 'odcm-component--rule',
                'primary_color' => 'blue-700',
                'status_display' => 'passed',
                'priority' => 2,
                'category' => 'Rule'
            ],
            'condition_failed' => [
                'dashicon' => 'dashicons-no-alt',
                'theme_class' => 'odcm-component--rule',
                'primary_color' => 'blue-700',
                'status_display' => 'failed',
                'priority' => 2,
                'category' => 'Rule'
            ],
            // Error/Debug events
            'fallback' => [
                'dashicon' => 'dashicons-warning',
                'theme_class' => 'odcm-component--error',
                'primary_color' => 'red-700',
                'status_display' => 'error',
                'priority' => 1,
                'category' => 'System'
            ],
            'debug' => [
                'dashicon' => 'dashicons-info',
                'theme_class' => 'odcm-component--debug',
                'primary_color' => 'yellow-700',
                'status_display' => 'debug',
                'priority' => 1,
                'category' => 'System'
            ],
            // Universal events
            'universal_event_processing' => [
                'dashicon' => 'dashicons-admin-generic',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'processed',
                'priority' => 1,
                'category' => 'System'
            ],
            'universal_event_processing_debug' => [
                'dashicon' => 'dashicons-info',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'monitored',
                'priority' => 2,
                'category' => 'System'
            ],
            '_universal_event_debug' => [
                'dashicon' => 'dashicons-search',
                'theme_class' => 'odcm-component--debug',
                'primary_color' => 'grey-700',
                'status_display' => 'debug',
                'priority' => 1,
                'category' => 'Debug'
            ],
            'universal_event_duplicate' => [
                'dashicon' => 'dashicons-admin-generic',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'duplicate',
                'priority' => 1,
                'category' => 'System'
            ]
        ];

        // Check for exact match
        if (isset($event_configs[$event_type])) {
            return $event_configs[$event_type];
        }

        // Check for patterns
        if (strpos($event_type, 'payment.stripe.') === 0) {
            return [
                'dashicon' => 'dashicons-cloud-saved',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'payment',
                'priority' => 3,
                'category' => 'Payment'
            ];
        }

        if (strpos($event_type, 'payment.paypal.') === 0) {
            return [
                'dashicon' => 'dashicons-cloud-saved',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'payment',
                'priority' => 3,
                'category' => 'Payment'
            ];
        }

        if (strpos($event_type, 'payment.') === 0) {
            return [
                'dashicon' => 'dashicons-cloud-saved',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'payment',
                'priority' => 3,
                'category' => 'Payment'
            ];
        }

        if (strpos($event_type, 'subscription_') === 0) {
            return [
                'dashicon' => 'dashicons-calendar-alt',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'recurring',
                'priority' => 2,
                'category' => 'System'
            ];
        }

        if (strpos($event_type, 'webhook_') === 0) {
            return [
                'dashicon' => 'dashicons-networking',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'webhook',
                'priority' => 1,
                'category' => 'System'
            ];
        }

        // Default fallback
        return [
            'dashicon' => 'dashicons-admin-generic',
            'theme_class' => 'odcm-component--system',
            'primary_color' => 'grey-700',
            'status_display' => 'event',
            'priority' => 1,
            'category' => 'System'
        ];
    }
}
