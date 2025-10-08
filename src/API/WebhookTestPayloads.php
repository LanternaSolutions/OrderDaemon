<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API;

/**
 * Webhook Test Payload Generator
 * 
 * Generates realistic sample webhook payloads for testing different gateway types
 * and event scenarios. All generated payloads are marked as test events.
 * 
 * @package OrderDaemon\CompletionManager\API
 * @since   1.1.1
 */
class WebhookTestPayloads
{
    /**
     * Generate test payload for specified gateway and event type
     * 
     * @param string $gateway Gateway name (paypal, stripe, generic)
     * @param string $event_type Event type to simulate
     * @return array Test payload data
     */
    public static function generate(string $gateway, string $event_type = 'payment_completed'): array
    {
        $base_payload = [
            '_odcm_test' => true,
            '_test_timestamp' => current_time('c'),
            '_test_gateway' => $gateway,
            '_test_event_type' => $event_type,
            '_test_user_id' => get_current_user_id(),
            '_test_user_login' => wp_get_current_user()->user_login,
        ];

        // First, try to get payload from a registered generator (plugin-based)
        $custom_payload = self::getCustomPayload($gateway, $event_type);
        if ($custom_payload !== null) {
            return array_merge($base_payload, $custom_payload);
        }

        // Fall back to built-in generators
        switch (strtolower($gateway)) {
            case 'paypal':
                return array_merge($base_payload, self::generatePayPalPayload($event_type));
            
            case 'stripe':
                return array_merge($base_payload, self::generateStripePayload($event_type));
            
            case 'square':
                return array_merge($base_payload, self::generateSquarePayload($event_type));
            
            case 'woocommerce_payments':
                return array_merge($base_payload, self::generateWooCommercePaymentsPayload($event_type));
            
            case 'generic':
            default:
                return array_merge($base_payload, self::generateGenericPayload($event_type));
        }
    }

    /**
     * Get custom payload from registered generators
     * 
     * @param string $gateway Gateway name
     * @param string $event_type Event type
     * @return array|null Custom payload or null if none registered
     */
    private static function getCustomPayload(string $gateway, string $event_type): ?array
    {
        /**
         * Filter to allow custom webhook test payload generation
         * 
         * @param array|null $payload Custom payload (return null to use default)
         * @param string $gateway Gateway name
         * @param string $event_type Event type
         */
        $custom_payload = apply_filters('odcm_webhook_test_payload', null, $gateway, $event_type);
        
        return is_array($custom_payload) ? $custom_payload : null;
    }

    /**
     * Generate PayPal webhook test payload
     * 
     * @param string $event_type Event type to simulate
     * @return array PayPal-formatted payload
     */
    private static function generatePayPalPayload(string $event_type): array
    {
        $order_id = self::getTestOrderId();
        $transaction_id = 'TEST_' . strtoupper(uniqid());
        
        $base = [
            'id' => 'WH-' . strtoupper(uniqid()),
            'create_time' => current_time('c'),
            'resource_type' => 'sale',
            'event_version' => '1.0',
            'summary' => 'Payment completed for test order',
        ];

        switch ($event_type) {
            case 'payment_completed':
                return array_merge($base, [
                    'event_type' => 'PAYMENT.SALE.COMPLETED',
                    'resource' => [
                        'id' => $transaction_id,
                        'state' => 'completed',
                        'amount' => [
                            'total' => '29.99',
                            'currency' => 'USD',
                        ],
                        'parent_payment' => 'PAY-' . strtoupper(uniqid()),
                        'invoice_number' => (string) $order_id,
                        'custom' => json_encode(['order_id' => $order_id]),
                        'create_time' => current_time('c'),
                        'update_time' => current_time('c'),
                    ],
                ]);

            case 'payment_refunded':
                return array_merge($base, [
                    'event_type' => 'PAYMENT.SALE.REFUNDED',
                    'resource' => [
                        'id' => 'REF-' . strtoupper(uniqid()),
                        'state' => 'completed',
                        'amount' => [
                            'total' => '29.99',
                            'currency' => 'USD',
                        ],
                        'sale_id' => $transaction_id,
                        'invoice_number' => (string) $order_id,
                        'create_time' => current_time('c'),
                        'update_time' => current_time('c'),
                    ],
                ]);

            case 'payment_failed':
                return array_merge($base, [
                    'event_type' => 'PAYMENT.SALE.DENIED',
                    'resource' => [
                        'id' => $transaction_id,
                        'state' => 'denied',
                        'amount' => [
                            'total' => '29.99',
                            'currency' => 'USD',
                        ],
                        'reason_code' => 'INSUFFICIENT_FUNDS',
                        'invoice_number' => (string) $order_id,
                        'create_time' => current_time('c'),
                        'update_time' => current_time('c'),
                    ],
                ]);

            default:
                return array_merge($base, [
                    'event_type' => 'PAYMENT.SALE.COMPLETED',
                    'resource' => [
                        'id' => $transaction_id,
                        'state' => 'completed',
                        'amount' => [
                            'total' => '29.99',
                            'currency' => 'USD',
                        ],
                        'invoice_number' => (string) $order_id,
                        'create_time' => current_time('c'),
                    ],
                ]);
        }
    }

    /**
     * Generate Stripe webhook test payload
     * 
     * @param string $event_type Event type to simulate
     * @return array Stripe-formatted payload
     */
    private static function generateStripePayload(string $event_type): array
    {
        $order_id = self::getTestOrderId();
        $payment_intent_id = 'pi_test_' . strtolower(uniqid());
        
        $base = [
            'id' => 'evt_test_' . strtolower(uniqid()),
            'object' => 'event',
            'api_version' => '2020-08-27',
            'created' => time(),
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => [
                'id' => 'req_test_' . strtolower(uniqid()),
                'idempotency_key' => null,
            ],
        ];

        switch ($event_type) {
            case 'payment_completed':
                return array_merge($base, [
                    'type' => 'payment_intent.succeeded',
                    'data' => [
                        'object' => [
                            'id' => $payment_intent_id,
                            'object' => 'payment_intent',
                            'amount' => 2999, // $29.99 in cents
                            'currency' => 'usd',
                            'status' => 'succeeded',
                            'metadata' => [
                                'order_id' => (string) $order_id,
                            ],
                            'created' => time(),
                            'description' => 'Test payment for order #' . $order_id,
                        ],
                    ],
                ]);

            case 'payment_refunded':
                return array_merge($base, [
                    'type' => 'charge.dispute.created',
                    'data' => [
                        'object' => [
                            'id' => 're_test_' . strtolower(uniqid()),
                            'object' => 'refund',
                            'amount' => 2999,
                            'currency' => 'usd',
                            'status' => 'succeeded',
                            'payment_intent' => $payment_intent_id,
                            'metadata' => [
                                'order_id' => (string) $order_id,
                            ],
                            'created' => time(),
                        ],
                    ],
                ]);

            case 'payment_failed':
                return array_merge($base, [
                    'type' => 'payment_intent.payment_failed',
                    'data' => [
                        'object' => [
                            'id' => $payment_intent_id,
                            'object' => 'payment_intent',
                            'amount' => 2999,
                            'currency' => 'usd',
                            'status' => 'requires_payment_method',
                            'last_payment_error' => [
                                'code' => 'card_declined',
                                'decline_code' => 'insufficient_funds',
                                'message' => 'Your card has insufficient funds.',
                            ],
                            'metadata' => [
                                'order_id' => (string) $order_id,
                            ],
                            'created' => time(),
                        ],
                    ],
                ]);

            default:
                return array_merge($base, [
                    'type' => 'payment_intent.succeeded',
                    'data' => [
                        'object' => [
                            'id' => $payment_intent_id,
                            'object' => 'payment_intent',
                            'amount' => 2999,
                            'currency' => 'usd',
                            'status' => 'succeeded',
                            'metadata' => [
                                'order_id' => (string) $order_id,
                            ],
                            'created' => time(),
                        ],
                    ],
                ]);
        }
    }

    /**
     * Generate Square webhook test payload
     * 
     * @param string $event_type Event type to simulate
     * @return array Square-formatted payload
     */
    private static function generateSquarePayload(string $event_type): array
    {
        $order_id = self::getTestOrderId();
        $payment_id = 'sq_test_' . strtolower(uniqid());
        
        $base = [
            'merchant_id' => 'TEST_MERCHANT_' . strtoupper(uniqid()),
            'type' => 'payment.updated',
            'event_id' => 'sq_evt_' . strtolower(uniqid()),
            'created_at' => current_time('c'),
        ];

        switch ($event_type) {
            case 'payment_completed':
                return array_merge($base, [
                    'data' => [
                        'type' => 'payment',
                        'id' => $payment_id,
                        'object' => [
                            'payment' => [
                                'id' => $payment_id,
                                'status' => 'COMPLETED',
                                'amount_money' => [
                                    'amount' => 2999, // $29.99 in cents
                                    'currency' => 'USD',
                                ],
                                'order_id' => 'sq_order_' . $order_id,
                                'reference_id' => (string) $order_id,
                                'created_at' => current_time('c'),
                                'updated_at' => current_time('c'),
                            ],
                        ],
                    ],
                ]);

            case 'payment_refunded':
                return array_merge($base, [
                    'type' => 'refund.updated',
                    'data' => [
                        'type' => 'refund',
                        'id' => 'sq_refund_' . strtolower(uniqid()),
                        'object' => [
                            'refund' => [
                                'id' => 'sq_refund_' . strtolower(uniqid()),
                                'status' => 'COMPLETED',
                                'amount_money' => [
                                    'amount' => 2999,
                                    'currency' => 'USD',
                                ],
                                'payment_id' => $payment_id,
                                'order_id' => 'sq_order_' . $order_id,
                                'created_at' => current_time('c'),
                                'updated_at' => current_time('c'),
                            ],
                        ],
                    ],
                ]);

            case 'payment_failed':
                return array_merge($base, [
                    'data' => [
                        'type' => 'payment',
                        'id' => $payment_id,
                        'object' => [
                            'payment' => [
                                'id' => $payment_id,
                                'status' => 'FAILED',
                                'amount_money' => [
                                    'amount' => 2999,
                                    'currency' => 'USD',
                                ],
                                'order_id' => 'sq_order_' . $order_id,
                                'reference_id' => (string) $order_id,
                                'created_at' => current_time('c'),
                                'updated_at' => current_time('c'),
                            ],
                        ],
                    ],
                ]);

            default:
                return array_merge($base, [
                    'data' => [
                        'type' => 'payment',
                        'id' => $payment_id,
                        'object' => [
                            'payment' => [
                                'id' => $payment_id,
                                'status' => 'COMPLETED',
                                'amount_money' => [
                                    'amount' => 2999,
                                    'currency' => 'USD',
                                ],
                                'order_id' => 'sq_order_' . $order_id,
                                'reference_id' => (string) $order_id,
                                'created_at' => current_time('c'),
                            ],
                        ],
                    ],
                ]);
        }
    }

    /**
     * Generate WooCommerce Payments webhook test payload
     * 
     * @param string $event_type Event type to simulate
     * @return array WooCommerce Payments-formatted payload
     */
    private static function generateWooCommercePaymentsPayload(string $event_type): array
    {
        $order_id = self::getTestOrderId();
        $payment_intent_id = 'pi_test_' . strtolower(uniqid());
        
        // WooCommerce Payments uses Stripe-like format
        $base = [
            'id' => 'evt_test_' . strtolower(uniqid()),
            'object' => 'event',
            'api_version' => '2020-08-27',
            'created' => time(),
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => [
                'id' => 'req_test_' . strtolower(uniqid()),
                'idempotency_key' => null,
            ],
        ];

        switch ($event_type) {
            case 'payment_completed':
                return array_merge($base, [
                    'type' => 'payment_intent.succeeded',
                    'data' => [
                        'object' => [
                            'id' => $payment_intent_id,
                            'object' => 'payment_intent',
                            'amount' => 2999,
                            'currency' => 'usd',
                            'status' => 'succeeded',
                            'metadata' => [
                                'order_id' => (string) $order_id,
                                'order_key' => 'wc_order_' . $order_id,
                                'payment_type' => 'single',
                            ],
                            'created' => time(),
                            'description' => 'WooCommerce Payments test for order #' . $order_id,
                        ],
                    ],
                ]);

            case 'payment_refunded':
                return array_merge($base, [
                    'type' => 'charge.dispute.created',
                    'data' => [
                        'object' => [
                            'id' => 'dp_test_' . strtolower(uniqid()),
                            'object' => 'dispute',
                            'amount' => 2999,
                            'currency' => 'usd',
                            'status' => 'warning_needs_response',
                            'payment_intent' => $payment_intent_id,
                            'metadata' => [
                                'order_id' => (string) $order_id,
                                'order_key' => 'wc_order_' . $order_id,
                            ],
                            'created' => time(),
                        ],
                    ],
                ]);

            case 'payment_failed':
                return array_merge($base, [
                    'type' => 'payment_intent.payment_failed',
                    'data' => [
                        'object' => [
                            'id' => $payment_intent_id,
                            'object' => 'payment_intent',
                            'amount' => 2999,
                            'currency' => 'usd',
                            'status' => 'requires_payment_method',
                            'last_payment_error' => [
                                'code' => 'card_declined',
                                'decline_code' => 'insufficient_funds',
                                'message' => 'Your card has insufficient funds.',
                            ],
                            'metadata' => [
                                'order_id' => (string) $order_id,
                                'order_key' => 'wc_order_' . $order_id,
                            ],
                            'created' => time(),
                        ],
                    ],
                ]);

            default:
                return array_merge($base, [
                    'type' => 'payment_intent.succeeded',
                    'data' => [
                        'object' => [
                            'id' => $payment_intent_id,
                            'object' => 'payment_intent',
                            'amount' => 2999,
                            'currency' => 'usd',
                            'status' => 'succeeded',
                            'metadata' => [
                                'order_id' => (string) $order_id,
                                'order_key' => 'wc_order_' . $order_id,
                            ],
                            'created' => time(),
                        ],
                    ],
                ]);
        }
    }

    /**
     * Generate generic webhook test payload
     * 
     * @param string $event_type Event type to simulate
     * @return array Generic payload format
     */
    private static function generateGenericPayload(string $event_type): array
    {
        $order_id = self::getTestOrderId();
        $transaction_id = 'TEST_TXN_' . strtoupper(uniqid());
        
        return [
            'event' => $event_type,
            'transaction_id' => $transaction_id,
            'order_id' => $order_id,
            'amount' => '29.99',
            'currency' => 'USD',
            'status' => $event_type === 'payment_failed' ? 'failed' : 'completed',
            'timestamp' => current_time('c'),
            'gateway' => 'generic',
            'test_mode' => true,
            'metadata' => [
                'source' => 'webhook_test',
                'user_id' => get_current_user_id(),
                'test_scenario' => $event_type,
            ],
        ];
    }

    /**
     * Get a test order ID (either existing or create a mock one)
     * 
     * @return int Test order ID
     */
    private static function getTestOrderId(): int
    {
        // Try to find an existing order for testing
        $orders = wc_get_orders([
            'limit' => 1,
            'status' => ['processing', 'on-hold', 'pending'],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!empty($orders)) {
            return $orders[0]->get_id();
        }

        // If no orders exist, return a mock order ID
        // This will be handled gracefully by the event processing system
        return 99999;
    }

    /**
     * Get available event types for a gateway
     * 
     * @param string $gateway Gateway name
     * @return array Available event types
     */
    public static function getAvailableEventTypes(string $gateway): array
    {
        // Base common events that all gateways support
        $common_events = [
            'payment_completed' => 'Payment Completed',
            'payment_refunded' => 'Payment Refunded',
            'payment_failed' => 'Payment Failed',
        ];

        // Gateway-specific events
        $gateway_events = self::getGatewaySpecificEvents(strtolower($gateway));
        
        // Allow plugins to filter event types
        $event_types = array_merge($common_events, $gateway_events);
        
        /**
         * Filter available webhook test event types for a gateway
         * 
         * @param array $event_types Available event types
         * @param string $gateway Gateway name
         */
        return apply_filters('odcm_webhook_test_event_types', $event_types, $gateway);
    }

    /**
     * Get gateway-specific event types
     * 
     * @param string $gateway Gateway name (lowercase)
     * @return array Gateway-specific event types
     */
    private static function getGatewaySpecificEvents(string $gateway): array
    {
        $gateway_events = [
            'paypal' => [
                'subscription_created' => 'Subscription Created',
                'subscription_cancelled' => 'Subscription Cancelled',
                'billing_agreement_created' => 'Billing Agreement Created',
                'payment_authorization_created' => 'Payment Authorization Created',
            ],
            'stripe' => [
                'invoice_paid' => 'Invoice Paid',
                'customer_created' => 'Customer Created',
                'subscription_created' => 'Subscription Created',
                'payment_intent_succeeded' => 'Payment Intent Succeeded',
                'charge_dispute_created' => 'Charge Dispute Created',
            ],
            'square' => [
                'payment_updated' => 'Payment Updated',
                'refund_updated' => 'Refund Updated',
                'order_updated' => 'Order Updated',
            ],
            'woocommerce_payments' => [
                'payment_intent_succeeded' => 'Payment Intent Succeeded',
                'charge_captured' => 'Charge Captured',
                'dispute_created' => 'Dispute Created',
            ],
        ];

        return $gateway_events[$gateway] ?? [];
    }

    /**
     * Validate if an event type is supported for a gateway
     * 
     * @param string $gateway Gateway name
     * @param string $event_type Event type
     * @return bool True if supported
     */
    public static function isEventTypeSupported(string $gateway, string $event_type): bool
    {
        $available_events = self::getAvailableEventTypes($gateway);
        return array_key_exists($event_type, $available_events);
    }
}
