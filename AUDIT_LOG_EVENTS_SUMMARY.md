# Order Daemon Audit Log Events Summary

This document provides a comprehensive list of audit log events that have been created for the marketing website screenshots.

## Event Categories and Types

### 1. Payment Gateway Events

#### PayPal Events
- `payment.paypal.payment_completed` - Successful PayPal payment processing
- `payment.paypal.payment_failed` - Failed PayPal payment
- `payment.paypal.payment_pending` - Pending PayPal payment
- `payment.paypal.payment_refunded` - PayPal refund processing

#### Subscription Events
- `payment.paypal.subscription_created` - New subscription creation
- `payment.paypal.subscription_cancelled` - Subscription cancellation
- `payment.paypal.renewal_payment_completed` - Subscription renewal payment
- `payment.paypal.subscription_suspended` - Subscription suspension
- `payment.paypal.subscription_reactivated` - Subscription reactivation

### 2. Core Order Events

#### Order Status Events
- `status_changed` - Order status transitions (e.g., pending → processing)
- `order_processing` - Order processing events
- `order_completed` - Order completion events
- `checkout_processed` - Checkout completion

#### Rule Execution Events
- `rule_execution` - Successful rule execution
- `rule_execution_failed` - Failed rule execution
- `rule_skipped` - Rules skipped due to conditions

### 3. Communication Events

#### Email Events
- `custom_email_sent` - Email notifications sent successfully
- `custom_email_failed` - Email sending failures

### 4. Integration Events

#### Webhook Events
- `webhook_reception` - Incoming webhook received
- `webhook_processing` - Webhook processing events
- `universal_event_processing` - Universal event processing

### 5. System Events

#### Configuration Events
- `config_export` - Configuration export events
- `config_import` - Configuration import events

#### Error Events
- `event_routing` - Event routing system events
- `condition_evaluation_failed` - Condition evaluation errors
- `trigger_evaluation_failed` - Trigger evaluation errors

### 2. Core Order Events

#### Order Status Events
- `status_changed` - Order status transitions (e.g., pending → processing)
- `order_processing` - Order processing events
- `order_completed` - Order completion events
- `checkout_processed` - Checkout completion

#### Rule Execution Events
- `rule_execution` - Successful rule execution
- `rule_execution_failed` - Failed rule execution
- `rule_skipped` - Rules skipped due to conditions

### 3. Communication Events

#### Email Events
- `custom_email_sent` - Email notifications sent successfully
- `custom_email_failed` - Email sending failures

### 4. Integration Events

#### Webhook Events
- `webhook_reception` - Incoming webhook received
- `webhook_processing` - Webhook processing events
- `universal_event_processing` - Universal event processing

### 5. System Events

#### Configuration Events
- `config_export` - Configuration export events
- `config_import` - Configuration import events

#### Error Events
- `event_routing` - Event routing system events
- `condition_evaluation_failed` - Condition evaluation errors
- `trigger_evaluation_failed` - Trigger evaluation errors

## Event Sequences Created

### 1. Successful Payment Processing Workflow
**Process ID:** `odcm_*`
**Order ID:** 12345
**Events:**
1. `payment.paypal.payment_completed` - PayPal payment completed ($99.99)
2. `rule_execution` - "Send Order Confirmation Email" rule executed
3. `custom_email_sent` - Order confirmation email sent

### 2. Subscription Lifecycle
**Process ID:** `odcm_*`
**Subscription ID:** SUB-*
**Order ID:** 12346
**Events:**
1. `payment.paypal.subscription_created` - New subscription created ($29.99/month)
2. `payment.paypal.renewal_payment_completed` - First renewal payment completed

### 3. Order Status Workflow
**Process ID:** `odcm_*`
**Order ID:** 12347
**Events:**
1. `checkout_processed` - Checkout completed ($79.99)
2. `status_changed` - Status changed from pending to processing
3. `rule_execution` - "Update Customer on Processing" rule executed

### 4. Error Handling Scenarios
**Process ID:** `odcm_*`
**Order ID:** 12348
**Events:**
1. `payment.paypal.payment_failed` - Payment failed (Insufficient funds)
2. `rule_execution_failed` - "Handle Failed Payment" rule failed
3. `custom_email_failed` - Payment failed notification email failed

### 5. Webhook Integration
**Process ID:** `odcm_*`
**Order ID:** 12349
**Events:**
1. `webhook_reception` - PayPal webhook received
2. `webhook_processing` - Webhook processed successfully
3. `universal_event_processing` - Universal event processed

### 6. Additional Payment Events
**Process ID:** `odcm_*`
**Order ID:** 12350
**Events:**
1. `payment.paypal.payment_pending` - PayPal payment pending review ($149.99)
2. `payment.paypal.payment_refunded` - PayPal payment refunded ($149.99)

### 7. Subscription Management Events
**Process ID:** `odcm_*`
**Subscription ID:** SUB-*
**Order ID:** 12351
**Events:**
1. `payment.paypal.subscription_cancelled` - Subscription cancelled by customer
2. `payment.paypal.subscription_suspended` - Subscription suspended due to payment failure
3. `payment.paypal.subscription_reactivated` - Subscription reactivated after payment update

### 8. System Configuration Events
**Process ID:** `odcm_*`
**Order ID:** N/A (System-level)
**Events:**
1. `config_export` - Configuration settings exported by administrator
2. `config_import` - Configuration settings imported by administrator

## Event Data Structure

Each audit log entry contains:

### Main Log Entry
- `timestamp` - When the event occurred
- `order_id` - Associated order ID (if applicable)
- `event_type` - Type of event
- `status` - Event status (success, error, info, etc.)
- `summary` - Human-readable summary
- `source` - Event source (webhook, manual, scheduled, etc.)
- `is_test` - Flag indicating test data (1 for generated events)
- `process_id` - Grouping ID for consolidated view

### Event Payload
- Detailed event-specific data in JSON format
- Includes raw gateway data for webhook events
- Contains execution details for rule events
- Includes email content for communication events

## Screenshot Recommendations

### Consolidated View
- Show grouped events by process ID
- Display the timeline with parent-child relationships
- Highlight the most important event in each group

### Payment Processing
- Show the complete workflow from payment to email
- Display PayPal transaction details
- Show rule execution metrics

### Subscription Management
- Show subscription creation and renewal sequence
- Display billing cycle information
- Highlight subscription status changes

### Error Handling
- Show error events with failure reasons
- Display error recovery attempts
- Highlight failed rule executions

### Webhook Integration
- Show webhook reception and processing
- Display gateway-specific data
- Highlight universal event processing

## Technical Implementation

The generated events demonstrate:

1. **Consolidated View** - Events grouped by `process_id`
2. **Parent-Child Relationships** - Rule executions linked to triggering events
3. **Timeline Display** - Chronological ordering of events
4. **Rich Event Details** - Comprehensive payload data
5. **Error Tracking** - Detailed error information
6. **Integration Capabilities** - Webhook and gateway events

This comprehensive set of audit log events provides excellent material for creating compelling marketing screenshots that showcase the Order Daemon Pro plugin's advanced monitoring and automation capabilities.
