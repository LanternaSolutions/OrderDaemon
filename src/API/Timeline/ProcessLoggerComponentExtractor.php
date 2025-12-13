<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Component extractor for ProcessLogger format payloads
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
final class ProcessLoggerComponentExtractor implements ComponentExtractorInterface
{
    /**
     * Extract normalized components from raw payload data
     */
    public function extractComponents(array $rawPayload, bool $includeDebug): array
    {
        // Enhanced error reporting for order events
        if (isset($rawPayload['event_type'])) {
            $event_type = $rawPayload['event_type'];
            
            // Special case logging for order completion events
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                if (strpos($event_type, 'checkout') !== false || 
                    strpos($event_type, 'order_') !== false || 
                    strpos($event_type, 'complete') !== false ||
                    strpos($event_type, 'completion') !== false) {
                    error_log('ODCM DEBUG: ProcessLoggerComponentExtractor: Processing order completion event type: ' . $event_type);
                    error_log('ODCM DEBUG: ProcessLoggerComponentExtractor: Raw payload keys: ' . implode(', ', array_keys($rawPayload)));
                }
            }
        }
        
        // Check if this is ProcessLogger format
        if ($this->isProcessLoggerFormat($rawPayload)) {
            return $this->extractProcessLoggerComponents($rawPayload, $includeDebug);
        }
        
        // For non-ProcessLogger format, we'll handle this in the synthetic component creation
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: ProcessLoggerComponentExtractor: Not a ProcessLogger format payload, will use synthetic component');
        }
        return [];
    }
    
    /**
     * Check if payload contains ProcessLogger format data
     */
    public function isProcessLoggerFormat(array $rawPayload): bool
    {
        $is_process_logger = isset($rawPayload['components']) && 
                             is_array($rawPayload['components']) && 
                             !empty($rawPayload['components']);
        
        // Enhanced debugging for format detection
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            // Only debug for order-related events to avoid log spam
            $is_order_related = false;
            if (isset($rawPayload['event_type'])) {
                $event_type = $rawPayload['event_type'];
                $is_order_related = 
                    strpos($event_type, 'checkout') !== false || 
                    strpos($event_type, 'order_') !== false || 
                    strpos($event_type, 'complete') !== false ||
                    strpos($event_type, 'completion') !== false;
            }
            
            if ($is_order_related) {
                error_log('ODCM DEBUG: isProcessLoggerFormat check for order event: ' . ($is_process_logger ? 'YES' : 'NO'));
                if (!$is_process_logger) {
                    error_log('ODCM DEBUG: Missing components key: ' . (!isset($rawPayload['components']) ? 'YES' : 'NO'));
                    error_log('ODCM DEBUG: Components not array: ' . (isset($rawPayload['components']) && !is_array($rawPayload['components']) ? 'YES' : 'NO'));
                    error_log('ODCM DEBUG: Empty components: ' . (isset($rawPayload['components']) && is_array($rawPayload['components']) && empty($rawPayload['components']) ? 'YES' : 'NO'));
                } 
            }
        }
        
        return $is_process_logger;
    }
    
    /**
     * Create synthetic component from legacy or empty payload
     * Creates renderer-compatible data structure using event_type directly
     * 
     * Fixed to ensure consistent structure with 'data' field as expected by TimelineData
     */
    public function createSyntheticComponent(array $logEntry): array
    {
        $summary = $logEntry['summary'] ?? 'Log Entry';
        $timestamp = $logEntry['timestamp'] ?? current_time('mysql');
        $status = $logEntry['status'] ?? 'info';
        $eventType = $logEntry['event_type'] ?? 'info';
        $source = $logEntry['source'] ?? 'system';
        
        // Build renderer-compatible data structure
        $data = [
            'summary' => $summary,
            'event_type' => $eventType,
            'source' => $source,
            'log_id' => $logEntry['log_id'] ?? $logEntry['id'] ?? 0,
            'status' => $status,
        ];
        
        // Add order context in renderer-expected format
        if (!empty($logEntry['order_id'])) {
            $data['order_id'] = (int) $logEntry['order_id'];
            
            // Add additional WooCommerce-compatible keys for renderer detection
            if ($this->isWooCommerceRelated($summary, $eventType)) {
                $data['order'] = ['id' => (int) $logEntry['order_id']];
                $data['woocommerce'] = true;
            }
        }
        
        // Detect Rule Traces: Prevent confusion with main events
        // e.g., 'rule_evaluation_non_canonical' masquerading as 'checkout_processed'
        $is_rule_trace = $this->isRuleRelated($summary, $eventType) || 
                         strpos($eventType, 'rule_evaluation') !== false ||
                         strpos($summary, 'rule evaluated') !== false;

        if ($is_rule_trace) {
            $data['rule_evaluation'] = true;
            $data['is_trace'] = true;
            
            // For technical traces, override the summary/label if it's identical to a main event type
            // to prevent "Checkout Completed" appearing twice
            if ($summary === $eventType || $summary === 'Checkout Completed') {
                $summary = 'Rule Trace: ' . ucfirst(str_replace('_', ' ', $eventType));
                $data['summary'] = $summary;
            }

            if (strpos($summary, 'rule') !== false) {
                $data['rule_id'] = $this->extractRuleIdFromSummary($summary);
            }
        }
        
        // Add error context for error renderers
        if ($status === 'error' || strpos($summary, 'error') !== false) {
            $data['error'] = $summary;
            $data['error_type'] = 'system_error';
        }
        
        // Add payment gateway context for gateway renderers
        if (strpos($summary, 'stripe') !== false) {
            $data['stripe_event'] = true;
            $data['gateway'] = 'stripe';
        } elseif (strpos($summary, 'paypal') !== false) {
            $data['paypal_event'] = true;
            $data['gateway'] = 'paypal';
        }
        
        // Merge payload data intelligently
        // This ensures debug events with data in payload but no components (like fallback debug events)
        // still show their data in the renderer
        $payloadRaw = $logEntry['payload'] ?? '';
        if (!empty($payloadRaw)) {
            $payloadData = json_decode($payloadRaw, true);
            if (is_array($payloadData) && !$this->isProcessLoggerFormat($payloadData)) {
                // Merge payload data into the main data array so keys like 'amount', 'currency', etc.
                // are available at the top level for renderers
                $data = array_merge($payloadData, $data);
                $data['legacy_payload'] = $payloadData; // Keep reference to original structure
            }
        }
        
        // Final label adjustment for traces
        $label = $summary;
        if ($is_rule_trace && strpos(strtolower($label), 'trace') === false && strpos(strtolower($label), 'rule') === false) {
             // If we detected it's a trace but the label looks like a main event, fix it
             $label = 'Rule Evaluation: ' . $label;
        }

        // Debug log the component structure
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: createSyntheticComponent - Creating component for event type: ' . $eventType);
            error_log('ODCM DEBUG: createSyntheticComponent - Component will have data keys: ' . implode(', ', array_keys($data)));
        }
        
        // Return properly structured component with 'component_id' for identification
        // and properly nested 'data' field as expected by TimelineData
        return [
            'component_id' => 'synthetic_' . ($logEntry['log_id'] ?? $logEntry['id'] ?? uniqid()),
            'event_type' => $eventType,
            'label' => $label, // Use adjusted label
            'ts' => $timestamp,
            'level' => $status,
            'data' => $data  // Ensure data is properly structured as an array
        ];
    }
    
    /**
     * Check if event is WooCommerce-related
     */
    private function isWooCommerceRelated(string $summary, string $eventType): bool
    {
        $wooKeywords = ['order', 'product', 'customer', 'woocommerce', 'status', 'payment', 'shipping'];
        $text = strtolower($summary . ' ' . $eventType);
        
        foreach ($wooKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if event is rule-related
     */
    private function isRuleRelated(string $summary, string $eventType): bool
    {
        $ruleKeywords = ['rule', 'condition', 'evaluation', 'match', 'decision'];
        $text = strtolower($summary . ' ' . $eventType);
        
        foreach ($ruleKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract rule ID from summary text
     */
    private function extractRuleIdFromSummary(string $summary): ?int
    {
        if (preg_match('/rule\s+#?(\d+)/i', $summary, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }
    
    /**
     * Extract components from ProcessLogger format payload
     */
    private function extractProcessLoggerComponents(array $rawPayload, bool $includeDebug): array
    {
        $components = $rawPayload['components'];
        $extractedComponents = [];
        
        // Enhanced debugging for component extraction
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $is_order_related = false;
            if (isset($rawPayload['event_type'])) {
                $event_type = $rawPayload['event_type'];
                $is_order_related = 
                    strpos($event_type, 'checkout') !== false || 
                    strpos($event_type, 'order_') !== false || 
                    strpos($event_type, 'complete') !== false ||
                    strpos($event_type, 'completion') !== false;
            }
            
            if ($is_order_related) {
                error_log('ODCM DEBUG: extractProcessLoggerComponents processing ' . count($components) . ' components for order event');
            }
        }

        foreach ($components as $idx => $component) {
            // Enhanced error reporting for component structure issues
            if (!is_array($component)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: Component #' . $idx . ' is not an array, skipping. Type: ' . gettype($component));
                }
                continue;
            }

            // Apply debug filtering
            if (!$includeDebug && $this->isDebugComponent($component)) {
                continue;
            }

            // Validate component structure with detailed logging
            if (!$this->isValidComponent($component)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: Component #' . $idx . ' has invalid structure');
                    error_log('ODCM DEBUG: - Missing event_type: ' . (!isset($component['event_type']) ? 'YES' : 'NO'));
                    error_log('ODCM DEBUG: - Missing data: ' . (!isset($component['data']) ? 'YES' : 'NO'));
                    error_log('ODCM DEBUG: - Data not array: ' . (isset($component['data']) && !is_array($component['data']) ? 'YES' : 'NO'));
                    error_log('ODCM DEBUG: - Data empty: ' . (isset($component['data']) && is_array($component['data']) && empty($component['data']) ? 'YES' : 'NO'));
                    
                    // For order-related events, show the actual data we received
                    $is_order_related = false;
                    if (isset($component['event_type'])) {
                        $event_type = $component['event_type'];
                        $is_order_related = 
                            strpos($event_type, 'checkout') !== false || 
                            strpos($event_type, 'order_') !== false || 
                            strpos($event_type, 'complete') !== false ||
                            strpos($event_type, 'completion') !== false;
                    }
                    
                    if ($is_order_related) {
                        error_log('ODCM DEBUG: Order component that failed validation: ' . json_encode($component));
                    }
                }
                continue;
            }

            // Normalize component structure, passing the full raw payload to allow merging of top-level data
            $normalizedComponent = $this->normalizeComponent($component, $rawPayload);
            $extractedComponents[] = $normalizedComponent;
        }
        
        // Log if we didn't extract any components for order events
        if (defined('ODCM_DEBUG') && ODCM_DEBUG && empty($extractedComponents)) {
            $is_order_related = false;
            if (isset($rawPayload['event_type'])) {
                $event_type = $rawPayload['event_type'];
                $is_order_related = 
                    strpos($event_type, 'checkout') !== false || 
                    strpos($event_type, 'order_') !== false || 
                    strpos($event_type, 'complete') !== false ||
                    strpos($event_type, 'completion') !== false;
            }
            
            if ($is_order_related) {
                error_log('ODCM DEBUG: Failed to extract any components for order event. Original component count: ' . count($components));
            }
        }

        return $extractedComponents;
    }
    
    /**
     * Check if component is debug-level
     */
    private function isDebugComponent(array $component): bool
    {
        $level = $component['level'] ?? '';
        $event_type = $component['event_type'] ?? '';
        
        return ($level === 'debug') || ($event_type === 'process_started');
    }
    
    /**
     * Validate component has required structure
     */
    private function isValidComponent(array $component): bool
    {
        // Special case for order completion events - use more lenient validation
        if (isset($component['event_type']) && 
            (strpos($component['event_type'], 'order_') !== false ||
             strpos($component['event_type'], 'checkout') !== false ||
             strpos($component['event_type'], 'completion') !== false ||
             strpos($component['event_type'], 'order_complete') !== false ||
             strpos($component['event_type'], 'checkout_processed') !== false ||
             strpos($component['event_type'], 'order_completed') !== false ||
             strpos($component['event_type'], 'checkout_completed') !== false)) {
            
            // For critical business events, be more lenient about structure
            $has_event_type = isset($component['event_type']);
            $has_data = isset($component['data']);
            
            // Enhanced debugging for order components
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                if (!$has_event_type) {
                    error_log('ODCM DEBUG: Order component validation failed: missing event_type');
                }
                if (!$has_data) {
                    error_log('ODCM DEBUG: Order component validation failed: missing data key');
                    error_log('ODCM DEBUG: Order component keys: ' . implode(', ', array_keys($component)));
                }
            }
            
            // More permissive for order events - will normalize structure later
            return $has_event_type && $has_data;
        }
        
        // Original strict validation for other event types
        return isset($component['event_type']) && 
               isset($component['data']) && 
               is_array($component['data']) &&
               !empty($component['data']);
    }
    
    /**
     * Normalize component structure to ensure consistency and inject top-level data.
     * This ensures a "wide pipeline" with complete context for renderers.
     */
    private function normalizeComponent(array $component, array $rawPayload): array
    {
        $ts = $component['ts'] ?? microtime(true);

        // Handle microsecond timestamps for precise chronological ordering
        if (is_float($ts) || (is_numeric($ts) && strpos((string)$ts, '.') !== false)) {
            // Keep as float for microsecond precision in sorting
            $normalized_ts = (float)$ts;
        } elseif (is_numeric($ts)) {
            // Convert Unix timestamp to float
            $normalized_ts = (float)$ts;
        } else {
            // Handle ISO datetime strings - convert to float timestamp
            $timestamp = strtotime($ts);
            $normalized_ts = $timestamp !== false ? (float)$timestamp : microtime(true);
        }
        
        // Enhanced defensive data handling - ensure data is always an array 
        $data = $component['data'] ?? [];
        if (!is_array($data)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: Component data not array, converting - Type: ' . gettype($data));
            }
            // Convert to array if possible, or create empty array with original as __raw_value
            if (is_object($data)) {
                $data = (array)$data;
            } else {
                $data = ['__raw_value' => $data];
            }
        }
        
        // Base normalized structure with defaults for all required fields
        $normalizedComponent = [
            'event_type' => $component['event_type'],
            'label' => $component['label'] ?? ucfirst($component['event_type']),
            'ts' => $normalized_ts,
            'level' => $component['level'] ?? 'info',
            'data' => $data,
        ];
    
        // Add top-level data access for renderers
        if (isset($rawPayload['rawData'])) {
            $normalizedComponent['rawData'] = $rawPayload['rawData'];
        }
    
        // Handle empty data case (especially for order events)
        if (empty($normalizedComponent['data']) && isset($component['event_type'])) {
            $event_type = $component['event_type'];
            // For order events, ensure minimum structure for renderers
            if (strpos($event_type, 'order_') !== false ||
                strpos($event_type, 'checkout') !== false ||
                strpos($event_type, 'completion') !== false) {
                
                $normalizedComponent['data'] = [
                    'event_type' => $event_type,
                    'summary' => $component['label'] ?? ucfirst($event_type),
                    '__synthetic' => true
                ];
                
                // Add order ID if present in component or raw payload
                if (isset($component['order_id'])) {
                    $normalizedComponent['data']['order_id'] = $component['order_id'];
                } elseif (isset($rawPayload['order_id'])) {
                    $normalizedComponent['data']['order_id'] = $rawPayload['order_id'];
                }
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: Created synthetic data structure for order event: ' . $event_type);
                }
            }
        }
    
        // Ensure order_id is accessible at top-level if present in data
        if (isset($normalizedComponent['data']['order_id']) && !isset($normalizedComponent['order_id'])) {
            $normalizedComponent['order_id'] = $normalizedComponent['data']['order_id'];
        }

        return $normalizedComponent;
    }
    
}
