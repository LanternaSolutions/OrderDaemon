# Timeline Timestamp Unification Plan

## Executive Summary

This document outlines a comprehensive plan to unify timestamp handling between log stream entries and timeline events in the Order Daemon system. The goal is to ensure that all timestamp display settings applied to log stream entries are also consistently applied to timeline events.

## Current State Analysis

### Log Stream Timestamp Handling

**Backend Implementation:**
- Raw timestamps are sent to the frontend without server-side formatting
- Timestamps are stored in the `timestamp` property of log entries
- No preprocessing or formatting is applied server-side

**Frontend Implementation:**
- Uses `formatTimestamp()` function in `insight-dashboard.js`
- Supports multiple display modes: 'timeOnly', 'dateTime', 'relative'
- User preferences are stored in `timestampDisplayMode` setting
- Timestamps are formatted client-side based on user preferences

**Key Code Locations:**
- `assets/js/insight-dashboard.js`: `formatTimestamp()` function (lines 1200-1250)
- Template usage: `<div class="odcm-log-timestamp js-format-timestamp" x-text="formatTimestamp(log?.timestamp, $el)"></div>`

### Timeline Event Timestamp Handling

**Backend Implementation:**
- Timestamps are formatted server-side using `formatTimestamp()` method in `RegistryTimelineRenderer.php`
- Formatted timestamps are embedded directly in the HTML output
- No raw timestamp data is sent to the frontend

**Frontend Implementation:**
- Timeline events are rendered as pre-formatted HTML
- No client-side timestamp processing occurs
- Timeline timestamps do not respect user display preferences

**Key Code Locations:**
- `src/API/Timeline/RegistryTimelineRenderer.php`: `formatTimestamp()` method (lines 300-350)
- Template usage: Hardcoded formatted timestamps in HTML output

## Problem Statement

The current implementation creates inconsistency in timestamp display:
1. **Different Processing Locations**: Log stream timestamps are formatted client-side, timeline timestamps are formatted server-side
2. **User Preferences Ignored**: Timeline events don't respect the user's timestamp display mode setting
3. **Inconsistent Formatting**: The two systems use different formatting logic and produce different results
4. **Maintenance Complexity**: Duplicate timestamp formatting logic exists in both PHP and JavaScript

## Solution Architecture

### High-Level Approach

1. **Unify Data Flow**: Ensure both log stream and timeline events send raw timestamps to the frontend
2. **Centralize Formatting Logic**: Use the existing JavaScript `formatTimestamp()` function for all timestamp formatting
3. **Preserve User Preferences**: Ensure timeline events respect the same display mode settings as log stream entries

### Detailed Implementation Plan

#### Phase 1: Backend Changes

**1. Modify TimelineEvent.php**
- **File**: `src/API/Timeline/TimelineEvent.php`
- **Changes**:
  - Ensure `timestamp` property contains raw timestamp data
  - Update `toArray()` method to include raw timestamp
  - Remove any server-side timestamp formatting

**2. Update RegistryTimelineRenderer.php**
- **File**: `src/API/Timeline/RegistryTimelineRenderer.php`
- **Changes**:
  - Remove the `formatTimestamp()` method (lines 300-350)
  - Replace all calls to `formatTimestamp()` with direct timestamp output
  - Update template generation to include raw timestamps with `js-format-timestamp` class
  - Ensure timeline HTML includes timestamp data attributes for JavaScript processing

**3. Update Timeline Adapters**
- **Files**: All files in `src/API/Timeline/` ending with `Adapter.php`
- **Changes**:
  - Ensure all adapters pass through raw timestamps without formatting
  - Update any timestamp-related methods to preserve raw data

#### Phase 2: Frontend Changes

**1. Enhance formatTimestamp() Function**
- **File**: `assets/js/insight-dashboard.js`
- **Changes**:
  - Update the `formatTimestamp()` function to handle timeline event timestamps
  - Add support for timeline-specific timestamp formats if needed
  - Ensure the function can handle both log stream and timeline timestamp data structures

**2. Update Timeline Rendering**
- **File**: `assets/js/insight-dashboard.js`
- **Changes**:
  - Modify timeline rendering logic to use the same `formatTimestamp()` function
  - Ensure timeline timestamp elements have the `js-format-timestamp` class
  - Update any timeline-specific timestamp processing to use centralized logic

**3. CSS Updates**
- **File**: `assets/css/insight-dashboard.css`
- **Changes**:
  - Ensure timeline timestamp elements have consistent styling with log stream timestamps
  - Add any necessary CSS classes for proper timestamp display

#### Phase 3: Testing and Validation

**Test Cases:**
1. **Display Mode Consistency**: Verify all display modes work identically for both log stream and timeline
2. **User Preference Persistence**: Ensure timeline respects saved user preferences
3. **Timestamp Format Compatibility**: Test various timestamp formats (ISO8601, Unix, MySQL)
4. **Performance Impact**: Measure any performance changes from client-side formatting
5. **Edge Cases**: Test null/empty timestamps, invalid formats, timezone handling

**Validation Criteria:**
- ✅ Timeline timestamps change when display mode is toggled
- ✅ Timeline and log stream timestamps use identical formatting
- ✅ User preferences persist across page reloads
- ✅ No performance degradation in timeline rendering
- ✅ All existing functionality remains intact

## Implementation Details

### Backend Implementation

**RegistryTimelineRenderer.php Changes:**
```php
// BEFORE: Server-side formatting
$timestamp = $this->formatTimestamp($component['ts'] ?? time());

// AFTER: Raw timestamp with client-side formatting class
$timestamp = $component['ts'] ?? time();
$html .= '<span class="odcm-component__ts js-format-timestamp" data-raw-timestamp="' . esc_attr($timestamp) . '">' . esc_html($timestamp) . '</span>';
```

**TimelineEvent.php Changes:**
```php
// Ensure timestamp property contains raw data
public string $timestamp; // Raw timestamp data

// Update toArray() method
public function toArray(): array
{
    return [
        // ... other properties
        'timestamp' => $this->timestamp, // Raw timestamp
        // ... other properties
    ];
}
```

### Frontend Implementation

**JavaScript Changes:**
```javascript
// Enhanced formatTimestamp function
formatTimestamp(ts, element = null) {
    // Handle timeline timestamps with data attributes
    if (element && element.hasAttribute('data-raw-timestamp')) {
        ts = element.getAttribute('data-raw-timestamp');
    }

    // Existing formatting logic
    // ... (rest of existing function)
}

// Timeline rendering update
function renderTimelineEvent(event) {
    const timestampElement = document.createElement('span');
    timestampElement.className = 'odcm-component__ts js-format-timestamp';
    timestampElement.setAttribute('data-raw-timestamp', event.timestamp);
    timestampElement.textContent = formatTimestamp(event.timestamp, timestampElement);
    // ... rest of rendering
}
```

## Migration Strategy

### Backward Compatibility

1. **Graceful Degradation**: Ensure the system works even if JavaScript is disabled
2. **Fallback Formatting**: Provide server-side formatting as fallback
3. **Feature Detection**: Check for JavaScript timestamp formatting capability

### Rollout Plan

1. **Phase 1**: Implement backend changes (raw timestamp output)
2. **Phase 2**: Implement frontend changes (unified formatting)
3. **Phase 3**: Testing and bug fixing
4. **Phase 4**: Gradual rollout with feature flags if needed

## Success Metrics

1. **Consistency**: 100% of timeline timestamps match log stream formatting
2. **User Satisfaction**: No support tickets related to timestamp display issues
3. **Performance**: No measurable performance degradation
4. **Code Quality**: Reduced code duplication and improved maintainability

## Risk Assessment

**Potential Risks:**
1. **Performance Impact**: Client-side formatting could impact rendering performance
2. **Compatibility Issues**: Different timestamp formats between systems
3. **User Confusion**: Changes in timestamp display behavior

**Mitigation Strategies:**
1. **Performance Testing**: Benchmark before and after changes
2. **Format Normalization**: Ensure all timestamps are converted to consistent format
3. **User Communication**: Document changes in release notes

## Conclusion

This plan provides a comprehensive approach to unifying timestamp handling across the Order Daemon system. By centralizing timestamp formatting logic and ensuring consistent data flow, we will achieve better maintainability, improved user experience, and reduced code complexity.
