#!/usr/bin/env php
<?php
/**
 * Test Rule Execution Fix - ProcessLogger Method Signatures
 * 
 * This script tests that the ProcessLogger method calls in UniversalEventProcessor
 * now use the correct signatures and create timeline events properly.
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

use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;

echo "=== Testing ProcessLogger Method Signatures ===\n\n";

// Test ProcessLogger directly first
echo "1. Testing ProcessLogger directly...\n";
$test_logger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger();

try {
    // Test correct method signatures
    echo "   - start() with correct signature... ";
    $test_logger->start('test_rule_execution', ['order_id' => 999, 'summary' => 'Test execution']);
    echo "✅ SUCCESS\n";
    
    echo "   - add_component() with correct signature... ";
    $test_logger->add_component('test_rule', 'Test Rule Executed', ['test' => 'data'], 'info', 'test_key');
    echo "✅ SUCCESS\n";
    
    echo "   - finish() with correct signature... ";
    $test_logger->finish('success', 'Test ProcessLogger completed successfully');
    echo "✅ SUCCESS\n";
    
} catch (\Throwable $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n2. Testing UniversalEventProcessor rule processing...\n";

// Create a test order to trigger rule evaluation
$test_order_data = [
    'status' => 'wc-processing',
    'billing' => [
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'email' => 'test@example.com',
    ],
];

try {
    $test_order = wc_create_order($test_order_data);
    if (is_wp_error($test_order)) {
        throw new Exception('Failed to create test order: ' . $test_order->get_error_message());
    }
    
    $order_id = $test_order->get_id();
    echo "   - Created test order #{$order_id}\n";
    
    // Add a product to make it realistic
    $product = new WC_Product_Simple();
    $product->set_name('Test Product');
    $product->set_price(10.00);
    $product->set_virtual(true);
    $product->save();
    
    $test_order->add_product($product, 1);
    $test_order->calculate_totals();
    $test_order->save();
    
    echo "   - Added test product, order total: $" . $test_order->get_total() . "\n";
    
    // Create a Universal Event to trigger rule processing
    $universal_event_data = [
        'eventType' => 'order_status_changed',
        'sourceGateway' => 'test',
        'channel' => 'system',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order_id,
        'transactionID' => '',
        'status' => 'processing',
        'amount' => (float) $test_order->get_total(),
        'currency' => $test_order->get_currency(),
        'occurredAt' => current_time('c'),
        'receivedAt' => current_time('c'),
        'idempotencyKey' => 'test_' . $order_id . '_' . time(),
        'rawData' => [
            'from_status' => 'pending',
            'to_status' => 'processing',
            'test_mode' => true,
        ]
    ];
    
    echo "   - Created Universal Event data\n";
    
    // Process through UniversalEventProcessor
    $processor = UniversalEventProcessor::instance();
    echo "   - Processing through UniversalEventProcessor... ";
    
    $result = $processor->processEvent($universal_event_data);
    
    if ($result) {
        echo "✅ PROCESSED SUCCESSFULLY\n";
    } else {
        echo "⚠️  NO RULES MATCHED (normal if no rules configured)\n";
    }
    
    // Check timeline for rule execution events
    echo "\n3. Checking timeline events...\n";
    
    global $wpdb;
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
    
    $events = $wpdb->get_results($wpdb->prepare(
        "SELECT l.log_id, l.summary, l.event_type, l.status, l.timestamp,
                COALESCE(p.payload, l.details, '') as payload 
         FROM {$log_table} l 
         LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id
         WHERE l.order_id = %d 
         AND l.timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY l.timestamp DESC",
        $order_id
    ));
    
    if (empty($events)) {
        echo "   ❌ NO TIMELINE EVENTS FOUND\n";
    } else {
        foreach ($events as $event) {
            echo "   📅 Event: {$event->event_type} - {$event->summary}\n";
            echo "      Status: {$event->status}, Time: {$event->timestamp}\n";
            
            // Check for rule execution events specifically
            if ($event->event_type === 'rule_execution' || strpos($event->event_type, 'rule') !== false) {
                echo "      🎯 RULE EXECUTION EVENT FOUND! ✅\n";
            }
            
            // Check payload for ProcessLogger components
            if (!empty($event->payload)) {
                $payload = json_decode($event->payload, true);
                if (isset($payload['components']) && !empty($payload['components'])) {
                    echo "      Components: " . count($payload['components']) . "\n";
                    foreach ($payload['components'] as $component) {
                        if (isset($component['event_type']) && $component['event_type'] === 'rule_execution') {
                            echo "         🔧 Rule execution component: " . ($component['label'] ?? 'No label') . "\n";
                        }
                    }
                }
            }
            echo "\n";
        }
    }
    
    // Clean up test order
    echo "4. Cleaning up...\n";
    $test_order->delete(true);
    $product->delete(true);
    echo "   - Test order and product deleted\n";
    
} catch (\Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    // Try to clean up if order was created
    if (isset($test_order) && $test_order) {
        try {
            $test_order->delete(true);
        } catch (\Throwable $cleanup_error) {
            echo "   ⚠️  Could not clean up test order\n";
        }
    }
    if (isset($product) && $product) {
        try {
            $product->delete(true);
        } catch (\Throwable $cleanup_error) {
            echo "   ⚠️  Could not clean up test product\n";
        }
    }
}

echo "\n=== Test Complete ===\n";
