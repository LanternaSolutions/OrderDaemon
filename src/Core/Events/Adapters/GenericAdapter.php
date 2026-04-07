<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events\Adapters;

use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;

/**
 * Generic Gateway Event Adapter
 * 
 * Handles generic webhook events from custom integrations and unknown gateways.
 * Provides a flexible fallback for processing webhook events that don't have
 * specific adapters.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events\Adapters
 * @since   1.1.1
 */
class GenericAdapter extends AbstractGatewayAdapter
{
    /**
     * Supported event types for generic adapter
     * 
     * @var array
     */
    protected array $supported_event_types = [
        'payment_completed',
        'payment_failed',
        'payment_pending',
        'payment_refunded',
        'payment_cancelled',
        'order_updated',
        'order_completed',
        'order_cancelled',
        'subscription_created',
        'subscription_cancelled',
        'subscription_renewed',
        'custom_event',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('generic');
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle(array $input): bool
    {
        $payload = $input['payload'] ?? [];
        
        // Generic adapter can handle any input that has basic event structure
        // or is explicitly marked as a test event
        return isset($payload['event']) || 
               isset($payload['event_type']) || 
               isset($payload['_odcm_test']) ||
               isset($payload['transaction_id']) ||
               isset($payload['order_id']);
    }

    /**
     * {@inheritdoc}
     */
    public function normalize(array $input): array
    {
        $sanitized_input = $this->sanitizeInput($input);
        $payload = $sanitized_input['payload'] ?? [];
        
        $this->log('Normalizing generic webhook', [
            'event' => $payload['event'] ?? $payload['event_type'] ?? 'unknown'
        ]);

        // Determine event type
        $event_type = $this->extractEventType($payload);
        if (!$event_type) {
            $this->log('Could not determine event type from generic payload', array_keys($payload));
            $event_type = 'custom_event';
        }

        // Extract common data
        $transaction_id = $this->extractTransactionId($payload);
        $order_id = $this->extractOrderId($payload);
        $amount = $this->extractAmount($payload);
        $currency = $payload['currency'] ?? 'USD';
        $status = $payload['status'] ?? 'unknown';

        // Create universal event
        $event_data = [
            'eventType' => $event_type,
            'sourceGateway' => 'generic',
            'channel' => 'webhook',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order_id,
            'secondaryObjectType' => null,
            'secondaryObjectID' => null,
            'transactionID' => $transaction_id,
            'status' => $status,
            'reason' => $payload['reason'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'occurredAt' => $payload['timestamp'] ?? current_time('c'),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => $this->computeIdempotencyKey($sanitized_input),
            'rawData' => $payload,
        ];

        return [new UniversalEvent($event_data)];
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthenticity(array $input): bool
    {
        $payload = $input['payload'] ?? [];

        // Always allow test events
        if (isset($payload['_odcm_test']) && $payload['_odcm_test']) {
            $this->log('Allowing test event through generic adapter');
            return true;
        }

        $connection = $input['_connection'] ?? null;

        // No connection config — legacy permissive mode
        if ($connection === null) {
            $this->log('Generic webhook authentication passed (permissive mode)');
            return true;
        }

        $auth_method = $connection['auth_method'] ?? 'none';
        $slug        = $connection['slug'] ?? ($input['connection'] ?? '');

        if ($auth_method === 'none') {
            $result = true;
        } elseif ($auth_method === 'bearer') {
            $auth_header = $input['headers']['authorization'] ?? '';
            $stored      = odcm_decrypt_value($connection['bearer_token'] ?? '');
            $result      = $stored !== '' && hash_equals('Bearer ' . $stored, $auth_header);
        } elseif ($auth_method === 'hmac') {
            $header_name = strtolower($connection['hmac_header'] ?? 'x-signature');
            $sig_header  = $input['headers'][$header_name] ?? '';
            $raw_body    = $input['raw_body'] ?? '';
            $secret      = odcm_decrypt_value($connection['hmac_secret'] ?? '');
            $expected    = hash_hmac('sha256', $raw_body, $secret);
            $result      = $secret !== '' && hash_equals($expected, $sig_header);
        } else {
            $result = false;
        }

        return (bool) apply_filters('odcm_webhook_connection_auth', $result, $slug, $input);
    }

    /**
     * {@inheritdoc}
     */
    public function computeIdempotencyKey(array $input): string
    {
        $payload = $input['payload'] ?? [];
        $components = ['generic'];

        // Use transaction ID if available
        if (!empty($payload['transaction_id'])) {
            $components[] = $payload['transaction_id'];
        }

        // Use order ID if available
        if (!empty($payload['order_id'])) {
            $components[] = 'order_' . $payload['order_id'];
        }

        // Use event type
        $event_type = $payload['event'] ?? $payload['event_type'] ?? 'unknown';
        $components[] = $event_type;

        // Use timestamp for uniqueness
        $timestamp = $payload['timestamp'] ?? $input['timestamp'] ?? time();
        $components[] = $timestamp;

        return 'odcm_generic_' . substr(md5(implode('|', $components)), 0, 16);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapEventType(string $gateway_event_type): string
    {
        // Map common event type variations to standard types
        $mapping = [
            'payment_complete' => 'payment_completed',
            'payment_success' => 'payment_completed',
            'payment_successful' => 'payment_completed',
            'payment_approved' => 'payment_completed',
            'payment_captured' => 'payment_completed',
            
            'payment_fail' => 'payment_failed',
            'payment_failure' => 'payment_failed',
            'payment_declined' => 'payment_failed',
            'payment_denied' => 'payment_failed',
            'payment_rejected' => 'payment_failed',
            
            'payment_refund' => 'payment_refunded',
            'refund' => 'payment_refunded',
            'refund_completed' => 'payment_refunded',
            
            'payment_cancel' => 'payment_cancelled',
            'payment_canceled' => 'payment_cancelled',
            'payment_void' => 'payment_cancelled',
            'payment_voided' => 'payment_cancelled',
            
            'order_complete' => 'order_completed',
            'order_finished' => 'order_completed',
            'order_fulfilled' => 'order_completed',
            
            'order_cancel' => 'order_cancelled',
            'order_canceled' => 'order_cancelled',
            'order_void' => 'order_cancelled',
            'order_voided' => 'order_cancelled',
        ];

        $normalized = strtolower($gateway_event_type);
        return $mapping[$normalized] ?? $gateway_event_type;
    }

    /**
     * {@inheritdoc}
     */
    protected function extractTransactionId(array $payload): ?string
    {
        // Try various common field names for transaction ID
        $txn_fields = [
            'transaction_id',
            'txn_id',
            'id',
            'payment_id',
            'charge_id',
            'reference_id',
            'external_id',
        ];
        
        foreach ($txn_fields as $field) {
            if (!empty($payload[$field])) {
                return (string) $payload[$field];
            }
        }

        return null;
    }

    /**
     * Extract order ID from payload
     * 
     * @param array $payload Webhook payload
     * @return int|null Order ID
     */
    private function extractOrderId(array $payload): ?int
    {
        // Try various common field names for order ID
        $order_fields = [
            'order_id',
            'order_number',
            'invoice_id',
            'invoice_number',
            'reference',
            'external_reference',
        ];
        
        foreach ($order_fields as $field) {
            if (!empty($payload[$field])) {
                $order_id = $payload[$field];
                if (is_numeric($order_id)) {
                    return (int) $order_id;
                }
                
                // Try to extract numeric part from string
                if (preg_match('/(\d+)/', $order_id, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Extract amount from payload
     * 
     * @param array $payload Webhook payload
     * @return float|null Amount
     */
    private function extractAmount(array $payload): ?float
    {
        // Try various common field names for amount
        $amount_fields = [
            'amount',
            'total',
            'value',
            'price',
            'sum',
            'gross',
            'net',
        ];
        
        foreach ($amount_fields as $field) {
            if (isset($payload[$field])) {
                $amount = $payload[$field];
                
                // Handle nested amount objects
                if (is_array($amount)) {
                    if (isset($amount['value'])) {
                        return (float) $amount['value'];
                    }
                    if (isset($amount['total'])) {
                        return (float) $amount['total'];
                    }
                    if (isset($amount['amount'])) {
                        return (float) $amount['amount'];
                    }
                } elseif (is_numeric($amount)) {
                    return (float) $amount;
                }
            }
        }

        return null;
    }

    /**
     * Extract event type from payload
     * 
     * @param array $payload Webhook payload
     * @return string|null Event type
     */
    private function extractEventType(array $payload): ?string
    {
        // Try various common field names for event type
        $event_fields = [
            'event',
            'event_type',
            'type',
            'action',
            'status',
            'webhook_event',
        ];
        
        foreach ($event_fields as $field) {
            if (!empty($payload[$field])) {
                return $this->mapEventType($payload[$field]);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function extractGatewaySpecificMetadata(array $input): array
    {
        $payload = $input['payload'] ?? [];
        
        return [
            'type' => 'generic',
            'is_test' => $payload['_odcm_test'] ?? false,
            'user_agent' => $input['user_agent'] ?? null,
            'ip_address' => $input['ip_address'] ?? null,
            'original_event_type' => $payload['event'] ?? $payload['event_type'] ?? null,
        ];
    }
}
