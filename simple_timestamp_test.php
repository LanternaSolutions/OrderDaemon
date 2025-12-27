<?php
/**
 * Simple test script to verify the timestamp fix
 *
 * This script tests the DisplayAdapter timestamp extraction directly.
 */

// Include only the necessary files
require_once __DIR__ . '/src/API/Timeline/DisplayAdapter.php';
require_once __DIR__ . '/src/API/Timeline/OrderEventAdapter.php';

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

// Mock PayloadComponentUIToolkit
if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
    class PayloadComponentUIToolkit {
        public function format_timestamp($timestamp) {
            if (is_numeric($timestamp)) {
                return gmdate('Y-m-d H:i:s', (int)$timestamp);
            } elseif (is_string($timestamp)) {
                return $timestamp;
            }
            return '1970-01-01 00:00:00';
        }
    }
}

// Test payloads with different timestamps
$test_payloads = [
    [
        'event_type' => 'order_created',
        'ts' => 1766699479, // December 25, 2025 10:51:19 PM
        'label' => 'Order Created',
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
        'event_type' => 'status_changed',
        'ts' => 1766699489, // December 25, 2025 10:51:29 PM (10 seconds later)
        'label' => 'Status changed',
        'level' => 'info',
        'data' => [
            'from' => 'pending',
            'to' => 'completed',
            'order_id' => 102,
            'change_type' => 'automatic'
        ]
    ],
    [
        'event_type' => 'order_created',
        'ts' => 1766699499, // December 25, 2025 10:51:39 PM (20 seconds later)
        'label' => 'Order Created',
        'level' => 'info',
        'data' => [
            'order_id' => 103,
            'status' => 'pending',
            'amount' => 15,
            'currency' => 'USD',
            'customer_id' => 2,
            'payment_method' => 'paypal'
        ]
    ]
];

echo "=== SIMPLE TIMESTAMP FIX TEST ===\n\n";

// Test each payload directly with OrderEventAdapter
foreach ($test_payloads as $index => $payload) {
    echo "Testing payload " . ($index + 1) . ":\n";
    echo "Event Type: " . $payload['event_type'] . "\n";
    echo "Raw Timestamp: " . $payload['ts'] . " (Unix timestamp)\n";

    try {
        // Create adapter directly
        $adapter = new \OrderDaemon\CompletionManager\API\Timeline\OrderEventAdapter();

        // Extract display data
        $displayData = $adapter->extractDisplayData($payload);

        // Check if timestamp is in display sections
        if (isset($displayData['display_sections']['timestamp'])) {
            $timestampValue = $displayData['display_sections']['timestamp']['value'];
            echo "Formatted Timestamp: " . $timestampValue . "\n";

            // Verify it's not a fallback
            if ($timestampValue === 'no timestamp' || $timestampValue === 'error') {
                echo "❌ ISSUE: Got fallback value instead of real timestamp\n";
            } elseif (strpos($timestampValue, '1970') !== false) {
                echo "❌ ISSUE: Got 1970 timestamp (epoch issue)\n";
            } else {
                // Convert back to timestamp to verify it matches
                $expected_date = gmdate('Y-m-d H:i:s', (int)$payload['ts']);
                if ($timestampValue === $expected_date) {
                    echo "✅ SUCCESS: Timestamp matches expected value\n";
                } else {
                    echo "❌ ISSUE: Timestamp doesn't match expected value\n";
                    echo "Expected: " . $expected_date . "\n";
                }
            }
        } else {
            echo "❌ ISSUE: No timestamp in display sections\n";
        }

        echo "---\n";
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        echo "---\n";
    }
}

// Test edge cases
echo "\n=== EDGE CASE TESTS ===\n\n";

// Test with missing timestamp
$no_timestamp_payload = [
    'event_type' => 'order_created',
    'label' => 'Order Created',
    'level' => 'info',
    'data' => [
        'order_id' => 102
    ]
];

echo "Testing payload with no timestamp:\n";
try {
    $adapter = new \OrderDaemon\CompletionManager\API\Timeline\OrderEventAdapter();
    $displayData = $adapter->extractDisplayData($no_timestamp_payload);

    if (isset($displayData['display_sections']['timestamp'])) {
        $timestampValue = $displayData['display_sections']['timestamp']['value'];
        echo "Result: " . $timestampValue . "\n";
        if ($timestampValue === 'no timestamp') {
            echo "✅ SUCCESS: Correctly shows 'no timestamp'\n";
        } else {
            echo "❌ ISSUE: Should show 'no timestamp' but got: " . $timestampValue . "\n";
        }
    } else {
        echo "❌ ISSUE: No timestamp in display sections\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test with invalid timestamp
$invalid_timestamp_payload = [
    'event_type' => 'order_created',
    'ts' => 'invalid_timestamp',
    'label' => 'Order Created',
    'level' => 'info',
    'data' => [
        'order_id' => 102
    ]
];

echo "\nTesting payload with invalid timestamp:\n";
try {
    $adapter = new \OrderDaemon\CompletionManager\API\Timeline\OrderEventAdapter();
    $displayData = $adapter->extractDisplayData($invalid_timestamp_payload);

    if (isset($displayData['display_sections']['timestamp'])) {
        $timestampValue = $displayData['display_sections']['timestamp']['value'];
        echo "Result: " . $timestampValue . "\n";
        if ($timestampValue === 'error') {
            echo "✅ SUCCESS: Correctly shows 'error' for invalid timestamp\n";
        } else {
            echo "❌ ISSUE: Should show 'error' but got: " . $timestampValue . "\n";
        }
    } else {
        echo "❌ ISSUE: No timestamp in display sections\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
