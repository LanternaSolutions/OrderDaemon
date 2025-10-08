<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

use WC_Order;

/**
 * Evaluation Context for Universal Events
 * 
 * Provides a unified context object that contains all relevant entities
 * and data needed for rule evaluation against universal events. This
 * allows rules to access event data, order information, subscription
 * details, and customer context in a consistent manner.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   1.1.1
 */
class EvaluationContext
{
    /**
     * The universal event being processed
     * 
     * @var UniversalEvent
     */
    public UniversalEvent $event;

    /**
     * The WooCommerce order (if available)
     * 
     * @var WC_Order|null
     */
    public ?WC_Order $order = null;

    /**
     * The WooCommerce subscription (if available and WC Subscriptions is active)
     * 
     * @var object|null
     */
    public ?object $subscription = null;

    /**
     * Customer/user context
     * 
     * @var array
     */
    public array $customer = [];

    /**
     * Gateway-specific metadata
     * 
     * @var array
     */
    public array $gateway_metadata = [];

    /**
     * Additional context data
     * 
     * @var array
     */
    public array $additional_context = [];

    /**
     * Constructor
     * 
     * @param UniversalEvent $event The universal event
     */
    public function __construct(UniversalEvent $event)
    {
        $this->event = $event;
        $this->resolveEntities();
    }

    /**
     * Get the order ID from the context
     * 
     * @return int|null Order ID if available
     */
    public function getOrderId(): ?int
    {
        if ($this->order) {
            return $this->order->get_id();
        }

        // Try to get from event
        if ($this->event->primaryObjectType === 'order' && $this->event->primaryObjectID) {
            return is_numeric($this->event->primaryObjectID) ? (int) $this->event->primaryObjectID : null;
        }

        if ($this->event->secondaryObjectType === 'order' && $this->event->secondaryObjectID) {
            return is_numeric($this->event->secondaryObjectID) ? (int) $this->event->secondaryObjectID : null;
        }

        return null;
    }

    /**
     * Get the subscription ID from the context
     * 
     * @return int|string|null Subscription ID if available
     */
    public function getSubscriptionId()
    {
        if ($this->subscription && method_exists($this->subscription, 'get_id')) {
            return $this->subscription->get_id();
        }

        // Try to get from event
        if ($this->event->primaryObjectType === 'subscription' && $this->event->primaryObjectID) {
            return $this->event->primaryObjectID;
        }

        if ($this->event->secondaryObjectType === 'subscription' && $this->event->secondaryObjectID) {
            return $this->event->secondaryObjectID;
        }

        return null;
    }

    /**
     * Get the customer ID from the context
     * 
     * @return int|null Customer ID if available
     */
    public function getCustomerId(): ?int
    {
        // Try from customer context first
        if (!empty($this->customer['id'])) {
            return (int) $this->customer['id'];
        }

        // Try from order
        if ($this->order && $this->order->get_customer_id()) {
            return $this->order->get_customer_id();
        }

        // Try from subscription
        if ($this->subscription && method_exists($this->subscription, 'get_customer_id')) {
            return $this->subscription->get_customer_id();
        }

        // Try from event
        if ($this->event->primaryObjectType === 'customer' && $this->event->primaryObjectID) {
            return is_numeric($this->event->primaryObjectID) ? (int) $this->event->primaryObjectID : null;
        }

        if ($this->event->secondaryObjectType === 'customer' && $this->event->secondaryObjectID) {
            return is_numeric($this->event->secondaryObjectID) ? (int) $this->event->secondaryObjectID : null;
        }

        return null;
    }

    /**
     * Check if this is a subscription-related event
     * 
     * @return bool True if subscription-related
     */
    public function isSubscriptionEvent(): bool
    {
        return $this->event->primaryObjectType === 'subscription' ||
               $this->event->secondaryObjectType === 'subscription' ||
               $this->subscription !== null ||
               in_array($this->event->eventType, [
                   'subscription_created',
                   'subscription_approved',
                   'subscription_cancelled',
                   'subscription_suspended',
                   'subscription_reactivated',
                   'subscription_completed',
                   'renewal_payment_processing',
                   'renewal_payment_completed',
                   'renewal_payment_failed',
                   'renewal_payment_pending',
                   'trial_started',
                   'trial_ended'
               ]);
    }

    /**
     * Check if this is a payment-related event
     * 
     * @return bool True if payment-related
     */
    public function isPaymentEvent(): bool
    {
        return in_array($this->event->eventType, [
            'payment_created',
            'payment_completed',
            'payment_denied',
            'payment_pending',
            'payment_refunded',
            'payment_reversed',
            'renewal_payment_processing',
            'renewal_payment_completed',
            'renewal_payment_failed',
            'renewal_payment_pending'
        ]);
    }

    /**
     * Check if this is a dispute-related event
     * 
     * @return bool True if dispute-related
     */
    public function isDisputeEvent(): bool
    {
        return in_array($this->event->eventType, [
            'dispute_opened',
            'dispute_resolved',
            'dispute_won',
            'dispute_lost'
        ]);
    }

    /**
     * Get event amount in a specific currency
     * 
     * @param string|null $target_currency Target currency (null for event currency)
     * @return float|null Amount in target currency
     */
    public function getEventAmount(?string $target_currency = null): ?float
    {
        if ($this->event->amount === null) {
            return null;
        }

        // If no target currency specified or same as event currency, return as-is
        if ($target_currency === null || $target_currency === $this->event->currency) {
            return $this->event->amount;
        }

        // TODO: Implement currency conversion
        // For now, return the original amount
        return $this->event->amount;
    }

    /**
     * Get a summary of the context for logging
     * 
     * @return array Context summary
     */
    public function getSummary(): array
    {
        return [
            'event_type' => $this->event->eventType,
            'source_gateway' => $this->event->sourceGateway,
            'channel' => $this->event->channel,
            'primary_object_type' => $this->event->primaryObjectType,
            'primary_object_id' => $this->event->primaryObjectID,
            'secondary_object_type' => $this->event->secondaryObjectType,
            'secondary_object_id' => $this->event->secondaryObjectID,
            'has_order' => $this->order !== null,
            'has_subscription' => $this->subscription !== null,
            'customer_id' => $this->getCustomerId(),
            'amount' => $this->event->amount,
            'currency' => $this->event->currency,
            'transaction_id' => $this->event->transactionID,
        ];
    }

    /**
     * Convert context to array for serialization
     * 
     * @return array Context data
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event->toArray(),
            'order_id' => $this->getOrderId(),
            'subscription_id' => $this->getSubscriptionId(),
            'customer_id' => $this->getCustomerId(),
            'customer' => $this->customer,
            'gateway_metadata' => $this->gateway_metadata,
            'additional_context' => $this->additional_context,
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Resolve entities based on event data
     * 
     * @return void
     */
    private function resolveEntities(): void
    {
        // Resolve order
        $order_id = null;
        if ($this->event->primaryObjectType === 'order' && $this->event->primaryObjectID) {
            $order_id = is_numeric($this->event->primaryObjectID) ? (int) $this->event->primaryObjectID : null;
        } elseif ($this->event->secondaryObjectType === 'order' && $this->event->secondaryObjectID) {
            $order_id = is_numeric($this->event->secondaryObjectID) ? (int) $this->event->secondaryObjectID : null;
        }

        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && is_a($order, 'WC_Order')) {
                $this->order = $order;
            }
        }

        // Resolve subscription
        $subscription_id = null;
        if ($this->event->primaryObjectType === 'subscription' && $this->event->primaryObjectID) {
            $subscription_id = $this->event->primaryObjectID;
        } elseif ($this->event->secondaryObjectType === 'subscription' && $this->event->secondaryObjectID) {
            $subscription_id = $this->event->secondaryObjectID;
        }

        if ($subscription_id && function_exists('wcs_get_subscription')) {
            try {
                $subscription = wcs_get_subscription($subscription_id);
                if ($subscription) {
                    $this->subscription = $subscription;
                }
            } catch (\Throwable $e) {
                // Subscription not found or WC Subscriptions not active
                $this->subscription = null;
            }
        }

        // Resolve customer context
        $customer_id = $this->getCustomerId();
        if ($customer_id) {
            $this->customer = [
                'id' => $customer_id,
                'user' => get_user_by('id', $customer_id),
            ];

            // Add customer metadata if available
            if ($this->customer['user']) {
                $this->customer['email'] = $this->customer['user']->user_email;
                $this->customer['display_name'] = $this->customer['user']->display_name;
                $this->customer['roles'] = $this->customer['user']->roles ?? [];
            }
        }

        // Extract gateway metadata from event
        if (!empty($this->event->rawData)) {
            $this->gateway_metadata = [
                'gateway' => $this->event->sourceGateway,
                'channel' => $this->event->channel,
                'transaction_id' => $this->event->transactionID,
                'raw_data_keys' => array_keys($this->event->rawData),
                'raw_data_size' => strlen(wp_json_encode($this->event->rawData)),
            ];

            // Add specific gateway metadata based on source
            if ($this->event->sourceGateway === 'paypal') {
                $this->extractPayPalMetadata();
            }
        }
    }

    /**
     * Extract PayPal-specific metadata
     * 
     * @return void
     */
    private function extractPayPalMetadata(): void
    {
        $raw_data = $this->event->rawData;

        if ($this->event->channel === 'ipn') {
            $this->gateway_metadata['paypal'] = [
                'type' => 'ipn',
                'receiver_email' => $raw_data['receiver_email'] ?? null,
                'payer_email' => $raw_data['payer_email'] ?? null,
                'test_ipn' => $raw_data['test_ipn'] ?? null,
                'txn_type' => $raw_data['txn_type'] ?? null,
                'payment_type' => $raw_data['payment_type'] ?? null,
                'pending_reason' => $raw_data['pending_reason'] ?? null,
            ];
        } elseif ($this->event->channel === 'webhook') {
            $this->gateway_metadata['paypal'] = [
                'type' => 'webhook',
                'webhook_id' => $raw_data['id'] ?? null,
                'event_version' => $raw_data['event_version'] ?? null,
                'resource_type' => isset($raw_data['resource']) ? 'present' : 'missing',
            ];
        }
    }

    /**
     * Add additional context data
     * 
     * @param string $key Context key
     * @param mixed $value Context value
     * @return void
     */
    public function addContext(string $key, $value): void
    {
        $this->additional_context[$key] = $value;
    }

    /**
     * Get additional context value
     * 
     * @param string $key Context key
     * @param mixed $default Default value if key not found
     * @return mixed Context value or default
     */
    public function getContext(string $key, $default = null)
    {
        return $this->additional_context[$key] ?? $default;
    }

    /**
     * Check if context has a specific key
     * 
     * @param string $key Context key
     * @return bool True if key exists
     */
    public function hasContext(string $key): bool
    {
        return array_key_exists($key, $this->additional_context);
    }
}
