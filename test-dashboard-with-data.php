<?php
/**
 * Test Dashboard with Existing Data
 * 
 * This script tests the dashboard API with the log entries that were just created.
 */

// Load WordPress
require_once '/var/www/html/wp-load.php';

echo "=== ODCM Dashboard Test with Data ===\n\n";

global $wpdb;
$audit_table = $wpdb->prefix . 'odcm_audit_log';

// Step 1: Check current data
echo "1. CHECKING CURRENT DATA...\n";
echo str_repeat('-', 50) . "\n";

$total_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
echo "Total entries in audit log: $total_count\n";

if ($total_count == 0) {
    echo "No entries found. Creating test entries...\n";
    
    // Create a few test entries
    $test_entries = [
        [
            'timestamp' => current_time('mysql'),
            'status' => 'info',
            'summary' => 'Test order processing',
            'event_type' => 'order_processing',
            'source' => 'system',
            'is_test' => 0, // Not a test entry
            'log_category' => 'order',
            'order_id' => 1001,
            'details' => json_encode(['message' => 'Order processed successfully'])
        ],
        [
            'timestamp' => current_time('mysql'),
            'status' => 'success',  
            'summary' => 'Order completed',
            'event_type' => 'order_completed',
            'source' => 'system',
            'is_test' => 0, // Not a test entry
            'log_category' => 'order',
            'order_id' => 1001,
            'details' => json_encode(['message' => 'Order completion successful'])
        ],
        [
            'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'status' => 'info',
            'summary' => 'Rule evaluation',
            'event_type' => 'rule_execution',
            'source' => 'evaluator',
            'is_test' => 0, // Not a test entry
            'log_category' => 'rule',
            'order_id' => 1002,
            'details' => json_encode(['rule_id' => 1, 'result' => 'matched'])
        ]
    ];
    
    foreach ($test_entries as $entry) {
        $wpdb->insert($audit_table, $entry);
        echo "Created entry: " . $entry['summary'] . "\n";
    }
    
    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
    echo "New total: $total_count\n";
}

// Show current entries
$all_entries = $wpdb->get_results("SELECT log_id, timestamp, status, event_type, summary, order_id, is_test FROM `$audit_table` ORDER BY timestamp DESC LIMIT 10", ARRAY_A);

echo "\nCurrent entries:\n";
foreach ($all_entries as $entry) {
    echo sprintf("ID: %d | %s | %s | %s | Order: %s | Test: %s | %s\n",
        $entry['log_id'],
        $entry['timestamp'],
        $entry['status'],
        $entry['event_type'],
        $entry['order_id'] ?: 'N/A',
        $entry['is_test'] ? 'YES' : 'NO',
        $entry['summary']
    );
}

// Step 2: Test API with admin user
echo "\n\n2. TESTING API WITH ADMIN USER...\n";
echo str_repeat('-', 50) . "\n";

// Set current user to admin
wp_set_current_user(1);

$current_user = wp_get_current_user();
echo "Current user ID: " . get_current_user_id() . "\n";
echo "Current user roles: " . implode(', ', $current_user->roles) . "\n";
echo "Has view_woocommerce_reports: " . (current_user_can('view_woocommerce_reports') ? 'YES' : 'NO') . "\n";
echo "Has manage_woocommerce: " . (current_user_can('manage_woocommerce') ? 'YES' : 'NO') . "\n";

if (class_exists('OrderDaemon\\CompletionManager\\API\\AuditLogEndpoint')) {
    $api_endpoint = new \OrderDaemon\CompletionManager\API\AuditLogEndpoint();
    
    // Test 1: Default request (our fixed version should include all entries)
    echo "\nTesting default API request...\n";
    $request = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
    $request->set_param('per_page', 20);
    $request->set_param('page', 1);
    
    $has_permission = $api_endpoint->check_permissions($request);
    echo "Permission check: " . ($has_permission ? 'PASSED' : 'FAILED') . "\n";
    
    if ($has_permission) {
        $response = $api_endpoint->get_logs($request);
        
        if (is_wp_error($response)) {
            echo "API Error: " . $response->get_error_message() . "\n";
        } elseif ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            echo "API Response: " . count($data['logs']) . " logs returned\n";
            echo "Total in pagination: " . ($data['pagination']['total'] ?? 'unknown') . "\n";
            
            if (!empty($data['logs'])) {
                echo "\nSUCCESS: API is returning log entries!\n";
                echo "First entry: " . json_encode($data['logs'][0]) . "\n";
                echo "\nThe dashboard should now display these entries.\n";
            } else {
                echo "PROBLEM: API still returning 0 logs\n";
                echo "Meta info: " . json_encode($data['meta'] ?? []) . "\n";
            }
        }
    }
    
    // Test 2: With explicit include_debug=false, include_test=false
    echo "\n\nTesting API with explicit filters (include_debug=false, include_test=false)...\n";
    $request2 = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
    $request2->set_param('per_page', 20);
    $request2->set_param('page', 1);
    $request2->set_param('include_debug', false);
    $request2->set_param('include_test', false);
    
    if ($api_endpoint->check_permissions($request2)) {
        $response2 = $api_endpoint->get_logs($request2);
        
        if ($response2 instanceof WP_REST_Response) {
            $data2 = $response2->get_data();
            echo "API Response with filters: " . count($data2['logs']) . " logs returned\n";
            echo "Total in pagination: " . ($data2['pagination']['total'] ?? 'unknown') . "\n";
            
            if (count($data2['logs']) < count($data['logs'] ?? [])) {
                echo "GOOD: Filtering is working (fewer entries when filters applied)\n";
            }
        }
    }
    
} else {
    echo "AuditLogEndpoint class not available\n";
}

// Step 3: Test actual HTTP request to simulate frontend
echo "\n\n3. TESTING HTTP REQUEST (SIMULATING FRONTEND)...\n";
echo str_repeat('-', 50) . "\n";

$site_url = get_site_url();
$api_url = $site_url . '/wp-json/odcm/v1/audit-log/';

// Create nonce
$nonce = wp_create_nonce('wp_rest');

// Set up session cookies for admin user
$admin_user = get_user_by('id', 1);
wp_set_current_user($admin_user->ID);
wp_set_auth_cookie($admin_user->ID);

echo "Making HTTP request as admin user...\n";
echo "URL: $api_url\n";
echo "Nonce: $nonce\n";

$response = wp_remote_get($api_url . '?per_page=10&page=1', [
    'headers' => [
        'X-WP-Nonce' => $nonce,
    ],
    'cookies' => [
        'wordpress_logged_in_' . COOKIEHASH => wp_generate_auth_cookie($admin_user->ID, time() + HOUR_IN_SECONDS, 'logged_in')
    ]
]);

if (is_wp_error($response)) {
    echo "HTTP Error: " . $response->get_error_message() . "\n";
} else {
    $response_code = wp_remote_retrieve_response_code($response);
    echo "HTTP Response Code: $response_code\n";
    
    if ($response_code === 200) {
        $response_body = wp_remote_retrieve_body($response);
        $json_data = json_decode($response_body, true);
        
        if ($json_data) {
            echo "JSON Response parsed successfully\n";
            echo "Logs count: " . count($json_data['logs'] ?? []) . "\n";
            echo "Total: " . ($json_data['pagination']['total'] ?? 'unknown') . "\n";
            
            if (!empty($json_data['logs'])) {
                echo "\nSUCCESS: HTTP API returns log entries!\n";
                echo "First entry summary: " . ($json_data['logs'][0]['summary'] ?? 'no summary') . "\n";
                echo "\nThe dashboard should now work correctly.\n";
            } else {
                echo "HTTP API still returning 0 logs\n";
            }
        } else {
            echo "Failed to parse JSON response\n";
            echo "Response body (first 500 chars): " . substr($response_body, 0, 500) . "\n";
        }
    } else {
        echo "HTTP Error: $response_code\n";
        echo "Response: " . wp_remote_retrieve_body($response) . "\n";
    }
}

echo "\n=== DASHBOARD TEST COMPLETE ===\n";
echo "If entries were created successfully, the insight dashboard should now display them.\n";
echo "You can access it at: $site_url/wp-admin/admin.php?page=odcm-insight-dashboard\n";
