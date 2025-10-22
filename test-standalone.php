<?php
// Standalone test script for rule evaluation renderer mapping

echo "=== STANDALONE TEST FOR RULE EVALUATION RENDERER MAPPING ===\n\n";

// Simplified registry
$registry = [
    // Rule evaluation parent entry
    'rule_evaluation' => [
        'id'             => 'rule_evaluation',
        'renderer_class' => 'RuleEvaluationRenderer',
        'aliases'        => []
    ],
    
    // Child entries
    'rule_evaluated' => [
        'id'             => 'rule_evaluated',
        'renderer_class' => 'RuleEvaluationRenderer',
        'aliases'        => [
            'rule_matched', 
            'rule_check', 
            'rule_evaluation' // Parent as an alias for consistent lookup
        ],
    ],
    'condition_passed' => [
        'id'             => 'condition_passed',
        'renderer_class' => 'RuleEvaluationRenderer',
        'aliases'        => [
            'rule_evaluation' // Parent as an alias for consistent lookup
        ],
    ],
    'condition_failed' => [
        'id'             => 'condition_failed',
        'renderer_class' => 'RuleEvaluationRenderer',
        'aliases'        => [
            'rule_evaluation', // Parent as an alias for consistent lookup
            'rule_no_match', 
            'condition_not_met'
        ],
    ],
];

// Function to find renderer by event type
function find_renderer_by_event_type($registry, $event_type) {
    // Direct match
    if (isset($registry[$event_type])) {
        echo "  Direct match found for '$event_type'\n";
        return $registry[$event_type];
    }
    
    // Alias match
    foreach ($registry as $type_id => $type) {
        if (isset($type['aliases']) && in_array($event_type, $type['aliases'])) {
            echo "  Alias match found for '$event_type' via '$type_id'\n";
            return $type;
        }
    }
    
    echo "  No match found for '$event_type'\n";
    return null;
}

// Function to test capability detection
function test_capability_detection($data) {
    $rule_keys = [
        'rule', 'rule_id', 'rule_name', 'conditions', 'actions',
        'matched', 'reason', 'result'
    ];
    
    foreach ($rule_keys as $key) {
        if (array_key_exists($key, $data)) {
            echo "  Capability match found via key: '$key'\n";
            return true;
        }
    }
    
    echo "  No capability match found\n";
    return false;
}

// Tests to run
$tests = [
    // Test parent component ID
    ['event_type' => 'rule_evaluation', 'data' => ['rule_id' => 123]],
    
    // Test direct child matches
    ['event_type' => 'rule_evaluated', 'data' => ['rule_id' => 123]],
    ['event_type' => 'condition_passed', 'data' => ['result' => true]],
    ['event_type' => 'condition_failed', 'data' => ['result' => false]],
    
    // Test aliases
    ['event_type' => 'rule_matched', 'data' => ['rule_id' => 123]],
    ['event_type' => 'rule_no_match', 'data' => ['rule_id' => 123]],
    
    // Test capability-based detection (no event_type match)
    ['event_type' => 'generic_event', 'data' => ['rule_id' => 123]],
    ['event_type' => 'generic_event', 'data' => ['some_other_field' => 'value']]
];

// Run tests
foreach ($tests as $index => $test) {
    $event_type = $test['event_type'];
    $data = $test['data'];
    
    echo "\nTEST #" . ($index + 1) . ": event_type='$event_type'\n";
    echo "Data keys: " . implode(', ', array_keys($data)) . "\n";
    
    // Try registry lookup first (Tier 1)
    echo "TIER 1 (REGISTRY LOOKUP):\n";
    $def = find_renderer_by_event_type($registry, $event_type);
    
    if ($def) {
        echo "RESULT: Would use " . $def['renderer_class'] . " (via registry lookup)\n";
    } else {
        // Try capability detection (Tier 2)
        echo "TIER 2 (CAPABILITY DETECTION):\n";
        $can_handle = test_capability_detection($data);
        
        if ($can_handle) {
            echo "RESULT: Would use RuleEvaluationRenderer (via capability detection)\n";
        } else {
            echo "RESULT: Would use FallbackRenderer\n";
        }
    }
}

echo "\n=== TEST COMPLETED ===\n";
