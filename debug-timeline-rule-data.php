<?php
/**
 * Debug Timeline Rule Data Processing
 * 
 * This script simulates exactly what happens when the timeline processes
 * a rule execution event and passes it to the RuleRenderer.
 */

// WordPress environment setup
define('WP_USE_THEMES', false);
require_once('/var/www/html/wp-config.php');
require_once('/var/www/html/wp-load.php');

global $wpdb;

echo "=== Timeline Rule Data Processing Debug ===\n\n";

// Get the latest rule_execution event
$sql = "SELECT l.log_id, l.order_id, l.event_type, l.summary, l.details, 
               COALESCE(p.payload, l.details, '') as payload_data 
        FROM {$wpdb->prefix}odcm_audit_log l 
        LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
        WHERE l.event_type = 'rule_execution' 
        ORDER BY l.timestamp DESC 
        LIMIT 1";

$result = $wpdb->get_row($sql);
if (!$result) {
    echo "❌ No rule_execution events found\n";
    exit;
}

$payload = json_decode($result->payload_data, true);
if (!$payload) {
    echo "❌ Invalid payload data\n";
    exit;
}

echo "✅ Found rule_execution event with payload\n\n";

// Simulate ProcessLoggerComponentExtractor processing
require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/API/Timeline/ComponentExtractorInterface.php';
require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/API/Timeline/ProcessLoggerComponentExtractor.php';

$extractor = new \OrderDaemon\CompletionManager\API\Timeline\ProcessLoggerComponentExtractor();
$components = $extractor->extractComponents($payload, true);

echo "=== COMPONENT EXTRACTION RESULTS ===\n";
echo "Number of components extracted: " . count($components) . "\n\n";

foreach ($components as $index => $component) {
    echo "--- Component #{$index} ---\n";
    echo "Event Type: " . ($component['event_type'] ?? 'MISSING') . "\n";
    echo "Label: " . ($component['label'] ?? 'MISSING') . "\n";
    echo "Level: " . ($component['level'] ?? 'MISSING') . "\n";
    
    // This is what gets passed to the RuleRenderer
    $rendererPayload = [
        'data' => $component['data'] ?? [],
        'rawData' => $component['rawData'] ?? []
    ];
    
    echo "\n=== RENDERER PAYLOAD STRUCTURE ===\n";
    echo "Data keys: " . implode(', ', array_keys($rendererPayload['data'])) . "\n";
    echo "RawData keys: " . implode(', ', array_keys($rendererPayload['rawData'])) . "\n";
    
    // Check critical data points
    echo "\n--- Critical Data Points ---\n";
    echo "rule_name in data: " . (isset($rendererPayload['data']['rule_name']) ? $rendererPayload['data']['rule_name'] : 'MISSING') . "\n";
    echo "order_id in data: " . (isset($rendererPayload['data']['order_id']) ? $rendererPayload['data']['order_id'] : 'MISSING') . "\n";
    echo "execution_status in data: " . (isset($rendererPayload['data']['execution_status']) ? $rendererPayload['data']['execution_status'] : 'MISSING') . "\n";
    
    // Check rawData structure
    if (isset($rendererPayload['rawData']['rule_execution'])) {
        $ruleExec = $rendererPayload['rawData']['rule_execution'];
        echo "rawData.rule_execution.rule_configuration.rule_name: " . ($ruleExec['rule_configuration']['rule_name'] ?? 'MISSING') . "\n";
        echo "rawData.rule_execution.order_evaluation_context.order_id: " . ($ruleExec['order_evaluation_context']['order_id'] ?? 'MISSING') . "\n";
    } else {
        echo "rawData.rule_execution: MISSING\n";
    }
    
    // Test RuleRenderer with this exact payload
    echo "\n=== RULERENDERER TEST ===\n";
    try {
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/BaseRenderer.php';
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/RuleRenderer.php';
        
        $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\RuleRenderer();
        
        // Test label generation with the exact payload structure the timeline uses
        $reflection = new ReflectionClass($renderer);
        $getLabelMethod = $reflection->getMethod('getLabel');
        $getLabelMethod->setAccessible(true);
        
        $label = $getLabelMethod->invoke($renderer, $rendererPayload, $component['event_type']);
        echo "Timeline Label Result: $label\n";
        
        // Expected: Should show rule name and order ID, not generic message
        if (strpos($label, 'virtual rule') !== false && strpos($label, '#121') !== false) {
            echo "✅ SUCCESS: Label contains rule name and order ID\n";
        } else {
            echo "❌ PROBLEM: Label is missing rule name or order ID\n";
            echo "Expected to contain: 'virtual rule' and '#121'\n";
            echo "Actual label: $label\n";
        }
        
        // Test status pill
        $getStatusPillMethod = $reflection->getMethod('getStatusPill');
        $getStatusPillMethod->setAccessible(true);
        
        $statusPill = $getStatusPillMethod->invoke($renderer, $rendererPayload, $component['event_type']);
        echo "Status Pill: " . json_encode($statusPill) . "\n";
        
    } catch (Exception $e) {
        echo "❌ RuleRenderer test failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
}

echo "=== DIAGNOSIS ===\n";
echo "This test shows exactly what data the RuleRenderer receives in the timeline context.\n";
echo "If the label is still generic, it means our data extraction logic needs fixing.\n";
