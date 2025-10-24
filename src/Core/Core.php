<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use OrderDaemon\CompletionManager\Admin\Notices;
use OrderDaemon\CompletionManager\Core\BlockCheckoutCompatibility;
use OrderDaemon\CompletionManager\Core\RefundDeletionDiagnostics;
use OrderDaemon\CompletionManager\Core\AttributionTracker;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;
use OrderDaemon\CompletionManager\Core\Events\EvaluationContext;
use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;

/**
 * Core plugin class responsible for main business logic.
 *
 * This refactored version changes the "Reprocess Orders" tool to be fully asynchronous,
 * preventing server timeouts and memory exhaustion on sites with many orders.
 */
class Core
{
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
        add_action('odcm_process_checkout_completion', [$this, 'background_checkout_processing'], 10, 1);
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
                        $slug = is_string($status_key) ? sanitize_key($status_key) : '';
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
    }

    /**
     * REFACTORED: Handles the "Reprocess Orders" request from the developer tools page.
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
        // Only process when explicitly requested via admin-post action
        $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
        if ($action !== 'odcm_reprocess_orders') {
            return;
        }

        // DEFENSIVE CHECK: Verify post type exists before processing
        if (!post_type_exists('odcm_order_rule')) {
            odcm_log_message("CRITICAL: Post type 'odcm_order_rule' not registered during reprocess request", 'error');
            odcm_log_message("This indicates a race condition in plugin initialization", 'error');

            // Add admin notice for debugging
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo esc_html__('Error: Order rules post type not available. Please contact support.', 'order-daemon');
                echo '</p></div>';
            });
            return;
        }

        // Log that this method was called
        odcm_log_message("handle_reprocess_request() called from hook: " . current_action(), 'info');
        odcm_log_message("REQUEST_METHOD: " . ($_SERVER["REQUEST_METHOD"] ?? "unknown"), 'info');
        odcm_log_message("REQUEST_URI: " . ($_SERVER["REQUEST_URI"] ?? "unknown"), 'info');
        odcm_log_message("POST data: " . wp_json_encode($_POST), 'info');
        odcm_log_message("GET data: " . wp_json_encode($_GET), 'info');
        odcm_log_message("Current user ID: " . get_current_user_id(), 'info');
        odcm_log_message("Is admin: " . (is_admin() ? "yes" : "no"), 'info');

        // Log the verification attempt
        odcm_log_message("About to verify reprocess request", 'info');

        if (!$this->verify_reprocess_request()) {
            odcm_log_message("verify_reprocess_request() returned false - exiting", 'error');
            return;
        }

        odcm_log_message("verify_reprocess_request() passed - proceeding", 'info');

        // Call our new, asynchronous method.
        $count = $this->reprocess_pending_orders();

        odcm_log_message("reprocess_pending_orders() returned count: " . $count, 'info');

        // Log the reprocess action to the audit trail
        $this->log_reprocess_action($count);

        $this->add_reprocess_success_notice($count);

        odcm_log_message("About to redirect after reprocess", 'info');

        $this->redirect_after_reprocess();
    }

    /**
     * Handle WooCommerce payment completion events - FAIL-SAFE IMPLEMENTATION
     *
     * CRITICAL: This method implements the "Never Break Revenue" philosophy.
     * All heavy processing is moved to background to ensure payment completion cannot be blocked.
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
                error_log("ODCM_CIRCUIT_BREAKER: Bypassing payment processing for order #{$order_id} - circuit breaker is open");
                return;
            }

            // Schedule for background processing
            as_enqueue_async_action('odcm_process_payment_completion', [
                'order_id' => $order_id,
                'payment_gateway' => $order->get_payment_method(),
                'scheduled_at' => current_time('c')
            ], 'odcm-payment-processing');
            
            // Minimal sync logging only - no heavy operations during payment
            $this->log_checkout_event_minimal($order_id, 'payment_scheduled');
            
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
     * Verifies the nonce and user capabilities for the reprocess request.
     * (This is a pre-existing private method, assumed to exist).
     *
     * @return bool True if the request is valid, false otherwise.
     */
    private function verify_reprocess_request(): bool
    {
        // Check if the reprocess button was clicked and nonce is valid
        if (isset($_POST['odcm_reprocess_orders']) &&
            isset($_POST['odcm_reprocess_nonce']) &&
            wp_verify_nonce(sanitize_key($_POST['odcm_reprocess_nonce']), 'odcm_reprocess_action')) {
            return current_user_can('manage_woocommerce');
        }
        return false;
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
                '%d order has been scheduled for reprocessing.',
                '%d orders have been scheduled for reprocessing.',
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
                'k' => 'c' . time() . rand(10,99),
                'event_type' => 'admin_action',
                'ts' => time(),
                'label' => sprintf(__('Admin reprocessed %d orders', 'order-daemon'), $count),
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

        // Generate UniversalEvent for status change
        try {
            if ($order instanceof \WC_Order) {
                $universal_event = $this->synthesize_status_change_event($order, 'unknown', $status_slug);
                $this->process_universal_event_from_hook($universal_event);
                odcm_log_message("Order #{$order_id} status change to '{$status_slug}' processed as universal event", 'info');
            }
        } catch (\Throwable $e) {
            odcm_log_message('Status change universal event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // Log status change using Universal Events system
        try {
            $sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
            
            $components = [];
            
            // Add status change component
            $status_data = $sanitizer->sanitize('status_changed', ['from' => 'unknown', 'to' => $status_slug]);
            $components[] = [
                'k' => 'c' . time() . rand(10,99),
                'event_type' => 'status_changed',
                'ts' => odcm_iso8601_now(),
                'label' => 'Status changed',
                'level' => 'info',
                'data' => $status_data,
            ];
            
            // Add attribution context if available
            if (is_array($attr)) {
                $src_plugin = is_array($attr['source_plugin'] ?? null) ? $attr['source_plugin'] : [];
                $plugin_compact = [
                    'type' => isset($src_plugin['type']) ? sanitize_key((string) $src_plugin['type']) : null,
                    'slug' => isset($src_plugin['slug']) ? sanitize_text_field((string) $src_plugin['slug']) : null,
                    'confidence' => isset($src_plugin['confidence']) ? (float) $src_plugin['confidence'] : null,
                ];
                $ext = is_array($attr['external_service'] ?? null) ? $attr['external_service'] : null;
                $ext_compact = is_array($ext) ? [
                    'name' => isset($ext['name']) ? sanitize_key((string) $ext['name']) : null,
                    'confidence' => isset($ext['confidence']) ? (float) $ext['confidence'] : null,
                ] : null;
                
                $attribution_data = [
                    'source' => $source,
                    'request_type' => isset($request_type) && $request_type !== '' ? $request_type : null,
                    'user_logged_in' => (bool) ($attr['user_context']['is_logged_in'] ?? false),
                    'source_plugin' => $plugin_compact,
                    'external_service' => $ext_compact,
                ];
                
                $components[] = [
                    'k' => 'c' . time() . rand(10,99),
                    'event_type' => 'info',
                    'ts' => odcm_iso8601_now(),
                    'label' => 'Attribution context',
                    'level' => 'info',
                    'data' => ['attribution' => $attribution_data],
                ];
            }
            
            $summary = sprintf('Order #%d status changed to "%s"; scheduled via specific hook', $order_id, $status_slug);
            
            odcm_log_event(
                $summary,
                [
                    'type' => 'status_change_processing',
                    'cid' => $order_id . ':' . time(),
                    'order_id' => $order_id,
                    'actor' => ['id' => null, 'role' => null, 'name' => 'system'],
                    'ts' => time(),
                    'components' => $components,
                ],
                $order_id,
                'info',
                'status_change_processing'
            );
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // Schedule the order for completion check
        $this->schedule_completion_check($order_id);

        try {
            if (isset($pl)) {
                $pl->finish('queued', sprintf('Order #%d status changed to "%s"; scheduled via specific hook', $order_id, $status_slug));
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
        if ($order_id <= 0) {
            return;
        }

        $from_slug = sanitize_key((string)$from_status);
        $to_slug   = sanitize_key((string)$to_status);

        // Always log in debug mode
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->log_status_change_evaluation($order_id, $from_slug, $to_slug);
        }

        // Get matching rules for this status change
        $matching_rules = $this->get_matching_rules_for_status_change($from_slug, $to_slug);

        // Log rule matching results when debug mode is enabled
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM_DEBUG_TRACE: Order #{$order_id} ({$from_slug} → {$to_slug}) - Found " . count($matching_rules) . " matching rules");
        }
        
        if (empty($matching_rules)) {
            // Log when no rules match (only in debug mode)
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM_DEBUG_TRACE: Order #{$order_id} - NO RULES MATCHED, exiting early");
            }
            $this->log_no_rules_matched($order_id, $from_slug, $to_slug);
            return; // Exit early - no rule processing needed
        }

        // Log that we found matching rules (only in debug mode)
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM_DEBUG_TRACE: Order #{$order_id} - RULES MATCHED, proceeding to universal event processing");
            foreach ($matching_rules as $rule) {
                error_log("ODCM_DEBUG_TRACE: Order #{$order_id} - Matching rule: {$rule['name']} (ID: {$rule['id']})");
            }
        }

        // Rules match - ALWAYS log this (production + debug)
        $this->log_rule_evaluation_started($order_id, $from_slug, $to_slug, $matching_rules);

        // Dedup using specific-hook marker and last processed meta
        try {
            if ($this->has_specific_status_processed($order_id, $to_slug, 30)) {
                odcm_log_message("Skipping general status change for order #{$order_id} ({$from_slug} → {$to_slug}) - specific hook meta indicates processed", 'info');
                return;
            }
        } catch (\Throwable $e) {
            odcm_log_message('Error checking specific status processed meta for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }
        try {
            if ($this->is_duplicate_status_event($order_id, $from_slug, $to_slug, 30)) {
                odcm_log_message("Skipping general status change for order #{$order_id} ({$from_slug} → {$to_slug}) - last processed meta indicates duplicate", 'info');
                return;
            }
        } catch (\Throwable $e) {
            odcm_log_message('Error checking _odcm_last_status_processed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
        }

        // Additional safety: if a pending Action Scheduler task exists very recently, skip to prevent duplicates
        if (function_exists('as_get_scheduled_actions')) {
            $recent_actions = as_get_scheduled_actions([
                'hook' => 'odcm_process_order_check',
                'args' => ['order_id' => $order_id],
                'status' => 'pending',
                'per_page' => 1,
                'date_query' => [ 'after' => '10 seconds ago' ]
            ]);
            if (!empty($recent_actions)) {
                odcm_log_message("Skipping general status change for order #{$order_id} ({$from_slug} → {$to_slug}) - pending action exists", 'info');
                return;
            }
        }

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

        // Narrative log with ProcessLogger and update last processed meta
        try {
            $pl = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger(new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer());
            $pl->start('status_change_processing', [ 'order_id' => $order_id, 'via' => 'general', 'source' => $source ]);
            $status_key = $pl->add_component('status_changed', 'Status changed (general hook)', [ 'from' => $from_slug, 'to' => $to_slug ]);
            if (is_array($attr)) {
                $src_plugin = is_array($attr['source_plugin'] ?? null) ? $attr['source_plugin'] : [];
                $plugin_compact = [
                    'type' => isset($src_plugin['type']) ? sanitize_key((string) $src_plugin['type']) : null,
                    'slug' => isset($src_plugin['slug']) ? sanitize_text_field((string) $src_plugin['slug']) : null,
                    'confidence' => isset($src_plugin['confidence']) ? (float) $src_plugin['confidence'] : null,
                ];
                $ext = is_array($attr['external_service'] ?? null) ? $attr['external_service'] : null;
                $ext_compact = is_array($ext) ? [
                    'name' => isset($ext['name']) ? sanitize_key((string) $ext['name']) : null,
                    'confidence' => isset($ext['confidence']) ? (float) $ext['confidence'] : null,
                ] : null;
                $pl->add_deferred_context($status_key, [
                    'attribution' => [
                        'source' => $source,
                        'request_type' => $request_type ?: null,
                        'user_logged_in' => (bool) ($attr['user_context']['is_logged_in'] ?? false),
                        'source_plugin' => $plugin_compact,
                        'external_service' => $ext_compact,
                    ]
                ]);
            }
            $pl->add_component('dedup', 'Dedup checks', [ 'specific_hook' => (bool) ($this->has_specific_status_processed($order_id, $to_slug, 30) ?? false) ], 'debug');
        } catch (\Throwable $e) {
            // ignore
        }

        // Generate UniversalEvent for general status change
        try {
            if ($order instanceof \WC_Order) {
                $universal_event = $this->synthesize_status_change_event($order, $from_slug, $to_slug);
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

        // Schedule the order for completion check
        $this->schedule_completion_check($order_id);

        try {
            if (isset($pl)) {
                $pl->finish('queued', sprintf('Order #%d status changed from "%s" to "%s"; scheduled via general hook', $order_id, $from_slug, $to_slug));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        odcm_log_message("Order #{$order_id} status changed ({$from_slug} → {$to_slug}), source={$source}; scheduled for completion check via general hook", 'info');
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
        $map = get_post_meta($order_id, $key, true);
        if (!is_array($map)) {
            $map = [];
        }
        $map[$status] = time();
        update_post_meta($order_id, $key, $map);
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
        $map = get_post_meta($order_id, $key, true);
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

        update_post_meta($order_id, '_odcm_last_status_processed', $payload);
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
        $val = get_post_meta($order_id, '_odcm_last_status_processed', true);
        return is_array($val) ? $val : null;
    }

    /**
     * Detect whether the provided from→to status event is a duplicate of the last processed event within a time window.
     *
     * This helps deduplicate cases where the general hook fires right after the specific hook or repeated
     * rapid transitions occur.
     *
     * @param int    $order_id       WooCommerce order ID.
     * @param string $from           From status slug.
     * @param string $to             To status slug.
     * @param int    $window         Time window in seconds. Default 30.
     * @return bool  True if duplicate, false otherwise.
     */
    private function is_duplicate_status_event(int $order_id, string $from, string $to, int $window = 30): bool
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

        if ($last_to !== $to) {
            return false;
        }
        $from_matches = ($last_from === $from) || ($last_from === 'unknown');
        if (!$from_matches) {
            return false;
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
     * Synthesize payment complete event from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @return UniversalEvent
     */
    private function synthesize_payment_complete_event(\WC_Order $order): UniversalEvent
    {
        return new UniversalEvent([
            'eventType' => 'payment_completed',
            'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => 'completed',
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => [] // No sensitive webhook data
        ]);
    }

    /**
     * Synthesize status change event from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @param string $from_status Previous status
     * @param string $to_status New status
     * @return UniversalEvent
     */
    private function synthesize_status_change_event(\WC_Order $order, string $from_status, string $to_status): UniversalEvent
    {
        return new UniversalEvent([
            'eventType' => $this->map_status_to_event_type($to_status),
            'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => $to_status,
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => [
                'from_status' => $from_status,
                'to_status' => $to_status,
                'source' => $this->determine_change_source()
            ]
        ]);
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
     * Handle checkout order processed events - FAIL-SAFE IMPLEMENTATION
     *
     * CRITICAL: This method implements the "Never Break Revenue" philosophy.
     * All heavy processing is moved to background to ensure checkout cannot be blocked.
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
            // Schedule for background processing instead of sync processing
            as_enqueue_async_action('odcm_process_checkout_completion', [
                'order_id' => $order_id,
                'checkout_type' => 'standard',
                'scheduled_at' => current_time('c')
            ], 'odcm-checkout-processing');
            
            // Minimal sync logging only - no heavy operations during checkout
            $this->log_checkout_event_minimal($order_id, 'scheduled');
            
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
        return new UniversalEvent([
            'eventType' => 'order_created',
            'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => $order->get_status(),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => [
                'order_status' => $order->get_status(),
                'payment_method' => $order->get_payment_method(),
                'customer_id' => $order->get_customer_id(),
                'source' => $this->determine_change_source()
            ]
        ]);
    }

    /**
     * Synthesize checkout processed event from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @param array $posted_data Posted checkout data
     * @return UniversalEvent
     */
    private function synthesize_checkout_processed_event(\WC_Order $order, array $posted_data): UniversalEvent
    {
        return new UniversalEvent([
            'eventType' => 'checkout_processed',
            'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
            'channel' => 'system',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => $order->get_status(),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'occurredAt' => current_time('c'),
            'rawData' => [
                'order_status' => $order->get_status(),
                'payment_method' => $order->get_payment_method(),
                'customer_id' => $order->get_customer_id(),
                'checkout_type' => 'standard',
                'source' => $this->determine_change_source()
            ]
        ]);
    }

    /**
     * Log status change evaluation for debug mode.
     * Called when ODCM_DEBUG is true to track all status change evaluations.
     *
     * @param int $order_id Order ID
     * @param string $from Previous status slug
     * @param string $to New status slug
     * @return void
     */
    private function log_status_change_evaluation(int $order_id, string $from, string $to): void
    {
        odcm_log_event(
            "Status change evaluation: Order #{$order_id} ({$from} → {$to})",
            ['from' => $from, 'to' => $to, 'debug_mode' => true],
            $order_id,
            'info',
            'status_evaluation'
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
        $rules = get_posts([
            'post_type' => 'odcm_order_rule',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_odcm_rule_active',
                    'value' => '1',
                    'compare' => '='
                ]
            ]
        ]);
        
        $matching_rules = [];
        
        foreach ($rules as $rule) {
            $rule_data = json_decode(get_post_meta($rule->ID, '_odcm_rule_data', true), true);
            
            if ($this->rule_matches_status_change($rule_data, $from_slug, $to_slug)) {
                $matching_rules[] = [
                    'id' => $rule->ID,
                    'name' => $rule->post_title,
                    'data' => $rule_data
                ];
            }
        }
        
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
            error_log(sprintf(
                'ODCM_CHECKOUT: Order #%d %s at %s',
                $order_id,
                $status,
                current_time('c')
            ));
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
            
            // Use WordPress error logging to avoid additional DB operations
            error_log('ODCM_SAFE_ERROR: ' . wp_json_encode($error_data));
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
                
                error_log("ODCM_EMERGENCY: Scheduled fallback processing for order #{$order_id}");
            }
        } catch (\Throwable $e) {
            // Even emergency fallback should not break checkout
            error_log("ODCM_EMERGENCY: Final fallback failed for order #{$order_id}");
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
                error_log(sprintf(
                    'ODCM_SLOW_OPERATION: %s took %.3fs for order #%d',
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
        $order_ids = wc_get_orders([
            'status' => ['processing', 'on-hold'],
            'limit'  => -1, // Get all matching orders.
            'return' => 'ids',
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
    // BACKGROUND PROCESSING METHODS - ASYNC PROCESSING
    // ========================================================================

    /**
     * Background checkout completion processing - ASYNC PROCESSING
     *
     * This method performs the heavy checkout processing in the background
     * that was originally done synchronously in handle_checkout_order_processed.
     *
     * Action Scheduler compatibility - handle both array and direct order ID
     *
     * @param array|int $args Arguments from Action Scheduler (can be array or direct order ID)
     * @return void
     */
    public function background_checkout_processing($args): void
    {
        // Handle Action Scheduler calling convention
        if (is_array($args)) {
            $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
            $checkout_type = isset($args['checkout_type']) ? sanitize_text_field($args['checkout_type']) : 'standard';
        } else {
            // Action Scheduler passes order ID directly
            $order_id = (int) $args;
            $checkout_type = 'standard';
        }

        if ($order_id <= 0) {
            error_log('ODCM_BACKGROUND: Invalid order ID for checkout processing');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            error_log("ODCM_BACKGROUND: Order #{$order_id} not found for checkout processing");
            return;
        }

        try {
            // Generate UniversalEvent for checkout completion
            $universal_event = $this->synthesize_checkout_processed_event($order, []);

            // Process through universal event pipeline
            $this->process_universal_event_from_hook($universal_event);

            error_log("ODCM_BACKGROUND: Checkout completion processed for order #{$order_id}");

        } catch (\Throwable $e) {
            error_log("ODCM_BACKGROUND: Checkout processing failed for order #{$order_id}: " . $e->getMessage());
            
            // Emergency fallback: schedule traditional order check if Universal Events fails
            $this->emergency_fallback_processing($order_id);
        }
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
            error_log('ODCM_BACKGROUND: Invalid order ID for payment processing');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            error_log("ODCM_BACKGROUND: Order #{$order_id} not found for payment processing");
            return;
        }

        try {
            // Generate UniversalEvent from WooCommerce data
            $universal_event = $this->synthesize_payment_complete_event($order);

            // Process through universal event pipeline
            $this->process_universal_event_from_hook($universal_event);

            error_log("ODCM_BACKGROUND: Payment completion processed for order #{$order_id}");

        } catch (\Throwable $e) {
            error_log("ODCM_BACKGROUND: Payment processing failed for order #{$order_id}: " . $e->getMessage());
            
            // Emergency fallback: schedule traditional order check if Universal Events fails
            $this->emergency_fallback_processing($order_id);
        }
    }
}
