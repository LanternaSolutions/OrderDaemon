# Order #121 Placeholder Issue - Comprehensive Analysis

## Executive Summary

Order #121 (and #122) exist in the WooCommerce database but do not appear in the Order Daemon insight dashboard because they were created as `shop_order_placehold` post types instead of standard `shop_order` post types. This issue represents a breakdown in the order creation process where orders are being created as placeholders rather than real, processable orders.

## Issue Details

### Symptoms
- Order #121 exists in WooCommerce UI and shows "automatically completed by active rule" in order notes
- Order #121 does NOT appear in Order Daemon insight dashboard
- Order #121 has NO audit log entries in the `wp_odcm_audit_log` table
- Active completion rule appears to be working (order notes indicate processing)

### Root Cause Analysis

#### Database Analysis
```sql
-- Real orders (only 2 exist)
SELECT ID, post_title, post_status, post_type
FROM wp_posts
WHERE post_type = 'shop_order'
ORDER BY ID DESC LIMIT 10;
-- Result: IDs 14, 18 (both from November 2025, wc-completed status)

-- Placeholder orders (recent)
SELECT ID, post_title, post_status, post_type
FROM wp_posts
WHERE ID IN (121, 122)
ORDER BY ID;
-- Result:
-- ID 121: draft status, shop_order_placehold type, created 2026-01-02 19:07:05
-- ID 122: draft status, shop_order_placehold type, created 2026-01-02 21:10:28

-- Audit log check
SELECT COUNT(*) FROM wp_odcm_audit_log WHERE order_id = 121;
-- Result: 0 (no audit logs exist for this order)
````

#### Order Daemon Processing Logic

The Order Daemon plugin only processes orders with:

1. __Post Type:__ `shop_order` (not `shop_order_placehold`)
2. __Status:__ Processing/on-hold/completed (not `draft`)
3. __Trigger:__ `order_processing` status change

Since orders #121 and #122 are `shop_order_placehold` with `draft` status:

- They are ignored by the completion rule system
- No audit logs are created
- They don't appear in the insight dashboard

#### Active Completion Rule

```json
{
  "trigger": {
    "id": "order_processing",
    "settings": []
  },
  "conditions": [
    {
      "id": "product_type",
      "settings": {
        "types": ["virtual"]
      }
    }
  ],
  "primaryAction": {
    "id": "change_status_to_completed",
    "settings": []
  },
  "secondaryActions": []
}
```

- __Trigger:__ Fires on `order_processing` status (not `draft`)
- __Condition:__ Applies to virtual products
- __Action:__ Changes status to `completed`

### Timeline of Events

#### Before Today

- System working normally
- Real orders created as `shop_order` post type
- Completion rules processing orders correctly
- Audit logs being created properly
- Orders appearing in insight dashboard

#### Today (Issue Emerged)

- Orders #121 and #122 created as `shop_order_placehold` instead of `shop_order`
- Orders show in WooCommerce UI with completion notes (suggesting partial processing)
- No audit logs created
- Orders missing from insight dashboard
- User noticed the discrepancy

### Technical Analysis

#### Post Type Comparison

| Aspect | Standard Order | Placeholder Order | |--------|----------------|-------------------| | Post Type | `shop_order` | `shop_order_placehold` | | Status | `processing`, `completed` | `draft` | | Creation | Standard WooCommerce flow | Unknown process | | Processing | Processed by Order Daemon | Ignored by Order Daemon | | Audit Logs | Created normally | No logs created | | Dashboard | Appears in insight dashboard | Missing from dashboard |

#### Database Query Analysis

The Order Daemon uses proper parameterized queries:

```php
// Example from AuditLogEndpoint.php
$where_clauses[] = "l.event_type NOT LIKE %s";
$where_params[] = 'debug_%';

// No issues with placeholder syntax in SQL queries
// "placeholder" references are SQL parameter placeholders, not order types
```

### Hypotheses

#### Hypothesis 1: Test Code Creation

- One of the test scripts (`test-logging-system.php`, etc.) created placeholder orders
- Tests use `wc_create_order()` but might have modified post type
- Test cleanup failed or was incomplete

#### Hypothesis 2: Database Corruption

- Database state changed unexpectedly
- Post type field corrupted for recent orders
- Index or constraint issue affecting order creation

#### Hypothesis 3: Integration Issue

- Another plugin interfering with order creation
- Custom code modifying post type during creation
- Hook or filter changing order post type to placeholder

#### Hypothesis 4: Code Regression

- Recent code change affected order creation
- Order creation process modified incorrectly
- Post type assignment broken in recent commit

### Debugging Steps Completed

1. ✅ __Confirmed order existence and type__ - Orders #121, #122 exist as `shop_order_placehold`
2. ✅ __Verified audit log absence__ - No logs for placeholder orders
3. ✅ __Checked real order processing__ - Only 2 real orders exist (IDs 14, 18)
4. ✅ __Examined completion rule__ - Rule works but only on real orders
5. ✅ __Analyzed database queries__ - No SQL placeholder syntax issues
6. ✅ __Reviewed test files__ - No explicit `shop_order_placehold` creation found

### Recommended Next Steps

#### Immediate Actions

1. __Convert placeholder orders to real orders__ (if they should be real):

   ```sql
   UPDATE wp_posts
   SET post_type = 'shop_order', post_status = 'processing'
   WHERE ID IN (121, 122)
   AND post_type = 'shop_order_placehold';
   ```

2. __Manually trigger processing__ for converted orders:

   ```php
   $core = new \OrderDaemon\CompletionManager\Core\Core();
   $core->schedule_completion_check(121);
   $core->schedule_completion_check(122);
   ```

#### Investigation Actions

1. __Check test execution logs__ - See which tests ran recently
2. __Examine order creation hooks__ - Find where `shop_order_placehold` is set
3. __Review recent code changes__ - Look for modifications to order creation
4. __Test order creation process__ - Verify new orders are created correctly

#### Prevention Actions

1. __Add validation__ - Ensure orders are created with correct post type
2. __Improve error handling__ - Detect and handle placeholder orders
3. __Enhance logging__ - Log when non-standard post types are created
4. __Add monitoring__ - Alert on unusual order post types

### Code References

#### Order Creation (Standard)

```php
// From test-logging-system.php
$order = wc_create_order();
if ($order && !is_wp_error($order)) {
    $order_id = $order->get_id();
    // Standard orders should have 'shop_order' post type
}
```

#### Completion Rule Processing

```php
// From src/Core/Core.php
public function handle_order_status_change(int $order_id): void {
    // Only processes orders that reach processing/on-hold status
    // Ignores draft orders and placeholder post types
}
```

#### Audit Log Query

```php
// From src/API/AuditLogEndpoint.php
public function get_logs(WP_REST_Request $request): WP_REST_Response {
    // Queries only process real orders with proper post types
    // Placeholder orders are excluded from results
}
```

### Expected Behavior vs Actual Behavior

| Process | Expected | Actual | |---------|----------|-------| | Order Creation | Creates `shop_order` post type | Creates `shop_order_placehold` post type | | Rule Processing | Processes all `shop_order` orders | Ignores `shop_order_placehold` orders | | Audit Logging | Creates logs for all processed orders | No logs for placeholder orders | | Dashboard Display | Shows all processed orders | Missing placeholder orders | | Order Notes | Shows processing status | Shows "completed by rule" despite no processing |

### Resolution Path

1. __Short-term:__ Convert existing placeholder orders to real orders and reprocess them
2. __Medium-term:__ Fix the order creation process to prevent placeholder orders
3. __Long-term:__ Add validation and monitoring to detect future issues

### Documentation for Future Reference

This document serves as a comprehensive analysis of the placeholder order issue. It can be used to:

- Continue debugging in new coding sessions
- Onboard new developers to the issue
- Reference the root cause and solution approach
- Monitor for similar issues in the future

## Appendix: SQL Queries for Debugging

```sql
-- Check all recent orders (all types)
SELECT ID, post_title, post_status, post_type, post_date
FROM wp_posts
WHERE post_type LIKE '%order%'
ORDER BY post_date DESC
LIMIT 20;

-- Check audit logs for specific order
SELECT * FROM wp_odcm_audit_log
WHERE order_id = 121
ORDER BY timestamp DESC;

-- Check order meta for processing status
SELECT meta_key, meta_value
FROM wp_postmeta
WHERE post_id = 121
AND meta_key LIKE '%odcm%';

-- Check action scheduler for pending order processing
SELECT * FROM wp_actionscheduler_actions
WHERE hook = 'odcm_process_order_check'
AND status IN ('pending', 'in-progress');
```

## Appendix: PHP Code for Manual Processing

```php
// Convert placeholder order to real order
function fix_placeholder_order($order_id) {
    global $wpdb;

    // Convert post type
    $wpdb->update(
        $wpdb->posts,
        ['post_type' => 'shop_order', 'post_status' => 'processing'],
        ['ID' => $order_id, 'post_type' => 'shop_order_placehold']
    );

    // Clear any placeholder-specific meta
    $wpdb->delete(
        $wpdb->postmeta,
        ['post_id' => $order_id, 'meta_key' => '_placeholder_order']
    );

    // Schedule for processing
    if (class_exists('\OrderDaemon\CompletionManager\Core\Core')) {
        $core = new \OrderDaemon\CompletionManager\Core\Core();
        return $core->schedule_completion_check($order_id);
    }

    return false;
}

// Process all placeholder orders
function process_all_placeholder_orders() {
    global $wpdb;

    $placeholder_orders = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts}
        WHERE post_type = 'shop_order_placehold'
        AND post_status = 'draft'"
    );

    foreach ($placeholder_orders as $order_id) {
        echo "Processing placeholder order #$order_id...\n";
        $result = fix_placeholder_order($order_id);
        echo "Result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
    }

    return count($placeholder_orders);
}
```

This comprehensive document provides a complete analysis of the Order #121 placeholder issue and can be used as a reference for continuing the debugging process in future coding sessions.


# Order #121 Placeholder Issue - Comprehensive Analysis

## Executive Summary

Order #121 (and #122) exist in the WooCommerce database but do not appear in the Order Daemon insight dashboard because they were created as `shop_order_placehold` post types instead of standard `shop_order` post types. This issue represents a breakdown in the order creation process where orders are being created as placeholders rather than real, processable orders.

## Issue Details

### Symptoms
- Order #121 exists in WooCommerce UI and shows "automatically completed by active rule" in order notes
- Order #121 does NOT appear in Order Daemon insight dashboard
- Order #121 has NO audit log entries in the `wp_odcm_audit_log` table
- Active completion rule appears to be working (order notes indicate processing)

### Root Cause Analysis

#### Database Analysis
```sql
-- Real orders (only 2 exist)
SELECT ID, post_title, post_status, post_type
FROM wp_posts
WHERE post_type = 'shop_order'
ORDER BY ID DESC LIMIT 10;
-- Result: IDs 14, 18 (both from November 2025, wc-completed status)

-- Placeholder orders (recent)
SELECT ID, post_title, post_status, post_type
FROM wp_posts
WHERE ID IN (121, 122)
ORDER BY ID;
-- Result:
-- ID 121: draft status, shop_order_placehold type, created 2026-01-02 19:07:05
-- ID 122: draft status, shop_order_placehold type, created 2026-01-02 21:10:28

-- Audit log check
SELECT COUNT(*) FROM wp_odcm_audit_log WHERE order_id = 121;
-- Result: 0 (no audit logs exist for this order)
````

#### Order Daemon Processing Logic

The Order Daemon plugin only processes orders with:

1. __Post Type:__ `shop_order` (not `shop_order_placehold`)
2. __Status:__ Processing/on-hold/completed (not `draft`)
3. __Trigger:__ `order_processing` status change

Since orders #121 and #122 are `shop_order_placehold` with `draft` status:

- They are ignored by the completion rule system
- No audit logs are created
- They don't appear in the insight dashboard

#### Active Completion Rule

```json
{
  "trigger": {
    "id": "order_processing",
    "settings": []
  },
  "conditions": [
    {
      "id": "product_type",
      "settings": {
        "types": ["virtual"]
      }
    }
  ],
  "primaryAction": {
    "id": "change_status_to_completed",
    "settings": []
  },
  "secondaryActions": []
}
```

- __Trigger:__ Fires on `order_processing` status (not `draft`)
- __Condition:__ Applies to virtual products
- __Action:__ Changes status to `completed`

### Timeline of Events

#### Before Today

- System working normally
- Real orders created as `shop_order` post type
- Completion rules processing orders correctly
- Audit logs being created properly
- Orders appearing in insight dashboard

#### Today (Issue Emerged)

- Orders #121 and #122 created as `shop_order_placehold` instead of `shop_order`
- Orders show in WooCommerce UI with completion notes (suggesting partial processing)
- No audit logs created
- Orders missing from insight dashboard
- User noticed the discrepancy

### Technical Analysis

#### Post Type Comparison

| Aspect | Standard Order | Placeholder Order | |--------|----------------|-------------------| | Post Type | `shop_order` | `shop_order_placehold` | | Status | `processing`, `completed` | `draft` | | Creation | Standard WooCommerce flow | Unknown process | | Processing | Processed by Order Daemon | Ignored by Order Daemon | | Audit Logs | Created normally | No logs created | | Dashboard | Appears in insight dashboard | Missing from dashboard |

#### Database Query Analysis

The Order Daemon uses proper parameterized queries:

```php
// Example from AuditLogEndpoint.php
$where_clauses[] = "l.event_type NOT LIKE %s";
$where_params[] = 'debug_%';

// No issues with placeholder syntax in SQL queries
// "placeholder" references are SQL parameter placeholders, not order types
```

### Hypotheses

#### Hypothesis 1: Test Code Creation

- One of the test scripts (`test-logging-system.php`, etc.) created placeholder orders
- Tests use `wc_create_order()` but might have modified post type
- Test cleanup failed or was incomplete

#### Hypothesis 2: Database Corruption

- Database state changed unexpectedly
- Post type field corrupted for recent orders
- Index or constraint issue affecting order creation

#### Hypothesis 3: Integration Issue

- Another plugin interfering with order creation
- Custom code modifying post type during creation
- Hook or filter changing order post type to placeholder

#### Hypothesis 4: Code Regression

- Recent code change affected order creation
- Order creation process modified incorrectly
- Post type assignment broken in recent commit

### Debugging Steps Completed

1. ✅ __Confirmed order existence and type__ - Orders #121, #122 exist as `shop_order_placehold`
2. ✅ __Verified audit log absence__ - No logs for placeholder orders
3. ✅ __Checked real order processing__ - Only 2 real orders exist (IDs 14, 18)
4. ✅ __Examined completion rule__ - Rule works but only on real orders
5. ✅ __Analyzed database queries__ - No SQL placeholder syntax issues
6. ✅ __Reviewed test files__ - No explicit `shop_order_placehold` creation found

### Recommended Next Steps

#### Immediate Actions

1. __Convert placeholder orders to real orders__ (if they should be real):

   ```sql
   UPDATE wp_posts
   SET post_type = 'shop_order', post_status = 'processing'
   WHERE ID IN (121, 122)
   AND post_type = 'shop_order_placehold';
   ```

2. __Manually trigger processing__ for converted orders:

   ```php
   $core = new \OrderDaemon\CompletionManager\Core\Core();
   $core->schedule_completion_check(121);
   $core->schedule_completion_check(122);
   ```

#### Investigation Actions

1. __Check test execution logs__ - See which tests ran recently
2. __Examine order creation hooks__ - Find where `shop_order_placehold` is set
3. __Review recent code changes__ - Look for modifications to order creation
4. __Test order creation process__ - Verify new orders are created correctly

#### Prevention Actions

1. __Add validation__ - Ensure orders are created with correct post type
2. __Improve error handling__ - Detect and handle placeholder orders
3. __Enhance logging__ - Log when non-standard post types are created
4. __Add monitoring__ - Alert on unusual order post types

### Code References

#### Order Creation (Standard)

```php
// From test-logging-system.php
$order = wc_create_order();
if ($order && !is_wp_error($order)) {
    $order_id = $order->get_id();
    // Standard orders should have 'shop_order' post type
}
```

#### Completion Rule Processing

```php
// From src/Core/Core.php
public function handle_order_status_change(int $order_id): void {
    // Only processes orders that reach processing/on-hold status
    // Ignores draft orders and placeholder post types
}
```

#### Audit Log Query

```php
// From src/API/AuditLogEndpoint.php
public function get_logs(WP_REST_Request $request): WP_REST_Response {
    // Queries only process real orders with proper post types
    // Placeholder orders are excluded from results
}
```

### Expected Behavior vs Actual Behavior

| Process | Expected | Actual | |---------|----------|-------| | Order Creation | Creates `shop_order` post type | Creates `shop_order_placehold` post type | | Rule Processing | Processes all `shop_order` orders | Ignores `shop_order_placehold` orders | | Audit Logging | Creates logs for all processed orders | No logs for placeholder orders | | Dashboard Display | Shows all processed orders | Missing placeholder orders | | Order Notes | Shows processing status | Shows "completed by rule" despite no processing |

### Resolution Path

1. __Short-term:__ Convert existing placeholder orders to real orders and reprocess them
2. __Medium-term:__ Fix the order creation process to prevent placeholder orders
3. __Long-term:__ Add validation and monitoring to detect future issues

### Documentation for Future Reference

This document serves as a comprehensive analysis of the placeholder order issue. It can be used to:

- Continue debugging in new coding sessions
- Onboard new developers to the issue
- Reference the root cause and solution approach
- Monitor for similar issues in the future

## Appendix: SQL Queries for Debugging

```sql
-- Check all recent orders (all types)
SELECT ID, post_title, post_status, post_type, post_date
FROM wp_posts
WHERE post_type LIKE '%order%'
ORDER BY post_date DESC
LIMIT 20;

-- Check audit logs for specific order
SELECT * FROM wp_odcm_audit_log
WHERE order_id = 121
ORDER BY timestamp DESC;

-- Check order meta for processing status
SELECT meta_key, meta_value
FROM wp_postmeta
WHERE post_id = 121
AND meta_key LIKE '%odcm%';

-- Check action scheduler for pending order processing
SELECT * FROM wp_actionscheduler_actions
WHERE hook = 'odcm_process_order_check'
AND status IN ('pending', 'in-progress');
```

## Appendix: PHP Code for Manual Processing

```php
// Convert placeholder order to real order
function fix_placeholder_order($order_id) {
    global $wpdb;

    // Convert post type
    $wpdb->update(
        $wpdb->posts,
        ['post_type' => 'shop_order', 'post_status' => 'processing'],
        ['ID' => $order_id, 'post_type' => 'shop_order_placehold']
    );

    // Clear any placeholder-specific meta
    $wpdb->delete(
        $wpdb->postmeta,
        ['post_id' => $order_id, 'meta_key' => '_placeholder_order']
    );

    // Schedule for processing
    if (class_exists('\OrderDaemon\CompletionManager\Core\Core')) {
        $core = new \OrderDaemon\CompletionManager\Core\Core();
        return $core->schedule_completion_check($order_id);
    }

    return false;
}

// Process all placeholder orders
function process_all_placeholder_orders() {
    global $wpdb;

    $placeholder_orders = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts}
        WHERE post_type = 'shop_order_placehold'
        AND post_status = 'draft'"
    );

    foreach ($placeholder_orders as $order_id) {
        echo "Processing placeholder order #$order_id...\n";
        $result = fix_placeholder_order($order_id);
        echo "Result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
    }

    return count($placeholder_orders);
}
```

This comprehensive document provides a complete analysis of the Order #121 placeholder issue and can be used as a reference for continuing the debugging process in future coding sessions.

## Appendix: Docker Debugging Commands

### Container Information
```bash
# List all running containers
docker ps

# WordPress container (for WP-CLI access)
docker exec order-daemon-devtools-wordpress-1 [command]

# Cron container (for WP-CLI access)
docker exec order-daemon-devtools-cron-1 [command]

# Database container (for direct SQL queries)
docker exec order-daemon-devtools-db-1 [command]
```

### Database Queries via Docker

```bash
# Check order #121 details
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT ID, post_title, post_status, post_type FROM wp_posts WHERE ID = 121;'"

# Check all recent orders
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT ID, post_title, post_status, post_type FROM wp_posts WHERE post_type LIKE \"%order%\" ORDER BY ID DESC LIMIT 10;'"

# Check audit logs for order #121
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT * FROM wp_odcm_audit_log WHERE order_id = 121;'"

# Count audit logs for order #121
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT COUNT(*) FROM wp_odcm_audit_log WHERE order_id = 121;'"
```

### WordPress CLI via Docker

```bash
# List all orders (using cron container with WP-CLI)
docker exec order-daemon-devtools-cron-1 wp post list --post_type=shop_order --per_page=100

# List placeholder orders
docker exec order-daemon-devtools-cron-1 wp post list --post_type=shop_order_placehold --per_page=100

# Get order details
docker exec order-daemon-devtools-cron-1 wp post get 121 --field=post_type
docker exec order-daemon-devtools-cron-1 wp post get 121 --field=post_status
```

### API Testing via Docker

```bash
# Test diagnostic endpoint
curl -s "http://localhost:8082/wp-json/odcm/v1/audit-log/diagnostic" | jq .

# Test audit log endpoint (requires authentication)
curl -s "http://localhost:8082/wp-json/odcm/v1/audit-log/?per_page=100" | jq .

# Check specific order via API
curl -s "http://localhost:8082/wp-json/wc/v3/orders/121" | jq .
```

### Debugging Workflow

1. **Check container status:**

   ```bash
   docker ps
   ```

2. **Verify database connectivity:**

   ```bash
   docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SHOW TABLES;'"
   ```

3. **Examine order creation:**

   ```bash
   docker exec order-daemon-devtools-cron-1 wp post list --post_type=shop_order --per_page=100
   docker exec order-daemon-devtools-cron-1 wp post list --post_type=shop_order_placehold --per_page=100
   ```

4. **Check audit logs:**

   ```bash
   docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT COUNT(*) as total_logs FROM wp_odcm_audit_log;'"
   ```

5. **Test order processing:**

   ```bash
   # Check if order exists in WooCommerce
   docker exec order-daemon-devtools-cron-1 wp post get 121

   # Check order meta
   docker exec order-daemon-devtools-cron-1 wp post meta list 121
   ```

### Fixing Placeholder Orders via Docker

```bash
# Convert placeholder order to real order (manual SQL)
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'UPDATE wp_posts SET post_type = \"shop_order\", post_status = \"processing\" WHERE ID = 121 AND post_type = \"shop_order_placehold\";'"

# Verify conversion
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT ID, post_type, post_status FROM wp_posts WHERE ID = 121;'"
```

### Monitoring and Verification

```bash
# Check if order appears in audit logs after processing
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT COUNT(*) FROM wp_odcm_audit_log WHERE order_id = 121;'"

# Check dashboard API response
curl -s "http://localhost:8082/wp-json/odcm/v1/audit-log/?per_page=100&order_id=121" | jq '.logs | length'
```

### Common Issues and Solutions

**Issue: WP-CLI not found in container**

```bash
# Use the cron container which has WP-CLI installed
docker exec order-daemon-devtools-cron-1 wp --info
```

**Issue: MySQL access denied**

```bash
# Use correct credentials (wordpress/wordpress)
docker exec order-daemon-devtools-db-1 sh -c "mysql -u wordpress -pwordpress wordpress -e 'SELECT VERSION();'"
```

**Issue: No output from commands**

```bash
# Try alternative syntax
docker exec -i order-daemon-devtools-db-1 mysql -u wordpress -pwordpress wordpress -e "SELECT * FROM wp_odcm_audit_log LIMIT 5;"
```

**Issue: Order still not appearing after conversion**

```bash
# Manually trigger processing
docker exec order-daemon-devtools-wordpress-1 wp eval "do_action('woocommerce_order_status_processing', 121);"
```
