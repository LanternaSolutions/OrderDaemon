<?php
/**
 * Debug Script for Insight Dashboard Issue
 * 
 * This script runs within the WordPress environment to debug why the insight dashboard
 * shows 0 log entries despite having confirmed entries in the audit log tables.
 */

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    // Try to find WordPress installation
    $wp_load_paths = [
        '/var/www/html/wp-load.php',           // Docker standard
        __DIR__ . '/../../../wp-load.php',    // Standard plugin structure
        __DIR__ . '/../../../../wp-load.php', // Alternative structure
        getcwd() . '/wp-load.php',             // Current directory
    ];
    
    $wp_loaded = false;
    foreach ($wp_load_paths as $wp_load) {
        if (file_exists($wp_load)) {
            require_once $wp_load;
            $wp_loaded = true;
            echo "Loaded WordPress from: $wp_load\n\n";
            break;
        }
    }
    
    if (!$wp_loaded) {
        echo "Error: Could not find WordPress installation.\n";
        echo "Tried paths:\n";
        foreach ($wp_load_paths as $path) {
            echo "  $path\n";
        }
        exit(1);
    }
}

echo "=== ODCM Insight Dashboard Debug Test ===\n\n";

// Step 1: Check database tables and data
echo "1. CHECKING DATABASE TABLES...\n";
echo str_repeat('-', 50) . "\n";

global $wpdb;
$audit_table = $wpdb->prefix . 'odcm_audit_log';
$payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

// Check if tables exist
$audit_exists = $wpdb->get_var("SHOW TABLES LIKE '$audit_table'") === $audit_table;
$payload_exists = $wpdb->get_var("SHOW TABLES LIKE '$payload_table'") === $payload_table;

echo "Audit table ($audit_table): " . ($audit_exists ? "EXISTS" : "MISSING") . "\n";
echo "Payload table ($payload_table): " . ($payload_exists ? "EXISTS" : "MISSING") . "\n";

if (!$audit_exists) {
    echo "ERROR: Audit log table is missing! The plugin may not be properly installed.\n";
    exit(1);
}

// Get basic stats
$total = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table");
$debug_count = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table WHERE status = 'debug' OR event_type LIKE 'debug_%'");
$test_count = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table WHERE is_test = 1");
$non_debug_test = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table WHERE status != 'debug' AND event_type NOT LIKE 'debug_%' AND is_test = 0");

echo "\nDatabase Statistics:\n";
echo "Total entries: $total\n";
echo "Debug entries: $debug_count\n";
echo "Test entries: $test_count\n";
echo "Non-debug, non-test entries: $non_debug_test\n";

// Get recent entries
$recent = $wpdb->get_results("SELECT log_id, timestamp, status, event_type, summary, order_id, is_test, process_id FROM $audit_table ORDER BY timestamp DESC LIMIT 10", ARRAY_A);

echo "\nRecent entries:\n";
foreach ($recent as $entry) {
    echo sprintf("ID: %d | %s | %s | %s | Order: %s | Test: %s | Process: %s | %s\n",
        $entry['log_id'],
        $entry['timestamp'], 
        $entry['status'],
        $entry['event_type'],
        $entry['order_id'] ?: 'N/A',
        $entry['is_test'] ? 'YES' : 'NO',
        $entry['process_id'] ?: 'N/A',
        substr($entry['summary'], 0, 40)
    );
}

// Step 2: Test the API endpoint directly
echo "\n\n2. TESTING API ENDPOINT DIRECTLY...\n";
echo str_repeat('-', 50) . "\n";

// Create a mock WP_REST_Request to test the endpoint
if (class_exists('WP_REST_Request')) {
    require_once ABSPATH . WPINC . '/rest-api.php';
    
    // Load the OrderDaemon API classes
    if (file_exists(WP_PLUGIN_DIR . '/order-daemon/src/API/AuditLogEndpoint.php')) {
        require_once WP_PLUGIN_DIR . '/order-daemon/src/API/AuditLogEndpoint.php';
        
        try {
            // Create API endpoint instance
            $endpoint = new \OrderDaemon\CompletionManager\API\AuditLogEndpoint();
            
            // Test 1: Default request (should include all entries)
            echo "Testing default API request (no filters)...\n";
            $request = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
            $request->set_param('per_page', 20);
            $request->set_param('page', 1);
            
            // Enable debug logging for this test
            if (!defined('ODCM_DEBUG')) {
                define('ODCM_DEBUG', true);
            }
            
            $response = $endpoint->get_logs($request);
            
            if (is_wp_error($response)) {
                echo "ERROR: API returned WP_Error: " . $response->get_error_message() . "\n";
            } elseif ($response instanceof WP_REST_Response) {
                $data = $response->get_data();
                echo "API Response: " . count($data['logs']) . " logs returned\n";
                echo "Total in pagination: " . ($data['pagination']['total'] ?? 'unknown') . "\n";
                echo "Applied filters: " . json_encode($data['filters'] ?? []) . "\n";
                
                if (!empty($data['logs'])) {
                    echo "\nFirst few entries from API:\n";
                    foreach (array_slice($data['logs'], 0, 3) as $log) {
                        echo sprintf("  API Log ID: %s | %s | %s | %s\n",
                            $log['id'],
                            $log['timestamp'] ?? 'no-timestamp',
                            $log['status'] ?? 'no-status', 
                            substr($log['summary'] ?? 'no-summary', 0, 30)
                        );
                    }
                } else {
                    echo "No logs returned from API!\n";
                }
            } else {
                echo "Unexpected response type: " . gettype($response) . "\n";
            }
            
            // Test 2: Explicit include_debug and include_test true
            echo "\n\nTesting API request with explicit include_debug=true, include_test=true...\n";
            $request2 = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
            $request2->set_param('per_page', 20);
            $request2->set_param('page', 1);
            $request2->set_param('include_debug', true);
            $request2->set_param('include_test', true);
            
            $response2 = $endpoint->get_logs($request2);
            
            if (is_wp_error($response2)) {
                echo "ERROR: API returned WP_Error: " . $response2->get_error_message() . "\n";
            } elseif ($response2 instanceof WP_REST_Response) {
                $data2 = $response2->get_data();
                echo "API Response with debug/test=true: " . count($data2['logs']) . " logs returned\n";
                echo "Total in pagination: " . ($data2['pagination']['total'] ?? 'unknown') . "\n";
            }
            
            // Test 3: Explicit include_debug and include_test false
            echo "\n\nTesting API request with explicit include_debug=false, include_test=false...\n";
            $request3 = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
            $request3->set_param('per_page', 20);
            $request3->set_param('page', 1);
            $request3->set_param('include_debug', false);
            $request3->set_param('include_test', false);
            
            $response3 = $endpoint->get_logs($request3);
            
            if (is_wp_error($response3)) {
                echo "ERROR: API returned WP_Error: " . $response3->get_error_message() . "\n";
            } elseif ($response3 instanceof WP_REST_Response) {
                $data3 = $response3->get_data();
                echo "API Response with debug/test=false: " . count($data3['logs']) . " logs returned\n";
                echo "Total in pagination: " . ($data3['pagination']['total'] ?? 'unknown') . "\n";
            }
            
        } catch (Exception $e) {
            echo "ERROR: Exception while testing API: " . $e->getMessage() . "\n";
            echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        }
    } else {
        echo "ERROR: Could not find AuditLogEndpoint.php file\n";
    }
} else {
    echo "ERROR: WP_REST_Request class not available\n";
}

// Step 3: Test database queries directly
echo "\n\n3. TESTING DATABASE QUERIES DIRECTLY...\n";
echo str_repeat('-', 50) . "\n";

// Test the exact query that would be used by the API
echo "Testing direct database query (simulating API default behavior)...\n";

// Simulate the new logic where include_debug and include_test default to true
$where_clauses = [];

// With our fix, when no parameters are provided, these should default to true
$include_debug = true; // This is the fix we made
$include_test = true;   // This is the fix we made

echo "include_debug = " . ($include_debug ? 'true' : 'false') . "\n";
echo "include_test = " . ($include_test ? 'true' : 'false') . "\n";

// Build WHERE clauses (simulating the fixed logic)
if (!$include_debug) {
    $where_clauses[] = "l.event_type NOT LIKE 'debug_%'";
    $where_clauses[] = "l.status != 'debug'";
}

if (!$include_test) {
    $where_clauses[] = "l.is_test = 0";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

echo "Generated WHERE clause: " . ($where_sql ?: '(none - showing all)') . "\n";

$query = "SELECT COUNT(*) FROM `$audit_table` l $where_sql";
$count = $wpdb->get_var($query);

echo "Query: $query\n";
echo "Count result: $count\n";

if ($wpdb->last_error) {
    echo "Database error: " . $wpdb->last_error . "\n";
}

// Test with actual data retrieval
$data_query = "SELECT log_id, timestamp, status, event_type, summary, order_id, is_test, process_id FROM `$audit_table` l $where_sql ORDER BY timestamp DESC LIMIT 5";
$sample_data = $wpdb->get_results($data_query, ARRAY_A);

echo "\nSample data from query:\n";
foreach ($sample_data as $row) {
    echo sprintf("ID: %d | %s | %s | %s | Order: %s | Test: %s | %s\n",
        $row['log_id'],
        $row['timestamp'],
        $row['status'],
        $row['event_type'], 
        $row['order_id'] ?: 'N/A',
        $row['is_test'] ? 'YES' : 'NO',
        substr($row['summary'], 0, 40)
    );
}

// Step 4: Test the welcome scenario logic
echo "\n\n4. TESTING WELCOME SCENARIO LOGIC...\n";
echo str_repeat('-', 50) . "\n";

if (class_exists('\OrderDaemon\CompletionManager\Admin\InsightDashboard')) {
    echo "Testing InsightDashboard welcome scenario detection...\n";
    
    // Test the determine_welcome_scenario method indirectly by checking the logic
    $log_count = $wpdb->get_var("SELECT COUNT(*) FROM `$audit_table`");
    $is_welcome = ($log_count === 0 || $log_count === '0');
    
    echo "Log count for welcome scenario: $log_count\n";
    echo "Would be welcome scenario: " . ($is_welcome ? 'YES' : 'NO') . "\n";
    
    // Check if there are options affecting the default behavior
    $debug_default = get_option('odcm_dashboard_include_debug_by_default', 'not_set');
    $test_default = get_option('odcm_dashboard_include_test_by_default', 'not_set');
    
    echo "odcm_dashboard_include_debug_by_default option: " . ($debug_default === 'not_set' ? 'NOT SET' : ($debug_default ? 'true' : 'false')) . "\n";
    echo "odcm_dashboard_include_test_by_default option: " . ($test_default === 'not_set' ? 'NOT SET' : ($test_default ? 'true' : 'false')) . "\n";
} else {
    echo "InsightDashboard class not available\n";
}

// Step 5: Test REST API registration
echo "\n\n5. TESTING REST API REGISTRATION...\n";
echo str_repeat('-', 50) . "\n";

// Check if REST routes are registered
$rest_server = rest_get_server();
$routes = $rest_server->get_routes();

$odcm_routes = array_filter(array_keys($routes), function($route) {
    return strpos($route, '/odcm/v1') === 0;
});

echo "Registered ODCM REST routes:\n";
foreach ($odcm_routes as $route) {
    echo "  $route\n";
}

if (empty($odcm_routes)) {
    echo "WARNING: No ODCM REST routes found! API may not be registered.\n";
}

// Check if the audit log endpoint specifically exists
$audit_log_route = '/odcm/v1/audit-log';
if (isset($routes[$audit_log_route])) {
    echo "\nAudit log endpoint is registered: $audit_log_route\n";
} else {
    echo "\nERROR: Audit log endpoint NOT registered!\n";
}

// Step 6: Test user capabilities
echo "\n\n6. TESTING USER CAPABILITIES...\n";
echo str_repeat('-', 50) . "\n";

$current_user = wp_get_current_user();
echo "Current user ID: " . get_current_user_id() . "\n";
echo "Current user login: " . $current_user->user_login . "\n";
echo "Current user roles: " . implode(', ', $current_user->roles) . "\n";

// Test required capabilities
$capabilities = [
    'view_woocommerce_reports',
    'manage_woocommerce', 
    'manage_options'
];

foreach ($capabilities as $cap) {
    echo "$cap: " . (current_user_can($cap) ? 'YES' : 'NO') . "\n";
}

// Step 7: Test the actual frontend request simulation
echo "\n\n7. SIMULATING FRONTEND REQUEST...\n";
echo str_repeat('-', 50) . "\n";

// Simulate what the frontend JavaScript would send
$frontend_params = [
    'page' => 1,
    'per_page' => 20,
    'view' => 'consolidated',
    // Note: frontend doesn't explicitly send include_debug or include_test when they're false
];

echo "Frontend would send parameters: " . json_encode($frontend_params) . "\n";

// Test what happens when parameters are missing (our fix should handle this)
echo "\nTesting parameter handling:\n";

// Simulate request without explicit debug/test parameters
$test_request = new WP_REST_Request('GET', '/wp-json/odcm/v1/audit-log/');
foreach ($frontend_params as $key => $value) {
    $test_request->set_param($key, $value);
}

// Test the parameter resolution logic
$include_debug_param = $test_request->get_param('include_debug'); // Should be null
$include_test_param = $test_request->get_param('include_test');   // Should be null

echo "include_debug parameter from request: " . ($include_debug_param === null ? 'NULL' : var_export($include_debug_param, true)) . "\n";
echo "include_test parameter from request: " . ($include_test_param === null ? 'NULL' : var_export($include_test_param, true)) . "\n";

// Test our fixed logic
$include_debug_resolved = ($include_debug_param === null) ? true : (bool) $include_debug_param;
$include_test_resolved = ($include_test_param === null) ? true : (bool) $include_test_param;

echo "After our fix - include_debug resolved to: " . ($include_debug_resolved ? 'true' : 'false') . "\n";
echo "After our fix - include_test resolved to: " . ($include_test_resolved ? 'true' : 'false') . "\n";

// Step 8: Test the complete flow
echo "\n\n8. TESTING COMPLETE API FLOW...\n";
echo str_repeat('-', 50) . "\n";

try {
    if (class_exists('\OrderDaemon\CompletionManager\API\AuditLogEndpoint')) {
        $api_endpoint = new \OrderDaemon\CompletionManager\API\AuditLogEndpoint();
        
        // Verify that our user has permission
        echo "Testing API permissions...\n";
        $has_permission = $api_endpoint->check_permissions($test_request);
        echo "API permission check: " . ($has_permission ? 'PASSED' : 'FAILED') . "\n";
        
        if ($has_permission) {
            echo "\nCalling API endpoint...\n";
            $api_response = $api_endpoint->get_logs($test_request);
            
            if (is_wp_error($api_response)) {
                echo "API Error: " . $api_response->get_error_message() . "\n";
                echo "Error data: " . json_encode($api_response->get_error_data()) . "\n";
            } elseif ($api_response instanceof WP_REST_Response) {
                $response_data = $api_response->get_data();
                echo "SUCCESS: API returned response\n";
                echo "Logs count: " . count($response_data['logs'] ?? []) . "\n";
                echo "Total: " . ($response_data['pagination']['total'] ?? 'unknown') . "\n";
                echo "View mode: " . ($response_data['meta']['view_mode'] ?? 'unknown') . "\n";
                
                if (empty($response_data['logs'])) {
                    echo "\nWARNING: API returned empty logs array!\n";
                    echo "This suggests the issue is in the filtering or query logic.\n";
                } else {
                    echo "\nSUCCESS: API returned " . count($response_data['logs']) . " logs\n";
                }
            }
        } else {
            echo "Cannot test API endpoint - permission check failed\n";
        }
    } else {
        echo "ERROR: AuditLogEndpoint class not found\n";
    }
} catch (Exception $e) {
    echo "ERROR: Exception in API test: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Step 9: Test raw HTTP request to the API
echo "\n\n9. TESTING RAW HTTP REQUEST...\n";
echo str_repeat('-', 50) . "\n";

$site_url = get_site_url();
$api_url = $site_url . '/wp-json/odcm/v1/audit-log/';

echo "Testing HTTP request to: $api_url\n";

// Create nonce for the request
$nonce = wp_create_nonce('wp_rest');
echo "Generated nonce: $nonce\n";

// Make HTTP request
$response = wp_remote_get($api_url . '?per_page=5&page=1', [
    'headers' => [
        'X-WP-Nonce' => $nonce,
    ],
    'cookies' => $_COOKIE, // Pass current session cookies
]);

if (is_wp_error($response)) {
    echo "HTTP Error: " . $response->get_error_message() . "\n";
} else {
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    echo "HTTP Response Code: $response_code\n";
    
    if ($response_code === 200) {
        $json_data = json_decode($response_body, true);
        if ($json_data) {
            echo "HTTP Response JSON parsed successfully\n";
            echo "Logs count in HTTP response: " . count($json_data['logs'] ?? []) . "\n";
            echo "Total in HTTP pagination: " . ($json_data['pagination']['total'] ?? 'unknown') . "\n";
        } else {
            echo "Failed to parse JSON response\n";
            echo "Raw response (first 200 chars): " . substr($response_body, 0, 200) . "\n";
        }
    } else {
        echo "Non-200 response code\n";
        echo "Response body (first 200 chars): " . substr($response_body, 0, 200) . "\n";
    }
}

echo "\n\n=== DEBUG TEST COMPLETE ===\n";
echo "Check the output above to identify where the issue occurs.\n";
