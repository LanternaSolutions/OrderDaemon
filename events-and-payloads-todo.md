# **Final Implementation Plan: Audit Log System Cleanup**

## **PHASE 1: Remove Data-Layer Consolidation**
**Goal**: Eliminate LogConsolidationService and restore "one event per DB row" architecture

### **Step 1.1: Remove LogConsolidationService**
- **File**: `src/Core/LogConsolidationService.php` → DELETE
- **Remove calls**: All `LogConsolidationService::consolidate_logs_for_display()` calls
- **Database**: Remove `consolidation_data` column if it exists

### **Step 1.2: Update AuditLogEndpoint Frontend Method**
- **File**: `src/API/AuditLogEndpoint.php`
- **Method**: `render_frontend_consolidated_entry()` (lines ~3610-3945)
- **Change from**:
  ```php
  $order_entries = $wpdb->get_results(...); // Re-fetch
  $consolidated_entries = $consolidator->consolidate_logs_for_display($order_entries); // Re-consolidate
  ```
- **Change to**:
  ```php
  // Query all events with same process_id
  $process_events = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$wpdb->prefix}odcm_audit_log WHERE process_id = %s ORDER BY timestamp ASC",
      $process_id
  ));
  ```

### **Step 1.3: Update UI Consolidation Logic**
- **File**: `src/API/AuditLogEndpoint.php`
- **Method**: Main logs fetching method
- **Change**: Group by `process_id` in SQL query instead of using stored consolidation data
- **Display**: Show consolidated entries based on shared `process_id`

---

## **PHASE 2: Implement Proper Component Extraction**
**Goal**: Use PayloadComponentRegistry + specific renderers for timeline display

### **Step 2.1: Create Component Extraction Method**
- **File**: `src/API/AuditLogEndpoint.php`
- **New method**: `extract_components_from_process_events($process_events)`
- **Logic**:
  ```php
  foreach ($process_events as $event) {
      $payload_data = // get from payloads table
      $components = json_decode($payload_data['payload_components'], true);
      foreach ($components as $component) {
          // Use PayloadComponentRegistry to get renderer
          // Use specific renderer (WooCommerceRenderer, StripeEventRenderer, etc.)
      }
  }
  ```

### **Step 2.2: Update Timeline Rendering**
- **Use**: Existing `PayloadComponentUIToolkit` for HTML generation
- **Use**: Existing 19 specialized renderers from `src/View/PayloadRenderer/`
- **Remove**: Complex fallback chains and extraction logic (lines 3610-3945)
- **Replace with**: Direct component-to-renderer mapping

---

## **PHASE 3: Standardize All Logging** 
**Goal**: Ensure all events use `payload_components` format via unified logging system

### **✅ Step 3.1: COMPLETED - Fix Infinite Loop Bug & Simplify Logging**
- **✅ COMPLETED**: Fixed infinite loop in `odcm_handle_log_processing()` 
- **✅ COMPLETED**: Implemented single unified `odcm_log_event()` function in `functions.php`
- **✅ COMPLETED**: Removed old functions (`odcm_log_custom_event`, `odcm_log_registered_event`)
- **✅ COMPLETED**: Updated all references in `RefundDeletionDiagnostics.php`
- **✅ COMPLETED**: Clean async flow: `odcm_log_event() → Action Scheduler → Database`

### **✅ Step 3.2: COMPLETED - Find Legacy AuditTrailLogger Calls**
- **✅ COMPLETED**: `AuditTrailLogger::record()` calls - **0 results found**
- **✅ COMPLETED**: Direct database insertions to audit log table - **1 correct instance found** (worker process)
- **✅ COMPLETED**: All non-Universal Event logging paths identified:
  - **GuardChecker.php**: 2 instances of `$this->logger->log()` 
  - **UniversalEventProcessor.php**: 1 instance of `new AuditTrailLogger()`
  - **Plugin.php**: 1 instance using AuditTrailLogger in constructor

### **Step 3.3: Replace with Unified odcm_log_event()**
- **Replace**: `AuditTrailLogger::record()` → `odcm_log_event()`
- **Replace**: Any remaining old logging calls → `odcm_log_event()`
- **Ensure**: All events get proper `payload_components` structure
- **Ensure**: All events get `process_id` via `odcm_maybe_add_process_id()`

---

## **PHASE 4: Verification & Testing**
**Goal**: Ensure the system works correctly end-to-end

### **Step 4.1: Data Flow Verification**
1. **Event Creation**: Universal Event → `odcm_log_event()` → database
2. **UI Listing**: Query events grouped by `process_id` for consolidated view
3. **Detail View**: Query events by `process_id` → extract `payload_components` → render via registry

### **Step 4.2: Test Scenarios**
- **Order processing**: Create order → rule evaluation → completion
- **PayPal webhook**: Webhook received → event processed → action taken
- **Detail timeline**: Click consolidated entry → verify proper component rendering

---

## **KEY ARCHITECTURAL OUTCOMES**

✅ **One event per DB row** (no data-layer consolidation)
✅ **UI-only consolidation** (group by `process_id` in queries)
✅ **Component-based rendering** (PayloadComponentRegistry + specific renderers)
✅ **Unified logging system** (all events via single `odcm_log_event()` function)
✅ **No infinite loop bugs** (direct database writes in worker processes)
✅ **No synthetic components** (all events have proper `payload_components`)
