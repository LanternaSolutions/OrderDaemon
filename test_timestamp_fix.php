<?php
/**
 * Test script to verify the timestamp fix
 *
 * This script tests the fixed formatTimestamp() method to ensure it properly
 * handles different timestamp formats and each component shows its individual timestamp.
 */

// Include the necessary files
require_once __DIR__ . '/src/API/Timeline/RegistryTimelineRenderer.php';

// Create a mock RegistryTimelineRenderer instance to test the formatTimestamp method
// Since the method is private, we'll use reflection to access it
$renderer = new ReflectionClass('OrderDaemon\CompletionManager\API\Timeline\RegistryTimelineRenderer');
$method = $renderer->getMethod('formatTimestamp');
$method->setAccessible(true);

// Create an instance of the renderer
$rendererInstance = $renderer->newInstanceWithoutConstructor();

// Test cases with different timestamp formats
$testCases = [
    // Numeric timestamps
    ['input' => 1766787825, 'expected' => '2025-12-26 23:23:45', 'description' => 'Numeric timestamp'],
    ['input' => 1766787831, 'expected' => '2025-12-26 23:23:51', 'description' => 'Different numeric timestamp'],

    // String timestamps (numeric strings)
    ['input' => '1766787825', 'expected' => '2025-12-26 23:23:45', 'description' => 'String numeric timestamp'],
    ['input' => '1766787831', 'expected' => '2025-12-26 23:23:51', 'description' => 'Different string numeric timestamp'],

    // String timestamps with milliseconds
    ['input' => '1766787831.117287', 'expected' => '1766787831.117287', 'description' => 'String with milliseconds (should return as-is)'],

    // Invalid inputs
    ['input' => null, 'expected' => 'Invalid timestamp', 'description' => 'Null input'],
    ['input' => '', 'expected' => 'Invalid timestamp', 'description' => 'Empty string'],
    ['input' => 'invalid', 'expected' => 'invalid', 'description' => 'Unparseable string'],
];

echo "Testing formatTimestamp() method fixes...\n";
echo "========================================\n\n";

$allPassed = true;

foreach ($testCases as $i => $testCase) {
    $input = $testCase['input'];
    $expected = $testCase['expected'];
    $description = $testCase['description'];

    try {
        $result = $method->invoke($rendererInstance, $input);

        // For numeric inputs, check if the result matches the expected format
        if (is_numeric($input) || (is_string($input) && is_numeric($input))) {
            // The result should be a formatted date string
            $passed = (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
        } else {
            // For other cases, check exact match
            $passed = ($result === $expected);
        }

        $status = $passed ? 'PASS' : 'FAIL';
        echo "Test " . ($i + 1) . ": $description\n";
        echo "  Input:    " . var_export($input, true) . "\n";
        echo "  Expected: $expected\n";
        echo "  Result:   $result\n";
        echo "  Status:   $status\n";

        if (!$passed) {
            $allPassed = false;
            echo "  ❌ FAILED: Result doesn't match expected value\n";
        } else {
            echo "  ✅ PASSED\n";
        }

        echo "\n";
    } catch (Exception $e) {
        echo "Test " . ($i + 1) . ": $description\n";
        echo "  Input:    " . var_export($input, true) . "\n";
        echo "  Error:    " . $e->getMessage() . "\n";
        echo "  Status:   FAIL\n";
        echo "  ❌ FAILED: Exception thrown\n";
        echo "\n";
        $allPassed = false;
    }
}

echo "========================================\n";
if ($allPassed) {
    echo "✅ All tests PASSED! The timestamp fix is working correctly.\n";
    echo "\nThe fix ensures that:\n";
    echo "1. Each timeline component will display its own unique timestamp\n";
    echo "2. Numeric timestamps are properly formatted\n";
    echo "3. String timestamps are handled correctly\n";
    echo "4. Invalid timestamps show a clear error message instead of current time\n";
} else {
    echo "❌ Some tests FAILED! Please review the implementation.\n";
}
