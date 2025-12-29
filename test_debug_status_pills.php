<?php
/**
 * Test script specifically for debug status pills implementation
 *
 * This script tests the debug status pill functionality to ensure that
 * debug events use the correct odcm-status-pill--debug CSS class.
 */

// Include the necessary files
require_once __DIR__ . '/src/API/Timeline/TimelineRendererInterface.php';
require_once __DIR__ . '/src/API/Timeline/RegistryTimelineRenderer.php';
require_once __DIR__ . '/src/API/Timeline/DisplayAdapter.php';
require_once __DIR__ . '/src/API/Timeline/OrderEventAdapter.php';
require_once __DIR__ . '/src/API/Timeline/PaymentEventAdapter.php';
require_once __DIR__ . '/src/API/Timeline/RuleExecutionAdapter.php';
require_once __DIR__ . '/src/API/Timeline/AdapterRegistry.php';
require_once __DIR__ . '/src/API/Timeline/TimelineData.php';

// Add WordPress function stubs for testing
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0) {
        return json_encode($data, $options);
    }
}

echo "=== Testing Debug Status Pill Implementation ===\n\n";

try {
    $renderer = new \OrderDaemon\CompletionManager\API\Timeline\RegistryTimelineRenderer();
    $reflection = new ReflectionClass($renderer);

    // Test renderStatusPill method with debug type
    echo "1. Testing renderStatusPill method with debug type:\n";
    $renderStatusPillMethod = $reflection->getMethod('renderStatusPill');
    $renderStatusPillMethod->setAccessible(true);

    $debugPill = $renderStatusPillMethod->invoke($renderer, 'debug', 'debug');
    echo "   Debug pill: " . $debugPill . "\n";

    // Verify it contains the correct CSS class
    if (strpos($debugPill, 'odcm-status-pill--debug') !== false) {
        echo "   ✅ Debug pill contains correct CSS class\n";
    } else {
        echo "   ❌ Debug pill missing correct CSS class\n";
    }
    echo "\n";

    // Test mapStatusToPillType method with debug events
    echo "2. Testing mapStatusToPillType method with debug events:\n";
    $mapStatusMethod = $reflection->getMethod('mapStatusToPillType');
    $mapStatusMethod->setAccessible(true);

    $debugEventTypes = ['_status_evaluation', 'rule_evaluation_non_canonical', 'debug'];
    foreach ($debugEventTypes as $eventType) {
        $pillType = $mapStatusMethod->invoke($renderer, $eventType, 'any_status');
        echo "   Event type '$eventType' maps to pill type: $pillType\n";

        if ($pillType === 'debug') {
            echo "   ✅ Correctly mapped to debug pill type\n";
        } else {
            echo "   ❌ Should map to debug pill type\n";
        }
    }
    echo "\n";

    // Test with DisplayAdapter as well
    echo "3. Testing DisplayAdapter debug status pill support:\n";
    $displayAdapter = new \OrderDaemon\CompletionManager\API\Timeline\OrderEventAdapter();
    $displayReflection = new ReflectionClass($displayAdapter);

    $displayRenderStatusPillMethod = $displayReflection->getMethod('renderStatusPill');
    $displayRenderStatusPillMethod->setAccessible(true);

    $displayDebugPill = $displayRenderStatusPillMethod->invoke($displayAdapter, 'debug', 'debug');
    echo "   DisplayAdapter debug pill: " . $displayDebugPill . "\n";

    if (strpos($displayDebugPill, 'odcm-status-pill--debug') !== false) {
        echo "   ✅ DisplayAdapter debug pill contains correct CSS class\n";
    } else {
        echo "   ❌ DisplayAdapter debug pill missing correct CSS class\n";
    }
    echo "\n";

    // Test DisplayAdapter mapStatusToPillType method
    $displayMapStatusMethod = $displayReflection->getMethod('mapStatusToPillType');
    $displayMapStatusMethod->setAccessible(true);

    foreach ($debugEventTypes as $eventType) {
        $pillType = $displayMapStatusMethod->invoke($displayAdapter, $eventType, 'any_status');
        echo "   DisplayAdapter: Event type '$eventType' maps to pill type: $pillType\n";

        if ($pillType === 'debug') {
            echo "   ✅ DisplayAdapter correctly mapped to debug pill type\n";
        } else {
            echo "   ❌ DisplayAdapter should map to debug pill type\n";
        }
    }
    echo "\n";

    echo "=== Debug Status Pill Implementation Test Complete ===\n";

} catch (Exception $e) {
    echo "Error during debug status pill testing: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    echo "\n=== Debug Status Pill Implementation Test Complete (with errors) ===\n";
}

// Test CSS classes exist
echo "\n=== Testing CSS Class Availability ===\n";

$cssFiles = [
    'assets/css/insight-dashboard.css',
    'assets/css/odcm-design-system.css'
];

foreach ($cssFiles as $cssFile) {
    if (file_exists($cssFile)) {
        $cssContent = file_get_contents($cssFile);
        if (strpos($cssContent, '.odcm-status-pill--debug') !== false) {
            echo "✅ $cssFile contains .odcm-status-pill--debug class\n";
        } else {
            echo "❌ $cssFile missing .odcm-status-pill--debug class\n";
        }
    } else {
        echo "❌ $cssFile not found\n";
    }
}

echo "\n=== CSS Class Test Complete ===\n";
