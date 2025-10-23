<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use OrderDaemon\CompletionManager\View\PayloadRenderer\RuleRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\OrderRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\PaymentRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\SystemRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\AnalysisRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer;

// Test data with metrics
$test_data = [
    'rule_id' => 123,
    'rule_name' => 'Test Rule',
    'metrics' => [
        'execution_time_ms' => 150.45,
        'memory_usage_bytes' => 1024,
        'cache_hits' => 5,
        'db_queries' => 3
    ]
];

// Test each renderer
$renderers = [
    'RuleRenderer' => [new RuleRenderer(), 'rule_matched'],
    'OrderRenderer' => [new OrderRenderer(), 'order_loaded'],
    'PaymentRenderer' => [new PaymentRenderer(), 'payment_completed'],
    'SystemRenderer' => [new SystemRenderer(), 'metrics'],
    'AnalysisRenderer' => [new AnalysisRenderer(), 'woocommerce_analysis'],
    'FallbackRenderer' => [new FallbackRenderer(), 'unknown_event']
];

echo "Testing metrics rendering across all renderers:\n\n";

foreach ($renderers as $name => [$renderer, $event_type]) {
    echo "=== Testing $name with $event_type event ===\n";
    $result = $renderer->render($test_data, $event_type);
    
    // Check if metrics section is present
    if (strpos($result, 'Performance Metrics') !== false) {
        echo "✓ Metrics section found\n";
        
        // Check for specific metric values
        $checks = [
            'execution_time_ms' => '150.45 ms',
            'memory_usage_bytes' => '1 KB',
            'cache_hits' => '5',
            'db_queries' => '3'
        ];
        
        foreach ($checks as $metric => $expected) {
            if (strpos($result, $expected) !== false) {
                echo "✓ Found metric: $metric = $expected\n";
            } else {
                echo "✗ Missing or incorrect metric: $metric\n";
            }
        }
    } else {
        echo "✗ No metrics section found!\n";
    }
    echo "\n";
}

echo "Test complete.\n";
