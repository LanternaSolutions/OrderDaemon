<?php
/**
 * Diagnose User Issue - Complete Rule Execution Analysis
 * 
 * This script examines all recent rule_execution events to find the one
 * causing "Rule evaluation completed for Order #0" and diagnose the issue.
 */

// WordPress environment setup
define('WP_USE_THEMES', false);
require_once('/var/www/html/wp-config.php');
require_once('/var/www/html/wp-load.php');

global $wpdb;

echo "=== Complete Rule Execution Diagnosis ===\n\n";

// Get all recent rule_execution events
$sql = "SELECT l.log_id, l.order_id, l.event_type, l.summary, l.timestamp,
               COALESCE(p.payload, l.details, '') as payload_data 
        FROM {$wpdb->prefix}odcm_audit_log l 
        LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
        WHERE l.event_type = 'rule_execution' 
        ORDER BY l.timestamp DESC 
        LIMIT 5";

$results = $wpdb->get_results($sql);

if (empty($results)) {
    echo "❌ No rule_execution events found in database\n";
    exit;
}

echo "✅ Found " . count($results) . " recent rule_execution events\n\n";

foreach ($results as $index => $result) {
    echo "=== RULE EXECUTION EVENT #" . ($index + 1) . " ===\n";
    echo "Log ID: {$result->log_id}\n";
    echo "Order ID: {$result->order_id}\n";
    echo "Summary: {$result->summary}\n";
    echo "Timestamp: {$result->timestamp}\n\n";
    
    $payload = json_decode($result->payload_data, true);
    if (!$payload) {
        echo "❌ Invalid payload data\n\n";
        continue;
    }
    
    // Simulate what the timeline would show for this event
    try {
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/API/Timeline/ComponentExtractorInterface.php';
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/API/Timeline/ProcessLoggerComponentExtractor.php';
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/BaseRenderer.php';
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/RuleRenderer.php';
        
        $extractor = new \OrderDaemon\CompletionManager\API\Timeline\ProcessLoggerComponentExtractor();
        $components = $extractor->extractComponents($payload, true);
        
        if (empty($components)) {
            echo "❌ No components extracted - this event won't show in timeline\n\n";
            continue;
        }
        
        foreach ($components as $compIndex => $component) {
            echo "--- Component #{$compIndex} ---\n";
            echo "Event Type: " . ($component['event_type'] ?? 'MISSING') . "\n";
            
            // Test exactly what the timeline would show
            $rendererPayload = [
                'data' => $component['data'] ?? [],
                'rawData' => $component['rawData'] ?? []
            ];
            
            $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\RuleRenderer();
            $reflection = new ReflectionClass($renderer);
            $getLabelMethod = $reflection->getMethod('getLabel');
            $getLabelMethod->setAccessible(true);
            
            $timelineLabel = $getLabelMethod->invoke($renderer, $rendererPayload, $component['event_type']);
            echo "TIMELINE DISPLAY LABEL: '$timelineLabel'\n";
            
            // Check if this matches the user's problem
            if (strpos($timelineLabel, 'Order #0') !== false) {
                echo "🎯 FOUND THE PROBLEM EVENT!\n";
                echo "This event shows 'Order #0' - diagnosing data structure...\n\n";
                
                echo "Data structure analysis:\n";
                echo "- rule_name in data: " . (isset($rendererPayload['data']['rule_name']) ? $rendererPayload['data']['rule_name'] : 'MISSING') . "\n";
                echo "- order_id in data: " . (isset($rendererPayload['data']['order_id']) ? $rendererPayload['data']['order_id'] : 'MISSING') . "\n";
                echo "- primary_object_id in data: " . (isset($rendererPayload['data']['primary_object_id']) ? $rendererPayload['data']['primary_object_id'] : 'MISSING') . "\n";
                
                if (isset($rendererPayload['rawData']['rule_execution'])) {
                    $ruleExec = $rendererPayload['rawData']['rule_execution'];
                    echo "- rawData rule_name: " . ($ruleExec['rule_configuration']['rule_name'] ?? 'MISSING') . "\n";
                    echo "- rawData order_id: " . ($ruleExec['order_evaluation_context']['order_id'] ?? 'MISSING') . "\n";
                } else {
                    echo "- rawData.rule_execution: MISSING\n";
                }
                
                echo "\nCOMPLETE COMPONENT DATA:\n";
                echo json_encode($component, JSON_PRETTY_PRINT) . "\n";
                
            } elseif (strpos($timelineLabel, 'Rule evaluation completed') !== false) {
                echo "⚠️  GENERIC LABEL DETECTED\n";
                echo "This shows a generic label, checking why...\n";
                
                echo "Data extraction check:\n";
                echo "- rule_name in data: " . (isset($rendererPayload['data']['rule_name']) ? $rendererPayload['data']['rule_name'] : 'MISSING') . "\n";
                echo "- order_id in data: " . (isset($rendererPayload['data']['order_id']) ? $rendererPayload['data']['order_id'] : 'MISSING') . "\n";
                
            } else {
                echo "✅ Good label - contains specific rule information\n";
            }
            
            // Test status pill
            $getStatusPillMethod = $reflection->getMethod('getStatusPill');
            $getStatusPillMethod->setAccessible(true);
            $statusPill = $getStatusPillMethod->invoke($renderer, $rendererPayload, $component['event_type']);
            echo "Status Pill: " . json_encode($statusPill) . "\n\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error processing event: " . $e->getMessage() . "\n\n";
    }
    
    echo str_repeat("-", 80) . "\n\n";
}

echo "=== SUMMARY ===\n";
echo "This analysis shows all recent rule execution events and identifies\n";
echo "which one is causing the 'Order #0' issue that the user is seeing.\n";
echo "If all events show good labels, the issue might be caching or browser-related.\n";
