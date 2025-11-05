<?php
/**
 * Debug script to examine real database payload structures
 */

// WordPress bootstrap
if (!defined('ABSPATH')) {
    $config_paths = [
        __DIR__ . '/wp-config.php',
        __DIR__ . '/../wp-config.php',
        __DIR__ . '/../../wp-config.php',
        __DIR__ . '/../../../wp-config.php'
    ];
    
    $wp_config_found = false;
    foreach ($config_paths as $config_path) {
        if (file_exists($config_path)) {
            require_once $config_path;
            $wp_config_found = true;
            break;
        }
    }
    
    if (!$wp_config_found) {
        die("Could not find wp-config.php. Please run this script from the WordPress root directory.\n");
    }
}

// Load WordPress
if (!function_exists('wp_load_translations')) {
    require_once ABSPATH . 'wp-settings.php';
}

echo "<h1>Real Database Payload Structure Analysis</h1>\n";

global $wpdb;
$logTable = $wpdb->prefix . 'odcm_audit_log';
$payloadTable = $wpdb->prefix . 'odcm_audit_log_payloads';

// Check if tables exist
$logTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$logTable}'");
$payloadTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$payloadTable}'");

echo "<h2>Table Existence Check</h2>\n";
echo wp_kses("<p>Log table ({$logTable}): " . ($logTableExists ? 'EXISTS' : 'MISSING') . "</p>\n");
echo wp_kses("<p>Payload table ({$payloadTable}): " . ($payloadTableExists ? 'EXISTS' : 'MISSING') . "</p>\n");

if (!$logTableExists) {
    echo "<p><strong>ERROR: Main log table does not exist!</strong></p>\n";
    exit;
}

// Find checkout events in the correct table
echo "<h2>Checkout Events in Database</h2>\n";
$checkoutEvents = $wpdb->get_results($wpdb->prepare("
    SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
           " . ($payloadTableExists ? "COALESCE(p.payload, l.details, '') as payload" : "l.details as payload") . "
    FROM {$logTable} l 
    " . ($payloadTableExists ? "LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id" : "") . "
    WHERE l.event_type LIKE %s OR l.event_type LIKE %s OR l.summary LIKE %s OR l.summary LIKE %s
    ORDER BY l.timestamp DESC 
    LIMIT 10
", '%checkout%', '%block_checkout%', '%checkout%', '%block_checkout%'));

if (empty($checkoutEvents)) {
    echo "<p><strong>No checkout events found in the correct tables.</strong></p>\n";
    
    // Check what event types we do have
    echo "<h3>Available Event Types (sample)</h3>\n";
    $sampleEvents = $wpdb->get_results("
        SELECT DISTINCT event_type, COUNT(*) as count 
        FROM {$logTable} 
        WHERE event_type IS NOT NULL AND event_type != ''
        GROUP BY event_type 
        ORDER BY count DESC 
        LIMIT 20
    ");
    
    echo "<ul>\n";
    foreach ($sampleEvents as $event) {
        echo wp_kses("<li>{$event->event_type} ({$event->count} events)</li>\n");
    }
    echo "</ul>\n";
    
} else {
    echo wp_kses("<p><strong>Found " . count($checkoutEvents) . " checkout events:</strong></p>\n");
    
    foreach ($checkoutEvents as $i => $event) {
        echo wp_kses("<hr>\n");
        echo wp_kses("<h3>Event #" . ($i + 1) . " (ID: {$event->id})</h3>\n");
        echo wp_kses("<p><strong>Basic Info:</strong></p>\n");
        echo wp_kses("<ul>\n");
        echo wp_kses("<li>Event Type: {$event->event_type}</li>\n");
        echo wp_kses("<li>Summary: {$event->summary}</li>\n");
        echo wp_kses("<li>Order ID: {$event->order_id}</li>\n");
        echo wp_kses("<li>Timestamp: {$event->timestamp}</li>\n");
        echo wp_kses("<li>Payload ID: {$event->payload_id}</li>\n");
        echo wp_kses("<li>Process ID: {$event->process_id}</li>\n");
        echo wp_kses("</ul>\n");
        
        // Analyze payload structure
        echo wp_kses("<p><strong>Payload Analysis:</strong></p>\n");
        if (empty($event->payload)) {
            echo wp_kses("<p>❌ <strong>Payload is empty</strong></p>\n");
        } else {
            echo wp_kses("<p>✅ <strong>Payload exists</strong> (length: " . strlen($event->payload) . " characters)</p>\n");
            
            // Try to decode JSON
            $payloadData = json_decode($event->payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo wp_kses("<p>❌ <strong>Invalid JSON:</strong> " . json_last_error_msg() . "</p>\n");
                echo wp_kses("<pre>" . htmlspecialchars(substr($event->payload, 0, 500)) . "...</pre>\n");
            } else {
                echo wp_kses("<p>✅ <strong>Valid JSON payload</strong></p>\n");
                echo wp_kses("<p><strong>Top-level keys:</strong> " . implode(', ', array_keys($payloadData)) . "</p>\n");
                
                // Check for ProcessLogger format
                $isProcessLogger = isset($payloadData['components']) && 
                                 is_array($payloadData['components']) && 
                                 !empty($payloadData['components']);
                echo wp_kses("<p><strong>ProcessLogger format:</strong> " . ($isProcessLogger ? 'YES' : 'NO') . "</p>\n");
                
                // Check for UniversalEvent format indicators
                $hasEventData = isset($payloadData['eventData']) || isset($payloadData['event_data']);
                $hasRawData = isset($payloadData['rawData']);
                $hasEventType = isset($payloadData['eventType']) || isset($payloadData['event_type']);
                
                echo wp_kses("<p><strong>UniversalEvent indicators:</strong></p>\n");
                echo wp_kses("<ul>\n");
                echo wp_kses("<li>Has eventData/event_data: " . ($hasEventData ? 'YES' : 'NO') . "</li>\n");
                echo wp_kses("<li>Has rawData: " . ($hasRawData ? 'YES' : 'NO') . "</li>\n");
                echo wp_kses("<li>Has eventType/event_type: " . ($hasEventType ? 'YES' : 'NO') . "</li>\n");
                echo wp_kses("</ul>\n");
                
                if ($hasRawData) {
                    echo wp_kses("<p><strong>rawData keys:</strong> " . implode(', ', array_keys($payloadData['rawData'])) . "</p>\n");
                    echo wp_kses("<p><strong>rawData empty:</strong> " . (empty($payloadData['rawData']) ? 'YES' : 'NO') . "</p>\n");
                }
                
                // Show structure sample
                echo wp_kses("<h4>Payload Structure Sample:</h4>\n");
                echo wp_kses("<pre>");
                echo wp_kses(htmlspecialchars(json_encode($payloadData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
                echo wp_kses("</pre>\n");
            }
        }
        
        // Test current extractor with this real data
        if (!empty($payloadData)) {
            echo wp_kses("<h4>Current Extractor Test:</h4>\n");
            
            // Load the extractor
            if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\ProcessLoggerComponentExtractor')) {
                require_once __DIR__ . '/src/API/Timeline/ProcessLoggerComponentExtractor.php';
            }
            
            $extractor = new OrderDaemon\CompletionManager\API\Timeline\ProcessLoggerComponentExtractor();
            
            echo wp_kses("<p><strong>isProcessLoggerFormat():</strong> ".
                 ($extractor->isProcessLoggerFormat($payloadData) ? 'TRUE' : 'FALSE') . "</p>\n");
            
            $components = $extractor->extractComponents($payloadData, true);
            echo wp_kses("<p><strong>Extracted components:</strong> " . count($components) . "</p>\n");
            
            foreach ($components as $j => $component) {
                echo wp_kses("<p><strong>Component $j:</strong></p>\n");
                echo wp_kses("<ul>\n");
                echo wp_kses("<li>Event type: " . ($component['event_type'] ?? 'MISSING') . "</li>\n");
                echo wp_kses("<li>Has rawData: " . (isset($component['rawData']) ? 'YES' : 'NO') . "</li>\n");
                if (isset($component['rawData'])) {
                    echo wp_kses("<li>rawData keys: " . implode(', ', array_keys($component['rawData'])) . "</li>\n");
                }
                echo wp_kses("</ul>\n");
            }
        }
    }
}

echo wp_kses("<hr>\n");
echo wp_kses("<h2>Analysis Complete</h2>\n");
echo wp_kses("<p>This analysis will help determine the correct extraction logic needed.</p>\n");
