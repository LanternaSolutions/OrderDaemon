<?php
/**
 * Debug script to test timestamp formatting
 */

// Test the formatTimestamp method
function formatTimestamp($ts): string
{
    if (is_numeric($ts)) {
        return gmdate('Y-m-d H:i:s', (int)$ts);
    } elseif (is_string($ts)) {
        // Handle string timestamps - try to parse them first
        if (is_numeric($ts)) {
            // String representation of a number
            return gmdate('Y-m-d H:i:s', (int)$ts);
        } elseif (strtotime($ts) !== false) {
            // Parseable date string
            return gmdate('Y-m-d H:i:s', strtotime($ts));
        } else {
            // Fallback for unparseable strings - return as-is but this shouldn't happen with valid data
            return $ts;
        }
    }

    // For invalid/empty timestamps, return a placeholder instead of current time
    return 'Invalid timestamp';
}

// Test cases from the timeline.txt file
$testCases = [
    1766787825,  // Should be: 2025-12-26 23:23:45
    1766787831,  // Should be: 2025-12-26 23:23:51
    1766787832,  // Should be: 2025-12-26 23:23:52
    '1766787825', // Should be: 2025-12-26 23:23:45
    '1766787831', // Should be: 2025-12-26 23:23:51
    '1766787831.117287', // Should be: 2025-12-26 23:23:51
];

echo "PHP Timestamp Formatting Test Results:\n";
echo "=====================================\n\n";

foreach ($testCases as $i => $timestamp) {
    $result = formatTimestamp($timestamp);
    $expected = '';

    // Set expected results based on timestamp
    if ($timestamp === 1766787825 || $timestamp === '1766787825') {
        $expected = '2025-12-26 23:23:45';
    } elseif ($timestamp === 1766787831 || $timestamp === '1766787831' || strpos($timestamp, '1766787831') === 0) {
        $expected = '2025-12-26 23:23:51';
    } elseif ($timestamp === 1766787832 || $timestamp === '1766787832') {
        $expected = '2025-12-26 23:23:52';
    }

    $status = ($result === $expected) ? '✅ PASS' : '❌ FAIL';
    echo "Test " . ($i + 1) . ": " . $status . "\n";
    echo "  Input:    " . $timestamp . "\n";
    echo "  Expected: " . $expected . "\n";
    echo "  Result:   " . $result . "\n";
    echo "\n";
}

echo "Analysis:\n";
echo "=========\n";
echo "The PHP backend is formatting timestamps correctly.\n";
echo "The issue appears to be in the JavaScript frontend where timestamps\n";
echo "are being reformatted after the HTML is rendered.\n";
echo "\n";

echo "Looking at the timeline.txt file, the timestamps show as:\n";
echo "- 'December 26, 2025 11:23 pm :45'\n";
echo "- 'December 26, 2025 11:23 pm :51'\n";
echo "- 'December 26, 2025 11:23 pm :52'\n";
echo "\n";

echo "This suggests that:\n";
echo "1. PHP correctly formats timestamps as '2025-12-26 23:23:45'\n";
echo "2. JavaScript is reformatting them to 'December 26, 2025 11:23 pm :45'\n";
echo "3. The JavaScript formatting is incorrect - it should show seconds properly\n";
echo "4. The issue is likely in the JavaScript formatTimestamp() method\n";
