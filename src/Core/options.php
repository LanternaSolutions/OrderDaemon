<?php
declare(strict_types=1);

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central Option Registration File - Feature Registry
 *
 * This file serves as the single source of truth for all available triggers, conditions,
 * and actions in the Order Daemon For Woocommerce plugin.
 *
 * DEVELOPER GUIDE - Adding New Options:
 * ====================================
 *
 * To add a new option to the plugin, follow these steps:
 *
 * 1. Choose the appropriate section (TRIGGERS, CONDITIONS, or ACTIONS)
 * 2. Register your option using the appropriate method
 * 3. Implement the render callback function
 * 4. Test the functionality
 *
 * RENDER CALLBACK IMPLEMENTATION:
 * ==============================
 *
 * Each option requires a render callback that generates the settings UI.
 * Currently using placeholder callbacks, but production implementations should:
 *
 * - Be methods in the MetaBox class for consistency
 * - Accept the current rule data as a parameter
 * - Generate escaped HTML output
 * - Include proper nonce fields for security
 * - Follow WordPress coding standards
 *
 * Example render callback:
 * ```php
 * public function render_my_condition_settings($rule_data) {
 *     $value = isset($rule_data['my_setting']) ? $rule_data['my_setting'] : '';
 *     echo '<input type="text" name="my_setting" value="' . esc_attr($value) . '">';
 * }
 * ```
 *
 * TESTING YOUR ADDITIONS:
 * =======================
 *
 * After adding new options, test with:
 * 1. Verify UI renders correctly
 * 2. Test that the functionality works as expected
 * 3. Verify proper error handling
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/adding-options
 */

// Get the global registry instance
// This singleton pattern ensures all registrations use the same registry object
$odcm_registry = odcm_get_registry_instance();

/**
 * Render callback functions for option settings
 * 
 * These functions connect the registry options to their corresponding
 * render methods in the MetaBox class. Each option type has its own
 * standardized render method.
 * 
 * @since 1.0.0
 */

if (!function_exists('odcm_render_trigger_settings')) {
    function odcm_render_trigger_settings($trigger_id, $post) {
        $metabox = new \OrderDaemon\CompletionManager\Admin\MetaBox();
        return $metabox->render_trigger_settings($trigger_id, $post);
    }
}

if (!function_exists('odcm_render_condition_settings')) {
    function odcm_render_condition_settings($condition_id, $post) {
        $metabox = new \OrderDaemon\CompletionManager\Admin\MetaBox();
        return $metabox->render_condition_settings($condition_id, $post);
    }
}

if (!function_exists('odcm_render_action_settings')) {
    function odcm_render_action_settings($action_id, $post) {
        $metabox = new \OrderDaemon\CompletionManager\Admin\MetaBox();
        return $metabox->render_action_settings($action_id, $post);
    }
}

// =============================================================================
// TRIGGERS REGISTRATION - When Rules Should Be Evaluated
// =============================================================================
//
// Triggers define the events in the WooCommerce order lifecycle that can initiate
// completion rule processing. Each trigger represents a specific moment when the
// plugin should check if any rules apply to an order.
//
// IMPLEMENTATION NOTES:
// - Triggers hook into WooCommerce actions/filters
// - Each trigger should have minimal performance impact
// - Use Action Scheduler for heavy processing, not direct execution
//
// ADDING NEW TRIGGERS:
// 1. Identify the WooCommerce hook you want to use
// 2. Register the trigger here
// 3. Implement the hook handler in the Core class
// 4. Create render callback for any trigger-specific settings

/**
 * Order Processing Trigger
 * 
 * This is the core trigger that fires when an order status changes to 'processing'.
 * It provides immediate value and demonstrates the plugin's capabilities.
 * 
 * Use Case: Most common scenario for auto-completion (digital products, services)
 * Hook: woocommerce_order_status_processing
 */
$odcm_registry->register_trigger([
    'id'              => 'order_processing',
    'label'           => __('rule_component.trigger.order_processing.label', 'order-daemon'),
    'description'     => __('rule_component.trigger.order_processing.description', 'order-daemon'),
    'section'         => 'primary', // Primary trigger section
    'render_callback' => 'odcm_render_trigger_settings',
]);

// =============================================================================
// CONDITIONS REGISTRATION - Rule Criteria and Targeting
// =============================================================================
//
// Conditions define the criteria that must be met for a completion rule to apply
// to an order. They enable precise targeting based on order properties, customer
// data, and contextual information.
//
// CONDITION TYPES:
// - Product-based: product_type, product_category
// - Customer-based: user_role
// - Payment-based: payment_gateway, order_total
// - Fulfillment-based: shipping_method
// - Marketing-based: coupon_used
//
// IMPLEMENTATION NOTES:
// - Conditions are evaluated in the order they're registered
// - Each condition should fail fast for performance
// - Use WooCommerce's built-in data structures when possible
// - Cache expensive lookups (categories, user roles, etc.)
//
// ADDING NEW CONDITIONS:
// 1. Identify the order/customer property to check
// 2. Register the condition here
// 3. Implement the evaluation logic in the UniversalEventProcessor class
// 4. Create render callback for condition-specific settings

/**
 * Product Type Condition
 * 
 * Checks if all products in the order match specific types (virtual, downloadable, etc.).
 * This is a fundamental condition that provides immediate value for digital product stores.
 * 
 * Use Case: Auto-complete orders containing only virtual/downloadable products
 * Data Source: WC_Product->is_virtual(), WC_Product->is_downloadable()
 */
$odcm_registry->register_condition([
    'id'              => 'product_type',
    'label'           => __('rule_component.condition.product_type.label', 'order-daemon'),
    'description'     => __('rule_component.condition.product_type.description', 'order-daemon'),
    'section'         => 'primary', // Primary condition section
    'render_callback' => 'odcm_render_condition_settings',
]);

/**
 * Product Category Condition
 * 
 * Checks if products belong to specific categories.
 * 
 * Use Case: Category-specific completion rules (courses, memberships, services)
 * Data Source: wp_get_post_terms() with 'product_cat' taxonomy
 */
$odcm_registry->register_condition([
    'id'              => 'product_category',
    'label'           => __('rule_component.condition.product_category.label', 'order-daemon'),
    'description'     => __('rule_component.condition.product_category.description', 'order-daemon'),
    'section'         => 'primary', // Primary condition section
    'render_callback' => 'odcm_render_condition_settings',
]);

/**
 * Order Total Condition
 * 
 * Checks order total against specified amounts with comparison operators.
 * 
 * Use Case: Auto-complete small orders, hold large orders for review
 * Data Source: WC_Order->get_total()
 */
$odcm_registry->register_condition([
    'id'              => 'order_total',
    'label'           => __('rule_component.condition.order_total.label', 'order-daemon'),
    'description'     => __('rule_component.condition.order_total.description', 'order-daemon'),
    'section'         => 'addon', // Add-on condition section (moved from primary as requested)
    'render_callback' => 'odcm_render_condition_settings',
]);

/**
 * User Role Condition
 * 
 * Targets orders based on customer's WordPress user role. Enables role-based
 * automation for membership sites, B2B stores, and tiered customer systems.
 * 
 * Use Case: VIP customer treatment, wholesale vs retail rules, membership tiers
 * Data Source: WP_User->roles, get_userdata()
 */

// =============================================================================
// ACTIONS REGISTRATION - What Happens When Rules Apply
// =============================================================================
//
// Actions define what should happen when a completion rule's conditions are met.
// They perform the actual work of completing orders, sending notifications,
// updating metadata, and integrating with external systems.
//
// ACTION TYPES:
// - Order Management: change_status_to_completed
// - Communication: send_custom_email
// - Documentation: add_order_note
// - Future: External integrations, webhooks, advanced notifications
//
// IMPLEMENTATION NOTES:
// - Actions are executed via Action Scheduler for reliability
// - Each action should be idempotent (safe to run multiple times)
// - Use WooCommerce's built-in functions when possible
// - Log action execution for audit trail and debugging
// - Handle failures gracefully with proper error reporting
//
// ADDING NEW ACTIONS:
// 1. Identify the specific task to perform
// 2. Register the action here
// 3. Implement the execution logic in the UniversalEventProcessor class
// 4. Create render callback for action-specific settings
// 5. Add proper error handling and logging

/**
 * Complete Order Action
 * 
 * The core action that changes order status to 'completed'. This is the fundamental
 * functionality that the plugin is built around and provides immediate value.
 * 
 * Use Case: Primary plugin functionality - auto-completing orders
 * Implementation: WC_Order->update_status('completed')
 * Side Effects: Triggers WooCommerce completion emails, inventory updates
 */
$odcm_registry->register_action([
    'id'              => 'change_status_to_completed',
    'label'           => __('rule_component.action.complete_order.label', 'order-daemon'),
    'description'     => __('rule_component.action.complete_order.description', 'order-daemon'),
    'section'         => 'primary', // Primary action section
    'render_callback' => 'odcm_render_action_settings',
]);

/**
 * Send Custom Email Action
 * 
 * Advanced action that sends customized email notifications beyond WooCommerce's
 * standard emails. Enables personalized communication and marketing automation.
 * 
 * Use Case: Custom completion notifications, upselling, follow-up sequences
 * Implementation: wp_mail() with custom templates and merge tags
 * Settings: Email template, recipient, subject, merge tags
 */

// Allow pro add-on to register additional components
if (function_exists('do_action')) {
    do_action('odcm_register_triggers', $odcm_registry);
    do_action('odcm_register_conditions', $odcm_registry);
    do_action('odcm_register_actions', $odcm_registry);
}

// =============================================================================
// END OF OPTION REGISTRATIONS
// =============================================================================
//
// DEVELOPER NOTES:
// ===============
//
// This file now contains all available options for the order daemon.
//
// NEXT STEPS FOR PRODUCTION:
// 1. Replace placeholder render callbacks with actual MetaBox methods
// 2. Implement condition evaluation logic in the UniversalEventProcessor class
// 3. Implement action execution logic in the UniversalEventProcessor class
// 4. Add comprehensive unit tests for all registered options
// 5. Create user documentation for each option type
//