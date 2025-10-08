<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry;

/**
 * REST API controller for the new Rule Builder.
 *
 * Provides endpoints for the frontend to fetch component definitions
 * and to get/save rule data.
 *
 * @package OrderDaemon\CompletionManager\API
 * @since   2.0.2
 */
class RuleBuilderApiController extends WP_REST_Controller
{
    /**
     * The namespace for this controller's route.
     *
     * @var string
     */
    protected $namespace = 'odcm/v1';

    /**
     * The base of this controller's route.
     *
     * @var string
     */
    protected $rest_base = 'rule-builder';

    private RuleComponentRegistry $component_registry;

    public function __construct()
    {
        $this->component_registry = new RuleComponentRegistry();
    }

    /**
     * Registers the routes for the objects of the controller.
     */
    public function register_routes()
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/components', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_components'],
                'permission_callback' => [$this, 'get_items_permissions_check'],
            ],
        ]);

        register_rest_route($this->namespace, '/rule/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_rule'],
                'permission_callback' => [$this, 'get_item_permissions_check'],
                'args'                => [
                    'id' => [
                        'description' => __('Unique identifier for the rule.'),
                        'type'        => 'integer',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'save_rule'],
                'permission_callback' => [$this, 'update_item_permissions_check'],
                'args'                => [
                    'id' => [
                        'description' => __('Unique identifier for the rule.'),
                        'type'        => 'integer',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/search-content', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'search_dynamic_content'],
                'permission_callback' => [$this, 'get_items_permissions_check'],
                'args'                => [
                    'source' => [
                        'description' => __('Data source to search (products, categories, posts, etc.)'),
                        'type'        => 'string',
                        'required'    => true,
                        'enum'        => ['products', 'categories', 'posts', 'users', 'order_statuses', 'payment_methods', 'shipping_methods', 'product_tags'],
                    ],
                    'search' => [
                        'description' => __('Search term'),
                        'type'        => 'string',
                        'default'     => '',
                    ],
                    'limit' => [
                        'description' => __('Maximum number of results'),
                        'type'        => 'integer',
                        'default'     => 50,
                        'minimum'     => 1,
                        'maximum'     => 100,
                    ],
                ],
            ],
        ]);

    }

    /**
     * Get all available and entitled components.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_components(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Start performance monitoring
            $start_time = microtime(true);
            
            // Ensure component registry is available
            if (!$this->component_registry) {
                $this->component_registry = new RuleComponentRegistry();
            }
            
            // Get components with error handling
            $triggers = $this->component_registry->get_triggers();
            $conditions = $this->component_registry->get_conditions();
            $actions = $this->component_registry->get_actions();
            
            // Categorize actions into primary (status-changing) and secondary
            $categorized_actions = $this->categorize_actions($actions);
            
            // Get current rule data for component state detection
            $rule_id = $request->get_param('rule_id');
            $current_rule_data = null;
            if ($rule_id) {
                $rule_data_json = get_post_meta((int) $rule_id, '_odcm_rule_data', true);
                if (!empty($rule_data_json)) {
                    $current_rule_data = json_decode($rule_data_json, true);
                }
            }
            
            // Format components for API response
            $components = [
                'triggers'         => $this->format_components($triggers, $current_rule_data),
                'conditions'       => $this->format_components($conditions, $current_rule_data),
                'primaryActions'   => $this->format_components($categorized_actions['primary'], $current_rule_data),
                'secondaryActions' => $this->format_components($categorized_actions['secondary'], $current_rule_data),
                // Keep legacy 'actions' for backward compatibility
                'actions'          => $this->format_components($categorized_actions['secondary'], $current_rule_data),
            ];
            
            // Performance monitoring
            $execution_time = microtime(true) - $start_time;
            $this->log_api_performance('get_components', $execution_time, [
                'triggers_count' => count($triggers),
                'conditions_count' => count($conditions),
                'primary_actions_count' => count($categorized_actions['primary']),
                'secondary_actions_count' => count($categorized_actions['secondary'])
            ]);
            
            return new WP_REST_Response([
                'triggers' => $components['triggers'],
                'conditions' => $components['conditions'],
                'primaryActions' => $components['primaryActions'],
                'secondaryActions' => $components['secondaryActions'],
                'actions' => $components['actions'], // Legacy compatibility
                'meta' => [
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                ],
            ], 200);
            
        } catch (\Exception $e) {
            // Log error for debugging
            $this->log_api_error('get_components', $e, []);
            
            return new WP_Error(
                'odcm_component_load_error',
                __('Failed to load rule builder components', 'order-daemon'),
                ['status' => 500]
            );
        }
    }

    /**
     * Get a single rule's data.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_rule(WP_REST_Request $request): WP_REST_Response
    {
        $rule_id = (int) $request['id'];
        $rule_data_json = get_post_meta($rule_id, '_odcm_rule_data', true);

        if (empty($rule_data_json)) {
            // Return a default, empty structure if no data exists yet
            $rule_data = [
                'trigger'          => null,
                'conditions'       => [],
                'primaryAction'    => [
                    'id'       => 'change_status_to_completed',
                    'settings' => []
                ],
                'secondaryActions' => [],
            ];
        } else {
            $rule_data = json_decode($rule_data_json, true);
            
            // Migration: Convert old structure to new structure
            if (isset($rule_data['action']) && !isset($rule_data['primaryAction'])) {
                $rule_data['primaryAction'] = $rule_data['action'];
                $rule_data['secondaryActions'] = [];
                unset($rule_data['action']);
            }
            
            // Ensure primary action exists
            if (!isset($rule_data['primaryAction'])) {
                $rule_data['primaryAction'] = [
                    'id'       => 'change_status_to_completed',
                    'settings' => []
                ];
            }
            
            // Ensure secondary actions array exists
            if (!isset($rule_data['secondaryActions'])) {
                $rule_data['secondaryActions'] = [];
            }
        }

        return new WP_REST_Response($rule_data, 200);
    }

    /**
     * Save a single rule's data with comprehensive entitlement validation and audit logging.
     *
     * This method follows WordPress best practices for saving post data, including:
     * - Proper nonce verification
     * - User capability checks
     * - Input sanitization
     * - WordPress hooks integration
     * - Complete error handling and response standardization
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function save_rule(WP_REST_Request $request): WP_REST_Response
    {
        $rule_id = (int) $request['id'];
        $json_params = $request->get_json_params();

        // Validate post ID exists and is valid
        $post = get_post($rule_id);
        if (!$post || $post->post_type !== 'odcm_order_rule') {
            return new WP_Error(
                'invalid_post_id', 
                __('Invalid completion rule ID.', 'order-daemon'), 
                ['status' => 404]
            );
        }

        // Validate JSON data exists
        if (empty($json_params)) {
            return new WP_Error(
                'invalid_data', 
                __('Invalid or empty rule data provided.', 'order-daemon'), 
                ['status' => 400]
            );
        }

        // Extract rule data and audit data from request
        $rule_data = $json_params['rule'] ?? $json_params;
        $audit_data = $json_params['audit'] ?? null;

        // WordPress hook before rule validation (allows for pre-processing)
        $rule_data = apply_filters('odcm_before_rule_validation', $rule_data, $rule_id, $post);

        // Validate rule data against user entitlements
        $validation_result = $this->validate_rule_entitlements($rule_data);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        // Sanitize and validate the rule data structure
        $sanitized_data = $this->sanitize_rule_data($rule_data);
        if (is_wp_error($sanitized_data)) {
            return $sanitized_data;
        }

        // WordPress hook before rule save (allows for modification)
        $sanitized_data = apply_filters('odcm_before_rule_save', $sanitized_data, $rule_id, $post);

        try {
            // Prepare the post data for update
            $post_data = [
                'ID' => $rule_id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true),
            ];
            
            // Update the post to update modification time
            $post_id = wp_update_post($post_data, true);
            
            if (is_wp_error($post_id)) {
                // Handle WordPress core error
                return new WP_Error(
                    'post_update_failed',
                    __('Failed to update rule post: ', 'order-daemon') . $post_id->get_error_message(),
                    ['status' => 500]
                );
            }
            
            // Save the rule data as post meta
            $json_data = wp_json_encode($sanitized_data);
            if (false === $json_data) {
                return new WP_Error(
                    'json_encode_failed',
                    __('Failed to encode rule data as JSON.', 'order-daemon'),
                    ['status' => 500]
                );
            }
            
            // Update the meta field with slashed data (WordPress requirement)
            $meta_result = update_post_meta($rule_id, '_odcm_rule_data', wp_slash($json_data));
            
            if (false === $meta_result && get_post_meta($rule_id, '_odcm_rule_data', true) !== $json_data) {
                return new WP_Error(
                    'meta_update_failed',
                    __('Failed to save rule data to post meta.', 'order-daemon'),
                    ['status' => 500]
                );
            }
            
            // Process audit logging if provided
            if ($audit_data && is_array($audit_data)) {
                $this->log_rule_audit_event($rule_id, $audit_data, $sanitized_data);
            }
            
            // Clear any caches
            clean_post_cache($rule_id);
            
            // WordPress hook after rule save (for post-processing)
            do_action('odcm_after_rule_save', $sanitized_data, $rule_id, $post);
            
            return new WP_REST_Response([
                'success' => true, 
                'message' => __('Rule saved successfully.', 'order-daemon'),
                'rule_id' => $rule_id,
                'audit_logged' => !empty($audit_data)
            ], 200);
            
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('ODCM Rule Save Error: ' . $e->getMessage());
            
            return new WP_Error(
                'rule_save_exception',
                __('An unexpected error occurred while saving the rule: ', 'order-daemon') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Validates that a rule only contains components and options the user can access.
     *
     * This is the security layer that prevents users from saving premium features
     * they don't have access to, even if they bypass frontend restrictions.
     *
     * @param array $rule_data The rule data to validate
     * @return true|WP_Error True if valid, WP_Error if validation fails
     */
    private function validate_rule_entitlements(array $rule_data)
    {
        $errors = [];

        // Validate trigger
        if (isset($rule_data['trigger']) && $rule_data['trigger']) {
            $trigger_data = $rule_data['trigger'];
            $trigger_error = $this->validate_component_entitlement($trigger_data, 'trigger');
            if (is_wp_error($trigger_error)) {
                $errors[] = $trigger_error->get_error_message();
            }
            // Defensive guard: explicitly block the premium "Any Status Change" trigger for free users
            if (is_array($trigger_data) && isset($trigger_data['id']) && $trigger_data['id'] === 'order_status_any_change') {
                if (!function_exists('odcm_can_use') || !odcm_can_use('trigger_premium')) {
                    $errors[] = __('Trigger "Any Status Change" is a Pro feature and cannot be saved without a license.', 'order-daemon');
                }
            }
        }

        // Validate conditions
        if (isset($rule_data['conditions']) && is_array($rule_data['conditions'])) {
            foreach ($rule_data['conditions'] as $index => $condition) {
                $condition_error = $this->validate_component_entitlement($condition, 'condition');
                if (is_wp_error($condition_error)) {
                    $errors[] = sprintf(__('Condition %d: %s', 'order-daemon'), $index + 1, $condition_error->get_error_message());
                }
            }
        }

        // Validate secondary actions
        if (isset($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
            foreach ($rule_data['secondaryActions'] as $index => $action) {
                $action_error = $this->validate_component_entitlement($action, 'action');
                if (is_wp_error($action_error)) {
                    $errors[] = sprintf(__('Action %d: %s', 'order-daemon'), $index + 1, $action_error->get_error_message());
                }
            }
        }

        if (!empty($errors)) {
            return new WP_Error(
                'odcm_premium_blocked',
                __('Rules containing Pro components can’t be saved without a license. Upgrade or remove Pro components to continue.', 'order-daemon'),
                ['status' => 403, 'violations' => $errors]
            );
        }

        return true;
    }

    /**
     * Validates that a user can access a specific component and its settings.
     *
     * @param array $component_data The component data to validate
     * @param string $component_type The type of component (trigger, condition, action)
     * @return true|WP_Error True if valid, WP_Error if validation fails
     */
    private function validate_component_entitlement(array $component_data, string $component_type)
    {
        if (!isset($component_data['id'])) {
            return new WP_Error('invalid_component', __('Component missing ID', 'order-daemon'));
        }

        $component_id = $component_data['id'];

        // Get the component instance to check its capability
        $component = $this->get_component_by_id($component_id, $component_type);
        if (!$component) {
            return new WP_Error('unknown_component', sprintf(__('Unknown %s: %s', 'order-daemon'), $component_type, $component_id));
        }

        // Check if user can access this component
        if (!function_exists('odcm_can_use') || !odcm_can_use($component->get_capability())) {
            return new WP_Error(
                'premium_component',
                sprintf(__('%s "%s" requires Pro access', 'order-daemon'), ucfirst($component_type), $component->get_label())
            );
        }

        // Validate component settings against schema and entitlements
        if (isset($component_data['settings']) && is_array($component_data['settings'])) {
            $settings_error = $this->validate_component_settings($component_data['settings'], $component);
            if (is_wp_error($settings_error)) {
                return $settings_error;
            }
        }

        return true;
    }

    /**
     * Validates component settings against schema and entitlement restrictions.
     *
     * @param array $settings The settings to validate
     * @param ComponentInterface $component The component instance
     * @return true|WP_Error True if valid, WP_Error if validation fails
     */
    private function validate_component_settings(array $settings, $component)
    {
        $schema = $component->get_settings_schema();
        if (!$schema || !isset($schema['properties'])) {
            return true; // No schema to validate against
        }

        foreach ($settings as $setting_key => $setting_value) {
            if (!isset($schema['properties'][$setting_key])) {
                continue; // Skip unknown settings (they'll be filtered out during sanitization)
            }

            $property = $schema['properties'][$setting_key];
            
            // Validate tiered checkbox selections
            if (isset($property['ui:widget']) && $property['ui:widget'] === 'tiered_checkboxes') {
                $validation_error = $this->validate_tiered_checkbox_settings($setting_value, $property);
                if (is_wp_error($validation_error)) {
                    return $validation_error;
                }
            }
        }

        return true;
    }

    /**
     * Validates tiered checkbox settings against user entitlements.
     *
     * @param mixed $setting_value The setting value to validate
     * @param array $property The property schema
     * @return true|WP_Error True if valid, WP_Error if validation fails
     */
    private function validate_tiered_checkbox_settings($setting_value, array $property)
    {
        if (!is_array($setting_value) || !isset($property['ui:tiers'])) {
            return true;
        }

        // Build a map of accessible options
        $accessible_options = [];
        foreach ($property['ui:tiers'] as $tier_id => $tier_config) {
            $tier_accessible = true;
            if (isset($tier_config['capability'])) {
                $tier_accessible = function_exists('odcm_can_use') && odcm_can_use($tier_config['capability']);
            }

            if ($tier_accessible && isset($tier_config['types'])) {
                $accessible_options = array_merge($accessible_options, $tier_config['types']);
            }
        }

        // Check if any selected options are not accessible
        $inaccessible_options = array_diff($setting_value, $accessible_options);
        if (!empty($inaccessible_options)) {
            return new WP_Error(
                'premium_options',
                sprintf(__('The following options require Pro access: %s'), implode(', ', $inaccessible_options))
            );
        }

        return true;
    }

    /**
     * Gets a component instance by ID and type.
     *
     * @param string $component_id The component ID
     * @param string $component_type The component type (trigger, condition, action)
     * @return ComponentInterface|null The component instance or null if not found
     */
    private function get_component_by_id(string $component_id, string $component_type)
    {
        switch ($component_type) {
            case 'trigger':
                $components = $this->component_registry->get_triggers();
                break;
            case 'condition':
                $components = $this->component_registry->get_conditions();
                break;
            case 'action':
                $components = $this->component_registry->get_actions();
                break;
            default:
                return null;
        }

        return $components[$component_id] ?? null;
    }

    /**
     * Sanitizes and validates rule data structure.
     *
     * @param array $rule_data The raw rule data
     * @return array|WP_Error The sanitized data or WP_Error on failure
     */
    private function sanitize_rule_data(array $rule_data)
    {
        $sanitized = [];

        // Sanitize trigger
        if (isset($rule_data['trigger'])) {
            if (is_array($rule_data['trigger']) && isset($rule_data['trigger']['id'])) {
                $sanitized['trigger'] = [
                    'id' => sanitize_text_field($rule_data['trigger']['id']),
                    'settings' => $this->sanitize_settings($rule_data['trigger']['settings'] ?? [])
                ];
            } else {
                $sanitized['trigger'] = null;
            }
        }

        // Sanitize conditions
        $sanitized['conditions'] = [];
        if (isset($rule_data['conditions']) && is_array($rule_data['conditions'])) {
            foreach ($rule_data['conditions'] as $condition) {
                if (is_array($condition) && isset($condition['id'])) {
                    $sanitized['conditions'][] = [
                        'id' => sanitize_text_field($condition['id']),
                        'settings' => $this->sanitize_settings($condition['settings'] ?? [])
                    ];
                }
            }
        }

        // Sanitize primary action (always present)
        $sanitized['primaryAction'] = [
            'id' => 'change_status_to_completed',
            'settings' => []
        ];

        // Sanitize secondary actions
        $sanitized['secondaryActions'] = [];
        if (isset($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
            foreach ($rule_data['secondaryActions'] as $action) {
                if (is_array($action) && isset($action['id'])) {
                    $sanitized['secondaryActions'][] = [
                        'id' => sanitize_text_field($action['id']),
                        'settings' => $this->sanitize_settings($action['settings'] ?? [])
                    ];
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitizes component settings.
     *
     * @param array $settings The settings to sanitize
     * @return array The sanitized settings
     */
    private function sanitize_settings(array $settings): array
    {
        $sanitized = [];

        foreach ($settings as $key => $value) {
            $clean_key = sanitize_key($key);
            
            if (is_array($value)) {
                $sanitized[$clean_key] = $this->sanitize_settings($value);
            } elseif (is_bool($value)) {
                $sanitized[$clean_key] = (bool) $value;
            } elseif (is_numeric($value)) {
                $sanitized[$clean_key] = is_float($value) ? (float) $value : absint($value);
            } else {
                $sanitized[$clean_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Check API permissions
     * 
     * Rule building is core functionality available to all users with WooCommerce management capabilities.
     * Individual components have their own entitlement checks for premium features.
     */
    public function get_items_permissions_check($request)
    {
        // Basic capability check - rule building is core functionality
        if (!current_user_can('manage_woocommerce')) {
            return new WP_Error('rest_forbidden', esc_html__('You do not have permissions to view these components.'), ['status' => 401]);
        }

        return true;
    }

    /**
     * Check if a given request has permission to get a specific rule.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True if the request has read access for the item, WP_Error object otherwise.
     */
    public function get_item_permissions_check($request)
    {
        if (!current_user_can('edit_post', (int) $request['id'])) {
            return new WP_Error('rest_forbidden', esc_html__('You do not have permissions to view this rule.'), ['status' => 401]);
        }
        return true;
    }

    /**
     * Check if a given request has permission to update a specific rule.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True if the request has read access for the item, WP_Error object otherwise.
     */
    public function update_item_permissions_check($request)
    {
        if (!current_user_can('edit_post', (int) $request['id'])) {
            return new WP_Error('rest_forbidden', esc_html__('You do not have permissions to save this rule.'), ['status' => 401]);
        }
        return true;
    }

    /**
     * Permission check for POST endpoints that require nonce and capability.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error
     */
    /**
     * Permission callback for POST requests that require nonce verification.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return bool|WP_Error True if user has permission, WP_Error otherwise.
     */
    public function post_permissions_check(WP_REST_Request $request): bool|WP_Error
    {
        // Check user capability
        if (!current_user_can('manage_woocommerce')) {
            return new WP_Error(
                'odcm_insufficient_permissions',
                __('You do not have permission to access this resource.', 'order-daemon'),
                ['status' => 403]
            );
        }

        // Verify nonce for POST requests
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'odcm_invalid_nonce',
                __('Invalid nonce. Please refresh the page and try again.', 'order-daemon'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Generate sanitized HTML summary for a single rule component.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */

    /**
     * Formats a list of components for the API response with entitlement-aware display.
     *
     * This method includes all components but marks premium ones appropriately.
     * Premium components that users can't access are included with restricted schemas
     * and premium indicators for better UX.
     *
     * @param Interfaces\ComponentInterface[] $components
     * @param array|null $current_rule_data Current rule data for state detection
     * @return array
     */
    private function format_components(array $components, ?array $current_rule_data = null): array
    {
        $formatted = [];
        foreach ($components as $component) {
            // Complete list of all free component capabilities
            $free_capabilities = [
                'free',
                'condition_product_type',
                'condition_single_category',
                'condition_order_total',
                'trigger_basic',
                'action_basic',
                'free_trigger',
                'free_condition',
                'free_action'
            ];
            
            // Complete list of all free component IDs for explicit matching
            // Updated to ensure consistent identification of core free components
            $free_component_ids = [
                'order_processing',       // Free trigger
                'order_total_amount',     // Free condition
                'product_type',           // Free condition
                'product_category',       // Free condition
                'change_status_to_completed', // Free action
                'order_processing_trigger', // Alternative naming
                'order_total_condition',  // Alternative naming
                'product_category_condition', // Alternative naming
                'product_type_condition'  // Alternative naming
            ];
            
            // Determine if component is free by capability OR explicit ID
            $capability = $component->get_capability();
            $component_id = $component->get_id();
            
            // Force-mark specific components as free regardless of their current capability
            $is_core_free_component = in_array($component_id, $free_component_ids);
            
            $is_free = ($is_core_free_component || 
                       in_array($capability, $free_capabilities) || 
                       strpos($capability, 'free_') === 0);
            
            $can_use = $is_free || (function_exists('odcm_can_use') && odcm_can_use($capability));
            $already_in_rule = $this->is_component_in_current_rule($component_id, $current_rule_data);

            // SERVER-SIDE FILTER: If user can't use it and it's not in the rule, hide it from selection lists
            if (!$can_use && !$already_in_rule) {
                continue;
            }
            
            // Determine component state for graceful degradation
            $component_state = 'available';
            if (!$can_use && $already_in_rule) {
                $component_state = 'already_selected_unavailable';
            } elseif (!$can_use) {
                $component_state = 'unavailable';
            }
            
            // Get ordering metadata from component if available
            $is_default = method_exists($component, 'is_default') ? $component->is_default() : false;
            $priority = method_exists($component, 'get_priority') ? $component->get_priority() : 999;
            
            // Assign consistent priorities based on component ID regardless of free/premium status
            // This ensures consistent ordering across both versions
            // FIXED: Ensuring order_processing is ALWAYS at the top of the trigger list
            if ($component_id === 'order_processing' || $component_id === 'order_processing_trigger') {
                $priority = 1; // Top trigger - CORE FREE FEATURE
            } else if ($component_id === 'order_total_amount' || $component_id === 'order_total_condition') {
                $priority = 2; // Top condition - CORE FREE FEATURE
            } else if ($component_id === 'product_category' || $component_id === 'product_category_condition') {
                $priority = 3; // Second condition - CORE FREE FEATURE
            } else if ($component_id === 'product_type' || $component_id === 'product_type_condition') {
                $priority = 4; // Third condition - CORE FREE FEATURE
            } else if ($component_id === 'change_status_to_completed') {
                $priority = 1; // Top action - CORE FREE FEATURE
            } else if ($is_free) {
                $priority = 10; // Other free components
            } else {
                // Premium components maintain their original priority but offset to come after free
                $priority = 100 + $priority;
            }
            
            // For already selected unavailable components, provide full schema for editing
            // Ensure schema is NULL for inaccessible components not in the rule
            $schema = ($can_use || $component_state === 'already_selected_unavailable') ? 
                     $component->get_settings_schema() : null;
            
            // Defense-in-depth: if somehow schema exists for inaccessible components not in rule, nullify it
            if ($schema && !$can_use && !$already_in_rule) {
                $schema = null;
            }

            if ($schema) {
                // Always filter schema by entitlement, regardless of component accessibility
                // This ensures free users get filtered options even for accessible components
                $schema = $this->filter_schema_by_entitlement($schema);
            }
            
            // Force specific components to always be treated as free (core features)
            if ($is_core_free_component) {
                $capability = 'free'; // Override any other capability to ensure no premium badge
            }
            
            $formatted[] = [
                'id'          => $component->get_id(),
                'label'       => $component->get_label(),
                'description' => $component->get_description(),
                'schema'      => $schema,
                'capability'  => $is_free ? 'free' : $component->get_capability(), // Mark free components with 'free' capability
                'accessible'  => $can_use,
                'state'       => $component_state,
                'already_in_rule' => $already_in_rule,
                'is_default'  => $is_default,
                'priority'    => $priority,
                'is_free'     => $is_free, // Add explicit is_free property for sorting
                'is_core_free' => $is_core_free_component, // Mark core free components explicitly
            ];
        }
        
        // Sort components by accessibility and priority
        return $this->sort_components($formatted);
    }

    /**
     * Checks if a component is already part of the current rule.
     *
     * @param string $component_id The component ID to check
     * @param array|null $rule_data The current rule data
     * @return bool True if component is in the rule
     */
    private function is_component_in_current_rule(string $component_id, ?array $rule_data): bool
    {
        if (!$rule_data) {
            return false;
        }

        // Check trigger
        if (isset($rule_data['trigger']['id']) && $rule_data['trigger']['id'] === $component_id) {
            return true;
        }

        // Check conditions
        if (isset($rule_data['conditions']) && is_array($rule_data['conditions'])) {
            foreach ($rule_data['conditions'] as $condition) {
                if (isset($condition['id']) && $condition['id'] === $component_id) {
                    return true;
                }
            }
        }

        // Check primary action
        if (isset($rule_data['primaryAction']['id']) && $rule_data['primaryAction']['id'] === $component_id) {
            return true;
        }

        // Check secondary actions
        if (isset($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
            foreach ($rule_data['secondaryActions'] as $action) {
                if (isset($action['id']) && $action['id'] === $component_id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Filters component schemas to remove premium options based on user entitlements.
     *
     * This method ensures that even within accessible components, premium-only options
     * are completely removed from the schema. This prevents free users from seeing
     * or accessing premium features within freemium components.
     *
     * @param array|null $schema The component's settings schema
     * @return array|null The filtered schema with premium options removed
     */
    private function filter_schema_by_entitlement(?array $schema): ?array
    {
        if (!$schema || !isset($schema['properties'])) {
            return $schema;
        }

        // Process each property in the schema
        foreach ($schema['properties'] as $property_key => &$property) {
            $property = $this->filter_property_by_entitlement($property, $property_key);
        }

        return $schema;
    }

    /**
     * Filters individual schema properties based on entitlements.
     *
     * Handles different property types and their entitlement requirements:
     * - Tiered checkboxes (ui:tiers with capability requirements)
     * - Searchable checkboxes (enum options with premium restrictions)
     * - Regular enums with premium options
     *
     * @param array $property The schema property to filter
     * @param string $property_key The property key for context
     * @return array The filtered property
     */
    private function filter_property_by_entitlement(array $property, string $property_key): array
    {
        // Handle tiered checkboxes (like ProductTypeCondition)
        if (isset($property['ui:widget']) && $property['ui:widget'] === 'tiered_checkboxes') {
            $property = $this->filter_tiered_checkboxes($property);
        }
        
        // Handle searchable checkboxes (like ShippingMethodCondition)
        elseif (isset($property['ui:widget']) && $property['ui:widget'] === 'searchable_checkboxes') {
            $property = $this->filter_searchable_checkboxes($property);
        }
        
        // Handle regular enums with potential premium options
        elseif (isset($property['enum'])) {
            $property = $this->filter_enum_options($property);
        }
        
        // Handle array items with enums (like multi-select options)
        elseif (isset($property['items']['enum'])) {
            $property['items'] = $this->filter_enum_options($property['items']);
        }

        return $property;
    }

    /**
     * Filters tiered checkbox options based on user capabilities.
     *
     * Removes entire tiers that the user cannot access and filters individual
     * options within accessible tiers based on their capability requirements.
     *
     * @param array $property The tiered checkboxes property
     * @return array The filtered property
     */
    private function filter_tiered_checkboxes(array $property): array
    {
        if (!isset($property['ui:tiers']) || !isset($property['items']['enum'])) {
            return $property;
        }

        $filtered_tiers = [];
        $filtered_enum = [];
        
        foreach ($property['ui:tiers'] as $tier_id => $tier_config) {
            // Check if user can access this tier
            $tier_accessible = true;
            if (isset($tier_config['capability'])) {
                $tier_accessible = function_exists('odcm_can_use') && odcm_can_use($tier_config['capability']);
            }

            if ($tier_accessible) {
                // Include the tier and its options
                $filtered_tiers[$tier_id] = $tier_config;
                
                // Add tier's options to the enum
                if (isset($tier_config['types'])) {
                    foreach ($tier_config['types'] as $type_id) {
                        if (isset($property['items']['enum'][$type_id])) {
                            $filtered_enum[$type_id] = $property['items']['enum'][$type_id];
                        }
                    }
                }
            }
            // Premium tiers are completely omitted
        }

        // Update the property with filtered data
        $property['ui:tiers'] = $filtered_tiers;
        $property['items']['enum'] = $filtered_enum;

        return $property;
    }

    /**
     * Filters searchable checkbox options based on entitlements.
     *
     * For searchable checkboxes, we filter the enum options to only include
     * those the user has access to. Premium options are completely removed.
     *
     * @param array $property The searchable checkboxes property
     * @return array The filtered property
     */
    private function filter_searchable_checkboxes(array $property): array
    {
        if (isset($property['items']['enum'])) {
            $property['items'] = $this->filter_enum_options($property['items']);
        }
        return $property;
    }

    /**
     * Filters enum options based on entitlements.
     *
     * This is a generic method for filtering enum options. Currently, it passes
     * through all options, but can be extended to handle specific premium enum
     * restrictions based on option keys or values.
     *
     * @param array $enum_container The container with 'enum' key
     * @return array The filtered container
     */
    private function filter_enum_options(array $enum_container): array
    {
        if (!isset($enum_container['enum'])) {
            return $enum_container;
        }

        // For now, pass through all enum options
        // This can be extended to filter specific premium options
        // based on naming conventions or explicit capability mappings
        
        return $enum_container;
    }

    /**
     * Checks if a user can access a specific option within a component.
     *
     * This method can be extended to handle option-level entitlement checking
     * for fine-grained control over individual options within components.
     *
     * @param string $option_key The option key to check
     * @param string $component_context Optional component context
     * @return bool True if user can access the option
     */
    private function can_use_option(string $option_key, string $component_context = ''): bool
    {
        // Default: allow all options for accessible components
        // This can be extended for option-level entitlement checking
        return true;
    }

    /**
     * Log API performance metrics
     */
    private function log_api_performance(string $endpoint, float $execution_time, array $context): void
    {
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }

        // Log slow API calls (>1 second)
        if ($execution_time > 1.0) {
            error_log(sprintf(
                'ODCM API Slow Call: %s took %.3fs - Context: %s',
                $endpoint,
                $execution_time,
                json_encode($context)
            ));
        }
    }

    /**
     * Categorizes actions into primary (status-changing) and secondary actions.
     *
     * Primary actions are status-changing actions that can serve as the main action of a rule.
     * Secondary actions are supplementary actions like sending emails, notifications, etc.
     *
     * @param array $actions All action components
     * @return array Array with 'primary' and 'secondary' keys containing categorized actions
     */
    private function categorize_actions(array $actions): array
    {
        $primary_actions = [];
        $secondary_actions = [];
        
        // Define which action IDs are considered primary (status-changing)
        $primary_action_ids = [
            'change_status_to_completed',
            'change_status_to_processing', 
            'change_status_to_on_hold',
            // Add more status-changing actions here as they're created
        ];
        
        foreach ($actions as $action_id => $action) {
            if (in_array($action_id, $primary_action_ids)) {
                $primary_actions[$action_id] = $action;
            } else {
                $secondary_actions[$action_id] = $action;
            }
        }
        
        return [
            'primary' => $primary_actions,
            'secondary' => $secondary_actions
        ];
    }

    /**
     * Sorts components by accessibility and priority for optimal user experience.
     *
     * Sorting order:
     * 1. Accessible components first, inaccessible last
     * 2. Within accessible: default/free first, then by priority
     * 3. Within inaccessible: by priority
     *
     * @param array $components The formatted components to sort
     * @return array The sorted components
     */
    private function sort_components(array $components): array
    {
        usort($components, function($a, $b) {
            // 1. Free components first, then accessible premium, then inaccessible premium
            if (isset($a['is_free']) && isset($b['is_free']) && $a['is_free'] !== $b['is_free']) {
                return $b['is_free'] - $a['is_free']; // free (true=1) before premium (false=0)
            }
            
            // 2. For non-free components, accessible first
            if ((!isset($a['is_free']) || !$a['is_free']) && $a['accessible'] !== $b['accessible']) {
                return $b['accessible'] - $a['accessible'];
            }
            
            // 3. Then by priority (lower number = higher priority)
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] - $b['priority'];
            }
            
            // 4. Finally by label alphabetically
            return strcmp($a['label'], $b['label']);
        });
        
        return $components;
    }

    /**
     * Logs rule audit events for comprehensive change tracking.
     *
     * This method processes audit data from the frontend and logs it using
     * the plugin's audit trail system for compliance and debugging purposes.
     *
     * @param int $rule_id The rule ID being modified
     * @param array $audit_data The audit data from the frontend
     * @param array $sanitized_rule_data The final sanitized rule data
     */
    private function log_rule_audit_event(int $rule_id, array $audit_data, array $sanitized_rule_data): void
    {
        try {
            // Sanitize audit data
            $action = sanitize_text_field($audit_data['action'] ?? 'rule_modified');
            $before_data = $audit_data['before_data'] ?? null;
            $after_data = $sanitized_rule_data; // Use sanitized data, not raw frontend data
            $user_context = $audit_data['user_context'] ?? [];

            // Prepare comprehensive audit log entry
            $audit_entry = [
                'rule_id' => $rule_id,
                'action' => $action,
                'before_data' => $before_data,
                'after_data' => $after_data,
                'user_context' => [
                    'timestamp' => current_time('mysql'),
                    'user_id' => get_current_user_id(),
                    'user_agent' => sanitize_text_field($user_context['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''),
                    'ip_address' => $this->get_client_ip(),
                    'page_url' => esc_url_raw($user_context['page_url'] ?? ''),
                    'frontend_timestamp' => sanitize_text_field($user_context['timestamp'] ?? ''),
                ],
                'changes_summary' => $this->generate_changes_summary($before_data, $after_data),
                'component_counts' => $this->count_rule_components($after_data),
            ];

            // Log using the plugin's audit system if available
            if (function_exists('odcm_log_custom_event')) {
                $message = $this->generate_audit_message($action, $rule_id, $audit_entry['changes_summary']);
                
                odcm_log_custom_event(
                    $message,
                    $audit_entry,
                    null, // No specific order ID
                    'info',
                    'rule_builder',
                    true // Force logging even if disabled
                );
            } else {
                // Fallback to WordPress error log
                error_log(sprintf(
                    'ODCM Rule Audit: %s for Rule #%d - Changes: %s',
                    $action,
                    $rule_id,
                    $audit_entry['changes_summary']
                ));
            }

            // Store audit metadata in post meta for quick access
            $this->store_rule_audit_metadata($rule_id, $audit_entry);

        } catch (\Exception $e) {
            // Log audit logging errors
            error_log(sprintf(
                'ODCM Audit Logging Error for Rule #%d: %s',
                $rule_id,
                $e->getMessage()
            ));
        }
    }

    /**
     * Generates a human-readable audit message.
     *
     * @param string $action The audit action
     * @param int $rule_id The rule ID
     * @param string $changes_summary The changes summary
     * @return string The audit message
     */
    private function generate_audit_message(string $action, int $rule_id, string $changes_summary): string
    {
        $user = wp_get_current_user();
        $user_display = $user->display_name ?: $user->user_login;
        
        switch ($action) {
            case 'rule_created':
                return sprintf(
                    'Rule #%d created by %s - %s',
                    $rule_id,
                    $user_display,
                    $changes_summary
                );
            case 'rule_modified':
                return sprintf(
                    'Rule #%d modified by %s - %s',
                    $rule_id,
                    $user_display,
                    $changes_summary
                );
            default:
                return sprintf(
                    'Rule #%d %s by %s - %s',
                    $rule_id,
                    $action,
                    $user_display,
                    $changes_summary
                );
        }
    }

    /**
     * Generates a summary of changes between before and after rule data.
     *
     * @param array|null $before_data The rule data before changes
     * @param array $after_data The rule data after changes
     * @return string A human-readable summary of changes
     */
    private function generate_changes_summary(?array $before_data, array $after_data): string
    {
        if (!$before_data) {
            return sprintf(
                'New rule with %d conditions, %d secondary actions',
                count($after_data['conditions'] ?? []),
                count($after_data['secondaryActions'] ?? [])
            );
        }

        $changes = [];

        // Check trigger changes
        $before_trigger = $before_data['trigger']['id'] ?? null;
        $after_trigger = $after_data['trigger']['id'] ?? null;
        if ($before_trigger !== $after_trigger) {
            $changes[] = sprintf('trigger: %s → %s', $before_trigger ?: 'none', $after_trigger ?: 'none');
        }

        // Check condition changes
        $before_conditions = count($before_data['conditions'] ?? []);
        $after_conditions = count($after_data['conditions'] ?? []);
        if ($before_conditions !== $after_conditions) {
            $changes[] = sprintf('conditions: %d → %d', $before_conditions, $after_conditions);
        }

        // Check secondary action changes
        $before_actions = count($before_data['secondaryActions'] ?? []);
        $after_actions = count($after_data['secondaryActions'] ?? []);
        if ($before_actions !== $after_actions) {
            $changes[] = sprintf('secondary actions: %d → %d', $before_actions, $after_actions);
        }

        return $changes ? implode(', ', $changes) : 'settings updated';
    }

    /**
     * Counts components in a rule for audit metadata.
     *
     * @param array $rule_data The rule data
     * @return array Component counts
     */
    private function count_rule_components(array $rule_data): array
    {
        return [
            'has_trigger' => !empty($rule_data['trigger']),
            'conditions_count' => count($rule_data['conditions'] ?? []),
            'has_primary_action' => !empty($rule_data['primaryAction']),
            'secondary_actions_count' => count($rule_data['secondaryActions'] ?? []),
        ];
    }

    /**
     * Stores audit metadata in post meta for quick access and reporting.
     *
     * @param int $rule_id The rule ID
     * @param array $audit_entry The audit entry data
     */
    private function store_rule_audit_metadata(int $rule_id, array $audit_entry): void
    {
        // Store last modified timestamp
        update_post_meta($rule_id, '_odcm_last_modified', current_time('mysql'));
        
        // Store last modified user
        update_post_meta($rule_id, '_odcm_last_modified_by', get_current_user_id());
        
        // Store component counts for quick filtering
        update_post_meta($rule_id, '_odcm_component_counts', $audit_entry['component_counts']);
        
        // Store audit trail reference (for linking to full audit logs)
        $audit_reference = [
            'timestamp' => $audit_entry['user_context']['timestamp'],
            'action' => $audit_entry['action'],
            'changes_summary' => $audit_entry['changes_summary'],
        ];
        update_post_meta($rule_id, '_odcm_last_audit', $audit_reference);
    }

    /**
     * Gets the client IP address for audit logging.
     *
     * @return string The client IP address
     */
    private function get_client_ip(): string
    {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field($_SERVER[$key]);
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * Search dynamic content for use in component settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function search_dynamic_content(WP_REST_Request $request): WP_REST_Response
    {
        $source = $request->get_param('source');
        $search = $request->get_param('search');
        $limit = (int) $request->get_param('limit');

        try {
            $start_time = microtime(true);
            
            $results = $this->perform_content_search($source, $search, $limit);
            
            $execution_time = microtime(true) - $start_time;
            $this->log_api_performance('search_dynamic_content', $execution_time, [
                'source' => $source,
                'search_term' => $search,
                'results_count' => count($results),
                'limit' => $limit
            ]);

            return new WP_REST_Response([
                'results' => $results,
                'meta' => [
                    'source' => $source,
                    'search_term' => $search,
                    'count' => count($results),
                    'execution_time' => $execution_time,
                ]
            ], 200);

        } catch (\Exception $e) {
            $this->log_api_error('search_dynamic_content', $e, [
                'source' => $source,
                'search' => $search,
                'limit' => $limit
            ]);

            return new WP_Error(
                'search_error',
                __('Failed to search content', 'order-daemon'),
                ['status' => 500]
            );
        }
    }

    /**
     * Performs the actual content search based on source type.
     *
     * @param string $source The data source to search
     * @param string $search The search term
     * @param int $limit Maximum number of results
     * @return array Array of search results
     */
    private function perform_content_search(string $source, string $search, int $limit): array
    {
        switch ($source) {
            case 'products':
                return $this->search_products($search, $limit);
            case 'categories':
                return $this->search_product_categories($search, $limit);
            case 'posts':
                return $this->search_posts_and_pages($search, $limit);
            case 'users':
                return $this->search_user_types($search, $limit);
            case 'order_statuses':
                return $this->search_order_statuses($search, $limit);
            case 'payment_methods':
                return $this->search_payment_methods($search, $limit);
            case 'shipping_methods':
                return $this->search_shipping_methods($search, $limit);
            case 'product_tags':
                return $this->search_product_tags($search, $limit);
            default:
                return [];
        }
    }

    /**
     * Search WooCommerce products by ID, title, and SKU.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array Product results
     */
    private function search_products(string $search, int $limit): array
    {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            return [];
        }

        $search = sanitize_text_field($search);
        $limit = max(1, min($limit, 100)); // Ensure reasonable limits

        // Build search query for products
        $sql = "
            SELECT DISTINCT p.ID, p.post_title, pm.meta_value as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
        ";

        $where_conditions = [];
        $search_params = [];

        if (!empty($search)) {
            // Search by ID (exact match)
            if (is_numeric($search)) {
                $where_conditions[] = "p.ID = %d";
                $search_params[] = (int) $search;
            }
            
            // Search by title (partial match)
            $where_conditions[] = "p.post_title LIKE %s";
            $search_params[] = '%' . $wpdb->esc_like($search) . '%';
            
            // Search by SKU (partial match)
            $where_conditions[] = "pm.meta_value LIKE %s";
            $search_params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($where_conditions)) {
            $sql .= " AND (" . implode(' OR ', $where_conditions) . ")";
        }

        $sql .= " ORDER BY p.post_title ASC LIMIT %d";
        $search_params[] = $limit;

        $results = $wpdb->get_results($wpdb->prepare($sql, $search_params));

        $formatted_results = [];
        foreach ($results as $product) {
            $display_title = $product->post_title;
            if (!empty($product->sku)) {
                $display_title .= " (SKU: {$product->sku})";
            }
            $display_title .= " (ID: {$product->ID})";

            $formatted_results[] = [
                'value' => (string) $product->ID,
                'label' => $display_title,
                'meta' => [
                    'id' => $product->ID,
                    'title' => $product->post_title,
                    'sku' => $product->sku ?: '',
                ]
            ];
        }

        return $formatted_results;
    }

    /**
     * Search product categories.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array Category results
     */
    private function search_product_categories(string $search, int $limit): array
    {
        if (!class_exists('WooCommerce')) {
            return [];
        }

        $args = [
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => $limit,
            'orderby' => 'name',
            'order' => 'ASC',
        ];

        if (!empty($search)) {
            $args['name__like'] = sanitize_text_field($search);
        }

        $categories = get_terms($args);
        if (is_wp_error($categories)) {
            return [];
        }

        $formatted_results = [];
        foreach ($categories as $category) {
            $formatted_results[] = [
                'value' => (string) $category->term_id,
                'label' => $category->name . " (ID: {$category->term_id})",
                'meta' => [
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'count' => $category->count,
                ]
            ];
        }

        return $formatted_results;
    }

    /**
     * Search posts and pages.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array Post results
     */
    private function search_posts_and_pages(string $search, int $limit): array
    {
        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if (!empty($search)) {
            if (is_numeric($search)) {
                // Search by ID
                $args['p'] = (int) $search;
            } else {
                // Search by title
                $args['s'] = sanitize_text_field($search);
            }
        }

        $posts = get_posts($args);
        $formatted_results = [];

        foreach ($posts as $post) {
            $post_type_label = get_post_type_object($post->post_type)->labels->singular_name;
            
            $formatted_results[] = [
                'value' => (string) $post->ID,
                'label' => $post->post_title . " ({$post_type_label}, ID: {$post->ID})",
                'meta' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => $post->post_type,
                    'status' => $post->post_status,
                ]
            ];
        }

        return $formatted_results;
    }

    /**
     * Search user types/roles.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array User type results
     */
    private function search_user_types(string $search, int $limit): array
    {
        global $wp_roles;

        if (!isset($wp_roles)) {
            $wp_roles = new \WP_Roles();
        }

        $roles = $wp_roles->get_names();
        $formatted_results = [];

        foreach ($roles as $role_key => $role_name) {
            // Filter by search term if provided
            if (!empty($search)) {
                $search_lower = strtolower($search);
                if (strpos(strtolower($role_name), $search_lower) === false && 
                    strpos(strtolower($role_key), $search_lower) === false) {
                    continue;
                }
            }

            $formatted_results[] = [
                'value' => $role_key,
                'label' => $role_name . " ({$role_key})",
                'meta' => [
                    'key' => $role_key,
                    'name' => $role_name,
                ]
            ];

            // Respect limit
            if (count($formatted_results) >= $limit) {
                break;
            }
        }

        return $formatted_results;
    }

    /**
     * Search order statuses.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array Order status results
     */
    private function search_order_statuses(string $search, int $limit): array
    {
        if (!class_exists('WooCommerce')) {
            return [];
        }

        $statuses = wc_get_order_statuses();
        $formatted_results = [];

        foreach ($statuses as $status_key => $status_name) {
            // Filter by search term if provided
            if (!empty($search)) {
                $search_lower = strtolower($search);
                if (strpos(strtolower($status_name), $search_lower) === false && 
                    strpos(strtolower($status_key), $search_lower) === false) {
                    continue;
                }
            }

            $formatted_results[] = [
                'value' => $status_key,
                'label' => $status_name . " ({$status_key})",
                'meta' => [
                    'key' => $status_key,
                    'name' => $status_name,
                ]
            ];

            // Respect limit
            if (count($formatted_results) >= $limit) {
                break;
            }
        }

        return $formatted_results;
    }

    /**
     * Search payment methods.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array Payment method results
     */
    private function search_payment_methods(string $search, int $limit): array
    {
        if (!class_exists('WooCommerce')) {
            return [];
        }

        $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
        $formatted_results = [];

        foreach ($payment_gateways as $gateway_id => $gateway) {
            // Filter by search term if provided
            if (!empty($search)) {
                $search_lower = strtolower($search);
                if (strpos(strtolower($gateway->get_title()), $search_lower) === false && 
                    strpos(strtolower($gateway_id), $search_lower) === false) {
                    continue;
                }
            }

            $formatted_results[] = [
                'value' => $gateway_id,
                'label' => $gateway->get_title() . " ({$gateway_id})",
                'meta' => [
                    'id' => $gateway_id,
                    'title' => $gateway->get_title(),
                    'enabled' => $gateway->is_available(),
                ]
            ];

            // Respect limit
            if (count($formatted_results) >= $limit) {
                break;
            }
        }

        return $formatted_results;
    }

    /**
     * Search shipping methods.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array Shipping method results
     */
    private function search_shipping_methods(string $search, int $limit): array
    {
        if (!class_exists('WooCommerce')) {
            return [];
        }

        $shipping_methods = WC()->shipping->get_shipping_methods();
        $formatted_results = [];

        foreach ($shipping_methods as $method_id => $method) {
            // Filter by search term if provided
            if (!empty($search)) {
                $search_lower = strtolower($search);
                if (strpos(strtolower($method->get_method_title()), $search_lower) === false && 
                    strpos(strtolower($method_id), $search_lower) === false) {
                    continue;
                }
            }

            $formatted_results[] = [
                'value' => $method_id,
                'label' => $method->get_method_title() . " ({$method_id})",
                'meta' => [
                    'id' => $method_id,
                    'title' => $method->get_method_title(),
                    'description' => $method->get_method_description(),
                ]
            ];

            // Respect limit
            if (count($formatted_results) >= $limit) {
                break;
            }
        }

        return $formatted_results;
    }

    /**
     * Search product tags.
     *
     * @param string $search Search term
     * @param int $limit Maximum results
     * @return array Product tag results
     */
    private function search_product_tags(string $search, int $limit): array
    {
        if (!class_exists('WooCommerce')) {
            return [];
        }

        $args = [
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
            'number' => $limit,
            'orderby' => 'name',
            'order' => 'ASC',
        ];

        if (!empty($search)) {
            $args['name__like'] = sanitize_text_field($search);
        }

        $tags = get_terms($args);
        if (is_wp_error($tags)) {
            return [];
        }

        $formatted_results = [];
        foreach ($tags as $tag) {
            $formatted_results[] = [
                'value' => (string) $tag->term_id,
                'label' => $tag->name . " (ID: {$tag->term_id})",
                'meta' => [
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'count' => $tag->count,
                ]
            ];
        }

        return $formatted_results;
    }

    /**
     * Log API errors for debugging
     */
    private function log_api_error(string $endpoint, \Exception $e, array $context): void
    {
        // Log to WordPress error log
        error_log(sprintf(
            'ODCM API Error in %s: %s - Context: %s',
            $endpoint,
            $e->getMessage(),
            json_encode($context)
        ));

        // Log to plugin's audit trail if available
        if (function_exists('odcm_log_custom_event')) {
            odcm_log_custom_event(
                "API Error in {$endpoint}: " . $e->getMessage(),
                array_merge($context, [
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'stack_trace' => $e->getTraceAsString(),
                ]),
                null,
                'error',
                'api_error',
                true
            );
        }
    }
}
