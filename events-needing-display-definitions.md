# Events Needing Display Adapter Definitions

## Summary

This document precisely identifies which events currently use the GenericEventAdapter and need enhanced display handling to show business-relevant key-value pairs.

## Current Event Coverage

### ✅ Events with Dedicated Adapters (15/20 = 75%)

**Payment Events (4/4)**
- `payment_completed` / `payment_completed`
- `payment_complete`
- `refund_processed`

**Order Events (8/8)**
- `order_status_changed`
- `order_on_hold`
- `order_processing`
- `subscription_cancelled`
- `subscription_created`
- `subscription_renewed`
- `customer_created`
- `checkout_initiated`

**Rule Events (5/5)**
- `rule_executed`
- `rule_skipped`
- `rule_error`
- `condition_evaluation_failed`
- `trigger_evaluation_failed`

### 📝 Events Using GenericEventAdapter (5/20 = 25%)

These events need enhanced display definitions:

## 1. Webhook Events

**Events**: `webhook_reception`, `webhook_processing`, `universal_event_processing`

**Current Display**: Generic fields only (event description, status, action, message)

**Needed Enhancements**:
- Webhook source/integration name
- Payload type and size
- Processing status and results
- Response time metrics
- Error details for failures

**Example Display Fields**:
```json
{
  "webhook_source": "Stripe",
  "payload_type": "payment.completed",
  "processing_status": "Processed",
  "response_time": "120ms",
  "error_details": "Invalid signature"
}
```

## 2. Email Events

**Events**: `custom_email_sent`, `custom_email_failed`

**Current Display**: Generic fields only

**Needed Enhancements**:
- Recipient email addresses
- Email subject and type
- Delivery status and timestamps
- Email template information
- Error details for failures

**Example Display Fields**:
```json
{
  "recipient_email": "customer@example.com",
  "email_subject": "Your Order Confirmation",
  "email_type": "Order Confirmation",
  "delivery_status": "Sent",
  "send_timestamp": "2024-01-15 14:30:45",
  "error_details": "SMTP connection failed"
}
```

## 3. System Events

**Events**: `config_export`, `config_import`

**Current Display**: Generic fields only

**Needed Enhancements**:
- Operation type and scope
- User/actor information
- Operation results and status
- Items processed count
- Error details if applicable

**Example Display Fields**:
```json
{
  "operation_type": "Configuration Export",
  "performed_by": "admin@example.com",
  "operation_status": "Success",
  "items_processed": 15,
  "timestamp": "2024-01-15 10:15:30"
}
```

## Implementation Plan

### Phase 1: Enhance GenericEventAdapter
- Add event type detection in `extractSpecializedFields()`
- Create category-specific methods:
  - `addWebhookFields()`
  - `addEmailFields()`
  - `addSystemOperationFields()`
- Implement field extraction logic for each category

### Phase 2: Field Extraction Logic
```markdown
- [ ] Webhook source extraction from payload
- [ ] Email recipient and subject extraction
- [ ] System operation type and user extraction
- [ ] Add validation and fallback logic
- [ ] Ensure consistent formatting
```

### Phase 3: Testing
```markdown
- [ ] Create test payloads for each event type
- [ ] Verify field extraction works correctly
- [ ] Test fallback behavior
- [ ] Validate display formatting
```

## Expected Results

**Before**: All 5 events show only generic fields with limited business context
**After**: Each event shows relevant business-specific key-value pairs with proper formatting

## Maintenance Approach

- **No new adapter classes** - Enhance GenericEventAdapter only
- **Backward compatibility** - Preserve existing behavior
- **Extensible design** - Easy to add new event categories
- **Minimal maintenance** - Single class to maintain

## Priority

**Medium Priority** - These are enhancement tasks, not critical fixes
**Estimated Effort**: 8-12 hours
**Risk Level**: Low (fallback behavior preserved)

This document provides a precise definition of which events need enhanced display handling and outlines the specific improvements required for each event category.
