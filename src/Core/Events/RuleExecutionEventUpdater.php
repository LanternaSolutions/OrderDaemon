<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rule Execution Event Updater
 *
 * Handles updating existing rule execution events with additional trigger information
 * using WordPress transient API for robustness and fault tolerance.
 *
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   1.1.40
 */
class RuleExecutionEventUpdater
{
    /**
     * Singleton instance
     *
     * @var RuleExecutionEventUpdater|null
     */
    private static ?RuleExecutionEventUpdater $instance = null;

    /**
     * Get singleton instance
     *
     * @return RuleExecutionEventUpdater
     */
    public static function instance(): RuleExecutionEventUpdater
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->setup_hooks();
        }
        return self::$instance;
    }

    /**
     * Set up WordPress hooks
     *
     * @return void
     */
    private function setup_hooks(): void
    {
        // Hook into the action that triggers immediate updates
        add_action('odcm_update_rule_execution_event', [$this, 'handle_immediate_update'], 10, 2);

        // Hook into WordPress shutdown to process any remaining transients
        add_action('shutdown', [$this, 'process_pending_updates']);

        // Hook into WordPress cron to process any pending updates
        add_action('odcm_process_pending_rule_execution_updates', [$this, 'process_pending_updates']);

        // Schedule regular processing of pending updates
        if (!wp_next_scheduled('odcm_process_pending_rule_execution_updates')) {
            wp_schedule_event(time(), 'hourly', 'odcm_process_pending_rule_execution_updates');
        }
    }

    /**
     * Handle immediate rule execution event updates
     *
     * @param int $event_id Log entry ID to update
     * @param array $updated_payload Updated payload data
     * @return void
     */
    public function handle_immediate_update(int $event_id, array $updated_payload): void
    {
        if ($event_id <= 0) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_RULE_UPDATE: Invalid event ID {$event_id} for immediate update", 'error');
            }
            return;
        }

        try {
            // Update the event in the database
            $success = $this->update_rule_execution_event($event_id, $updated_payload);

            if ($success) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $rule_name = $updated_payload['rule_name'] ?? 'unknown';
                    $order_id = $updated_payload['order_id'] ?? 'unknown';
                    odcm_log_message("ODCM_RULE_UPDATE: Successfully updated rule execution event {$event_id} for Rule '{$rule_name}' (Order #{$order_id})", 'debug');
                }
            } else {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_RULE_UPDATE: Failed to update rule execution event {$event_id} immediately, will retry via transient", 'warning');
                }

                // If immediate update fails, store in transient for later processing
                $transient_key = 'odcm_rule_execution_update_' . $event_id;
                $update_data = [
                    'event_id' => $event_id,
                    'payload' => $updated_payload,
                    'timestamp' => current_time('mysql'),
                    'attempts' => 1,
                ];
                set_transient($transient_key, $update_data, HOUR_IN_SECONDS);
                
                // Invalidate related caches since we created a new transient
                $this->invalidate_transient_caches();
            }
        } catch (\Throwable $e) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_RULE_UPDATE: Exception during immediate update for event {$event_id}: " . $e->getMessage(), 'error');
            }

            // Store in transient for later processing
            $transient_key = 'odcm_rule_execution_update_' . $event_id;
            $update_data = [
                'event_id' => $event_id,
                'payload' => $updated_payload,
                'timestamp' => current_time('mysql'),
                'attempts' => 1,
                'error' => $e->getMessage(),
            ];
            set_transient($transient_key, $update_data, HOUR_IN_SECONDS);
            
            // Invalidate related caches since we created a new transient
            $this->invalidate_transient_caches();
        }
    }

    /**
     * Process pending rule execution event updates
     *
     * @return void
     */
    public function process_pending_updates(): void
    {
        global $wpdb;

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_RULE_UPDATE: Starting processing of pending rule execution updates", 'debug');
        }

        // Find all transients with our prefix
        $cache_key = 'odcm_pending_rule_execution_transients';
        $transient_keys = wp_cache_get($cache_key, 'odcm_rule_execution');
        
        if (false === $transient_keys) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $transient_keys = DatabaseHelper::get_col(
                "SELECT option_name FROM {$wpdb->options}
                WHERE option_name LIKE %s
                AND option_name NOT LIKE %s",
                [
                    '_transient_odcm_rule_execution_update_%',
                    '_transient_timeout_odcm_rule_execution_update_%',
                ]
            );
            
            // Cache for 5 minutes as transients are dynamic
            wp_cache_set($cache_key, $transient_keys, 'odcm_rule_execution', 300);
        }
    }

    public function get_pending_updates_stats(): array
    {
        global $wpdb;

        // Count pending update transients
        $cache_key = 'odcm_pending_updates_count';
        $pending_count = wp_cache_get($cache_key, 'odcm_rule_execution');
        
        if (false === $pending_count) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $pending_count = DatabaseHelper::get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND option_name NOT LIKE %s",
                [
                    '_transient_odcm_rule_execution_update_%',
                    '_transient_timeout_odcm_rule_execution_update_%',
                ]
            );
            
            // Cache for 2 minutes as this can change frequently
            wp_cache_set($cache_key, $pending_count, 'odcm_rule_execution', 120);
        }

        return [
            'pending_updates' => (int) $pending_count,
            'last_processed' => current_time('mysql'),
        ];
    }

    /**
     * Clean up all pending updates
     *
     * @return int Number of updates cleaned up
     */
    public function cleanup_pending_updates(): int
    {
        global $wpdb;

        // Find all transients with our prefix
        $cache_key = 'odcm_cleanup_rule_execution_transients';
        $transient_keys = wp_cache_get($cache_key, 'odcm_rule_execution');
        
        if (false === $transient_keys) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $transient_keys = DatabaseHelper::get_col(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND option_name NOT LIKE %s",
                [
                    '_transient_odcm_rule_execution_update_%',
                    '_transient_timeout_odcm_rule_execution_update_%',
                ]
            );
            
            // Cache for 1 minute as this is only used for cleanup operations
            wp_cache_set($cache_key, $transient_keys, 'odcm_rule_execution', 60);
        }

        $cleaned_count = 0;

        foreach ($transient_keys as $transient_key) {
            if (preg_match('/_transient_odcm_rule_execution_update_(\d+)/', $transient_key, $matches)) {
                $event_id = (int) $matches[1];
                if (delete_transient('odcm_rule_execution_update_' . $event_id)) {
                    $cleaned_count++;
                }
            }
        }

        // Clear cache after cleanup to ensure fresh data on next call
        wp_cache_delete($cache_key, 'odcm_rule_execution');

        return $cleaned_count;
    }

    /**
     * Update a rule execution event in the database
     *
     * @param int $event_id Log entry ID to update
     * @param array $updated_payload Updated payload data
     * @return bool True if update was successful, false otherwise
     */
    private function update_rule_execution_event(int $event_id, array $updated_payload): bool
    {
        global $wpdb;

        if ($event_id <= 0) {
            return false;
        }

        try {
            // Convert payload to JSON
            $payload_json = wp_json_encode($updated_payload);
            if ($payload_json === false) {
                throw new \RuntimeException('Failed to encode payload as JSON');
            }

            // Update the log entry
            $result = DatabaseHelper::update(
                $wpdb->prefix . 'odcm_audit_log',
                [
                    'payload' => $payload_json,
                    'timestamp' => current_time('mysql'),
                ],
                [
                    'log_id' => $event_id,
                ],
                [
                    '%s', // payload
                    '%s', // timestamp
                ],
                [
                    '%d', // log_id
                ]
            );

            if ($result === false) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    odcm_log_message("ODCM_RULE_UPDATE: Database update failed for event {$event_id}: " . $wpdb->last_error, 'error');
                }
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_RULE_UPDATE: Exception updating event {$event_id}: " . $e->getMessage(), 'error');
            }
            return false;
        }
    }

    /**
     * Invalidate all transient-related caches
     *
     * This method should be called whenever transients are created, updated, or deleted
     * to ensure cache consistency.
     *
     * @return void
     */
    private function invalidate_transient_caches(): void
    {
        wp_cache_delete('odcm_pending_rule_execution_transients', 'odcm_rule_execution');
        wp_cache_delete('odcm_pending_updates_count', 'odcm_rule_execution');
        wp_cache_delete('odcm_cleanup_rule_execution_transients', 'odcm_rule_execution');
    }
}
