<?php
/**
 * Debug script to investigate hierarchy visualization issues
 */

// Bootstrap WordPress
require_once 'wp-config.php';
require_once 'wp-load.php';

echo "=== HIERARCHY VISUALIZATION DEBUG ===\n\n";

// Check recent audit log entries for orders 87 and 88
global $wpdb;

echo "1. CHECKING DATABASE PARENT_ID VALUES:\n";
echo "=====================================\n";

$results = $wpdb->get_results(
    "SELECT log_id, order_id, event_type, parent_id, summary, timestamp 
     FROM {$wpdb->prefix}odcm_audit_log 
     WHERE order_id IN (87, 88) 
     ORDER BY order_id, timestamp DESC 
     LIMIT 15",
    ARRAY_A
);

foreach ($results as $row) {
    printf("Order #%d | Event: %s | Parent ID: %s | Summary: %s | Time: %s\n", 
        $row['order_id'], 
        $row['event_type'], 
        $row['parent_id'] ? $row['parent_id'] : 'NULL',
        substr($row['summary'], 0, 60),
        $row['timestamp']
    );
}

echo "\n\n2. TESTING PARENT_ID RESOLUTION FUNCTION:\n";
echo "==========================================\n";

// Test the parent_id resolution function
if (function_exists('odcm_resolve_parent_id')) {
    $test_parent_id = odcm_resolve_parent_id('checkout_processed', 87);
    echo "Resolving parent for order 87 with event type 'checkout_processed': " . ($test_parent_id ? $test_parent_id : 'NULL') . "\n";
    
    $test_parent_id2 = odcm_resolve_parent_id('payment_completed', 88);
    echo "Resolving parent for order 88 with event type 'payment_completed': " . ($test_parent_id2 ? $test_parent_id2 : 'NULL') . "\n";
} else {
    echo "ERROR: odcm_resolve_parent_id function not found!\n";
}

echo "\n\n3. CHECKING TIMELINE RENDERER:\n";
echo "===============================\n";

// Test timeline rendering for order 87
if (class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\RegistryTimelineRenderer')) {
    try {
        $renderer = new \OrderDaemon\CompletionManager\API\Timeline\RegistryTimelineRenderer();
        
        // Get timeline for order 87
        $timeline = $renderer->getTimeline(87);
        
        echo "Timeline entries for Order #87:\n";
        foreach ($timeline as $entry) {
            printf("- Event: %s | Has parent_id: %s | CSS classes: %s\n",
                $entry['event_type'] ?? 'unknown',
                isset($entry['parent_id']) && $entry['parent_id'] ? 'YES' : 'NO',
                isset($entry['css_classes']) ? implode(' ', $entry['css_classes']) : 'none'
            );
        }
    } catch (Exception $e) {
        echo "ERROR rendering timeline: " . $e->getMessage() . "\n";
    }
} else {
    echo "ERROR: RegistryTimelineRenderer class not found!\n";
}

echo "\n\n4. CHECKING FOR DUPLICATE EVENTS:\n";
echo "==================================\n";

// Check for duplicate events
$duplicates = $wpdb->get_results(
    "SELECT order_id, event_type, COUNT(*) as count 
     FROM {$wpdb->prefix}odcm_audit_log 
     WHERE order_id IN (87, 88) 
     GROUP BY order_id, event_type 
     HAVING COUNT(*) > 1
     ORDER BY order_id, count DESC",
    ARRAY_A
);

if (!empty($duplicates)) {
    echo "Found duplicate events:\n";
    foreach ($duplicates as $dup) {
        printf("Order #%d has %d '%s' events\n", $dup['order_id'], $dup['count'], $dup['event_type']);
    }
} else {
    echo "No duplicate events found.\n";
}

echo "\n\n5. CHECKING EVENT TYPE RELATIONSHIPS:\n";
echo "=====================================\n";

// Check what parent event types are being stored
$parent_relationships = $wpdb->get_results(
    "SELECT DISTINCT a1.event_type as child_event, a2.event_type as parent_event 
     FROM {$wpdb->prefix}odcm_audit_log a1 
     LEFT JOIN {$wpdb->prefix}odcm_audit_log a2 ON a1.parent_id = a2.log_id 
     WHERE a1.parent_id IS NOT NULL 
     AND a1.order_id IN (87, 88)",
    ARRAY_A
);

if (!empty($parent_relationships)) {
    echo "Parent-child relationships found:\n";
    foreach ($parent_relationships as $rel) {
        printf("Child: %s -> Parent: %s\n", $rel['child_event'], $rel['parent_event'] ?? 'NULL');
    }
} else {
    echo "No parent-child relationships found in database.\n";
}

echo "\n\nDEBUG COMPLETE\n";
