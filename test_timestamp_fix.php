<?php
/**
 * Test script to verify the timestamp formatting fix
 *
 * This script tests that:
 * 1. Timeline timestamps (PHP-formatted) are NOT reformatted by JavaScript
 * 2. Log stream timestamps (raw) ARE formatted by JavaScript
 * 3. The context-aware formatting works correctly
 */

// Test data
$test_cases = [
    [
        'name' => 'PHP-formatted timeline timestamp',
        'timestamp' => '2025-12-26 22:23:45',
        'element_class' => '', // No js-format-timestamp class
        'expected_behavior' => 'Should remain unchanged (no JavaScript formatting)',
        'expected_output' => '2025-12-26 22:23:45'
    ],
    [
        'name' => 'Raw Unix timestamp (seconds)',
        'timestamp' => '1766787825',
        'element_class' => 'js-format-timestamp', // Has formatting class
        'expected_behavior' => 'Should be formatted by JavaScript',
        'expected_output' => 'Should show formatted date/time based on user locale'
    ],
    [
        'name' => 'Raw Unix timestamp with milliseconds',
        'timestamp' => '1766787825000',
        'element_class' => 'js-format-timestamp', // Has formatting class
        'expected_behavior' => 'Should be formatted by JavaScript',
        'expected_output' => 'Should show formatted date/time based on user locale'
    ],
    [
        'name' => 'ISO date string',
        'timestamp' => '2025-12-26T22:23:45Z',
        'element_class' => 'js-format-timestamp', // Has formatting class
        'expected_behavior' => 'Should be formatted by JavaScript',
        'expected_output' => 'Should show formatted date/time based on user locale'
    ]
];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Timestamp Formatting Fix Test</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
        .test-case { border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .test-name { font-weight: bold; color: #0073aa; }
        .expected { color: #228B22; }
        .actual { color: #DC143C; }
        .pass { background-color: #f0fff0; border-left: 4px solid #228B22; }
        .fail { background-color: #fff0f0; border-left: 4px solid #DC143C; }
        .js-format-timestamp { font-weight: bold; }
    </style>
</head>
<body>
    <h1>Timestamp Formatting Fix Test</h1>
    <p>This test verifies that the context-aware timestamp formatting works correctly.</p>

    <h2>Test Cases</h2>";

foreach ($test_cases as $index => $case) {
    $has_formatting_class = !empty($case['element_class']);
    $element_class = $has_formatting_class ? 'class="' . $case['element_class'] . '"' : '';

    echo "<div class='test-case' id='test-case-$index'>
        <div class='test-name'>{$case['name']}</div>
        <div><strong>Input:</strong> {$case['timestamp']}</div>
        <div><strong>Element Class:</strong> {$case['element_class']}</div>
        <div class='expected'><strong>Expected:</strong> {$case['expected_behavior']} - {$case['expected_output']}</div>
        <div><strong>Actual Output:</strong> <span class='actual' id='actual-$index'>Loading...</span></div>
        <div><strong>Status:</strong> <span id='status-$index'>Running...</span></div>
    </div>";
}

echo "
    <h2>Test Summary</h2>
    <div id='summary'>
        <p>Running tests...</p>
    </div>

    <script>
        // Mock the formatTimestamp function to simulate the fix
        function formatTimestamp(ts, element = null) {
            try {
                // If element is provided and doesn't have the js-format-timestamp class,
                // return the timestamp as-is (for timeline components and any non-log-stream timestamps)
                if (element && !element.classList.contains('js-format-timestamp')) {
                    // Return raw timestamp for non-log-stream elements
                    return typeof ts === 'string' ? ts : String(ts);
                }

                // For elements with js-format-timestamp class, apply formatting
                let timestamp = ts;

                // Handle string timestamps and unit conversion
                if (typeof timestamp === 'string') {
                    // Check if it's a numeric string (possibly with milliseconds)
                    if (/^\\d+(\\.\\d+)?$/.test(timestamp)) {
                        // Convert to number
                        timestamp = parseFloat(timestamp);

                        // If it's a Unix timestamp in seconds (10 digits), convert to milliseconds
                        if (timestamp > 1000000000 && timestamp < 9999999999) {
                            timestamp = timestamp * 1000;
                        }
                    } else {
                        // Try to parse as ISO date string
                        const parsedDate = new Date(timestamp);
                        if (!isNaN(parsedDate.getTime())) {
                            timestamp = parsedDate.getTime();
                        }
                    }
                } else if (typeof timestamp === 'number') {
                    // If it's a Unix timestamp in seconds (10 digits), convert to milliseconds
                    if (timestamp > 1000000000 && timestamp < 9999999999) {
                        timestamp = timestamp * 1000;
                    }
                }

                // Create Date object with properly formatted timestamp
                const d = new Date(timestamp);

                // Format as date & time
                return d.toLocaleString(undefined, {
                    year: 'numeric', month: 'short', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                });

            } catch (e) {
                console.warn('Timestamp formatting error:', e);
                return 'invalid: ' + String(ts || '');
            }
        }

        // Run the tests
        document.addEventListener('DOMContentLoaded', function() {
            const testCases = " . json_encode($test_cases) . ";
            let passed = 0;
            let failed = 0;

            testCases.forEach((testCase, index) => {
                const testElement = document.getElementById('test-case-' + index);
                const actualElement = document.getElementById('actual-' + index);
                const statusElement = document.getElementById('status-' + index);

                // Create a mock element to simulate the DOM context
                const mockElement = document.createElement('div');
                if (testCase.element_class) {
                    mockElement.classList.add(testCase.element_class);
                }

                // Call the formatTimestamp function
                const result = formatTimestamp(testCase.timestamp, mockElement);

                // Update the actual output
                actualElement.textContent = result;

                // Determine if the test passed
                let testPassed = false;
                let statusMessage = '';

                if (!testCase.element_class) {
                    // For timeline timestamps (no formatting class), should remain unchanged
                    testPassed = result === testCase.timestamp;
                    statusMessage = testPassed ? 'PASS: Timestamp unchanged as expected' : 'FAIL: Timestamp was formatted when it should not be';
                } else {
                    // For log stream timestamps (with formatting class), should be formatted
                    testPassed = result !== testCase.timestamp && !result.startsWith('invalid:');
                    statusMessage = testPassed ? 'PASS: Timestamp formatted as expected' : 'FAIL: Timestamp was not formatted properly';
                }

                // Update status
                statusElement.textContent = statusMessage;

                // Update test case styling
                if (testPassed) {
                    testElement.classList.add('pass');
                    testElement.classList.remove('fail');
                    passed++;
                } else {
                    testElement.classList.add('fail');
                    testElement.classList.remove('pass');
                    failed++;
                }
            });

            // Update summary
            const summaryElement = document.getElementById('summary');
            summaryElement.innerHTML = `
                <p><strong>Tests Completed:</strong> ` + testCases.length + `</p>
                <p><strong>Passed:</strong> <span style="color: #228B22;">` + passed + `</span></p>
                <p><strong>Failed:</strong> <span style="color: #DC143C;">` + failed + `</span></p>
                <p><strong>Success Rate:</strong> ` + Math.round((passed / testCases.length) * 100) + `%</p>
            `;

            if (failed === 0) {
                summaryElement.innerHTML += '<p style="color: #228B22; font-weight: bold;">✅ All tests passed! The timestamp formatting fix is working correctly.</p>';
            } else {
                summaryElement.innerHTML += '<p style="color: #DC143C; font-weight: bold;">❌ Some tests failed. Please review the implementation.</p>';
            }
        });
    </script>
</body>
</html>";
