Excellent point! I'll provide a focused implementation plan that completely hides `_status_evaluation` events from all frontend interfaces while preserving them in the database for internal plugin operations.

## Updated Implementation Plan: Frontend-Only Filtering for _status_evaluation Events

### 1. **Core Principle**
**Preserve internal events, hide from users**: Keep `_status_evaluation` events in the database for plugin functionality, but ensure they never appear in any user-facing interface.

### 2. **Current State Analysis**

After analyzing the codebase, I found that `_status_evaluation` events are currently:

1. **In `RegistryTimelineRenderer.php`**:
   - NOT in the filtered events array in `shouldFilterDebugEvent()` method (needs to be added)
   - Included in `extractPrimaryStatus()` method as a debug event (needs to be removed)
   - Has a dedicated configuration in `getEventTypeConfig()` method (needs to be removed)
   - Included in `mapStatusToPillType()` method for special debug handling (needs to be removed)

2. **In `GenericEventAdapter.php`**:
   - Has a dedicated `addStatusEvaluationFields()` method call (needs to be removed)

3. **In `DisplayAdapter.php`**:
   - Included in `mapStatusToPillType()` method for special debug handling (needs to be removed)
   - Has a configuration in `getEventTypeConfig()` method (needs to be removed)

4. **In `AuditLogEndpoint.php`**:
   - No specific filtering for `_status_evaluation` events in the API endpoints (needs to be added)

### 3. **Implementation Steps**

#### A. Timeline Renderer Filtering (Primary Filter)
**File**: `src/API/Timeline/RegistryTimelineRenderer.php`

**Change the `shouldFilterDebugEvent()` method**:
```php
// Add _status_evaluation to the filtered events array
if (in_array($event_type, [
    'order_check_scheduled',
    'rule_evaluation_non_canonical',
    'process_started',
    'order_loaded',
    '_status_evaluation'  // Add this line
])) {
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - debug-only event type: {$event_type}");
    }
    return true;  // This ensures the event is completely hidden from rendering
}
```

**Update `extractPrimaryStatus()` method**:
```php
// Remove _status_evaluation from special debug event handling
// Before:
if (in_array($eventType, ['_status_evaluation', 'rule_evaluation_non_canonical', 'debug', 'process_started', 'order_loaded'])) {

// After:
if (in_array($eventType, ['rule_evaluation_non_canonical', 'debug', 'process_started', 'order_loaded'])) {
```

**Remove from `getEventTypeConfig()` method**:
```php
// Remove this entire configuration block
'_status_evaluation' => [
    'dashicon' => 'dashicons-info-outline',
    'theme_class' => 'odcm-component--debug',
    'primary_color' => 'yellow-700',
    'status_display' => 'evaluation',
    'priority' => 1,
    'category' => 'Rule'
],
```

**Update `mapStatusToPillType()` method**:
```php
// Remove _status_evaluation from special handling
// Before:
if (in_array($eventType, ['_status_evaluation', 'rule_evaluation_non_canonical', 'debug'])) {

// After:
if (in_array($eventType, ['rule_evaluation_non_canonical', 'debug'])) {
```

#### B. Remove Adapter Special Handling
**File**: `src/API/Timeline/GenericEventAdapter.php`

**Remove the dedicated method call**:
```php
// Remove this elseif block completely
elseif ($eventType === '_status_evaluation') {
    $this->addStatusEvaluationFields($fields, $payload);
}
```

#### C. Remove Display Adapter Configuration
**File**: `src/API/Timeline/DisplayAdapter.php`

**Update `mapStatusToPillType()` method**:
```php
// Remove _status_evaluation from special handling
// Before:
if (in_array($eventType, ['_status_evaluation', 'rule_evaluation_non_canonical', 'debug'])) {

// After:
if (in_array($eventType, ['rule_evaluation_non_canonical', 'debug'])) {
```

**Remove from `getEventTypeConfig()` method**:
```php
// Remove this entire configuration block
'_status_evaluation' => [
    'dashicon' => 'dashicons-warning',
    'theme_class' => 'odcm-component--error',
    'primary_color' => 'red-700',
    'status_display' => 'evaluation',
    'priority' => 1,
    'category' => 'System'
],
```

#### D. API Endpoint Filtering
**File**: `src/API/AuditLogEndpoint.php`

Add filtering to API methods that return timeline data:
```php
// Add filtering to get_all_filtered_logs method
private function get_all_filtered_logs(WP_REST_Request $request): array|WP_Error
{
    // ... existing code ...

    // Add _status_evaluation filtering
    $conditions[] = "l.event_type != %s";
    $params[] = '_status_evaluation';

    // ... rest of existing code ...
}
```

### 4. **Testing Strategy**

#### A. Unit Tests
```php
// Test that _status_evaluation events are filtered
public function test_status_evaluation_filtering() {
    $payload = [
        'event_type' => '_status_evaluation',
        'data' => ['from' => 'pending', 'to' => 'processing']
    ];

    $renderer = new RegistryTimelineRenderer();
    $result = $renderer->renderComponent($payload, false, false, false);

    $this->assertEmpty($result, '_status_evaluation events should be completely filtered');
}
```

#### B. Integration Tests
- Verify timeline rendering with mixed event types
- Confirm other debug events still work when toggle is ON
- Test that business events are unaffected
- Test API endpoints return filtered data

#### C. Regression Tests
- Test complete order lifecycle scenarios
- Verify rule execution visibility
- Check timeline grouping functionality
- Ensure database integrity is maintained

### 5. **Expected Results**

1. **Frontend**: `_status_evaluation` events completely invisible in all user interfaces
2. **Database**: Events preserved for internal plugin operations
3. **Performance**: Improved rendering performance due to fewer events
4. **UX**: Cleaner, more focused timeline experience
5. **Debugging**: Internal plugin functionality remains intact
6. **API**: Consistent filtering across all data endpoints

### 6. **Deployment Plan**

1. **Phase 1**: Implement filtering changes (immediate frontend cleanup)
2. **Phase 2**: Test thoroughly in staging environment
3. **Phase 3**: Deploy to production
4. **Phase 4**: Monitor for any edge cases

### 7. **Rollback Plan**

If issues arise:
```php
// Temporary feature flag for emergency rollback
add_filter('odcm_show_status_evaluation_events', '__return_true');

// Add conditional filtering
if (apply_filters('odcm_show_status_evaluation_events', false)) {
    // Show the events (rollback state)
} else {
    // Hide the events (normal state)
}
```

### 8. **Implementation Order**

I recommend implementing these changes in the following order:

1. **RegistryTimelineRenderer.php** (primary filtering layer)
2. **GenericEventAdapter.php** (remove special handling)
3. **DisplayAdapter.php** (remove configurations)
4. **AuditLogEndpoint.php** (API filtering)

This approach ensures comprehensive filtering at multiple levels while maintaining database integrity.

## Summary

This updated implementation plan addresses the specific findings from the codebase analysis. The changes will completely remove `_status_evaluation` events from all frontend interfaces while preserving them in the database for internal plugin operations. The implementation focuses on rendering and display layers to ensure users never see these redundant events while maintaining all internal functionality.
