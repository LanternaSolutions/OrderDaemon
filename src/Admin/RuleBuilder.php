<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

use OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry;
use OrderDaemon\CompletionManager\Core\RuleComponents\RuleIndexBuilder;
use OrderDaemon\CompletionManager\Includes\DependencyChecker;

/**
 * Rule Builder for the Order Daemon Completion Manager
 *
 * This class implements a compact, accessible, and modern interface for building
 * completion rules. It utilizes a streamlined approach focused on:
 * 
 * - Compact accordion-style layout
 * - Accessibility-first component selection
 * - Natural language rule summaries
 * - Design system integrity
 * - PHP-first architecture with server-side data preparation
 *
 * @package OrderDaemon\CompletionManager\Admin
 * @since   1.0.0
 */
final class RuleBuilder
{
    private RuleComponentRegistry $component_registry;
    public function __construct()
    {
        $this->component_registry = new RuleComponentRegistry();
    }
    /**
     * Initializes the rule builder functionality by adding WordPress hooks.
     */
    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'remove_unnecessary_metaboxes']);
        add_action('add_meta_boxes', [$this, 'add_rule_builder_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('save_post_odcm_order_rule', [$this, 'save_rule_data'], 10, 3);

        // Optional backfill job registration (admin-only context)
        add_action('odcm_rebuild_rule_indexes_job', [\OrderDaemon\CompletionManager\Core\RuleComponents\RuleIndexBuilder::class, 'backfill_all']);
        
        // Only schedule Action Scheduler jobs in web contexts (not CLI)
        if (!(defined('WP_CLI') && WP_CLI) && !(defined('DOING_CRON') && DOING_CRON)) {
            add_action('admin_init', static function() {
                if (!get_option('odcm_indexes_built')) {
                    if (function_exists('as_schedule_single_action')) {
                        // Schedule once, allow Action Scheduler to de-duplicate
                        \as_schedule_single_action(time() + 10, 'odcm_rebuild_rule_indexes_job', [], 'odcm');
                    } else {
                        // Fallback: run immediately if Action Scheduler is unavailable (dev env)
                        \OrderDaemon\CompletionManager\Core\RuleComponents\RuleIndexBuilder::backfill_all();
                    }
                }
            });
        }
    }
    
    /**
    /**
     * Saves rule data from form submission to post meta and builds derived indexes.
     *
     * This method integrates with WordPress's standard post saving mechanism.
     * It retrieves the rule data from the hidden form field, saves it to post meta,
     * and builds derived indexes for fast admin filtering (PR3 integration).
     *
     * @param int     $post_id The post ID.
     * @param WP_Post $post    The post object.
     * @param bool    $update  Whether this is an existing post being updated.
     */
    public function save_rule_data(int $post_id, \WP_Post $post, bool $update): void 
    {
        // Security checks
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Verify nonce for rule builder saves
        if (!isset($_POST['odcm_rule_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['odcm_rule_nonce'])), 'odcm_save_rule')) {
            // Don't use wp_send_json_error in post save context - just return early
            return;
        }
        
        // Check if our hidden field is set
        if (!isset($_POST['_odcm_rule_data'])) {
            return;
        }
        
        // Sanitize and save the rule data
        $rule_data = sanitize_text_field(wp_unslash($_POST['_odcm_rule_data']));
        
        // Validate JSON format and decode
        $decoded_data = json_decode($rule_data, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_data)) {
            // Invalid JSON - clear indexes and do not save
            try {
                (new RuleIndexBuilder())->clear_indexes($post_id);
            } catch (\Throwable $e) {
                $this->log_error('Failed to clear indexes on invalid JSON for post ' . $post_id . ': ' . $e->getMessage());
            }
            return;
        }
        
        // Save JSON as-is (properly escaped for WordPress)
        update_post_meta($post_id, '_odcm_rule_data', wp_slash($rule_data));
        
        // Build derived indexes safely (PR3 integration)
        try {
            (new RuleIndexBuilder())->build_and_save($post_id, $decoded_data);
        } catch (\Throwable $e) {
            $this->log_error('Failed to build/save rule indexes for post ' . $post_id . ': ' . $e->getMessage());
            // Clear indexes on failure to prevent stale data
            try {
                (new RuleIndexBuilder())->clear_indexes($post_id);
            } catch (\Throwable $clear_error) {
                $this->log_error('Failed to clear indexes after build failure for post ' . $post_id . ': ' . $clear_error->getMessage());
            }
        }
    }
    
    /**
     * Safe logging that respects WordPress debugging settings
     * 
     * @param string $message Message to log
     * @return void
     */
    private function log_error(string $message): void
    {
        // Only log when debugging is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Use WordPress logging function if available
            if (function_exists('odcm_log_message')) {
                odcm_log_message('Rule Builder: ' . $message, 'error');
            } else {
                // Use WordPress debugging log function to ensure compatibility
                if (function_exists('wp_debug_log')) {
                    wp_debug_log('ODCM Rule Builder: ' . $message);
                }
                // Only if wp_debug_log doesn't exist, use WP error logger through apply_filters
                // This avoids direct error_log usage while still ensuring errors are captured
                else {
                    do_action('odcm_log_error', 'Rule Builder: ' . $message);
                    // If action isn't handled, write to WordPress debug.log if available
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        // Write to debug.log file using WordPress constants
                        $debug_file = WP_CONTENT_DIR . '/debug.log';
                        @file_put_contents(
                            $debug_file,
                            '[' . date('Y-m-d H:i:s') . '] ODCM Rule Builder: ' . $message . PHP_EOL,
                            FILE_APPEND
                        );
                    }
                }
            }
        }
    }

    /**
     * Removes unnecessary metaboxes from the completion rule edit screen.
     */
    public function remove_unnecessary_metaboxes(): void
    {
        global $post_type;
        
        if ($post_type !== 'odcm_order_rule') {
            return;
        }
        
        // Remove the default editor metabox since we're using our custom rule builder
        remove_post_type_support('odcm_order_rule', 'editor');
        
        // Remove other unnecessary metaboxes
        remove_meta_box('commentstatusdiv', 'odcm_order_rule', 'normal');
        remove_meta_box('commentsdiv', 'odcm_order_rule', 'normal');
        remove_meta_box('trackbacksdiv', 'odcm_order_rule', 'normal');
        remove_meta_box('postcustom', 'odcm_order_rule', 'normal');
        remove_meta_box('postexcerpt', 'odcm_order_rule', 'normal');
        remove_meta_box('slugdiv', 'odcm_order_rule', 'normal');
        remove_meta_box('pageparentdiv', 'odcm_order_rule', 'side');
    }

    /**
     * Adds the rule builder metabox to the completion rule edit screen.
     */
    public function add_rule_builder_metabox(): void
    {
        add_meta_box(
            'odcm-rule-builder',
            __('admin.rule_builder.header_title', 'order-daemon'),
            [$this, 'render_rule_builder'],
            'odcm_order_rule',
            'normal',
            'high'
        );
    }

    /**
     * Enqueues the CSS and JavaScript needed for the modern Rule Builder.
     *
     * This method implements the PHP-first architecture by preparing all data
     * server-side before passing it to the frontend. No API calls are needed
     * on page load, eliminating race conditions and improving performance.
     *
     * @param string $hook_suffix The hook suffix of the current admin page.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        // Use get_current_screen() for reliable detection; fall back to global $post_type
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $current_post_type = $screen && isset($screen->post_type) ? $screen->post_type : (isset($GLOBALS['post_type']) ? $GLOBALS['post_type'] : null);
        $current_base = $screen && isset($screen->base) ? $screen->base : $hook_suffix;

        // Only enqueue on our CPT edit screens (classic or block editor)
        if ($current_post_type !== 'odcm_order_rule' || !in_array($current_base, ['post', 'post-new.php', 'post.php'], true)) {
            // Note: In block editor, base is usually 'post'; in classic it's 'post.php' or 'post-new.php'
            return;
        }

        $plugin_version = defined('ODCM_VERSION') ? ODCM_VERSION : '3.0.0';
        $assets_url = plugin_dir_url(ODCM_PLUGIN_FILE) . 'assets/';

        // Enqueue Alpine.js served locally
        wp_enqueue_script(
            'alpine-js',
            $assets_url . 'js/vendor/alpine.min.js',
            [],
            '3.14.9',
            true
        );
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'alpine-js') {
                return str_replace('<script ', '<script defer ', $tag);
            }
            return $tag;
        }, 10, 2);

        // Enqueue design system CSS as foundation
        wp_enqueue_style(
            'odcm-design-system',
            $assets_url . 'css/odcm-design-system.css',
            [],
            $plugin_version
        );

        // Enqueue modern rule builder CSS with design system dependency
        wp_enqueue_style(
            'odcm-rule-builder',
            $assets_url . 'css/rule-builder.css',
            ['odcm-design-system'],
            $plugin_version
        );

        // Enqueue shared toast system
        wp_enqueue_script(
            'odcm-shared-toasts',
            $assets_url . 'js/shared/toasts.js',
            [],
            $plugin_version,
            true
        );

        // Enqueue modern rule builder JavaScript with dependencies
        wp_enqueue_script(
            'odcm-rule-builder',
            $assets_url . 'js/rule-builder.js',
            ['alpine-js', 'wp-api-fetch', 'odcm-shared-toasts'],
            $plugin_version,
            true
        );

        // Configure wp.apiFetch with proper nonce and root URL (still needed for saving)
        $rest_nonce = wp_create_nonce('wp_rest');
        wp_add_inline_script('wp-api-fetch', sprintf(
            'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
            wp_json_encode($rest_nonce)
        ), 'after');

        wp_add_inline_script('wp-api-fetch', sprintf(
            'wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( %s ) );',
            wp_json_encode(esc_url_raw(rest_url()))
        ), 'after');

        // PHP-FIRST ARCHITECTURE: Prepare all data server-side
        $rule_data = $this->load_rule_data(get_the_ID());
        $components_data = $this->load_components_data($rule_data);
        $prepared_fields = $this->prepare_all_fields($components_data, $rule_data);

        // Prepare base configuration
        $config = [
            'postId'  => get_the_ID(),
            'nonce'   => $rest_nonce,
            
            // Complete rule data (no API call needed)
            'rule' => $rule_data,
            
            // Complete component definitions (no API call needed)
            'components' => $components_data,
            
            // Pre-prepared fields for all components (eliminates race condition)
            'preparedFields' => $prepared_fields,
            
                        
            // API endpoints (only used for saving now)
            'api' => [
                'nonce'   => $rest_nonce,
                'rule'    => rest_url('odcm/v1/rule/' . get_the_ID()),
                'baseUrl' => rest_url('odcm/v1/rule-builder'),
                'summary' => rest_url('odcm/v1/rule-builder/summary'),
            ],
            
            'uiCapabilities' => [
                'canSelectMultipleProductCategories' => function_exists('odcm_can_use') && odcm_can_use('condition_multi_category'),
                // In core (free) plugin, premium components are educational-only; keep disabled in UI
                'canAccessPremiumComponents' => false,
            ],
            // Debug info
            'debug' => [
                'condition_multi_category' => function_exists('odcm_can_use') ? odcm_can_use('condition_multi_category') : false,
                'premium_features' => function_exists('odcm_can_use') ? odcm_can_use('premium_features') : false,
                'php_version' => PHP_VERSION
            ],
            'i18n' => [
                'saving'           => __('admin.rule_builder.status.saving', 'order-daemon'),
                'saved'            => __('admin.rule_builder.status.saved', 'order-daemon'),
                'error'            => __('admin.rule_builder.status.error', 'order-daemon'),
                'loading'          => __('admin.rule_builder.status.loading', 'order-daemon'),
                'addCondition'     => __('admin.rule_builder.action.add_condition', 'order-daemon'),
                'edit'             => __('admin.rule_builder.action.edit', 'order-daemon'),
                'remove'           => __('admin.rule_builder.action.remove', 'order-daemon'),
                'searchConditions' => __('admin.rule_builder.search.conditions_placeholder', 'order-daemon'),
                'searchActions'    => __('admin.rule_builder.search.actions_placeholder', 'order-daemon'),
                'noSettings'       => __('admin.rule_builder.message.no_settings_required', 'order-daemon'),
            ],
        ];

        // Allow pro plugin to modify the configuration
        $config = apply_filters('odcm_rule_builder_config', $config);

        // Pass complete, ready-to-use data to frontend (no API calls needed)
        wp_localize_script('odcm-rule-builder', 'odcmRuleBuilderConfig', $config);
    }

    /**
     * Loads rule data from the database.
     *
     * @param int $rule_id The rule post ID
     * @return array The rule data structure
     */
    private function load_rule_data(int $rule_id): array
    {
        $rule_data_json = get_post_meta($rule_id, '_odcm_rule_data', true);

        if (empty($rule_data_json)) {
            // Return a default, empty structure if no data exists yet
            return [
                'trigger'          => null,
                'conditions'       => [],
                'primaryAction'    => [
                    'id'       => 'change_status_to_completed',
                    'settings' => []
                ],
                'secondaryActions' => [],
            ];
        }

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

        return $rule_data;
    }

    /**
     * Loads and formats component data from the registry.
     *
     * @param array $rule_data The current rule data for state detection
     * @return array The formatted components data
     */
    private function load_components_data(array $rule_data): array
    {
        // Get components from registry
        $triggers = $this->component_registry->get_triggers();
        $conditions = $this->component_registry->get_conditions();
        $actions = $this->component_registry->get_actions();
        
        // Categorize actions
        $primary_action_ids = [
            'change_status_to_completed',
            'change_status_to_processing', 
            'change_status_to_on_hold',
        ];
        
        $primary_actions = [];
        $secondary_actions = [];
        
        foreach ($actions as $action_id => $action) {
            if (in_array($action_id, $primary_action_ids)) {
                $primary_actions[$action_id] = $action;
            } else {
                $secondary_actions[$action_id] = $action;
            }
        }
        
        return [
            'triggers'         => $this->format_components($triggers, $rule_data),
            'conditions'       => $this->format_components($conditions, $rule_data),
            'primaryActions'   => $this->format_components($primary_actions, $rule_data),
            'secondaryActions' => $this->format_components($secondary_actions, $rule_data),
            'actions'          => $this->format_components($secondary_actions, $rule_data), // Legacy compatibility
        ];
    }

    /**
     * Formats components for frontend consumption.
     *
     * @param array $components The components to format
     * @param array $rule_data The current rule data
     * @return array The formatted components
     */
    private function format_components(array $components, array $rule_data): array
    {
        $formatted = [];
        
        foreach ($components as $component) {
            $can_use = function_exists('odcm_can_use') && odcm_can_use($component->get_capability());
            $already_in_rule = $this->is_component_in_current_rule($component->get_id(), $rule_data);
            
            // Determine component state
            $component_state = 'available';
            if (!$can_use && $already_in_rule) {
                $component_state = 'already_selected_unavailable';
            } elseif (!$can_use) {
                $component_state = 'unavailable';
            }
            
            // Always include schema so settings can render in UI; capability is enforced at runtime
            $schema = $component->get_settings_schema();
            
            $formatted[] = [
                'id'          => $component->get_id(),
                'label'       => $component->get_label(),
                'description' => $component->get_description(),
                'schema'      => $schema,
                'capability'  => $component->get_capability(),
                'accessible'  => $can_use,
                'state'       => $component_state,
                'already_in_rule' => $already_in_rule,
                'is_default'  => method_exists($component, 'is_default') ? $component->is_default() : false,
                'priority'    => method_exists($component, 'get_priority') ? $component->get_priority() : 999,
            ];
        }
        
        // Sort by accessibility and priority
        usort($formatted, function($a, $b) {
            if ($a['accessible'] !== $b['accessible']) {
                return $b['accessible'] - $a['accessible'];
            }
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] - $b['priority'];
            }
            return strcmp($a['label'], $b['label']);
        });
        
        return $formatted;
    }

    /**
     * Checks if a component is already in the rule.
     *
     * @param string $component_id The component ID
     * @param array $rule_data The rule data
     * @return bool
     */
    private function is_component_in_current_rule(string $component_id, array $rule_data): bool
    {
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
     * Pre-prepares all fields for all components to eliminate race conditions.
     * This is the key to the PHP-first architecture - all data is ready before rendering.
     *
     * @param array $components_data The components data
     * @param array $rule_data The current rule data
     * @return array The prepared fields for each component
     */
    private function prepare_all_fields(array $components_data, array $rule_data): array
    {
        $prepared = [];
        
        // Prepare fields for ALL triggers (not just the one currently selected)
        // This ensures fields are available when user selects a new trigger
        foreach ($components_data['triggers'] as $trigger_component) {
            if ($trigger_component['schema']) {
                $current_settings = [];
                // If this trigger is currently selected, use its settings
                if ($rule_data['trigger'] && $rule_data['trigger']['id'] === $trigger_component['id']) {
                    $current_settings = $rule_data['trigger']['settings'] ?? [];
                }
                
                $prepared['trigger_0_' . $trigger_component['id']] = $this->prepare_component_fields(
                    $trigger_component['schema'],
                    $current_settings,
                    'trigger',
                    0
                );
            }
        }
        
        // Prepare fields for ALL conditions (for when user adds new conditions)
        foreach ($components_data['conditions'] as $condition_component) {
            if ($condition_component['schema']) {
                $prepared['condition_template_' . $condition_component['id']] = $this->prepare_component_fields(
                    $condition_component['schema'],
                    [], // Empty settings for template
                    'condition',
                    null // Use null instead of 'template' string
                );
            }
        }
        
        // Prepare fields for conditions currently in the rule
        foreach ($rule_data['conditions'] as $index => $condition) {
            if (isset($condition['id'])) {
                $condition_component = $this->find_component_by_id($components_data['conditions'], $condition['id']);
                if ($condition_component && $condition_component['schema']) {
                    $prepared["condition_{$index}"] = $this->prepare_component_fields(
                        $condition_component['schema'],
                        $condition['settings'] ?? [],
                        'condition',
                        $index
                    );
                }
            }
        }
        
        // Prepare fields for ALL primary actions
        foreach ($components_data['primaryActions'] as $action_component) {
            if ($action_component['schema']) {
                $current_settings = [];
                // If this action is currently selected, use its settings
                if ($rule_data['primaryAction'] && $rule_data['primaryAction']['id'] === $action_component['id']) {
                    $current_settings = $rule_data['primaryAction']['settings'] ?? [];
                }
                
                $prepared['primaryAction_' . $action_component['id']] = $this->prepare_component_fields(
                    $action_component['schema'],
                    $current_settings,
                    'primaryAction',
                    null
                );
            }
        }
        
        // Prepare fields for ALL secondary actions (for when user adds new actions)
        foreach ($components_data['secondaryActions'] as $action_component) {
            if ($action_component['schema']) {
                $prepared['action_template_' . $action_component['id']] = $this->prepare_component_fields(
                    $action_component['schema'],
                    [], // Empty settings for template
                    'action',
                    null // Use null instead of 'template' string
                );
            }
        }
        
        // Prepare fields for secondary actions currently in the rule
        foreach ($rule_data['secondaryActions'] as $index => $action) {
            if (isset($action['id'])) {
                $action_component = $this->find_component_by_id($components_data['secondaryActions'], $action['id']);
                if ($action_component && $action_component['schema']) {
                    $prepared["action_{$index}"] = $this->prepare_component_fields(
                        $action_component['schema'],
                        $action['settings'] ?? [],
                        'action',
                        $index
                    );
                }
            }
        }
        
        return $prepared;
    }

    /**
     * Finds a component by ID in a components array.
     *
     * @param array $components The components array
     * @param string $id The component ID
     * @return array|null The component or null if not found
     */
    private function find_component_by_id(array $components, string $id): ?array
    {
        foreach ($components as $component) {
            if ($component['id'] === $id) {
                return $component;
            }
        }
        return null;
    }


    /**
     * Prepares fields for a component based on its schema.
     * This eliminates the race condition by having all data ready server-side.
     *
     * @param array $schema The component schema
     * @param array $current_settings The current settings
     * @param string $component_type The component type
     * @param int|null $index The component index
     * @return array The prepared fields
     */
    private function prepare_component_fields(array $schema, array $current_settings, string $component_type, ?int $index): array
    {
        if (!isset($schema['properties'])) {
            return [];
        }
        
        $fields = [];
        
        foreach ($schema['properties'] as $key => $property) {
            $field_id = $component_type . '_' . ($index !== null ? $index . '_' : '') . $key;
            
            // Get current value, fall back to schema default, then type default
            $current_value = $current_settings[$key] ?? null;
            $default_value = $property['default'] ?? $this->get_default_value($property);
            
            // Prepare selected values for array fields
            $selected_values = [];
            if ($property['type'] === 'array') {
                if (is_array($current_value)) {
                    $selected_values = $current_value;
                } elseif (is_array($default_value)) {
                    $selected_values = $default_value;
                }
            }
            
            // Get enum options
            $enum_options = [];
            if (isset($property['items']['enum'])) {
                $enum_options = $property['items']['enum'];
            } elseif (isset($property['enum'])) {
                $enum_options = $property['enum'];
            }
            
            // Get premium options
            $premium_options = $property['ui:premium_options'] ?? [];
            
            $fields[$key] = [
                'id' => $field_id,
                'key' => $key,
                'title' => $property['title'] ?? '',
                'description' => $property['description'] ?? '',
                'widget' => $this->get_widget_type($property),
                'value' => $current_value !== null ? $current_value : $default_value,
                'enumOptions' => $enum_options,
                'selectedValues' => $selected_values,
                'premiumOptions' => $premium_options,
                'placeholder' => $property['ui:placeholder'] ?? 'Search options...',
                // Numeric attributes for number/integer inputs
                'minimum' => isset($property['minimum']) ? $property['minimum'] : null,
                'maximum' => isset($property['maximum']) ? $property['maximum'] : null,
                'step' => isset($property['step']) ? $property['step'] : (($property['type'] ?? '') === 'integer' ? 1 : null),
                'default' => $default_value,
                // Radio-with-inline-number patterns mapping
                'radioInputs' => $property['ui:radio_inputs'] ?? []
            ];
        }
        
        return $fields;
    }

    /**
     * Determines the widget type for a property.
     *
     * @param array $property The property definition
     * @return string The widget type
     */
    private function get_widget_type(array $property): string
    {
        // Respect explicit widget override
        if (isset($property['ui:widget']) && is_string($property['ui:widget'])) {
            // Never allow 'select' per PR1; map to radio_group
            return $property['ui:widget'] === 'select' ? 'radio_group' : $property['ui:widget'];
        }

        // Booleans are plain checkboxes
        if (($property['type'] ?? '') === 'boolean') {
            return 'checkbox';
        }

        // Arrays with enum items are checkbox groups (optionally searchable)
        if (($property['type'] ?? '') === 'array' && isset($property['items']['enum'])) {
            $searchable = (bool)($property['ui:searchable'] ?? false);
            return $searchable ? 'searchable_checkboxes' : 'checkboxes';
        }

        // Single string enums are radio groups (styled like checkboxes)
        if (($property['type'] ?? '') === 'string' && isset($property['enum'])) {
            return 'radio_group';
        }

        // Fallbacks based on type
        if (($property['type'] ?? '') === 'number' || ($property['type'] ?? '') === 'integer') {
            return 'number';
        }

        return 'text';
    }

    /**
     * Gets the default value for a property type.
     *
     * @param array $property The property definition
     * @return mixed The default value
     */
    private function get_default_value(array $property)
    {
        switch ($property['type']) {
            case 'boolean':
                return false;
            case 'array':
                return [];
            case 'number':
            case 'integer':
                return 0;
            default:
                return '';
        }
    }

    /**
     * Renders the modern rule builder interface directly in the post content area.
     *
     * This method outputs the HTML structure for the Alpine.js application,
     * implementing the compact style layout with WHEN/IF/THEN sections.
     *
     * @param \WP_Post $post The current post object.
     */
    public function render_rule_builder(\WP_Post $post): void
    {
        // Only render for completion rule post type
        if ($post->post_type !== 'odcm_order_rule') {
            return;
        }
        
        // Load rule data for the hidden field
        $rule_data = $this->load_rule_data($post->ID);

        ?>
        <!-- Hidden form field to store rule data for WordPress standard post saving -->
        <input type="hidden" name="_odcm_rule_data" id="odcm_rule_data_field" value="<?php echo esc_attr(wp_json_encode($rule_data)); ?>">
        <?php // Nonce for secure save per PR1 requirements
        wp_nonce_field('odcm_save_rule', 'odcm_rule_nonce'); ?>
        
        <div class="odcm-rule-builder-wrapper" x-data="ruleBuilder()" x-cloak x-init="$watch('rule', value => { document.getElementById('odcm_rule_data_field').value = JSON.stringify(value); })">
            <!-- Rule Builder Header -->
            <div class="odcm-rule-builder-header">
                <h2><?php esc_html_e('Rule Builder', 'order-daemon'); ?></h2>
            </div>

            <!-- Rule Builder Content -->
            <div class="odcm-rule-builder-content">
                <div id="odcm-rule-builder">
                    <!-- Loading State -->
                    <div x-show="loading" class="odcm-loading-state">
                        <div class="odcm-loading-spinner"></div>
                        <p><?php esc_html_e('admin.rule_builder.status.loading_rule_builder', 'order-daemon'); ?></p>
                    </div>

                    <!-- Main Application -->
                    <div x-show="!loading" class="odcm-rule-builder-app">

                <!-- WHEN Section (Trigger) -->
                <div class="odcm-rule-section">
                    <h3 class="odcm-section-title">
                        <?php esc_html_e('admin.rule_builder.section.when', 'order-daemon'); ?>
                        <span class="odcm-section-subtitle"><?php esc_html_e('(Trigger)', 'order-daemon'); ?></span>
                    </h3>

                    <div x-show="!rule.trigger" class="odcm-empty-state">
                        <button type="button" 
                                @click="isAddingTrigger = !isAddingTrigger" 
                                class="odcm-add-component-button odcm-add-trigger-button">
                            <span class="odcm-button-icon">+</span>
                            <?php esc_html_e('admin.rule_builder.action.add_trigger_description', 'order-daemon'); ?>
                        </button>
                    </div>

                    <!-- Trigger Component Row -->
                    <div x-show="rule.trigger" class="odcm-rule-row" :class="{ 'odcm-expanded': editingTriggerIndex === 0, 'odcm-no-settings': !componentHasSettings('trigger', 0), 'odcm-component-inaccessible': !getComponentDefinition('trigger', rule.trigger?.id)?.accessible }" @click="!getComponentDefinition('trigger', rule.trigger?.id)?.accessible ? null : (componentHasSettings('trigger', 0) && handleRowClick('trigger', 0, $event))">
                        <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                        <div class="odcm-component-summary" x-html="getComponentSummary(rule.trigger, 'trigger', 0)"></div>
                        <div class="odcm-component-actions">
                            <button type="button" 
                                    @click="removeTrigger()" 
                                    class="odcm-remove-button">
                                <?php esc_html_e('Remove', 'order-daemon'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Trigger Settings Panel -->
                    <div x-show="rule.trigger && editingTriggerIndex === 0" 
                         class="odcm-settings-panel"
                         :class="{ 'odcm-expanded': editingTriggerIndex === 0 }">
                        <!-- Trigger Validation Errors -->
                        <div x-show="triggerValidationErrors.length > 0" class="odcm-validation-errors">
                            <template x-for="error in triggerValidationErrors" :key="error">
                                <div class="odcm-validation-error" x-text="error"></div>
                            </template>
                        </div>
                        <div x-data="settingsPanel('trigger', 0)" x-init="$nextTick(() => { 
                                                        const doInit = () => { 
                                                            const component = getTriggerComponent(rule.trigger?.id); 
                                                            const schema = component?.schema; 
                                                            const settings = rule.trigger?.settings || {}; 
                                                            initSettings(schema, settings);
                                                        };
                                                        // Initial run
                                                        doInit();
                                                        // Re-run when the trigger object changes
                                                        $watch(() => rule.trigger, () => doInit());
                                                        // Re-run when the trigger id changes (more granular)
                                                        $watch(() => rule.trigger?.id, () => doInit());
                                                        // Re-run when the panel expands to ensure DOM is ready
                                                        $watch(() => editingTriggerIndex, (v) => { if (v === 0) doInit(); });
                                                    })">
                            <template x-if="Object.keys(fields).length > 0">
                                <div class="odcm-settings-form">
                                    <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                        <div class="odcm-form-group">
                                            <!-- Field Label -->
                                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>
                                            
                                            <!-- Field Description -->
                                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>
                                            
                                            <!-- Searchable Checkboxes Widget -->
                                            <template x-if="field.widget === 'searchable_checkboxes'">
                                                <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                    <div class="odcm-searchable-checkboxes" 
                                                         x-data="searchableWidget(field.id)" 
                                                         x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.premiumOptions, field.key))">
                                                    <div class="odcm-search-header">
                                                        <input type="text" 
                                                               :id="field.id + '_search'"
                                                               class="odcm-search-input"
                                                               :placeholder="field.placeholder || 'Search options...'"
                                                               x-model="searchTerm"
                                                               @input="filterOptions()">
                                                        <button type="button" 
                                                                class="odcm-show-all-button"
                                                                x-show="searchTerm && !showAll"
                                                                @click="showAll = true; filterOptions()">
                                                            Show All
                                                        </button>
                                                    </div>
                                                    <div class="odcm-searchable-list">
                                                        <div class="odcm-checkbox-group">
                                                            <template x-for="option in filteredOptions" :key="option.value">
                                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                                    <input type="checkbox" 
                                                                           :id="field.id + '_' + option.value"
                                                                           :value="option.value" 
                                                                           :checked="selectedValues.includes(option.value)"
                                                                           :disabled="shouldDisableOption(option.value)"
                                                                           @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'trigger', 0)">
                                                                    <div class="odcm-checkbox-content">
                                                                        <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                                        <span x-show="premiumOptions.includes(option.value)" class="odcm-premium-badge odcm-premium-badge--inline">PRO</span>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                                <p>No options found matching your search.</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="odcm-selected-summary">
                                                        <span class="odcm-summary-text" x-show="selectedValues.length > 0">
                                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                                        </span>
                                                        <div class="odcm-selected-summary-buttons">
                                                            <button type="button" 
                                                                    class="odcm-select-all-compact"
                                                                    x-show="canSelectAll && hasSelectableOptions"
                                                                    @click="selectAll(field.key, 'trigger', 0)">
                                                                Select All
                                                            </button>
                                                            <button type="button" 
                                                                    class="odcm-clear-all-compact"
                                                                    x-show="selectedValues.length > 0"
                                                                    @click="clearAll(field.key, 'trigger', 0)">
                                                                Clear All
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                </template>
                                            </template>
    
                                            <!-- Non-searchable Checkboxes Widget -->
                                            <template x-if="field.widget === 'checkboxes'">
                                                <div class="odcm-checkbox-group">
                                                    <!-- Clear All button only -->
                                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'trigger', 0)">
                                                        <button type="button" 
                                                                class="odcm-clear-all-button odcm-checkbox-control-button"
                                                                @click="clearAllCheckboxes(field.key, 'trigger', 0)">
                                                            Clear All
                                                        </button>
                                                    </div>
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                                            <input type="checkbox"
                                                                   :id="field.id + '_' + val"
                                                                   :value="val"
                                                                   :checked="(field.selectedValues || []).includes(val)"
                                                                   @change="updateArraySetting(field.key, val, $event.target.checked, 'trigger', 0)">
                                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                                        </label>
                                                    </template>
                                                </div>
                                            </template>
    
                                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                                            <template x-if="field.widget === 'radio_group'">
                                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String(field.value === val)" tabindex="0"
                                                               @keydown.enter.prevent="$event.currentTarget.querySelector('input')?.click()"
                                                               @keydown.space.prevent="$event.currentTarget.querySelector('input')?.click()">
                                                            <input type="radio"
                                                                   :id="field.id + '_' + val"
                                                                   :name="field.id"
                                                                   :value="val"
                                                                   :checked="(rule.trigger?.settings[field.key] ?? field.value) === val"
                                                                   @change="updateSetting(field.key, $event.target.value, 'trigger', 0)">
                                                            <span class="odcm-radio-text" x-text="label"></span>
                                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                                            <template x-if="field.radioInputs && field.radioInputs[val]">
                                                                <input type="number"
                                                                       class="odcm-inline-number-input"
                                                                       :min="field.minimum !== null ? field.minimum : undefined"
                                                                       :max="field.maximum !== null ? field.maximum : undefined"
                                                                       :step="field.step !== null ? field.step : undefined"
                                                                       :value="(rule.trigger?.settings[field.radioInputs[val]] ?? '')"
                                                                       :disabled="(rule.trigger?.settings[field.key] ?? field.value) !== val"
                                                                       @input="updateSiblingField('trigger', field.radioInputs[val], $event.target.value, 'trigger', 0)">
                                                            </template>
                                                        </label>
                                                    </template>
                                                </div>
                                            </template>
                                            
                                            <!-- Button-style radio group -->
                                            <template x-if="field.widget === 'button_radio_group'">
                                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <button type="button"
                                                                class="odcm-radio-button"
                                                                :class="{ 'is-active': (rule.trigger?.settings[field.key] ?? field.value) === val }"
                                                                :aria-pressed="String((rule.trigger?.settings[field.key] ?? field.value) === val)"
                                                                @click="updateSetting(field.key, val, 'trigger', 0)"
                                                                x-text="label">
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>
                                            
                                            <!-- Textarea field -->
                                            <template x-if="field.widget === 'textarea'">
                                                <textarea :id="field.id"
                                                          class="odcm-form-textarea"
                                                          rows="6"
                                                          :placeholder="field.description || ''"
                                                          :value="field.value"
                                                          @input="updateSetting(field.key, $event.target.value, 'trigger', 0)"></textarea>
                                            </template>
                                            
                                            <!-- Other field types -->
                                            <template x-if="field.widget === 'text'">
                                                <input type="text" 
                                                       :id="field.id"
                                                       class="odcm-form-input"
                                                       :value="field.value"
                                                       :placeholder="field.description || ''"
                                                       @input="updateSetting(field.key, $event.target.value, 'trigger', 0)">
                                            </template>
                                            
                                            <template x-if="field.widget === 'checkbox'">
                                                <label class="odcm-checkbox-label">
                                                    <input type="checkbox" 
                                                           :id="field.id"
                                                           :checked="field.value"
                                                           @change="updateSetting(field.key, $event.target.checked, 'trigger', 0)">
                                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="Object.keys(fields).length === 0">
                                <div class="odcm-no-settings">
                                    <p>No configurable settings available for this trigger.</p>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Trigger Inline Selector -->
                    <div x-show="isAddingTrigger" class="odcm-inline-selector" :class="{ 'odcm-expanded': isAddingTrigger }">
                        <div class="odcm-selector-header">
                            <input type="text" 
                                   x-model="triggerSearchTerm" 
                                   placeholder="<?php esc_attr_e('admin.rule_builder.search.triggers_placeholder', 'order-daemon'); ?>"
                                   class="odcm-search-input">
                            <button type="button" 
                                    @click="isAddingTrigger = false" 
                                    class="odcm-close-selector">×</button>
                        </div>
                        <div class="odcm-selector-list">
                            <template x-for="trigger in filteredTriggers" :key="trigger.id">
                                <button type="button" 
                                        @click="selectComponent('trigger', trigger.id)" 
                                        class="odcm-selector-option"
                                        :class="{ 'odcm-premium-option': shouldShowPremiumBadge(trigger) }">
                                    <div class="odcm-option-content">
                                        <div class="odcm-option-title" x-text="trigger.label"></div>
                                        <div class="odcm-option-description" x-text="trigger.description"></div>
                                    </div>
                                    <span x-show="shouldShowPremiumBadge(trigger)"
                                    class="odcm-premium-badge odcm-premium-badge--inline"
                                    :title="odcmRuleBuilderConfig?.upgrade?.message || ''">PRO</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- IF Section (Conditions) -->
                <div class="odcm-rule-section">
                    <h3 class="odcm-section-title">
                        <?php esc_html_e('admin.rule_builder.section.if', 'order-daemon'); ?>
                        <span class="odcm-section-subtitle"><?php esc_html_e('(Conditions)', 'order-daemon'); ?></span>
                        <span class="odcm-component-count" x-text="`(${rule.conditions.length})`"></span>
                    </h3>

                    <div x-show="rule.conditions.length === 0" class="odcm-empty-state">
                    </div>

                    <!-- Conditions List -->
                    <template x-for="(condition, index) in rule.conditions" :key="index">
                        <div class="odcm-condition-wrapper">
                            <!-- Condition Row -->
                            <div class="odcm-rule-row" 
                                 :class="{ 'odcm-expanded': editingConditionIndex === index, 'odcm-no-settings': !componentHasSettings('condition', index), 'odcm-component-inaccessible': !getComponentDefinition('condition', condition.id)?.accessible }"
                                 draggable="true"
                                 @dragstart="startDragCondition(index, $event)"
                                 @dragover="dragOverCondition(index, $event)"
                                 @drop="dropCondition(index, $event)"
                                 @dragend="endDrag()"
                                 @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) && handleRowClick('condition', index, $event))">
                                <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                                <div class="odcm-component-summary" x-html="getComponentSummary(condition, 'condition', index)"></div>
                                <div class="odcm-component-actions">
                                    <button type="button" 
                                            @click="removeCondition(index)" 
                                            class="odcm-remove-button">
                                        <?php esc_html_e('Remove', 'order-daemon'); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Condition Settings Panel -->
                            <div x-show="editingConditionIndex === index" 
                                 class="odcm-settings-panel"
                                 :class="{ 'odcm-expanded': editingConditionIndex === index }">
                                <div x-data="settingsPanel('condition', index)" x-init="initSettings(getConditionComponent(condition.id)?.schema, condition.settings || {})">
                                    <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                        <div class="odcm-form-group">
                                            <!-- Field Label -->
                                            <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>
                                            
                                            <!-- Field Description -->
                                            <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>
                                            
                                            <!-- Searchable Checkboxes Widget -->
                                            <template x-if="field.widget === 'searchable_checkboxes'">
                                                <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                    <div class="odcm-searchable-checkboxes" 
                                                         x-data="searchableWidget(field.id)" 
                                                         x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.premiumOptions, field.key))">
                                                    <div class="odcm-search-header">
                                                        <input type="text" 
                                                               :id="field.id + '_search'"
                                                               class="odcm-search-input"
                                                               :placeholder="field.placeholder || 'Search options...'"
                                                               x-model="searchTerm"
                                                               @input="filterOptions()">
                                                        <button type="button" 
                                                                class="odcm-show-all-button"
                                                                x-show="searchTerm && !showAll"
                                                                @click="showAll = true; filterOptions()">
                                                            Show All
                                                        </button>
                                                    </div>
                                                    <div class="odcm-searchable-list">
                                                        <div class="odcm-checkbox-group">
                                                            <template x-for="option in filteredOptions" :key="option.value">
                                                                <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                                    <input type="checkbox" 
                                                                           :id="field.id + '_' + option.value"
                                                                           :value="option.value" 
                                                                           :checked="selectedValues.includes(option.value)"
                                                                           :disabled="shouldDisableOption(option.value)"
                                                                           @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'condition', index)">
                                                                    <div class="odcm-checkbox-content">
                                                                        <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                                        <span x-show="premiumOptions.includes(option.value)" class="odcm-premium-badge odcm-premium-badge--inline">PRO</span>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                            <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                                <p>No options found matching your search.</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="odcm-selected-summary">
                                                        <span class="odcm-summary-text" x-show="selectedValues.length > 0">
                                                            Selected: <span x-text="selectedValues.length"></span> option(s)
                                                        </span>
                                                        <div class="odcm-selected-summary-buttons">
                                                            <button type="button" 
                                                                    class="odcm-select-all-compact"
                                                                    x-show="canSelectAll && hasSelectableOptions"
                                                                    @click="selectAll(field.key, 'condition', index)">
                                                                Select All
                                                            </button>
                                                            <button type="button" 
                                                                    class="odcm-clear-all-compact"
                                                                    x-show="selectedValues.length > 0"
                                                                    @click="clearAll(field.key, 'condition', index)">
                                                                Clear All
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                </template>
                                            </template>
                                            
                                            <!-- Non-searchable Checkboxes Widget -->
                                            <template x-if="field.widget === 'checkboxes'">
                                                <div class="odcm-checkbox-group">
                                                    <!-- Clear All button -->
                                                    <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'condition', index)">
                                                        <button type="button" 
                                                                class="odcm-clear-all-button odcm-checkbox-control-button"
                                                                @click="clearAllCheckboxes(field.key, 'condition', index)">
                                                            Clear All
                                                        </button>
                                                    </div>
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                                            <input type="checkbox"
                                                                   :id="field.id + '_' + val"
                                                                   :value="val"
                                                                   :checked="(field.selectedValues || []).includes(val)"
                                                                   @change="updateArraySetting(field.key, val, $event.target.checked, 'condition', index)">
                                                            <span class="odcm-checkbox-text" x-text="label"></span>
                                                        </label>
                                                    </template>
                                                </div>
                                            </template>

                                            <!-- Radio group (styled like checkboxes) or radio with inline inputs -->
                                            <template x-if="field.widget === 'radio_group'">
                                                <div class="odcm-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <label class="odcm-radio-label" :for="field.id + '_' + val" role="radio" :aria-checked="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)" tabindex="0">
                                                            <input type="radio"
                                                                   :id="field.id + '_' + val"
                                                                   :name="field.id"
                                                                   :value="val"
                                                                   :checked="(rule.conditions[index]?.settings[field.key] ?? field.value) === val"
                                                                   @change="updateSetting(field.key, $event.target.value, 'condition', index)">
                                                            <span class="odcm-radio-text" x-text="label"></span>
                                                            <!-- Inline numeric input when radioInputs mapping exists -->
                                                            <template x-if="field.radioInputs && field.radioInputs[val]">
                                                                <input type="number"
                                                                       class="odcm-inline-number-input"
                                                                       :min="field.minimum !== null ? field.minimum : undefined"
                                                                       :max="field.maximum !== null ? field.maximum : undefined"
                                                                       :step="field.step !== null ? field.step : undefined"
                                                                       :value="(rule.conditions[index]?.settings[field.radioInputs[val]] ?? '')"
                                                                       :disabled="(rule.conditions[index]?.settings[field.key] ?? field.value) !== val"
                                                                       @input="updateSiblingField('condition', field.radioInputs[val], $event.target.value, 'condition', index)">
                                                            </template>
                                                        </label>
                                                    </template>
                                                </div>
                                            </template>
                                            
                                            <!-- Button-style radio group -->
                                            <template x-if="field.widget === 'button_radio_group'">
                                                <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <button type="button"
                                                                class="odcm-radio-button"
                                                                :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }"
                                                                :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)"
                                                                @click="updateSetting(field.key, val, 'condition', index)"
                                                                x-text="label">
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>
                                            
                                            <!-- Textarea field -->
                                            <template x-if="field.widget === 'textarea'">
                                                <textarea :id="field.id"
                                                          class="odcm-form-textarea"
                                                          rows="6"
                                                          :placeholder="field.description || ''"
                                                          :value="field.value"
                                                          @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                                            </template>

                                            <!-- Other field types -->
                                            <template x-if="field.widget === 'text'">
                                                <input type="text" 
                                                       :id="field.id"
                                                       class="odcm-form-input"
                                                       :value="field.value"
                                                       :placeholder="field.description || ''"
                                                       @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                                            </template>
                                            
                                            <template x-if="field.widget === 'checkbox'">
                                                <label class="odcm-checkbox-label">
                                                    <input type="checkbox" 
                                                           :id="field.id"
                                                           :checked="field.value"
                                                           @change="updateSetting(field.key, $event.target.checked, 'condition', index)">
                                                    <span class="odcm-checkbox-text" x-text="field.title"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Add Condition Button -->
                    <button type="button" 
                            @click="isAddingCondition = !isAddingCondition" 
                            class="odcm-add-component-button odcm-add-condition-button">
                        <span class="odcm-button-icon">+</span>
                            <?php esc_html_e('Add Condition', 'order-daemon'); ?>
                    </button>

                    <!-- Condition Inline Selector -->
                    <div x-show="isAddingCondition" class="odcm-inline-selector" :class="{ 'odcm-expanded': isAddingCondition }">
                        <div class="odcm-selector-header">
                            <input type="text" 
                                   x-model="conditionSearchTerm" 
                                   placeholder="<?php esc_attr_e('Search conditions...', 'order-daemon'); ?>"
                                   class="odcm-search-input">
                            <button type="button" 
                                    @click="isAddingCondition = false" 
                                    class="odcm-close-selector">×</button>
                        </div>
                        <div class="odcm-selector-list">
                            <template x-for="condition in filteredConditions" :key="condition.id">
                                <button type="button" 
                                        @click="selectComponent('condition', condition.id)" 
                                        class="odcm-selector-option"
                                        :class="{ 'odcm-premium-option': shouldShowPremiumBadge(condition) }">
                                    <div class="odcm-option-content">
                                        <div class="odcm-option-title" x-text="condition.label"></div>
                                        <div class="odcm-option-description" x-text="condition.description"></div>
                                    </div>
                                    <span x-show="shouldShowPremiumBadge(condition)"
                                    class="odcm-premium-badge odcm-premium-badge--inline"
                                    :title="odcmRuleBuilderConfig?.upgrade?.message || ''">PRO</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- THEN Section (Actions) -->
                <div class="odcm-rule-section">
                    <h3 class="odcm-section-title">
                        <?php esc_html_e('admin.rule_builder.section.then', 'order-daemon'); ?>
                        <span class="odcm-section-subtitle"><?php esc_html_e('(Actions)', 'order-daemon'); ?></span>
                    </h3>

                    <!-- Primary Action Section -->
                    <div class="odcm-primary-action-section">
                        <h4 class="odcm-subsection-title"><?php esc_html_e('Primary Action', 'order-daemon'); ?></h4>
                        
                        <div x-show="!rule.primaryAction" class="odcm-empty-state">
                            <button type="button" 
                                    @click="isAddingPrimaryAction = !isAddingPrimaryAction" 
                                    class="odcm-add-component-button odcm-add-primary-action-button">
                                <span class="odcm-button-icon">+</span>
                                <?php esc_html_e('admin.rule_builder.action.add_primary_action_description', 'order-daemon'); ?>
                            </button>
                        </div>

                        <!-- Primary Action Row -->
                        <div x-show="rule.primaryAction" class="odcm-rule-row odcm-primary-action" 
                             :class="{ 'odcm-expanded': editingPrimaryAction, 'odcm-no-settings': !componentHasSettings('primaryAction', 0), 'odcm-component-inaccessible': !getComponentDefinition('primaryAction', rule.primaryAction?.id)?.accessible }" 
                             @click="!getComponentDefinition('primaryAction', rule.primaryAction?.id)?.accessible ? null : (componentHasSettings('primaryAction', 0) && handleRowClick('primaryAction', 0, $event))">
                            <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                            <div class="odcm-component-summary" x-html="getComponentSummary(rule.primaryAction, 'primaryAction', 0)"></div>
                            <div class="odcm-component-actions">
                                    <div class="odcm-component-badge odcm-badge-primary">
                                        <?php esc_html_e('Primary', 'order-daemon'); ?>
                                    </div>
                                    <button type="button" 
                                            @click="removePrimaryAction()" 
                                            class="odcm-remove-button"
                                            x-show="components.primaryActions && components.primaryActions.length > 1">
                                        <?php esc_html_e('Change', 'order-daemon'); ?>
                                    </button>
                            </div>
                        </div>

                        <!-- Primary Action Settings Panel -->
                        <div x-show="rule.primaryAction && editingPrimaryAction" 
                             class="odcm-settings-panel"
                             :class="{ 'odcm-expanded': editingPrimaryAction }">
                            <div x-data="settingsPanel('primaryAction', null)" x-init="initSettings(getPrimaryActionComponent(rule.primaryAction?.id)?.schema, rule.primaryAction?.settings || {})">
                                <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                    <div class="odcm-form-group">
                                        <!-- Field Label -->
                                        <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>
                                        
                                        <!-- Field Description -->
                                        <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>
                                        
                                        <!-- Searchable Checkboxes Widget -->
                                        <template x-if="field.widget === 'searchable_checkboxes'">
                                            <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                <div class="odcm-searchable-checkboxes" 
                                                     x-data="searchableWidget(field.id)" 
                                                     x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.premiumOptions, field.key))">
                                                <div class="odcm-search-header">
                                                    <input type="text" 
                                                           :id="field.id + '_search'"
                                                           class="odcm-search-input"
                                                           :placeholder="field.placeholder || 'Search options...'"
                                                           x-model="searchTerm"
                                                           @input="filterOptions()">
                                                    <button type="button" 
                                                            class="odcm-show-all-button"
                                                            x-show="searchTerm && !showAll"
                                                            @click="showAll = true; filterOptions()">
                                                        Show All
                                                    </button>
                                                </div>
                                                <div class="odcm-searchable-list">
                                                    <div class="odcm-checkbox-group">
                                                        <template x-for="option in filteredOptions" :key="option.value">
                                                            <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                                <input type="checkbox" 
                                                                       :id="field.id + '_' + option.value"
                                                                       :value="option.value" 
                                                                       :checked="selectedValues.includes(option.value)"
                                                                       :disabled="shouldDisableOption(option.value)"
                                                                       @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'primaryAction', null)">
                                                                <div class="odcm-checkbox-content">
                                                                    <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                                    <span x-show="premiumOptions.includes(option.value)" class="odcm-premium-badge odcm-premium-badge--inline">PRO</span>
                                                                </div>
                                                            </label>
                                                        </template>
                                                        <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                            <p>No options found matching your search.</p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="odcm-selected-summary">
                                                    <span class="odcm-summary-text" x-show="selectedValues.length > 0">
                                                        Selected: <span x-text="selectedValues.length"></span> option(s)
                                                    </span>
                                                    <div class="odcm-selected-summary-buttons">
                                                        <button type="button" 
                                                                class="odcm-select-all-compact"
                                                                x-show="canSelectAll && hasSelectableOptions"
                                                                @click="selectAll(field.key, 'primaryAction', null)">
                                                            Select All
                                                        </button>
                                                        <button type="button" 
                                                                class="odcm-clear-all-compact"
                                                                x-show="selectedValues.length > 0"
                                                                @click="clearAll(field.key, 'primaryAction', null)">
                                                            Clear All
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            </template>
                                        </template>
                                        
                                        <!-- Other field types -->
                                        <template x-if="field.widget === 'text'">
                                            <input type="text" 
                                                   :id="field.id"
                                                   class="odcm-form-input"
                                                   :value="field.value"
                                                   :placeholder="field.description || ''"
                                                   @input="updateSetting(field.key, $event.target.value, 'primaryAction', null)">
                                        </template>
                                        
                                        <template x-if="field.widget === 'checkbox'">
                                            <label class="odcm-checkbox-label">
                                                <input type="checkbox" 
                                                       :id="field.id"
                                                       :checked="field.value"
                                                       @change="updateSetting(field.key, $event.target.checked, 'primaryAction', null)">
                                                <span class="odcm-checkbox-text" x-text="field.title"></span>
                                            </label>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Primary Action Inline Selector -->
                        <div x-show="isAddingPrimaryAction" class="odcm-inline-selector" :class="{ 'odcm-expanded': isAddingPrimaryAction }">
                            <div class="odcm-selector-header">
                                <input type="text" 
                                       x-model="primaryActionSearchTerm" 
                                       placeholder="<?php esc_attr_e('admin.rule_builder.search.primary_actions_placeholder', 'order-daemon'); ?>"
                                       class="odcm-search-input">
                                <button type="button" 
                                        @click="isAddingPrimaryAction = false" 
                                        class="odcm-close-selector">×</button>
                            </div>
                            <div class="odcm-selector-list">
                                <template x-for="action in filteredPrimaryActions" :key="action.id">
                                    <button type="button" 
                                            @click="selectComponent('primaryAction', action.id)" 
                                            class="odcm-selector-option"
                                            :class="{ 'odcm-premium-option': shouldShowPremiumBadge(action) }">
                                        <div class="odcm-option-content">
                                            <div class="odcm-option-title" x-text="action.label"></div>
                                            <div class="odcm-option-description" x-text="action.description"></div>
                                        </div>
                                        <span x-show="shouldShowPremiumBadge(action)" 
                                              class="odcm-premium-badge odcm-premium-badge--inline">PRO</span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Secondary Actions Section -->
                    <div class="odcm-secondary-actions-section">
                        <h4 class="odcm-subsection-title">
                            <?php esc_html_e('Secondary Actions', 'order-daemon'); ?>
                            <span class="odcm-component-count" x-text="`(${rule.secondaryActions.length})`"></span>
                        </h4>

                        <div x-show="rule.secondaryActions.length === 0" class="odcm-empty-state">
                        </div>

                        <!-- Secondary Actions List -->
                        <template x-for="(action, index) in rule.secondaryActions" :key="index">
                            <div class="odcm-action-wrapper">
                                <!-- Action Row -->
                                <div class="odcm-rule-row" 
                                     :class="{ 'odcm-expanded': editingActionIndex === index, 'odcm-no-settings': !componentHasSettings('action', index), 'odcm-component-inaccessible': !getComponentDefinition('action', action.id)?.accessible }" 
                                     @click="!getComponentDefinition('action', action.id)?.accessible ? null : (componentHasSettings('action', index) && handleRowClick('action', index, $event))">
                                    <div class="odcm-drag-handle" aria-hidden="true">⋮⋮</div>
                                    <div class="odcm-component-summary" x-html="getComponentSummary(action, 'action', index)"></div>
                                    <div class="odcm-component-actions">
                                        <button type="button" 
                                                @click="removeAction(index)" 
                                                class="odcm-remove-button">
                                            <?php esc_html_e('Remove', 'order-daemon'); ?>
                                        </button>
                                    </div>
                                </div>

                                <!-- Action Settings Panel -->
                                <div x-show="editingActionIndex === index" 
                                     class="odcm-settings-panel"
                                     :class="{ 'odcm-expanded': editingActionIndex === index }">
                                    <div x-data="settingsPanel('action', index)" x-init="initSettings(getActionComponent(action.id)?.schema, action.settings || {})">
                                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                            <div class="odcm-form-group">
                                                <!-- Field Label -->
                                                <label x-show="field.title" :for="field.id" class="odcm-form-label" x-text="field.title"></label>
                                                
                                                <!-- Field Description -->
                                                <div x-show="field.description" class="odcm-form-description" x-text="field.description"></div>
                                                
                                                <!-- Searchable Checkboxes Widget -->
                                                <template x-if="field.widget === 'searchable_checkboxes'">
                                                    <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                        <div class="odcm-searchable-checkboxes" 
                                                             x-data="searchableWidget(field.id)" 
                                                             x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.premiumOptions, field.key))">
                                                        <div class="odcm-search-header">
                                                            <input type="text" 
                                                                   :id="field.id + '_search'"
                                                                   class="odcm-search-input"
                                                                   :placeholder="field.placeholder || 'Search options...'"
                                                                   x-model="searchTerm"
                                                                   @input="filterOptions()">
                                                            <button type="button" 
                                                                    class="odcm-show-all-button"
                                                                    x-show="searchTerm && !showAll"
                                                                    @click="showAll = true; filterOptions()">
                                                                Show All
                                                            </button>
                                                        </div>
                                                        <div class="odcm-searchable-list">
                                                            <div class="odcm-checkbox-group">
                                                                <template x-for="option in filteredOptions" :key="option.value">
                                                                    <label :class="getOptionClasses(option.value)" :for="field.id + '_' + option.value" x-show="shouldShowOption(option.value, option.label)">
                                                                        <input type="checkbox" 
                                                                               :id="field.id + '_' + option.value"
                                                                               :value="option.value" 
                                                                               :checked="selectedValues.includes(option.value)"
                                                                               :disabled="shouldDisableOption(option.value)"
                                                                               @change="handleCheckboxChange(field.key, option.value, $event.target.checked, 'action', index)">
                                                                        <div class="odcm-checkbox-content">
                                                                            <span class="odcm-checkbox-text" x-text="option.label"></span>
                                                                            <span x-show="premiumOptions.includes(option.value)" class="odcm-premium-badge odcm-premium-badge--inline">PRO</span>
                                                                        </div>
                                                                    </label>
                                                                </template>
                                                                <div x-show="filteredOptions.length === 0" class="odcm-no-results">
                                                                    <p>No options found matching your search.</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="odcm-selected-summary">
                                                            <span class="odcm-summary-text" x-show="selectedValues.length > 0">
                                                                Selected: <span x-text="selectedValues.length"></span> option(s)
                                                            </span>
                                                            <div class="odcm-selected-summary-buttons">
                                                                <button type="button" 
                                                                        class="odcm-select-all-compact"
                                                                        x-show="canSelectAll && hasSelectableOptions"
                                                                        @click="selectAll(field.key, 'action', index)">
                                                                    Select All
                                                                </button>
                                                                <button type="button" 
                                                                        class="odcm-clear-all-compact"
                                                                        x-show="selectedValues.length > 0"
                                                                        @click="clearAll(field.key, 'action', index)">
                                                                    Clear All
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    </template>
                                                </template>
                                                
                                                <!-- Non-searchable Checkboxes Widget -->
                                                <template x-if="field.widget === 'checkboxes'">
                                                    <div class="odcm-checkbox-group">
                                                        <!-- Clear All button -->
                                                        <div class="odcm-checkbox-controls" x-show="areAnyCheckboxesSelected(field.key, 'action', index)">
                                                            <button type="button" 
                                                                    class="odcm-clear-all-button odcm-checkbox-control-button"
                                                                    @click="clearAllCheckboxes(field.key, 'action', index)">
                                                                Clear All
                                                            </button>
                                                        </div>
                                                        <template x-for="(label, val) in field.enumOptions" :key="val">
                                                            <label class="odcm-checkbox-label" :for="field.id + '_' + val">
                                                                <input type="checkbox"
                                                                       :id="field.id + '_' + val"
                                                                       :value="val"
                                                                       :checked="(field.selectedValues || []).includes(val)"
                                                                       @change="updateArraySetting(field.key, val, $event.target.checked, 'action', index)">
                                                                <span class="odcm-checkbox-text" x-text="label"></span>
                                                            </label>
                                                        </template>
                                                    </div>
                                                </template>

                                                <!-- Button-style radio group -->
                                                <template x-if="field.widget === 'button_radio_group'">
                                                    <div class="odcm-button-radio-group" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                        <template x-for="(label, val) in field.enumOptions" :key="val">
                                                            <button type="button"
                                                                    class="odcm-radio-button"
                                                                    :class="{ 'is-active': (rule.secondaryActions[index]?.settings[field.key] ?? field.value) === val }"
                                                                    :aria-pressed="String((rule.secondaryActions[index]?.settings[field.key] ?? field.value) === val)"
                                                                    @click="updateSetting(field.key, val, 'action', index)"
                                                                    x-text="label">
                                                            </button>
                                                        </template>
                                                    </div>
                                                </template>
                                                

                                                <!-- Other field types -->
                                                <template x-if="field.widget === 'text'">
                                                    <input type="text"
                                                           :id="field.id"
                                                           class="odcm-form-input"
                                                           :value="field.value"
                                                           :placeholder="field.description || ''"
                                                           @input="updateSetting(field.key, $event.target.value, 'action', index)">
                                                </template>

                                                <template x-if="field.widget === 'textarea'">
                                                    <textarea :id="field.id"
                                                           class="odcm-form-textarea"
                                                           rows="4"
                                                           :placeholder="field.description || ''"
                                                           :value="field.value"
                                                           @input="updateSetting(field.key, $event.target.value, 'action', index)"></textarea>
                                                </template>


                                                <template x-if="field.widget === 'checkbox'">
                                                    <label class="odcm-checkbox-label">
                                                        <input type="checkbox"
                                                               :id="field.id"
                                                               :checked="field.value"
                                                               @change="updateSetting(field.key, $event.target.checked, 'action', index)">
                                                        <span class="odcm-checkbox-text" x-text="field.title"></span>
                                                    </label>
                                                </template>

                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Add Secondary Action Button -->
                        <button type="button" 
                                @click="isAddingAction = !isAddingAction" 
                                class="odcm-add-component-button odcm-add-secondary-action-button">
                            <span class="odcm-button-icon">+</span>
                                <?php esc_html_e('Add Secondary Action', 'order-daemon'); ?>
                        </button>

                        <!-- Secondary Action Inline Selector -->
                        <div x-show="isAddingAction" class="odcm-inline-selector" :class="{ 'odcm-expanded': isAddingAction }">
                            <div class="odcm-selector-header">
                                <input type="text" 
                                       x-model="actionSearchTerm" 
                                       placeholder="<?php esc_attr_e('admin.rule_builder.search.secondary_actions_placeholder', 'order-daemon'); ?>"
                                       class="odcm-search-input">
                                <button type="button" 
                                        @click="isAddingAction = false" 
                                        class="odcm-close-selector">×</button>
                            </div>
                            <div class="odcm-selector-list">
                                <template x-for="action in filteredSecondaryActions" :key="action.id">
                                    <button type="button" 
                                            @click="selectComponent('action', action.id)" 
                                            class="odcm-selector-option"
                                            :class="{ 'odcm-premium-option': shouldShowPremiumBadge(action) }">
                                        <div class="odcm-option-content">
                                            <div class="odcm-option-title" x-text="action.label"></div>
                                            <div class="odcm-option-description" x-text="action.description"></div>
                                        </div>
                                        <span x-show="shouldShowPremiumBadge(action)" 
                                              class="odcm-premium-badge odcm-premium-badge--inline">PRO</span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        [x-cloak] { display: none !important; }
        </style>
        <?php
    }
}
