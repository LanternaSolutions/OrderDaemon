<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

use WC_Order;
use WP_User;

/**
 * Evaluation Context
 *
 * Encapsulates all the entities and metadata needed for evaluating rules
 * against universal events. This context provides a unified interface
 * for accessing orders, subscriptions, customers, and event data during
 * rule evaluation.
 *
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   2.2.0
 */
class EvaluationContext
{
    /**
     * The universal event that triggered the evaluation
     *
     * @var UniversalEvent
     */
    public UniversalEvent $event;

    /**
     * The WooCommerce order associated with the event (if any)
     *
     * @var WC_Order|null
     */
    public ?WC_Order $order = null;

    /**
     * The WooCommerce subscription associated with the event (if any)
     *
     * @var \WC_Subscription|null
     */
    public $subscription = null;

    /**
     * The WordPress user/customer associated with the event (if any)
     *
     * @var WP_User|null
     */
    public ?WP_User $customer = null;

    /**
     * Additional gateway-specific metadata
     *
     * @var array
     */
    public array $gateway_metadata = [];

    /**
     * Create a new evaluation context
     *
     * @param UniversalEvent $event The universal event
     * @param WC_Order|null $order Associated order
     * @param mixed $subscription Associated subscription (WC_Subscription if available)
     * @param WP_User|null $customer Associated customer
     * @param array $gateway_metadata Additional gateway metadata
     */
    public function __construct(
        UniversalEvent $event,
        ?WC_Order $order = null,
                       $subscription = null,
        ?WP_User $customer = null,
        array $gateway_metadata = []
    ) {
        $this->event = $event;
        $this->order = $order;
        $this->subscription = $subscription;
        $this->customer = $customer;
        $this->gateway_metadata = $gateway_metadata;
    }

    /**
     * Get the order ID from the context
     *
     * @return int|null
     */
    public function getOrderId(): ?int
    {
        if ($this->order) {
            return $this->order->get_id();
        }

        // Try to get order ID from event
        if ($this->event->primaryObjectType === 'order' && $this->event->primaryObjectID) {
            return $this->event->primaryObjectID;
        }

        if ($this->event->secondaryObjectType === 'order' && $this->event->secondaryObjectID) {
            return $this->event->secondaryObjectID;
        }

        return null;
    }

    /**
     * Get the subscription ID from the context
     *
     * @return int|null
     */
    public function getSubscriptionId(): ?int
    {
        if ($this->subscription && method_exists($this->subscription, 'get_id')) {
            return $this->subscription->get_id();
        }

        // Try to get subscription ID from event
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
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        if ($this->customer) {
            return $this->customer->ID;
        }

        // Try to get customer from order
        if ($this->order) {
            $customer_id = $this->order->get_customer_id();
            return $customer_id > 0 ? $customer_id : null;
        }

        // Try to get customer from subscription
        if ($this->subscription && method_exists($this->subscription, 'get_customer_id')) {
            $customer_id = $this->subscription->get_customer_id();
            return $customer_id > 0 ? $customer_id : null;
        }

        // Try to get customer ID from event
        if ($this->event->primaryObjectType === 'customer' && $this->event->primaryObjectID) {
            return $this->event->primaryObjectID;
        }

        if ($this->event->secondaryObjectType === 'customer' && $this->event->secondaryObjectID) {
            return $this->event->secondaryObjectID;
        }

        return null;
    }

    /**
     * Check if the context has a valid order
     *
     * @return bool
     */
    public function hasOrder(): bool
    {
        return $this->order !== null && $this->order instanceof WC_Order;
    }

    /**
     * Check if the context has a valid subscription
     *
     * @return bool
     */
    public function hasSubscription(): bool
    {
        return $this->subscription !== null &&
            (class_exists('WC_Subscription') && $this->subscription instanceof \WC_Subscription);
    }

    /**
     * Check if the context has a valid customer
     *
     * @return bool
     */
    public function hasCustomer(): bool
    {
        return $this->customer !== null && $this->customer instanceof WP_User;
    }

    /**
     * Get gateway metadata value by key
     *
     * @param string $key Metadata key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function getGatewayMetadata(string $key, $default = null)
    {
        return $this->gateway_metadata[$key] ?? $default;
    }

    /**
     * Set gateway metadata value
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return void
     */
    public function setGatewayMetadata(string $key, $value): void
    {
        $this->gateway_metadata[$key] = $value;
    }

    /**
     * Get all gateway metadata
     *
     * @return array
     */
    public function getAllGatewayMetadata(): array
    {
        return $this->gateway_metadata;
    }

    /**
     * Create context from a universal event with entity resolution
     *
     * @param UniversalEvent $event The universal event
     * @return self
     */
    public static function fromEvent(UniversalEvent $event): self
    {
        $order = null;
        $subscription = null;
        $customer = null;

        // Resolve order
        $order_id = null;
        if ($event->primaryObjectType === 'order' && $event->primaryObjectID) {
            $order_id = $event->primaryObjectID;
        } elseif ($event->secondaryObjectType === 'order' && $event->secondaryObjectID) {
            $order_id = $event->secondaryObjectID;
        }

        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if (!$order || !$order instanceof WC_Order) {
                $order = null;
            }
        }

        // Resolve subscription
        $subscription_id = null;
        if ($event->primaryObjectType === 'subscription' && $event->primaryObjectID) {
            $subscription_id = $event->primaryObjectID;
        } elseif ($event->secondaryObjectType === 'subscription' && $event->secondaryObjectID) {
            $subscription_id = $event->secondaryObjectID;
        }

        if ($subscription_id && function_exists('wcs_get_subscription')) {
            try {
                $subscription = wcs_get_subscription($subscription_id);
                if (!$subscription || !class_exists('WC_Subscription') || !$subscription instanceof \WC_Subscription) {
                    $subscription = null;
                }
            } catch (\Throwable $e) {
                $subscription = null;
            }
        }

        // Resolve customer
        $customer_id = null;
        if ($event->primaryObjectType === 'customer' && $event->primaryObjectID) {
            $customer_id = $event->primaryObjectID;
        } elseif ($event->secondaryObjectType === 'customer' && $event->secondaryObjectID) {
            $customer_id = $event->secondaryObjectID;
        } elseif ($order) {
            $customer_id = $order->get_customer_id();
        } elseif ($subscription && method_exists($subscription, 'get_customer_id')) {
            $customer_id = $subscription->get_customer_id();
        }

        if ($customer_id && $customer_id > 0) {
            $customer = get_user_by('id', $customer_id);
            if (!$customer || !$customer instanceof WP_User) {
                $customer = null;
            }
        }

        // Extract gateway metadata from raw event data
        $gateway_metadata = [];
        if (is_array($event->rawData)) {
            $gateway_metadata = [
                'raw_event_data' => $event->rawData,
                'transaction_id' => $event->transactionID,
                'amount' => $event->amount,
                'currency' => $event->currency,
                'status' => $event->status,
                'reason' => $event->reason,
                'occurred_at' => $event->occurredAt,
                'received_at' => $event->receivedAt,
            ];
        }

        return new self($event, $order, $subscription, $customer, $gateway_metadata);
    }

    /**
     * Convert context to array for debugging/logging
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'event' => [
                'type' => $this->event->eventType,
                'gateway' => $this->event->sourceGateway,
                'channel' => $this->event->channel,
                'transaction_id' => $this->event->transactionID,
                'amount' => $this->event->amount,
                'currency' => $this->event->currency,
                'status' => $this->event->status,
            ],
            'entities' => [
                'order_id' => $this->getOrderId(),
                'subscription_id' => $this->getSubscriptionId(),
                'customer_id' => $this->getCustomerId(),
            ],
            'has_entities' => [
                'order' => $this->hasOrder(),
                'subscription' => $this->hasSubscription(),
                'customer' => $this->hasCustomer(),
            ],
            'gateway_metadata_keys' => array_keys($this->gateway_metadata),
        ];
    }
}
<?php
