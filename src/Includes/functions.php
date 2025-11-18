<?php

declare(strict_types=1);

/**
 * Global Helper Functions - Entitlement System & Core Utilities
 *
 * This file contains globally available helper functions that power the
 * Order Daemon For Woocommerce plugin. It serves as the foundation for
 * the plugin's entitlement-aware architecture and provides essential
 * utilities used throughout the codebase.
 *
 * ENTITLEMENT SYSTEM OVERVIEW:
 * ============================
 * 
 * The plugin uses a capability-based entitlement system that controls access
 * to features based on user licensing. This creates a seamless freemium
 * experience where features are dynamically enabled/disabled.
 * 
 * Key Components:
 * - odcm_can_use(): Central entitlement checking function
 * - Capability keys: Unique identifiers for each feature
 * - Tier-based access: Free, Premium, and future Enterprise tiers
 * - Development mode: Testing toggle for premium features
 * 
 * ARCHITECTURE PRINCIPLES:
 * =======================
 * 
 * 1. Single Source of Truth: All entitlement logic in odcm_can_use()
 * 2. Fail-Safe Defaults: Unknown features default to restricted
 * 3. Performance First: Minimal overhead for capability checks
 * 4. Future-Proof: Easy to extend for new licensing systems
 * 5. Developer-Friendly: Clear naming and comprehensive documentation
 * 
 * INTEGRATION POINTS:
 * ==================
 * 
 * - OptionRegistry: Each registered option has a capability key
 * - MetaBox UI: Dynamic rendering based on user entitlements
 * - Core Logic: Feature execution gated by capability checks
 * - Admin Interface: Premium features shown as upgrade opportunitiese
 * 
 * DEVELOPMENT WORKFLOW:
 * ====================
 * 
 * 1. Add new capability to odcm_can_use() switch statement
 * 2. Register option with capability in options.php
 * 3. Check capability in UI rendering (MetaBox.php)
 * 4. Gate feature execution in business logic
 * 5. Test with both free and premium scenarios
 *
 * @package OrderDaemon\CompletionManager\Includes
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/entitlements
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}


/**
 * Central Entitlement Function - The Heart of the Freemium System
 *
 * This function serves as the single source of truth for all feature access control
 * in the Order Daemon For Woocommerce plugin. It implements a capability-based
 * entitlement system that creates a seamless freemium experience by dynamically
 * enabling/disabling features based on user licensing.
 *
 * ENTITLEMENT ARCHITECTURE:
 * ========================
 * 
 * The system uses a three-tier approach:
 * 1. FREE TIER: Core functionality available to all users
 * 2. PREMIUM TIER: Advanced features requiring paid license
 * 3. ENTERPRISE TIER: Future expansion for high-end features
 * 
 * Each feature is identified by a unique capability key that follows naming
 * conventions to ensure consistency and maintainability.
 * 
 * CAPABILITY NAMING CONVENTIONS:
 * =============================
 * 
 * Format: {type}_{feature}[_{modifier}]
 * 
 * Types:
 * - trigger_*    : When rules should be evaluated
 * - condition_*  : What criteria must be met
 * - action_*     : What happens when rules apply
 * - {feature}_*  : General plugin features
 * 
 * Examples:
 * - trigger_basic           : Basic order status triggers (FREE)
 * - trigger_payment_complete: Advanced payment triggers (PREMIUM)
 * - condition_product_type  : Product type conditions (FREE)
 * - condition_customer_role : Customer role conditions (PREMIUM)
 * - action_basic           : Basic order completion (FREE)
 * - action_send_custom_email: Custom email actions (PREMIUM)
 * - unlimited_rules        : Multiple active rules (PREMIUM)
 * 
 * DEVELOPMENT MODE:
 * ================
 * 
 * For testing premium features during development, add this to wp-config.php:
 * define('ODCM_IS_PREMIUM_DEBUG', true);
 * 
 * This allows developers to test the full feature set without a license.
 * 
 * LICENSING SYSTEM INTEGRATION:
 * ============================
 * 
 * This function is designed to be the only place that needs modification when
 * integrating with real licensing systems. The current implementation uses a
 * simple boolean check, but it can easily accommodate:
 * 
 * - Easy Digital Downloads Software Licensing
 * - Freemius SDK
 * - Custom licensing APIs
 * - Subscription-based models
 * - Time-limited trials
 * 
 * PERFORMANCE CONSIDERATIONS:
 * ==========================
 * 
 * - Function is called frequently during UI rendering
 * - Uses simple switch statement for O(1) lookup performance
 * - No database queries or external API calls
 * - Results can be cached if needed for heavy usage
 * 
 * SECURITY CONSIDERATIONS:
 * =======================
 * 
 * - Unknown capabilities default to false (fail-safe)
 * - No user input validation needed (internal function)
 * - Capability keys are hardcoded (no injection risk)
 * - Debug mode only works with wp-config.php access
 *
 * @since 1.0.0
 *
 * @param string $feature_key {
 *     The unique capability identifier for the feature to check.
 *     Must match one of the predefined capability keys in the switch statement.
 *     
 *     Free Tier Capabilities:
 *     - 'trigger_basic'          : Basic order status triggers
 *     - 'condition_product_type' : Product type conditions
 *     - 'condition_single_category': Single category selection
 *     - 'condition_order_total'  : Order total conditions
 *     - 'action_basic'          : Basic order completion
 *     - 'insight_dashboard'      : Insight dashboard access
 *     
 *     Premium Tier Capabilities:
 *     - 'unlimited_rules'        : Multiple active rules
 *     - 'trigger_on_hold'        : On-hold status triggers
 *     - 'trigger_payment_complete': Payment completion triggers
 *     - 'condition_multi_category': Multiple category selection
 *     - 'condition_customer_role' : Customer role conditions
 *     - 'condition_payment_gateway': Payment gateway conditions
 *     - 'condition_shipping_method': Shipping method conditions
 *     - 'condition_coupon_used'  : Coupon usage conditions
 *     - 'action_send_custom_email': Custom email actions
 *     - 'action_add_custom_note' : Custom order note actions
 *     - 'log_management_settings': Advanced logging controls
 * }
 *
 * @return bool True if the user can access the feature, false otherwise.
 *              Defaults to false for unknown capability keys (fail-safe).
 *
 * @example
 * ```php
 * // Basic usage - check if user can access a feature
 * if (odcm_can_use('unlimited_rules')) {
 *     // User has premium license - allow multiple rules
 *     $this->create_additional_rule();
 * } else {
 *     // Free user - show upgrade prompt
 *     $this->show_upgrade_notice();
 * }
 * 
 * // UI rendering - show/hide features based on entitlements
 * $conditions = $registry->get_conditions();
 * foreach ($conditions as $condition) {
 *     if (odcm_can_use($condition['capability'])) {
 *         // Render functional UI element
 *         echo $this->render_condition_input($condition);
 *     } else {
 *         // Render locked/premium placeholder
 *         echo $this->render_premium_placeholder($condition);
 *     }
 * }
 * 
 * // Feature execution - gate business logic
 * public function execute_custom_email_action($order_id) {
 *     if (!odcm_can_use('action_send_custom_email')) {
 *         throw new Exception('Premium feature not available');
 *     }
 *     
 *     // Execute premium functionality
 *     $this->send_custom_email($order_id);
 * }
 * 
 * // Development testing - enable premium features
 * // Add to wp-config.php: define('ODCM_IS_PREMIUM_DEBUG', true);
 * if (odcm_can_use('condition_customer_role')) {
 *     // This will return true in debug mode
 *     echo 'Premium feature available for testing';
 * }
 * ```
 */
function odcm_can_use(string $feature_key): bool
{
    // CLI Development Bypass - Enable premium features for CLI testing
    // This allows developers to test CLI features without licensing constraints
    if ((defined('ODCM_CLI_DEV_BYPASS') && ODCM_CLI_DEV_BYPASS) && 
        (defined('WP_CLI') && WP_CLI)) {
        // Allow all premium features when in CLI dev bypass mode
        if (in_array($feature_key, [
            'config_management',
            'unlimited_rules',
            'trigger_on_hold',
            'trigger_payment_complete',
            'trigger_pending',
            'trigger_failed',
            'trigger_cancelled',
            'trigger_refunded',
            'trigger_completed',
            'trigger_premium',
            'action_custom_redirect',
            'action_add_order_note',
            'action_send_email',
            'action_change_status_to_processing',
            'action_change_status_to_on_hold',
            'log_management_settings',
            'audit_log_filter_advanced',
            'audit_log_export',
            'audit_log_retention_control',
            'premium_features',
            'condition_multi_category',
            'condition_user_role',
            'condition_payment_gateway',
            'condition_shipping_method',
            'condition_coupon_used',
            'condition_product_type_advanced',
            'condition_specific_products',
            'condition_order_item_count'
        ])) {
            return true;
        }
    }
    
    // Premium access is controlled entirely through the 'odcm_is_premium_user' filter.
    // The DevToolbar and real licensing systems hook into this filter to enable premium features.
    // Default: FREE mode (false) when no filter is applied.
    $is_premium_user = apply_filters('odcm_is_premium_user', false);

    // Feature capability switch
    switch ($feature_key) {
        // --- PREMIUM TIER 1: Features requiring any premium license ---
        case 'unlimited_rules':
        case 'trigger_on_hold':
        case 'trigger_payment_complete':
        case 'trigger_pending':
        case 'trigger_failed':
        case 'trigger_cancelled':
        case 'trigger_refunded':
        case 'trigger_completed':
        case 'trigger_premium':             // Any Status Change trigger (premium)
        case 'action_custom_redirect':
        case 'action_add_order_note':
        case 'action_send_email':
        case 'action_change_status_to_processing': // Premium primary action: Processing
        case 'action_change_status_to_on_hold':    // Premium primary action: On Hold
        case 'log_management_settings':
        case 'audit_log_filter_advanced':   // Advanced filtering (date range, status, etc.)
        case 'audit_log_export':            // Export functionality
        case 'audit_log_retention_control': // Custom retention settings
        case 'premium_features':            // General premium component access
        return $is_premium_user;

            break;

        // --- PREMIUM TIER 2: Example of a more granular feature ---
        // This case demonstrates future-proofing for different license tiers.
        case 'condition_multi_category':
        case 'condition_user_role':
        case 'condition_payment_gateway':
        case 'condition_shipping_method':
        case 'condition_coupon_used':
        case 'condition_product_type_advanced': // All product types beyond Virtual/Downloadable
        case 'condition_specific_products':
        case 'condition_order_item_count':
            // For now, this is also tied to the simple premium check.
            // In the future, this is where you would add a check for a specific license plan.
        return $is_premium_user;

            break;

        // --- FREE TIER: Features available to everyone ---
        // These cases explicitly return true for clarity.
        case 'condition_product_type':
        case 'condition_single_category':
        case 'condition_order_total':
        case 'trigger_basic':
        case 'action_basic':
        case 'insight_dashboard':          // Basic dashboard access (renamed from audit_trail_ui)
        case 'audit_log_basic_search':
        case 'audit_log_bulk_actions':
        return true;

            break;

        // --- Default Case ---
        // If an unknown feature key is passed, default to false for security.
        default:
        return false;
    }//end switch

}//end odcm_can_use()




/**
 * Helper function to check user capability and handle permission denied responses.
 *
 * This function provides a centralized way to check user capabilities and handle
 * permission denied scenarios consistently across the plugin.
 *
 * @since 1.0.0
 *
 * @param  string $capability The capability to check (default: 'manage_woocommerce').
 * @param  string $context    The context for the check ('ajax', 'admin_page', 'form_handler').
 * @param  string $message    Optional custom permission denied message.
 * @return boolean True if user has capability, false otherwise (and handles response based on context).
 */
function odcm_check_user_capability(string $capability='manage_woocommerce', string $context='admin_page', string $message=''): bool
{
    if (current_user_can($capability)) {
        return true;
    }

    // Set default message if none provided
    if (empty($message)) {
        $message = __('security.permission_denied', 'order-daemon');
    }

    // Handle response based on context
    switch ($context) {
        case 'ajax':
            wp_send_json_error(['message' => $message]);
            wp_die();
        break;

        case 'admin_page':
            wp_die(esc_html($message));
        break;

        case 'form_handler':
            // For form handlers, just return false and let the caller handle it
        return false;
    }

    return false;

}//end odcm_check_user_capability()




/**
 * Helper function to schedule Action Scheduler tasks with duplicate prevention.
 *
 * This function provides a centralized way to schedule Action Scheduler tasks
 * with built-in duplicate prevention and debug mode support.
 *
 * @since 1.0.0
 *
 * @param  integer       $order_id         The order ID to process.
 * @param  string        $hook             The hook name for the scheduled action.
 * @param  boolean       $check_duplicates Whether to check for existing scheduled actions.
 * @param  callable|null $debug_callback   Optional callback to execute in debug mode.
 * @return boolean True if action was scheduled, false otherwise.
 */
function odcm_schedule_action(int $order_id, string $hook='odcm_process_order_check', bool $check_duplicates=false, ?callable $debug_callback=null): bool
{
    // Ensure Action Scheduler functions exist
    if (!function_exists('as_schedule_single_action')) {
        return false;
    }

    $args = ['order_id' => $order_id];

    // Check for duplicates if requested
    if ($check_duplicates) {
        $scheduled_actions = as_get_scheduled_actions(
            [
                'hook'   => $hook,
                'args'   => $args,
                'status' => 'pending',
            ]
        );

        if (!empty($scheduled_actions)) {
            return false;
            // Action already scheduled
        }
    }

    // Schedule the action
    as_schedule_single_action(
        time(),
        $hook,
        $args,
        'completion-manager'
    );

    // Execute immediately in debug mode
    if (defined('ODCM_DEBUG') && ODCM_DEBUG && $debug_callback && is_callable($debug_callback)) {
        call_user_func($debug_callback, $order_id);
    }

    return true;

}//end odcm_schedule_action()


/**
 * Helper function to create debug-gated log messages with consistent formatting.
 *
 * This function provides a centralized way to create log messages with
 * consistent formatting and debug-gating across the plugin.
 *
 * @since 1.0.0
 *
 * @param  string $message The log message.
 * @param  string $level   The log level ('error', 'success', 'notice', 'info').
 * @return void
 */
function odcm_log_message(string $message, string $level='notice'): void
{
    if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
        return;
    }

    $level_prefixes = [
        'error'   => '[ODCM ERROR]',
        'success' => '[ODCM SUCCESS]',
        'notice'  => '[ODCM NOTICE]',
        'info'    => '[ODCM INFO]',
    ];

    $prefix = ($level_prefixes[$level] ?? '[ODCM NOTICE]');
    error_log("{$prefix} {$message}");

}//end odcm_log_message()


/**
 * Get the Global OptionRegistry Instance - Central Hub for Entitlement-Aware Options
 *
 * This function provides global access to the OptionRegistry singleton instance,
 * which serves as the central hub for all triggers, conditions, and actions in
 * the entitlement system. It implements the singleton pattern to ensure all
 * parts of the plugin work with the same registry data.
 *
 * ARCHITECTURAL ROLE:
 * ==================
 * 
 * The OptionRegistry is the cornerstone of the plugin's entitlement-aware
 * architecture. It bridges the gap between:
 * 
 * 1. Option Registration (options.php) - Where features are defined
 * 2. Entitlement Checking (odcm_can_use()) - Where access is controlled
 * 3. UI Rendering (MetaBox.php) - Where features are displayed
 * 4. Business Logic (Executor.php) - Where features are executed
 * 
 * SINGLETON PATTERN:
 * =================
 * 
 * Uses static variable to ensure only one registry instance exists throughout
 * the plugin lifecycle. This guarantees:
 * - Consistent data across all plugin components
 * - No duplicate registrations
 * - Efficient memory usage
 * - Predictable behavior
 * 
 * USAGE PATTERNS:
 * ==============
 * 
 * 1. Option Registration (typically in options.php):
 *    $registry = odcm_get_registry_instance();
 *    $registry->register_condition([...]);
 * 
 * 2. UI Rendering (typically in MetaBox.php):
 *    $registry = odcm_get_registry_instance();
 *    $conditions = $registry->get_conditions();
 * 
 * 3. Feature Validation (anywhere in the plugin):
 *    $registry = odcm_get_registry_instance();
 *    $triggers = $registry->get_triggers();
 * 
 * INTEGRATION WITH ENTITLEMENT SYSTEM:
 * ====================================
 * 
 * The registry works seamlessly with the entitlement system:
 * - Each registered option has a capability key
 * - UI components use odcm_can_use() to check access
 * - Features are dynamically shown/hidden based on user license
 * - No code changes needed when user upgrades
 * 
 * PERFORMANCE CONSIDERATIONS:
 * ==========================
 * 
 * - Singleton pattern prevents multiple instantiations
 * - Registry data is stored in memory (no database queries)
 * - Options are registered once during plugin initialization
 * - Retrieval operations are simple array access (O(1))
 * 
 * THREAD SAFETY:
 * =============
 * 
 * WordPress is single-threaded, so no additional synchronization is needed.
 * The static variable provides sufficient isolation for the singleton pattern.
 *
 * @since 1.0.0
 *
 * @return \OrderDaemon\CompletionManager\Core\OptionRegistry {
 *     The singleton OptionRegistry instance containing all registered options.
 *     
 *     The returned instance provides these methods:
 *     - register_trigger(array $args): void
 *     - register_condition(array $args): void  
 *     - register_action(array $args): void
 *     - get_triggers(): array
 *     - get_conditions(): array
 *     - get_actions(): array
 * }
 *
 * @example
 * ```php
 * // Basic usage - get registry and register an option
 * $registry = odcm_get_registry_instance();
 * $registry->register_condition([
 *     'id'              => 'my_condition',
 *     'label'           => __('My Condition', 'domain'),
 *     'description'     => __('A custom condition.', 'domain'),
 *     'capability'      => 'condition_my_condition',
 *     'render_callback' => [$this, 'render_my_condition'],
 * ]);
 * 
 * // UI rendering - get options and render based on entitlements
 * $registry = odcm_get_registry_instance();
 * $conditions = $registry->get_conditions();
 * 
 * foreach ($conditions as $condition) {
 *     if (odcm_can_use($condition['capability'])) {
 *         // User can access this condition
 *         echo '<input type="radio" value="' . esc_attr($condition['id']) . '">';
 *         echo esc_html($condition['label']);
 *     } else {
 *         // Show as premium feature
 *         echo '<span class="premium">' . esc_html($condition['label']) . ' (Premium)</span>';
 *     }
 * }
 * 
 * // Validation - check if a specific option exists
 * $registry = odcm_get_registry_instance();
 * $triggers = $registry->get_triggers();
 * 
 * if (isset($triggers['my_trigger'])) {
 *     // Trigger is registered and available
 *     $trigger_data = $triggers['my_trigger'];
 * }
 * 
 * // Multiple calls return the same instance (singleton)
 * $registry1 = odcm_get_registry_instance();
 * $registry2 = odcm_get_registry_instance();
 * // $registry1 === $registry2 (same object)
 * ```
 */
function odcm_get_registry_instance(): \OrderDaemon\CompletionManager\Core\OptionRegistry
{
    static $instance = null;

    if ($instance === null) {
        $instance = new \OrderDaemon\CompletionManager\Core\OptionRegistry();
    }

    return $instance;

}//end odcm_get_registry_instance()


/**
 * Get the Global FilterRegistry Instance - Central Hub for Entitlement-Aware Audit Log Filters
 *
 * This function provides global access to the FilterRegistry singleton instance,
 * which serves as the central hub for all audit log filters in the entitlement
 * system. It implements the singleton pattern to ensure all parts of the plugin
 * work with the same registry data.
 *
 * ARCHITECTURAL ROLE:
 * ==================
 * 
 * The FilterRegistry is a specialized component of the plugin's entitlement-aware
 * architecture. It bridges the gap between:
 * 
 * 1. Filter Registration (audit-filters.php) - Where filters are defined
 * 2. Entitlement Checking (odcm_can_use()) - Where access is controlled
 * 3. UI Rendering (AuditTrailListTable.php) - Where filters are displayed
 * 4. Query Processing (get_logs()) - Where filters are applied
 * 
 * SINGLETON PATTERN:
 * =================
 * 
 * Uses static variable to ensure only one registry instance exists throughout
 * the plugin lifecycle. This guarantees:
 * - Consistent filter data across all plugin components
 * - No duplicate filter registrations
 * - Efficient memory usage
 * - Predictable behavior
 * 
 * USAGE PATTERNS:
 * ==============
 * 
 * 1. Filter Registration (typically in audit-filters.php):
 *    $registry = odcm_get_filter_registry_instance();
 *    $registry->register_filter([...]);
 * 
 * 2. UI Rendering (typically in AuditTrailListTable.php):
 *    $registry = odcm_get_filter_registry_instance();
 *    $filters = $registry->get_filters();
 * 
 * 3. Feature Validation (anywhere in the plugin):
 *    $registry = odcm_get_filter_registry_instance();
 *    $date_filter = $registry->get_filter('date_range');
 * 
 * INTEGRATION WITH ENTITLEMENT SYSTEM:
 * ====================================
 * 
 * The registry works seamlessly with the entitlement system:
 * - Each registered filter has a capability key and tier designation
 * - UI components use odcm_can_use() to check access
 * - Filters are dynamically enabled/disabled based on user license
 * - Premium filters show PREMIUM badges when user lacks access
 * - No code changes needed when user upgrades
 * 
 * PERFORMANCE CONSIDERATIONS:
 * ==========================
 * 
 * - Singleton pattern prevents multiple instantiations
 * - Registry data is stored in memory (no database queries)
 * - Filters are registered once during plugin initialization
 * - Retrieval operations are simple array access (O(1))
 * 
 * THREAD SAFETY:
 * =============
 * 
 * WordPress is single-threaded, so no additional synchronization is needed.
 * The static variable provides sufficient isolation for the singleton pattern.
 *
 * @since 1.0.0
 *
 * @return \OrderDaemon\CompletionManager\Core\FilterRegistry {
 *     The singleton FilterRegistry instance containing all registered filters.
 *     
 *     The returned instance provides these methods:
 *     - register_filter(array $args): void
 *     - get_filters(): array
 *     - get_filter(string $filter_id): ?array
 *     - has_filter(string $filter_id): bool
 *     - get_filters_by_tier(string $tier): array
 * }
 *
 * @example
 * ```php
 * // Basic usage - get registry and register a filter
 * $registry = odcm_get_filter_registry_instance();
 * $registry->register_filter([
 *     'id'              => 'date_range',
 *     'label'           => __('Date Range', 'domain'),
 *     'tier'            => 'premium',
 *     'capability'      => 'audit_log_filter_advanced',
 *     'render_callback' => [$this, 'render_date_range_filter'],
 * ]);
 * 
 * // UI rendering - get filters and render based on entitlements
 * $registry = odcm_get_filter_registry_instance();
 * $filters = $registry->get_filters();
 * 
 * foreach ($filters as $filter) {
 *     $has_permission = odcm_can_use($filter['capability']);
 *     
 *     echo '<div class="filter-container">';
 *     echo '<label>' . esc_html($filter['label']);
 *     
 *     if ($filter['tier'] === 'premium') {
 *         echo ' <span class="premium-badge">PREMIUM</span>';
 *     }
 *     
 *     echo '</label>';
 *     
 *     // Call the render callback with permission status
 *     call_user_func($filter['render_callback'], $has_permission);
 *     
 *     echo '</div>';
 * }
 * 
 * // Validation - check if a specific filter exists
 * $registry = odcm_get_filter_registry_instance();
 * 
 * if ($registry->has_filter('date_range')) {
 *     // Date range filter is available
 *     $filter = $registry->get_filter('date_range');
 * }
 * 
 * // Get filters by tier for separate rendering
 * $free_filters = $registry->get_filters_by_tier('free');
 * $premium_filters = $registry->get_filters_by_tier('premium');
 * 
 * // Multiple calls return the same instance (singleton)
 * $registry1 = odcm_get_filter_registry_instance();
 * $registry2 = odcm_get_filter_registry_instance();
 * // $registry1 === $registry2 (same object)
 * ```
 */
function odcm_get_filter_registry_instance(): \OrderDaemon\CompletionManager\Core\FilterRegistry
{
    static $instance = null;

    if ($instance === null) {
        $instance = new \OrderDaemon\CompletionManager\Core\FilterRegistry();
    }

    return $instance;

}//end odcm_get_filter_registry_instance()


/**
 * Internal Registry-Based Logging Function - Core Plugin Event Logger
 *
 * This function serves as the primary logging mechanism for the Order Daemon plugin's
 * internal events. It implements the Registry Pattern to provide structured, consistent,
 * and maintainable logging for all known event types defined in the event registry.
 *
 * REGISTRY INTEGRATION:
 * ====================
 * 
 * This function works exclusively with events defined in odcm_get_log_event_types().
 * It validates the event slug against the registry and uses the event's metadata
 * to generate consistent log entries with proper categorization and formatting.
 * 
 * DEBUG MODE INTEGRATION:
 * ======================
 * 
 * Events with category 'debug' are only logged when ODCM_DEBUG is true. This
 * prevents verbose developer logging from cluttering production audit trails
 * while maintaining full debugging capabilities during development.
 * 
 * DYNAMIC SUMMARY GENERATION:
 * ===========================
 * 
 * Uses sprintf() with the event's summary_template to generate dynamic summaries
 * from context data. This ensures consistent messaging while allowing for
 * contextual information like order IDs, rule names, etc.
 * 
 * ASYNCHRONOUS PROCESSING:
 * =======================
 * 
 * Delegates to the existing odcm_log_event() function to maintain compatibility
 * with the current Action Scheduler-based asynchronous logging architecture.
 * This preserves performance while adding the new registry-based structure.
 * 
 * SECURITY CONSIDERATIONS:
 * =======================
 * 
 * - Event slugs are validated against a known registry (no injection risk)
 * - Context data is passed through existing sanitization in odcm_log_event()
 * - Debug mode check prevents information leakage in production
 * - Function is internal-only (not exposed to third-party code)
 *
 * @since 1.0.0
 *
 * @param string $event_slug {
 *     The unique event identifier that must exist in the event registry.
 *     Must match a key in the array returned by odcm_get_log_event_types().
 *     
 *     Examples:
 *     - 'order_completed'
 *     - 'rule_matched'
 *     - 'invalid_order'
 *     - 'process_order_check_start' (debug event)
 * }
 * @param array $context_data {
 *     Associative array of data used to populate the summary template and
 *     provide additional context for the log entry.
 *     
 *     Common keys:
 *     - 'order_id': (int) WooCommerce order ID
 *     - 'rule_name': (string) Name of the completion rule
 *     - 'error_message': (string) Error details for failure events
 *     - 'user_id': (int) WordPress user ID
 *     - 'payload': (array) Additional structured data
 *     
 *     The array values are used with sprintf() to populate the summary template.
 *     Order matters - values are used positionally with template placeholders.
 * }
 *
 * @return bool True if the event was successfully queued for logging, false on failure.
 *              Returns false immediately for debug events when debug mode is disabled.
 *
 * @example
 * ```php
 * // Log a successful order completion
 * odcm_log_event(
 *     'Order #123 completed successfully',
 *     ['completion_time' => time()],
 *     123,
 *     'success',
 *     'order_completed'
 * );
 * 
 * // Log a rule match with context
 * odcm_log_event(
 *     'Order #456 matched completion rule: Virtual Products Auto-Complete',
 *     ['rule_id' => 789],
 *     456,
 *     'info',
 *     'rule_matched'
 * );
 * 
 * // Debug event
 * odcm_log_event(
 *     'Starting order check process for order #789',
 *     ['trigger' => 'woocommerce_order_status_processing'],
 *     789,
 *     'debug',
 *     'process_order_check_start'
 * );
 * 
 * // Basic event logging
 * $result = odcm_log_event('Custom plugin action completed');
 * // Result: true (if Action Scheduler is available)
 * ```
 */

/**
 * Public Custom Event Logging API - Third-Party Developer Interface
 *
 * This function provides a simple, flexible, and well-documented API for third-party
 * developers to log custom events to the Order Daemon audit trail system. It offers
 * full access to the status registry while maintaining the 'custom' log category
 * for proper categorization and UI treatment.
 *
 * DESIGN PHILOSOPHY:
 * =================
 * 
 * This function is designed to be the public face of the logging system for
 * external developers. It prioritizes:
 * - Simplicity: Clear, intuitive parameter structure
 * - Flexibility: Support for all status types and optional parameters
 * - Validation: Robust input validation with sensible defaults
 * - Integration: Full access to the existing status registry
 * - Documentation: Comprehensive examples and usage patterns
 * 
 * STATUS REGISTRY INTEGRATION:
 * ===========================
 * 
 * Unlike the internal logging function, this API validates the status parameter
 * against the full status registry (odcm_get_log_statuses()). This ensures:
 * - Third-party events get proper UI styling and treatment
 * - Consistent status handling across all log entries
 * - Automatic fallback to 'info' for invalid statuses
 * - Full access to all available status types
 * 
 * CATEGORIZATION:
 * ==============
 * 
 * All events logged through this function are automatically assigned the
 * 'custom' log category. This enables:
 * - Clear distinction between plugin and third-party events
 * - Proper filtering and UI treatment
 * - Consistent audit trail organization
 * - Future extensibility for custom event management
 * 
 * ASYNCHRONOUS PROCESSING:
 * =======================
 * 
 * Like the internal logging function, this API delegates to odcm_log_event()
 * to maintain compatibility with the existing Action Scheduler-based
 * asynchronous logging architecture. This ensures consistent performance
 * and reliability regardless of the logging source.
 * 
 * SECURITY CONSIDERATIONS:
 * =======================
 * 
 * - All parameters are validated and sanitized
 * - Status validation prevents invalid CSS class injection
 * - Summary text is passed through existing sanitization
 * - No direct database access (uses existing secure pipeline)
 * - Payload data is handled by existing sanitization functions
 *
 * @since 1.0.0
 *
 * @param string $summary {
 *     A brief, human-readable summary of the event.
 *     This will be displayed as the main log entry text in the audit trail.
 *     
 *     Guidelines:
 *     - Keep concise but descriptive (recommended: 50-100 characters)
 *     - Use active voice ("User updated settings" vs "Settings were updated")
 *     - Include relevant context (order IDs, user names, etc.)
 *     - Avoid sensitive information (passwords, API keys, etc.)
 *     
 *     Examples:
 *     - "Custom integration processed order #123"
 *     - "Third-party plugin updated customer data"
 *     - "External API sync completed successfully"
 * }
 * @param array|null $payload {
 *     Optional associative array of additional structured data related to the event.
 *     This data is stored separately and can be viewed in the audit trail details.
 *     
 *     Common use cases:
 *     - API response data
 *     - Configuration changes
 *     - Error details and stack traces
 *     - Performance metrics
 *     - Integration-specific metadata
 *     
 *     The payload will be JSON-encoded and stored in the payloads table.
 *     Avoid including large binary data or circular references.
 * }
 * @param int|null $order_id {
 *     Optional WooCommerce order ID to associate with this event.
 *     When provided, the event will appear in order-specific audit trail views
 *     and can be filtered by order ID in the admin interface.
 *     
 *     Use cases:
 *     - Order processing events
 *     - Payment gateway interactions
 *     - Shipping integrations
 *     - Customer communication events
 * }
 * @param string $status {
 *     The status/severity level of the event. Must be a valid status from the
 *     status registry (odcm_get_log_statuses()). Invalid statuses will be
 *     automatically converted to 'info' with a warning logged.
 *     
 *     Available statuses:
 *     - 'success': Successful operations, completions
 *     - 'error': Failures, exceptions, critical issues
 *     - 'warning': Non-critical issues, deprecation notices
 *     - 'info': General information, status updates
 *     - 'notice': Important notifications, reminders
 *     - 'debug': Development/troubleshooting information
 *     - 'critical': System-critical failures
 *     - 'pending': Operations in progress
 *     - 'skipped': Intentionally bypassed operations
 *     - 'completed': Finished processes, final states
 * }
 * @param string|null $event_type {
 *     Optional custom event type identifier for categorization and filtering.
 *     If not provided, defaults to 'custom_event' for generic third-party events.
 *     
 *     Naming conventions:
 *     - Use lowercase with underscores: 'my_plugin_sync'
 *     - Include plugin/integration name: 'mailchimp_subscriber_update'
 *     - Be descriptive but concise: 'payment_gateway_webhook'
 *     
 *     This field is used for:
 *     - Filtering events in the admin interface
 *     - Grouping related events
 *     - Integration-specific reporting
 *     - Debugging and troubleshooting
 * }
 *
 * @return bool True if the event was successfully queued for logging, false on failure.
 *              Failure can occur due to missing Action Scheduler or system errors.
 *
 * @example
 * ```php
 * // Basic usage - log a simple event
 * odcm_log_event('My plugin performed an action');
 *
 * // Log with status and order association
 * odcm_log_event(
 *     'Payment gateway webhook processed',
 *     null,
 *     123, // order_id
 *     'success'
 * );
 * 
 * // Full usage with all parameters
 * odcm_log_event(
 *     'External API sync completed',
 *     [
 *         'api_endpoint' => 'https://api.example.com/sync',
 *         'records_processed' => 150,
 *         'duration_ms' => 2500,
 *         'response_code' => 200
 *     ],
 *     456, // order_id
 *     'success',
 *     'external_api_sync'
 * );
 * 
 * // Error logging with details
 * odcm_log_event(
 *     'Failed to connect to external service',
 *     [
 *         'service' => 'inventory_api',
 *         'error_code' => 'CONNECTION_TIMEOUT',
 *         'retry_count' => 3,
 *         'last_attempt' => time()
 *     ],
 *     null, // no specific order
 *     'error',
 *     'inventory_sync_error'
 * );
 * 
 * // Integration-specific event
 * odcm_log_event(
 *     'MailChimp subscriber updated',
 *     [
 *         'subscriber_email' => 'customer@example.com',
 *         'list_id' => 'abc123',
 *         'tags_added' => ['customer', 'vip'],
 *         'merge_fields' => ['FNAME' => 'John', 'LNAME' => 'Doe']
 *     ],
 *     789,
 *     'success',
 *     'mailchimp_subscriber_update'
 * );
 * 
 * // Warning with custom event type
 * odcm_log_event(
 *     'Deprecated API endpoint used',
 *     [
 *         'endpoint' => '/api/v1/orders',
 *         'replacement' => '/api/v2/orders',
 *         'deprecation_date' => '2024-12-31'
 *     ],
 *     null,
 *     'warning',
 *     'api_deprecation_notice'
 * );
 * ```
 */
function odcm_log_event(
    string $summary,
    array $data = [],
    ?int $order_id = null,
    string $status = 'info',
    string $event_type = 'event',
    bool $is_test = false,
    ?string $process_id = null
): bool {
    global $wpdb;
    
    // Guard clause - ensure Action Scheduler is available
    if (!function_exists('as_enqueue_async_action')) {
        return false;
    }

    // Validate and sanitize summary
    if (empty($summary) || !is_string($summary)) {
        $summary = 'Event logged';
    }

    // Validate status against registry
    $available_statuses = odcm_get_log_statuses();
    if (!array_key_exists($status, $available_statuses)) {
        $status = 'info';
    }

    // If rich 'components' array is already provided in the data, use it directly.
    // Otherwise, create a default wrapper component for backward compatibility.
    if (isset($data['components']) && is_array($data['components']) && !empty($data['components'])) {
        $components = $data['components'];
    } else {
        $level = in_array($status, ['error','warning','info','debug','success'], true) ? $status : 'info';
        if ($level === 'success') { 
            $level = 'info'; 
        }
        
        $components = [[
            'k' => odcm_component_key(),
            'event_type' => $event_type,
            'ts' => time(),
            'label' => $summary,
            'level' => $level,
            'data' => $data,
        ]];
    }
    
    // If rawData is already provided in the data, use it directly.
    // Otherwise, check if it's nested somewhere we can extract it from.
    $rawData = null;
    if (isset($data['rawData']) && is_array($data['rawData']) && !empty($data['rawData'])) {
        $rawData = $data['rawData'];
    }
    
    $envelope = [
        'type' => 'event',
        'cid' => ($order_id ? (string)$order_id : 'na') . ':' . time(),
        'oid' => $order_id,
        'actor' => [
            'id' => get_current_user_id() ?: null,
            'role' => null,
            'name' => null
        ],
        'ts' => time(),
        'status' => $status,
        'summary' => $summary,
        'components' => $components,
    ];
    
    // Add rawData to envelope if present
    if ($rawData !== null) {
        $envelope['rawData'] = $rawData;
    }
    
    // Prepare full event data
    $event_data = [
        'summary' => $summary,
        'status' => $status,
        'event_type' => $event_type,
        'order_id' => $order_id,
        'is_test' => $is_test,
        'envelope' => $envelope,
        'source' => 'logger',
        'timestamp' => current_time('mysql'),
        'data' => $data,
    ];
    
    // Add process ID if provided or auto-detect
    if ($process_id) {
        $event_data['process_id'] = $process_id;
    } else {
        $event_data = odcm_maybe_add_process_id($event_data);
    }
    
    // Generate unique queue ID
    $queue_id = uniqid('odcm_log_', true);
    
    // PHASE 1: Store in queue table
    $queue_result = $wpdb->insert(
        "{$wpdb->prefix}odcm_audit_log_queue",
        [
            'queue_id' => $queue_id,
            'event_data' => wp_json_encode($event_data),
            'created_at' => $event_data['timestamp'],
            'status' => 'pending'
        ]
    );
    
    if ($queue_result === false) {
        error_log("ODCM: Failed to queue log entry: " . $wpdb->last_error);
        return false;
    }
    
    // PHASE 2: Schedule background processing
    $action_id = as_enqueue_async_action(
        'odcm_process_queued_log_entry',
        ['queue_id' => $queue_id],  // Tiny! Always under 180 bytes
        'odcm-logs'
    );
    
    if (!$action_id) {
        error_log("ODCM: Failed to schedule queue processing for {$queue_id}");
        // Data is still in queue, will be picked up by cleanup job
        return false;
    }
    
    // Debug logging
    $debug_enabled = (defined('ODCM_DEBUG') && ODCM_DEBUG) || get_option('odcm_dev_debug_override', 0);
    if ($debug_enabled) {
        error_log("ODCM: Queued log entry {$queue_id} for processing (Action ID: {$action_id})");
    }
    
    return true;
}

/**
 * Get Log Event Types Registry - Global Wrapper Function
 *
 * This function provides global access to the log event types registry defined
 * in the LogRegistries.php file. It serves as a bridge between the namespaced
 * registry functions and the global logging API.
 *
 * @since 1.0.0
 * @return array Array of event type definitions
 */
function odcm_get_log_event_types(): array
{
    return \OrderDaemon\CompletionManager\Core\odcm_get_log_event_types();
}

/**
 * Get Log Status Registry - Global Wrapper Function
 *
 * This function provides global access to the log status registry defined
 * in the LogRegistries.php file. It serves as a bridge between the namespaced
 * registry functions and the global logging API.
 *
 * @since 1.0.0
 * @return array Array of status definitions
 */
function odcm_get_log_statuses(): array
{
    return \OrderDaemon\CompletionManager\Core\odcm_get_log_statuses();
}

/**
 * Encode status string to integer code - Global Wrapper Function
 *
 * @since 1.0.0
 * @param string $status Status string
 * @return int Status code
 */
function odcm_encode_status(string $status): int 
{
    return \OrderDaemon\CompletionManager\Core\odcm_encode_status($status);
}

/**
 * Decode status code to string - Global Wrapper Function
 *
 * @since 1.0.0
 * @param int $code Status code
 * @return string Status string
 */
function odcm_decode_status(int $code): string 
{
    return \OrderDaemon\CompletionManager\Core\odcm_decode_status($code);
}

/**
 * Encode source string to integer code - Global Wrapper Function
 *
 * @since 1.0.0
 * @param string $source Source string
 * @return int Source code
 */
function odcm_encode_source(string $source): int 
{
    return \OrderDaemon\CompletionManager\Core\odcm_encode_source($source);
}

/**
 * Decode source code to string - Global Wrapper Function
 *
 * @since 1.0.0
 * @param int $code Source code
 * @return string Source string
 */
function odcm_decode_source(int $code): string 
{
    return \OrderDaemon\CompletionManager\Core\odcm_decode_source($code);
}

/**
 * Validate custom summary against character limits
 *
 * Enforces character limits for custom event summaries to ensure
 * they fit within Action Scheduler payload constraints.
 *
 * @since 1.0.0
 * @param string $summary The custom summary to validate
 * @param int $max_length Maximum allowed character length (default: 60)
 * @return string Validated and potentially truncated summary
 */
function odcm_validate_custom_summary(string $summary, int $max_length = 60): string 
{
    if (strlen($summary) <= $max_length) {
        return $summary;
    }
    
    // Truncate with ellipsis, ensuring we don't break in the middle of a word
    $truncated = substr($summary, 0, $max_length - 3);
    $last_space = strrpos($truncated, ' ');
    
    if ($last_space !== false && $last_space > $max_length * 0.8) {
        // If we have a space in the last 20% of the string, break there
        $truncated = substr($truncated, 0, $last_space);
    }
    
    return $truncated . '...';
}

/**
 * Efficiently retrieves metadata for multiple posts using a single database query and caches the result.
 *
 * This function is designed to be highly performant. It first checks for cached data in a transient
 * to avoid database queries altogether on subsequent requests for the same set of post IDs.
 * If no transient is found, it uses `update_meta_cache()` to warm up the WordPress object cache
 * for all requested post IDs in a single, optimized database query. It then retrieves the meta
 * for each post (from the now-primed cache) and stores the consolidated results in a transient
 * for future requests.
 *
 * @param array $post_ids An array of post IDs.
 * @return array An associative array where keys are post IDs and values are their metadata.
 */
function odcm_get_post_meta_by_ids(array $post_ids): array
{
    if (empty($post_ids)) {
        return [];
    }

    // Create a unique cache key based on the post IDs to avoid collisions.
    $cache_key   = 'odcm_meta_for_posts_' . md5(wp_json_encode($post_ids));
    $cached_meta = get_transient($cache_key);

    // If a valid transient exists, return the cached data immediately.
    if (false !== $cached_meta) {
        return $cached_meta;
    }

    // Prime the WordPress object cache for all post IDs in a single database query.
    update_meta_cache('post', $post_ids);

    $all_meta = [];
    foreach ($post_ids as $post_id) {
        // This call will now hit the pre-warmed object cache, not the database.
        $all_meta[$post_id] = get_post_meta($post_id);
    }

    // Cache the consolidated result for 1 hour.
    set_transient($cache_key, $all_meta, (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600));

    return $all_meta;
}


/**
 * Action Scheduler Args Column Readability Enhancement
 *
 * Improves the readability of the 'Args' column for our plugin's scheduled actions
 * in the Action Scheduler admin interface. This function intercepts the column output,
 * extracts smart summaries from event_data, and creates collapsible details with
 * pretty-printed JSON for enhanced UX.
 *
 * REQUIREMENTS ANALYSIS:
 * =====================
 * 
 * Target Output Format:
 * <details>
 *   <summary>Order #96 completed successfully. event_data (click to expand)</summary>
 *   <pre><code>{
 *     "summary": "Order #96 completed successfully",
 *     "status": "success", 
 *     "event_type": "order_completed",
 *     // ... formatted JSON payload
 *   }</code></pre>
 * </details>
 * 
 * SMART SUMMARY EXTRACTION:
 * ========================
 * 
 * 1. For event_data arrays: Extract event_data['summary'] field
 * 2. For simple args: Generate contextual summary (e.g., "Order #96")
 * 3. Fallback: Return original output unchanged (fail-safe)
 * 
 * COMPATIBILITY DESIGN:
 * ====================
 * 
 * This implementation is designed to play well with other plugins and Action Scheduler:
 * - Only processes actions that belong to our plugin (odcm_ prefix check)
 * - Returns original output unchanged for all other plugins' actions
 * - Uses defensive coding with proper row array validation
 * - Implements graceful fallbacks for edge cases
 * - No external dependencies (pure PHP + WordPress functions)
 * 
 * SECURITY CONSIDERATIONS:
 * =======================
 * 
 * - All output is properly escaped using esc_html() before rendering
 * - No user input is processed (only Action Scheduler internal data)
 * - Uses WordPress core functions for JSON encoding and escaping
 * - Inline styles are minimal and safe (no user-controlled content)
 * 
 * PERFORMANCE CONSIDERATIONS:
 * ==========================
 * 
 * - Early return for non-plugin actions (minimal overhead for other plugins)
 * - Efficient string prefix checking using strpos() === 0
 * - JSON formatting only applied when necessary
 * - No database queries or external API calls
 * 
 * PLUGIN PREFIX DETECTION:
 * =======================
 * 
 * The function checks for the 'odcm_' prefix which is used consistently
 * throughout the plugin for all scheduled actions:
 * - odcm_process_order_check (main order processing)
 * - odcm_reprocess_orders_batch (batch reprocessing)
 * - odcm_process_log_entry (log processing)
 *
 * @since 1.0.0
 *
 * @param string $output The original HTML output for the Args column from Action Scheduler.
 * @param array $row The Action Scheduler row array containing hook, args, and other data.
 *
 * @return string The modified HTML output with collapsible details for our plugin's actions,
 *                or the original output unchanged for other plugins' actions.
 *
 * @example
 * Before: <ul><li><code>'event_data' => array(...)</code></li></ul>
 * After:  <details>
 *           <summary>Order #96 completed successfully. event_data (click to expand)</summary>
 *           <pre><code>{ "summary": "Order #96 completed successfully", ... }</code></pre>
 *         </details>
 */
function odcm_format_as_args_column($output, $row) {
    // Defensive check: Ensure we have a valid row array
    if (!is_array($row) || !isset($row['hook']) || !isset($row['args'])) {
        return $output;
    }

    // Get the hook name from the row
    $hook = $row['hook'];

    // Early return if hook is not a string or is empty
    if (!is_string($hook) || empty($hook)) {
        return $output;
    }

    // Define the prefixes for our plugin's action hooks
    $plugin_prefixes = ['odcm_'];
    $is_our_action = false;

    // Check if this action belongs to our plugin
    foreach ($plugin_prefixes as $prefix) {
        if (strpos($hook, $prefix) === 0) {
            $is_our_action = true;
            break;
        }
    }

    // If this action does not belong to our plugin, return original output unchanged
    if (!$is_our_action) {
        return $output;
    }

    // Get the arguments from the row
    $args = $row['args'];

    // If args are empty or not an array, return original output
    if (empty($args) || !is_array($args)) {
        return $output;
    }

    // Smart Summary Extraction Logic
    $summary_text = '';
    $key_name = '';

    // Check for event_data structure (audit logging system)
    if (isset($args['event_data']) && is_array($args['event_data']) && isset($args['event_data']['summary'])) {
        $summary_text = $args['event_data']['summary'];
        $key_name = 'event_data';
    }
    // Check for simple order_id structure
    elseif (isset($args['order_id']) && is_numeric($args['order_id'])) {
        $summary_text = "Order #{$args['order_id']}";
        $key_name = 'order_id';
    }
    // Fallback: Unknown structure - return original output unchanged
    else {
        return $output;
    }

    // Format the arguments into a human-readable, indented JSON string
    $formatted_args = json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    // If JSON encoding failed, return original output
    if ($formatted_args === false) {
        return $output;
    }
    
    // Escape the string for security before outputting
    $escaped_args = esc_html($formatted_args);
    $escaped_summary = esc_html($summary_text);
    $escaped_key_name = esc_html($key_name);

    // Create collapsible details with smart summary
    $new_output = sprintf(
        '<details><summary>%s. %s (click to expand)</summary><pre style="white-space: pre-wrap; word-break: break-all; margin: 0; font-family: monospace; font-size: 12px; line-height: 1.4; max-width: 100%%; overflow-wrap: break-word;"><code>%s</code></pre></details>',
        $escaped_summary,
        $escaped_key_name,
        $escaped_args
    );
    
    return $new_output;
}

// Hook the function to the Action Scheduler args column filter
// Priority 10 ensures it runs at the default priority level
// 2 parameters: $output and $action object
add_filter('action_scheduler_list_table_column_args', 'odcm_format_as_args_column', 10, 2);




/**
 * Add process ID to event data if it's an order lifecycle event
 *
 * Ensures registry-based canonical events (with nested data.order_id) receive
 * a shared process_id as well.
 *
 * @param array $event_data
 * @return array
 */
function odcm_maybe_add_process_id(array $event_data): array 
{
    // Must have an event_type
    if (empty($event_data['event_type'])) {
        return $event_data;
    }

    // Resolve order_id from top-level or nested canonical data
    $order_id = null;
    if (!empty($event_data['order_id'])) {
        $order_id = (int) $event_data['order_id'];
    } elseif (!empty($event_data['data']) && is_array($event_data['data']) && !empty($event_data['data']['order_id'])) {
        $order_id = (int) $event_data['data']['order_id'];
    }

    if (empty($order_id) || $order_id <= 0) {
        return $event_data;
    }

    // Discover lifecycle family
    if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
        require_once __DIR__ . '/../Core/ProcessLifecycleDiscovery.php';
    }
    $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
    $families = $discovery->get_process_families();
    $lifecycle_types = isset($families['order_lifecycle']['process_types']) && is_array($families['order_lifecycle']['process_types'])
        ? $families['order_lifecycle']['process_types']
        : [];

    // Known main-table lifecycle slugs used by our logging pipeline
$main_table_lifecycle = [
    'checkout_processing',
    'block_checkout_processed',
    'status_change_processing',
    'manual_status_change',
    'rule_execution',
    'order_completion',
    'process_started',
    'no_rules_matched',  // Debug
];
    $lifecycle_union = array_values(array_unique(array_merge($lifecycle_types, $main_table_lifecycle)));

    $event_type = (string) $event_data['event_type'];
    if (!in_array($event_type, $lifecycle_union, true)) {
        return $event_data;
    }

    // Get or create process ID for this order
    if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessIdManager')) {
        require_once __DIR__ . '/../Core/ProcessIdManager.php';
    }
    $process_manager = \OrderDaemon\CompletionManager\Core\ProcessIdManager::instance();
    $process_id = $process_manager->get_or_create_process_id((int) $order_id);

    // Add process ID to event data (both top-level and nested canonical data)
    $event_data['process_id'] = $process_id;
    if (!empty($event_data['data']) && is_array($event_data['data'])) {
        $event_data['data']['process_id'] = $process_id;
        if (empty($event_data['data']['order_id'])) {
            $event_data['data']['order_id'] = (int) $order_id;
        }
    }

    return $event_data;
}


/**
 * Get current ISO 8601 timestamp in site timezone.
 *
 * Uses wp_date('c') to respect WordPress site timezone settings configured under Settings > General.
 *
 * @since 1.0.0
 * @return string ISO 8601 formatted date-time.
 */
function odcm_iso8601_now(): string
{
    return wp_date('c');
}

/**
 * Format a UNIX timestamp to ISO 8601 in the site timezone.
 *
 * @since 1.0.0
 * @param int $timestamp UNIX timestamp (seconds since epoch).
 * @return string ISO 8601 formatted date-time.
 */
function odcm_iso8601_from_timestamp(int $timestamp): string
{
    return wp_date('c', $timestamp);
}

/**
 * Generate a unique component key.
 * Uses optimized format: c{timestamp}{random}[-{suffix}]
 *
 * @param string|null $suffix
 * @return string
 */
function odcm_component_key(string $suffix = null): string
{
    return 'c' . time() . wp_rand(10, 99) . ($suffix ? '-' . $suffix : '');
}
