<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use OrderDaemon\CompletionManager\Admin\Notices;
use OrderDaemon\CompletionManager\Includes\AuditTrailLogger;
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

        // ENHANCED DUPLICATE PREVENTION: Rely on Action Scheduler's built-in deduplication
        if (function_exists('as_get_scheduled_actions')) {
            // Check for pending actions
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

            // Check for very recent completed actions (within last 5 minutes)
            // This prevents rapid re-scheduling of the same order
            $recent_actions = as_get_scheduled_actions([
                'hook' => 'odcm_process_order_check',
                'args' => ['order_id' => $order_id],
                'status' => 'complete',
                'per_page' => 1,
                'date_query' => [
                    'after' => '5 minutes ago'
                ]
            ]);

            if (!empty($recent_actions)) {
                odcm_log_message("Skipping duplicate scheduling for order #{$order_id} - recently processed", 'info');
                return false;
            }

            // Check for currently running actions
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
        
        $payload_components = [
            [
                'key' => 'admin-reprocess-' . wp_generate_uuid4(),
                'kind' => 'info',
                'ts' => odcm_iso8601_now(),
                'label' => 'Admin requested reprocess',
                'level' => 'info',
                'data' => $sanitizer->sanitize('info', ['message' => 'Admin requested reprocess of all orders']),
            ]
        ];
        
        odcm_log_custom_event(
            'Admin requested reprocess of all orders',
            [
                'type' => 'admin_action',
                'correlation_id' => 'odcm:admin_reprocess:' . wp_generate_uuid4(),
                'actor' => [
                    'id' => get_current_user_id(),
                    'role' => null,
                    'name' => wp_get_current_user()->display_name ?: wp_get_current_user()->user_login
                ],
                'started_at' => odcm_iso8601_now(),
                'finished_at' => odcm_iso8601_now(),
                'payload_components' => $payload_components,
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
     * Handles WooCommerce payment completion events.
     *
     * This method is triggered when a payment is completed and schedules
     * the order for automatic completion rule processing.
     *
     * @param int $order_id The ID of the order that had payment completed.
     */
    public function handle_payment_complete(int $order_id): void
    {
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        try {
            // Generate UniversalEvent from WooCommerce data
            $universal_event = $this->synthesize_payment_complete_event($order);

            // Process through universal event pipeline
            $this->process_universal_event_from_hook($universal_event);

            odcm_log_message("Payment completed for order #{$order_id}, processed as universal event", 'info');
        } catch (\Throwable $e) {
            // Log error but don't let it break the payment process
            odcm_log_message('Payment complete event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
            
            // Fallback: still schedule traditional processing
            $this->schedule_completion_check($order_id);
            return;
        }

        // Always schedule traditional order check for backward compatibility
        $this->schedule_completion_check($order_id);
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
            
            $payload_components = [];
            
            // Add status change component
            $status_data = $sanitizer->sanitize('status_changed', ['from' => 'unknown', 'to' => $status_slug]);
            $payload_components[] = [
                'key' => 'status-change-' . wp_generate_uuid4(),
                'kind' => 'status_changed',
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
                
                $payload_components[] = [
                    'key' => 'attribution-' . wp_generate_uuid4(),
                    'kind' => 'info',
                    'ts' => odcm_iso8601_now(),
                    'label' => 'Attribution context',
                    'level' => 'info',
                    'data' => ['attribution' => $attribution_data],
                ];
            }
            
            $summary = sprintf('Order #%d status changed to "%s"; scheduled via specific hook', $order_id, $status_slug);
            
            odcm_log_custom_event(
                $summary,
                [
                    'type' => 'status_change_processing',
                    'correlation_id' => 'odcm:status_change:' . $order_id . ':' . wp_generate_uuid4(),
                    'order_id' => $order_id,
                    'actor' => ['id' => null, 'role' => null, 'name' => 'system'],
                    'started_at' => odcm_iso8601_now(),
                    'finished_at' => odcm_iso8601_now(),
                    'payload_components' => $payload_components,
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
     * Handles general WooCommerce order status change events.
     *
     * This method is triggered for ALL order status changes and includes deduplication
     * logic to prevent duplicate processing when specific status hooks have already
     * been triggered.
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

        // Check if any rules with AnyStatusChangeTrigger should be triggered for this transition
        if (!$this->should_trigger_any_status_change_rules($from_slug, $to_slug)) {
            odcm_log_message("Skipping general status change for order #{$order_id} ({$from_slug} → {$to_slug}) - no AnyStatusChangeTrigger rules match this transition", 'info');
            return;
        }

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
            $pl->add_component('dedup', 'Dedup checks', [ 'specific_hook' => (bool) ($this->has_specific_status_processed($order_id, $to_slug, 30) ?? false) ], 'info');
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
            odcm_log_message('Universal event processing failed: ' . $e->getMessage(), 'error');
            odcm_log_message('Universal event processing error details: ' . $e->getFile() . ':' . $e->getLine(), 'error');
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
     * Handle checkout order processed events
     *
     * @param int $order_id The ID of the processed order
     * @param array $posted_data Posted checkout data
     * @param \WC_Order $order The order object
     * @return void
     */
    public function handle_checkout_order_processed(int $order_id, array $posted_data, \WC_Order $order): void
    {
        if ($order_id <= 0 || !$order instanceof \WC_Order) {
            return;
        }

        try {
            // Generate UniversalEvent for checkout completion
            $universal_event = $this->synthesize_checkout_processed_event($order, $posted_data);

            // Process through universal event pipeline
            $this->process_universal_event_from_hook($universal_event);

            odcm_log_message("Checkout processed for order #{$order_id}, processed as universal event", 'info');
        } catch (\Throwable $e) {
            // Log error but don't let it break the checkout process
            odcm_log_message('Checkout processed event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
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
}
