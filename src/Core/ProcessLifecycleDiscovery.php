<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

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
            error_log('ODCM: Process lifecycle discovery failed: ' . $e->getMessage());
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
                'label' => __('Order Processing', 'order-daemon'),
                'process_types' => array_values(array_unique($order_lifecycle_types)),
                'consolidate_ui' => true,
                'time_window_minutes' => 30,
            ],
            'payment_gateway_lifecycle' => [
                'id' => 'payment_gateway_lifecycle',
                'label' => __('Payment Gateway Events', 'order-daemon'),
                'process_types' => array_values(array_unique($payment_gateway_types)),
                'consolidate_ui' => true,
                'cross_entity' => true,
                'time_window_minutes' => 15,
            ],
            'subscription_lifecycle' => [
                'id' => 'subscription_lifecycle',
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
            $table_name = $wpdb->prefix . 'odcm_audit_log';

            // Use the authoritative event_type column for discovered process types.
            // This is reliable across environments and matches the values used by the UI and API.
            $sql = "SELECT DISTINCT event_type FROM {$table_name} WHERE event_type IS NOT NULL AND event_type != ''";
            $results = $wpdb->get_col($sql);
            $types = is_array($results) ? $results : [];
            $types = array_values(array_unique(array_filter(array_map('strval', $types))));

            return $types;
        } catch (\Throwable $e) {
            error_log('ODCM: Process type discovery failed: ' . $e->getMessage());
            return [];
        }
    }
}
