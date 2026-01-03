<?php
/**
 * Test script to debug the welcome scenario check via AJAX
 */

// Set up WordPress environment
define('WP_USE_THEMES', false);
require_once('wp-load.php');

// Set debug mode
define('ODCM_DEBUG', true);

// Test the AJAX handler directly
try {
    echo "Testing welcome scenario AJAX handler...\n";

    // Mock the POST request
    $_POST['_wpnonce'] = wp_create_nonce('wp_rest');

    // Create the dashboard instance
    $dashboard = new OrderDaemon\CompletionManager\Admin\InsightDashboard();

    // Call the AJAX handler
    $dashboard->handle_welcome_scenario_check();

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
