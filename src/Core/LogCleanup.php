<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles automatic cleanup of old audit trail logs.
 * This class implements safe batch deletion to prevent database performance issues.
 */
class LogCleanup
{

    /**
     * Maximum number of rows to delete per batch to prevent database locks.
     */
    private const BATCH_SIZE = 500;

    /**
     * Initialize the log cleanup functionality.
     *
     * @return void
     */
    public function init(): void
    {
        // Hook into the Action Scheduler cleanup action
        add_action('odcm_cleanup_old_logs', [$this, 'cleanup_old_logs'], 10);

    }//end init()

    /**
     * Cleanup old audit trail logs based on the retention policy.
     * This method performs safe batch deletion to prevent database performance issues.
     * Implements differentiated retention logic for free vs premium users.
     *
     * @return void
     */
    public function cleanup_old_logs(): void
    {
        global $wpdb;

        // Free/Core plugin: use a fixed retention period to keep DB lean
        $retention_days = 30; // Fixed for free/core - 30 days provides good balance

        // Define table names - use esc_sql for table identifiers
        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payloads_table = $wpdb->prefix . 'odcm_audit_log_payloads';
        // Escape table names for use in SQL queries (placeholders cannot be used for identifiers)
        $log_table_identifier = esc_sql($log_table);
        $payloads_table_identifier = esc_sql($payloads_table);

        // Calculate the cutoff date
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        // Create a cache key for the count query
        $count_cache_key = 'odcm_logs_to_delete_' . md5($cutoff_date);

        // Try to get from cache first
        $total_to_delete = wp_cache_get($count_cache_key);

        // Cache miss - perform count query
        if (false === $total_to_delete) {
            $count_sql = "SELECT COUNT(*) FROM {$log_table_identifier} WHERE timestamp < %s";
            $total_to_delete = $wpdb->get_var($wpdb->prepare($count_sql, $cutoff_date));

            // Cache the result for 10 minutes
            // This is appropriate for cleanup operations that don't need real-time precision
            wp_cache_set($count_cache_key, $total_to_delete, '', 10 * MINUTE_IN_SECONDS);
        }

        if (!$total_to_delete || $total_to_delete === 0) {
            // No records to delete
            return;
        }

        // Perform batch deletion to prevent database locks
        $deleted_total = 0;
        $max_iterations = 100; // Safety limit to prevent infinite loops
        $iteration = 0;

        while ($deleted_total < $total_to_delete && $iteration < $max_iterations) {
            // Create a unique cache key for this batch
            // Include iteration to ensure each batch gets a fresh query
            $batch_cache_key = 'odcm_logs_batch_' . md5($cutoff_date . '_' . $iteration);

            // Try to get from cache first
            $logs_to_delete = wp_cache_get($batch_cache_key);

            // Cache miss - perform batch query
            if (false === $logs_to_delete) {
                $batch_sql = "SELECT log_id, payload_id FROM {$log_table_identifier} WHERE timestamp < %s LIMIT %d";
                $logs_to_delete = $wpdb->get_results($wpdb->prepare($batch_sql, $cutoff_date, self::BATCH_SIZE));

                // Cache the result briefly - just enough to avoid duplicate queries
                // in case of concurrent cleanup processes
                wp_cache_set($batch_cache_key, $logs_to_delete, '', 60); // 1 minute
            }

            if (empty($logs_to_delete)) {
                // No more logs to delete
                break;
            }

            // Collect payload IDs to delete
            $payload_ids = [];
            $log_ids = [];

            foreach ($logs_to_delete as $log) {
                $log_ids[] = $log->log_id;

                // Only collect non-null payload IDs
                if (!empty($log->payload_id)) {
                    $payload_ids[] = $log->payload_id;
                }
            }

            // Delete the log entries
            if (!empty($log_ids)) {
                // Transaction key to prevent duplicate delete operations
                $delete_lock_key = 'odcm_deleting_logs_' . md5(implode(',', $log_ids));
                $is_deleting = wp_cache_get($delete_lock_key);

                if (false === $is_deleting) {
                    // Set lock
                    wp_cache_set($delete_lock_key, true, '', 60); // 1 minute lock

                    // Create placeholder string for the IN clause
                    $placeholders = implode(',', array_fill(0, count($log_ids), '%d'));
                    $delete_sql = "DELETE FROM {$log_table_identifier} WHERE log_id IN ($placeholders)";
                    $deleted_rows = $wpdb->query($wpdb->prepare($delete_sql, ...$log_ids));

                    // Delete log ID cache keys after deletion
                    foreach ($log_ids as $log_id) {
                        // Delete any cached log entries that might exist
                        wp_cache_delete('odcm_log_' . $log_id);
                    }

                    // Release lock
                    wp_cache_delete($delete_lock_key);
                } else {
                    // Another process is deleting these logs
                    $deleted_rows = 0;
                }

                if ($deleted_rows === false) {
                    // Error occurred
                    break;
                }

                $deleted_total += $deleted_rows;
            }

            // Delete the corresponding payload entries
            if (!empty($payload_ids)) {
                // Transaction key to prevent duplicate delete operations
                $payload_lock_key = 'odcm_deleting_payloads_' . md5(implode(',', $payload_ids));
                $is_deleting_payloads = wp_cache_get($payload_lock_key);

                if (false === $is_deleting_payloads) {
                    // Set lock
                    wp_cache_set($payload_lock_key, true, '', 60); // 1 minute lock

                    // Create placeholder string for the IN clause
                    $placeholders = implode(',', array_fill(0, count($payload_ids), '%d'));
                    $delete_sql = "DELETE FROM {$payloads_table_identifier} WHERE payload_id IN ($placeholders)";
                    $wpdb->query($wpdb->prepare($delete_sql, ...$payload_ids));

                    // Delete payload cache keys after deletion
                    foreach ($payload_ids as $payload_id) {
                        // Delete any cached payload entries that might exist
                        wp_cache_delete('odcm_payload_' . $payload_id);
                    }

                    // Release lock
                    wp_cache_delete($payload_lock_key);
                }
            }

            $iteration++;

            // Add a small delay between batches to reduce database load
            if (count($logs_to_delete) === self::BATCH_SIZE) {
                usleep(100000); // 0.1 second delay
            }
        }//end while

        // Log the cleanup activity if any records were deleted
        if ($deleted_total > 0) {
            $this->log_cleanup_activity($deleted_total, $retention_days);
        }

    }//end cleanup_old_logs()

    /**
     * Log the cleanup activity to the audit trail.
     *
     * @param  integer $deleted_count  Number of records deleted.
     * @param  integer $retention_days Retention period in days.
     * @return void
     */
    private function log_cleanup_activity(int $deleted_count, int $retention_days): void
    {

        $retention_type = 'free';

        $details = [
            'deleted_count'    => $deleted_count,
            'deleted_payloads' => 0,
            'retention_days'   => $retention_days,
            'retention_type'   => $retention_type,
            'cleanup_date'     => current_time('mysql'),
        ];

        // Narrative-based single process entry for cleanup activity
        $pl = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger(new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer());
        $pl->start('admin_action', [ 'summary' => 'Audit log cleanup' ]);
        $pl->add_component('metrics', 'Records deleted', [ 'name' => 'deleted_count', 'value' => (float)$deleted_count ], 'debug');
        $pl->add_component('metrics', 'Retention days', [ 'name' => 'retention_days', 'value' => (float)$retention_days, 'unit' => 'days' ], 'debug');
        $pl->add_component('info', 'Cleanup details', [ 'message' => sprintf('Type: %s', esc_html($retention_type)) ], 'info');
        $pl->finish('success', sprintf('Deleted %d old log records (retention %d days)', $deleted_count, $retention_days));

    }//end log_cleanup_activity()

    /**
     * Cache of log stats to prevent redundant counting operations
     *
     * @var array
     */
    private static $log_stats_cache = [];

    /**
     * Manually trigger log cleanup (for testing or manual execution).
     * This method can be called from WP-CLI or other admin interfaces.
     * Implements differentiated retention logic for free vs premium users.
     *
     * @return array Results of the cleanup operation.
     */
    public function manual_cleanup(): array
    {
        global $wpdb;

        // Free/Core plugin: use a fixed retention period
        $retention_days = 30;

        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $log_table_identifier = esc_sql($log_table);
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        // Create a cache key for the manual cleanup count
        $manual_count_cache_key = 'odcm_manual_cleanup_count_' . md5($cutoff_date);

        // Try to get from cache first
        $count_to_delete = wp_cache_get($manual_count_cache_key);

        // Cache miss - perform count query
        if (false === $count_to_delete) {
            $count_sql = "SELECT COUNT(*) FROM {$log_table_identifier} WHERE timestamp < %s";
            $count_to_delete = $wpdb->get_var($wpdb->prepare($count_sql, $cutoff_date));

            // Cache the result for 5 minutes - manual cleanup has higher freshness expectations
            wp_cache_set($manual_count_cache_key, $count_to_delete, '', 5 * MINUTE_IN_SECONDS);
        }

        if (!$count_to_delete || $count_to_delete === 0) {
            return [
                'success'       => true,
                'message'       => __('No old records found to clean up', 'order-daemon'),
                'deleted_count' => 0,
                'deleted_payloads' => 0,
            ];
        }

        // Create a cache key for payload count
        $payload_count_cache_key = 'odcm_manual_payload_count_' . md5($cutoff_date);

        // Try to get from cache first
        $payload_count_to_delete = wp_cache_get($payload_count_cache_key);

        // Cache miss - perform count query
        if (false === $payload_count_to_delete) {
            $count_sql = "SELECT COUNT(*) FROM {$log_table_identifier} WHERE timestamp < %s AND payload_id IS NOT NULL";
            $payload_count_to_delete = $wpdb->get_var($wpdb->prepare($count_sql, $cutoff_date));

            // Cache the result for 5 minutes
            wp_cache_set($payload_count_cache_key, $payload_count_to_delete, '', 5 * MINUTE_IN_SECONDS);
        }

        // Perform the cleanup
        $this->cleanup_old_logs();

        $message = sprintf(
            /* translators: %1$d: number of deleted records, %2$d: retention period in days */
            __('Successfully deleted %1$d log records (retention: %2$d days)', 'order-daemon'),
            $count_to_delete,
            $retention_days
        );

        if ($payload_count_to_delete > 0) {
            $message .= ' ' . sprintf(
                /* translators: %d: number of deleted payload records */
                __('Also deleted %d payload records', 'order-daemon'),
                $payload_count_to_delete
            );
        }

        return [
            'success'       => true,
            'message'       => $message,
            'deleted_count' => $count_to_delete,
            'deleted_payloads' => $payload_count_to_delete,
        ];

    }//end manual_cleanup()

}//end class
