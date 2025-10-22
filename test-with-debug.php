<?php
// Test script for rule evaluation renderer with direct debugging

// Create a modified version of the registry function with debug output
require_once 'src/Core/PayloadComponentRegistry.php';

// Define a custom debug function
function custom_debug($message) {
    echo "DEBUG: $message\n";
}

// Modified find_best_renderer_for_data that uses our custom debug function
function custom_find_best_renderer_for_data(string $event_type, array $data): ?array
{
    // Print debug output
    custom_debug("Starting lookup for event_type='$event_type'");
    custom_debug("Data keys: " . implode(', ', array_keys($data)));
    
    // Tier 1: Registry lookup with aliases (fast path)
    $def = odcm_get_payload_component_type_by_event_type($event_type);
    if ($def) {
        $renderer_class = $def['renderer_class'] ?? 'none';
        custom_debug("Tier 1 SUCCESS - Found registry match: event_type='$event_type' -> renderer='$renderer_class'");
        return $def;
    }
    
    custom_debug("Tier 1 FAILED - No registry match for event_type='$event_type'");
    custom_debug("Starting Tier 2 capability-based lookup...");
    
    // Tier 2: Capability-based lookup (smart fallback)
    $types = odcm_get_payload_component_types();
    
    // Include event_type in data for canHandle() calls to provide complete context
    $data_with_event_type = array_merge($data, ['event_type' => $event_type]);
    
    $tier2_attempts = 0;
    foreach ($types as $type_id => $type) {
        if (!isset($type['renderer_class'])) {
            continue;
        }
        
        $renderer_class = $type['renderer_class'];
        $original_renderer_class = $renderer_class;
        
        // Add namespace if not fully qualified
        if (strpos($renderer_class, '\\') === false) {
            $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
        }
        
        $tier2_attempts++;
        
        custom_debug("Tier 2 attempt #$tier2_attempts - Testing type_id='$type_id', renderer='$original_renderer_class'");
        
        // Simulate the canHandle method for RuleEvaluationRenderer
        if ($original_renderer_class === 'RuleEvaluationRenderer') {
            $rule_keys = [
                'rule', 'rule_id', 'rule_name', 'conditions', 'actions',
                'evaluation_result', 'trigger', 'trigger_type', 'rule_evaluation',
                'matched', 'reason', 'outcome', 'condition_label', 'expected_value',
                'actual_value', 'operator', 'result'
            ];
            
            foreach ($rule_keys as $key) {
                if (array_key_exists($key, $data_with_event_type)) {
                    custom_debug("Tier 2 SUCCESS - Renderer '$original_renderer_class' can handle event_type='$event_type' (found key: $key)");
                    return $type;
                }
            }
        }
        // Add simulation for other renderers as needed
    }
    
    custom_debug("Tier 2 FAILED - No capable renderer found after $tier2_attempts attempts");
    custom_debug("Tier 3 FALLBACK - Using fallback renderer for event_type='$event_type'");
    
    // Tier 3: Fallback renderer (guaranteed fallback)
    return odcm_get_payload_component_type('fallback');
}

// Enable debug mode (not used but kept for compatibility)
define('ODCM_DEBUG', true);

echo "=== TESTING RULE EVALUATION RENDERER WITH DEBUG OUTPUT ===\n\n";

// Test rule evaluation events
$test_events = [
    [
        'event_type' => 'rule_evaluation', // Parent ID
        'data' => [
            'rule_id' => 123,
            'matched' => true
        ]
    ],
    [
        'event_type' => 'rule_evaluated', // Direct match
        'data' => [
            'rule_id' => 123,
            'matched' => true
        ]
    ],
    [
        'event_type' => 'condition_passed', // Child component
        'data' => [
            'condition_label' => 'Order Total > 50',
            'expected_value' => '50',
            'actual_value' => '75',
            'result' => true
        ]
    ],
    [
        'event_type' => 'generic_event', // Not in registry but has rule-related data
        'data' => [
            'rule_id' => 123,
            'matched' => false
        ]
    ]
];

foreach ($test_events as $index => $event) {
    $event_type = $event['event_type'];
    $data = $event['data'];
    
    echo "\n=== TEST CASE #" . ($index + 1) . ": event_type='" . $event_type . "' ===\n";
    
    // Use our custom debug function instead of the original
    $renderer_def = custom_find_best_renderer_for_data($event_type, $data);
    
    if ($renderer_def) {
        echo "RESULT: Would use " . $renderer_def['renderer_class'] . " for event_type='$event_type'\n";
    } else {
        echo "RESULT: No renderer found for event_type='$event_type'\n";
    }
}

echo "\n=== DEBUG TEST COMPLETED ===\n";
echo "Custom debug function output shown inline above\n";
