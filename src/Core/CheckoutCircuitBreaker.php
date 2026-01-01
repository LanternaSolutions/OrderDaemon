<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Checkout Circuit Breaker - FAIL-SAFE CHECKOUT PROTECTION
 *
 * Implements the "Never Break Revenue" philosophy by automatically disabling
 * plugin processing when checkout failures exceed threshold.
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 */
class CheckoutCircuitBreaker
{
    /**
     * Number of failures before circuit opens
     */
    private const FAILURE_THRESHOLD = 5;

    /**
     * Time in seconds before attempting to recover (5 minutes)
     */
    private const RECOVERY_TIMEOUT = 300;

    /**
     * Transient key for tracking checkout failures
     */
    private const FAILURES_KEY = 'odcm_checkout_failures';

    /**
     * Option key for emergency disable flag
     */
    private const EMERGENCY_DISABLE_KEY = 'odcm_emergency_disable';

    /**
     * Option key for last failure timestamp
     */
    private const LAST_FAILURE_KEY = 'odcm_last_checkout_failure';

    /**
     * Check if circuit breaker is currently open (preventing processing)
     *
     * @return bool True if circuit is open, false if closed
     */
    public function isCircuitOpen(): bool
    {
        try {
            $failures = get_transient(self::FAILURES_KEY) ?: 0;
            $emergency_disabled = get_option(self::EMERGENCY_DISABLE_KEY, false);
            
            return ((int) $failures >= self::FAILURE_THRESHOLD) || $emergency_disabled;
        } catch (\Throwable $e) {
            // On error, assume circuit is closed to allow processing
            $this->logMessage('ODCM_CIRCUIT_BREAKER: Error checking circuit state: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Record a checkout failure and potentially open the circuit
     *
     * @param string $context Additional context about the failure
     * @param array $metadata Additional failure metadata
     * @return void
     */
    public function recordFailure(string $context = '', array $metadata = []): void
    {
        try {
            $failures = get_transient(self::FAILURES_KEY) ?: 0;
            $new_failure_count = $failures + 1;
            
            // Update failure count with recovery timeout
            set_transient(self::FAILURES_KEY, $new_failure_count, self::RECOVERY_TIMEOUT);
            
            // Record when this failure occurred
            update_option(self::LAST_FAILURE_KEY, [
                'timestamp' => current_time('c'),
                'count' => $new_failure_count,
                'context' => $context,
                'metadata' => $metadata
            ]);
            
            // Log the failure
            $this->logFailure($new_failure_count, $context, $metadata);

            // Open circuit if threshold reached
            if ($new_failure_count >= self::FAILURE_THRESHOLD) {
                $this->openCircuit($new_failure_count, $context);
            }
            
        } catch (\Throwable $e) {
            // Circuit breaker operations should never throw - fail safe
            $this->logMessage('ODCM_CIRCUIT_BREAKER: Error recording failure: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Record a checkout success and potentially close the circuit
     *
     * @return void
     */
    public function recordSuccess(): void
    {
        try {
            $was_open = $this->isCircuitOpen();
            
            // Clear failure count
            delete_transient(self::FAILURES_KEY);
            
            // Re-enable if was disabled
            if (get_option(self::EMERGENCY_DISABLE_KEY, false)) {
                delete_option(self::EMERGENCY_DISABLE_KEY);
                
                // Log recovery
                $this->logRecovery();
            }
            
            // Update success metrics
            $success_count = get_transient('odcm_checkout_successes') ?: 0;
            set_transient('odcm_checkout_successes', $success_count + 1, 300);
            
            // Log recovery if circuit was previously open
            if ($was_open) {
                $this->logMessage('ODCM_CIRCUIT_BREAKER: Circuit recovered - successful checkout completed', 'info');
            }
            
        } catch (\Throwable $e) {
            // Circuit breaker operations should never throw - fail safe
            $this->logMessage('ODCM_CIRCUIT_BREAKER: Error recording success: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Check if processing should be bypassed due to circuit breaker state
     *
     * Primary method used by checkout hooks to determine if processing should be skipped.
     *
     * @return bool True if processing should be bypassed
     */
    public function shouldBypassProcessing(): bool
    {
        return $this->isCircuitOpen();
    }

    /**
     * Get current circuit breaker status and metrics
     *
     * @return array Status information including failure count, circuit state, etc.
     */
    public function getStatus(): array
    {
        try {
            $failures = get_transient(self::FAILURES_KEY) ?: 0;
            $successes = get_transient('odcm_checkout_successes') ?: 0;
            $emergency_disabled = get_option(self::EMERGENCY_DISABLE_KEY, false);
            $last_failure = get_option(self::LAST_FAILURE_KEY, null);
            
            $is_open = $this->isCircuitOpen();
            $total_events = $failures + $successes;
            $success_rate = $total_events > 0 ? round(($successes / $total_events) * 100, 2) : 100;
            
            // Determine health status
            $health = 'healthy';
            if ($is_open) {
                $health = 'circuit_open';
            } elseif ($failures >= 3) {
                $health = 'warning';
            } elseif ($failures >= 1) {
                $health = 'degraded';
            }
            
            return [
                'circuit_open' => $is_open,
                'health_status' => $health,
                'failure_count' => (int) $failures,
                'success_count' => (int) $successes,
                'success_rate' => $success_rate,
                'failure_threshold' => self::FAILURE_THRESHOLD,
                'recovery_timeout' => self::RECOVERY_TIMEOUT,
                'emergency_disabled' => (bool) $emergency_disabled,
                'last_failure' => $last_failure,
                'time_to_recovery' => $is_open ? $this->getTimeToRecovery() : null,
                'timestamp' => current_time('c')
            ];
        } catch (\Throwable $e) {
            return [
                'circuit_open' => false,
                'health_status' => 'unknown',
                'failure_count' => 0,
                'success_count' => 0,
                'success_rate' => 0,
                'error' => $e->getMessage(),
                'timestamp' => current_time('c')
            ];
        }
    }

    /**
     * Manually reset the circuit breaker (admin override)
     *
     * @return bool True if reset successful, false otherwise
     */
    public function manualReset(): bool
    {
        try {
            delete_transient(self::FAILURES_KEY);
            delete_option(self::EMERGENCY_DISABLE_KEY);
            delete_option(self::LAST_FAILURE_KEY);
            
            // Log manual reset
            $this->logMessage('ODCM_CIRCUIT_BREAKER: Manual reset performed by admin', 'info');
            
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    'Circuit breaker reset by admin',
                    [
                        'reset_type' => 'manual',
                        'admin_user' => wp_get_current_user()->user_login ?? 'unknown',
                        'timestamp' => current_time('c')
                    ],
                    null,
                    'info',
                    'circuit_breaker_reset'
                );
            }
            
            return true;
        } catch (\Throwable $e) {
            $this->logMessage('ODCM_CIRCUIT_BREAKER: Manual reset failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get time remaining until circuit recovery (seconds)
     *
     * @return int|null Seconds until recovery, or null if circuit is closed
     */
    public function getTimeToRecovery(): ?int
    {
        try {
            if (!$this->isCircuitOpen()) {
                return null;
            }
            
            $last_failure = get_option(self::LAST_FAILURE_KEY, null);
            if (!is_array($last_failure) || !isset($last_failure['timestamp'])) {
                return null;
            }
            
            $failure_time = strtotime($last_failure['timestamp']);
            $recovery_time = $failure_time + self::RECOVERY_TIMEOUT;
            $now = time();
            
            if ($now >= $recovery_time) {
                return 0; // Recovery time has passed
            }
            
            return $recovery_time - $now;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Open the circuit breaker (disable processing)
     *
     * @param int $failure_count Current failure count
     * @param string $context Additional context
     * @return void
     */
    private function openCircuit(int $failure_count, string $context): void
    {
        try {
            // Set emergency disable flag
            update_option(self::EMERGENCY_DISABLE_KEY, true);
            
            // Log critical event
            $this->logMessage(sprintf(
                'ODCM_CIRCUIT_BREAKER: EMERGENCY DISABLE - Circuit opened after %d failures. Context: %s',
                $failure_count,
                $context
            ), 'error');
            
            // Send admin notification if possible
            $this->notifyAdminOfCircuitOpen($failure_count, $context);
            
            // Log to audit trail if available
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    'Circuit breaker activated due to checkout failures',
                    [
                        'failure_count' => $failure_count,
                        'threshold' => self::FAILURE_THRESHOLD,
                        'context' => $context,
                        'recovery_timeout' => self::RECOVERY_TIMEOUT
                    ],
                    null,
                    'error',
                    'emergency_disable'
                );
            }
        } catch (\Throwable $e) {
            $this->logMessage('ODCM_CIRCUIT_BREAKER: Error opening circuit: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Log a checkout failure
     *
     * @param int $failure_count Current failure count
     * @param string $context Additional context
     * @param array $metadata Additional metadata
     * @return void
     */
    private function logFailure(int $failure_count, string $context, array $metadata): void
    {
        try {
            $this->logMessage(sprintf(
                'ODCM_CIRCUIT_BREAKER: Checkout failure #%d/%d - %s',
                $failure_count,
                self::FAILURE_THRESHOLD,
                $context
            ), 'warning');
            
            // Log to audit trail if available and failure is significant
            if (function_exists('odcm_log_event') && $failure_count >= 2) {
                odcm_log_event(
                    "Checkout failure detected",
                    array_merge([
                        'failure_count' => $failure_count,
                        'threshold' => self::FAILURE_THRESHOLD,
                        'context' => $context
                    ], $metadata),
                    null,
                    'warning',
                    'checkout_failure'
                );
            }
        } catch (\Throwable $e) {
            // Even logging should not fail
        }
    }

    /**
     * Log circuit recovery
     *
     * @return void
     */
    private function logRecovery(): void
    {
        try {
            $this->logMessage('ODCM_CIRCUIT_BREAKER: Circuit recovered - plugin re-enabled after successful checkout', 'info');
            
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    'Circuit breaker deactivated after successful checkout',
                    [
                        'recovery_type' => 'automatic',
                        'timestamp' => current_time('c')
                    ],
                    null,
                    'success',
                    'emergency_re_enable'
                );
            }
        } catch (\Throwable $e) {
            // Even logging should not fail
        }
    }

    /**
     * Notify admin of circuit breaker opening
     *
     * @param int $failure_count Current failure count
     * @param string $context Additional context
     * @return void
     */
    private function notifyAdminOfCircuitOpen(int $failure_count, string $context): void
    {
        try {
            // Use WordPress admin notices system if available
            if (class_exists('OrderDaemon\\CompletionManager\\Admin\\Notices')) {
                \OrderDaemon\CompletionManager\Admin\Notices::add_site_wide(
                    'circuit_breaker_open',
                    'error',
                    sprintf(
                        'Order Daemon has been automatically disabled due to %d checkout failures. The plugin will attempt to recover automatically in %d minutes.',
                        $failure_count,
                        self::RECOVERY_TIMEOUT / 60
                    )
                );
            }
        } catch (\Throwable $e) {
            // Notification failure should not break circuit breaker
        }
    }

    /**
     * Static factory method to get singleton instance
     *
     * @return self
     */
    public static function instance(): self
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }
    
    /**
     * Log messages using WordPress-friendly logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    private function logMessage(string $message, string $level = 'debug'): void
    {
        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($message, $level);
            return;
        }
        
        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
            return;
        }
        
        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_' . $level, $message);
            return;
        }
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
            $debug_file = WP_CONTENT_DIR . '/debug.log';
            @file_put_contents(
                $debug_file,
                '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
                FILE_APPEND
            );
            return;
        }
    }
}
