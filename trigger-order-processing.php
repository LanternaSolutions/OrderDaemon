<?php
/**
 * Script to manually trigger processing for converted placeholder orders
 */

// Set up WordPress environment
require_once(__DIR__ . '/wp-load.php');

// Include Order Daemon core
require_once(__DIR__ . '/src/Core/Core.php');

function trigger_order_processing() {
    $order_ids = [121, 122];

    echo "Starting manual processing for converted orders...\n";

    foreach ($order_ids as $order_id) {
        echo "Processing order #$order_id...\n";

        try {
            // Initialize the Core class
            $core = new \OrderDaemon\CompletionManager\Core\Core();

            // Schedule completion check for this order
            $result = $core->schedule_completion_check($order_id);

            if ($result) {
                echo "✅ Successfully scheduled order #$order_id for processing\n";
            } else {
                echo "❌ Failed to schedule order #$order_id for processing\n";
            }

            // Also trigger the status change hook manually to ensure processing
            do_action('woocommerce_order_status_processing', $order_id);

            echo "🔄 Triggered status change hook for order #$order_id\n";

        } catch (Exception $e) {
            echo "💥 Error processing order #$order_id: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    echo "Processing complete. Orders should now be processed by Order Daemon rules.\n";
}

// Run the function
trigger_order_processing();
