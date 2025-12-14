<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

use OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry;
use OrderDaemon\CompletionManager\Core\Evaluator;
use OrderDaemon\CompletionManager\Core\ProcessIdManager;

/**
 * Universal Event Processor
 * 
 * Processes universal events through the rule engine. This class serves as
 * the bridge between the webhook system and the existing rule evaluation
 * framework, enabling event-driven automation workflows.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   1.1.0
 */
class UniversalEventProcessor
{
    /**
     * Singleton instance
     * 
     * @var UniversalEventProcessor|null
     */
    private static ?UniversalEventProcessor $instance = null;

    /**
     * Rule component registry
     * 
     * @var RuleComponentRegistry
     */
    private RuleComponentRegistry $registry;

    /**
     * Rule evaluator
     * 
     * @var Evaluator
     */
    private Evaluator $evaluator;

    /**
     * Matched rule data for enhanced logging
     * 
     * @var array|null
     */
    private ?array $matched_rule_data = null;

    /**
     * Universal event context flag to prevent duplicate timeline events
     * 
     * @var bool
     */
    private static bool $universal_event_context = false;

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->registry = new RuleComponentRegistry();
        $this->evaluator = new Evaluator();
    }

    /**
     * Get singleton instance
     * 
     * @return UniversalEventProcessor
     */
    public static function instance(): UniversalEventProcessor
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Process a universal event through the rule engine
     * 
     * @param array $event_data Serialized universal event data
     * @return bool True if event was processed successfully
     */
    public function processEvent(array $event_data): bool
    {
        $start_time = microtime(true);
        
        // DEBUG: Log the incoming event data for process_id assignment troubleshooting
        if (defined('ODCM_DEBUG') && ODCM_DEBUG && function_exists('odcm_log_message')) {
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: UniversalEventProcessor received event data:", 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - primaryObjectType: " . ($event_data['primaryObjectType'] ?? 'MISSING'), 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - primaryObjectID: " . ($event_data['primaryObjectID'] ?? 'MISSING') . " (type: " . gettype($event_data['primaryObjectID'] ?? null) . ")", 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - eventType: " . ($event_data['eventType'] ?? 'MISSING'), 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - idempotencyKey: " . ($event_data['idempotencyKey'] ?? 'MISSING'), 'info');
        }
        
        // Use shared process_id for order lifecycle events, random for others
        $order_id = isset($event_data['primaryObjectID']) && $event_data['primaryObjectType'] === 'order' 
            ? (int) $event_data['primaryObjectID'] 
            : 0;
        
        // ENHANCED DEBUG: Log the process_id assignment logic with more detail
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: Process ID assignment logic:", 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - Extracted order_id: $order_id", 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - primaryObjectType check: " . ($event_data['primaryObjectType'] === 'order' ? 'PASS' : 'FAIL'), 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - primaryObjectID isset: " . (isset($event_data['primaryObjectID']) ? 'YES' : 'NO'), 'info');
            if (isset($event_data['primaryObjectID'])) {
                odcm_log_message("ODCM_PROCESS_ID_DEBUG: - primaryObjectID value: " . $event_data['primaryObjectID'], 'info');
                odcm_log_message("ODCM_PROCESS_ID_DEBUG: - primaryObjectID > 0: " . ($event_data['primaryObjectID'] > 0 ? 'YES' : 'NO'), 'info');
            }
            
            // Log event type and idempotency key for further debugging
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - Event Type: " . ($event_data['eventType'] ?? 'UNKNOWN'), 'info');
            odcm_log_message("ODCM_PROCESS_ID_DEBUG: - Idempotency Key: " . ($event_data['idempotencyKey'] ?? 'MISSING'), 'info');
        }
            
        // IMPORTANT: We always need a valid process_id, so use special handling for the case where
        // order_id is invalid or ProcessIdManager returns null
        $process_id = null;
        
        if ($order_id > 0) {
            // Try to get a shared process_id for proper order consolidation
            $process_id = ProcessIdManager::instance()->get_or_create_process_id($order_id);
            
            // Verify we got a valid process_id back (ProcessIdManager may return null for invalid orders)
            if ($process_id !== null) {
                // DEBUG: Log successful shared process_id assignment
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_PROCESS_ID_DEBUG: Using SHARED process_id for order #{$order_id}: $process_id", 'debug');
                }
            } else {
                // CRITICAL: ProcessIdManager rejected this order_id, so we need to use a unique process_id
                // This will prevent "Order #0" issues by avoiding the problematic process_id format
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_PROCESS_ID_DEBUG: CRITICAL: ProcessIdManager rejected order_id {$order_id}, falling back to unique ID", 'warning');
                }
                
                // Use unique system process_id as fallback
                $process_id = 'odcm_system_fallback_' . microtime(true) . '_' . uniqid();
            }
        }
        
        // If we still don't have a valid process_id (no order_id or validation failed), use a unique one
        if ($process_id === null) {
            // Use unique process_id for non-order events
            $process_id = 'odcm_universal_' . uniqid();
            
            // DEBUG: Log unique process_id assignment
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_PROCESS_ID_DEBUG: Using UNIQUE process_id (NOT CONSOLIDATED): $process_id", 'debug');
                odcm_log_message("ODCM_PROCESS_ID_DEBUG: This event will appear OUTSIDE the consolidated timeline!", 'debug');
            }
        }

        try {
            // Enhanced validation with detailed debugging
            $validation_result = $this->validateEventData($event_data, $process_id);
            if (!$validation_result) {
                $this->logError('Invalid event data structure', $event_data, $process_id);
                return false;
            }

            // Create UniversalEvent object from data with enhanced error handling
            try {
                $universal_event = new UniversalEvent($event_data);
            } catch (\InvalidArgumentException $e) {
                // Log the specific validation failure in UniversalEvent constructor
                $this->logError('UniversalEvent constructor validation failed: ' . $e->getMessage(), [
                    'original_event_data' => $event_data,
                    'validation_error' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                ], $process_id);
                return false;
            } catch (\Throwable $e) {
                // Log any other unexpected errors during UniversalEvent construction
                $this->logError('UniversalEvent constructor failed with unexpected error: ' . $e->getMessage(), [
                    'original_event_data' => $event_data,
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                ], $process_id);
                return false;
            }

            // Create evaluation context
            $context = new EvaluationContext($universal_event);

            // Check for idempotency
            if ($this->isDuplicateEvent($universal_event)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDuplicateEvent($universal_event, $process_id);
                }
                return true; // Not an error, just already processed
            }

            // Process through rule engine
            $result = $this->processUniversalEventRules($context, $process_id);

            // Log final processing result
            $execution_time = microtime(true) - $start_time;
            $this->logProcessingResult($context, $result, $execution_time, $process_id);

            return $result;

        } catch (\Throwable $e) {
            $execution_time = microtime(true) - $start_time;
            $this->logProcessingError($e, $event_data, $execution_time, $process_id);
            return false;
        }
    }

    /**
     * Create business-friendly error message from technical error
     * 
     * Converts technical error messages into user-friendly versions that
     * are appropriate for display in the timeline.
     * 
     * @param string $technical_message The technical error message
     * @param string $gateway The payment gateway name
     * @return string Business-friendly error message
     */
    public function createBusinessErrorMessage(string $technical_message, string $gateway): string
    {
        // Required fields for UniversalEvent
        $required_fields = [
            'eventType',
            'sourceGateway',
            'channel',
            'primaryObjectType',
            'occurredAt',
            'receivedAt',
            'idempotencyKey'
        ];

        // DEBUG: Log validation start
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_VALIDATION_DEBUG: Starting validateEventData for process_id: $process_id", 'info');
            odcm_log_message("ODCM_VALIDATION_DEBUG: Event data keys: " . implode(', ', array_keys($event_data)), 'info');
        }

        foreach ($required_fields as $field) {
            if (!isset($event_data[$field])) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_VALIDATION_DEBUG: FAIL - Missing required field: $field", 'error');
                }
                return false;
            }
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $value = $event_data[$field];
                $type = gettype($value);
                $display_value = is_null($value) ? 'NULL' : (is_string($value) ? "\"$value\"" : (is_scalar($value) ? (string) $value : '[complex]'));
                odcm_log_message("ODCM_VALIDATION_DEBUG: OK - Field '$field': $display_value (type: $type)", 'info');
            }
        }

        // Validate event type format
        if (!is_string($event_data['eventType']) || empty($event_data['eventType'])) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_VALIDATION_DEBUG: FAIL - eventType validation failed.", 'error');
            }
            return false;
        }

        // Validate idempotency key
        if (!is_string($event_data['idempotencyKey']) || empty($event_data['idempotencyKey'])) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_VALIDATION_DEBUG: FAIL - idempotencyKey validation failed.", 'error');
            }
            return false;
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_VALIDATION_DEBUG: SUCCESS - All initial validation passed. Moving to UniversalEvent constructor...", 'info');
        }

        return true;
    }

    /**
     * Check if this event has already been processed (idempotency check)
     * 
     * @param UniversalEvent $event Universal event
     * @return bool True if duplicate
     */
    private function isDuplicateEvent(UniversalEvent $event): bool
    {
        global $wpdb;

        // Create a unique cache key for this idempotency check
        $cache_key = 'odcm_idempotency_' . md5($event->idempotencyKey);
        
        // Check if we have this result cached
        $cached_result = wp_cache_get($cache_key);
        
        if (false !== $cached_result) {
            // Cache hit - return cached result
            return (bool)$cached_result;
        }
        
        // Cache miss - perform database query
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
             WHERE event_type = 'universal_event_processing' 
             AND idempotency_key = %s
             AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $event->idempotencyKey
        ));
        
        $is_duplicate = (int)$existing > 0;
        
        // Cache the result for 1 hour - idempotency checks are good candidates for caching
        // as they prevent duplicate processing even with cache race conditions
        wp_cache_set($cache_key, (int)$is_duplicate, '', HOUR_IN_SECONDS);
        
        return $is_duplicate;
    }

    /**
     * Log event reception for debugging
     * 
     * @param EvaluationContext $context Evaluation context
     * @param string $process_id Process ID
     * @return void
     */
    private function logEventReception(EvaluationContext $context, string $process_id): void
    {
        $event = $context->event;
        
        // Use the event's getSummary() method for better user-facing descriptions
        $summary = $event->getSummary();
        $gateway = $event->sourceGateway ? ucfirst($event->sourceGateway) : 'Payment gateway';
        $message = !empty($summary) ? "Event received: {$summary}" : sprintf('%s %s received', $gateway, $event->eventType);
        
        \odcm_log_event(
            $message,
            [
                'event_type' => $event->eventType,
                'source_gateway' => $event->sourceGateway,
                'channel' => $event->channel,
                'primary_object_type' => $event->primaryObjectType,
                'primary_object_id' => $event->primaryObjectID,
                'transaction_id' => $event->transactionID,
                'amount' => $event->amount,
                'currency' => $event->currency,
                'idempotency_key' => $event->idempotencyKey,
                'has_order' => $context->order !== null,
                'has_subscription' => $context->subscription !== null,
                'customer_id' => $context->getCustomerId(),
            ],
            $context->getOrderId(),
            'info',
            'universal_event_reception',
            false,
            $process_id
        );
    }

    /**
     * Log duplicate event detection
     * 
     * @param UniversalEvent $event Universal event
     * @param string $process_id Process ID
     * @return void
     */
    private function logDuplicateEvent(UniversalEvent $event, string $process_id): void
    {
        \odcm_log_event(
            sprintf('Duplicate universal event detected: %s (idempotency key: %s)', $event->eventType, $event->idempotencyKey),
            [
                'event_type' => $event->eventType,
                'source_gateway' => $event->sourceGateway,
                'idempotency_key' => $event->idempotencyKey,
                'duplicate_detection' => true,
            ],
            null,
            'debug',
            'universal_event_duplicate',
            false,
            $process_id
        );
    }

    /**
     * Log processing result
     * 
     * @param EvaluationContext $context Evaluation context
     * @param bool $result Processing result
     * @param float $execution_time Execution time in seconds
     * @param string $process_id Process ID
     * @return void
     */
    private function logProcessingResult(EvaluationContext $context, bool $result, float $execution_time, string $process_id): void
    {
        $event = $context->event;
        
        // Extract original event data
        $eventData = $event->toArray();
        
        // COMPONENT-BASED TIMELINE VISIBILITY: 
        // If a Universal Event has components (structured display data), 
        // it should appear in the timeline regardless of rule matches.
        // This makes timeline visibility naturally extensible and future-proof.
        $has_components = !empty($eventData['components']);
        
        // Create payload for timeline storage
        $payload_for_storage = [
            'event_type' => $event->eventType,
            'source_gateway' => $event->sourceGateway,
            'channel' => $event->channel,
            'primary_object_type' => $event->primaryObjectType,
            'primary_object_id' => $event->primaryObjectID,
            'transaction_id' => $event->transactionID,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'idempotency_key' => $event->idempotencyKey,
            'processing_result' => $result,
            'execution_time_ms' => round($execution_time * 1000, 2),
            'has_order' => $context->order !== null,
            'has_subscription' => $context->subscription !== null,
            'customer_id' => $context->getCustomerId(),
            // Include components in ProcessLogger structure for proper timeline rendering
            'components' => $eventData['components'] ?? [],
            // Preserve raw data for passing full original context to the UI renderers
            'rawData' => $event->rawData,
        ];
        
        // Enrich the top-level payload with component data for checkout_processed events
        // This ensures the renderer has the data it needs in both places - the component and the top level event
        if ($event->eventType === 'checkout_processed' && !empty($eventData['components'])) {
            // Find the checkout_processed component and extract its rich data
            foreach ($eventData['components'] as $component) {
                if (isset($component['event_type']) && $component['event_type'] === 'checkout_processed' && !empty($component['data'])) {
                    // Copy essential fields from component data to top-level payload
                    $payload_for_storage['order_id'] = $component['data']['order_id'] ?? $event->primaryObjectID;
                    $payload_for_storage['status'] = $component['data']['status'] ?? $event->status;
                    $payload_for_storage['payment_method'] = $component['data']['payment_method'] ?? '';
                    // Only copy total and currency if not already present at top level
                    if (isset($component['data']['total']) && (!isset($payload_for_storage['total']) || $payload_for_storage['total'] === 0)) {
                        $payload_for_storage['total'] = $component['data']['total'];
                    }
                    if (isset($component['data']['currency']) && empty($payload_for_storage['currency'])) {
                        $payload_for_storage['currency'] = $component['data']['currency'];
                    }
                    // Copy any other useful fields that renderers might expect
                    if (isset($component['data']['checkout_type'])) {
                        $payload_for_storage['checkout_type'] = $component['data']['checkout_type'];
                    }
                    break;
                }
            }
        }
        
        // Determine if this event should create rule execution timeline entries
        $is_canonical_rule_event = $this->isCanonicalTimelineEvent($event->eventType);
        
        // FIRST: Always log business events with components to preserve the timeline
        if ($has_components) {
            $summary = $event->getSummary();
            $gateway = $event->sourceGateway ? ucfirst($event->sourceGateway) : 'Payment gateway';
            $order_id = $context->getOrderId();
            $amount = $event->amount;
            $currency = $event->currency;

            // Generate user-friendly message based on event type
            $message = $this->generateUserFriendlyMessage($event->eventType, $gateway, $order_id, $amount, $currency, $summary);

            \odcm_log_event(
                $message,
                $payload_for_storage,
                $context->getOrderId(),
                'info', // Use info level for business events
                'universal_event_processing',
                false,
                $process_id
            );
        }
        
        // DISABLED: Standard rule execution event creation is now disabled to prevent duplicates
        // All rule execution events are now handled through the consolidated event system
        // This ensures a single source of truth and prevents duplicate timeline entries
        if ($result && $is_canonical_rule_event && $this->matched_rule_data) {
            // Rule execution events are now handled exclusively through createConsolidatedRuleExecutionEvent()
            // This method is called from processUniversalEventRules() for canonical events
            // No additional events are created here to prevent duplication
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Skipping standard rule execution event creation (handled by consolidated system)", 'debug');
            }
        } else if ($result && !$is_canonical_rule_event && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                // Log rule evaluation for non-canonical events with improved messaging
                $rule_name = $this->matched_rule_data['rule']->post_title ?? 'virtual rule';
                \odcm_log_event(
                    sprintf('Rule "%s" evaluated event: %s', $rule_name, $event->eventType),
                    [
                        'event_type' => $event->eventType,
                        'rule_name' => $rule_name,
                        'explanation' => 'This rule evaluated a ' . $event->eventType . ' event. This is a debug entry showing rule evaluation behavior for non-standard event types.',
                        'purpose' => 'Helps developers understand when rules evaluate different event types',
                        'note' => 'This entry appears in debug mode to provide visibility into rule evaluation',
                        'canonical_event' => false,
                        'timeline_behavior' => 'Debug entry created for visibility',
                        'debug_context' => [
                            'event_source' => $event->sourceGateway,
                            'event_channel' => $event->channel,
                            'order_id' => $context->getOrderId(),
                            'customer_id' => $context->getCustomerId(),
                            'rule_evaluation_context' => 'This debug entry helps trace rule evaluation for events that were not known when the original code was written',
                        ],
                    ],
                    $context->getOrderId(),
                    'debug',
                    'rule_evaluation_non_canonical',
                    false,
                    $process_id
                );
        } else if (!$has_components && defined('ODCM_DEBUG') && ODCM_DEBUG) {
            // Events without components only logged in debug mode
            $summary = $event->getSummary();
            $gateway = $event->sourceGateway ? ucfirst($event->sourceGateway) : 'Payment gateway';
            $message = !empty($summary) ? "No matching rules for: {$summary}" : sprintf('%s %s completed with no matching rules', $gateway, $event->eventType);
            
            \odcm_log_event(
                $message,
                $payload_for_storage,
                $context->getOrderId(),
                'debug',
                'universal_event_processing_debug',
                false,
                $process_id
            );
        }
        // Events without components and no rule matches are not logged (reduces noise)
        // This is extensible: any new event type with components will automatically appear in timeline
    }

    /**
     * Log processing error
     * 
     * @param \Throwable $error Error that occurred
     * @param array $event_data Original event data
     * @param float $execution_time Execution time in seconds
     * @param string $process_id Process ID
     * @return void
     */
    private function logProcessingError(\Throwable $error, array $event_data, float $execution_time, string $process_id): void
    {
        // Create business-friendly error message
        $gateway = isset($event_data['sourceGateway']) ? ucfirst($event_data['sourceGateway']) : 'Payment gateway';
        $business_message = $this->createBusinessErrorMessage($error->getMessage(), $gateway);
        
        \odcm_log_event(
            $business_message,
            [
                'event_type' => $event_data['eventType'] ?? 'unknown',
                'source_gateway' => $event_data['sourceGateway'] ?? 'unknown',
                'idempotency_key' => $event_data['idempotencyKey'] ?? 'unknown',
                'business_error_message' => $business_message,
                'technical_error_message' => $error->getMessage(), // Keep for debugging
                'error_file' => $error->getFile(),
                'error_line' => $error->getLine(),
                'execution_time_ms' => round($execution_time * 1000, 2),
                'event_data_summary' => [
                    'event_type' => $event_data['eventType'] ?? null,
                    'source_gateway' => $event_data['sourceGateway'] ?? null,
                    'primary_object_type' => $event_data['primaryObjectType'] ?? null,
                    'primary_object_id' => $event_data['primaryObjectID'] ?? null,
                ],
            ],
            null,
            'error',
            'universal_event_processing_error',
            false,
            $process_id
        );
    }

    /**
     * Log general error
     * 
     * @param string $message Error message
     * @param array $context Error context
     * @param string $process_id Process ID
     * @return void
     */
    private function logError(string $message, array $context, string $process_id): void
    {
        \odcm_log_event(
            'Payment gateway processor error: ' . $message,
            array_merge($context, [
                'component' => 'universal_event_processor',
                'error_type' => 'validation_error',
            ]),
            null,
            'error',
            'universal_event_processor_error',
            false,
            $process_id
        );
    }

    /**
     * Get processing statistics
     * 
     * @param int $hours Number of hours to look back (default: 24)
     * @return array Processing statistics
     */
    /**
     * Cache of processing statistics to prevent redundant queries
     * 
     * @var array
     */
    private static $processing_stats_cache = [];
    
    /**
     * Get processing statistics with caching
     * 
     * @param int $hours Number of hours to look back (default: 24)
     * @return array Processing statistics
     */
    public function getProcessingStats(int $hours = 24): array
    {
        global $wpdb;
        
        // Check static in-memory cache first (for multiple calls within the same request)
        if (isset(self::$processing_stats_cache[$hours])) {
            return self::$processing_stats_cache[$hours];
        }
        
        // Create cache key for this specific hours parameter
        $cache_key = 'odcm_processing_stats_' . $hours;
        
        // Try to get from persistent cache
        $cached_stats = wp_cache_get($cache_key);
        
        if (false !== $cached_stats) {
            // Store in static cache for this request
            self::$processing_stats_cache[$hours] = $cached_stats;
            return $cached_stats;
        }
        
        // Cache miss - calculate statistics from database
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        // Get total events processed with caching
        $total_cache_key = 'odcm_events_total_' . $hours;
        $total_events = wp_cache_get($total_cache_key);
        
        if (false === $total_events) {
            $total_events = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
                 WHERE event_type = 'universal_event_processing' 
                 AND timestamp >= %s",
                $since
            ));
            
            // Cache this count for 5 minutes
            wp_cache_set($total_cache_key, $total_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get successful events with caching
        $success_cache_key = 'odcm_events_success_' . $hours;
        $successful_events = wp_cache_get($success_cache_key);
        
        if (false === $successful_events) {
            $successful_events = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
                 WHERE event_type = 'universal_event_processing' 
                 AND status = 'success'
                 AND timestamp >= %s",
                $since
            ));
            
            // Cache this count for 5 minutes
            wp_cache_set($success_cache_key, $successful_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get failed events with caching
        $failed_cache_key = 'odcm_events_failed_' . $hours;
        $failed_events = wp_cache_get($failed_cache_key);
        
        if (false === $failed_events) {
            $failed_events = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
                 WHERE event_type IN ('universal_event_processing_error', 'universal_event_processor_error')
                 AND timestamp >= %s",
                $since
            ));
            
            // Cache this count for 5 minutes
            wp_cache_set($failed_cache_key, $failed_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get duplicate events with caching
        $duplicate_cache_key = 'odcm_events_duplicate_' . $hours;
        $duplicate_events = wp_cache_get($duplicate_cache_key);
        
        if (false === $duplicate_events) {
            $duplicate_events = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
                 WHERE event_type = 'universal_event_duplicate'
                 AND timestamp >= %s",
                $since
            ));
            
            // Cache this count for 5 minutes
            wp_cache_set($duplicate_cache_key, $duplicate_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get events by gateway with caching
        $gateway_cache_key = 'odcm_events_by_gateway_' . $hours;
        $events_by_gateway = wp_cache_get($gateway_cache_key);
        
        if (false === $events_by_gateway) {
            $events_by_gateway = $wpdb->get_results($wpdb->prepare(
                "SELECT JSON_EXTRACT(payload, '$.source_gateway') as gateway, COUNT(*) as count
                 FROM {$wpdb->prefix}odcm_audit_log 
                 WHERE event_type = 'universal_event_processing'
                 AND timestamp >= %s
                 GROUP BY gateway
                 ORDER BY count DESC",
                $since
            ), 'ARRAY_A');
            
            // Cache this result for 5 minutes
            wp_cache_set($gateway_cache_key, $events_by_gateway, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Compile the full stats array
        $stats = [
            'period_hours' => $hours,
            'total_events' => (int) $total_events,
            'successful_events' => (int) $successful_events,
            'failed_events' => (int) $failed_events,
            'duplicate_events' => (int) $duplicate_events,
            'success_rate' => $total_events > 0 ? round(($successful_events / $total_events) * 100, 2) : 0,
            'events_by_gateway' => $events_by_gateway ?: [],
            'cached' => true,
            'cache_time' => current_time('mysql'),
        ];
        
        // Cache the compiled statistics
        wp_cache_set($cache_key, $stats, '', 5 * MINUTE_IN_SECONDS);
        
        // Store in static cache for this request
        self::$processing_stats_cache[$hours] = $stats;
        
        return $stats;
    }

    /**
     * Storage for tracking rule trigger events during processing
     *
     * This cache groups trigger events by order ID, then by rule ID, allowing
     * us to consolidate multiple trigger events for the same rule+order combination
     *
     * @var array
     */
    private static $rule_trigger_events = [];

    /**
     * Storage for tracking rule execution events to prevent duplicates
     *
     * This tracks existing rule execution events by order ID and rule ID
     * to enable updating existing events instead of creating duplicates
     *
     * @var array
     */
    private static $rule_execution_events = [
        // order_id => [
        //     rule_id => [
        //         'event_id' => '...',  // ID of the logged event
        //         'primary_trigger' => '...',  // First trigger that matched
        //         'all_triggers' => ['...', '...'],  // All triggers that matched
        //         'process_id' => '...',  // Process ID for correlation
        //     ]
        // ]
    ];
    
    /**
     * Storage for tracking which events have been processed for each order+rule
     * This prevents duplicate rule execution events from different canonical event types
     *
     * @var array
     */
    private static $processed_events = [];
    
    /**
     * Process universal event through rule engine
     * 
     * Simplified rule processing that evaluates rules against universal events
     * and executes matching actions. Replaces the legacy Executor functionality.
     * 
     * @param EvaluationContext $context Universal event context
     * @param string $process_id Process ID for correlation
     * @return bool True if any rule matched and executed
     */
    private function processUniversalEventRules(EvaluationContext $context, string $process_id): bool
    {
        $order_id = $context->getOrderId();
        
        // Log entry to universal event rule processing
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Processing rules for Order #{$order_id}", 'debug');
        }
        
        // Check if post type exists
        if (!post_type_exists('odcm_order_rule')) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Order rule post type does not exist!", 'debug');
            }
            return false;
        }

        // Get active rules
        $rules_query = new \WP_Query([
            'post_type'      => 'odcm_order_rule',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Found " . $rules_query->found_posts . " published rules", 'debug');
        }

        if (!$rules_query->have_posts()) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - No rules found, returning false", 'debug');
            }
            return false;
        }
        
        // ENHANCED VALIDATION: Strict checks for Order #0 prevention
        // Validate order ID with detailed logging to avoid "Order #0" issues
        if (!$order_id || $order_id <= 0) {
            $event_info = json_encode([
                'event_type' => $context->event->eventType,
                'source_gateway' => $context->event->sourceGateway,
                'idempotency_key' => $context->event->idempotencyKey,
                'requested_order_id' => $order_id,
                'primary_object_id' => $context->event->primaryObjectID,
                'primary_object_type' => $context->event->primaryObjectType
            ]);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - CRITICAL: Invalid Order ID debugging trace: " . $event_info, 'error');
                
                // Add stack trace for detailed debugging
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
                $trace_info = "";
                foreach ($backtrace as $idx => $frame) {
                    $file = isset($frame['file']) ? basename($frame['file']) : 'unknown';
                    $line = $frame['line'] ?? '?';
                    $function = $frame['function'] ?? 'unknown';
                    $trace_info .= "#{$idx} {$file}:{$line} - {$function}(), ";
                }
                odcm_log_message("ODCM_DEBUG_TRACE: Invalid Order ID backtrace: " . $trace_info, 'error');
            }
            
            // Return false to completely skip rule evaluation for invalid order IDs
            return false;
        }
        
        // CANONICAL TIMELINE EVENT LOGIC
        // Only create ProcessLogger timeline events for the canonical rule evaluation trigger.
        // This prevents duplicate timeline events while preserving all rule evaluation functionality.
        $is_canonical_timeline_event = $this->isCanonicalTimelineEvent($context->event->eventType);
        
        // Store trigger event details for consolidated rule execution records
        $this->recordTriggerEvent($order_id, $context);
        
        // ProcessLogger is only created for canonical events (order_status_changed)
        // This ensures a single source of truth in the timeline while preserving rule logic
        $rule_logger = null;
        if ($is_canonical_timeline_event) {
            // Set universal event context to prevent ProcessLogger from creating timeline events
            // ProcessLogger will still provide process_id infrastructure but won't duplicate timeline events
            \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger::set_universal_event_context(true);
            
            // Order ID validation has already happened above
            $rule_logger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger();
            $rule_logger->start('rule_execution', [
                'order_id' => $order_id, // Safe to use now that we've validated it
                'summary' => 'Universal event rule processing',
                'event_type' => $context->event->eventType,
                'source_gateway' => $context->event->sourceGateway,
            ]);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Created ProcessLogger for CANONICAL event: {$context->event->eventType}", 'debug');
            }
        } else {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Skipping ProcessLogger for non-canonical event: {$context->event->eventType}", 'debug');
            }
        }

        // Process rules with First Match Wins logic
        foreach ($rules_query->posts as $rule) {
            // Load rule JSON data
            $json = get_post_meta((int)$rule->ID, '_odcm_rule_data', true);
            $rule_data = is_string($json) ? json_decode($json, true) : null;
            
            if (!is_array($rule_data)) {
                continue;
            }

            // Evaluate rule against universal event context 
            // Debug log the evaluation for troubleshooting purposes
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Evaluating rule '{$rule->post_title}' (ID: {$rule->ID}) for event type: {$context->event->eventType}", 'debug');
            }
            
            $trace = $this->evaluator->evaluateRuleAgainstUniversalEvent($context, $rule_data, $this->registry);

            if ($trace['matched']) {
                // Store matched rule data for enhanced logging
                $this->matched_rule_data = [
                    'rule' => $rule,
                    'rule_data' => $rule_data,
                    'trace' => $trace,
                    'context' => $context
                ];
                
                // Add rule ID to trigger events for consolidated event tracking
                $this->recordRuleMatch($order_id, (int)$rule->ID, $context);
                
                // Add ProcessLogger component for rule execution (only for canonical events)
                if ($rule_logger) {
                    $rule_logger->add_component(
                        'rule_execution',
                        sprintf('Rule "%s" executed successfully', $rule->post_title),
                        [
                            'rule_id' => $rule->ID,
                            'rule_name' => $rule->post_title,
                            'matched_conditions' => count(array_filter($trace['conditions'], fn($c) => $c['result'] === 'pass')),
                            'total_conditions' => count($trace['conditions']),
                            'event_type' => $context->event->eventType,
                            'order_id' => $context->getOrderId(),
                            // RICH CONTEXT: Include information about all triggering events
                            'canonical_event' => $is_canonical_timeline_event,
                            'trigger_context' => 'This rule was triggered by ' . $context->event->eventType . ' but timeline event created for canonical order_status_changed',
                        ],
                        'info',
                        'rule_execution_' . $rule->ID
                    );
                }
                
                // Execute primary action and track results
                $action_results = [];
                if (isset($rule_data['primaryAction']['id'])) {
                    $action_results['primary'] = $this->executeUniversalEventAction($context, $rule_data['primaryAction']);
                }

                // Execute secondary actions and track results
                if (!empty($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
                    $action_results['secondary'] = [];
                    foreach ($rule_data['secondaryActions'] as $index => $actionDef) {
                        if (isset($actionDef['id'])) {
                            $action_results['secondary'][$index] = $this->executeUniversalEventAction($context, $actionDef);
                        }
                    }
                }
                
                // Store action results for enhanced logging
                $this->matched_rule_data['action_results'] = $action_results;

                // Don't call ProcessLogger.finish() when universal_event_context is active
                // This prevents the Order #0 issue caused by ProcessLogger returning correlation_id
                // which then gets processed as a malformed log entry
                if ($rule_logger && !self::$universal_event_context) {
                    $rule_logger->finish(
                        'success',
                        sprintf('Rule "%s" evaluated successfully for Order #%d', $rule->post_title, $context->getOrderId())
                    );
                }

                // RESET: Clear universal event context flag after successful rule processing completes
                if ($is_canonical_timeline_event) {
                    \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger::set_universal_event_context(false);
                    
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Reset universal_event_context flag after successful rule execution", 'debug');
                    }
                }

                // If this is a canonical event and we have a rule match, create a consolidated rule execution event
                if ($is_canonical_timeline_event) {
                    $this->createConsolidatedRuleExecutionEvent($order_id, (int)$rule->ID, $context, $process_id);
                }

                // First Match Wins - stop processing
                return true;
            } else {
                // For debug purposes only, log non-match information
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    // Find the failed condition for more detailed logging
                    $failed_condition = null;
                    foreach ($trace['conditions'] as $condition) {
                        if ($condition['result'] === 'fail') {
                            $failed_condition = $condition;
                            break;
                        }
                    }
                    
                    $message = sprintf('Rule "%s" did not match conditions', $rule->post_title);
                    if ($failed_condition) {
                        $message .= sprintf(' (Failed on: %s)', $failed_condition['label']);
                    }
                    
                    // Enhanced debug logging with detailed condition information
                    odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Rule '{$rule->post_title}' didn't match - Event type: {$context->event->eventType}, Order ID: {$context->getOrderId()}", 'debug');
                    
                    // Log non-match at debug level directly to the audit log
                    \odcm_log_event(
                        $message,
                        [
                            'rule_id' => $rule->ID,
                            'rule_name' => $rule->post_title,
                            'conditions' => $trace['conditions'],
                            'failed_condition' => $failed_condition ? $failed_condition['label'] : null,
                            'event_type' => $context->event->eventType,
                            'source_gateway' => $context->event->sourceGateway,
                        ],
                        $context->getOrderId(),
                        'debug', // Explicitly debug level
                        'rule_no_match',
                        false
                    );
                }
                
                // No need to create a ProcessLogger or call finish() for non-matches
            }
        }

        // FINISH ProcessLogger when no rules matched (only for canonical events)
        // Don't call finish() when universal_event_context is active to prevent Order #0 issue
        if ($rule_logger && !self::$universal_event_context) {
            $rule_logger->finish(
                'debug',
                sprintf('No matching rules found for Order #%d (event: %s)', $context->getOrderId(), $context->event->eventType)
            );
        }

        // RESET: Clear universal event context flag after rule processing completes
        if ($is_canonical_timeline_event) {
            \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger::set_universal_event_context(false);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Reset universal_event_context flag after rule processing", 'debug');
            }
        }

        return false; // No rules matched
    }
    
    /**
     * Record a trigger event for an order
     * 
     * This helps track all events that trigger rule evaluation for an order
     * to be used in consolidated rule execution events.
     * 
     * @param int $order_id Order ID
     * @param EvaluationContext $context Universal event context
     * @return void
     */
    private function recordTriggerEvent(int $order_id, EvaluationContext $context): void
    {
        if ($order_id <= 0) {
            return;
        }
        
        // Initialize order tracking if doesn't exist
        if (!isset(self::$rule_trigger_events[$order_id])) {
            self::$rule_trigger_events[$order_id] = [
                'events' => [],
                'rule_matches' => [],
            ];
        }
        
        // Add this event to the order's trigger events
        $trigger_data = [
            'event_type' => $context->event->eventType,
            'source_gateway' => $context->event->sourceGateway,
            'timestamp' => $context->event->occurredAt,
            'amount' => $context->event->amount,
            'currency' => $context->event->currency,
            'status_from' => $context->event->rawData['from_status'] ?? null,
            'status_to' => $context->event->rawData['to_status'] ?? null,
            'idempotency_key' => $context->event->idempotencyKey,
            'customer_type' => ($context->order && $context->order->get_customer_id() > 0) ? 'registered' : 'guest',
            'payment_method' => $context->order ? $context->order->get_payment_method_title() : '',
        ];
        
        self::$rule_trigger_events[$order_id]['events'][$context->event->eventType] = $trigger_data;
    }
    
    /**
     * Record a rule match for an order
     * 
     * This tracks which rules matched for each order
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param EvaluationContext $context Evaluation context
     */
    private function recordRuleMatch(int $order_id, int $rule_id, EvaluationContext $context): void
    {
        if ($order_id <= 0 || $rule_id <= 0) {
            return;
        }
        
        // Initialize rule tracking
        if (!isset(self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id])) {
            self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id] = [];
        }
        
        // Add this event as a trigger for this rule
        self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id][] = $context->event->eventType;
    }

    /**
     * Check if a consolidated rule execution event already exists for this rule+order combination
     * Enhanced with improved database lookup and persistent caching to prevent duplicates
     * across multiple requests for the same rule+order combination.
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param EvaluationContext|null $context Evaluation context (optional)
     * @return array|null Existing event data or null if not found
     */
    private function getExistingRuleExecutionEvent(int $order_id, int $rule_id, ?EvaluationContext $context = null): ?array
    {
        if ($order_id <= 0 || $rule_id <= 0) {
            return null;
        }

        // Check in-memory cache first
        if (isset(self::$rule_execution_events[$order_id][$rule_id])) {
            return self::$rule_execution_events[$order_id][$rule_id];
        }

        // Check persistent cache using WordPress transients
        $cache_key = 'odcm_rule_exec_' . $order_id . '_' . $rule_id;
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            // Store in instance variable for this request
            self::$rule_execution_events[$order_id][$rule_id] = $cached_data;
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Found cached rule execution event for Order #{$order_id}, Rule ID {$rule_id}", 'debug');
            }
            
            return $cached_data;
        }

        // Check if we have any existing rule execution events in the database
        global $wpdb;

        // ENHANCED QUERY: Use precise rule_id lookup in the payload
        // This provides more reliable deduplication across different requests
        $existing_events = $wpdb->get_results($wpdb->prepare(
            "SELECT log_id, payload 
             FROM {$wpdb->prefix}odcm_audit_log
             WHERE order_id = %d
             AND event_type = 'rule_execution'
             AND JSON_EXTRACT(payload, '$.rule_id') = %d
             AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY timestamp DESC",
            $order_id,
            $rule_id
        ), 'ARRAY_A');

        if (!empty($existing_events)) {
            foreach ($existing_events as $event) {
                // Parse payload JSON
                $payload = json_decode($event['payload'], true);
                if (is_array($payload)) {
                    // Found existing event for this rule+order combination
                    $event_data = [
                        'event_id' => $event['log_id'],
                        'primary_trigger' => $payload['primary_trigger'] ?? ($context ? $context->event->eventType : ''),
                        'all_triggers' => $payload['all_triggers'] ?? [],
                        'process_id' => $payload['process_id'] ?? '',
                    ];

                    // Cache it for future reference - both in memory and transient
                    self::$rule_execution_events[$order_id][$rule_id] = $event_data;
                    set_transient($cache_key, $event_data, HOUR_IN_SECONDS);
                    
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Found existing rule execution event in database - Event ID: {$event['log_id']}", 'debug');
                    }

                    return $event_data;
                }
            }
        }
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - No existing rule execution event found for Order #{$order_id}, Rule ID {$rule_id}", 'debug');
        }

        return null;
    }

    /**
     * Update an existing rule execution event with additional trigger information
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param EvaluationContext $context Current evaluation context
     * @param string $process_id Process ID
     * @return bool True if update was successful, false otherwise
     */
    private function updateExistingRuleExecutionEvent(int $order_id, int $rule_id, EvaluationContext $context, string $process_id): bool
    {
        $existing_event = $this->getExistingRuleExecutionEvent($order_id, $rule_id);

        if (!$existing_event) {
            return false;
        }

        // Get the current trigger events for this rule
        $trigger_events = [];
        $primary_trigger_event = $context->event->eventType;

        if (isset(self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id])) {
            foreach (self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id] as $event_type) {
                if (isset(self::$rule_trigger_events[$order_id]['events'][$event_type])) {
                    $trigger_events[$event_type] = self::$rule_trigger_events[$order_id]['events'][$event_type];
                }
            }
        }

        // Add the new trigger to existing triggers
        $all_triggers = array_unique(array_merge($existing_event['all_triggers'], array_keys($trigger_events)));

        // Build updated payload
        $rule_payload = $this->enhancePayloadWithRuleData($this->buildBasePayload($context), $context);

        // Add consolidated trigger event information
        $rule_payload['primary_trigger'] = $primary_trigger_event;
        $rule_payload['all_triggers'] = $all_triggers;
        $rule_payload['trigger_details'] = $trigger_events;

        // Create consolidated rule execution component
        $rule_component = $this->createConsolidatedRuleExecutionComponent(
            $rule_payload,
            $context,
            $primary_trigger_event,
            $trigger_events
        );

        // Create separate payload for rule execution event
        $rule_payload['components'] = [$rule_component];

        // Update the existing event using WordPress transient API for robustness
        $transient_key = 'odcm_rule_execution_update_' . $existing_event['event_id'];
        $update_data = [
            'event_id' => $existing_event['event_id'],
            'payload' => $rule_payload,
            'timestamp' => current_time('mysql'),
            'order_id' => $order_id,
            'rule_id' => $rule_id,
            'process_id' => $process_id,
        ];

        // Store update data in transient for processing
        set_transient($transient_key, $update_data, HOUR_IN_SECONDS);

        // Also trigger immediate update via action hook for real-time processing
        do_action('odcm_update_rule_execution_event', $existing_event['event_id'], $rule_payload);

        // Update our in-memory cache
        self::$rule_execution_events[$order_id][$rule_id] = [
            'event_id' => $existing_event['event_id'],
            'primary_trigger' => $primary_trigger_event,
            'all_triggers' => $all_triggers,
            'process_id' => $process_id,
        ];

        return true;
    }
    
    /**
     * Create a consolidated rule execution event for an order/rule combination
     * 
     * This creates a single rule execution event that shows all triggering events
     * with enhanced validation to prevent Order #0 issues
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param EvaluationContext $context Current evaluation context
     * @param string $process_id Process ID
     */
    private function createConsolidatedRuleExecutionEvent(int $order_id, int $rule_id, EvaluationContext $context, string $process_id): void
    {
        // ENHANCED VALIDATION: Stricter checks to prevent Order #0 issues
        if ($order_id <= 0 || $rule_id <= 0 || !$this->matched_rule_data) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message(
                    "ODCM_DEDUP_DEBUG: Rejecting rule execution event creation - Invalid parameters: " . 
                    "order_id={$order_id}, rule_id={$rule_id}, has_rule_data=" . ($this->matched_rule_data ? 'yes' : 'no'),
                    'warning'
                );
            }
            return;
        }
        
        // Only create consolidated events for canonical events to prevent duplicates
        if (!$this->isCanonicalTimelineEvent($context->event->eventType)) {
            return;
        }
        
        // Ensure we have trigger events recorded
        if (!isset(self::$rule_trigger_events[$order_id]) || 
            !isset(self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id])) {
            return;
        }
        
        $rule_name = $this->matched_rule_data['rule']->post_title ?? 'unnamed rule';
        
        // Get trigger events for this rule
        $trigger_events = [];
        
        // Determine the primary trigger event - use getPrimaryCanonicalEvent for consistency
        $primary_trigger_event = $this->getPrimaryCanonicalEvent(
            $context->event->eventType,
            $order_id,
            $rule_id
        );
        
        // Collect all trigger events that matched this rule
        foreach (self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id] as $event_type) {
            if (isset(self::$rule_trigger_events[$order_id]['events'][$event_type])) {
                $trigger_events[$event_type] = self::$rule_trigger_events[$order_id]['events'][$event_type];
            }
        }
        
        // ALWAYS check for existing event first, update if found
        $existing_event = $this->getExistingRuleExecutionEvent($order_id, $rule_id, $context);

        if ($existing_event) {
            // Instead of creating a new event, update the existing event
            $this->updateExistingRuleExecutionEvent($order_id, $rule_id, $context, $process_id);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Updated existing rule execution event for Rule '{$rule_name}' (Order #{$order_id})", 'debug');
                odcm_log_message("ODCM_DEDUP_DEBUG: Event ID: {$existing_event['event_id']}, Primary trigger: {$primary_trigger_event}", 'debug');
            }

            // Return early - existing event updated, no new event created
            return;
        }

        // Enhance payload with rule execution data
        $rule_payload = $this->enhancePayloadWithRuleData($this->buildBasePayload($context), $context);
        
        // Add consolidated trigger event information
        $rule_payload['primary_trigger'] = $primary_trigger_event;
        $rule_payload['all_triggers'] = array_keys($trigger_events);
        $rule_payload['trigger_details'] = $trigger_events;
        
        // Create consolidated rule execution component
        $rule_component = $this->createConsolidatedRuleExecutionComponent(
            $rule_payload,
            $context,
            $primary_trigger_event,
            $trigger_events
        );
        
        // Create separate payload for rule execution event
        $rule_payload['components'] = [$rule_component];
        
        // Log consolidated rule execution event
        $rule_message = sprintf('Rule "%s" evaluated successfully for Order #%d', $rule_name, $context->getOrderId());
        
        $event_id = \odcm_log_event(
            $rule_message,
            $rule_payload,
            $context->getOrderId(),
            'success',
            'rule_execution',
            false,
            $process_id
        );

        // Cache the new event for future reference with improved caching
        if ($event_id) {
            $event_data = [
                'event_id' => $event_id,
                'primary_trigger' => $primary_trigger_event,
                'all_triggers' => array_keys($trigger_events),
                'process_id' => $process_id,
                'trigger_details' => $trigger_events,
            ];
            
            // Store in both memory cache and persistent cache
            self::$rule_execution_events[$order_id][$rule_id] = $event_data;
            
            // Use improved cache key
            $cache_key = 'odcm_rule_exec_order_' . $order_id . '_rule_' . $rule_id;
            set_transient($cache_key, $event_data, HOUR_IN_SECONDS);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Created new consolidated rule execution event for Rule '{$rule_name}' (Order #{$order_id})", 'debug');
                odcm_log_message("ODCM_DEDUP_DEBUG: Event ID: {$event_id}, Primary trigger: {$primary_trigger_event}", 'debug');
            }
        }
    }
    
    /**
     * Build base payload from context
     * 
     * @param EvaluationContext $context Evaluation context
     * @return array Base payload
     */
    private function buildBasePayload(EvaluationContext $context): array
    {
        $event = $context->event;
        
        // Extract original event data
        $eventData = $event->toArray();
        
        // Create base payload for timeline storage
        return [
            'event_type' => $event->eventType,
            'source_gateway' => $event->sourceGateway,
            'channel' => $event->channel,
            'primary_object_type' => $event->primaryObjectType,
            'primary_object_id' => $event->primaryObjectID,
            'transaction_id' => $event->transactionID,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'idempotency_key' => $event->idempotencyKey,
            'processing_result' => true,
            'execution_time_ms' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) * 1000,
            'has_order' => $context->order !== null,
            'has_subscription' => $context->subscription !== null,
            'customer_id' => $context->getCustomerId(),
            // Include components in ProcessLogger structure for proper timeline rendering
            'components' => $eventData['components'] ?? [],
            // Preserve raw data for passing full original context to the UI renderers
            'rawData' => $event->rawData ?? [],
        ];
    }

    /**
     * Enhance payload with comprehensive rule execution data
     * 
     * @param array $payload_for_storage Base payload
     * @param EvaluationContext $context Evaluation context
     * @return array Enhanced payload with rule data
     */
    private function enhancePayloadWithRuleData(array $payload_for_storage, EvaluationContext $context): array
    {
        if (!$this->matched_rule_data) {
            return $payload_for_storage;
        }

        $rule = $this->matched_rule_data['rule'];
        $rule_data = $this->matched_rule_data['rule_data'];
        $trace = $this->matched_rule_data['trace'];
        $action_results = $this->matched_rule_data['action_results'] ?? [];

        // Determine execution status
        $execution_status = $this->determineRuleExecutionStatus($action_results);
        
        // Extract executed actions for display
        $executed_actions = $this->formatExecutedActions($rule_data, $action_results);
        
        // Add main timeline display data (above the fold)
        $payload_for_storage['rule_name'] = $rule->post_title;
        $payload_for_storage['rule_id'] = $rule->ID;
        $payload_for_storage['executed_actions'] = $executed_actions;
        $payload_for_storage['execution_status'] = $execution_status;
        $payload_for_storage['order_id'] = $context->getOrderId();

        // Build comprehensive debugging data (below the fold)
        $comprehensive_rule_data = [
            // === RULE CONFIGURATION ===
            'rule_configuration' => [
                'rule_id' => $rule->ID,
                'rule_name' => $rule->post_title,
                'rule_status' => 'publish',
                'trigger_type' => $rule_data['trigger']['id'] ?? 'unknown',
                'trigger_settings' => $rule_data['trigger']['settings'] ?? [],
            ],
            
            // === CONDITION EVALUATION BREAKDOWN ===
            'condition_evaluation' => [
                'total_conditions' => count($trace['conditions']),
                'conditions_passed' => count(array_filter($trace['conditions'], fn($c) => $c['result'] === 'pass')),
                'evaluation_logic' => 'ALL', // Could be enhanced to read from rule data
                'condition_details' => $this->formatConditionDetails($trace['conditions']),
            ],
            
            // === ORDER CONTEXT AT EVALUATION ===
            'order_evaluation_context' => [
                'order_id' => $context->getOrderId(),
                'order_status' => $context->order ? $context->order->get_status() : 'unknown',
                'order_total' => $context->event->amount,
                'order_currency' => $context->event->currency,
                'customer_id' => $context->getCustomerId(),
                'customer_type' => ($context->order && $context->order->get_customer_id() > 0) ? 'registered' : 'guest',
                'payment_method' => $context->order ? $context->order->get_payment_method() : '',
                'payment_method_title' => $context->order ? $context->order->get_payment_method_title() : '',
                'billing_country' => $context->order ? $context->order->get_billing_country() : '',
                'shipping_country' => $context->order ? $context->order->get_shipping_country() : '',
            ],
            
            // === TRIGGER EVENT DETAILS ===
            'trigger_event_context' => [
                'triggering_event' => $context->event->eventType,
                'event_source' => $context->event->sourceGateway,
                'event_channel' => $context->event->channel,
                'event_timestamp' => $context->event->occurredAt,
                'idempotency_key' => $context->event->idempotencyKey,
                'status_transition' => [
                    'from_status' => $context->event->rawData['from_status'] ?? null,
                    'to_status' => $context->event->rawData['to_status'] ?? null,
                ]
            ],
            
            // === ACTION EXECUTION DETAILS ===
            'action_execution' => $this->formatActionExecutionDetails($rule_data, $action_results),
            
            // === SYSTEM PERFORMANCE ===
            'execution_metrics' => [
                'evaluation_time_ms' => $payload_for_storage['execution_time_ms'],
                'rule_position_in_queue' => 1, // First match wins
                'first_match_wins' => true,
            ],
            
            // === COMPLETE EVALUATION TRACE ===
            'full_evaluation_trace' => $trace,
        ];

        // Add comprehensive data to rawData for expandable sections
        $payload_for_storage['rawData']['rule_execution'] = $comprehensive_rule_data;

        return $payload_for_storage;
    }

    /**
     * Determine rule execution status based on action results
     * 
     * @param array $action_results Action execution results
     * @return string Status (EXECUTED, PARTIAL, ERROR)
     */
    private function determineRuleExecutionStatus(array $action_results): string
    {
        if (empty($action_results)) {
            return 'EXECUTED'; // Rule matched, no actions to execute
        }

        $all_successful = true;
        $any_successful = false;

        // Check primary action
        if (isset($action_results['primary'])) {
            $primary_success = $action_results['primary'] === 'success';
            $all_successful = $all_successful && $primary_success;
            $any_successful = $any_successful || $primary_success;
        }

        // Check secondary actions
        if (isset($action_results['secondary']) && is_array($action_results['secondary'])) {
            foreach ($action_results['secondary'] as $result) {
                $success = $result === 'success';
                $all_successful = $all_successful && $success;
                $any_successful = $any_successful || $success;
            }
        }

        if ($all_successful) {
            return 'EXECUTED';
        } elseif ($any_successful) {
            return 'PARTIAL';
        } else {
            return 'ERROR';
        }
    }

    /**
     * Format executed actions for timeline display
     * 
     * @param array $rule_data Rule configuration data
     * @param array $action_results Action execution results
     * @return string Formatted actions list
     */
    private function formatExecutedActions(array $rule_data, array $action_results): string
    {
        $actions = [];

        // Add primary action
        if (isset($rule_data['primaryAction']['id'])) {
            $actions[] = $this->getActionLabel($rule_data['primaryAction']['id']);
        }

        // Add secondary actions
        if (!empty($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
            foreach ($rule_data['secondaryActions'] as $actionDef) {
                if (isset($actionDef['id'])) {
                    $actions[] = $this->getActionLabel($actionDef['id']);
                }
            }
        }

        return implode(', ', $actions);
    }

    /**
     * Get human-readable action label
     * 
     * @param string $action_id Action ID
     * @return string Action label
     */
    private function getActionLabel(string $action_id): string
    {
        $actions = $this->registry->get_actions();
        if (isset($actions[$action_id])) {
            return $actions[$action_id]->get_label();
        }

        // Fallback to action ID with formatting
        return ucwords(str_replace('_', ' ', $action_id));
    }

    /**
     * Format condition details for debugging display
     * 
     * @param array $conditions Condition evaluation results
     * @return array Formatted condition details
     */
    private function formatConditionDetails(array $conditions): array
    {
        $formatted = [];
        
        foreach ($conditions as $condition) {
            $formatted[] = [
                'condition_type' => $condition['component_id'] ?? 'unknown',
                'condition_label' => $condition['label'] ?? 'Unknown Condition',
                'result' => strtoupper($condition['result'] ?? 'unknown'),
                'evaluation_reason' => $condition['message'] ?? 'No details available'
            ];
        }
        
        return $formatted;
    }

    /**
     * Format action execution details for debugging
     * 
     * @param array $rule_data Rule configuration data
     * @param array $action_results Action execution results
     * @return array Formatted action execution details
     */
    private function formatActionExecutionDetails(array $rule_data, array $action_results): array
    {
        $details = [];

        // Primary action
        if (isset($rule_data['primaryAction']['id'])) {
            $action_id = $rule_data['primaryAction']['id'];
            $details['primary_action'] = [
                'action_id' => $action_id,
                'action_label' => $this->getActionLabel($action_id),
                'action_settings' => $rule_data['primaryAction']['settings'] ?? [],
                'execution_result' => $action_results['primary'] ?? 'unknown',
            ];
        }

        // Secondary actions
        $secondary_details = [];
        if (!empty($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
            foreach ($rule_data['secondaryActions'] as $index => $actionDef) {
                if (isset($actionDef['id'])) {
                    $action_id = $actionDef['id'];
                    $secondary_details[] = [
                        'action_id' => $action_id,
                        'action_label' => $this->getActionLabel($action_id),
                        'action_settings' => $actionDef['settings'] ?? [],
                        'execution_result' => $action_results['secondary'][$index] ?? 'unknown',
                    ];
                }
            }
        }
        
        if (!empty($secondary_details)) {
            $details['secondary_actions'] = $secondary_details;
        }

        return $details;
    }

    /**
     * Execute action component for universal event context
     * 
     * @param EvaluationContext $context Universal event context
     * @param array $actionDef Action definition
     * @return string Execution result ('success' or 'failed')
     */
    private function executeUniversalEventAction(EvaluationContext $context, array $actionDef): string
    {
        $id = isset($actionDef['id']) && is_string($actionDef['id']) ? $actionDef['id'] : '';
        if ($id === '') {
            return 'failed';
        }

        $actions = $this->registry->get_actions();
        if (!isset($actions[$id])) {
            return 'failed';
        }

        $component = $actions[$id];
        $schema = $component->get_settings_schema();
        $rawSettings = is_array($actionDef['settings'] ?? null) ? $actionDef['settings'] : [];
        $clean = $this->evaluator->sanitize_by_schema($rawSettings, $schema);

        try {
            // Execute action with appropriate entity
            if ($context->order && method_exists($component, 'execute')) {
                // Standard order-based action
                $component->execute($context->order, $clean);
                return 'success';
            } elseif (method_exists($component, 'executeUniversalEvent')) {
                // Universal event-aware action
                $component->executeUniversalEvent($context, $clean);
                return 'success';
            } elseif ($context->order) {
                // Fallback: try with order if available
                $component->execute($context->order, $clean);
                return 'success';
            } else {
                // No valid execution path
                return 'failed';
            }
        } catch (\Throwable $e) {
            // Log action execution error but don't stop processing
            \odcm_log_event(
                sprintf('Action execution failed: %s', $e->getMessage()),
                [
                    'action_id' => $id,
                    'action_label' => $component->get_label(),
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'event_type' => $context->event->eventType,
                    'source_gateway' => $context->event->sourceGateway,
                ],
                $context->getOrderId(),
                'error',
                'universal_event_action_error'
            );
            return 'failed';
        }
    }


    /**
     * Check if this event type should create timeline events
     * 
     * This implements the "single source of truth" principle for rule execution timeline events.
     * Only the canonical event (representing the actual business decision point) creates 
     * timeline events, while all events still trigger rule evaluation to preserve functionality.
     * 
     * SAFETY FALLBACK: Unknown events default to creating timeline events to ensure
     * no events are accidentally hidden from the timeline.
     * 
     * @param string $event_type Universal event type
     * @return bool True if this event should create timeline events
     */
    private function isCanonicalTimelineEvent(string $event_type): bool
    {
        // CANONICAL EVENTS (creates timeline events)
        // These represent legitimate business events that users need to see
        // Each represents a distinct business milestone in the order lifecycle
        $canonical_timeline_events = [
            'order_status_changed',  // When rules actually change order status
            'checkout_processed',    // When checkout is completed (legitimate business event)
            'order_created',        // When order is created (legitimate business event)
            'payment_completed',    // When payment is completed (legitimate business event)
        ];

        // NON-CANONICAL EVENTS (rule evaluation only, no timeline events)
        // These are purely technical/internal events that don't represent business milestones
        // They should only appear in debug mode for troubleshooting
        $non_canonical_events = [
            'order_check_scheduled',  // Internal scheduling, not business-relevant
            'rule_evaluation_non_canonical', // Debug traces for rule evaluation
            '_status_evaluation',     // Debug events for status change evaluation
            'process_started',        // Technical process lifecycle events
        ];

        // Explicit canonical events always create timeline events
        if (in_array($event_type, $canonical_timeline_events)) {
            return true;
        }

        // Known non-canonical events never create timeline events (deduplication)
        // These events are technical-only and should not clutter the main timeline
        if (in_array($event_type, $non_canonical_events)) {
            return false;
        }

        // SAFETY FALLBACK: Unknown events default to creating timeline events
        // This ensures new event types aren't accidentally hidden from the timeline
        return true;
    }
    
    /**
     * Determine primary canonical event for rule execution display
     * 
     * When multiple canonical events trigger the same rule, this method
     * determines which one should be displayed in the timeline for consistency
     * 
     * @param string $event_type Current event type
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @return string Primary event type to display
     */
    private function getPrimaryCanonicalEvent(string $event_type, int $order_id, int $rule_id): string
    {
        // Define a unique key for this order+rule combination
        $key = $order_id . '_' . $rule_id;
        
        // If this is the first event for this order+rule, register it as primary
        if (!isset(self::$processed_events[$key])) {
            self::$processed_events[$key] = $event_type;
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Registered primary event {$event_type} for Order #{$order_id}, Rule #{$rule_id}", 'debug');
            }
            return $event_type;
        }
        
        // Otherwise, return the existing primary event
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEDUP_DEBUG: Using existing primary event " . self::$processed_events[$key] . " instead of {$event_type}", 'debug');
        }
        return self::$processed_events[$key];
    }

    /**
     * Create a consolidated rule_execution component for timeline display
     * 
     * This creates a comprehensive rule execution component that shows all triggers
     * that matched this rule, helping users understand the automation behavior
     * 
     * @param array $payload Enhanced payload with rule data
     * @param EvaluationContext $context Evaluation context
     * @param string $primary_trigger Primary trigger event type
     * @param array $all_triggers All trigger events for this rule
     * @return array Consolidated rule execution component
     */
    private function createConsolidatedRuleExecutionComponent(
        array $payload, 
        EvaluationContext $context, 
        string $primary_trigger,
        array $all_triggers
    ): array {
        $rule_name = $payload['rule_name'] ?? $this->matched_rule_data['rule']->post_title ?? 'unnamed rule';
        $order_id = $payload['order_id'] ?? $context->getOrderId();
        $executed_actions = $payload['executed_actions'] ?? '';
        $execution_status = $payload['execution_status'] ?? 'EXECUTED';
        
        // Generate trigger summary based on event type
        $trigger_summary = $this->getTriggerSummary($primary_trigger, $all_triggers);
        
        // Create comprehensive execution summary
        $execution_summary = self::getExecutionSummary($context, $payload);
        
        // Create proper component label
        $label = sprintf('Rule "%s" evaluated successfully for Order #%d', $rule_name, $order_id);
        
        // Build the comprehensive rule execution component
        $component = [
            'event_type' => 'rule_execution',
            'label' => $label,
            'ts' => microtime(true),
            'level' => 'info',
            'data' => [
                // ==== MAIN BUSINESS DATA (ABOVE THE FOLD) ====
                'event_type' => 'rule_execution',
                'primary_object_type' => 'order',
                'primary_object_id' => $order_id,
                'order_id' => $order_id, // Ensure order_id is in data for renderer
                'rule_name' => $rule_name,
                'rule_id' => $payload['rule_id'] ?? null,
                'execution_summary' => $execution_summary,
                'trigger' => $trigger_summary,
                'actions' => $executed_actions,
                'execution_status' => $execution_status,
                
                // ==== RULE EVALUATION SUMMARY (PRIMARY DISPLAY) ====
                'evaluation_summary' => [
                    'result' => isset($this->matched_rule_data['trace']) ? 
                        count(array_filter($this->matched_rule_data['trace']['conditions'], 
                            fn($c) => $c['result'] === 'pass')) . '/' . 
                        count($this->matched_rule_data['trace']['conditions']) . 
                        ' conditions passed' : '',
                    'logic' => 'ALL conditions must pass', // Could be enhanced to read from rule data
                    'order_status' => $context->order ? ucfirst($context->order->get_status()) : '',
                    'order_total' => isset($payload['amount'], $payload['currency']) ? 
                        strtoupper($payload['currency']) . ' ' . number_format((float)$payload['amount'], 2) : '',
                    'payment_method' => $context->order ? $context->order->get_payment_method_title() : '',
                    'customer_type' => ($context->order && $context->order->get_customer_id() > 0) ? 'Registered' : 'Guest',
                    'event_type' => $primary_trigger,
                    'event_source' => ucfirst($payload['source_gateway'] ?? $context->event->sourceGateway),
                    'event_channel' => ucfirst($payload['channel'] ?? $context->event->channel),
                    'event_time' => date('Y-m-d H:i:s', (int)$context->event->occurredAt),
                    'event_id' => substr($payload['idempotency_key'] ?? '', 0, 15) . '...',
                ],
                
                // ==== TRIGGER DETAILS (SUPPORTING SECTION) ====
                'from_status' => isset($all_triggers[$primary_trigger]['status_from']) ? 
                    ucfirst($all_triggers[$primary_trigger]['status_from']) : '',
                'to_status' => isset($all_triggers[$primary_trigger]['status_to']) ? 
                    ucfirst($all_triggers[$primary_trigger]['status_to']) : '',
                
                // ==== CONDITION DETAILS (SUPPORTING SECTION) ====
                'conditions' => self::formatConditionsForDisplay($this->matched_rule_data['trace']['conditions'] ?? []),
                
                // ==== TECHNICAL EXECUTION DETAILS (BELOW THE FOLD) ====
                'technical_details' => [
                    'rule_id' => $payload['rule_id'] ?? null,
                    'trigger_type' => isset($this->matched_rule_data['rule_data']) ? 
                        $this->matched_rule_data['rule_data']['trigger']['id'] ?? '' : '',
                    'evaluation_time' => $payload['execution_time_ms'] ?? 0,
                    'first_match_wins' => 'Yes',
                    'rule_position' => '#1',
                    'event_idempotency_key' => $payload['idempotency_key'] ?? '',
                    'primary_action_result' => isset($this->matched_rule_data['action_results']['primary']) ? 
                        ucfirst($this->matched_rule_data['action_results']['primary']) : 'Success',
                ],
                
                // Include standard fields for backward compatibility
                'amount' => $payload['amount'] ?? 0,
                'currency' => $payload['currency'] ?? '',
                'processing_result' => $payload['processing_result'] ?? true,
                'customer_id' => $payload['customer_id'] ?? null,
                'transaction_id' => $payload['transaction_id'] ?? '',
                'source_gateway' => $payload['source_gateway'] ?? '',
                'channel' => $payload['channel'] ?? '',
                'execution_time_ms' => $payload['execution_time_ms'] ?? 0,
                'idempotency_key' => $payload['idempotency_key'] ?? '',
                'has_order' => $payload['has_order'] ?? false,
                
                // ==== OTHER TRIGGERS THAT MATCHED (SECONDARY DISPLAY) ====
                'all_matching_triggers' => array_keys($all_triggers),
                'trigger_details' => $all_triggers,
            ],
            // Include the comprehensive rawData for expandable sections
            'rawData' => $payload['rawData'] ?? [],
        ];
        
        return $component;
    }
    
    /**
     * Update an existing rule execution event with additional trigger information
     *

        if (strpos($message_lower, 'duplicate') !== false) {
            return "$gateway processing notice: Duplicate event detected";
        }

        if (strpos($message_lower, 'not found') !== false) {
            return "$gateway processing error: Referenced order not found";
        }

        // Generic fallback for unknown errors
        return "$gateway event processing error: Unable to process event";
    }

    /**
     * Generate user-friendly message based on event type and context
     * 
     * @param string $event_type The event type
     * @param string $gateway The payment gateway name
     * @param int $order_id The order ID
     * @param float $amount The amount
     * @param string $currency The currency
     * @param string $summary The original summary
     * @return string User-friendly message
     */
    private function generateUserFriendlyMessage(string $event_type, string $gateway, int $order_id, float $amount, string $currency, string $summary): string
    {
        // Format amount with currency symbol
        $formatted_amount = $this->formatAmount($amount, $currency);

        // Generate message based on event type and actual payload data
        switch ($event_type) {
            case 'checkout_processed':
                // Check if this is a consolidated view or individual view
                // For now, we'll use the same format for both, but this could be enhanced
                return sprintf('Payment received via %s - %s',
                    $gateway,
                    $formatted_amount
                );

            case 'order_completed':
                return sprintf('Completed - %s processed via %s',
                    $formatted_amount,
                    $gateway
                );

            case 'payment_completed':
                return sprintf('%s payment processed successfully - %s',
                    $gateway,
                    $formatted_amount
                );

            case 'order_status_changed':
                // For order status changes, we need to look at the actual payload data
                // to determine the specific status transition that occurred
                return $this->generateStatusChangeMessage($summary);

            default:
                // Fallback to original format for unknown event types
                if (!empty($summary)) {
                    return "Processed: {$summary}";
                } else {
                    return sprintf('%s %s processed', $gateway, $event_type);
                }
        }
    }
    /**
     * Generate a status change message based on the actual payload data
     * 
     * @param string $summary The original summary from the event
     * @return string Status change message
     */
    private function generateStatusChangeMessage(string $summary): string
    {
        // Extract status change information from the summary or payload
        // The summary might contain information like "Status Changed to Completed"
        // We need to parse this to get the actual status transition

        // Look for patterns in the summary that indicate status changes
        if (strpos($summary, 'Status Changed to') !== false) {
            // Extract the target status
            preg_match('/Status Changed to ([^\s]+)/', $summary, $matches);
            if (!empty($matches[1])) {
                $to_status = $matches[1];

                // Look for "From" pattern to get the source status
                preg_match('/From ([^\s]+) To ([^\s]+)/', $summary, $from_matches);
                if (!empty($from_matches[1]) && !empty($from_matches[2])) {
                    $from_status = $from_matches[1];
                    $to_status = $from_matches[2];
                    return sprintf('Status changed: %s → %s', $from_status, $to_status);
                } else {
                    // If we don't have the "From" information, just show the target status
                    return sprintf('Status changed to %s', $to_status);
                }
            }
        }

        // Fallback: if we can't parse the status change, use a generic message
        return 'Status updated';
    }

    /**
     * Format amount with proper currency symbol
     * 
     * @param float $amount The amount
     * @param string $currency The currency code
     * @return string Formatted amount
     */
    private function formatAmount(float $amount, string $currency): string
    {
        // Simple formatting for now - could be enhanced with proper currency symbols
        switch (strtoupper($currency)) {
            case 'USD':
                return '$' . number_format($amount, 2);
            case 'EUR':
                return '€' . number_format($amount, 2);
            case 'GBP':
                return '£' . number_format($amount, 2);
            default:
                return $currency . ' ' . number_format($amount, 2);
        }
    }
}
