# Order Daemon View Mode Solution - Complete Implementation Guide

## Problem Context

The Order Daemon plugin has two critical issues with view mode functionality:

### **Issue 1: Incorrect Event Grouping**
The checkout completed and payment events are still being grouped together in the payment received log stream item, even when flat view mode is selected. This occurs because there are **multiple layers of grouping logic** that are not respecting the view mode parameter.

### **Issue 2: Missing Events in Individual View**
When placing a new order, only 1 event appears instead of the expected 5 events. Events appear gradually on auto-refresh, and sometimes only appear after manual refresh. This indicates **caching and timing issues** that prevent proper real-time event display.

## Root Cause Analysis

After thorough investigation, we found **3 separate layers where grouping/consolidation is happening**:

### **Layer 1: API Level Grouping (AuditLogEndpoint.php)**
The `get_logs()` method in `AuditLogEndpoint.php` applies process ID consolidation BEFORE checking the view mode:

```php
// Current problematic code in get_logs():
$all_logs = $this->get_all_filtered_logs($request);

// Apply UI-only consolidation by process_id for lifecycle events
try {
    $include_debug = (bool) $request->get_param('include_debug');
    $all_logs = $this->apply_process_id_consolidation($all_logs, $include_debug);
} catch (\Throwable $e) {
    // Fail-safe: keep original logs ungrouped
    $this->logDebugMessage('ODCM: Process ID consolidation failed: ' . $e->getMessage(), 'error');
}

// Only AFTER consolidation, it checks view mode
if ($view === 'flat') {
    // Flat view: paginate raw events directly, no consolidation
    // BUT IT'S TOO LATE - CONSOLIDATION ALREADY HAPPENED!
}
```

### **Layer 2: DatabaseTimelineBuilder.php**
The `DatabaseTimelineBuilder` class has its own consolidation logic that groups events by process_id, regardless of view mode.

### **Layer 3: Frontend JavaScript Logic**
The frontend JavaScript may be making independent grouping decisions that override the backend view mode.

## Complete Solution Architecture

### **Phase 1: Fix API Level Grouping Logic**

#### **File: `src/API/AuditLogEndpoint.php`**

**Problem**: The `get_logs()` method applies consolidation before checking view mode.

**Solution**: Restructure the method to check view mode FIRST, then apply appropriate logic for each mode.

**Key Changes**:

1. **Move View Mode Check to Beginning** (Lines ~250-350)
   - Check `$view` parameter immediately after getting it from the request
   - Branch logic based on view mode BEFORE any data processing

2. **Fix `apply_process_id_consolidation()` Method** (Lines ~759-843)
   - Add `$view_mode` parameter to the method signature
   - Return early without consolidation when `$view_mode === 'flat'`
   - Only apply consolidation when `$view_mode === 'consolidated'`

3. **Update `get_logs()` Method Structure**:
   ```php
   // NEW STRUCTURE:
   $view = $request->get_param('view') ?: 'consolidated';
   
   if ($view === 'flat') {
       // Flat view: NO consolidation, direct pagination
       $page_logs = $this->get_filtered_logs($request, $per_page, $page);
       $total = $this->get_filtered_log_count($request);
       // Format for flat view - no process groups
       $formatted_logs = $this->format_logs_for_flat_view($page_logs);
   } else {
       // Consolidated view: apply process grouping
       $all_logs = $this->get_all_filtered_logs($request);
       $all_logs = $this->apply_process_id_consolidation($all_logs, $include_debug, 'consolidated');
       // Format for consolidated view - with process groups
       $formatted_logs = $this->format_logs_for_api($all_logs);
   }
   ```

### **Phase 2: Fix Component Rendering Issues**

#### **File: `src/API/AuditLogEndpoint.php`**

**Problem**: The `render_components()` method doesn't properly handle view mode for individual log rendering.

**Solution**: Update the method to pass view mode to the TimelineRequest and ensure individual events are not grouped.

**Key Changes**:

1. **Update `render_components()` Method** (Lines ~350-500)
   - Extract view mode from request parameters
   - Pass view mode to TimelineRequest constructor
   - Add debugging to verify view mode is being respected

2. **Fix `format_logs_for_flat_view()` Method** (Lines ~948-975)
   - Ensure it explicitly sets `is_process_group = false` for all entries
   - Add validation to prevent any consolidation flags
   - Remove any process-related metadata that might confuse the frontend

### **Phase 3: Fix Caching and Timing Issues**

#### **File: `src/API/AuditLogEndpoint.php`**

**Problem**: Aggressive caching and race conditions prevent immediate event display.

**Solution**: Implement proper cache invalidation and timing controls.

**Key Changes**:

1. **Add Cache Busting for View Mode Changes**
   - Include view mode in cache keys
   - Invalidate cache when view mode changes
   - Add cache headers to prevent browser caching of different view modes

2. **Fix Real-time Event Handling**
   - Add timing controls for event rendering
   - Implement proper event ordering
   - Prevent race conditions in process lifecycle events

3. **Add Performance Monitoring**
   - Log timing metrics for each operation
   - Track cache hit/miss rates
   - Monitor event delivery times

### **Phase 4: End-to-End View Mode Implementation**

#### **Files: Multiple**

**Problem**: Inconsistent view mode handling across different layers.

**Solution**: Implement consistent view mode propagation through all layers.

**Key Changes**:

1. **Update TimelineRequest Class**
   - Add view mode property
   - Pass view mode through the entire timeline building process
   - Ensure all timeline builders respect the view mode

2. **Update DatabaseTimelineBuilder Class**
   - Add view mode parameter to buildTimeline() method
   - Skip process grouping when view mode is 'flat'
   - Only apply grouping when view mode is 'consolidated'

3. **Update Frontend JavaScript**
   - Ensure view mode is properly sent in API requests
   - Remove any client-side grouping logic
   - Add error handling for view mode mismatches

## Detailed Implementation Steps

### **Step 1: Modify AuditLogEndpoint.php - get_logs() Method**

**Current problematic code** (Lines ~288-349):
```php
// Apply UI-only consolidation by process_id for lifecycle events
try {
    $include_debug = (bool) $request->get_param('include_debug');
    $all_logs = $this->apply_process_id_consolidation($all_logs, $include_debug);
} catch (\Throwable $e) {
    // Fail-safe: keep original logs ungrouped
    $this->logDebugMessage('ODCM: Process ID consolidation failed: ' . $e->getMessage(), 'error');
}

// Determine view mode: consolidated (default) or flat (raw chronological)
$view = $request->get_param('view') ?: 'consolidated';
if ($view === 'flat') {
    // Flat view: paginate raw events directly, no consolidation
    $page_logs = $this->get_filtered_logs($request, $per_page, $page);
    $total = $this->get_filtered_log_count($request);
    // ...
}
```

**Fixed code**:
```php
// Determine view mode: consolidated (default) or flat (raw chronological)
$view = $request->get_param('view') ?: 'consolidated';

if ($view === 'flat') {
    // Flat view: NO consolidation, direct pagination of individual events
    $page_logs = $this->get_filtered_logs($request, $per_page, $page);
    $total = $this->get_filtered_log_count($request);
    
    // Calculate pagination
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
    $start_item = $total > 0 ? ($offset + 1) : 0;
    $end_item = $total > 0 ? min($offset + $per_page, $total) : 0;

    // Format for flat view - NO process groups
    $formatted_logs = $this->format_logs_for_flat_view($page_logs);
    
    $response_data = [
        'logs' => $formatted_logs,
        'pagination' => [
            'total' => $total,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $per_page,
            'start_item' => $start_item,
            'end_item' => $end_item,
            'has_previous' => $page > 1,
            'has_next' => $page < $total_pages,
        ],
        'filters' => $this->get_applied_filters($request),
        'meta' => [
            'execution_time' => $execution_time,
            'timestamp' => current_time('mysql'),
            'consolidated_pagination' => false,
            'pagination_basis' => 'raw',
            'view_mode' => 'flat',
        ],
    ];
} else {
    // Consolidated view: apply process grouping
    $all_logs = $this->get_all_filtered_logs($request);
    
    if (is_wp_error($all_logs)) {
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage('ODCM: DEBUG - Converting WP_Error to empty array', 'debug');
        }
        $all_logs = [];
    }

    // Apply UI-only consolidation by process_id for lifecycle events
    try {
        $include_debug = (bool) $request->get_param('include_debug');
        $all_logs = $this->apply_process_id_consolidation($all_logs, $include_debug, 'consolidated');
    } catch (\Throwable $e) {
        // Fail-safe: keep original logs ungrouped
        $this->logDebugMessage('ODCM: Process ID consolidation failed: ' . $e->getMessage(), 'error');
    }

    // Calculate pagination for consolidated view
    $total = is_array($all_logs) ? count($all_logs) : 0;
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
    $page_logs = $total > 0 ? array_slice($all_logs, $offset, $per_page) : [];
    $start_item = $total > 0 ? ($offset + 1) : 0;
    $end_item = $total > 0 ? min($offset + $per_page, $total) : 0;

    // Format for consolidated view - WITH process groups
    $formatted_logs = $this->format_logs_for_api($page_logs);
    
    $response_data = [
        'logs' => $formatted_logs,
        'pagination' => [
            'total' => $total,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $per_page,
            'start_item' => $start_item,
            'end_item' => $end_item,
            'has_previous' => $page > 1,
            'has_next' => $page < $total_pages,
        ],
        'filters' => $this->get_applied_filters($request),
        'meta' => [
            'execution_time' => $execution_time,
            'timestamp' => current_time('mysql'),
            'consolidated_pagination' => true,
            'pagination_basis' => 'consolidated',
            'view_mode' => 'consolidated',
        ],
    ];
}
```

### **Step 2: Modify apply_process_id_consolidation() Method**

**Current method signature** (Line ~759):
```php
private function apply_process_id_consolidation(array $logs, bool $include_debug): array
```

**Updated method signature**:
```php
private function apply_process_id_consolidation(array $logs, bool $include_debug, string $view_mode = 'consolidated'): array
```

**Updated method logic**:
```php
private function apply_process_id_consolidation(array $logs, bool $include_debug, string $view_mode = 'consolidated'): array
{
    // If flat view mode, return logs unchanged (no consolidation)
    if ($view_mode === 'flat') {
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage('ODCM Consolidation: SKIPPED - flat view mode requested', 'debug');
        }
        return $logs;
    }

    if (empty($logs)) {
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage('ODCM Consolidation: Empty input logs array', 'warning');
        }
        return [];
    }

    // Rest of the consolidation logic remains the same...
    // (existing code for process grouping)
}
```

### **Step 3: Update format_logs_for_flat_view() Method**

**Current issues**: Method doesn't explicitly prevent process group flags.

**Updated method**:
```php
private function format_logs_for_flat_view(array $logs): array
{
    $formatted_logs = [];

    foreach ($logs as $log) {
        // For flat view, ensure we have a valid log_id
        $log_id = $log['log_id'] ?? 0;

        // Create simple individual log format - NO process group handling
        $formatted_log = [
            'id' => (int) $log_id,
            'timestamp' => $log['timestamp'],
            'status' => $log['status'],
            'summary' => $log['summary'],
            'event_type' => $log['event_type'],
        ];

        // Add order_id if present
        if (!empty($log['order_id'])) {
            $formatted_log['order_id'] = (int) $log['order_id'];
        }

        // Add payload_id if available (for rendering components)
        if (!empty($log['payload_id'])) {
            $formatted_log['payload_id'] = (int) $log['payload_id'];
        }

        // CRITICAL: In flat view, we explicitly set is_process_group to false
        // to ensure frontend doesn't try to consolidate
        $formatted_log['is_process_group'] = false;
        
        // Also explicitly remove any process-related metadata that might exist
        unset($formatted_log['process_id'], $formatted_log['process_count']);
        unset($formatted_log['_is_process_group'], $formatted_log['_process_count']);
        unset($formatted_log['_process_logs']);

        $formatted_logs[] = $formatted_log;
    }

    return $formatted_logs;
}
```

### **Step 4: Update render_components() Method**

**Add view mode handling**:
```php
public function render_components(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    try {
        // Get view mode from request parameters
        $view_mode = $request->get_param('view_mode') ?? 'consolidated';
        $log_id = $request->get_param('log_id');
        $include_debug = (bool) $request->get_param('include_debug');

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM: render_components called for log_id: " . $log_id . ", view_mode: " . $view_mode, 'debug');
        }

        // Ensure services are initialized
        if (!$this->timelineBuilder instanceof TimelineBuilderInterface) {
            try {
                $this->timelineBuilder = new DatabaseTimelineBuilder(new ProcessLoggerComponentExtractor());
            } catch (\Throwable $e) {
                throw $e;
            }
        }
        
        if (!$this->timelineRenderer instanceof TimelineRendererInterface) {
            try {
                $this->timelineRenderer = new RegistryTimelineRenderer();
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        // Start performance monitoring
        $start_time = microtime(true);

        // Create immutable request object with view mode
        try {
            $timelineRequest = TimelineRequest::fromRestRequest($request);
            // IMPORTANT: Set view mode on the request object
            if (method_exists($timelineRequest, 'setViewMode')) {
                $timelineRequest->setViewMode($view_mode);
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM: TimelineRequest created: log_id=" . $timelineRequest->logId . ", include_debug=" . ($timelineRequest->includeDebug ? 'true' : 'false') . ", view_mode=" . $view_mode, 'debug');
        }

        // Build timeline data using injected services
        try {
            $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: TimelineData created with " . $timelineData->getComponentCount() . " components", 'debug');
                $this->logDebugMessage("ODCM: TimelineData type: " . ($timelineData->isProcessGroup() ? 'process_group' : 'individual'), 'debug');
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        // Rest of the method remains the same...
    }
}
```

### **Step 5: Update TimelineRequest Class**

**Add view mode support** to the TimelineRequest class:

```php
class TimelineRequest
{
    public int $logId;
    public bool $includeDebug;
    public string $viewMode = 'consolidated'; // Add this property
    
    public function __construct(int $logId, bool $includeDebug = false, string $viewMode = 'consolidated')
    {
        $this->logId = $logId;
        $this->includeDebug = $includeDebug;
        $this->viewMode = $viewMode;
    }
    
    public function setViewMode(string $viewMode): void
    {
        $this->viewMode = in_array($viewMode, ['consolidated', 'flat']) ? $viewMode : 'consolidated';
    }
    
    public function getViewMode(): string
    {
        return $this->viewMode;
    }
    
    public static function fromRestRequest(WP_REST_Request $request): self
    {
        $logId = (int) $request->get_param('log_id');
        $includeDebug = (bool) $request->get_param('include_debug');
        $viewMode = $request->get_param('view_mode') ?? 'consolidated';
        
        return new self($logId, $includeDebug, $viewMode);
    }
}
```

### **Step 6: Update DatabaseTimelineBuilder Class**

**Modify the buildTimeline() method** to respect view mode:

```php
public function buildTimeline(TimelineRequest $request): TimelineData
{
    $logId = $request->logId;
    $includeDebug = $request->includeDebug;
    $viewMode = $request->getViewMode();
    
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        $this->logDebugMessage("ODCM TimelineBuilder: Building timeline for log_id {$logId}, view_mode: {$viewMode}", 'debug');
    }
    
    // Get the audit log entry
    $logEntry = $this->getAuditLogEntry($logId);
    if (!$logEntry) {
        throw new \Exception("Audit log entry not found: {$logId}");
    }
    
    // If flat view mode, create individual timeline without process grouping
    if ($viewMode === 'flat') {
        return $this->buildIndividualTimeline($logEntry, $includeDebug);
    }
    
    // Otherwise, proceed with process grouping logic
    return $this->buildProcessTimeline($logEntry, $includeDebug);
}

private function buildIndividualTimeline(array $logEntry, bool $includeDebug): TimelineData
{
    // Extract components without any process grouping
    $components = $this->componentExtractor->extractComponents($logEntry, $includeDebug);
    
    // Create individual timeline data
    return TimelineData::individual(
        $logEntry['log_id'],
        $components,
        [
            'view_mode' => 'flat',
            'is_individual' => true,
            'log_entry' => $logEntry,
        ]
    );
}

private function buildProcessTimeline(array $logEntry, bool $includeDebug): TimelineData
{
    // Existing process grouping logic
    // ... (keep the existing buildTimeline logic here)
    
    return TimelineData::processGroup(
        $logEntry['log_id'],
        $components,
        [
            'view_mode' => 'consolidated',
            'is_process_group' => true,
            'process_id' => $processId,
            'process_logs' => $processLogs,
        ]
    );
}
```

## Testing Strategy

### **Test Case 1: Flat View Mode**
1. **Set view mode to 'flat'**
2. **Create a new order**
3. **Verify all 5 events appear individually in the log stream**
4. **Verify no events are grouped together**
5. **Verify events appear immediately without manual refresh**

### **Test Case 2: Consolidated View Mode**
1. **Set view mode to 'consolidated'**
2. **Create a new order**
3. **Verify process groups are created correctly**
4. **Verify checkout/payment events are grouped when they should be**
5. **Verify process count is accurate**

### **Test Case 3: View Mode Switching**
1. **Start in consolidated view**
2. **Switch to flat view**
3. **Verify display updates immediately**
4. **Switch back to consolidated view**
5. **Verify process groups reappear correctly**

### **Test Case 4: Real-time Updates**
1. **Open log stream in flat view**
2. **Create a new order**
3. **Verify all events appear immediately**
4. **No manual refresh required**
5. **No gradual appearance of events**

## Expected Results

After implementing this solution:

### **✅ Issue 1 Resolved: No More Incorrect Grouping**
- Flat view mode will show each event individually
- Consolidated view mode will group related events properly
- Checkout completed and payment events will no longer be incorrectly grouped in flat view

### **✅ Issue 2 Resolved: All Events Appear Immediately**
- All 5 events will appear immediately when a new order is created
- No waiting for auto-refresh or manual refresh
- Real-time event delivery works correctly
- Race conditions and timing issues are resolved

### **✅ Additional Benefits**
- Consistent view mode behavior across all components
- Better performance with proper caching strategies
- Improved debugging and monitoring capabilities
- Clear separation between individual and consolidated view logic

## Implementation Checklist

- [ ] Modify `get_logs()` method in `AuditLogEndpoint.php` to check view mode first
- [ ] Update `apply_process_id_consolidation()` method to respect view mode
- [ ] Fix `format_logs_for_flat_view()` method to prevent process group flags
- [ ] Update `render_components()` method to pass view mode
- [ ] Add view mode support to `TimelineRequest` class
- [ ] Modify `DatabaseTimelineBuilder` to respect view mode
- [ ] Test flat view mode with new orders
- [ ] Test consolidated view mode with process groups
- [ ] Test real-time event delivery
- [ ] Verify no more gradual event appearance
- [ ] Test view mode switching
- [ ] Performance testing and optimization

## Risk Mitigation

### **Backward Compatibility**
- All existing consolidated view functionality remains unchanged
- Default view mode is still 'consolidated'
- No breaking changes to API structure

### **Performance Impact**
- Flat view mode should be more efficient (no consolidation overhead)
- Consolidated view mode performance remains the same
- Proper caching prevents database overload

### **Error Handling**
- Comprehensive debugging logs for troubleshooting
- Graceful fallback if view mode is not specified
- Clear error messages for edge cases

This solution provides a complete, end-to-end fix for the view mode issues in the Order Daemon plugin, ensuring both individual and consolidated views work correctly and consistently.
