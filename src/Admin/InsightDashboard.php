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
 * @since   1.0.0
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
        add_action('admin_menu', [$this, 'remove_duplicate_submenu'], 999); // Late priority to ensure removal after WordPress processes menus
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_custom_menu_icon'], 15);
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
     * Register the Order Daemon top-level menu and submenus
     * 
     * The key ordering here ensures the Insight Dashboard is both the
     * default destination for the top-level Order Daemon menu and the
     * first item in the submenu list.
     */
    public function register_menu_page(): void
    {
        // 1. First, add the main top-level menu (Order Daemon) pointing to the dashboard
        // WooCommerce uses position 55-56, Products around 57, so we use 56.5
        add_menu_page(
            __('admin.insight_dashboard.menu.title', 'order-daemon'),
            __('admin.insight_dashboard.menu.title', 'order-daemon'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_dashboard_page'],
            'none',
            56.5
        );
        
        // 2. IMPORTANT: First submenu must use EXACTLY the same slug as the parent
        // to ensure the parent menu links to the Insight Dashboard
        add_submenu_page(
            self::PAGE_SLUG,
            __('admin.insight_dashboard.submenu.insight_dashboard', 'order-daemon'),
            __('admin.insight_dashboard.submenu.insight_dashboard', 'order-daemon'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_dashboard_page']
        );
        
        // 3. Then add remaining submenu items
        add_submenu_page(
                self::PAGE_SLUG,
                __('admin.insight_dashboard.submenu.all_order_rules', 'order-daemon'),
                __('All Order Rules', 'order-daemon'),
                'manage_woocommerce',
                'edit.php?post_type=odcm_order_rule',
                null
        );

        // Add "Diagnostics" as third submenu item
        add_submenu_page(
            self::PAGE_SLUG,
            __('admin.insight_dashboard.submenu.diagnostics', 'order-daemon'),
            __('admin.insight_dashboard.submenu.diagnostics', 'order-daemon'),
            'manage_woocommerce',
            'odcm-diagnostics',
            [$this, 'render_diagnostics_page']
        );
    }

    /**
     * Previously removed duplicate submenu, but that's no longer needed
     * We're keeping the default submenu item and just renaming it
     * 
     * This method is kept for compatibility but doesn't remove anything anymore.
     */
    public function remove_duplicate_submenu(): void
    {
        // No longer removing the auto-created submenu item
    }

    /**
     * Render the diagnostics page using the DiagnosticDashboard
     */
    public function render_diagnostics_page(): void
    {
        // Initialize and render the diagnostic dashboard
        $diagnostic_dashboard = new DiagnosticDashboard();
        $diagnostic_dashboard->init();
        $diagnostic_dashboard->render_dashboard_page();
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

        // Alpine.js 3.14.9 served locally
        wp_enqueue_script(
            'alpine-js',
            $assets_url . 'js/vendor/alpine.min.js',
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

        // Base accordion state
        $accordion_state = [
            'display' => false,
            'orderProcessing' => false,
            'debug' => false,
            'dataManagement' => false,
        ];

        // Allow pro plugin to add additional accordion states
        $accordion_state = apply_filters('odcm_insight_dashboard_accordion_state', $accordion_state);

        // Localize script with API endpoints and configuration
        wp_localize_script('odcm-insight-dashboard-js', 'odcmInsightConfig', [
            // Use root-relative REST URLs to ensure same-origin requests (cookies sent), avoiding masked 404s
            'apiUrl' => wp_make_link_relative(rest_url('odcm/v1/audit-log/')),
            'renderUrl' => wp_make_link_relative(rest_url('odcm/v1/audit-log/render-components/')),
            'renderBatchUrl' => wp_make_link_relative(rest_url('odcm/v1/audit-log/render-components/batch/')),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_rest'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'premium_access' => (bool) apply_filters('odcm_is_premium_user', false),
            'perPage' => $this->get_user_per_page_setting(),
            'autoRefreshInterval' => 5000, // 5 seconds
            'debug' => self::is_global_debug_active(),
            'accordionState' => $accordion_state,
            'dateTimeConfig' => [
                'dateFormat' => get_option('date_format', 'F j, Y'),
                'timeFormat' => get_option('time_format', 'g:i a'),
                'timezone' => wp_timezone_string(),
                'startOfWeek' => (int) get_option('start_of_week', 0),
                'use24Hour' => strpos(get_option('time_format', 'g:i a'), 'H') !== false || strpos(get_option('time_format', 'g:i a'), 'G') !== false,
            ],
            'i18n' => [
            'loading' => __('admin.insight_dashboard.loading', 'order-daemon'),
            'error' => __('admin.insight_dashboard.error_loading_data', 'order-daemon'),
            'noLogs' => __('admin.insight_dashboard.no_logs', 'order-daemon'),
            'selectLog' => __('admin.insight_dashboard.select_log_entry', 'order-daemon'),
            'filters' => __('admin.insight_dashboard.filters', 'order-daemon'),
            'details' => __('admin.insight_dashboard.details', 'order-daemon'),
            'close' => __('admin.insight_dashboard.close', 'order-daemon'),
            'refresh' => __('admin.insight_dashboard.refresh', 'order-daemon'),
            'newLogsAvailable' => __('admin.insight_dashboard.new_logs_available', 'order-daemon'),
            'includeDebug' => __('admin.insight_dashboard.filters.include_debug_logs', 'order-daemon'),
            'timeOnly' => __('admin.insight_dashboard.timestamp.time_only', 'order-daemon'),
            'dateAndTime' => __('admin.insight_dashboard.timestamp.date_and_time', 'order-daemon'),
            'relativeTime' => __('admin.insight_dashboard.timestamp.relative_time', 'order-daemon'),
        ],
    ]);
    }

    /**
     * Enqueue custom styles for the plugin admin menu icon.
     */
    public function enqueue_custom_menu_icon(): void
    {
        if (!is_admin()) {
            return;
        }
        
        $menu_slug = self::PAGE_SLUG;
        
        // Create colored SVGs for different states
        $svg_normal_base64 = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xOS43MTUgNS4wMDE0NEMxOS44MDQ0IDQuOTk3NTkgMTkuODkzNCA1LjAwMTQ2IDE5Ljk4MjEgNS4wMTMxMkMyMC4wMTQ4IDUuMDc4MzkgMjAuMDAyNyA1LjEzNjggMTkuOTQ1NyA1LjE4ODMzQzE4LjY3NDUgNi42MzY3MSAxNy40NjgzIDguMTMxODQgMTYuMzI3IDkuNjczNzJDMTUuODk0IDEwLjI4ODcgMTUuNDczIDEwLjkxMTcgMTUuMDY0MSAxMS41NDI2QzE0Ljk4MzEgMTEuNjA4NSAxNC44OSAxMS42Mjc5IDE0Ljc4NDkgMTEuNjAxQzE0LjY1NzcgMTEuNDc4NyAxNC41NDg0IDExLjM0MjQgMTQuNDU3IDExLjE5MjJDMTMuOTkxNCAxMC40Njc5IDEzLjUzODEgOS43MzU5NSAxMy4wOTcgOC45OTYyNEMxMy4wMTAxIDguNzc4MzkgMTMuMDg3IDguNjk2NjMgMTMuMzI3NyA4Ljc1MDk0QzEzLjU3MjYgOC45MDg2OCAxMy43ODcyIDkuMDk5NDUgMTMuOTcxMyA5LjMyMzNDMTQuMjA5IDkuNjIyMDkgMTQuNDU1OSA5LjkxNDEgMTQuNzEyIDEwLjE5OTNDMTQuNzYzOCAxMC4yMTQyIDE0LjgxMjQgMTAuMjA2NCAxNC44NTc3IDEwLjE3NkMxNS4zNzA1IDkuNDcyNSAxNS44OTI2IDguNzc1NTYgMTYuNDI0MiA4LjA4NTE0QzE3LjAzODYgNy4zMTQ4IDE3LjY5ODMgNi41Nzg5MiAxOC40MDM1IDUuODc3NDlDMTguNjk3MSA1LjYwOCAxOS4wMTI4IDUuMzY2NTggMTkuMzUwNyA1LjE1MzI5QzE5LjQ3MTYgNS4wOTUyOCAxOS41OTMgNS4wNDQ2NiAxOS43MTUgNS4wMDE0NFoiIGZpbGw9IiMxOTFBMUEiLz4KPHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0zLjcxMDI2IDYuMDI5MjlDNS4wMTkzNyA1Ljk1NDUxIDYuMTYwODMgNi4zMzIxNyA3LjEzNDY0IDcuMTYyMzJDNy42NTQzOSA3LjYzMDgzIDguMTE5ODkgOC4xNDA4OCA4LjUzMTExIDguNjkyNDlDOC41ODY2NSA4Ljc2OTExIDguNjMxMTcgOC44NTA4OCA4LjY2NDY4IDguOTM3NzhDOC41NDkwNSA5LjE2MDIgOC40MDMzNCA5LjM2MjY4IDguMjI3NTMgOS41NDUxOEM4LjE5MjQ2IDkuNTYzNDIgOC4xNjAwOCA5LjU1OTUyIDguMTMwMzggOS41MzM1QzcuNjcyOTcgOC44NDAxOCA3LjExNDM5IDguMjI4ODggNi40NTQ2MiA3LjY5OTYzQzUuNjQ2MjMgNy4wNzQ0OCA0LjcyMzM0IDYuODAxOTIgMy42ODU5OCA2Ljg4MTk4QzIuMzM1MjkgNy4xMTQxNyAxLjQ0MDczIDcuODU3ODUgMS4wMDIzMyA5LjExMjk5QzAuNjI1MDk0IDEwLjYyNTggMS4wOTg2OCAxMS44MjEyIDIuNDIzMDkgMTIuNjk5QzMuODU4ODIgMTMuNDM5OCA1LjE5NDU3IDEzLjI5OTcgNi40MzAzNCAxMi4yNzg1QzYuODQwMTkgMTEuOTA3NiA3LjIyNDcyIDExLjUxNDMgNy41ODM5NCAxMS4wOTg3QzguMTc2MTYgMTAuMzUgOC43NTkwMyA5LjU5NDU5IDkuMzMyNTYgOC44MzI2NUM5LjkxNjY5IDguMDc2MDUgMTAuNTkyNyA3LjQwMjQ3IDExLjM2MDUgNi44MTE5QzEyLjQ0NzEgNi4xMDM5OCAxMy42MjA5IDUuOTMyNjQgMTQuODgyIDYuMjk3OTVDMTUuMzI4MSA2LjQzNjM4IDE1LjcxMjcgNi42NjYxIDE2LjAzNTYgNi45ODcxMUMxNi4xNzY1IDcuMjE3ODkgMTYuMTA3NyA3LjMxNTI0IDE1LjgyOTIgNy4yNzkxMkMxNC4yNjE5IDYuNTMyMTkgMTIuODEyOCA2LjY5NTcyIDExLjQ4MTkgNy43Njk3MUMxMC45MTYyIDguMjkwNDEgMTAuMzk0IDguODQ3MTkgOS45MTU0MyA5LjQ0MDA1QzkuMjUyODcgMTAuMzE4OCA4LjU4MDk0IDExLjE5MDkgNy44OTk2NiAxMi4wNTY1QzcuMjk4MjYgMTIuNzk4OCA2LjU0OTQ0IDEzLjM1NTUgNS42NTMxNyAxMy43MjY5QzMuODQwMDcgMTQuMzAzNCAyLjI4MTcyIDEzLjkyNTcgMC45NzgwNDggMTIuNTkzOEMwLjE0MDE2OCAxMS42MDk4IC0wLjE1OTM1NSAxMC40ODg1IDAuMDc5NDUyNCA5LjIyOThDMC40NDQwNjQgNy43MDMzMiAxLjM5NTI5IDYuNjg3MSAyLjkzMzEgNi4xODExNEMzLjE5Mjg0IDYuMTE3NTUgMy40NTE4OCA2LjA2NjkyIDMuNzEwMjYgNi4wMjkyOVoiIGZpbGw9IiMxOTFBMUEiLz4KPHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xNy4yNjIxIDkuNzQzNzdDMTcuMzAyIDkuNzM4NzcgMTcuMzM4NCA5Ljc0NjU4IDE3LjM3MTQgOS43NjcxNEMxNy40MDIzIDkuODE4OTMgMTcuNDI2NiA5Ljg3MzQ1IDE3LjQ0NDIgOS45MzA2N0MxNy40ODQ5IDExLjM0MzIgMTYuOTM4NSAxMi40OTE4IDE1LjgwNDkgMTMuMzc2NUMxNC40NjkxIDE0LjE5MTYgMTMuMDkyOSAxNC4yNTM5IDExLjY3NjIgMTMuNTYzNEMxMC43MTMzIDEzLjAxMDkgOS45MjM5OCAxMi4yODI4IDkuMzA4MjkgMTEuMzc5MUM5LjI4ODQ1IDExLjI5ODEgOS4yOTY1NiAxMS4yMjAzIDkuMzMyNTggMTEuMTQ1NUM5LjM3NDUgMTEuMTIwMyA5LjQxOTAyIDExLjExNjQgOS40NjYxNiAxMS4xMzM4QzEwLjEwMTggMTEuODA4NCAxMC44MjIzIDEyLjM3NjggMTEuNjI3NiAxMi44MzkyQzEyLjQ5NzEgMTMuMzA4MiAxMy40MiAxMy40NDg0IDE0LjM5NjMgMTMuMjU5N0MxNS4zOTUyIDEyLjk4NDEgMTYuMTUyMSAxMi40MTE4IDE2LjY2NzEgMTEuNTQyNkMxNi45Njc1IDExLjAyNjIgMTcuMTQ1NiAxMC40NzMzIDE3LjIwMTQgOS44ODM5NEMxNy4yMTUzIDkuODMzOTMgMTcuMjM1NSA5Ljc4NzIgMTcuMjYyMSA5Ljc0Mzc3WiIgZmlsbD0iIzE5MUExQSIvPgo8L3N2Zz4K';
        $svg_hover_base64 = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xOS43MTUgNS4wMDE0NEMxOS44MDQ0IDQuOTk3NTkgMTkuODkzNCA1LjAwMTQ2IDE5Ljk4MjEgNS4wMTMxMkMyMC4wMTQ4IDUuMDc4MzkgMjAuMDAyNyA1LjEzNjggMTkuOTQ1NyA1LjE4ODMzQzE4LjY3NDUgNi42MzY3MSAxNy40NjgzIDguMTMxODQgMTYuMzI3IDkuNjczNzJDMTUuODk0IDEwLjI4ODcgMTUuNDczIDEwLjkxMTcgMTUuMDY0MSAxMS41NDI2QzE0Ljk4MzEgMTEuNjA4NSAxNC44OSAxMS42Mjc5IDE0Ljc4NDkgMTEuNjAxQzE0LjY1NzcgMTEuNDc4NyAxNC41NDg0IDExLjM0MjQgMTQuNDU3IDExLjE5MjJDMTMuOTkxNCAxMC40Njc5IDEzLjUzODEgOS43MzU5NSAxMy4wOTcgOC45OTYyNEMxMy4wMTAxIDguNzc4MzkgMTMuMDg3IDguNjk2NjMgMTMuMzI3NyA4Ljc1MDk0QzEzLjU3MjYgOC45MDg2OCAxMy43ODcyIDkuMDk5NDUgMTMuOTcxMyA5LjMyMzNDMTQuMjA5IDkuNjIyMDkgMTQuNDU1OSA5LjkxNDEgMTQuNzEyIDEwLjE5OTNDMTQuNzYzOCAxMC4yMTQyIDE0LjgxMjQgMTAuMjA2NCAxNC44NTc3IDEwLjE3NkMxNS4zNzA1IDkuNDcyNSAxNS44OTI2IDguNzc1NTYgMTYuNDI0MiA4LjA4NTE0QzE3LjAzODYgNy4zMTQ4IDE3LjY5ODMgNi41Nzg5MiAxOC40MDM1IDUuODc3NDlDMTguNjk3MSA1LjYwOCAxOS4wMTI4IDUuMzY2NTggMTkuMzUwNyA1LjE1MzI5QzE5LjQ3MTYgNS4wOTUyOCAxOS41OTMgNS4wNDQ2NiAxOS43MTUgNS4wMDE0NFoiIGZpbGw9IiMxOTFBMUEiLz4KPHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0zLjcxMDI2IDYuMDI5MjlDNS4wMTkzNyA1Ljk1NDUxIDYuMTYwODMgNi4zMzIxNyA3LjEzNDY0IDcuMTYyMzJDNy42NTQzOSA3LjYzMDgzIDguMTE5ODkgOC4xNDA4OCA4LjUzMTExIDguNjkyNDlDOC41ODY2NSA4Ljc2OTExIDguNjMxMTcgOC44NTA4OCA4LjY2NDY4IDguOTM3NzhDOC41NDkwNSA5LjE2MDIgOC40MDMzNCA5LjM2MjY4IDguMjI3NTMgOS41NDUxOEM4LjE5MjQ2IDkuNTYzNDIgOC4xNjAwOCA5LjU1OTUyIDguMTMwMzggOS41MzM1QzcuNjcyOTcgOC44NDAxOCA3LjExNDM5IDguMjI4ODggNi40NTQ2MiA3LjY5OTYzQzUuNjQ2MjMgNy4wNzQ0OCA0LjcyMzM0IDYuODAxOTIgMy42ODU5OCA2Ljg4MTk4QzIuMzM1MjkgNy4xMTQxNyAxLjQ0MDczIDcuODU3ODUgMS4wMDIzMyA5LjExMjk5QzAuNjI1MDk0IDEwLjYyNTggMS4wOTg2OCAxMS44MjEyIDIuNDIzMDkgMTIuNjk5QzMuODU4ODIgMTMuNDM5OCA1LjE5NDU3IDEzLjI5OTcgNi40MzAzNCAxMi4yNzg1QzYuODQwMTkgMTEuOTA3NiA3LjIyNDcyIDExLjUxNDMgNy41ODM5NCAxMS4wOTg3QzguMTc2MTYgMTAuMzUgOC43NTkwMyA5LjU5NDU5IDkuMzMyNTYgOC44MzI2NUM5LjkxNjY5IDguMDc2MDUgMTAuNTkyNyA3LjQwMjQ3IDExLjM2MDUgNi44MTE5QzEyLjQ0NzEgNi4xMDM5OCAxMy42MjA5IDUuOTMyNjQgMTQuODgyIDYuMjk3OTVDMTUuMzI4MSA2LjQzNjM4IDE1LjcxMjcgNi42NjYxIDE2LjAzNTYgNi45ODcxMUMxNi4xNzY1IDcuMjE3ODkgMTYuMTA3NyA3LjMxNTI0IDE1LjgyOTIgNy4yNzkxMkMxNC4yNjE5IDYuNTMyMTkgMTIuODEyOCA2LjY5NTcyIDExLjQ4MTkgNy43Njk3MUMxMC45MTYyIDguMjkwNDEgMTAuMzk0IDguODQ3MTkgOS45MTU0MyA5LjQ0MDA1QzkuMjUyODcgMTAuMzE4OCA4LjU4MDk0IDExLjE5MDkgNy44OTk2NiAxMi4wNTY1QzcuMjk4MjYgMTIuNzk4OCA2LjU0OTQ0IDEzLjM1NTUgNS42NTMxNyAxMy43MjY5QzMuODQwMDcgMTQuMzAzNCAyLjI4MTcyIDEzLjkyNTcgMC45NzgwNDggMTIuNTkzOEMwLjE0MDE2OCAxMS42MDk4IC0wLjE1OTM1NSAxMC40ODg1IDAuMDc5NDUyNCA5LjIyOThDMC40NDQwNjQgNy43MDMzMiAxLjM5NTI5IDYuNjg3MSAyLjkzMzEgNi4xODExNEMzLjE5Mjg0IDYuMTE3NTUgMy40NTE4OCA2LjA2NjkyIDMuNzEwMjYgNi4wMjkyOVoiIGZpbGw9IiMxOTFBMUEiLz4KPHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xNy4yNjIxIDkuNzQzNzdDMTcuMzAyIDkuNzM4NzcgMTcuMzM4NCA5Ljc0NjU4IDE3LjM3MTQgOS43NjcxNEMxNy40MDIzIDkuODE4OTMgMTcuNDI2NiA5Ljg3MzQ1IDE3LjQ0NDIgOS45MzA2N0MxNy40ODQ5IDExLjM0MzIgMTYuOTM4NSAxMi40OTE4IDE1LjgwNDkgMTMuMzc2NUMxNC40NjkxIDE0LjE5MTYgMTMuMDkyOSAxNC4yNTM5IDExLjY3NjIgMTMuNTYzNEMxMC43MTMzIDEzLjAxMDkgOS45MjM5OCAxMi4yODI4IDkuMzA4MjkgMTEuMzc5MUM5LjI4ODQ1IDExLjI5ODEgOS4yOTY1NiAxMS4yMjAzIDkuMzMyNTggMTEuMTQ1NUM5LjM3NDUgMTEuMTIwMyA5LjQxOTAyIDExLjExNjQgOS40NjYxNiAxMS4xMzM4QzEwLjEwMTggMTEuODA4NCAxMC44MjIzIDEyLjM3NjggMTEuNjI3NiAxMi44MzkyQzEyLjQ5NzEgMTMuMzA4MiAxMy40MiAxMy40NDg0IDE0LjM5NjMgMTMuMjU5N0MxNS4zOTUyIDEyLjk4NDEgMTYuMTUyMSAxMi40MTE4IDE2LjY2NzEgMTEuNTQyNkMxNi45Njc1IDExLjAyNjIgMTcuMTQ1NiAxMC40NzMzIDE3LjIwMTQgOS44ODM5NEMxNy4yMTUzIDkuODMzOTMgMTcuMjM1NSA5Ljc4NzIgMTcuMjYyMSA5Ljc0Mzc3WiIgZmlsbD0iIzE5MUExQSIvPgo8L3N2Zz4K';
        
        $css = "
            /*
             * Completely override the dashicons approach with background-image
             */
            #toplevel_page_{$menu_slug} .wp-menu-image {
                background-image: url('{$svg_normal_base64}') !important;
                background-repeat: no-repeat !important;
                background-position: center !important;
                background-size: 20px 20px !important;
            }

            #toplevel_page_{$menu_slug} .wp-menu-image::before {
                display: none !important;
                content: none !important;
            }

            /* On hover/active, change to hover color */
            #toplevel_page_{$menu_slug}:hover .wp-menu-image,
            #toplevel_page_{$menu_slug}.current .wp-menu-image {
                background-image: url('{$svg_hover_base64}') !important;
            }
            
            /* Remove dashicons class to prevent conflicts */
            #toplevel_page_{$menu_slug} .wp-menu-image.dashicons-before {
                font-family: inherit !important;
                font-size: inherit !important;
            }
        ";

        wp_add_inline_style('wp-admin', $css);
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
            wp_die(esc_html__('upgrade_prompts.ajax.security_check_failed', 'order-daemon'));
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('admin.insight_dashboard.permission.insufficient_permissions', 'order-daemon'));
        }

        // Check if user has access to insight dashboard (defensive check)
        if (function_exists('odcm_can_use') && !odcm_can_use('insight_dashboard')) {
            wp_send_json_error(__('admin.insight_dashboard.ajax.feature_not_available', 'order-daemon'));
        }

        try {
            $is_welcome_scenario = $this->determine_welcome_scenario();

            wp_send_json_success([
                    'is_welcome_scenario' => $is_welcome_scenario
            ]);
        } catch (\Exception $e) {
            error_log('ODCM: Error checking welcome scenario: ' . $e->getMessage());
            wp_send_json_error(__('admin.insight_dashboard.ajax.failed_welcome_scenario_check', 'order-daemon'));
        }
    }

    /**
     * Determine if this is a welcome scenario (no logs available)
     *
     * @return bool True if no log entries exist in the system
     */
    private function determine_welcome_scenario(): bool
    {
        global $wpdb;
        
        // Check if audit log table exists
        $audit_log_table = $wpdb->prefix . 'odcm_audit_log';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$audit_log_table}'") === $audit_log_table;
        
        if (!$table_exists) {
            // If table doesn't exist, it's definitely a welcome scenario
            return true;
        }
        
        // Check if any log entries exist
        $log_count = $wpdb->get_var("SELECT COUNT(*) FROM {$audit_log_table}");
        
        // If no logs exist, show welcome scenario
        return (int) $log_count === 0;
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
                <?php $this->componentRenderers['unified_header']->renderWithContext($context); ?>
            </div>

            <!-- Content Grid -->
            <div class="odcm-content-grid">
                <!-- Filter Pane -->
                <div class="odcm-filter-pane">
                    <?php $this->componentRenderers['filter_pane']->renderWithContext($context); ?>
                <!--</div> DO NOT UNCOMMENT THIS DIV - IT WILL BREAK UI -->

                <!-- Log Stream -->
                <div class="odcm-log-stream">
                    <?php $this->componentRenderers['log_stream']->renderWithContext($context); ?>
                </div>

                <!-- Detail Pane -->
                <div class="odcm-detail-pane">
                    <?php $this->componentRenderers['detail_pane']->renderWithContext($context); ?>
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
            wp_die(esc_html__('admin.insight_dashboard.permission.insufficient_permissions', 'order-daemon'));
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
                                title="<?php echo esc_attr__('admin.insight_dashboard.pane.close', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                        </button>
                        
                        <!-- Right arrow: open last opened pane (visible only when pane is closed) -->
                        <button type="button"
                                class="odcm-pane-icon-button"
                                x-show="!filterPaneVisible"
                                @click="openLastOpenedPane()"
                                title="<?php echo esc_attr__('admin.insight_dashboard.pane.open_last', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                        
                        <!-- Filters tab button -->
                        <button type="button"
                                class="odcm-pane-icon-button"
                                @click="showFiltersPane()"
                                :aria-pressed="activeFilterTab === 'filters' && filterPaneVisible"
                                title="<?php echo esc_attr__('admin.insight_dashboard.filters', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-search"></span>
                        </button>
                        
                        <!-- Settings tab button -->
                        <button type="button"
                                class="odcm-pane-icon-button"
                                @click="showSettingsPane()"
                                :aria-pressed="activeFilterTab === 'settings' && filterPaneVisible"
                                title="<?php echo esc_attr__('admin.insight_dashboard.settings.title', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </button>

                        <!-- Documentation link -->
                        <a href="<?php echo esc_url(ODCM_DOCS_URL); ?>"
                           target="_blank"
                           class="odcm-docs-link"
                           title="<?php echo esc_attr__('admin.insight_dashboard.docs.view_documentation', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-editor-help"></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Log Stream Header -->
            <div class="odcm-unified-header-section odcm-unified-header-stream">
                <div class="odcm-stream-header-content">
                    <h3><?php echo esc_html__('admin.insight_dashboard.stream.title', 'order-daemon'); ?></h3>
                </div>
                <div class="odcm-stream-controls">
                    <div class="odcm-stream-view-toggle" role="group" aria-label="Toggle view mode">
                        <span class="odcm-toggle-label"
                              :class="{ 'is-active': viewMode === 'consolidated' }">
                            <?php echo esc_html__('Consolidated', 'order-daemon'); ?>
                        </span>
                        <label class="odcm-toggle-switch" @click.stop>
                            <input type="checkbox"
                                   :checked="viewMode === 'flat'"
                                   @change="setViewMode($event.target.checked ? 'flat' : 'consolidated')">
                            <span class="odcm-toggle-slider"></span>
                        </label>
                        <span class="odcm-toggle-label"
                              :class="{ 'is-active': viewMode === 'flat' }"
                              title="<?php echo esc_attr__('Shows all events ungrouped, in strict chronological order', 'order-daemon'); ?>">
                            Flat Stream (VERBOSE!)
                        </span>
                    </div>
                    <div class="odcm-refresh-controls">
                        <button type="button" 
                                class="odcm-refresh-button button"
                                @click="manualRefresh()"
                                :disabled="loading">
                            <span class="dashicons dashicons-update" :class="{ 'is-spinning': isRefreshing }"></span>
                            <span class="odcm-button-text" x-text="autoRefreshEnabled ? '<?php echo esc_js(__('Refresh', 'order-daemon')); ?>' : '<?php echo esc_js(__('Refresh', 'order-daemon')); ?>'"></span>
                        </button>
                        <span x-text="autoRefreshEnabled ? '<?php echo esc_html__('every', 'order-daemon'); ?>' : ''"></span>

                        <template x-if="autoRefreshEnabled">
                            <input type="number" 
                                    x-model="refreshInterval" 
                                    min="1" 
                                    max="60" 
                                    class="odcm-interval-input"
                                    @click.stop>
                        </template>
                        <template x-if="autoRefreshEnabled">
                            <span><?php echo esc_html__('seconds', 'order-daemon'); ?></span>
                        </template>

                        <label class="odcm-toggle-switch">
                            <input type="checkbox" x-model="autoRefreshEnabled">
                            <span class="odcm-toggle-slider"></span>
                            <span class="odcm-toggle-label"><?php echo esc_html__('admin.insight_dashboard.actions.auto_refresh', 'order-daemon'); ?></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Detail Header -->
            <div class="odcm-unified-header-section odcm-unified-header-details" x-show="selectedLog">
                <h3><?php echo esc_html__('admin.insight_dashboard.detail_pane.events_timeline', 'order-daemon'); ?></h3>
                <div class="odcm-detail-pane-header-actions">
                    <button type="button" 
                            class="odcm-detail-pane-expand-toggle"
                            @click="toggleDetailPaneExpansion()"
                            :title="detailPaneExpanded ? '<?php echo esc_attr__('admin.insight_dashboard.detail_pane.contract_details_pane', 'order-daemon'); ?>' : '<?php echo esc_attr__('admin.insight_dashboard.detail_pane.expand_details_pane', 'order-daemon'); ?>'">
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
                    <label for="filter-search"><?php echo esc_html__('admin.insight_dashboard.filters.search.label', 'order-daemon'); ?></label>
                    <input type="text" 
                           id="filter-search"
                           x-model="filters.search"
                           placeholder="<?php echo esc_attr__('admin.insight_dashboard.filters.search.placeholder', 'order-daemon'); ?>"
                           class="regular-text">
                </div>

                <!-- Premium Filters Group -->
                <div class="odcm-premium-filter-group" :class="{ 'is-disabled': !canUsePremiumFilters }">
                    <div class="odcm-premium-badge odcm-premium-badge--absolute"><?php echo esc_html__('admin.insight_dashboard.premium.badge', 'order-daemon'); ?></div>
                    <div class="odcm-premium-overlay" x-show="!canUsePremiumFilters" @click.prevent.stop="if (config?.upgrade?.enabled) { showToast(config.upgrade.message, 'info'); }" :title="config?.upgrade?.enabled ? config.upgrade.message : ''" aria-hidden="true"></div>
                    
                    <!-- Status Filter -->
                    <div class="odcm-filter-group">
                        <label for="filter-status"><?php echo esc_html__('admin.insight_dashboard.filters.status.label', 'order-daemon'); ?></label>
                        <select id="filter-status" 
                                x-model="filters.status"
                                :disabled="!canUsePremiumFilters">
                            <option value=""><?php echo esc_html__('admin.insight_dashboard.filters.status.all', 'order-daemon'); ?></option>
                            <option value="success"><?php echo esc_html__('status.success', 'order-daemon'); ?></option>
                            <option value="error"><?php echo esc_html__('status.error', 'order-daemon'); ?></option>
                            <option value="warning"><?php echo esc_html__('status.warning', 'order-daemon'); ?></option>
                            <option value="info"><?php echo esc_html__('status.info', 'order-daemon'); ?></option>
                        </select>
                    </div>

                    <!-- Event Type Filter -->
                    <div class="odcm-filter-group">
                        <label for="filter-event-type"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.label', 'order-daemon'); ?></label>
                        <select id="filter-event-type" 
                                x-model="filters.event_type"
                                :disabled="!canUsePremiumFilters">
                            <option value=""><?php echo esc_html__('admin.insight_dashboard.filters.event_type.all', 'order-daemon'); ?></option>
                            <option value="rule_check"><?php echo esc_html__('', 'order-daemon'); ?></option>
                            <option value="order_completion"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.order_completion', 'order-daemon'); ?></option>
                            <option value="manual_trigger"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.manual_trigger', 'order-daemon'); ?></option>
                            <option value="scheduled_task"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.scheduled_task', 'order-daemon'); ?></option>
                            <option value="webhook_received"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.webhook_received', 'order-daemon'); ?></option>
                            <option value="error_occurred"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.error_occurred', 'order-daemon'); ?></option>
                        </select>
                    </div>

                    <!-- Source Filter -->
                    <div class="odcm-filter-group">
                        <label for="filter-source"><?php echo esc_html__('admin.insight_dashboard.filters.source.label', 'order-daemon'); ?></label>
                        <select id="filter-source" 
                                x-model="filters.source"
                                :disabled="!canUsePremiumFilters">
                            <option value=""><?php echo esc_html__('admin.insight_dashboard.filters.source.all', 'order-daemon'); ?></option>
                            <option value="manual"><?php echo esc_html__('admin.insight_dashboard.filters.source.manual', 'order-daemon'); ?></option>
                            <option value="scheduled"><?php echo esc_html__('admin.insight_dashboard.filters.source.scheduled', 'order-daemon'); ?></option>
                            <option value="webhook"><?php echo esc_html__('admin.insight_dashboard.filters.source.webhook', 'order-daemon'); ?></option>
                            <option value="api"><?php echo esc_html__('admin.insight_dashboard.filters.source.api', 'order-daemon'); ?></option>
                            <option value="system"><?php echo esc_html__('admin.insight_dashboard.filters.source.system', 'order-daemon'); ?></option>
                        </select>
                    </div>

                    <!-- Date Range Filter -->
                    <div class="odcm-filter-group">
                        <label><?php echo esc_html__('admin.insight_dashboard.filters.date_range.label', 'order-daemon'); ?></label>
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
                            <label for="filter-include-tests"><?php echo esc_html__('admin.insight_dashboard.filters.include_test_logs', 'order-daemon'); ?></label>
                        </div>
                        <div class="odcm-checkbox-item">
                            <input type="checkbox" 
                                   id="filter-include-debug"
                                   x-model="filters.include_debug">
                            <label for="filter-include-debug"><?php echo esc_html__('Include Debug Logs', 'order-daemon'); ?></label>
                        </div>
                    </div>
                </div>

                <!-- Filter Actions -->
                <div class="odcm-filter-actions">
                    <button type="submit" class="button button-primary">
                        <?php echo esc_html__('admin.insight_dashboard.filters.apply_filters', 'order-daemon'); ?>
                    </button>
                    <button type="button" class="button" @click="clearFilters()">
                        <?php echo esc_html__('admin.insight_dashboard.filters.clear_all', 'order-daemon'); ?>
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
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('admin.insight_dashboard.settings.display_options.title', 'order-daemon'); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('admin.insight_dashboard.settings.display_options.description', 'order-daemon'); ?></p>
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
                            <label class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.timestamp_format.label', 'order-daemon'); ?></label>
                            <div class="odcm-setting-row-pair">
                                <p class="description"><?php echo esc_html__('admin.insight_dashboard.settings.timestamp_format.description', 'order-daemon'); ?></p>
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
                            <label for="odcm_logs_per_page" class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.entries_per_page.label', 'order-daemon'); ?></label>
                            <div class="odcm-setting-row-pair">
                                <p class="description"><?php echo esc_html__('admin.insight_dashboard.settings.entries_per_page.description', 'order-daemon'); ?></p>
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
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('admin.insight_dashboard.settings.order_processing.title', 'order-daemon'); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('admin.insight_dashboard.settings.order_processing.description', 'order-daemon'); ?></p>
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
                            <label class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.reprocess_orders.label', 'order-daemon'); ?></label>
                            <p class="description"><?php echo esc_html__('admin.insight_dashboard.settings.reprocess_orders.description', 'order-daemon'); ?></p>
                            <button type="button" 
                                    class="odcm-refresh-button button"
                                    @click="reprocessPendingOrders()"
                                    :disabled="isReprocessing">
                                <span class="dashicons dashicons-update" :class="{ 'is-spinning': isReprocessing }"></span>
                                <span class="odcm-button-text" x-text="isReprocessing ? '<?php echo esc_js(__('admin.insight_dashboard.ajax.processing', 'order-daemon')); ?>' : '<?php echo esc_js(__('admin.insight_dashboard.settings.reprocess_orders.label', 'order-daemon')); ?>'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>


            <?php
            // Allow pro plugin to add additional settings sections
            do_action('odcm_insight_dashboard_settings_sections');
            ?>

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
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('admin.insight_dashboard.settings.debug_settings.title', 'order-daemon'); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('admin.insight_dashboard.settings.debug_settings.description', 'order-daemon'); ?></p>
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
                                <?php echo esc_html__('admin.insight_dashboard.settings.global_debug_mode.label', 'order-daemon'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('admin.insight_dashboard.settings.global_debug_mode.description', 'order-daemon'); ?>
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
                                <?php echo esc_html__('admin.insight_dashboard.settings.detailed_notes.label', 'order-daemon'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('admin.insight_dashboard.settings.detailed_notes.description', 'order-daemon'); ?>
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
                        <span class="odcm-settings-accordion-label"><?php echo esc_html__('admin.insight_dashboard.settings.data_management.title', 'order-daemon'); ?></span>
                        <p class="odcm-settings-accordion-description"><?php echo esc_html__('admin.insight_dashboard.settings.data_management.description', 'order-daemon'); ?></p>
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
                        <?php
                        $is_premium = (bool) apply_filters('odcm_is_premium_user', false);
                        $pro_plugin_active = defined('ODCM_PRO_VERSION');
                        $pro_plugin_installed = file_exists(WP_PLUGIN_DIR . '/order-daemon-pro/order-daemon-pro.php');
                        ?>
                        
                        <!-- Export Logs Feature -->
                        <?php if (!$is_premium): ?>
                            <div class="odcm-setting-row odcm-premium-notice-small">
                                <label class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.export_logs.label', 'order-daemon'); ?></label>
                                <p><?php echo esc_html__('admin.insight_dashboard.settings.export_logs.description', 'order-daemon'); ?></p>
                                <a href="https://orderdaemon.com/pricing" class="button-secondary odcm-button-small" target="_blank">
                                    <?php echo esc_html__('Upgrade to Pro', 'order-daemon'); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Premium Export Logs Feature - Active -->
                            <div class="odcm-setting-row">
                                <label class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.export_logs.label', 'order-daemon'); ?></label>
                                <p class="description"><?php echo esc_html__('admin.insight_dashboard.settings.export_logs.description', 'order-daemon'); ?></p>
                                <div class="odcm-export-controls">
                                    <button type="button" 
                                            class="button"
                                            @click="exportLogs('csv')"
                                            :disabled="isExporting && exportFormat === 'csv'">
                                        <span class="dashicons dashicons-download"></span>
                                        <span x-text="isExporting && exportFormat === 'csv' ? '<?php echo esc_js(__('admin.insight_dashboard.ajax.exporting', 'order-daemon')); ?>' : '<?php echo esc_js(__('Export CSV', 'order-daemon')); ?>'"></span>
                                    </button>
                                    <button type="button" 
                                            class="button"
                                            @click="exportLogs('json')"
                                            :disabled="isExporting && exportFormat === 'json'">
                                        <span class="dashicons dashicons-download"></span>
                                        <span x-text="isExporting && exportFormat === 'json' ? '<?php echo esc_js(__('admin.insight_dashboard.ajax.exporting', 'order-daemon')); ?>' : '<?php echo esc_js(__('Export JSON', 'order-daemon')); ?>'"></span>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Log Retention Policy Feature -->
                        <?php if (!$is_premium): ?>
                            <div class="odcm-setting-row odcm-danger-section">
                                <label class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.log_retention.label', 'order-daemon'); ?></label>
                                <p class="description"><?php echo esc_html__('admin.insight_dashboard.settings.log_retention.description', 'order-daemon'); ?></p>
                                <a href="https://orderdaemon.com/pricing" class="button-secondary odcm-button-small" target="_blank">
                                    <?php echo esc_html__('Upgrade to Pro', 'order-daemon'); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Premium Log Retention Policy Feature - Active -->
                            <div class="odcm-setting-row odcm-danger-section">
                                <label class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.log_retention.label', 'order-daemon'); ?></label>
                                <p class="description"><?php echo esc_html__('admin.insight_dashboard.settings.log_retention.description', 'order-daemon'); ?></p>
                                <div class="odcm-retention-controls">
                                    <div class="odcm-retention-setting">
                                        <label for="retention-days"><?php echo esc_html__('Retention Period:', 'order-daemon'); ?></label>
                                        <input type="number" 
                                               id="retention-days"
                                               x-model="retentionDays"
                                               min="1" 
                                               max="365" 
                                               class="small-text"
                                               @click.stop>
                                        <span><?php echo esc_html__('days', 'order-daemon'); ?></span>
                                        <button type="button" 
                                                class="button button-small"
                                                @click="updateRetentionPolicy()"
                                                :disabled="isUpdatingRetention">
                                            <span x-text="isUpdatingRetention ? '<?php echo esc_js(__('admin.insight_dashboard.ajax.updating', 'order-daemon')); ?>' : '<?php echo esc_js(__('Update Policy', 'order-daemon')); ?>'"></span>
                                        </button>
                                    </div>
                                    <div class="odcm-cleanup-section">
                                        <button type="button" 
                                                class="button button-secondary odcm-danger-button"
                                                @click="cleanupOldLogs()"
                                                :disabled="isCleaningUp">
                                            <span class="dashicons dashicons-trash"></span>
                                            <span x-text="isCleaningUp ? '<?php echo esc_js(__('admin.insight_dashboard.ajax.cleaning', 'order-daemon')); ?>' : '<?php echo esc_js(__('Cleanup Now', 'order-daemon')); ?>'"></span>
                                        </button>
                                        <p class="description"><?php echo esc_html__('This will remove all event logs older than the retention period.', 'order-daemon'); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
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
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_rest')) {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.security_check_failed', 'order-daemon')]);
        }

        // Get and validate input
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 0;

        // Validate range
        if ($per_page < 10 || $per_page > 200) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.invalid_per_page_range', 'order-daemon')]);
        }

        // Update user meta
        $user_id = get_current_user_id();
        $updated = update_user_meta($user_id, 'odcm_logs_per_page', $per_page);

        if ($updated !== false) {
            wp_send_json_success([
                /* translators: %d: The number of log entries to show per page. */
                'message' => sprintf(__('admin.insight_dashboard.ajax.per_page_updated', 'order-daemon'), $per_page),
                'per_page' => $per_page
            ]);
        } else {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.failed_to_update_setting', 'order-daemon')]);
        }
    }

    /**
     * Handle AJAX request to save debug settings
     */
    public function handle_debug_settings_ajax(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_rest')) {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.security_check_failed', 'order-daemon')]);
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
            'message' => __('admin.insight_dashboard.ajax.debug_settings_saved', 'order-daemon'),
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
        $context = [
            'action_type' => 'debug_mode_change',
            'debug_enabled' => $enabled,
            'source' => 'insight_dashboard',
            'user_id' => get_current_user_id(),
            'user_login' => wp_get_current_user()->user_login,
            'timestamp' => current_time('mysql'),
        ];

        // Generate dynamic source label from context
        $source_label = $this->get_debug_source_label($context['source']);

        $message = sprintf(
            'Global debug mode %s via %s',
            $enabled ? 'enabled' : 'disabled',
            $source_label
        );

        // Use Order Daemon custom event logging if available
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                $message,
                $context,
                null, // No order ID
                'debug', // Debug level
                'insight_debug_toggle' // Event type
            );
        }
    }

    /**
     * Get human-readable label for debug source
     * 
     * @param string $source The technical source identifier
     * @return string Human-readable source label
     */
    private function get_debug_source_label(string $source): string
    {
        // Map known public source values to human-readable labels
        // Exclude internal dev tools like 'dev_toolbar' since that's not for public use
        $source_labels = apply_filters('odcm_debug_source_labels', [
            'insight_dashboard' => 'Insight Dashboard',
            'api' => 'API',
            'cli' => 'CLI',
            'webhook' => 'Webhook',
            // 3rd party devs can add their sources via the filter
        ]);

        // Get label from map, or auto-format the source value
        if (isset($source_labels[$source])) {
            return $source_labels[$source];
        } else {
            // Auto-format: 'my_custom_source' -> 'My Custom Source'
            // Convert underscores and hyphens to spaces, then apply title case
            return ucwords(str_replace(['_', '-'], ' ', $source));
        }
    }



    /**
     * Handle AJAX request to reprocess pending orders
     */
    public function handle_reprocess_pending_orders_ajax(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wp_rest')) {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.security_check_failed', 'order-daemon')]);
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
                        'admin.insight_dashboard.ajax.reprocess_success_singular',
                        'admin.insight_dashboard.ajax.reprocess_success_plural',
                        $count,
                        'order-daemon'
                    ),
                    $count
                ),
                'count' => $count
            ]);
            
        } catch (\Exception $e) {
            // Log the error
            error_log('ODCM: Error in reprocess pending orders AJAX: ' . $e->getMessage());
            
            wp_send_json_error([
                'message' => __('admin.insight_dashboard.ajax.failed_reprocess_orders', 'order-daemon')
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
                        <?php echo esc_html__('admin.insight_dashboard.actions.retry', 'order-daemon'); ?>
                    </button>
                </div>
            </template>

            <!-- Empty State with Context Awareness - Only render when appropriate -->
            <template x-if="!loading && !error && logs.length === 0 && !initialLoad">
                <div class="odcm-empty-state">
                    <!-- Filtered Empty State (No results match current filters/search) -->
                    <template x-if="hasActiveFilters">
                        <div class="odcm-filtered-empty-state">
                            <span class="dashicons dashicons-filter"></span>
                            <h4><?php echo esc_html__('No results match your current filters', 'order-daemon'); ?></h4>
                            <p><?php echo esc_html__('Try adjusting your filters or clear them to see all activity.', 'order-daemon'); ?></p>
                            <div class="odcm-empty-actions">
                                <button type="button" class="button" @click="clearFilters()">
                                    <?php echo esc_html__('Clear filters', 'order-daemon'); ?>
                                </button>
                                <button type="button" class="button button-secondary" @click="fetchLogs()">
                                    <?php echo esc_html__('Refresh', 'order-daemon'); ?>
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Welcome State (Fresh Installation) -->
                    <template x-if="!hasActiveFilters && isWelcomeScenario">
                        <div class="odcm-welcome-state">
                            <div class="odcm-welcome-icon">
                                <span class="dashicons dashicons-chart-line"></span>
                            </div>
                            <div class="odcm-welcome-text">
                                <h3><?php echo esc_html__('admin.insight_dashboard.welcome.title', 'order-daemon'); ?></h3>
                                <p><?php echo esc_html__('admin.insight_dashboard.welcome.description', 'order-daemon'); ?></p>
                           </div>

                            <div class="odcm-welcome-steps">
                                <h4><?php echo esc_html__('admin.insight_dashboard.welcome.steps.title', 'order-daemon'); ?></h4>
                                <ol>
                                    <li><strong><?php echo esc_html__('admin.insight_dashboard.welcome.steps.create_rule', 'order-daemon'); ?></strong> <?php echo esc_html__('admin.insight_dashboard.welcome.steps.create_rule_location', 'order-daemon'); ?></li>
                                    <li><strong><?php echo esc_html__('admin.insight_dashboard.welcome.steps.place_order', 'order-daemon'); ?></strong> <?php echo esc_html__('admin.insight_dashboard.welcome.steps.place_order_description', 'order-daemon'); ?></li>
                                    <li><strong><?php echo esc_html__('admin.insight_dashboard.welcome.steps.return_here', 'order-daemon'); ?></strong> <?php echo esc_html__('admin.insight_dashboard.welcome.steps.return_here_description', 'order-daemon'); ?></li>
                                </ol>
                            </div>

                            <div class="odcm-welcome-actions">
                                <a href="<?php echo esc_url(admin_url('edit.php?post_type=odcm_order_rule')); ?>" class="button button-primary">
                                    <?php echo esc_html__('admin.insight_dashboard.welcome.actions.create_first_rule', 'order-daemon'); ?>
                                </a>
                                <a href="https://orderdaemon.com/docs" target="_blank" class="button button-secondary odcm-docs-link">
                                    <?php echo esc_html__('admin.insight_dashboard.docs.view_documentation', 'order-daemon'); ?>
                                </a>
                            </div>

                            <div class="odcm-welcome-note">
                                <p><em>💡 <?php echo esc_html__('admin.insight_dashboard.welcome.tip', 'order-daemon'); ?></em></p>
                            </div>
                        </div>
                    </template>

                    <!-- Regular Empty State (Rules exist, no recent activity) -->
                    <template x-if="!hasActiveFilters && !isWelcomeScenario">
                        <div class="odcm-regular-empty-state">
                            <span class="dashicons dashicons-admin-post"></span>
                            <h4><?php echo esc_html__('admin.insight_dashboard.empty.no_activity.title', 'order-daemon'); ?></h4>
                            <p><?php echo esc_html__('admin.insight_dashboard.empty.no_activity.description', 'order-daemon'); ?></p>
                            <div class="odcm-empty-actions">
                                <button type="button" class="button" @click="fetchLogs()">
                                    <?php echo esc_html__('Refresh', 'order-daemon'); ?>
                                </button>
                                <a href="<?php echo esc_url(admin_url('edit.php?post_type=odcm_order_rule')); ?>" class="button button-secondary">
                                    <?php echo esc_html__('admin.insight_dashboard.empty.manage_rules', 'order-daemon'); ?>
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
                                <?php echo esc_html__('admin.insight_dashboard.log_stream.select_all', 'order-daemon'); ?>
                            </label>
                        </div>
                        <div class="odcm-batch-actions" x-show="hasSelection">
                            <span class="odcm-selection-count" x-text="selectedCount + ' <?php echo esc_js(__('selected', 'order-daemon')); ?>'"></span>
                            <button type="button" 
                                    class="odcm-delete-selected button button-secondary"
                                    @click="deleteSelectedLogs()"
                                    :disabled="isDeleting">
                                <span class="dashicons dashicons-trash" :class="{ 'is-spinning': isDeleting }"></span>
                                <span x-text="isDeleting ? '<?php echo esc_js(__('admin.insight_dashboard.log_stream.deleting', 'order-daemon')); ?>' : '<?php echo esc_js(__('admin.insight_dashboard.log_stream.delete_selected', 'order-daemon')); ?>'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <template x-for="(log, index) in logs" :key="log?.id || ('invalid-' + index)">
                    <div x-show="log && log.id"
                         :class="log ? getLogEntryClasses(log) : 'odcm-log-entry'">
                        
                        <div class="odcm-log-entry-checkbox" x-show="log && log.id">
                            <input type="checkbox" 
                                   :id="'log-checkbox-' + (log?.id || 'invalid')"
                                   :checked="log?.id && isLogSelected(log.id)"
                                   @change="log?.id && toggleLogSelection(log.id)"
                                   @click.stop
                                   class="odcm-log-checkbox">
                            <label :for="'log-checkbox-' + (log?.id || 'invalid')" class="odcm-log-checkbox-label">
                                <span class="screen-reader-text"><?php echo esc_html__('admin.insight_dashboard.log_stream.select_log_entry', 'order-daemon'); ?></span>
                            </label>
                        </div>
                        
                        <div class="odcm-log-entry-content" @click="log && selectLog(log)">
                            <div class="odcm-log-entry-header">
                                <div class="odcm-log-timestamp" x-text="formatTimestamp(log?.timestamp)"></div>
                                <div x-show="log?.order_id">
                                    Order #<span x-text="log.order_id"></span>
                                </div>
                                <div class="odcm-log-summary">
                                    <span x-text="log?.summary || '<?php echo esc_js(__('admin.insight_dashboard.log_stream.no_summary', 'order-daemon')); ?>'"></span>
                                </div>
                                <span class="odcm-status-pill" 
                                      :class="'odcm-status-pill--' + ((log?.status && typeof log.status === 'string') ? log.status.toLowerCase() : 'unknown')"
                                      x-text="log?.status || '<?php echo esc_js(__('admin.insight_dashboard.log_stream.unknown_status', 'order-daemon')); ?>'"></span>
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
                            <?php echo esc_html__('admin.insight_dashboard.pagination.of', 'order-daemon'); ?>
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

}
