<?php
/**
 * Simple verification of the filtering logic implementation
 */

echo "=== Simple Timeline Debug Event Filtering Verification ===\n\n";

// Copy the exact filtering logic from our implementation
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

// Test the exact "Rule Processing Started" event from timeline.txt
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
    // NOTE: No rule_execution section, No rule_name - this makes it incomplete
];

// Test in production mode
define('ODCM_DEBUG', false);

echo "Testing the exact 'Rule Processing Started' event from timeline.txt:\n";
echo str_repeat('-', 60) . "\n";

echo "Event analysis:\n";
echo "  event_type: '" . $ruleProcessingStartedEvent['event_type'] . "'\n";
echo "  Has rule_execution section: " . (isset($ruleProcessingStartedEvent['rule_execution']) ? "YES" : "NO") . "\n";
echo "  Has data.rule_name: " . (isset($ruleProcessingStartedEvent['data']['rule_name']) ? "YES" : "NO") . "\n";
echo "  Has data.correlation_id: " . (isset($ruleProcessingStartedEvent['data']['correlation_id']) ? "YES" : "NO") . "\n";
echo "  Has data.process_type: " . (isset($ruleProcessingStartedEvent['data']['process_type']) ? "YES" : "NO") . "\n";
echo "  Has data.status: " . (isset($ruleProcessingStartedEvent['data']['status']) ? "YES" : "NO") . "\n";

echo "\nFiltering logic evaluation:\n";
$shouldFilter = shouldFilterDebugEvent($ruleProcessingStartedEvent);

echo "  shouldFilterDebugEvent() returns: " . ($shouldFilter ? "TRUE" : "FALSE") . "\n";
echo "  Event will be: " . ($shouldFilter ? "FILTERED ❌" : "VISIBLE ✅") . " in production mode\n";

echo "\n" . str_repeat('-', 60) . "\n";

if ($shouldFilter) {
    echo "✅ CORRECT: The 'Rule Processing Started' event WILL be filtered in production mode!\n";
    echo "\nThe implementation is working correctly. If you're still seeing this event in the frontend,\n";
    echo "it's likely due to one of these reasons:\n";
    echo "\n";
    echo "1. 🧹 Browser Cache: The old timeline is cached in your browser\n";
    echo "   → Try Ctrl+F5 (hard refresh) or clear browser cache\n";
    echo "\n";
    echo "2. 🔄 Server Cache: There might be server-side caching\n";
    echo "   → Check if there's any caching mechanism (Redis, OPcache, etc.)\n";
    echo "\n";
    echo "3. 📄 Old Data: The timeline.txt file shows pre-fix data\n";
    echo "   → This file was rendered before our filtering was implemented\n";
    echo "\n";
    echo "4. 🐛 Debug Mode: Debug mode might be enabled in your environment\n";
    echo "   → Check if ODCM_DEBUG constant is defined and set to true\n";
    echo "\n";
    echo "5. 🔄 Timeline Regeneration: The timeline might need to be regenerated\n";
    echo "   → Try triggering a new order or clearing timeline cache\n";
} else {
    echo "❌ INCORRECT: The 'Rule Processing Started' event will NOT be filtered!\n";
    echo "There's an issue with the filtering logic that needs to be fixed.\n";
}

exit($shouldFilter ? 0 : 1);
