<?php
/**
 * Debug Multiple Rule Execution Triggers for Order #113
 * Analyze what events are triggering multiple rule evaluations
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

echo "=== Multiple Rule Trigger Analysis for Order #113 ===\n\n";

global $wpdb;

// Get all events for Order #113 in chronological order
$all_events = $wpdb->get_results("
    SELECT l.timestamp, l.event_type, l.summary, l.source, l.process_id,
           SUBSTRING(l.timestamp, 18, 2) as seconds
    FROM {$wpdb->prefix}odcm_audit_log l 
    WHERE l.order_id = 113 
    ORDER BY l.timestamp ASC
");

echo "=== Complete Order #113 Event Timeline ===\n";
$current_second = null;
foreach ($all_events as $event) {
    if ($current_second !== $event->seconds) {
        $current_second = $event->seconds;
        echo "\n--- Second :{$event->seconds} ---\n";
    }
    echo "  {$event->event_type}: {$event->summary}\n";
    echo "    Source: {$event->source}, Process: " . substr($event->process_id, -10) . "\n";
}

// Look for Universal Event processing events specifically
echo "\n\n=== Universal Event Processing Events ===\n";
$universal_events = $wpdb->get_results("
    SELECT l.timestamp, l.event_type, l.summary, l.status, p.payload
    FROM {$wpdb->prefix}odcm_audit_log l 
    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
    WHERE l.order_id = 113 
    AND l.event_type LIKE '%universal_event%'
    ORDER BY l.timestamp ASC
");

if (empty($universal_events)) {
    echo "❌ NO Universal Event processing events found\n";
} else {
    foreach ($universal_events as $ue) {
        echo "Time: {$ue->timestamp}\n";
        echo "Type: {$ue->event_type}\n";
        echo "Status: {$ue->status}\n";
        echo "Summary: {$ue->summary}\n";
        
        if (!empty($ue->payload)) {
            $payload = json_decode($ue->payload, true);
            if ($payload && isset($payload['event_type'])) {
                echo "Triggered by: {$payload['event_type']}\n";
                echo "Source gateway: " . ($payload['source_gateway'] ?? 'unknown') . "\n";
                echo "Idempotency key: " . ($payload['idempotency_key'] ?? 'unknown') . "\n";
            }
        }
        echo "\n";
    }
}

// Check for status change events that might trigger multiple Universal Events
echo "=== Status Change Events ===\n";
$status_events = $wpdb->get_results("
    SELECT l.timestamp, l.event_type, l.summary, p.payload
    FROM {$wpdb->prefix}odcm_audit_log l 
    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
    WHERE l.order_id = 113 
    AND l.event_type LIKE '%status%'
    ORDER BY l.timestamp ASC
");

foreach ($status_events as $se) {
    echo "Time: {$se->timestamp}\n";
    echo "Type: {$se->event_type}\n";
    echo "Summary: {$se->summary}\n";
    
    if (!empty($se->payload)) {
        $payload = json_decode($se->payload, true);
        if ($payload) {
            echo "From status: " . ($payload['from_status'] ?? $payload['rawData']['from_status'] ?? 'unknown') . "\n";
            echo "To status: " . ($payload['to_status'] ?? $payload['rawData']['to_status'] ?? 'unknown') . "\n";
        }
    }
    echo "\n";
}

// Look for duplicate idempotency keys or correlation patterns
echo "=== Duplication Pattern Analysis ===\n";
$rule_execution_times = $wpdb->get_results("
    SELECT l.timestamp, 
           SUBSTRING(l.timestamp, 18, 2) as seconds,
           COUNT(*) as count_in_second
    FROM {$wpdb->prefix}odcm_audit_log l 
    WHERE l.order_id = 113 
    AND l.event_type = 'rule_execution'
    GROUP BY SUBSTRING(l.timestamp, 1, 19)
    ORDER BY l.timestamp ASC
");

echo "Rule executions by second:\n";
foreach ($rule_execution_times as $time) {
    echo "  :{$time->seconds} - {$time->count_in_second} rule execution(s)\n";
    if ($time->count_in_second > 1) {
        echo "    ⚠️  MULTIPLE EXECUTIONS IN SAME SECOND!\n";
    }
}

echo "\n=== Analysis Complete ===\n";
echo "This will help identify what's causing multiple rule evaluations.\n";
