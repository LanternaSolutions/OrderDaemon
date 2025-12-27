<?php
/**
 * Test script to verify that theme classes are being applied to timeline components
 */

require_once __DIR__ . '/vendor/autoload.php';

// Mock the necessary WordPress functions for testing
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

// Test data for different event types
$testEvents = [
    [
        'event_type' => 'order_created',
        'label' => 'Order Created',
        'ts' => time(),
        'level' => 'info',
        'data' => [
            'order_id' => 102,
            'status' => 'pending',
            'amount' => 10,
            'currency' => 'USD',
            'customer_id' => 1,
            'payment_method' => 'stripe'
        ]
    ],
    [
        'event_type' => 'payment_completed',
        'label' => 'Payment Completed',
        'ts' => time(),
        'level' => 'info',
        'data' => [
            'order_id' => 102,
            'payment_method' => 'stripe',
            'amount' => 10,
            'currency' => 'USD'
        ]
    ],
    [
        'event_type' => 'rule_execution',
        'label' => 'Rule Executed',
        'ts' => time(),
        'level' => 'info',
        'data' => [
            'rule_name' => 'test rule',
            'execution_status' => 'success',
            'order_id' => 102
        ]
    ],
    [
        'event_type' => 'status_changed',
        'label' => 'Status Changed',
        'ts' => time(),
        'level' => 'info',
        'data' => [
            'from' => 'pending',
            'to' => 'completed',
            'order_id' => 102
        ]
    ]
];

echo "=== Timeline Theme Class Test ===\n\n";

// Test each event type
foreach ($testEvents as $event) {
    echo "Testing event type: " . $event['event_type'] . "\n";

        try {
            // Create timeline data
            $timelineData = \OrderDaemon\CompletionManager\API\Timeline\TimelineData::individual(1, [$event]);

        // Render timeline
        $renderer = new \OrderDaemon\CompletionManager\API\Timeline\RegistryTimelineRenderer();
        $html = $renderer->renderTimeline($timelineData);

        // Check for expected theme classes
        $expectedThemeClass = '';
        switch ($event['event_type']) {
            case 'order_created':
            case 'status_changed':
                $expectedThemeClass = 'odcm-component--order';
                break;
            case 'payment_completed':
                $expectedThemeClass = 'odcm-component--payment';
                break;
            case 'rule_execution':
                $expectedThemeClass = 'odcm-component--rule';
                break;
            default:
                $expectedThemeClass = 'odcm-component--system';
        }

        if (strpos($html, $expectedThemeClass) !== false) {
            echo "✅ PASS: Found expected theme class: $expectedThemeClass\n";
        } else {
            echo "❌ FAIL: Expected theme class '$expectedThemeClass' not found\n";
            echo "HTML snippet: " . substr($html, 0, 200) . "...\n";
        }

        // Check for basic component structure
        if (strpos($html, 'class="odcm-component') !== false) {
            echo "✅ PASS: Component structure found\n";
        } else {
            echo "❌ FAIL: Component structure not found\n";
        }

        echo "\n";
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    }
}

echo "=== Test Complete ===\n";
