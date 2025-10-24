<?php
declare(strict_types=1);

// Load WordPress
require_once '/var/www/html/wp-load.php';

// Load plugin files
require_once __DIR__ . '/src/View/PayloadRenderer/BaseRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/RuleRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/PaymentRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/OrderRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/SystemRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/AnalysisRenderer.php';
require_once __DIR__ . '/src/View/PayloadRenderer/FallbackRenderer.php';
require_once __DIR__ . '/src/Core/PayloadComponentRegistry.php';
require_once __DIR__ . '/src/Core/LogRegistries.php';

/**
 * Test Debug Pill Rendering
 *
 * This script tests that events categorized as 'debug' in the registry
 * properly receive a DEBUG pill when rendered.
 */

echo "=== TESTING DEBUG EVENT PILL RENDERING ===\n\n";

// Get events categorized as 'debug' from the registry
$event_types = odcm_get_log_event_types();
$debug_events = [];

foreach ($event_types as $event_type => $config) {
    if (isset($config['category']) && $config['category'] === 'debug') {
        $debug_events[$event_type] = $config;
    }
}

echo "Found " . count($debug_events) . " events categorized as 'debug' in the registry.\n\n";

// Test data for debug events
$test_data = [
    // For order_processing_started
    'order_processing_started' => [
        'order_id' => 123,
        'process_id' => 'proc_' . time(),
        'timestamp' => time(),
    ],
    
    // For rule_matched
    'rule_matched' => [
        'rule_id' => 456,
        'rule_name' => 'Test Rule',
        'order_id' => 123,
        'matched' => true,
    ],
    
    // For process_started
    'process_started' => [
        'order_id' => 123,
        'process_type' => 'order_processing',
        'timestamp' => time(),
    ],
    
    // For step_timing
    'step_timing' => [
        'step_name' => 'process_order',
        'duration_ms' => 245.5,
        'timestamp' => time(),
    ],
    
    // For no_match_found
    'no_match_found' => [
        'order_id' => 123,
        'rules_checked' => 5,
        'timestamp' => time(),
    ],
];

// Output directory
$output_dir = __DIR__ . '/test-output';
if (!is_dir($output_dir)) {
    mkdir($output_dir);
}

// Test rendering for debug events
$success_count = 0;
$failure_count = 0;

foreach ($debug_events as $event_type => $config) {
    // Only test events we have test data for
    if (!isset($test_data[$event_type])) {
        echo "Skipping {$event_type} (no test data available)\n";
        continue;
    }
    
    echo "\nTesting debug event: {$event_type}\n";
    echo "----------------------------------------\n";
    
    $data = $test_data[$event_type];
    
        // Explicitly add the event_type to the data
        $data['event_type'] = $event_type;
        
        // Print event info
        echo "Event type: {$event_type}\n";
        echo "Category from registry: " . ($config['category'] ?? 'none') . "\n";
    
    // Get renderer class
    $renderer_class = odcm_get_renderer_for_event_type($event_type);
    
    if (!$renderer_class) {
        echo "✗ No renderer found for {$event_type}\n";
        $failure_count++;
        continue;
    }
    
    echo "Using renderer: {$renderer_class}\n";
    
    try {
        // Create renderer instance - check if namespace is already included
        if (strpos($renderer_class, '\\') !== false) {
            // Already has namespace
            $full_renderer_class = $renderer_class;
        } else {
            // Add namespace
            $full_renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
        }
        $renderer = new $full_renderer_class();
        
        // Set up timeline data (for level checking)
        $timeline = [
            'label' => $config['label'] ?? ucfirst(str_replace('_', ' ', $event_type)),
            'ts' => time(),
            'level' => $config['default_status'] ?? 'info'
        ];
        
        // Create a reflection method to access the protected isDebugEvent method
        $reflectionMethod = new \ReflectionMethod($full_renderer_class, 'isDebugEvent');
        $reflectionMethod->setAccessible(true);
        
        // Test isDebugEvent method directly
        $is_debug = $reflectionMethod->invoke($renderer, $data);
        echo "isDebugEvent result: " . ($is_debug ? 'TRUE' : 'FALSE') . "\n";

        // Access getStatusPill method for further debugging
        $reflectionStatusPill = new \ReflectionMethod($full_renderer_class, 'getStatusPill');
        $reflectionStatusPill->setAccessible(true);
        
        // Call getStatusPill method to see what it returns
        $status_pill = $reflectionStatusPill->invoke($renderer, $data, $event_type);
        echo "getStatusPill result: " . ($status_pill ? json_encode($status_pill) : 'NULL') . "\n";
        
        // Render the event
        $html = $renderer->render($data, $event_type, $timeline);
        
        // Check if the debug pill is present
        $has_debug_pill = strpos($html, 'odcm-status-pill--debug') !== false;
        
        if ($has_debug_pill) {
            echo "✓ Successfully rendered {$event_type} with DEBUG pill\n";
            $success_count++;
        } else {
            echo "✗ DEBUG pill missing for {$event_type}\n";
            $failure_count++;
        }
        
        // Save output to file for inspection
        $output_file = "{$output_dir}/debug_{$event_type}.html";
        file_put_contents($output_file, $html);
        echo "  Output saved to: " . basename($output_file) . "\n";
        
    } catch (\Throwable $e) {
        echo "✗ Error rendering {$event_type}: " . $e->getMessage() . "\n";
        echo "  " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failure_count++;
    }
}

echo "\n=== TEST SUMMARY ===\n";
echo "Total debug events tested: " . ($success_count + $failure_count) . "\n";
echo "Success: {$success_count}\n";
echo "Failure: {$failure_count}\n";

if ($failure_count === 0) {
    echo "\n✓ All tested debug events correctly received DEBUG pills!\n";
} else {
    echo "\n✗ Some debug events did not receive DEBUG pills. Check the output files for details.\n";
}
