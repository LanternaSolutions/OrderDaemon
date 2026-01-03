<?php
/**
 * Test Plugin Status and Logging System
 * 
 * This script checks if the Order Daemon plugin is properly loaded and
 * tests the logging system functionality.
 */

// Load WordPress
require_once '/var/www/html/wp-load.php';

echo "=== ODCM Plugin Status and Logging Test ===\n\n";

// Step 1: Check plugin activation
echo "1. CHECKING PLUGIN ACTIVATION...\n";
echo str_repeat('-', 50) . "\n";

$active_plugins = get_option('active_plugins', []);
$plugin_file = 'order-daemon/order-daemon.php';
$is_active = in_array($plugin_file, $active_plugins);

echo "Plugin file: $plugin_file\n";
echo "Plugin is active: " . ($is_active ? 'YES' : 'NO') . "\n";

if (!$is_active) {
    echo "ERROR: Plugin is not activated!\n";
    echo "Active plugins:\n";
    foreach ($active_plugins as $plugin) {
        echo "  - $plugin\n";
    }
    echo "\nAttempting to activate plugin...\n";
    
    // Try to activate the plugin
    activate_plugin($plugin_file);
    
    // Check again
    $active_plugins = get_option('active_plugins', []);
    $is_now_active = in_array($plugin_file, $active_plugins);
    echo "Plugin activated: " . ($is_now_active ? 'YES' : 'NO') . "\n";
    
    if (!$is_now_active) {
        // Check if plugin file exists
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        echo "Plugin file exists at $plugin_path: " . (file_exists($plugin_path) ? 'YES' : 'NO') . "\n";
        exit(1);
    }
}

// Step 2: Check class loading
echo "\n2. CHECKING CLASS LOADING...\n";
echo str_repeat('-', 50) . "\n";

$classes_to_check = [
    'OrderDaemon\\CompletionManager\\Plugin',
    'OrderDaemon\\CompletionManager\\Core\\Core', 
    'OrderDaemon\\CompletionManager\\Core\\Logging\\ProcessLogger',
    'OrderDaemon\\CompletionManager\\API\\AuditLogEndpoint'
];

foreach ($classes_to_check as $class) {
    echo "$class: " . (class_exists($class) ? 'LOADED' : 'NOT LOADED') . "\n";
}

// Step 3: Check function availability
echo "\n3. CHECKING FUNCTION AVAILABILITY...\n";
echo str_repeat('-', 50) . "\n";

$functions_to_check = [
    'odcm_log_message',
    'odcm_log_event',
    'odcm_can_use'
];

foreach ($functions_to_check as $func) {
    echo "$func: " . (function_exists($func) ? 'EXISTS' : 'NOT EXISTS') . "\n";
}

// Step 4: Check database table structure
echo "\n4. CHECKING DATABASE TABLE STRUCTURE...\n";
echo str_repeat('-', 50) . "\n";

global $wpdb;
$audit_table = $wpdb->prefix . 'odcm_audit_log';

// Show table structure
$columns = $wpdb->get_results("SHOW COLUMNS FROM `$audit_table`", ARRAY_A);

if ($columns) {
    echo "Table structure for $audit_table:\n";
    foreach ($columns as $column) {
        echo sprintf("  %s | %s | %s | %s\n",
            $column['Field'],
            $column['Type'],
            $column['Null'],
            $column['Key']
        );
    }
} else {
    echo "ERROR: Could not get table structure\n";
    if ($wpdb->last_error) {
        echo "Database error: " . $wpdb->last_error . "\n";
    }
}

// Step 5: Test manual log entry creation
echo "\n5. TESTING MANUAL LOG ENTRY CREATION...\n";
echo str_repeat('-', 50) . "\n";

if (function_exists('odcm_log_event')) {
    echo "Creating test log entry using odcm_log_event()...\n";
    
    try {
        $result = odcm_log_event(
            'Manual test log entry from debug script',
            [
                'test' => true,
                'source' => 'debug_script',
                'created_at' => current_time('mysql'),
                'debug_info' => 'Testing log creation functionality'
            ],
            null, // No order ID
            'info',
            'manual_test'
        );
        
        echo "odcm_log_event result: " . var_export($result, true) . "\n";
        
        // Check if entry was created
        $new_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
        echo "Log count after test entry: $new_count\n";
        
        if ($new_count > 0) {
            $latest = $wpdb->get_row("SELECT * FROM `$audit_table` ORDER BY timestamp DESC LIMIT 1", ARRAY_A);
            echo "Latest entry created:\n";
            foreach ($latest as $key => $value) {
                echo "  $key: " . (is_string($value) ? substr($value, 0, 100) : $value) . "\n";
            }
        }
        
    } catch (Exception $e) {
        echo "ERROR creating test log entry: " . $e->getMessage() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
    }
} else {
    echo "odcm_log_event function not available\n";
    
    // Try direct database insertion
    echo "\nTrying direct database insertion...\n";
    
    $data = [
        'timestamp' => current_time('mysql'),
        'status' => 'info',
        'summary' => 'Direct DB test entry',
        'event_type' => 'manual_test',
        'source' => 'debug_script',
        'is_test' => 1,
        'details' => json_encode(['test' => true, 'method' => 'direct_db'])
    ];
    
    $inserted = $wpdb->insert($audit_table, $data);
    
    if ($inserted) {
        echo "Direct DB insertion successful. Insert ID: " . $wpdb->insert_id . "\n";
        $new_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
        echo "New log count: $new_count\n";
    } else {
        echo "Direct DB insertion failed\n";
        if ($wpdb->last_error) {
            echo "Database error: " . $wpdb->last_error . "\n";
        }
    }
}

// Step 6: Test ProcessLogger directly
echo "\n6. TESTING PROCESSLOGGER DIRECTLY...\n";
echo str_repeat('-', 50) . "\n";

if (class_exists('OrderDaemon\\CompletionManager\\Core\\Logging\\ProcessLogger')) {
    echo "ProcessLogger class is available\n";
    
    try {
        // Check if ComponentSanitizer is available
        if (class_exists('OrderDaemon\\CompletionManager\\Core\\Logging\\ComponentSanitizer')) {
            $sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
            $process_logger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger($sanitizer);
            
            echo "Creating ProcessLogger instance...\n";
            
            // Try to start a process and log something
            $process_logger->start('test_process', [
                'source' => 'debug_script',
                'test' => true
            ]);
            
            $process_logger->add_component('info', 'Testing ProcessLogger functionality', [
                'message' => 'This is a test log entry from debug script'
            ]);
            
            $process_logger->finish('success', 'Test process completed');
            
            echo "ProcessLogger test completed\n";
            
            // Check if entries were created
            $new_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
            echo "Log count after ProcessLogger test: $new_count\n";
            
        } else {
            echo "ComponentSanitizer class not available\n";
        }
        
    } catch (Exception $e) {
        echo "ERROR testing ProcessLogger: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
} else {
    echo "ProcessLogger class not available\n";
}

// Step 7: Check for order processing hooks
echo "\n7. CHECKING ORDER PROCESSING HOOKS...\n";
echo str_repeat('-', 50) . "\n";

// Check if WooCommerce hooks are properly set up
$woocommerce_hooks = [
    'woocommerce_order_status_processing',
    'woocommerce_order_status_completed',
    'woocommerce_checkout_order_processed',
    'woocommerce_payment_complete'
];

foreach ($woocommerce_hooks as $hook) {
    $priority_10 = has_action($hook);
    echo "$hook: " . ($priority_10 ? "HOOKED (priority $priority_10)" : 'NOT HOOKED') . "\n";
}

// Step 8: Test order creation simulation
echo "\n8. TESTING ORDER CREATION SIMULATION...\n";
echo str_repeat('-', 50) . "\n";

if (class_exists('WooCommerce')) {
    echo "WooCommerce is available\n";
    echo "WC Version: " . (defined('WC_VERSION') ? WC_VERSION : 'unknown') . "\n";
    
    // Try to trigger order processing manually
    echo "\nTesting order processing hooks...\n";
    
    // Create a test order ID (we'll use a fake one for testing)
    $test_order_id = 99999;
    
    echo "Firing woocommerce_order_status_processing hook for order $test_order_id...\n";
    do_action('woocommerce_order_status_processing', $test_order_id);
    
    echo "Firing woocommerce_checkout_order_processed hook for order $test_order_id...\n";  
    do_action('woocommerce_checkout_order_processed', $test_order_id, [], []);
    
    // Check if anything was logged
    $final_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
    echo "Final log count after order hooks: $final_count\n";
    
    if ($final_count > 0) {
        echo "\nSUCCESS: Log entries were created!\n";
        $latest_entries = $wpdb->get_results("SELECT * FROM `$audit_table` ORDER BY timestamp DESC LIMIT 3", ARRAY_A);
        foreach ($latest_entries as $entry) {
            echo sprintf("Entry: ID %d | %s | %s | %s | Order: %s\n",
                $entry['log_id'],
                $entry['timestamp'],
                $entry['status'],
                $entry['event_type'],
                $entry['order_id'] ?: 'N/A'
            );
        }
    } else {
        echo "WARNING: No log entries were created by order hooks\n";
        echo "This suggests the Order Daemon is not properly hooked into WooCommerce events\n";
    }
    
} else {
    echo "WooCommerce is not available\n";
}

// Step 9: Final API test with data
echo "\n9. FINAL API TEST WITH AVAILABLE DATA...\n";
echo str_repeat('-', 50) . "\n";

$final_audit_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
echo "Final audit table count: $final_audit_count\n";

if ($final_audit_count > 0) {
    echo "Testing API with available data...\n";
    
    // Use admin user context for API test
    wp_set_current_user(1); // Set to admin user
    
    if (class_exists('OrderDaemon\\CompletionManager\\API\\AuditLogEndpoint')) {
        $api_endpoint = new \OrderDaemon\CompletionManager\API\AuditLogEndpoint();
        $test_request = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
        $test_request->set_param('per_page', 20);
        $test_request->set_param('page', 1);
        
        // Test permissions with admin user
        $has_permission = $api_endpoint->check_permissions($test_request);
        echo "API permission check with admin user: " . ($has_permission ? 'PASSED' : 'FAILED') . "\n";
        
        if ($has_permission) {
            $api_response = $api_endpoint->get_logs($test_request);
            
            if ($api_response instanceof WP_REST_Response) {
                $response_data = $api_response->get_data();
                echo "API returned " . count($response_data['logs']) . " logs\n";
                echo "Total: " . ($response_data['pagination']['total'] ?? 'unknown') . "\n";
                
                if (!empty($response_data['logs'])) {
                    echo "\nSUCCESS: Dashboard should now show log entries!\n";
                } else {
                    echo "WARNING: API still returning 0 logs despite database entries\n";
                }
            }
        }
    }
} else {
    echo "No audit log entries available for API testing\n";
}

echo "\n=== TEST COMPLETE ===\n";
