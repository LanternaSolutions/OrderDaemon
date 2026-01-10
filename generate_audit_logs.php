<?php
/**
 * Order Daemon Audit Log Generator
 *
 * This script generates sample audit log events for marketing screenshots.
 * It creates realistic event sequences that showcase the plugin's capabilities.
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate sample audit log events
 */
function odcm_generate_sample_audit_logs() {
    global $wpdb;

    // Check if audit log table exists
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    // Check if tables exist
    $log_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $log_table)) === $log_table;
    $payload_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $payload_table)) === $payload_table;

    if (!$log_table_exists || !$payload_table_exists) {
        return new WP_Error('missing_tables', 'Audit log tables do not exist');
    }

    // Clear existing sample data (optional)
    $wpdb->query("DELETE FROM {$log_table} WHERE is_test = 1");
    $wpdb->query("DELETE FROM {$payload_table} WHERE payload_id NOT IN (SELECT payload_id FROM {$log_table})");

    // Generate sample events
    $events_generated = [];

    // 1. Successful Payment Processing Workflow
    $events_generated[] = generate_payment_processing_workflow();

    // 2. Subscription Lifecycle Events
    $events_generated[] = generate_subscription_lifecycle();

    // 3. Order Status Workflow
    $events_generated[] = generate_order_status_workflow();

    // 4. Error Handling Scenarios
    $events_generated[] = generate_error_scenarios();

    // 5. Webhook Integration Events
    $events_generated[] = generate_webhook_events();

    // 6. Additional Payment Events
    $events_generated[] = generate_additional_payment_events();

    // 7. Subscription Management Events
    $events_generated[] = generate_subscription_management_events();

    // 8. System Configuration Events
    $events_generated[] = generate_system_configuration_events();

    return $events_generated;
}

/**
 * Generate a complete payment processing workflow
 */
function generate_payment_processing_workflow() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $order_id = 198742;
    $process_id = generate_enhanced_process_id($order_id, 'payment_processing');
    $timestamp = current_time('mysql');

    // 1. PayPal payment completed
    $payment_payload = [
        'event_type' => 'payment.paypal.payment_completed',
        'sourceGateway' => 'paypal',
        'channel' => 'webhook',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order_id,
        'transactionID' => 'PAYPAL-' . uniqid(),
        'status' => 'completed',
        'amount' => 99.99,
        'currency' => 'USD',
        'occurredAt' => date('c', strtotime('-5 minutes')),
        'receivedAt' => date('c'),
        'idempotencyKey' => 'odcm_paypal_' . uniqid(),
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'resource' => [
                    'id' => 'PAYPAL-' . uniqid(),
                    'status' => 'COMPLETED',
                    'amount' => ['value' => '99.99', 'currency_code' => 'USD']
                ]
            ]
        ]
    ];

    $payment_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.payment_completed',
        'status' => 'success',
        'summary' => 'PayPal payment completed for order #' . $order_id,
        'details' => json_encode($payment_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. Rule execution triggered by payment
    $rule_payload = [
        'rule_execution' => [
            'rule_name' => 'Send Order Confirmation Email',
            'rule_id' => 1,
            'trigger' => 'payment_complete',
            'conditions' => [['type' => 'order_total', 'operator' => '>', 'value' => 50]],
            'actions' => [['type' => 'send_email', 'recipient' => 'customer@example.com']],
            'execution_time' => 1.23,
            'memory_usage' => 2048,
            'result' => 'success'
        ],
        'order_data' => ['order_id' => $order_id, 'total' => 99.99]
    ];

    $rule_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-4 minutes')),
        'order_id' => $order_id,
        'event_type' => 'rule_execution',
        'status' => 'success',
        'summary' => 'Rule "Send Order Confirmation Email" executed successfully',
        'details' => json_encode($rule_payload),
        'source' => 'scheduled',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 3. Email notification sent
    $email_payload = [
        'recipient' => 'customer@example.com',
        'subject' => 'Your Order #' . $order_id . ' Confirmation',
        'content' => 'Thank you for your purchase!',
        'status' => 'sent',
        'email_type' => 'order_confirmation'
    ];

    $email_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-3 minutes')),
        'order_id' => $order_id,
        'event_type' => 'custom_email_sent',
        'status' => 'success',
        'summary' => 'Order confirmation email sent to customer',
        'details' => json_encode($email_payload),
        'source' => 'rule_action',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'order_id' => $order_id,
        'events' => [$payment_log_id, $rule_log_id, $email_log_id],
        'type' => 'payment_processing'
    ];
}

/**
 * Generate subscription lifecycle events
 */
function generate_subscription_lifecycle() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $order_id = 204873;
    $process_id = generate_enhanced_process_id($order_id, 'subscription_lifecycle');
    $subscription_id = 'SUB-' . uniqid();

    // 1. Subscription created
    $subscription_created_payload = [
        'event_type' => 'payment.paypal.subscription_created',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'subscription',
        'primaryObjectID' => $subscription_id,
        'secondaryObjectType' => 'order',
        'secondaryObjectID' => $order_id,
        'status' => 'active',
        'amount' => 29.99,
        'currency' => 'USD',
        'billing_cycle' => 'monthly',
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'BILLING.SUBSCRIPTION.CREATED',
                'resource' => [
                    'id' => $subscription_id,
                    'status' => 'ACTIVE',
                    'billing_info' => ['cycle_executions' => [['tenure_type' => 'REGULAR', 'sequence' => 1]]]
                ]
            ]
        ]
    ];

    $sub_created_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.subscription_created',
        'status' => 'success',
        'summary' => 'New subscription created: ' . $subscription_id,
        'details' => json_encode($subscription_created_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. First renewal payment
    $renewal_payload = [
        'event_type' => 'payment.paypal.renewal_payment_completed',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'subscription',
        'primaryObjectID' => $subscription_id,
        'secondaryObjectType' => 'order',
        'secondaryObjectID' => $order_id,
        'status' => 'completed',
        'amount' => 29.99,
        'currency' => 'USD',
        'renewal_number' => 1,
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'resource' => [
                    'id' => 'PAYPAL-' . uniqid(),
                    'status' => 'COMPLETED',
                    'amount' => ['value' => '29.99', 'currency_code' => 'USD'],
                    'supplementary_data' => ['related_ids' => ['subscription_id' => $subscription_id]]
                ]
            ]
        ]
    ];

    $renewal_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-1 day')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.renewal_payment_completed',
        'status' => 'success',
        'summary' => 'Subscription renewal payment completed',
        'details' => json_encode($renewal_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'subscription_id' => $subscription_id,
        'order_id' => $order_id,
        'events' => [$sub_created_log_id, $renewal_log_id],
        'type' => 'subscription_lifecycle'
    ];
}

/**
 * Generate order status workflow events
 */
function generate_order_status_workflow() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $order_id = 185629;
    $process_id = generate_enhanced_process_id($order_id, 'order_status_workflow');

    // 1. Checkout processed
    $checkout_payload = [
        'order_id' => $order_id,
        'status' => 'processing',
        'payment_method' => 'paypal',
        'total' => 79.99,
        'items' => [['product' => 'Premium Product', 'quantity' => 1, 'price' => 79.99]]
    ];

    $checkout_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'order_id' => $order_id,
        'event_type' => 'checkout_processed',
        'status' => 'success',
        'summary' => 'Checkout completed for order #' . $order_id,
        'details' => json_encode($checkout_payload),
        'source' => 'woocommerce',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. Status changed to processing
    $status_payload = [
        'old_status' => 'pending',
        'new_status' => 'processing',
        'order_id' => $order_id,
        'user_id' => 1,
        'reason' => 'Payment received'
    ];

    $status_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-55 minutes')),
        'order_id' => $order_id,
        'event_type' => 'status_changed',
        'status' => 'success',
        'summary' => 'Order status changed from pending to processing',
        'details' => json_encode($status_payload),
        'source' => 'manual',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 3. Rule execution for status change
    $rule_payload = [
        'rule_execution' => [
            'rule_name' => 'Update Customer on Processing',
            'rule_id' => 2,
            'trigger' => 'order_status_changed',
            'conditions' => [['type' => 'status', 'operator' => '=', 'value' => 'processing']],
            'actions' => [['type' => 'send_email', 'recipient' => 'customer@example.com']],
            'execution_time' => 0.87,
            'result' => 'success'
        ]
    ];

    $rule_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-50 minutes')),
        'order_id' => $order_id,
        'event_type' => 'rule_execution',
        'status' => 'success',
        'summary' => 'Rule "Update Customer on Processing" executed',
        'details' => json_encode($rule_payload),
        'source' => 'scheduled',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'order_id' => $order_id,
        'events' => [$checkout_log_id, $status_log_id, $rule_log_id],
        'type' => 'order_status_workflow'
    ];
}

/**
 * Generate error handling scenarios
 */
function generate_error_scenarios() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $order_id = 172635;
    $process_id = generate_enhanced_process_id($order_id, 'error_scenarios');

    // 1. Payment failed
    $payment_failed_payload = [
        'event_type' => 'payment.paypal.payment_failed',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order_id,
        'status' => 'failed',
        'amount' => 49.99,
        'currency' => 'USD',
        'error_code' => 'PAYMENT_DECLINED',
        'error_message' => 'Insufficient funds',
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'PAYMENT.CAPTURE.DENIED',
                'resource' => [
                    'id' => 'PAYPAL-' . uniqid(),
                    'status' => 'DENIED',
                    'amount' => ['value' => '49.99', 'currency_code' => 'USD'],
                    'failure_reason' => 'INSUFFICIENT_FUNDS'
                ]
            ]
        ]
    ];

    $payment_failed_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.payment_failed',
        'status' => 'error',
        'summary' => 'PayPal payment failed: Insufficient funds',
        'details' => json_encode($payment_failed_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. Rule execution failed
    $rule_failed_payload = [
        'rule_execution' => [
            'rule_name' => 'Handle Failed Payment',
            'rule_id' => 3,
            'trigger' => 'payment_failed',
            'error' => 'Failed to send notification email',
            'execution_time' => 2.15,
            'result' => 'error'
        ]
    ];

    $rule_failed_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-25 minutes')),
        'order_id' => $order_id,
        'event_type' => 'rule_execution_failed',
        'status' => 'error',
        'summary' => 'Rule execution failed: Handle Failed Payment',
        'details' => json_encode($rule_failed_payload),
        'source' => 'scheduled',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 3. Email sending failed
    $email_failed_payload = [
        'recipient' => 'customer@example.com',
        'subject' => 'Payment Failed Notification',
        'error' => 'SMTP connection timeout',
        'email_type' => 'payment_failed'
    ];

    $email_failed_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-20 minutes')),
        'order_id' => $order_id,
        'event_type' => 'custom_email_failed',
        'status' => 'error',
        'summary' => 'Failed to send payment failed notification',
        'details' => json_encode($email_failed_payload),
        'source' => 'rule_action',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'order_id' => $order_id,
        'events' => [$payment_failed_log_id, $rule_failed_log_id, $email_failed_log_id],
        'type' => 'error_handling'
    ];
}

/**
 * Generate webhook integration events
 */
function generate_webhook_events() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $order_id = 164928;
    $process_id = generate_enhanced_process_id($order_id, 'webhook_integration');

    // 1. Webhook received
    $webhook_received_payload = [
        'gateway' => 'paypal',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'webhook_id' => 'WH-' . uniqid(),
        'status' => 'received',
        'timestamp' => date('c'),
        'raw_payload' => [
            'id' => 'PAYPAL-' . uniqid(),
            'status' => 'COMPLETED',
            'amount' => ['value' => '59.99', 'currency_code' => 'USD']
        ]
    ];

    $webhook_received_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
        'order_id' => $order_id,
        'event_type' => 'webhook_reception',
        'status' => 'success',
        'summary' => 'PayPal webhook received: PAYMENT.CAPTURE.COMPLETED',
        'details' => json_encode($webhook_received_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. Webhook processing
    $webhook_processing_payload = [
        'webhook_id' => $webhook_received_payload['webhook_id'],
        'gateway' => 'paypal',
        'event_type' => 'payment.paypal.payment_completed',
        'order_id' => $order_id,
        'status' => 'processed',
        'processing_time' => 0.45,
        'result' => 'success'
    ];

    $webhook_processing_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-9 minutes')),
        'order_id' => $order_id,
        'event_type' => 'webhook_processing',
        'status' => 'success',
        'summary' => 'Webhook processed successfully',
        'details' => json_encode($webhook_processing_payload),
        'source' => 'system',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 3. Universal event processing
    $universal_event_payload = [
        'event_type' => 'payment.paypal.payment_completed',
        'order_id' => $order_id,
        'gateway' => 'paypal',
        'status' => 'completed',
        'amount' => 59.99,
        'currency' => 'USD',
        'processing_result' => 'order_updated'
    ];

    $universal_event_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-8 minutes')),
        'order_id' => $order_id,
        'event_type' => 'universal_event_processing',
        'status' => 'success',
        'summary' => 'Universal event processed: payment completed',
        'details' => json_encode($universal_event_payload),
        'source' => 'system',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'order_id' => $order_id,
        'events' => [$webhook_received_log_id, $webhook_processing_log_id, $universal_event_log_id],
        'type' => 'webhook_integration'
    ];
}

/**
 * Insert an audit log entry with payload table support
 */
function insert_audit_log_entry($data) {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $defaults = [
        'timestamp' => current_time('mysql'),
        'order_id' => null,
        'event_type' => 'unknown',
        'status' => 'info',
        'summary' => '',
        'details' => '{}',
        'source' => 'system',
        'is_test' => 0,
        'process_id' => null
    ];

    $data = wp_parse_args($data, $defaults);

    // Insert into log table
    $result = $wpdb->insert($log_table, [
        'timestamp' => $data['timestamp'],
        'order_id' => $data['order_id'],
        'event_type' => $data['event_type'],
        'status' => $data['status'],
        'summary' => $data['summary'],
        'details' => $data['details'],
        'source' => $data['source'],
        'is_test' => $data['is_test'],
        'process_id' => $data['process_id']
    ]);

    if ($result === false) {
        return false;
    }

    $log_id = $wpdb->insert_id;

    // Insert into payload table if details contain substantial data
    $details_data = json_decode($data['details'], true);
    if (!empty($details_data) && is_array($details_data) && count($details_data) > 1) {
        $payload_result = $wpdb->insert($payload_table, [
            'payload' => $data['details'],
            'format' => 'json'
        ]);

        if ($payload_result !== false) {
            $payload_id = $wpdb->insert_id;
            $wpdb->update($log_table, ['payload_id' => $payload_id], ['log_id' => $log_id]);
        }
    }

    return $log_id;
}

/**
 * Generate enhanced process ID using ProcessIdManager if available
 */
function generate_enhanced_process_id($order_id, $type) {
    // Try to use ProcessIdManager for more realistic process IDs
    if (class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessIdManager') && $order_id > 0) {
        try {
            $process_manager = \OrderDaemon\CompletionManager\Core\ProcessIdManager::instance();
            return $process_manager->get_or_create_process_id($order_id);
        } catch (\Throwable $e) {
            // Fall back to manual generation if ProcessIdManager fails
        }
    }

    // Fallback to manual process ID generation
    return 'odcm_' . uniqid() . '_' . $type;
}

/**
 * Generate additional payment events (pending, refunded)
 */
function generate_additional_payment_events() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $process_id = generate_enhanced_process_id(12350, 'payment_additional');
    $order_id = 12350;

    // 1. Payment pending
    $payment_pending_payload = [
        'event_type' => 'payment.paypal.payment_pending',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order_id,
        'status' => 'pending',
        'amount' => 149.99,
        'currency' => 'USD',
        'reason' => 'Payment review required',
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'PAYMENT.CAPTURE.PENDING',
                'resource' => [
                    'id' => 'PAYPAL-' . uniqid(),
                    'status' => 'PENDING',
                    'amount' => ['value' => '149.99', 'currency_code' => 'USD'],
                    'seller_protection' => ['status' => 'ELIGIBLE']
                ]
            ]
        ]
    ];

    $pending_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.payment_pending',
        'status' => 'warning',
        'summary' => 'PayPal payment pending review for order #' . $order_id,
        'details' => json_encode($payment_pending_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. Payment refunded
    $payment_refunded_payload = [
        'event_type' => 'payment.paypal.payment_refunded',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order_id,
        'status' => 'refunded',
        'amount' => 149.99,
        'currency' => 'USD',
        'refund_reason' => 'Customer requested refund',
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
                'resource' => [
                    'id' => 'PAYPAL-' . uniqid(),
                    'status' => 'REFUNDED',
                    'amount' => ['value' => '149.99', 'currency_code' => 'USD'],
                    'seller_protection' => ['status' => 'ELIGIBLE']
                ]
            ]
        ]
    ];

    $refunded_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.payment_refunded',
        'status' => 'info',
        'summary' => 'PayPal payment refunded for order #' . $order_id,
        'details' => json_encode($payment_refunded_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'order_id' => $order_id,
        'events' => [$pending_log_id, $refunded_log_id],
        'type' => 'additional_payment_events'
    ];
}

/**
 * Generate subscription management events (cancelled, suspended, reactivated)
 */
function generate_subscription_management_events() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $process_id = generate_enhanced_process_id(12351, 'subscription_mgmt');
    $subscription_id = 'SUB-' . uniqid();
    $order_id = 12351;

    // 1. Subscription cancelled
    $subscription_cancelled_payload = [
        'event_type' => 'payment.paypal.subscription_cancelled',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'subscription',
        'primaryObjectID' => $subscription_id,
        'secondaryObjectType' => 'order',
        'secondaryObjectID' => $order_id,
        'status' => 'cancelled',
        'amount' => 29.99,
        'currency' => 'USD',
        'cancel_reason' => 'Customer requested cancellation',
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
                'resource' => [
                    'id' => $subscription_id,
                    'status' => 'CANCELLED',
                    'billing_info' => ['outstanding_balance' => ['value' => '0.00', 'currency_code' => 'USD']]
                ]
            ]
        ]
    ];

    $cancelled_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.subscription_cancelled',
        'status' => 'info',
        'summary' => 'Subscription cancelled: ' . $subscription_id,
        'details' => json_encode($subscription_cancelled_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. Subscription suspended
    $subscription_suspended_payload = [
        'event_type' => 'payment.paypal.subscription_suspended',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'subscription',
        'primaryObjectID' => $subscription_id,
        'secondaryObjectType' => 'order',
        'secondaryObjectID' => $order_id,
        'status' => 'suspended',
        'amount' => 29.99,
        'currency' => 'USD',
        'suspend_reason' => 'Payment failed',
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'BILLING.SUBSCRIPTION.SUSPENDED',
                'resource' => [
                    'id' => $subscription_id,
                    'status' => 'SUSPENDED',
                    'billing_info' => ['outstanding_balance' => ['value' => '29.99', 'currency_code' => 'USD']]
                ]
            ]
        ]
    ];

    $suspended_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.subscription_suspended',
        'status' => 'warning',
        'summary' => 'Subscription suspended due to payment failure: ' . $subscription_id,
        'details' => json_encode($subscription_suspended_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 3. Subscription reactivated
    $subscription_reactivated_payload = [
        'event_type' => 'payment.paypal.subscription_reactivated',
        'sourceGateway' => 'paypal',
        'primaryObjectType' => 'subscription',
        'primaryObjectID' => $subscription_id,
        'secondaryObjectType' => 'order',
        'secondaryObjectID' => $order_id,
        'status' => 'active',
        'amount' => 29.99,
        'currency' => 'USD',
        'reactivation_reason' => 'Customer updated payment method',
        'rawData' => [
            'paypal_webhook_data' => [
                'event_type' => 'BILLING.SUBSCRIPTION.RE-ACTIVATED',
                'resource' => [
                    'id' => $subscription_id,
                    'status' => 'ACTIVE',
                    'billing_info' => ['cycle_executions' => [['tenure_type' => 'REGULAR', 'sequence' => 2]]]
                ]
            ]
        ]
    ];

    $reactivated_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-1 day')),
        'order_id' => $order_id,
        'event_type' => 'payment.paypal.subscription_reactivated',
        'status' => 'success',
        'summary' => 'Subscription reactivated: ' . $subscription_id,
        'details' => json_encode($subscription_reactivated_payload),
        'source' => 'webhook',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'subscription_id' => $subscription_id,
        'order_id' => $order_id,
        'events' => [$cancelled_log_id, $suspended_log_id, $reactivated_log_id],
        'type' => 'subscription_management'
    ];
}

/**
 * Generate system configuration events
 */
function generate_system_configuration_events() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

    $process_id = generate_enhanced_process_id(0, 'system_config');

    // 1. Configuration export
    $config_export_payload = [
        'event_type' => 'config_export',
        'action' => 'export_settings',
        'user_id' => 1,
        'user_role' => 'administrator',
        'export_format' => 'json',
        'settings_exported' => [
            'rule_settings' => true,
            'notification_settings' => true,
            'integration_settings' => true,
            'advanced_settings' => false
        ],
        'timestamp' => date('c')
    ];

    $export_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        'order_id' => null,
        'event_type' => 'config_export',
        'status' => 'success',
        'summary' => 'Configuration settings exported by administrator',
        'details' => json_encode($config_export_payload),
        'source' => 'manual',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    // 2. Configuration import
    $config_import_payload = [
        'event_type' => 'config_import',
        'action' => 'import_settings',
        'user_id' => 1,
        'user_role' => 'administrator',
        'import_format' => 'json',
        'settings_imported' => [
            'rule_settings' => true,
            'notification_settings' => true,
            'integration_settings' => false,
            'advanced_settings' => true
        ],
        'import_result' => [
            'success_count' => 12,
            'failed_count' => 1,
            'skipped_count' => 3
        ],
        'timestamp' => date('c')
    ];

    $import_log_id = insert_audit_log_entry([
        'timestamp' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
        'order_id' => null,
        'event_type' => 'config_import',
        'status' => 'success',
        'summary' => 'Configuration settings imported by administrator',
        'details' => json_encode($config_import_payload),
        'source' => 'manual',
        'is_test' => 1,
        'process_id' => $process_id
    ]);

    return [
        'process_id' => $process_id,
        'order_id' => null,
        'events' => [$export_log_id, $import_log_id],
        'type' => 'system_configuration'
    ];
}

// Add WP-CLI command for easy execution
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('odcm generate-audit-logs', function($args, $assoc_args) {
        $result = odcm_generate_sample_audit_logs();

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
            return;
        }

        WP_CLI::success(sprintf('Generated %d event groups with %d total events', count($result), array_sum(array_map(function($group) {
            return count($group['events']);
        }, $result))));

        foreach ($result as $group) {
            WP_CLI::line(sprintf('  - %s: %d events in process %s', $group['type'], count($group['events']), $group['process_id']));
        }
    });
}
