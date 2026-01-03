# Dashboard Audit Log Processing Fix - Implementation Plan

## Executive Summary

New orders that are auto-completed by rules are not appearing in the Insight Dashboard. After extensive investigation, the root cause has been identified: **Action Scheduler actions are not being scheduled during web requests** (checkout). This document provides a complete implementation plan to fix this issue.

---

## Understanding `register_shutdown_function()` - The Core Solution

### What Is It?

`register_shutdown_function()` is a core PHP function that registers a callback to be executed **after the script finishes execution** - either when it completes normally, when `exit()` is called, or when a fatal error occurs.

### PHP Version Availability

| PHP Version | Available? |
|-------------|------------|
| PHP 4.0+ (May 2000) | ✅ Yes |
| PHP 5.x | ✅ Yes |
| PHP 7.x | ✅ Yes |
| PHP 8.x | ✅ Yes |

**WordPress Compatibility:**
- WordPress requires PHP 7.0+ minimum (as of WP 5.2+)
- Most hosts run PHP 7.4-8.2+
- **Probability of a WordPress site not having this function: Essentially 0%** - it's been in PHP for 24+ years

### How It Works - Execution Timeline

```
1. Request arrives at server
2. PHP script starts executing
3. register_shutdown_function() is called (registers callback, doesn't run it yet)
4. Script continues executing
5. Response is sent to browser (fastcgi_finish_request if available, or output buffer flush)
6. Script reaches end / exit() is called
7. ⭐ SHUTDOWN PHASE BEGINS ⭐
8. All registered shutdown functions run IN ORDER of registration
9. PHP script fully terminates
```

### Why This Solves the Problem

**The Problem:**
- Action Scheduler's `as_enqueue_async_action()` fails silently during web requests (checkout)
- Queue entries are created, but never processed
- Orders don't appear in the Insight Dashboard

**Why Shutdown Handler Fixes This:**

1. **Guaranteed Execution**: Unlike Action Scheduler which depends on WordPress cron being triggered, a separate page request to process jobs, or external cron hitting `wp-cron.php` - the shutdown handler runs **in the same request** that created the queue entry.

2. **Runs After Response Sent**: With `fastcgi_finish_request()` (common on most hosts) or output buffering, the HTTP response is sent to the user's browser first. The shutdown function then runs "in the background" from the user's perspective. **User experience is not affected** - the checkout page loads normally.

3. **No External Dependencies**: Doesn't need wp-cron, doesn't need the Docker cron container, doesn't need Action Scheduler. Works even on cheap shared hosting with disabled cron.

### Real-World WordPress Compatibility

| Scenario | Without Shutdown Handler | With Shutdown Handler |
|----------|-------------------------|----------------------|
| User completes checkout | Queue entry created, waits for cron | Queue entry created AND processed immediately |
| No users on site for hours | Entries accumulate, wait for cron | N/A - entries are processed at creation time |
| Cheap host with disabled cron | Entries never processed | Entries still processed |
| High traffic site | Cron may struggle to keep up | Each request processes its own entry |

**Key Insight**: The shutdown handler ensures that **every web request that creates a queue entry also processes it**. This is fundamentally different from the current architecture where web request creates entry → hopes Action Scheduler picks it up later. Now: web request creates entry → same request processes it before terminating.

---

## Architecture Decision: Queue System + Shutdown Handler

### Was Building Around Action Scheduler Wrong?

**No.** Action Scheduler remains the right tool for:
- ✅ Scheduled tasks (run this in 2 hours)
- ✅ Batch processing (process 1000 items in chunks)
- ✅ Heavy operations that shouldn't block requests
- ✅ Rate-limited API calls
- ✅ Tasks that can be retried if they fail

**But for real-time audit logging** that must happen reliably with every triggered event, the shutdown handler provides guaranteed execution.

### The Queue System Is Still Valuable

The existing queue table and payload system are NOT wasted work. The queue provides:
- **Atomicity**: Either the queue entry exists or it doesn't - no partial states
- **Debugging**: You can see what's pending, what failed, timestamps, etc.
- **Retry capability**: If the shutdown handler fails (timeout, etc.), the queue entry still exists and can be processed later
- **Batch recovery**: If something goes catastrophically wrong, you can run a batch process to recover

**What changes: HOW the queue gets processed, not whether you have a queue.**

### Scope of Changes: MINOR

| Component | Change Required |
|-----------|-----------------|
| Queue table structure | ❌ None |
| `odcm_log_event()` queue insertion | ❌ None |
| Payload/event data structure | ❌ None |
| `odcm_process_queued_log_entry()` | ✅ Add atomic claim pattern (~10 lines) |
| Dashboard/API reading from audit log | ❌ None |
| `odcm_log_event()` scheduling section | ✅ Add shutdown handler fallback (~10 lines) |
| `actions.php` | ✅ Add `odcm_process_queue_sync()` function (~25 lines) |

**Total code change: ~45 lines**, and it's **additive** - we're adding a fallback, not ripping out Action Scheduler.

---

## Environment Details

### Docker Development Environment
- **Cron Container**: `order-daemon-devtools-cron-1`
- **WordPress CLI Access**: `docker exec order-daemon-devtools-cron-1 wp <command>`
- **WordPress is running with HPOS (High-Performance Order Storage) enabled**

### Useful Docker Commands
```bash
# Restart cron container
docker restart order-daemon-devtools-cron-1

# Execute WP-CLI commands
docker exec order-daemon-devtools-cron-1 wp eval '<php_code>'

# Check pending queue items
docker exec order-daemon-devtools-cron-1 wp eval 'global $wpdb; $pending = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue WHERE status = \"pending\" LIMIT 10"); print_r($pending);'

# Check pending Action Scheduler actions
docker exec order-daemon-devtools-cron-1 wp eval 'if (function_exists("as_get_scheduled_actions")) { $actions = as_get_scheduled_actions(["group" => "odcm-logs", "status" => "pending", "per_page" => 10], "ARRAY_A"); print_r($actions); }'

# Manually process pending queue items
docker exec order-daemon-devtools-cron-1 wp eval 'global $wpdb; $pending = $wpdb->get_results("SELECT queue_id FROM {$wpdb->prefix}odcm_audit_log_queue WHERE status = \"pending\" LIMIT 20"); foreach ($pending as $item) { odcm_process_queued_log_entry(["queue_id" => $item->queue_id]); } echo "Processed " . count($pending) . " items\n";'
```

---

## Research Findings

### Problem Investigation Results

1. **Queue entries ARE being created correctly** ✅
   - When orders are processed during checkout, queue entries are inserted into `wp_odcm_audit_log_queue` table
   - Example: Order #130 had 8 pending queue items

2. **Action Scheduler IS available and functioning** ✅
   - `as_schedule_single_action()` function exists
   - `as_enqueue_async_action()` function exists  
   - ActionScheduler class exists and tables are initialized

3. **Action Scheduler actions are NOT being created during web requests** ❌
   - When calling `odcm_log_event()` from WP-CLI, AS actions ARE created
   - When `odcm_log_event()` runs during checkout (web request), AS actions are NOT created
   - `as_enqueue_async_action()` appears to fail silently during checkout

### Evidence From Testing

```
=== Pending Queue Items ===
Count: 5
  - odcm_log_6958698ab550f480683587 (pending) @ 2026-01-03 01:57:46
  - odcm_log_6958698ab282b114845350 (pending) @ 2026-01-03 01:57:46
  ...

=== Pending Action Scheduler Actions ===
Count: 0   <-- THIS IS THE PROBLEM!
```

After manually calling `odcm_log_event()` from CLI:
```
=== Pending AS actions ===
Pending AS actions: 1  <-- AS WORKS FROM CLI
```

### Additional Issues Fixed

Two methods in `UniversalEventProcessor.php` were broken due to incorrect "WordPress Plugin Checker" fixes:

1. **`isDuplicateEvent()`** - Was using `WP_Query` with `'post_type' => 'odcm_audit_log'`, but `odcm_audit_log` is a custom table, NOT a post type. **FIXED** - Now uses direct database queries.

2. **`getExistingRuleExecutionEvent()`** - Same issue. **FIXED** - Now uses direct database queries.

---

## Duplicate Protection Analysis

### Current Protection Mechanisms in `odcm_process_queued_log_entry()`

**1. Status-based retrieval:**
```php
$queue_entry = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue
     WHERE queue_id = %s AND status = 'pending'",
    $queue_id
));
```
If the entry is already `processed`, this returns null → function exits.

**2. Deduplication hash caching:**
```php
$dedup_cache_key = 'odcm_log_dedup_' . $dedup_hash;
$existing_entry = wp_cache_get($dedup_cache_key);

if (false !== $existing_entry) {
    // Skip creating duplicate log entry
    odcm_log_message("Skipping duplicate log entry creation...", 'debug');
    $log_result = true;
}
```

**3. Status update after processing:**
```php
$wpdb->update(
    "{$wpdb->prefix}odcm_audit_log_queue",
    ['status' => 'processed', 'processed_at' => current_time('mysql')],
    ['queue_id' => $queue_id]
);
```

### Race Condition Vulnerability (TOCTOU)

The current implementation has a **time-of-check-to-time-of-use** vulnerability:

```
Timeline:
[Request A] SELECTs entry → status = 'pending' ✓
[Request B] SELECTs entry → status = 'pending' ✓  (hasn't been updated yet!)
[Request A] Processes entry
[Request A] UPDATEs status → 'processed'
[Request B] Processes entry (DUPLICATE!)
[Request B] UPDATEs status → 'processed'
```

**This can happen when:**
- Action Scheduler runs at the exact same time as the shutdown handler
- Two shutdown handlers run in rapid succession (unlikely but possible with multiple requests)

### Solution: Atomic Claim Pattern

Use an **atomic UPDATE** that both claims and verifies in one query:

```php
// ATOMIC CLAIM - only succeeds if status is 'pending'
$claimed = $wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->prefix}odcm_audit_log_queue
     SET status = 'processing'
     WHERE queue_id = %s AND status = 'pending'",
    $queue_id
));

if ($claimed === 0) {
    // Another process already claimed it, or it's already processed
    odcm_log_message("Queue entry {$queue_id} already claimed or processed", 'debug');
    return;
}
```

**Why this works:**
- `UPDATE ... WHERE status = 'pending'` is atomic at the database level
- If two requests try simultaneously, only ONE will get `affected_rows = 1`
- The other gets `affected_rows = 0` and exits immediately

---

## Implementation Plan

### Files to Modify

1. **`src/Includes/functions.php`** - Add shutdown handler in `odcm_log_event()`
2. **`src/Includes/actions.php`** - Add `odcm_process_queue_sync()` function and atomic claim pattern

### Step 1: Add Atomic Claim Pattern to `odcm_process_queued_log_entry()`

In `src/Includes/actions.php`, modify the beginning of `odcm_process_queued_log_entry()`:

```php
function odcm_process_queued_log_entry($args): void
{
    global $wpdb;

    // ... existing queue_id extraction code ...
    
    if (empty($queue_id)) {
        odcm_log_message('odcm_process_queued_log_entry called with empty queue_id', 'error');
        return;
    }
    
    // ATOMIC CLAIM - prevents race conditions between AS and shutdown handler
    // This UPDATE only succeeds if status is still 'pending'
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}odcm_audit_log_queue
         SET status = 'processing'
         WHERE queue_id = %s AND status = 'pending'",
        $queue_id
    ));
    
    if ($claimed === 0) {
        // Another process already claimed it, or it's already processed
        odcm_log_message("Queue entry {$queue_id} already claimed or processed, skipping", 'debug');
        return;
    }
    
    // Clear any cached entry since we just changed the status
    $cache_key = 'odcm_queue_entry_' . md5($queue_id);
    wp_cache_delete($cache_key);
    
    // Now safe to retrieve and process - we have exclusive ownership
    $queue_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue
         WHERE queue_id = %s",
        $queue_id
    ));
    
    if (!$queue_entry) {
        odcm_log_message("Queue entry {$queue_id} not found after claiming", 'error');
        return;
    }
    
    // ... rest of existing processing code ...
    // (Remove the old status = 'pending' check from the SELECT since we already claimed it)
```

Also update the error handler to reset status back to 'pending' for retry:

```php
} catch (\Throwable $e) {
    // Update queue entry with error - reset to 'pending' for retry
    $retry_count = (int) $queue_entry->retry_count + 1;
    
    $wpdb->update(
        "{$wpdb->prefix}odcm_audit_log_queue",
        [
            'retry_count' => $retry_count,
            'last_error' => $e->getMessage(),
            'status' => $retry_count >= 3 ? 'failed' : 'pending'  // Reset to pending if under retry limit
        ],
        ['queue_id' => $queue_id],
        ['%d', '%s', '%s'],
        ['%s']
    );
    // ... rest of error handling ...
}
```

### Step 2: Add Shutdown Handler Function

Add this new function to `src/Includes/actions.php` near `odcm_process_queued_log_entry`:

```php
/**
 * Process pending queue items synchronously (for shutdown handler)
 * 
 * This is called via register_shutdown_function() to process log entries
 * after the HTTP response has been sent to the client. It serves as a
 * fallback when Action Scheduler fails to schedule the processing.
 *
 * @since 1.2.2
 * @param string $queue_id The specific queue ID to process
 * @return void
 */
function odcm_process_queue_sync(string $queue_id): void
{
    // Ensure WordPress functions are still available
    if (!function_exists('get_option')) {
        return;
    }
    
    // Ensure we don't process during shutdown if things are broken
    if (connection_aborted()) {
        return;
    }
    
    // Delegate to the main processing function
    // The atomic claim pattern inside will prevent duplicates
    odcm_process_queued_log_entry(['queue_id' => $queue_id]);
    
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        odcm_log_message("Processed {$queue_id} via shutdown handler fallback", 'debug');
    }
}
```

### Step 3: Modify `odcm_log_event()` Function

In `src/Includes/functions.php`, find the `odcm_log_event()` function and modify the section after queue insertion:

**Current code** (around line 930-950):
```php
// PHASE 2: Schedule background processing
$action_id = as_enqueue_async_action(
    'odcm_process_queued_log_entry',
    ['queue_id' => $queue_id],
    'odcm-logs'
);

if (!$action_id) {
    odcm_log_message("Failed to schedule queue processing for {$queue_id}", 'error');
    return false;
}
```

**Modified code**:
```php
// PHASE 2: Schedule background processing via Action Scheduler
$action_id = as_enqueue_async_action(
    'odcm_process_queued_log_entry',
    ['queue_id' => $queue_id],
    'odcm-logs'
);

// PHASE 3: Register synchronous fallback via shutdown handler
// This ensures processing happens even if Action Scheduler fails (known issue during web requests)
// The atomic claim pattern in odcm_process_queued_log_entry() prevents duplicate processing
register_shutdown_function('odcm_process_queue_sync', $queue_id);

if (!$action_id) {
    // Log warning but don't return false - shutdown handler will process it
    odcm_log_message("AS scheduling failed for {$queue_id}, shutdown handler fallback active", 'warning');
}
```

---

## Testing Plan

After implementing the fix:

1. **Create a new test order** in WooCommerce
2. **Check the queue table** - should show entry then quickly process
3. **Check the audit log table** - should show entries for the new order
4. **Check the Insight Dashboard** - order should appear in timeline

```bash
# Test with new order
docker exec order-daemon-devtools-cron-1 wp eval '
global $wpdb;

// Create test order
$order = wc_create_order();
$order->set_status("processing");
$order->save();
$order_id = $order->get_id();

echo "Created order #{$order_id}\n";
sleep(2); // Give shutdown handler time to run

// Check audit log entries
$entries = $wpdb->get_results($wpdb->prepare(
    "SELECT log_id, event_type FROM {$wpdb->prefix}odcm_audit_log WHERE order_id = %d",
    $order_id
));
echo "Audit log entries: " . count($entries) . "\n";
foreach ($entries as $e) { echo "  - {$e->event_type}\n"; }

// Check pending queue items
$pending = $wpdb->get_results($wpdb->prepare(
    "SELECT queue_id FROM {$wpdb->prefix}odcm_audit_log_queue WHERE status = %s AND event_data LIKE %s",
    "pending",
    "%\"order_id\":{$order_id}%"
));
echo "Still pending in queue: " . count($pending) . "\n";
'
```

---

## Summary of Changes

| File | Change |
|------|--------|
| `src/Core/Events/UniversalEventProcessor.php` | Fixed `isDuplicateEvent()` and `getExistingRuleExecutionEvent()` to use direct DB queries instead of WP_Query (already done) |
| `src/Includes/actions.php` | Added `odcm_process_queue_sync()` function for shutdown handler |
| `src/Includes/actions.php` | Modified `odcm_process_queued_log_entry()` to use atomic claim pattern |
| `src/Includes/functions.php` | Modified `odcm_log_event()` to register shutdown handler fallback |

---

## Expected Results

After implementation:
1. ✅ Queue entries are created during checkout (unchanged)
2. ✅ Action Scheduler attempts to schedule processing (unchanged, may still fail)
3. ✅ **NEW**: Shutdown handler processes entry if AS fails
4. ✅ **NEW**: Atomic claim pattern prevents duplicate processing
5. ✅ Audit log entries appear in database immediately after request completes
6. ✅ Orders appear in Insight Dashboard without manual intervention
7. ✅ Works on all WordPress installations (no external cron required)

---

## Rollback Plan

If issues arise:
1. Remove the `register_shutdown_function()` call from `odcm_log_event()`
2. Remove the `odcm_process_queue_sync()` function from `actions.php`
3. Revert the atomic claim changes in `odcm_process_queued_log_entry()` (restore original SELECT with status check)
4. Revert to queue-only approach (will require fixing AS issue or setting up external cron)
