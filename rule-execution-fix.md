# Rule Execution Timeline Fix

## Issue Summary

The timeline was showing duplicate or extra rule execution events with the following problems:

1. An extra rule execution event for "Order #0" that only has a correlation ID
2. Duplicate rule execution events at the end of the timeline
3. Rule execution events appearing after status change events, which breaks the logical order

## Root Causes

1. **The "Order #0" Issue**:
   - In `ProcessLogger.php`, when `universal_event_context` was active, the `finish()` method was still returning a correlation_id instead of `false`, causing an event with invalid data to be created
   - This resulted in a rule execution event for "Order #0" with minimal information
   - There was also a critical issue where the `createBusinessErrorMessage()` method in `UniversalEventProcessor.php` was incorrectly overwritten with the `validateEventData()` method's content, causing `array_keys()` errors

## Additional Issues Found

After testing, we discovered:
1. **Method Implementation Confusion**: The `createBusinessErrorMessage()` method had been incorrectly overwritten with the content of `validateEventData()`, causing PHP errors: `array_keys(): Argument #1 ($array) must be of type array, null given`
2. **Missing Proper Method Implementation**: The proper implementation of `validateEventData()` was missing from the class
3. **Incorrect Method Reference**: The `getExecutionSummary()` method was being called with a `self::` static reference but was implemented as an instance method

2. **Duplicate Rule Execution Events**: 
   - The code was not properly tracking which events had already been processed
   - Non-canonical events (like checkout_processed and order_check_scheduled) were creating separate rule execution events
   - Each rule evaluation was creating a new timeline event even if one already existed for that rule+order combination

3. **Incorrect Ordering**:
   - Rule execution events were being created after status change events, breaking the logical flow where a rule executes and then changes status

## Implemented Fixes

### 1. Fix in ProcessLogger.php

The critical fix in `ProcessLogger.php` was to ensure that when `universal_event_context` is active, the `finish()` method immediately returns `false` instead of the correlation ID:

```php
// Check if universal event context is active - if so, skip timeline event creation
if (self::$universal_event_context) {
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        $this->critical_log('Skipping timeline event creation due to universal event context');
    }
    // CRITICAL FIX: Return false during universal_event_context instead of correlation_id
    // This prevents Order #0 issues by avoiding the inclusion of process_id in logging calls
    return false;
}
```

### 2. Fix in UniversalEventProcessor.php

- Fixed the `createBusinessErrorMessage()` method which was incorrectly overwritten with `validateEventData()` content:
  ```php
  /**
   * Create business-friendly error message from technical error
   * 
   * Creates a simple error message by prefixing the original message with
   * the gateway name to provide context.
   * 
   * @param string $technical_message The technical error message
   * @param string $gateway The payment gateway name
   * @return string Business-friendly error message
   */
  public function createBusinessErrorMessage(string $technical_message, string $gateway): string
  {
      // Simple implementation: prefix with gateway name for context
      return "{$gateway} processing error: {$technical_message}";
  }
  ```

- Added proper implementation of the `validateEventData()` method which was missing:
  ```php
  /**
   * Validate event data for processing
   * 
   * Checks that the event data has all required fields and the correct format.
   * 
   * @param array $event_data Event data to validate
   * @param string $process_id Process ID for logging reference
   * @return bool True if valid, false otherwise
   */
  private function validateEventData(array $event_data, string $process_id): bool
  {
      // Required fields for UniversalEvent
      $required_fields = [/* fields list */];
      
      // Null check before using array_keys
      if (is_array($event_data)) {
          // validation logic
      } else {
          return false;
      }
      
      // more validation logic...
  }
  ```

- Fixed the `getExecutionSummary()` method call from static to instance method: 
  ```php
  // Changed from:
  $execution_summary = self::getExecutionSummary($context, $payload);
  
  // To:
  $execution_summary = $this->getExecutionSummary($context, $payload);
  ```

- Restored the full implementation of the file that contained critical methods including:
  - `processUniversalEventRules`: The core method that evaluates rules against events
  - `createConsolidatedRuleExecutionEvent`: The method that creates a single source of truth for rule execution events
  - Support methods for tracking and deduplicating events

- Enhanced validation to prevent invalid Order IDs:
  ```php
  // ENHANCED VALIDATION: Strict checks for Order #0 prevention
  // Validate order ID with detailed logging to avoid "Order #0" issues
  if (!$order_id || $order_id <= 0) {
      // Detailed logging of the issue
      return false;
  }
  ```

- Added canonical event tracking to prevent duplicate entries:
  ```php
  private function isCanonicalTimelineEvent(string $event_type): bool
  {
      // CANONICAL EVENTS (creates timeline events)
      $canonical_timeline_events = [
          'order_status_changed',  // When rules actually change order status
          'checkout_processed',    // When checkout is completed
          'order_created',        // When order is created
          'payment_completed',    // When payment is completed
      ];
      
      // NON-CANONICAL EVENTS (rule evaluation only, no timeline events)
      $non_canonical_events = [
          'order_check_scheduled',  // Internal scheduling, not business-relevant
          'rule_evaluation_non_canonical', // Debug traces for rule evaluation
          '_status_evaluation',     // Debug events for status change evaluation
          'process_started',        // Technical process lifecycle events
      ];
      
      // Logic to determine if this should create a timeline event
      // ...
  }
  ```

### 3. Fix in ProcessIdManager.php

Enhanced validation in ProcessIdManager to prevent Order #0 issues:

```php
/**
  * Get or create a process ID for an order lifecycle
  * 
  * Enhanced with stronger validation to prevent Order #0 issues
  * Uses strict validation to prevent any invalid order IDs from creating process IDs
  * that could lead to "Order #0" events in the timeline
  *
  * @param int $order_id The order ID to get/create process ID for
  * @return string|null Process ID string or null for invalid order IDs
  */
public function get_or_create_process_id(int $order_id): ?string
{
    // STRICTER VALIDATION: Ensure order ID is valid to prevent Order #0 issues
    if ($order_id <= 0) {
        // Log detailed warning when invalid order ID is provided
        if (defined('ODCM_DEBUG') && ODCM_DEBUG && function_exists('odcm_log_message')) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = isset($backtrace[1]) ? $backtrace[1]['function'] : 'unknown';
            
            odcm_log_message("PROCESS_ID_WARNING: Invalid order ID {$order_id} rejected (called from {$caller})", 'warning');
        }
        
        // Return null instead of a fallback process ID to force callers to handle this case
        return null;
    }
    
    // Original implementation follows...
}
```

## Expected Results

After these fixes:

1. The "Order #0" rule execution event should no longer appear
2. No duplicate rule execution events should be created
3. Rule execution events should appear in the proper logical order (rule executes → status changes)
4. The timeline should show a clean, logical flow of events without duplication

## Debugging Assistance

For debugging purposes, we've added more detailed logging throughout the codebase:

- ProcessLogger now logs when it skips timeline event creation due to universal_event_context
- UniversalEventProcessor logs detailed information about rule evaluation and event creation
- ProcessIdManager logs when invalid order IDs are rejected

These debug logs can be found in the WordPress debug log when ODCM_DEBUG is enabled.
