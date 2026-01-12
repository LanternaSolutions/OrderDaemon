<?php
declare(strict_types=1);

/**
 * Audit Log Filter Definitions
 *
 * This file contains all filter registrations for the audit log interface.
 * It provides comprehensive filtering capabilities for the audit log system.
 *
 * FILTER ARCHITECTURE:
 * ===================
 *
 * Each filter is registered with:
 * - id: Unique identifier (e.g., 'date_range')
 * - label: Human-readable name for UI
 * - render_callback: Function to render filter input UI
 *
 * RENDER CALLBACKS:
 * ================
 *
 * Each filter has a render callback that receives:
 * - $filter: Filter configuration array
 * - $has_permission: Whether user can use this filter
 * - $current_value: Current filter value
 * - Callback should render appropriate UI based on permission status
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/audit-log-filters
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Register all audit log filters with the FilterRegistry
 * 
 * This function is called during plugin initialization to register all
 * available filters for the audit log interface. It follows the established
 * pattern from options.php but is specifically tailored for filtering.
 * 
 * @since 1.0.0
 * @return void
 */
function odcm_register_audit_filters(): void
{
    $registry = odcm_get_filter_registry_instance();

    /**
     * Basic Search Filter
     * 
     * Provides text-based search across log summaries, event types, and sources.
     * This is the core search functionality.
     */
    $registry->register_filter([
        'id'              => 'basic_search',
        'label'           => __('admin.insight_dashboard.filters.search.label', 'order-daemon'),
        'render_callback' => 'odcm_render_basic_search_filter',
    ]);

    /**
     * Date Range Filter
     * 
     * Allows filtering by specific date ranges using date pickers.
     * Provides precise temporal filtering.
     */
    $registry->register_filter([
        'id'              => 'date_range',
        'label'           => __('admin.insight_dashboard.filters.date_range.label', 'order-daemon'),
        'render_callback' => 'odcm_render_date_range_filter',
    ]);

    /**
     * Status Filter
     * 
     * Filter logs by status (Success, Error, Warning, etc.).
     * Helps users quickly identify issues or successful operations.
     */
    $registry->register_filter([
        'id'              => 'status',
        'label'           => __('admin.insight_dashboard.filters.status.label', 'order-daemon'),
        'render_callback' => 'odcm_render_status_filter',
    ]);

    /**
     * Event Type Filter
     * 
     * Filter by specific event types (rule_check, order_completion, etc.).
     * Allows users to focus on specific types of operations.
     */
    $registry->register_filter([
        'id'              => 'event_type',
        'label'           => __('admin.insight_dashboard.filters.event_type.label', 'order-daemon'),
        'render_callback' => 'odcm_render_event_type_filter',
    ]);


    /**
     * Source Filter
     * 
     * Filter by log source (manual, scheduled, webhook, etc.).
     * Helps identify the origin of different log entries.
     */
    $registry->register_filter([
        'id'              => 'source',
        'label'           => __('admin.insight_dashboard.filters.source.label', 'order-daemon'),
        'render_callback' => 'odcm_render_source_filter',
    ]);
}

// ============================================================================
// FILTER RENDER CALLBACKS
// ============================================================================

/**
 * Render Search Filter Input
 * 
 * Renders a text input for search functionality.
 * 
 * @since 1.0.0
 * @param array $filter Filter configuration array
 * @param bool $has_permission Whether user can use this filter
 * @param string $current_value Current filter value
 * @return void
 */
function odcm_render_basic_search_filter(array $filter, bool $has_permission, string $current_value): void
{
    // Add nonce field for the filter form
    wp_nonce_field('odcm_audit_filter_action', '_wpnonce');
    
    echo '<input type="text" ';
    echo 'name="s" ';
    echo 'id="odcm-search-input" ';
    echo 'value="' . esc_attr($current_value) . '" ';
    echo 'placeholder="' . esc_attr__('admin.insight_dashboard.filters.search.placeholder', 'order-daemon') . '" ';
    echo 'class="regular-text" ';
    
    if (!$has_permission) {
        echo 'disabled="disabled" ';
    }
    
    echo '/>';
}

/**
 * Render Date Range Filter Inputs
 * 
 * Renders date picker inputs for start and end dates.
 * 
 * @since 1.0.0
 * @param array $filter Filter configuration array
 * @param bool $has_permission Whether user can use this filter
 * @param string $current_value Current filter value (not used for date range)
 * @return void
 */
function odcm_render_date_range_filter(array $filter, bool $has_permission, string $current_value): void
{
    // Verify nonce if this is a form submission
    $is_valid_request = false;
    if (isset($_REQUEST['_wpnonce'])) {
        $is_valid_request = wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'odcm_audit_filter_action');
    }
    
    // Only process filter data if the request is valid or if it's an initial page load
    $date_from = '';
    $date_to = '';
    
    // Only process GET parameters if nonce validation passes or if we're not processing a form submission
    if ($is_valid_request || !isset($_REQUEST['_wpnonce'])) {
        $date_from = isset($_GET['date_start']) ? sanitize_text_field(wp_unslash($_GET['date_start'])) : '';
        $date_to = isset($_GET['date_end']) ? sanitize_text_field(wp_unslash($_GET['date_end'])) : '';
    }
    
    echo '<div class="odcm-date-range-container">';
    
    // From date
    echo '<input type="date" ';
    echo 'name="date_start" ';
    echo 'id="odcm-date-start" ';
    echo 'value="' . esc_attr($date_from) . '" ';
    echo 'class="regular-text" ';
    
    if (!$has_permission) {
        echo 'disabled="disabled" ';
    }
    
    echo '/>';
    
    echo '<span class="odcm-date-separator">' . esc_html__('admin.insight_dashboard.filters.date_range.to', 'order-daemon') . '</span>';
    
    // To date
    echo '<input type="date" ';
    echo 'name="date_end" ';
    echo 'id="odcm-date-end" ';
    echo 'value="' . esc_attr($date_to) . '" ';
    echo 'class="regular-text" ';
    
    if (!$has_permission) {
        echo 'disabled="disabled" ';
    }
    
    echo '/>';
    
    echo '</div>';
}

/**
 * Render Status Filter Dropdown
 * 
 * Renders a select dropdown with available log statuses.
 * 
 * @since 1.0.0
 * @param array $filter Filter configuration array
 * @param bool $has_permission Whether user can use this filter
 * @param string $current_value Current filter value
 * @return void
 */
function odcm_render_status_filter(array $filter, bool $has_permission, string $current_value): void
{
    // Verify nonce if this is a form submission
    if (!empty($_REQUEST) && isset($_REQUEST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'odcm_audit_filter_action')) {
            // Invalid nonce, but we'll still show the filter - just won't process values
            $current_value = '';
        }
    }
    
    $statuses = [
        ''        => __('admin.insight_dashboard.filters.status.all', 'order-daemon'),
        'success' => __('status.success', 'order-daemon'),
        'error'   => __('status.error', 'order-daemon'),
        'warning' => __('status.warning', 'order-daemon'),
        'info'    => __('status.info', 'order-daemon'),
    ];
    
    echo '<select name="status" id="odcm-status-filter" class="regular-text"';
    
    if (!$has_permission) {
        echo ' disabled="disabled"';
    }
    
    echo '>';
    
    foreach ($statuses as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"';
        selected($current_value, $value);
        echo '>' . esc_html($label) . '</option>';
    }
    
    echo '</select>';
}

/**
 * Render Event Type Filter Dropdown
 *
 * Renders a select dropdown with available event types.
 *
 * @since 1.0.0
 * @param array $filter Filter configuration array
 * @param bool $has_permission Whether user can use this filter
 * @param string $current_value Current filter value
 * @return void
 */
function odcm_render_event_type_filter(array $filter, bool $has_permission, string $current_value): void
{
    // Verify nonce if this is a form submission
    if (!empty($_REQUEST) && isset($_REQUEST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'odcm_audit_filter_action')) {
            $current_value = '';
        }
    }

    // Define basic fallback event types
    // (It's impractical to maintain exhaustive list of potential events)
    $fallback_event_types = [
        // Core system events
        'rule_execution' => __('Rule Execution', 'order-daemon'),
        'status_changed' => __('Status Changed', 'order-daemon'),
        'order_completion' => __('Order Completion', 'order-daemon'),
        'manual_status_change' => __('Manual Status Change', 'order-daemon'),
        'checkout_processed' => __('Checkout Processed', 'order-daemon'),
        'email_sent' => __('Email Sent', 'order-daemon'),
        'stock_adjusted' => __('Stock Adjusted', 'order-daemon'),
        'note_added' => __('Note Added', 'order-daemon'),

        // Payment gateway events (critical for users)
        // Stripe events - comprehensive coverage of common Stripe webhook events
        'payment.stripe.checkout_processed' => __('Stripe Checkout Processed', 'order-daemon'),
        'payment.stripe.payment_intent_succeeded' => __('Stripe Payment Succeeded', 'order-daemon'),
        'payment.stripe.payment_intent_created' => __('Stripe Payment Created', 'order-daemon'),
        'payment.stripe.payment_intent_payment_failed' => __('Stripe Payment Failed', 'order-daemon'),
        'payment.stripe.charge_succeeded' => __('Stripe Charge Succeeded', 'order-daemon'),
        'payment.stripe.charge_failed' => __('Stripe Charge Failed', 'order-daemon'),
        'payment.stripe.customer_subscription_created' => __('Stripe Subscription Created', 'order-daemon'),
        'payment.stripe.customer_subscription_updated' => __('Stripe Subscription Updated', 'order-daemon'),
        'payment.stripe.customer_subscription_deleted' => __('Stripe Subscription Cancelled', 'order-daemon'),
        'payment.stripe.invoice_payment_succeeded' => __('Stripe Invoice Payment Succeeded', 'order-daemon'),
        'payment.stripe.invoice_payment_failed' => __('Stripe Invoice Payment Failed', 'order-daemon'),
        'payment.stripe.charge_refunded' => __('Stripe Charge Refunded', 'order-daemon'),

        // PayPal events
        'payment.paypal.payment_captured' => __('PayPal Payment Captured', 'order-daemon'),
        'payment.paypal.order_completed' => __('PayPal Order Completed', 'order-daemon'),
        'payment.paypal.payment_completed' => __('PayPal Payment Completed', 'order-daemon'),
        'payment.paypal.payment_failed' => __('PayPal Payment Failed', 'order-daemon'),
        'payment.paypal.payment_pending' => __('PayPal Payment Pending', 'order-daemon'),
        'payment.paypal.payment_refunded' => __('PayPal Payment Refunded', 'order-daemon'),
        'payment.paypal.renewal_payment_completed' => __('PayPal Renewal Payment Completed', 'order-daemon'),
        'payment.paypal.subscription_cancelled' => __('PayPal Subscription Cancelled', 'order-daemon'),
        'payment.paypal.subscription_created' => __('PayPal Subscription Created', 'order-daemon'),
        'payment.paypal.subscription_reactivated' => __('PayPal Subscription Reactivated', 'order-daemon'),
        'payment.paypal.subscription_suspended' => __('PayPal Subscription Suspended', 'order-daemon'),

        // Square events
        'payment.square.charge_completed' => __('Square Charge Completed', 'order-daemon'),
        'payment.square.payment_processed' => __('Square Payment Processed', 'order-daemon'),

        // Webhook and external events
        'webhook_received' => __('Webhook Received', 'order-daemon'),
        'webhook_processed' => __('Webhook Processed', 'order-daemon'),

        // Error and warning events
        'error_occurred' => __('Error Occurred', 'order-daemon'),
        'validation_failed' => __('Validation Failed', 'order-daemon'),
        'action_failed' => __('Action Failed', 'order-daemon'),
    ];

    // Start with all event types option
    $event_types = [
        '' => __('All Event Types', 'order-daemon')
    ];

    // Try to fetch dynamic event types from API
    $use_fallback = false;

    if ($has_permission && class_exists('OrderDaemon\\CompletionManager\\API\\AuditLogEndpoint')) {
        try {
            $endpoint = new \OrderDaemon\CompletionManager\API\AuditLogEndpoint();
            $request = new \WP_REST_Request('GET', '/odcm/v1/audit-log/filter-options');
            $request->set_timeout(10);
            $request->set_param('context', 'filter');

            $response = $endpoint->get_filter_options($request);

            if (!is_wp_error($response) && isset($response->data['filter_options']['event_types'])) {
                foreach ($response->data['filter_options']['event_types'] as $event) {
                    // Skip internal-only events
                    $internal_events = ['rule_no_match', 'debug_', 'process_started'];
                    $is_internal = false;
                    foreach ($internal_events as $internal) {
                        if (strpos($event['value'], $internal) === 0) {
                            $is_internal = true;
                            break;
                        }
                    }
                    if (!$is_internal) {
                        $event_types[$event['value']] = $event['label'];
                    }
                }
            } else {
                $use_fallback = true;
            }
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ODCM: Failed to fetch dynamic event types: ' . $e->getMessage());
            }
            $use_fallback = true;
        }
    } else {
        $use_fallback = true;
    }

    // Use fallback if API call failed
    if ($use_fallback) {
        $event_types = array_merge($event_types, $fallback_event_types);
    }

    // Allow extensions to modify event types
    $event_types = apply_filters('odcm_event_type_filter_options', $event_types);

    // Sort alphabetically for better UX after filters applied for better ux
    ksort($event_types);

    // Render the dropdown
    echo '<select name="event_type" id="odcm-event-type-filter" class="regular-text"';

    if (!$has_permission) {
        echo ' disabled="disabled"';
    }

    echo '>';

    foreach ($event_types as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"';
        selected($current_value, $value);
        echo '>' . esc_html($label) . '</option>';
    }

    echo '</select>';
}


/**
 * Render Source Filter Dropdown
 * 
 * Renders a select dropdown with available log sources.
 * 
 * @since 1.0.0
 * @param array $filter Filter configuration array
 * @param bool $has_permission Whether user can use this filter
 * @param string $current_value Current filter value
 * @return void
 */
function odcm_render_source_filter(array $filter, bool $has_permission, string $current_value): void
{
    // Verify nonce if this is a form submission
    if (!empty($_REQUEST) && isset($_REQUEST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'odcm_audit_filter_action')) {
            // Invalid nonce, but we'll still show the filter - just won't process values
            $current_value = '';
        }
    }
    
    $sources = [
        ''          => __('admin.insight_dashboard.filters.status.all', 'order-daemon'),
        'manual'    => __('admin.insight_dashboard.filters.source.manual', 'order-daemon'),
        'scheduled' => __('admin.insight_dashboard.filters.source.scheduled', 'order-daemon'),
        'webhook'   => __('admin.insight_dashboard.filters.source.webhook', 'order-daemon'),
        'api'       => __('admin.insight_dashboard.filters.source.api', 'order-daemon'),
        'system'    => __('admin.insight_dashboard.filters.source.system', 'order-daemon'),
    ];
    
    echo '<select name="source" id="odcm-source-filter" class="regular-text"';
    
    if (!$has_permission) {
        echo ' disabled="disabled"';
    }
    
    echo '>';
    
    foreach ($sources as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"';
        selected($current_value, $value);
        echo '>' . esc_html($label) . '</option>';
    }
    
    echo '</select>';
}

// Register filters when this file is loaded
odcm_register_audit_filters();
