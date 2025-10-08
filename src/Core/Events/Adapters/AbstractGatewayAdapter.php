<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events\Adapters;

use OrderDaemon\CompletionManager\Core\Events\GatewayEventAdapter;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;

/**
 * Abstract Gateway Adapter Base Class
 * 
 * Provides common functionality for all gateway adapters including entity
 * resolution, metadata extraction, and utility methods for event processing.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events\Adapters
 * @since   next
 */
abstract class AbstractGatewayAdapter implements GatewayEventAdapter
{
    /**
     * Gateway name identifier
     * 
     * @var string
     */
    protected string $gateway_name;

    /**
     * Supported event types for this gateway
     * 
     * @var array
     */
    protected array $supported_event_types = [];

    /**
     * Constructor
     * 
     * @param string $gateway_name Gateway identifier
     */
    public function __construct(string $gateway_name)
    {
        $this->gateway_name = $gateway_name;
    }

    /**
     * {@inheritdoc}
     */
    public function getGatewayName(): string
    {
        return $this->gateway_name;
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedEventTypes(): array
    {
        return $this->supported_event_types;
    }

    /**
     * {@inheritdoc}
     */
    public function identifyEntities(UniversalEvent $event): UniversalEvent
    {
        $event_data = $event->toArray();
        
        // Try to resolve order ID from transaction mapping
        if ($event->transactionID && !$event->primaryObjectID) {
            $order_id = $this->findOrderByTransactionId($event->transactionID);
            if ($order_id) {
                $event_data['primaryObjectType'] = 'order';
                $event_data['primaryObjectID'] = $order_id;
            }
        }

        // Try to resolve subscription ID from gateway subscription ID
        if ($event->primaryObjectType === 'subscription' && $event->primaryObjectID) {
            $subscription_id = $this->findSubscriptionByGatewayId((string) $event->primaryObjectID);
            if ($subscription_id) {
                $event_data['primaryObjectID'] = $subscription_id;
            }
        }

        // Try to resolve customer ID
        if (!$event->secondaryObjectType && $event_data['primaryObjectID']) {
            $customer_id = $this->findCustomerByEntity($event_data['primaryObjectType'], $event_data['primaryObjectID']);
            if ($customer_id) {
                $event_data['secondaryObjectType'] = 'customer';
                $event_data['secondaryObjectID'] = $customer_id;
            }
        }

        return new UniversalEvent($event_data);
    }

    /**
     * {@inheritdoc}
     */
    public function extractMetadata(array $input): array
    {
        $metadata = [
            'gateway' => $this->gateway_name,
            'received_at' => current_time('c'),
            'user_agent' => $input['headers']['user-agent'] ?? null,
            'ip_address' => $this->getClientIpAddress(),
        ];

        // Add gateway-specific metadata
        $gateway_metadata = $this->extractGatewaySpecificMetadata($input);
        
        return array_merge($metadata, $gateway_metadata);
    }

    /**
     * Find WooCommerce order by transaction ID
     * 
     * @param string $transaction_id Gateway transaction ID
     * @return int|null Order ID if found
     */
    protected function findOrderByTransactionId(string $transaction_id): ?int
    {
        global $wpdb;

        // Search in order meta for transaction ID
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_transaction_id' 
             AND meta_value = %s 
             LIMIT 1",
            $transaction_id
        ));

        return $order_id ? (int) $order_id : null;
    }

    /**
     * Find WooCommerce subscription by gateway subscription ID
     * 
     * @param string $gateway_subscription_id Gateway subscription identifier
     * @return int|null Subscription ID if found
     */
    protected function findSubscriptionByGatewayId(string $gateway_subscription_id): ?int
    {
        global $wpdb;

        if (!function_exists('wcs_get_subscriptions')) {
            return null;
        }

        // Search in subscription meta for gateway subscription ID
        $subscription_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key IN ('_paypal_subscription_id', '_stripe_subscription_id', '_gateway_subscription_id')
             AND meta_value = %s 
             LIMIT 1",
            $gateway_subscription_id
        ));

        return $subscription_id ? (int) $subscription_id : null;
    }

    /**
     * Find customer ID associated with an entity
     * 
     * @param string $entity_type Entity type (order, subscription)
     * @param int $entity_id Entity ID
     * @return int|null Customer ID if found
     */
    protected function findCustomerByEntity(string $entity_type, int $entity_id): ?int
    {
        switch ($entity_type) {
            case 'order':
                if (function_exists('wc_get_order')) {
                    $order = wc_get_order($entity_id);
                    if ($order && $order->get_customer_id()) {
                        return $order->get_customer_id();
                    }
                }
                break;

            case 'subscription':
                if (function_exists('wcs_get_subscription')) {
                    try {
                        $subscription = wcs_get_subscription($entity_id);
                        if ($subscription && method_exists($subscription, 'get_customer_id')) {
                            return $subscription->get_customer_id();
                        }
                    } catch (\Throwable $e) {
                        // Subscription not found or invalid
                    }
                }
                break;
        }

        return null;
    }

    /**
     * Get client IP address from request
     * 
     * @return string|null Client IP address
     */
    protected function getClientIpAddress(): ?string
    {
        // Check for various headers that might contain the real IP
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancers/proxies
            'HTTP_X_FORWARDED',          // Proxies
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxies
            'HTTP_FORWARDED',            // Proxies
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // Handle comma-separated list (X-Forwarded-For can contain multiple IPs)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Sanitize and validate input data
     * 
     * @param array $input Raw input data
     * @return array Sanitized input data
     */
    protected function sanitizeInput(array $input): array
    {
        $sanitized = [];

        // Sanitize headers
        if (isset($input['headers']) && is_array($input['headers'])) {
            $sanitized['headers'] = [];
            foreach ($input['headers'] as $key => $value) {
                $sanitized['headers'][sanitize_key($key)] = sanitize_text_field((string) $value);
            }
        }

        // Sanitize payload (recursive)
        if (isset($input['payload']) && is_array($input['payload'])) {
            $sanitized['payload'] = $this->sanitizeArrayRecursive($input['payload']);
        }

        // Preserve other fields
        foreach ($input as $key => $value) {
            if (!in_array($key, ['headers', 'payload'], true)) {
                if (is_string($value)) {
                    $sanitized[$key] = sanitize_text_field($value);
                } elseif (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeArrayRecursive($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize array data
     * 
     * @param array $data Array to sanitize
     * @return array Sanitized array
     */
    protected function sanitizeArrayRecursive(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $key = sanitize_key((string) $key);

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArrayRecursive($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = sanitize_text_field($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value;
            } else {
                // Convert other types to string and sanitize
                $sanitized[$key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Log adapter activity for debugging
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    protected function log(string $message, array $context = []): void
    {
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $log_message = sprintf(
                'ODCM %s Adapter: %s',
                ucfirst($this->gateway_name),
                $message
            );

            if (!empty($context)) {
                $log_message .= ' - Context: ' . wp_json_encode($context);
            }

            error_log($log_message);
        }
    }

    /**
     * Extract gateway-specific metadata (to be implemented by subclasses)
     * 
     * @param array $input Raw input data
     * @return array Gateway-specific metadata
     */
    abstract protected function extractGatewaySpecificMetadata(array $input): array;

    /**
     * Map gateway event type to universal event type (to be implemented by subclasses)
     * 
     * @param string $gateway_event_type Gateway-specific event type
     * @return string Universal event type
     */
    abstract protected function mapEventType(string $gateway_event_type): string;

    /**
     * Extract transaction ID from gateway payload (to be implemented by subclasses)
     * 
     * @param array $payload Gateway payload
     * @return string|null Transaction ID if found
     */
    abstract protected function extractTransactionId(array $payload): ?string;
}
