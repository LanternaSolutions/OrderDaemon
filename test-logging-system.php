<?php
/**
 * Test Logging System Functionality
 * 
 * This script tests why the logging functions return true but don't create database entries.
 */

// Load WordPress
require_once '/var/www/html/wp-load.php';

echo "=== ODCM Logging System Deep Test ===\n\n";

global $wpdb;
$audit_table = $wpdb->prefix . 'odcm_audit_log';
$payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

// Step 1: Test direct database access
echo "1. TESTING DIRECT DATABASE ACCESS...\n";
echo str_repeat('-', 50) . "\n";

echo "Current timestamp: " . current_time('mysql') . "\n";

// Test direct insertion
$test_data = [
    'timestamp' => current_time('mysql'),
    'status' => 'info',
    'summary' => 'Direct test entry',
    'event_type' => 'direct_test',
    'source' => 'debug_script',
    'is_test' => 1,
    'log_category' => 'debug',
    'details' => json_encode(['test' => true, 'method' => 'direct'])
];

echo "Attempting direct database insert...\n";
$result = $wpdb->insert($audit_table, $test_data);
echo "wpdb->insert result: " . ($result !== false ? 'SUCCESS' : 'FAILED') . "\n";

if ($result === false && $wpdb->last_error) {
    echo "Database error: " . $wpdb->last_error . "\n";
}

$insert_id = $wpdb->insert_id;
echo "Insert ID: $insert_id\n";

// Check count
$count_after_direct = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
echo "Count after direct insert: $count_after_direct\n";

if ($count_after_direct > 0) {
    echo "SUCCESS: Direct database insert works!\n";
    $latest = $wpdb->get_row("SELECT * FROM `$audit_table` ORDER BY log_id DESC LIMIT 1", ARRAY_A);
    echo "Latest entry: ID " . $latest['log_id'] . " | " . $latest['summary'] . "\n";
} else {
    echo "FAILED: Direct database insert didn't work\n";
}

// Step 2: Test ProcessLogger in detail
echo "\n\n2. TESTING PROCESSLOGGER IN DETAIL...\n";
echo str_repeat('-', 50) . "\n";

if (class_exists('OrderDaemon\\CompletionManager\\Core\\Logging\\ProcessLogger') && 
    class_exists('OrderDaemon\\CompletionManager\\Core\\Logging\\ComponentSanitizer')) {
    
    echo "Creating ProcessLogger with debugging...\n";
    
    try {
        $sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
        $logger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger($sanitizer);
        
        echo "ProcessLogger instance created\n";
        
        // Enable error reporting for this test
        $old_error_reporting = error_reporting(E_ALL);
        
        echo "Starting process with ProcessLogger...\n";
        $logger->start('debug_test_process', [
            'debug' => true,
            'source' => 'debug_script',
            'timestamp' => microtime(true)
        ]);
        
        echo "Adding component...\n";
        $logger->add_component('info', 'Debug test component', [
            'message' => 'This is a test message',
            'data' => ['test' => true, 'value' => 123]
        ]);
        
        echo "Finishing process...\n";
        $logger->finish('success', 'Debug test completed successfully');
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        
        echo "ProcessLogger operations completed\n";
        
        // Check if anything was written
        $count_after_logger = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
        echo "Count after ProcessLogger: $count_after_logger\n";
        
    } catch (Exception $e) {
        echo "ERROR in ProcessLogger test: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

// Step 3: Test odcm_log_event with detailed debugging
echo "\n\n3. TESTING odcm_log_event WITH DEBUGGING...\n";  
echo str_repeat('-', 50) . "\n";

if (function_exists('odcm_log_event')) {
    echo "Testing odcm_log_event with debug output...\n";
    
    // Enable WordPress debug logging
    if (!defined('WP_DEBUG')) {
        define('WP_DEBUG', true);
    }
    if (!defined('WP_DEBUG_LOG')) {
        define('WP_DEBUG_LOG', true);
    }
    
    echo "Calling odcm_log_event...\n";
    
    $result = odcm_log_event(
        'Test from debug script with detailed context',
        [
            'debug_test' => true,
            'source' => 'debug_script',
            'timestamp' => time(),
            'microtime' => microtime(true),
            'context' => 'testing logging functionality'
        ],
        12345, // Test order ID
        'info',
        'debug_test_event'
    );
    
    echo "odcm_log_event returned: " . var_export($result, true) . "\n";
    
    // Check count immediately after
    $count_after_event = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
    echo "Count after odcm_log_event: $count_after_event\n";
    
    // Check if there are pending transactions or buffering issues
    echo "Forcing wpdb queries to flush...\n";
    $wpdb->flush();
    
    $count_after_flush = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
    echo "Count after flush: $count_after_flush\n";
    
    // Check WordPress debug log for any errors
    $debug_log_path = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($debug_log_path)) {
        echo "WordPress debug log exists. Recent entries:\n";
        $debug_content = file_get_contents($debug_log_path);
        $debug_lines = explode("\n", $debug_content);
        $recent_lines = array_slice($debug_lines, -10); // Last 10 lines
        foreach ($recent_lines as $line) {
            if (!empty(trim($line))) {
                echo "  " . trim($line) . "\n";
            }
        }
    } else {
        echo "No WordPress debug log found at $debug_log_path\n";
    }
}

// Step 4: Check if there are any conditions preventing logging
echo "\n\n4. CHECKING LOGGING CONDITIONS...\n";
echo str_repeat('-', 50) . "\n";

// Check if there are any global flags that might disable logging
$debug_constants = [
    'ODCM_DEBUG',
    'ODCM_DISABLE_LOGGING', 
    'ODCM_TEST_MODE',
    'WP_DEBUG',
    'WP_DEBUG_LOG'
];

foreach ($debug_constants as $constant) {
    $defined = defined($constant);
    $value = $defined ? constant($constant) : 'undefined';
    echo "$constant: " . ($defined ? $value : 'NOT DEFINED') . "\n";
}

// Check relevant options
$options_to_check = [
    'odcm_logging_enabled',
    'odcm_audit_logging_enabled', 
    'odcm_debug',
    'odcm_disable_audit_logging'
];

foreach ($options_to_check as $option) {
    $value = get_option($option, 'not_set');
    echo "$option: " . ($value === 'not_set' ? 'NOT SET' : var_export($value, true)) . "\n";
}

// Step 5: Test with a real order simulation
echo "\n\n5. TESTING WITH REAL ORDER SIMULATION...\n";
echo str_repeat('-', 50) . "\n";

if (class_exists('WooCommerce') && function_exists('wc_create_order')) {
    echo "Creating a real WooCommerce order for testing...\n";
    
    try {
        // Create a test order
        $order = wc_create_order();
        
        if ($order && !is_wp_error($order)) {
            $order_id = $order->get_id();
            echo "Created test order ID: $order_id\n";
            
            // Add a simple product to the order
            if (function_exists('wc_get_products')) {
                $products = wc_get_products(['limit' => 1]);
                if (!empty($products)) {
                    $product = $products[0];
                    $order->add_product($product, 1);
                    echo "Added product to order: " . $product->get_name() . "\n";
                }
            }
            
            // Set order status to trigger hooks
            echo "Setting order status to processing...\n";
            $order->set_status('processing');
            $order->save();
            
            echo "Order saved with status: " . $order->get_status() . "\n";
            
            // Check if any log entries were created
            $count_after_order = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
            echo "Count after order creation: $count_after_order\n";
            
            // Also check for entries with this specific order ID
            $order_entries = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$audit_table` WHERE order_id = %d", $order_id));
            echo "Entries for order $order_id: $order_entries\n";
            
            // Clean up - delete the test order
            $order->delete(true);
            echo "Test order deleted\n";
            
        } else {
            echo "Failed to create test order\n";
        }
        
    } catch (Exception $e) {
        echo "ERROR creating test order: " . $e->getMessage() . "\n";
    }
} else {
    echo "WooCommerce order creation functions not available\n";
}

// Step 6: Final count check
echo "\n\n6. FINAL STATUS CHECK...\n";
echo str_repeat('-', 50) . "\n";

$final_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
echo "Final audit log count: $final_count\n";

if ($final_count > 0) {
    echo "\nSUCCESS: Log entries were created!\n";
    
    // Show all entries
    $all_entries = $wpdb->get_results("SELECT log_id, timestamp, status, event_type, summary, order_id, is_test FROM `$audit_table` ORDER BY timestamp DESC", ARRAY_A);
    foreach ($all_entries as $entry) {
        echo sprintf("Entry: ID %d | %s | %s | %s | Order: %s | Test: %s | %s\n",
            $entry['log_id'],
            $entry['timestamp'],
            $entry['status'],
            $entry['event_type'],
            $entry['order_id'] ?: 'N/A',
            $entry['is_test'] ? 'YES' : 'NO',
            substr($entry['summary'], 0, 50)
        );
    }
} else {
    echo "\nPROBLEM: No log entries were created despite function calls returning true\n";
    echo "This indicates an issue with the logging implementation itself.\n";
}

echo "\n=== TEST COMPLETE ===\n";
