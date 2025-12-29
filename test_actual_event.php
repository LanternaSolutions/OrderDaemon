<?php
/**
 * Test the actual Rule Processing Started event from the new timeline.txt
 */

// Copy the exact filtering logic from our implementation
function shouldFilterDebugEvent(array $payload): bool
{
    // Show all events in debug mode
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        return false;
    }

    // Get event type
    $event_type = $payload['data']['event_type'] ?? $payload['event_type'] ?? '';

    echo "Debug: event_type = '" . $event_type . "'\n";

    // 1. Check for known debug-only event types (not business events)
    if (in_array($event_type, [
        'order_check_scheduled',  // Internal scheduling, not business-relevant
        'rule_evaluation_non_canonical', // Debug traces for rule evaluation
        '_status_evaluation',     // Debug events for status change evaluation
        'process_started',        // Technical process lifecycle events
        'order_loaded'           // Purely technical loading event
    ])) {
        echo "Debug: Matched known debug event type\n";
        return true;
    }

    // 2. Check for incomplete rule execution events
    // These have event_type "rule_execution" but lack complete rule data
    if ($event_type === 'rule_execution') {
        echo "Debug: Checking incomplete rule event criteria...\n";

        // Check if this is an incomplete rule processing event
        $hasCompleteRuleData = !empty($payload['rule_execution']['rule_name']) ||
                              !empty($payload['rule_execution']['rule_configuration']['rule_name']) ||
                              !empty($payload['data']['rule_name']);

        $hasProcessingMetadata = !empty($payload['data']['correlation_id']) ||
                               !empty($payload['data']['process_type']) ||
                               !empty($payload['data']['status']);

        echo "Debug: hasCompleteRuleData = " . ($hasCompleteRuleData ? "TRUE" : "FALSE") . "\n";
        echo "Debug: hasProcessingMetadata = " . ($hasProcessingMetadata ? "TRUE" : "FALSE") . "\n";

        // It's incomplete if it has processing data but lacks complete rule data
        if ($hasProcessingMetadata && !$hasCompleteRuleData) {
            echo "Debug: Event should be filtered (incomplete rule event)\n";
            return true;
        } else {
            echo "Debug: Event should NOT be filtered\n";
        }
    }

    echo "Debug: Event type doesn't match filtering criteria\n";
    return false;
}

// The exact event structure from the new timeline.txt
$actualEvent = [
    'event_type' => 'rule_execution',
    'label' => 'Rule "virtual rule" evaluated successfully for Order #105',
    'ts' => 1766952562,
    'level' => 'info',
    'data' => [
        'process_type' => 'rule_execution',
        'correlation_id' => 'odcm:lifecycle:105:1766952562:69518e72476709.03962767',
        'status' => 'success',
        'source' => 'api',
        'component_count' => 2,
        'actor' => 'system',
        'metrics' => [
            'attribution_capture_ms' => 0.0011920928955078125
        ]
    ],
    'display_sections' => [
        [
            'title' => 'Summary',
            'items' => [
                [
                    'key' => 'Summary',
                    'value' => 'Rule "virtual rule" evaluated successfully for Order #105'
                ],
                [
                    'key' => 'Event',
                    'value' => 'rule_execution'
                ]
            ]
        ]
    ]
    // NOTE: No rule_execution section, No rule_name - this makes it incomplete
];

echo "=== Testing Actual Rule Processing Started Event ===\n\n";

// Test in production mode
define('ODCM_DEBUG', false);

echo "Event structure analysis:\n";
echo "  event_type: '" . $actualEvent['event_type'] . "'\n";
echo "  Has rule_execution section: " . (isset($actualEvent['rule_execution']) ? "YES" : "NO") . "\n";
echo "  Has data.rule_name: " . (isset($actualEvent['data']['rule_name']) ? "YES" : "NO") . "\n";
echo "  Has data.correlation_id: " . (isset($actualEvent['data']['correlation_id']) ? "YES" : "NO") . "\n";
echo "  Has data.process_type: " . (isset($actualEvent['data']['process_type']) ? "YES" : "NO") . "\n";
echo "  Has data.status: " . (isset($actualEvent['data']['status']) ? "YES" : "NO") . "\n";

echo "\nFiltering logic execution:\n";
$shouldFilter = shouldFilterDebugEvent($actualEvent);

echo "\nFinal result:\n";
echo "  shouldFilterDebugEvent() returns: " . ($shouldFilter ? "TRUE" : "FALSE") . "\n";
echo "  Event will be: " . ($shouldFilter ? "FILTERED ✅" : "VISIBLE ❌") . " in production mode\n";

if (!$shouldFilter) {
    echo "\n❌ PROBLEM IDENTIFIED!\n";
    echo "The event should be filtered but isn't. This means:\n";
    echo "1. The filtering logic might not be called\n";
    echo "2. There might be a bug in the implementation\n";
    echo "3. The event might be processed differently\n";
} else {
    echo "\n✅ Filtering logic is correct!\n";
    echo "The event SHOULD be filtered. If it's still showing, the issue is:\n";
    echo "1. The filtering method isn't being called\n";
    echo "2. The return value is being ignored\n";
    echo "3. There's another code path rendering the event\n";
}
