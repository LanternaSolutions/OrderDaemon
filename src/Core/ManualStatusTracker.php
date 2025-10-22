<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use WC_Order;
use OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;
use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;

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
     * Track order status changes and detect manual user actions.
     * 
     * This method is called whenever an order status changes in WooCommerce.
     * It determines if the change was made by a logged-in user and logs
     * appropriate chain of custody information.
     *
     * @param int    $order_id   The order ID.
     * @param string $from       The previous status.
     * @param string $to         The new status.
     * @param WC_Order $order    The order object.
     */
    public static function track_status_change(int $order_id, string $from, string $to, WC_Order $order): void
    {
        // Capture enhanced attribution context (request type, plugin, user, service)
        $attr = AttributionTracker::instance()->capture_context();
        $request_type = is_array($attr) ? sanitize_key((string) ($attr['request_type'] ?? '')) : '';
        $external_service_name = (is_array($attr) && isset($attr['external_service']['name'])) ? sanitize_key((string) $attr['external_service']['name']) : null;

        // Map attribution into canonical 'source' values used by premium filters
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

        // Define standard WooCommerce workflow transitions that are automatic
        $automatic_workflow_transitions = [
            'checkout-draft' => 'pending',    // Standard checkout completion
            'pending' => 'processing',        // Standard payment processing
        ];
        
        $is_automatic_workflow = isset($automatic_workflow_transitions[$from]) && 
                                $automatic_workflow_transitions[$from] === $to;

        // Backward compatibility: do not log non-manual changes unless debugging is enabled
        if ($source !== 'manual' && (!defined('ODCM_DEBUG') || !ODCM_DEBUG)) {
            return;
        }
        // For automatic workflow transitions, only log when ODCM_DEBUG is enabled
        if ($is_automatic_workflow && (!defined('ODCM_DEBUG') || !ODCM_DEBUG)) {
            return;
        }

        // Get current user information (falls back to 'system' label if no user)
        $current_user = wp_get_current_user();
        $user_id = isset($current_user->ID) ? (int) $current_user->ID : 0;
        $user_display_name = $user_id > 0 ? ($current_user->display_name ?: $current_user->user_login) : 'system';

        // Check if this change might have bypassed automation
        $bypassed_automation = self::would_automation_have_triggered($order, $from, $to);

        // Determine the appropriate event type and context (kept for compatibility)
        $event_type = $is_automatic_workflow ? 'automatic_workflow_transition' : 'manual_status_change';
        $context = $is_automatic_workflow ? 'automatic_workflow' : 'manual_status_change';

        // Generate UniversalEvent for manual status changes
        if ($source === 'manual' && !$is_automatic_workflow) {
            try {
                $universal_event = self::synthesize_manual_status_change_event($order, $from, $to, $user_id, $bypassed_automation);
                self::process_universal_event_from_hook($universal_event);
                odcm_log_message("Manual status change for order #{$order_id} ({$from} → {$to}) processed as universal event", 'info');
            } catch (\Throwable $e) {
                odcm_log_message('Manual status change universal event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
            }
        }

        // Log status change using Universal Events system
        $sanitizer = new ComponentSanitizer();
        
        // Prepare sanitized components
        $components = [];
        
        // Add status change component
        $status_data = $sanitizer->sanitize('status_changed', ['from' => $from, 'to' => $to]);
        $components[] = [
            'k' => 'c' . time() . rand(10,99),
            'event_type' => 'status_changed',
            'ts' => time(),
            'label' => 'Status changed',
            'level' => 'info',
            'data' => $status_data,
        ];
        
        // Add workflow info if automatic
        if ($is_automatic_workflow) {
            $info_data = $sanitizer->sanitize('info', ['message' => 'Standard WooCommerce workflow transition detected']);
            $components[] = [
                'k' => 'c' . time() . rand(10,99),
                'event_type' => 'info',
                'ts' => time(),
                'label' => 'Automatic workflow transition',
                'level' => 'info',
                'data' => $info_data,
            ];
        }
        
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
                'request_type' => $request_type ?: null,
                'user_logged_in' => (bool) ($attr['user_context']['is_logged_in'] ?? false),
                'source_plugin' => $plugin_compact,
                'external_service' => $ext_compact,
            ];
            
            $components[] = [
                'k' => 'c' . time() . rand(10,99),
                'event_type' => 'info',
                'ts' => time(),
                'label' => 'Attribution context',
                'level' => 'info',
                'data' => ['attribution' => $attribution_data],
            ];
        }
        
        // Add automation bypass warning if applicable
        if ($bypassed_automation) {
            $warning_data = $sanitizer->sanitize('warning', [
                'code' => 'bypassed_automation',
                'message' => 'This change may have bypassed auto rules'
            ]);
            $components[] = [
                'k' => 'c' . time() . rand(10,99),
                'event_type' => 'warning',
                'ts' => time(),
                'label' => 'Automation bypass context',
                'level' => 'warning',
                'data' => $warning_data,
            ];
        }
        
        // Generate final summary
        $final_summary = sprintf(
            'Order #%d status %s changed from "%s" to "%s" by %s.',
            $order_id,
            $is_automatic_workflow ? 'automatically' : 'manually',
            $from,
            $to,
            $user_display_name
        );
        
        // Log using Universal Events system
        odcm_log_event(
            $final_summary,
            [
                'type' => $event_type,
                'cid' => $order_id . ':' . time(),
                'oid' => $order_id,
                'actor' => [
                    'id' => $source === 'manual' ? $user_id : null,
                    'role' => $source === 'manual' ? ($current_user->roles[0] ?? null) : null,
                    'name' => $source === 'manual' ? $current_user->display_name : null,
                ],
                'ts' => time(),
                'components' => $components,
            ],
            $order_id,
            'success',
            $event_type
        );

        // Only add order notes for truly manual changes, not automatic workflow transitions
        if (!$is_automatic_workflow && $source === 'manual') {
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
    }

    /**
     * Track manual order edits from the admin interface.
     * 
     * This method captures when a user manually edits an order through
     * the WooCommerce admin interface, providing additional chain of custody context.
     *
     * @param int $post_id The order post ID.
     * @param \WP_Post $post The order post object.
     */
    public static function track_manual_order_edit(int $post_id, \WP_Post $post): void
    {
        // Only track if user is logged in and this is an order
        if (!is_user_logged_in() || $post->post_type !== 'shop_order') {
            return;
        }

        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_display_name = $current_user->display_name ?: $current_user->user_login;

        // Get the order object
        $order = wc_get_order($post_id);
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
     * Check if the current request context indicates a manual user action.
     * 
     * This helper method determines if the current execution context
     * suggests a manual user action versus an automated system action.
     *
     * @return bool True if this appears to be a manual user action.
     */
    private static function is_manual_user_action(): bool
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return false;
        }

        // Check if this is an admin request
        if (is_admin()) {
            return true;
        }

        // Check if this is a REST API request with authentication
        if (defined('REST_REQUEST') && (bool) constant('REST_REQUEST')) {
            return is_user_logged_in();
        }

        // Check for AJAX requests from admin
        if (wp_doing_ajax() && is_admin()) {
            return true;
        }

        // Default to false for automated contexts
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
                'cid' => $order_id . ':' . time(),
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
    }

    /**
     * Synthesize manual status change event from WooCommerce order data
     *
     * @param \WC_Order $order WooCommerce order object
     * @param string $from_status Previous status
     * @param string $to_status New status
     * @param int $user_id User ID who made the change
     * @param bool $bypassed_automation Whether automation was bypassed
     * @return UniversalEvent
     */
    private static function synthesize_manual_status_change_event(\WC_Order $order, string $from_status, string $to_status, int $user_id, bool $bypassed_automation): UniversalEvent
    {
        return new UniversalEvent([
            'eventType' => 'manual_status_change',
            'sourceGateway' => self::normalize_gateway_name($order->get_payment_method()),
            'channel' => 'manual',
            'primaryObjectType' => 'order',
            'primaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => $to_status,
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'reason' => $bypassed_automation ? 'automation_bypassed' : 'manual_intervention',
            'occurredAt' => current_time('c'),
            'rawData' => [
                'from_status' => $from_status,
                'to_status' => $to_status,
                'user_id' => $user_id,
                'bypassed_automation' => $bypassed_automation,
                'source' => 'manual',
                'user_display_name' => self::get_user_display_name($user_id)
            ]
        ]);
    }

    /**
     * Normalize gateway name to standard format
     *
     * @param string $payment_method WooCommerce payment method ID
     * @return string Normalized gateway name
     */
    private static function normalize_gateway_name(string $payment_method): string
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
     * Get user display name for a given user ID
     *
     * @param int $user_id User ID
     * @return string User display name
     */
    private static function get_user_display_name(int $user_id): string
    {
        if ($user_id <= 0) {
            return 'system';
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return 'unknown_user_' . $user_id;
        }

        return $user->display_name ?: $user->user_login;
    }

    /**
     * Process universal event from hook through the universal event pipeline
     *
     * @param UniversalEvent $event Universal event to process
     * @return void
     */
    private static function process_universal_event_from_hook(UniversalEvent $event): void
    {
        try {
            // Schedule universal event processing through Action Scheduler
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'odcm_process_lifecycle_event',
                    ['event' => $event->toArray()],
                    'odcm-universal-events'
                );
            } else {
                // Fallback: process directly if Action Scheduler not available
                $processor = UniversalEventProcessor::instance();
                $processor->processEvent($event->toArray());
            }
        } catch (\Throwable $e) {
            // Log error but don't let it break the manual status change process
            odcm_log_message('Payment gateway event processing error: ' . $e->getMessage(), 'error');
            odcm_log_message('Payment gateway event processing error details: ' . $e->getFile() . ':' . $e->getLine(), 'error');
            // Continue execution without throwing the exception
        }
    }
}
