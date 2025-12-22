<?php
require_once 'wp-config.php';

// Test creating a new event with parent_event_type to see if hierarchy fix works
echo "=== TESTING HIERARCHY FIX ===\n\n";

// First create a parent event
echo "1. Creating parent event (checkout_processed)...\n";
$parent_result = odcm_log_event(
    'Test checkout completed for Order #999',
    ['checkout_type' => 'standard'],
    999,
    'success',
    'checkout_processed',
    false,
    null
);
echo "Parent event created: " . ($parent_result ? 'YES' : 'NO') . "\n\n";

// Wait one second so child event has different timestamp
sleep(1);

// Then create a child event that should reference the parent
echo "2. Creating child event (rule_execution) with parent_event_type...\n";
$child_result = odcm_log_event(
    'Test rule execution for Order #999',
    ['rule_name' => 'Test Rule'],
    999,
    'success',
    'rule_execution',
    false,
    null,
    'checkout_processed'  // This is the parent_event_type parameter
);
echo "Child event created: " . ($child_result ? 'YES' : 'NO') . "\n\n";

// Wait a few seconds for async processing
echo "3. Waiting for background processing...\n";
sleep(4);

// Now check if parent_id was set
echo "4. Checking results in database...\n";
global $wpdb;
$recent_events = $wpdb->get_results($wpdb->prepare(
    "SELECT log_id, event_type, parent_id, summary, timestamp FROM {$wpdb->prefix}odcm_audit_log 
     WHERE order_id = %d 
     ORDER BY timestamp DESC LIMIT 5",
    999
));

if ($recent_events) {
    foreach ($recent_events as $event) {
        $parent_status = $event->parent_id ? "parent_id={$event->parent_id}" : "NO parent_id";
        echo "  log_id={$event->log_id}, event_type={$event->event_type}, {$parent_status}\n";
        echo "    summary: {$event->summary}\n";
        echo "    timestamp: {$event->timestamp}\n\n";
    }
} else {
    echo "  NO events found for Order #999\n";
}

echo "=== END TEST ===\n";
