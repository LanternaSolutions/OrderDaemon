<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

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
        
        // Define table names
        $log_table = $wpdb->prefix.'odcm_audit_log';
        $payloads_table = $wpdb->prefix.'odcm_audit_log_payloads';

        // Calculate the cutoff date
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        // First, count how many records would be deleted (for logging purposes)
        $count_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE timestamp < %s",
            $cutoff_date
        );
        $total_to_delete = $wpdb->get_var($count_query);

        if (!$total_to_delete || $total_to_delete === 0) {
            // No records to delete
            return;
        }

        // Perform batch deletion to prevent database locks
        $deleted_total = 0;
        $max_iterations = 100; // Safety limit to prevent infinite loops
        $iteration = 0;

        while ($deleted_total < $total_to_delete && $iteration < $max_iterations) {
            // First, get a batch of log IDs to delete
            $log_ids_query = $wpdb->prepare(
                "SELECT log_id, payload_id FROM {$log_table} WHERE timestamp < %s LIMIT %d",
                $cutoff_date,
                self::BATCH_SIZE
            );
            
            $logs_to_delete = $wpdb->get_results($log_ids_query);
            
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
                $log_ids_string = implode(',', array_map('intval', $log_ids));
                $delete_logs_query = "DELETE FROM {$log_table} WHERE log_id IN ({$log_ids_string})";
                $deleted_rows = $wpdb->query($delete_logs_query);
                
                if ($deleted_rows === false) {
                    // Error occurred
                    break;
                }
                
                $deleted_total += $deleted_rows;
            }
            
            // Delete the corresponding payload entries
            if (!empty($payload_ids)) {
                $payload_ids_string = implode(',', array_map('intval', $payload_ids));
                $delete_payloads_query = "DELETE FROM {$payloads_table} WHERE payload_id IN ({$payload_ids_string})";
                $wpdb->query($delete_payloads_query);
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
        if (!class_exists('OrderDaemon\CompletionManager\Includes\AuditTrailLogger')) {
            return;
        }

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
        $pl->add_component('metrics', 'Records deleted', [ 'name' => 'deleted_count', 'value' => (float)$deleted_count ]);
        $pl->add_component('metrics', 'Retention days', [ 'name' => 'retention_days', 'value' => (float)$retention_days, 'unit' => 'days' ]);
        $pl->add_component('info', 'Cleanup details', [ 'message' => sprintf('Type: %s', esc_html($retention_type)) ]);
        $pl->finish('success', sprintf('Deleted %d old log records (retention %d days)', $deleted_count, $retention_days));

    }//end log_cleanup_activity()


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

        $log_table = $wpdb->prefix.'odcm_audit_log';
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        // Count records that would be deleted
        $count_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE timestamp < %s",
            $cutoff_date
        );
        $count_to_delete = $wpdb->get_var($count_query);

        if (!$count_to_delete || $count_to_delete === 0) {
            return [
                'success'       => true,
                'message'       => __('No old log records found to delete.', 'order-daemon'),
                'deleted_count' => 0,
                'deleted_payloads' => 0,
            ];
        }

        // Count payload records that would be deleted
        $payload_count_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE timestamp < %s AND payload_id IS NOT NULL",
            $cutoff_date
        );
        $payload_count_to_delete = $wpdb->get_var($payload_count_query);

        // Perform the cleanup
        $this->cleanup_old_logs();

        $message = sprintf(
            // translators: %1$d: number of deleted records, %2$d: retention period in days
            __('Successfully deleted %1$d old log records (older than %2$d days).', 'order-daemon'),
            $count_to_delete,
            $retention_days
        );
        
        if ($payload_count_to_delete > 0) {
            $message .= ' ' . sprintf(
                // translators: %d: number of deleted payload records
                __('Also deleted %d associated payload records.', 'order-daemon'),
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
