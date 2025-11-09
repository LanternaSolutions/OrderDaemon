<?php
declare(strict_types=1);

/**
 * Audit Log Filter Definitions - Entitlement-Aware Filter Registry
 *
 * This file contains all filter registrations for the audit log interface.
 * It follows the same pattern as options.php but is specifically designed
 * for audit log filtering capabilities.
 *
 * FILTER ARCHITECTURE:
 * ===================
 * 
 * Each filter is registered with:
 * - id: Unique identifier (e.g., 'date_range')
 * - label: Human-readable name for UI
 * - tier: Product tier ('free' or 'premium')
 * - capability: Entitlement key for access control
 * - render_callback: Function to render filter input UI
 * 
 * ENTITLEMENT INTEGRATION:
 * =======================
 * 
 * Filters are automatically integrated with the entitlement system:
 * - Free filters are always available
 * - Premium filters show PREMIUM badges
 * - Disabled state for users without access
 * - Server-side validation prevents bypass attempts
 * 
 * RENDER CALLBACKS:
 * ================
 * 
 * Each filter has a render callback that receives permission status:
 * - $has_permission (bool): Whether user can use this filter
 * - Callback should render appropriate UI (enabled/disabled)
 * - Premium filters should show upgrade prompts when disabled
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

    // ============================================================================
    // FREE TIER FILTERS - Available to all users
    // ============================================================================

    /**
     * Basic Search Filter
     * 
     * Provides text-based search across log summaries, event types, and sources.
     * This is the core search functionality available to all users.
     */
    $registry->register_filter([
        'id'              => 'basic_search',
        'label'           => __('core.audit_filters.search.label', 'order-daemon'),
        'tier'            => 'free',
        'capability'      => 'audit_log_basic_search',
        'render_callback' => 'odcm_render_basic_search_filter',
    ]);

    // ============================================================================
    // PREMIUM TIER FILTERS - Require premium license
    // ============================================================================

    /**
     * Date Range Filter
     * 
     * Allows filtering by specific date ranges using date pickers.
     * Premium feature that provides precise temporal filtering.
     */
    $registry->register_filter([
        'id'              => 'date_range',
        'label'           => __('core.audit_filters.date_range.label', 'order-daemon'),
        'tier'            => 'premium',
        'capability'      => 'audit_log_filter_advanced',
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
        'label'           => __('core.audit_filters.status.label', 'order-daemon'),
        'tier'            => 'premium',
        'capability'      => 'audit_log_filter_advanced',
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
        'label'           => __('core.audit_filters.event_type.label', 'order-daemon'),
        'tier'            => 'premium',
        'capability'      => 'audit_log_filter_advanced',
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
        'label'           => __('core.audit_filters.source.label', 'order-daemon'),
        'tier'            => 'premium',
        'capability'      => 'audit_log_filter_advanced',
        'render_callback' => 'odcm_render_source_filter',
    ]);
}

// ============================================================================
// FILTER RENDER CALLBACKS
// ============================================================================

/**
 * Render Basic Search Filter Input
 * 
 * Renders a simple text input for basic search functionality.
 * Always enabled for free users.
 * 
 * @since 1.0.0
 * @param array $filter Filter configuration array
 * @param bool $has_permission Whether user can use this filter
 * @param string $current_value Current filter value
 * @return void
 */
function odcm_render_basic_search_filter(array $filter, bool $has_permission, string $current_value): void
{
    echo '<input type="text" ';
    echo 'name="s" ';
    echo 'id="odcm-search-input" ';
    echo 'value="' . esc_attr($current_value) . '" ';
    echo 'placeholder="' . esc_attr__('core.audit_filters.search.placeholder', 'order-daemon') . '" ';
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
 * Shows as disabled for free users.
 * 
 * @since 1.0.0
 * @param array $filter Filter configuration array
 * @param bool $has_permission Whether user can use this filter
 * @param string $current_value Current filter value (not used for date range)
 * @return void
 */
function odcm_render_date_range_filter(array $filter, bool $has_permission, string $current_value): void
{
    $date_from = isset($_GET['date_start']) ? sanitize_text_field($_GET['date_start']) : '';
    $date_to = isset($_GET['date_end']) ? sanitize_text_field($_GET['date_end']) : '';
    
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
    
    echo '<span class="odcm-date-separator">' . esc_html__('core.audit_filters.date_range.to', 'order-daemon') . '</span>';
    
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
    $statuses = [
        ''        => __('core.audit_filters.status.all', 'order-daemon'),
        'success' => __('core.audit_filters.status.success', 'order-daemon'),
        'error'   => __('core.audit_filters.status.error', 'order-daemon'),
        'warning' => __('core.audit_filters.status.warning', 'order-daemon'),
        'info'    => __('core.audit_filters.status.info', 'order-daemon'),
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
    $event_types = [
        ''                    => __('core.audit_filters.event_type.all', 'order-daemon'),
        'rule_check'          => __('core.audit_filters.event_type.rule_check', 'order-daemon'),
        'order_completion'    => __('core.audit_filters.event_type.order_completion', 'order-daemon'),
        'manual_trigger'      => __('core.audit_filters.event_type.manual_trigger', 'order-daemon'),
        'scheduled_task'      => __('core.audit_filters.event_type.scheduled_task', 'order-daemon'),
        'webhook_received'    => __('core.audit_filters.event_type.webhook_received', 'order-daemon'),
        'error_occurred'      => __('core.audit_filters.event_type.error_occurred', 'order-daemon'),
    ];
    
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
    $sources = [
        ''          => __('core.audit_filters.source.all', 'order-daemon'),
        'manual'    => __('core.audit_filters.source.manual', 'order-daemon'),
        'scheduled' => __('core.audit_filters.source.scheduled', 'order-daemon'),
        'webhook'   => __('core.audit_filters.source.webhook', 'order-daemon'),
        'api'       => __('core.audit_filters.source.api', 'order-daemon'),
        'system'    => __('core.audit_filters.source.system', 'order-daemon'),
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
