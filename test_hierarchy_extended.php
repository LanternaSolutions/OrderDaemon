<?php
require_once 'wp-config.php';

// Test creating a new event with parent_event_type to see if hierarchy fix works
echo "=== TESTING HIERARCHY FIX (EXTENDED) ===\n\n";

// Check current Action Scheduler queue status
echo "0. Checking Action Scheduler queue before test...\n";
if (function_exists('as_get_scheduled_actions')) {
    $pending_actions = as_get_scheduled_actions(['status' => 'pending', 'per_page' => 5]);
    echo "Pending actions: " . count($pending_actions) . "\n";
}
echo "\n";

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
sleep(2);

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

// Check Action Scheduler queue after creating events
echo "3. Checking Action Scheduler queue after creating events...\n";
if (function_exists('as_get_scheduled_actions')) {
    $pending_actions = as_get_scheduled_actions(['status' => 'pending', 'per_page' => 10]);
    echo "Pending actions: " . count($pending_actions) . "\n";
    foreach ($pending_actions as $action) {
        if (strpos($action->get_hook(), 'odcm_') === 0) {
            echo "  - Hook: " . $action->get_hook() . ", Next run: " . $action->get_schedule()->next() . "\n";
        }
    }
}
echo "\n";

// Wait longer for async processing
echo "4. Waiting for background processing (10 seconds)...\n";
sleep(10);

// Check if events got processed
echo "5. Checking results in database...\n";
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
        echo "  ✓ log_id={$event->log_id}, event_type={$event->event_type}, {$parent_status}\n";
        echo "    summary: {$event->summary}\n";
        echo "    timestamp: {$event->timestamp}\n\n";
    }
} else {
    echo "  ✗ NO events found for Order #999\n";
    echo "  Checking queue table instead...\n";
    
    $queue_entries = $wpdb->get_results($wpdb->prepare(
        "SELECT queue_id, status, created_at, last_error FROM {$wpdb->prefix}odcm_audit_log_queue 
         WHERE event_data LIKE %s 
         ORDER BY created_at DESC LIMIT 5",
        '%"order_id":999%'
    ));
    
    if ($queue_entries) {
        echo "  Found events in queue:\n";
        foreach ($queue_entries as $entry) {
            echo "    - Queue ID: {$entry->queue_id}, Status: {$entry->status}, Created: {$entry->created_at}\n";
            if ($entry->last_error) {
                echo "      Error: {$entry->last_error}\n";
            }
        }
    } else {
        echo "  NO events found in queue either\n";
    }
}

echo "=== END EXTENDED TEST ===\n";
