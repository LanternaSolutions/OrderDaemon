<?php
/**
 * Test script for debugging the timeline adapter system
 *
 * This script tests the adapter system with sample event data to identify
 * why all events are falling back to the fallback view.
 */

// Set up debug mode
define('ODCM_DEBUG', true);
define('WP_DEBUG', true);

// Include necessary files
require_once __DIR__ . '/src/API/Timeline/DisplayAdapter.php';
require_once __DIR__ . '/src/API/Timeline/AdapterRegistry.php';
require_once __DIR__ . '/src/API/Timeline/OrderEventAdapter.php';
require_once __DIR__ . '/src/API/Timeline/PaymentEventAdapter.php';
require_once __DIR__ . '/src/API/Timeline/RuleExecutionAdapter.php';
require_once __DIR__ . '/src/API/Timeline/GenericEventAdapter.php';

// Test event data for different event types
$testEvents = [
    'order_created' => [
        'event_type' => 'order_created',
        'order_id' => 100,
        'timestamp' => '2025-12-23 18:01:29',
        'data' => [
            'order_id' => 100,
            'customer_id' => 42,
            'order_total' => 99.99,
            'payment_method' => 'stripe',
            'status' => 'pending'
        ]
    ],
    'status_changed' => [
        'event_type' => 'status_changed',
        'order_id' => 100,
        'timestamp' => '2025-12-23 18:05:12',
        'data' => [
            'order_id' => 100,
            'from_status' => 'pending',
            'to_status' => 'processing',
            'changed_by' => 1
        ]
    ],
    'payment_completed' => [
        'event_type' => 'payment_completed',
        'order_id' => 100,
        'timestamp' => '2025-12-23 18:07:45',
        'data' => [
            'order_id' => 100,
            'amount' => 99.99,
            'payment_method' => 'stripe',
            'transaction_id' => 'txn_123456789'
        ]
    ],
    'rule_execution_completed' => [
        'event_type' => 'rule_execution_completed',
        'order_id' => 100,
        'timestamp' => '2025-12-23 18:10:33',
        'data' => [
            'order_id' => 100,
            'rule_id' => 'order_auto_complete',
            'execution_status' => 'completed',
            'actions_executed' => ['send_notification', 'update_order_status']
        ]
    ],
    'unknown_event' => [
        'event_type' => 'custom_unknown_event',
        'order_id' => 100,
        'timestamp' => '2025-12-23 18:15:22',
        'data' => [
            'custom_field' => 'custom_value'
        ]
    ]
];

echo "=== Timeline Adapter Debugging Test ===\n\n";

// Test each event type
foreach ($testEvents as $testName => $eventData) {
    echo "Testing event: {$testName}\n";
    echo "Event type: {$eventData['event_type']}\n";

    try {
        // Clear cache to ensure fresh adapter creation
        OrderDaemon\CompletionManager\API\Timeline\AdapterRegistry::clearCache();

        // Get adapter for this event
        $adapter = OrderDaemon\CompletionManager\API\Timeline\AdapterRegistry::getAdapterForEvent($eventData);

        echo "Adapter class: " . get_class($adapter) . "\n";

        // Extract display data
        $displayData = $adapter->extractDisplayData($eventData);

        echo "Display data extracted successfully\n";
        echo "Display sections: " . count($displayData['display_sections'] ?? []) . "\n";
        echo "Detail sections: " . count($displayData['detail_sections'] ?? []) . "\n";

        // Check if this is a fallback adapter
        $isFallback = (get_class($adapter) === 'OrderDaemon\\CompletionManager\\API\\Timeline\\GenericEventAdapter');
        echo "Is fallback adapter: " . ($isFallback ? 'YES' : 'NO') . "\n";

        echo "Result: SUCCESS\n\n";

    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n\n";
    } catch (Throwable $e) {
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n\n";
    }
}

echo "=== Test Complete ===\n";

// Test class existence
echo "\n=== Class Existence Check ===\n";
$adapterClasses = [
    'OrderDaemon\\CompletionManager\\API\\Timeline\\OrderEventAdapter',
    'OrderDaemon\\CompletionManager\\API\\Timeline\\PaymentEventAdapter',
    'OrderDaemon\\CompletionManager\\API\\Timeline\\RuleExecutionAdapter',
    'OrderDaemon\\CompletionManager\\API\\Timeline\\GenericEventAdapter',
    'OrderDaemon\\CompletionManager\\API\\Timeline\\DisplayAdapter'
];

foreach ($adapterClasses as $className) {
    $exists = class_exists($className);
    echo "$className: " . ($exists ? 'EXISTS' : 'MISSING') . "\n";
}

echo "\n=== Cache Statistics ===\n";
$cacheStats = OrderDaemon\CompletionManager\API\Timeline\AdapterRegistry::getCacheStats();
print_r($cacheStats);
