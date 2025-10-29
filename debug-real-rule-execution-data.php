<?php
/**
 * Debug Real Rule Execution Data Structure
 * 
 * This script examines the actual rule_execution events in the database 
 * to understand what data structure our RuleRenderer is receiving.
 */

// WordPress environment setup
define('WP_USE_THEMES', false);
require_once('/var/www/html/wp-config.php');
require_once('/var/www/html/wp-load.php');

global $wpdb;

echo "=== Real Rule Execution Event Data Analysis ===\n\n";

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
    echo "❌ No rule_execution events found in database\n";
    echo "This suggests rule events are still being logged as 'universal_event_processing'\n\n";
    
    // Check for universal_event_processing events instead
    $sql2 = "SELECT l.log_id, l.order_id, l.event_type, l.summary, l.details,
                    COALESCE(p.payload, l.details, '') as payload_data 
             FROM {$wpdb->prefix}odcm_audit_log l 
             LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
             WHERE l.event_type = 'universal_event_processing' 
             AND l.summary LIKE '%rule%' 
             ORDER BY l.timestamp DESC 
             LIMIT 1";
    
    $result2 = $wpdb->get_row($sql2);
    
    if ($result2) {
        echo "✅ Found rule-related universal_event_processing event:\n";
        echo "Event ID: {$result2->log_id}\n";
        echo "Order ID: {$result2->order_id}\n";
        echo "Event Type: {$result2->event_type}\n";
        echo "Summary: {$result2->summary}\n\n";
        
        echo "=== PAYLOAD DATA STRUCTURE ===\n";
        $payload = json_decode($result2->payload_data, true);
        if ($payload) {
            echo "Raw payload keys: " . implode(', ', array_keys($payload)) . "\n\n";
            
            // Check for rule-related data
            if (isset($payload['rule_name'])) {
                echo "✅ Found rule_name at top level: " . $payload['rule_name'] . "\n";
            }
            
            if (isset($payload['order_id'])) {
                echo "✅ Found order_id at top level: " . $payload['order_id'] . "\n";
            }
            
            if (isset($payload['rawData']['rule_execution'])) {
                echo "✅ Found rawData.rule_execution structure\n";
                echo "rawData.rule_execution keys: " . implode(', ', array_keys($payload['rawData']['rule_execution'])) . "\n";
            } else {
                echo "❌ No rawData.rule_execution structure found\n";
                if (isset($payload['rawData'])) {
                    echo "rawData keys: " . implode(', ', array_keys($payload['rawData'])) . "\n";
                }
            }
            
            echo "\n=== COMPLETE PAYLOAD STRUCTURE ===\n";
            echo json_encode($payload, JSON_PRETTY_PRINT);
        } else {
            echo "❌ Payload is not valid JSON\n";
            echo "Raw payload: " . $result2->payload_data . "\n";
        }
    } else {
        echo "❌ No rule-related events found at all\n";
        echo "This suggests rules aren't being executed or logged properly\n";
    }
    
    exit;
}

echo "✅ Found rule_execution event:\n";
echo "Event ID: {$result->log_id}\n";
echo "Order ID: {$result->order_id}\n";
echo "Event Type: {$result->event_type}\n";
echo "Summary: {$result->summary}\n\n";

echo "=== PAYLOAD DATA STRUCTURE ===\n";
$payload = json_decode($result->payload_data, true);
if ($payload) {
    echo "Raw payload keys: " . implode(', ', array_keys($payload)) . "\n\n";
    
    // Check for rule-related data our renderer expects
    if (isset($payload['rule_name'])) {
        echo "✅ Found rule_name at top level: " . $payload['rule_name'] . "\n";
    } else {
        echo "❌ No rule_name at top level\n";
    }
    
    if (isset($payload['order_id'])) {
        echo "✅ Found order_id at top level: " . $payload['order_id'] . "\n";
    } else {
        echo "❌ No order_id at top level\n";
    }
    
    if (isset($payload['execution_status'])) {
        echo "✅ Found execution_status: " . $payload['execution_status'] . "\n";
    } else {
        echo "❌ No execution_status found\n";
    }
    
    if (isset($payload['rawData']['rule_execution'])) {
        echo "✅ Found rawData.rule_execution structure\n";
        $rule_exec = $payload['rawData']['rule_execution'];
        echo "rawData.rule_execution keys: " . implode(', ', array_keys($rule_exec)) . "\n";
        
        if (isset($rule_exec['rule_configuration']['rule_name'])) {
            echo "✅ Found rule name in rawData: " . $rule_exec['rule_configuration']['rule_name'] . "\n";
        }
        
        if (isset($rule_exec['order_evaluation_context']['order_id'])) {
            echo "✅ Found order ID in rawData: " . $rule_exec['order_evaluation_context']['order_id'] . "\n";
        }
    } else {
        echo "❌ No rawData.rule_execution structure found\n";
        if (isset($payload['rawData'])) {
            echo "Available rawData keys: " . implode(', ', array_keys($payload['rawData'])) . "\n";
        } else {
            echo "❌ No rawData at all\n";
        }
    }
    
    // Check data structure for fallback extraction
    if (isset($payload['data'])) {
        echo "\n--- Checking nested 'data' structure ---\n";
        echo "data keys: " . implode(', ', array_keys($payload['data'])) . "\n";
        
        if (isset($payload['data']['rule_name'])) {
            echo "✅ Found rule_name in data: " . $payload['data']['rule_name'] . "\n";
        }
        
        if (isset($payload['data']['order_id'])) {
            echo "✅ Found order_id in data: " . $payload['data']['order_id'] . "\n";
        }
    }
    
    echo "\n=== COMPLETE PAYLOAD STRUCTURE ===\n";
    echo json_encode($payload, JSON_PRETTY_PRINT);
    
} else {
    echo "❌ Payload is not valid JSON\n";
    echo "Raw payload: " . $result->payload_data . "\n";
}

echo "\n=== RENDERER TESTING ===\n";

// Test our RuleRenderer with this actual data
try {
    require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/BaseRenderer.php';
    require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/PayloadComponentUIToolkit.php';
    require_once '/var/www/html/wp-content/plugins/order-daemon-core/src/View/PayloadRenderer/RuleRenderer.php';
    
    $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\RuleRenderer();
    
    // Test label generation with actual data
    $reflection = new ReflectionClass($renderer);
    $getLabelMethod = $reflection->getMethod('getLabel');
    $getLabelMethod->setAccessible(true);
    
    $label = $getLabelMethod->invoke($renderer, $payload, 'rule_execution');
    echo "Generated Label: $label\n";
    
    // Test status pill
    $getStatusPillMethod = $reflection->getMethod('getStatusPill');
    $getStatusPillMethod->setAccessible(true);
    
    $statusPill = $getStatusPillMethod->invoke($renderer, $payload, 'rule_execution');
    echo "Status Pill: " . json_encode($statusPill) . "\n";
    
    echo "\n✅ RuleRenderer can process this data structure\n";
    
} catch (Exception $e) {
    echo "❌ RuleRenderer failed: " . $e->getMessage() . "\n";
}

echo "\n=== CONCLUSION ===\n";
echo "This analysis shows the actual data structure that RuleRenderer receives.\n";
echo "Any gaps between expected and actual structure need to be addressed.\n";
