# Order Daemon Audit Log Generator

This script generates sample audit log events for marketing screenshots of the Order Daemon and Order Daemon Pro plugins.

## Features

- Generates realistic event sequences that showcase plugin capabilities
- Creates consolidated event groups for the insight dashboard
- Supports multiple event types including payments, subscriptions, rules, emails, and webhooks
- Marks all generated events as test data for easy cleanup

## Event Types Generated

### 1. Payment Processing Workflow
- `payment.paypal.payment_completed` - PayPal payment completion
- `rule_execution` - Rule execution triggered by payment
- `custom_email_sent` - Email notification sent

### 2. Subscription Lifecycle
- `payment.paypal.subscription_created` - New subscription creation
- `payment.paypal.renewal_payment_completed` - Subscription renewal payment

### 3. Order Status Workflow
- `checkout_processed` - Checkout completion
- `status_changed` - Order status transition
- `rule_execution` - Rule execution for status change

### 4. Error Handling Scenarios
- `payment.paypal.payment_failed` - Payment failure
- `rule_execution_failed` - Rule execution failure
- `custom_email_failed` - Email sending failure

### 5. Webhook Integration
- `webhook_reception` - Webhook received
- `webhook_processing` - Webhook processing
- `universal_event_processing` - Universal event processing

### 6. Additional Payment Events
- `payment.paypal.payment_pending` - Payment pending review
- `payment.paypal.payment_refunded` - Payment refunded

### 7. Subscription Management Events
- `payment.paypal.subscription_cancelled` - Subscription cancelled
- `payment.paypal.subscription_suspended` - Subscription suspended
- `payment.paypal.subscription_reactivated` - Subscription reactivated

### 8. System Configuration Events
- `config_export` - Configuration export
- `config_import` - Configuration import

## Usage

### WP-CLI Command (Recommended)

```bash
wp odcm generate-audit-logs
```

### PHP Function Call

```php
$result = odcm_generate_sample_audit_logs();
```

### Manual Execution

1. Place the script in your WordPress installation
2. Include the file in your theme or plugin
3. Call the `odcm_generate_sample_audit_logs()` function

## Cleanup

To remove all generated test data:

```sql
DELETE FROM {$wpdb->prefix}odcm_audit_log WHERE is_test = 1;
DELETE FROM {$wpdb->prefix}odcm_audit_log_payloads WHERE payload_id NOT IN (SELECT payload_id FROM {$wpdb->prefix}odcm_audit_log);
```

## Technical Details

- All events are marked with `is_test = 1` for easy identification
- Events are grouped by `process_id` for consolidated view
- Timestamps are staggered to create realistic sequences
- Event payloads contain detailed information for rich display

## Enhanced Features

### Payload Table Integration
- Automatically stores detailed event data in separate payload table
- Reduces main table size while preserving rich event details
- Follows plugin's database architecture best practices

### Process ID Management
- Uses ProcessIdManager when available for realistic process IDs
- Falls back to manual generation for compatibility
- Ensures proper event grouping in consolidated views

### Additional Event Coverage
- Expanded payment events (pending, refunded)
- Complete subscription lifecycle (cancelled, suspended, reactivated)
- System configuration events (export/import)
- Provides comprehensive screenshot material for all plugin features

## Screenshot Recommendations

After generating the logs, capture screenshots of:

1. **Consolidated View** - Show grouped events by process
2. **Payment Processing** - Show PayPal payment → Rule → Email sequence
3. **Subscription Management** - Show subscription creation and renewal
4. **Error Handling** - Show payment failure and error recovery
5. **Webhook Integration** - Show webhook reception and processing

## Customization

You can modify the script to:

- Change order IDs and amounts
- Add more event types
- Adjust timestamps for different time ranges
- Create different error scenarios
- Add more complex rule executions
