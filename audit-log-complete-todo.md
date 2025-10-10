# DETAILED PLAN: Complete Audit Logging System Functionality Gaps

## CURRENT STATUS SUMMARY

**✅ INFRASTRUCTURE WORKING:**
- API debug filtering fixed (returns 7 logs instead of 0)
- Action Scheduler deduplication fixed (tasks process successfully)
- Payload storage verified working (rich JSON data stored with correct linking)
- Dashboard displays Block Checkout events for orders #20-24

**❌ REMAINING FUNCTIONALITY GAPS:**
1. **Core WooCommerce hooks not firing** - Real orders (like #31) generate 0 audit entries
2. **UI consolidation not working** - Process groups show as individual entries instead of consolidated lifecycle view
3. **Detail pane rendering broken** - Rich payload data not displaying in details view

---

## PHASE 1: FIX CORE WOOCOMMERCE HOOK EXECUTION

### Problem
Core.php hooks are registered but don't fire during real checkout. Only BlockCheckoutCompatibility events are captured.

### Investigation Steps
1. **Verify hook registration timing**
    - Check if Core::init() is called before WooCommerce hooks fire
    - Test if hooks register too late in WordPress lifecycle

2. **Test hook priority conflicts**
    - Check if other plugins prevent WooCommerce hooks from firing
    - Try different hook priorities (5, 15, 20 instead of 10)

3. **Test alternative WooCommerce hooks**
    - Try `woocommerce_checkout_order_processed` instead of status change hooks
    - Test `woocommerce_new_order` for order creation events

### Implementation Steps
1. **Debug hook execution timing**
   ```php
   // Add to Core.php init() method
   add_action('woocommerce_order_status_completed', function($order_id) {
       error_log('ODCM: Hook fired for order #' . $order_id);
   }, 5, 1);
   ```

2. **Test with alternative hooks**
   ```php
   // Add to Core.php registerStatusHooks()
   add_action('woocommerce_checkout_order_processed', [$this, 'handle_checkout_processed'], 10, 1);
   add_action('woocommerce_new_order', [$this, 'handle_new_order'], 10, 1);
   ```

3. **Add diagnostic logging**
    - Log when hooks register vs when WooCommerce events fire
    - Test with fresh order creation vs status changes

### Files to Modify
- `src/Core/Core.php` - Hook registration and timing
- `src/Plugin.php` - Initialization sequence if needed

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

## PRIORITY ORDER & DEPENDENCIES

1. **PHASE 1 (Core Hooks)** - **HIGHEST PRIORITY**
    - Blocks all new order audit logging
    - Required for complete functionality

2. **PHASE 3 (Detail Rendering)** - **MEDIUM PRIORITY**
    - Existing events can't be viewed properly
    - Affects user experience significantly

3. **PHASE 2 (UI Consolidation)** - **LOWER PRIORITY**
    - Events are visible but fragmented
    - UX improvement rather than blocking issue

---

## DEBUGGING COMMANDS FOR TESTING

```bash
# Test new order creation
docker exec order-daemon-devtools-cron-1 wp eval "
$order = wc_create_order();
$order->add_product(wc_get_product(17)); // virtual product
$order->set_status('completed');
$order->save();
echo 'Created order #' . $order->get_id();
"

# Check audit events for order
docker exec order-daemon-devtools-cron-1 wp eval "
global \$wpdb;
\$results = \$wpdb->get_results(\"SELECT * FROM {\$wpdb->prefix}odcm_audit_log WHERE order_id = 32 ORDER BY timestamp\", ARRAY_A);
echo 'Found ' . count(\$results) . ' events for order #32';
"

# Test Action Scheduler processing  
docker exec order-daemon-devtools-cron-1 wp action-scheduler run

# Test API response
docker exec order-daemon-devtools-cron-1 wp eval "
\$endpoint = new \\OrderDaemon\\CompletionManager\\API\\AuditLogEndpoint();
\$request = new \\WP_REST_Request('GET', '/odcm/v1/audit-log');
\$response = \$endpoint->get_logs(\$request);
\$data = \$response->get_data();
echo 'API returns ' . count(\$data['logs']) . ' logs';
"
```

This plan provides a systematic approach to resolving all remaining audit logging functionality gaps while maintaining the working infrastructure we've already established.