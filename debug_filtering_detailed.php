<?php
/**
 * Detailed debugging test for the Rule Processing Started event
 */

// Test the exact event structure from timeline.txt
$ruleProcessingStartedEvent = [
    'event_type' => 'rule_execution',
    'label' => 'Rule "virtual rule" evaluated successfully for Order #104',
    'ts' => 1766922556,
    'level' => 'info',
    'data' => [
        'process_type' => 'rule_execution',
        'correlation_id' => 'odcm:lifecycle:104:1766922555:6951193bee8da6.95133676',
        'status' => 'success',
        'source' => 'api',
        'component_count' => 2,
        'actor' => 'system',
        'metrics' => [
            'attribution_capture_ms' => 0.0050067901611328125
        ]
    ],
    // Note: NO rule_execution section, NO rule_name
];

echo "=== Debugging Rule Processing Started Event ===\n\n";

echo "Event structure:\n";
echo "event_type: " . $ruleProcessingStartedEvent['event_type'] . "\n";
echo "Has rule_execution section: " . (isset($ruleProcessingStartedEvent['rule_execution']) ? 'YES' : 'NO') . "\n";
echo "Has data.rule_name: " . (isset($ruleProcessingStartedEvent['data']['rule_name']) ? 'YES' : 'NO') . "\n";
echo "Has data.correlation_id: " . (isset($ruleProcessingStartedEvent['data']['correlation_id']) ? 'YES' : 'NO') . "\n";
echo "Has data.process_type: " . (isset($ruleProcessingStartedEvent['data']['process_type']) ? 'YES' : 'NO') . "\n";
echo "Has data.status: " . (isset($ruleProcessingStartedEvent['data']['status']) ? 'YES' : 'NO') . "\n";

echo "\nTesting filtering logic:\n";

// Test our filtering function
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
        echo "  Checking incomplete rule event criteria...\n";

        // Check if this is an incomplete rule processing event
        $hasCompleteRuleData = !empty($payload['rule_execution']['rule_name']) ||
                              !empty($payload['rule_execution']['rule_configuration']['rule_name']) ||
                              !empty($payload['data']['rule_name']);

        $hasProcessingMetadata = !empty($payload['data']['correlation_id']) ||
                               !empty($payload['data']['process_type']) ||
                               !empty($payload['data']['status']);

        echo "  hasCompleteRuleData: " . ($hasCompleteRuleData ? 'TRUE' : 'FALSE') . "\n";
        echo "  hasProcessingMetadata: " . ($hasProcessingMetadata ? 'TRUE' : 'FALSE') . "\n";

        // It's incomplete if it has processing data but lacks complete rule data
        if ($hasProcessingMetadata && !$hasCompleteRuleData) {
            echo "  Result: SHOULD FILTER (incomplete rule event)\n";
            return true;
        } else {
            echo "  Result: SHOULD NOT FILTER\n";
        }
    }

    return false;
}

// Test in production mode
define('ODCM_DEBUG', false);
$shouldFilter = shouldFilterDebugEvent($ruleProcessingStartedEvent);

echo "\nFinal result:\n";
echo "Should filter in production mode: " . ($shouldFilter ? 'YES ✅' : 'NO ❌') . "\n";

if (!$shouldFilter) {
    echo "\n🔍 Debugging why it's not being filtered...\n";

    // Check each condition step by step
    $event_type = $ruleProcessingStartedEvent['data']['event_type'] ?? $ruleProcessingStartedEvent['event_type'] ?? '';
    echo "Event type detected: '" . $event_type . "'\n";

    if ($event_type === 'rule_execution') {
        echo "Event type matches 'rule_execution' ✅\n";

        $hasCompleteRuleData = !empty($ruleProcessingStartedEvent['rule_execution']['rule_name']) ||
                              !empty($ruleProcessingStartedEvent['rule_execution']['rule_configuration']['rule_name']) ||
                              !empty($ruleProcessingStartedEvent['data']['rule_name']);

        $hasProcessingMetadata = !empty($ruleProcessingStartedEvent['data']['correlation_id']) ||
                               !empty($ruleProcessingStartedEvent['data']['process_type']) ||
                               !empty($ruleProcessingStartedEvent['data']['status']);

        echo "hasCompleteRuleData: " . var_export($hasCompleteRuleData, true) . "\n";
        echo "hasProcessingMetadata: " . var_export($hasProcessingMetadata, true) . "\n";
        echo "Condition (hasProcessingMetadata && !hasCompleteRuleData): " . var_export($hasProcessingMetadata && !$hasCompleteRuleData, true) . "\n";
    } else {
        echo "Event type does NOT match 'rule_execution' ❌\n";
    }
}
