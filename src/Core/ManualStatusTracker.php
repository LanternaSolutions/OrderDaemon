<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use WC_Order;
use OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer;
use OrderDaemon\CompletionManager\Core\Security\GuardFactory;
use OrderDaemon\CompletionManager\Core\Security\SecurityException;

/**
 * Manual Status Tracker - Chain of Custody Logging
 *
 * This class manually tracks status changes made by logged-in users to provide clear
 * chain of custody information and distinguish between automated and manual actions.
 *
 * CHAIN OF CUSTODY PRINCIPLES:
 * ===========================
 * 
 * The system must differentiate between:
 * - Automated status changes triggered by the plugin's rules
 * - Manual status changes made by logged-in users
 * - System-level changes from other plugins or WooCommerce core
 * 
 * This enables store owners to answer critical questions like:
 * - "Who changed this order status?"
 * - "Was this automated or manual?"
 * - "Why didn't automation run on this order?"
 * 
 * IMPLEMENTATION STRATEGY:
 * =======================
 * 
 * 1. Hook into woocommerce_order_status_changed with high priority
 * 2. Detect if a user is logged in and initiated the change
 * 3. Log manual changes with user attribution
 * 4. Provide context about whether automation was bypassed
 * 
 * INTEGRATION WITH EXISTING SYSTEM:
 * =================================
 * 
 * This class integrates with the existing audit log architecture:
 * - Uses the new registry-based logging system
 * - Follows established payload structure patterns
 * - Supports the process_id correlation system
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 */
class ManualStatusTracker
{
    /**
     * Temporary storage for manual status change context
     * Key: order_id, Value: manual context array
     * 
     * @var array
     */
    private static array $manual_contexts = [];

    /**
     * Initialize the manual status tracking hooks.
     * This method should be called during plugin initialization.
     */
    public static function init(): void
    {
        // Hook into order status changes with high priority to capture manual changes
        add_action('woocommerce_order_status_changed', [self::class, 'track_status_change'], 5, 4);
        
        // Hook into order save to detect manual edits
        add_action('woocommerce_process_shop_order_meta', [self::class, 'track_manual_order_edit'], 10, 2);
    }

    /**
     * Get manual status change context for an order
     * 
     * @param int $order_id Order ID
     * @return array|null Manual context data or null if not found
     */
    public static function get_manual_context(int $order_id): ?array
    {
        return self::$manual_contexts[$order_id] ?? null;
    }

    /**
     * Clear manual status change context for an order
     * 
     * @param int $order_id Order ID
     * @return void
     */
    public static function clear_manual_context(int $order_id): void
    {
        unset(self::$manual_contexts[$order_id]);
    }

    /**
     * Clear all manual contexts (cleanup method)
     * 
     * @return void
     */
    public static function clear_all_contexts(): void
    {
        self::$manual_contexts = [];
    }

    /**
     * Track order status changes and detect manual user actions.
     * 
     * PURE DETECTION ONLY - This method captures attribution data for manual status changes
     * and stores it for Core.php to pick up. It does NOT create any events or timeline entries.
     *
     * @param int    $order_id   The order ID.
     * @param string $from       The previous status.
     * @param string $to         The new status.
     * @param WC_Order $order    The order object.
     */
    public static function track_status_change(int $order_id, string $from, string $to, WC_Order $order): void
    {
        // Only detect manual changes - all other changes are handled by Core.php
        if (!self::is_manual_user_action()) {
            return;
        }

        // Verify nonce for any state-changing request (form, AJAX, REST)
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'odcm_manual_status_change')) {
            // Log security event with proper context
            odcm_log_event(
                'Nonce verification failed',
                ['order_id' => $order_id, 'context' => 'manual_status_change'],
                $order_id,
                'error',
                'nonce_verification_failed'
            );
            return;
        }

        // Create and verify nonce guard with proper error handling
        try {
            $guard = GuardFactory::createNonceGuard($nonce, 'odcm_manual_status_change');
            $guard->verify();
        } catch (SecurityException $e) {
            // Log security exception with detailed context
            odcm_log_event(
                'Nonce guard verification failed',
                [
                    'order_id' => $order_id,
                    'error' => $e->getMessage(),
                    'context' => 'manual_status_change'
                ],
                $order_id,
                'error',
                'nonce_guard_failed'
            );
            return;
        }

        // Capture attribution context for manual changes
        $attr = AttributionTracker::instance()->capture_context();
        
        // Get current user information
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_display_name = $current_user->display_name ?: $current_user->user_login;

        // Check if this change might have bypassed automation
        $bypassed_automation = self::would_automation_have_triggered($order, $from, $to);

        // Store manual change context for Core.php to pick up
        $manual_context = [
            'is_manual' => true,
            'user_id' => $user_id,
            'user_display_name' => $user_display_name,
            'bypassed_automation' => $bypassed_automation,
            'from_status' => $from,
            'to_status' => $to,
            'timestamp' => time(),
            'attribution' => $attr,
        ];

        // Store in static property for Core.php Universal Event synthesis to pick up
        self::$manual_contexts[$order_id] = $manual_context;

        // Add order note for manual changes (this is the only direct action we take)
        $note_message = sprintf(
            'Order status manually changed from "%s" to "%s" by %s.',
            wc_get_order_status_name($from),
            wc_get_order_status_name($to),
            $user_display_name
        );

        if ($bypassed_automation) {
            $note_message .= ' This change may have bypassed automatic completion rules.';
        }

        $order->add_order_note($note_message, false, true);
    }

    /**
     * Track manual order edits from the admin interface.
     *
     * This method captures when a user manually edits an order through
     * the WooCommerce admin interface, providing additional chain of custody context.
     * Supports both legacy WP_Post objects and HPOS order objects.
     *
     * @param int $post_id The order post ID.
     * @param mixed $post_or_order The order post object (WP_Post) or HPOS order object.
     */
    public static function track_manual_order_edit(int $post_id, mixed $post_or_order): void
    {
        // Verify nonce for admin form submissions
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'odcm_manual_order_edit')) {
            // Log security event with proper context
            odcm_log_event(
                'Nonce verification failed',
                ['order_id' => $post_id, 'context' => 'manual_order_edit'],
                $post_id,
                'error',
                'nonce_verification_failed'
            );
            return;
        }

                // Create and verify nonce guard with proper error handling
                try {
                    $guard = GuardFactory::createNonceGuard($nonce, 'odcm_manual_order_edit');
                    $guard->verify();
                } catch (SecurityException $e) {
                    // Log security exception with detailed context
                    odcm_log_event(
                        'Nonce guard verification failed',
                        [
                            'order_id' => $post_id,
                            'error' => $e->getMessage(),
                            'context' => 'manual_order_edit'
                        ],
                        $post_id,
                        'error',
                        'nonce_guard_failed'
                    );
                    return;
                }

        // Extract order ID from either WP_Post or HPOS order object
        if ($post_or_order instanceof \WP_Post) {
            $order_id = $post_or_order->ID;
        } elseif (is_object($post_or_order) && method_exists($post_or_order, 'get_id')) {
            // Handle HPOS order objects (Automattic\WooCommerce\Admin\Overrides\Order)
            $order_id = $post_or_order->get_id();
        } else {
            // Fallback to the provided post_id if we can't determine from the object
            $order_id = $post_id;
        }

        // Only track if user is logged in and this is an order
        if (!is_user_logged_in() || !\OrderDaemon\CompletionManager\Includes\Utils\OrderTypeDetector::is_processable_order($order_id)) {
            return;
        }

        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_display_name = $current_user->display_name ?: $current_user->user_login;

        // Get the order object
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Log the manual edit using registry-based logging (DEBUG level)
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_event(
                sprintf('Order #%d manually edited by %s', $post_id, $user_display_name),
                [
                    'order_id' => $post_id,
                    'user_id' => $user_id,
                    'user_display_name' => $user_display_name,
                    'action_taken' => 'order_edit',
                    'edit_context' => 'admin_interface',
                    'order_status' => $order->get_status(),
                    'order_data' => [
                        'total' => $order->get_total(),
                        'currency' => $order->get_currency(),
                        'payment_method' => $order->get_payment_method(),
                        'customer_id' => $order->get_customer_id(),
                    ]
                ],
                $post_id,
                'info',
                'manual_order_edit'
            );
        }
    }

    /**
     * Determine if automation would have triggered for this status change.
     * 
     * This method analyzes whether the manual status change might have
     * bypassed automatic completion rules, providing valuable context
     * for troubleshooting and chain of custody tracking.
     *
     * @param WC_Order $order The order object.
     * @param string   $from  The previous status.
     * @param string   $to    The new status.
     * 
     * @return bool True if automation might have been bypassed.
     */
    private static function would_automation_have_triggered(WC_Order $order, string $from, string $to): bool
    {
        // If the order was changed to 'completed', check if automation might have done this
        if ($to === 'completed') {
            // Check if there are any active completion rules that might apply
            $rules_query = new \WP_Query([
                'post_type'      => 'odcm_order_rule',
                'post_status'    => 'publish',
                'posts_per_page' => 1, // Just check if any exist
                'fields'         => 'ids',
            ]);

            // If there are active rules, automation might have been bypassed
            return $rules_query->have_posts();
        }

        // For other status changes, assume no automation bypass
        return false;
    }

    /**
     * Detect if current execution context is Order Daemon automation.
     * 
     * Uses backtrace to check if we're being called from Order Daemon's
     * rule action execution, following WordPress core pattern for context detection.
     *
     * @return bool True if this is Order Daemon automation.
     */
    private static function is_automation_context(): bool
    {
        // Check for a dedicated marker in process context
        if (isset($GLOBALS['odcm_automation_context']) && $GLOBALS['odcm_automation_context'] === true) {
            return true;
        }
        
        // Check for a transient marker for this process
        $process_id = get_transient('odcm_current_process_id');
        if (!empty($process_id) && strpos($process_id, 'auto_') === 0) {
            return true;
        }
        
        // Check for specific runtime hooks that signal automation
        if (has_action('odcm_automation_running') && did_action('odcm_automation_running') > 0) {
            return true;
        }
        
        // Use WordPress safe caller detection
        if (function_exists('wp_get_caller_class')) {
            $caller_class = wp_get_caller_class();
            if ($caller_class && (
                strpos($caller_class, 'OrderDaemon\\CompletionManager\\Core\\RuleComponents\\RuleActions') === 0 ||
                $caller_class === 'OrderDaemon\\CompletionManager\\Core\\Evaluator'
            )) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if the current request context indicates a manual user action.
     * 
     * This helper method determines if the current execution context
     * suggests a manual user action versus an automated system action.
     * 
     * CRITICAL: This must be very specific to avoid false positives.
     * Only mark as manual when user is directly editing orders.
     *
     * @return bool True if this appears to be a manual user action.
     */
    private static function is_manual_user_action(): bool
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return false;
        }

        // Check if this is Order Daemon automation context (definitely not manual)
        if (self::is_automation_context()) {
            return false;
        }

        // Check if this is Action Scheduler context (definitely not manual)
        if (self::is_action_scheduler_context()) {
            return false;
        }

        // Check if this is a cron/background process (definitely not manual)
        if (wp_doing_cron() || (defined('DOING_CRON') && DOING_CRON)) {
            return false;
        }

        // Check for specific WooCommerce order edit actions (definitely manual)
        if (self::is_woocommerce_order_edit()) {
            return true;
        }

        // Check for AJAX requests that are order management related (potentially manual)
        if (wp_doing_ajax() && self::is_order_management_ajax()) {
            return true;
        }

        // Check for REST API requests with specific order management context
        if (defined('REST_REQUEST') && (bool) constant('REST_REQUEST')) {
            return self::is_order_management_rest();
        }

        // Default to false - be conservative to avoid false positives
        return false;
    }

    /**
     * Check if this is Action Scheduler executing background tasks
     *
     * @return bool True if Action Scheduler context
     */
    private static function is_action_scheduler_context(): bool
    {
        // Check for Action Scheduler execution via constants
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        
        // Check for Action Scheduler marker
        if (isset($GLOBALS['current_hook']) && strpos($GLOBALS['current_hook'], 'action_scheduler') !== false) {
            return true;
        }
        
        // Check if specific Action Scheduler functions exist and are running
        if (function_exists('as_unschedule_action') && 
            (isset($GLOBALS['doing_as_cron']) || get_transient('doing_as_cron'))) {
            return true;
        }
        
        // Look for Action Scheduler classes in the global namespace
        if (class_exists('ActionScheduler_QueueRunner', false) || 
            class_exists('ActionScheduler_AsyncRequest_Runner', false)) {
            return true;
        }
        
        // Use WordPress safe caller detection if available
        if (function_exists('wp_get_caller_class')) {
            $caller_class = wp_get_caller_class();
            if ($caller_class && (
                strpos($caller_class, 'ActionScheduler') !== false ||
                strpos($caller_class, 'WC_Action_Queue') !== false
            )) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if this is a WooCommerce order edit action
     *
     * @return bool True if editing an order
     */
    private static function is_woocommerce_order_edit(): bool
    {
        global $pagenow, $typenow;

        // Check if we're on the order edit page
        if ($pagenow === 'post.php' && $typenow === 'shop_order') {
            return true;
        }

        // Check if we're on the orders list page with edit action
        if ($pagenow === 'edit.php' && $typenow === 'shop_order') {
            $action = (isset($_GET['action']) && is_string($_GET['action'])) ? sanitize_key(wp_unslash($_GET['action'])) : '';
            return $action === 'edit';
        }

        // Check for WooCommerce admin order edit screens
        if (is_admin()) {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'shop_order') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this is an order management AJAX request
     *
     * @return bool True if order management AJAX
     */
    private static function is_order_management_ajax(): bool
    {
        if (!wp_doing_ajax()) {
            return false;
        }

        // Check if action exists before processing
        if (!isset($_REQUEST['action'])) {
            return false;
        }

        // Verify nonce for AJAX actions when present
        // Note: We don't verify nonce as a strict requirement here because this is detection-only code
        // and we don't want to break WooCommerce's own AJAX handlers that may not include our nonces
        if (isset($_REQUEST['security'])) {
            $security_nonce = is_string($_REQUEST['security']) ? sanitize_text_field(wp_unslash($_REQUEST['security'])) : '';
            $nonce_valid = wp_verify_nonce(
                $security_nonce, 
                'woocommerce-order-ajax'
            );
            if (!$nonce_valid) {
                // Log suspicious activity but allow WooCommerce to handle security
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $action = sanitize_key(wp_unslash($_REQUEST['action']));
                    do_action('odcm_log_security_warning', 'Invalid WooCommerce AJAX nonce for: ' . $action);
                }
            }
        }

        $action = sanitize_key(wp_unslash($_REQUEST['action']));
        
        // WooCommerce order management AJAX actions
        $order_ajax_actions = [
            'woocommerce_mark_order_status',
            'woocommerce_update_order_review',
            'woocommerce_save_order_items',
            'woocommerce_add_order_item',
            'woocommerce_remove_order_item',
            'woocommerce_add_order_note',
            'woocommerce_delete_order_note',
        ];

        return in_array($action, $order_ajax_actions, true);
    }

    /**
     * Check if this is an order management REST API request
     *
     * @return bool True if order management REST
     */
    private static function is_order_management_rest(): bool
    {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }

        // Check for REST API nonce when available (WP REST API will handle authorization)
        if (isset($_REQUEST['_wpnonce'])) {
            $rest_nonce = is_string($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
            $nonce_valid = wp_verify_nonce($rest_nonce, 'wp_rest');
            
            // Log if invalid but don't block - this is just detection code
            if (!$nonce_valid && defined('WP_DEBUG') && WP_DEBUG) {
                do_action('odcm_log_security_warning', 'Invalid REST API nonce detected');
            }
        }
        
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        
        // WooCommerce REST API order endpoints
        if (strpos($request_uri, '/wp-json/wc/') !== false && strpos($request_uri, '/orders/') !== false) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
            // Only consider PUT/POST as potentially manual (not GET which could be automated)
            return in_array($method, ['PUT', 'POST', 'PATCH'], true);
        }

        return false;
    }

    /**
     * Get detailed user context for logging.
     * 
     * This method gathers comprehensive information about the current user
     * for inclusion in audit log entries, supporting detailed chain of custody tracking.
     *
     * @return array User context data for logging.
     */
    private static function get_user_context(): array
    {
        if (!is_user_logged_in()) {
            return [
                'user_id' => 0,
                'user_type' => 'guest',
                'user_display_name' => 'Guest',
                'user_roles' => [],
                'user_capabilities' => [],
            ];
        }

        $current_user = wp_get_current_user();

        return [
            'user_id' => $current_user->ID,
            'user_type' => 'logged_in',
            'user_display_name' => $current_user->display_name ?: $current_user->user_login,
            'user_login' => $current_user->user_login,
            'user_email' => $current_user->user_email,
            'user_roles' => $current_user->roles,
            'user_capabilities' => array_keys(array_filter($current_user->allcaps)),
            'user_registered' => $current_user->user_registered,
        ];
    }

    /**
     * Log a manual trigger event when a user manually initiates automation.
     *
     * This method should be called when a user manually triggers the automation
     * to re-run on a specific order, providing clear attribution for the action.
     *
     * @param int $order_id The order ID.
     * @param string $trigger_context Additional context about how it was triggered.
     */
    public static function log_manual_trigger(int $order_id, string $trigger_context = 'admin_interface'): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $current_user = wp_get_current_user();
        $user_context = self::get_user_context();

        // Log manual trigger using Universal Events system
        $sanitizer = new ComponentSanitizer();

        $trigger_message = sprintf('User %s triggered automation for order #%d (%s)',
            $user_context['user_display_name'], $order_id, $trigger_context);

        $info_data = $sanitizer->sanitize('info', ['message' => $trigger_message]);

        $payload_components = [
            [
                'key' => 'manual-trigger-' . wp_generate_uuid4(),
                'event_type' => 'info',
                'ts' => odcm_iso8601_now(),
                'label' => 'Manual trigger invoked',
                'level' => 'info',
                'data' => $info_data,
            ]
        ];

        $summary = sprintf('Manual automation trigger for Order #%d', $order_id);

        // Log using Universal Events system
        odcm_log_event(
            $summary,
            [
                'type' => 'admin_action',
                'cid' => $order_id . ':' . time(), // This is a separate event type, so keep unique correlation ID
                'oid' => $order_id,
                'actor' => [
                    'id' => $current_user->ID,
                    'role' => null,
                    'name' => $user_context['user_display_name']
                ],
                'ts' => time(),
                'components' => $payload_components,
            ],
            $order_id,
            'success',
            'admin_action'
        );

        // DEBUG: Add debug logging to trace manual trigger execution
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_MANUAL_TRIGGER_DEBUG: Manual trigger logged for order #{$order_id}", 'debug');
            odcm_log_message("ODCM_MANUAL_TRIGGER_DEBUG: About to schedule completion check for manual trigger", 'debug');
        }

        // Schedule the order for completion check to trigger rule evaluation
        // This ensures that manual triggers actually execute the rules
        $core = new \OrderDaemon\CompletionManager\Core\Core();
        $core->schedule_completion_check($order_id);

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            odcm_log_message("ODCM_MANUAL_TRIGGER_DEBUG: Scheduled completion check for order #{$order_id} after manual trigger", 'debug');
        }
    }

}
