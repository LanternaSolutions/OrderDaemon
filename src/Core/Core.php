<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use OrderDaemon\CompletionManager\Admin\Notices;
use OrderDaemon\CompletionManager\Core\BlockCheckoutCompatibility;
use OrderDaemon\CompletionManager\Core\RefundDeletionDiagnostics;
use OrderDaemon\CompletionManager\Core\AttributionTracker;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;
use OrderDaemon\CompletionManager\Core\Events\EvaluationContext;
use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;
use OrderDaemon\CompletionManager\Includes\Utils\OrderMetaManager;
use OrderDaemon\CompletionManager\Includes\Functions;
use WC_Order;

/**
 * Core plugin class responsible for main business logic.
 *
 * This refactored version changes the "Reprocess Orders" tool to be fully asynchronous,
 * preventing server timeouts and memory exhaustion on sites with many orders.
 */
class Core
{
    /**
     * Controlled error logging that follows WordPress coding standards
     *
     * This method centralizes all error logging to ensure consistency
     * and respect WordPress debugging settings.
     *
     * @param string $message Message to log
     * @return void
     */
    private function controlled_error_log(string $message): void
    {
        // Only log when debugging is enabled, with safety checks
        if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('ODCM_DEBUG') && ODCM_DEBUG)) {
            // Use WordPress logging function if available
            if (function_exists('odcm_log_message')) {
                odcm_log_message($message, 'error');
            } else {
                // Fallback to WordPress logging mechanisms
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // Use WordPress action hook if available for centralized error handling
                    if (function_exists('do_action')) {
                        do_action('odcm_log_error', 'ODCM_CORE: ' . $message);
                    }
                    
                    // Use WordPress debug log function if available
                    if (function_exists('wp_debug_log')) {
                        wp_debug_log('ODCM_CORE: ' . $message);
                    }
                    
                    // If WP_DEBUG_LOG is enabled, write to debug.log file using safe utilities
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        // Get validated debug file path
                        $debug_file = odcm_get_safe_debug_file_path();
                        if ($debug_file) {
                            // Write to debug.log file using safe file operations
                            odcm_safe_file_put_contents(
                                $debug_file,
                                '[' . gmdate('Y-m-d H:i:s') . '] ODCM_CORE: ' . $message . PHP_EOL,
                                FILE_APPEND
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Controls if global $woocommerce object is available for our checks.
     *
     * @return bool True if WooCommerce global is available.
     */
    private function is_woocommerce_ready(): bool
    {
        global $woocommerce;
        
        if (!isset($woocommerce) || !is_object($woocommerce)) {
            $this->controlled_error_log('Emergency check: WooCommerce global not available');
            return false;
        }
        
        if (!method_exists($woocommerce, 'init') || !property_exists($woocommerce, 'version')) {
            $this->controlled_error_log('Emergency check: WooCommerce global missing required methods/properties');
            return false;
        }
        
        return true;
    }

    /**
     * Initializes the core functionality.
     *
     * CRITICAL: This method registers admin_init hooks with priority 20 to ensure
     * they run AFTER the 'odcm_order_rule' post type is registered (which
     * happens on 'init' hook priority 5). This prevents race conditions where
     * admin form handlers try to query the post type before it exists.
     *
     * Hook Priority Sequence:
     * - init priority 5:  Post type registration (Plugin.php)
     * - init priority 6:  Options loading (Plugin.php)
     * - init priority 10: Core initialization (this method)
     * - admin_init priority 20: Reprocess handler (safe to query post type)
     *
     * Hooks into WordPress to schedule and execute order completion checks.
     *
     * @since 1.0.0
     */
    public function init()
    {
        
        // WordPress-standard form processing using admin-post.php
        add_action('admin_post_odcm_reprocess_orders', [$this, 'handle_reprocess_request']);

        // RACE CONDITION FIX: Use priority 20 to ensure post type is registered first
        // The 'odcm_order_rule' post type is registered on 'init' priority 5,
        // so admin_init priority 20 ensures it's available when this handler runs.
        add_action('admin_init', [$this, 'handle_reprocess_request'], 20);

        // NEW: Register the handler for our asynchronous reprocessing batch action.
        add_action('odcm_reprocess_orders_batch', [$this, 'schedule_orders_for_reprocessing'], 10, 1);

        // FAIL-SAFE: Register background processing handlers for checkout protection
        add_action('odcm_process_payment_completion', [$this, 'background_payment_processing'], 10, 1);

        // NOTE: The 'odcm_process_order_check' hook is handled by the global function
        // odcm_handle_order_check_processing() in actions.php, which properly handles
        // both array and integer arguments and delegates to the UniversalEventProcessor class.
        // We don't need to register a duplicate handler here.

        // AUTOMATIC TRIGGER HOOKS: Register WooCommerce hooks for automatic order processing
        // These hooks ensure that orders are automatically checked against completion rules
        // when payments are completed or order status changes occur.
        add_action('woocommerce_payment_complete', [$this, 'handle_payment_complete'], 10, 1);

        // Dynamically register hooks for ALL WooCommerce order statuses
        try {
            if (function_exists('wc_get_order_statuses')) {
                $statuses = wc_get_order_statuses();
                if (is_array($statuses)) {
                    foreach ($statuses as $status_key => $label) {
                        // wc_get_order_statuses keys are like 'wc-processing' -> we need 'processing'
                        $slug = is_string($status_key) ? sanitize_key((string) $status_key) : '';
                        if ($slug === '') {
                            continue;
                        }
                        if (strpos($slug, 'wc-') === 0) {
                            $slug = substr($slug, 3);
                        }
                        // Register specific status hook at priority 10
                        add_action("woocommerce_order_status_{$slug}", [$this, 'handle_order_status_change'], 10, 1);
                    }
                    odcm_log_message('Registered dynamic order status hooks for ' . count($statuses) . ' statuses', 'info');
                } else {
                    odcm_log_message('wc_get_order_statuses() did not return an array during hook registration', 'error');
                }
            } else {
                odcm_log_message('wc_get_order_statuses() is not available; cannot register status-specific hooks', 'error');
            }
        } catch (\Throwable $e) {
            odcm_log_message('Exception during dynamic status hook registration: ' . $e->getMessage(), 'error');
        }

        // CRITICAL: Register the general order status changed hook to catch all status transitions
        // This ensures that completion rules are checked whenever ANY order status changes
        // Uses lower priority (15) to run after specific status hooks and includes deduplication
        add_action('woocommerce_order_status_changed', [$this, 'handle_general_order_status_change'], 15, 4);

        // ORDER LIFECYCLE HOOKS: Register order creation and lifecycle events
        add_action('woocommerce_new_order', [$this, 'handle_new_order'], 10, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'handle_checkout_order_processed'], 10, 3);

        // SUBSCRIPTION HOOKS: Register WooCommerce Subscriptions hooks if available
        if (function_exists('wcs_get_subscription')) {
            add_action('woocommerce_subscription_status_updated', [$this, 'handle_subscription_status_change'], 10, 3);
            add_action('woocommerce_subscription_renewal_payment_complete', [$this, 'handle_renewal_payment'], 10, 2);
            odcm_log_message('Registered WooCommerce Subscriptions hooks for universal events', 'info');
        }

        // Initialize WooCommerce Blocks (Store API) observation-only compatibility
        try {
            $blocks = new BlockCheckoutCompatibility();
            $blocks->init();
        } catch (\Throwable $e) {
            // Silently ignore to maintain compatibility on environments without Woo Blocks
        }

        // Initialize Refund & Deletion Diagnostics (observation-only)
        try {
            $refundDeletion = new RefundDeletionDiagnostics();
            $refundDeletion->init();
        } catch (\Throwable $e) {
            // Silently ignore to maintain compatibility on environments without WooCommerce
        }

        // Initialize Rule Execution Event Updater for consolidated timeline events
        try {
            $ruleEventUpdater = \OrderDaemon\CompletionManager\Core\Events\RuleExecutionEventUpdater::instance();
            
            // Set up the hook handler for existing events updates
            add_action('init', function() {
                // Process any pending updates during WordPress init, once DB is fully ready
                \OrderDaemon\CompletionManager\Core\Events\RuleExecutionEventUpdater::instance()->process_pending_updates();
            }, 20);
            
            odcm_log_message('Rule Execution Event Updater initialized for consolidated timeline events', 'info');
        } catch (\Throwable $e) {
            odcm_log_message('Failed to initialize Rule Execution Event Updater: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Handles the "Reprocess Orders" request
     *
     * This method now calls the fully asynchronous reprocess_pending_orders() method
     * and redirects the user back with a success notice.
     *
     * RACE CONDITION PROTECTION: This method includes defensive checks to ensure
     * the 'odcm_order_rule' post type exists before attempting to query it.
     * This prevents "invalid post type" errors if there are any remaining timing issues.
     *
     * @since 1.0.0
     */
    public function handle_reprocess_request(): void
    {
        // Define validation rules for all expected parameters.
        $validation_rules = [
            'page'   => ['type' => 'string', 'required' => false],
            'tab'    => ['type' => 'string', 'required' => false],
            'action' => ['type' => 'string', 'required' => false], // Checked manually below
            '_wpnonce' => ['type' => 'string', 'required' => false],
            'odcm_reprocess_orders' => ['type' => 'string', 'required' => false],
            'odcm_reprocess_nonce'  => ['type' => 'string', 'required' => false],
        ];

        try {
            // SECURITY: Sanitize only specific expected parameters from $_REQUEST.
            $safe_params = odcm_validate_and_sanitize_params([
                'page' => sanitize_text_field($_REQUEST['page'] ?? ''),
                'tab' => sanitize_text_field($_REQUEST['tab'] ?? ''),
                'action' => sanitize_text_field($_REQUEST['action'] ?? ''),
                '_wpnonce' => sanitize_text_field($_REQUEST['_wpnonce'] ?? ''),
                'odcm_reprocess_orders' => sanitize_text_field($_REQUEST['odcm_reprocess_orders'] ?? ''),
                'odcm_reprocess_nonce' => sanitize_text_field($_REQUEST['odcm_reprocess_nonce'] ?? ''),
            ], $validation_rules);
        } catch (\InvalidArgumentException $e) {
            // This should not happen with all fields being optional, but as a safeguard:
            odcm_log_message("Parameter validation failed for reprocess request: " . $e->getMessage(), 'error');
            return;
        }

        // Guard: Only proceed if our specific action is set.
        if (empty($safe_params['action']) || 'odcm_reprocess_orders' !== $safe_params['action']) {
            return;
        }

        // Security: Consolidate nonce verification for both GET and POST.
        $nonce = $safe_params['_wpnonce'] ?? $safe_params['odcm_reprocess_nonce'] ?? '';
        if ( ! wp_verify_nonce($nonce, 'odcm_reprocess_action')) {
            wp_die(esc_html__('Security check failed', 'order-daemon'));
        }

        // Security: Verify user capabilities.
        if ( ! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'order-daemon'));
        }

        // DEFENSIVE CHECK: Verify post type exists before processing.
        if ( ! post_type_exists('odcm_order_rule')) {
            odcm_log_message("CRITICAL: Post type 'odcm_order_rule' not registered during reprocess request", 'error');
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('core.errors.post_type_not_available', 'order-daemon') . '</p></div>';
            });
            return;
        }

        // Logging for debugging purposes.
        odcm_log_message("handle_reprocess_request() called from hook: " . current_action(), 'info');
        odcm_log_message("REQUEST_METHOD: " . (isset($_SERVER["REQUEST_METHOD"]) ? sanitize_text_field(wp_unslash($_SERVER["REQUEST_METHOD"])) : "unknown"), 'info');
        odcm_log_message("REQUEST_URI: " . (isset($_SERVER["REQUEST_URI"]) ? esc_url_raw(wp_unslash($_SERVER["REQUEST_URI"])) : "unknown"), 'info');
        odcm_log_message("Sanitized params: " . wp_json_encode($safe_params), 'info');

        // Call the asynchronous reprocessing method.
        $count = $this->reprocess_pending_orders();

        odcm_log_message("reprocess_pending_orders() returned count: " . $count, 'info');

        // Log the admin action to the audit trail.
        $this->log_reprocess_action($count);

        // Add a success notice for the user.
        $this->add_reprocess_success_notice($count);

        // Redirect back to the tools page.
        odcm_log_message("About to redirect after reprocess", 'info');
        $this->redirect_after_reprocess();
    }

    /**
     * Handle WooCommerce payment completion events - UNIVERSAL EVENTS IMPLEMENTATION
     *
     * CRITICAL: This method implements the "Never Break Revenue" philosophy while using
     * Universal Events to eliminate duplicate timeline entries and provide rich payment data.
     *
     * @param int $order_id The ID of the order that had payment completed.
     */
    public function handle_payment_complete(int $order_id): void
    {
        $start_time = microtime(true);
        
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        try {
            // Check circuit breaker first
            if ($this->should_bypass_processing()) {
                odcm_log_message("CIRCUIT_BREAKER: Bypassing payment processing for order #{$order_id} - circuit breaker is open", 'error');
                return;
            }

            // CREATE UNIVERSAL EVENT for payment completion instead of background processing
            $universal_event = $this->synthesize_payment_complete_event($order);
            
            // PROCESS through Universal Events pipeline - this creates single timeline event
            $this->process_universal_event_from_hook($universal_event);
            
            odcm_log_message("Payment completion for order #{$order_id} processed as universal event", 'info');
            
            // Record success for circuit breaker
            $this->record_checkout_success();
            
        } catch (\Throwable $e) {
            // NEVER break payment completion - log error and continue
            $this->log_safe_error('payment_hook_failed', $e, [
                'order_id' => $order_id,
                'method' => 'handle_payment_complete'
            ]);
            
            // Record failure for circuit breaker
            $this->record_checkout_failure();
            
            // Emergency fallback processing
            $this->emergency_fallback_processing($order_id);
        } finally {
            // Monitor execution time
            $this->monitor_execution_time($start_time, 'payment_complete', $order_id);
        }
    }

    /**
     * Schedules a single order for a completion check with enhanced duplicate prevention.
     *
     * This method relies on Action Scheduler's built-in deduplication and idempotent operations
     * rather than timing-based locks. This approach is more reliable and eliminates the need
     * for arbitrary timeout values and lock cleanup.
     *
     * @param int $order_id The ID of the order to check.
     * @return bool True if scheduled successfully, false if prevented or failed.
     */
    public function schedule_completion_check(int $order_id): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        // DUPLICATE PREVENTION: Only prevent queue flooding and processing conflicts
        if (function_exists('as_get_scheduled_actions')) {
            // Check for pending actions to prevent queue flooding
            $existing_actions = as_get_scheduled_actions([
                'hook' => 'odcm_process_order_check',
                'args' => ['order_id' => $order_id],
                'status' => 'pending',
                'per_page' => 1
            ]);

            if (!empty($existing_actions)) {
                odcm_log_message("Skipping duplicate scheduling for order #{$order_id} - pending action exists", 'info');
                return false;
            }

            // Check for currently running actions to prevent processing conflicts
            $running_actions = as_get_scheduled_actions([
                'hook' => 'odcm_process_order_check',
                'args' => ['order_id' => $order_id],
                'status' => 'in-progress',
                'per_page' => 1
            ]);

            if (!empty($running_actions)) {
                odcm_log_message("Skipping duplicate scheduling for order #{$order_id} - action currently running", 'info');
                return false;
            }
        }

        // Schedule the action - Action Scheduler handles any remaining deduplication
        $action_id = as_enqueue_async_action(
            'odcm_process_order_check',
            ['order_id' => $order_id],
            'odcm-order-processing'
        );

        if ($action_id) {
            odcm_log_message("Successfully scheduled order #{$order_id} for processing (Action ID: {$action_id})", 'info');
            return true;
        } else {
            odcm_log_message("Failed to schedule order #{$order_id} for processing", 'error');
            return false;
        }
    }
    
    /**
     * Adds an admin notice indicating the success of the reprocessing request.
     * Uses the plugin's built-in Notices system for consistent messaging.
     *
     * @param int $count The number of orders scheduled.
     */
    private function add_reprocess_success_notice(int $count)
    {
        $message = sprintf(
        // translators: %d is the number of orders.
            _n(
                'admin.insight_dashboard.ajax.reprocess_success_singular',
                'admin.insight_dashboard.ajax.reprocess_success_plural',
                $count,
                'order-daemon'
            ),
            $count
        );

        // Use the plugin's built-in Notices system with a fixed ID
        // This ensures each new reprocess action overwrites the previous notice
        // instead of accumulating multiple notices
        Notices::add_site_wide(
            'reprocess_success',
            'success',
            $message
        );
    }

    /**
     * Logs the reprocess action to the audit trail.
     * Records the admin action with relevant metadata for tracking purposes.
     *
     * @param int $count The number of orders scheduled for reprocessing.
     */
    private function log_reprocess_action(int $count): void
    {
        $current_user = wp_get_current_user();
        $user_display_name = $current_user->display_name ?: $current_user->user_login;

        // Narrative-based single process entry for admin reprocess action
        // Log admin reprocess action using Universal Events system
        $sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
        
        $action_data = $sanitizer->sanitize('admin_action', [
            'action' => 'reprocess_orders',
            'user_id' => get_current_user_id(),
            'user_name' => $user_display_name,
            'order_count' => $count
        ]);
        
        $components = [
            [
                'k' => odcm_component_key(),
                'event_type' => 'admin_action',
                'ts' => time(),
                /* translators: %d: Number of orders reprocessed by admin */
                'label' => sprintf(__('core.admin.reprocessed_orders', 'order-daemon'), $count),
                'level' => 'info',
                'data' => $action_data
            ]
        ];
        
        odcm_log_event(
            'Admin requested reprocess of all orders',
            [
                'type' => 'admin_action',
                'cid' => 'admin:' . time(),
                'actor' => [
                    'id' => get_current_user_id(),
                    'role' => null,
                    'name' => wp_get_current_user()->display_name ?: wp_get_current_user()->user_login
                ],
                'ts' => time(),
                'components' => $components,
            ],
            null,
            'info',
            'admin_action'
        );
    }

    /**
     * Redirects the user back to the settings page after the request.
     * (This is a pre-existing private method, assumed to exist).
     */
    private function redirect_after_reprocess()
    {
        wp_safe_redirect(admin_url('admin.php?page=odcm-settings&tab=dev_tools'));
        exit;
    }



    /**
     * Handles WooCommerce order status change events.
     *
     * This method is triggered when an order status changes to processing or on-hold
     * and schedules the order for automatic completion rule processing.
     *
     * @param int $order_id The ID of the order that changed status.
     */
    public function handle_order_status_change(int $order_id): void
    {
        
        if ($order_id <= 0) {
            return;
        }

        // Determine current status (the "to" status)
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $status = $order ? $order->get_status() : '';
        $status_slug = $status !== '' ? sanitize_key((string)$status) : 'unknown';
        

        // Capture attribution and map to canonical source values
        $source = 'system';
        $attr = [];
        try {
            $attr = \OrderDaemon\CompletionManager\Core\AttributionTracker::instance()->capture_context();
            $request_type = is_array($attr) ? sanitize_key((string)($attr['request_type'] ?? '')) : '';
            $external_service_name = (is_array($attr) && isset($attr['external_service']['name'])) ? sanitize_key((string)$attr['external_service']['name']) : null;
            if (is_user_logged_in()) {
                $source = 'manual';
            } elseif ($request_type === 'webhook' || !empty($external_service_name)) {
                $source = 'webhook';
            } elseif ($request_type === 'rest' || $request_type === 'ajax') {
                $source = 'api';
            } elseif (in_array($request_type, ['action_scheduler','cron','cli','wp_cli'], true)) {
                $source = 'scheduled';
            } else {
                $source = 'system';
            }
        } catch (\Throwable $e) {
            odcm_log_message('Attribution tracking failed in specific status handler for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // Update last processed meta for deduplication and diagnostics
        try {
            $meta_payload = [
                'from' => 'unknown',
                'to' => $status_slug,
                'time' => time(),
                'via' => 'specific',
                'source' => $source,
                'request_type' => isset($request_type) ? $request_type : null,
                'source_plugin' => is_array($attr['source_plugin'] ?? null) ? [
                    'type' => isset($attr['source_plugin']['type']) ? sanitize_key((string) $attr['source_plugin']['type']) : null,
                    'slug' => isset($attr['source_plugin']['slug']) ? sanitize_text_field((string) $attr['source_plugin']['slug']) : null,
                    'confidence' => isset($attr['source_plugin']['confidence']) ? (float) $attr['source_plugin']['confidence'] : null,
                ] : null,
                'external_service' => is_array($attr['external_service'] ?? null) ? [
                    'name' => isset($attr['external_service']['name']) ? sanitize_key((string) $attr['external_service']['name']) : null,
                    'confidence' => isset($attr['external_service']['confidence']) ? (float) $attr['external_service']['confidence'] : null,
                ] : null,
                'user_context' => is_array($attr['user_context'] ?? null) ? [
                    'is_logged_in' => (bool) ($attr['user_context']['is_logged_in'] ?? false),
                ] : null,
            ];
            $this->update_last_status_processed_meta($order_id, $meta_payload);
        } catch (\Throwable $e) {
            odcm_log_message('Failed updating _odcm_last_status_processed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // Mark this order as processed by the specific status hook to help deduplicate
        try {
            if ($status_slug !== 'unknown') {
                $this->mark_specific_status_processed($order_id, $status_slug);
            }
        } catch (\Throwable $e) {
            odcm_log_message('Failed to mark specific status processed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // REMOVED: Universal Event creation to eliminate duplicates
        // Timeline events are now created ONLY by handle_general_order_status_change()
        // This preserves rule evaluation while eliminating duplicate timeline entries

        // KEEP: Schedule the order for completion check (CRITICAL for rule evaluation)
        $this->schedule_completion_check($order_id);

        try {
            if (isset($pl)) {
                $pl->finish('queued', sprintf('Order status changed to "%s"', $status_slug));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        odcm_log_message("Order #{$order_id} status changed to '{$status_slug}', scheduled for completion check (specific hook)", 'info');
    }

    /**
     * Handles general WooCommerce order status change events with hybrid logging.
     * - Normal mode: Only logs when rules are triggered and actions taken
     * - Debug mode: ALSO logs rule evaluations and status changes for all orders
     *    - CAN CAUSE DATA BLOAT!
     *
     * @param int $order_id The ID of the order that changed status.
     * @param string $from_status The previous order status.
     * @param string $to_status The new order status.
     * @param WC_Order $order The order object.
     */
    public function handle_general_order_status_change(int $order_id, string $from_status, string $to_status, $order): void
    {
        // CRITICAL: Capture occurrence timestamp IMMEDIATELY when hook fires
        // This ensures chronological ordering reflects real-world occurrence time
        $occurrence_timestamp = microtime(true);
        
        if ($order_id <= 0) {
            return;
        }

        $from_slug = sanitize_key((string)$from_status);
        $to_slug   = sanitize_key((string)$to_status);

        // Always log in debug mode
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->log_status_change_evaluation($order_id, $from_slug, $to_slug);
        }

        // Apply smart deduplication checks to prevent duplicate timeline events
        // while allowing ALL legitimate status changes (including rule-executed changes)
        
        // Check for exact duplicate transitions (same from→to within time window)
        try {
            if ($this->is_duplicate_status_transition($order_id, $from_slug, $to_slug, 30)) {
                odcm_log_message("Skipping duplicate status transition for order #{$order_id} ({$from_slug} → {$to_slug}) - identical transition recently processed", 'info');
                return;
            }
        } catch (\Throwable $e) {
            odcm_log_message('Error checking duplicate status transition for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // REMOVED: Overly aggressive specific-hook deduplication that was blocking rule-executed status changes
        // Status changes from rule execution are legitimate and should create timeline events
        
        // REMOVED: Overly aggressive Action Scheduler check that was preventing timeline events
        // Timeline event creation is separate from rule evaluation scheduling
        // Action Scheduler tasks are for rule processing, not timeline visibility

        // Attribution mapping using AttributionTracker
        $source = 'system';
        $attr = [];
        $request_type = '';
        try {
            $attr = AttributionTracker::instance()->capture_context();
            $request_type = is_array($attr) ? sanitize_key((string)($attr['request_type'] ?? '')) : '';
            $external_service_name = (is_array($attr) && isset($attr['external_service']['name'])) ? sanitize_key((string)$attr['external_service']['name']) : null;
            if (is_user_logged_in()) {
                $source = 'manual';
            } elseif ($request_type === 'webhook' || !empty($external_service_name)) {
                $source = 'webhook';
            } elseif ($request_type === 'rest' || $request_type === 'ajax') {
                $source = 'api';
            } elseif (in_array($request_type, ['action_scheduler','cron','cli','wp_cli'], true)) {
                $source = 'scheduled';
            } else {
                $source = 'system';
            }
        } catch (\Throwable $e) {
            // Fall back to 'system' and log
            odcm_log_message('Attribution tracking failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // Generate UniversalEvent for ALL status changes (timeline visibility)
        // This ensures timeline events appear regardless of rule matches
        try {
            if ($order instanceof \WC_Order) {
                $universal_event = $this->synthesize_status_change_event($order, $from_slug, $to_slug, $occurrence_timestamp);
                $this->process_universal_event_from_hook($universal_event);
                odcm_log_message("Order #{$order_id} general status change ({$from_slug} → {$to_slug}) processed as universal event", 'info');
            }
        } catch (\Throwable $e) {
            odcm_log_message('General status change universal event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        try {
            $meta_payload = [
                'from' => $from_slug,
                'to' => $to_slug,
                'time' => time(),
                'via' => 'general',
                'source' => $source,
                'request_type' => $request_type ?: null,
                'source_plugin' => is_array($attr['source_plugin'] ?? null) ? [
                    'type' => isset($attr['source_plugin']['type']) ? sanitize_key((string) $attr['source_plugin']['type']) : null,
                    'slug' => isset($attr['source_plugin']['slug']) ? sanitize_text_field((string) $attr['source_plugin']['slug']) : null,
                    'confidence' => isset($attr['source_plugin']['confidence']) ? (float) $attr['source_plugin']['confidence'] : null,
                ] : null,
                'external_service' => is_array($attr['external_service'] ?? null) ? [
                    'name' => isset($attr['external_service']['name']) ? sanitize_key((string) $attr['external_service']['name']) : null,
                    'confidence' => isset($attr['external_service']['confidence']) ? (float) $attr['external_service']['confidence'] : null,
                ] : null,
                'user_context' => is_array($attr['user_context'] ?? null) ? [
                    'is_logged_in' => (bool) ($attr['user_context']['is_logged_in'] ?? false),
                ] : null,
            ];
            $this->update_last_status_processed_meta($order_id, $meta_payload);
        } catch (\Throwable $e) {
            odcm_log_message('Failed updating _odcm_last_status_processed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // Now check for rule evaluation - this is separate from timeline event creation
        // Get matching rules for this status change
        $matching_rules = $this->get_matching_rules_for_status_change($from_slug, $to_slug);

        // Log rule matching results when debug mode is enabled
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->controlled_error_log("DEBUG_TRACE: Order #{$order_id} ({$from_slug} → {$to_slug}) - Found " . count($matching_rules) . " matching rules");
        }
        
        if (empty($matching_rules)) {
            // Log when no rules match (only in debug mode)
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->controlled_error_log("DEBUG_TRACE: Order #{$order_id} - NO RULES MATCHED, skipping rule evaluation but timeline event already created");
            }
            
            // Schedule basic order check for compatibility
            $this->schedule_completion_check($order_id);
            
            odcm_log_message("Order #{$order_id} status changed ({$from_slug} → {$to_slug}), source={$source}; timeline event created, no rules matched", 'info');
            return; // Exit early - no rule processing needed, but timeline event was created
        }

        // Log that we found matching rules (only in debug mode)
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->controlled_error_log("DEBUG_TRACE: Order #{$order_id} - RULES MATCHED, proceeding to rule evaluation");
            foreach ($matching_rules as $rule) {
                $this->controlled_error_log("DEBUG_TRACE: Order #{$order_id} - Matching rule: {$rule['name']} (ID: {$rule['id']})");
            }
        }

        // Schedule the order for completion check (rule evaluation)
        $this->schedule_completion_check($order_id);

        odcm_log_message("Order #{$order_id} status changed ({$from_slug} → {$to_slug}), source={$source}; timeline event created and scheduled for rule evaluation", 'info');
    }

    /**
     * Mark that a specific status hook processed this order recently.
     * Uses a single meta key to store an associative array of status => timestamp.
     *
     * @param int    $order_id    WooCommerce order ID.
     * @param string $status_slug The status slug (e.g., 'processing').
     * @return void
     */
    private function mark_specific_status_processed(int $order_id, string $status_slug): void
    {
        $order_id = absint($order_id);
        $status   = sanitize_key($status_slug);
        if ($order_id <= 0 || $status === '') {
            return;
        }
        $key = '_odcm_status_hook_processed';
        $map = OrderMetaManager::get_meta($order_id, $key, true);
        if (!is_array($map)) {
            $map = [];
        }
        $map[$status] = time();
        OrderMetaManager::update_meta($order_id, $key, $map);
    }

    /**
     * Check whether a specific status hook has processed this order within the given window.
     *
     * @param int    $order_id       WooCommerce order ID.
     * @param string $status_slug    The status slug to check.
     * @param int    $within_seconds Time window in seconds.
     * @return bool  True if processed within the window, false otherwise.
     */
    private function has_specific_status_processed(int $order_id, string $status_slug, int $within_seconds = 30): bool
    {
        $order_id = absint($order_id);
        $status   = sanitize_key($status_slug);
        if ($order_id <= 0 || $status === '' || $within_seconds <= 0) {
            return false;
        }
        $key = '_odcm_status_hook_processed';
        $map = OrderMetaManager::get_meta($order_id, $key, true);
        if (!is_array($map)) {
            return false;
        }
        $ts = isset($map[$status]) ? absint((string) $map[$status]) : 0;
        if ($ts <= 0) {
            return false;
        }
        return ($ts >= (time() - $within_seconds));
    }

    // NOTE: The old `get_reprocessable_orders()` method is no longer needed with this new architecture and has been removed.

    /**
     * Update the last processed status meta (_odcm_last_status_processed) with sanitized payload.
     *
     * @param int   $order_id WooCommerce order ID.
     * @param array $data     Associative array with keys: from, to, time, via, source, request_type,
     *                        source_plugin{type,slug,confidence}, external_service{name,confidence}, user_context{is_logged_in}.
     * @return void
     */
    private function update_last_status_processed_meta(int $order_id, array $data): void
    {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return;
        }

        $from  = isset($data['from']) ? sanitize_key((string) $data['from']) : 'unknown';
        $to    = isset($data['to']) ? sanitize_key((string) $data['to']) : '';
        $time  = isset($data['time']) ? absint((string) $data['time']) : time();
        $via   = isset($data['via']) ? sanitize_key((string) $data['via']) : 'general';
        $via   = in_array($via, ['specific','general'], true) ? $via : 'general';
        $source = isset($data['source']) ? sanitize_key((string) $data['source']) : 'system';
        $allowed_sources = ['manual','webhook','api','scheduled','system'];
        $source = in_array($source, $allowed_sources, true) ? $source : 'system';
        $request_type = isset($data['request_type']) ? sanitize_key((string) $data['request_type']) : '';

        $sp = is_array($data['source_plugin'] ?? null) ? $data['source_plugin'] : null;
        $source_plugin = is_array($sp) ? [
            'type' => isset($sp['type']) ? sanitize_key((string) $sp['type']) : null,
            'slug' => isset($sp['slug']) ? sanitize_text_field((string) $sp['slug']) : null,
            'confidence' => isset($sp['confidence']) ? (float) $sp['confidence'] : null,
        ] : null;

        $es = is_array($data['external_service'] ?? null) ? $data['external_service'] : null;
        $external_service = is_array($es) ? [
            'name' => isset($es['name']) ? sanitize_key((string) $es['name']) : null,
            'confidence' => isset($es['confidence']) ? (float) $es['confidence'] : null,
        ] : null;

        $uc = is_array($data['user_context'] ?? null) ? $data['user_context'] : null;
        $user_context = is_array($uc) ? [
            'is_logged_in' => (bool) ($uc['is_logged_in'] ?? false),
        ] : null;

        $payload = [
            'from' => $from,
            'to' => $to,
            'time' => $time,
            'via' => $via,
            'source' => $source,
            'request_type' => ($request_type !== '') ? $request_type : null,
            'source_plugin' => $source_plugin,
            'external_service' => $external_service,
            'user_context' => $user_context,
        ];

        OrderMetaManager::update_meta($order_id, '_odcm_last_status_processed', $payload);
    }

    /**
     * Retrieve the last processed status meta.
     *
     * @param int $order_id WooCommerce order ID.
     * @return array|null Returns the meta array or null if missing/invalid.
     */
    private function get_last_status_processed(int $order_id): ?array
    {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return null;
        }
        $val = OrderMetaManager::get_meta($order_id, '_odcm_last_status_processed', true);
        return is_array($val) ? $val : null;
    }

    /**
     * Check for exact duplicate status transitions within a time window.
     * This only blocks identical from→to transitions, allowing different transitions.
     *
     * @param int    $order_id       WooCommerce order ID.
     * @param string $from           From status slug.
     * @param string $to             To status slug.
     * @param int    $window         Time window in seconds. Default 30.
     * @return bool  True if identical transition recently occurred, false otherwise.
     */
    private function is_duplicate_status_transition(int $order_id, string $from, string $to, int $window = 30): bool
    {
        $order_id = absint($order_id);
        if ($order_id <= 0 || $window <= 0) {
            return false;
        }
        $last = $this->get_last_status_processed($order_id);
        if (!is_array($last)) {
            return false;
        }
        $last_from = isset($last['from']) ? sanitize_key((string) $last['from']) : '';
        $last_to   = isset($last['to']) ? sanitize_key((string) $last['to']) : '';
        $last_time = isset($last['time']) ? absint((string) $last['time']) : 0;

        $from = sanitize_key($from);
        $to   = sanitize_key($to);

        // Only block if BOTH from AND to match exactly (true duplicate)
        // This allows sequential status changes like: pending→processing, processing→completed
        if ($last_from !== $from || $last_to !== $to) {
            return false; // Different transition - allow it
        }

        if ($last_time <= 0) {
            return false;
        }
        return ($last_time >= (time() - $window));
    }

    /**
     * Check if any rules with AnyStatusChangeTrigger should be triggered for the given status transition.
     *
     * This method queries active rules with the AnyStatusChangeTrigger component and evaluates
     * whether any of them should be triggered for the current status transition.
     *
     * @param string $from_slug The previous order status slug.
     * @param string $to_slug   The new order status slug.
     * @return bool True if any AnyStatusChangeTrigger rules should be triggered, false otherwise.
     */
    private function should_trigger_any_status_change_rules(string $from_slug, string $to_slug): bool
    {
        // Sanitize inputs
        $from_slug = sanitize_key($from_slug);
        $to_slug = sanitize_key($to_slug);

        if ($from_slug === '' || $to_slug === '') {
            return false;
        }

        // Query active rules that might have AnyStatusChangeTrigger
        $rules = get_posts([
            'post_type' => 'odcm_order_rule',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        if (empty($rules)) {
            return false;
        }

        // Load the AnyStatusChangeTrigger component
        try {
            $registry = \OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry::instance();
            $trigger_component = $registry->get_trigger('order_status_any_change');

            if (!$trigger_component) {
                odcm_log_message('AnyStatusChangeTrigger component not found in registry', 'error');
                return false;
            }
        } catch (\Throwable $e) {
            odcm_log_message('Failed to load AnyStatusChangeTrigger component: ' . $e->getMessage(), 'error');
            return false;
        }

        // Check each rule to see if it has AnyStatusChangeTrigger and should trigger for this transition
        foreach ($rules as $rule_id) {
            try {
                // Get rule data from the main rule data meta
                $rule_data_json = get_post_meta($rule_id, '_odcm_rule_data', true);
                if (empty($rule_data_json)) {
                    continue;
                }

                $rule_data = json_decode($rule_data_json, true);
                if (!is_array($rule_data) || !isset($rule_data['trigger'])) {
                    continue;
                }

                // Check if this rule uses the AnyStatusChangeTrigger
                if ($rule_data['trigger']['id'] !== 'order_status_any_change') {
                    continue;
                }

                // Get trigger settings
                $trigger_settings = $rule_data['trigger']['settings'] ?? [];
                if (!is_array($trigger_settings)) {
                    $trigger_settings = [];
                }

                // Create context for the trigger evaluation
                $context = [
                    'order_id' => 0, // Will be set when actually processing
                    'source' => 'status_change',
                    'from_status' => $from_slug,
                    'to_status' => $to_slug,
                ];

                // Check if this rule's trigger should fire for this transition
                // Use the new interface signature: should_trigger(context, settings)
                if ($trigger_component->should_trigger($context, $trigger_settings)) {
                    odcm_log_message("AnyStatusChangeTrigger rule #{$rule_id} matches transition {$from_slug} → {$to_slug}", 'info');
                    return true;
                }
            } catch (\Throwable $e) {
                odcm_log_message("Error evaluating AnyStatusChangeTrigger rule #{$rule_id}: " . $e->getMessage(), 'error');
                continue;
            }
        }

        return false;
    }


    /**
     * Synthesize status change event from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @param string $from_status Previous status
     * @param string $to_status New status
     * @param float $processing_timestamp Processing timestamp when hook fired (for debugging)
     * @return UniversalEvent
     */
    private function synthesize_status_change_event(\WC_Order $order, string $from_status, string $to_status, float $processing_timestamp): UniversalEvent
    {
        $order_id = $order->get_id();
        
        // DEBUG: Log the event creation process
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->controlled_error_log("PROCESS_ID_DEBUG: Creating UniversalEvent for order #{$order_id} status change ({$from_status} → {$to_status})");
        }

        // USE REAL OCCURRENCE TIMESTAMP from WooCommerce order data
        $real_occurrence_timestamp = $this->derive_real_occurrence_timestamp($order, $from_status, $to_status);

        // Check for manual status change context from ManualStatusTracker
        $manual_context = ManualStatusTracker::get_manual_context($order_id);
        $is_manual = is_array($manual_context) && ($manual_context['is_manual'] ?? false);
        
        // Clean up the context after reading
        if ($is_manual) {
            ManualStatusTracker::clear_manual_context($order_id);
        }

        // Capture attribution context for rawData
        $attribution = [];
        try {
            $attr = AttributionTracker::instance()->capture_context();
            if (is_array($attr)) {
                $attribution = [
                    'request_type' => $attr['request_type'] ?? 'unknown',
                    'user_logged_in' => $attr['user_context']['is_logged_in'] ?? false,
                    'source_plugin' => $attr['source_plugin'] ?? null,
                    'external_service' => $attr['external_service'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            $attribution = ['error' => 'Attribution capture failed'];
        }

        $rawData = [
            'from_status' => $from_status,
            'to_status' => $to_status,
            'source' => $this->determine_change_source(),
            'attribution' => $attribution,
            'order_total' => $order->get_total(),
            'customer_id' => $order->get_customer_id(),
            'real_occurrence_timestamp' => $real_occurrence_timestamp,
            'processing_timestamp' => $processing_timestamp, // Keep for debugging
        ];

        // Create status change component with real timestamp
        $status_data = [
            'from' => $from_status,
            'to' => $to_status,
            'order_id' => $order_id,
        ];

        // Add manual change data to components for main timeline display
        if ($is_manual) {
            $status_data['manual_change'] = true;
            $status_data['changed_by_user_id'] = $manual_context['user_id'];
            $status_data['changed_by_user_name'] = $manual_context['user_display_name'];
            $status_data['change_type'] = 'manual';
            
            if ($manual_context['bypassed_automation']) {
                $status_data['bypassed_automation'] = true;
                $status_data['automation_bypass_warning'] = 'This manual change may have bypassed automatic completion rules.';
            }
        } else {
            $status_data['change_type'] = 'automatic';
        }

        // USE REAL OCCURRENCE TIMESTAMP for chronological ordering
        $components = [[
            'k' => 'status_change_' . str_replace('.', '_', (string)$real_occurrence_timestamp),
            'event_type' => 'status_changed',
            'ts' => $real_occurrence_timestamp,
            'label' => 'Status changed',
            'level' => 'info',
            'data' => $status_data,
        ]];

        // CRITICAL: Ensure primaryObjectID is properly set as integer for process_id assignment
        $universal_event_data = [
            'eventType' => $this->map_status_to_event_type($to_status),
            'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
            'channel' => $is_manual ? 'manual' : 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => (int) $order_id, // EXPLICIT integer cast to ensure process_id logic works
            'transactionID' => $order->get_transaction_id(),
            'status' => $to_status,
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'reason' => $is_manual ? 'manual_change' : 'automatic_change',
            'occurredAt' => current_time('c'),
            'receivedAt' => current_time('c'), // Required for validation
            'idempotencyKey' => 'status_change_' . $order_id . '_' . $from_status . '_' . $to_status . '_' . time(), // Required for validation and deduplication
            'rawData' => $rawData,
            'components' => $components 
        ];

        // DEBUG: Log the UniversalEvent data structure
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->controlled_error_log("PROCESS_ID_DEBUG: UniversalEvent data for order #{$order_id}:");
            $this->controlled_error_log("PROCESS_ID_DEBUG: - primaryObjectType: " . $universal_event_data['primaryObjectType']);
            $this->controlled_error_log("PROCESS_ID_DEBUG: - primaryObjectID: " . $universal_event_data['primaryObjectID'] . " (type: " . gettype($universal_event_data['primaryObjectID']) . ")");
            $this->controlled_error_log("PROCESS_ID_DEBUG: - eventType: " . $universal_event_data['eventType']);
            $this->controlled_error_log("PROCESS_ID_DEBUG: - idempotencyKey: " . $universal_event_data['idempotencyKey']);
        }

        return new UniversalEvent($universal_event_data);
    }

    /**
     * Synthesize subscription event from WooCommerce subscription data
     *
     * @param mixed $subscription WooCommerce subscription object
     * @param string $event_type Event type
     * @return UniversalEvent
     */
    private function synthesize_subscription_event($subscription, string $event_type): UniversalEvent
    {
        $parent_id = null;
        if (method_exists($subscription, 'get_parent_id')) {
            $parent_id = $subscription->get_parent_id();
        }

        return new UniversalEvent([
            'eventType' => $event_type,
            'sourceGateway' => $this->normalize_gateway_name($subscription->get_payment_method()),
            'channel' => 'system',
            'primaryObjectType' => 'subscription',
            'primaryObjectID' => $subscription->get_id(),
            'secondaryObjectType' => 'order',
            'secondaryObjectID' => $parent_id,
            'amount' => (float) $subscription->get_total(),
            'currency' => $subscription->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => []
        ]);
    }

    /**
     * Synthesize payment complete event with gateway-specific data in rawData
     *
     * @param \WC_Order $order WooCommerce order object
     * @return UniversalEvent
     */
    private function synthesize_payment_complete_event(\WC_Order $order): UniversalEvent
    {
        // GET REAL PAYMENT TIMESTAMP from WooCommerce order data
        $payment_timestamp = $this->get_real_payment_timestamp($order);

        $payment_method = $order->get_payment_method();
        $gateway_title = $order->get_payment_method_title();
        
        // Gather gateway-specific data for rawData
        $gateway_data = [
            'payment_method_id' => $payment_method,
            'payment_method_title' => $gateway_title,
            'transaction_id' => $order->get_transaction_id(),
            'order_key' => $order->get_order_key(),
            'payment_date' => $order->get_date_paid() ? $order->get_date_paid()->format('c') : null,
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
        ];
        
        // Add Stripe-specific data if available
        if (strpos($payment_method, 'stripe') !== false) {
            $gateway_data['stripe_data'] = [
                'payment_intent_id' => $order->get_meta('_stripe_intent_id'),
                'charge_id' => $order->get_meta('_stripe_charge_id'),
                'source_id' => $order->get_meta('_stripe_source_id'),
                'customer_id' => $order->get_meta('_stripe_customer_id'),
            ];
        }
        
        // Add PayPal-specific data if available  
        if (strpos($payment_method, 'paypal') !== false || strpos($payment_method, 'ppcp') !== false) {
            $gateway_data['paypal_data'] = [
                'transaction_id' => $order->get_meta('_paypal_transaction_id'),
                'payer_id' => $order->get_meta('_paypal_payer_id'),
                'payment_status' => $order->get_meta('_paypal_status'),
            ];
        }

        // Create payment completion component with real timestamp
        $payment_data = [
            'status' => 'completed',
            'order_id' => $order->get_id(),
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'transaction_id' => $order->get_transaction_id(),
            'payment_method' => $gateway_title,
        ];

        $components = [[
            'k' => 'payment_complete_' . str_replace('.', '_', (string)$payment_timestamp),
            'event_type' => 'payment_completed',
            'ts' => $payment_timestamp, // REAL payment timestamp from WooCommerce data
            'label' => 'Payment completed',
            'level' => 'info',
            'data' => $payment_data,
        ]];

        return new UniversalEvent([
            'eventType' => 'payment_completed',
            'sourceGateway' => $this->normalize_gateway_name($payment_method),
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => 'completed',
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => $gateway_data,  // Gateway-specific data for expandable sections
            'components' => $components 
        ]);
    }

    /**
     * Map WooCommerce status to universal event type
     *
     * @param string $status WooCommerce order status
     * @return string Universal event type
     */
    private function map_status_to_event_type(string $status): string
    {
        $status_mapping = [
            'completed' => 'payment_completed',
            'processing' => 'payment_processing',
            'on-hold' => 'payment_pending',
            'failed' => 'payment_failed',
            'cancelled' => 'payment_cancelled',
            'refunded' => 'payment_refunded',
        ];

        return $status_mapping[$status] ?? 'order_status_changed';
    }

    /**
     * Determine the source of the status change
     *
     * @return string Change source
     */
    private function determine_change_source(): string
    {
        try {
            $attr = AttributionTracker::instance()->capture_context();
            $request_type = is_array($attr) ? sanitize_key((string)($attr['request_type'] ?? '')) : '';
            $external_service_name = (is_array($attr) && isset($attr['external_service']['name'])) ? sanitize_key((string)$attr['external_service']['name']) : null;

            if (is_user_logged_in()) {
                return 'manual';
            } elseif ($request_type === 'webhook' || !empty($external_service_name)) {
                return 'webhook';
            } elseif ($request_type === 'rest' || $request_type === 'ajax') {
                return 'api';
            } elseif (in_array($request_type, ['action_scheduler','cron','cli','wp_cli'], true)) {
                return 'scheduled';
            } else {
                return 'system';
            }
        } catch (\Throwable $e) {
            return 'system';
        }
    }

    /**
     * Process universal event from hook through the universal event pipeline
     *
     * @param UniversalEvent $event Universal event to process
     * @return void
     */
    private function process_universal_event_from_hook(UniversalEvent $event): void
    {
        try {
            // Create evaluation context
            $context = $this->create_evaluation_context_from_event($event);
            if (!$context) {
                return;
            }

            // Process through universal event processor
            $processor = UniversalEventProcessor::instance();
            $processor->processEvent($event->toArray());
        } catch (\Throwable $e) {
            // Log error but don't let it break the checkout process
            odcm_log_message('Payment gateway event processing error: ' . $e->getMessage(), 'error');
            odcm_log_message('Payment gateway event processing error details: ' . $e->getFile() . ':' . $e->getLine(), 'error');
            // Continue execution without throwing the exception
        }
    }

    /**
     * Derive real occurrence timestamp from WooCommerce order data based on event type
     *
     * @param \WC_Order $order WooCommerce order object
     * @param string $from_status Previous status
     * @param string $to_status New status
     * @return float Real occurrence timestamp
     */
    private function derive_real_occurrence_timestamp(\WC_Order $order, string $from_status, string $to_status): float
    {
        // For checkout events (draft → pending): Use order creation time
        if ($from_status === 'checkout-draft' && $to_status === 'pending') {
            return (float) $order->get_date_created()->getTimestamp();
        }
        
        // For payment completion events (pending → completed): Use payment date
        if ($to_status === 'completed' && $order->get_date_paid()) {
            return (float) $order->get_date_paid()->getTimestamp();
        }
        
        // For other status changes: Use date modified or fallback to current time
        if ($order->get_date_modified()) {
            return (float) $order->get_date_modified()->getTimestamp();
        }
        
        // Fallback to current time with microsecond precision
        return microtime(true);
    }

    /**
     * Get real payment timestamp from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @return float Payment timestamp
     */
    private function get_real_payment_timestamp(\WC_Order $order): float
    {
        // Use date_paid if available (most accurate)
        if ($order->get_date_paid()) {
            return (float) $order->get_date_paid()->getTimestamp();
        }
        
        // Fallback to date_modified for immediate payment scenarios
        if ($order->get_date_modified()) {
            return (float) $order->get_date_modified()->getTimestamp();
        }
        
        // Final fallback to current time
        return microtime(true);
    }


    /**
     * Create evaluation context from universal event
     *
     * @param UniversalEvent $event Universal event
     * @return EvaluationContext|null Evaluation context or null on failure
     */
    private function create_evaluation_context_from_event(UniversalEvent $event): ?EvaluationContext
    {
        $order = null;
        $subscription = null;
        $customer = null;

        // Load order if available
        if ($event->primaryObjectType === 'order' && $event->primaryObjectID) {
            $order = wc_get_order($event->primaryObjectID);
        }

        // Load subscription if available
        if ($event->primaryObjectType === 'subscription' && $event->primaryObjectID) {
            if (function_exists('wcs_get_subscription')) {
                $subscription = wcs_get_subscription($event->primaryObjectID);
            }
        }

        // Load customer
        if ($order && $order->get_customer_id()) {
            $customer = get_user_by('id', $order->get_customer_id());
        } elseif ($subscription && method_exists($subscription, 'get_customer_id')) {
            $customer = get_user_by('id', $subscription->get_customer_id());
        }

        // Create gateway metadata
        $gateway_metadata = [
            'gateway' => $event->sourceGateway,
            'channel' => $event->channel,
            'event_type' => $event->eventType,
            'source' => 'woocommerce_hook',
        ];

        return new EvaluationContext(
            $event,
            $order,
            $subscription,
            $customer,
            $gateway_metadata
        );
    }

    /**
     * Handle subscription status change events
     *
     * @param mixed $subscription WooCommerce subscription object
     * @param string $new_status New subscription status
     * @param string $old_status Old subscription status
     * @return void
     */
    public function handle_subscription_status_change($subscription, string $new_status, string $old_status): void
    {
        if (!$subscription || !method_exists($subscription, 'get_id')) {
            return;
        }

        // Generate UniversalEvent from subscription data
        $event_type = 'subscription_' . $new_status;
        $universal_event = $this->synthesize_subscription_event($subscription, $event_type);

        // Process through universal event pipeline
        $this->process_universal_event_from_hook($universal_event);

        odcm_log_message("Subscription #{$subscription->get_id()} status changed to '{$new_status}', processed as universal event", 'info');
    }

    /**
     * Handle subscription renewal payment complete events
     *
     * @param mixed $subscription WooCommerce subscription object
     * @param \WC_Order $renewal_order Renewal order object
     * @return void
     */
    public function handle_renewal_payment($subscription, \WC_Order $renewal_order): void
    {
        if (!$subscription || !method_exists($subscription, 'get_id')) {
            return;
        }

        // Generate UniversalEvent from subscription renewal data
        $universal_event = $this->synthesize_subscription_event($subscription, 'renewal_payment_completed');

        // Process through universal event pipeline
        $this->process_universal_event_from_hook($universal_event);

        odcm_log_message("Subscription #{$subscription->get_id()} renewal payment completed, processed as universal event", 'info');
    }

    /**
     * Handle new order creation events
     *
     * @param int $order_id The ID of the newly created order
     * @param \WC_Order|null $order The order object (if available)
     * @return void
     */
    public function handle_new_order(int $order_id, $order = null): void
    {
        
        if ($order_id <= 0) {
            return;
        }

        // Load order if not provided
        if (!$order instanceof \WC_Order) {
            $order = wc_get_order($order_id);
        }

        if (!$order instanceof \WC_Order) {
            return;
        }

        try {
            // Generate UniversalEvent for order creation
            $universal_event = $this->synthesize_order_created_event($order);

            // Process through universal event pipeline
            $this->process_universal_event_from_hook($universal_event);

            odcm_log_message("New order #{$order_id} created, processed as universal event", 'info');
        } catch (\Throwable $e) {
            // Log error but don't let it break the order creation process
            odcm_log_message('Order creation event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // Schedule traditional order check for backward compatibility
        $this->schedule_completion_check($order_id);
    }

    /**
     * Handle checkout order processed events - UNIFIED CHECKOUT PROCESSING
     *
     * CRITICAL: This method implements the "Never Break Revenue" philosophy.
     * Uses the unified checkout handler to prevent duplicate Action Scheduler jobs.
     *
     * @param int $order_id The ID of the processed order
     * @param array $posted_data Posted checkout data
     * @param \WC_Order $order The order object
     * @return void
     */
    public function handle_checkout_order_processed(int $order_id, array $posted_data, \WC_Order $order): void
    {
        $start_time = microtime(true);
        
        if ($order_id <= 0 || !$order instanceof \WC_Order) {
            return;
        }

        try {
            // UNIFIED: Use the centralized checkout processor to eliminate duplicates
            $this->unified_checkout_processor($order_id, $order, 'traditional_checkout', $posted_data);
            
            // Record success for circuit breaker
            $this->record_checkout_success();
            
        } catch (\Throwable $e) {
            // NEVER break checkout - log error and continue
            $this->log_safe_error('checkout_hook_failed', $e, [
                'order_id' => $order_id,
                'method' => 'handle_checkout_order_processed'
            ]);
            
            // Record failure for circuit breaker
            $this->record_checkout_failure();
            
            // Emergency fallback processing
            $this->emergency_fallback_processing($order_id);
        } finally {
            // Monitor execution time
            $this->monitor_execution_time($start_time, 'checkout_order_processed', $order_id);
        }
    }

    /**
     * Synthesize order created event from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @return UniversalEvent
     */
    private function synthesize_order_created_event(\WC_Order $order): UniversalEvent
    {
        $order_id = $order->get_id();
        $source = $this->determine_change_source();
        
        // Create order creation component
        $component_data = [
            'order_id' => $order_id,
            'status' => $order->get_status(),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'customer_id' => $order->get_customer_id(),
            'payment_method' => $order->get_payment_method(),
            'source' => $source,
        ];

        // Use creation timestamp for component
        $ts = (float) $order->get_date_created()->getTimestamp();

        $components = [[
            'k' => 'order_created_' . str_replace('.', '_', (string)$ts),
            'event_type' => 'order_created',
            'ts' => $ts,
            'label' => 'Order Created',
            'level' => 'info',
            'data' => $component_data,
        ]];

        return new UniversalEvent([
            'eventType' => 'order_created',
            'sourceGateway' => null,
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order_id,
            'transactionID' => $order->get_transaction_id(),
            'status' => $order->get_status(),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => [
                'order_status' => $order->get_status(),
                'customer_id' => $order->get_customer_id(),
                'source' => $source
            ],
            'components' => $components
        ]);
    }


    /**
     * Log status change evaluation for debug mode.
     * Called when ODCM_DEBUG is true to track all status change evaluations.
     *
     * This method now implements a comprehensive solution to reduce timeline noise:
     * 1. Only logs to debug log, not to timeline (event_type starts with underscore)
     * 2. Merges evaluation details into the main status change event
     * 3. Respects logging level settings
     *
     * @param int $order_id Order ID
     * @param string $from Previous status slug
     * @param string $to New status slug
     * @return void
     */
    private function log_status_change_evaluation(int $order_id, string $from, string $to): void
    {
        // Get the correct shared process_id for this order
        $process_id = \OrderDaemon\CompletionManager\Core\ProcessIdManager::instance()
            ->get_or_create_process_id($order_id);
        
        // SOLUTION 1: Use event_type that starts with underscore to exclude from timeline
        // SOLUTION 2: Include detailed evaluation data for debugging
        odcm_log_event(
            "Status change evaluation: Order #{$order_id} ({$from} → {$to})",
            [
                'from' => $from,
                'to' => $to,
                'debug_mode' => true,
                'evaluation_details' => [
                    'timestamp' => current_time('c'),
                    'source' => $this->determine_change_source(),
                    'purpose' => 'This event provides debugging information about status change evaluations but is excluded from the main timeline to reduce noise'
                ]
            ],
            $order_id,
            'debug',  // Changed from 'info' to 'debug' to reduce visibility
            '_status_evaluation',  // Prefixed with underscore to exclude from timeline
            false,     // is_test
            $process_id
        );
    }

    /**
     * Log when no rules match a status change (debug mode only).
     * Helps debug why certain orders don't trigger any rules.
     *
     * @param int $order_id Order ID
     * @param string $from Previous status slug
     * @param string $to New status slug
     * @return void
     */
    private function log_no_rules_matched(int $order_id, string $from, string $to): void
    {
        $rule_count = $this->count_active_rules();
        
        // Get or create process_id for this order to ensure consolidation
        $process_id = \OrderDaemon\CompletionManager\Core\ProcessIdManager::instance()
            ->get_or_create_process_id($order_id);
        
        odcm_log_event(
            "No rules matched for Order #{$order_id} status change ({$from} → {$to})",
            [
                'from' => $from, 
                'to' => $to, 
                'total_active_rules' => $rule_count,
                'debug_mode' => true
            ],
            $order_id,
            'debug',
            'no_rules_matched',
            false,  // is_test
            $process_id
        );
    }

    /**
     * Log when rule evaluation starts (normal + debug modes).
     * Always logged when rules are found that could match.
     *
     * @param int $order_id Order ID
     * @param string $from Previous status slug
     * @param string $to New status slug
     * @param array $rules Array of matching rules
     * @return void
     */
    private function log_rule_evaluation_started(int $order_id, string $from, string $to, array $rules): void
    {
        odcm_log_event(
            "Evaluating " . count($rules) . " rule(s) for Order #{$order_id}",
            [
                'from' => $from, 
                'to' => $to, 
                'rule_count' => count($rules),
                'rule_ids' => array_column($rules, 'id')
            ],
            $order_id,
            'info',
            'rule_evaluation_started'
        );
    }

    /**
     * Log individual rule evaluation results (normal + debug modes).
     *
     * @param int $order_id Order ID
     * @param array $rule Rule data with id and name
     * @param array $result Evaluation result with matched boolean and details
     * @return void
     */
    private function log_rule_evaluation_result(int $order_id, array $rule, array $result): void
    {
        $status = $result['matched'] ? 'success' : 'info';
        $action = $result['matched'] ? 'matched and executed' : 'evaluated but did not match';
        
        odcm_log_event(
            "Rule '{$rule['name']}' {$action} for Order #{$order_id}",
            [
                'rule_id' => $rule['id'],
                'rule_name' => $rule['name'],
                'matched' => $result['matched'],
                'conditions_met' => $result['conditions_met'] ?? null,
                'actions_taken' => $result['actions_taken'] ?? []
            ],
            $order_id,
            $status,
            'rule_evaluation_result'
        );
    }

    /**
     * Get matching rules for a specific status change.
     * Replaces the boolean should_trigger_any_status_change_rules with detailed rule matching.
     *
     * @param string $from_slug Previous status slug
     * @param string $to_slug New status slug
     * @return array Array of matching rules with id, name, and data
     */
    private function get_matching_rules_for_status_change(string $from_slug, string $to_slug): array
    {
        // Use cache to avoid repeated queries for the same status change
        static $rule_cache = [];
        $cache_key = "status_change_{$from_slug}_{$to_slug}";
        
        if (isset($rule_cache[$cache_key])) {
            return $rule_cache[$cache_key];
        }
        
        // Use persistent cache for cross-request efficiency
        $persistent_cache_key = 'odcm_matching_rules_' . md5($from_slug . '_' . $to_slug);
        $cached_rules = wp_cache_get($persistent_cache_key);
        if (false !== $cached_rules) {
            // Store in static cache and return
            $rule_cache[$cache_key] = $cached_rules;
            return $cached_rules;
        }
        
        // Get all published rules without using meta_query for better performance
        $rules = get_posts([
            'post_type' => 'odcm_order_rule',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids', // Get only IDs for better performance
        ]);
        
        $matching_rules = [];
        
        if (!empty($rules)) {
            // Prefetch meta data for all rules to reduce queries
            $rule_meta = [];
            update_meta_cache('post', $rules); // Prime the meta cache
            
            foreach ($rules as $rule_id) {
                // Check if rule is active using direct meta access
                $rule_active = get_post_meta($rule_id, '_odcm_rule_active', true);
                
                // Skip inactive rules
                if ($rule_active !== '1') {
                    continue;
                }
                
                // Get rule data
                $rule_data = json_decode(get_post_meta($rule_id, '_odcm_rule_data', true), true);
                
                if ($this->rule_matches_status_change($rule_data, $from_slug, $to_slug)) {
                    $matching_rules[] = [
                        'id' => $rule_id,
                        'name' => get_the_title($rule_id),
                        'data' => $rule_data
                    ];
                }
            }
        }
        
        // Cache the result for future requests (5 minutes cache)
        wp_cache_set($persistent_cache_key, $matching_rules, '', 5 * MINUTE_IN_SECONDS);
        
        // Store in static cache to avoid duplicate processing in same request
        $rule_cache[$cache_key] = $matching_rules;
        
        return $matching_rules;
    }

    /**
     * Check if a specific rule matches a status change.
     *
     * @param array $rule_data Rule configuration data
     * @param string $from Previous status slug
     * @param string $to New status slug
     * @return bool True if rule matches this status change
     */
    private function rule_matches_status_change(array $rule_data, string $from, string $to): bool
    {
        $trigger = $rule_data['trigger'] ?? [];
        $trigger_id = $trigger['id'] ?? '';
        
        // Check different trigger types
        switch ($trigger_id) {
            case 'order_status_any_change':
                return $this->evaluate_any_status_change_trigger($trigger, $from, $to);
            case 'order_processing':
                return $to === 'processing';
            case 'order_completed':
                return $to === 'completed';
            // Add other trigger types as needed...
            default:
                return false;
        }
    }

    /**
     * Evaluate AnyStatusChangeTrigger for a specific transition.
     *
     * @param array $trigger Trigger configuration
     * @param string $from Previous status slug
     * @param string $to New status slug
     * @return bool True if trigger should fire
     */
    private function evaluate_any_status_change_trigger(array $trigger, string $from, string $to): bool
    {
        $settings = $trigger['settings'] ?? [];
        
        // If no from/to restrictions, match all transitions
        $from_statuses = $settings['from_statuses'] ?? [];
        $to_statuses = $settings['to_statuses'] ?? [];
        
        // Empty arrays mean "all statuses"
        $from_match = empty($from_statuses) || in_array($from, $from_statuses);
        $to_match = empty($to_statuses) || in_array($to, $to_statuses);
        
        return $from_match && $to_match;
    }

    /**
     * Count active rules for logging purposes.
     *
     * @return int Number of active rules
     */
    private function count_active_rules(): int
    {
        $rules = get_posts([
            'post_type' => 'odcm_order_rule',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        return count($rules);
    }

    /**
     * Normalize gateway name to standard format
     *
     * @param string $payment_method WooCommerce payment method ID
     * @return string Normalized gateway name
     */
    private function normalize_gateway_name(string $payment_method): string
    {
        $gateway_mapping = [
            'paypal' => 'paypal',
            'ppcp-gateway' => 'paypal',
            'ppcp-credit-card-gateway' => 'paypal',
            'stripe' => 'stripe',
            'stripe_cc' => 'stripe',
            'stripe_sepa' => 'stripe',
            'bacs' => 'bank_transfer',
            'cheque' => 'check',
            'cod' => 'cash_on_delivery',
        ];

        return $gateway_mapping[$payment_method] ?? $payment_method;
    }

    // ========================================================================
    // FAIL-SAFE IMPLEMENTATION METHODS
    // ========================================================================

    /**
     * Log checkout event with minimal overhead - FAIL-SAFE LOGGING
     * 
     * Only logs essential information to avoid heavy database operations during checkout.
     *
     * @param int $order_id Order ID
     * @param string $status Processing status
     * @return void
     */
    private function log_checkout_event_minimal(int $order_id, string $status): void
    {
        try {
            // Use WordPress error_log to avoid database operations during checkout
            // Use WordPress debug logging mechanisms
            $log_message = sprintf(
                'ODCM_CHECKOUT: Order #%d %s at %s',
                $order_id,
                $status,
                current_time('c')
            );
            
            // Use WordPress action hook if available
            if (function_exists('do_action')) {
                do_action('odcm_log_error', $log_message);
            }
            
            // Use WordPress debug log function if available
            if (function_exists('wp_debug_log')) {
                wp_debug_log($log_message);
            }
            
                    // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        $debug_file = odcm_get_uploads_dir() . '/debug.log';
                        odcm_safe_file_put_contents(
                            $debug_file,
                            '[' . gmdate('Y-m-d H:i:s') . '] ' . $log_message . PHP_EOL,
                            FILE_APPEND
                        );
                    }
        } catch (\Throwable $e) {
            // Even logging should not break checkout - complete silence on failure
        }
    }

    /**
     * Log safe error - FAIL-SAFE ERROR LOGGING
     *
     * Enhanced error logging that never throws exceptions and uses WordPress error_log
     * to avoid additional database operations during checkout.
     *
     * @param string $context Error context
     * @param \Throwable $exception The exception that occurred
     * @param array $metadata Additional error metadata
     * @return void
     */
    private function log_safe_error(string $context, \Throwable $exception, array $metadata = []): void
    {
        try {
            $error_data = [
                'context' => $context,
                'error_message' => $exception->getMessage(),
                'error_file' => $exception->getFile(),
                'error_line' => $exception->getLine(),
                'metadata' => $metadata,
                'timestamp' => current_time('c'),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ];
            
            // Use controlled error logging to avoid additional DB operations, but only in debug mode
            if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('ODCM_DEBUG') && ODCM_DEBUG)) {
                $this->controlled_error_log('SAFE_ERROR: ' . wp_json_encode($error_data));
            }
        } catch (\Throwable $e) {
            // Even error logging should not break checkout - complete silence on failure
        }
    }

    /**
     * Record checkout success for circuit breaker - FAIL-SAFE CIRCUIT BREAKER
     *
     * @return void
     */
    private function record_checkout_success(): void
    {
        try {
            CheckoutCircuitBreaker::instance()->recordSuccess();
        } catch (\Throwable $e) {
            // Circuit breaker should not break checkout - silence on failure
        }
    }

    /**
     * Record checkout failure for circuit breaker - FAIL-SAFE CIRCUIT BREAKER
     *
     * @param string $context Additional context about the failure
     * @param array $metadata Additional failure metadata
     * @return void
     */
    private function record_checkout_failure(string $context = '', array $metadata = []): void
    {
        try {
            CheckoutCircuitBreaker::instance()->recordFailure($context, $metadata);
        } catch (\Throwable $e) {
            // Circuit breaker should not break checkout - silence on failure
        }
    }

    /**
     * Emergency fallback processing - FAIL-SAFE FALLBACK
     *
     * When all else fails, ensure the order is at least scheduled for basic processing.
     *
     * @param int $order_id Order ID
     * @return void
     */
    private function emergency_fallback_processing(int $order_id): void
    {
        try {
            // Last resort: schedule basic order check with minimal data
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('odcm_process_order_check', [
                    'order_id' => $order_id
                ], 'odcm-emergency-processing');
                
                $this->controlled_error_log("EMERGENCY: Scheduled fallback processing for order #{$order_id}");
            }
        } catch (\Throwable $e) {
            // Even emergency fallback should not break checkout
            $this->controlled_error_log("EMERGENCY: Final fallback failed for order #{$order_id}");
        }
    }

    /**
     * Monitor execution time - FAIL-SAFE PERFORMANCE MONITORING
     *
     * Tracks execution time and logs slow operations without breaking checkout.
     *
     * @param float $start_time Start time from microtime(true)
     * @param string $operation_name Operation name for logging
     * @param int $order_id Order ID for context
     * @return void
     */
    private function monitor_execution_time(float $start_time, string $operation_name, int $order_id): void
    {
        try {
            $execution_time = microtime(true) - $start_time;
            
            // Log slow operations (>0.5 seconds)
            if ($execution_time > 0.5) {
                $this->controlled_error_log(sprintf(
                    'SLOW_OPERATION: %s took %.3fs for order #%d',
                    $operation_name,
                    $execution_time,
                    $order_id
                ));
            }
            
            // Record performance metrics
            $perf_key = 'odcm_perf_' . sanitize_key($operation_name);
            $current_avg = get_transient($perf_key) ?: 0.0;
            $new_avg = ($current_avg + $execution_time) / 2; // Simple rolling average
            set_transient($perf_key, $new_avg, 3600); // 1 hour
            
        } catch (\Throwable $e) {
            // Performance monitoring should not break checkout
        }
    }

    /**
     * Check if circuit breaker is open - FAIL-SAFE CIRCUIT BREAKER CHECK
     *
     * @return bool True if circuit breaker is open (too many failures)
     */
    private function is_circuit_breaker_open(): bool
    {
        try {
            $failures = get_transient('odcm_checkout_failures');
            return (int) $failures >= 5;
        } catch (\Throwable $e) {
            return false; // Default to allowing processing
        }
    }

    /**
     * Get checkout health status - FAIL-SAFE HEALTH CHECK
     *
     * @return array Health status information
     */
    public function get_checkout_health_status(): array
    {
        try {
            $failures = get_transient('odcm_checkout_failures') ?: 0;
            $successes = get_transient('odcm_checkout_successes') ?: 0;
            $total = $failures + $successes;
            
            $success_rate = $total > 0 ? round(($successes / $total) * 100, 2) : 100;
            
            $health = 'healthy';
            if ($failures >= 5) {
                $health = 'critical';
            } elseif ($failures >= 2) {
                $health = 'warning';
            }
            
            return [
                'status' => $health,
                'success_rate' => $success_rate,
                'recent_failures' => (int) $failures,
                'recent_successes' => (int) $successes,
                'circuit_breaker_open' => $this->is_circuit_breaker_open(),
                'last_check' => current_time('c')
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unknown',
                'success_rate' => 0,
                'recent_failures' => 0,
                'recent_successes' => 0,
                'circuit_breaker_open' => false,
                'last_check' => current_time('c'),
                'error' => $e->getMessage()
            ];
        }
    }

    // ========================================================================
    // ORDER PROCESSING HELPER METHODS
    // ========================================================================

    /**
     * NEW: Finds all pending orders and schedules them for reprocessing in the background.
     *
     * This method avoids performance issues by fetching only order IDs and handing them
     * off to Action Scheduler for asynchronous processing.
     *
     * @return int The number of orders scheduled for reprocessing.
     */
    public function reprocess_pending_orders(): int
    {
        // Fetch only the IDs to keep memory usage extremely low.
        $order_ids = \OrderDaemon\CompletionManager\Includes\Utils\OrderQueryHelper::get_order_ids([
            'status' => ['processing', 'on-hold'],
            'limit'  => -1, // Get all matching orders.
        ]);

        if (empty($order_ids)) {
            return 0;
        }

        // Schedule a single background action with all the IDs.
        // Action Scheduler processes this asynchronously, preventing server timeouts.
        as_enqueue_async_action(
            'odcm_reprocess_orders_batch', // The hook our new function listens for.
            ['order_ids' => $order_ids],
            'odcm-order-processing'      // A custom group for our actions.
        );

        return count($order_ids);
    }

    /**
     * REFACTORED: Schedules completion checks for a given batch of order IDs.
     *
     * This method is now executed in the background by Action Scheduler. It loops
     * through the provided IDs and schedules an individual check for each one.
     *
     * @param array $order_ids An array of WooCommerce order IDs passed by Action Scheduler.
     */
    public function schedule_orders_for_reprocessing(array $order_ids)
    {
        if (empty($order_ids)) {
            return;
        }

        foreach ($order_ids as $order_id) {
            // Schedule an individual check for each order. This leverages the existing
            // reliable, single-order processing logic of the plugin.
            $this->schedule_completion_check((int) $order_id);
        }
    }

    /**
     * Check if processing should be bypassed due to circuit breaker - FAIL-SAFE CIRCUIT BREAKER
     *
     * @return bool True if processing should be bypassed
     */
    private function should_bypass_processing(): bool
    {
        try {
            return CheckoutCircuitBreaker::instance()->shouldBypassProcessing();
        } catch (\Throwable $e) {
            return false; // Default to allowing processing
        }
    }

    // ========================================================================
    // UNIFIED CHECKOUT PROCESSING - ELIMINATES DUPLICATE JOBS
    // ========================================================================

    /**
     * Unified checkout processor - CONSOLIDATES ALL CHECKOUT TYPES
     *
     * This method centralizes checkout processing from both block and traditional 
     * checkout handlers to eliminate duplicate Action Scheduler jobs while preserving
     * the rich data capture and exact payload schemas.
     *
     * @param int $order_id Order ID
     * @param \WC_Order $order Order object
     * @param string $source_context Source context (e.g., 'block_checkout', 'traditional_checkout')
     * @param array $additional_data Additional context data (posted_data for traditional)
     * @return void
     */
    private function unified_checkout_processor(int $order_id, \WC_Order $order, string $source_context, array $additional_data = []): void
    {
        // Check if we've already scheduled processing for this order
        if (!$this->should_process_checkout_unified($order_id)) {
            $this->log_checkout_event_minimal($order_id, 'skipped_already_scheduled');
            odcm_log_message("Unified checkout processor skipped for order #{$order_id} - already scheduled", 'info');
            return;
        }
        
        try {
            // Check for existing rich data from block checkout
            $has_rich_data = OrderMetaManager::get_meta($order_id, '_odcm_checkout_data_queued');
            
            if ($has_rich_data) {
                // Block checkout already queued rich data - use it
                odcm_log_message("Order #{$order_id} using existing rich block checkout data", 'info');
            } else {
                // Queue checkout data based on source context
                if ($source_context === 'traditional_checkout') {
                    $this->queue_traditional_checkout_data($order, $additional_data);
                } else {
                    // Fallback: ensure some basic data is queued
                    $this->queue_basic_checkout_data($order);
                }
            }
            
            // SINGLE POINT OF ACTION SCHEDULER SCHEDULING
            $this->schedule_unified_checkout_completion($order_id);
            
            // Minimal sync logging only - no heavy operations during checkout
            $this->log_checkout_event_minimal($order_id, 'unified_scheduled');
            
        } catch (\Throwable $e) {
            // Log error but continue - should not break checkout
            $this->log_safe_error('unified_checkout_processor_failed', $e, [
                'order_id' => $order_id,
                'source_context' => $source_context
            ]);
        }
    }

    /**
     * Check if unified checkout processing should proceed for this order
     *
     * Uses direct database queries for reliable Action Scheduler job detection
     * The original as_get_scheduled_actions() search was unreliable and caused
     * the complete order tracking failure.
     *
     * @param int $order_id Order ID
     * @return bool True if processing should proceed, false if already scheduled
     */
    private function should_process_checkout_unified(int $order_id): bool
    {
        // Check for recent unified scheduling to prevent duplicates
        $unified_key = "odcm_unified_checkout_scheduled_{$order_id}";
        if (get_transient($unified_key)) {
            odcm_log_message("Unified processor skipping order #{$order_id} - recent transient flag found", 'info');
            return false;
        }
        
        // Use direct database query for reliable job detection with proper caching
        global $wpdb;
        
        // Create a cache key for this specific order ID check
        $as_job_cache_key = 'odcm_as_jobs_' . $order_id;
        $cached_count = wp_cache_get($as_job_cache_key);
        
        // Only run the query if we don't have a cached result
        if (false === $cached_count) {
            $table_name = $wpdb->prefix . 'actionscheduler_actions';
            // Validate and construct a safe table name - cannot use placeholders for table names
            $table_name_clean = esc_sql($table_name);

            // Direct query is needed for reliable Action Scheduler job detection with proper caching
            // Prepare the full query with table name sanitization and proper value escaping
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $existing_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `%s` WHERE hook = %s AND status IN ('pending', 'in-progress') AND hook_arguments LIKE %s",
                $table_name_clean,
                'odcm_process_checkout_completion',
                '%' . $wpdb->esc_like('"order_id":' . intval($order_id)) . '%'
            ));
            
            // Cache the result for 60 seconds - Action Scheduler job existence is semi-volatile
            wp_cache_set($as_job_cache_key, $existing_count, '', 60);
        } else {
            // Use the cached count
            $existing_count = (int) $cached_count;
        }
        
        if ($existing_count > 0) {
            odcm_log_message("Unified processor skipping order #{$order_id} - found {$existing_count} existing jobs via database query", 'info');
            // Set transient for consistency but shorter time
            set_transient($unified_key, 1, 60); // 1 minute
            return false;
        }
        
        // Get job details with proper caching
        $job_details_cache_key = 'odcm_as_job_details_' . $order_id;
        $cached_job_details = wp_cache_get($job_details_cache_key);
        
        if (false === $cached_job_details) {
            // Properly prepare the SQL query for job details
            $table_name = $wpdb->prefix . 'actionscheduler_actions';
            $table_name_clean = esc_sql($table_name);

            // Direct query is needed for reliable Action Scheduler job detection with proper caching
            // Create a safe query with proper preparation
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $job_details = $wpdb->get_results($wpdb->prepare(
                "SELECT action_id, hook_arguments, status FROM `%s` WHERE hook = %s AND hook_arguments LIKE %s LIMIT 5",
                $table_name_clean,
                'odcm_process_checkout_completion',
                '%' . $wpdb->esc_like('"order_id":' . intval($order_id)) . '%'
            ));
            
            // Cache the result for 60 seconds
            wp_cache_set($job_details_cache_key, $job_details, '', 60);
        } else {
            // Use cached job details
            $job_details = $cached_job_details;
        }
        
        if (!empty($job_details)) {
            odcm_log_message("Unified processor found " . count($job_details) . " jobs for order #{$order_id} via detailed query", 'info');
            foreach ($job_details as $job) {
                odcm_log_message("- Job ID {$job->action_id}: status={$job->status}, args=" . substr($job->hook_arguments, 0, 100) . "...", 'info');
            }
            set_transient($unified_key, 1, 60);
            return false;
        }
        
        // Set flag to prevent duplicate processing
        set_transient($unified_key, 1, 300); // 5 minutes
        odcm_log_message("Unified processor allowing order #{$order_id} - no existing jobs found via database query", 'info');
        return true;
    }

    /**
     * Schedule unified checkout completion processing
     *
     * @param int $order_id Order ID
     * @return void
     */
    private function schedule_unified_checkout_completion(int $order_id): void
    {
        // Schedule for background processing with consistent arguments
        as_enqueue_async_action('odcm_process_checkout_completion', [
            'order_id' => $order_id,
            'unified_processor' => true,
            'scheduled_at' => current_time('c')
        ], 'odcm-checkout-processing');
        
        odcm_log_message("Unified checkout completion scheduled for order #{$order_id}", 'info');
    }

    /**
     * Queue basic checkout data when no rich data is available
     *
     * @param \WC_Order $order WooCommerce order object
     * @return void
     */
    private function queue_basic_checkout_data(\WC_Order $order): void
    {
        global $wpdb;
        
        $order_id = $order->get_id();
        $checkout_timestamp = (float) $order->get_date_created()->getTimestamp();
        
        // Create minimal checkout context
        $basic_context = [
            'cart_analysis' => ['total_items' => count($order->get_items())],
            'payment_context' => [
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'total_amount' => (float) $order->get_total(),
                'currency' => $order->get_currency(),
            ]
        ];
        
        // Prepare queue data with basic context
        $queue_data = [
            'order_id' => $order_id,
            'checkout_type' => 'basic', // Indicates minimal data
            'checkout_timestamp' => $checkout_timestamp,
            'checkout_context' => $basic_context,
            'order_data' => [
                'status' => $order->get_status(),
                'total' => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'transaction_id' => $order->get_transaction_id(),
                'customer_id' => $order->get_customer_id(),
            ],
            'source' => $this->determine_change_source(),
            'queued_at' => current_time('c'),
        ];
        
        // Generate unique queue ID
        $queue_id = 'basic_checkout_' . $order_id . '_' . time() . '_' . wp_rand(1000, 9999);
        
        // Create a transaction lock to prevent duplicate inserts
        $transaction_key = 'odcm_queue_transaction_' . md5($queue_id);
        $transaction_lock = wp_cache_get($transaction_key);
        
        if (false === $transaction_lock) {
            // Set a short transaction lock
            wp_cache_set($transaction_key, true, '', 30); // 30 seconds
            
            // Insert into queue table with proper checks
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert(
                $wpdb->prefix . 'odcm_audit_log_queue',
                [
                    'queue_id' => $queue_id,
                    'event_data' => wp_json_encode($queue_data),
                    'status' => 'pending',
                    'created_at' => current_time('mysql'),
                    'retry_count' => 0
                ]
            );
            
            // Cache the result to prevent duplicate insertions
            if ($result !== false) {
                $queue_cache_key = 'odcm_queue_' . md5($queue_id);
                wp_cache_set($queue_cache_key, true, '', 300); // 5 minutes
            }
            
            // Release transaction lock
            wp_cache_delete($transaction_key);
        } else {
            // Another process is handling this insert
            odcm_log_message("Transaction lock active for queue ID $queue_id - preventing duplicate insert", 'info');
            $result = true; // Assume success
        }
        
        if ($result === false) {
            throw new \Exception('Failed to queue basic checkout data: ' . esc_html($wpdb->last_error));
        }
        
        // Set marker to indicate this order has queued data
        OrderMetaManager::update_meta($order_id, '_odcm_checkout_queue_id', $queue_id);
        OrderMetaManager::update_meta($order_id, '_odcm_checkout_data_queued', '1');
        
        odcm_log_message("Basic checkout data queued with ID: {$queue_id} for order #{$order_id}", 'info');
    }

    // ========================================================================
    // BACKGROUND PROCESSING METHODS - ASYNC PROCESSING
    // ========================================================================


    /**
     * Queue traditional checkout data for async Universal Event creation.
     * 
     * Stores basic checkout context with real timestamp in the queue database.
     * The async processor will use this data to create the Universal Event.
     *
     * @param \WC_Order $order WooCommerce order object
     * @param array $posted_data Posted checkout data
     * @return void
     */
    private function queue_traditional_checkout_data(\WC_Order $order, array $posted_data): void
    {
        global $wpdb;
        
        $order_id = $order->get_id();
        $checkout_timestamp = (float) $order->get_date_created()->getTimestamp();
        
        // Build basic checkout context for traditional checkout
        $checkout_context = [];
        if (class_exists('OrderDaemon\\CompletionManager\\Core\\CheckoutContextBuilder')) {
            try {
                $checkout_context = CheckoutContextBuilder::buildCheckoutContext($order, 'standard');
            } catch (\Throwable $e) {
                // Fallback to basic context if CheckoutContextBuilder fails
                $checkout_context = [
                    'cart_analysis' => ['total_items' => count($order->get_items())],
                    'payment_context' => [
                        'payment_method' => $order->get_payment_method(),
                        'payment_method_title' => $order->get_payment_method_title(),
                        'total_amount' => $order->get_total(),
                        'currency' => $order->get_currency(),
                    ]
                ];
            }
        }
        
        // Prepare queue data with basic context and original timestamp
        $queue_data = [
            'order_id' => $order_id,
            'checkout_type' => 'standard',
            'checkout_timestamp' => $checkout_timestamp,
            'checkout_context' => $checkout_context,
            'order_data' => [
                'status' => $order->get_status(),
                'total' => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'transaction_id' => $order->get_transaction_id(),
                'customer_id' => $order->get_customer_id(),
            ],
            'posted_data' => $posted_data, // Include posted checkout data
            'source' => $this->determine_change_source(),
            'queued_at' => current_time('c'),
        ];
        
        // Generate unique queue ID
        $queue_id = 'checkout_' . $order_id . '_' . time() . '_' . wp_rand(1000, 9999);
        
        // Create a transaction lock to prevent duplicate inserts
        $transaction_key = 'odcm_traditional_queue_' . md5($queue_id . '_' . $order_id);
        $transaction_lock = wp_cache_get($transaction_key);
        
        if (false === $transaction_lock) {
            // Set a short transaction lock
            wp_cache_set($transaction_key, true, '', 30); // 30 seconds
            
            // Check if there's already a queued entry for this order
            $existing_queue_key = 'odcm_order_queued_' . $order_id;
            $existing_queue = wp_cache_get($existing_queue_key);
            
            if (false === $existing_queue) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $result = $wpdb->insert(
                    $wpdb->prefix . 'odcm_audit_log_queue',
                    [
                        'queue_id' => $queue_id,
                        'event_data' => wp_json_encode($queue_data),
                        'status' => 'pending',
                        'created_at' => current_time('mysql'),
                        'retry_count' => 0
                    ]
                );
                
                // Cache the order queue status to prevent duplicates
                if ($result !== false) {
                    wp_cache_set($existing_queue_key, $queue_id, '', 300); // 5 minutes
                }
            } else {
                // Already queued, log and use existing
                odcm_log_message("Found existing queue entry for order #$order_id - using existing queue ID", 'info');
                $result = true; // Assume success
                $queue_id = $existing_queue; // Use existing queue ID
            }
            
            // Release transaction lock
            wp_cache_delete($transaction_key);
        } else {
            // Another process is handling this insert
            odcm_log_message("Transaction lock active for order #$order_id - preventing duplicate queue entry", 'info');
            $result = true; // Assume success
        }
        
        if ($result === false) {
            throw new \Exception('Failed to queue traditional checkout data: ' . esc_html($wpdb->last_error));
        }
        
        // Set marker to indicate this order has queued data
        OrderMetaManager::update_meta($order_id, '_odcm_checkout_queue_id', $queue_id);
        OrderMetaManager::update_meta($order_id, '_odcm_checkout_data_queued', '1');
        
        odcm_log_message("Traditional checkout data queued with ID: {$queue_id} for order #{$order_id}", 'info');
    }

    /**
     * Background payment completion processing - ASYNC PROCESSING
     *
     * This method performs the heavy payment processing in the background
     * that was originally done synchronously in handle_payment_complete.
     *
     * Action Scheduler compatibility - handle both array and direct order ID
     *
     * @param array|int $args Arguments from Action Scheduler (can be array or direct order ID)
     * @return void
     */
    public function background_payment_processing($args): void
    {
        // Handle Action Scheduler calling convention
        if (is_array($args)) {
            $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
            $payment_gateway = isset($args['payment_gateway']) ? sanitize_text_field($args['payment_gateway']) : '';
        } else {
            // Action Scheduler passes order ID directly
            $order_id = (int) $args;
            $payment_gateway = '';
        }

        if ($order_id <= 0) {
            $this->controlled_error_log('BACKGROUND: Invalid order ID for payment processing');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            $this->controlled_error_log("BACKGROUND: Order #{$order_id} not found for payment processing");
            return;
        }

        try {
            // Generate UniversalEvent from WooCommerce data
            $universal_event = $this->synthesize_payment_complete_event($order);

            // Process through universal event pipeline
            $this->process_universal_event_from_hook($universal_event);

            $this->controlled_error_log("BACKGROUND: Payment completion processed for order #{$order_id}");

        } catch (\Throwable $e) {
            $this->controlled_error_log("BACKGROUND: Payment processing failed for order #{$order_id}: " . $e->getMessage());
            
            // Emergency fallback: schedule traditional order check if Universal Events fails
            $this->emergency_fallback_processing($order_id);
        }
    }
}
