<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

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
     * @param int $order_id
     * @return string
     */
    public function get_or_create_process_id(int $order_id): string
    {
        // Check for existing active process ID in order meta
        $existing_id = get_post_meta($order_id, '_odcm_active_process_id', true);

        if (!empty($existing_id)) {
            // Verify the process is still active (within reasonable time window)
            if ($this->is_process_active($existing_id)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log(sprintf('ODCM PID: reuse existing pid for order %d: %s', $order_id, (string)$existing_id));
                }
                return (string) $existing_id;
            }
        }

        // Create new process ID
        $process_id = 'odcm:lifecycle:' . $order_id . ':' . time() . ':' . uniqid('', true);

        // Store in order meta
        update_post_meta($order_id, '_odcm_active_process_id', $process_id);
        update_post_meta($order_id, '_odcm_process_started_at', time());

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log(sprintf('ODCM PID: created new pid for order %d: %s (previous: %s)', $order_id, $process_id, (string)($existing_id ?: 'none')));
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
        delete_post_meta($order_id, '_odcm_active_process_id');
        update_post_meta($order_id, '_odcm_last_process_closed_at', time());
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
