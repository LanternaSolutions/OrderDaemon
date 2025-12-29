# Timeline Debug Event Filtering Implementation Plan - UPDATED

## Current Problem Analysis

Looking at the actual rendered timeline in `timeline.txt`, I can see the issue:

**The "Rule Processing Started" event is still rendering in production mode because:**

1. **Event Type Mismatch**: The event has `event_type: "rule_execution"` (not a debug-specific type)
2. **Incomplete Rule Detection**: It's actually an incomplete rule processing event that should be filtered
3. **Current Filtering Logic**: The `shouldFilterDebugEvent()` method only checks for specific debug event types like `rule_evaluation_non_canonical`, `_status_evaluation`, etc.

## Updated Understanding

### The Real Issue

The problem is more nuanced than initially thought:

1. **Debug Events**: Events like `rule_evaluation_non_canonical` and `_status_evaluation` are correctly filtered
2. **Incomplete Rule Events**: Events with `event_type: "rule_execution"` but missing complete rule data should ALSO be filtered
3. **Detection Logic**: We need to detect incomplete rule events at the rendering level, not just by event type

### Current Filtering Flow

```
Event Payload → RegistryTimelineRenderer::renderComponent()
    ↓
shouldFilterDebugEvent() - checks event_type only
    ↓
Returns false for "rule_execution" events
    ↓
Event gets rendered even if it's incomplete
```

## Updated Solution Approach

### Simple Flag-Based Filtering at Render Time

**Key Insight**: We need to check BOTH:
1. **Event Type**: Is it a known debug-only event type?
2. **Event Completeness**: Does it have complete rule data?

### Implementation Plan

#### Step 1: Enhance the Filtering Logic

**File**: `src/API/Timeline/RegistryTimelineRenderer.php`

```php
/**
 * Enhanced debug event filtering - check both event type and completeness
 */
private function shouldFilterDebugEvent(array $payload): bool
{
    // Show all events in debug mode
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        return false;
    }

    // Get event type
    $event_type = $payload['data']['event_type'] ?? $payload['event_type'] ?? '';

    // 1. Check for known debug-only event types
    if (in_array($event_type, [
        'order_check_scheduled',
        'rule_evaluation_non_canonical',
        '_status_evaluation',
        'process_started',
        'order_loaded'
    ])) {
        return true;
    }

    // 2. Check for incomplete rule execution events
    // These have event_type "rule_execution" but lack complete rule data
    if ($event_type === 'rule_execution') {
        // Check if this is an incomplete rule processing event
        $hasCompleteRuleData = !empty($payload['rule_execution']['rule_name']) ||
                              !empty($payload['rule_execution']['rule_configuration']['rule_name']) ||
                              !empty($payload['data']['rule_name']);

        $hasProcessingMetadata = !empty($payload['data']['correlation_id']) ||
                               !empty($payload['data']['process_type']) ||
                               !empty($payload['data']['status']);

        // It's incomplete if it has processing data but lacks complete rule data
        if ($hasProcessingMetadata && !$hasCompleteRuleData) {
            return true;
        }
    }

    return false;
}
```

#### Step 2: Add Debug Flag to Event Data

**File**: `src/API/Timeline/RuleExecutionAdapter.php`

```php
/**
 * Extract specialized fields for rule execution events
 */
protected function extractSpecializedFields(array $payload): array
{
    // Check if this is an incomplete processing event
    if ($this->isIncompleteRuleEvent($payload)) {
        // Add debug flag to payload for filtering
        $payload['debug_only'] = true;
        return $this->extractProcessingStartedFields($payload);
    }

    // Original logic for complete rule execution events
    $fields = [];
    // ... rest of existing logic
}
```

#### Step 3: Update Event Type Configuration

**File**: `src/API/Timeline/DisplayAdapter.php`

Ensure incomplete rule events get proper debug styling:

```php
'rule_execution' => [
    'dashicon' => 'dashicons-admin-generic',
    'theme_class' => 'odcm-component--rule', // Default to rule styling
    'primary_color' => 'blue-700',
    'status_display' => 'success',
    'priority' => 2,
    'category' => 'Rule'
],
```

## Testing Strategy

### Test Cases

1. **Complete Rule Execution Events**: Should render in both debug and production modes
2. **Incomplete Rule Processing Events**: Should only render in debug mode
3. **Known Debug Events**: Should only render in debug mode
4. **Mixed Timeline**: Verify correct filtering in timeline with both types

### Test Data Examples

**Complete Rule Event** (should render):
```json
{
    "event_type": "rule_execution",
    "rule_execution": {
        "rule_name": "virtual rule",
        "rule_configuration": {...}
    }
}
```

**Incomplete Rule Event** (should be filtered):
```json
{
    "event_type": "rule_execution",
    "data": {
        "correlation_id": "odcm:lifecycle:104:...",
        "process_type": "rule_execution",
        "status": "success"
    }
    // Missing rule_name and rule_configuration
}
```

## Success Criteria

✅ **Incomplete Rule Events Filtered**: "Rule Processing Started" events hidden in production mode
✅ **Debug Events Filtered**: Known debug events hidden in production mode
✅ **Complete Rule Events Visible**: Normal rule executions still visible
✅ **Debug Mode Works**: All events visible when debug mode enabled
✅ **Proper Styling**: Debug events use correct CSS classes when shown

## Rollout Plan

1. **Immediate Fix**: Update `shouldFilterDebugEvent()` with incomplete rule detection
2. **Add Debug Flags**: Mark incomplete events in RuleExecutionAdapter
3. **Testing**: Verify with real timeline data
4. **Monitoring**: Check production timelines for proper filtering

## Future Enhancements

1. **Event Metadata**: Add explicit `debug_only: true` flag to event payloads
2. **Performance**: Optimize filtering logic for large timelines
3. **User Controls**: Allow users to toggle debug event visibility
4. **Logging**: Add debug logging for filtering decisions

This updated plan addresses the actual issue seen in the timeline where incomplete rule processing events are incorrectly rendering in production mode.
