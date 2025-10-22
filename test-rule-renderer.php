<?php
// Test script for rule evaluation renderer - no WordPress/error_log dependencies

echo "=== TESTING RULE EVALUATION RENDERER REGISTRY ENTRIES ===\n\n";

// Simple function to check if registry entries are set up correctly
function check_registry_entry($registry, $event_type) {
    // Direct match?
    if (isset($registry[$event_type])) {
        return [
            'status' => 'DIRECT MATCH',
            'id' => $event_type,
            'renderer_class' => $registry[$event_type]['renderer_class'] ?? 'unknown'
        ];
    }
    
    // Alias match?
    foreach ($registry as $key => $entry) {
        if (isset($entry['aliases']) && in_array($event_type, $entry['aliases'])) {
            return [
                'status' => 'ALIAS MATCH',
                'id' => $key,
                'renderer_class' => $entry['renderer_class'] ?? 'unknown'
            ];
        }
    }
    
    return [
        'status' => 'NO MATCH',
        'id' => null,
        'renderer_class' => null
    ];
}

// Define registry manually for testing - simplified structure based on PayloadComponentRegistry.php
$registry = [
    // Rule evaluation parent entry for RuleEvaluationRenderer
    'rule_evaluation' => [
        'id'             => 'rule_evaluation',
        'label'          => 'Rule Evaluation',
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--rule',
        'icon'           => 'dashicons-filter',
        'aliases'        => []
    ],
    
    // Rule evaluation domain
    'rule_evaluated' => [
        'id'             => 'rule_evaluated',
        'label'          => 'Rule Evaluated',
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--rule',
        'icon'           => 'dashicons-filter',
        'aliases'        => [
            'rule_matched', 
            'rule_check', 
            'rule_evaluation_success',
            'rule_evaluation_started',
            'rule_evaluation_result',
            'rule_evaluation' // Add parent as alias for consistent lookup
        ]
    ],
    'decision' => [
        'id'             => 'decision',
        'label'          => 'Decision',
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--rule',
        'icon'           => 'dashicons-yes',
        'aliases'        => ['rule_evaluation'] // Make sure parent is listed as an alias
    ],
    'condition_passed' => [
        'id'             => 'condition_passed',
        'label'          => 'Condition Passed',
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--success',
        'icon'           => 'dashicons-yes',
        'aliases'        => ['rule_evaluation'] // Make sure parent is listed as an alias
    ],
    'condition_failed' => [
        'id'             => 'condition_failed',
        'label'          => 'Condition Failed',
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--warning',
        'icon'           => 'dashicons-warning',
        'aliases'        => ['rule_evaluation', 'rule_no_match', 'condition_not_met', 'no_rules_matched'],
    ],
];

// Test for RuleEvaluationRenderer::getComponentId() = 'rule_evaluation'
echo "Testing getComponentId() = 'rule_evaluation'\n";
$component_id = 'rule_evaluation';
$result = check_registry_entry($registry, $component_id);
echo "REGISTRY LOOKUP FOR '$component_id': " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

// Test for rule-specific event types
$rule_event_types = [
    'rule_evaluated',
    'decision',
    'condition_passed', 
    'condition_failed',
    'rule_matched',
    'rule_no_match',
    'rule_check', 
    'no_rules_matched'
];

foreach ($rule_event_types as $event_type) {
    echo "Testing event_type = '$event_type'\n";
    $result = check_registry_entry($registry, $event_type);
    echo "REGISTRY LOOKUP: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
}

echo "=== TEST RULE EVALUATION canHandle() METHOD ===\n\n";

// Simulate the canHandle() method from RuleEvaluationRenderer
function simulate_can_handle($data) {
    // Extract rule-related keys for testing
    $rule_keys = [
        'rule', 'rule_id', 'rule_name', 'conditions', 'actions',
        'evaluation_result', 'trigger', 'trigger_type', 'rule_evaluation',
        'matched', 'reason', 'outcome', 'condition_label', 'expected_value',
        'actual_value', 'operator', 'result'
    ];
    
    // Check for explicit rule-related keys
    foreach ($rule_keys as $key) {
        if (array_key_exists($key, $data)) {
            return true;
        }
    }
    
    return false;
}

// Test data examples
$test_events = [
    [
        'event_type' => 'rule_evaluated',
        'data' => [
            'rule_id' => 123,
            'matched' => true,
            'reason' => 'All conditions met'
        ]
    ],
    [
        'event_type' => 'condition_passed',
        'data' => [
            'condition_label' => 'Order Total > 50',
            'expected_value' => '50',
            'actual_value' => '75',
            'operator' => 'greater_than',
            'result' => true
        ]
    ],
    [
        'event_type' => 'generic_event', // Not in registry
        'data' => [
            'rule_id' => 123, // But has rule-related keys
            'matched' => false
        ]
    ],
    [
        'event_type' => 'generic_event',  // Not in registry
        'data' => [
            'message' => 'Something happened' // No rule-related keys
        ]
    ]
];

foreach ($test_events as $index => $event) {
    $event_type = $event['event_type'];
    $data = $event['data'];
    
    echo "=== TEST CASE #" . ($index + 1) . ": event_type='" . $event_type . "' ===\n";
    
    // Check registry match (Tier 1)
    $registry_result = check_registry_entry($registry, $event_type);
    echo "TIER 1 (REGISTRY): " . json_encode($registry_result, JSON_PRETTY_PRINT) . "\n";
    
    // Check canHandle (Tier 2)
    $can_handle = simulate_can_handle($data);
    echo "TIER 2 (CAPABILITY): " . ($can_handle ? "CAN HANDLE" : "CANNOT HANDLE") . "\n";
    
    // Simulate three-tier lookup
    if ($registry_result['status'] !== 'NO MATCH') {
        echo "FINAL RESULT: Would use " . $registry_result['renderer_class'] . " (via registry lookup)\n";
    } else if ($can_handle) {
        echo "FINAL RESULT: Would use RuleEvaluationRenderer (via capability check)\n";
    } else {
        echo "FINAL RESULT: Would fall back to FallbackRenderer\n";
    }
    
    echo "\n";
}

echo "=== DEBUGGING NAMESPACE RESOLUTION ===\n\n";

// Simulate namespace resolution in odcm_find_best_renderer_for_data()
function simulate_namespace_resolution($renderer_class) {
    // Original class name
    echo "Original renderer class: '$renderer_class'\n";
    
    // Add namespace if not fully qualified
    if (strpos($renderer_class, '\\') === false) {
        $full_renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
        echo "Added namespace: '$full_renderer_class'\n";
        return $full_renderer_class;
    } else {
        echo "Already has namespace\n";
        return $renderer_class;
    }
}

// Test namespace resolution
$test_classes = [
    'RuleEvaluationRenderer',
    'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\RuleEvaluationRenderer',
    'SomeOtherRenderer'
];

foreach ($test_classes as $class) {
    echo "Testing class: $class\n";
    $resolved_class = simulate_namespace_resolution($class);
    // In a real environment, we would check if class_exists($resolved_class)
    echo "Would check if class_exists(): " . $resolved_class . "\n\n";
}

echo "=== TEST COMPLETED ===\n";
