<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

// require_once ODCM_PLUGIN_DIR . 'src/Admin/AuditTrailAdmin.php';

use OrderDaemon\CompletionManager\Admin\RuleBuilder;
use OrderDaemon\CompletionManager\Admin\Notices;
use OrderDaemon\CompletionManager\Includes\Odcm_Config;

class Admin
{

    /**
     * Rule builder instance.
     *
     * @var RuleBuilder The modern rule builder for our CPT.
     */
    private RuleBuilder $rule_builder;

    /**
     * Notices instance.
     *
     * @var Notices
     */
    private Notices $notices;

    /**
     * Audit trail admin instance.
     *
     * @var AuditTrailAdmin
     */
    // private AuditTrailAdmin $audit_trail_admin;

    /**
     * Admin constructor.
     */
    public function __construct()
    {
        $this->rule_builder = new RuleBuilder();
        $this->notices      = new Notices();

    }//end __construct()


    /**
     * Initialize the admin functionality.
     * 
     * IMPORTANT: Post type registration is NOT handled here to prevent race conditions.
     * The 'odcm_completion_rule' post type is registered globally in Plugin.php on
     * 'init' hook priority 5 to ensure it's available in all execution contexts
     * (admin, CLI, frontend, background processing) before any admin_init hooks fire.
     * 
     * This method only initializes admin-specific functionality that depends on
     * the post type already being registered.
     *
     * @return void
     * @since 1.0.0
     */
    public function init(): void
    {
        // REMOVED: Post type registration is now handled globally in Plugin.php
        // to prevent race conditions between admin_init hooks and post type registration.
        // The post type MUST be available before any admin functionality tries to query it.

        // Initialize rule builder
        $this->rule_builder->init();

        // Register notices
        $this->notices->register();


        // Register admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts'], 10);

        // Register front-end scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_styles'], 10);

        // Add custom columns to the order_rule post type
        add_filter('manage_odcm_order_rule_posts_columns', [$this, 'add_custom_columns'], 10);
        add_action('manage_odcm_order_rule_posts_custom_column', [$this, 'render_custom_columns'], 10, 2);

        // Handle AJAX requests for toggling rule status
        add_action('wp_ajax_odcm_toggle_rule_status', [$this, 'ajax_toggle_rule_status'], 10);

        // Add Order Rule to admin bar "+ New" menu
        add_action('admin_bar_menu', [$this, 'add_order_rule_to_admin_bar'], 999);

    }//end init()


    /**
     * Enqueue admin scripts.
     *
     * @param  string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function enqueue_admin_scripts(string $hook_suffix): void
    {
        // Get the version for the scripts
        $script_version = defined('ODCM_VERSION') ? ODCM_VERSION : '1.0.0';

        // ALWAYS enqueue admin notices script on ALL admin pages
        // This ensures notices can be dismissed regardless of where they appear
        $notices_script_path = ODCM_PLUGIN_URL.'assets/js/admin-notices.js';
        wp_enqueue_script('odcm-admin-notices', $notices_script_path, [], $script_version, true);
        
        // Localize the ajaxurl for the notices script
        wp_localize_script('odcm-admin-notices', 'odcm_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('odcm_dismiss_notice_nonce')
        ]);

        // Call the audit trail admin enqueue method for audit trail specific assets
        // $this->audit_trail_admin->enqueue_assets($hook_suffix);

        // Define whitelist of plugin pages where other assets should be loaded
        $allowed_hooks = [
            'post.php',
            'post-new.php',
            'edit.php',
            'toplevel_page_odcm-audit-trail',
            'woocommerce_page_odcm-settings',
            'woocommerce_page_odcm-audit-trail',
            'admin_page_odcm-audit-trail',
        ];

        // Check if we're on one of our plugin pages for other scripts
        if (!in_array($hook_suffix, $allowed_hooks, true)) {
            return;
            // Not one of our pages, so don't load other scripts
        }

        // For post edit screens, verify it's our custom post type
        if (in_array($hook_suffix, ['post.php', 'post-new.php', 'edit.php'], true)) {
            $screen = get_current_screen();
            if (!$screen || 'odcm_order_rule' !== $screen->post_type) {
                return;
                // It's an edit screen, but not for our CPT
            }
        }

        // Enqueue admin styles on plugin pages
        wp_enqueue_style(
            'odcm-admin-styles',
            ODCM_PLUGIN_URL.'assets/css/admin.css',
            [],
            $script_version
        );

        // Enqueue toggle button script only on the completion rules list page
        if ($hook_suffix === 'edit.php') {
            // Enqueue JS for the toggle button functionality
            wp_enqueue_script(
                'odcm-toggle-rules',
                ODCM_PLUGIN_URL.'assets/js/toggle-rules.js',
                ['jquery'],
                $script_version,
                true
            );

            // Pass data to the script
            wp_localize_script(
                'odcm-toggle-rules',
                'odcmToggleRules',
                [
                    'ajaxUrl'          => admin_url('admin-ajax.php'),
                    'activeText'       => __('Active', Odcm_Config::$text_domain),
                    'inactiveText'     => __('Inactive', Odcm_Config::$text_domain),
                    'errorMessage'     => __('Error updating rule status. Please try again.', Odcm_Config::$text_domain),
                    'draftText'        => __('Draft', Odcm_Config::$text_domain),
                    'publishedText'    => __('Published', Odcm_Config::$text_domain),
                    'lastModifiedText' => __('Last Modified', Odcm_Config::$text_domain),
                ]
            );
        }//end if

        // NOTE: Audit trail assets are now handled by AuditTrailAdmin::enqueue_assets()
        // to prevent duplicate loading and ensure proper hook detection

    }//end enqueue_admin_scripts()


    /**
     * Register the order_rule Custom Post Type.
     * 
     * CRITICAL: This method is called globally from Plugin.php on 'init' hook priority 5
     * to ensure the post type is available in ALL execution contexts before any
     * admin_init hooks fire. This prevents "invalid post type" errors when:
     * 
     * - Admin forms try to query order rules
     * - CLI commands access the post type
     * - Background Action Scheduler tasks process orders
     * - Cron jobs run completion checks
     * 
     * DO NOT call this method directly from Admin::init() as it will create
     * race conditions with admin_init hooks.
     *
     * @return void
     * @since 1.0.0
     */
    public function register_completion_rule_post_type(): void
    {
        $labels = [
            'name'               => _x('Order Rules', 'post type general name', Odcm_Config::$text_domain),
            'singular_name'      => _x('Order Rule', 'post type singular name', Odcm_Config::$text_domain),
            'menu_name'          => _x('Order Rules', 'admin menu', Odcm_Config::$text_domain),
            'name_admin_bar'     => _x('Order Rule', 'add new on admin bar', Odcm_Config::$text_domain),
            'add_new'            => _x('Add New', 'order rule', Odcm_Config::$text_domain),
            'add_new_item'       => __('Add New Order Rule', Odcm_Config::$text_domain),
            'new_item'           => __('New Order Rule', Odcm_Config::$text_domain),
            'edit_item'          => __('Edit Order Rule', Odcm_Config::$text_domain),
            'view_item'          => __('View Order Rule', Odcm_Config::$text_domain),
            'all_items'          => __('All Order Rules', Odcm_Config::$text_domain),
            'search_items'       => __('Search Order Rules', Odcm_Config::$text_domain),
            'parent_item_colon'  => __('Parent Order Rules:', Odcm_Config::$text_domain),
            'not_found'          => __('No order rules found.', Odcm_Config::$text_domain),
            'not_found_in_trash' => __('No order rules found in Trash.', Odcm_Config::$text_domain),
        ];

        $args = [
            'labels'             => $labels,
            'description'        => __('Completion rules for WooCommerce', Odcm_Config::$text_domain),
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => 'woocommerce', // Show in WooCommerce submenu for WordPress editor access
            'show_in_admin_bar'  => false,          // Keep admin bar clean
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'map_meta_cap'       => true,           // Enable proper capability mapping
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => [
                'title',
                'page-attributes',
            ],
            'show_in_rest'       => false,
        ];

        register_post_type('odcm_order_rule', $args);

    }//end register_completion_rule_post_type()


    /**
     * Add custom columns to the completion rule list table.
     *
     * @param  array $columns The existing columns.
     * @return array The modified columns.
     */
    public function add_custom_columns(array $columns): array
    {
        $new_columns = [];

        // Insert the 'Active' column after the checkbox column
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'cb') {
                $new_columns['active'] = __('Active', Odcm_Config::$text_domain);
            }
        }

        return $new_columns;

    }//end add_custom_columns()


    /**
     * Render the content of custom columns.
     *
     * @param  string  $column  The column name.
     * @param  integer $post_id The post ID.
     * @return void
     */
    public function render_custom_columns(string $column, int $post_id): void
    {
        if ($column === 'active') {
            // Get the current post status
            $post      = get_post($post_id);
            $is_active = $post->post_status === 'publish';

            // Render the toggle switch
            echo '<div class="odcm-toggle-container">';
            echo '<label class="odcm-toggle-switch" title="'.($is_active ? esc_attr__('Active', Odcm_Config::$text_domain) : esc_attr__('Inactive', Odcm_Config::$text_domain)).'">';
            echo '<input type="checkbox" '.checked($is_active, true, false).' data-rule-id="'.esc_attr($post_id).'" data-nonce="'.esc_attr(wp_create_nonce('odcm_toggle_rule_'.$post_id)).'">';
            echo '<span class="odcm-toggle-slider"></span>';
            echo '</label>';
            echo '</div>';
        }

    }//end render_custom_columns()


    /**
     * Handle AJAX request to toggle rule active status.
     *
     * @return void
     */
    public function ajax_toggle_rule_status(): void
    {
        // Always check capability and nonce first in AJAX handlers
        odcm_check_user_capability('manage_woocommerce', 'ajax');

        // Get post ID and verify nonce
        $post_id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'odcm_toggle_rule_'.$post_id)) {
            wp_send_json_error(['message' => __('Security check failed.', Odcm_Config::$text_domain)]);
            wp_die();
        }

        // Check specific post permissions
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('You do not have permission to edit this rule.', Odcm_Config::$text_domain)]);
            wp_die();
        }

        // Get current post
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Rule not found.', Odcm_Config::$text_domain)]);
        }

        // Check if user can use unlimited rules
        $can_use_unlimited_rules = odcm_can_use('unlimited_rules');

        // For freemium users, set all other rules to draft when activating a rule
        if (!$can_use_unlimited_rules && $post->post_status !== 'publish') {
            // Get all published rules
            $published_rules = get_posts(
                [
                    'post_type'      => 'odcm_order_rule',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'exclude'        => [$post_id],
                ]
            );

            // Set all other published rules to draft
            foreach ($published_rules as $rule_id) {
                wp_update_post(
                    [
                        'ID'          => $rule_id,
                        'post_status' => 'draft',
                    ]
                );
            }
        }//end if

        // Toggle the post status
        $new_post_status = $post->post_status === 'publish' ? 'draft' : 'publish';

        // Update the post status
        $post_data = [
            'ID'          => $post_id,
            'post_status' => $new_post_status,
        ];

        $result = wp_update_post($post_data);

        if ($result && !is_wp_error($result)) {
            // Get the updated post to get fresh data
            $updated_post = get_post($post_id);

            // Format the date text based on post status
            $date_text = $new_post_status === 'publish' ? __('Published', Odcm_Config::$text_domain) : __('Last Modified', Odcm_Config::$text_domain);

            // Get the post title
            $post_title = $updated_post->post_title;

            // For draft posts, we need to include the "- Draft" suffix for the title
            $display_title = $new_post_status === 'publish' ? $post_title : $post_title.' - '.__('Draft', Odcm_Config::$text_domain);

            wp_send_json_success(
                [
                    'message'        => __('Rule status updated successfully.', Odcm_Config::$text_domain),
                    'new_status'     => $new_post_status === 'publish' ? '1' : '0',
                    'is_premium'     => $can_use_unlimited_rules,
                    'affected_rules' => !$can_use_unlimited_rules && $new_post_status === 'publish' ? count($published_rules) : 0,
                    'date_text'      => $date_text,
                    'display_title'  => $display_title,
                    'post_title'     => $post_title,
                    'post_id'        => $post_id,
                ]
            );
        } else {
            wp_send_json_error(['message' => __('Failed to update rule status.', Odcm_Config::$text_domain)]);
        }//end if

    }//end ajax_toggle_rule_status()


    /**
     * Enqueue styles for the front-end.
     * This ensures that toggle switches and other UI elements are properly styled on the front-end.
     *
     * @return void
     */
    public function enqueue_frontend_styles(): void
    {
        // Get the version for the scripts
        $script_version = defined('ODCM_VERSION') ? ODCM_VERSION : '1.0.0';

        // Enqueue the admin CSS which contains the toggle styles
        wp_enqueue_style(
            'odcm-frontend-styles',
            ODCM_PLUGIN_URL.'assets/css/admin.css',
            [],
            $script_version
        );
    }//end enqueue_frontend_styles()

    /**
     * Add Order Rule to admin bar "+ New" menu
     */
    public function add_order_rule_to_admin_bar($wp_admin_bar) {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $wp_admin_bar->add_node(array(
            'parent' => 'new-content',
            'id'     => 'new-order-rule',
            'title'  => __('Order Rule', 'order-daemon'),
            'href'   => admin_url('post-new.php?post_type=odcm_order_rule'),
        ));
    }


}//end class
