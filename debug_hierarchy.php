<?php
// Debug script to trace hierarchy visualization issues
require_once 'wp-config.php';

global $wpdb;

echo "=== HIERARCHY DEBUG TRACE ===\n\n";

// 1. Check if parent_id column exists
echo "1. Checking if parent_id column exists...\n";
$parent_id_column = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}odcm_audit_log LIKE 'parent_id'");
if ($parent_id_column) {
    echo "✓ parent_id column exists: " . $parent_id_column->Type . "\n";
} else {
    echo "✗ parent_id column MISSING!\n";
}
echo "\n";

// 2. Check for any entries with parent_id values
echo "2. Checking for entries with parent_id values...\n";
$entries_with_parent_id = $wpdb->get_results(
    "SELECT log_id, event_type, parent_id, summary FROM {$wpdb->prefix}odcm_audit_log 
     WHERE parent_id IS NOT NULL 
     ORDER BY log_id DESC LIMIT 10"
);

if ($entries_with_parent_id) {
    echo "Found " . count($entries_with_parent_id) . " entries with parent_id:\n";
    foreach ($entries_with_parent_id as $entry) {
        echo "  log_id={$entry->log_id}, event_type={$entry->event_type}, parent_id={$entry->parent_id}, summary={$entry->summary}\n";
    }
} else {
    echo "✗ NO entries found with parent_id values!\n";
}
echo "\n";

// 3. Check Order 84 specifically
echo "3. Checking Order 84 entries...\n";
$order_84_entries = $wpdb->get_results(
    "SELECT log_id, event_type, parent_id, summary FROM {$wpdb->prefix}odcm_audit_log 
     WHERE order_id = 84 
     ORDER BY log_id"
);

if ($order_84_entries) {
    echo "Found " . count($order_84_entries) . " entries for Order 84:\n";
    foreach ($order_84_entries as $entry) {
        $parent_status = $entry->parent_id ? "parent_id={$entry->parent_id}" : "NO parent_id";
        echo "  log_id={$entry->log_id}, event_type={$entry->event_type}, {$parent_status}\n";
        echo "    summary: {$entry->summary}\n";
    }
} else {
    echo "✗ NO entries found for Order 84!\n";
}
echo "\n";

// 4. Check rule execution events specifically
echo "4. Checking rule execution events...\n";
$rule_executions = $wpdb->get_results(
    "SELECT log_id, event_type, parent_id, order_id, summary FROM {$wpdb->prefix}odcm_audit_log 
     WHERE event_type = 'rule_execution' 
     ORDER BY log_id DESC LIMIT 5"
);

if ($rule_executions) {
    echo "Found " . count($rule_executions) . " rule execution events:\n";
    foreach ($rule_executions as $entry) {
        $parent_status = $entry->parent_id ? "parent_id={$entry->parent_id}" : "NO parent_id";
        echo "  log_id={$entry->log_id}, order_id={$entry->order_id}, {$parent_status}\n";
        echo "    summary: {$entry->summary}\n";
    }
} else {
    echo "✗ NO rule execution events found!\n";
}
echo "\n";

// 5. Test a specific timeline request
echo "5. Testing timeline request for log_id 588...\n";
try {
    define('ODCM_DEBUG', true); // Ensure debug is on
    
    // Create a test timeline request
    require_once 'src/API/Timeline/TimelineRequest.php';
    require_once 'src/API/Timeline/DatabaseTimelineBuilder.php';
    require_once 'src/API/Timeline/ProcessLoggerComponentExtractor.php';
    
    $extractor = new \OrderDaemon\CompletionManager\API\Timeline\ProcessLoggerComponentExtractor();
    $builder = new \OrderDaemon\CompletionManager\API\Timeline\DatabaseTimelineBuilder($extractor);
    
    $request = new \OrderDaemon\CompletionManager\API\Timeline\TimelineRequest(588, true, 'consolidated');
    $timelineData = $builder->buildTimeline($request);
    
    echo "Timeline built successfully with " . $timelineData->getComponentCount() . " components\n";
    
    // Check if any components have parent_id
    $components = $timelineData->components;
    $components_with_parent_id = 0;
    foreach ($components as $component) {
        if (isset($component['parent_id']) && $component['parent_id'] !== null) {
            $components_with_parent_id++;
            echo "  Component with parent_id found: event_type={$component['event_type']}, parent_id={$component['parent_id']}\n";
        }
    }
    
    if ($components_with_parent_id === 0) {
        echo "✗ NO components found with parent_id in timeline!\n";
    } else {
        echo "✓ Found {$components_with_parent_id} components with parent_id\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error testing timeline: " . $e->getMessage() . "\n";
}

echo "\n=== END DEBUG TRACE ===\n";
