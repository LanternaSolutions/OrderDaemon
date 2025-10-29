<?php
/**
 * Debug Order #113 Rule Execution Events
 */

// WordPress bootstrap
if (!defined('ABSPATH')) {
    $config_paths = [
        __DIR__ . '/wp-config.php',
        __DIR__ . '/../wp-config.php',
        __DIR__ . '/../../wp-config.php',
        __DIR__ . '/../../../wp-config.php'
    ];
    
    foreach ($config_paths as $config_path) {
        if (file_exists($config_path)) {
            require_once $config_path;
            break;
        }
    }
}

if (!function_exists('wp_load_translations')) {
    require_once ABSPATH . 'wp-settings.php';
}

echo "=== Order #113 Rule Execution Events Analysis ===\n\n";

global $wpdb;
$events = $wpdb->get_results("
    SELECT l.log_id, l.summary, l.order_id, l.event_type, l.details, p.payload
    FROM {$wpdb->prefix}odcm_audit_log l 
    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
    WHERE l.order_id = 113 
    AND l.event_type = 'rule_execution'
    ORDER BY l.timestamp DESC 
    LIMIT 6
");

if (empty($events)) {
    echo "❌ NO RULE EXECUTION EVENTS FOUND for Order #113\n";
    exit;
}

echo "Found " . count($events) . " rule execution events:\n\n";

foreach ($events as $i => $event) {
    echo "--- Event #" . ($i + 1) . " ---\n";
    echo "Summary: {$event->summary}\n";
    echo "Order ID in DB: {$event->order_id}\n";
    echo "Event Type: {$event->event_type}\n";
    
    if (!empty($event->payload)) {
        $payload = json_decode($event->payload, true);
        if ($payload) {
            echo "Payload structure:\n";
            echo "  - order_id: " . ($payload['order_id'] ?? 'MISSING') . "\n";
            echo "  - summary: " . ($payload['summary'] ?? 'MISSING') . "\n";
            echo "  - has context: " . (isset($payload['context']) ? 'YES' : 'NO') . "\n";
            if (isset($payload['context']['order_id'])) {
                echo "  - context order_id: {$payload['context']['order_id']}\n";
            }
            echo "  - has components: " . (isset($payload['components']) ? count($payload['components']) : '0') . "\n";
            
            if (isset($payload['components'])) {
                foreach ($payload['components'] as $j => $component) {
                    echo "    Component {$j}: " . ($component['event_type'] ?? 'no event_type') . "\n";
                    echo "      Label: " . ($component['label'] ?? 'no label') . "\n";
                    if (isset($component['data']['order_id'])) {
                        echo "      Data order_id: {$component['data']['order_id']}\n";
                    }
                }
            }
        } else {
            echo "❌ Invalid JSON payload\n";
        }
    } else {
        echo "❌ No payload data\n";
    }
    echo "\n";
}

// Check for any patterns in the timestamps
echo "=== Timestamp Analysis ===\n";
$timestamps = $wpdb->get_results("
    SELECT l.timestamp, l.summary, l.process_id
    FROM {$wpdb->prefix}odcm_audit_log l 
    WHERE l.order_id = 113 
    AND l.event_type = 'rule_execution'
    ORDER BY l.timestamp ASC
");

foreach ($timestamps as $ts) {
    echo "Time: {$ts->timestamp}, Process: {$ts->process_id}, Summary: {$ts->summary}\n";
}

echo "\n=== Analysis Complete ===\n";
