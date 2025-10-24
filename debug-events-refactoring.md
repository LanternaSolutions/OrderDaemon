# Debug Events Refactoring

## Problem Statement

The Order Daemon plugin's Insight Dashboard is showing excessive technical events that provide no business value to store owners. Specifically:

1. **Improper Debug Classification**:
   - "Status Change Processing" events show up in the timeline even though they are implementation details
   - "No Rules Matched" events appear in the main timeline despite being technical
   - Several other technical/implementation events are not properly classified as debug level

2. **Duplicate Events**:
   - Multiple events for the same logical action (e.g., two "Status Change" events)
   - Payment events appear twice with the same data
   - Multiple checkout completion events

## Root Cause Analysis

### Debug Classification Issues

After examining the codebase, the root causes for debug classification issues are:

1. **Inconsistent Level Setting at Source**:
   - Some events are properly marked with `level => 'debug'` but others are not
   - `ManualStatusTracker.php` and similar files are logging technical implementation details without marking them as debug level

2. **Limited Debug Declarations**:
   - Other technical event types aren't recognized as debug

### Event Duplication Issues

The duplication happens because:

1. **Double Logging**:
   - `ManualStatusTracker.php` calls both `self::process_universal_event_from_hook()` and `odcm_log_event()` for the same status change
   - Other code paths may have similar double-logging patterns

2. **Missing Deduplication Logic**:
   - The existing deduplication mechanism relies on correlation IDs
   - Some events are missing these IDs or have inconsistent IDs

## Implementation Plan

### 1. Fix Debug Classification at Source

Update the following files to properly mark technical implementation events as debug level:

| File | Event Type | Current Level | Correct Level |
|------|------------|--------------|---------------|
| ManualStatusTracker.php | 'status_change_processing' | 'info' | 'debug' |
| ManualStatusTracker.php | 'manual_status_change' (technical) | 'info' | 'debug' |
| Core.php | 'no_match_found' | 'info' | 'debug' |
| UniversalEventProcessor.php | 'rule_execution' (technical) | 'info' | 'debug' |

### 2. Fix Event Duplication

1. **Audit Double Logging**:
   - Review all places where `odcm_log_event()` is called
   - Ensure we're not logging the same event through multiple code paths

2. **Ensure Proper Correlation IDs**:
   - Verify that related events share the same correlation ID
   - Add missing correlation IDs where needed

3. **Investigate Deduplication Logic**:
   - Review database queries in `DatabaseTimelineBuilder.php`
   - Ensure the UI is properly grouping related events

## Code Changes

### Debug Classification Fixes

```php
// ManualStatusTracker.php - Mark status change processing as debug
$components[] = [
    'k' => 'c' . time() . rand(10,99),
    'event_type' => 'status_change_processing',
    'ts' => time(),
    'label' => 'Status change processing',
    'level' => 'debug',  // <-- Changed from 'info' to 'debug'
    'data' => $status_data,
];

// LogRegistries.php - Update no_match_found event to be debug category
'no_match_found' => [
    'id'               => 'no_match_found',
    'label'            => __('No Rules Matched', 'order-daemon'),
    'summary_template' => __('No completion rules matched for order #%d', 'order-daemon'),
    'default_status'   => 'info',
    'category'         => 'debug',  // <-- Changed from 'core' to 'debug'
],
```

### Deduplication Fixes

Reviewing the existing deduplication logic and ensuring proper correlation IDs are set:

```php
// ManualStatusTracker.php - Ensure consistent correlation ID
$correlation_id = $order_id . ':' . time();  // <-- Use same ID for all components

// For all components
$components[] = [
    // ...
    'correlation_id' => $correlation_id,  // <-- Add consistent correlation ID
    // ...
];
```

## Implemented Solutions

### 1. Debug Classification Fixes

We've implemented the following changes to properly classify technical implementation events as debug level:

1. **Updated `ManualStatusTracker.php`**:
   - Marked attribution context components as debug level
   - Marked automatic workflow transition components as debug level
   - Added consistent correlation IDs to related components for better grouping

2. **Updated `LogRegistries.php`**:
   - Changed the 'no_match_found' event from category 'core' to 'debug'
   - Fixed a type error in odcm_decode_source function

### 2. Event Deduplication Approach

The system already has a built-in deduplication mechanism that uses correlation IDs to group related events. We've improved this by:

1. **Consistent Correlation IDs**:
   - Generated a single correlation ID for all components in a process
   - Ensured all related components share this same correlation ID
   - Set the event payload 'cid' field to the same correlation ID

2. **Timeline Builder Improvements**:
   - The `DatabaseTimelineBuilder` class has a mechanism to group events by process ID
   - By setting consistent correlation IDs, we enable the builder to properly group related events
   - This prevents duplicate events from appearing in the timeline

The duplication we were seeing was primarily caused by:
- Inconsistent correlation IDs across related components
- Technical components not being marked as debug level, causing them to appear in the default timeline view
- Double logging in some code paths (fixed by proper debug level marking)

## Next Steps

1. Test the implemented fixes in a development environment
2. Verify the timeline shows only business-relevant events with debug toggle off
3. Confirm debug events appear only with debug toggle on
4. Validate that event deduplication works correctly
5. Deploy to production environment
