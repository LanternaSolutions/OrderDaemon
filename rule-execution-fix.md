# Rule Execution Timeline Fix

## Issues Fixed

We've addressed two specific issues in the timeline display:

1. **"Rule evaluation completed for Order #0" issue**
   - Erroneous event appearing in timeline that references a non-existent order
   - Has only a correlation ID but no proper order context

2. **Duplicate rule execution events**
   - Multiple rule_execution events for the same rule and order
   - Events appearing at different positions in the timeline
   - Final rule_execution event appearing at the end of the timeline 

## Root Causes

### Order #0 Issue

The "Order #0" issue was caused by the `ProcessLogger.php` returning its `correlation_id` as a log ID even when `universal_event_context` was true (meaning the UniversalEventProcessor would handle the timeline event creation). This correlation ID was then misinterpreted as a real event ID, creating a malformed event that referenced "Order #0".

```php
// Check if universal event context is active - if so, skip timeline event creation
// since UniversalEventProcessor will create enhanced events instead
if (self::$universal_event_context) {
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        $this->critical_log('Skipping timeline event creation due to universal event context');
    }
    // Still return the correlation_id as log_id for process continuity
    return $this->correlation_id; 
}
```

### Duplicate Rule Execution Events

The duplicate rule execution events were caused by:

1. Multiple canonical event types (`checkout_processed`, `payment_completed`, etc.) that could all trigger the same rule
2. Each of these canonical events would create its own rule_execution event 
3. Insufficient deduplication logic that didn't properly track which rule+order combinations already had rule_execution events
4. Lack of a system to determine which event should be the primary trigger event shown in the timeline

## Solution Implemented

### Fix for Order #0 Issue

In `ProcessLogger.php`, we changed the return value when `universal_event_context` is true:

```php
// Check if universal event context is active - if so, skip timeline event creation
// since UniversalEventProcessor will create enhanced events instead
if (self::$universal_event_context) {
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        $this->critical_log('Skipping timeline event creation due to universal event context');
    }
    // Return false instead of correlation_id
    return false;
}
```

This prevents the correlation_id from being misinterpreted as a real event ID, eliminating the "Order #0" events entirely.

### Fix for Duplicate Rule Execution Events

In `UniversalEventProcessor.php`, we implemented multiple improvements:

1. **Event Tracking**: Added a static array to track which events have already been processed for each order+rule combination:

```php
/**
 * Storage for tracking which events have been processed for each order+rule
 * This prevents duplicate rule execution events from different canonical event types
 *
 * @var array
 */
private static $processed_events = [];
```

2. **Primary Event Determination**: Added a method to determine which event type should be shown as the primary trigger in the timeline:

```php
/**
 * Determine primary canonical event for rule execution display
 * 
 * When multiple canonical events trigger the same rule, this method
 * determines which one should be displayed in the timeline for consistency
 * 
 * @param string $event_type Current event type
 * @param int $order_id Order ID
 * @param int $rule_id Rule ID
 * @return string Primary event type to display
 */
private function getPrimaryCanonicalEvent(string $event_type, int $order_id, int $rule_id): string
{
    // Define a unique key for this order+rule combination
    $key = $order_id . '_' . $rule_id;
    
    // If this is the first event for this order+rule, register it as primary
    if (!isset(self::$processed_events[$key])) {
        self::$processed_events[$key] = $event_type;
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_DEDUP_DEBUG: Registered primary event {$event_type} for Order #{$order_id}, Rule #{$rule_id}", 'debug');
        }
        return $event_type;
    }
    
    // Otherwise, return the existing primary event
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        odcm_log_message("ODCM_DEDUP_DEBUG: Using existing primary event " . self::$processed_events[$key] . " instead of {$event_type}", 'debug');
    }
    return self::$processed_events[$key];
}
```

3. **Enhanced Deduplication**: Improved the `createConsolidatedRuleExecutionEvent()` method to always check for existing events and update them rather than creating duplicates:

```php
// ALWAYS check for existing event first, update if found
$existing_event = $this->getExistingRuleExecutionEvent($order_id, $rule_id, $context);

if ($existing_event) {
    // Instead of creating a new event, update the existing event
    $this->updateExistingRuleExecutionEvent($order_id, $rule_id, $context, $process_id);
    
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        odcm_log_message("ODCM_DEDUP_DEBUG: Updated existing rule execution event for Rule '{$rule_name}' (Order #{$order_id})", 'debug');
        odcm_log_message("ODCM_DEDUP_DEBUG: Event ID: {$existing_event['event_id']}, Primary trigger: {$primary_trigger_event}", 'debug');
    }

    // Return early - existing event updated, no new event created
    return;
}
```

4. **Improved Caching**: Enhanced caching mechanisms to better track existing events across requests:

```php
// Cache the new event for future reference with improved caching
if ($event_id) {
    $event_data = [
        'event_id' => $event_id,
        'primary_trigger' => $primary_trigger_event,
        'all_triggers' => array_keys($trigger_events),
        'process_id' => $process_id,
        'trigger_details' => $trigger_events,
    ];
    
    // Store in both memory cache and persistent cache
    self::$rule_execution_events[$order_id][$rule_id] = $event_data;
    
    // Use improved cache key
    $cache_key = 'odcm_rule_exec_order_' . $order_id . '_rule_' . $rule_id;
    set_transient($cache_key, $event_data, HOUR_IN_SECONDS);
```

## Expected Results

After these changes:

1. The "Rule evaluation completed for Order #0" event will be completely eliminated from the timeline.

2. For each order and rule combination, there will be only a single rule_execution event in the timeline.

3. The rule_execution event will:
   - Appear immediately after the triggering event that first matched the rule
   - Include information about all events that triggered the rule (not just the first one)
   - Have consistent, accurate timestamps that reflect when the rule was actually evaluated

These changes preserve all the business data while providing a cleaner, more logical timeline view for users.
