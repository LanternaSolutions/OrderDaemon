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
                // and preserve detailed error information for display
                $validation_error_details = [
                    'original_event_data' => $event_data,
                    'validation_error' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'validation_field' => $this->extractValidationFieldFromError($e->getMessage()),
                    'validation_rule' => $this->extractValidationRuleFromError($e->getMessage()),
                    'event_data_structure' => $this->analyzeEventDataStructure($event_data),
                ];

                $this->logErrorWithDetails('UniversalEvent constructor validation failed: ' . $e->getMessage(), $validation_error_details, $process_id);
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
     * Creates a simple error message by prefixing the original message with
     * the gateway name to provide context.
     * 
     * @param string $technical_message The technical error message
     * @param string $gateway The payment gateway name
     * @return string Business-friendly error message
     */
    public function createBusinessErrorMessage(string $technical_message, string $gateway): string
    {
        // Simple implementation: prefix with gateway name for context
        return "{$gateway} processing error: {$technical_message}";
    }
    
    /**
     * Validate event data for processing
     * 
     * Checks that the event data has all required fields and the correct format.
     * 
     * @param array $event_data Event data to validate
     * @param string $process_id Process ID for logging reference
     * @return bool True if valid, false otherwise
     */
    private function validateEventData(array $event_data, string $process_id): bool
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
            odcm_log_message("ODCM_VALIDATION_DEBUG: Starting validateEventData for process_id: {$process_id}", 'info');
            
            // Add null check before using array_keys
            if (is_array($event_data)) {
                odcm_log_message("ODCM_VALIDATION_DEBUG: Event data keys: " . implode(', ', array_keys($event_data)), 'info');
            } else {
                odcm_log_message("ODCM_VALIDATION_DEBUG: WARNING - event_data is not an array!", 'error');
                return false;
            }
        }

        try {
            foreach ($required_fields as $field) {
                if (!isset($event_data[$field])) {
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        odcm_log_message("ODCM_VALIDATION_DEBUG: FAIL - Missing required field: {$field}", 'error');
                    }
                    return false;
                }
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $value = $event_data[$field];
                    $type = gettype($value);
                    $display_value = is_null($value) ? 'NULL' : (is_string($value) ? "\"{$value}\"" : (is_scalar($value) ? (string) $value : '[complex]'));
                    odcm_log_message("ODCM_VALIDATION_DEBUG: OK - Field '{$field}': {$display_value} (type: {$type})", 'info');
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
        } catch (\Exception $e) {
            // Log any errors that occur during validation
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_VALIDATION_DEBUG: EXCEPTION - {$e->getMessage()}", 'error');
            }
            return false;
        }
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
        
        // Cache miss - query the custom audit log table directly
        // Note: Using esc_sql() for table name as placeholders cannot be used for identifiers
        $audit_log_table = esc_sql($wpdb->prefix . 'odcm_audit_log');
        $twenty_four_hours_ago = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // Check for existing events with this idempotency key in the last 24 hours
        $existing_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$audit_log_table}` 
             WHERE event_type = %s 
             AND idempotency_key = %s 
             AND timestamp > %s",
            'universal_event_processing',
            $event->idempotencyKey,
            $twenty_four_hours_ago
        ));
        
        $is_duplicate = ((int) $existing_count) > 0;
        
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
            $order_id = $context->getOrderId();
            $amount = $event->amount;
            $currency = $event->currency;

            // Create user-friendly message based on event type
            if ($event->eventType === 'order_check_scheduled') {
                $message = "Scheduled check: no rules triggered";
                $short_explanation = "Order Daemon checked Order #{$order_id} but no rules were triggered. This is normal. It is also common when the order has already been processed recently.";

                // Add detailed explanation to payload
                $payload_for_storage['debug_explanation'] = "Order Daemon automatically checks orders to run automation rules. This entry shows a check was performed on Order #{$order_id} (" . $gateway . ", " . $currency . " " . $amount . ") but no rules matched the current order status and conditions. Orders are often checked multiple times against different rules and triggers.";
            } else {
                $message = !empty($summary) ? "No matching rules for: {$summary}" : sprintf('%s %s completed with no matching rules', $gateway, $event->eventType);
            }

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
     * Log error with detailed validation information for display
     * 
     * Enhanced error logging that preserves specific validation error details
     * in the components payload for display in the UI.
     * 
     * @param string $message Error message
     * @param array $context Error context with validation details
     * @param string $process_id Process ID
     * @return void
     */
    private function logErrorWithDetails(string $message, array $context, string $process_id): void
    {
        // Extract detailed validation information
        $validation_error = $context['validation_error'] ?? 'Unknown validation error';
        $validation_field = $context['validation_field'] ?? 'unknown_field';
        $validation_rule = $context['validation_rule'] ?? 'unknown_rule';
        $event_data_structure = $context['event_data_structure'] ?? [];
        $original_event_data = $context['original_event_data'] ?? [];

        // Create a detailed error component for display
        $error_component = $this->createValidationErrorComponent(
            $validation_error,
            $validation_field,
            $validation_rule,
            $event_data_structure,
            $original_event_data
        );

        // Create business-friendly error message
        $gateway = $original_event_data['sourceGateway'] ?? 'Payment gateway';
        $business_message = $this->createBusinessErrorMessage($message, $gateway);

        // Build comprehensive error payload with validation details in components
        $error_payload = [
            'event_type' => $original_event_data['eventType'] ?? 'unknown',
            'source_gateway' => $original_event_data['sourceGateway'] ?? 'unknown',
            'idempotency_key' => $original_event_data['idempotencyKey'] ?? 'unknown',
            'business_error_message' => $business_message,
            'technical_error_message' => $message,
            'error_file' => $context['error_file'] ?? '',
            'error_line' => $context['error_line'] ?? '',
            'execution_time_ms' => round(microtime(true) - $start_time * 1000, 2),
            'event_data_summary' => [
                'event_type' => $original_event_data['eventType'] ?? null,
                'source_gateway' => $original_event_data['sourceGateway'] ?? null,
                'primary_object_type' => $original_event_data['primaryObjectType'] ?? null,
                'primary_object_id' => $original_event_data['primaryObjectID'] ?? null,
            ],
            'validation_details' => [
                'validation_error' => $validation_error,
                'validation_field' => $validation_field,
                'validation_rule' => $validation_rule,
                'event_data_structure_analysis' => $event_data_structure,
            ],
            'components' => [$error_component],
            'rawData' => [
                'original_event_data' => $original_event_data,
                'validation_context' => $context,
            ],
        ];

        \odcm_log_event(
            $business_message,
            $error_payload,
            null,
            'error',
            'universal_event_processor_error',
            false,
            $process_id
        );
    }

    /**
     * Extract validation field from error message
     * 
     * @param string $error_message Error message from exception
     * @return string Extracted field name or 'unknown'
     */
    private function extractValidationFieldFromError(string $error_message): string
    {
        // Common error message patterns and their field extraction
        $patterns = [
            '/Invalid (channel|eventType|primaryObjectType|timestamp):/' => 1,
            '/Invalid ([\w]+) format:/' => 1,
            '/Missing required field: ([\w]+)/' => 1,
            '/Invalid ([\w]+) validation failed\./' => 1,
        ];

        foreach ($patterns as $pattern => $group) {
            if (preg_match($pattern, $error_message, $matches)) {
                return $matches[$group] ?? 'unknown';
            }
        }

        // Try to extract field names from common validation messages
        if (strpos($error_message, 'channel') !== false) {
            return 'channel';
        }
        if (strpos($error_message, 'eventType') !== false) {
            return 'eventType';
        }
        if (strpos($error_message, 'primaryObjectType') !== false) {
            return 'primaryObjectType';
        }
        if (strpos($error_message, 'timestamp') !== false) {
            return 'timestamp';
        }
        if (strpos($error_message, 'idempotencyKey') !== false) {
            return 'idempotencyKey';
        }

        return 'unknown';
    }

    /**
     * Extract validation rule from error message
     * 
     * @param string $error_message Error message from exception
     * @return string Extracted validation rule or 'unknown'
     */
    private function extractValidationRuleFromError(string $error_message): string
    {
        // Common validation rule patterns
        $rules = [
            '/Must be one of: (.*?)$/' => 'Must be one of: $1',
            '/Invalid format: (.*?)$/' => 'Invalid format: $1',
            '/is required/' => 'Field is required',
            '/cannot be empty/' => 'Field cannot be empty',
            '/must be string/' => 'Field must be string',
            '/must be numeric/' => 'Field must be numeric',
            '/must be array/' => 'Field must be array',
        ];

        foreach ($rules as $pattern => $description) {
            if (preg_match($pattern, $error_message, $matches)) {
                if (isset($matches[1])) {
                    return str_replace('$1', $matches[1], $description);
                }
                return $description;
            }
        }

        // Try to identify specific validation rules
        if (strpos($error_message, 'Must be one of:') !== false) {
            return 'Field must be one of allowed values';
        }
        if (strpos($error_message, 'Invalid timestamp format') !== false) {
            return 'Timestamp must be valid ISO8601 format';
        }
        if (strpos($error_message, 'is required') !== false) {
            return 'Field is required but missing';
        }
        if (strpos($error_message, 'cannot be empty') !== false) {
            return 'Field cannot be empty after sanitization';
        }

        return 'Unknown validation rule';
    }

    /**
     * Analyze event data structure for debugging
     * 
     * @param array $event_data Event data to analyze
     * @return array Structure analysis with field types and values
     */
    private function analyzeEventDataStructure(array $event_data): array
    {
        $analysis = [
            'field_count' => count($event_data),
            'field_types' => [],
            'required_fields_present' => [],
            'field_values' => [],
        ];

        // Check required fields
        $required_fields = [
            'eventType',
            'sourceGateway',
            'channel',
            'primaryObjectType',
            'occurredAt',
            'receivedAt',
            'idempotencyKey'
        ];

        foreach ($required_fields as $field) {
            $analysis['required_fields_present'][$field] = isset($event_data[$field]);
            if (isset($event_data[$field])) {
                $analysis['field_types'][$field] = gettype($event_data[$field]);
                $analysis['field_values'][$field] = $this->getSafeFieldValueForDisplay($event_data[$field]);
            }
        }

        // Add validation-specific analysis
        $analysis['validation_issues'] = $this->identifyPotentialValidationIssues($event_data);

        return $analysis;
    }

    /**
     * Get safe field value for display (truncated and sanitized)
     * 
     * @param mixed $value Field value
     * @return string Safe display value
     */
    private function getSafeFieldValueForDisplay($value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            $safe_value = substr($value, 0, 50); // Truncate long strings
            return '"' . esc_html($safe_value) . (strlen($value) > 50 ? '..."' : '"');
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return '[array with ' . count($value) . ' elements]';
        }

        if (is_object($value)) {
            return '[object of class ' . get_class($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }

    /**
     * Identify potential validation issues in event data
     * 
     * @param array $event_data Event data to analyze
     * @return array Potential validation issues found
     */
    private function identifyPotentialValidationIssues(array $event_data): array
    {
        $issues = [];

        // Check for empty strings in required fields
        $required_fields = ['eventType', 'channel', 'primaryObjectType', 'occurredAt', 'receivedAt', 'idempotencyKey'];

        foreach ($required_fields as $field) {
            if (isset($event_data[$field]) && $event_data[$field] === '') {
                $issues[] = "Required field '{$field}' is empty string";
            }
        }

        // Check channel validity
        if (isset($event_data['channel'])) {
            $valid_channels = ['webhook', 'ipn', 'sdk', 'manual', 'system', 'scheduled'];
            if (!in_array($event_data['channel'], $valid_channels)) {
                $issues[] = "Invalid channel value: '{$event_data['channel']}'. Must be one of: " . implode(', ', $valid_channels);
            }
        }

        // Check primaryObjectType validity
        if (isset($event_data['primaryObjectType'])) {
            $valid_object_types = ['order', 'subscription', 'refund', 'authorization', 'membership', 'customer', 'product'];
            if (!in_array($event_data['primaryObjectType'], $valid_object_types)) {
                $issues[] = "Invalid primaryObjectType value: '{$event_data['primaryObjectType']}'. Must be one of: " . implode(', ', $valid_object_types);
            }
        }

        // Check timestamp formats
        if (isset($event_data['occurredAt']) && !empty($event_data['occurredAt'])) {
            if (!$this->isValidTimestampFormat($event_data['occurredAt'])) {
                $issues[] = "Invalid occurredAt timestamp format: '{$event_data['occurredAt']}'";
            }
        }

        if (isset($event_data['receivedAt']) && !empty($event_data['receivedAt'])) {
            if (!$this->isValidTimestampFormat($event_data['receivedAt'])) {
                $issues[] = "Invalid receivedAt timestamp format: '{$event_data['receivedAt']}'";
            }
        }

        return $issues;
    }

    /**
     * Check if timestamp is in valid format
     * 
     * @param string $timestamp Timestamp to check
     * @return bool True if valid format
     */
    private function isValidTimestampFormat(string $timestamp): bool
    {
        // Check if it's a valid ISO8601 timestamp
        try {
            new \DateTime($timestamp);
            return true;
        } catch (\Exception $e) {
            // Not ISO8601, check if it's a numeric Unix timestamp
            return is_numeric($timestamp);
        }
    }

    /**
     * Create validation error component for display
     * 
     * Creates a structured component with detailed validation error information
     * that can be displayed in the UI timeline.
     * 
     * @param string $validation_error The validation error message
     * @param string $validation_field The field that failed validation
     * @param string $validation_rule The validation rule that failed
     * @param array $event_data_structure Event data structure analysis
     * @param array $original_event_data Original event data
     * @return array Validation error component
     */
    private function createValidationErrorComponent(
        string $validation_error,
        string $validation_field,
        string $validation_rule,
        array $event_data_structure,
        array $original_event_data
    ): array {
        // Create user-friendly descriptions
        $field_description = $this->getFieldDescription($validation_field);
        $rule_description = $this->getRuleDescription($validation_rule, $validation_field);

        // Build the validation error component
        $component = [
            'event_type' => 'validation_error',
            'label' => 'Payment Gateway Validation Error',
            'ts' => microtime(true),
            'level' => 'error',
            'data' => [
                'event_type' => 'validation_error',
                'error_summary' => 'Payment gateway processor error: Invalid event data structure',
                'validation_error' => $validation_error,
                'validation_field' => $validation_field,
                'validation_field_description' => $field_description,
                'validation_rule' => $validation_rule,
                'validation_rule_description' => $rule_description,
                'source_gateway' => $original_event_data['sourceGateway'] ?? 'unknown',
                'event_type' => $original_event_data['eventType'] ?? 'unknown',
                'idempotency_key' => $original_event_data['idempotencyKey'] ?? 'unknown',
                'primary_object_type' => $original_event_data['primaryObjectType'] ?? null,
                'primary_object_id' => $original_event_data['primaryObjectID'] ?? null,

                // Detailed validation information
                'validation_details' => [
                    'field_being_validated' => $validation_field,
                    'field_description' => $field_description,
                    'validation_rule' => $validation_rule,
                    'validation_rule_description' => $rule_description,
                    'error_message' => $validation_error,
                    'suggested_fix' => $this->getSuggestedFix($validation_field, $validation_rule),
                ],

                // Event data structure analysis
                'event_data_analysis' => $event_data_structure,

                // Potential issues identified
                'potential_issues' => $event_data_structure['validation_issues'] ?? [],

                // Field values for debugging
                'field_values' => $event_data_structure['field_values'] ?? [],
            ],
            'rawData' => [
                'original_event_data' => $original_event_data,
                'validation_context' => [
                    'validation_error' => $validation_error,
                    'validation_field' => $validation_field,
                    'validation_rule' => $validation_rule,
                ],
            ],
        ];

        return $component;
    }

    /**
     * Get user-friendly field description
     * 
     * @param string $field_name Field name
     * @return string User-friendly description
     */
    private function getFieldDescription(string $field_name): string
    {
        $descriptions = [
            'eventType' => 'Event Type - Identifies the type of event being processed',
            'sourceGateway' => 'Source Gateway - The payment gateway that generated the event',
            'channel' => 'Channel - How the event was received (webhook, IPN, etc.)',
            'primaryObjectType' => 'Primary Object Type - The main entity type this event relates to',
            'primaryObjectID' => 'Primary Object ID - The ID of the main entity',
            'occurredAt' => 'Occurred At - When the event happened at the source',
            'receivedAt' => 'Received At - When the plugin received the event',
            'idempotencyKey' => 'Idempotency Key - Unique identifier for deduplication',
            'timestamp' => 'Timestamp - When the event occurred',
        ];

        return $descriptions[$field_name] ?? "{$field_name} - Event data field";
    }

    /**
     * Get user-friendly rule description
     * 
     * @param string $validation_rule Validation rule
     * @param string $field_name Field name
     * @return string User-friendly description
     */
    private function getRuleDescription(string $validation_rule, string $field_name): string
    {
        if (strpos($validation_rule, 'Must be one of:') !== false) {
            return 'The field must be one of the allowed values';
        }

        if (strpos($validation_rule, 'Invalid format') !== false) {
            return 'The field must be in the correct format';
        }

        if (strpos($validation_rule, 'is required') !== false) {
            return 'This field is mandatory and cannot be missing';
        }

        if (strpos($validation_rule, 'cannot be empty') !== false) {
            return 'This field cannot be empty after processing';
        }

        if (strpos($validation_rule, 'must be string') !== false) {
            return 'This field must be a text value';
        }

        if (strpos($validation_rule, 'must be numeric') !== false) {
            return 'This field must be a number';
        }

        if ($field_name === 'channel') {
            return 'Channel must be one of: webhook, ipn, sdk, manual, system, scheduled';
        }

        if ($field_name === 'primaryObjectType') {
            return 'Primary object type must be one of: order, subscription, refund, authorization, membership, customer, product';
        }

        if ($field_name === 'timestamp' || strpos($validation_rule, 'ISO8601') !== false) {
            return 'Timestamp must be in ISO8601 format (e.g., 2023-01-01T00:00:00+00:00) or Unix timestamp';
        }

        return 'The field value did not pass validation';
    }

    /**
     * Get suggested fix for validation error
     * 
     * @param string $field_name Field name
     * @param string $validation_rule Validation rule
     * @return string Suggested fix
     */
    private function getSuggestedFix(string $field_name, string $validation_rule): string
    {
        if ($field_name === 'channel') {
            return 'Ensure the channel field is set to one of the allowed values: webhook, ipn, sdk, manual, system, scheduled';
        }

        if ($field_name === 'primaryObjectType') {
            return 'Ensure the primaryObjectType field is set to one of the allowed values: order, subscription, refund, authorization, membership, customer, product';
        }

        if ($field_name === 'eventType' && strpos($validation_rule, 'empty') !== false) {
            return 'Ensure the eventType field contains a valid event type string';
        }

        if (strpos($validation_rule, 'timestamp') !== false || strpos($validation_rule, 'ISO8601') !== false) {
            return 'Ensure timestamp fields are in ISO8601 format (e.g., 2023-01-01T00:00:00+00:00) or valid Unix timestamps';
        }

        if (strpos($validation_rule, 'required') !== false) {
            return 'Ensure all required fields are present in the event data';
        }

        if (strpos($validation_rule, 'empty') !== false) {
            return 'Ensure the field contains a valid non-empty value';
        }

        return 'Check the event data structure and ensure all fields conform to the expected format and validation rules';
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
            $total_events = $this->getEventCount('universal_event_processing', $hours, $since);
            // Cache this count for 5 minutes
            wp_cache_set($total_cache_key, $total_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get successful events with caching
        $success_cache_key = 'odcm_events_success_' . $hours;
        $successful_events = wp_cache_get($success_cache_key);
        
        if (false === $successful_events) {
            $successful_events = $this->getEventCountWithStatus('universal_event_processing', 'success', $hours, $since);
            // Cache this count for 5 minutes
            wp_cache_set($success_cache_key, $successful_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get failed events with caching
        $failed_cache_key = 'odcm_events_failed_' . $hours;
        $failed_events = wp_cache_get($failed_cache_key);
        
        if (false === $failed_events) {
            $failed_events = $this->getEventCountWithStatus(['universal_event_processing_error', 'universal_event_processor_error'], null, $hours, $since);
            // Cache this count for 5 minutes
            wp_cache_set($failed_cache_key, $failed_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get duplicate events with caching
        $duplicate_cache_key = 'odcm_events_duplicate_' . $hours;
        $duplicate_events = wp_cache_get($duplicate_cache_key);
        
        if (false === $duplicate_events) {
            $duplicate_events = $this->getEventCount('universal_event_duplicate', $hours, $since);
            // Cache this count for 5 minutes
            wp_cache_set($duplicate_cache_key, $duplicate_events, '', 5 * MINUTE_IN_SECONDS);
        }
        
        // Get events by gateway with caching
        $gateway_cache_key = 'odcm_events_by_gateway_' . $hours;
        $events_by_gateway = wp_cache_get($gateway_cache_key);
        
        if (false === $events_by_gateway) {
            $events_by_gateway = $this->getEventsByGateway($hours, $since);
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
     * Get event count using WordPress recommended methods
     * 
     * @param string|array $event_type Event type(s)
     * @param int $hours Number of hours to look back
     * @param string $since Date string for the start time
     * @return int Event count
     */
    private function getEventCount($event_type, int $hours, string $since): int
    {
        $args = [
            'post_type' => 'odcm_audit_log',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'event_type',
                    'value' => $event_type,
                    'compare' => '='
                ]
            ],
            'date_query' => [
                [
                    'after' => $since,
                    'inclusive' => true
                ]
            ],
            'fields' => 'ids'
        ];
        
        $query = new \WP_Query($args);
        return $query->post_count;
    }
    
    /**
     * Get event count with status filter using WordPress recommended methods
     * 
     * @param string|array $event_type Event type(s)
     * @param string|null $status Status to filter by (null for no status filter)
     * @param int $hours Number of hours to look back
     * @param string $since Date string for the start time
     * @return int Event count
     */
    private function getEventCountWithStatus($event_type, ?string $status, int $hours, string $since): int
    {
        $args = [
            'post_type' => 'odcm_audit_log',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'event_type',
                    'value' => $event_type,
                    'compare' => is_array($event_type) ? 'IN' : '='
                ]
            ],
            'date_query' => [
                [
                    'after' => $since,
                    'inclusive' => true
                ]
            ],
            'fields' => 'ids'
        ];
        
        if ($status !== null) {
            $args['meta_query'][] = [
                'key' => 'status',
                'value' => $status,
                'compare' => '='
            ];
        }
        
        $query = new \WP_Query($args);
        return $query->post_count;
    }
    
    /**
     * Get events by gateway using WordPress recommended methods
     * 
     * @param int $hours Number of hours to look back
     * @param string $since Date string for the start time
     * @return array Events by gateway
     */
    private function getEventsByGateway(int $hours, string $since): array
    {
        $args = [
            'post_type' => 'odcm_audit_log',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'event_type',
                    'value' => 'universal_event_processing',
                    'compare' => '='
                ]
            ],
            'date_query' => [
                [
                    'after' => $since,
                    'inclusive' => true
                ]
            ]
        ];
        
        $query = new \WP_Query($args);
        $events_by_gateway = [];
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $payload = get_post_meta($post_id, 'payload', true);
                
                if (!empty($payload) && is_string($payload)) {
                    $payload_data = json_decode($payload, true);
                    if (isset($payload_data['source_gateway'])) {
                        $gateway = $payload_data['source_gateway'];
                        $events_by_gateway[$gateway] = ($events_by_gateway[$gateway] ?? 0) + 1;
                    }
                }
            }
            
            // Convert to the expected format
            $result = [];
            foreach ($events_by_gateway as $gateway => $count) {
                $result[] = [
                    'gateway' => $gateway,
                    'count' => $count
                ];
            }
            
            // Sort by count descending
            usort($result, function($a, $b) {
                return $b['count'] - $a['count'];
            });
        }
        
        wp_reset_postdata();
        
        return $result;
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
                
                // Log error context without debug_backtrace for production safety
                odcm_log_message("ODCM_DEBUG_TRACE: Invalid Order ID - skipping rule evaluation", 'error');
            }
            
            // Return false to completely skip rule evaluation for invalid order IDs
            return false;
        }

        // Filter rules based on current order status for ALL order-related events
        // This prevents rules with triggers that don't match the current status from executing
        // Example: A rule with "order_processing" trigger should NOT fire when order is "on-hold"
        $current_order_status = $context->order ? $context->order->get_status() : null;
        if ($current_order_status) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Filtering rules for order_check_scheduled event. Current order status: {$current_order_status}", 'debug');
            }

            // Filter rules to only include those whose trigger matches the current order status
            $filtered_rules = [];
            foreach ($rules_query->posts as $rule) {
                $json = get_post_meta((int)$rule->ID, '_odcm_rule_data', true);
                $rule_data = is_string($json) ? json_decode($json, true) : null;

                if (is_array($rule_data) && isset($rule_data['trigger']['id'])) {
                    $trigger_id = $rule_data['trigger']['id'];

                    // Check if this trigger should be allowed for the current order status
                    if ($this->shouldTriggerForStatus($trigger_id, $current_order_status)) {
                        $filtered_rules[] = $rule;
                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Rule '{$rule->post_title}' (ID: {$rule->ID}) ALLOWED for status '{$current_order_status}' with trigger '{$trigger_id}'", 'debug');
                        }
                    } else {
                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Rule '{$rule->post_title}' (ID: {$rule->ID}) SKIPPED - trigger '{$trigger_id}' does not match current status '{$current_order_status}'", 'debug');
                        }
                    }
                }
            }

            // Replace the query results with filtered rules
            if (count($filtered_rules) < count($rules_query->posts)) {
                $original_count = count($rules_query->posts);
                $rules_query->posts = $filtered_rules;
                $rules_query->post_count = count($filtered_rules);
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - Filtered from {$original_count} to " . count($filtered_rules) . " rules that match current status '{$current_order_status}'", 'debug');
                }
            }

            // If no rules match the current status, return early
            if (empty($filtered_rules)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_DEBUG_TRACE: UniversalEventProcessor - No rules match current status '{$current_order_status}' - skipping rule evaluation", 'debug');
                }
                return false;
            }
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
                        sprintf('Rule Executed: %s', $rule->post_title)
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
                        false,
                        $process_id
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
     * Determine if a trigger type should fire for a given order status
     *
     * This method maps trigger types to the order statuses they are designed to match.
     * For example, an "order_processing" trigger should ONLY fire when the order is
     * actually in "processing" status, not when it's in "on-hold" or other statuses.
     *
     * This prevents rules from being incorrectly triggered when an order_check_scheduled
     * event is processed for an order that has been manually changed to a different status.
     *
     * @param string $trigger_id The trigger type ID (e.g., 'order_processing', 'order_completed')
     * @param string $current_status The current WooCommerce order status (without 'wc-' prefix)
     * @return bool True if this trigger should be allowed for the given status
     */
    private function shouldTriggerForStatus(string $trigger_id, string $current_status): bool
    {
        // Map trigger types to their applicable order statuses
        // Each trigger type is designed to fire for specific order statuses
        $trigger_status_map = [
            // "Order Processing" trigger - only fires when order is in "processing" status
            'order_processing' => ['processing'],
            
            // "Order Completed" trigger - only fires when order is in "completed" status
            'order_completed' => ['completed'],
            
            // "Order On Hold" trigger - only fires when order is in "on-hold" status
            'order_on_hold' => ['on-hold'],
            
            // "Order Pending" trigger - only fires when order is in "pending" status
            'order_pending' => ['pending'],
            
            // "Order Failed" trigger - only fires when order is in "failed" status
            'order_failed' => ['failed'],
            
            // "Order Cancelled" trigger - only fires when order is in "cancelled" status
            'order_cancelled' => ['cancelled'],
            
            // "Order Refunded" trigger - only fires when order is in "refunded" status
            'order_refunded' => ['refunded'],
            
            // "Any Status Change" trigger - fires for any status (used for generic automation)
            'order_status_any_change' => [], // Empty array = matches all statuses
        ];

        // Check if this trigger type has a defined status mapping
        if (isset($trigger_status_map[$trigger_id])) {
            $allowed_statuses = $trigger_status_map[$trigger_id];
            
            // Empty array means this trigger matches ALL statuses
            if (empty($allowed_statuses)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_DEBUG_TRACE: shouldTriggerForStatus - Trigger '{$trigger_id}' matches ALL statuses (current: '{$current_status}')", 'debug');
                }
                return true;
            }
            
            // Check if current status is in the allowed list
            $is_allowed = in_array($current_status, $allowed_statuses, true);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $allowed_str = implode(', ', $allowed_statuses);
                $result_str = $is_allowed ? 'ALLOWED' : 'NOT ALLOWED';
                odcm_log_message("ODCM_DEBUG_TRACE: shouldTriggerForStatus - Trigger '{$trigger_id}' {$result_str} for status '{$current_status}' (allowed: {$allowed_str})", 'debug');
            }
            
            return $is_allowed;
        }

        // For unknown trigger types, allow them by default to avoid breaking custom triggers
        // This ensures backward compatibility with custom/third-party triggers
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEBUG_TRACE: shouldTriggerForStatus - Unknown trigger '{$trigger_id}' allowed by default for status '{$current_status}'", 'debug');
        }
        
        return true;
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
            // Add current order status context for events that don't have status transition data
            'current_order_status' => $context->order ? $context->order->get_status() : null,
            'order_status_history' => $this->getOrderStatusHistory($context),
        ];

        self::$rule_trigger_events[$order_id]['events'][$context->event->eventType] = $trigger_data;
    }

    /**
     * Get order status history for context
     *
     * @param EvaluationContext $context
     * @return array
     */
    private function getOrderStatusHistory(EvaluationContext $context): array
    {
        if (!$context->order) {
            return [];
        }

        $history = [];
        $order = $context->order;

        // Get the current status
        $current_status = $order->get_status();

        // Try to get previous status from order meta if available
        $previous_status = null;
        $order_status_changes = get_post_meta($order->get_id(), '_order_status_history', true);

        if (is_array($order_status_changes) && !empty($order_status_changes)) {
            // Get the most recent status change (excluding the current one)
            $recent_changes = array_filter($order_status_changes, function($change) use ($current_status) {
                return isset($change['to']) && $change['to'] !== $current_status;
            });

            if (!empty($recent_changes)) {
                $most_recent = end($recent_changes);
                $previous_status = $most_recent['from'] ?? null;
            }
        }

        return [
            'current_status' => $current_status,
            'previous_status' => $previous_status,
            'status_changes' => $order_status_changes ?? [],
        ];
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
     * Enhanced with improved database lookup, robust caching, and detailed validation
     * across multiple requests for the same rule+order combination.
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param EvaluationContext|null $context Evaluation context (optional)
     * @return array|null Existing event data or null if not found
     */
    private function getExistingRuleExecutionEvent(int $order_id, int $rule_id, ?EvaluationContext $context = null): ?array
    {
        // ENHANCED VALIDATION: Strict check for meaningful order and rule IDs
        if ($order_id <= 0 || $rule_id <= 0) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Invalid parameters passed to getExistingRuleExecutionEvent: order_id={$order_id}, rule_id={$rule_id}", 'warning');
                
                odcm_log_message("ODCM_DEDUP_DEBUG: Invalid parameters - validation failed", 'debug');
            }
            return null;
        }

        // ENHANCED CACHE KEY: More distinct key to prevent collisions
        $cache_key = sprintf('odcm_rule_exec_o%d_r%d', $order_id, $rule_id);

        // Check in-memory cache first for best performance
        if (isset(self::$rule_execution_events[$order_id][$rule_id])) {
            $cached_event = self::$rule_execution_events[$order_id][$rule_id];
            
            // ADDITIONAL VALIDATION: Ensure cache entry has required fields
            if (!isset($cached_event['event_id']) || !isset($cached_event['primary_trigger'])) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_DEDUP_DEBUG: Corrupt cache entry detected in memory cache, refreshing from DB", 'warning');
                }
                // Don't use corrupted cache entries
                unset(self::$rule_execution_events[$order_id][$rule_id]);
            } else {
                return $cached_event;
            }
        }

        // Check persistent cache using WordPress transients
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            // VALIDATION: Check if cached data has expected structure
            if (is_array($cached_data) && isset($cached_data['event_id'])) {
                // Store validated entry in instance variable for this request
                self::$rule_execution_events[$order_id][$rule_id] = $cached_data;
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_DEDUP_DEBUG: Using cached rule execution event for Order #{$order_id}, Rule #{$rule_id}", 'debug');
                }
                
                return $cached_data;
            } else {
                // Invalid cached data, delete it
                delete_transient($cache_key);
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_DEDUP_DEBUG: Found corrupt transient cache entry, deleted it", 'warning');
                }
            }
        }

        // Query the custom audit log table directly
        // Note: Using esc_sql() for table name as placeholders cannot be used for identifiers
        global $wpdb;
        $audit_log_table = esc_sql($wpdb->prefix . 'odcm_audit_log');
        $payload_table = esc_sql($wpdb->prefix . 'odcm_audit_log_payloads');
        $twenty_four_hours_ago = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // Find existing rule execution events for this order+rule combination
        $existing_events = $wpdb->get_results($wpdb->prepare(
            "SELECT l.log_id, l.timestamp, COALESCE(p.payload, l.details) as payload
             FROM `{$audit_log_table}` l
             LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id
             WHERE l.event_type = %s 
             AND l.order_id = %d
             AND l.timestamp > %s
             ORDER BY l.timestamp DESC
             LIMIT 5",
            'rule_execution',
            $order_id,
            $twenty_four_hours_ago
        ), ARRAY_A);
        
        // Filter to find the one matching our rule_id
        $filtered_events = [];
        if (!empty($existing_events)) {
            foreach ($existing_events as $event) {
                $payload_data = json_decode($event['payload'] ?? '', true);
                if (json_last_error() === JSON_ERROR_NONE &&
                    isset($payload_data['rule_id']) &&
                    (int)$payload_data['rule_id'] === $rule_id) {
                    
                    $filtered_events[] = [
                        'log_id' => $event['log_id'],
                        'payload' => $event['payload'],
                        'timestamp' => $event['timestamp']
                    ];
                    break; // We only need the most recent one
                }
            }
        }
        
        $existing_events = $filtered_events;

        // No events found in database
        if (empty($existing_events)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: No existing rule execution event found for Order #{$order_id}, Rule #{$rule_id}", 'debug');
            }
            return null;
        }
        
        // Process the event we found
        $event = $existing_events[0];
        
        // Parse payload with error handling to avoid JSON issues
        $payload = json_decode($event['payload'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Invalid JSON in rule execution event payload (ID: {$event['log_id']}): " . json_last_error_msg(), 'error');
            }
            return null;
        }
        
        // ENHANCED DATA STRUCTURE: More complete event data
        $event_data = [
            'event_id' => $event['log_id'],
            'primary_trigger' => $payload['primary_trigger'] ?? ($context ? $context->event->eventType : ''),
            'all_triggers' => is_array($payload['all_triggers'] ?? null) ? $payload['all_triggers'] : [],
            'process_id' => $payload['process_id'] ?? '',
            'order_id' => $order_id,
            'rule_id' => $rule_id,
            'rule_name' => $payload['rule_name'] ?? '',
            'timestamp' => strtotime($event['timestamp']),
            'last_updated' => time(),
        ];

        // Save complete data to both caches
        self::$rule_execution_events[$order_id][$rule_id] = $event_data;
        set_transient($cache_key, $event_data, HOUR_IN_SECONDS);
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEDUP_DEBUG: Found existing rule execution event in database - Event ID: {$event['log_id']}, Created: {$event['timestamp']}", 'debug');
        }

        return $event_data;
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

        // Get essential rule information
        $rule = $this->matched_rule_data['rule'] ?? null;
        $rule_name = $rule ? $rule->post_title : 'unnamed rule';
        $rule_id_val = $rule ? $rule->ID : $rule_id;

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

        // Build base payload
        $base_payload = $this->buildBasePayload($context);

        // Ensure essential fields are populated (these can be missed in some contexts)
        $base_payload['order_id'] = $order_id;
        $base_payload['rule_id'] = $rule_id_val;
        $base_payload['rule_name'] = $rule_name;

        // Build full rule payload
        $rule_payload = $this->enhancePayloadWithRuleData($base_payload, $context);

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
        
        // DEBUG: Add detailed context for rule event update - helps in debugging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEDUP_DEBUG: Updating rule execution event: event_id={$existing_event['event_id']}, rule_id={$rule_id_val}, order_id={$order_id}", 'debug');
            
            // Log the structure of the payload to ensure it's correct
            $essential_keys = ['rule_id', 'rule_name', 'order_id', 'primary_trigger', 'all_triggers', 'execution_status'];
            $available_keys = array_keys($rule_payload);
            $missing_keys = array_diff($essential_keys, $available_keys);
            
            if (!empty($missing_keys)) {
                odcm_log_message("ODCM_DEDUP_DEBUG: WARNING - Missing keys in update payload: " . implode(', ', $missing_keys), 'warning');
            } else {
                odcm_log_message("ODCM_DEDUP_DEBUG: Update payload complete with all essential keys", 'debug');
            }
        }

        // Update the existing event using WordPress transient API for robustness
        $transient_key = 'odcm_rule_execution_update_' . $existing_event['event_id'];
        $update_data = [
            'event_id' => $existing_event['event_id'],
            'payload' => $rule_payload,
            'timestamp' => current_time('mysql'),
            'order_id' => $order_id,
            'rule_id' => $rule_id_val,
            'process_id' => $process_id,
        ];

        // Store update data in transient for processing
        set_transient($transient_key, $update_data, HOUR_IN_SECONDS);

        // Also trigger immediate update via action hook for real-time processing
        do_action('odcm_update_rule_execution_event', $existing_event['event_id'], $rule_payload);

        // Update our in-memory cache with complete data
        self::$rule_execution_events[$order_id][$rule_id] = [
            'event_id' => $existing_event['event_id'],
            'primary_trigger' => $primary_trigger_event,
            'all_triggers' => $all_triggers,
            'process_id' => $process_id,
            // Add more context to ensure complete data
            'rule_id' => $rule_id_val,
            'rule_name' => $rule_name,
            'order_id' => $order_id,
        ];

        return true;
    }
    
    /**
     * Create a consolidated rule execution event for an order/rule combination
     * 
     * COMPLETELY REFACTORED implementation that ensures:
     * - No "Order #0" issues by using strict validation
     * - No duplicate events by robust check-then-create-or-update pattern
     * - Clear identification of primary trigger event
     * - Proper timeline chronology
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param EvaluationContext $context Current evaluation context
     * @param string $process_id Process ID
     */
    private function createConsolidatedRuleExecutionEvent(int $order_id, int $rule_id, EvaluationContext $context, string $process_id): void
    {
        // === 1. ENHANCED VALIDATION ===
        // Critical validation to prevent "Order #0" issues
        if (!$this->validateRuleExecutionParameters($order_id, $rule_id, $context, $process_id)) {
            return;
        }
        
        // === 2. EVENT TYPE FILTERING ===
        // Only create consolidated events for canonical events to prevent duplicates
        if (!$this->isCanonicalTimelineEvent($context->event->eventType)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Skipping event creation for non-canonical event: {$context->event->eventType}", 'debug');
            }
            return;
        }
        
        // === 3. RULE AND TRIGGER DATA ===
        $rule = $this->matched_rule_data['rule'] ?? null;
        $rule_name = $rule ? $rule->post_title : 'unnamed rule';
        $rule_id_val = $rule ? (int)$rule->ID : $rule_id;
        
        // Get the primary trigger event and collect all trigger events
        $primary_trigger_event = $this->getPrimaryCanonicalEvent($context->event->eventType, $order_id, $rule_id);
        $trigger_events = $this->collectTriggerEvents($order_id, $rule_id);
        
        // === 4. CHECK FOR EXISTING EVENT ===
        $existing_event = $this->getExistingRuleExecutionEvent($order_id, $rule_id, $context);
        
        // If we already have an event for this order+rule, update it
        if ($existing_event) {
            $result = $this->updateExistingRuleExecutionEvent($order_id, $rule_id, $context, $process_id);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $status = $result ? 'SUCCESS' : 'FAILED';
                odcm_log_message("ODCM_DEDUP_DEBUG: {$status} - Updated existing rule execution event for Rule '{$rule_name}' (Order #{$order_id})", 
                    $result ? 'debug' : 'warning');
                
                if ($result) {
                    odcm_log_message("ODCM_DEDUP_DEBUG: Event ID: {$existing_event['event_id']}, Primary trigger: {$primary_trigger_event}", 'debug');
                }
            }
            return;
        }
        
        // === 5. CREATE NEW EVENT ===
        // Build the complete rule execution payload with all relevant data
        $rule_payload = $this->buildRuleExecutionPayload(
            $order_id,
            $rule_id_val,
            $rule_name, 
            $primary_trigger_event, 
            $trigger_events,
            $context,
            $process_id
        );
        
        // === 6. CREATE DATABASE RECORD ===
        // Log the consolidated rule execution event with hierarchy support
        // Use the primary trigger as the parent event type to establish parent-child relationships
        $event_id = \odcm_log_event(
            sprintf('Rule Executed: %s', $rule_name),
            $rule_payload,
            $order_id,
            'success',
            'rule_execution',
            false,
            $process_id,
            $primary_trigger_event // Parent event type for hierarchy visualization
        );
        
        // === 7. CACHE THE RESULT ===
        if ($event_id) {
            $this->cacheRuleExecutionEvent($order_id, $rule_id_val, $event_id, $rule_name, $primary_trigger_event, $trigger_events, $process_id);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: SUCCESS - Created rule execution event for Rule '{$rule_name}' (Order #{$order_id})", 'debug');
                odcm_log_message("ODCM_DEDUP_DEBUG: Event ID: {$event_id}, Primary trigger: {$primary_trigger_event}", 'debug');
            }
        } else {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: ERROR - Failed to create rule execution event!", 'error');
            }
        }
    }
    
    /**
     * Validate parameters for rule execution event creation
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param EvaluationContext $context Evaluation context
     * @param string $process_id Process ID
     * @return bool True if all parameters are valid
     */
    private function validateRuleExecutionParameters(int $order_id, int $rule_id, EvaluationContext $context, string $process_id): bool
    {
        // Validate order ID and rule ID
        if ($order_id <= 0 || $rule_id <= 0) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Invalid IDs - order_id={$order_id}, rule_id={$rule_id}", 'warning');
            }
            return false;
        }
        
        // Validate matched rule data
        if (!$this->matched_rule_data) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Missing matched rule data", 'warning');
            }
            return false;
        }
        
        // Validate process ID format
        $valid_process_id_prefix = 'odcm:lifecycle:';
        if (strpos($process_id, $valid_process_id_prefix) !== 0) {
            // Log warning but don't reject - it's not critical
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Unexpected process ID format: {$process_id}", 'warning');
            }
        }
        
        // Validate context
        if (!$context || !($context instanceof EvaluationContext)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: Invalid context", 'warning');
            }
            return false;
        }
        
        // Validate trigger events
        if (!isset(self::$rule_trigger_events[$order_id]) || 
            !isset(self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id])) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: No trigger events recorded for order_id={$order_id}, rule_id={$rule_id}", 'warning');
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Collect all trigger events that matched this rule
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @return array Array of trigger events keyed by event type
     */
    private function collectTriggerEvents(int $order_id, int $rule_id): array
    {
        $trigger_events = [];
        
        // Sanity check - make sure data structure exists
        if (!isset(self::$rule_trigger_events[$order_id]) || 
            !isset(self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id]) ||
            !is_array(self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id])) {
            return [];
        }
        
        // Collect all trigger events that matched this rule
        foreach (self::$rule_trigger_events[$order_id]['rule_matches'][$rule_id] as $event_type) {
            if (isset(self::$rule_trigger_events[$order_id]['events'][$event_type])) {
                $trigger_events[$event_type] = self::$rule_trigger_events[$order_id]['events'][$event_type];
            }
        }
        
        return $trigger_events;
    }
    
    /**
     * Build the complete rule execution payload
     * 
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param string $rule_name Rule name
     * @param string $primary_trigger_event Primary trigger event type
     * @param array $trigger_events All trigger events
     * @param EvaluationContext $context Evaluation context
     * @param string $process_id Process ID
     * @return array Complete rule execution payload
     */
    private function buildRuleExecutionPayload(int $order_id, int $rule_id, string $rule_name, string $primary_trigger_event, 
                                             array $trigger_events, EvaluationContext $context, string $process_id): array
    {
        // Build base payload
        $base_payload = $this->buildBasePayload($context);
        
        // Add essential fields that must be present
        $base_payload['order_id'] = $order_id;
        $base_payload['rule_id'] = $rule_id;
        $base_payload['rule_name'] = $rule_name;
        
        // Add rule execution data
        $rule_payload = $this->enhancePayloadWithRuleData($base_payload, $context);
        
        // Add event relationships
        $rule_payload['primary_trigger'] = $primary_trigger_event;
        $rule_payload['all_triggers'] = array_keys($trigger_events);
        $rule_payload['trigger_details'] = $trigger_events;
        
        // Create consolidated component
        $rule_component = $this->createConsolidatedRuleExecutionComponent(
            $rule_payload,
            $context,
            $primary_trigger_event,
            $trigger_events
        );
        
        // Add component to payload
        $rule_payload['components'] = [$rule_component];
        
        return $rule_payload;
    }
    
    /**
     * Cache rule execution event data in both memory and persistent cache
     * 
     * Modified to accept both boolean and integer for $event_id to support async
     * logging workflows where odcm_log_event() returns true for queued events
     *
     * @param int $order_id Order ID
     * @param int $rule_id Rule ID
     * @param mixed $event_id Event ID (int) or success indicator (bool)
     * @param string $rule_name Rule name
     * @param string $primary_trigger Primary trigger event type
     * @param array $trigger_events All trigger events
     * @param string $process_id Process ID
     */
    private function cacheRuleExecutionEvent(int $order_id, int $rule_id, $event_id, string $rule_name, 
                                          string $primary_trigger, array $trigger_events, string $process_id): void
    {
        // If event_id is a boolean from async logging, use a composite identifier instead
        if (is_bool($event_id)) {
            // Debug log if we receive a boolean
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_DEDUP_DEBUG: cacheRuleExecutionEvent received boolean instead of ID - using composite identifier", 'debug');
            }
            
            // Use 0 as a sentinel value to indicate this event was queued but doesn't have a real ID yet
            $stored_event_id = 0;
        } else {
            $stored_event_id = $event_id;
        }
        
        // Create event data structure with composite identification
        $event_data = [
            'event_id' => $stored_event_id, 
            'primary_trigger' => $primary_trigger,
            'all_triggers' => array_keys($trigger_events),
            'process_id' => $process_id,
            'rule_id' => $rule_id,
            'rule_name' => $rule_name,
            'order_id' => $order_id,
            'timestamp' => time(),
            'last_updated' => time(),
            // Add composite identification for robust deduplication (helpful for future redesign)
            'composite_id' => sprintf('%d_%d_%s_%d', $order_id, $rule_id, md5($process_id), time())
        ];
        
        // Store in memory cache
        self::$rule_execution_events[$order_id][$rule_id] = $event_data;
        
        // Store in persistent cache with a robust key format
        $cache_key = sprintf('odcm_rule_exec_o%d_r%d', $order_id, $rule_id);
        set_transient($cache_key, $event_data, HOUR_IN_SECONDS);
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
            'execution_time_ms' => round(microtime(true) - ($this->getValidatedRequestTimeFloat() ?? microtime(true)), 2) * 1000,
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
        $execution_summary = $this->getExecutionSummary($context, $payload);

        // Create proper component label
        $label = sprintf('Rule Executed: %s', $rule_name);

        // Get status information with fallback to order context
        $status_info = $this->getStatusInformationForComponent($primary_trigger, $all_triggers, $context);

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
                    'event_time' => gmdate('Y-m-d H:i:s', (int)$context->event->occurredAt),
                    'event_id' => substr($payload['idempotency_key'] ?? '', 0, 15) . '...',
                ],

                // ==== TRIGGER DETAILS (SUPPORTING SECTION) ====
                'from_status' => $status_info['from_status'],
                'to_status' => $status_info['to_status'],
                
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
     * Format conditions for display in UI
     * 
     * @param array $conditions Array of condition results
     * @return array Formatted conditions for display
     */
    private function formatConditionsForDisplay(array $conditions): array
    {
        $formatted = [];
        
        foreach ($conditions as $condition) {
            $formatted[] = [
                'type' => $condition['component_id'] ?? 'unknown',
                'label' => $condition['label'] ?? 'Unknown Condition',
                'result' => strtoupper($condition['result'] ?? 'unknown'),
            ];
        }
        
        return $formatted;
    }
    
    /**
     * Get execution summary for rule
     * 
     * @param EvaluationContext $context Evaluation context
     * @param array $payload Enhanced payload with rule data
     * @return string Execution summary
     */
    private function getExecutionSummary(EvaluationContext $context, array $payload): string
    {
        // If we have status transition information, show that first
        if (isset($context->event->rawData['from_status']) && isset($context->event->rawData['to_status'])) {
            $from = ucfirst($context->event->rawData['from_status']);
            $to = ucfirst($context->event->rawData['to_status']);
            
            return sprintf('Completed Order (status changed from %s → %s)', $from, $to);
        }
        
        // Otherwise, use a generic completion message
        if (isset($context->event->eventType) && $context->event->eventType === 'payment_completed') {
            return 'Completed Order (payment processed successfully)';
        }
        
        return 'Completed Order';
    }
    
    /**
     * Get trigger summary for display in rule execution component
     * 
     * Creates a user-friendly description of what triggered the rule
     * 
     * @param string $primary_trigger The primary trigger event type
     * @param array $all_triggers All trigger events for this rule
     * @return string User-friendly trigger summary
     */
    private function getTriggerSummary(string $primary_trigger, array $all_triggers): string
    {
        // If we have specific data for the primary trigger, use that
        if (isset($all_triggers[$primary_trigger])) {
            $trigger_data = $all_triggers[$primary_trigger];
            
            // Create context-specific trigger descriptions
            switch ($primary_trigger) {
                case 'payment_completed':
                    $gateway = ucfirst($trigger_data['source_gateway'] ?? 'payment gateway');
                    if (!empty($trigger_data['amount']) && !empty($trigger_data['currency'])) {
                        $amount = $this->formatAmount((float)$trigger_data['amount'], $trigger_data['currency']);
                        return "Payment completion ({$gateway}: {$amount})";
                    }
                    return "Payment completion via {$gateway}";
                    
                case 'order_status_changed':
                    $from = ucfirst($trigger_data['status_from'] ?? 'previous status');
                    $to = ucfirst($trigger_data['status_to'] ?? 'new status');
                    return "Status change: {$from} → {$to}";
                    
                case 'checkout_processed':
                    $gateway = ucfirst($trigger_data['source_gateway'] ?? 'payment gateway');
                    return "Checkout completion via {$gateway}";
                    
                case 'order_created':
                    return "Order creation";
            }
        }
        
        // Generic fallback based on event type name
        $event_name = str_replace('_', ' ', $primary_trigger);
        return ucfirst($event_name);
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
     * Get validated REQUEST_TIME_FLOAT value with proper sanitization
     * 
     * Validates, unslashes, and sanitizes $_SERVER['REQUEST_TIME_FLOAT']
     * to prevent security issues and ensure data integrity.
     * 
     * @return float|null Validated request time float or null if invalid
     */
    private function getValidatedRequestTimeFloat(): ?float
    {
        // Check if the server variable exists
        if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return null;
        }

        // Get the raw value
        $raw_value = $_SERVER['REQUEST_TIME_FLOAT'];

        // Apply wp_unslash to remove any magic quotes/slashes
        $unslashed_value = wp_unslash($raw_value);

        // Validate that it's numeric
        if (!is_numeric($unslashed_value)) {
            return null;
        }

        // Convert to float
        $float_value = (float)$unslashed_value;

        // Additional validation: ensure it's a reasonable timestamp
        // Should be within reasonable bounds (current time +/- 1 hour)
        $current_time = microtime(true);
        if ($float_value < ($current_time - 3600) || $float_value > ($current_time + 3600)) {
            return null;
        }

        return $float_value;
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

    /**
     * Get status information for component display
     *
     * Extracts status transition information from event components and context
     * to provide proper timeline display. Handles various event types and
     * provides fallback values when specific status information is not available.
     *
     * @param string $primary_trigger The primary trigger event type
     * @param array $all_triggers All trigger events for this rule
     * @param EvaluationContext $context Evaluation context
     * @return array Status information with 'from_status' and 'to_status' fields
     */
    private function getStatusInformationForComponent(string $primary_trigger, array $all_triggers, EvaluationContext $context): array
    {
        $from_status = null;
        $to_status = null;

        // Try to extract status information from the primary trigger event
        if (isset($all_triggers[$primary_trigger])) {
            $trigger_data = $all_triggers[$primary_trigger];

            // Extract status transition information if available
            if (isset($trigger_data['status_from'])) {
                $from_status = $trigger_data['status_from'];
            }
            if (isset($trigger_data['status_to'])) {
                $to_status = $trigger_data['status_to'];
            }
        }

        // If we don't have status from triggers, try to get it from the event context
        if (($from_status === null || $to_status === null) && $context->event) {
            $event_data = $context->event->rawData ?? [];

            // Check for status transition in event raw data
            if (isset($event_data['from_status']) && $from_status === null) {
                $from_status = $event_data['from_status'];
            }
            if (isset($event_data['to_status']) && $to_status === null) {
                $to_status = $event_data['to_status'];
            }

            // For some event types, we can infer status from other data
            if ($primary_trigger === 'payment_completed' && $to_status === null) {
                $to_status = 'completed';
            }
            if ($primary_trigger === 'checkout_processed' && $to_status === null) {
                $to_status = 'processing';
            }
            if ($primary_trigger === 'order_created' && $to_status === null) {
                $to_status = 'pending';
            }
        }

        // If we still don't have status information, try to get it from the order object
        if (($from_status === null || $to_status === null) && $context->order) {
            $current_status = $context->order->get_status();

            // If we have current status but no to_status, use current status
            if ($to_status === null) {
                $to_status = $current_status;
            }

            // Try to get previous status from order history if available
            if ($from_status === null) {
                $order_status_changes = get_post_meta($context->order->get_id(), '_order_status_history', true);
                if (is_array($order_status_changes) && !empty($order_status_changes)) {
                    $recent_changes = array_filter($order_status_changes, function($change) use ($current_status) {
                        return isset($change['to']) && $change['to'] !== $current_status;
                    });

                    if (!empty($recent_changes)) {
                        $most_recent = end($recent_changes);
                        $from_status = $most_recent['from'] ?? null;
                    }
                }
            }
        }

        // Provide sensible defaults if we still don't have status information
        if ($from_status === null) {
            $from_status = 'unknown';
        }
        if ($to_status === null) {
            $to_status = $context->order ? $context->order->get_status() : 'unknown';
        }

        // Debug logging for status extraction
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_STATUS_DEBUG: Extracted status information for component display", 'debug');
            odcm_log_message("ODCM_STATUS_DEBUG: - Primary trigger: {$primary_trigger}", 'debug');
            odcm_log_message("ODCM_STATUS_DEBUG: - From status: {$from_status}", 'debug');
            odcm_log_message("ODCM_STATUS_DEBUG: - To status: {$to_status}", 'debug');
        }

        return [
            'from_status' => $from_status,
            'to_status' => $to_status,
        ];
    }
}
