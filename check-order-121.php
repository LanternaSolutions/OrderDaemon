<?php
/**
 * Debug script to check if order #121 exists and manually trigger its processing
 */

// Set up WordPress environment
define('WP_USE_THEMES', false);
require_once('wp-load.php');

// Check if order #121 exists
$order = wc_get_order(121);

if (!$order) {
    echo "Order #121 does not exist in WooCommerce.\n";
    exit(1);
}

echo "Order #121 found!\n";
echo "Status: " . $order->get_status() . "\n";
echo "Date Created: " . $order->get_date_created()->format('Y-m-d H:i:s') . "\n";
echo "Total: " . $order->get_total() . " " . $order->get_currency() . "\n";

// Check if there are any active completion rules
$rules = get_posts([
    'post_type' => 'odcm_order_rule',
    'post_status' => 'publish',
    'posts_per_page' => -1,
]);

echo "\nActive completion rules: " . count($rules) . "\n";

if (count($rules) === 0) {
    echo "No active completion rules found. Order #121 won't be processed automatically.\n";
} else {
    echo "Found " . count($rules) . " active rules:\n";
    foreach ($rules as $rule) {
        $rule_data = json_decode(get_post_meta($rule->ID, '_odcm_rule_data', true), true);
        echo "- Rule #" . $rule->ID . ": " . $rule->post_title . " (Trigger: " . ($rule_data['trigger']['id'] ?? 'unknown') . ")\n";
    }
}

// Manually trigger order processing
echo "\nManually triggering order processing for #121...\n";

try {
    // Use the Core class to schedule completion check
    $core = new \OrderDaemon\CompletionManager\Core\Core();
    $result = $core->schedule_completion_check(121);

    if ($result) {
        echo "Successfully scheduled order #121 for completion check.\n";
    } else {
        echo "Failed to schedule order #121 for completion check.\n";
    }
} catch (Exception $e) {
    echo "Error scheduling order processing: " . $e->getMessage() . "\n";
}

// Check audit log for order #121
global $wpdb;
$logs = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}odcm_audit_log WHERE order_id = %d ORDER BY timestamp DESC",
        121
    )
);

echo "\nAudit log entries for order #121: " . count($logs) . "\n";

if (count($logs) > 0) {
    echo "Existing log entries:\n";
    foreach ($logs as $log) {
        echo "- Log #" . $log->log_id . ": " . $log->summary . " (" . $log->status . ") at " . $log->timestamp . "\n";
    }
} else {
    echo "No audit log entries found for order #121.\n";
}

echo "\nDebug script completed.\n";
