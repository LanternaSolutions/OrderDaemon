<?php
/**
 * Simple debug test to see what's happening
 */

// Include DisplayAdapter to trigger the class_exists check
require_once __DIR__ . '/src/API/Timeline/DisplayAdapter.php';

// Mock PayloadComponentUIToolkit
if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
    class PayloadComponentUIToolkit {
        public function format_timestamp($timestamp) {
            echo "format_timestamp called with: " . var_export($timestamp, true) . "\n";

            if (is_numeric($timestamp)) {
                $result = gmdate('Y-m-d H:i:s', (int)$timestamp);
                echo "Returning: " . $result . "\n";
                return $result;
            } elseif (is_string($timestamp)) {
                echo "Returning string: " . $timestamp . "\n";
                return $timestamp;
            }

            echo "Returning epoch\n";
            return '1970-01-01 00:00:00';
        }
    }
}

// Test the validation logic directly
$timestamp = 1766699479;
$toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
$formatted = $toolkit->format_timestamp($timestamp);

echo "Formatted result: '" . $formatted . "'\n";
echo "Is empty: " . (empty($formatted) ? 'yes' : 'no') . "\n";
echo "Is epoch: " . ($formatted === '1970-01-01 00:00:00' ? 'yes' : 'no') . "\n";

if (!empty($formatted) && $formatted !== '1970-01-01 00:00:00') {
    echo "✅ PASS: Would return formatted timestamp\n";
} else {
    echo "❌ FAIL: Would return error\n";
}
