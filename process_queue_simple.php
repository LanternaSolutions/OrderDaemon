<?php
require_once 'wp-config.php';

echo "=== PROCESSING QUEUE SIMPLE ===\n\n";vrvtw5y637z8n8

// Find queued entries directly from our queue table
global $wpdb;

echo "1. Finding queued events for Order #999...\n";
$queue_entries = $wpdb->get_results($wpdb->prepare(
    "SELECT queue_id, status, created_at, event_data FROM {$wpdb->prefix}odcm_audit_log_queue 
     WHERE event_data LIKE %s AND status = 'pending'
     ORDER BY created_at DESC LIMIT 5",
    '%"order_id":999%'
));

echo "Found " . count($queue_entries) . " queued entries\n\n";

if (!empty($queue_entries)) {
    echo "2. Processing queued entries manually...\n";
    
    foreach ($queue_entries as $entry) {
        echo "Processing queue_id: {$entry->queue_id}\n";
        
        // Manually call the queue processor function
        if (function_exists('odcm_process_queued_log_entry')) {
            try {
                odcm_process_queued_log_entry($entry->queue_id);
                echo "  ✓ Processed successfully\n";
            } catch (Exception $e) {
                echo "  ✗ Error: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  ✗ odcm_process_queued_log_entry function not found\n";
        }
        echo "\n";
    }
} else {
    echo "2. No queued entries found to process\n\n";
}

echo "3. Checking results in audit log...\n";
$recent_events = $wpdb->get_results($wpdb->prepare(
    "SELECT log_id, event_type, parent_id, summary, timestamp FROM {$wpdb->prefix}odcm_audit_log 
     WHERE order_id = %d 
     ORDER BY timestamp DESC LIMIT 5",
    999
));

if ($recent_events) {
    echo "✓ Found " . count($recent_events) . " events for Order #999:\n\n";
    foreach ($recent_events as $event) {
        $parent_status = $event->parent_id ? "parent_id={$event->parent_id}" : "NO parent_id";
        echo "  log_id={$event->log_id}, event_type={$event->event_type}, {$parent_status}\n";
        echo "    summary: {$event->summary}\n";
        echo "    timestamp: {$event->timestamp}\n\n";
    }
    
    // Check if hierarchy is working
    $events_with_parent = array_filter($recent_events, function($event) {
        return !empty($event->parent_id);
    });
    
    if (!empty($events_with_parent)) {
        echo "🎉 SUCCESS! Found " . count($events_with_parent) . " events with parent_id!\n";
        echo "The hierarchy feature is working correctly.\n";
        
        // Show the hierarchy relationship
        foreach ($events_with_parent as $child) {
            $parent = array_filter($recent_events, function($event) use ($child) {
                return $event->log_id == $child->parent_id;
            });
            $parent = reset($parent);
            
            if ($parent) {
                echo "\nHierarchy relationship found:\n";
                echo "  PARENT: log_id={$parent->log_id}, event_type={$parent->event_type}\n";
                echo "    → {$parent->summary}\n";
                echo "  CHILD:  log_id={$child->log_id}, event_type={$child->event_type}, parent_id={$child->parent_id}\n";
                echo "    → {$child->summary}\n";
            }
        }
    } else {
        echo "❌ No events found with parent_id set.\n";
    }
} else {
    echo "❌ NO events found for Order #999\n";
}

echo "\n=== END SIMPLE PROCESSING ===\n";
