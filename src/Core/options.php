<?php
declare(strict_types=1);

/**
 * Central Option Registration File - Entitlement-Aware Feature Registry
 * 
 * This file serves as the single source of truth for all available triggers, conditions,
 * and actions in the Order Daemon For Woocommerce plugin. It demonstrates the plugin's
 * entitlement-aware architecture where features are dynamically enabled/disabled based
 * on user licensing.
 * 
 * DEVELOPER GUIDE - Adding New Options:
 * ====================================
 * 
 * To add a new option to the plugin, follow these steps:
 * 
 * 1. Choose the appropriate section (TRIGGERS, CONDITIONS, or ACTIONS)
 * 2. Register your option using the appropriate method
 * 3. Follow the capability naming conventions
 * 4. Implement the render callback function
 * 5. Test with both free and premium user scenarios
 * 
 * CAPABILITY NAMING CONVENTIONS:
 * =============================
 * 
 * Free Tier Capabilities (always return true in odcm_can_use()):
 * - trigger_basic          : Basic order status triggers
 * - condition_product_type : Product type conditions
 * - condition_order_total  : Order total conditions
 * - action_basic          : Basic order completion actions
 * 
 * Premium Tier Capabilities (require premium license):
 * - trigger_*             : Advanced triggers (payment_complete, on_hold, etc.)
 * - condition_*           : Advanced conditions (customer_role, payment_gateway, etc.)
 * - action_*              : Advanced actions (send_custom_email, add_custom_note, etc.)
 * 
 * ENTITLEMENT SYSTEM INTEGRATION:
 * ===============================
 * 
 * Each registered option includes a 'capability' key that integrates with the
 * odcm_can_use() function. This creates a seamless freemium experience where:
 * 
 * - Free users see basic options as functional UI elements
 * - Premium options appear as locked/disabled with upgrade prompts
 * - The UI dynamically adapts based on user's license level
 * - No code changes needed when user upgrades - features unlock automatically
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
 * 1. Free user account (should see basic options only)
 * 2. Premium user account (should see all options)
 * 3. Verify UI renders correctly in both scenarios
 * 4. Test that capability checks work as expected
 * 
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/adding-options
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Get the global registry instance
// This singleton pattern ensures all registrations use the same registry object
$registry = odcm_get_registry_instance();

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
// ENTITLEMENT STRATEGY:
// - Basic triggers (order_processing) are free to encourage adoption
// - Advanced triggers require premium license for sophisticated workflows
//
// IMPLEMENTATION NOTES:
// - Triggers hook into WooCommerce actions/filters
// - Each trigger should have minimal performance impact
// - Use Action Scheduler for heavy processing, not direct execution
//
// ADDING NEW TRIGGERS:
// 1. Identify the WooCommerce hook you want to use
// 2. Choose appropriate capability (trigger_basic for free, trigger_* for premium)
// 3. Register the trigger here
// 4. Implement the hook handler in the Core class
// 5. Create render callback for any trigger-specific settings

/**
 * Basic Order Processing Trigger (FREE TIER)
 * 
 * This is the core trigger that fires when an order status changes to 'processing'.
 * It's available in the free tier to provide immediate value and demonstrate
 * the plugin's capabilities.
 * 
 * Use Case: Most common scenario for auto-completion (digital products, services)
 * Hook: woocommerce_order_status_processing
 * Capability: trigger_basic (free)
 */
$registry->register_trigger([
    'id'              => 'order_processing',
    'label'           => __('Order Processing', 'order-daemon'),
    'description'     => __('Runs when an order status changes to "Processing". Ideal for most standard automations.', 'order-daemon'),
    'capability'      => 'trigger_basic', // FREE: Core functionality
    'tier'            => 'free',
    'section'         => 'primary', // Primary trigger section
    'render_callback' => 'odcm_render_trigger_settings',
]);

/**
 * Payment Complete Trigger (PREMIUM TIER)
 * 
 * Advanced trigger that fires specifically when payment is completed, regardless
 * of order status. Useful for complex payment workflows and subscription scenarios.
 * 
 * Use Case: Subscription renewals, complex payment gateways, delayed capture
 * Hook: woocommerce_payment_complete
 * Capability: trigger_payment_complete (premium)
 */

// =============================================================================
// CONDITIONS REGISTRATION - Rule Criteria and Targeting
// =============================================================================
//
// Conditions define the criteria that must be met for a completion rule to apply
// to an order. They enable precise targeting based on order properties, customer
// data, and contextual information.
//
// ENTITLEMENT STRATEGY:
// - Basic conditions (product_type, order_total) are free for core functionality
// - Advanced conditions require premium license for sophisticated targeting
// - Freemium approach: single category selection free, multiple selection premium
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
// 2. Choose appropriate capability level (free vs premium)
// 3. Register the condition here
// 4. Implement the evaluation logic in the Executor class
// 5. Create render callback for condition-specific settings

/**
 * Product Type Condition (FREE TIER)
 * 
 * Checks if all products in the order match specific types (virtual, downloadable, etc.).
 * This is a fundamental condition that provides immediate value for digital product stores.
 * 
 * Use Case: Auto-complete orders containing only virtual/downloadable products
 * Data Source: WC_Product->is_virtual(), WC_Product->is_downloadable()
 * Capability: condition_product_type (free)
 */
$registry->register_condition([
    'id'              => 'product_type',
    'label'           => __('Product Type', 'order-daemon'),
    'description'     => __('Check if the order contains only specific types of products.', 'order-daemon'),
    'capability'      => 'condition_product_type', // FREE: Essential for digital products
    'tier'            => 'free',
    'section'         => 'primary', // Primary condition section
    'render_callback' => 'odcm_render_condition_settings',
]);

/**
 * Product Category Condition (FREEMIUM)
 * 
 * Checks if products belong to specific categories. Free tier allows single category
 * selection, premium tier enables multiple category selection with AND/OR logic.
 * 
 * Use Case: Category-specific completion rules (courses, memberships, services)
 * Data Source: wp_get_post_terms() with 'product_cat' taxonomy
 * Capability: condition_single_category (freemium base)
 * Premium Upgrade: condition_multi_category for multiple selection
 */
$registry->register_condition([
    'id'              => 'product_category',
    'label'           => __('Product Category', 'order-daemon'),
    'description'     => __('Check if the order contains products from specific categories.', 'order-daemon'),
    'capability'      => 'condition_single_category', // FREEMIUM: Single category free, multi premium
    'tier'            => 'free',
    'section'         => 'primary', // Primary condition section
    'render_callback' => 'odcm_render_condition_settings',
]);

/**
 * Order Total Condition (FREE TIER)
 * 
 * Checks order total against specified amounts with comparison operators.
 * Free tier provides basic value-based automation to demonstrate plugin value.
 * 
 * Use Case: Auto-complete small orders, hold large orders for review
 * Data Source: WC_Order->get_total()
 * Capability: condition_order_total (free)
 */
$registry->register_condition([
    'id'              => 'order_total',
    'label'           => __('Order Total', 'order-daemon'),
    'description'     => __('Check if the order total is above, below, or equal to a specific amount.', 'order-daemon'),
    'capability'      => 'condition_order_total', // FREE: Basic value-based automation
    'tier'            => 'free',
    'section'         => 'addon', // Add-on condition section (moved from primary as requested)
    'render_callback' => 'odcm_render_condition_settings',
]);

/**
 * User Role Condition (PREMIUM TIER)
 * 
 * Targets orders based on customer's WordPress user role. Enables role-based
 * automation for membership sites, B2B stores, and tiered customer systems.
 * 
 * Use Case: VIP customer treatment, wholesale vs retail rules, membership tiers
 * Data Source: WP_User->roles, get_userdata()
 * Capability: condition_customer_role (premium)
 */

// =============================================================================
// ACTIONS REGISTRATION - What Happens When Rules Apply
// =============================================================================
//
// Actions define what should happen when a completion rule's conditions are met.
// They perform the actual work of completing orders, sending notifications,
// updating metadata, and integrating with external systems.
//
// ENTITLEMENT STRATEGY:
// - Basic action (order completion) is free as core plugin functionality
// - Advanced actions require premium license for extended automation
// - Focus on value-add features that justify premium pricing
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
// 2. Choose appropriate capability level (free vs premium)
// 3. Register the action here
// 4. Implement the execution logic in the Executor class
// 5. Create render callback for action-specific settings
// 6. Add proper error handling and logging

/**
 * Complete Order Action (FREE TIER)
 * 
 * The core action that changes order status to 'completed'. This is the fundamental
 * functionality that the plugin is built around and provides immediate value.
 * 
 * Use Case: Primary plugin functionality - auto-completing orders
 * Implementation: WC_Order->update_status('completed')
 * Capability: action_basic (free)
 * Side Effects: Triggers WooCommerce completion emails, inventory updates
 */
$registry->register_action([
    'id'              => 'change_status_to_completed',
    'label'           => __('Change Status to \'Completed\'', 'order-daemon'),
    'description'     => __('Mark the order as complete.', 'order-daemon'),
    'capability'      => 'action_basic', // FREE: Core plugin functionality
    'tier'            => 'free',
    'section'         => 'primary', // Primary action section
    'render_callback' => 'odcm_render_action_settings',
]);

/**
 * Send Custom Email Action (PREMIUM TIER)
 * 
 * Advanced action that sends customized email notifications beyond WooCommerce's
 * standard emails. Enables personalized communication and marketing automation.
 * 
 * Use Case: Custom completion notifications, upselling, follow-up sequences
 * Implementation: wp_mail() with custom templates and merge tags
 * Capability: action_send_custom_email (premium)
 * Settings: Email template, recipient, subject, merge tags
 */

// Allow pro add-on to register additional components
if (function_exists('do_action')) {
    do_action('odcm_register_triggers', $registry);
    do_action('odcm_register_conditions', $registry);
    do_action('odcm_register_actions', $registry);
}

// =============================================================================
// END OF OPTION REGISTRATIONS
// =============================================================================
//
// DEVELOPER NOTES:
// ===============
//
// This file now contains all available options for the order daemon.
// The entitlement system is fully integrated, providing a seamless freemium
// experience that scales with user needs.
//
// NEXT STEPS FOR PRODUCTION:
// 1. Replace placeholder render callbacks with actual MetaBox methods
// 2. Implement condition evaluation logic in the Executor class
// 3. Implement action execution logic in the Executor class
// 4. Add comprehensive unit tests for all registered options
// 5. Create user documentation for each option type
//
// MAINTENANCE GUIDELINES:
// 1. Keep capability naming consistent with established patterns
// 2. Always include comprehensive comments for new options
// 3. Test both free and premium user experiences
// 4. Update the developer guide when adding new option types
// 5. Consider backward compatibility when modifying existing options
//
// For more information, see the developer guide at
// /docs/developer-guide/05-entitlements-system-guide.md
