<?php
/**
 * Debug script to find events that contain rawData
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

echo "<h1>Search for Events with rawData</h1>\n";

global $wpdb;
$logTable = $wpdb->prefix . 'odcm_audit_log';
$payloadTable = $wpdb->prefix . 'odcm_audit_log_payloads';

// Check if payload table exists
$payloadTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$payloadTable}'");

if ($payloadTableExists) {
    echo "<h2>Searching payloads table for rawData...</h2>\n";
    
    // Search for payloads containing rawData
    $eventsWithRawData = $wpdb->get_results("
        SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
               l.event_type, l.source, l.payload_id, 
               SUBSTRING(p.payload, 1, 200) as payload_preview
        FROM {$logTable} l 
        LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id
        WHERE p.payload LIKE '%rawData%'
        ORDER BY l.timestamp DESC 
        LIMIT 10
    ");
    
    if (empty($eventsWithRawData)) {
        echo "<p><strong>❌ NO events found with rawData in payloads table!</strong></p>\n";
    } else {
        echo "<p><strong>✅ Found " . count($eventsWithRawData) . " events with rawData:</strong></p>\n";
        foreach ($eventsWithRawData as $event) {
            echo wp_kses("<p>ID: {$event->id}, Type: {$event->event_type}, Summary: {$event->summary}</p>\n");
        }
    }
    
    // Also search for full payloads with the pattern
    echo "<h3>Getting full payloads with rawData...</h3>\n";
    $fullPayloads = $wpdb->get_results("
        SELECT l.log_id as id, l.event_type, l.summary, p.payload
        FROM {$logTable} l 
        LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id
        WHERE p.payload LIKE '%rawData%'
        LIMIT 5
    ");
    
    foreach ($fullPayloads as $event) {
        echo wp_kses("<hr>\n");
        echo wp_kses("<h4>Event ID: {$event->id} ({$event->event_type})</h4>\n");
        $payloadData = json_decode($event->payload, true);
        if ($payloadData && isset($payloadData['rawData'])) {
            echo wp_kses("<p><strong>✅ Has rawData with keys:</strong> " . implode(', ', array_keys($payloadData['rawData'])) . "</p>\n");
            echo wp_kses("<pre>" . htmlspecialchars(json_encode($payloadData, JSON_PRETTY_PRINT)) . "</pre>\n");
        }
    }
}

// Also check main table details column
echo "<h2>Checking main table details column...</h2>\n";
$eventsWithRawDataInDetails = $wpdb->get_results("
    SELECT log_id as id, timestamp, event_type, summary, 
           SUBSTRING(details, 1, 200) as details_preview
    FROM {$logTable}
    WHERE details LIKE '%rawData%'
    ORDER BY timestamp DESC 
    LIMIT 5
");

if (empty($eventsWithRawDataInDetails)) {
    echo "<p><strong>❌ NO events found with rawData in details column!</strong></p>\n";
} else {
    echo "<p><strong>✅ Found " . count($eventsWithRawDataInDetails) . " events with rawData in details:</strong></p>\n";
    foreach ($eventsWithRawDataInDetails as $event) {
        echo wp_kses("<p>ID: {$event->id}, Type: {$event->event_type}</p>\n");
    }
}

// Let's also check what types of events exist that might contain rich data
echo wp_kses("<h2>Event Types That Might Contain Rich Data</h2>\n");
$richEventTypes = $wpdb->get_results("
    SELECT DISTINCT l.event_type, COUNT(*) as count,
           MAX(CHAR_LENGTH(COALESCE(p.payload, l.details, ''))) as max_payload_size
    FROM {$logTable} l 
    " . ($payloadTableExists ? "LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id" : "") . "
    WHERE CHAR_LENGTH(COALESCE(" . ($payloadTableExists ? "p.payload" : "l.details") . ", '')) > 500
    GROUP BY l.event_type 
    ORDER BY max_payload_size DESC
    LIMIT 15
");

echo "<table border='1'>\n";
echo "<tr><th>Event Type</th><th>Count</th><th>Max Payload Size</th></tr>\n";
foreach ($richEventTypes as $eventType) {
    echo wp_kses("<tr><td>{$eventType->event_type}</td><td>{$eventType->count}</td><td>{$eventType->max_payload_size}</td></tr>\n");
}
echo "</table>\n";

echo "<hr>\n";
echo "<h2>Conclusion</h2>\n";
echo "<p>This search will help us understand if rawData is being stored anywhere in the database.</p>\n";
