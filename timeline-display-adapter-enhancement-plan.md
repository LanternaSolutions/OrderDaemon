# Timeline Display Adapter Enhancement Plan

## Executive Summary

This document outlines the plan to enhance timeline display adapters for events that currently use the GenericEventAdapter but would benefit from more specific handling. The goal is to provide better business-relevant key-value pair displays for these events while maintaining the current architecture.

## Current State Analysis

### Events with Dedicated Adapters (15/20 = 75%)
- **Payment Events**: PaymentEventAdapter (4 events)
- **Order Events**: OrderEventAdapter (8 events including customer_created)
- **Rule Events**: RuleExecutionAdapter (5 events including evaluation failures)

### Events Using GenericEventAdapter (5/20 = 25%)
These events currently fall back to GenericEventAdapter but could benefit from enhanced handling:

1. **Webhook Events**: `webhook_reception`, `webhook_processing`
2. **Email Events**: `custom_email_sent`, `custom_email_failed`
3. **System Events**: `config_export`, `config_import`

### Missing Event Coverage (13 events)
These events are not specifically handled by any adapter and could benefit from enhanced handling:

1. **System Events**: `info`, `warning`, `error`, `admin_action`, `process_started`, `process_event`, `lifecycle_event`, `custom_event`, `action_scheduled`
2. **Subscription Events**: `subscription_created`, `subscription_approved`, `subscription_cancelled`, `subscription_suspended`, `subscription_reactivated`, `subscription_completed`, `subscription_expired`, `subscription_paused`, `subscription_resumed`, `subscription_updated`, `trial_ending`, `renewal_payment_completed`, `renewal_payment_failed`, `renewal_payment_processing`, `renewal_payment_pending`

### Events to Exclude from User Display
After careful analysis, these events will be excluded from user-facing timeline displays:

1. **Analysis Events**: `refund_analysis`, `woocommerce_analysis`, `dedup`
2. **System Events**: `process_started`, `process_event`, `lifecycle_event`, `action_scheduled`
3. **Universal Events**: `universal_event_processing`

**Note**: `custom_event` is intentionally NOT excluded as it serves as both a fallback mechanism for unknown events and a legitimate way for users to add custom events to their timelines.

### Debug-Only Events
These events will be converted to debug-only mode:

1. **Metrics Events**: `metrics` (only shown when ODCM_DEBUG is true and debug logs are enabled)

## Enhancement Strategy

Instead of creating new dedicated adapters (which would increase maintenance burden), we will:

1. **Enhance GenericEventAdapter** to provide better handling for specific event types
2. **Add event-type-specific methods** within GenericEventAdapter
3. **Extend OrderEventAdapter** to handle subscription events as complex recurring orders
4. **Maintain the current routing logic** in AdapterRegistry
5. **Improve business data extraction** for each event category

## Architectural Rationale

### Subscription Events in OrderEventAdapter
Subscriptions are fundamentally complex recurring orders with additional lifecycle states. By handling them in the OrderEventAdapter:

- **Maintains consistency** in how order-related events are displayed
- **Leverages existing order display logic** and context
- **Reduces adapter proliferation** while providing appropriate handling
- **Follows the principle** that subscriptions are recurring orders with extended lifecycle

### Analysis/System Events in GenericEventAdapter
These secondary/system-level events are appropriately handled by the enhanced GenericEventAdapter:

- **Avoids creating dedicated adapters** for less critical event types
- **Follows established pattern** of using GenericEventAdapter for non-core business events
- **Maintains architectural simplicity** while providing better displays
- **Keeps maintenance burden low** through centralized enhancement

### Payment Gateway Support Architecture

The system already has robust support for handling any payment gateway through a tiered adapter architecture:

1. **Dedicated Adapters**: Specific gateways like Stripe and PayPal have dedicated adapters with comprehensive event coverage
2. **Generic Fallback**: The GenericAdapter handles unknown gateways with flexible field mapping and event type normalization
3. **Extensible Design**: New gateways can be added by creating new adapters or extending the GenericAdapter

**Key Capabilities**:
- **Universal Gateway Support**: Any payment gateway can be processed through the GenericAdapter
- **Flexible Field Mapping**: Supports multiple field name variations for transaction IDs, amounts, etc.
- **Event Type Normalization**: Maps diverse gateway event formats to standard universal event types
- **Idempotency Handling**: Comprehensive duplicate detection across all gateways

**Implementation Notes**:
- The GenericAdapter serves as a fallback for unknown gateways, ensuring no webhook data is rejected
- Payment gateway events are processed through the UniversalEventProcessor, which handles entity resolution and rule processing
- The system can handle both IPN (Instant Payment Notification) and webhook-based gateways

## Detailed Implementation Plan

### 1. Webhook Events Enhancement

**Events**: `webhook_reception`, `webhook_processing`, `universal_event_processing`

**Current Limitations**:
- Generic display without webhook-specific fields
- No source/integration identification
- Limited payload type information
- No processing status indicators

**Enhancement Tasks**:
```markdown
- [ ] Add `addWebhookFields()` method to GenericEventAdapter
- [ ] Extract webhook source/integration name
- [ ] Display webhook payload type and size
- [ ] Show processing status and results
- [ ] Add webhook-specific error handling
- [ ] Format webhook URLs and endpoints
```

**Expected Fields**:
- Webhook Source (e.g., "Stripe", "PayPal", "Custom Integration")
- Payload Type (e.g., "payment.completed", "order.created")
- Processing Status (e.g., "Received", "Processed", "Failed")
- Response Time (if available)
- Error Details (for failed webhooks)

### 2. Email Events Enhancement

**Events**: `custom_email_sent`, `custom_email_failed`

**Current Limitations**:
- Generic display without email-specific fields
- No recipient information
- Limited subject/body extraction
- No delivery status indicators

**Enhancement Tasks**:
```markdown
- [ ] Add `addEmailFields()` method to GenericEventAdapter
- [ ] Extract recipient email addresses
- [ ] Display email subject and type
- [ ] Show delivery status and timestamps
- [ ] Add email-specific error handling
- [ ] Format email templates and types - the oinly emails that order daemon handles are the ones that the plugin itself sends because of rule actions in the Pro plugin
```

**Expected Fields**:
- Recipient Email(s)
- Email Subject
- Email Type (e.g., "Order Confirmation", "Payment Receipt")
- Delivery Status (e.g., "Sent", "Failed", "Queued")
- Send Timestamp
- Error Details (for failed emails)

### 3. System Events Enhancement

**Events**: `config_export`, `config_import`

**Current Limitations**:
- Generic display without system operation context
- No user/actor identification
- Limited operation details
- No success/failure indicators

**Enhancement Tasks**:
```markdown
- [ ] Add `addSystemOperationFields()` method to GenericEventAdapter
- [ ] Extract operation type and scope
- [ ] Display user/actor information
- [ ] Show operation results and status, using a status pill for success/failure/error/etc. as necessary
- [ ] Add configuration-specific details
- [ ] Format operation timestamps
```

**Expected Fields**:
- Operation Type (e.g., "Config Export", "Config Import")
- Performed By (user ID/name)
- Operation Status (e.g., "Success", "Partial", "Failed") - in a status pill, affecting the timeline component theme
- Items Processed (count of configurations)
- Timestamp
- Error Details (if applicable)

### 4. System Events Enhancement (User-Facing)

**Events**: `info`, `warning`, `error`, `admin_action`

**Current Limitations**:
- Generic display without system event context
- No event severity or priority indicators
- Limited system operation details
- No actor/user identification

**Enhancement Tasks**:
```markdown
- [ ] Add `addSystemEventFields()` method to GenericEventAdapter
- [ ] Extract event severity and priority
- [ ] Display system operation details
- [ ] Show actor/user information
- [ ] Add system-specific error handling
- [ ] Format system event output appropriately
- [ ] Utilize the relevant status pills and component themes for the various statuses, as relevant
```

**Expected Fields**:
- Event Type (e.g., "System Warning", "Admin Action")
- Status pill (e.g., "Error", "Warning", "Success", or no status pill if it doesnt actually add any useful info for the user that isnt already easily scannable with the eye - status pills help to focus in on the important info, so no need to add them when its not clear what the utility is)
- Operation Details (e.g., "Database backup completed")
- Performed By (user ID/name if applicable)
- Timestamp
- Error Details (if applicable)

### 5. Metrics Events (Debug-Only)

**Events**: `metrics`

**Implementation Approach**:
- Convert to debug-only events that only record when ODCM_DEBUG is true
- Only show in timeline when "include debug logs" filter is enabled
- Provide concise, user-friendly display for debugging purposes

**Enhancement Tasks**:
```markdown
- [ ] Add debug event detection for metrics events
- [ ] Configure to use debug status pill: "DEBUG" with 'debug' type
- [ ] Implement concise user-friendly title: "Performance Metrics"
- [ ] Define primary key-value pairs for display:
  - Metric Name
  - Formatted Value + Unit
  - Collection Context (if relevant)
- [ ] Move technical details to expandable debug section
- [ ] Only display when 'show debug logs' toggle is enabled in the insight dashboard filters
- [ ] Ensure proper filtering in timeline queries
```

**Expected Display**:
- **Status Pill**: "DEBUG" with debug styling
- **Event Title**: "Metrics: [Metric Name]"
- **Primary Display**: Key metric values in concise format
- **Expandable Section**: Full technical details for debugging
- **Debug Data**: Technical details (raw_value, collection_method, timestamp_ms, etc.)

### 6. Subscription Events Enhancement (OrderEventAdapter)

**Events**: `subscription_created`, `subscription_approved`, `subscription_cancelled`, `subscription_suspended`, `subscription_reactivated`, `subscription_completed`, `subscription_expired`, `subscription_paused`, `subscription_resumed`, `subscription_updated`, `trial_ending`, `renewal_payment_completed`, `renewal_payment_failed`, `renewal_payment_processing`, `renewal_payment_pending`

**Current Limitations**:
- No dedicated handling for subscription lifecycle events
- Limited subscription-specific field extraction
- No recurring payment information
- Generic display without subscription context

**Enhancement Tasks**:
```markdown
- [ ] Extend OrderEventAdapter to recognize subscription event patterns
- [ ] Add `addSubscriptionFields()` method to OrderEventAdapter
- [ ] Extract subscription lifecycle state and details
- [ ] Display recurring payment information
- [ ] Show subscription metadata (billing cycle, next payment, etc.)
- [ ] Add subscription-specific error handling
- [ ] Reuse existing order display patterns for subscription data
```

**Expected Fields**:
- Subscription ID and Status
- Subscription Type (e.g., "Monthly", "Annual")
- Billing Cycle Information
- Next Payment Date
- Recurring Amount
- Lifecycle State (e.g., "Active", "Cancelled", "Suspended")
- Related Order ID (if applicable)
- Timestamp
- Error Details (if applicable)

## Implementation Approach

### Phase 1: Event Filtering and Exclusion Logic
```markdown
- [ ] Implement filtering logic to exclude technical events (universal_event_processing, dedup, etc.)
- [ ] Ensure custom_event is properly handled as user-facing content
- [ ] Add event type detection in display adapters to filter out system-only events
- [ ] Implement debug mode filtering for metrics events
- [ ] Add comprehensive event category detection
```

### Phase 2: GenericEventAdapter Enhancement
```markdown
- [ ] Create category-specific methods (addWebhookFields(), addEmailFields(), addSystemEventFields(), addMetricsFields())
- [ ] Implement field extraction logic for webhook events (source, payload type, status)
- [ ] Implement field extraction logic for email events (recipients, subject, delivery status)
- [ ] Implement field extraction logic for system events (severity, operation details, user context)
- [ ] Add appropriate section organization and status pill configuration
- [ ] Ensure fallback to generic handling for unknown events
```

### Phase 3: OrderEventAdapter Extension for Subscriptions
```markdown
- [ ] Extend event type detection to recognize subscription patterns
- [ ] Add addSubscriptionFields() method to OrderEventAdapter
- [ ] Implement subscription lifecycle state handling (active, cancelled, suspended, etc.)
- [ ] Add recurring payment information extraction (billing cycle, next payment date)
- [ ] Reuse existing order display patterns for subscription data
- [ ] Ensure consistent formatting with order events
```

### Phase 4: Testing and Validation
```markdown
- [ ] Create test payloads for each event type (webhook, email, system, subscription)
- [ ] Verify field extraction works correctly for all categories
- [ ] Test fallback behavior for unknown event types
- [ ] Validate display formatting consistency across adapters
- [ ] Ensure no regression in existing functionality
- [ ] Test event filtering and exclusion logic
- [ ] Validate debug mode filtering for metrics events
```

### Phase 5: Integration and Finalization
```markdown
- [ ] Update AdapterRegistry with enhanced routing logic
- [ ] Ensure no conflicts between adapter routing
- [ ] Test comprehensive event type coverage
- [ ] Implement final validation and error handling
- [ ] Add documentation and examples for all event types
```

## Technical Implementation Details

### GenericEventAdapter Modifications

```php
protected function extractSpecializedFields(array &$payload): array
{
    $fields = parent::extractSpecializedFields($payload); // Get base fields

    $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? '';

    // Enhanced handling for specific event categories
    if (strpos($eventType, 'webhook_') !== false || $eventType === 'universal_event_processing') {
        $this->addWebhookFields($fields, $payload);
    }
    elseif (strpos($eventType, 'email_') !== false || strpos($eventType, 'custom_email') !== false) {
        $this->addEmailFields($fields, $payload);
    }
    elseif (strpos($eventType, 'config_') !== false) {
        $this->addSystemOperationFields($fields, $payload);
    }
    elseif (strpos($eventType, 'refund_') !== false || strpos($eventType, 'woocommerce_') !== false || $eventType === 'dedup') {
        $this->addAnalysisFields($fields, $payload);
    }
    elseif (in_array($eventType, ['info', 'warning', 'error', 'metrics', 'admin_action', 'process_started', 'process_event', 'lifecycle_event', 'custom_event', 'action_scheduled'])) {
        $this->addSystemEventFields($fields, $payload);
    }

    return $fields;
}
```

### OrderEventAdapter Modifications

```php
protected function extractSpecializedFields(array &$payload): array
{
    $fields = parent::extractSpecializedFields($payload); // Get base fields

    $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? '';

    // Handle subscription events as complex recurring orders
    if (strpos($eventType, 'subscription_') !== false ||
        strpos($eventType, 'renewal_payment_') !== false ||
        $eventType === 'trial_ending') {
        $this->addSubscriptionFields($fields, $payload);
    }
 
    return $fields ;
}
```

### Event Category Detection Logic

The enhanced adapters will use comprehensive pattern matching:

**GenericEventAdapter**:
1. **Webhook Events**: `webhook_*` or `universal_event_processing`
2. **Email Events**: `email_*` or `custom_email*`
3. **System Operations**: `config_*`
4. **Analysis Events**: `refund_*`, `woocommerce_*`, or `dedup`
5. **System Events**: Specific event types (`info`, `warning`, `error`, etc.)

**OrderEventAdapter**:
1. **Subscription Events**: `subscription_*`, `renewal_payment_*`, or `trial_ending`

## Technical Implementation Details

### GenericEventAdapter Modifications

```php
protected function extractSpecializedFields(array &$payload): array
{
    $fields = parent::extractSpecializedFields($payload); // Get base fields

    $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? '';

    // Enhanced handling for specific event categories
    if (strpos($eventType, 'webhook_') !== false || $eventType === 'universal_event_processing') {
        $this->addWebhookFields($fields, $payload);
    }
    elseif (strpos($eventType, 'email_') !== false || strpos($eventType, 'custom_email') !== false) {
        $this->addEmailFields($fields, $payload);
    }
    elseif (strpos($eventType, 'config_') !== false) {
        $this->addSystemOperationFields($fields, $payload);
    }

    return $fields;
}
```

### Event Category Detection Logic

The enhanced GenericEventAdapter will use pattern matching to identify event categories:

1. **Webhook Events**: `webhook_*` or `universal_event_processing`
2. **Email Events**: `email_*` or `custom_email*`
3. **System Events**: `config_*`

## Expected Outcomes

### Before Enhancement
- 5 events show generic fields with limited business context
- 13 additional events have no specific handling
- Limited event-type-specific information across all categories
- Basic display formatting without specialized fields

### After Enhancement

**GenericEventAdapter Enhancements**:
- Webhook events show source, payload type, processing status, response time
- Email events show recipients, subjects, delivery status, timestamps
- System operation events show operation type, user, results, item counts
- System events (info, warning, error, admin_action) show severity, details, and context
- Metrics events show concise performance data with debug-only display

**OrderEventAdapter Enhancements**:
- Subscription events show subscription ID, status, billing cycle, next payment date
- Recurring payment events show payment details, related subscription
- All subscription lifecycle states properly displayed
- Consistent formatting with existing order events

**Overall Improvements**:
- Focused event coverage on business-relevant events only
- Comprehensive business-relevant key-value pairs for each event type
- Consistent formatting patterns across all event categories
- Improved usability and business context for timeline displays
- Reduced noise by excluding system-only and analysis events
- Proper debug handling for technical metrics events

**Events Excluded from Display**:
- Analysis events (refund_analysis, woocommerce_analysis, dedup)
- System process events (process_started, process_event, lifecycle_event, custom_event, action_scheduled)
- Universal event processing events

**Events Converted to Debug-Only**:
- Metrics events (only shown when debug mode enabled)

## Maintenance Considerations

1. **Minimal new classes** - Enhancements within existing adapters (GenericEventAdapter, OrderEventAdapter)
2. **Backward compatibility** - All existing behavior preserved with fallback mechanisms
3. **Extensible design** - Easy to add new event categories through pattern matching
4. **Consistent patterns** - Follows established adapter implementation styles
5. **Low maintenance burden** - Centralized enhancements in two adapter classes
6. **Clear separation** - Core business events in OrderEventAdapter, system events in GenericEventAdapter
7. **Debug event handling** - Proper integration with existing debug event infrastructure
8. **Event filtering** - Respects existing debug toggle and filtering mechanisms

## Success Metrics

1. **Coverage**: Focused coverage on business-relevant events (excluding noise events)
2. **Business relevance**: Each displayed event category shows 3-7 relevant key-value pairs
3. **Consistency**: Uniform display patterns across all event types and adapters
4. **Performance**: No degradation in timeline rendering speed (<5% impact)
5. **Maintainability**: Easy to extend for future event types (new patterns added in <1 hour)
6. **User satisfaction**: Improved business context leads to better decision-making
7. **Error reduction**: Clearer event displays reduce support tickets related to event interpretation
8. **Noise reduction**: Successful exclusion of system-only and low-value events
9. **Debug functionality**: Proper debug-only display for metrics events
10. **Filter effectiveness**: Debug events properly filtered based on user preferences

## Timeline and Resources

**Priority**: Medium (enhancement, not critical)
**Dependencies**: None (self-contained within existing adapters)
**Risk Level**: Low (comprehensive fallback behavior preserved)

## Next Steps

1. **Implementation**:
   - Implement GenericEventAdapter enhancements for all identified categories
   - Extend OrderEventAdapter for subscription event handling
   - Add comprehensive pattern matching for all event types

2. **Testing**:
   - Create test payloads for each event type (5 original + 13 new = 18 total)
   - Verify field extraction works correctly for all categories
   - Test fallback behavior for unknown event types
   - Validate display formatting consistency

3. **Validation**:
   - Ensure no regression in existing functionality
   - Test subscription events in OrderEventAdapter
   - Test all analysis/system events in GenericEventAdapter
   - Verify comprehensive event type coverage

4. **Documentation**:
   - Document the enhanced behavior for all event categories
   - Update any references to legacy PayloadRenderer system
   - Create examples of expected display output

5. **Deployment**:
   - Monitor performance impact in production
   - Gather user feedback on enhanced displays
   - Iterate based on real-world usage patterns

## Architectural Benefits

This enhanced approach provides:
- **Logical event categorization** based on fundamental nature (orders vs. system events)
- **Reduced adapter proliferation** through strategic enhancement of existing adapters
- **Improved code reuse** by leveraging existing order display logic for subscriptions
- **Better maintainability** through centralized pattern-based routing
- **Future extensibility** with clear patterns for adding new event categories
- **Comprehensive payment gateway support** through tiered adapter architecture

The plan maintains all benefits of the original approach while providing comprehensive coverage for all identified event types through a coherent architectural strategy.
