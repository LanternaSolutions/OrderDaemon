<?php
/**
 * Debug script to check checkout event rendering pipeline
 */

// WordPress bootstrap
if (!defined('ABSPATH')) {
    // Try to find wp-config.php
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

// Ensure our plugin classes are loaded
if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\ProcessLoggerComponentExtractor')) {
    require_once __DIR__ . '/src/API/Timeline/ProcessLoggerComponentExtractor.php';
}
if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\OrderRenderer')) {
    require_once __DIR__ . '/src/View/PayloadRenderer/BaseRenderer.php';
    require_once __DIR__ . '/src/View/PayloadRenderer/PayloadComponentUIToolkit.php';
    require_once __DIR__ . '/src/View/PayloadRenderer/OrderRenderer.php';
}

// Enable debug logging
if (!defined('ODCM_DEBUG')) {
    define('ODCM_DEBUG', true);
}

echo "<h1>Checkout Event Rendering Debug</h1>\n";

// Test 1: Check if we have any checkout events in the database
global $wpdb;
$table_name = $wpdb->prefix . 'odcm_completion_log';

echo "<h2>Test 1: Database Checkout Events</h2>\n";
$checkout_events = $wpdb->get_results($wpdb->prepare("
    SELECT id, order_id, summary, payload, timestamp 
    FROM {$table_name} 
    WHERE summary LIKE %s OR summary LIKE %s 
    ORDER BY timestamp DESC 
    LIMIT 5
", '%checkout%', '%block_checkout%'));

if (empty($checkout_events)) {
    echo "<p><strong>No checkout events found in database.</strong></p>\n";
    echo "<p>Let's create a synthetic checkout event to test the rendering pipeline.</p>\n";
    
    // Test 2: Create synthetic checkout event
    echo "<h2>Test 2: Synthetic Checkout Event</h2>\n";
    
    $synthetic_payload = [
        'components' => [
            [
                'event_type' => 'checkout_processed',
                'label' => 'Checkout Completed',
                'ts' => current_time('mysql'),
                'level' => 'info',
                'data' => [
                    'order_id' => 12345,
                    'status' => 'processing',
                    'payment_method' => 'stripe',
                    'total' => 99.99,
                    'currency' => 'USD',
                    'event_type' => 'checkout_processed'
                ]
            ]
        ],
        'rawData' => [
            'checkout_context' => [
                'gateway' => 'stripe',
                'transaction_id' => 'ch_1234567890',
                'customer_email' => 'test@example.com',
                'billing_address' => [
                    'street' => '123 Main St',
                    'city' => 'Test City',
                    'state' => 'TS',
                    'zip' => '12345'
                ],
                'line_items' => [
                    [
                        'product_id' => 100,
                        'quantity' => 2,
                        'price' => 49.99
                    ]
                ]
            ],
            'webhook_payload' => [
                'id' => 'evt_1234567890',
                'type' => 'payment_intent.succeeded',
                'amount' => 9999,
                'currency' => 'usd'
            ]
        ],
        'cid' => 'test-correlation-123',
        'type' => 'checkout_processing'
    ];
    
} else {
    echo "<p><strong>Found " . count($checkout_events) . " checkout events:</strong></p>\n";
    foreach ($checkout_events as $event) {
        echo "<p>ID: {$event->id}, Order: {$event->order_id}, Summary: {$event->summary}</p>\n";
    }
    
    // Use the first event for testing
    $test_event = $checkout_events[0];
    $synthetic_payload = json_decode($test_event->payload, true);
    
    echo "<h2>Test 2: Real Checkout Event Analysis</h2>\n";
    echo "<p><strong>Event ID:</strong> {$test_event->id}</p>\n";
    echo "<p><strong>Payload Structure:</strong></p>\n";
}

// Debug the payload structure
echo "<h3>Payload Analysis</h3>\n";
echo "<pre>";
echo "Top-level keys: " . implode(', ', array_keys($synthetic_payload)) . "\n";
echo "Has components: " . (isset($synthetic_payload['components']) ? 'YES' : 'NO') . "\n";
echo "Has rawData: " . (isset($synthetic_payload['rawData']) ? 'YES' : 'NO') . "\n";

if (isset($synthetic_payload['rawData'])) {
    echo "rawData keys: " . implode(', ', array_keys($synthetic_payload['rawData'])) . "\n";
    echo "rawData empty: " . (empty($synthetic_payload['rawData']) ? 'YES' : 'NO') . "\n";
}

if (isset($synthetic_payload['components'])) {
    echo "Components count: " . count($synthetic_payload['components']) . "\n";
    foreach ($synthetic_payload['components'] as $i => $component) {
        echo "Component $i event_type: " . ($component['event_type'] ?? 'MISSING') . "\n";
        echo "Component $i has data: " . (isset($component['data']) ? 'YES' : 'NO') . "\n";
    }
}
echo "</pre>\n";

// Test 3: Component extraction
echo "<h2>Test 3: Component Extraction</h2>\n";
$extractor = new OrderDaemon\CompletionManager\API\Timeline\ProcessLoggerComponentExtractor();

echo "<p><strong>Testing ProcessLoggerComponentExtractor...</strong></p>\n";
$extracted_components = $extractor->extractComponents($synthetic_payload, true);

echo "<p>Extracted " . count($extracted_components) . " components</p>\n";

foreach ($extracted_components as $i => $component) {
    echo "<h4>Component $i:</h4>\n";
    echo "<pre>";
    echo "Event type: " . ($component['event_type'] ?? 'MISSING') . "\n";
    echo "Top-level keys: " . implode(', ', array_keys($component)) . "\n";
    echo "Has rawData: " . (isset($component['rawData']) ? 'YES' : 'NO') . "\n";
    if (isset($component['rawData'])) {
        echo "rawData keys: " . implode(', ', array_keys($component['rawData'])) . "\n";
        echo "rawData empty: " . (empty($component['rawData']) ? 'YES' : 'NO') . "\n";
    }
    echo "Has data: " . (isset($component['data']) ? 'YES' : 'NO') . "\n";
    if (isset($component['data'])) {
        echo "data keys: " . implode(', ', array_keys($component['data'])) . "\n";
    }
    echo "</pre>\n";
}

// Test 4: Render the checkout component
echo "<h2>Test 4: Component Rendering</h2>\n";

if (!empty($extracted_components)) {
    // Find the checkout component
    $checkout_component = null;
    foreach ($extracted_components as $component) {
        if (in_array($component['event_type'] ?? '', ['checkout_processed', 'block_checkout_processed'])) {
            $checkout_component = $component;
            break;
        }
    }
    
    if ($checkout_component) {
        echo "<p><strong>Found checkout component, testing renderer...</strong></p>\n";
        
        $renderer = new OrderDaemon\CompletionManager\View\PayloadRenderer\OrderRenderer();
        $toolkit = new OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
        
        // Test the specific rendering method
        echo "<h4>Direct renderSpecificContent call:</h4>\n";
        
        ob_start();
        $reflection = new ReflectionClass($renderer);
        $method = $reflection->getMethod('renderSpecificContent');
        $method->setAccessible(true);
        
        $result = $method->invoke($renderer, $checkout_component, $checkout_component['event_type'], $toolkit);
        $debug_output = ob_get_clean();
        
        echo "<h5>Debug Output:</h5>\n";
        echo "<pre>" . htmlspecialchars($debug_output) . "</pre>\n";
        
        echo "<h5>Rendered Output:</h5>\n";
        echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f9f9f9;'>\n";
        echo $result;
        echo "</div>\n";
        
        // Also test the full render method
        echo "<h4>Full render method call:</h4>\n";
        $timeline_data = [
            'label' => 'Checkout Completed',
            'ts' => current_time('mysql'),
            'level' => 'info'
        ];
        
        ob_start();
        $full_result = $renderer->render($checkout_component, $checkout_component['event_type'], $timeline_data);
        $full_debug_output = ob_get_clean();
        
        echo "<h5>Full Debug Output:</h5>\n";
        echo "<pre>" . htmlspecialchars($full_debug_output) . "</pre>\n";
        
        echo "<h5>Full Rendered Output:</h5>\n";
        echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f9f9f9;'>\n";
        echo $full_result;
        echo "</div>\n";
        
    } else {
        echo "<p><strong>No checkout component found in extracted components.</strong></p>\n";
    }
} else {
    echo "<p><strong>No components were extracted.</strong></p>\n";
}

echo "<h2>Debug Complete</h2>\n";
echo "<p>Check the error log for detailed debug messages.</p>\n";
