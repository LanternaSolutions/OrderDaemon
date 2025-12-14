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
     * Uses strict validation to prevent any invalid order IDs from creating process IDs
     * that could lead to "Order #0" events in the timeline
     *
     * @param int $order_id The order ID to get/create process ID for
     * @return string|null Process ID string or null for invalid order IDs
     */
    public function get_or_create_process_id(int $order_id): ?string
    {
        // STRICTER VALIDATION: Ensure order ID is valid to prevent Order #0 issues
        // This enhanced validation completely prevents process IDs for invalid order IDs
        if ($order_id <= 0) {
            // Log detailed warning when invalid order ID is provided
            if (defined('ODCM_DEBUG') && ODCM_DEBUG && function_exists('odcm_log_message')) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                $caller = isset($backtrace[1]) ? $backtrace[1]['function'] : 'unknown';
                $file = isset($backtrace[0]) ? basename($backtrace[0]['file']) : 'unknown';
                $line = isset($backtrace[0]) ? $backtrace[0]['line'] : '?';
                
                odcm_log_message("PROCESS_ID_WARNING: Invalid order ID {$order_id} rejected in {$file}:{$line} (called from {$caller})", 'warning');
            }
            
            // Return null instead of a fallback process ID to force callers to handle this case
            // This prevents Order #0 events by making the absence of a valid process ID explicit
            return null;
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
