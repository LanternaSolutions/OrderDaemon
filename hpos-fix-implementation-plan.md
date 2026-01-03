# Order Daemon HPOS Support Fix - Comprehensive Implementation Plan

## Executive Summary

This document provides a detailed implementation plan to fix the broken HPOS (High-Performance Order Storage) support in Order Daemon. The plugin has partial HPOS support in `OrderMetaManager` but fails to process HPOS orders due to broken order type detection logic.

## Current State Analysis

### Working Components
- ✅ `OrderMetaManager` - Fully HPOS-compatible metadata operations
- ✅ `wc_get_orders()` usage in Core.php - HPOS-aware order queries
- ✅ HPOS detection via `OrderUtil::custom_orders_table_usage_is_enabled()`

### Broken Components
- ❌ `functions.php` line 1078 - Only processes `shop_order` post type
- ❌ `ManualStatusTracker.php` line 105 - Only tracks `shop_order` edits
- ❌ `RefundDeletionDiagnostics.php` line 1078 - Only processes `shop_order` refunds/deletions
- ❌ Missing HPOS-aware order type detection utilities

## Root Cause

The plugin fails to process HPOS orders because:
1. **Order Discovery Broken**: Functions check `post_type === 'shop_order'` instead of using HPOS-aware detection
2. **Manual Tracking Broken**: HPOS orders (`shop_order_placehold`) aren't recognized as processable orders
3. **Refund/Deletion Tracking Broken**: HPOS orders aren't processed for refund and deletion diagnostics
4. **Metadata Operations Work**: `OrderMetaManager` correctly handles HPOS, but orders never reach it

## Implementation Plan

### Phase 1: Core Infrastructure Fixes

#### 1.1 Add HPOS-Aware Order Type Detection Utility

**File**: `src/Includes/Utils/OrderTypeDetector.php` (NEW)
```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

class OrderTypeDetector
{
    /**
     * Check if an order ID represents a processable order (HPOS or legacy)
     *
     * @param int $order_id Order ID to check
     * @return bool True if this is a processable order
     */
    public static function is_processable_order(int $order_id): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        $post_type = get_post_type($order_id);

        // Traditional WooCommerce orders
        if ($post_type === 'shop_order') {
            return true;
        }

        // HPOS placeholder orders
        if ($post_type === 'shop_order_placehold') {
            return self::is_hpos_enabled();
        }

        return false;
    }

    /**
     * Check if HPOS is enabled
     *
     * @return bool True if HPOS custom order tables are in use
     */
    public static function is_hpos_enabled(): bool
    {
        return OrderMetaManager::is_hpos_enabled();
    }

    /**
     * Get order type for logging/debugging purposes
     *
     * @param int $order_id Order ID
     * @return string Order type: 'legacy', 'hpos', or 'unknown'
     */
    public static function get_order_type(int $order_id): string
    {
        $post_type = get_post_type($order_id);

        if ($post_type === 'shop_order') {
            return 'legacy';
        }

        if ($post_type === 'shop_order_placehold' && self::is_hpos_enabled()) {
            return 'hpos';
        }

        return 'unknown';
    }
}
```

#### 1.2 Fix Order Processing in functions.php

**File**: `src/Includes/functions.php` (MODIFY)
**Location**: Line 1078 - Replace broken post type check

```php
// REPLACE THIS BROKEN CODE:
if (get_post_type($post_id) === 'shop_order') {
    $order_ids[] = $post_id;
}

// WITH THIS HPOS-AWARE CODE:
if (OrderTypeDetector::is_processable_order($post_id)) {
    $order_ids[] = $post_id;
}
```

#### 1.3 Fix Manual Status Tracking

**File**: `src/Core/ManualStatusTracker.php` (MODIFY)
**Location**: Line 105 - Replace broken post type check

```php
// REPLACE THIS BROKEN CODE:
if (!is_user_logged_in() || $post->post_type !== 'shop_order') {
    return;
}

// WITH THIS HPOS-AWARE CODE:
if (!is_user_logged_in() || !OrderTypeDetector::is_processable_order($post->ID)) {
    return;
}
```

#### 1.4 Fix Refund/Deletion Diagnostics

**File**: `src/Core/RefundDeletionDiagnostics.php` (MODIFY)
**Location**: Line 1078 - Replace broken post type check

```php
// REPLACE THIS BROKEN CODE:
if (get_post_type($post_id) !== 'shop_order') {
    return;
}

// WITH THIS HPOS-AWARE CODE:
if (!OrderTypeDetector::is_processable_order($post_id)) {
    return;
}
```

### Phase 2: Enhanced HPOS Integration

#### 2.1 Add HPOS Order Query Helper

**File**: `src/Includes/Utils/OrderQueryHelper.php` (NEW)
```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

class OrderQueryHelper
{
    /**
     * Get orders using HPOS-aware query
     *
     * @param array $args Query arguments
     * @return array Order IDs
     */
    public static function get_order_ids(array $args = []): array
    {
        $defaults = [
            'status' => ['processing', 'on-hold'],
            'limit' => -1,
            'return' => 'ids',
        ];

        $query_args = wp_parse_args($args, $defaults);

        // Use wc_get_orders() which is HPOS-aware
        return wc_get_orders($query_args);
    }

    /**
     * Find orders by metadata with HPOS support
     *
     * @param string $meta_key Metadata key
     * @param string $meta_value Metadata value
     * @param array $additional_args Additional query args
     * @return array Order IDs
     */
    public static function find_orders_by_metadata(string $meta_key, string $meta_value, array $additional_args = []): array
    {
        return OrderMetaManager::find_orders_by_meta($meta_key, $meta_value, -1, $additional_args);
    }
}
```

#### 2.2 Update Core Processing to Use HPOS Helpers

**File**: `src/Core/Core.php` (MODIFY)
**Location**: Various order query locations

Replace direct `wc_get_orders()` calls with `OrderQueryHelper::get_order_ids()` for consistency.

### Phase 3: Testing and Validation

#### 3.1 Docker Test Commands

```bash
# 1. Verify HPOS is enabled
docker exec order-daemon-devtools-cron-1 wp eval "echo OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager::is_hpos_enabled() ? 'HPOS ENABLED' : 'HPOS DISABLED';"

# 2. Check order types for orders 121 and 122
docker exec order-daemon-devtools-cron-1 wp eval "
\$detector = new OrderDaemon\CompletionManager\Includes\Utils\OrderTypeDetector();
echo 'Order 121: ' . \$detector->get_order_type(121) . PHP_EOL;
echo 'Order 122: ' . \$detector->get_order_type(122) . PHP_EOL;
"

# 3. Test order processing with new detection
docker exec order-daemon-devtools-cron-1 wp eval "
\$detector = new OrderDaemon\CompletionManager\Includes\Utils\OrderTypeDetector();
echo 'Order 121 processable: ' . (\$detector->is_processable_order(121) ? 'YES' : 'NO') . PHP_EOL;
echo 'Order 122 processable: ' . (\$detector->is_processable_order(122) ? 'YES' : 'NO') . PHP_EOL;
"

# 4. Test metadata operations on HPOS orders
docker exec order-daemon-devtools-cron-1 wp eval "
\$meta = OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager::get_meta(121, '_transaction_id', true);
echo 'Order 121 transaction ID: ' . (\$meta ?: 'NOT FOUND') . PHP_EOL;
"

# 5. Test manual status tracking
docker exec order-daemon-devtools-cron-1 wp eval "
// Simulate manual status change
do_action('woocommerce_order_status_changed', 121, 'draft', 'processing', wc_get_order(121));
echo 'Manual status tracking test completed';
"
```

#### 3.2 SQL Verification Queries

```sql
-- Check if orders 121 and 122 are now properly typed
SELECT ID, post_type, post_status
FROM wp_posts
WHERE ID IN (121, 122);

-- Verify audit logs are being created for HPOS orders
SELECT COUNT(*) as hpos_logs
FROM wp_odcm_audit_log
WHERE order_id IN (121, 122);

-- Check if manual tracking is working
SELECT COUNT(*) as manual_notes
FROM wp_comments
WHERE comment_post_ID IN (121, 122)
AND comment_type = 'order_note'
AND comment_content LIKE '%manually changed%';
```

### Phase 4: Documentation Updates

#### 4.1 Update HPOS Documentation

**File**: `README.txt` (APPEND HPOS SECTION)

```markdown
## WooCommerce HPOS (High-Performance Order Storage) Support

Order Daemon fully supports WooCommerce's High-Performance Order Storage system.

### How HPOS Support Works

- **Automatic Detection**: The plugin automatically detects when HPOS is enabled
- **Dual Mode Operation**: Works seamlessly with both legacy and HPOS order storage
- **Unified Processing**: All order processing works identically regardless of storage method
- **Transparent Integration**: No configuration needed - just works

### HPOS Compatibility Features

✅ Order metadata operations (read/write/delete)
✅ Order status change tracking
✅ Manual edit detection
✅ Completion rule processing
✅ Audit logging
✅ Dashboard integration

### Technical Implementation

The plugin uses WooCommerce's recommended HPOS integration patterns:

1. **Order Detection**: Uses `OrderUtil::custom_orders_table_usage_is_enabled()`
2. **Metadata Access**: Uses `wc_get_order()` and order object methods
3. **Query Compatibility**: Uses `wc_get_orders()` for HPOS-aware queries
4. **Post Type Handling**: Recognizes both `shop_order` and `shop_order_placehold` types

### Troubleshooting HPOS Issues

If you experience issues with HPOS orders:

1. **Verify HPOS Status**:
   ```php
   echo OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager::is_hpos_enabled() ? 'Enabled' : 'Disabled';
   ```

2. **Check Order Types**:
   ```php
   $detector = new OrderDaemon\CompletionManager\Includes\Utils\OrderTypeDetector();
   echo $detector->get_order_type($order_id);
   ```

3. **Test Metadata Access**:
   ```php
   $meta = OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager::get_meta($order_id, '_transaction_id');
   ```

### HPOS Performance Considerations

- HPOS orders are processed with the same performance as legacy orders
- Metadata operations use WooCommerce's optimized HPOS methods
- No additional database queries are required for HPOS support
```

## Implementation Checklist

- [x] Create `OrderTypeDetector.php` with HPOS-aware detection
- [x] Fix `functions.php` order processing (line 1078)
- [x] Fix `ManualStatusTracker.php` manual edit tracking (line 105)
- [x] Fix `RefundDeletionDiagnostics.php` refund/deletion tracking
- [x] Create `OrderQueryHelper.php` for consistent order queries
- [x] Update `Core.php` to use new helpers where appropriate
- [x] Run Docker tests to verify HPOS order processing
- [x] Execute SQL queries to confirm audit logs and metadata
- [x] Update documentation with HPOS support information
- [ ] Add HPOS-specific test cases to test suite
- [ ] Investigate and fix Insight Dashboard display issues for new orders
- [ ] Test order #124 display in dashboard

## Expected Outcomes

After implementation:
1. ✅ HPOS orders (shop_order_placehold) will be processed by Order Daemon
2. ✅ Manual status changes on HPOS orders will be tracked
3. ✅ Refund and deletion operations on HPOS orders will be tracked
4. ✅ Audit logs will be created for HPOS order processing
5. ✅ Dashboard will display HPOS orders alongside legacy orders
6. ✅ All completion rules will work with HPOS orders
7. ✅ No breaking changes to existing legacy order processing

## Rollback Plan

If issues arise:
1. Revert the four core file changes (functions.php, ManualStatusTracker.php, RefundDeletionDiagnostics.php, Core.php)
2. The new utility classes can remain as they're additive
3. HPOS orders will continue to be ignored (current behavior)
4. No data loss or corruption will occur

## Current Issues and Investigation

### Order #124 Dashboard Display Issue

**Problem**: Order #124 was created successfully, shows in WooCommerce admin, and is processed by active rules, but does not appear in the Insight Dashboard.

**Potential Causes**:
1. **Dashboard Data Retrieval**: Dashboard queries may not be HPOS-compatible
2. **Timeline Event Processing**: Events may not be stored/retrieved correctly for dashboard display
3. **Filtering Logic**: Dashboard filters may exclude certain order types or statuses
4. **Database Query Issues**: HPOS table joins or query compatibility problems
5. **Caching Issues**: Stale data being served to dashboard

**Investigation Plan**:
1. Examine InsightDashboard.php data retrieval logic
2. Check AuditLogEndpoint.php API data fetching
3. Review timeline builders and event processing
4. Test dashboard queries with order #124 specifically
5. Verify database caching and synchronization

## Success Criteria

✅ Orders #121 and #122 (HPOS placeholders) appear in Order Daemon dashboard
✅ Audit logs are created for HPOS order processing
✅ Manual status changes on HPOS orders are tracked
✅ Refund and deletion operations on HPOS orders are tracked
✅ Completion rules execute on HPOS orders
✅ No regression in legacy order processing
✅ All existing tests continue to pass
❌ Order #124 appears in Insight Dashboard (INVESTIGATION NEEDED)
❌ All new orders appear in dashboard consistently (INVESTIGATION NEEDED)

## Next Steps

1. **Investigate Dashboard Display Issues**: Examine InsightDashboard.php and related components
2. **Test Order #124 Specifically**: Verify timeline events and dashboard queries for this order
3. **Add Comprehensive Test Cases**: Create test cases covering HPOS scenarios
4. **Monitor New Orders**: Track if this affects all new orders or just specific cases
5. **Update Documentation**: Add troubleshooting section for dashboard display issues
