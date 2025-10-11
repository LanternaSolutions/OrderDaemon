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
     * Constructor
     */
    private function __construct()
    {
        $this->registry = new RuleComponentRegistry();
        $this->evaluator = new Evaluator();
        
        // Inject ProcessLogger into Evaluator for audit trail creation
        $sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
        $process_logger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger($sanitizer);
        $this->evaluator->set_process_logger($process_logger);
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
        
        // Use shared process_id for order lifecycle events, random for others
        $order_id = isset($event_data['primaryObjectID']) && $event_data['primaryObjectType'] === 'order' 
            ? (int) $event_data['primaryObjectID'] 
            : 0;
            
        if ($order_id > 0) {
            // Use shared process_id for proper order consolidation
            $process_id = ProcessIdManager::instance()->get_or_create_process_id($order_id);
        } else {
            // Use unique process_id for non-order events
            $process_id = 'odcm_universal_' . uniqid();
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
             AND JSON_EXTRACT(payload, '$.idempotency_key') = %s
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
            'info',
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
        $status = $result ? 'success' : 'info';
        
        // Use the event's getSummary() method for better user-facing descriptions
        $summary = $event->getSummary();
        $gateway = $event->sourceGateway ? ucfirst($event->sourceGateway) : 'Payment gateway';
        $message = $result 
            ? (!empty($summary) ? "Successfully processed: {$summary}" : sprintf('%s %s processed successfully', $gateway, $event->eventType))
            : (!empty($summary) ? "No matching rules for: {$summary}" : sprintf('%s %s completed with no matching rules', $gateway, $event->eventType));

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
                'processing_result' => $result,
                'execution_time_ms' => round($execution_time * 1000, 2),
                'has_order' => $context->order !== null,
                'has_subscription' => $context->subscription !== null,
                'customer_id' => $context->getCustomerId(),
            ],
            $context->getOrderId(),
            $status,
            'universal_event_processing',
            false,
            $process_id
        );
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
        // Check if post type exists
        if (!post_type_exists('odcm_order_rule')) {
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

        if (!$rules_query->have_posts()) {
            return false;
        }

        // Process rules with First Match Wins logic
        foreach ($rules_query->posts as $rule) {
            // Load rule JSON data
            $json = get_post_meta((int)$rule->ID, '_odcm_rule_data', true);
            $rule_data = is_string($json) ? json_decode($json, true) : null;
            
            if (!is_array($rule_data)) {
                continue;
            }

            // Start ProcessLogger for this rule evaluation
            $sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
            $rule_logger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger($sanitizer);
            $this->evaluator->set_process_logger($rule_logger);
            
            $rule_logger->start('rule_execution', [
                'order_id' => $context->getOrderId(),
                'summary' => sprintf('Evaluating rule: %s', $rule->post_title),
                'source' => 'universal_event_processor'
            ]);

            // Evaluate rule against universal event context
            $trace = $this->evaluator->evaluateRuleAgainstUniversalEvent($context, $rule_data, $this->registry);

            if ($trace['matched']) {
                // Log rule match
                $rule_logger->add_component('rule_matched', 
                    sprintf('Rule "%s" matched', $rule->post_title), 
                    ['rule_id' => $rule->ID, 'rule_name' => $rule->post_title]
                );

                // Execute primary action
                if (isset($rule_data['primaryAction']['id'])) {
                    $rule_logger->add_component('action_executed', 
                        sprintf('Executing primary action: %s', $rule_data['primaryAction']['id']), 
                        ['action_id' => $rule_data['primaryAction']['id']]
                    );
                    $this->executeUniversalEventAction($context, $rule_data['primaryAction']);
                }

                // Execute secondary actions
                if (!empty($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
                    foreach ($rule_data['secondaryActions'] as $actionDef) {
                        if (isset($actionDef['id'])) {
                            $rule_logger->add_component('action_executed', 
                                sprintf('Executing secondary action: %s', $actionDef['id']), 
                                ['action_id' => $actionDef['id']]
                            );
                            $this->executeUniversalEventAction($context, $actionDef);
                        }
                    }
                }

                // Finish with success
                $rule_logger->finish('success', sprintf('Rule "%s" executed successfully', $rule->post_title));

                // First Match Wins - stop processing
                return true;
            } else {
                // Log rule non-match
                $rule_logger->add_component('rule_no_match', 
                    sprintf('Rule "%s" did not match', $rule->post_title), 
                    ['rule_id' => $rule->ID, 'rule_name' => $rule->post_title, 'conditions' => $trace['conditions']]
                );
                
                // Finish with info status
                $rule_logger->finish('info', sprintf('Rule "%s" did not match conditions', $rule->post_title));
            }
        }

        return false; // No rules matched
    }

    /**
     * Execute action component for universal event context
     * 
     * @param EvaluationContext $context Universal event context
     * @param array $actionDef Action definition
     * @return void
     */
    private function executeUniversalEventAction(EvaluationContext $context, array $actionDef): void
    {
        $id = isset($actionDef['id']) && is_string($actionDef['id']) ? $actionDef['id'] : '';
        if ($id === '') {
            return;
        }

        $actions = $this->registry->get_actions();
        if (!isset($actions[$id])) {
            return;
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
            } elseif (method_exists($component, 'executeUniversalEvent')) {
                // Universal event-aware action
                $component->executeUniversalEvent($context, $clean);
            } else {
                // Fallback: try with order if available
                if ($context->order) {
                    $component->execute($context->order, $clean);
                }
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
        }
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
