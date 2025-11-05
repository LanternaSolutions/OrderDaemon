<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

use OrderDaemon\CompletionManager\Includes\Odcm_Config;

// Ensure WP_List_Table is loaded before we try to extend it
if (!class_exists('WP_List_Table')) {
    include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for displaying completion rules.
 */
class CompletionRulesListTable extends \WP_List_Table
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(
            [
                'singular' => 'Rule',
                'plural'   => 'Rules',
                'ajax'     => false,
                'class'    => 'widefat fixed striped rules',
            ]
        );
    }

    /**
     * Get the columns for the table.
     *
     * @return array
     */
    public function get_columns()
    {
        return [
            'handle'   => __('Drag', Odcm_Config::$text_domain),
            'cb'       => '<input type="checkbox" />',
            'active'   => __('Active', 'order-daemon'),
            'title'    => __('Title', 'order-daemon'),
            'priority' => __('Priority', 'order-daemon'),
            'date'     => __('Date', 'order-daemon'),
        ];
    }

    /**
     * Get sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns()
    {
        return [
            'title'    => ['title', false],
            'priority' => ['menu_order', false],
            'date'     => ['post_date', true],
        ];
    }

    /**
     * Get bulk actions.
     *
     * @return array
     */
    public function get_bulk_actions()
    {
        return [
            'activate'   => __('Activate', 'order-daemon'),
            'deactivate' => __('Deactivate', 'order-daemon'),
            'trash'      => __('Move to Trash', 'order-daemon'),
        ];
    }

    /**
     * Process bulk actions.
     *
     * @return void
     */
    public function process_bulk_action()
    {
        // Security check
        if (isset($_POST['_wpnonce']) && !empty($_POST['rule'])) {
            $action = $this->current_action();
            $nonce  = sanitize_key($_POST['_wpnonce']);

            // Verify the nonce
            if (!wp_verify_nonce($nonce, 'bulk-' . $this->_args['plural'])) {
                wp_die(esc_html__('Security check failed.', 'order-daemon'));
            }

            // Only allow users with manage_woocommerce capability
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have permission to perform this action.', 'order-daemon'));
            }

            // Sanitize rule IDs
            $rule_ids = array_map('absint', $_POST['rule']);

            // Process actions
            switch ($action) {
                case 'activate':
                    foreach ($rule_ids as $rule_id) {
                        wp_update_post([
                            'ID'          => $rule_id,
                            'post_status' => 'publish',
                        ]);
                    }
                    break;

                case 'deactivate':
                    foreach ($rule_ids as $rule_id) {
                        wp_update_post([
                            'ID'          => $rule_id,
                            'post_status' => 'draft',
                        ]);
                    }
                    break;

                case 'trash':
                    foreach ($rule_ids as $rule_id) {
                        wp_trash_post($rule_id);
                    }
                    break;
            }

            // Redirect to avoid resubmission (redirect to logs tab since rules tab is removed)
            wp_safe_redirect(add_query_arg(['page' => 'odcm-settings', 'tab' => 'logs'], admin_url('admin.php')));
            exit;
        }
    }

    /**
     * Checkbox column renderer.
     *
     * @param object $item The current item.
     *
     * @return string
     */
    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="rule[]" value="%s" />',
            $item->ID
        );
    }
    
    /**
     * Handle column renderer.
     *
     * @param object $item The current item.
     *
     * @return string
     */
    public function column_handle($item)
    {
        // Make the handle visible and clickable
        return '<span class="odcm-drag-handle dashicons dashicons-menu" style="cursor:grab;"></span>';
    }

    /**
     * Active column renderer.
     *
     * @param object $item The current item.
     *
     * @return string
     */
    public function column_active($item)
    {
        $is_active = $item->post_status === 'publish';

        $output = '<div class="odcm-toggle-container">';
        $output .= '<label class="odcm-toggle-switch" title="' . ($is_active ? 
            esc_attr__('Active', 'order-daemon') : 
            esc_attr__('Inactive', 'order-daemon')) . '">';
        $output .= '<input type="checkbox" ' . checked($is_active, true, false) . 
            ' data-rule-id="' . esc_attr($item->ID) . 
            '" data-nonce="' . esc_attr(wp_create_nonce('odcm_toggle_rule_' . $item->ID)) . '">';
        $output .= '<span class="odcm-toggle-slider"></span>';
        $output .= '</label>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Title column renderer.
     *
     * @param object $item The current item.
     *
     * @return string
     */
    public function column_title($item)
    {
        // Generate edit link to WordPress post editor (CHANGED from custom interface)
        $edit_link = admin_url('post.php?post=' . absint($item->ID) . '&action=edit');
        
        $title = '<strong><a href="' . esc_url($edit_link) . '">' . esc_html($item->post_title) . '</a>';

        if ($item->post_status === 'draft') {
            $title .= ' - <span class="post-state">' . esc_html__('Draft', 'order-daemon') . '</span>';
        }

        $title .= '</strong>';

        // Row actions
        $actions = [];
        $actions['edit'] = '<a href="' . esc_url($edit_link) . '">' . esc_html__('Edit', 'order-daemon') . '</a>';

        // Custom delete link using admin_post handler
        $delete_url = wp_nonce_url(
            add_query_arg([
                'action' => 'odcm_delete_rule',
                'rule_id' => $item->ID
            ], admin_url('admin-post.php')),
            'odcm_delete_rule_' . $item->ID
        );
        $actions['delete'] = '<a href="' . esc_url($delete_url) . '" class="submitdelete" onclick="return confirm(\'' . 
            esc_js(__('Are you sure you want to delete this rule? This action cannot be undone.', 'order-daemon')) . 
            '\');">' . esc_html__('Delete', 'order-daemon') . '</a>';

        return $title . $this->row_actions($actions);
    }

    /**
     * Priority column renderer.
     *
     * @param object $item The current item.
     *
     * @return string
     */
    public function column_priority($item)
    {
        return esc_html($item->menu_order);
    }

    /**
     * Date column renderer.
     *
     * @param object $item The current item.
     *
     * @return string
     */
    public function column_date($item)
    {
        $output = '';

        if ($item->post_status === 'publish') {
            $output .= esc_html__('Published', 'order-daemon') . '<br>';
        } else {
            $output .= esc_html__('Last Modified', 'order-daemon') . '<br>';
        }

        $output .= '<abbr title="' . esc_attr(get_the_time('Y/m/d g:i:s a', $item)) . '">' . 
            esc_html(get_the_date('', $item)) . '</abbr>';

        return $output;
    }

    /**
     * Message to display when there are no items.
     */
    public function no_items()
    {
        echo esc_html__('No order rules found. Click "Add New" to create your first rule.', 'order-daemon');
    }

    /**
     * Get the per page setting with appropriate fallbacks.
     *
     * @return integer Number of items per page.
     */
    private function get_per_page_setting(): int
    {
        $per_page = 20; // Default fallback value
        $max_per_page = 200; // Maximum allowed records per page for safety

        // Try all possible meta keys for the per_page setting
        $possible_keys = [
            'odcm_rules_per_page', // Our primary option
            'woocommerce_page_odcm_settings_per_page', // WordPress auto-generated name
            'woocommerce_page_odcm-settings_per_page', // Alternative format
            'users_per_page', // Default fallback
        ];

        // Pre-fetch all user meta to avoid N+1 queries
        $user_id = get_current_user_id();
        $all_user_meta = get_user_meta($user_id);

        // Loop through keys and use the first valid value found
        foreach ($possible_keys as $key) {
            $value = isset($all_user_meta[$key][0]) ? (int) $all_user_meta[$key][0] : 0;
            if ($value > 0) {
                // Ensure the value doesn't exceed our maximum limit
                $value = min($value, $max_per_page);
                $per_page = $value;
                break;
            }
        }

        // Safety cap - never allow more than 200 per page
        return min($per_page, 200);
    }

    /**
     * Display a single row for the table, with added data-id attribute.
     *
     * @param object $item The current item.
     */
    public function single_row($item) {
        $class = 'odcm-rule-row';
        echo '<tr class="' . esc_attr($class) . '" id="rule-row-' . esc_attr($item->ID) . '" data-id="' . esc_attr($item->ID) . '">';
        $this->single_row_columns($item);
        echo '</tr>';
    }
    
    /**
     * Prepare the items for the table.
     */
    public function prepare_items()
    {
        // Set up the table structure
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        // Handle any bulk actions
        $this->process_bulk_action();

        // Get pagination settings
        $per_page = $this->get_per_page_setting();
        $current_page = $this->get_pagenum();

        // Get sorting parameters
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'menu_order';
        $order = isset($_REQUEST['order']) ? sanitize_key($_REQUEST['order']) : 'asc';

        // Map orderby values to actual query parameters
        $orderby_mapping = [
            'title' => 'title',
            'priority' => 'menu_order',
            'date' => 'date',
        ];

        // Use the mapped value if available, otherwise use the original
        $orderby = isset($orderby_mapping[$orderby]) ? $orderby_mapping[$orderby] : $orderby;

        // Get rules with pagination
        $args = [
            'post_type'      => 'odcm_order_rule',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        // Add search if provided
        if (!empty($_REQUEST['s'])) {
            $args['s'] = sanitize_text_field($_REQUEST['s']);
        }

        $query = new \WP_Query($args);
        $this->items = $query->posts;

        // Set up pagination
        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => $per_page,
            'total_pages' => $query->max_num_pages,
        ]);
    }
}
