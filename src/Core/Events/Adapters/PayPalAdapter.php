<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events\Adapters;

use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;

/**
 * PayPal Gateway Event Adapter
 * 
 * Handles PayPal IPN (Instant Payment Notification) and webhook events,
 * normalizing them into UniversalEvent objects. Supports both one-time
 * payments and subscription lifecycle events.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events\Adapters
 * @since   next
 */
class PayPalAdapter extends AbstractGatewayAdapter
{
    /**
     * PayPal IPN verification URL (sandbox)
     */
    private const IPN_VERIFY_URL_SANDBOX = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';

    /**
     * PayPal IPN verification URL (live)
     */
    private const IPN_VERIFY_URL_LIVE = 'https://ipnpb.paypal.com/cgi-bin/webscr';

    /**
     * PayPal webhook verification URL
     */
    private const WEBHOOK_VERIFY_URL = 'https://api.paypal.com/v1/notifications/verify-webhook-signature';

    /**
     * Supported event types - Comprehensive PayPal IPN and webhook coverage
     * 
     * @var array
     */
    protected array $supported_event_types = [
        // Payment events
        'payment_created',
        'payment_completed',
        'payment_denied',
        'payment_pending',
        'payment_failed',
        'payment_refunded',
        'payment_reversed',
        'payment_voided',
        
        // Subscription events
        'subscription_created',
        'subscription_approved',
        'subscription_cancelled',
        'subscription_suspended',
        'subscription_reactivated',
        'subscription_completed',
        'subscription_expired',
        
        // Recurring payment events
        'recurring_payment',
        'recurring_payment_profile_created',
        'recurring_payment_failed',
        'recurring_payment_skipped',
        'recurring_payment_suspended',
        'recurring_payment_cancelled',
        
        // Renewal events
        'renewal_payment_processing',
        'renewal_payment_completed',
        'renewal_payment_failed',
        'renewal_payment_pending',
        
        // Trial events
        'trial_started',
        'trial_ended',
        
        // Dispute events
        'dispute_opened',
        'dispute_resolved',
        'dispute_won',
        'dispute_lost',
        
        // Express checkout events
        'express_checkout_created',
        'express_checkout_completed',
        
        // Mass payment events
        'mass_payment_completed',
        'mass_payment_failed',
        
        // Authorization events
        'authorization_created',
        'authorization_voided',
        'authorization_expired',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('paypal');
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle(array $input): bool
    {
        // Check for PayPal IPN indicators
        if ($this->isPayPalIPN($input)) {
            return true;
        }

        // Check for PayPal webhook indicators
        if ($this->isPayPalWebhook($input)) {
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
        
        if ($this->isPayPalIPN($sanitized_input)) {
            return $this->normalizeIPN($sanitized_input);
        }

        if ($this->isPayPalWebhook($sanitized_input)) {
            return $this->normalizeWebhook($sanitized_input);
        }

        throw new \InvalidArgumentException('Input is not a valid PayPal event');
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthenticity(array $input): bool
    {
        if ($this->isPayPalIPN($input)) {
            return $this->validateIPN($input);
        }

        if ($this->isPayPalWebhook($input)) {
            return $this->validateWebhook($input);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function computeIdempotencyKey(array $input): string
    {
        $components = ['paypal'];

        if ($this->isPayPalIPN($input)) {
            $payload = $input['payload'] ?? [];
            $components[] = 'ipn';
            $components[] = $payload['txn_id'] ?? '';
            $components[] = $payload['txn_type'] ?? '';
            $components[] = $payload['payment_status'] ?? '';
        } elseif ($this->isPayPalWebhook($input)) {
            $payload = $input['payload'] ?? [];
            $components[] = 'webhook';
            $components[] = $payload['id'] ?? '';
            $components[] = $payload['event_type'] ?? '';
        }

        return 'odcm_paypal_' . substr(md5(implode('|', $components)), 0, 16);
    }

    /**
     * Check if input is a PayPal IPN
     * 
     * @param array $input Input data
     * @return bool True if PayPal IPN
     */
    private function isPayPalIPN(array $input): bool
    {
        $payload = $input['payload'] ?? [];
        
        // IPN typically contains these fields
        return isset($payload['payment_status']) || 
               isset($payload['txn_type']) || 
               isset($payload['subscr_id']);
    }

    /**
     * Check if input is a PayPal webhook
     * 
     * @param array $input Input data
     * @return bool True if PayPal webhook
     */
    private function isPayPalWebhook(array $input): bool
    {
        $headers = $input['headers'] ?? [];
        $payload = $input['payload'] ?? [];
        
        // Webhook contains specific headers and structure
        return (isset($headers['paypal-transmission-sig']) || 
                isset($headers['paypal-auth-algo'])) &&
               isset($payload['event_type']);
    }

    /**
     * Normalize PayPal IPN into UniversalEvent objects
     * 
     * @param array $input Sanitized input data
     * @return UniversalEvent[] Array of universal events
     */
    private function normalizeIPN(array $input): array
    {
        $payload = $input['payload'] ?? [];
        $events = [];

        $this->log('Normalizing PayPal IPN', ['txn_type' => $payload['txn_type'] ?? 'unknown']);

        // Determine event type based on IPN data
        $event_type = $this->mapIPNEventType($payload);
        if (!$event_type) {
            $this->log('Unknown IPN event type', $payload);
            return [];
        }

        // Extract common data
        $transaction_id = $this->extractTransactionId($payload);
        $amount = isset($payload['mc_gross']) ? (float) $payload['mc_gross'] : null;
        $currency = $payload['mc_currency'] ?? null;

        // Determine primary object type and ID
        $primary_object_type = 'order';
        $primary_object_id = null;
        $secondary_object_type = null;
        $secondary_object_id = null;

        // Handle subscription events
        if (isset($payload['subscr_id'])) {
            $primary_object_type = 'subscription';
            $primary_object_id = $payload['subscr_id'];
            
            // Try to find related order
            if (isset($payload['custom'])) {
                $order_id = $this->extractOrderIdFromCustom($payload['custom']);
                if ($order_id) {
                    $secondary_object_type = 'order';
                    $secondary_object_id = $order_id;
                }
            }
        } elseif ($transaction_id) {
            // Try to find order by transaction ID
            $order_id = $this->findOrderByTransactionId($transaction_id);
            if ($order_id) {
                $primary_object_id = $order_id;
            }
        }

        // Create universal event
        $event_data = [
            'eventType' => $event_type,
            'sourceGateway' => 'paypal',
            'channel' => 'ipn',
            'primaryObjectType' => $primary_object_type,
            'primaryObjectID' => $primary_object_id,
            'secondaryObjectType' => $secondary_object_type,
            'secondaryObjectID' => $secondary_object_id,
            'transactionID' => $transaction_id,
            'status' => $payload['payment_status'] ?? null,
            'reason' => $payload['pending_reason'] ?? $payload['reason_code'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'occurredAt' => $this->parsePayPalTimestamp($payload['payment_date'] ?? ''),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => $this->computeIdempotencyKey($input),
            'rawData' => $payload,
        ];

        $events[] = new UniversalEvent($event_data);

        return $events;
    }

    /**
     * Normalize PayPal webhook into UniversalEvent objects
     * 
     * @param array $input Sanitized input data
     * @return UniversalEvent[] Array of universal events
     */
    private function normalizeWebhook(array $input): array
    {
        $payload = $input['payload'] ?? [];
        $events = [];

        $this->log('Normalizing PayPal webhook', ['event_type' => $payload['event_type'] ?? 'unknown']);

        $event_type = $this->mapWebhookEventType($payload['event_type'] ?? '');
        if (!$event_type) {
            $this->log('Unknown webhook event type', ['event_type' => $payload['event_type'] ?? 'unknown']);
            return [];
        }

        // Extract resource data
        $resource = $payload['resource'] ?? [];
        $transaction_id = $this->extractWebhookTransactionId($resource);
        $amount = $this->extractWebhookAmount($resource);
        $currency = $resource['amount']['currency_code'] ?? null;

        // Determine primary object
        $primary_object_type = 'order';
        $primary_object_id = null;
        $secondary_object_type = null;
        $secondary_object_id = null;

        // Handle subscription webhooks
        if (strpos($payload['event_type'] ?? '', 'BILLING.SUBSCRIPTION') === 0) {
            $primary_object_type = 'subscription';
            $primary_object_id = $resource['id'] ?? null;
        } elseif ($transaction_id) {
            // Try to find order by transaction ID
            $order_id = $this->findOrderByTransactionId($transaction_id);
            if ($order_id) {
                $primary_object_id = $order_id;
            }
        }

        // Create universal event
        $event_data = [
            'eventType' => $event_type,
            'sourceGateway' => 'paypal',
            'channel' => 'webhook',
            'primaryObjectType' => $primary_object_type,
            'primaryObjectID' => $primary_object_id,
            'secondaryObjectType' => $secondary_object_type,
            'secondaryObjectID' => $secondary_object_id,
            'transactionID' => $transaction_id,
            'status' => $resource['status'] ?? null,
            'reason' => $resource['status_details']['reason'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'occurredAt' => $payload['create_time'] ?? current_time('c'),
            'receivedAt' => current_time('c'),
            'idempotencyKey' => $this->computeIdempotencyKey($input),
            'rawData' => $payload,
        ];

        $events[] = new UniversalEvent($event_data);

        return $events;
    }

    /**
     * {@inheritdoc}
     */
    protected function mapEventType(string $gateway_event_type): string
    {
        // This method is used by the abstract class
        return $this->mapIPNEventType(['txn_type' => $gateway_event_type]) ?: 'payment_created';
    }

    /**
     * Map PayPal IPN event types to universal event types
     * 
     * @param array $payload IPN payload
     * @return string|null Universal event type
     */
    private function mapIPNEventType(array $payload): ?string
    {
        $txn_type = $payload['txn_type'] ?? '';
        $payment_status = $payload['payment_status'] ?? '';

        // Subscription events (subscr_*)
        if (strpos($txn_type, 'subscr_') === 0) {
            switch ($txn_type) {
                case 'subscr_signup':
                    return 'subscription_created';
                case 'subscr_payment':
                    return $payment_status === 'Completed' ? 'renewal_payment_completed' : 'renewal_payment_failed';
                case 'subscr_failed':
                    return 'renewal_payment_failed';
                case 'subscr_cancel':
                    return 'subscription_cancelled';
                case 'subscr_eot':
                    return 'subscription_completed';
                case 'subscr_modify':
                    return 'subscription_modified';
            }
        }

        // Recurring payment profile events (recurring_payment_*)
        if (strpos($txn_type, 'recurring_payment') === 0) {
            switch ($txn_type) {
                case 'recurring_payment_profile_created':
                    return 'recurring_payment_profile_created';
                case 'recurring_payment':
                    return $payment_status === 'Completed' ? 'recurring_payment' : 'recurring_payment_failed';
                case 'recurring_payment_skipped':
                    return 'recurring_payment_skipped';
                case 'recurring_payment_failed':
                    return 'recurring_payment_failed';
                case 'recurring_payment_profile_cancel':
                    return 'recurring_payment_cancelled';
                case 'recurring_payment_suspended':
                    return 'recurring_payment_suspended';
                case 'recurring_payment_suspended_due_to_max_failed_payment':
                    return 'recurring_payment_suspended';
            }
        }

        // Express checkout events
        switch ($txn_type) {
            case 'express_checkout':
                return 'express_checkout_completed';
            case 'web_accept':
                return $payment_status === 'Completed' ? 'payment_completed' : 'payment_pending';
            case 'send_money':
                return 'payment_completed';
            case 'cart':
                return 'payment_completed';
        }

        // Mass payment events
        if (strpos($txn_type, 'masspay') === 0) {
            return $payment_status === 'Completed' ? 'mass_payment_completed' : 'mass_payment_failed';
        }

        // Authorization and capture events
        switch ($txn_type) {
            case 'authorization':
                return 'authorization_created';
            case 'capture':
                return 'payment_completed';
            case 'void':
                return 'authorization_voided';
        }

        // Dispute and chargeback events
        switch ($txn_type) {
            case 'new_case':
                return 'dispute_opened';
            case 'adjustment':
                // Check reason code to determine if it's a dispute resolution
                $reason_code = $payload['reason_code'] ?? '';
                if (in_array($reason_code, ['chargeback', 'chargeback_reimbursement', 'chargeback_settlement'], true)) {
                    return 'dispute_resolved';
                }
                return 'payment_adjustment';
        }

        // Payment status-based mapping (fallback for unknown txn_types)
        switch ($payment_status) {
            case 'Completed':
                return 'payment_completed';
            case 'Pending':
                return 'payment_pending';
            case 'Failed':
            case 'Denied':
                return 'payment_denied';
            case 'Refunded':
                return 'payment_refunded';
            case 'Reversed':
                return 'payment_reversed';
            case 'Canceled_Reversal':
                return 'payment_reversal_cancelled';
            case 'Voided':
                return 'payment_voided';
            case 'Expired':
                return 'authorization_expired';
        }

        // If we can't map it, log for future enhancement
        if (!empty($txn_type) || !empty($payment_status)) {
            $this->log('Unmapped PayPal IPN event type', [
                'txn_type' => $txn_type,
                'payment_status' => $payment_status,
                'payload_keys' => array_keys($payload)
            ]);
        }

        return null;
    }

    /**
     * Map PayPal webhook event type to universal event type
     * 
     * @param string $webhook_event_type PayPal webhook event type
     * @return string|null Universal event type
     */
    private function mapWebhookEventType(string $webhook_event_type): ?string
    {
        $mapping = [
            // Payment events
            'PAYMENT.CAPTURE.COMPLETED' => 'payment_completed',
            'PAYMENT.CAPTURE.DENIED' => 'payment_denied',
            'PAYMENT.CAPTURE.PENDING' => 'payment_pending',
            'PAYMENT.CAPTURE.REFUNDED' => 'payment_refunded',
            'PAYMENT.CAPTURE.REVERSED' => 'payment_reversed',
            
            // Subscription events
            'BILLING.SUBSCRIPTION.CREATED' => 'subscription_created',
            'BILLING.SUBSCRIPTION.ACTIVATED' => 'subscription_approved',
            'BILLING.SUBSCRIPTION.CANCELLED' => 'subscription_cancelled',
            'BILLING.SUBSCRIPTION.SUSPENDED' => 'subscription_suspended',
            'BILLING.SUBSCRIPTION.RE-ACTIVATED' => 'subscription_reactivated',
            
            // Subscription payment events
            'BILLING.SUBSCRIPTION.PAYMENT.COMPLETED' => 'renewal_payment_completed',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'renewal_payment_failed',
        ];

        return $mapping[$webhook_event_type] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    protected function extractTransactionId(array $payload): ?string
    {
        // IPN transaction ID fields (in order of preference)
        $txn_fields = ['txn_id', 'parent_txn_id', 'subscr_id'];
        
        foreach ($txn_fields as $field) {
            if (!empty($payload[$field])) {
                return $payload[$field];
            }
        }

        return null;
    }

    /**
     * Extract transaction ID from webhook resource
     * 
     * @param array $resource Webhook resource data
     * @return string|null Transaction ID
     */
    private function extractWebhookTransactionId(array $resource): ?string
    {
        return $resource['id'] ?? $resource['capture_id'] ?? $resource['sale_id'] ?? null;
    }

    /**
     * Extract amount from webhook resource
     * 
     * @param array $resource Webhook resource data
     * @return float|null Amount
     */
    private function extractWebhookAmount(array $resource): ?float
    {
        if (isset($resource['amount']['value'])) {
            return (float) $resource['amount']['value'];
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

        if ($this->isPayPalIPN($input)) {
            $metadata['type'] = 'ipn';
            $metadata['receiver_email'] = $payload['receiver_email'] ?? null;
            $metadata['payer_email'] = $payload['payer_email'] ?? null;
            $metadata['test_ipn'] = $payload['test_ipn'] ?? null;
        } elseif ($this->isPayPalWebhook($input)) {
            $metadata['type'] = 'webhook';
            $metadata['webhook_id'] = $payload['id'] ?? null;
            $metadata['event_version'] = $payload['event_version'] ?? null;
        }

        return $metadata;
    }

    /**
     * Validate PayPal IPN authenticity
     * 
     * @param array $input Input data
     * @return bool True if authentic
     */
    private function validateIPN(array $input): bool
    {
        $payload = $input['payload'] ?? [];
        
        // Build verification request
        $verification_data = 'cmd=_notify-validate&' . http_build_query($payload);
        
        // Determine verification URL (sandbox vs live)
        $is_sandbox = isset($payload['test_ipn']) && $payload['test_ipn'] === '1';
        $verify_url = $is_sandbox ? self::IPN_VERIFY_URL_SANDBOX : self::IPN_VERIFY_URL_LIVE;
        
        // Send verification request
        $response = wp_remote_post($verify_url, [
            'body' => $verification_data,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'ODCM-PayPal-IPN-Verification',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->log('IPN verification failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $is_verified = trim($body) === 'VERIFIED';

        $this->log('IPN verification result', ['verified' => $is_verified, 'response' => $body]);

        return $is_verified;
    }

    /**
     * Validate PayPal webhook authenticity
     * 
     * @param array $input Input data
     * @return bool True if authentic
     */
    private function validateWebhook(array $input): bool
    {
        $headers = $input['headers'] ?? [];
        
        // For now, just check for required headers
        // Full webhook verification requires PayPal API credentials
        $required_headers = ['paypal-transmission-sig', 'paypal-auth-algo', 'paypal-transmission-time'];
        
        foreach ($required_headers as $header) {
            if (empty($headers[$header])) {
                $this->log('Webhook validation failed', ['missing_header' => $header]);
                return false;
            }
        }

        // TODO: Implement full webhook signature verification
        // This requires PayPal webhook ID and certificate from settings
        
        $this->log('Webhook validation passed (basic check)');
        return true;
    }

    /**
     * Parse PayPal timestamp format
     * 
     * @param string $timestamp PayPal timestamp
     * @return string ISO8601 timestamp
     */
    private function parsePayPalTimestamp(string $timestamp): string
    {
        if (empty($timestamp)) {
            return current_time('c');
        }

        try {
            // PayPal uses format like "20:12:59 Jan 13, 2009 PST"
            $date = new \DateTime($timestamp);
            return $date->format('c');
        } catch (\Exception $e) {
            $this->log('Failed to parse PayPal timestamp', ['timestamp' => $timestamp, 'error' => $e->getMessage()]);
            return current_time('c');
        }
    }

    /**
     * Extract order ID from PayPal custom field
     * 
     * @param string $custom Custom field value
     * @return int|null Order ID
     */
    private function extractOrderIdFromCustom(string $custom): ?int
    {
        // Custom field often contains order ID or JSON with order ID
        if (is_numeric($custom)) {
            return (int) $custom;
        }

        // Try to decode as JSON
        $data = json_decode($custom, true);
        if (is_array($data) && isset($data['order_id'])) {
            return (int) $data['order_id'];
        }

        return null;
    }
}
