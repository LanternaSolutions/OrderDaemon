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
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM_PROCESS_ID_DEBUG: UniversalEventProcessor received event data:");
            error_log("ODCM_PROCESS_ID_DEBUG: - primaryObjectType: " . ($event_data['primaryObjectType'] ?? 'MISSING'));
            error_log("ODCM_PROCESS_ID_DEBUG: - primaryObjectID: " . ($event_data['primaryObjectID'] ?? 'MISSING') . " (type: " . gettype($event_data['primaryObjectID'] ?? null) . ")");
            error_log("ODCM_PROCESS_ID_DEBUG: - eventType: " . ($event_data['eventType'] ?? 'MISSING'));
            error_log("ODCM_PROCESS_ID_DEBUG: - idempotencyKey: " . ($event_data['idempotencyKey'] ?? 'MISSING'));
        }
        
        // Use shared process_id for order lifecycle events, random for others
        $order_id = isset($event_data['primaryObjectID']) && $event_data['primaryObjectType'] === 'order' 
            ? (int) $event_data['primaryObjectID'] 
            : 0;
        
        // DEBUG: Log the process_id assignment logic
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM_PROCESS_ID_DEBUG: Process ID assignment logic:");
            error_log("ODCM_PROCESS_ID_DEBUG: - Extracted order_id: $order_id");
            error_log("ODCM_PROCESS_ID_DEBUG: - primaryObjectType check: " . ($event_data['primaryObjectType'] === 'order' ? 'PASS' : 'FAIL'));
            error_log("ODCM_PROCESS_ID_DEBUG: - primaryObjectID isset: " . (isset($event_data['primaryObjectID']) ? 'YES' : 'NO'));
            if (isset($event_data['primaryObjectID'])) {
                error_log("ODCM_PROCESS_ID_DEBUG: - primaryObjectID value: " . $event_data['primaryObjectID']);
                error_log("ODCM_PROCESS_ID_DEBUG: - primaryObjectID > 0: " . ($event_data['primaryObjectID'] > 0 ? 'YES' : 'NO'));
            }
        }
            
        if ($order_id > 0) {
            // Use shared process_id for proper order consolidation
            $process_id = ProcessIdManager::instance()->get_or_create_process_id($order_id);
            
            // DEBUG: Log successful shared process_id assignment
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_PROCESS_ID_DEBUG: Using SHARED process_id for order #{$order_id}: $process_id");
            }
        } else {
            // Use unique process_id for non-order events
            $process_id = 'odcm_universal_' . uniqid();
            
            // DEBUG: Log unique process_id assignment (this is the problem case)
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_PROCESS_ID_DEBUG: Using UNIQUE process_id (NOT CONSOLIDATED): $process_id");
                error_log("ODCM_PROCESS_ID_DEBUG: This event will appear OUTSIDE the consolidated timeline!");
            }
        }

        try {
            // Validate event data
            if (!$this->validateEventData($event_data)) {
                $this->logError('Invalid event data structure', $event_data, $process_id);
                return false;
            }

            // Create UniversalEvent object from data
            $universal_event = new UniversalEvent($event_data);

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
     * Validate event data structure
     * 
     * @param array $event_data Event data to validate
     * @return bool True if valid
     */
    private function validateEventData(array $event_data): bool
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

        foreach ($required_fields as $field) {
            if (!isset($event_data[$field])) {
                return false;
            }
        }

        // Validate event type format
        if (!is_string($event_data['eventType']) || empty($event_data['eventType'])) {
            return false;
        }

        // Validate idempotency key
        if (!is_string($event_data['idempotencyKey']) || empty($event_data['idempotencyKey'])) {
            return false;
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

        // Check for existing log entries with the same idempotency key
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
             WHERE event_type = 'universal_event_processing' 
             AND idempotency_key = %s
             AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $event->idempotencyKey
        ));

        return (int) $existing > 0;
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
            $message = !empty($summary) ? "Processed: {$summary}" : sprintf('%s %s processed', $gateway, $event->eventType);
            
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
        
        // SECOND: Only create rule execution events for canonical triggers to prevent duplicates
        if ($result && $is_canonical_rule_event && $this->matched_rule_data) {
            // Enhance payload with rule execution data
            $rule_payload = $this->enhancePayloadWithRuleData($payload_for_storage, $context);
            
            // Create rule_execution component
            $rule_execution_component = $this->createRuleExecutionComponent($rule_payload, $context);
            
            // Create separate payload for rule execution event
            $rule_payload['components'] = [$rule_execution_component];
            
            // Log rule execution event
            $rule_name = $this->matched_rule_data['rule']->post_title ?? 'unnamed rule';
            $rule_message = sprintf('Rule "%s" evaluated successfully for Order #%d', $rule_name, $context->getOrderId());
    
            \odcm_log_event(
                $rule_message,
                $rule_payload,
                $context->getOrderId(),
                'success',
                'rule_execution',
                false,
                $process_id
            );
        } else if ($result && !$is_canonical_rule_event && defined('ODCM_DEBUG') && ODCM_DEBUG) {
            // Log rule evaluation for non-canonical events only in debug mode
            $rule_name = $this->matched_rule_data['rule']->post_title ?? 'unnamed rule';
            \odcm_log_event(
                sprintf('Rule "%s" evaluated for non-canonical event: %s', $rule_name, $event->eventType),
                [
                    'event_type' => $event->eventType,
                    'rule_name' => $rule_name,
                    'canonical_event' => false,
                    'timeline_event_suppressed' => true,
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
    public function getProcessingStats(int $hours = 24): array
    {
        global $wpdb;

        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        // Get total events processed
        $total_events = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
             WHERE event_type = 'universal_event_processing' 
             AND timestamp >= %s",
            $since
        ));

        // Get successful events
        $successful_events = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
             WHERE event_type = 'universal_event_processing' 
             AND status = 'success'
             AND timestamp >= %s",
            $since
        ));

        // Get failed events
        $failed_events = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
             WHERE event_type IN ('universal_event_processing_error', 'universal_event_processor_error')
             AND timestamp >= %s",
            $since
        ));

        // Get duplicate events
        $duplicate_events = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log 
             WHERE event_type = 'universal_event_duplicate'
             AND timestamp >= %s",
            $since
        ));

        // Get events by gateway
        $events_by_gateway = $wpdb->get_results($wpdb->prepare(
            "SELECT JSON_EXTRACT(payload, '$.source_gateway') as gateway, COUNT(*) as count
             FROM {$wpdb->prefix}odcm_audit_log 
             WHERE event_type = 'universal_event_processing'
             AND timestamp >= %s
             GROUP BY gateway
             ORDER BY count DESC",
            $since
        ), 'ARRAY_A');

        return [
            'period_hours' => $hours,
            'total_events' => (int) $total_events,
            'successful_events' => (int) $successful_events,
            'failed_events' => (int) $failed_events,
            'duplicate_events' => (int) $duplicate_events,
            'success_rate' => $total_events > 0 ? round(($successful_events / $total_events) * 100, 2) : 0,
            'events_by_gateway' => $events_by_gateway ?: [],
        ];
    }

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
            error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Processing rules for Order #{$order_id}");
        }
        
        // Check if post type exists
        if (!post_type_exists('odcm_order_rule')) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Order rule post type does not exist!");
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
            error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Found " . $rules_query->found_posts . " published rules");
        }

        if (!$rules_query->have_posts()) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - No rules found, returning false");
            }
            return false;
        }

        // CANONICAL TIMELINE EVENT LOGIC
        // Only create ProcessLogger timeline events for the canonical rule evaluation trigger.
        // This prevents duplicate timeline events while preserving all rule evaluation functionality.
        $is_canonical_timeline_event = $this->isCanonicalTimelineEvent($context->event->eventType);
        
        // ProcessLogger is only created for canonical events (order_status_changed)
        // This ensures a single source of truth in the timeline while preserving rule logic
        $rule_logger = null;
        if ($is_canonical_timeline_event) {
            // Set universal event context to prevent ProcessLogger from creating timeline events
            // ProcessLogger will still provide process_id infrastructure but won't duplicate timeline events
            \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger::set_universal_event_context(true);
            
            $rule_logger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger();
            $rule_logger->start('rule_execution', [
                'order_id' => $order_id,
                'summary' => 'Universal event rule processing',
                'event_type' => $context->event->eventType,
                'source_gateway' => $context->event->sourceGateway,
            ]);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Created ProcessLogger for CANONICAL event: {$context->event->eventType}");
            }
        } else {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Skipping ProcessLogger for non-canonical event: {$context->event->eventType}");
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
                error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Evaluating rule '{$rule->post_title}' (ID: {$rule->ID}) for event type: {$context->event->eventType}");
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

                // FINISH ProcessLogger to create rule execution event (only for canonical events)
                if ($rule_logger) {
                    $rule_logger->finish(
                        'success',
                        sprintf('Rule "%s" evaluated successfully for Order #%d', $rule->post_title, $context->getOrderId())
                    );
                }

                // RESET: Clear universal event context flag after successful rule processing completes
                if ($is_canonical_timeline_event) {
                    \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger::set_universal_event_context(false);
                    
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Reset universal_event_context flag after successful rule execution");
                    }
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
                    error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Rule '{$rule->post_title}' didn't match - " . 
                              "Event type: {$context->event->eventType}, Order ID: {$context->getOrderId()}");
                    
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
        if ($rule_logger) {
            $rule_logger->finish(
                'debug',
                sprintf('No matching rules found for Order #%d (event: %s)', $context->getOrderId(), $context->event->eventType)
            );
        }

        // RESET: Clear universal event context flag after rule processing completes
        if ($is_canonical_timeline_event) {
            \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger::set_universal_event_context(false);
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_DEBUG_TRACE: UniversalEventProcessor - Reset universal_event_context flag after rule processing");
            }
        }

        return false; // No rules matched
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
     * Determine if this event type should create timeline events
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
        // These represent the most accurate business decision points where automation takes effect
        $canonical_timeline_events = [
            'order_status_changed',  // When rules actually change order status (most accurate timing)
        ];
        
        // NON-CANONICAL EVENTS (rule evaluation only, no timeline events)
        // These should explicitly only trigger rule evaluation, or they'll generate duplicates
        $non_canonical_events = [
            'checkout_processed',      // Duplicates order_status_changed
            'order_created',          // Duplicates order_status_changed  
            'payment_completed',      // Duplicates order_status_changed
            'order_check_scheduled',  // Internal scheduling, not business-relevant
        ];
        
        // Explicit canonical events always create timeline events
        if (in_array($event_type, $canonical_timeline_events)) {
            return true;
        }
        
        // Known non-canonical events never create timeline events (deduplication)
        if (in_array($event_type, $non_canonical_events)) {
            return false;
        }
        
        // SAFETY FALLBACK: Unknown events default to creating timeline events
        // This ensures new event types aren't accidentally hidden from the timeline
        return true;
    }

    /**
     * Create a rule_execution component for timeline display
     * 
     * @param array $payload_for_storage Enhanced payload with rule data
     * @param EvaluationContext $context Evaluation context
     * @return array Rule execution component
     */
    private function createRuleExecutionComponent(array $payload_for_storage, EvaluationContext $context): array
    {
        $rule_name = $payload_for_storage['rule_name'] ?? 'unnamed rule';
        $order_id = $payload_for_storage['order_id'] ?? $context->getOrderId();
        $executed_actions = $payload_for_storage['executed_actions'] ?? '';
        $execution_status = $payload_for_storage['execution_status'] ?? 'EXECUTED';
        
        // Create proper component label
        $label = sprintf('Rule "%s" evaluated successfully for Order #%d', $rule_name, $order_id);
        
        return [
            'event_type' => 'rule_execution',
            'label' => $label,
            'ts' => microtime(true),
            'level' => 'info',
            'data' => [
                'event_type' => 'rule_execution',
                'primary_object_type' => 'order',
                'primary_object_id' => $order_id,
                'order_id' => $order_id, // Ensure order_id is in data for renderer
                'rule_name' => $rule_name,
                'rule_id' => $payload_for_storage['rule_id'] ?? null,
                'executed_actions' => $executed_actions,
                'execution_status' => $execution_status,
                'amount' => $payload_for_storage['amount'] ?? 0,
                'currency' => $payload_for_storage['currency'] ?? '',
                'processing_result' => $payload_for_storage['processing_result'] ?? true,
                'customer_id' => $payload_for_storage['customer_id'] ?? null,
                'transaction_id' => $payload_for_storage['transaction_id'] ?? '',
                'source_gateway' => $payload_for_storage['source_gateway'] ?? '',
                'channel' => $payload_for_storage['channel'] ?? '',
                'execution_time_ms' => $payload_for_storage['execution_time_ms'] ?? 0,
                'idempotency_key' => $payload_for_storage['idempotency_key'] ?? '',
                'has_order' => $payload_for_storage['has_order'] ?? false,
            ],
            // Include the comprehensive rawData for expandable sections
            'rawData' => $payload_for_storage['rawData'] ?? [],
        ];
    }

    /**
     * Create business-friendly error message from technical error
     * 
     * @param string $technical_message The technical error message
     * @param string $gateway The payment gateway name
     * @return string Business-friendly error message
     */
    private function createBusinessErrorMessage(string $technical_message, string $gateway): string
    {
        $message_lower = strtolower($technical_message);
        
        // Map common technical errors to business-friendly messages
        if (strpos($message_lower, 'invalid arguments') !== false) {
            return "$gateway event processing error: Missing required data";
        }
        
        if (strpos($message_lower, 'authentication') !== false || strpos($message_lower, 'unauthorized') !== false) {
            return "$gateway authentication error: Unable to verify event authenticity";
        }
        
        if (strpos($message_lower, 'timeout') !== false || strpos($message_lower, 'connection') !== false) {
            return "$gateway connection error: Network communication failed";
        }
        
        if (strpos($message_lower, 'database') !== false) {
            return "$gateway processing error: Data storage issue";
        }
        
        if (strpos($message_lower, 'validation') !== false) {
            return "$gateway validation error: Event data format issue";
        }
        
        if (strpos($message_lower, 'duplicate') !== false) {
            return "$gateway processing notice: Duplicate event detected";
        }
        
        if (strpos($message_lower, 'not found') !== false) {
            return "$gateway processing error: Referenced order not found";
        }
        
        // Generic fallback for unknown errors
        return "$gateway event processing error: Unable to process event";
    }
}
