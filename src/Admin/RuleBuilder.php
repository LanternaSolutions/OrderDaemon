<?php

declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry;
use OrderDaemon\CompletionManager\Core\RuleComponents\RuleIndexBuilder;

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
        add_action('all_admin_notices', [$this, 'render_page_header']);
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
                        // Write to debug.log file using safe file operation
                        $debug_file = odcm_get_safe_debug_file_path();
                        odcm_safe_file_put_contents(
                            $debug_file,
                            '[' . gmdate('Y-m-d H:i:s') . '] ODCM Rule Builder: ' . $message . PHP_EOL,
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
        $ds_path = defined('ODCM_PLUGIN_DIR') ? ODCM_PLUGIN_DIR . 'assets/css/odcm-design-system.css' : '';
        $ds_version = (file_exists($ds_path)) ? filemtime($ds_path) : $plugin_version;
        wp_enqueue_style(
            'odcm-design-system',
            $assets_url . 'css/odcm-design-system.css',
            [],
            $ds_version
        );

        // Enqueue modern rule builder CSS with design system dependency
        wp_enqueue_style(
            'odcm-rule-builder',
            $assets_url . 'css/rule-builder.css',
            ['odcm-design-system'],
            $plugin_version
        );

        // Hide WP-generated heading so the unified header is the only one
        wp_add_inline_style('odcm-rule-builder', '
            .post-type-odcm_order_rule .wrap > .wp-heading-inline,
            .post-type-odcm_order_rule .wrap > .page-title-action,
            .post-type-odcm_order_rule hr.wp-header-end { display: none; }
        ');

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
        \OrderDaemon\CompletionManager\Includes\AssetHelper::add_inline_script('wp-api-fetch', sprintf(
            'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
            wp_json_encode($rest_nonce)
        ), 'after');

        \OrderDaemon\CompletionManager\Includes\AssetHelper::add_inline_script('wp-api-fetch', sprintf(
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
            
            // Debug info
            'debug' => [
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

        // Add inline CSS for Alpine.js x-cloak functionality using WordPress standards
        \OrderDaemon\CompletionManager\Includes\AssetHelper::add_inline_style('odcm-rule-builder', '[x-cloak] { display: none !important; }');
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

        // Normalize: PHP's json_decode(true) cannot distinguish an empty JSON object {}
        // from an empty JSON array [], so both decode to [].  When re-encoded for the
        // frontend via wp_localize_script / wp_json_encode, an empty PHP array becomes
        // the JSON array [] rather than the object {}.  Alpine.js then initialises
        // rule.conditions[n].settings as a JavaScript Array, and JSON.stringify silently
        // drops any string-keyed properties written to it — so every updateSetting call
        // is a no-op for serialisation purposes.  Casting empty arrays to stdClass here
        // ensures they re-encode as {} and Alpine gets a plain JS object.
        $rule_data = $this->normalize_empty_settings($rule_data);
        
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
            $can_use = true;
            $already_in_rule = $this->is_component_in_current_rule($component->get_id(), $rule_data);

            // All components default to available
            $component_state = 'available';

            // Always include schema so settings can render in UI; capability is enforced at runtime
            $schema = $component->get_settings_schema();

            $formatted[] = [
                'id'          => $component->get_id(),
                'label'       => $component->get_label(),
                'description' => $component->get_description(),
                'icon'        => method_exists($component, 'get_icon') ? $component->get_icon() : '',
                'schema'      => $schema,
                'accessible'  => $can_use,
                'state'       => $component_state,
                'already_in_rule' => $already_in_rule,
                'is_default'  => method_exists($component, 'is_default') ? $component->is_default() : false,
                'priority'    => method_exists($component, 'get_priority') ? $component->get_priority() : 999,
            ];
        }

        // Sort by priority only
        usort($formatted, function($a, $b) {
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
                    // Cast to array: normalize_empty_settings() may have converted {} to stdClass
                    $current_settings = (array)($rule_data['trigger']['settings'] ?? []);
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
                        (array)($condition['settings'] ?? []),
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
                    // Cast to array: normalize_empty_settings() may have converted {} to stdClass
                    $current_settings = (array)($rule_data['primaryAction']['settings'] ?? []);
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
                        (array)($action['settings'] ?? []),
                        'action',
                        $index
                    );
                }
            }
        }
        
        return $prepared;
    }

    /**
     * Normalises settings values after json_decode(, true) to ensure they
     * re-encode as JSON objects ({}) rather than JSON arrays ([]).
     *
     * PHP's json_decode with the assoc flag cannot distinguish an empty JSON
     * object {} from an empty JSON array [], so both become an empty PHP
     * array [].  When wp_json_encode (or json_encode) later re-encodes that
     * empty array it produces the JSON array [], not the object {}.  Alpine.js
     * receives [] and initialises rule.*.settings as a JavaScript Array; assigning
     * string-keyed properties to a JS Array works at runtime but JSON.stringify
     * silently drops those properties, so every updateSetting write is lost.
     *
     * Casting the empty array to stdClass forces json_encode to output {} which
     * Alpine correctly maps to a plain JavaScript object.
     *
     * @param array $rule_data The decoded rule data.
     * @return array The rule data with empty settings normalised.
     */
    private function normalize_empty_settings(array $rule_data): array
    {
        // Trigger settings
        if (isset($rule_data['trigger']['settings']) && $rule_data['trigger']['settings'] === []) {
            $rule_data['trigger']['settings'] = new \stdClass();
        }

        // Condition settings
        if (isset($rule_data['conditions']) && is_array($rule_data['conditions'])) {
            foreach ($rule_data['conditions'] as $i => $condition) {
                if (isset($condition['settings']) && $condition['settings'] === []) {
                    $rule_data['conditions'][$i]['settings'] = new \stdClass();
                }
            }
        }

        // Primary action settings
        if (isset($rule_data['primaryAction']['settings']) && $rule_data['primaryAction']['settings'] === []) {
            $rule_data['primaryAction']['settings'] = new \stdClass();
        }

        // Secondary action settings
        if (isset($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
            foreach ($rule_data['secondaryActions'] as $i => $action) {
                if (isset($action['settings']) && $action['settings'] === []) {
                    $rule_data['secondaryActions'][$i]['settings'] = new \stdClass();
                }
            }
        }

        return $rule_data;
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
            
            $fields[$key] = [
                'id' => $field_id,
                'key' => $key,
                'title' => $property['title'] ?? '',
                'description' => $property['description'] ?? '',
                'widget' => $this->get_widget_type($property),
                'value' => $current_value !== null ? $current_value : $default_value,
                'enumOptions' => $enum_options,
                'selectedValues' => $selected_values,
                'placeholder' => $property['ui:placeholder'] ?? '',
                // Numeric attributes for number/integer inputs
                'minimum' => isset($property['minimum']) ? $property['minimum'] : null,
                'maximum' => isset($property['maximum']) ? $property['maximum'] : null,
                'step' => isset($property['step']) ? $property['step'] : (($property['type'] ?? '') === 'integer' ? 1 : null),
                'default' => $default_value,
                // Radio-with-inline-number patterns mapping
                'radioInputs' => $property['ui:radio_inputs'] ?? [],
                // Inline grouping for horizontal layout
                'inlineGroup' => $property['ui:inline_group'] ?? null
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
    public function render_page_header(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'odcm_order_rule' || !in_array($screen->base, ['post', 'post-new'], true)) {
            return;
        }
        $rules_url = admin_url('admin.php?page=odcm-rules-list');
        ?>
        <div class="odcm-unified-header odcm-scope">
            <div class="odcm-unified-header__brand">
                <svg width="22" height="22" viewBox="0 0 128 128" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M115.575 38.0075C116.022 37.9874 116.467 38.0076 116.911 38.0682C117.074 38.4076 117.013 38.7113 116.728 38.9793C110.372 46.5109 104.341 54.2856 98.6351 62.3033C96.4699 65.5014 94.365 68.7407 92.3207 72.0217C91.9153 72.364 91.4499 72.4652 90.9242 72.3254C90.2886 71.689 89.7422 70.9803 89.2849 70.1995C86.957 66.4332 84.6903 62.6269 82.4847 58.7804C82.0502 57.6476 82.4348 57.2225 83.6383 57.5049C84.8632 58.3251 85.9359 59.3171 86.8562 60.4811C88.0448 62.0349 89.2794 63.5533 90.5599 65.0366C90.8192 65.1137 91.062 65.0732 91.2885 64.9151C93.8523 61.257 96.4631 57.6329 99.1209 54.0427C102.193 50.037 105.492 46.2104 109.018 42.563C110.486 41.1616 112.064 39.9062 113.753 38.7971C114.358 38.4955 114.965 38.2322 115.575 38.0075Z"/>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M35.5513 43.3525C42.0969 42.9637 47.8042 44.9275 52.6732 49.2443C55.272 51.6805 57.5994 54.3328 59.6555 57.2011C59.9332 57.5996 60.1558 58.0248 60.3234 58.4767C59.7453 59.6333 59.0167 60.6861 58.1376 61.6351C57.9623 61.73 57.8004 61.7097 57.6519 61.5744C55.3649 57.9691 52.5719 54.7904 49.2731 52.0383C45.2311 48.7875 40.6167 47.3702 35.4299 47.7865C28.6765 48.9939 24.2036 52.8611 22.0117 59.3878C20.1255 67.2545 22.4934 73.4702 29.1154 78.0348C36.2941 81.8873 42.9729 81.1584 49.1517 75.8482C51.201 73.9197 53.1236 71.8747 54.9197 69.7135C57.8808 65.82 60.7952 61.8921 63.6628 57.93C66.5835 53.9957 69.9633 50.4931 73.8023 47.4221C79.2353 43.7409 85.1045 42.85 91.41 44.7495C93.6407 45.4694 95.5633 46.6639 97.178 48.3332C97.8828 49.5333 97.5387 50.0395 96.1458 49.8517C88.3096 45.9676 81.0641 46.818 74.4095 52.4027C71.581 55.1104 68.9702 58.0056 66.5771 61.0885C63.2644 65.6579 59.9047 70.1931 56.4983 74.6941C53.4913 78.5538 49.7472 81.449 45.2659 83.3799C36.2004 86.3776 28.4086 84.4137 21.8902 77.4882C17.7008 72.3713 16.2032 66.5403 17.3973 59.9952C19.2203 52.0575 23.9764 46.7731 31.6655 44.1421C32.9642 43.8115 34.2594 43.5482 35.5513 43.3525Z"/>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M103.31 62.6677C103.51 62.6417 103.692 62.6823 103.857 62.7892C104.012 63.0585 104.133 63.3421 104.221 63.6396C104.425 70.9849 101.692 76.9577 96.0244 81.5578C89.3454 85.7966 82.4643 86.1206 75.381 82.5296C70.5663 79.657 66.6198 75.8708 63.5414 71.1713C63.4421 70.7505 63.4827 70.3455 63.6628 69.9565C63.8724 69.8258 64.095 69.8055 64.3307 69.8958C67.5089 73.4036 71.1114 76.3595 75.1381 78.7637C79.4853 81.2028 84.0998 81.9317 88.9813 80.9504C93.9758 79.5176 97.7605 76.5414 100.335 72.0216C101.837 69.3362 102.728 66.4613 103.007 63.3966C103.076 63.1365 103.177 62.8936 103.31 62.6677Z"/>
                </svg>
                <span class="odcm-unified-header__title">Order Daemon</span>
            </div>
            <span class="odcm-unified-header__sep" aria-hidden="true">/</span>
            <a class="odcm-unified-header__crumb" href="<?php echo esc_url($rules_url); ?>"><?php echo esc_html__('admin.list_table.page_title', 'order-daemon'); ?></a>
            <span class="odcm-unified-header__sep" aria-hidden="true">/</span>
            <span class="odcm-unified-header__crumb"><?php echo esc_html__('admin.rule_builder.page_title', 'order-daemon'); ?></span>
        </div>
        <?php
    }

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
        
        <div class="odcm-rule-builder-wrapper rb odcm-scope" x-data="ruleBuilder()" x-cloak x-init="$watch('rule', value => { document.getElementById('odcm_rule_data_field').value = JSON.stringify(value); })">
            <!-- Loading State -->
            <div x-show="loading" class="odcm-loading-state">
                <div class="odcm-loading-spinner"></div>
                <p><?php esc_html_e('admin.rule_builder.status.loading_rule_builder', 'order-daemon'); ?></p>
            </div>

            <!-- Main Application -->
            <div x-show="!loading">
                <!-- Title row -->
                <div class="rb__title-row">
                    <h3 class="rb__title"><?php esc_html_e('admin.rule_builder.page_title', 'order-daemon'); ?></h3>
                </div>

                <!-- Rule body -->
                <div class="rb__body">

                <!-- WHEN Section (Trigger) -->
                <div class="rb__section">
                    <div class="rb__section-head">
                        <span class="odcm-kw"><?php esc_html_e('admin.rule_builder.section.when', 'order-daemon'); ?></span>
                        <span class="rb__section-sub"><?php esc_html_e('admin.rule_builder.trigger_section_label', 'order-daemon'); ?></span>
                        <span class="rb__section-count">· 1 of 1 required</span>
                    </div>

                    <div x-show="!rule.trigger">
                        <button type="button"
                                @click="isAddingTrigger = !isAddingTrigger"
                                class="rb__add">
                            <span class="plus">+</span>
                            <?php esc_html_e('admin.rule_builder.action.add_trigger_description', 'order-daemon'); ?>
                        </button>
                    </div>

                    <!-- Trigger Component Row -->
                    <div x-show="rule.trigger" class="rb__row" :data-expanded="editingTriggerIndex === 0 ? 'true' : 'false'">
                        <div class="rb__row-head" @click="!getComponentDefinition('trigger', rule.trigger?.id)?.accessible ? null : (componentHasSettings('trigger', 0) && handleRowClick('trigger', 0, $event))">
                            <div class="rb__row-summary" x-html="getComponentSummary(rule.trigger, 'trigger', 0)"></div>
                            <div class="rb__row-actions">
                                <button type="button"
                                        @click.stop="removeTrigger()"
                                        class="rb__icon-btn rb__icon-btn--danger"
                                        title="<?php esc_attr_e('admin.rule_builder.remove_button', 'order-daemon'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="rb__row-body">
                            <div class="rb__settings">
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
                                <div class="rb__settings-form">
                                    <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                        <div class="rb__field">
                                            <!-- Field Label -->
                                            <label x-show="field.title" :for="field.id" class="rb__field-label" x-text="field.title"></label>
                                            
                                            <!-- Field Description -->
                                            <div x-show="field.description" class="rb__field-help" x-text="field.description"></div>
                                            
                                            <!-- Searchable Checkboxes Widget -->
                                            <template x-if="field.widget === 'searchable_checkboxes'">
                                                <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                    <div class="odcm-searchable-checkboxes" 
                                                         x-data="searchableWidget(field.id)" 
                                                         x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.key))">
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
                                                <div class="odcm-segmented" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <button type="button"
                                                                class="odcm-segmented__item"
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
                                                          class="odcm-input"
                                                          rows="6"
                                                          :placeholder="field.placeholder || ''"
                                                          :value="field.value"
                                                          @input="updateSetting(field.key, $event.target.value, 'trigger', 0)"></textarea>
                                            </template>
                                            
                                            <!-- Other field types -->
                                            <template x-if="field.widget === 'text'">
                                                <input type="text"
                                                       :id="field.id"
                                                       class="odcm-input"
                                                       :value="field.value"
                                                       :placeholder="field.placeholder || ''"
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
                            </div><!-- /rb__settings -->
                        </div><!-- /rb__row-body -->
                    </div><!-- /rb__row -->

                    <!-- Trigger Inline Selector -->
                    <div x-show="isAddingTrigger" class="rb__picker">
                        <div class="rb__picker-head">
                            <div style="flex: 1;">
                                <h4><?php esc_html_e('admin.rule_builder.selector.trigger_title', 'order-daemon'); ?></h4>
                            </div>
                            <button type="button" class="odcm-btn odcm-btn--ghost odcm-btn--sm" @click="isAddingTrigger = false">Cancel</button>
                        </div>
                        <div class="rb__picker-search">
                            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.5-3.5"/></svg></span>
                            <input type="text"
                                   x-model="triggerSearchTerm"
                                   placeholder="<?php esc_attr_e('admin.rule_builder.search.triggers_placeholder', 'order-daemon'); ?>"
                                   class="odcm-input">
                        </div>
                        <div class="rb__picker-list">
                            <template x-for="trigger in filteredTriggers" :key="trigger.id">
                                <button type="button"
                                        @click="selectComponent('trigger', trigger.id)"
                                        class="rb__picker-item">
                                    <span class="rb__picker-item-icon" x-html="trigger.icon"></span>
                                    <div class="rb__picker-item-main">
                                        <div class="rb__picker-item-head">
                                            <span class="rb__picker-item-label" x-text="trigger.label"></span>
                                        </div>
                                        <span class="rb__picker-item-desc" x-text="trigger.description"></span>
                                    </div>
                                    <span class="rb__picker-item-chev">→</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- IF Section (Conditions) -->
                <div class="rb__section">
                    <div class="rb__section-head">
                        <span class="odcm-kw"><?php esc_html_e('admin.rule_builder.section.if', 'order-daemon'); ?></span>
                        <span class="rb__section-sub"><?php esc_html_e('admin.rule_builder.conditions_section_label', 'order-daemon'); ?></span>
                        <span class="rb__section-count" x-text="`· ${rule.conditions.length}`"></span>
                    </div>

                    <!-- Conditions List -->
                    <template x-for="(condition, index) in rule.conditions" :key="index">
                        <!-- Condition Row (with nested settings) -->
                        <div class="rb__row"
                             :data-expanded="editingConditionIndex === index ? 'true' : 'false'"
                             :data-test-state="$store.odcmTestHighlight?.conditionStates[index] ?? ''"
                             draggable="true"
                             @dragstart="startDragCondition(index, $event)"
                             @dragover="dragOverCondition(index, $event)"
                             @drop="dropCondition(index, $event)"
                             @dragend="endDrag()">
                            <div class="rb__row-head" @click="!getComponentDefinition('condition', condition.id)?.accessible ? null : (componentHasSettings('condition', index) && handleRowClick('condition', index, $event))">
                                <span class="drag" aria-hidden="true">⋮⋮</span>
                                <span class="rb__row-icon" x-html="getComponentDefinition('condition', condition.id)?.icon || ''" aria-hidden="true"></span>
                                <div class="rb__row-summary" x-html="getComponentSummary(condition, 'condition', index)"></div>
                                <div class="rb__row-actions">
                                    <button type="button"
                                            @click.stop="removeCondition(index)"
                                            class="rb__icon-btn rb__icon-btn--danger"
                                            title="<?php esc_attr_e('admin.rule_builder.remove_button', 'order-daemon'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="rb__row-body">
                            <div class="rb__settings">
                                <div x-data="{ 
                                        ...settingsPanel('condition', index),
                                        activeGroup: (rule.conditions[index]?.settings?.comparison_type || getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.default || 'absolute_date'),
                                        hasConditionalGroups() {
                                            return !!getConditionComponent(condition.id)?.schema?.properties?.comparison_type?.['ui:conditional_groups'];
                                        },
                                        getConditionalGroupFields() {
                                            const schema = getConditionComponent(condition.id)?.schema;
                                            if (!schema?.properties?.comparison_type?.['ui:conditional_groups']) return null;
                                            return schema.properties.comparison_type['ui:conditional_groups'][this.activeGroup] || [];
                                        },
                                        isFieldInActiveGroup(fieldKey) {
                                            if (!this.hasConditionalGroups()) return true;
                                            const schema = getConditionComponent(condition.id)?.schema;
                                            const conditionalGroups = schema?.properties?.comparison_type?.['ui:conditional_groups'];
                                            if (!conditionalGroups) return true;
                                            // comparison_type is always visible (it's the controller)
                                            if (fieldKey === 'comparison_type') return true;
                                            // Check if field is in any conditional group
                                            const allConditionalFields = Object.values(conditionalGroups).flat();
                                            if (!allConditionalFields.includes(fieldKey)) return true;
                                            // Check if field is in the active group
                                            const activeFields = conditionalGroups[this.activeGroup] || [];
                                            return activeFields.includes(fieldKey);
                                        }
                                     }" 
                                     x-init="$nextTick(() => {
                                        const doInit = () => {
                                            const comp = getConditionComponent(condition.id);
                                            const schema = comp?.schema;
                                            const settings = rule.conditions[index]?.settings || {};
                                            initSettings(schema, settings);
                                            const ct = settings.comparison_type ?? comp?.schema?.properties?.comparison_type?.default;
                                            if (ct) activeGroup = ct;
                                        };
                                        doInit();
                                        $watch(() => editingConditionIndex, (v) => { if (v === index) doInit(); });
                                        $watch(() => rule.conditions[index]?.settings?.comparison_type, (val) => { if (val) activeGroup = val; });
                                     })">
                                    <div class="rb__settings-form">
                                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                            <div class="rb__field" 
                                             x-show="isFieldInActiveGroup(fieldKey)"
                                             :class="field.inlineGroup ? 'odcm-inline-group odcm-inline-group--' + field.inlineGroup : ''">
                                            <!-- Field Label -->
                                            <label x-show="field.title" :for="field.id" class="rb__field-label" x-text="field.title"></label>
                                            
                                            <!-- Field Description -->
                                            <div x-show="field.description" class="rb__field-help" x-text="field.description"></div>
                                            
                                            <!-- Searchable Checkboxes Widget -->
                                            <template x-if="field.widget === 'searchable_checkboxes'">
                                                <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                    <div class="odcm-searchable-checkboxes" 
                                                         x-data="searchableWidget(field.id)" 
                                                         x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.key))">
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
                                                                   @change="updateSetting(field.key, $event.target.value, 'condition', index); if (fieldKey === 'comparison_type') activeGroup = $event.target.value">
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
                                                <div class="odcm-segmented" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                    <template x-for="(label, val) in field.enumOptions" :key="val">
                                                        <button type="button"
                                                                class="odcm-segmented__item"
                                                                :class="{ 'is-active': (rule.conditions[index]?.settings[field.key] ?? field.value) === val }"
                                                                :aria-pressed="String((rule.conditions[index]?.settings[field.key] ?? field.value) === val)"
                                                                @click="updateRadioSetting(field.key, val, 'condition', index)"
                                                                x-text="label">
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>
                                            
                                            <!-- Textarea field -->
                                            <template x-if="field.widget === 'textarea'">
                                                <textarea :id="field.id"
                                                          class="odcm-input"
                                                          rows="6"
                                                          :placeholder="field.placeholder || ''"
                                                          :value="field.value"
                                                          @input="updateSetting(field.key, $event.target.value, 'condition', index)"></textarea>
                                            </template>

                                            <!-- Date picker widget -->
                                            <template x-if="field.widget === 'date_picker'">
                                                <input type="date"
                                                       :id="field.id"
                                                       class="odcm-input odcm-date-picker"
                                                       :value="field.value"
                                                       @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                                            </template>

                                            <!-- Time picker widget -->
                                            <template x-if="field.widget === 'time_picker'">
                                                <input type="time"
                                                       :id="field.id"
                                                       class="odcm-input odcm-time-picker"
                                                       :value="field.value"
                                                       @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                                            </template>

                                            <!-- Number input widget -->
                                            <template x-if="field.widget === 'number'">
                                                <input type="number"
                                                       :id="field.id"
                                                       class="odcm-input odcm-number-input"
                                                       :value="field.value"
                                                       :min="field.minimum"
                                                       :max="field.maximum"
                                                       :step="field.step"
                                                       @input="updateSetting(field.key, $event.target.value, 'condition', index)">
                                            </template>

                                            <!-- Other field types -->
                                            <template x-if="field.widget === 'text'">
                                                <input type="text"
                                                       :id="field.id"
                                                       class="odcm-input"
                                                       :value="field.value"
                                                       :placeholder="field.placeholder || ''"
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
                            </div><!-- /rb__settings -->
                        </div><!-- /rb__row-body -->
                    </div><!-- /rb__row -->
                    </template>

                    <!-- Add Condition Button -->
                    <button type="button"
                            @click="isAddingCondition = !isAddingCondition"
                            class="rb__add"
                            x-show="!isAddingCondition">
                        <span class="plus">+</span>
                        <?php esc_html_e('admin.rule_builder.add_condition_button', 'order-daemon'); ?>
                    </button>

                    <!-- Condition Picker -->
                    <div x-show="isAddingCondition" class="rb__picker">
                        <div class="rb__picker-head">
                            <div style="flex: 1;">
                                <h4><?php esc_html_e('admin.rule_builder.selector.condition_title', 'order-daemon'); ?></h4>
                            </div>
                            <button type="button" class="odcm-btn odcm-btn--ghost odcm-btn--sm" @click="isAddingCondition = false">Cancel</button>
                        </div>
                        <div class="rb__picker-search">
                            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.5-3.5"/></svg></span>
                            <input type="text"
                                   x-model="conditionSearchTerm"
                                   placeholder="<?php esc_attr_e('admin.rule_builder.condition_search_placeholder', 'order-daemon'); ?>"
                                   class="odcm-input">
                        </div>
                        <div class="rb__picker-list">
                            <template x-for="condition in filteredConditions" :key="condition.id">
                                <button type="button"
                                        @click="selectComponent('condition', condition.id)"
                                        class="rb__picker-item">
                                    <span class="rb__picker-item-icon" x-html="condition.icon"></span>
                                    <div class="rb__picker-item-main">
                                        <div class="rb__picker-item-head">
                                            <span class="rb__picker-item-label" x-text="condition.label"></span>
                                        </div>
                                        <span class="rb__picker-item-desc" x-text="condition.description"></span>
                                    </div>
                                    <span class="rb__picker-item-chev">→</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- THEN Section (Actions) -->
                <div class="rb__section">
                    <div class="rb__section-head">
                        <span class="odcm-kw"><?php esc_html_e('admin.rule_builder.section.then', 'order-daemon'); ?></span>
                        <span class="rb__section-sub"><?php esc_html_e('admin.rule_builder.actions_section_label', 'order-daemon'); ?></span>
                    </div>

                    <!-- Primary Action Row (with nested settings) -->
                    <div x-show="!rule.primaryAction">
                        <button type="button"
                                @click="isAddingPrimaryAction = !isAddingPrimaryAction"
                                class="rb__add">
                            <span class="plus">+</span>
                            <?php esc_html_e('admin.rule_builder.action.add_primary_action_description', 'order-daemon'); ?>
                        </button>
                    </div>

                    <div x-show="rule.primaryAction" class="rb__row" :data-expanded="editingPrimaryAction ? 'true' : 'false'">
                        <div class="rb__row-head" @click="!getComponentDefinition('primaryAction', rule.primaryAction?.id)?.accessible ? null : (componentHasSettings('primaryAction', 0) && handleRowClick('primaryAction', 0, $event))">
                            <div class="rb__row-summary" x-html="getComponentSummary(rule.primaryAction, 'primaryAction', 0)"></div>
                            <div class="rb__row-actions">
                                <span class="odcm-pill rb__pill-primary"><?php esc_html_e('admin.rule_builder.primary_badge', 'order-daemon'); ?></span>
                                <button type="button"
                                        @click.stop="removePrimaryAction()"
                                        class="rb__icon-btn rb__icon-btn--danger"
                                        x-show="components.primaryActions && components.primaryActions.length > 1"
                                        title="<?php esc_attr_e('admin.rule_builder.remove_button', 'order-daemon'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="rb__row-body">
                            <div class="rb__settings">
                            <div x-data="settingsPanel('primaryAction', null)" x-init="initSettings(getPrimaryActionComponent(rule.primaryAction?.id)?.schema, rule.primaryAction?.settings || {})">
                                <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                    <div class="rb__field">
                                        <!-- Field Label -->
                                        <label x-show="field.title" :for="field.id" class="rb__field-label" x-text="field.title"></label>
                                        
                                        <!-- Field Description -->
                                        <div x-show="field.description" class="rb__field-help" x-text="field.description"></div>
                                        
                                        <!-- Searchable Checkboxes Widget -->
                                        <template x-if="field.widget === 'searchable_checkboxes'">
                                            <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                <div class="odcm-searchable-checkboxes" 
                                                     x-data="searchableWidget(field.id)" 
                                                     x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.key))">
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
                                                       class="odcm-input"
                                                       :value="field.value"
                                                       :placeholder="field.placeholder || ''"
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
                            </div><!-- /settingsPanel x-data -->
                        </div><!-- /rb__settings -->
                    </div><!-- /rb__row-body -->
                    </div><!-- /rb__row (primary action) -->

                    <!-- Primary Action Picker -->
                    <div x-show="isAddingPrimaryAction" class="rb__picker">
                        <div class="rb__picker-head">
                            <div style="flex: 1;">
                                <h4><?php esc_html_e('admin.rule_builder.selector.primary_action_title', 'order-daemon'); ?></h4>
                            </div>
                            <button type="button" class="odcm-btn odcm-btn--ghost odcm-btn--sm" @click="isAddingPrimaryAction = false">Cancel</button>
                        </div>
                        <div class="rb__picker-search">
                            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.5-3.5"/></svg></span>
                            <input type="text"
                                   x-model="primaryActionSearchTerm"
                                   placeholder="<?php esc_attr_e('admin.rule_builder.search.primary_actions_placeholder', 'order-daemon'); ?>"
                                   class="odcm-input">
                        </div>
                        <div class="rb__picker-list">
                            <template x-for="action in filteredPrimaryActions" :key="action.id">
                                <button type="button"
                                        @click="selectComponent('primaryAction', action.id)"
                                        class="rb__picker-item">
                                    <span class="rb__picker-item-icon" x-html="action.icon"></span>
                                    <div class="rb__picker-item-main">
                                        <div class="rb__picker-item-head">
                                            <span class="rb__picker-item-label" x-text="action.label"></span>
                                        </div>
                                        <span class="rb__picker-item-desc" x-text="action.description"></span>
                                    </div>
                                    <span class="rb__picker-item-chev">→</span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Secondary Actions List -->
                    <template x-for="(action, index) in rule.secondaryActions" :key="index">
                        <!-- Secondary Action Row (with nested settings) -->
                        <div class="rb__row"
                             :data-expanded="editingActionIndex === index ? 'true' : 'false'">
                            <div class="rb__row-head" @click="!getComponentDefinition('action', action.id)?.accessible ? null : (componentHasSettings('action', index) && handleRowClick('action', index, $event))">
                                <div class="rb__row-summary" x-html="getComponentSummary(action, 'action', index)"></div>
                                <div class="rb__row-actions">
                                    <button type="button"
                                            @click.stop="removeAction(index)"
                                            class="rb__icon-btn rb__icon-btn--danger"
                                            title="<?php esc_attr_e('admin.rule_builder.remove_button', 'order-daemon'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                    </div>
                                </div>

                                <div class="rb__row-body">
                                <div class="rb__settings">
                                    <div x-data="settingsPanel('action', index)" x-init="initSettings(getActionComponent(action.id)?.schema, action.settings || {})">
                                        <template x-for="(field, fieldKey) in fields" :key="fieldKey">
                                            <div class="rb__field">
                                                <!-- Field Label -->
                                                <label x-show="field.title" :for="field.id" class="rb__field-label" x-text="field.title"></label>
                                                
                                                <!-- Field Description -->
                                                <div x-show="field.description" class="rb__field-help" x-text="field.description"></div>
                                                
                                                <!-- Searchable Checkboxes Widget -->
                                                <template x-if="field.widget === 'searchable_checkboxes'">
                                                    <template x-if="field.enumOptions && Object.keys(field.enumOptions).length > 0">
                                                        <div class="odcm-searchable-checkboxes" 
                                                             x-data="searchableWidget(field.id)" 
                                                             x-init="$nextTick(() => init(field.enumOptions, field.selectedValues, field.key))">
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
                                                    <div class="odcm-segmented" role="radiogroup" :aria-labelledby="field.id + '_label'">
                                                        <template x-for="(label, val) in field.enumOptions" :key="val">
                                                            <button type="button"
                                                                    class="odcm-segmented__item"
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
                                                           class="odcm-input"
                                                           :value="field.value"
                                                           :placeholder="field.placeholder || ''"
                                                           @input="updateSetting(field.key, $event.target.value, 'action', index)">
                                                </template>

                                                <template x-if="field.widget === 'textarea'">
                                                    <textarea :id="field.id"
                                                           class="odcm-input"
                                                           rows="4"
                                                           :placeholder="field.placeholder || ''"
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
                                </div><!-- /rb__settings -->
                            </div><!-- /rb__row-body -->
                        </div><!-- /rb__row (secondary action) -->
                    </template>

                    <!-- Add Secondary Action Button -->
                    <button type="button"
                            @click="isAddingAction = !isAddingAction"
                            class="rb__add"
                            x-show="!isAddingAction">
                        <span class="plus">+</span>
                        <?php esc_html_e('admin.rule_builder.add_secondary_action_button', 'order-daemon'); ?>
                    </button>

                    <!-- Secondary Action Picker -->
                    <div x-show="isAddingAction" class="rb__picker">
                        <div class="rb__picker-head">
                            <div style="flex: 1;">
                                <h4><?php esc_html_e('admin.rule_builder.selector.secondary_action_title', 'order-daemon'); ?></h4>
                            </div>
                            <button type="button" class="odcm-btn odcm-btn--ghost odcm-btn--sm" @click="isAddingAction = false">Cancel</button>
                        </div>
                        <div class="rb__picker-search">
                            <span class="icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.5-3.5"/></svg></span>
                            <input type="text"
                                   x-model="actionSearchTerm"
                                   placeholder="<?php esc_attr_e('admin.rule_builder.search.secondary_actions_placeholder', 'order-daemon'); ?>"
                                   class="odcm-input">
                        </div>
                        <div class="rb__picker-list">
                            <template x-for="action in filteredSecondaryActions" :key="action.id">
                                <button type="button"
                                        @click="selectComponent('action', action.id)"
                                        class="rb__picker-item">
                                    <span class="rb__picker-item-icon" x-html="action.icon"></span>
                                    <div class="rb__picker-item-main">
                                        <div class="rb__picker-item-head">
                                            <span class="rb__picker-item-label" x-text="action.label"></span>
                                        </div>
                                        <span class="rb__picker-item-desc" x-text="action.description"></span>
                                    </div>
                                    <span class="rb__picker-item-chev">→</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div><!-- /rb__section (THEN) -->
                </div><!-- /rb__body -->
            </div><!-- /app div -->
        </div><!-- /odcm-rule-builder-wrapper -->

        <?php
    }
}
