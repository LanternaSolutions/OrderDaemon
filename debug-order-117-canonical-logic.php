<?php
/**
 * Debug Order #117 Canonical Logic
 * Investigate why checkout_processed events are still creating rule execution timeline events
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

echo "=== Order #117 Canonical Logic Debug ===\n\n";

global $wpdb;

// 1. Check all Universal Event processing entries for Order #117
echo "1. Universal Event Processing Events for Order #117:\n";
$universal_events = $wpdb->get_results("
    SELECT l.timestamp, l.event_type, l.summary, l.status, p.payload
    FROM {$wpdb->prefix}odcm_audit_log l 
    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
    WHERE l.order_id = 117 
    AND l.event_type LIKE '%universal_event%'
    ORDER BY l.timestamp ASC
");

if (empty($universal_events)) {
    echo "   ❌ NO Universal Event processing events found\n";
} else {
    foreach ($universal_events as $ue) {
        echo "   Time: {$ue->timestamp}\n";
        echo "   Type: {$ue->event_type}\n";
        echo "   Status: {$ue->status}\n";
        echo "   Summary: {$ue->summary}\n";
        
        if (!empty($ue->payload)) {
            $payload = json_decode($ue->payload, true);
            if ($payload && isset($payload['event_type'])) {
                echo "   Triggered by: {$payload['event_type']}\n";
                echo "   Source gateway: " . ($payload['source_gateway'] ?? 'unknown') . "\n";
                echo "   Idempotency key: " . ($payload['idempotency_key'] ?? 'unknown') . "\n";
                echo "   Processing result: " . ($payload['processing_result'] ? 'true' : 'false') . "\n";
                echo "   Has components: " . (isset($payload['components']) ? count($payload['components']) : '0') . "\n";
            }
        }
        echo "\n";
    }
}

// 2. Check all rule execution events for Order #117
echo "2. Rule Execution Events for Order #117:\n";
$rule_events = $wpdb->get_results("
    SELECT l.timestamp, l.summary, l.status, p.payload
    FROM {$wpdb->prefix}odcm_audit_log l 
    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
    WHERE l.order_id = 117 
    AND l.event_type = 'rule_execution'
    ORDER BY l.timestamp ASC
");

foreach ($rule_events as $i => $event) {
    echo "   --- Rule Execution Event #" . ($i + 1) . " ---\n";
    echo "   Time: {$event->timestamp}\n";
    echo "   Summary: {$event->summary}\n";
    echo "   Status: {$event->status}\n";
    
    if (!empty($event->payload)) {
        $payload = json_decode($event->payload, true);
        if ($payload) {
            echo "   Process type: " . ($payload['process_type'] ?? 'MISSING') . "\n";
            echo "   Correlation ID: " . ($payload['correlation_id'] ?? 'MISSING') . "\n";
            echo "   Component count: " . ($payload['component_count'] ?? 'MISSING') . "\n";
            echo "   Source: " . ($payload['source'] ?? 'MISSING') . "\n";
            
            // Check for components
            if (isset($payload['components'])) {
                echo "   Components: " . count($payload['components']) . "\n";
                foreach ($payload['components'] as $j => $component) {
                    echo "     Component {$j}: " . ($component['event_type'] ?? 'no event_type') . "\n";
                    echo "       Label: " . ($component['label'] ?? 'no label') . "\n";
                    if (isset($component['data']['canonical_event'])) {
                        echo "       Canonical event: " . ($component['data']['canonical_event'] ? 'YES' : 'NO') . "\n";
                    }
                    if (isset($component['data']['trigger_context'])) {
                        echo "       Trigger context: " . $component['data']['trigger_context'] . "\n";
                    }
                }
            }
        }
    }
    echo "\n";
}

// 3. Test the canonical logic directly
echo "3. Testing Canonical Logic Directly:\n";

// Load the UniversalEventProcessor to test the method
use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;

try {
    $processor = UniversalEventProcessor::instance();
    
    // Test the logic using reflection since the method is private
    $reflection = new ReflectionClass($processor);
    $method = $reflection->getMethod('isCanonicalTimelineEvent');
    $method->setAccessible(true);
    
    $test_events = [
        'checkout_processed',
        'order_created', 
        'order_status_changed',
        'payment_completed',
        'order_check_scheduled'
    ];
    
    foreach ($test_events as $event_type) {
        $is_canonical = $method->invoke($processor, $event_type);
        echo "   {$event_type}: " . ($is_canonical ? 'CANONICAL ✅' : 'NON-CANONICAL ❌') . "\n";
    }
    
} catch (\Throwable $e) {
    echo "   ❌ Error testing canonical logic: " . $e->getMessage() . "\n";
}

// 4. Check if there are multiple checkout_processed events
echo "\n4. Checking for Multiple Checkout Events:\n";
$checkout_events = $wpdb->get_results("
    SELECT l.timestamp, l.event_type, l.summary, p.payload
    FROM {$wpdb->prefix}odcm_audit_log l 
    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
    WHERE l.order_id = 117 
    AND (l.event_type LIKE '%checkout%' OR l.summary LIKE '%checkout%')
    ORDER BY l.timestamp ASC
");

foreach ($checkout_events as $event) {
    echo "   Time: {$event->timestamp}\n";
    echo "   Type: {$event->event_type}\n";
    echo "   Summary: {$event->summary}\n";
    
    if (!empty($event->payload)) {
        $payload = json_decode($event->payload, true);
        if ($payload && isset($payload['event_type'])) {
            echo "   Event type in payload: {$payload['event_type']}\n";
            echo "   Idempotency key: " . ($payload['idempotency_key'] ?? 'unknown') . "\n";
        }
    }
    echo "\n";
}

echo "=== Debug Complete ===\n";
echo "This analysis will help identify why canonical logic isn't working as expected.\n";
