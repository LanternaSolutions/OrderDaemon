<?php
/**
 * Test script to debug the audit log API endpoint
 */

// Set up WordPress environment
define('WP_USE_THEMES', false);
require_once('wp-load.php');

// Set debug mode
define('ODCM_DEBUG', true);

// Test the API endpoint directly
try {
    // Create a mock REST request
    $request = new WP_REST_Request('GET', '/odcm/v1/audit-log/');

    // Set default parameters
    $request->set_param('page', 1);
    $request->set_param('per_page', 20);
    $request->set_param('view', 'consolidated');
    $request->set_param('include_debug', false);
    $request->set_param('include_test', false);

    // Create the endpoint instance
    $endpoint = new OrderDaemon\CompletionManager\API\AuditLogEndpoint();

    // Call the get_logs method
    $response = $endpoint->get_logs($request);

    // Check if response is successful
    if (is_wp_error($response)) {
        echo "API Error: " . $response->get_error_message() . "\n";
        echo "Error Code: " . $response->get_error_code() . "\n";
    } else {
        $data = $response->get_data();
        echo "API Response:\n";
        echo "Total logs: " . ($data['pagination']['total'] ?? 'N/A') . "\n";
        echo "Logs returned: " . count($data['logs'] ?? []) . "\n";
        echo "Response data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
