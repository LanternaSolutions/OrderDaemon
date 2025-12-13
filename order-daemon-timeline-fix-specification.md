# Order Daemon Timeline Event Duplication Fix Specification

## Executive Summary

This document details a critical issue in the Order Daemon plugin where timeline events are being incorrectly duplicated, creating confusion for users. The issue stems from improper classification of "canonical" vs "non-canonical" events in the UniversalEventProcessor.

## Problem Analysis

### Current Behavior

The timeline currently shows duplicate events that serve no purpose for end users:

1. **Order Created** appears twice:
   - First as a proper event with complete data
   - Second as an empty "DEBUG" event with no data fields

2. **Checkout Completed** appears twice:
   - First as a "PROCESSED" event with full data
   - Second as a "DEBUG" event with empty fields

3. **Additional unnecessary debug events** appear in the timeline:
   - `_status_evaluation` events (purely technical)
   - `payment_completed` debug traces
   - `order_check_scheduled` internal events

### Root Cause

The issue is in the `UniversalEventProcessor::isCanonicalTimelineEvent()` method which incorrectly classifies legitimate business events as "non-canonical":

```php
// CURRENT (INCORRECT) CLASSIFICATION
$non_canonical_events = [
    'checkout_processed',      // WRONG: This is a legitimate business event
    'order_created',          // WRONG: This is a legitimate business event
    'payment_completed',      // WRONG: This is a legitimate business event
    'order_check_scheduled',  // CORRECT: This is purely technical
];
```

### Impact on Users

- **Confusion**: Users see duplicate events and don't know which one to trust
- **Clutter**: Timeline is filled with empty/meaningless debug events
- **Distrust**: Users may question the reliability of the timeline data
- **Inefficiency**: Users waste time trying to understand why events appear twice

## Technical Deep Dive

### Event Creation Flow

1. **UniversalEventProcessor::processEvent()** receives an event
2. **UniversalEventProcessor::logProcessingResult()** decides what to log:
   - Creates main business event if event has components
   - Creates debug event for non-canonical events (INCORRECT LOGIC)
   - Creates debug event for events without components

3. **RegistryTimelineRenderer::shouldFilterDebugEvent()** attempts to filter:
   - Filters out events like `order_created`, `checkout_processed` (INCORRECT)
   - This creates the "empty duplicate" phenomenon

### Canonical vs Non-Canonical Event Philosophy

**Canonical Events** (Should appear in main timeline):
- Represent real business milestones
- Single source of truth for what happened
- Contain meaningful data for end users
- Examples: order created, checkout completed, status changed, payment completed

**Non-Canonical Events** (Should be debug-only):
- Purely technical/internal events
- Duplicate information from canonical events
- Not meaningful to end users
- Examples: rule evaluation traces, scheduling events, process started

## Recommended Solution

### 1. Fix Event Classification

**Update `UniversalEventProcessor::isCanonicalTimelineEvent()` method:**

```php
// CORRECT CLASSIFICATION
$canonical_timeline_events = [
    'order_status_changed',
    'checkout_processed',      // LEGITIMATE: Shows checkout completion
    'order_created',          // LEGITIMATE: Shows order creation
    'payment_completed',      // LEGITIMATE: Shows payment completion
];

$non_canonical_events = [
    'order_check_scheduled',  // TECHNICAL: Internal scheduling only
    'rule_evaluation_non_canonical', // DEBUG: Rule evaluation traces
    '_status_evaluation',     // DEBUG: Status change evaluation
    'process_started',        // TECHNICAL: Process lifecycle events
];
```

### 2. Fix Debug Event Filtering

**Update `RegistryTimelineRenderer::shouldFilterDebugEvent()` method:**

```php
private function shouldFilterDebugEvent(array $payload): bool
{
    // Show all events in debug mode
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        return false;
    }

    // Get event type
    $event_type = $payload['data']['event_type'] ?? $payload['event_type'] ?? '';

    // Hide ONLY truly technical debug events (not business events)
    if (in_array($event_type, [
        'order_check_scheduled',
        'rule_evaluation_non_canonical',
        '_status_evaluation',
        'process_started',
        'order_loaded' // Purely technical loading event
    ])) {
        return true;
    }

    return false;
}
```

### 3. Fix Price Formatting

**Update `OrderRenderer::renderOrderCreated()` method:**

Current problematic code uses `wc_price()` which generates HTML wrapping:
```php
'Amount' => isset($data['amount'], $data['currency'])
   ? wc_price($data['amount'], ['currency' => $data['currency']])
   : '',
```

Fix to use clean formatting:
```php
'Amount' => isset($data['amount'], $data['currency'])
   ? $this->formatCurrency($data['amount'], $data['currency'])
   : '',
```

Add helper method:
```php
private function formatCurrency(float $amount, string $currency): string
{
    $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol($currency));
    return $currency_symbol . number_format((float)$amount, 2);
}
```

### 4. Improve Technical Details Organization

Enhance the UI components to better organize technical details in expandable sections rather than cluttering the main view.

## Implementation Plan

### Step 1: Fix Event Classification

**File**: `src/Core/Events/UniversalEventProcessor.php`
**Method**: `isCanonicalTimelineEvent()`
**Change**: Remove `checkout_processed`, `order_created`, and `payment_completed` from non-canonical list

### Step 2: Fix Debug Filtering

**File**: `src/API/Timeline/RegistryTimelineRenderer.php`
**Method**: `shouldFilterDebugEvent()`
**Change**: Update filtering logic to only hide truly technical events

### Step 3: Fix Price Formatting

**File**: `src/View/PayloadRenderer/OrderRenderer.php`
**Method**: `renderOrderCreated()`
**Change**: Replace `wc_price()` with clean currency formatting

### Step 4: Test Changes

1. **Unit Tests**: Verify event classification logic
2. **Integration Tests**: Verify timeline rendering
3. **Manual Testing**: Verify timeline appears clean and understandable
4. **Debug Mode Testing**: Verify debug events still appear when needed

## Expected Results

### Before Fix

```
Order Created                [PROPER EVENT]
Order Created DEBUG          [EMPTY DUPLICATE]
Checkout Completed PROCESSED [PROPER EVENT]
Checkout Completed DEBUG     [EMPTY DUPLICATE]
_status_evaluation DEBUG     [UNNECESSARY]
payment_completed DEBUG      [UNNECESSARY]
```

### After Fix

```
Order Created                [SINGLE PROPER EVENT]
Checkout Completed PROCESSED [SINGLE PROPER EVENT]
Payment Completed            [SINGLE PROPER EVENT]
[Debug events only appear in debug mode]
```

## Benefits

1. **Clean Timeline**: Users see each business event exactly once
2. **Improved Trust**: Timeline data is reliable and understandable
3. **Better UX**: Users can quickly understand order history
4. **Maintained Debugging**: Technical events still available in debug mode
5. **Performance**: Fewer events to render and process

## Risk Assessment

- **Low Risk**: Changes are localized to event classification logic
- **Backward Compatible**: Existing events continue to work
- **Debug Preserved**: All debugging capability maintained
- **Easy Rollback**: Changes are simple and reversible

## Conclusion

This fix will transform the timeline from a confusing, cluttered view into a clean, reliable record of order events that users can trust and understand. The changes are surgical, low-risk, and preserve all existing functionality while dramatically improving the user experience.
