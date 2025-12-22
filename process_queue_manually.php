<?php
require_once 'wp-config.php';

echo "=== MANUALLY PROCESSING ACTION SCHEDULER QUEUE ===\n\n";

// Manually trigger Action Scheduler to process our queued events
if (function_exists('ActionScheduler_QueueRunner') && class_exists('ActionScheduler_QueueRunner')) {
    echo "1. Running Action Scheduler queue...\n";
    
    $queue_runner = ActionScheduler_QueueRunner::instance();
    $queue_runner->run();
    
    echo "Queue processing completed.\n\n";
} else {
    echo "1. ActionScheduler_QueueRunner not available, trying alternative method...\n";
    
    // Alternative: manually get and process pending actions
    if (function_exists('as_get_scheduled_actions')) {
        $pending_actions = as_get_scheduled_actions([
            'hook' => 'odcm_process_queued_log_entry', 
            'status' => 'pending',
            'per_page' => 10
        ]);
        
        echo "Found " . count($pending_actions) . " pending odcm_process_queued_log_entry actions\n";
        
        foreach ($pending_actions as $action) {
            echo "Processing action ID: " . $action->get_id() . "\n";
            $args = $action->get_args();
            
            // Manually call the hook handler
            if (function_exists('odcm_process_queued_log_entry')) {
                odcm_process_queued_log_entry($args);
                echo "  - Handler executed\n";
            }
        }
    }
}

echo "\n2. Checking results in database...\n";
global $wpdb;
$recent_events = $wpdb->get_results($wpdb->prepare(
    "SELECT log_id, event_type, parent_id, summary, timestamp FROM {$wpdb->prefix}odcm_audit_log 
     WHERE order_id = %d 
     ORDER BY timestamp DESC LIMIT 5",
    999
));

if ($recent_events) {
    echo "✓ Found " . count($recent_events) . " events for Order #999:\n";
    foreach ($recent_events as $event) {
        $parent_status = $event->parent_id ? "parent_id={$event->parent_id}" : "NO parent_id";
        echo "  log_id={$event->log_id}, event_type={$event->event_type}, {$parent_status}\n";
        echo "    summary: {$event->summary}\n";
        echo "    timestamp: {$event->timestamp}\n\n";
    }
    
    // Check if any have parent_id set
    $events_with_parent = array_filter($recent_events, function($event) {
        return !empty($event->parent_id);
    });
    
    if (!empty($events_with_parent)) {
        echo "🎉 SUCCESS! Found " . count($events_with_parent) . " events with parent_id set!\n";
        echo "The hierarchy feature is working correctly.\n";
    } else {
        echo "❌ No events found with parent_id set.\n";
        echo "The hierarchy feature still needs debugging.\n";
    }
} else {
    echo "❌ NO events found for Order #999\n";
}

echo "\n=== END MANUAL PROCESSING ===\n";
