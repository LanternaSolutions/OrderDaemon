<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events\Adapters;

use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;

/**
 * Stripe Gateway Event Adapter
 * 
 * Handles Stripe webhook events, normalizing them into UniversalEvent objects.
 * Supports comprehensive Stripe event coverage including payments, subscriptions,
 * disputes, refunds, and customer lifecycle events.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events\Adapters
 * @since   next
 */
class StripeAdapter extends AbstractGatewayAdapter
{
    /**
     * Stripe webhook endpoint signature header
     */
    private const STRIPE_SIGNATURE_HEADER = 'stripe-signature';

    /**
     * Supported event types - Comprehensive Stripe webhook coverage
     * 
     * @var array
     */
    protected array $supported_event_types = [
        // Payment Intent events
        'payment_intent_created',
        'payment_intent_succeeded',
        'payment_intent_payment_failed',
        'payment_intent_canceled',
        'payment_intent_requires_action',
        'payment_intent_processing',
        
        // Charge events
        'charge_succeeded',
        'charge_failed',
        'charge_pending',
        'charge_captured',
        'charge_updated',
        'charge_dispute_created',
        
        // Subscription events
        'customer_subscription_created',
        'customer_subscription_updated',
        'customer_subscription_deleted',
        'customer_subscription_trial_will_end',
        'customer_subscription_paused',
        'customer_subscription_resumed',
        
        // Invoice events
        'invoice_created',
        'invoice_finalized',
        'invoice_payment_succeeded',
        'invoice_payment_failed',
        'invoice_payment_action_required',
        'invoice_upcoming',
        'invoice_voided',
        
        // Customer events
        'customer_created',
        'customer_updated',
        'customer_deleted',
        'customer_source_created',
        'customer_source_updated',
        'customer_source_deleted',
        
        // Dispute events
        'charge_dispute_created',
        'charge_dispute_updated',
        'charge_dispute_closed',
        'charge_dispute_funds_withdrawn',
        'charge_dispute_funds_reinstated',
        
        // Refund events
        'charge_refunded',
        'refund_created',
        'refund_updated',
        'refund_failed',
        
        // Payout events
        'payout_created',
        'payout_updated',
        'payout_paid',
        'payout_failed',
        'payout_canceled',
        
        // Setup Intent events
        'setup_intent_created',
        'setup_intent_succeeded',
        'setup_intent_setup_failed',
        'setup_intent_canceled',
        
        // Price and Product events
        'price_created',
        'price_updated',
        'product_created',
        'product_updated',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('stripe');
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle(array $input): bool
    {
        // Check for Stripe webhook indicators
        if ($this->isStripeWebhook($input)) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize(array $input): array
    {
        $sanitized_input = $this->sanitizeInput($input);
        
        if ($this->isStripeWebhook($sanitized_input)) {
            return $this->normalizeWebhook($sanitized_input);
        }

        throw new \InvalidArgumentException('Input is not a valid Stripe event');
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthenticity(array $input): bool
    {
        if ($this->isStripeWebhook($input)) {
            return $this->validateWebhook($input);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function computeIdempotencyKey(array $input): string
    {
        $components = ['stripe'];

        if ($this->isStripeWebhook($input)) {
            $payload = $input['payload'] ?? [];
            $components[] = 'webhook';
            $components[] = $payload['id'] ?? '';
            $components[] = $payload['type'] ?? '';
            
            // Include object ID for uniqueness
            if (isset($payload['data']['object']['id'])) {
                $components[] = $payload['data']['object']['id'];
            }
        }

        return 'odcm_stripe_' . substr(md5(implode('|', $components)), 0, 16);
    }

    /**
     * Check if input is a Stripe webhook
     * 
     * @param array $input Input data
     * @return bool True if Stripe webhook
     */
    private function isStripeWebhook(array $input): bool
    {
        $headers = $input['headers'] ?? [];
        $payload = $input['payload'] ?? [];
        
        // Stripe webhooks contain specific headers and structure
        return (isset($headers[self::STRIPE_SIGNATURE_HEADER]) || 
                isset($headers['stripe_signature'])) &&
               isset($payload['type']) &&
               isset($payload['data']);
    }

    /**
     * Normalize Stripe webhook into UniversalEvent objects
     * 
     * @param array $input Sanitized input data
     * @return UniversalEvent[] Array of universal events
     */
    private function normalizeWebhook(array $input): array
    {
        $payload = $input['payload'] ?? [];
        $events = [];

        $this->log('Normalizing Stripe webhook', ['event_type' => $payload['type'] ?? 'unknown']);

        $event_type = $this->mapWebhookEventType($payload['type'] ?? '');
        if (!$event_type) {
            $this->log('Unknown webhook event type', ['event_type' => $payload['type'] ?? 'unknown']);
            return [];
        }

        // Extract object data
        $object_data = $payload['data']['object'] ?? [];
        $object_type = $object_data['object'] ?? '';
        
        // Extract common data
        $stripe_id = $this->extractStripeId($object_data);
        $amount = $this->extractAmount($object_data);
        $currency = $this->extractCurrency($object_data);
        $status = $this->extractStatus($object_data);

        // Determine primary object type and ID
        $primary_object_type = 'order';
        $primary_object_id = null;
        $secondary_object_type = null;
        $secondary_object_id = null;

        // Handle subscription events
        if (strpos($payload['type'], 'customer.subscription') === 0) {
            $primary_object_type = 'subscription';
            $primary_object_id = $stripe_id;
            
            // Try to find related order
            if (isset($object_data['metadata']['order_id'])) {
                $order_id = (int) $object_data['metadata']['order_id'];
                if ($order_id) {
                    $secondary_object_type = 'order';
                    $secondary_object_id = $order_id;
                }
            }
        } elseif ($stripe_id) {
            // Try to find order by Stripe ID
            $order_id = $this->findOrderByStripeId($stripe_id);
            if ($order_id) {
                $primary_object_id = $order_id;
            } elseif (isset($object_data['metadata']['order_id'])) {
                // Check metadata for order ID
                $order_id = (int) $object_data['metadata']['order_id'];
                if ($order_id) {
                    $primary_object_id = $order_id;
                }
            }
        }

        // Create universal event
        $event_data = [
            'eventType' => $event_type,
            'sourceGateway' => 'stripe',
            'channel' => 'webhook',
            'primaryObjectType' => $primary_object_type,
            'primaryObjectID' => $primary_object_id,
            'secondaryObjectType' => $secondary_object_type,
            'secondaryObjectID' => $secondary_object_id,
            'transactionID' => $stripe_id,
            'status' => $status,
            'reason' => $this->extractFailureReason($object_data),
            'amount' => $amount,
            'currency' => $currency,
            'occurredAt' => $this->parseStripeTimestamp($payload['created'] ?? 0),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => $this->computeIdempotencyKey($input),
            'rawData' => $payload,
        ];

        $events[] = new UniversalEvent($event_data);

        return $events;
    }

    /**
     * Map Stripe webhook event type to universal event type
     * 
     * @param string $webhook_event_type Stripe webhook event type
     * @return string|null Universal event type
     */
    private function mapWebhookEventType(string $webhook_event_type): ?string
    {
        $mapping = [
            // Payment Intent events
            'payment_intent.created' => 'payment_created',
            'payment_intent.succeeded' => 'payment_completed',
            'payment_intent.payment_failed' => 'payment_failed',
            'payment_intent.canceled' => 'payment_cancelled',
            'payment_intent.requires_action' => 'payment_requires_action',
            'payment_intent.processing' => 'payment_processing',
            
            // Charge events
            'charge.succeeded' => 'payment_completed',
            'charge.failed' => 'payment_failed',
            'charge.pending' => 'payment_pending',
            'charge.captured' => 'payment_captured',
            'charge.updated' => 'payment_updated',
            'charge.refunded' => 'payment_refunded',
            'charge.dispute.created' => 'dispute_opened',
            
            // Subscription events
            'customer.subscription.created' => 'subscription_created',
            'customer.subscription.updated' => 'subscription_updated',
            'customer.subscription.deleted' => 'subscription_cancelled',
            'customer.subscription.trial_will_end' => 'trial_ending',
            'customer.subscription.paused' => 'subscription_paused',
            'customer.subscription.resumed' => 'subscription_resumed',
            
            // Invoice events
            'invoice.created' => 'invoice_created',
            'invoice.finalized' => 'invoice_finalized',
            'invoice.payment_succeeded' => 'renewal_payment_completed',
            'invoice.payment_failed' => 'renewal_payment_failed',
            'invoice.payment_action_required' => 'payment_requires_action',
            'invoice.upcoming' => 'invoice_upcoming',
            'invoice.voided' => 'invoice_voided',
            
            // Customer events
            'customer.created' => 'customer_created',
            'customer.updated' => 'customer_updated',
            'customer.deleted' => 'customer_deleted',
            'customer.source.created' => 'payment_method_added',
            'customer.source.updated' => 'payment_method_updated',
            'customer.source.deleted' => 'payment_method_removed',
            
            // Dispute events
            'charge.dispute.updated' => 'dispute_updated',
            'charge.dispute.closed' => 'dispute_resolved',
            'charge.dispute.funds_withdrawn' => 'dispute_funds_withdrawn',
            'charge.dispute.funds_reinstated' => 'dispute_funds_reinstated',
            
            // Refund events
            'refund.created' => 'refund_created',
            'refund.updated' => 'refund_updated',
            'refund.failed' => 'refund_failed',
            
            // Payout events
            'payout.created' => 'payout_created',
            'payout.updated' => 'payout_updated',
            'payout.paid' => 'payout_completed',
            'payout.failed' => 'payout_failed',
            'payout.canceled' => 'payout_cancelled',
            
            // Setup Intent events
            'setup_intent.created' => 'setup_intent_created',
            'setup_intent.succeeded' => 'setup_intent_completed',
            'setup_intent.setup_failed' => 'setup_intent_failed',
            'setup_intent.canceled' => 'setup_intent_cancelled',
        ];

        return $mapping[$webhook_event_type] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    protected function mapEventType(string $gateway_event_type): string
    {
        // This method is used by the abstract class
        return $this->mapWebhookEventType($gateway_event_type) ?: 'payment_created';
    }

    /**
     * Extract Stripe object ID
     * 
     * @param array $object_data Stripe object data
     * @return string|null Stripe ID
     */
    private function extractStripeId(array $object_data): ?string
    {
        return $object_data['id'] ?? null;
    }

    /**
     * Extract amount from Stripe object
     * 
     * @param array $object_data Stripe object data
     * @return float|null Amount in major currency units
     */
    private function extractAmount(array $object_data): ?float
    {
        if (isset($object_data['amount'])) {
            // Stripe amounts are in cents, convert to major units
            return (float) $object_data['amount'] / 100;
        }

        if (isset($object_data['amount_due'])) {
            return (float) $object_data['amount_due'] / 100;
        }

        if (isset($object_data['amount_paid'])) {
            return (float) $object_data['amount_paid'] / 100;
        }

        return null;
    }

    /**
     * Extract currency from Stripe object
     * 
     * @param array $object_data Stripe object data
     * @return string|null Currency code
     */
    private function extractCurrency(array $object_data): ?string
    {
        return isset($object_data['currency']) ? strtoupper($object_data['currency']) : null;
    }

    /**
     * Extract status from Stripe object
     * 
     * @param array $object_data Stripe object data
     * @return string|null Status
     */
    private function extractStatus(array $object_data): ?string
    {
        return $object_data['status'] ?? null;
    }

    /**
     * Extract failure reason from Stripe object
     * 
     * @param array $object_data Stripe object data
     * @return string|null Failure reason
     */
    private function extractFailureReason(array $object_data): ?string
    {
        // Check various failure reason fields
        $reason_fields = [
            'failure_reason',
            'failure_code',
            'decline_code',
            'outcome.reason',
            'last_payment_error.code',
            'last_payment_error.decline_code'
        ];

        foreach ($reason_fields as $field) {
            if (strpos($field, '.') !== false) {
                // Handle nested fields
                $parts = explode('.', $field);
                $value = $object_data;
                foreach ($parts as $part) {
                    if (isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        $value = null;
                        break;
                    }
                }
                if ($value) {
                    return (string) $value;
                }
            } elseif (!empty($object_data[$field])) {
                return (string) $object_data[$field];
            }
        }

        return null;
    }

    /**
     * Find WooCommerce order by Stripe ID
     * 
     * @param string $stripe_id Stripe object ID
     * @return int|null Order ID if found
     */
    private function findOrderByStripeId(string $stripe_id): ?int
    {
        global $wpdb;

        // Search in order meta for various Stripe ID fields
        $stripe_meta_keys = [
            '_stripe_charge_id',
            '_stripe_payment_intent_id',
            '_stripe_subscription_id',
            '_stripe_invoice_id',
            '_transaction_id'
        ];

        foreach ($stripe_meta_keys as $meta_key) {
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = %s 
                 AND meta_value = %s 
                 LIMIT 1",
                $meta_key,
                $stripe_id
            ));

            if ($order_id) {
                return (int) $order_id;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function extractGatewaySpecificMetadata(array $input): array
    {
        $metadata = [];
        $payload = $input['payload'] ?? [];

        if ($this->isStripeWebhook($input)) {
            $metadata['type'] = 'webhook';
            $metadata['webhook_id'] = $payload['id'] ?? null;
            $metadata['api_version'] = $payload['api_version'] ?? null;
            $metadata['livemode'] = $payload['livemode'] ?? null;
            $metadata['pending_webhooks'] = $payload['pending_webhooks'] ?? null;
            
            // Extract object type
            if (isset($payload['data']['object']['object'])) {
                $metadata['object_type'] = $payload['data']['object']['object'];
            }
        }

        return $metadata;
    }

    /**
     * Validate Stripe webhook authenticity
     * 
     * @param array $input Input data
     * @return bool True if authentic
     */
    private function validateWebhook(array $input): bool
    {
        $headers = $input['headers'] ?? [];
        
        // Check for Stripe signature header
        $signature = $headers[self::STRIPE_SIGNATURE_HEADER] ?? 
                    $headers['stripe_signature'] ?? 
                    null;

        if (!$signature) {
            $this->log('Webhook validation failed', ['reason' => 'missing_signature']);
            return false;
        }

        // TODO: Implement full webhook signature verification
        // This requires Stripe webhook endpoint secret from settings
        // For now, just check that signature exists and has expected format
        
        if (!preg_match('/^t=\d+,v1=[a-f0-9]{64}/', $signature)) {
            $this->log('Webhook validation failed', ['reason' => 'invalid_signature_format']);
            return false;
        }

        $this->log('Webhook validation passed (basic check)');
        return true;
    }

    /**
     * Parse Stripe timestamp format
     * 
     * @param int $timestamp Stripe Unix timestamp
     * @return string ISO8601 timestamp
     */
    private function parseStripeTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return current_time('c');
        }

        try {
            $date = new \DateTime('@' . $timestamp);
            return $date->format('c');
        } catch (\Exception $e) {
            $this->log('Failed to parse Stripe timestamp', ['timestamp' => $timestamp, 'error' => $e->getMessage()]);
            return current_time('c');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function extractTransactionId(array $payload): ?string
    {
        // Extract ID from various Stripe object types
        if (isset($payload['data']['object']['id'])) {
            return $payload['data']['object']['id'];
        }

        if (isset($payload['id'])) {
            return $payload['id'];
        }

        return null;
    }
}
