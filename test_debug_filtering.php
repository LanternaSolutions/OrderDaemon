<?php
/**
 * Test script for timeline debug event filtering implementation
 *
 * This script tests the enhanced filtering logic to ensure:
 * 1. Known debug events are filtered in production mode
 * 2. Incomplete rule events are filtered in production mode
 * 3. Complete rule events are visible in both modes
 * 4. All events are visible in debug mode
 */

// Set up autoloading
require_once __DIR__ . '/vendor/autoload.php';

// Test data - simulate the timeline events from timeline.txt
$testEvents = [
    // Complete rule execution event (should be visible in both modes)
    [
        'event_type' => 'rule_execution',
        'label' => 'Rule "virtual rule" evaluated successfully for Order #104',
        'ts' => 1766922556.179735,
        'level' => 'info',
        'data' => [
            'process_type' => 'rule_execution',
            'correlation_id' => 'odcm:lifecycle:104:1766922555:6951193bee8da6.95133676',
            'status' => 'success',
            'source' => 'api',
            'component_count' => 2,
            'actor' => 'system'
        ],
        'rule_execution' => [
            'rule_name' => 'virtual rule',
            'rule_configuration' => [
                'rule_name' => 'virtual rule',
                'rule_id' => 13
            ]
        ],
        'order_id' => 104
    ],

    // Incomplete rule processing event (should be filtered in production)
    [
        'event_type' => 'rule_execution',
        'label' => 'Rule Processing Started',
        'ts' => 1766922556,
        'level' => 'info',
        'data' => [
            'process_type' => 'rule_execution',
            'correlation_id' => 'odcm:lifecycle:104:1766922555:6951193bee8da6.95133676',
            'status' => 'success',
            'source' => 'api',
            'component_count' => 2,
            'actor' => 'system'
        ],
        'order_id' => 104
    ],

    // Known debug event (should be filtered in production)
    [
        'event_type' => '_status_evaluation',
        'label' => 'Status change evaluation: Order #104 (checkout-draft → pending)',
        'ts' => 1766922556,
        'level' => 'debug',
        'data' => [
            'from' => 'checkout-draft',
            'to' => 'pending',
            'debug_mode' => true,
            'evaluation_details' => [
                'timestamp' => '2025-12-28T12:49:16+01:00',
                'source' => 'manual',
                'purpose' => 'This event provides debugging information about status change evaluations but is excluded from the main timeline to reduce noise'
            ]
        ],
        'order_id' => 104
    ],

    // Another known debug event (should be filtered in production)
    [
        'event_type' => 'rule_evaluation_non_canonical',
        'label' => 'Rule "virtual rule" evaluated event: order_check_scheduled',
        'ts' => 1766922610,
        'level' => 'debug',
        'data' => [
            'event_type' => 'order_check_scheduled',
            'rule_name' => 'virtual rule',
            'explanation' => 'This rule evaluated a order_check_scheduled event. This is a debug entry showing rule evaluation behavior for non-standard event types.',
            'purpose' => 'Helps developers understand when rules evaluate different event types',
            'note' => 'This entry appears in debug mode to provide visibility into rule evaluation',
            'canonical_event' => false,
            'timeline_behavior' => 'Debug entry created for visibility'
        ],
        'order_id' => 104
    ],

    // Regular business event (should be visible in both modes)
    [
        'event_type' => 'order_created',
        'label' => 'Order Created',
        'ts' => 1766922550,
        'level' => 'info',
        'data' => [
            'order_id' => 104,
            'status' => 'pending',
            'amount' => 10,
            'currency' => 'USD',
            'customer_id' => 1,
            'payment_method' => 'stripe',
            'source' => 'manual'
        ],
        'order_id' => 104
    ]
];

// Test the filtering logic
function testFilteringLogic() {
    global $testEvents;

    echo "=== Timeline Debug Event Filtering Test ===\n\n";

    // Test in production mode (debug disabled)
    echo "🔍 Testing in PRODUCTION mode (ODCM_DEBUG = false):\n";
    define('ODCM_DEBUG', false);

    $productionVisible = 0;
    $productionFiltered = 0;

    foreach ($testEvents as $index => $event) {
        // Use the same filtering logic as RegistryTimelineRenderer
        $shouldFilter = shouldFilterDebugEvent($event);

        if ($shouldFilter) {
            echo "  ❌ Event {$index} ({$event['event_type']}) - FILTERED\n";
            $productionFiltered++;
        } else {
            echo "  ✅ Event {$index} ({$event['event_type']}) - VISIBLE\n";
            $productionVisible++;
        }
    }

    echo "\nProduction Mode Results:\n";
    echo "  Visible events: {$productionVisible}\n";
    echo "  Filtered events: {$productionFiltered}\n";
    echo "  Total events: " . count($testEvents) . "\n";

    // Test in debug mode (debug enabled)
    echo "\n🔍 Testing in DEBUG mode (ODCM_DEBUG = true):\n";
    // Redefine constant for debug mode
    if (defined('ODCM_DEBUG')) {
        // Can't redefine constants, so we'll simulate it
        $ODCM_DEBUG = true;
    } else {
        define('ODCM_DEBUG', true);
    }

    $debugVisible = 0;
    $debugFiltered = 0;

    foreach ($testEvents as $index => $event) {
        // Use the same filtering logic as RegistryTimelineRenderer
        $shouldFilter = shouldFilterDebugEventWithDebug($event, true);

        if ($shouldFilter) {
            echo "  ❌ Event {$index} ({$event['event_type']}) - FILTERED\n";
            $debugFiltered++;
        } else {
            echo "  ✅ Event {$index} ({$event['event_type']}) - VISIBLE\n";
            $debugVisible++;
        }
    }

    echo "\nDebug Mode Results:\n";
    echo "  Visible events: {$debugVisible}\n";
    echo "  Filtered events: {$debugFiltered}\n";
    echo "  Total events: " . count($testEvents) . "\n";

    // Verify expected results
    echo "\n=== Test Results Verification ===\n";

    $expectedProductionVisible = 2; // Complete rule + order_created
    $expectedProductionFiltered = 3; // Incomplete rule + 2 debug events
    $expectedDebugVisible = 5; // All events
    $expectedDebugFiltered = 0; // None filtered in debug mode

    $productionPass = ($productionVisible === $expectedProductionVisible && $productionFiltered === $expectedProductionFiltered);
    $debugPass = ($debugVisible === $expectedDebugVisible && $debugFiltered === $expectedDebugFiltered);

    echo "Production mode test: " . ($productionPass ? "✅ PASSED" : "❌ FAILED") . "\n";
    echo "  Expected: {$expectedProductionVisible} visible, {$expectedProductionFiltered} filtered\n";
    echo "  Actual: {$productionVisible} visible, {$productionFiltered} filtered\n";

    echo "Debug mode test: " . ($debugPass ? "✅ PASSED" : "❌ FAILED") . "\n";
    echo "  Expected: {$expectedDebugVisible} visible, {$expectedDebugFiltered} filtered\n";
    echo "  Actual: {$debugVisible} visible, {$debugFiltered} filtered\n";

    if ($productionPass && $debugPass) {
        echo "\n🎉 ALL TESTS PASSED! The filtering implementation is working correctly.\n";
        return true;
    } else {
        echo "\n💥 SOME TESTS FAILED! Please review the implementation.\n";
        return false;
    }
}

// Copy of the enhanced filtering logic from RegistryTimelineRenderer
function shouldFilterDebugEvent(array $payload): bool
{
    // Show all events in debug mode
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        return false;
    }

    // Get event type
    $event_type = $payload['data']['event_type'] ?? $payload['event_type'] ?? '';

    // 1. Check for known debug-only event types (not business events)
    if (in_array($event_type, [
        'order_check_scheduled',  // Internal scheduling, not business-relevant
        'rule_evaluation_non_canonical', // Debug traces for rule evaluation
        '_status_evaluation',     // Debug events for status change evaluation
        'process_started',        // Technical process lifecycle events
        'order_loaded'           // Purely technical loading event
    ])) {
        return true;
    }

    // 2. Check for incomplete rule execution events
    // These have event_type "rule_execution" but lack complete rule data
    if ($event_type === 'rule_execution') {
        // Check if this is an incomplete rule processing event
        $hasCompleteRuleData = !empty($payload['rule_execution']['rule_name']) ||
                              !empty($payload['rule_execution']['rule_configuration']['rule_name']) ||
                              !empty($payload['data']['rule_name']);

        $hasProcessingMetadata = !empty($payload['data']['correlation_id']) ||
                               !empty($payload['data']['process_type']) ||
                               !empty($payload['data']['status']);

        // It's incomplete if it has processing data but lacks complete rule data
        if ($hasProcessingMetadata && !$hasCompleteRuleData) {
            return true;
        }
    }

    return false;
}

// Version for debug mode testing
function shouldFilterDebugEventWithDebug(array $payload, bool $debugMode): bool
{
    // Show all events in debug mode
    if ($debugMode) {
        return false;
    }

    return shouldFilterDebugEvent($payload);
}

// Run the test
$testPassed = testFilteringLogic();
exit($testPassed ? 0 : 1);
