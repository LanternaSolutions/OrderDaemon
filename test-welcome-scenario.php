<?php
/**
 * Test script to debug the welcome scenario check
 */

// Set up WordPress environment
define('WP_USE_THEMES', false);
require_once('wp-load.php');

// Set debug mode
define('ODCM_DEBUG', true);

// Test the welcome scenario check directly
try {
    // Create the dashboard instance
    $dashboard = new OrderDaemon\CompletionManager\Admin\InsightDashboard();

    // Call the welcome scenario check method
    $is_welcome = $dashboard->determine_welcome_scenario();

    echo "Welcome scenario check result: " . ($is_welcome ? 'true' : 'false') . "\n";

    // Also test the AJAX handler directly
    echo "Testing AJAX handler...\n";

    // Mock the POST request
    $_POST['_wpnonce'] = wp_create_nonce('wp_rest');

    // Call the AJAX handler
    $dashboard->handle_welcome_scenario_check();

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
