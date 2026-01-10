<?php
/**
 * Process Stuck Queue Entries
 *
 * This script processes any pending audit log queue entries that may be stuck
 * and moves them to the main audit log table so they appear in the Insight Dashboard.
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

function process_stuck_audit_log_queue() {
    global $wpdb;

    echo '=== Audit Log Queue Processor ===' . PHP_EOL . PHP_EOL;

    // Check current state
    $log_table = $wpdb->prefix . 'odcm_audit_log';
    $queue_table = $wpdb->prefix . 'odcm_audit_log_queue';

    $log_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$log_table} WHERE is_test = 1"));
    $queue_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'pending'));

    echo 'Current State:' . PHP_EOL;
    echo '  Test events in audit log: ' . $log_count . PHP_EOL;
    echo '  Pending queue events: ' . $queue_count . PHP_EOL . PHP_EOL;

    if ($queue_count > 0) {
        echo 'Processing pending queue entries...' . PHP_EOL;

        $pending_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT queue_id, event_data FROM {$queue_table} WHERE status = %s LIMIT 100",
            'pending'
        ));

        $processed_count = 0;
        $failed_count = 0;

        foreach ($pending_entries as $entry) {
            $event_data = json_decode($entry->event_data, true);

            if (!empty($event_data) && is_array($event_data)) {
                // Insert into main audit log table
                $log_result = $wpdb->insert($log_table, [
                    'timestamp' => $event_data['timestamp'],
                    'order_id' => $event_data['order_id'],
                    'event_type' => $event_data['event_type'],
                    'status' => $event_data['status'],
                    'summary' => $event_data['summary'],
                    'details' => $event_data['details'],
                    'source' => $event_data['source'],
                    'is_test' => $event_data['is_test'],
                    'process_id' => $event_data['process_id']
                ]);

                if ($log_result !== false) {
                    // Mark queue entry as processed
                    $wpdb->update($queue_table, ['status' => 'processed'], ['queue_id' => $entry->queue_id]);
                    $processed_count++;
                    echo '  ✓ Processed queue entry: ' . $entry->queue_id . PHP_EOL;
                } else {
                    $failed_count++;
                    echo '  ✗ Failed to process queue entry: ' . $entry->queue_id . PHP_EOL;
                }
            }
        }

        // Check final state
        $final_log_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$log_table} WHERE is_test = 1"));
        $final_queue_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'pending'));

        echo PHP_EOL . 'Processing Complete:' . PHP_EOL;
        echo '  Events processed: ' . $processed_count . PHP_EOL;
        echo '  Events failed: ' . $failed_count . PHP_EOL;
        echo '  Final test events in log: ' . $final_log_count . PHP_EOL;
        echo '  Final pending queue events: ' . $final_queue_count . PHP_EOL . PHP_EOL;

        if ($final_queue_count === 0) {
            echo '🎉 All queue entries processed successfully!' . PHP_EOL;
            echo 'Events should now be visible in the Insight Dashboard.' . PHP_EOL;
        } else {
            echo '⚠️  Some queue entries remain unprocessed.' . PHP_EOL;
        }
    } else {
        echo 'No pending queue entries found.' . PHP_EOL;
        echo 'All events may already be processed and visible in the dashboard.' . PHP_EOL;
    }

    return true;
}

// Run the processor if called directly
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('odcm process-stuck-queue', function() {
        $result = process_stuck_audit_log_queue();
        if ($result) {
            WP_CLI::success('Queue processing completed');
        } else {
            WP_CLI::error('Queue processing failed');
        }
    });
}
