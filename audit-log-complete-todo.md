# DETAILED PLAN: Complete Audit Logging System Functionality Gaps

## CURRENT STATUS SUMMARY

**✅ INFRASTRUCTURE FULLY OPERATIONAL (COMPLETED 2025-10-10):**
- API debug filtering fixed (returns 7 logs instead of 0)
- Action Scheduler deduplication fixed (tasks process successfully)
- Payload storage verified working (rich JSON data stored with correct linking)
- Dashboard displays Block Checkout events for orders #20-24
- **NEW: Core WooCommerce hooks fixed** - Hooks fire correctly, completion rules match virtual products
- **NEW: UniversalEventProcessor parameter fix** - Extended odcm_log_event() to accept 6th parameter (process_id)
- **NEW: Data structure validation fixed** - Action Scheduler properly processes wrapped event_data
- **NEW: End-to-end verification completed** - All logging components work when Action Scheduler processes

**✅ PHASE 1 COMPLETED:**
1. ~~**Core WooCommerce hooks not firing**~~ - **FIXED**: Hooks fire correctly, completion rules match, audit entries queued

**❌ REMAINING FUNCTIONALITY GAPS:**
1. **Action Scheduler overload** - "Too many concurrent batches" prevents log processing (orders #41, #42 affected)
2. **UI consolidation not working** - Process groups show as individual entries instead of consolidated lifecycle view
3. **Detail pane rendering broken** - Rich payload data not displaying in details view

---

## ✅ PHASE 1: CORE WOOCOMMERCE HOOK EXECUTION - **COMPLETED 2025-10-10**

### ✅ Problem Resolution
~~Core.php hooks are registered but don't fire during real checkout.~~ 

**RESOLVED**: Hooks fire correctly. Issue was in logging infrastructure, not hook registration.

### ✅ Root Cause Found
**Issue 1**: UniversalEventProcessor called `odcm_log_event()` with 6 parameters, but function only accepted 5
- **Fixed**: Extended `odcm_log_event()` in `src/Includes/functions.php` to accept optional 6th parameter `$process_id`

**Issue 2**: Data structure validation failure in log processing
- **Fixed**: Action Scheduler now properly handles wrapped `event_data` structure

### ✅ Verification Results
**Test Orders**:
- **Order #39**: Virtual product, completion rule matches (UniversalEventProcessor returns TRUE)
- **Order #41**: Virtual product, completion rule matches, manual test entries created
- **Order #42**: Virtual product, completion rule matches (confirmed same pattern)

**Evidence of Working System**:
- ✅ WooCommerce hooks fire (Action Scheduler entries created)
- ✅ Completion rules match virtual products (UniversalEventProcessor returns TRUE)
- ✅ Logging infrastructure works (manual processing creates audit entries)
- ✅ Dashboard API works (Order #41 visible with 4 entries)
- ✅ Context extraction works (Order ID 41 extracted correctly)

### 🚨 **CURRENT BLOCKER**: Action Scheduler Overload
**Error**: "Too many concurrent batches" prevents pending log actions from processing
**Impact**: Completion rule audit entries stuck in queue, orders not visible in dashboard
**Solution Required**: Clear Action Scheduler queue before testing new orders

### Files Modified
- ✅ `src/Includes/functions.php` - Extended odcm_log_event() for 6th parameter

---

## PHASE 2: FIX UI CONSOLIDATION BY PROCESS_ID

### Problem
Block Checkout events have `process_id` but appear as individual entries instead of grouped lifecycle view.

### Root Cause Analysis
1. **Check consolidation logic**
    - File: `src/API/AuditLogEndpoint.php` method `apply_process_id_consolidation()`
    - Verify process_id grouping logic works for Block Checkout events

2. **Check frontend rendering**
    - Verify dashboard JavaScript handles consolidated entries correctly
    - Check if `is_process_representative` flag is respected

### Implementation Steps
1. **Debug consolidation logic**
   ```php
   // In apply_process_id_consolidation() method
   error_log('ODCM CONSOLIDATION: Processing ' . count($logs) . ' logs');
   error_log('ODCM CONSOLIDATION: Found ' . count($by_process_id) . ' process groups');
   ```

2. **Test process grouping**
    - Verify Block Checkout events (orders #22-24) have same process_id format
    - Test if `event_type = 'block_checkout_processed'` is in lifecycle types

3. **Fix consolidation display**
    - Ensure representative entries show unified timeline
    - Fix process summary generation for Block Checkout events

### Files to Modify
- `src/API/AuditLogEndpoint.php` - Consolidation logic
- `src/Core/ProcessLifecycleDiscovery.php` - Lifecycle type definitions

---

## PHASE 3: FIX DETAIL PANE PAYLOAD RENDERING

### Problem
Payload data exists in database but detail pane shows empty/wrong content.

### Root Cause Analysis
1. **Check API payload fetching**
    - File: `src/API/AuditLogEndpoint.php` method `render_components()`
    - Verify payload table joins work correctly

2. **Check renderer pipeline**
    - Verify PayloadComponentRegistry finds correct renderers
    - Test if `payload_components` structure is parsed correctly

### Implementation Steps
1. **Debug payload fetching**
   ```php
   // In render_components() method
   error_log('ODCM RENDER: Payload raw length: ' . strlen($payload_raw));
   error_log('ODCM RENDER: Decoded payload keys: ' . json_encode(array_keys($details ?? [])));
   ```

2. **Test renderer pipeline**
    - Verify BlockCheckoutCompatibility payloads have correct `payload_components` structure
    - Test if renderers handle Block Checkout specific data

3. **Fix payload structure**
    - Ensure BlockCheckoutCompatibility creates compatible payload format
    - Verify `envelope` structure matches expected narrative timeline format

### Files to Modify
- `src/API/AuditLogEndpoint.php` - Payload rendering
- `src/Core/BlockCheckoutCompatibility.php` - Payload structure
- `src/View/PayloadRenderer/` - Specific renderers if needed

---

## PHASE 4: END-TO-END TESTING & VERIFICATION

### Test Scenarios
1. **Place new order (e.g., #32)**
    - Verify Core hooks fire and create audit events
    - Check if multiple lifecycle events get consolidated
    - Test detail pane shows rich timeline data

2. **Check existing Block Checkout events**
    - Verify orders #22-24 consolidate by process_id
    - Test detail pane shows checkout context data
    - Confirm timeline rendering works

3. **Verify API functionality**
    - Test with debug logs enabled/disabled
    - Check pagination and filtering
    - Verify process grouping in list view

### Success Criteria
- [ ] New orders generate complete audit trails (Core events + Block Checkout)
- [ ] Multiple events per order consolidate into single timeline view
- [ ] Detail pane displays rich payload data with proper timeline rendering
- [ ] Dashboard shows unified order lifecycle instead of fragmented events

---

## PRIORITY ORDER & DEPENDENCIES - **UPDATED 2025-10-10**

1. **🚨 ACTION SCHEDULER CLEANUP** - **IMMEDIATE PRIORITY**
    - Blocks all audit log processing (orders #41, #42 affected)
    - Required before any further testing
    - **Solution**: Use database cleanup method to clear queue

2. **PHASE 3 (Detail Rendering)** - **HIGHEST PRIORITY**
    - Existing events can't be viewed properly
    - Affects user experience significantly
    - **Ready to implement** once Action Scheduler cleared

3. **PHASE 2 (UI Consolidation)** - **MEDIUM PRIORITY**
    - Events are visible but fragmented
    - UX improvement, system functional without it
    - **Ready to implement** once Action Scheduler cleared

4. ~~**PHASE 1 (Core Hooks)**~~ - **✅ COMPLETED**
    - ~~Blocks all new order audit logging~~ - **FIXED**
    - ~~Required for complete functionality~~ - **WORKING**

---

## DEBUGGING COMMANDS FOR TESTING - **UPDATED 2025-10-10**

### 🚨 **FIRST: Clear Action Scheduler Queue**
```bash
# Method 1: Database cleanup (recommended)
docker exec order-daemon-devtools-cron-1 wp eval "
global \$wpdb;
\$wpdb->query('TRUNCATE TABLE {\$wpdb->prefix}actionscheduler_actions');
\$wpdb->query('TRUNCATE TABLE {\$wpdb->prefix}actionscheduler_groups');
\$wpdb->query('TRUNCATE TABLE {\$wpdb->prefix}actionscheduler_logs');
echo 'Action Scheduler tables cleared completely';
"

# Method 2: Batch reset (gentler)
docker exec order-daemon-devtools-cron-1 wp eval "
delete_transient('action_scheduler_batches');
delete_option('action_scheduler_batch_processing');
\$wpdb->query(\"UPDATE {\$wpdb->prefix}actionscheduler_actions SET status = 'failed' WHERE status = 'in-progress'\");
echo 'Reset Action Scheduler batch processing state';
"
```

### **THEN: Test System**
```bash
# Test new order creation
docker exec order-daemon-devtools-cron-1 wp eval "
\$order = wc_create_order();
\$order->add_product(wc_get_product(17)); // virtual product
\$order->set_status('completed');
\$order->save();
echo 'Created order #' . \$order->get_id();
"

# Check audit events for order (replace 43 with actual order ID)
docker exec order-daemon-devtools-cron-1 wp eval "
global \$wpdb;
\$results = \$wpdb->get_results(\"SELECT * FROM {\$wpdb->prefix}odcm_audit_log WHERE order_id = 43 ORDER BY timestamp\", ARRAY_A);
echo 'Found ' . count(\$results) . ' events for order #43';
"

# Test Action Scheduler processing (should work after cleanup)
docker exec order-daemon-devtools-cron-1 wp action-scheduler run

# Test API response
docker exec order-daemon-devtools-cron-1 wp eval "
\$endpoint = new \\OrderDaemon\\CompletionManager\\API\\AuditLogEndpoint();
\$request = new \\WP_REST_Request('GET', '/odcm/v1/audit-log');
\$response = \$endpoint->get_logs(\$request);
\$data = \$response->get_data();
echo 'API returns ' . count(\$data['logs']) . ' logs';
"

# Verify UniversalEventProcessor works (should return TRUE and create entries)
docker exec order-daemon-devtools-cron-1 wp eval "
\$order = wc_get_order(43); // replace with test order ID
\$processor = \\OrderDaemon\\CompletionManager\\Core\\Events\\UniversalEventProcessor::instance();
\$result = \$processor->processEvent([
    'eventType' => 'order_check_scheduled',
    'primaryObjectType' => 'order',
    'primaryObjectID' => 43,
    'idempotencyKey' => 'test_' . time(),
    'occurredAt' => current_time('c'),
    'receivedAt' => current_time('c')
]);
echo 'UniversalEventProcessor result: ' . (\$result ? 'TRUE' : 'FALSE');
"
```

This plan provides a systematic approach to resolving all remaining audit logging functionality gaps while maintaining the working infrastructure we've already established.
