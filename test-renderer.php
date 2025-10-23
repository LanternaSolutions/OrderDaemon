<?php
declare(strict_types=1);

// Load WordPress
require_once __DIR__ . '/../../../../wp-load.php';

// Load plugin files
require_once __DIR__ . '/src/View/PayloadRenderer/BaseRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/RuleRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/PaymentRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/OrderRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/SystemRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/AnalysisRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/FallbackRenderer.php';
require_once __DIR__ . '/src/Core/PayloadComponentRegistry.php';

/**
 * Test Renderer System
 *
 * Tests all event types with sample data to verify rendering.
 */

// Sample data for each event type
$test_data = [
    // Rule events
    'rule_matched' => [
        'rule_id' => 123,
        'rule_name' => 'High Value Orders',
        'matched' => true,
        'priority' => 10,
    ],
    'condition_passed' => [
        'condition_label' => 'Order Total > $100',
        'operator' => '>',
        'expected_value' => '100',
        'actual_value' => '150',
        'order_id' => 456,
    ],
    'action_executed' => [
        'action_id' => 'complete_order',
        'action_type' => 'order_action',
        'status' => 'success',
        'parameters' => ['status' => 'completed'],
    ],

    // Payment events
    'payment_completed' => [
        'amount' => 99.99,
        'currency' => 'USD',
        'transaction_id' => 'ch_123456',
        'payment_method' => 'Credit Card',
        'source_gateway' => 'stripe',
        'order_id' => 789,
    ],
    'refund_created' => [
        'amount' => 49.99,
        'currency' => 'USD',
        'refund_id' => 're_123456',
        'order_id' => 789,
        'reason' => 'Customer request',
        'user_id' => 1,
    ],
    'stripe_event' => [
        'type' => 'charge.succeeded',
        'id' => 'evt_123456',
        'amount' => 99.99,
        'currency' => 'USD',
        'event_data' => ['payment_intent' => 'pi_123456'],
    ],

    // Order events
    'status_changed' => [
        'from' => 'pending',
        'to' => 'processing',
        'order_id' => 789,
        'user_id' => 1,
        'manual' => true,
    ],
    'block_checkout_processed' => [
        'order_id' => 789,
        'status' => 'success',
        'payment_method' => 'stripe',
        'total' => 99.99,
        'currency' => 'USD',
        'customer_data' => ['email' => 'customer@example.com'],
    ],
    'meta_updated' => [
        'order_id' => 789,
        'meta_key' => '_shipping_address',
        'old_value' => '123 Old St',
        'new_value' => '456 New St',
        'user_id' => 1,
    ],

    // System events
    'info' => [
        'message' => 'Process completed successfully',
        'process_id' => 'proc_123456',
    ],
    'warning' => [
        'message' => 'Rate limit approaching',
        'current_rate' => 95,
        'limit' => 100,
    ],
    'error' => [
        'message' => 'API request failed',
        'code' => 500,
        'details' => 'Connection timeout',
    ],
    'metrics' => [
        'name' => 'API Response Time',
        'value' => 245.5,
        'unit' => 'ms',
        'context' => ['endpoint' => '/api/v1/orders'],
    ],

    // Analysis events
    'refund_analysis' => [
        'refund_id' => 're_123456',
        'order_id' => 789,
        'amount' => 49.99,
        'currency' => 'USD',
        'refund_type' => 'partial',
        'percentage' => 50,
        'refund_details' => ['items' => [['id' => 1, 'qty' => 1]]],
        'order_impact' => ['total_refunded' => 49.99],
    ],
    'woocommerce_analysis' => [
        'order_id' => 789,
        'status' => 'completed',
        'total' => 99.99,
        'currency' => 'USD',
        'items' => [['product_id' => 123, 'qty' => 2]],
        'changes' => ['status' => ['from' => 'processing', 'to' => 'completed']],
        'impact' => ['stock_reduced' => true],
    ],
    'dedup' => [
        'order_id' => 789,
        'status' => 'checking',
        'hook' => 'woocommerce_order_status_completed',
        'specific_hook' => true,
        'check_results' => ['duplicate' => false],
        'history' => ['last_processed' => '2023-01-01 12:00:00'],
    ],
];

// Test each event type
foreach ($test_data as $event_type => $data) {
    echo "\nTesting event type: {$event_type}\n";
    echo "----------------------------------------\n";

    // Get renderer class
    $renderer_class = odcm_get_renderer_for_event_type($event_type);
    $renderer = new $renderer_class();

    // Render the event
    try {
        $html = $renderer->render($data, $event_type);
        echo "✓ Successfully rendered {$event_type}\n";
        
        // Save output to file for inspection
        $output_dir = __DIR__ . '/test-output';
        if (!is_dir($output_dir)) {
            mkdir($output_dir);
        }
        file_put_contents(
            "{$output_dir}/{$event_type}.html",
            $html
        );
        echo "  Output saved to: test-output/{$event_type}.html\n";
    } catch (\Throwable $e) {
        echo "✗ Error rendering {$event_type}: " . $e->getMessage() . "\n";
        echo "  " . $e->getTraceAsString() . "\n";
    }
}

echo "\nTesting complete! Check test-output/ directory for rendered results.\n";
