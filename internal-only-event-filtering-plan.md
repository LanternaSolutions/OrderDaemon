# Internal-Only Event Filtering Implementation Plan

## Overview

This document provides a comprehensive plan for implementing a system to filter out internal-only events (system noise) from the frontend while preserving debug events for user troubleshooting. The solution distinguishes between:

1. **Internal-Only Events**: System events that are pure noise for users (never shown)
2. **Debug Events**: Technical events that help users troubleshoot (shown when debug mode enabled)
3. **Normal Events**: Business events shown to all users (always shown)

## Problem Statement

Currently, internal system events like `rule_no_match` are being shown in the frontend, creating noise and confusion for users. These events should never be displayed, regardless of debug settings, as they are implementation details rather than debugging information.

## Solution Architecture

### 1. Event Categorization System

```php
/**
 * EVENT CATEGORIZATION SYSTEM
 *
 * 1. INTERNAL-ONLY EVENTS: System events that are pure noise for users
 *    - Never shown in frontend, even with debug mode
 *    - Examples: rule_no_match, process_started, order_loaded
 *    - These are technical implementation details, not debugging information
 *
 * 2. DEBUG EVENTS: Technical events that help users troubleshoot
 *    - Shown when user enables debug mode (include_debug=true)
 *    - Examples: _status_evaluation, rule_evaluation_non_canonical
 *    - These provide useful troubleshooting information for advanced users
 *
 * 3. NORMAL EVENTS: Business events shown to all users
 *    - Always shown in frontend
 *    - Examples: order_created, payment_completed, status_changed
 */
```

### 2. Implementation Files

The implementation requires changes to the following files:

1. **`src/API/Timeline/AdapterRegistry.php`** - Core filtering logic
2. **`src/API/AuditLogEndpoint.php`** - Database-level filtering
3. **`src/API/Timeline/ProcessLoggerComponentExtractor.php`** - Component extraction filtering
4. **`src/API/Timeline/RegistryTimelineRenderer.php`** - Rendering filtering

## Detailed Implementation Steps

### Step 1: Add Internal-Only Event System to AdapterRegistry

```php
// Add to src/API/Timeline/AdapterRegistry.php

/**
 * Get list of events that are internal system noise and should NEVER be shown
 *
 * @return array List of internal-only event types
 */
private static function getInternalOnlyEvents(): array
{
    return [
        'rule_no_match',            // Rule evaluation failures (pure noise)
        'process_started',         // Internal process lifecycle
        'order_loaded',            // Technical loading events
        'universal_event_processing_debug', // Debug processing noise
        // Add more internal-only events here as needed
    ];
}

/**
 * Check if event should be completely filtered (internal system noise)
 *
 * @param string $eventType Event type to check
 * @return bool True if event should never reach frontend
 */
public static function isInternalOnlyEvent(string $eventType): bool
{
    // Use WordPress-approved array search for better performance
    return in_array($eventType, self::getInternalOnlyEvents(), true);
}
```

### Step 2: Update Database-Level Filtering in AuditLogEndpoint

```php
// Add to src/API/AuditLogEndpoint.php

/**
 * Build WHERE conditions for internal-only event filtering
 *
 * @return array Array with conditions and parameters for prepared statements
 */
private function buildInternalOnlyEventFilter(): array
{
    $internalOnlyEvents = [];
    if (class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\AdapterRegistry')) {
        $internalOnlyEvents = AdapterRegistry::getInternalOnlyEvents();
    }

    $conditions = [];
    $params = [];

    // Use WordPress-approved prepared statements
    foreach ($internalOnlyEvents as $eventType) {
        $conditions[] = "l.event_type != %s";
        $params[] = $eventType;
    }

    return [$conditions, $params];
}

/**
 * Update get_all_filtered_logs method to include internal-only filtering
 */
private function get_all_filtered_logs(WP_REST_Request $request): array|WP_Error
{
    global $wpdb;

    // Start with internal-only event filtering (always applied)
    list($internalConditions, $internalParams) = $this->buildInternalOnlyEventFilter();

    $conditions = $internalConditions;
    $params = $internalParams;

    // Then apply debug filtering based on user preference
    $include_debug = (bool) $request->get_param('include_debug');
    if (!$include_debug) {
        $debugConditions = $this->buildDebugEventFilter();
        $conditions = array_merge($conditions, $debugConditions['conditions']);
        $params = array_merge($params, $debugConditions['params']);
    }

    // Build query with WordPress-approved prepared statements
    $where_clause = implode(' AND ', $conditions);
    $sql = $wpdb->prepare(
        "SELECT l.* FROM `{$wpdb->prefix}odcm_audit_log` l WHERE $where_clause ORDER BY l.timestamp DESC",
        ...$params
    );

    // Execute with proper error handling
    $results = $wpdb->get_results($sql, ARRAY_A);

    if ($wpdb->last_error) {
        $this->logDatabaseError('get_all_filtered_logs', $wpdb->last_error, $sql);
        return new WP_Error('database_error', 'Database query failed');
    }

    return $results ?: [];
}
```

### Step 3: Update Component Extraction Filtering

```php
// Update src/API/Timeline/ProcessLoggerComponentExtractor.php

/**
 * Check if component should be filtered based on internal-only and debug preferences
 */
private function shouldFilterComponent(array $component, bool $includeDebug): bool
{
    $event_type = $component['event_type'] ?? '';

    // FIRST: Filter internal-only events (always filtered)
    if (class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\AdapterRegistry') &&
        AdapterRegistry::isInternalOnlyEvent($event_type)) {
        return true;
    }

    // SECOND: Filter debug events based on user preference
    if (!$includeDebug && $this->isDebugOnlyComponent($component)) {
        return true;
    }

    return false;
}

/**
 * Check if component is debug-only (for troubleshooting)
 */
private function isDebugOnlyComponent(array $component): bool
{
    $event_type = $component['event_type'] ?? '';
    $level = $component['level'] ?? '';

    // Debug events are those with debug level or specific debug prefixes
    // Note: These are different from internal-only events
    return ($level === 'debug') ||
           (strpos($event_type, 'debug_') === 0) ||
           in_array($event_type, [
               '_status_evaluation',
               'rule_evaluation_non_canonical',
               'order_check_scheduled'
           ], true);
}
```

### Step 4: Update Rendering Filtering

```php
// Update src/API/Timeline/RegistryTimelineRenderer.php

/**
 * Check if component should be filtered during rendering
 */
private function shouldFilterComponentForRendering(array $payload, bool $includeDebug): bool
{
    $event_type = $payload['data']['event_type'] ?? $payload['event_type'] ?? '';

    // FIRST: Filter internal-only events (always filtered)
    if (method_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\AdapterRegistry', 'isInternalOnlyEvent') &&
        AdapterRegistry::isInternalOnlyEvent($event_type)) {
        $this->logDebugMessage("FILTERED: Internal-only event: {$event_type}");
        return true;
    }

    // SECOND: Filter debug events based on user preference
    if (!$includeDebug && $this->isDebugComponent($payload)) {
        $this->logDebugMessage("FILTERED: Debug event (include_debug=false): {$event_type}");
        return true;
    }

    return false;
}
```

## WordPress Compliance Requirements

### Database Query Compliance

All database queries must:

1. **Use `$wpdb->prepare()`** for all SQL statements
2. **Properly escape table names** using `esc_sql()`
3. **Handle errors gracefully** with proper error logging
4. **Use parameterized queries** to prevent SQL injection

### Example Compliant Query

```php
// WordPress-compliant database query
global $wpdb;

$internalOnlyEvents = AdapterRegistry::getInternalOnlyEvents();
$conditions = [];
$params = [];

foreach ($internalOnlyEvents as $eventType) {
    $conditions[] = "l.event_type != %s";
    $params[] = $eventType;
}

$where_clause = implode(' AND ', $conditions);
$sql = $wpdb->prepare(
    "SELECT l.* FROM `{$wpdb->prefix}odcm_audit_log` l WHERE $where_clause ORDER BY l.timestamp DESC",
    ...$params
);

$results = $wpdb->get_results($sql, ARRAY_A);

if ($wpdb->last_error) {
    // Proper error handling
    error_log("Database error: " . $wpdb->last_error);
    return new WP_Error('database_error', 'Database query failed');
}
```

## Testing Plan

### Test Cases

1. **Internal-Only Events**
   - Verify `rule_no_match` events never appear in frontend
   - Test with debug mode enabled and disabled
   - Check database queries exclude internal-only events

2. **Debug Events**
   - Verify debug events appear when `include_debug=true`
   - Verify debug events are hidden when `include_debug=false`
   - Test specific debug events: `_status_evaluation`, `rule_evaluation_non_canonical`

3. **Normal Events**
   - Verify normal business events always appear
   - Test various event types: `order_created`, `payment_completed`, `status_changed`

4. **Edge Cases**
   - Test empty event lists
   - Test malformed event data
   - Test database errors

### Test Scenarios

1. **Log Stream View**
   - Verify internal-only events don't appear
   - Verify debug events work with toggle
   - Verify normal events always appear

2. **Timeline View**
   - Verify internal-only events don't appear in detailed timeline
   - Verify debug events work with toggle
   - Verify normal events always appear

3. **Database Queries**
   - Verify SQL queries are properly parameterized
   - Verify error handling works correctly
   - Verify performance is acceptable

## Implementation Checklist

- [ ] Add `getInternalOnlyEvents()` method to `AdapterRegistry.php`
- [ ] Add `isInternalOnlyEvent()` method to `AdapterRegistry.php`
- [ ] Update `get_all_filtered_logs()` in `AuditLogEndpoint.php`
- [ ] Update `get_filtered_logs()` in `AuditLogEndpoint.php`
- [ ] Update `get_filtered_log_count()` in `AuditLogEndpoint.php`
- [ ] Update component extraction in `ProcessLoggerComponentExtractor.php`
- [ ] Update rendering filtering in `RegistryTimelineRenderer.php`
- [ ] Add comprehensive error handling
- [ ] Add logging for debugging
- [ ] Test internal-only event filtering
- [ ] Test debug event filtering
- [ ] Test normal event display
- [ ] Verify WordPress compliance
- [ ] Performance testing
- [ ] Edge case testing

## Success Criteria

1. **Internal-only events** (`rule_no_match`, etc.) never appear in frontend
2. **Debug events** appear only when `include_debug=true`
3. **Normal events** always appear regardless of settings
4. **Database queries** are WordPress-compliant and secure
5. **Performance** is not degraded
6. **Error handling** works correctly
7. **Logging** provides adequate debugging information

## Documentation Requirements

1. **Code comments** explaining the filtering system
2. **Method documentation** for all new methods
3. **Inline comments** for complex logic
4. **Error messages** that are clear and actionable
5. **Logging** that helps with troubleshooting

## Rollback Plan

If issues arise:

1. **Revert database changes** first (most critical)
2. **Revert component extraction changes**
3. **Revert rendering changes**
4. **Test each step** to isolate issues
5. **Provide clear error messages** to help diagnose problems

## Maintenance Plan

1. **Add new internal-only events** to the centralized list
2. **Update documentation** when adding new event types
3. **Monitor performance** of filtering queries
4. **Review event categorization** periodically
5. **Update tests** when adding new event types

## Implementation Notes

- **Use strict type comparisons** (`===`, `!==`) for reliability
- **Use WordPress-approved methods** for all operations
- **Add proper error handling** at all levels
- **Include comprehensive logging** for debugging
- **Follow WordPress coding standards**
- **Maintain backward compatibility**
- **Optimize database queries** for performance

This plan provides a complete, WordPress-compliant solution for filtering internal-only events while preserving debug functionality for user troubleshooting.