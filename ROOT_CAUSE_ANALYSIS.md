# ROOT CAUSE ANALYSIS - Order Daemon Not Processing Orders

## 🔍 **Problem Summary**
Orders are being placed and auto-completed, but **NO audit logs are created** and the Insight Dashboard remains empty.

## ✅ **What's Working**
- Plugin loads and initializes
- Core::init() is called
- Hooks are registered in Core.php
- Action Scheduler creates tasks
- WooCommerce Blocks checkout completes orders
- Database tables exist

## ❌ **What's Broken**
**Action Scheduler callbacks are NOT registered when tasks execute!**

### Evidence:
```sql
SELECT message FROM wp_actionscheduler_logs WHERE action_id = 135;
-- Result: "action failed via WP Cron: Scheduled action for odcm_rebuild_rule_indexes_job 
--          will not be executed as no callbacks are registered."
```

### What This Means:
1. When BlockCheckoutCompatibility processes an order, it calls:
   ```php
   as_enqueue_async_action('odcm_process_lifecycle_event', [...])
   ```

2. Action Scheduler creates the task successfully

3. BUT when WP-Cron tries to execute the task, it fails because:
   ```php
   add_action('odcm_process_lifecycle_event', 'odcm_handle_universal_event_processing', 10, 1);
   ```
   **This hook registration doesn't exist when the task runs!**

## 🎯 **Root Cause**

The problem is in the plugin's load sequence:

**Current (Broken) Sequence:**
1. WordPress loads plugin files
2. Plugin initializes
3. Hooks get registered in PHP memory
4. Page request completes
5. Later: WP-Cron runs in separate request
6. **NEW REQUEST - hooks NOT registered!**
7. Action Scheduler tries to call callback
8. Callback doesn't exist → task fails

**The Issue:** 
`actions.php` is probably only loaded during admin requests or specific contexts, NOT during WP-Cron execution.

## 📊 **Test Results**

### Order #17 Timeline:
- **20:43:35 UTC**: Order placed via WooCommerce Blocks
- **20:43:35 UTC**: Order auto-completed (by WooCommerce, not plugin)
- **20:43:35 UTC**: BlockCheckoutCompatibility tries to schedule task
- **Result**: Either task wasn't created OR was created but failed silently
- **20:43:55 - 20:47:39 UTC**: Multiple `odcm_rebuild_rule_indexes_job` tasks fail with "no callbacks registered"

### Database Evidence:
```sql
-- NO order processing tasks created
SELECT COUNT(*) FROM wp_actionscheduler_actions 
WHERE hook LIKE '%lifecycle%' OR hook LIKE '%checkout%' OR hook LIKE '%payment%';
-- Result: 0

-- NO audit logs created  
SELECT COUNT(*) FROM wp_odcm_audit_log;
-- Result: 0

-- Order exists and is completed
SELECT ID, post_status FROM wp_posts WHERE ID = 17;
-- Result: 17, wc-completed
```

## 🔧 **Solution**

The fix needs to ensure Action Scheduler callbacks are ALWAYS registered, even during WP-Cron requests.

### Option 1: Move Hook Registration to Main Plugin File
Move all `add_action` for Action Scheduler hooks from `actions.php` to the main plugin initialization that runs on EVERY request.

### Option 2: Ensure actions.php Loads During Cron
Verify that `actions.php` is included in the plugin's main file and loads during all request types, not just admin.

### Option 3: Register Hooks Earlier
Use `plugins_loaded` or `init` hooks with very early priority to ensure callbacks exist before Action Scheduler tries to use them.

## 🧪 **How to Verify the Fix**

1. Check that callbacks are registered:
```php
// Add to functions.php temporarily
add_action('init', function() {
    error_log('HAS odcm_process_lifecycle_event: ' . (has_action('odcm_process_lifecycle_event') ? 'YES' : 'NO'));
});
```

2. Place a new test order

3. Check Action Scheduler logs:
```sql
SELECT message FROM wp_actionscheduler_logs 
WHERE action_id IN (
    SELECT action_id FROM wp_actionscheduler_actions 
    WHERE hook LIKE '%odcm%' 
    ORDER BY action_id DESC LIMIT 5
);
```

Should see: "action complete" instead of "no callbacks registered"

4. Check audit logs:
```sql
SELECT COUNT(*) FROM wp_odcm_audit_log;
```

Should be > 0

## 📝 **Files to Check**

1. **Main plugin file**: `order-daemon.php` or `order-daemon-core.php`
   - Does it include `actions.php`?
   - When is it included?
   
2. **Plugin.php**: `src/Plugin.php`
   - How are action handlers registered?
   - Do they register on all request types?

3. **actions.php**: `src/Includes/actions.php`
   - Are the `add_action` calls conditional?
   - Do they run during WP-Cron?

## 🎯 **Expected Behavior After Fix**

When an order is placed via Blocks checkout:
1. ✅ BlockCheckoutCompatibility::handle_block_checkout_processed() fires
2. ✅ Calls `as_enqueue_async_action('odcm_process_lifecycle_event', [...])`
3. ✅ Action Scheduler creates task
4. ✅ WP-Cron picks up task
5. ✅ Callback `odcm_handle_universal_event_processing()` EXISTS and executes
6. ✅ UniversalEventProcessor processes the event
7. ✅ Audit logs are created
8. ✅ Dashboard displays the timeline
9. ✅ Rules are evaluated and actions executed

---

**Status**: Root cause identified - Action Scheduler callbacks not registered during WP-Cron execution
**Priority**: CRITICAL - Blocks all order processing
**Impact**: Plugin appears functional but silently fails to process any orders
