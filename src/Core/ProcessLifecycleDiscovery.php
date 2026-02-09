<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

// Include functions.php for helper methods
require_once __DIR__ . '/../../Includes/functions.php';

/**
 * Auto-discovering registry for Process Lifecycle Consolidation.
 *
 * Follows the same pattern as RuleComponentRegistry - scans for process types
 * and applies business logic rules for UI consolidation.
 *
 * @package OrderDaemon\CompletionManager\Core
 */
final class ProcessLifecycleDiscovery
{
    /**
     * Discovered process families keyed by family id.
     *
     * @var array<string, array>
     */
    private array $process_families = [];

    /**
     * Lazy discovery flag.
     *
     * @var bool
     */
    private bool $is_discovered = false;

    /**
     * Singleton instance.
     *
     * @var ProcessLifecycleDiscovery|null
     */
    private static ?ProcessLifecycleDiscovery $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return ProcessLifecycleDiscovery
     */
    public static function instance(): ProcessLifecycleDiscovery
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get discovered process families.
     *
     * @return array<string, array>
     */
    public function get_process_families(): array
    {
        $this->discover_process_families();
        return $this->process_families;
    }

    /**
     * Perform discovery once per request.
     *
     * @return void
     */
    private function discover_process_families(): void
    {
        if ($this->is_discovered) {
            return;
        }

        try {
            // Auto-discover process types (scan codebase and database)
            $discovered_types = $this->scan_for_process_types();

            // Apply business logic rules
            $families = $this->categorize_by_business_logic($discovered_types);

            // Allow WordPress filter overrides
            $this->process_families = apply_filters('odcm_process_lifecycle_families', $families);
        } catch (\Throwable $e) {
            // Fail safe to a minimal default, do not break UI
            if (function_exists('odcm_log_message')) {
                odcm_log_message('Process lifecycle discovery failed: ' . $e->getMessage(), 'error');
            }
            $this->process_families = $this->categorize_by_business_logic([]);
        }

        $this->is_discovered = true;
    }

    /**
     * Categorize discovered types into business families.
     *
     * @param string[] $process_types
     * @return array<string, array>
     */
    private function categorize_by_business_logic(array $process_types): array
    {
        $order_lifecycle_types = [];
        $payment_gateway_types = [];
        $subscription_lifecycle_types = [];
        $individual_types = [];

        foreach ($process_types as $type) {
            if ($this->is_order_lifecycle_process($type)) {
                $order_lifecycle_types[] = $type;
            } elseif ($this->is_payment_gateway_process($type)) {
                $payment_gateway_types[] = $type;
            } elseif ($this->is_subscription_lifecycle_process($type)) {
                $subscription_lifecycle_types[] = $type;
            } else {
                $individual_types[] = $type;
            }
        }

        return [
            'order_lifecycle' => [
                'id' => 'order_lifecycle',
                /* translators: Process group label for order processing workflows and lifecycle */
                'label' => __('Order Processing Workflows', 'order-daemon'),
                'process_types' => array_values(array_unique($order_lifecycle_types)),
                'consolidate_ui' => true,
                'time_window_minutes' => 30,
            ],
            'payment_gateway_lifecycle' => [
                'id' => 'payment_gateway_lifecycle',
                /* translators: Process group label for payment gateway events and transactions */
                'label' => __('Payment Gateway Events', 'order-daemon'),
                'process_types' => array_values(array_unique($payment_gateway_types)),
                'consolidate_ui' => true,
                'cross_entity' => true,
                'time_window_minutes' => 15,
            ],
            'subscription_lifecycle' => [
                'id' => 'subscription_lifecycle',
                /* translators: Process group label for subscription lifecycle events */
                'label' => __('Subscription Lifecycle', 'order-daemon'),
                'process_types' => array_values(array_unique($subscription_lifecycle_types)),
                'consolidate_ui' => true,
                'time_window_minutes' => 60,
            ],
            'individual_entries' => [
                'id' => 'individual_entries',
                'process_types' => array_values(array_unique($individual_types)),
                'consolidate_ui' => false,
                'time_window_minutes' => null,
            ],
        ];
    }

    /**
     * Identify order lifecycle canonical process types.
     *
     * @param string $process_type
     * @return bool
     */
    private function is_order_lifecycle_process(string $process_type): bool
    {
        $order_lifecycle_patterns = [
            'checkout_processing',
            'block_checkout_processed',
            'status_change_processing',
            'manual_status_change',
            'automatic_workflow_transition',
            'rule_execution',
            'order_completion',
            'order_processing',
            'universal_event_processing',
            'rule_execution',
            'universal_event_reception',
            'universal_event_processing_error',
            'universal_event_argument_error',
            'universal_event_validation_error',
            'process_started', // Context-dependent
        ];

        return in_array($process_type, $order_lifecycle_patterns, true);
    }

    /**
     * Identify payment gateway lifecycle process types.
     *
     * @param string $process_type
     * @return bool
     */
    private function is_payment_gateway_process(string $process_type): bool
    {
        $payment_gateway_patterns = [
            'payment_created',
            'payment_completed',
            'payment_pending',
            'payment_failed',
            'payment_denied',
            'payment_refunded',
            'payment_partially_refunded',
            'payment_reversed',
            'payment_voided',
            'dispute_opened',
            'dispute_updated',
            'dispute_resolved',
            'dispute_closed',
            'dispute_won',
            'dispute_lost',
            'universal_event_processing', // Universal event system events
        ];

        return in_array($process_type, $payment_gateway_patterns, true);
    }

    /**
     * Identify subscription lifecycle process types.
     *
     * @param string $process_type
     * @return bool
     */
    private function is_subscription_lifecycle_process(string $process_type): bool
    {
        $subscription_patterns = [
            'subscription_created',
            'subscription_activated',
            'subscription_updated',
            'subscription_cancelled',
            'subscription_suspended',
            'subscription_reactivated',
            'subscription_completed',
            'subscription_expired',
            'renewal_payment_completed',
            'renewal_payment_failed',
            'renewal_payment_pending',
            'renewal_payment_processing',
            'trial_started',
            'trial_ended',
        ];

        // Also check for wildcard patterns
        foreach ($subscription_patterns as $pattern) {
            if ($pattern === $process_type) {
                return true;
            }
        }

        // Handle renewal_payment_* wildcard pattern
        if (strpos($process_type, 'renewal_payment_') === 0) {
            return true;
        }

        // Handle subscription_* wildcard pattern
        if (strpos($process_type, 'subscription_') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Scan for process types from codebase and database.
     *
     * @return string[]
     */
    private function scan_for_process_types(): array
    {
        $process_types = [];

        // Database discovery as a reliable fallback (works in production too)
        $process_types = array_merge($process_types, $this->discover_from_database());

        return array_values(array_unique(array_filter(array_map('strval', $process_types))));
    }

    /**
     * Discover process types from database details JSON.
     *
     * @return string[]
     */
    private function discover_from_database(): array
    {
        global $wpdb;

        try {
            // Use the authoritative event_type column for discovered process types.
            // This is reliable across environments and matches the values used by the UI and API.
            // Prepare the query with the empty string for the event_type check
            $empty_value = '';
            
            // Cache key for process types
            // Static in-memory cache for the current request in addition to transient cache
            static $process_types_cache = [];

            // Create a unique cache key for the audit log table
            $cache_key = 'odcm_process_types_audit_log';
            
            // Check static cache first for better performance
            if (isset($process_types_cache[$cache_key])) {
                return $process_types_cache[$cache_key];
            }
            
            // Check persistent cache
            $cached_types = wp_cache_get($cache_key);
            
            if (false !== $cached_types) {
                // Store in static cache for this request
                $process_types_cache[$cache_key] = $cached_types;
                return $cached_types;
            }
            
            // Cache miss - run the query
            // Use $wpdb->get_col() with a properly prepared query
            // The WHERE clause uses $wpdb->prepare() for proper value escaping
            $query = $wpdb->prepare(
                "SELECT DISTINCT event_type FROM `{$wpdb->prefix}odcm_audit_log` WHERE event_type IS NOT NULL AND event_type != %s",
                $empty_value
            );

            // Execute the query with WordPress's database API
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query, $query is properly prepared above using $wpdb->prepare()
            $results = $wpdb->get_col($query);
            
            // Process the results
            $types = is_array($results) ? $results : [];
            $types = array_values(array_unique(array_filter(array_map('strval', $types))));
            
            // Cache results for 1 hour (process types don't change frequently)
            wp_cache_set($cache_key, $types, '', HOUR_IN_SECONDS);
            
            // Store in static cache for this request
            $process_types_cache[$cache_key] = $types;
            
            return $types;
        } catch (\Throwable $e) {
            // Log error using the plugin's logging function instead of error_log
            if (function_exists('odcm_log_message')) {
                odcm_log_message('ODCM: Process type discovery failed: ' . $e->getMessage(), 'error');
            } else {
                // Fallback to WordPress error logging mechanisms
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // Use WordPress action hook if available for centralized error handling
                    if (function_exists('do_action')) {
                        do_action('odcm_log_error', 'Process type discovery failed: ' . $e->getMessage());
                    }
                    
                    // Use WordPress debug log function if available
                    if (function_exists('wp_debug_log')) {
                        wp_debug_log('ODCM: Process type discovery failed: ' . $e->getMessage());
                    }
                    
                    // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        $debug_file = odcm_get_uploads_dir() . '/debug.log';
                        if (odcm_validate_file_path($debug_file)) {
                            odcm_safe_file_put_contents(
                                $debug_file,
                                '[' . gmdate('Y-m-d H:i:s') . '] ODCM: Process type discovery failed: ' . $e->getMessage() . PHP_EOL,
                                FILE_APPEND
                            );
                        } else {
                            odcm_critical_log("Invalid debug file path: " . esc_html($debug_file));
                        }
                    }
                }
            }
            return [];
        }
    }
}
