<?php
/**
 * Debug test to see what's happening with timestamp formatting
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

// Mock PayloadComponentUIToolkit with debug output
if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
    class PayloadComponentUIToolkit {
        public function format_timestamp($timestamp) {
            echo "DEBUG: format_timestamp called with: " . var_export($timestamp, true) . "\n";

            if (is_numeric($timestamp)) {
                $result = gmdate('Y-m-d H:i:s', (int)$timestamp);
                echo "DEBUG: Returning formatted timestamp: " . $result . "\n";
                return $result;
            } elseif (is_string($timestamp)) {
                echo "DEBUG: Returning string timestamp: " . $timestamp . "\n";
                return $timestamp;
            }

            echo "DEBUG: Returning epoch fallback\n";
            return '1970-01-01 00:00:00';
        }
    }
}

// Test payload
$payload = [
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
];

echo "=== DEBUG TIMESTAMP TEST ===\n\n";

try {
    $adapter = new \OrderDaemon\CompletionManager\API\Timeline\OrderEventAdapter();

    echo "Testing timestamp extraction...\n";

    // Test the formatTimestampWithToolkit method directly
    $timestamp = $payload['ts'];
    echo "Input timestamp: " . $timestamp . "\n";

    $formatted = $adapter->formatTimestampWithToolkit($timestamp);
    echo "formatTimestampWithToolkit returned: " . $formatted . "\n";

    // Extract display data
    $displayData = $adapter->extractDisplayData($payload);

    echo "Display data keys: " . implode(', ', array_keys($displayData)) . "\n";

    if (isset($displayData['display_sections']['timestamp'])) {
        $timestampValue = $displayData['display_sections']['timestamp']['value'];
        echo "Final timestamp in display sections: " . $timestampValue . "\n";
    } else {
        echo "No timestamp in display sections\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== DEBUG TEST COMPLETE ===\n";
