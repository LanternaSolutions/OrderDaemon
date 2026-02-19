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
    public function extractComponents(array $rawPayload, bool $includeDebug, array $logEntryContext = []): array
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
                    odcm_log_message('ODCM DEBUG: ProcessLoggerComponentExtractor: Processing order completion event', 'debug', [
                        'event_type' => $event_type,
                        'payload_keys' => array_keys($rawPayload)
                    ]);
                }
            }
        }
        
        // Check if this is ProcessLogger format
        if ($this->isProcessLoggerFormat($rawPayload)) {
            return $this->extractProcessLoggerComponents($rawPayload, $includeDebug, $logEntryContext);
        }
        
        // For non-ProcessLogger format, we'll handle this in the synthetic component creation
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message('ODCM DEBUG: ProcessLoggerComponentExtractor: Not a ProcessLogger format payload', 'debug');
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
                $debugData = [
                    'is_process_logger' => $is_process_logger ? 'YES' : 'NO',
                    'missing_components_key' => !isset($rawPayload['components']) ? 'YES' : 'NO',
                    'components_not_array' => isset($rawPayload['components']) && !is_array($rawPayload['components']) ? 'YES' : 'NO',
                    'empty_components' => isset($rawPayload['components']) && is_array($rawPayload['components']) && empty($rawPayload['components']) ? 'YES' : 'NO'
                ];
                odcm_log_message('ODCM DEBUG: isProcessLoggerFormat check for order event', 'debug', $debugData);
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
            odcm_log_message('ODCM DEBUG: createSyntheticComponent - Creating component for event type', 'debug', [
                'event_type' => $eventType,
                'data_keys' => array_keys($data)
            ]);
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
    private function extractProcessLoggerComponents(array $rawPayload, bool $includeDebug, array $logEntryContext = []): array
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
                odcm_log_message('ODCM DEBUG: extractProcessLoggerComponents processing components', 'debug', [
                    'component_count' => count($components)
                ]);
            }
        }

        foreach ($components as $idx => $component) {
            // Enhanced error reporting for component structure issues
            if (!is_array($component)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message('ODCM DEBUG: Component validation failed', 'debug', [
                        'component_index' => $idx,
                        'type' => gettype($component),
                        'reason' => 'not an array'
                    ]);
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
                    $validationData = [
                        'missing_event_type' => !isset($component['event_type']) ? 'YES' : 'NO',
                        'missing_data' => !isset($component['data']) ? 'YES' : 'NO',
                        'data_not_array' => isset($component['data']) && !is_array($component['data']) ? 'YES' : 'NO',
                        'data_empty' => isset($component['data']) && is_array($component['data']) && empty($component['data']) ? 'YES' : 'NO'
                    ];
                    odcm_log_message('ODCM DEBUG: Component validation failed', 'error', [
                        'component_index' => $idx,
                        'validation_data' => $validationData
                    ]);
                    
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
                        odcm_log_message('ODCM DEBUG: Order component that failed validation', 'error', [
                            'component_data' => $component
                        ]);
                    }
                }
                continue;
            }

            // Normalize component structure, passing the full raw payload and log entry context to allow merging of top-level data
            $normalizedComponent = $this->normalizeComponent($component, $rawPayload, $logEntryContext);
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
                odcm_log_message('ODCM DEBUG: Failed to extract any components for order event', 'error', [
                    'original_component_count' => count($components)
                ]);
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

        // Check for rule_no_match events specifically
        if ($event_type === 'rule_no_match') {
            return true;
        }

        // Check for rule_no_match in nested data
        if (isset($component['data']['event_type']) && $component['data']['event_type'] === 'rule_no_match') {
            return true;
        }

        // Check for rule_no_match flags
        if (!empty($component['rule_no_match']) || !empty($component['data']['rule_no_match'])) {
            return true;
        }

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
                    odcm_log_message('ODCM DEBUG: Order component validation failed', 'debug', [
                        'missing_event_type' => 'YES'
                    ]);
                }
                if (!$has_data) {
                    odcm_log_message('ODCM DEBUG: Order component validation failed', 'debug', [
                        'missing_data_key' => 'YES',
                        'component_keys' => array_keys($component)
                    ]);
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
    private function normalizeComponent(array $component, array $rawPayload, array $logEntryContext = []): array
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
                odcm_log_message('ODCM DEBUG: Component data not array, converting', 'debug', [
                    'type' => gettype($data)
                ]);
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
                    odcm_log_message('ODCM DEBUG: Created synthetic data structure for order event', 'debug', [
                        'event_type' => $event_type
                    ]);
                }
            }
        }
    
        // Ensure order_id is accessible at top-level if present in data
        if (isset($normalizedComponent['data']['order_id']) && !isset($normalizedComponent['order_id'])) {
            $normalizedComponent['order_id'] = $normalizedComponent['data']['order_id'];
        }

        // PARENT-CHILD HIERARCHY VISUALIZATION: 
        // Inject parent_id from log entry context into each component
        // This enables the RegistryTimelineRenderer to build the hierarchy map correctly
        if (!empty($logEntryContext['parent_id'])) {
            $normalizedComponent['parent_id'] = $logEntryContext['parent_id'];
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message('ODCM DEBUG: Injected parent_id into component', 'debug', [
                    'parent_id' => $logEntryContext['parent_id'],
                    'component_event_type' => $component['event_type']
                ]);
            }
        }

        // Display/Raw two-layer scaffolding (non-breaking defaults)
        // These fields enable the redesigned renderer to present structured data while maintaining backward compatibility.
        if (!isset($normalizedComponent['display_sections']) || !is_array($normalizedComponent['display_sections'])) {
            $normalizedComponent['display_sections'] = $this->buildBasicDisplaySections($normalizedComponent);
        }
        if (!isset($normalizedComponent['detail_sections']) || !is_array($normalizedComponent['detail_sections'])) {
            $normalizedComponent['detail_sections'] = [];
        }
        if (!isset($normalizedComponent['tech_data']) || !is_array($normalizedComponent['tech_data'])) {
            $normalizedComponent['tech_data'] = [];
        }
        if (!isset($normalizedComponent['actions_taken']) || !is_array($normalizedComponent['actions_taken'])) {
            $normalizedComponent['actions_taken'] = [];
        }

        return $normalizedComponent;
    }

    /**
     * Build a minimal display section set from a normalized component
     * This provides a human-friendly fallback without changing existing rendering.
     */
    private function buildBasicDisplaySections(array $normalizedComponent): array
    {
        $sections = [];

        // Summary section
        $summaryItems = [];
        $label = $normalizedComponent['label'] ?? null;
        if (is_string($label) && $label !== '') {
            $summaryItems[] = ['key' => 'Summary', 'value' => $label];
        }

        $eventType = $normalizedComponent['event_type'] ?? null;
        if (is_string($eventType) && $eventType !== '') {
            $summaryItems[] = ['key' => 'Event', 'value' => $eventType];
        }

        $OrderId = $normalizedComponent['order_id'] ?? ($normalizedComponent['data']['order_id'] ?? null);
        if (!empty($OrderId)) {
            $summaryItems[] = ['key' => 'Order', 'value' => '#' . (string) $OrderId];
        }

        if (!empty($summaryItems)) {
            $sections[] = [
                'title' => 'Summary',
                'items' => $summaryItems,
            ];
        }

        return $sections;
    }
    
}