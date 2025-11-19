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

        // Hook into load-edit.php to replace the default list table with our custom one
        add_action('load-edit.php', [$this, 'setup_custom_list_table']);

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

        // Handle AJAX request for updating rule order
        add_action('wp_ajax_odcm_update_rule_order', [$this, 'ajax_update_rule_order'], 10);



    }//end init()

    /**
     * Setup custom list table for the order rules.
     * Using WordPress-native hooks instead of replacing the entire page.
     * 
     * @return void
     */
    public function setup_custom_list_table(): void
    {
        // Only process on the order rules edit screen
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'odcm_order_rule') {
            return;
        }
        
        // Add a notice explaining drag-and-drop functionality
        add_action('admin_notices', function() {
            echo '<div class="notice notice-info is-dismissible"><p>' . 
                                esc_html__('admin.ui.drag_drop_tip', 'order-daemon') .
                '</p></div>';
        });
        
        // Add additional script in the footer to enhance the drag-and-drop experience
        add_action('admin_footer', function() {
            ?>
            <script>
            jQuery(document).ready(function($) {
                // Add data-id attribute to each table row based on the post ID
                $('#the-list tr').each(function() {
                    const postId = $(this).attr('id').replace('post-', '');
                    if (postId) {
                        $(this).attr('data-id', postId);
                    }
                });
                
                // Initialize priority column values
                $('#the-list tr').each(function(index) {
                    // Find the priority column and update its text
                    const $priorityCell = $(this).find('.column-priority');
                    if ($priorityCell.length && $priorityCell.text().trim() === '') {
                        $priorityCell.text(index);
                    }
                });
            });
            </script>
            <?php
        });
        
        // Add custom row attributes to enable drag-and-drop
        add_filter('post_class', function($classes, $class, $post_id) {
            if (get_post_type($post_id) === 'odcm_order_rule') {
                $classes[] = 'odcm-rule-row';
            }
            return $classes;
        }, 10, 3);
        
        // Add data-id attribute to each row to enable drag-and-drop
        add_action('manage_posts_custom_column', function($column_name, $post_id) {
            // We only need to do this once per row, so we'll use the first column
            static $already_added = [];
            
            // Skip if we've already processed this post
            if (isset($already_added[$post_id])) {
                return;
            }
            
            // Only for our post type
            if (get_post_type($post_id) !== 'odcm_order_rule') {
                return;
            }
            
            // Add JavaScript to inject data-id attribute
            echo '<script>jQuery(document).ready(function($) {
                $("#post-' . esc_attr($post_id, 'order-daemon') . '").attr("data-id", "' . esc_attr($post_id, 'order-daemon') . '");
            });</script>';
            
            $already_added[$post_id] = true;
        }, 999, 2);
    }
                
    /**
     * Handle AJAX request to update rule order.
     * 
     * @return void
     */
    public function ajax_update_rule_order(): void
    {
        // Check user capability
        odcm_check_user_capability('manage_woocommerce', 'ajax');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'odcm_update_rule_order')) {
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
            wp_die();
        }
        
        // Get the rule order data
        $rule_ids = isset($_POST['rule_ids']) ? array_map('absint', $_POST['rule_ids']) : [];
        
        if (empty($rule_ids)) {
            wp_send_json_error(['message' => __('admin.ajax.no_rule_order_data', 'order-daemon')]);
            wp_die();
        }
        
        // Update the menu_order for each rule
        $success = true;
        foreach ($rule_ids as $position => $rule_id) {
            // Verify this rule exists and user has permission to edit it
            if (!get_post($rule_id) || !current_user_can('edit_post', $rule_id)) {
                $success = false;
                continue;
            }
            
            // Update the menu_order (lower number = higher priority)
            $result = wp_update_post([
                'ID' => $rule_id,
                'menu_order' => $position,
            ]);
            
            if (!$result || is_wp_error($result)) {
                $success = false;
            }
        }
        
        if ($success) {
            wp_send_json_success(['message' => __('admin.ajax.rule_order_update_success', 'order-daemon')]);
        } else {
            wp_send_json_error(['message' => __('admin.ajax.rule_order_update_error', 'order-daemon')]);
        }
        
        wp_die();
    }


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
        if (in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            $screen = get_current_screen();
            if (!$screen || 'odcm_order_rule' !== $screen->post_type) {
                return;
                // It's a single post edit screen, but not for our CPT
            }
        }

        // Special handling for the edit.php screen (list table)
        // We need to check if it's our post type from the URL
        if ($hook_suffix === 'edit.php') {
            $current_post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : 'post';
            if ('odcm_order_rule' !== $current_post_type) {
                return;
                // It's a list page, but not for our custom post type
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
            // Ensure jQuery UI is loaded for sortable functionality
            wp_enqueue_script('jquery-ui-sortable');
            
            // Enqueue JS for the toggle button functionality
            wp_enqueue_script(
                'odcm-toggle-rules',
                ODCM_PLUGIN_URL.'assets/js/toggle-rules.js',
                ['jquery', 'jquery-ui-sortable'],
                $script_version,
                true
            );

            // Pass data to the script
            wp_localize_script(
                'odcm-toggle-rules',
                'odcmToggleRules',
                [
                    'ajaxUrl'          => admin_url('admin-ajax.php'),
                    'activeText'       => __('admin.ui.active', 'order-daemon'),
                    'inactiveText'     => __('admin.ui.inactive', 'order-daemon'),
                    'errorMessage'     => __('admin.ui.rule_status_update_error', 'order-daemon'),
                    'draftText'        => __('admin.ui.draft', 'order-daemon'),
                    'publishedText'    => __('admin.ui.published', 'order-daemon'),
                    'lastModifiedText' => __('admin.ui.last_modified', 'order-daemon'),
                    'orderNonce'       => wp_create_nonce('odcm_update_rule_order'),
                    'orderErrorMessage' => __('admin.ui.rule_order_update_error', 'order-daemon'),
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
            'name'               => _x('admin.post_type.order_rules_plural', 'post type general name', 'order-daemon'),
            'singular_name'      => _x('admin.post_type.order_rule_singular', 'post type singular name', 'order-daemon'),
            'menu_name'          => _x('admin.post_type.order_rules_menu', 'admin menu', 'order-daemon'),
            'name_admin_bar'     => _x('admin.post_type.order_rule_admin_bar', 'add new on admin bar', 'order-daemon'),
            'add_new'            => _x('admin.post_type.add_new', 'order rule', 'order-daemon'),
            'add_new_item'       => __('admin.post_type.add_new_item', 'order-daemon'),
            'new_item'           => __('admin.post_type.new_item', 'order-daemon'),
            'edit_item'          => __('admin.post_type.edit_item', 'order-daemon'),
            'view_item'          => __('admin.post_type.view_item', 'order-daemon'),
            'all_items'          => __('admin.post_type.all_items', 'order-daemon'),
            'search_items'       => __('admin.post_type.search_items', 'order-daemon'),
            'parent_item_colon'  => __('admin.post_type.parent_item_colon', 'order-daemon'),
            'not_found'          => __('admin.post_type.not_found', 'order-daemon'),
            'not_found_in_trash' => __('admin.post_type.not_found_in_trash', 'order-daemon'),
        ];

        $args = [
            'labels'             => $labels,
            'description'        => __('admin.post_type.description', 'order-daemon'),
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false,          // Don't show in any menu, handled by InsightDashboard
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
        
        // Add the drag handle column as the first column
        $new_columns['handle'] = '<span class="dashicons dashicons-menu"></span>';
        
        // Add the remaining columns, inserting Active after checkbox
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'cb') {
                $new_columns['active'] = __('admin.list_table.column.active', 'order-daemon');
            }
        }
        
        // Add priority column before the date column
        if (isset($new_columns['date'])) {
            $date_column = $new_columns['date'];
            unset($new_columns['date']);
            $new_columns['priority'] = __('admin.list_table.column.priority', 'order-daemon');
            $new_columns['date'] = $date_column;
        } else {
            $new_columns['priority'] = __('admin.list_table.column.priority', 'order-daemon');
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
        if ($column === 'handle') {
            // Render the drag handle
            echo '<span class="odcm-drag-handle dashicons dashicons-menu" style="cursor:grab;"></span>';
        } elseif ($column === 'active') {
            // Get the current post status
            $post      = get_post($post_id);
            $is_active = $post->post_status === 'publish';

            // Render the toggle switch
            echo '<div class="odcm-toggle-container">';
            echo '<label class="odcm-toggle-switch" title="'.($is_active ? esc_attr__('admin.ui.active', 'order-daemon') : esc_attr__('admin.ui.inactive', 'order-daemon')).'">';
            echo '<input type="checkbox" '.checked($is_active, true, false).' data-rule-id="'.esc_attr($post_id).'" data-nonce="'.esc_attr(wp_create_nonce('odcm_toggle_rule_'.$post_id)).'">';
            echo '<span class="odcm-toggle-slider"></span>';
            echo '</label>';
            echo '</div>';
        } elseif ($column === 'priority') {
            // Get the post
            $post = get_post($post_id);
            
            // Display the menu_order (priority) with a hint
            echo '<span class="priority-value" title="' . esc_attr__('admin.ui.priority_tooltip', 'order-daemon') . '">';
            echo esc_html($post->menu_order);
            echo '</span>';
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
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
            wp_die();
        }

        // Check specific post permissions
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('admin.ajax.no_permission_edit_rule', 'order-daemon')]);
            wp_die();
        }

        // Get current post
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('admin.ajax.rule_not_found', 'order-daemon')]);
        }

        // Check if user can use unlimited rules
        $can_use_unlimited_rules = odcm_can_use('unlimited_rules');

        // For freemium users, enforce priority 0 constraint for active rules
        if (!$can_use_unlimited_rules) {
            if ($post->post_status !== 'publish') {
                // Activating a rule - ensure it gets priority 0 and deactivate others
                
                // First, set this rule to priority 0 (highest priority)
                wp_update_post([
                    'ID' => $post_id,
                    'menu_order' => 0,
                ]);

                // Get all other published rules
                $published_rules = get_posts([
                    'post_type'      => 'odcm_order_rule',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'exclude'        => [$post_id],
                ]);

                // Set all other published rules to draft
                foreach ($published_rules as $rule_id) {
                    wp_update_post([
                        'ID'          => $rule_id,
                        'post_status' => 'draft',
                    ]);
                }
            } else {
                // Deactivating the current rule - no additional action needed
                // Free version users can deactivate their single rule
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
            $date_text = $new_post_status === 'publish' ? __('admin.ui.published', 'order-daemon') : __('admin.ui.last_modified', 'order-daemon');

            // Get the post title
            $post_title = $updated_post->post_title;

            // For draft posts, we need to include the "- Draft" suffix for the title
            $display_title = $new_post_status === 'publish' ? $post_title : $post_title.' - '.__('admin.ui.draft', 'order-daemon');

            wp_send_json_success(
                [
                    'message'        => __('admin.ajax.rule_status_update_success', 'order-daemon'),
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
            wp_send_json_error(['message' => __('admin.ajax.rule_status_update_failure', 'order-daemon')]);
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
            'title'  => __('admin.ui.order_rule', 'order-daemon'),
            'href'   => admin_url('post-new.php?post_type=odcm_order_rule'),
        ));
    }




}//end class
