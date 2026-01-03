<?php
/**
 * Check Recent Order Activity
 * 
 * This script checks for recent orders and audit log entries to debug
 * why a real order isn't showing in the insight dashboard.
 */

// Load WordPress
require_once '/var/www/html/wp-load.php';

echo "=== Checking Recent Order Activity ===\n\n";

global $wpdb;
$audit_table = $wpdb->prefix . 'odcm_audit_log';

// Step 1: Check recent orders in WooCommerce
echo "1. CHECKING RECENT WOOCOMMERCE ORDERS...\n";
echo str_repeat('-', 50) . "\n";

if (class_exists('WooCommerce')) {
    echo "WooCommerce is active: " . WC_VERSION . "\n";
    
    // Get recent orders (last 24 hours)
    $recent_orders = wc_get_orders([
        'limit' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
        'date_created' => '>' . (time() - 86400), // Last 24 hours
    ]);
    
    echo "Recent orders (last 24 hours): " . count($recent_orders) . "\n\n";
    
    foreach ($recent_orders as $order) {
        $order_id = $order->get_id();
        $status = $order->get_status();
        $total = $order->get_total();
        $date_created = $order->get_date_created();
        
        echo sprintf("Order %d | Status: %s | Total: %s | Created: %s\n",
            $order_id,
            $status,
            wc_price($total),
            $date_created ? $date_created->format('Y-m-d H:i:s') : 'unknown'
        );
        
        // Check if this order has any audit log entries
        $order_logs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$audit_table` WHERE order_id = %d",
            $order_id
        ));
        
        echo "  └─ Audit log entries for this order: $order_logs\n";
        
        if ($order_logs > 0) {
            $order_entries = $wpdb->get_results($wpdb->prepare(
                "SELECT log_id, timestamp, status, event_type, summary FROM `$audit_table` WHERE order_id = %d ORDER BY timestamp DESC",
                $order_id
            ), ARRAY_A);
            
            foreach ($order_entries as $entry) {
                echo sprintf("     Log %d: %s | %s | %s | %s\n",
                    $entry['log_id'],
                    $entry['timestamp'],
                    $entry['status'],
                    $entry['event_type'],
                    $entry['summary']
                );
            }
        }
        echo "\n";
    }
} else {
    echo "WooCommerce is not active\n";
}

// Step 2: Check all audit log entries
echo "\n2. CHECKING ALL AUDIT LOG ENTRIES...\n";
echo str_repeat('-', 50) . "\n";

$total_logs = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
echo "Total audit log entries: $total_logs\n";

if ($total_logs > 0) {
    // Get all entries
    $all_logs = $wpdb->get_results("SELECT log_id, timestamp, status, event_type, summary, order_id, is_test, process_id FROM `$audit_table` ORDER BY timestamp DESC", ARRAY_A);
    
    echo "\nAll audit log entries:\n";
    foreach ($all_logs as $entry) {
        echo sprintf("ID: %d | %s | %s | %s | Order: %s | Test: %s | Process: %s | %s\n",
            $entry['log_id'],
            $entry['timestamp'],
            $entry['status'],
            $entry['event_type'],
            $entry['order_id'] ?: 'N/A',
            $entry['is_test'] ? 'YES' : 'NO',
            $entry['process_id'] ?: 'N/A',
            substr($entry['summary'], 0, 50)
        );
    }
    
    // Check recent entries (last hour)
    $recent_logs = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `$audit_table` WHERE timestamp > %s",
        date('Y-m-d H:i:s', time() - 3600)
    ));
    echo "\nRecent entries (last hour): $recent_logs\n";
} else {
    echo "No audit log entries found.\n";
}

// Step 3: Check active rules
echo "\n\n3. CHECKING ACTIVE COMPLETION RULES...\n";
echo str_repeat('-', 50) . "\n";

$rules = get_posts([
    'post_type' => 'odcm_order_rule',
    'post_status' => 'publish',
    'numberposts' => -1,
    'meta_query' => [
        [
            'key' => 'odcm_rule_active',
            'value' => '1',
            'compare' => '='
        ]
    ]
]);

echo "Active completion rules: " . count($rules) . "\n";

foreach ($rules as $rule) {
    $rule_id = $rule->ID;
    $rule_name = $rule->post_title;
    $rule_active = get_post_meta($rule_id, 'odcm_rule_active', true);
    
    echo "Rule $rule_id: $rule_name (Active: " . ($rule_active ? 'YES' : 'NO') . ")\n";
    
    // Get rule configuration
    $rule_data = get_post_meta($rule_id, 'odcm_rule_data', true);
    if ($rule_data) {
        $config = json_decode($rule_data, true);
        if ($config) {
            echo "  └─ Triggers: " . count($config['triggers'] ?? []) . "\n";
            echo "  └─ Conditions: " . count($config['conditions'] ?? []) . "\n";
            echo "  └─ Actions: " . count($config['actions'] ?? []) . "\n";
        }
    }
}

// Step 4: Test API with current data
echo "\n\n4. TESTING API WITH CURRENT DATA...\n";
echo str_repeat('-', 50) . "\n";

// Set to admin user
wp_set_current_user(1);

if (class_exists('OrderDaemon\\CompletionManager\\API\\AuditLogEndpoint')) {
    $api_endpoint = new \OrderDaemon\CompletionManager\API\AuditLogEndpoint();
    
    // Test default request
    echo "Testing API default request (should include all non-test entries)...\n";
    $request = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
    $request->set_param('per_page', 20);
    $request->set_param('page', 1);
    
    if ($api_endpoint->check_permissions($request)) {
        $response = $api_endpoint->get_logs($request);
        
        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            echo "API returned " . count($data['logs']) . " logs\n";
            echo "Total: " . ($data['pagination']['total'] ?? 'unknown') . "\n";
            
            if (!empty($data['logs'])) {
                echo "Log entries returned by API:\n";
                foreach ($data['logs'] as $log) {
                    echo sprintf("  API Log %s: %s | %s | %s | Order: %s\n",
                        $log['id'],
                        $log['timestamp'] ?? 'no-time',
                        $log['status'] ?? 'no-status',
                        $log['event_type'] ?? 'no-type',
                        $log['order_id'] ?? 'N/A'
                    );
                }
            }
        }
    }
    
    // Test with include_test=true to see all entries
    echo "\nTesting API with include_test=true (to see all entries including test ones)...\n";
    $request_with_test = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
    $request_with_test->set_param('per_page', 20);
    $request_with_test->set_param('page', 1);
    $request_with_test->set_param('include_test', true);
    
    if ($api_endpoint->check_permissions($request_with_test)) {
        $response_with_test = $api_endpoint->get_logs($request_with_test);
        
        if ($response_with_test instanceof WP_REST_Response) {
            $data_with_test = $response_with_test->get_data();
            echo "API with include_test=true returned " . count($data_with_test['logs']) . " logs\n";
            echo "Total: " . ($data_with_test['pagination']['total'] ?? 'unknown') . "\n";
        }
    }
}

// Step 5: Check WooCommerce order processing hooks and logging
echo "\n\n5. CHECKING ORDER PROCESSING INTEGRATION...\n";
echo str_repeat('-', 50) . "\n";

// Check if Order Daemon hooks are attached to WooCommerce events
$woo_hooks = [
    'woocommerce_checkout_order_processed',
    'woocommerce_order_status_processing', 
    'woocommerce_order_status_completed',
    'woocommerce_payment_complete'
];

echo "Checking WooCommerce hook integration:\n";
foreach ($woo_hooks as $hook) {
    $callbacks = $GLOBALS['wp_filter'][$hook] ?? null;
    if ($callbacks) {
        echo "$hook: HOOKED\n";
        foreach ($callbacks->callbacks as $priority => $callback_group) {
            foreach ($callback_group as $callback) {
                if (is_array($callback['function'])) {
                    $class = is_object($callback['function'][0]) ? get_class($callback['function'][0]) : $callback['function'][0];
                    $method = $callback['function'][1];
                    echo "  └─ Priority $priority: $class::$method\n";
                } else {
                    echo "  └─ Priority $priority: " . $callback['function'] . "\n";
                }
            }
        }
    } else {
        echo "$hook: NOT HOOKED\n";
    }
}

// Check Order Daemon specific options
echo "\nChecking Order Daemon configuration:\n";
$odcm_options = [
    'odcm_logging_enabled',
    'odcm_audit_logging_enabled',
    'odcm_debug',
    'odcm_disable_audit_logging',
    'odcm_plugin_activated'
];

foreach ($odcm_options as $option) {
    $value = get_option($option, 'not_set');
    echo "$option: " . ($value === 'not_set' ? 'NOT SET' : var_export($value, true)) . "\n";
}

echo "\n=== ANALYSIS SUMMARY ===\n";

if ($total_logs == 0) {
    echo "❌ ISSUE: No audit log entries found\n";
    echo "   - The Order Daemon logging system is not capturing order events\n";
    echo "   - This could be due to:\n";
    echo "     • Plugin hooks not properly attached to WooCommerce events\n";  
    echo "     • Logging disabled by configuration\n";
    echo "     • Order processing not triggering the expected hooks\n";
    echo "     • Rule evaluation not running\n";
} else {
    $non_test_logs = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table` WHERE is_test = 0");
    
    if ($non_test_logs == 0) {
        echo "⚠️  ISSUE: Only test entries found\n";
        echo "   - Real orders are not creating audit log entries\n";
        echo "   - The dashboard filters out test entries by default\n";
    } else {
        echo "✅ GOOD: Non-test audit log entries exist\n";
        echo "   - The dashboard should display these entries\n";
        echo "   - If not visible, check frontend JavaScript console for errors\n";
    }
}

echo "\nDashboard URL: " . get_site_url() . "/wp-admin/admin.php?page=odcm-insight-dashboard\n";
