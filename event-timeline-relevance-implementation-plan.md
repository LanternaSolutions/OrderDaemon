# Event Timeline Relevance & Debug Classification Implementation Plan

**Project**: Order Daemon Core Plugin  
**Objective**: Refine event logging and timeline rendering to show only business-relevant events by default, with proper debug classification  
**Date**: October 24, 2025  
**Status**: Ready for Implementation  

## Problem Statement

The Order Daemon plugin's Insight Dashboard and Details Pane Timeline currently displays excessive technical events that provide no business value to store owners. The timeline is cluttered with system implementation details, poorly labeled events, and missing debug classifications.

### Current Issues Identified

1. **Excessive Rule Execution Events**: Multiple technical "Rule Execution" entries with internal correlation IDs, component counts, and performance metrics
2. **Missing Debug Classification**: Technical events lack proper DEBUG pills despite containing implementation details
3. **Poor User-Friendly Language**: Events use developer terminology instead of business language
4. **Event Noise**: System-level details exposed to business users who don't need them

### Example Timeline Problems

```
❌ Rule Execution
   Status: success
   Source: universal_event_processor  
   Component Count: 4
   Correlation ID: odcm:lifecycle:41:1761231642:68fa431a5a73f5.26641338
   Performance Metrics: Attribution Capture Ms: 0.0029 ms

❌ Block Checkout Processed
   (Technical implementation detail meaningless to store owners)
```

## Business vs Debug Event Definition

### Business Events
Events that directly impact or inform store operations and business outcomes:
- ✅ Order status changes (pending → processing → completed)
- ✅ Payment confirmations/failures  
- ✅ Rule matches that trigger business actions ("Auto-Complete Virtual Products rule matched")
- ✅ Customer-facing automation results
- ✅ Revenue-impacting events (refunds, cancellations)

### Debug Events  
Technical implementation details useful for troubleshooting and development:
- 🔧 Rule execution mechanics (component counts, correlation IDs)
- 🔧 Performance metrics (attribution capture times)
- 🔧 System process details (universal_event_processor status)
- 🔧 Internal state transitions
- 🔧 Detailed rule evaluation traces

## Architecture Overview

### Key Components Involved

1. **Event Logging Sources** (`src/Core/Events/UniversalEventProcessor.php`, `src/Core/Logging/ProcessLogger.php`)
2. **Timeline Rendering** (`src/View/PayloadRenderer/BaseRenderer.php`, `src/View/PayloadRenderer/RuleRenderer.php`, etc.)
3. **Event Classification** (`src/View/PayloadRenderer/BaseRenderer.php::isDebugEvent()`)
4. **UI Components** (`src/View/DashboardComponents/DetailPaneRenderer.php`)

### Current Event Flow
```
Event Source → ProcessLogger → odcm_log_event() → Database → Timeline Renderer → UI Display
```

## Implementation Plan

### Phase 1: Debug Event Classification at Source

**Objective**: Properly classify events as debug vs business at their logging source

#### Tasks:

1. **Audit All Event Logging Calls**
   - Search for all `odcm_log_event()` calls across codebase
   - Search for `ProcessLogger::add_component()` calls
   - Classify each call as business vs debug event

   **Files to Review**:
   ```
   src/Core/Events/UniversalEventProcessor.php
   src/Core/Logging/ProcessLogger.php
   src/Core/Events/EventRouter.php
   src/Core/ManualStatusTracker.php
   src/API/WebhookController.php
   ```

2. **Update ProcessLogger Debug Levels**
   - Mark technical components with `level => 'debug'`
   - Update rule execution logging to use debug level for technical details
   
   **Specific Changes in `ProcessLogger.php`**:
   ```php
   // Mark process_started as debug
   $this->components[] = [
       'level' => 'debug',  // ← Add this
       // ... existing fields
   ];
   ```

3. **Update UniversalEventProcessor Logging**
   - Mark rule execution internals as debug
   - Keep only business outcomes as info/success level
   
   **Changes in `UniversalEventProcessor.php`**:
   ```php
   // Mark technical rule processing as debug
   $rule_logger->add_component('rule_matched', 
       sprintf('Rule "%s" matched', $rule->post_title), 
       ['rule_id' => $rule->ID, 'rule_name' => $rule->post_title],
       'debug'  // ← Add debug level
   );
   ```

### Phase 2: Business Language Translation

**Objective**: Replace technical terms with business-friendly language for non-debug events

#### Tasks:

1. **Update Event Labels**
   - "Block Checkout Processed" → "Checkout Completed"
   - "universal_event_processor" → contextual business terms
   - Remove technical correlation IDs from business events

2. **Improve Contextual Summaries**
   - Replace generic "Rule Execution" with specific "Rule matched: Auto-Complete Virtual Products"
   - Add meaningful business context to event summaries

   **Example Changes**:
   ```php
   // Before
   $summary = "Rule Execution";
   
   // After  
   $summary = sprintf("%s: %s", $rule_event_name, $rule_name); //renderers frequently handle multiple event_types with different user-friendly names (not sure the syntax of this example is correct, though)
   ```

3. **Update BaseRenderer Labels**
   - Modify `getLabel()` methods in renderer classes
   - Ensure business events have clear, non-technical labels

   **Files to Modify**:
   ```
   src/View/PayloadRenderer/RuleRenderer.php
   src/View/PayloadRenderer/SystemRenderer.php
   src/View/PayloadRenderer/OrderRenderer.php
   ```

### Phase 3: Unified Debug Section Implementation

**Objective**: Consolidate all debug-like data within business events into a single expandable "Debug" section

#### Background
Even business-relevant events (like "Rule Matched") often contain debug-like data mixed with business data:
- Business data: Rule name, action taken, outcome
- Debug-like data: Correlation IDs, component counts, performance metrics, technical details

Instead of hiding entire events, we need granular control over what data is displayed prominently vs. hidden in a collapsible debug section.

#### Tasks:

1. **Update BaseRenderer Debug Section**
   - Rename `renderMetrics()` method to `renderDebugSection()`
   - Change section title from "Performance Metrics" to "Debug"
   - Accept mixed debug data types (metrics, correlation IDs, technical details)

   **Changes in `BaseRenderer.php`**:
   ```php
   // Before
   protected function renderMetrics(array $metrics, PayloadComponentUIToolkit $toolkit, string $title = 'Performance Metrics'): string
   
   // After
   protected function renderDebugSection(array $debug_data, PayloadComponentUIToolkit $toolkit, string $title = 'Debug'): string
   {
       // Accept mixed debug data: metrics, correlation IDs, technical details, etc.
       $formatted_data = [];
       foreach ($debug_data as $key => $value) {
           $formattedKey = ucwords(str_replace('_', ' ', $key));
           // Format millisecond values, etc.
           if (is_float($value) && strpos($key, '_ms') !== false) {
               $value = number_format($value, 4) . ' ms';
           }
           $formatted_data[$formattedKey] = (string)$value;
       }
       return $toolkit->render_key_value_list($formatted_data, $title);
   }
   ```

2. **Update Renderer Implementation Pattern**
   - Each renderer organizes data into business-relevant vs debug sections
   - Business data stays in main body, debug data moves to expandable section
   - Renderers decide which fields go where based on business value

   **Example Pattern**:
   ```php
   protected function renderSpecificContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string
   {
       // 1. Render main business-relevant data
       $business_data = [
           'Rule Name' => $data['rule_name'] ?? '',
           'Status' => $data['status'] ?? '',
           // ... other business fields
       ];
       $content = $toolkit->render_key_value_list($business_data, 'Rule Details');
       
       // 2. Collect debug-like data for unified section
       $debug_data = [];
       if (isset($data['correlation_id'])) $debug_data['correlation_id'] = $data['correlation_id'];
       if (isset($data['component_count'])) $debug_data['component_count'] = $data['component_count'];
       if (isset($data['metrics'])) $debug_data = array_merge($debug_data, $data['metrics']);
       
       // 3. Add debug section if we have debug data
       if (!empty($debug_data)) {
           $content .= $this->renderDebugSection($debug_data, $toolkit);
       }
       
       return $content;
   }
   ```

3. **Update All Renderer Classes**
   - Migrate from `renderMetrics()` calls to `renderDebugSection()`
   - Reorganize data presentation: business data prominent, technical data in debug section
   - Maintain existing business logic while improving data organization

   **Files to Update**:
   ```
   src/View/PayloadRenderer/RuleRenderer.php
   src/View/PayloadRenderer/SystemRenderer.php
   src/View/PayloadRenderer/OrderRenderer.php
   src/View/PayloadRenderer/PaymentRenderer.php
   ```

### Phase 4: Debug Toggle Implementation

**Objective**: Add UI toggle to show/hide debug events in timeline

#### Tasks:

1. **Review Timeline Query**
   - Ensure database queries to filter debug events when toggle is on/off
   - Ensure debug events are hidden by default

2. **Frontend Integration**
   - Ensure JavaScript handles the debug toggle correctly
   - Refresh log stream and timeline when debug setting changes

   **Files to Modify**:
   ```
   src/Admin/InsightDashboard.php
   src/View/DashboardComponents/FilterPaneRenderer.php
   assets/js/insight-dashboard.js
   ```

## Technical Implementation Details

### File Modifications Required

#### 1. `src/Core/Events/UniversalEventProcessor.php`
- Update logging calls to include debug level classification
- Simplify business event summaries
- Mark performance metrics and correlation IDs as debug

#### 2. `src/Core/Logging/ProcessLogger.php`
- Add debug level to technical components
- Ensure process_started events are marked as debug

#### 3. `src/View/PayloadRenderer/BaseRenderer.php`
- Rename `renderMetrics()` to `renderDebugSection()` with unified debug data handling
- Enhance `isDebugEvent()` method if needed (minimal changes expected)
- Update `getLabel()` for business-friendly terms
- Modify empty component handling

#### 4. `src/View/PayloadRenderer/RuleRenderer.php`
- Update rule-specific labels and summaries
- Reorganize data: business fields in main body, technical fields in debug section
- Migrate to new `renderDebugSection()` method

#### 5. `src/View/PayloadRenderer/SystemRenderer.php`
- Update system event labels and organization
- Migrate to unified debug section approach

#### 6. `src/View/PayloadRenderer/OrderRenderer.php` & `PaymentRenderer.php`
- Update event-specific data organization
- Migrate to unified debug section approach

#### 7. `src/Admin/InsightDashboard.php`
- Ensure it handles debug filter state

### Database Considerations

No schema changes required. The existing `level` field in component data can be used to store debug classification.

### JavaScript Updates

#### `assets/js/insight-dashboard.js`
- Confirm debug toggle event handler
- Ensure log stream and timeline refresh on debug setting change
- Ensure persist debug preference in localStorage or user meta

## Testing Strategy

### 1. Manual Testing Scenarios
- Create test order and trigger various events
- Verify debug events are properly classified
- Test debug toggle functionality
- Validate business-friendly language

### 2. Timeline Validation
- **Default View**: Should show only business-relevant events
- **Debug Mode**: Should show all events including technical details
- **Event Labels**: Should use business language for non-debug events

### 3. Regression Testing
- Ensure existing functionality remains intact
- Verify event data integrity
- Test timeline rendering performance

## Expected Outcomes

### Before Implementation
```
Timeline Events (16 items):
❌ Rule Execution (technical details)
❌ Rule Execution (technical details) 
❌ Block Checkout Processed
❌ Payment Completed (duplicate)
❌ Status Change Processing (technical)
❌ Manual Status Change (technical)
❌ No Rules Matched (debug info)
❌ Rule Execution (performance metrics)
... (8 more technical events)
```

### After Implementation  
```
Timeline Events (4 items, with debug toggle available):
✅ Checkout Completed
✅ Payment Completed 
✅ Order Status Changed: Pending → Completed
✅ Auto-Complete Rule Applied

[🔧 Show Debug Events] ← Toggle for technical details
```

## Success Metrics

1. **Event Reduction**: Timeline shows 70% fewer events by default
2. **Business Clarity**: All visible events use business-friendly language
3. **Debug Access**: Technical users can still access debug information via toggle
4. **User Experience**: Store owners can understand their order processing at a glance

## Implementation Checklist

### Phase 1: Debug Event Classification
- [ ] Audit all event logging calls and classify as business vs debug
- [ ] Update ProcessLogger to mark technical components as debug level
- [ ] Update UniversalEventProcessor logging with proper debug classification

### Phase 2: Business Language Translation
- [ ] Replace technical terms with business language in non-debug events
- [ ] Improve contextual summaries for business events
- [ ] Update BaseRenderer labels for business-friendly terms

### Phase 3: Unified Debug Section
- [ ] Rename BaseRenderer `renderMetrics()` to `renderDebugSection()`
- [ ] Update BaseRenderer to handle mixed debug data types
- [ ] Migrate RuleRenderer to unified debug section approach
- [ ] Migrate SystemRenderer to unified debug section approach
- [ ] Migrate OrderRenderer and PaymentRenderer to unified debug section approach
- [ ] Test debug section displays technical data correctly

### Phase 4: Debug Toggle Implementation
- [ ] Confirm debug toggle UI in dashboard
- [ ] Ensure implementation of debug filtering in timeline queries
- [ ] Update JavaScript for debug toggle functionality if necessary

### Testing & Validation
- [ ] Test manual scenarios with debug toggle
- [ ] Verify timeline shows only relevant business events by default
- [ ] Validate debug mode shows technical details when enabled
- [ ] Verify unified debug sections work across all renderer types

**Next Steps**: Begin with Phase 1 (Debug Event Classification) by auditing existing event logging calls and updating them with proper debug level classification.
