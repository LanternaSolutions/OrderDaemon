<?php
/**
 * Examine a specific event that contains rawData
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

echo "<h1>Examine Event with rawData</h1>\n";

global $wpdb;
$logTable = $wpdb->prefix . 'odcm_audit_log';
$payloadTable = $wpdb->prefix . 'odcm_audit_log_payloads';

// Get event 838 (one with rawData)
$event = $wpdb->get_row($wpdb->prepare("
    SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
           p.payload
    FROM {$logTable} l 
    LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id
    WHERE l.log_id = %d
", 838), 'ARRAY_A');

if (!$event) {
    echo "<p><strong>Event 838 not found!</strong></p>\n";
    exit;
}

echo wp_kses("<h2>Event 838 Details</h2>\n", 'post');
echo wp_kses("<ul>\n", 'post');
echo wp_kses("<li>Event Type: {$event['event_type']}</li>\n", 'post');
echo wp_kses("<li>Summary: {$event['summary']}</li>\n", 'post');
echo wp_kses("<li>Order ID: {$event['order_id']}</li>\n", 'post');
echo wp_kses("<li>Process ID: {$event['process_id']}</li>\n", 'post');
echo wp_kses("</ul>\n", 'post');

$payloadData = json_decode($event['payload'], true);
if (!$payloadData) {
    echo wp_kses("<p><strong>Failed to decode payload JSON!</strong></p>\n", 'post');
    exit;
}

echo wp_kses("<h3>Payload Structure</h3>\n", 'post');
echo wp_kses("<p><strong>Top-level keys:</strong> " . implode(', ', array_keys($payloadData)) . "</p>\n", 'post');
echo wp_kses("<p><strong>Has rawData:</strong> " . (isset($payloadData['rawData']) ? 'YES' : 'NO') . "</p>\n", 'post');

if (isset($payloadData['rawData'])) {
    echo wp_kses("<p><strong>rawData keys:</strong> " . implode(', ', array_keys($payloadData['rawData'])) . "</p>\n", 'post');
    echo wp_kses("<p><strong>rawData size:</strong> " . strlen(json_encode($payloadData['rawData'])) . " characters</p>\n", 'post');
}

echo wp_kses("<h3>Components</h3>\n", 'post');
if (isset($payloadData['components'])) {
    echo wp_kses("<p><strong>Component count:</strong> " . count($payloadData['components']) . "</p>\n", 'post');
    foreach ($payloadData['components'] as $i => $component) {
        echo wp_kses("<h4>Component $i:</h4>\n", 'post');
        echo wp_kses("<ul>\n", 'post');
        echo wp_kses("<li>Event type: " . ($component['event_type'] ?? 'MISSING') . "</li>\n", 'post');
        echo wp_kses("<li>Label: " . ($component['label'] ?? 'MISSING') . "</li>\n", 'post');
        echo wp_kses("<li>Data keys: " . (isset($component['data']) ? implode(', ', array_keys($component['data'])) : 'NONE') . "</li>\n", 'post');
        echo wp_kses("</ul>\n", 'post');
    }
}

echo wp_kses("<h3>Full Payload</h3>\n", 'post');
echo wp_kses("<pre>", 'post');
echo wp_kses(htmlspecialchars(json_encode($payloadData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)), 'post');
echo wp_kses("</pre>\n", 'post');

// Now test the current extractor with this event
echo wp_kses("<hr>\n", 'post');
echo wp_kses("<h2>Test Current Extractor</h2>\n", 'post');

if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\ProcessLoggerComponentExtractor')) {
    require_once __DIR__ . '/src/API/Timeline/ProcessLoggerComponentExtractor.php';
}

$extractor = new OrderDaemon\CompletionManager\API\Timeline\ProcessLoggerComponentExtractor();

echo wp_kses( "<p><strong>isProcessLoggerFormat():</strong> " .
     ($extractor->isProcessLoggerFormat($payloadData) ? 'TRUE' : 'FALSE') . "</p>\n", 'post');

$components = $extractor->extractComponents($payloadData, true);
echo wp_kses("<p><strong>Extracted components:</strong> " . count($components) . "</p>\n", 'post');

foreach ($components as $j => $component) {
    echo wp_kses("<h4>Extracted Component $j:</h4>\n", 'post');
    echo wp_kses("<ul>\n", 'post');
    echo wp_kses("<li>Event type: " . ($component['event_type'] ?? 'MISSING') . "</li>\n", 'post');
    echo wp_kses("<li>Has rawData: " . (isset($component['rawData']) ? 'YES' : 'NO') . "</li>\n", 'post');
    if (isset($component['rawData'])) {
        echo wp_kses("<li>rawData keys: " . implode(', ', array_keys($component['rawData'])) . "</li>\n", 'post');
        echo wp_kses("<li>rawData size: " . strlen(json_encode($component['rawData'])) . " chars</li>\n", 'post');
    }
    echo wp_kses("</ul>\n", 'post');
}

echo wp_kses("<hr>\n", 'post');
echo wp_kses("<h2>Analysis Complete</h2>\n", 'post');
