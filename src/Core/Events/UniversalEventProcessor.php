<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

use OrderDaemon\CompletionManager\Core\Executor;

/**
 * Universal Event Processor
 * 
 * Handles Action Scheduler processing of universal events through the rule engine.
 * This is the bridge between the universal event system and the existing rule
 * processing infrastructure, enabling event-driven automation.
 * 
 * Processing Flow:
 * 1. Deserialize UniversalEvent from Action Scheduler
 * 2. Resolve entity relationships (load WC_Order, WC_Subscription, etc.)
 * 3. Create EvaluationContext with event + entities + customer data
 * 4. Execute rule evaluation via existing Executor
 * 5. Log results to audit trail with universal event metadata
 * 
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   2.2.0
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
     * Process universal event from Action Scheduler
     * 
     * This is the main entry point for universal event processing.
     * Called by Action Scheduler with serialized event data.
     * 
     * @param array $event_data Serialized UniversalEvent data
     * @return bool Success status
     */
    public function processEvent(array $event_data): bool
    {
        $start_time = microtime(true);
        $process_id = $event_data['process_id'] ?? 'odcm_universal_' . uniqid();

        try {
            // Deserialize UniversalEvent
            $event = $this->deserializeEvent($event_data);
            if (!$event || !$event->isValid()) {
                error_log('ODCM Universal Event Processor: Invalid event data');
                return false;
            }

            // Check idempotency to prevent duplicate processing
            if ($this->isAlreadyProcessed($event)) {
                error_log('ODCM Universal Event Processor: Event already processed (idempotency): ' . $event->idempotencyKey);
                return true; // Return true since it was already processed successfully
            }

            // Create evaluation context
            $context = $this->createEvaluationContext($event);
            if (!$context) {
                error_log('ODCM Universal Event Processor: Failed to create evaluation context');
                return false;
            }

            // Execute rule processing
            $result = $this->executeRuleProcessing($context, $process_id);

            // Mark as processed for idempotency
            $this->markAsProcessed($event);

            // Log processing completion
            $execution_time = microtime(true) - $start_time;
            $this->logProcessingResult($event, $result, $execution_time, $process_id);

            return $result;

        } catch (\Throwable $e) {
            $execution_time = microtime(true) - $start_time;
            $this->logProcessingError($event_data, $e, $execution_time, $process_id);
            return false;
        }
    }

    /**
     * Deserialize event data into UniversalEvent object
     * 
     * @param array $event_data
     * @return UniversalEvent|null
     */
    private function deserializeEvent(array $event_data): ?UniversalEvent
    {
        try {
            // Remove process_id from event data before deserializing
            $clean_data = $event_data;
            unset($clean_data['process_id']);

            return UniversalEvent::fromArray($clean_data);
        } catch (\Throwable $e) {
            error_log('ODCM Universal Event Processor: Deserialization error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create evaluation context from universal event
     * 
     * @param UniversalEvent $event
     * @return EvaluationContext|null
     */
    private function createEvaluationContext(UniversalEvent $event): ?EvaluationContext
    {
        try {
            $order = null;
            $subscription = null;
            $customer = null;

            // Load WooCommerce order if available
            if ($event->primaryObjectType === 'order' && $event->primaryObjectID) {
                $order = wc_get_order($event->primaryObjectID);
                if (!$order) {
                    error_log('ODCM Universal Event Processor: Order not found: ' . $event->primaryObjectID);
                }
            } elseif ($event->secondaryObjectType === 'order' && $event->secondaryObjectID) {
                $order = wc_get_order($event->secondaryObjectID);
            }

            // Load WooCommerce subscription if available
            if ($event->primaryObjectType === 'subscription' && $event->primaryObjectID) {
                $subscription = $this->loadSubscription($event->primaryObjectID);
                if (!$subscription) {
                    error_log('ODCM Universal Event Processor: Subscription not found: ' . $event->primaryObjectID);
                }
            } elseif ($event->secondaryObjectType === 'subscription' && $event->secondaryObjectID) {
                $subscription = $this->loadSubscription($event->secondaryObjectID);
            }

            // Load customer/user
            if ($event->primaryObjectType === 'customer' && $event->primaryObjectID) {
                $customer = get_user_by('id', $event->primaryObjectID);
            } elseif ($order && $order->get_customer_id()) {
                $customer = get_user_by('id', $order->get_customer_id());
            } elseif ($subscription && method_exists($subscription, 'get_customer_id')) {
                $customer = get_user_by('id', $subscription->get_customer_id());
            }

            // Create gateway metadata
            $gateway_metadata = $this->createGatewayMetadata($event, $order, $subscription);

            return new EvaluationContext(
                $event,
                $order,
                $subscription,
                $customer,
                $gateway_metadata
            );

        } catch (\Throwable $e) {
            error_log('ODCM Universal Event Processor: Context creation error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Execute rule processing through existing Executor
     * 
     * @param EvaluationContext $context
     * @param string $process_id
     * @return bool
     */
    private function executeRuleProcessing(EvaluationContext $context, string $process_id): bool
    {
        try {
            // Get Executor instance
            $executor = Executor::instance();

            // Process through universal event method
            return $executor->process_universal_event($context, $process_id);

        } catch (\Throwable $e) {
            error_log('ODCM Universal Event Processor: Rule execution error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if event has already been processed (idempotency)
     * 
     * @param UniversalEvent $event
     * @return bool
     */
    private function isAlreadyProcessed(UniversalEvent $event): bool
    {
        $key = 'odcm_processed_' . md5($event->idempotencyKey);
        return get_transient($key) !== false;
    }

    /**
     * Mark event as processed for idempotency
     * 
     * @param UniversalEvent $event
     * @return void
     */
    private function markAsProcessed(UniversalEvent $event): void
    {
        $key = 'odcm_processed_' . md5($event->idempotencyKey);
        // Store for 24 hours to prevent duplicate processing
        set_transient($key, time(), 24 * HOUR_IN_SECONDS);
    }

    /**
     * Load WooCommerce subscription
     * 
     * @param int $subscription_id
     * @return object|null
     */
    private function loadSubscription(int $subscription_id): ?object
    {
        // Check if WooCommerce Subscriptions is active
        if (!function_exists('wcs_get_subscription')) {
            return null;
        }

        try {
            $subscription = wcs_get_subscription($subscription_id);
            return $subscription ?: null;
        } catch (\Throwable $e) {
            error_log('ODCM Universal Event Processor: Subscription load error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create gateway metadata for context
     * 
     * @param UniversalEvent $event
     * @param object|null $order
     * @param object|null $subscription
     * @return array
     */
    private function createGatewayMetadata(UniversalEvent $event, ?object $order, ?object $subscription): array
    {
        $metadata = [
            'gateway' => $event->sourceGateway,
            'channel' => $event->channel,
            'transaction_id' => $event->transactionID,
            'event_type' => $event->eventType,
            'status' => $event->status,
            'reason' => $event->reason,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'occurred_at' => $event->occurredAt,
            'received_at' => $event->receivedAt,
        ];

        // Add order-specific gateway data
        if ($order) {
            $metadata['order_payment_method'] = $order->get_payment_method();
            $metadata['order_payment_method_title'] = $order->get_payment_method_title();
            $metadata['order_transaction_id'] = $order->get_transaction_id();
        }

        // Add subscription-specific gateway data
        if ($subscription && method_exists($subscription, 'get_payment_method')) {
            $metadata['subscription_payment_method'] = $subscription->get_payment_method();
            $metadata['subscription_payment_method_title'] = $subscription->get_payment_method_title();
        }

        return $metadata;
    }

    /**
     * Log successful processing result
     * 
     * @param UniversalEvent $event
     * @param bool $result
     * @param float $execution_time
     * @param string $process_id
     * @return void
     */
    private function logProcessingResult(UniversalEvent $event, bool $result, float $execution_time, string $process_id): void
    {
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log(sprintf(
                'ODCM Universal Event Processor: %s processing %s event %s (%.3fs)',
                $result ? 'Successfully' : 'Failed',
                $event->sourceGateway ?? 'system',
                $event->eventType,
                $execution_time
            ));
        }

        // Log to audit trail if available
        if (function_exists('odcm_log_custom_event')) {
            $status = $result ? 'success' : 'error';
            $summary = sprintf(
                'Universal event processed: %s %s',
                $event->sourceGateway ?? 'system',
                $event->eventType
            );

            $details = [
                'event_type' => $event->eventType,
                'source_gateway' => $event->sourceGateway,
                'channel' => $event->channel,
                'transaction_id' => $event->transactionID,
                'primary_object_type' => $event->primaryObjectType,
                'primary_object_id' => $event->primaryObjectID,
                'secondary_object_type' => $event->secondaryObjectType,
                'secondary_object_id' => $event->secondaryObjectID,
                'idempotency_key' => $event->idempotencyKey,
                'execution_time' => $execution_time,
                'processing_result' => $result,
            ];

            odcm_log_custom_event(
                $summary,
                $details,
                $event->primaryObjectType === 'order' ? $event->primaryObjectID : null,
                $status,
                'universal_event_processing',
                false,
                $process_id
            );
        }
    }

    /**
     * Log processing error
     * 
     * @param array $event_data
     * @param \Throwable $error
     * @param float $execution_time
     * @param string $process_id
     * @return void
     */
    private function logProcessingError(array $event_data, \Throwable $error, float $execution_time, string $process_id): void
    {
        error_log(sprintf(
            'ODCM Universal Event Processor Error: %s (%.3fs) - Data: %s',
            $error->getMessage(),
            $execution_time,
            json_encode($event_data)
        ));

        // Log to audit trail if available
        if (function_exists('odcm_log_custom_event')) {
            $summary = 'Universal event processing failed: ' . $error->getMessage();

            $details = array_merge($event_data, [
                'error_message' => $error->getMessage(),
                'error_file' => $error->getFile(),
                'error_line' => $error->getLine(),
                'execution_time' => $execution_time,
            ]);

            odcm_log_custom_event(
                $summary,
                $details,
                null,
                'error',
                'universal_event_processing',
                false,
                $process_id
            );
        }
    }
}

/**
 * Evaluation Context
 * 
 * Contains all the data needed for rule evaluation in the universal event system.
 * This includes the event itself, related entities, and contextual metadata.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   2.2.0
 */
class EvaluationContext
{
    /**
     * The universal event being processed
     * 
     * @var UniversalEvent
     */
    public readonly UniversalEvent $event;

    /**
     * Related WooCommerce order (if any)
     * 
     * @var object|null
     */
    public readonly ?object $order;

    /**
     * Related WooCommerce subscription (if any)
     * 
     * @var object|null
     */
    public readonly ?object $subscription;

    /**
     * Related WordPress user/customer (if any)
     * 
     * @var \WP_User|null
     */
    public readonly ?\WP_User $customer;

    /**
     * Gateway and processing metadata
     * 
     * @var array
     */
    public readonly array $gateway_metadata;

    /**
     * Constructor
     * 
     * @param UniversalEvent $event
     * @param object|null $order
     * @param object|null $subscription
     * @param \WP_User|null $customer
     * @param array $gateway_metadata
     */
    public function __construct(
        UniversalEvent $event,
        ?object $order = null,
        ?object $subscription = null,
        ?\WP_User $customer = null,
        array $gateway_metadata = []
    ) {
        $this->event = $event;
        $this->order = $order;
        $this->subscription = $subscription;
        $this->customer = $customer;
        $this->gateway_metadata = $gateway_metadata;
    }

    /**
     * Get order ID (primary or secondary)
     * 
     * @return int|null
     */
    public function getOrderId(): ?int
    {
        if ($this->order) {
            return $this->order->get_id();
        }

        if ($this->event->primaryObjectType === 'order') {
            return $this->event->primaryObjectID;
        }

        if ($this->event->secondaryObjectType === 'order') {
            return $this->event->secondaryObjectID;
        }

        return null;
    }

    /**
     * Get subscription ID (primary or secondary)
     * 
     * @return int|null
     */
    public function getSubscriptionId(): ?int
    {
        if ($this->subscription && method_exists($this->subscription, 'get_id')) {
            return $this->subscription->get_id();
        }

        if ($this->event->primaryObjectType === 'subscription') {
            return $this->event->primaryObjectID;
        }

        if ($this->event->secondaryObjectType === 'subscription') {
            return $this->event->secondaryObjectID;
        }

        return null;
    }

    /**
     * Get customer ID
     * 
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        if ($this->customer) {
            return $this->customer->ID;
        }

        if ($this->order && $this->order->get_customer_id()) {
            return $this->order->get_customer_id();
        }

        if ($this->subscription && method_exists($this->subscription, 'get_customer_id')) {
            return $this->subscription->get_customer_id();
        }

        return null;
    }

    /**
     * Check if this is a subscription-related event
     * 
     * @return bool
     */
    public function isSubscriptionEvent(): bool
    {
        return strpos($this->event->eventType, 'subscription_') === 0 || 
               strpos($this->event->eventType, 'renewal_') === 0 ||
               $this->subscription !== null;
    }

    /**
     * Check if this is a payment-related event
     * 
     * @return bool
     */
    public function isPaymentEvent(): bool
    {
        return strpos($this->event->eventType, 'payment_') === 0;
    }

    /**
     * Check if this is a dispute-related event
     * 
     * @return bool
     */
    public function isDisputeEvent(): bool
    {
        return strpos($this->event->eventType, 'dispute_') === 0;
    }
}
