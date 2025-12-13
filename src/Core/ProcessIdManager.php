<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager;

/**
 * Manages shared process IDs for order lifecycle consolidation
 */
final class ProcessIdManager
{
    /**
     * @var ProcessIdManager|null
     */
    private static ?ProcessIdManager $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return ProcessIdManager
     */
    public static function instance(): ProcessIdManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get or create a process ID for an order lifecycle
     * 
     * Enhanced with stronger validation to prevent Order #0 issues
     *
     * @param int $order_id
     * @return string
     */
    public function get_or_create_process_id(int $order_id): string
    {
        // CRITICAL FIX: Validate order ID to prevent Order #0 issues
        // This is a key change to prevent malformed process IDs
        if ($order_id <= 0) {
            // Log warning when invalid order ID is provided (only if logging function exists)
            if (defined('ODCM_DEBUG') && ODCM_DEBUG && function_exists('odcm_log_message')) {
                odcm_log_message("PROCESS_ID_WARNING: Invalid order ID {$order_id} provided to get_or_create_process_id", 'warning');
            }
            
            // Return a fallback process ID that won't create "Order #0" events
            // Use microsecond precision for uniqueness even when called multiple times
            return 'odcm:system:' . microtime(true) . ':' . uniqid('', true);
        }

        // Check for existing active process ID in order meta
        $existing_id = OrderMetaManager::get_meta($order_id, '_odcm_active_process_id', true);

        if (!empty($existing_id)) {
            // Verify the process is still active (within reasonable time window)
            if ($this->is_process_active($existing_id)) {
                return (string) $existing_id;
            }
        }

        // Create new process ID - include validation check in format
        $process_id = 'odcm:lifecycle:' . $order_id . ':' . time() . ':' . uniqid('', true);

        // Store in order meta
        OrderMetaManager::update_meta($order_id, '_odcm_active_process_id', $process_id);
        OrderMetaManager::update_meta($order_id, '_odcm_process_started_at', time());

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("PROCESS_ID_DEBUG: Created new process ID {$process_id} for order #{$order_id}", 'debug');
        }

        return $process_id;
    }

    /**
     * Close/expire a process ID
     *
     * @param int $order_id
     * @return void
     */
    public function close_process(int $order_id): void
    {
        OrderMetaManager::delete_meta($order_id, '_odcm_active_process_id');
        OrderMetaManager::update_meta($order_id, '_odcm_last_process_closed_at', time());
    }

    /**
     * Check if a process is still active (within 2 hours)
     *
     * @param string $process_id
     * @return bool
     */
    private function is_process_active(string $process_id): bool
    {
        // Extract timestamp from process ID
        if (preg_match('/(\d{10})/', $process_id, $matches)) {
            $process_time = (int) $matches[1];
            $age_hours = (time() - $process_time) / 3600;
            return $age_hours < 2; // 2 hour window
        }
        return false;
    }
}
