<?php
/**
 * Test script for timeline event caching
 * 
 * This script tests the UniversalEventProcessor's handling of rule execution events, 
 * particularly focusing on the cacheRuleExecutionEvent() method that was modified
 * to handle both boolean and integer event_id values.
 */

// Bootstrap WordPress (needed for WordPress functions)
require_once __DIR__ . '/src/Plugin.php';

// Set debug mode
define('ODCM_DEBUG', true);

// Mock an event that would trigger the TypeError
$test_order_id = 75; // Order ID from the error message
$test_rule_id = 42;  // Arbitrary rule ID for testing
$test_rule_name = "Auto-Complete Virtual Products";
$test_primary_trigger = "payment_completed";
$test_trigger_events = ['payment_completed' => ['source_gateway' => 'stripe']];
$test_process_id = "odcm:lifecycle:test123";

// Expected boolean response from odcm_log_event (simulating queue-based logging)
$test_event_id = true;

echo "Starting test for cacheRuleExecutionEvent...\n";

try {
    // Get the UniversalEventProcessor instance
    $processor = \OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor::instance();
    
    // Use reflection to access the private method
    $reflection = new ReflectionClass($processor);
    $method = $reflection->getMethod('cacheRuleExecutionEvent');
    $method->setAccessible(true);
    
    // Call the method with our test data including boolean event_id
    echo "Testing with boolean event_id (simulating queued logging)...\n";
    $method->invokeArgs($processor, [
        $test_order_id,
        $test_rule_id,
        $test_event_id, // Boolean instead of int
        $test_rule_name,
        $test_primary_trigger,
        $test_trigger_events,
        $test_process_id
    ]);
    
    echo "SUCCESS: Method accepted boolean event_id without TypeError\n";
    
    // Also test with integer for completeness
    echo "Testing with integer event_id...\n";
    $method->invokeArgs($processor, [
        $test_order_id,
        $test_rule_id,
        123, // Integer event_id
        $test_rule_name,
        $test_primary_trigger,
        $test_trigger_events,
        $test_process_id
    ]);
    
    echo "SUCCESS: Method accepted integer event_id\n";
    echo "All tests completed successfully!\n";
    
} catch (\TypeError $e) {
    echo "ERROR: TypeError still occurring: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch (\Exception $e) {
    echo "ERROR: Unexpected exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
