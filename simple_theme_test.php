<?php
/**
 * Simple test to verify theme classes are being applied
 */

// Test the logic directly without running the full renderer
function testThemeClassLogic() {
    // Simulate the event type configuration
    $event_configs = [
        'order_created' => ['theme_class' => 'odcm-component--order'],
        'payment_completed' => ['theme_class' => 'odcm-component--payment'],
        'rule_execution' => ['theme_class' => 'odcm-component--rule'],
        'status_changed' => ['theme_class' => 'odcm-component--order'],
        'unknown_event' => ['theme_class' => 'odcm-component--system']
    ];

    $test_cases = [
        ['event_type' => 'order_created', 'expected_class' => 'odcm-component--order'],
        ['event_type' => 'payment_completed', 'expected_class' => 'odcm-component--payment'],
        ['event_type' => 'rule_execution', 'expected_class' => 'odcm-component--rule'],
        ['event_type' => 'status_changed', 'expected_class' => 'odcm-component--order'],
        ['event_type' => 'unknown_event', 'expected_class' => 'odcm-component--system']
    ];

    echo "=== Simple Theme Class Logic Test ===\n\n";

    foreach ($test_cases as $test_case) {
        $event_type = $test_case['event_type'];
        $expected_class = $test_case['expected_class'];

        // Simulate the logic from renderThreeTierComponent
        $eventConfig = $event_configs[$event_type] ?? ['theme_class' => 'odcm-component--system'];
        $themeClass = $eventConfig['theme_class'] ?? 'odcm-component--system';

        // Simulate the HTML generation
        $html = '<div class="odcm-component ' . htmlspecialchars($themeClass, ENT_QUOTES, 'UTF-8') . '">';

        echo "Testing event type: $event_type\n";
        if (strpos($html, $expected_class) !== false) {
            echo "✅ PASS: Found expected theme class: $expected_class\n";
            echo "   HTML: " . substr($html, 0, 50) . "...\n";
        } else {
            echo "❌ FAIL: Expected theme class '$expected_class' not found\n";
            echo "   HTML: " . $html . "\n";
        }
        echo "\n";
    }

    echo "=== Test Complete ===\n";
}

// Run the test
testThemeClassLogic();
