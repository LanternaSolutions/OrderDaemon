<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

use OrderDaemon\CompletionManager\Includes\Odcm_Config;
use OrderDaemon\CompletionManager\Includes\Odcm_Strings;
use OrderDaemon\CompletionManager\Includes\DependencyChecker;
use OrderDaemon\CompletionManager\View\DashboardComponents\UnifiedHeaderRenderer;
use OrderDaemon\CompletionManager\View\DashboardComponents\FilterPaneRenderer;
use OrderDaemon\CompletionManager\View\DashboardComponents\LogStreamRenderer;
use OrderDaemon\CompletionManager\View\DashboardComponents\DetailPaneRenderer;

/**
 * Insight Dashboard Admin Page
 * 
 * Modern, interactive dashboard for audit log analysis with real-time updates,
 * advanced filtering, and detailed log inspection capabilities.
 * 
 * Features:
 * - Three-pane layout (filters, stream, details)
 * - Alpine.js 3.14.9 reactive interface
 * - Auto-refreshing log stream (5-second intervals)
 * - Mobile-responsive slide-out panes
 * - Filter persistence across sessions
 * - WordPress standard pagination
 * 
 * @package OrderDaemon\CompletionManager\Admin
 * @since   2.0.0
 */
class InsightDashboard
{
    /**
     * Page slug for the insight dashboard
     */
    const PAGE_SLUG = 'odcm-insight-dashboard';

    /**
     * Component renderers map when component-based mode is enabled.
     *
     * @var array<string, \OrderDaemon\CompletionManager\View\DashboardComponents\DashboardComponentRenderer>
     */
    private array $componentRenderers = [];

    /**
     * Initialize the insight dashboard functionality
     */
    public function init(): void
    {
        // Apply debug override early in the lifecycle
        $this->apply_debug_override();
        
        add_action('admin_menu', [$this, 'register_menu_page'], 15);
        add_action('admin_menu', [$this, 'reorder_woocommerce_submenu'], 999); // Late priority to reorder after all items are added
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets'], 10, 1);
        add_filter('admin_body_class', [$this, 'add_body_class']);
        
        // Register AJAX handlers for settings
        add_action('wp_ajax_odcm_update_per_page', [$this, 'handle_update_per_page_ajax']);
        add_action('wp_ajax_odcm_save_debug_settings', [$this, 'handle_debug_settings_ajax']);
        add_action('wp_ajax_odcm_reprocess_pending_orders', [$this, 'handle_reprocess_pending_orders_ajax']);

        // Register AJAX handlers for onboarding
        add_action('wp_ajax_odcm_check_welcome_scenario', [$this, 'handle_welcome_scenario_check']);

    }

    /**
     * Apply debug override early in the WordPress lifecycle
     * 
     * Uses the same debug override system as DevToolbar for consistency.
     */
    private function apply_debug_override(): void
    {
        // Use the same debug override option as DevToolbar
        $debug_override = get_option('odcm_dev_debug_override', null);
        if ($debug_override !== null) {
            $is_debug_enabled = (bool) $debug_override;
            
            // Use the same global variable as DevToolbar
            $GLOBALS['odcm_debug_override'] = $is_debug_enabled;
            
            // Hook into the debug check functions early
            add_filter('odcm_debug_enabled', function($current_state) use ($is_debug_enabled) {
                return $is_debug_enabled;
            }, 999);
        }
    }

    /**
     * Register the insight dashboard menu page
     */
    public function register_menu_page(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Order Daemon', Odcm_Config::$text_domain),
            __('Order Daemon', Odcm_Config::$text_domain),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_dashboard_page']
        );
    }

    /**
     * Reorder WooCommerce submenu to place Order Daemon directly after Order Rules
     * 
     * This method runs with priority 999 to ensure it executes after all menu items are registered.
     * It manually reorders the WooCommerce submenu array to achieve the desired positioning.
     */
    public function reorder_woocommerce_submenu(): void
    {
        global $submenu;
        
        // Check if WooCommerce submenu exists
        if (!isset($submenu['woocommerce']) || !is_array($submenu['woocommerce'])) {
            return;
        }
        
        $wc_submenu = $submenu['woocommerce'];
        $order_rules_item = null;
        $order_daemon_item = null;
        $order_rules_index = null;
        $order_daemon_index = null;
        
        // Find the order rules and order daemon menu items
        foreach ($wc_submenu as $index => $menu_item) {
            if (isset($menu_item[2])) {
                // Look for order rules (custom post type)
                if (strpos($menu_item[2], 'edit.php?post_type=odcm_order_rule') !== false) {
                    $order_rules_item = $menu_item;
                    $order_rules_index = $index;
                }
                // Look for order daemon (our insight dashboard)
                if ($menu_item[2] === self::PAGE_SLUG) {
                    $order_daemon_item = $menu_item;
                    $order_daemon_index = $index;
                }
            }
        }
        
        // If both items are found, reorder them
        if ($order_rules_item !== null && $order_daemon_item !== null && $order_rules_index !== null && $order_daemon_index !== null) {
            // Remove both items from their current positions
            unset($submenu['woocommerce'][$order_rules_index]);
            unset($submenu['woocommerce'][$order_daemon_index]);
            
            // Re-index the array to avoid gaps
            $submenu['woocommerce'] = array_values($submenu['woocommerce']);
            
            // Find the best insertion point (after WooCommerce core items but before other plugins)
            $insert_position = $this->find_optimal_insertion_position($submenu['woocommerce']);
            
            // Insert order rules first
            array_splice($submenu['woocommerce'], $insert_position, 0, [$order_rules_item]);
            
            // Insert order daemon right after order rules
            array_splice($submenu['woocommerce'], $insert_position + 1, 0, [$order_daemon_item]);
        }
    }
    
    /**
     * Find the optimal position to insert our menu items in the WooCommerce submenu
     * 
     * This method specifically looks for the "Orders" menu item and places our items
     * immediately after it, which is the most logical placement for order completion functionality.
     * 
     * @param array $submenu_items The current WooCommerce submenu items
     * @return int The index where our items should be inserted
     */
    private function find_optimal_insertion_position(array $submenu_items): int
    {
        $default_position = 2; // Fallback position
        
        foreach ($submenu_items as $index => $menu_item) {
            if (isset($menu_item[2])) {
                // Look for "Orders" menu item - we want to insert immediately after it
                if (strpos($menu_item[2], 'edit.php?post_type=shop_order') !== false) {
                    return $index + 1; // Insert right after Orders
                }
            }
        }
        
        // If we can't find the Orders menu item, use the default position
        return min($default_position, count($submenu_items));
    }

    /**
     * Get the hook suffix for this page
     */
    private function get_hook_suffix(): string
    {
        return get_plugin_page_hookname(self::PAGE_SLUG, 'woocommerce');
    }

    /**
     * Enqueue dashboard-specific assets
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        // Only load on our dashboard page
        if (!$this->is_dashboard_page($hook_suffix)) {
            return;
        }

        $plugin_version = defined('ODCM_VERSION') ? ODCM_VERSION : '2.0.0';
        $assets_url = defined('ODCM_PLUGIN_URL') ? ODCM_PLUGIN_URL . 'assets/' : '';

        // Prism.js CSS for syntax highlighting
        wp_enqueue_style(
            'odcm-prism-css',
            $assets_url . 'css/vendor/prism.css',
            [],
            $plugin_version
        );

        // Prism.js JavaScript for syntax highlighting
        wp_enqueue_script(
            'odcm-prism-js',
            $assets_url . 'js/vendor/prism.js',
            [],
            $plugin_version,
            true
        );

        // Alpine.js 3.14.9 from CDN for optimal performance
        wp_enqueue_script(
            'alpine-js',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
            [],
            '3.14.9',
            true
        );
        
        // Add defer attribute to Alpine.js script
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'alpine-js') {
                return str_replace('<script ', '<script defer ', $tag);
            }
            return $tag;
        }, 10, 2);

        // Enqueue shared toast system
        wp_enqueue_script(
            'odcm-shared-toasts',
            $assets_url . 'js/shared/toasts.js',
            [],
            $plugin_version,
            true
        );

        // Enqueue design system CSS first (contains shared styles including toasts)
        wp_enqueue_style(
            'odcm-design-system',
            $assets_url . 'css/odcm-design-system.css',
            [],
            $plugin_version
        );

        // Dashboard-specific CSS (self-contained with complete three-tier system)
        wp_enqueue_style(
            'odcm-insight-dashboard',
            $assets_url . 'css/insight-dashboard.css',
            ['odcm-prism-css', 'odcm-design-system'], // Depend on Prism.js CSS and design system
            $plugin_version
        );

        // Dashboard JavaScript with Alpine.js app - depends on Alpine.js, Prism.js, and shared toasts
        wp_enqueue_script(
            'odcm-insight-dashboard-js',
            $assets_url . 'js/insight-dashboard.js',
            ['alpine-js', 'odcm-prism-js', 'odcm-shared-toasts'], // Ensure Alpine, Prism, and toasts load first
            $plugin_version,
            false // Load in head to ensure registration before Alpine processes DOM
        );

        // CSS loading validation and emergency fallback styles
        wp_add_inline_script(
            'odcm-insight-dashboard-js',
            "(function(){try{var r=document.documentElement;var v=getComputedStyle(r).getPropertyValue('--odcm-theme-grey-100');if(!v||v.trim()===''){var s=document.createElement('style');s.setAttribute('data-odcm-inline-fallback','1');s.textContent='/* ODCM minimal fallback */ .odcm-insight-dashboard{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;color:#222;background:#fff;} .odcm-insight-dashboard .odcm-unified-header{position:sticky;top:0;background:#fff;border-bottom:1px solid #ddd;padding:8px;z-index:10;} .odcm-insight-dashboard .odcm-content-grid{display:flex;gap:8px;margin-top:8px;} .odcm-insight-dashboard .odcm-filter-pane,.odcm-insight-dashboard .odcm-log-stream,.odcm-insight-dashboard .odcm-detail-pane{border:1px solid #ddd;border-radius:4px;background:#fff;min-height:200px;padding:8px;flex:1;} @media(max-width:782px){.odcm-insight-dashboard .odcm-content-grid{flex-direction:column;}} .odcm-css-warning{border:1px solid #f0c36d;background:#fff8e5;color:#5f3b00;padding:8px;border-radius:4px;margin:8px 0;font-size:13px;} .odcm-badge--error{display:inline-block;background:#dc3545;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;} .odcm-premium-filter-group input:disabled,.odcm-premium-filter-group select:disabled{background:#eee;cursor:not-allowed;opacity:.7;}';document.head.appendChild(s);var b=document.createElement('div');b.className='odcm-css-warning';b.textContent='Order Daemon: Some styles failed to load. Using minimal fallback for safe display.';var c=document.getElementById('odcm-insight-dashboard');if(c){c.insertBefore(b,c.firstChild);}document.body.classList.add('odcm-css-fallback-mode');}}catch(e){}})();",
            'before'
        );

        // Localize script with API endpoints and configuration
        wp_localize_script('odcm-insight-dashboard-js', 'odcmInsightConfig', [
            // Use root-relative REST URLs to ensure same-origin requests (cookies sent), avoiding masked 404s
            'apiUrl' => wp_make_link_relative(rest_url('odcm/v1/audit-log/')),
            'renderUrl' => wp_make_link_relative(rest_url('odcm/v1/audit-log/render-components/')),
            'renderBatchUrl' => wp_make_link_relative(rest_url('odcm/v1/audit-log/render-components/batch/')),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_rest'),
            'premium_access' => false,
            'perPage' => $this->get_user_per_page_setting(),
            'autoRefreshInterval' => 5000, // 5 seconds
            'debug' => self::is_global_debug_active(),
            'dateTimeConfig' => [
                'dateFormat' => get_option('date_format', 'F j, Y'),
                'timeFormat' => get_option('time_format', 'g:i a'),
                'timezone' => wp_timezone_string(),
                'startOfWeek' => (int) get_option('start_of_week', 0),
                'use24Hour' => strpos(get_option('time_format', 'g:i a'), 'H') !== false || strpos(get_option('time_format', 'g:i a'), 'G') !== false,
            ],
            'i18n' => [
            'loading' => __('Loading...', Odcm_Config::$text_domain),
            'error' => __('Error loading data', Odcm_Config::$text_domain),
            'noLogs' => __('No log entries found', Odcm_Config::$text_domain),
            'selectLog' => __('Select a log entry to view details', Odcm_Config::$text_domain),
            'filters' => __('Filters', Odcm_Config::$text_domain),
            'details' => __('Details', Odcm_Config::$text_domain),
            'close' => __('Close', Odcm_Config::$text_domain),
            'refresh' => __('Refresh', Odcm_Config::$text_domain),
            'newLogsAvailable' => __('New log entries available', Odcm_Config::$text_domain),
            'includeDebug' => __('Include Debug Logs', Odcm_Config::$text_domain),
            'timeOnly' => __('Time Only', Odcm_Config::$text_domain),
            'dateAndTime' => __('Date & Time', Odcm_Config::$text_domain),
            'relativeTime' => __('Relative Time', Odcm_Config::$text_domain),
        ],
        'upgrade' => [
            'enabled' => DependencyChecker::should_show_upgrade_prompts(),
            'message' => esc_html(DependencyChecker::get_wordpress_org_compliant_message('insight_filters')),
            'docsUrl' => defined('ODCM_DOCS_URL') ? esc_url_raw(ODCM_DOCS_URL) : '',
        ]
    ]);
    }

    /**
     * Check if we're on the dashboard page
     */
    private function is_dashboard_page(string $hook_suffix): bool
    {
        return str_contains($hook_suffix, self::PAGE_SLUG) || 
               (isset($_GET['page']) && $_GET['page'] === self::PAGE_SLUG);
    }

    /**
     * Get user's per-page setting
     */
    private function get_user_per_page_setting(): int
    {
        $user_setting = get_user_meta(get_current_user_id(), 'odcm_logs_per_page', true);
        return $user_setting ? (int) $user_setting : 20;
    }

    /**
     * Handle AJAX request to check welcome scenario
     */
    public function handle_welcome_scenario_check(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_rest')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Check if user has access to insight dashboard (defensive check)
        if (function_exists('odcm_can_use') && !odcm_can_use('insight_dashboard')) {
            wp_send_json_error('Feature not available');
        }

        try {
            $is_welcome_scenario = $this->determine_welcome_scenario();

            wp_send_json_success([
                    'is_welcome_scenario' => $is_welcome_scenario
            ]);
        } catch (\Exception $e) {
            error_log('ODCM: Error checking welcome scenario: ' . $e->getMessage());
            wp_send_json_error('Failed to check welcome scenario');
        }
    }

    /**
     * Determine if this is a welcome scenario (fresh installation)
     *
     * @return bool True if this appears to be a fresh installation with no rules
     */
    private function determine_welcome_scenario(): bool
    {
        // Check if any order rules exist
        $rule_count = wp_count_posts('odcm_order_rule');
        $total_rules = 0;

        if ($rule_count && is_object($rule_count)) {
            // Count all non-trash statuses
            foreach (get_post_stati() as $status) {
                if ($status !== 'trash' && isset($rule_count->$status)) {
                    $total_rules += (int) $rule_count->$status;
                }
            }
        }

        // If no rules exist, this is definitely a welcome scenario
        if ($total_rules === 0) {
            return true;
        }

        // If no rules exist, it's a welcome scenario. Otherwise, it's not.
        return $total_rules === 0;
    }

    /**
     * Check if component-based dashboard should be used
     */
    private function use_component_dashboard(): bool
    {
        // For now, always use component-based dashboard
        // This could be made configurable in the future
        return true;
    }

    /**
     * Initialize component renderers
     */
    private function initializeComponents(): void
    {
        $this->componentRenderers = [
            'unified_header' => new UnifiedHeaderRenderer(function($data = []) { $this->render_unified_header(); }),
            'filter_pane' => new FilterPaneRenderer(function($data = []) { $this->render_filter_pane(); }),
            'log_stream' => new LogStreamRenderer(function($data = []) { $this->render_log_stream(); }),
            'detail_pane' => new DetailPaneRenderer(function($data = []) { $this->render_detail_pane(); }),
        ];
    }

    /**
     * Render dashboard using component-based approach
     */
    private function render_dashboard_html_components(): void
    {
        $context = [
            'user_can_manage' => current_user_can('manage_woocommerce'),
            'is_welcome_scenario' => $this->determine_welcome_scenario(),
            'debug_enabled' => self::is_global_debug_active(),
        ];

        ?>
        <div class="wrap">
            <!-- Alpine.js App Container -->
            <div id="odcm-insight-dashboard" 
                 x-data="insightDashboard()"
                 x-init="init()"
             class="odcm-insight-dashboard"
             :class="dashboardClasses">

            <!-- Unified Sticky Header Bar -->
            <div class="odcm-unified-header">
                <?php echo $this->componentRenderers['unified_header']->renderWithContext($context); ?>
            </div>

            <!-- Content Grid -->
            <div class="odcm-content-grid">
                <!-- Filter Pane -->
                <div class="odcm-filter-pane">
                    <?php echo $this->componentRenderers['filter_pane']->renderWithContext($context); ?>
                <!--</div> DO NOT UNCOMMENT THIS DIV - IT WILL BREAK UI -->

                <!-- Log Stream -->
                <div class="odcm-log-stream">
                    <?php echo $this->componentRenderers['log_stream']->renderWithContext($context); ?>
                </div>

                <!-- Detail Pane -->
                <div class="odcm-detail-pane">
                    <?php echo $this->componentRenderers['detail_pane']->renderWithContext($context); ?>
                </div>
            </div>

            <!-- Toast Notifications -->
            <div class="odcm-toast-container">
                <template x-for="toast in (typeof ODCMToasts !== 'undefined' ? ODCMToasts.toasts : [])" :key="toast.id">
                    <div class="odcm-toast" 
                         :class="'odcm-toast--' + toast.type"
                         x-show="true"
                         x-transition:enter="odcm-toast-enter"
                         x-transition:leave="odcm-toast-leave">
                        <span x-text="toast.message"></span>
                        <button type="button" @click="removeToast(toast.id)">×</button>
                    </div>
                </template>
            </div>
        </div>
        </div>
        <?php
    }

    /**
     * Render the main dashboard page
     */
    public function render_dashboard_page(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', Odcm_Config::$text_domain));
        }

        // Check if user has access to audit trail functionality
        // Since insight dashboard is a free feature, only restrict if function explicitly denies access
        if (function_exists('odcm_can_use') && !odcm_can_use('insight_dashboard')) {
            // This should not happen for free features, but provide fallback
            $this->render_upgrade_notice();
            return;
        }

        if ($this->use_component_dashboard()) {
            $this->initializeComponents();
            $this->render_dashboard_html_components();
            return;
        }

        $this->render_dashboard_html();
    }

    /**
     * Render the main dashboard HTML structure
     */
    private function render_dashboard_html(): void
    {
        ?>
        <div class="wrap">
            <!-- Alpine.js App Container -->
            <div id="odcm-insight-dashboard" 
                 x-data="insightDashboard()"
                 x-init="init()"
             class="odcm-insight-dashboard"
             :class="dashboardClasses">

            <!-- Unified Sticky Header Bar -->
            <div class="odcm-unified-header">
                <?php $this->render_unified_header(); ?>
            </div>

            <!-- Content Grid -->
            <div class="odcm-content-grid">
                <!-- Filter Pane -->
                <div class="odcm-filter-pane">
                    <?php $this->render_filter_pane(); ?>
                <!--</div> DO NOT UNCOMMENT THIS DIV - IT WILL BREAK UI -->

                <!-- Log Stream -->
                <div class="odcm-log-stream">
                    <?php $this->render_log_stream(); ?>
                </div>

                <!-- Detail Pane -->
                <div class="odcm-detail-pane">
                    <?php $this->render_detail_pane(); ?>
                </div>
            </div>

            <!-- Toast Notifications -->
            <div class="odcm-toast-container">
                <template x-for="toast in (typeof ODCMToasts !== 'undefined' ? ODCMToasts.toasts : [])" :key="toast.id">
                    <div class="odcm-toast" 
                         :class="'odcm-toast--' + toast.type"
                         x-show="true"
                         x-transition:enter="odcm-toast-enter"
                         x-transition:leave="odcm-toast-leave">
                        <span x-text="toast.message"></span>
                        <button type="button" @click="removeToast(toast.id)">×</button>
                    </div>
                </template>
            </div>
        </div>
        </div>
        <?php
    }

    /**
     * Add custom body class for the dashboard page
     */
    public function add_body_class(string $classes): string
    {
        $current_screen = get_current_screen();
        if ($current_screen && $this->is_dashboard_page($current_screen->id)) {
            $classes .= ' odcm-is-fullscreen-dashboard';
        }
        return $classes;
    }

    /**
     * Render the unified sticky header bar
     */
    private function render_unified_header(): void
    {
        ?>
        <div class="odcm-unified-header-content">
            <!-- Filter Header with Icon Buttons -->
            <div class="odcm-unified-header-section odcm-unified-header-filters">
                <div class="odcm-filter-pane-header-actions">
                    <!-- Static Controls: Always visible in the same order -->
                    <div class="odcm-pane-icon-buttons">
                        <!-- Left arrow: close current pane (visible only when pane is open) -->
                        <button type="button"
                                class="odcm-pane-icon-button"
                                x-show="filterPaneVisible"
                                @click="closeFilterPane()"
                                title="<?php echo esc_attr__('Close pane', Odcm_Config::$text_domain); ?>">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                        </button>
                        
                        <!-- Right arrow: open last opened pane (visible only when pane is closed) -->
                        <button type="button"
                                class="odcm-pane-icon-button"
                                x-show="!filterPaneVisible"
                                @click="openLastOpenedPane()"
                                title="<?php echo esc_attr__('Open last pane', Odcm_Config::$text_domain); ?>">
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                        
                        <!-- Filters tab button -->
                        <button type="button"
                                class="odcm-pane-icon-button"
                                @click="showFiltersPane()"
                                :aria-pressed="activeFilterTab === 'filters' && filterPaneVisible"
                                title="<?php echo esc_attr__('Filters', Odcm_Config::$text_domain); ?>">
                            <span class="dashicons dashicons-search"></span>
                        </button>
                        
                        <!-- Settings tab button -->
                        <button type="button"
                                class="odcm-pane-icon-button"
                                @click="showSettingsPane()"
                                :aria-pressed="activeFilterTab === 'settings' && filterPaneVisible"
                                title="<?php echo esc_attr__('Settings', Odcm_Config::$text_domain); ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Log Stream Header -->
            <div class="odcm-unified-header-section odcm-unified-header-stream">
                <div class="odcm-stream-header-content">
                    <h3><?php echo esc_html__('Log Stream', Odcm_Config::$text_domain); ?></h3>
                    <a href="<?php echo esc_url(ODCM_DOCS_URL); ?>" 
                       target="_blank" 
                       class="odcm-docs-link"
                       title="<?php echo esc_attr__('View Documentation', Odcm_Config::$text_domain); ?>">
                        <span class="dashicons dashicons-editor-help"></span>
                    </a>
                </div>
                <div class="odcm-stream-controls">
                    <div class="odcm-refresh-controls">
                        <button type="button" 
                                class="odcm-refresh-button button"
                                @click="manualRefresh()"
                                :disabled="loading">
                            <span class="dashicons dashicons-update" :class="{ 'is-spinning': isRefreshing }"></span>
                            <span class="odcm-button-text" x-text="autoRefreshEnabled ? '<?php echo esc_js(__('Refresh', Odcm_Config::$text_domain)); ?>' : '<?php echo esc_js(__('Refresh', Odcm_Config::$text_domain)); ?>'"></span>
                        </button>
                        <span x-text="autoRefreshEnabled ? '<?php echo esc_html__('every', Odcm_Config::$text_domain); ?>' : ''"></span>

                        <template x-if="autoRefreshEnabled">
                            <input type="number" 
                                    x-model="refreshInterval" 
                                    min="1" 
                                    max="60" 
                                    class="odcm-interval-input"
                                    @click.stop>
                        </template>
                        <template x-if="autoRefreshEnabled">
                            <span>second/s</span>
                        </template>

                        <label class="odcm-toggle-switch">
                            <input type="checkbox" x-model="autoRefreshEnabled">
                            <span class="odcm-toggle-slider"></span>
                            <span class="odcm-toggle-label"><?php echo esc_html__('Auto-refresh', Odcm_Config::$text_domain); ?></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Detail Header -->
            <div class="odcm-unified-header-section odcm-unified-header-details" x-show="selectedLog">
                <h3><?php echo esc_html__('Events Timeline', Odcm_Config::$text_domain); ?></h3>
                <div class="odcm-detail-pane-header-actions">
                    <button type="button" 
                            class="odcm-detail-pane-expand-toggle"
                            @click="toggleDetailPaneExpansion()"
                            :title="detailPaneExpanded ? '<?php echo esc_attr__('Contract details pane', Odcm_Config::$text_domain); ?>' : '<?php echo esc_attr__('Expand details pane', Odcm_Config::$text_domain); ?>'">
                        <span class="dashicons dashicons-arrow-left-alt icon-expand"></span>
                        <span class="dashicons dashicons-arrow-right-alt icon-collapse"></span>
                    </button>
                    <button type="button" 
                            class="odcm-close-pane"
                            @click="closeDetails()">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the filter pane content
     */
    private function render_filter_pane(): void
    {
        ?>
        <div class="odcm-filter-pane-content">
            <!-- Filters Tab Content -->
            <div x-show="activeFilterTab === 'filters'" class="odcm-tab-content">
                <?php $this->render_filters_tab_content(); ?>
            </div>
            
            <!-- Settings Tab Content -->
            <div x-show="activeFilterTab === 'settings'" class="odcm-settings-pane-content">
                <?php $this->render_settings_tab_content(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the filters tab content
     */
    private function render_filters_tab_content(): void
    {
        ?>
        <form class="odcm-filter-form" @submit.prevent="applyFilters()">
                <!-- Omni Search (Free) -->
                <div class="odcm-filter-group">
                    <label for="filter-search"><?php echo esc_html__('Search', Odcm_Config::$text_domain); ?></label>
                    <input type="text" 
                           id="filter-search"
                           x-model="filters.search"
                           placeholder="<?php echo esc_attr__('Search Order ID or free text...', Odcm_Config::$text_domain); ?>"
                           class="regular-text">
                </div>

                <!-- Premium Filters Group -->
                <div class="odcm-premium-filter-group" :class="{ 'is-disabled': !canUsePremiumFilters }">
                    <div class="odcm-premium-badge odcm-premium-badge--absolute"><?php echo esc_html__('PREMIUM', Odcm_Config::$text_domain); ?></div>
                    <div class="odcm-premium-overlay" x-show="!canUsePremiumFilters" @click.prevent.stop="if (config?.upgrade?.enabled) { showToast(config.upgrade.message, 'info'); }" :title="config?.upgrade?.enabled ? config.upgrade.message : ''" aria-hidden="true"></div>
                    
                    <!-- Status Filter -->
                    <div class="odcm-filter-group">
                        <label for="filter-status"><?php echo esc_html__('Status', Odcm_Config::$text_domain); ?></label>
                        <select id="filter-status" 
                                x-model="filters.status"
                                :disabled="!canUsePremiumFilters">
                            <option value=""><?php echo esc_html__('All Statuses', Odcm_Config::$text_domain); ?></option>
                            <option value="success"><?php echo esc_html__('Success', Odcm_Config::$text_domain); ?></option>
                            <option value="error"><?php echo esc_html__('Error', Odcm_Config::$text_domain); ?></option>
                            <option value="warning"><?php echo esc_html__('Warning', Odcm_Config::$text_domain); ?></option>
                            <option value="info"><?php echo esc_html__('Info', Odcm_Config::$text_domain); ?></option>
                        </select>
                    </div>

                    <!-- Event Type Filter -->
                    <div class="odcm-filter-group">
                        <label for="filter-event-type"><?php echo esc_html__('Event Type', Odcm_Config::$text_domain); ?></label>
                        <select id="filter-event-type" 
                                x-model="filters.event_type"
                                :disabled="!canUsePremiumFilters">
                            <option value=""><?php echo esc_html__('All Event Types', Odcm_Config::$text_domain); ?></option>
                            <option value="rule_check"><?php echo esc_html__('Rule Check', Odcm_Config::$text_domain); ?></option>
                            <option value="order_completion"><?php echo esc_html__('Order Completion', Odcm_Config::$text_domain); ?></option>
                            <option value="manual_trigger"><?php echo esc_html__('Manual Trigger', Odcm_Config::$text_domain); ?></option>
                            <option value="scheduled_task"><?php echo esc_html__('Scheduled Task', Odcm_Config::$text_domain); ?></option>
                            <option value="webhook_received"><?php echo esc_html__('Webhook Received', Odcm_Config::$text_domain); ?></option>
                            <option value="error_occurred"><?php echo esc_html__('Error Occurred', Odcm_Config::$text_domain); ?></option>
                        </select>
                    </div>

                    <!-- Source Filter -->
                    <div class="odcm-filter-group">
                        <label for="filter-source"><?php echo esc_html__('Source', Odcm_Config::$text_domain); ?></label>
                        <select id="filter-source" 
                                x-model="filters.source"
                                :disabled="!canUsePremiumFilters">
                            <option value=""><?php echo esc_html__('All Sources', Odcm_Config::$text_domain); ?></option>
                            <option value="manual"><?php echo esc_html__('Manual', Odcm_Config::$text_domain); ?></option>
                            <option value="scheduled"><?php echo esc_html__('Scheduled', Odcm_Config::$text_domain); ?></option>
                            <option value="webhook"><?php echo esc_html__('Webhook', Odcm_Config::$text_domain); ?></option>
                            <option value="api"><?php echo esc_html__('API', Odcm_Config::$text_domain); ?></option>
                            <option value="system"><?php echo esc_html__('System', Odcm_Config::$text_domain); ?></option>
                        </select>
                    </div>

                    <!-- Date Range Filter -->
                    <div class="odcm-filter-group">
                        <label><?php echo esc_html__('Date Range', Odcm_Config::$text_domain); ?></label>
                        <div class="odcm-date-range">
                            <input type="date" 
                                   x-model="filters.date_start"
                                   :disabled="!canUsePremiumFilters"
                                   class="regular-text">
                            <span>–</span>
                            <input type="date" 
                                   x-model="filters.date_end"
                                   :disabled="!canUsePremiumFilters"
                                   class="regular-text">
                        </div>
                    </div>
                </div>

                <!-- Include Test and Debug Logs -->
                <div class="odcm-filter-group">
                    <div class="odcm-checkbox-group">
                        <div class="odcm-checkbox-item">
                            <input type="checkbox" 
                                   id="filter-include-tests"
                                   x-model="filters.include_tests">
                            <label for="filter-include-tests"><?php echo esc_html__('Include Test Logs', Odcm_Config::$text_domain); ?></label>
                        </div>
                        <div class="odcm-checkbox-item">
                            <input type="checkbox" 
                                   id="filter-include-debug"
                                   x-model="filters.include_debug">
                            <label for="filter-include-debug"><?php echo esc_html__('Include Debug Logs', Odcm_Config::$text_domain); ?></label>
                        </div>
                    </div>
                </div>

                <!-- Filter Actions -->
                <div class="odcm-filter-actions">
                    <button type="submit" class="button button-primary">
                        <?php echo esc_html__('Apply Filters', Odcm_Config::$text_domain); ?>
                    </button>
                    <button type="button" class="button" @click="clearFilters()">
                        <?php echo esc_html__('Clear All', Odcm_Config::$text_domain); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render the settings tab content
     */
    private function render_settings_tab_content(): void
    {
        // Get current setting values
        $detailed_notes = get_option('odcm_detailed_notes', false);
        $global_debug = self::is_global_debug_active(); // Use the override-aware method
        
        ?>
        <div class="odcm-tab-content">
            <!-- Display Settings Accordion Section -->
            <div class="odcm-settings-accordion">
                <div class="odcm-settings-accordion-header" 
                     @click="toggleSettingsSection('display')"
                     :class="{ 'is-expanded': settingsAccordionState.display }"
                     role="button"
                     tabindex="0"
                     :aria-expanded="settingsAccordionState.display"
                     aria-controls="display-settings-content">
                    <div class="odcm-settings-accordion-title">
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('Display Options', Odcm_Config::$text_domain); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('Configure how the dashboard displays information.', Odcm_Config::$text_domain); ?></p>
                    </div>
                    <span class="odcm-settings-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div class="odcm-settings-accordion-content" 
                     id="display-settings-content"
                     x-show="settingsAccordionState.display"
                     x-transition:enter="odcm-accordion-enter"
                     x-transition:enter-start="odcm-accordion-enter-start"
                     x-transition:enter-end="odcm-accordion-enter-end"
                     x-transition:leave="odcm-accordion-leave"
                     x-transition:leave-start="odcm-accordion-leave-start"
                     x-transition:leave-end="odcm-accordion-leave-end">
                    <div class="odcm-settings-section-inner">
                        <!-- Timestamp Format -->
                        <div class="odcm-setting-row">
                            <label class="odcm-setting-label"><?php echo esc_html__('Timestamp Format', Odcm_Config::$text_domain); ?></label>
                            <div class="odcm-setting-row-pair">
                                <p class="description"><?php echo esc_html__('Choose how timestamps are displayed in log entries.', Odcm_Config::$text_domain); ?></p>
                                <button type="button" 
                                        class="odcm-timestamp-toggle button"
                                        @click="toggleTimestampMode()"
                                        :title="'Current: ' + (timestampDisplayMode === 'timeOnly' ? i18n.timeOnly : timestampDisplayMode === 'relative' ? i18n.relativeTime : i18n.dateAndTime)">
                                    <span class="dashicons dashicons-clock"></span>
                                    <span class="odcm-button-text" x-text="timestampDisplayMode === 'timeOnly' ? i18n.timeOnly : timestampDisplayMode === 'relative' ? i18n.relativeTime : i18n.dateAndTime"></span>
                                </button>
                            </div>
                        </div>

                        <!-- Entries Per Page -->
                        <div class="odcm-setting-row">
                            <label for="odcm_logs_per_page" class="odcm-setting-label"><?php echo esc_html__('Entries Per Page', Odcm_Config::$text_domain); ?></label>
                            <div class="odcm-setting-row-pair">
                                <p class="description"><?php echo esc_html__('Number of log entries to display per page (10-200).', Odcm_Config::$text_domain); ?></p>
                                <input type="number" 
                                    id="odcm_logs_per_page"
                                    x-model="perPage"
                                    @change="updatePerPageSetting()"
                                    min="10" 
                                    max="200" 
                                    class="small-text">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Processing Accordion Section -->
            <div class="odcm-settings-accordion">
                <div class="odcm-settings-accordion-header" 
                     @click="toggleSettingsSection('orderProcessing')"
                     :class="{ 'is-expanded': settingsAccordionState.orderProcessing }"
                     role="button"
                     tabindex="0"
                     :aria-expanded="settingsAccordionState.orderProcessing"
                     aria-controls="order-processing-content">
                    <div class="odcm-settings-accordion-title">
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('Order Processing', Odcm_Config::$text_domain); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('Manage and reprocess pending orders in your WooCommerce store.', Odcm_Config::$text_domain); ?></p>
                    </div>
                    <span class="odcm-settings-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div class="odcm-settings-accordion-content" 
                     id="order-processing-content"
                     x-show="settingsAccordionState.orderProcessing"
                     x-transition:enter="odcm-accordion-enter"
                     x-transition:enter-start="odcm-accordion-enter-start"
                     x-transition:enter-end="odcm-accordion-enter-end"
                     x-transition:leave="odcm-accordion-leave"
                     x-transition:leave-start="odcm-accordion-leave-start"
                     x-transition:leave-end="odcm-accordion-leave-end">
                    <div class="odcm-settings-section-inner">
                        <!-- Reprocess Pending Orders -->
                        <div class="odcm-setting-row">
                            <label class="odcm-setting-label"><?php echo esc_html__('Reprocess Pending Orders', Odcm_Config::$text_domain); ?></label>
                            <p class="description"><?php echo esc_html__('Find all orders with "processing" or "on-hold" status and reprocess them against your order rules. This operation runs in the background to prevent timeouts.', Odcm_Config::$text_domain); ?></p>
                            <button type="button" 
                                    class="odcm-refresh-button button"
                                    @click="reprocessPendingOrders()"
                                    :disabled="isReprocessing">
                                <span class="dashicons dashicons-update" :class="{ 'is-spinning': isReprocessing }"></span>
                                <span class="odcm-button-text" x-text="isReprocessing ? '<?php echo esc_js(__('Processing...', Odcm_Config::$text_domain)); ?>' : '<?php echo esc_js(__('Reprocess Pending Orders', Odcm_Config::$text_domain)); ?>'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Educational Prompts Accordion Section -->
            <?php 
                // Read current frequency preference from user meta (shared with UpgradePrompts)
                $prefs = get_user_meta(get_current_user_id(), 'odcm_upgrade_prefs', true);
                $freq = is_array($prefs) && isset($prefs['frequency']) ? $prefs['frequency'] : 'normal';
                $freq = in_array($freq, ['normal','reduced','off'], true) ? $freq : 'normal';
            ?>
            <div class="odcm-settings-accordion">
                <div class="odcm-settings-accordion-header" 
                     @click="toggleSettingsSection('education')"
                     :class="{ 'is-expanded': settingsAccordionState.education }"
                     role="button"
                     tabindex="0"
                     :aria-expanded="settingsAccordionState.education"
                     aria-controls="education-settings-content">
                    <div class="odcm-settings-accordion-title">
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('Educational Prompts', Odcm_Config::$text_domain); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('Control how often upgrade and feature education prompts appear.', Odcm_Config::$text_domain); ?></p>
                    </div>
                    <span class="odcm-settings-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div class="odcm-settings-accordion-content" 
                     id="education-settings-content"
                     x-show="settingsAccordionState.education"
                     x-transition:enter="odcm-accordion-enter"
                     x-transition:enter-start="odcm-accordion-enter-start"
                     x-transition:enter-end="odcm-accordion-enter-end"
                     x-transition:leave="odcm-accordion-leave"
                     x-transition:leave-start="odcm-accordion-leave-start"
                     x-transition:leave-end="odcm-accordion-leave-end">
                    <div class="odcm-settings-section-inner">
                        <div class="odcm-setting-row">
                            <label class="odcm-setting-label"><?php echo esc_html__('Prompt Frequency', Odcm_Config::$text_domain); ?></label>
                            <div class="odcm-setting-row">
                                <p class="description"><?php echo esc_html__('These prompts are educational and help you discover features. You can adjust how often they appear.', Odcm_Config::$text_domain); ?></p>
                                <fieldset role="radiogroup" aria-label="<?php echo esc_attr__('Prompt Frequency', Odcm_Config::$text_domain); ?>">
                                    <label class="odcm-radio-row"><input type="radio" class="odcm-upgrade-frequency" name="odcm-upgrade-frequency" value="normal" <?php checked($freq, 'normal'); ?> > <?php echo esc_html__('Normal', Odcm_Config::$text_domain); ?></label>
                                    <label class="odcm-radio-row"><input type="radio" class="odcm-upgrade-frequency" name="odcm-upgrade-frequency" value="reduced" <?php checked($freq, 'reduced'); ?> > <?php echo esc_html__('Reduced', Odcm_Config::$text_domain); ?></label>
                                    <label class="odcm-radio-row"><input type="radio" class="odcm-upgrade-frequency" name="odcm-upgrade-frequency" value="off" <?php checked($freq, 'off'); ?> > <?php echo esc_html__('Off', Odcm_Config::$text_domain); ?></label>
                                </fieldset>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Webhook Configuration Accordion Section -->
            <div class="odcm-settings-accordion">
                <div class="odcm-settings-accordion-header" 
                     @click="toggleSettingsSection('webhooks')"
                     :class="{ 'is-expanded': settingsAccordionState.webhooks }"
                     role="button"
                     tabindex="0"
                     :aria-expanded="settingsAccordionState.webhooks"
                     aria-controls="webhook-settings-content">
                    <div class="odcm-settings-accordion-title">
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('Webhook Configuration', Odcm_Config::$text_domain); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('Configure webhook endpoints for payment gateways and external services.', Odcm_Config::$text_domain); ?></p>
                    </div>
                    <span class="odcm-settings-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div class="odcm-settings-accordion-content" 
                     id="webhook-settings-content"
                     x-show="settingsAccordionState.webhooks"
                     x-transition:enter="odcm-accordion-enter"
                     x-transition:enter-start="odcm-accordion-enter-start"
                     x-transition:enter-end="odcm-accordion-enter-end"
                     x-transition:leave="odcm-accordion-leave"
                     x-transition:leave-start="odcm-accordion-leave-start"
                     x-transition:leave-end="odcm-accordion-leave-end">
                    <div class="odcm-settings-section-inner">
                        <?php $this->render_webhook_configuration(); ?>
                    </div>
                </div>
            </div>

            <!-- Debug Settings Accordion Section -->
            <div class="odcm-settings-accordion">
                <div class="odcm-settings-accordion-header" 
                     @click="toggleSettingsSection('debug')"
                     :class="{ 'is-expanded': settingsAccordionState.debug }"
                     role="button"
                     tabindex="0"
                     :aria-expanded="settingsAccordionState.debug"
                     aria-controls="debug-settings-content">
                    <div class="odcm-settings-accordion-title">
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('Debug Settings', Odcm_Config::$text_domain); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('Configure settings to help with debugging and development.', Odcm_Config::$text_domain); ?></p>
                    </div>
                    <span class="odcm-settings-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div class="odcm-settings-accordion-content" 
                     id="debug-settings-content"
                     x-show="settingsAccordionState.debug"
                     x-transition:enter="odcm-accordion-enter"
                     x-transition:enter-start="odcm-accordion-enter-start"
                     x-transition:enter-end="odcm-accordion-enter-end"
                     x-transition:leave="odcm-accordion-leave"
                     x-transition:leave-start="odcm-accordion-leave-start"
                     x-transition:leave-end="odcm-accordion-leave-end">
                    <div class="odcm-settings-section-inner">
                        <!-- Enable Global Debug Mode -->
                        <div class="odcm-setting-row">
                            <label for="odcm_global_debug" class="odcm-setting-label">
                                <input type="checkbox" 
                                       name="odcm_global_debug" 
                                       id="odcm_global_debug" 
                                       <?php checked($global_debug); ?>
                                       @change="saveDebugSetting('odcm_global_debug', $event.target.checked)">
                                <?php echo esc_html__('Enable Global Debug Mode', Odcm_Config::$text_domain); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Sets the ODCM_DEBUG constant to true. Adds verbose debugging info server-wide. Use with caution.', Odcm_Config::$text_domain); ?>
                            </p>
                        </div>

                        <!-- Detailed Order Notes -->
                        <div class="odcm-setting-row">
                            <label for="odcm_detailed_notes" class="odcm-setting-label">
                                <input type="checkbox" 
                                       name="odcm_detailed_notes" 
                                       id="odcm_detailed_notes" 
                                       <?php checked($detailed_notes); ?>
                                       @change="saveDebugSetting('odcm_detailed_notes', $event.target.checked)">
                                <?php echo esc_html__('Add Debug Info to Order Notes', Odcm_Config::$text_domain); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Include detailed product information in order notes when rules do not match. Helps with debugging but may add data bloat.', Odcm_Config::$text_domain); ?>
                            </p>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Data Management Accordion Section -->
            <div class="odcm-settings-accordion">
                <div class="odcm-settings-accordion-header" 
                     @click="toggleSettingsSection('dataManagement')"
                     :class="{ 'is-expanded': settingsAccordionState.dataManagement }"
                     role="button"
                     tabindex="0"
                     :aria-expanded="settingsAccordionState.dataManagement"
                     aria-controls="data-management-content">
                    <div class="odcm-settings-accordion-title">
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('Data Management', Odcm_Config::$text_domain); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('Advanced data management and export features.', Odcm_Config::$text_domain); ?></p>
                    </div>
                    <span class="odcm-settings-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div class="odcm-settings-accordion-content" 
                     id="data-management-content"
                     x-show="settingsAccordionState.dataManagement"
                     x-transition:enter="odcm-accordion-enter"
                     x-transition:enter-start="odcm-accordion-enter-start"
                     x-transition:enter-end="odcm-accordion-enter-end"
                     x-transition:leave="odcm-accordion-leave"
                     x-transition:leave-start="odcm-accordion-leave-start"
                     x-transition:leave-end="odcm-accordion-leave-end">
                    <div class="odcm-settings-section-inner">
                        <!-- Export Logs (Pro Feature) -->
                        <div class="odcm-setting-row odcm-premium-notice-small">
                            <label class="odcm-setting-label"><?php echo esc_html__('Export Logs', Odcm_Config::$text_domain); ?></label>
                            <p><?php echo esc_html__('Exporting audit trail logs is a pro feature.', Odcm_Config::$text_domain); ?></p>
                            <a href="<?php echo esc_url(ODCM_PREMIUM_URL); ?>" class="button-secondary odcm-button-small" target="_blank">
                                <?php echo esc_html__('Upgrade to Pro', Odcm_Config::$text_domain); ?>
                            </a>
                        </div>
                        <!-- Log Retention Policy (Fixed in Free) -->
                        <div class="odcm-setting-row odcm-danger-section">
                            <label class="odcm-setting-label"><?php echo esc_html__('Log Retention Policy', Odcm_Config::$text_domain); ?></label>
                            <p class="description"><?php echo esc_html__('In the free version, audit trail logs are kept for 30 days. Retention controls are available in Pro.', Odcm_Config::$text_domain); ?></p>
                            <a href="<?php echo esc_url(ODCM_PREMIUM_URL); ?>" class="button-secondary odcm-button-small" target="_blank">
                                <?php echo esc_html__('Upgrade to Pro', Odcm_Config::$text_domain); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


    /**
     * Handle AJAX request to update per page setting
     */
    public function handle_update_per_page_ajax(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', Odcm_Config::$text_domain)]);
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_rest')) {
            wp_send_json_error(['message' => __('Security check failed.', Odcm_Config::$text_domain)]);
        }

        // Get and validate input
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 0;

        // Validate range
        if ($per_page < 10 || $per_page > 200) {
            wp_send_json_error(['message' => __('Entries per page must be between 10 and 200.', Odcm_Config::$text_domain)]);
        }

        // Update user meta
        $user_id = get_current_user_id();
        $updated = update_user_meta($user_id, 'odcm_logs_per_page', $per_page);

        if ($updated !== false) {
            wp_send_json_success([
                'message' => sprintf(__('Entries per page updated to %d.', Odcm_Config::$text_domain), $per_page),
                'per_page' => $per_page
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to update setting.', Odcm_Config::$text_domain)]);
        }
    }

    /**
     * Handle AJAX request to save debug settings
     */
    public function handle_debug_settings_ajax(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', Odcm_Config::$text_domain)]);
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_rest')) {
            wp_send_json_error(['message' => __('Security check failed.', Odcm_Config::$text_domain)]);
        }

        // Get and validate input - only process the setting that was sent
        $updated_settings = [];
        
        if (isset($_POST['odcm_global_debug'])) {
            $global_debug = $_POST['odcm_global_debug'] === '1';
            $this->update_global_debug_mode($global_debug);
            $updated_settings['global_debug'] = $global_debug;
        }
        
        if (isset($_POST['odcm_detailed_notes'])) {
            $detailed_notes = $_POST['odcm_detailed_notes'] === '1';
            update_option('odcm_detailed_notes', $detailed_notes, 'no');
            $updated_settings['detailed_notes'] = $detailed_notes;
        }

        // Log the debug setting change
        if (isset($updated_settings['global_debug'])) {
            $this->log_debug_mode_change($updated_settings['global_debug']);
        }

        wp_send_json_success([
            'message' => __('Debug settings saved successfully.', Odcm_Config::$text_domain),
            'updated_settings' => $updated_settings
        ]);
    }

    /**
     * Update global debug mode setting
     * 
     * Uses the same debug override system as DevToolbar for consistency.
     */
    private function update_global_debug_mode(bool $enabled): void
    {
        // Use the same debug override option as DevToolbar
        update_option('odcm_dev_debug_override', $enabled ? 1 : 0, 'no');
        
        // Apply the override immediately for this request using same global as DevToolbar
        $GLOBALS['odcm_debug_override'] = $enabled;
        
        // Hook into the debug check functions if they exist
        if (function_exists('add_filter')) {
            add_filter('odcm_debug_enabled', function($current_state) use ($enabled) {
                return $enabled;
            }, 999);
        }
    }

    /**
     * Check if global debug mode is currently active
     * 
     * Uses the same debug override system as DevToolbar for consistency.
     */
    public static function is_global_debug_active(): bool
    {
        // Check for runtime override first (same global as DevToolbar)
        if (isset($GLOBALS['odcm_debug_override'])) {
            return (bool) $GLOBALS['odcm_debug_override'];
        }

        // Check for stored override (same option as DevToolbar)
        $debug_override = get_option('odcm_dev_debug_override', null);
        if ($debug_override !== null) {
            return (bool) $debug_override;
        }

        // Fall back to the original ODCM_DEBUG constant
        return defined('ODCM_DEBUG') && ODCM_DEBUG;
    }

    /**
     * Log debug mode changes for audit trail
     */
    private function log_debug_mode_change(bool $enabled): void
    {
        $message = sprintf(
            'Global debug mode %s via Insight Dashboard',
            $enabled ? 'enabled' : 'disabled'
        );

        $context = [
            'action_type' => 'debug_mode_change',
            'debug_enabled' => $enabled,
            'source' => 'insight_dashboard',
            'user_id' => get_current_user_id(),
            'user_login' => wp_get_current_user()->user_login,
            'timestamp' => current_time('mysql'),
        ];

        // Use Order Daemon custom event logging if available
        if (function_exists('odcm_log_custom_event')) {
            odcm_log_custom_event(
                $message,
                $context,
                null, // No order ID
                'info', // Log level
                'insight_debug_toggle' // Event type
            );
        }
    }



    /**
     * Handle AJAX request to reprocess pending orders
     */
    public function handle_reprocess_pending_orders_ajax(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', Odcm_Config::$text_domain)]);
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_rest')) {
            wp_send_json_error(['message' => __('Security check failed.', Odcm_Config::$text_domain)]);
        }

        try {
            // Get the Core instance to call the reprocess method
            $core = new \OrderDaemon\CompletionManager\Core\Core();
            
            // Call the existing reprocess pending orders method
            $count = $core->reprocess_pending_orders();
            
            // Log the reprocess action for audit trail
            $this->log_reprocess_action_ajax($count);
            
            // Return success response
            wp_send_json_success([
                'message' => sprintf(
                    // translators: %d is the number of orders.
                    _n(
                        '%d order has been scheduled for reprocessing.',
                        '%d orders have been scheduled for reprocessing.',
                        $count,
                        Odcm_Config::$text_domain
                    ),
                    $count
                ),
                'count' => $count
            ]);
            
        } catch (\Exception $e) {
            // Log the error
            error_log('ODCM: Error in reprocess pending orders AJAX: ' . $e->getMessage());
            
            wp_send_json_error([
                'message' => __('Failed to reprocess pending orders. Please try again.', Odcm_Config::$text_domain)
            ]);
        }
    }


    /**
     * Log the reprocess action to the audit trail (AJAX version)
     * 
     * @param int $count The number of orders scheduled for reprocessing
     */
    private function log_reprocess_action_ajax(int $count): void
    {
        $current_user = wp_get_current_user();
        $user_display_name = $current_user->display_name ?: $current_user->user_login;

        // Narrative-based single process entry for admin reprocess action
        $pl = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger(new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer());
        $pl->start('admin_action', [ 'order_id' => null, 'actor_user_id' => get_current_user_id(), 'summary' => 'Admin requested reprocess' ]);
        $pl->add_component('info', 'Reprocess orders requested', [ 'message' => sprintf('%s requested reprocessing of %d orders', $user_display_name, $count) ]);
        $pl->add_component('metrics', 'Orders scheduled', [ 'name' => 'orders_scheduled', 'value' => (float)$count ]);
        $pl->finish('success', sprintf('Admin requested reprocessing of %d orders', $count));
    }


    /**
     * Render the log stream content
     */
    private function render_log_stream(): void
    {
        ?>
        <div class="odcm-log-stream-content">

            <!-- Loading State -->
            <div class="odcm-loading-state" x-show="loading && logs.length === 0">
                <span class="spinner is-active"></span>
                <span x-text="i18n.loading"></span>
            </div>

            <!-- Error State - Only render when there's actually an error -->
            <template x-if="error && logs.length === 0">
                <div class="odcm-error-state">
                    <span class="dashicons dashicons-warning"></span>
                    <span x-text="error"></span>
                    <button type="button" class="button" @click="fetchLogs()">
                        <?php echo esc_html__('Retry', Odcm_Config::$text_domain); ?>
                    </button>
                </div>
            </template>

            <!-- Empty State with Context Awareness - Only render when appropriate -->
            <template x-if="!loading && !error && logs.length === 0 && !initialLoad">
                <div class="odcm-empty-state">
                    <!-- Welcome State (Fresh Installation) -->
                    <template x-if="isWelcomeScenario">
                        <div class="odcm-welcome-state">
                            <div class="odcm-welcome-icon">
                                <span class="dashicons dashicons-chart-line"></span>
                            </div>
                            <div class="odcm-welcome-text">
                                <h3><?php echo Odcm_Strings::esc_html__('Welcome to Order Daemon Insights!'); ?></h3>
                                <p><?php echo Odcm_Strings::esc_html__('This dashboard will show real-time activity from your order completion rules once they start running.'); ?></p>
                           </div>

                            <div class="odcm-welcome-steps">
                                <h4><?php echo Odcm_Strings::esc_html__('To get started:'); ?></h4>
                                <ol>
                                    <li><strong><?php echo Odcm_Strings::esc_html__('Create your first completion rule'); ?></strong> <?php echo Odcm_Strings::esc_html__('in WooCommerce → All Order Rules'); ?></li>
                                    <li><strong><?php echo Odcm_Strings::esc_html__('Place a test order'); ?></strong> <?php echo Odcm_Strings::esc_html__('that matches your rule conditions'); ?></li>
                                    <li><strong><?php echo Odcm_Strings::esc_html__('Return here'); ?></strong> <?php echo Odcm_Strings::esc_html__('to see the automation in action'); ?></li>
                                </ol>
                            </div>

                            <div class="odcm-welcome-actions">
                                <a href="<?php echo esc_url(admin_url('edit.php?post_type=odcm_order_rule')); ?>" class="button button-primary">
                                    <?php echo Odcm_Strings::esc_html__('Create Your First Rule'); ?>
                                </a>
                                <a href="https://orderdaemon.com/docs" target="_blank" class="button button-secondary odcm-docs-link">
                                    <?php echo Odcm_Strings::esc_html__('View Documentation'); ?>
                                </a>
                            </div>

                            <div class="odcm-welcome-note">
                                <p><em>💡 <?php echo Odcm_Strings::esc_html__('Tip: Activity will appear here automatically once your rules start processing orders. No additional setup required!'); ?></em></p>
                            </div>
                        </div>
                    </template>

                    <!-- Regular Empty State (Rules exist, no recent activity) -->
                    <template x-if="!isWelcomeScenario">
                        <div class="odcm-regular-empty-state">
                            <span class="dashicons dashicons-admin-post"></span>
                            <h4><?php echo Odcm_Strings::esc_html__('No Recent Activity'); ?></h4>
                            <p><?php echo Odcm_Strings::esc_html__('Your completion rules are set up but haven\'t processed any orders recently.'); ?></p>
                            <div class="odcm-empty-actions">
                                <button type="button" class="button" @click="fetchLogs()">
                                    <?php echo Odcm_Strings::esc_html__(Odcm_Strings::REFRESH); ?>
                                </button>
                                <a href="<?php echo esc_url(admin_url('edit.php?post_type=odcm_order_rule')); ?>" class="button button-secondary">
                                    <?php echo Odcm_Strings::esc_html__('Manage Rules'); ?>
                                </a>
                            </div>
                        </div>
                    </template>
                </div>
            </template>


            <!-- Log Entries -->
            <div class="odcm-log-entries" x-show="logs.length > 0">
                <!-- Log Entries Controls Wrapper -->
                <div class="odcm-log-entries-controls">
                    <!-- Batch Selection Controls -->
                    <div class="odcm-batch-controls">
                        <div class="odcm-select-all-container">
                            <input type="checkbox" 
                                   id="select-all-logs"
                                   x-model="selectAll"
                                   @change="toggleSelectAll()"
                                   class="odcm-select-all-checkbox">
                            <label for="select-all-logs" class="odcm-select-all-label">
                                <?php echo esc_html__('Select All', Odcm_Config::$text_domain); ?>
                            </label>
                        </div>
                        <div class="odcm-batch-actions" x-show="hasSelection">
                            <span class="odcm-selection-count" x-text="selectedCount + ' selected'"></span>
                            <button type="button" 
                                    class="odcm-delete-selected button button-secondary"
                                    @click="deleteSelectedLogs()"
                                    :disabled="isDeleting">
                                <span class="dashicons dashicons-trash" :class="{ 'is-spinning': isDeleting }"></span>
                                <span x-text="isDeleting ? '<?php echo esc_js(__('Deleting...', Odcm_Config::$text_domain)); ?>' : '<?php echo esc_js(__('Delete Selected', Odcm_Config::$text_domain)); ?>'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <template x-for="(log, index) in logs" :key="log?.id || ('invalid-' + index)">
                    <div class="odcm-log-entry" 
                         x-show="log && log.id"
                         :class="{ 
                             'is-selected': selectedLog && selectedLog.id === log?.id,
                             'is-checkbox-selected': log?.id && isLogSelected(log.id),
                             'is-consolidated': log?.consolidation_data && log.consolidation_data.is_consolidated
                         }">
                        
                        <div class="odcm-log-entry-checkbox" x-show="log && log.id">
                            <input type="checkbox" 
                                   :id="'log-checkbox-' + (log?.id || 'invalid')"
                                   :checked="log?.id && isLogSelected(log.id)"
                                   @change="log?.id && toggleLogSelection(log.id)"
                                   @click.stop
                                   class="odcm-log-checkbox">
                            <label :for="'log-checkbox-' + (log?.id || 'invalid')" class="odcm-log-checkbox-label">
                                <span class="screen-reader-text"><?php echo esc_html__('Select log entry', Odcm_Config::$text_domain); ?></span>
                            </label>
                        </div>
                        
                        <div class="odcm-log-entry-content" @click="log && selectLog(log)">
                            <div class="odcm-log-entry-header">
                                <div class="odcm-log-summary">
                                    <span x-show="log?.consolidation_data && log.consolidation_data.is_consolidated"
                                          class="dashicons dashicons-networking odcm-consolidated-icon"
                                          title="<?php echo esc_attr__('Consolidated Entry', Odcm_Config::$text_domain); ?>"></span>
                                    <span x-text="log?.summary || 'No summary available'"></span>
                                </div>
                                <span class="odcm-status-pill" 
                                      :class="'odcm-status-pill--' + ((log?.status && typeof log.status === 'string') ? log.status.toLowerCase() : 'unknown')"
                                      x-text="log?.status || 'Unknown'"></span>
                            </div>
                            
                            <div class="odcm-log-meta">
                                <span class="odcm-log-timestamp" x-text="formatTimestamp(log?.timestamp)"></span>
                                <span x-show="log?.order_id">
                                    <?php echo esc_html__('Order:', Odcm_Config::$text_domain); ?> #<span x-text="log.order_id"></span>
                                </span>
                                <span x-text="log?.event_type || ''"></span>
                                <span x-text="log?.source || ''"></span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Pagination -->
            <div class="odcm-pagination" x-show="totalPages > 1">
                <div class="tablenav-pages">
                    <span class="displaying-num" x-text="paginationText"></span>
                    <span class="pagination-links">
                        <button type="button" 
                                class="button first-page"
                                :disabled="currentPage === 1"
                                @click="goToPage(1)">‹‹</button>
                        <button type="button" 
                                class="button prev-page"
                                :disabled="currentPage === 1"
                                @click="goToPage(currentPage - 1)">‹</button>
                        <span class="paging-input">
                            <input type="number" 
                                   x-model="currentPage"
                                   @change="goToPage(currentPage)"
                                   :min="1"
                                   :max="totalPages"
                                   class="current-page">
                            <?php echo esc_html__('of', Odcm_Config::$text_domain); ?>
                            <span class="total-pages" x-text="totalPages"></span>
                        </span>
                        <button type="button" 
                                class="button next-page"
                                :disabled="currentPage === totalPages"
                                @click="goToPage(currentPage + 1)">›</button>
                        <button type="button" 
                                class="button last-page"
                                :disabled="currentPage === totalPages"
                                @click="goToPage(totalPages)">››</button>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the detail pane content
     */
    private function render_detail_pane(): void
    {
        ?>
        <div class="odcm-detail-pane-content">
            <!-- Loading State -->
            <div class="odcm-detail-loading" x-show="detailLoading">
                <span class="spinner is-active"></span>
                <span x-text="i18n.loading"></span>
            </div>

            <!-- Detail Content -->
            <div class="odcm-detail-content" 
                 x-show="!detailLoading && selectedLog"
                 x-html="detailHtml">
            </div>

            <!-- Empty State -->
            <div class="odcm-detail-empty" x-show="!detailLoading && !selectedLog">
                <span class="dashicons dashicons-info"></span>
                <span x-text="i18n.selectLog"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render webhook configuration section
     */
    private function render_webhook_configuration(): void
    {
        // Get webhook health status
        $webhook_health = $this->get_webhook_health_status();
        
        ?>
        <!-- Webhook Endpoints -->
        <div class="odcm-setting-row">
            <label class="odcm-setting-label"><?php echo esc_html__('Webhook Endpoints', Odcm_Config::$text_domain); ?></label>
            <p class="description"><?php echo esc_html__('Configure these URLs in your payment gateway settings to receive real-time event notifications.', Odcm_Config::$text_domain); ?></p>
            
            <div class="odcm-webhook-endpoints">
                <!-- PayPal Webhook URL -->
                <div class="odcm-webhook-endpoint">
                    <label class="odcm-webhook-label">
                        <span class="odcm-gateway-icon">💳</span>
                        <?php echo esc_html__('PayPal Webhook URL', Odcm_Config::$text_domain); ?>
                        <span class="odcm-status-indicator odcm-status-indicator--<?php echo esc_attr($webhook_health['paypal']['status']); ?>" 
                              title="<?php echo esc_attr($webhook_health['paypal']['message']); ?>">
                            <?php echo $webhook_health['paypal']['status'] === 'active' ? '✅' : '⚠️'; ?>
                        </span>
                    </label>
                    <div class="odcm-webhook-url-container">
                        <input type="text" 
                               class="odcm-webhook-url regular-text" 
                               value="<?php echo esc_attr(rest_url('odcm/v1/webhooks/paypal')); ?>" 
                               readonly>
                        <button type="button" 
                                class="odcm-copy-url button button-secondary"
                                onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000)">
                            <?php echo esc_html__('Copy', Odcm_Config::$text_domain); ?>
                        </button>
                    </div>
                </div>

                <!-- Stripe Webhook URL -->
                <div class="odcm-webhook-endpoint">
                    <label class="odcm-webhook-label">
                        <span class="odcm-gateway-icon">💳</span>
                        <?php echo esc_html__('Stripe Webhook URL', Odcm_Config::$text_domain); ?>
                        <span class="odcm-status-indicator odcm-status-indicator--coming-soon" 
                              title="<?php echo esc_attr__('Stripe adapter coming soon', Odcm_Config::$text_domain); ?>">
                            🚧
                        </span>
                    </label>
                    <div class="odcm-webhook-url-container">
                        <input type="text" 
                               class="odcm-webhook-url regular-text" 
                               value="<?php echo esc_attr(rest_url('odcm/v1/webhooks/stripe')); ?>" 
                               readonly>
                        <button type="button" 
                                class="odcm-copy-url button button-secondary"
                                onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000)">
                            <?php echo esc_html__('Copy', Odcm_Config::$text_domain); ?>
                        </button>
                    </div>
                </div>

                <!-- Generic Webhook URL -->
                <div class="odcm-webhook-endpoint">
                    <label class="odcm-webhook-label">
                        <span class="odcm-gateway-icon">🔗</span>
                        <?php echo esc_html__('Generic Webhook URL', Odcm_Config::$text_domain); ?>
                        <span class="odcm-status-indicator odcm-status-indicator--<?php echo esc_attr($webhook_health['generic']['status']); ?>" 
                              title="<?php echo esc_attr($webhook_health['generic']['message']); ?>">
                            <?php echo $webhook_health['generic']['status'] === 'active' ? '✅' : '⚠️'; ?>
                        </span>
                    </label>
                    <div class="odcm-webhook-url-container">
                        <input type="text" 
                               class="odcm-webhook-url regular-text" 
                               value="<?php echo esc_attr(rest_url('odcm/v1/webhooks/generic')); ?>" 
                               readonly>
                        <button type="button" 
                                class="odcm-copy-url button button-secondary"
                                onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000)">
                            <?php echo esc_html__('Copy', Odcm_Config::$text_domain); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gateway Adapters Status -->
        <div class="odcm-setting-row">
            <label class="odcm-setting-label"><?php echo esc_html__('Gateway Adapters', Odcm_Config::$text_domain); ?></label>
            <p class="description"><?php echo esc_html__('Status of available payment gateway adapters for processing webhook events.', Odcm_Config::$text_domain); ?></p>
            
            <div class="odcm-gateway-adapters">
                <?php foreach ($webhook_health['adapters'] as $gateway => $adapter_info): ?>
                <div class="odcm-adapter-status">
                    <div class="odcm-adapter-info">
                        <span class="odcm-adapter-name"><?php echo esc_html(ucfirst($gateway)); ?> Adapter</span>
                        <span class="odcm-adapter-status-badge odcm-adapter-status-badge--<?php echo esc_attr($adapter_info['status']); ?>">
                            <?php echo esc_html(ucfirst($adapter_info['status'])); ?>
                        </span>
                    </div>
                    <div class="odcm-adapter-details">
                        <small><?php echo esc_html($adapter_info['class']); ?></small>
                        <small><?php echo esc_html(sprintf(__('%d supported events', Odcm_Config::$text_domain), count($adapter_info['supported_events']))); ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Webhook Security Settings -->
        <div class="odcm-setting-row">
            <label class="odcm-setting-label"><?php echo esc_html__('Security Settings', Odcm_Config::$text_domain); ?></label>
            <p class="description"><?php echo esc_html__('Configure webhook authentication and security options.', Odcm_Config::$text_domain); ?></p>
            
            <div class="odcm-security-settings">
                <!-- PayPal Webhook Secret -->
                <div class="odcm-security-setting">
                    <label for="odcm_paypal_webhook_secret"><?php echo esc_html__('PayPal Webhook Secret', Odcm_Config::$text_domain); ?></label>
                    <input type="password" 
                           id="odcm_paypal_webhook_secret"
                           name="odcm_paypal_webhook_secret"
                           value="<?php echo esc_attr(get_option('odcm_paypal_webhook_secret', '')); ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr__('Enter PayPal webhook secret...', Odcm_Config::$text_domain); ?>">
                    <p class="description"><?php echo esc_html__('Optional: PayPal webhook secret for signature verification.', Odcm_Config::$text_domain); ?></p>
                </div>

                <!-- Webhook Debug Mode -->
                <div class="odcm-security-setting">
                    <label for="odcm_webhook_debug">
                        <input type="checkbox" 
                               id="odcm_webhook_debug"
                               name="odcm_webhook_debug"
                               <?php checked(get_option('odcm_webhook_debug', false)); ?>>
                        <?php echo esc_html__('Enable Webhook Debug Mode', Odcm_Config::$text_domain); ?>
                    </label>
                    <p class="description"><?php echo esc_html__('Log detailed webhook processing information for troubleshooting.', Odcm_Config::$text_domain); ?></p>
                </div>
            </div>
        </div>

        <!-- Webhook Testing -->
        <div class="odcm-setting-row">
            <label class="odcm-setting-label"><?php echo esc_html__('Webhook Testing', Odcm_Config::$text_domain); ?></label>
            <p class="description"><?php echo esc_html__('Test webhook processing with sample data to verify your configuration.', Odcm_Config::$text_domain); ?></p>
            
            <div class="odcm-webhook-testing">
                <button type="button" 
                        class="odcm-test-webhook button"
                        onclick="this.textContent='Testing...'; setTimeout(() => this.textContent='Test PayPal Webhook', 3000)">
                    <?php echo esc_html__('Test PayPal Webhook', Odcm_Config::$text_domain); ?>
                </button>
                <button type="button" 
                        class="odcm-test-webhook button" 
                        disabled
                        title="<?php echo esc_attr__('Stripe adapter coming soon', Odcm_Config::$text_domain); ?>">
                    <?php echo esc_html__('Test Stripe Webhook', Odcm_Config::$text_domain); ?>
                </button>
            </div>
        </div>

        <!-- Recent Webhook Activity -->
        <div class="odcm-setting-row">
            <label class="odcm-setting-label"><?php echo esc_html__('Recent Activity', Odcm_Config::$text_domain); ?></label>
            <p class="description"><?php echo esc_html__('Latest webhook events received and processed.', Odcm_Config::$text_domain); ?></p>
            
            <div class="odcm-recent-webhooks">
                <?php $recent_webhooks = $this->get_recent_webhook_activity(); ?>
                <?php if (empty($recent_webhooks)): ?>
                    <div class="odcm-no-webhooks">
                        <span class="dashicons dashicons-info"></span>
                        <span><?php echo esc_html__('No recent webhook activity. Webhooks will appear here once received.', Odcm_Config::$text_domain); ?></span>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_webhooks as $webhook): ?>
                    <div class="odcm-webhook-activity">
                        <div class="odcm-webhook-summary">
                            <span class="odcm-webhook-gateway"><?php echo esc_html(ucfirst($webhook['gateway'])); ?></span>
                            <span class="odcm-webhook-event"><?php echo esc_html($webhook['event_type']); ?></span>
                            <span class="odcm-webhook-status odcm-webhook-status--<?php echo esc_attr($webhook['status']); ?>">
                                <?php echo esc_html(ucfirst($webhook['status'])); ?>
                            </span>
                        </div>
                        <div class="odcm-webhook-time">
                            <?php echo esc_html(human_time_diff(strtotime($webhook['timestamp'])) . ' ago'); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get webhook health status
     * 
     * @return array Webhook health information
     */
    private function get_webhook_health_status(): array
    {
        try {
            // Check if EventRouter is available
            if (!class_exists('\OrderDaemon\CompletionManager\Core\Events\EventRouter')) {
                return $this->get_default_webhook_health();
            }

            $router = new \OrderDaemon\CompletionManager\Core\Events\EventRouter();
            $adapters = $router->getAvailableAdapters();

            $health = [
                'paypal' => [
                    'status' => isset($adapters['paypal']) ? 'active' : 'inactive',
                    'message' => isset($adapters['paypal']) ? 'PayPal adapter is active and ready' : 'PayPal adapter not available'
                ],
                'stripe' => [
                    'status' => 'coming-soon',
                    'message' => 'Stripe adapter coming in future update'
                ],
                'generic' => [
                    'status' => 'active',
                    'message' => 'Generic webhook endpoint is active'
                ],
                'adapters' => $adapters
            ];

            return $health;

        } catch (\Throwable $e) {
            return $this->get_default_webhook_health();
        }
    }

    /**
     * Get default webhook health status (fallback)
     * 
     * @return array Default health information
     */
    private function get_default_webhook_health(): array
    {
        return [
            'paypal' => [
                'status' => 'unknown',
                'message' => 'Unable to determine PayPal adapter status'
            ],
            'stripe' => [
                'status' => 'coming-soon',
                'message' => 'Stripe adapter coming in future update'
            ],
            'generic' => [
                'status' => 'active',
                'message' => 'Generic webhook endpoint should be active'
            ],
            'adapters' => []
        ];
    }

    /**
     * Get recent webhook activity
     * 
     * @return array Recent webhook events
     */
    private function get_recent_webhook_activity(): array
    {
        global $wpdb;

        try {
            // Query recent webhook events from audit log
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT summary, event_type, status, timestamp 
                 FROM {$wpdb->prefix}odcm_audit_log 
                 WHERE event_type IN ('webhook_reception', 'webhook_processing', 'universal_event_processing')
                 ORDER BY timestamp DESC 
                 LIMIT %d",
                5
            ), ARRAY_A);

            $webhooks = [];
            foreach ($results as $result) {
                // Extract gateway from summary or event type
                $gateway = 'unknown';
                if (stripos($result['summary'], 'paypal') !== false) {
                    $gateway = 'paypal';
                } elseif (stripos($result['summary'], 'stripe') !== false) {
                    $gateway = 'stripe';
                }

                $webhooks[] = [
                    'gateway' => $gateway,
                    'event_type' => $result['event_type'],
                    'status' => $result['status'] ?: 'info',
                    'timestamp' => $result['timestamp']
                ];
            }

            return $webhooks;

        } catch (\Throwable $e) {
            return [];
        }
    }
}
