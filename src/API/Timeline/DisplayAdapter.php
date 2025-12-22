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
        return $this->organizeIntoSections($standardFields, $specializedFields, $additionalFields);
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

        // Extract timestamp
        $fields['timestamp'] = $payload['timestamp'] ?? gmdate('Y-m-d H:i:s');

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
    abstract protected function extractSpecializedFields(array $payload): array;

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
            $currentPath = empty($prefix) ? $key : $prefix . '.' . $key;

            // Skip if this is a complex object that should be handled by specialized methods
            if (is_array($value) && $this->isComplexObject($value)) {
                continue;
            }

            // Check if this key matches any interesting pattern
            $matchesPattern = false;
            foreach ($patterns as $pattern) {
                if (strpos($key, $pattern) !== false) {
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
     * Organize extracted fields into display sections
     *
     * @param array $standardFields Standard fields
     * @param array $specializedFields Specialized fields
     * @param array $additionalFields Additional fields
     * @return array Organized display sections
     */
    protected function organizeIntoSections(array $standardFields, array $specializedFields, array $additionalFields): array
    {
        $display_sections = [];
        $detail_sections = [];
        $tech_data = [];

        // Add standard fields to display sections
        foreach ($standardFields as $key => $value) {
            $label = $this->formatFieldLabel($key);
            $display_sections[$key] = [
                'label' => $label,
                'value' => $value
            ];
        }

        // Add specialized fields to appropriate sections
        foreach ($specializedFields as $key => $config) {
            if (isset($config['section'])) {
                $section = $config['section'];
                $label = $config['label'];
                $value = $config['value'];

                if ($section === 'main') {
                    $display_sections[$key] = [
                        'label' => $label,
                        'value' => $value
                    ];
                } else {
                    if (!isset($detail_sections[$section])) {
                        $detail_sections[$section] = [
                            'label' => $this->formatSectionLabel($section),
                            'data' => []
                        ];
                    }
                    $detail_sections[$section]['data'][$key] = [
                        'label' => $label,
                        'value' => $value
                    ];
                }
            }
        }

        // Add additional fields to detail sections
        if (!empty($additionalFields)) {
            $detail_sections['additional_details'] = [
                'label' => 'Additional Details',
                'data' => []
            ];

            foreach ($additionalFields as $key => $value) {
                $label = $this->formatFieldLabel($key);
                $detail_sections['additional_details']['data'][$key] = [
                    'label' => $label,
                    'value' => $value
                ];
            }
        }

        return [
            'display_sections' => $display_sections,
            'detail_sections' => $detail_sections,
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
        // Replace underscores and dots with spaces
        $label = str_replace(['_', '.'], ' ', $key);

        // Capitalize words
        $label = ucwords($label);

        return $label;
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
}
