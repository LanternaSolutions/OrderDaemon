# Timeline Display Adapter System Debugging Prompt

## Executive Summary

The new timeline display adapter system is experiencing a critical failure where ALL timeline events are falling back to the "Fallback View" instead of using the newly implemented display adapters. This document provides a comprehensive analysis of the issue for debugging purposes.

## Problem Description

### Current Behavior
- **All timeline events** are displaying as "Fallback View" with the message "Event data is available but could not be processed normally."
- **Affected Event Types**:
  - Order Created
  - Status Changed
  - Checkout Completed
  - Payment Event
  - Rule Execution Events

### Expected Behavior
- Events should be processed by the appropriate display adapters:
  - OrderEventAdapter for order-related events
  - PaymentEventAdapter for payment/checkout events
  - RuleExecutionAdapter for rule execution events
  - GenericEventAdapter as fallback for unknown events

## Technical Analysis

### System Architecture Overview

```
┌───────────────────────────────────────────────────────────────┐
│                    RegistryTimelineRenderer                   │
│  (Existing UI Component - Renders timeline events to HTML)     │
└───────────────────────────────────────────────────────────────┘
                                      ▲
                                      │
                                      ▼
┌───────────────────────────────────────────────────────────────┐
│                      AdapterRegistry                           │
│  (New Component - Maps event types to appropriate adapters)    │
└───────────────────────────────────────────────────────────────┘
                                      ▲
                                      │
                                      ▼
┌───────────────────────────────────────────────────────────────┐
│                      DisplayAdapter                            │
│  (Base Class - Provides defensive WordPress function wrappers) │
└───────────────────────────────────────────────────────────────┘
                                      ▲
                                      │
                                      ▼
┌───────────────────────────────────────────────────────────────┐
│  OrderEventAdapter  │  PaymentEventAdapter  │  RuleExecutionAdapter  │
│  (Specialized adapters for different event types)              │
└───────────────────────────────────────────────────────────────┘
```

### Key Components Analysis

#### 1. RegistryTimelineRenderer
**File**: `src/API/Timeline/RegistryTimelineRenderer.php`
**Purpose**: Renders timeline events to HTML using the new adapter system
**Critical Methods**:
- `renderTimelineEvent()` - Main rendering method
- `getDisplayData()` - Should call the new adapter system
- `renderFallbackView()` - Currently being used for all events

#### 2. AdapterRegistry
**File**: `src/API/Timeline/AdapterRegistry.php`
**Purpose**: Maps event types to appropriate display adapters
**Critical Methods**:
- `getAdapterForEvent()` - Main adapter selection logic
- `createAdapter()` - Instantiates adapter classes
- `getFallbackAdapter()` - Returns fallback adapter

#### 3. Display Adapters
**Files**:
- `OrderEventAdapter.php` - Order-related events
- `PaymentEventAdapter.php` - Payment/checkout events
- `RuleExecutionAdapter.php` - Rule execution events
- `GenericEventAdapter.php` - Fallback for unknown events

**Purpose**: Extract and format display data from event payloads

## Debugging Investigation Plan

### Phase 1: Integration Point Analysis

**Objective**: Verify that RegistryTimelineRenderer is correctly calling the new adapter system

**Steps**:
1. Examine `RegistryTimelineRenderer::getDisplayData()` method
2. Check if it's calling `AdapterRegistry::getAdapterForEvent()`
3. Verify the event payload structure being passed
4. Check for any error handling that might trigger fallback

**Expected Findings**:
- Method should call `AdapterRegistry::getAdapterForEvent($payload)`
- Should then call `$adapter->extractDisplayData($payload)`
- Should only fall back on exceptions

### Phase 2: Adapter Selection Analysis

**Objective**: Verify that AdapterRegistry is correctly selecting adapters

**Steps**:
1. Examine `AdapterRegistry::getAdapterForEvent()` method
2. Check the event type extraction logic
3. Verify the pattern matching for different event types
4. Test with actual event types from Order #100

**Expected Event Types**:
- `order_created`
- `status_changed`
- `checkout_completed`
- `payment_*`
- `rule_execution_*`

**Pattern Matching Logic**:
```php
// Current logic in AdapterRegistry
if (strpos($event_type, 'rule_execution') !== false) {
    $adapter = self::createAdapter('RuleExecutionAdapter', $event_type);
}
elseif (strpos($event_type, 'order_') !== false || strpos($event_type, 'status_changed') !== false) {
    $adapter = self::createAdapter('OrderEventAdapter', $event_type);
}
elseif (strpos($event_type, 'payment') !== false || strpos($event_type, 'checkout') !== false) {
    $adapter = self::createAdapter('PaymentEventAdapter', $event_type);
}
else {
    $adapter = self::createAdapter('GenericEventAdapter', $event_type);
}
```

### Phase 3: Error Analysis

**Objective**: Identify any errors preventing adapter usage

**Steps**:
1. Check PHP error logs for exceptions
2. Examine try-catch blocks in AdapterRegistry
3. Look for class existence checks
4. Verify adapter instantiation process

**Potential Error Sources**:
- Missing adapter classes
- Class autoloading issues
- Invalid adapter instances
- WordPress function availability checks

### Phase 4: Data Structure Analysis

**Objective**: Verify event data structure compatibility

**Steps**:
1. Examine actual event payload from Order #100
2. Compare with expected structure in adapters
3. Check for missing required fields
4. Verify event_type field format

**Expected Data Structure**:
```php
[
    'event_type' => 'order_created', // or other event type
    'order_id' => 100,
    'timestamp' => '2025-12-23 18:01:29',
    'data' => [
        // Event-specific data
    ],
    // Other event metadata
]
```

## Potential Root Causes

### 1. Integration Gap
**Hypothesis**: RegistryTimelineRenderer is not calling the new adapter system
**Evidence**: All events show fallback view
**Testing**: Check if `getDisplayData()` calls `AdapterRegistry::getAdapterForEvent()`

### 2. Adapter Selection Failure
**Hypothesis**: AdapterRegistry is failing to select appropriate adapters
**Evidence**: All events fall back to fallback view
**Testing**: Log adapter selection process and results

### 3. Class Loading Issues
**Hypothesis**: Adapter classes cannot be loaded/instantiated
**Evidence**: System falls back instead of using adapters
**Testing**: Check class existence and autoloading

### 4. Data Structure Mismatch
**Hypothesis**: Event data structure doesn't match adapter expectations
**Evidence**: Events have data but "could not be processed normally"
**Testing**: Compare actual vs expected data structures

### 5. Error Handling Issues
**Hypothesis**: Overly aggressive error handling causes fallback
**Evidence**: System falls back instead of failing visibly
**Testing**: Check try-catch blocks and error conditions

## Debugging Tools and Techniques

### 1. Logging Enhancement
Add detailed logging to:
- Adapter selection process
- Adapter instantiation
- Data extraction process
- Error conditions

### 2. Data Inspection
Inspect actual event payloads:
- Use `var_export()` or `print_r()`
- Log to debug file
- Compare with expected structures

### 3. Step-by-Step Testing
Test each component individually:
1. Test AdapterRegistry with sample event types
2. Test individual adapters with sample data
3. Test full integration chain

### 4. Error Simulation
Simulate potential error conditions:
- Missing event_type field
- Invalid event types
- Missing adapter classes
- Invalid data structures

## Recommended Debugging Approach

### Step 1: Verify Integration
```php
// In RegistryTimelineRenderer::getDisplayData()
try {
    $adapter = AdapterRegistry::getAdapterForEvent($payload);
    $displayData = $adapter->extractDisplayData($payload);
    // Use $displayData for rendering
} catch (\Throwable $e) {
    // Log the actual error
    error_log('Adapter failed: ' . $e->getMessage());
    // Fall back to fallback view
}
```

### Step 2: Log Adapter Selection
```php
// In AdapterRegistry::getAdapterForEvent()
self::logDebugMessage("Selecting adapter for event type: {$event_type}", 'debug');
$adapter = self::createAdapter('OrderEventAdapter', $event_type);
self::logDebugMessage("Selected adapter: " . get_class($adapter), 'debug');
```

### Step 3: Test with Real Data
```php
// Get actual event data from Order #100
$events = get_order_events(100);
foreach ($events as $event) {
    try {
        $adapter = AdapterRegistry::getAdapterForEvent($event);
        $displayData = $adapter->extractDisplayData($event);
        // Inspect $displayData
    } catch (\Throwable $e) {
        // Log specific error for this event
        error_log("Event {$event['event_type']} failed: " . $e->getMessage());
    }
}
```

## Expected Outcomes

1. **Identify Integration Issue**: If RegistryTimelineRenderer isn't calling the new system
2. **Find Adapter Selection Problem**: If pattern matching isn't working
3. **Discover Class Loading Issues**: If adapters can't be instantiated
4. **Uncover Data Structure Problems**: If event data doesn't match expectations
5. **Reveal Error Handling Issues**: If system falls back too aggressively

## Success Criteria

✅ All event types are processed by appropriate adapters
✅ No events fall back to fallback view unnecessarily
✅ System handles edge cases gracefully
✅ Error messages are clear and actionable
✅ Performance remains acceptable

## Next Steps for Debugging Agent

1. **Toggle to ACT mode** to begin debugging
2. **Start with integration verification** in RegistryTimelineRenderer
3. **Add comprehensive logging** to identify failure points
4. **Test with actual Order #100 data** to reproduce the issue
5. **Implement fixes** based on root cause analysis
6. **Verify all event types** render correctly after fixes

This document provides a complete roadmap for debugging the timeline display adapter system integration issue.
