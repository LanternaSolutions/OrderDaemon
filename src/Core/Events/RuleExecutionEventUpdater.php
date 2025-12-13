<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

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
        $transient_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name LIKE %s",
            '_transient_odcm_rule_execution_update_%',
            '_transient_timeout_odcm_rule_execution_update_%'
        ));

        if (empty($transient_keys)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_message("ODCM_RULE_UPDATE: No pending rule execution updates found", 'debug');
            }
            return;
        }

        $processed_count = 0;
        $failed_count = 0;

        foreach ($transient_keys as $transient_key) {
            // Extract the event ID from the transient key
            if (preg_match('/_transient_odcm_rule_execution_update_(\d+)/', $transient_key, $matches)) {
                $event_id = (int) $matches[1];

                // Get the transient data
                $update_data = get_transient('odcm_rule_execution_update_' . $event_id);

                if ($update_data && is_array($update_data)) {
                    $processed_count++;

                    try {
                        // Attempt to update the event
                        $success = $this->update_rule_execution_event($update_data['event_id'], $update_data['payload']);

                        if ($success) {
                            // Delete the transient on successful update
                            delete_transient('odcm_rule_execution_update_' . $event_id);

                            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                                $rule_name = $update_data['payload']['rule_name'] ?? 'unknown';
                                $order_id = $update_data['payload']['order_id'] ?? 'unknown';
                                odcm_log_message("ODCM_RULE_UPDATE: Successfully processed pending update for event {$event_id} - Rule '{$rule_name}' (Order #{$order_id})", 'debug');
                            }
                        } else {
                            $failed_count++;

                            // Increment attempt count and extend transient if we should retry
                            $update_data['attempts'] = ($update_data['attempts'] ?? 0) + 1;

                            if ($update_data['attempts'] <= 3) {
                                // Retry within the next hour
                                set_transient('odcm_rule_execution_update_' . $event_id, $update_data, HOUR_IN_SECONDS);

                                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                                    odcm_log_message("ODCM_RULE_UPDATE: Update failed for event {$event_id} (attempt {$update_data['attempts']}), will retry", 'warning');
                                }
                            } else {
                                // Give up after 3 attempts
                                delete_transient('odcm_rule_execution_update_' . $event_id);

                                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                                    odcm_log_message("ODCM_RULE_UPDATE: Giving up on update for event {$event_id} after 3 failed attempts", 'error');
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $failed_count++;

                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            odcm_log_message("ODCM_RULE_UPDATE: Exception processing pending update for event {$event_id}: " . $e->getMessage(), 'error');
                        }

                        // Delete the transient on exception to prevent infinite retries
                        delete_transient('odcm_rule_execution_update_' . $event_id);
                    }
                }
            }
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_RULE_UPDATE: Completed processing of pending updates - Processed: {$processed_count}, Failed: {$failed_count}", 'debug');
        }
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
            $result = $wpdb->update(
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
     * Get statistics about pending updates
     *
     * @return array Statistics about pending updates
     */
    public function get_pending_updates_stats(): array
    {
        global $wpdb;

        // Count pending update transients
        $pending_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name LIKE %s",
            '_transient_odcm_rule_execution_update_%',
            '_transient_timeout_odcm_rule_execution_update_%'
        ));

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
        $transient_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name LIKE %s",
            '_transient_odcm_rule_execution_update_%',
            '_transient_timeout_odcm_rule_execution_update_%'
        ));

        $cleaned_count = 0;

        foreach ($transient_keys as $transient_key) {
            if (preg_match('/_transient_odcm_rule_execution_update_(\d+)/', $transient_key, $matches)) {
                $event_id = (int) $matches[1];
                if (delete_transient('odcm_rule_execution_update_' . $event_id)) {
                    $cleaned_count++;
                }
            }
        }

        return $cleaned_count;
    }
}
