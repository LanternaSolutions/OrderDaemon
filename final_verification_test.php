<?php
/**
 * Final verification test to check if theme classes are applied in the actual renderer
 */

require_once __DIR__ . '/vendor/autoload.php';

// Mock WordPress functions
if (!function_exists('__')) {
    function __($text, $domain = '') {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0) {
        return json_encode($data, $options);
    }
}

// Create a minimal test by directly calling the renderThreeTierComponent method
// We'll use reflection to access the private method
echo "=== Final Verification Test ===\n\n";

try {
    // Create a renderer instance
    $renderer = new \OrderDaemon\CompletionManager\API\Timeline\RegistryTimelineRenderer();

    // Use reflection to access the private method
    $reflection = new ReflectionClass($renderer);
    $method = $reflection->getMethod('renderThreeTierComponent');
    $method->setAccessible(true);

    // Test data for different event types
    $test_cases = [
        [
            'event_type' => 'order_created',
            'expected_theme' => 'odcm-component--order',
            'display_data' => ['display_sections' => []],
            'raw_payload' => ['event_type' => 'order_created', 'ts' => time()]
        ],
        [
            'event_type' => 'payment_completed',
            'expected_theme' => 'odcm-component--payment',
            'display_data' => ['display_sections' => []],
            'raw_payload' => ['event_type' => 'payment_completed', 'ts' => time()]
        ],
        [
            'event_type' => 'rule_execution',
            'expected_theme' => 'odcm-component--rule',
            'display_data' => ['display_sections' => []],
            'raw_payload' => ['event_type' => 'rule_execution', 'ts' => time()]
        ]
    ];

    foreach ($test_cases as $test_case) {
        echo "Testing event type: " . $test_case['event_type'] . "\n";

        // Call the private method
        $html = $method->invoke($renderer, $test_case['display_data'], $test_case['raw_payload']);

        // Check for the expected theme class
        if (strpos($html, $test_case['expected_theme']) !== false) {
            echo "✅ PASS: Found expected theme class: " . $test_case['expected_theme'] . "\n";

            // Extract and show the class attribute
            if (preg_match('/class="([^"]+)"/', $html, $matches)) {
                echo "   Full class attribute: " . $matches[1] . "\n";
            }
        } else {
            echo "❌ FAIL: Expected theme class '" . $test_case['expected_theme'] . "' not found\n";
            echo "   HTML snippet: " . substr($html, 0, 100) . "...\n";
        }
        echo "\n";
    }

    echo "=== Verification Complete ===\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
