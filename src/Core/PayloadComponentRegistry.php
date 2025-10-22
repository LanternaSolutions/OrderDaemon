<?php
declare(strict_types=1);

// Note: This file intentionally does NOT use a namespace
// so that the functions are available globally for WordPress compatibility

/**
 * Payload Component Registry - Central Hub for Payload Component Definitions
 *
 * This registry serves as the single source of truth for all known payload component types
 * in the Order Daemon audit log system. It implements the Registry Pattern to provide
 * structured, extensible, and maintainable payload rendering architecture.
 *
 * COMPONENT REGISTRY OVERVIEW:
 * ===========================
 * 
 * The component registry centralizes the definition of all payload component types,
 * providing a structured approach to payload rendering that enables:
 * - Consistent component categorization and identification
 * - Dynamic renderer class assignment
 * - Standardized CSS styling with visual differentiation
 * - Easy extension for new component types
 * - Clear separation between different payload data types
 * 
 * COMPONENT CATEGORIES:
 * ====================
 * 
 * - 'api_call': API request/response data and external service interactions
 * - 'error_details': Error objects, exceptions, and stack traces
 * - 'performance_metrics': Timing, memory usage, and performance data
 * - 'rule_evaluation': Rule processing, condition matching, and decision logic
 * - 'database_query': SQL queries, results, and database interactions
 * - 'woocommerce_data': Order, product, and WooCommerce-specific data
 * - 'system_info': System diagnostics, environment, and configuration data
 * 
 * INTEGRATION WITH EXISTING ARCHITECTURE:
 * ======================================
 * 
 * This registry follows the established patterns in the plugin:
 * - Similar structure to LogRegistries.php for consistency
 * - Integration with existing CSS framework in admin.css
 * - Compatibility with current Prism.js syntax highlighting
 * - Preservation of existing AuditTrailListTable functionality
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/payload-rendering-system
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Get Payload Component Types Registry - Single Source of Truth for Component Definitions
 *
 * This function returns a comprehensive registry of all known payload component types
 * that can be rendered by the Order Daemon audit log system. Each component type includes
 * metadata for consistent rendering, dynamic class assignment, and proper visual styling.
 *
 * REGISTRY STRUCTURE:
 * ==================
 * 
 * Each component type is defined with the following properties:
 * - id: Unique component slug used for registry lookup
 * - label: Human-readable title for component headers
 * - renderer_class: PHP class name for rendering this component type
 * - css_class: CSS class for visual styling and differentiation
 * - icon: Dashicons icon for component headers
 * - priority: Rendering order priority (lower numbers render first)
 * - detection_keys: Array of payload keys that indicate this component type
 * 
 * RENDERER CLASS NAMING:
 * =====================
 * 
 * Renderer classes follow the pattern: {ComponentName}Renderer
 * - Located in: src/View/PayloadRenderer/
 * - Namespace: OrderDaemon\CompletionManager\View\PayloadRenderer
 * - Interface: PayloadComponentRenderer
 * 
 * CSS CLASS NAMING:
 * ================
 * 
 * CSS classes follow the pattern: odcm-{component-type}-payload
 * - Styled in: assets/css/admin.css
 * - Uses CSS custom properties for consistent theming
 * - Includes border colors for visual differentiation
 * 
 * DETECTION SYSTEM:
 * ================
 * 
 * Components can be detected by:
 * 1. Explicit payload keys (detection_keys array)
 * 2. Renderer class canHandle() method for complex detection
 * 3. Fallback to default renderer for unrecognized data
 * 
 * EXTENSIBILITY:
 * =============
 * 
 * New component types can be added by:
 * 1. Adding entry to this registry function
 * 2. Creating corresponding renderer class
 * 3. Adding CSS styles for visual differentiation
 * 4. Using the new component in payload data
 *
 * @since 1.0.0
 *
 * @return array<string, array<string, mixed>> {
 *     Associative array of component types keyed by component slug.
 *     
 *     @type array $component_slug {
 *         Individual component type definition.
 *         
 *         @type string   $id              Unique component identifier (matches array key).
 *         @type string   $label           Human-readable component name for headers.
 *         @type string   $renderer_class  PHP class name for rendering this component.
 *         @type string   $css_class       CSS class name for styling the component.
 *         @type string   $icon            Dashicons icon class for component headers.
 *         @type int      $priority        Rendering order priority (lower = first).
 *         @type string[] $detection_keys  Payload keys that indicate this component type.
 *     }
 * }
 *
 * @example
 * ```php
 * // Get all component types
 * $components = odcm_get_payload_component_types();
 * 
 * // Look up specific component
 * $api_component = $components['api_call'];
 * echo $api_component['label']; // "API Call"
 * 
 * // Get renderer class
 * $renderer_class = $api_component['renderer_class'];
 * $renderer = new $renderer_class();
 * 
 * // Check detection keys
 * $payload = ['api_request' => [...], 'api_response' => [...]];
 * $has_api_data = !empty(array_intersect(
 *     array_keys($payload), 
 *     $api_component['detection_keys']
 * ));
 * ```
 */
function odcm_get_payload_component_types(): array
{
    return [
        // === NARRATIVE-ONLY REGISTRY: Kinds == Component IDs ===

        // Payment Event parent entry for PaymentEventRenderer
        'payment_event' => [
            'id'             => 'payment_event',
            'label'          => __('Payment Event', 'order-daemon'),
            'renderer_class' => 'PaymentEventRenderer',
            'css_class'      => 'odcm-component--payment',
            'icon'           => 'dashicons-money-alt',
            'aliases'        => [
                'payment_completed', 
                'payment_failed', 
                'refund_created',
                'payment_succeeded',
                'payment_processed',
                'charge_succeeded'
            ],
            'status_pill'    => [
                'label' => __('Payment', 'order-daemon'),
                'type'  => 'info'
            ],
        ],
        
        // Payment Events (UNIVERSAL - handles all gateway payments)
        'payment_completed' => [
            'id'             => 'payment_completed',
            'label'          => __('Payment Completed', 'order-daemon'),
            'renderer_class' => 'PaymentEventRenderer',
            'css_class'      => 'odcm-component--payment',
            'icon'           => 'dashicons-money-alt',
            'aliases'        => [
                'payment_succeeded',
                'payment_processed',
                'charge_succeeded',
                'payment_event' // Add parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('Payment', 'order-daemon'),
                'type'  => 'success'
            ],
        ],
        'payment_failed' => [
            'id'             => 'payment_failed',
            'label'          => __('Payment Failed', 'order-daemon'),
            'renderer_class' => 'PaymentEventRenderer',
            'css_class'      => 'odcm-component--payment',
            'icon'           => 'dashicons-warning',
            'aliases'        => [
                'payment_error',
                'charge_failed',
                'payment_declined',
                'payment_event' // Add parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('Payment Failed', 'order-daemon'),
                'type'  => 'error'
            ],
        ],
        'refund_created' => [
            'id'             => 'refund_created',
            'label'          => __('Refund Created', 'order-daemon'),
            'renderer_class' => 'PaymentEventRenderer',
            'css_class'      => 'odcm-component--payment',
            'icon'           => 'dashicons-undo',
            'aliases'        => [
                'refund_issued',
                'refund_processed',
                'payment_event' // Add parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('Refund', 'order-daemon'),
                'type'  => 'warning'
            ],
        ],

        // Rule evaluation parent entry for RuleEvaluationRenderer
        'rule_evaluation' => [
            'id'             => 'rule_evaluation',
            'label'          => __('Rule Evaluation', 'order-daemon'),
            'renderer_class' => 'RuleEvaluationRenderer',
            'css_class'      => 'odcm-component--rule',
            'icon'           => 'dashicons-filter',
            'status_pill'    => [
                'label' => __('Rule', 'order-daemon'),
                'type'  => 'info'
            ],
        ],
        
        // Rule evaluation domain
        'rule_evaluated' => [
            'id'             => 'rule_evaluated',
            'label'          => __('Rule Evaluated', 'order-daemon'),
            'renderer_class' => 'RuleEvaluationRenderer',
            'css_class'      => 'odcm-component--rule',
            'icon'           => 'dashicons-filter',
            'aliases'        => [
                'rule_matched', 
                'rule_check', 
                'rule_evaluation_success',
                'rule_evaluation_started',
                'rule_evaluation_result',
                'rule_evaluation' // Parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('Rule Match', 'order-daemon'),
                'type'  => 'success'
            ],
        ],
        'decision' => [
            'id'             => 'decision',
            'label'          => __('Decision', 'order-daemon'),
            'renderer_class' => 'RuleEvaluationRenderer',
            'css_class'      => 'odcm-component--rule',
            'icon'           => 'dashicons-yes',
            'aliases'        => [
                'rule_evaluation' // Parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('Decision', 'order-daemon'),
                'type'  => 'info'
            ],
        ],
        'validation' => [
        'id'             => 'validation',
        'label'          => __('Validation Result', 'order-daemon'),
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--rule',
        'icon'           => 'dashicons-yes-alt',
        'status_pill'    => [
            'label' => __('Validated', 'order-daemon'),
            'type'  => 'success'
        ],
    ],
    'condition_passed' => [
        'id'             => 'condition_passed',
        'label'          => __('Condition Passed', 'order-daemon'),
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--success',
        'icon'           => 'dashicons-yes',
        'aliases'        => [
            'rule_evaluation' // Parent as an alias for consistent lookup
        ],
        'status_pill'    => [
            'label' => __('Passed', 'order-daemon'),
            'type'  => 'success'
        ],
    ],
        'condition_failed' => [
        'id'             => 'condition_failed',
        'label'          => __('Condition Failed', 'order-daemon'),
        'renderer_class' => 'RuleEvaluationRenderer',
        'css_class'      => 'odcm-component--warning',
        'icon'           => 'dashicons-warning',
        'aliases'        => ['rule_no_match', 'condition_not_met', 'no_rules_matched'],
        'status_pill'    => [
            'label' => __('Failed', 'order-daemon'),
            'type'  => 'warning'
        ],
    ],

        // WooCommerce parent entry for WooCommerceRenderer
        'woocommerce_data' => [
            'id'             => 'woocommerce_data',
            'label'          => __('WooCommerce Data', 'order-daemon'),
            'renderer_class' => 'WooCommerceRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-cart',
            'aliases'        => [
                'order_loaded',
                'block_checkout_processed',
                'status_changed',
                'stock_adjusted',
                'meta_updated'
            ],
            'status_pill'    => [
                'label' => __('WooCommerce', 'order-daemon'),
                'type'  => 'woocommerce'
            ],
        ],
        
        // WooCommerce domain (AFTER rule evaluation - less priority in canHandle() testing)
        'order_loaded' => [
            'id'             => 'order_loaded',
            'label'          => __('Order Loaded', 'order-daemon'),
            'renderer_class' => 'WooCommerceRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-cart',
            'aliases'        => [
                'order_created',
                'woocommerce_data' // Parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('WooCommerce', 'order-daemon'),
                'type'  => 'woocommerce'
            ],
        ],
        'block_checkout_processed' => [
            'id'             => 'block_checkout_processed',
            'label'          => __('Block Checkout Processed', 'order-daemon'),
            'renderer_class' => 'WooCommerceRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-cart',
            'aliases'        => [
                'woocommerce_data' // Parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('Checkout', 'order-daemon'),
                'type'  => 'woocommerce'
            ],
        ],
        'status_changed' => [
            'id'             => 'status_changed',
            'label'          => __('Order Status Changed', 'order-daemon'),
            'renderer_class' => 'WooCommerceRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-randomize',
            'aliases'        => [
                'status_change_processing',
                'status_evaluation',
                'woocommerce_data' // Parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('WooCommerce', 'order-daemon'),
                'type'  => 'woocommerce'
            ],
        ],
        'stock_adjusted' => [
            'id'             => 'stock_adjusted',
            'label'          => __('Stock Adjusted', 'order-daemon'),
            'renderer_class' => 'WooCommerceRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-archive',
            'aliases'        => [
                'woocommerce_data' // Parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('WooCommerce', 'order-daemon'),
                'type'  => 'woocommerce'
            ],
        ],
        'meta_updated' => [
            'id'             => 'meta_updated',
            'label'          => __('Order Meta Updated', 'order-daemon'),
            'renderer_class' => 'WooCommerceRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-admin-generic',
            'aliases'        => [
                'woocommerce_data' // Parent as an alias for consistent lookup
            ],
            'status_pill'    => [
                'label' => __('WooCommerce', 'order-daemon'),
                'type'  => 'woocommerce'
            ],
        ],

        // PayPal Events (Universal Event gateway-specific rendering)
        'paypal_event' => [
            'id'             => 'paypal_event',
            'label'          => __('PayPal Event', 'order-daemon'),
            'renderer_class' => 'PayPalEventRenderer',
            'css_class'      => 'odcm-component--paypal',
            'icon'           => 'dashicons-money-alt',
            'status_pill'    => [
                'label' => __('PayPal', 'order-daemon'),
                'type'  => 'gateway'
            ],
        ],

        // Stripe Events (Universal Event gateway-specific rendering)
        'stripe_event' => [
            'id'             => 'stripe_event',
            'label'          => __('Stripe Event', 'order-daemon'),
            'renderer_class' => 'StripeEventRenderer',
            'css_class'      => 'odcm-component--stripe',
            'icon'           => 'dashicons-money-alt',
            'status_pill'    => [
                'label' => __('Stripe', 'order-daemon'),
                'type'  => 'gateway'
            ],
        ],

        // Subscription Events (WooCommerce Subscriptions lifecycle rendering)
        'subscription_event' => [
            'id'             => 'subscription_event',
            'label'          => __('Subscription Event', 'order-daemon'),
            'renderer_class' => 'SubscriptionEventRenderer',
            'css_class'      => 'odcm-component--subscription',
            'icon'           => 'dashicons-update',
            'status_pill'    => [
                'label' => __('Subscription', 'order-daemon'),
                'type'  => 'subscription'
            ],
        ],

        // Outbound communications (distinct renderers)
        'http_webhook' => [
            'id'             => 'http_webhook',
            'label'          => __('Webhook Call', 'order-daemon'),
            'renderer_class' => 'HttpWebhookRenderer',
            'css_class'      => 'odcm-component--api',
            'icon'           => 'dashicons-rss',
            'status_pill'    => [
                'label' => __('Webhook', 'order-daemon'),
                'type'  => 'info'
            ],
        ],
        'email_action' => [
            'id'             => 'email_action',
            'label'          => __('Email Action', 'order-daemon'),
            'renderer_class' => 'EmailActionRenderer',
            'css_class'      => 'odcm-component--api',
            'icon'           => 'dashicons-email',
            'status_pill'    => [
                'label' => __('Email Sent', 'order-daemon'),
                'type'  => 'success'
            ],
        ],

        // Database domain (generalized)
        'database_query' => [
            'id'             => 'database_query',
            'label'          => __('Database Query', 'order-daemon'),
            'renderer_class' => 'DatabaseQueryRenderer',
            'css_class'      => 'odcm-component--database',
            'icon'           => 'dashicons-database',
        ],

        // Performance
        'metrics' => [
            'id'             => 'metrics',
            'label'          => __('Performance Metric', 'order-daemon'),
            'renderer_class' => 'PerformanceRenderer',
            'css_class'      => 'odcm-component--performance',
            'icon'           => 'dashicons-chart-line',
        ],

        // System/operational
        'action_scheduled' => [
            'id'             => 'action_scheduled',
            'label'          => __('Action Scheduled', 'order-daemon'),
            'renderer_class' => 'SystemRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-clock',
            'aliases'        => [
                'order_check_scheduled'
            ],
            'status_pill'    => [
                'label' => __('Scheduled', 'order-daemon'),
                'type'  => 'pending'
            ],
        ],
        'action_run' => [
            'id'             => 'action_run',
            'label'          => __('Action Executed', 'order-daemon'),
            'renderer_class' => 'SystemRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-controls-play',
            'aliases'        => ['action_executed', 'action_complete'],
            'status_pill'    => [
                'label' => __('Completed', 'order-daemon'),
                'type'  => 'completed'
            ],
        ],
        'info' => [
            'id'             => 'info',
            'label'          => __('Info', 'order-daemon'),
            'renderer_class' => 'SystemRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-info',
            'aliases'        => [
                'process_started', 
                'process_info',
                'admin_action',
                'event',
                'custom_event'
            ],
        ],
        'note_added' => [
            'id'             => 'note_added',
            'label'          => __('Note', 'order-daemon'),
            'renderer_class' => 'SystemRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-edit',
        ],
        'system_snapshot' => [
            'id'             => 'system_snapshot',
            'label'          => __('System Snapshot', 'order-daemon'),
            'renderer_class' => 'SystemRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-admin-tools',
        ],
        'dev_debug' => [
            'id'             => 'dev_debug',
            'label'          => __('Developer Debug', 'order-daemon'),
            'renderer_class' => 'SystemRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-admin-tools',
        ],

        // Errors and warnings
        'warning' => [
            'id'             => 'warning',
            'label'          => __('Warning', 'order-daemon'),
            'renderer_class' => 'ErrorRenderer',
            'css_class'      => 'odcm-component--error',
            'icon'           => 'dashicons-warning',
            'status_pill'    => [
                'label' => __('Warning', 'order-daemon'),
                'type'  => 'warning'
            ],
        ],
        'error' => [
            'id'             => 'error',
            'label'          => __('Error', 'order-daemon'),
            'renderer_class' => 'ErrorRenderer',
            'css_class'      => 'odcm-component--error',
            'icon'           => 'dashicons-dismiss',
            'status_pill'    => [
                'label' => __('Error', 'order-daemon'),
                'type'  => 'error'
            ],
        ],

        // Fallback
        'fallback' => [
            'id'             => 'fallback',
            'label'          => __('Additional Data', 'order-daemon'),
            'renderer_class' => 'FallbackRenderer',
            'css_class'      => 'odcm-component--fallback',
            'icon'           => 'dashicons-text-page',
        ],

        // === Refunds & Deletions (Narrative kinds) ===
        'refund_analysis' => [
            'id'             => 'refund_analysis',
            'label'          => __('Refund Analysis', 'order-daemon'),
            'renderer_class' => 'RefundAnalysisRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-money-alt',
            'category'       => 'woocommerce',
        ],
        'order_deletion' => [
            'id'             => 'order_deletion',
            'label'          => __('Order Deletion', 'order-daemon'),
            'renderer_class' => 'OrderDeletionRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-trash',
            'category'       => 'system',
        ],
        // Helpful mappings for related kinds used by diagnostics
        'system_info' => [
            'id'             => 'system_info',
            'label'          => __('System Info', 'order-daemon'),
            'renderer_class' => 'SystemInfoRenderer',
            'css_class'      => 'odcm-component--system',
            'icon'           => 'dashicons-admin-tools',
        ],
        'woocommerce_analysis' => [
            'id'             => 'woocommerce_analysis',
            'label'          => __('Order Impact', 'order-daemon'),
            'renderer_class' => 'WooCommerceRenderer',
            'css_class'      => 'odcm-component--woocommerce',
            'icon'           => 'dashicons-chart-pie',
        ],
    ];
}


/**
 * Get Payload Component Type by ID
 *
 * Retrieves a specific component type definition from the registry.
 *
 * @since 1.0.0
 *
 * @param string $component_id The component ID to retrieve.
 * @return array|null Component definition array or null if not found.
 *
 * @example
 * ```php
 * $api_component = odcm_get_payload_component_type('api_call');
 * if ($api_component) {
 *     $renderer = new $api_component['renderer_class']();
 * }
 * ```
 */
function odcm_get_payload_component_type(string $component_id): ?array
{
    $components = odcm_get_payload_component_types();
    return $components[$component_id] ?? null;
}

/**
 * Get Payload Component Type by Event Type (including aliases)
 *
 * Looks up a component type by its event_type, checking both the registry ID
 * and any defined aliases. This allows flexible mapping of event types
 * to their appropriate renderers.
 *
 * @since 1.0.0
 *
 * @param string $event_type The event type from payload data.
 * @return array|null Component definition array or null if not found.
 *
 * @example
 * ```php
 * // Direct match
 * $component = odcm_get_payload_component_type_by_event_type('rule_evaluated');
 * 
 * // Alias match (if 'rule_matched' is an alias for 'rule_evaluated')
 * $component = odcm_get_payload_component_type_by_event_type('rule_matched');
 * // Returns the same 'rule_evaluated' component definition
 * ```
 */
function odcm_get_payload_component_type_by_event_type(string $event_type): ?array
{
    $types = odcm_get_payload_component_types();
    
    // Direct match by registry ID
    if (isset($types[$event_type])) {
        return $types[$event_type];
    }
    
    // Search aliases
    foreach ($types as $type) {
        if (isset($type['aliases']) && is_array($type['aliases']) && in_array($event_type, $type['aliases'], true)) {
            return $type;
        }
    }
    
    // No match found
    return null;
}

/**
 * Find best renderer for component data using smart three-tier lookup
 *
 * This function implements intelligent renderer selection by combining
 * registry-based lookup with capability-based detection. It provides
 * robust renderer selection even when event types don't exactly
 * match registry entries.
 *
 * Three-tier lookup strategy:
 * 1. Registry lookup (fast path for exact/alias matches)
 * 2. Capability-based lookup (smart fallback using canHandle() methods)
 * 3. Fallback renderer (guaranteed fallback)
 *
 * @since 1.0.0
 *
 * @param string $event_type The event type from payload data.
 * @param array $data The component data to render.
 * @return array|null Component definition array or null if no renderer found.
 *
 * @example
 * ```php
 * // Smart lookup that handles mismatches
 * $def = odcm_find_best_renderer_for_data('info', $rule_data);
 * if ($def) {
 *     $renderer = new $def['renderer_class']();
 *     echo $renderer->render($rule_data);
 * }
 * ```
 */
function odcm_find_best_renderer_for_data(string $event_type, array $data): ?array
{
    // Enhanced debug logging for renderer selection process
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        error_log("ODCM RENDERER DEBUG: Starting lookup for event_type='$event_type'");
        error_log("ODCM RENDERER DEBUG: Data keys: " . implode(', ', array_keys($data)));
        error_log("ODCM RENDERER DEBUG: Data sample: " . substr(json_encode($data), 0, 200) . (strlen(json_encode($data)) > 200 ? '...' : ''));
    }
    
    // Tier 1: Registry lookup with aliases (fast path)
    $def = odcm_get_payload_component_type_by_event_type($event_type);
    if ($def) {
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $renderer_class = $def['renderer_class'] ?? 'none';
            error_log("ODCM RENDERER DEBUG: Tier 1 SUCCESS - Found registry match: event_type='$event_type' -> renderer='$renderer_class'");
        }
        return $def;
    }
    
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        error_log("ODCM RENDERER DEBUG: Tier 1 FAILED - No registry match for event_type='$event_type'");
        error_log("ODCM RENDERER DEBUG: Starting Tier 2 capability-based lookup...");
    }
    
    // Tier 2: Capability-based lookup (smart fallback)
    $types = odcm_get_payload_component_types();
    
    // Include event_type in data for canHandle() calls to provide complete context
    $data_with_event_type = array_merge($data, ['event_type' => $event_type]);
    
    $tier2_attempts = 0;
    foreach ($types as $type_id => $type) {
        if (!isset($type['renderer_class'])) {
            continue;
        }
        
        $renderer_class = $type['renderer_class'];
        $original_renderer_class = $renderer_class;
        
        // Add namespace if not fully qualified
        if (strpos($renderer_class, '\\') === false) {
            $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
        }
        
        $tier2_attempts++;
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM RENDERER DEBUG: Tier 2 attempt #$tier2_attempts - Testing type_id='$type_id', renderer='$original_renderer_class'");
            error_log("ODCM RENDERER DEBUG: Full renderer class: '$renderer_class'");
        }
        
        // Check if renderer class exists and can handle the data
        if (class_exists($renderer_class)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM RENDERER DEBUG: Class exists, attempting instantiation...");
            }
            
            try {
                $renderer = new $renderer_class();
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $has_canHandle = method_exists($renderer, 'canHandle');
                    error_log("ODCM RENDERER DEBUG: Instantiated successfully, has canHandle(): " . ($has_canHandle ? 'YES' : 'NO'));
                }
                
                // Check if renderer implements canHandle() and can handle this data
                // Pass data with event_type so renderers have complete context for matching
                if (method_exists($renderer, 'canHandle')) {
                    $can_handle_result = $renderer->canHandle($data_with_event_type);
                    
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        error_log("ODCM RENDERER DEBUG: canHandle() result: " . ($can_handle_result ? 'TRUE' : 'FALSE'));
                    }
                    
                    if ($can_handle_result) {
                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            error_log("ODCM RENDERER DEBUG: Tier 2 SUCCESS - Renderer '$original_renderer_class' can handle event_type='$event_type'");
                        }
                        return $type;
                    }
                } else {
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        error_log("ODCM RENDERER DEBUG: Renderer has no canHandle() method, skipping");
                    }
                }
            } catch (\Throwable $e) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log("ODCM RENDERER DEBUG: Instantiation failed: " . $e->getMessage());
                }
                // Skip renderer if instantiation fails
                continue;
            }
        } else {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM RENDERER DEBUG: Class '$renderer_class' does not exist");
            }
        }
    }
    
    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        error_log("ODCM RENDERER DEBUG: Tier 2 FAILED - No capable renderer found after $tier2_attempts attempts");
        error_log("ODCM RENDERER DEBUG: Tier 3 FALLBACK - Using fallback renderer for event_type='$event_type'");
    }
    
    // Tier 3: Fallback renderer (guaranteed fallback)
    return odcm_get_payload_component_type('fallback');
}
