<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use OrderDaemon\CompletionManager\Includes\Odcm_Config;
use OrderDaemon\CompletionManager\View\DashboardComponents\DashboardComponentUIToolkit;
use OrderDaemon\CompletionManager\View\DashboardComponents\UnifiedHeaderRenderer;
use OrderDaemon\CompletionManager\View\DashboardComponents\FilterPaneRenderer;
use OrderDaemon\CompletionManager\View\DashboardComponents\LogStreamRenderer;
use OrderDaemon\CompletionManager\View\DashboardComponents\DetailPaneRenderer;
use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;

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
 * Permission Model:
 * - Dashboard access and viewing: view_woocommerce_reports (Shop Manager + Administrator)
 * - Settings changes: manage_woocommerce (Administrator only)
 * - Destructive operations (reprocess, export, delete): manage_woocommerce (Administrator only)
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
     * Constructor
     */
    public function __construct()
    {
        // Clean constructor - no fragile export handling here
    }

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
        add_action('wp_ajax_odcm_save_uninstall_data_setting', [$this, 'handle_uninstall_data_setting_ajax']);
        add_action('wp_ajax_odcm_reprocess_pending_orders', [$this, 'handle_reprocess_pending_orders_ajax']);

        // Register AJAX handlers for onboarding
        add_action('wp_ajax_odcm_check_welcome_scenario', [$this, 'handle_welcome_scenario_check']);

        // Register AJAX handler for Alpine.js failure logging
        add_action('wp_ajax_odcm_log_alpine_failure', [$this, 'handle_log_alpine_failure_ajax']);
    }

    /**
     * Apply debug override early in the WordPress lifecycle
     * 
     * Uses the standard debug system for consistency with ODCM_DEBUG constant.
     */
    private function apply_debug_override(): void
    {
        // Use the standard debug option that syncs with ODCM_DEBUG constant
        $debug_override = get_option('odcm_debug', null);
        if ($debug_override !== null) {
            $is_debug_enabled = (bool) $debug_override;
            
            // Set global variable for runtime override capability
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
     * 
     * Uses view_woocommerce_reports capability to allow Shop Managers access to reports.
     */
    public function register_menu_page(): void
    {
        // 1. First, add the main top-level menu (Order Daemon) pointing to the dashboard
        // WooCommerce uses position 55-56, Products around 57, so we use 56.5
        // Use view_woocommerce_reports to allow Shop Managers access (WooCommerce standard for reports)
        add_menu_page(
            __('admin.insight_dashboard.menu.title', 'order-daemon'),
            __('admin.insight_dashboard.menu.title', 'order-daemon'),
            'view_woocommerce_reports',
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
            'view_woocommerce_reports',
            self::PAGE_SLUG,
            [$this, 'render_dashboard_page']
        );

        // 3. Then add remaining submenu items
        // Keep manage_woocommerce for order rules management (requires admin)
        add_submenu_page(
                self::PAGE_SLUG,
                __('admin.insight_dashboard.submenu.all_order_rules', 'order-daemon'),
                __('admin.insight_dashboard.filter.all_order_rules', 'order-daemon'),
                'manage_woocommerce',
                'edit.php?post_type=odcm_order_rule',
                null
        );

        // Add "Diagnostics" as third submenu item
        // Keep manage_woocommerce for diagnostics (requires admin)
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

        // Alpine.js fallback system - enhanced detection and user-friendly error handling
        wp_add_inline_script(
            'alpine-js',
            $this->get_alpine_fallback_script(),
            'after'
        );

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

        // Dashboard-specific CSS - use filemtime for cache busting during development/updates
        $css_path = defined('ODCM_PLUGIN_DIR') ? ODCM_PLUGIN_DIR . 'assets/css/insight-dashboard.css' : '';
        $css_version = (file_exists($css_path)) ? filemtime($css_path) : $plugin_version;
        wp_enqueue_style(
            'odcm-insight-dashboard',
            $assets_url . 'css/insight-dashboard.css',
            ['odcm-prism-css', 'odcm-design-system'], // Depend on Prism.js CSS and design system
            $css_version
        );

        // Dashboard JavaScript with Alpine.js app - depends on Alpine.js, Prism.js, and shared toasts
        // Use filemtime for cache busting during development/updates
        $js_path = defined('ODCM_PLUGIN_DIR') ? ODCM_PLUGIN_DIR . 'assets/js/insight-dashboard.js' : '';
        $js_version = (file_exists($js_path)) ? filemtime($js_path) : $plugin_version;

        wp_enqueue_script(
            'odcm-insight-dashboard-js',
            $assets_url . 'js/insight-dashboard.js',
            ['alpine-js', 'odcm-prism-js', 'odcm-shared-toasts'], // Ensure Alpine, Prism, and toasts load first
            $js_version,
            false // Load in head to ensure registration before Alpine processes DOM
        );

        // CSS loading validation and emergency fallback styles
        wp_add_inline_script(
            'odcm-insight-dashboard-js',
            "(function(){try{var r=document.documentElement;var v=getComputedStyle(r).getPropertyValue('--odcm-theme-grey-100');if(!v||v.trim()===''){var s=document.createElement('style');s.setAttribute('data-odcm-inline-fallback','1');s.textContent='/* ODCM minimal fallback */ .odcm-insight-dashboard{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;color:#222;background:#fff;} .odcm-insight-dashboard .odcm-unified-header{position:sticky;top:0;background:#fff;border-bottom:1px solid #ddd;padding:8px;z-index:10;} .odcm-insight-dashboard .odcm-content-grid{display:flex;gap:8px;margin-top:8px;} .odcm-insight-dashboard .odcm-filter-pane,.odcm-insight-dashboard .odcm-log-stream,.odcm-insight-dashboard .odcm-detail-pane{border:1px solid #ddd;border-radius:4px;background:#fff;min-height:200px;padding:8px;flex:1;} @media(max-width:782px){.odcm-insight-dashboard .odcm-content-grid{flex-direction:column;}} .odcm-css-warning{border:1px solid #f0c36d;background:#fff8e5;color:#5f3b00;padding:8px;border-radius:4px;margin:8px 0;font-size:13px;} .odcm-badge--error{display:inline-block;background:#dc3545;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;} .odcm-advanced-filter-group input:disabled,.odcm-advanced-filter-group select:disabled{background:#eee;cursor:not-allowed;opacity:.7;}';document.head.appendChild(s);var b=document.createElement('div');b.className='odcm-css-warning';b.textContent='Order Daemon: Some styles failed to load. Using minimal fallback for safe display.';var c=document.getElementById('odcm-insight-dashboard');if(c){c.insertBefore(b,c.firstChild);}document.body.classList.add('odcm-css-fallback-mode');}}catch(e){}})();",
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
            'adminPostUrl' => admin_url('admin-post.php'),
            'nonce' => wp_create_nonce('wp_rest'),
            'restNonce' => wp_create_nonce('wp_rest'),
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
        // Verify nonce if processing GET parameter
        if (isset($_GET['page'])) {
            // Check if nonce exists and verify it if it does
            $has_nonce = isset($_REQUEST['_wpnonce']);
            $nonce_verified = $has_nonce && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'wp_rest');
            
            // Only use $_GET['page'] if we have a verified nonce or it's a direct admin page load
            if ($nonce_verified || !isset($_REQUEST['action'])) {
                $page = sanitize_key(wp_unslash($_GET['page']));
                return str_contains($hook_suffix, self::PAGE_SLUG) || $page === self::PAGE_SLUG;
            }
        }
        
        return str_contains($hook_suffix, self::PAGE_SLUG);
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
     * Get the Alpine.js fallback script for graceful degradation
     * 
     * This script provides comprehensive detection and user-friendly error handling
     * when Alpine.js fails to load due to network issues, CSP restrictions, or other problems.
     * 
     * @return string The JavaScript fallback code
     */
    private function get_alpine_fallback_script(): string
    {
        $ajax_url = esc_js(admin_url('admin-ajax.php'));
        $nonce = wp_create_nonce('wp_rest');
        
        return "(function setupODCMAlpineFallback() {
    // Only run once
    if (window.__odcmAlpineFallbackInstalled) return;
    window.__odcmAlpineFallbackInstalled = true;

    // Check if Alpine.js is available
    function checkAlpineAvailability() {
        try {
            if (typeof Alpine !== 'undefined' && typeof Alpine.data === 'function') {
                return true;
            }
            var alpineScript = document.querySelector('script[src*=\"alpine\"]');
            if (alpineScript && !alpineScript.hasAttribute('data-loaded')) {
                return 'loading';
            }
            return false;
        } catch (e) {
            return false;
        }
    }

    // Enhanced Alpine.js detection with multiple checks
    function checkAlpineJS() {
        var alpineStatus = checkAlpineAvailability();
        if (alpineStatus === true) {
            if (typeof window.ODCM_DEBUG !== 'undefined' && window.ODCM_DEBUG) {
                console.log('ODCM: Alpine.js loaded successfully');
            }
            return true;
        }
        if (alpineStatus === 'loading') {
            setTimeout(checkAlpineJS, 500);
            return false;
        }
        console.error('ODCM: Alpine.js failed to load. Dashboard interactivity will be limited.');
        showAlpineFallbackUI();
        logAlpineLoadFailure();
        return false;
    }

    // Show user-friendly fallback UI
    function showAlpineFallbackUI() {
        try {
            var dashboard = document.getElementById('odcm-insight-dashboard');
            if (!dashboard) return;
            if (dashboard.querySelector('.odcm-alpine-fallback')) return;

            var fallbackNotice = document.createElement('div');
            fallbackNotice.className = 'odcm-alpine-fallback';
            fallbackNotice.setAttribute('role', 'alert');
            fallbackNotice.setAttribute('aria-live', 'assertive');
            fallbackNotice.style.cssText = 'background:#fff8e5;border:2px solid #f0c36d;border-radius:6px;padding:20px;margin:20px;position:relative;box-shadow:0 2px 8px rgba(0,0,0,0.1);';
            fallbackNotice.innerHTML = '<div style=\"display:flex;align-items:start;gap:15px;\"><div style=\"flex-shrink:0;\"><span class=\"dashicons dashicons-warning\" style=\"color:#d63638;font-size:32px;\"></span></div><div style=\"flex:1;\"><h3 style=\"margin:0 0 10px 0;color:#d63638;\">Dashboard Loading Issue</h3><p style=\"margin:0 0 15px 0;line-height:1.5;\">The dashboard framework failed to load. This prevents interactive features from working properly.</p><div style=\"background:#fff;border:1px solid #ddd;border-radius:4px;padding:15px;margin-bottom:15px;\"><strong>Common causes:</strong><ul style=\"margin:8px 0 0 20px;padding:0;\"><li>Browser extensions blocking scripts</li><li>Content Security Policy (CSP) restrictions</li><li>Network connectivity issues</li><li>JavaScript errors from other plugins</li></ul></div><div style=\"display:flex;gap:10px;margin-top:15px;\"><button onclick=\"window.location.reload()\" style=\"padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:500;\">🔄 Refresh Page</button><button onclick=\"this.parentNode.parentNode.parentNode.parentNode.style.display=\\'none\\'\" style=\"padding:8px 16px;background:#6c757d;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:500;\">Hide This Message</button></div></div></div>';
            dashboard.insertBefore(fallbackNotice, dashboard.firstChild);
            addFallbackCSS();
        } catch (error) {
            console.error('ODCM: Error showing Alpine.js fallback UI:', error);
        }
    }

    // Add additional CSS for fallback UI
    function addFallbackCSS() {
        try {
            var styleId = 'odcm-alpine-fallback-css';
            if (document.getElementById(styleId)) return;
            var style = document.createElement('style');
            style.id = styleId;
            style.textContent = '.odcm-alpine-fallback{animation:odcm-fadeIn 0.5s ease;}@keyframes odcm-fadeIn{from{opacity:0;transform:translateY(-10px);}to{opacity:1;transform:translateY(0);}}.odcm-alpine-fallback ~ .odcm-unified-header,.odcm-alpine-fallback ~ .odcm-content-grid{opacity:0.7;pointer-events:none;user-select:none;}';
            document.head.appendChild(style);
        } catch (error) {
            console.error('ODCM: Error adding fallback CSS:', error);
        }
    }

    // Log detailed information about Alpine.js load failure
    function logAlpineLoadFailure() {
        try {
            var envInfo = {
                userAgent: navigator.userAgent,
                platform: navigator.platform,
                language: navigator.language,
                online: navigator.onLine,
                timestamp: new Date().toISOString()
            };
            var issues = [];
            var scripts = document.querySelectorAll('script');
            var alpineScriptFound = false;
            scripts.forEach(function(script) {
                if (script.src && script.src.indexOf('alpine') !== -1) {
                    alpineScriptFound = true;
                }
            });
            if (!alpineScriptFound) {
                issues.push('Alpine.js script tag not found');
            }
            try {
                var metaCSP = document.querySelector('meta[http-equiv=\"Content-Security-Policy\"]');
                if (metaCSP) {
                    issues.push('Content Security Policy (CSP) meta tag found - may be blocking Alpine.js');
                }
            } catch (e) {}
            console.groupCollapsed('ODCM Alpine.js Load Failure Details');
            console.log('Environment:', envInfo);
            console.log('Potential Issues:', issues.length > 0 ? issues : ['None detected']);
            console.groupEnd();
            if (typeof odcmInsightConfig !== 'undefined' && odcmInsightConfig.debug) {
                try {
                    fetch('" . $ajax_url . "', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'odcm_log_alpine_failure',
                            _wpnonce: '" . $nonce . "',
                            env: JSON.stringify(envInfo),
                            issues: JSON.stringify(issues)
                        })
                    }).catch(function() {});
                } catch (e) {}
            }
        } catch (error) {
            console.error('ODCM: Error in Alpine.js failure logging:', error);
        }
    }

    // Check Alpine.js availability after a delay to allow for loading
    setTimeout(checkAlpineJS, 2000);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAlpineJS);
    } else {
        checkAlpineJS();
    }
})();";
    }

    /**
     * Handle AJAX request to check welcome scenario
     */
    public function handle_welcome_scenario_check(): void
    {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_die(esc_html__('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon'));
        }

        // Check user capabilities - use WooCommerce standard for reports (allows Shop Managers)
        if (!current_user_can('view_woocommerce_reports') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('admin.insight_dashboard.permission.insufficient_permissions', 'order-daemon'));
        }

        try {
            $is_welcome_scenario = $this->determine_welcome_scenario();

            wp_send_json_success([
                    'is_welcome_scenario' => $is_welcome_scenario
            ]);
        } catch (\Exception $e) {
            // Use WordPress-friendly logging function instead of error_log
            if (function_exists('odcm_log_message')) {
                odcm_log_message('Error checking welcome scenario: ' . $e->getMessage(), 'error');
            }
            wp_send_json_error(__('admin.insight_dashboard.ajax.failed_welcome_scenario_check', 'order-daemon'));
        }
    }

    /**
     * Determine if this is a welcome scenario (no logs available)
     *
     * @return bool True if no log entries exist in the system
     */
    /**
     * Static cache for welcome scenario data
     *
     * @var bool|null
     */
    private static ?bool $welcome_scenario_cache = null;

    /**
     * Determine if this is a welcome scenario (no logs available)
     *
     * @return bool True if no log entries exist in the system
     */
    private function determine_welcome_scenario(): bool
    {
        global $wpdb;

        // Use static variable for in-memory caching during this request
        if (self::$welcome_scenario_cache !== null) {
            return self::$welcome_scenario_cache;
        }

        // Check persistent cache first
        $cache_key = 'odcm_welcome_scenario';
        $cached_result = wp_cache_get($cache_key);
        
        if ($cached_result !== false) {
            // Store in static cache for this request
            self::$welcome_scenario_cache = (bool)$cached_result;
            return self::$welcome_scenario_cache;
        }
        
        // Check if audit log table exists
        $audit_log_table = $wpdb->prefix . 'odcm_audit_log';
        $table_exists_cache_key = 'odcm_table_exists_' . md5($audit_log_table);
        $table_exists = wp_cache_get($table_exists_cache_key);

        if ($table_exists === false) {
            // Use WordPress recommended method to check if table exists
            // Check if the table exists by querying it directly with a safe query
            $database_helper = DatabaseHelper::get_instance();
            $table_exists = $database_helper->get_var(
                $wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $audit_log_table
                )
            ) === $audit_log_table;

            // Cache the result for 1 hour - table existence rarely changes
            wp_cache_set($table_exists_cache_key, $table_exists ? '1' : '0', '', HOUR_IN_SECONDS);
        } else {
            $table_exists = (bool)$table_exists;
        }
        
        if (!$table_exists) {
            // If table doesn't exist, it's definitely a welcome scenario
            wp_cache_set($cache_key, '1', '', 5 * MINUTE_IN_SECONDS);
            self::$welcome_scenario_cache = true;
            return true;
        }
        
        // Check if any log entries exist
        $log_count_cache_key = 'odcm_log_count';
        $log_count = wp_cache_get($log_count_cache_key);

        if ($log_count === false) {
            $log_table_escaped = esc_sql($wpdb->prefix . 'odcm_audit_log');
            $database_helper = DatabaseHelper::get_instance();
            $log_count = $database_helper->get_var("SELECT COUNT(*) FROM `{$log_table_escaped}`");

            // Cache the result for 5 minutes - log count may change more frequently
            wp_cache_set($log_count_cache_key, $log_count, '', 5 * MINUTE_IN_SECONDS);
        }
        
        $is_welcome = (int) $log_count === 0;
        
        // Cache the final result for 5 minutes
        wp_cache_set($cache_key, $is_welcome ? '1' : '0', '', 5 * MINUTE_IN_SECONDS);
        
        // Store in static cache for this request
        self::$welcome_scenario_cache = $is_welcome;
        
        // If no logs exist, show welcome scenario
        return $is_welcome;
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
        // Check user capabilities - use WooCommerce standard for reports (allows Shop Managers)
        // Fallback to manage_woocommerce for backward compatibility
        if (!current_user_can('view_woocommerce_reports') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('admin.insight_dashboard.permission.insufficient_permissions', 'order-daemon'));
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
        $refresh_text  = esc_js(__('admin.insight_dashboard.logs.refresh_button', 'order-daemon'));
        $every_text    = esc_js(__('admin.insight_dashboard.logs.refresh_interval_prefix', 'order-daemon'));
        $seconds_text  = esc_js(__('admin.insight_dashboard.logs.refresh_interval_suffix', 'order-daemon'));

        ?>
        <div class="odcm-unified-header-content">
            <!-- Filter Header with Icon Buttons -->
            <div class="odcm-unified-header-section odcm-unified-header-filters">
                <div class="odcm-filter-pane-header-actions">
                    <!-- Static Controls: Always visible in the same order -->
                    <div class="odcm-pane-icon-buttons">
                        <!-- Left arrow: close current pane (visible only when pane is open) -->
                        <button type="button"
                                <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('filterPaneVisible'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'closeFilterPane()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createClassAttribute(['odcm-pane-icon-button']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                title="<?php echo esc_attr__('admin.insight_dashboard.pane.close', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                        </button>

                        <!-- Right arrow: open last opened pane (visible only when pane is closed) -->
                        <button type="button"
                                <?php echo DashboardComponentUIToolkit::createClassAttribute(['odcm-pane-icon-button']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('!filterPaneVisible'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'openLastOpenedPane()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                title="<?php echo esc_attr__('admin.insight_dashboard.pane.open_last', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>

                        <!-- Filters tab button -->
                        <button type="button"
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'showFiltersPane()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createClassAttribute(['odcm-pane-icon-button', 'odcm-active' => 'activeFilterTab === "filters" && filterPaneVisible']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                title="<?php echo esc_attr__('admin.insight_dashboard.filters', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-search"></span>
                        </button>

                        <!-- Settings tab button -->
                        <button type="button"
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'showSettingsPane()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createClassAttribute(['odcm-pane-icon-button', 'odcm-active' => 'activeFilterTab === "settings" && filterPaneVisible']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                title="<?php echo esc_attr__('admin.insight_dashboard.settings.title', 'order-daemon'); ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </button>
                    </div>

                    <!-- Documentation link -->
                    <a href="<?php echo esc_url(ODCM_DOCS_URL); ?>"
                       target="_blank"
                       class="odcm-docs-link"
                       title="<?php echo esc_attr__('admin.insight_dashboard.docs.view_documentation', 'order-daemon'); ?>">
                        Docs&nbsp;
                        <span class="dashicons dashicons-external"></span>
                    </a>
                </div>
            </div>

            <!-- Log Stream Header -->
            <div class="odcm-unified-header-section odcm-unified-header-stream">
                <div class="odcm-stream-header-content">
                    <h3><?php echo esc_html__('admin.insight_dashboard.stream.title', 'order-daemon'); ?></h3>
                </div>
                <div class="odcm-stream-controls">
                    <div class="odcm-refresh-controls">
                        <button type="button"
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'manualRefresh()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createClassAttribute(['odcm-refresh-button', 'odcm-button', 'is-disabled' => 'loading']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineDisabledBinding('loading'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <span <?php echo DashboardComponentUIToolkit::createClassAttribute(['dashicons', 'dashicons-update', 'is-spinning' => 'loading']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                            <span class="odcm-button-text" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding("'" . esc_js($refresh_text) . "'"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                        </button>

                        <span <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('autoRefreshEnabled ? "' . $every_text . '" : ""'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>

                        <template x-if="autoRefreshEnabled">
                            <input type="number"
                                <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('refreshInterval'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                min="1"
                                max="60"
                                class="odcm-interval-input"
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', '$event.stopPropagation()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        </template>

                        <template x-if="autoRefreshEnabled">
                            <span><?php echo esc_html($seconds_text); ?></span>
                        </template>

                        <label class="odcm-toggle-switch">
                            <input type="checkbox" <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('autoRefreshEnabled'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <span class="odcm-toggle-slider"></span>
                            <span class="odcm-toggle-label"><?php echo esc_html__('admin.insight_dashboard.actions.auto_refresh', 'order-daemon'); ?></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Detail Header -->
            <div class="odcm-unified-header-section odcm-unified-header-details" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('selectedLog'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <h3><?php echo esc_html__('admin.insight_dashboard.detail_pane.events_timeline', 'order-daemon'); ?></h3>
                <div class="odcm-detail-pane-header-actions">
                    <button type="button"
                            class="odcm-detail-pane-expand-toggle"
                            <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'toggleDetailPaneExpansion()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php
                            echo DashboardComponentUIToolkit::createAlpineTitleBinding( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                'detailPaneExpanded ? ' .
                                wp_json_encode(esc_attr__('admin.insight_dashboard.detail_pane.contract_details_pane', 'order-daemon')) .
                                ' : ' .
                                wp_json_encode(esc_attr__('admin.insight_dashboard.detail_pane.expand_details_pane', 'order-daemon'))
                            );
                            ?>>
                        <span class="dashicons dashicons-arrow-left-alt icon-expand"></span>
                        <span class="dashicons dashicons-arrow-right-alt icon-collapse"></span>
                    </button>
                    <button type="button"
                            class="odcm-close-pane"
                            <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'closeDetails()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
            <div <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('activeFilterTab === "filters"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="odcm-tab-content">
                <?php $this->render_filters_tab_content(); ?>
            </div>
            
            <!-- Settings Tab Content -->
            <div <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('activeFilterTab === "settings"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="odcm-settings-pane-content">
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
        <form class="odcm-filter-form" <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('submit', 'applyFilters()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <!-- Omni Search (Free) -->
                <div class="odcm-filter-section">
                    <div class="odcm-search-filter-group">
                        <label for="filter-search" class="odcm-filter-section-title"><?php echo esc_html__('admin.insight_dashboard.filters.search.label', 'order-daemon'); ?></label>
                        <input type="text"
                            id="filter-search"
                            <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('filters.search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('input', 'debouncedFetchLogs()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            placeholder="<?php echo esc_attr__('admin.insight_dashboard.filters.search.placeholder', 'order-daemon'); ?>"
                            class="odcm-search-input">
                    </div>
                </div>

                <!-- Advanced Filters Group -->
                <div class="odcm-advanced-filter-group">
                    
                    <!-- Event Type Filter -->
                    <div class="odcm-filter-group">
                        <label for="filter-event-type"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.label', 'order-daemon'); ?></label>
                        <select id="filter-event-type"
                                <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('filters.event_type'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'applyFilters()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <option value=""><?php echo esc_html__('admin.insight_dashboard.filters.event_type.all', 'order-daemon'); ?></option>
                            <option value="rule_check"><?php echo esc_html__('admin.insight_dashboard.filters.event_type.rule_check', 'order-daemon'); ?></option>
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
                                <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('filters.source'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'applyFilters()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
                                   id="filter-date-start"
                                   aria-label="<?php echo esc_attr__('admin.insight_dashboard.filters.date_range.from', 'order-daemon'); ?>"
                                   <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('filters.date_start'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'applyFilters()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   class="regular-text">
                            <span>–</span>
                            <input type="date"
                                   id="filter-date-end"
                                   aria-label="<?php echo esc_attr__('admin.insight_dashboard.filters.date_range.to', 'order-daemon'); ?>"
                                   <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('filters.date_end'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'applyFilters()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
                                   <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('filters.include_tests'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <label for="filter-include-tests"><?php echo esc_html__('admin.insight_dashboard.filters.include_test_logs', 'order-daemon'); ?></label>
                        </div>
                        <div class="odcm-checkbox-item">
                            <input type="checkbox" 
                                   id="filter-include-debug"
                                   <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('filters.include_debug'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <label for="filter-include-debug"><?php echo esc_html__('admin.insight_dashboard.logs.include_debug_logs', 'order-daemon'); ?></label>
                        </div>
                    </div>
                </div>

                <!-- Filter Actions -->
                <div class="odcm-filter-actions">
                    <button type="submit" class="button button-primary">
                        <?php echo esc_html__('admin.insight_dashboard.filters.apply_filters', 'order-daemon'); ?>
                    </button>
                    <button type="button" class="button" <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'clearFilters()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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

        $processing_text = esc_js(__('admin.insight_dashboard.ajax.processing', 'order-daemon'));
        $reprocess_text  = esc_js(__('admin.insight_dashboard.settings.reprocess_orders.label', 'order-daemon'));

        ?>
        <div class="odcm-tab-content">
            <!-- Display Settings Section -->
            <div class="odcm-settings-section">
                <div class="odcm-settings-group">
                    <h3 class="odcm-settings-section-title"><?php echo esc_html__('admin.insight_dashboard.settings.display_label', 'order-daemon'); ?></h3>
                    <div class="odcm-setting-row">
                        <label class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.timestamp_format_label', 'order-daemon'); ?></label>
                        <div class="odcm-setting-control">
                            <button type="button"
                                    class="odcm-timestamp-toggle button"
                                    <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'toggleTimestampMode()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php echo DashboardComponentUIToolkit::createAlpineTitleBinding("'Current: ' + (timestampDisplayMode === 'timeOnly' ? i18n.timeOnly : timestampDisplayMode === 'relative' ? i18n.relativeTime : i18n.dateAndTime)"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                <span class="dashicons dashicons-clock"></span>
                                <span class="odcm-button-text"
                                      <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('timestampDisplayMode === "timeOnly" ? i18n.timeOnly : timestampDisplayMode === "relative" ? i18n.relativeTime : i18n.dateAndTime'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                    <?php echo esc_html__('admin.insight_dashboard.settings.timestamp_format_datetime', 'order-daemon'); ?>
                                </span>
                            </button>
                        </div>
                    </div>
                    <div class="odcm-setting-row">
                        <label for="odcm_logs_per_page" class="odcm-setting-label"><?php echo esc_html__('admin.insight_dashboard.settings.log_entries_per_page', 'order-daemon'); ?></label>
                        <div class="odcm-setting-control">
                            <input type="number" 
                                id="odcm_logs_per_page"
                                <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('perPage'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'updatePerPageSetting()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                min="10" 
                                max="200" 
                                class="small-text">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Order Processing Section -->
            <div class="odcm-settings-section">
                <div class="odcm-settings-group">
                    <div class="odcm-setting-row">
                    <h3 class="odcm-settings-section-title">Order Processing</h3>
                        <label class="odcm-setting-label">Reprocess Pending Orders</label>
                        <div class="odcm-setting-control">
                            <span class="odcm-setting-hint">Batch operation to reprocess orders with processing/on-hold statuses. (Useful after payment system failures and rule changes.)</span>
                            <button type="button"
                                    class="odcm-refresh-button odcm-button"
                                    <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'reprocessPendingOrders()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php echo DashboardComponentUIToolkit::createAlpineDisabledBinding('isReprocessing'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                <span <?php echo DashboardComponentUIToolkit::createClassAttribute(['dashicons', 'dashicons-update', 'is-spinning' => 'isReprocessing']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                                <span class="odcm-button-text" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('isReprocessing ? "' . esc_js($processing_text) . '" : "' . esc_js($reprocess_text) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Debug Settings Section -->
            <div class="odcm-settings-section">
                <div class="odcm-settings-group">
                    <div class="odcm-setting-row">
                        <h3 class="odcm-settings-section-title">Debug</h3>
                        <label for="odcm_global_debug" class="odcm-setting-label">
                            <input type="checkbox"
                                   name="odcm_global_debug"
                                   id="odcm_global_debug"
                                   <?php checked($global_debug); ?>
                                   <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'saveDebugSetting(\'odcm_global_debug\', $event.target.checked)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            Toggle Global ODCM Debug Mode
                        </label>
                        <span class="odcm-setting-hint">Enable server-wide debug logging (use with caution)</span>
                    </div>
                    <div class="odcm-setting-row">
                        <label for="odcm_detailed_notes" class="odcm-setting-label">
                            <input type="checkbox"
                                   name="odcm_detailed_notes"
                                   id="odcm_detailed_notes"
                                   <?php checked($detailed_notes); ?>
                                   <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'saveDebugSetting(\'odcm_detailed_notes\', $event.target.checked)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            Add Detailed Order Notes
                        </label>
                        <span class="odcm-setting-hint">Add debug info to order notes when rules don't match</span>
                    </div>
                </div>
            </div>

            <!-- DANGER ZONE Settings Section -->
            <div class="odcm-settings-section">
                <div class="odcm-settings-group">
                    <div class="odcm-setting-row">
                        <h3 class="odcm-settings-section-title">DANGER ZONE</h3>
                        <label for="odcm_remove_all_data_on_uninstall" class="odcm-setting-label">
                            <input type="checkbox"
                                name="odcm_remove_all_data_on_uninstall"
                                id="odcm_remove_all_data_on_uninstall"
                                <?php checked(get_option('odcm_remove_all_data_on_uninstall', false)); ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'saveUninstallDataSetting($event.target.checked)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            Uninstall Removes All Order Daemon Data
                        </label>
                        <span class="odcm-setting-hint">When this plugin is uninstalled, also remove all audit log tables, options, and rules. This cannot be undone.</span>
                    </div>
                </div>
            </div>

            <?php
            // Allow extension plugins to add additional settings sections
            // Placed at the bottom to maintain UI consistency - extensions should appear after core settings
            do_action('odcm_insight_dashboard_settings_sections');
            ?>
        </div>
        <?php
    }


    /**
     * Handle AJAX request to update per page setting
     */
    public function handle_update_per_page_ajax(): void
    {
        // Check user capabilities - use WooCommerce standard for reports (allows Shop Managers)
        if (!current_user_can('view_woocommerce_reports') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon')]);
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
     * 
     * Note: Debug settings require manage_woocommerce (Administrator only)
     * as they can affect site behavior and performance.
     */
    public function handle_debug_settings_ajax(): void
    {
        // Check user capabilities - require manage_woocommerce for settings changes
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon')]);
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

        if (isset($_POST['odcm_remove_all_data_on_uninstall'])) {
            $remove_all_data = $_POST['odcm_remove_all_data_on_uninstall'] === '1';
            update_option('odcm_remove_all_data_on_uninstall', $remove_all_data, 'no');
            $updated_settings['remove_all_data_on_uninstall'] = $remove_all_data;
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
     * Handle AJAX request to save uninstall data removal setting
     * 
     * This endpoint specifically handles the "Remove all Order Daemon data on uninstall" checkbox.
     * It provides dedicated toast responses for success/failure of this specific setting.
     */
    public function handle_uninstall_data_setting_ajax(): void
    {
        // Check user capabilities - require manage_woocommerce for settings changes
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon'),
                'type' => 'error',
                'toast' => true
            ]);
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_send_json_error([
                'message' => __('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon'),
                'type' => 'error',
                'toast' => true
            ]);
        }

        // Get and validate input
        if (!isset($_POST['odcm_remove_all_data_on_uninstall'])) {
            wp_send_json_error([
                'message' => __('admin.insight_dashboard.ajax.missing_required_parameter', 'order-daemon'),
                'type' => 'error',
                'toast' => true
            ]);
        }

        $remove_all_data = $_POST['odcm_remove_all_data_on_uninstall'] === '1';

        try {
            // Update the option
            $updated = update_option('odcm_remove_all_data_on_uninstall', $remove_all_data, 'no');

            if ($updated !== false) {
                wp_send_json_success([
                    'message' => $remove_all_data 
                        ? __('admin.insight_dashboard.ajax.uninstall_data_enabled', 'order-daemon')
                        : __('admin.insight_dashboard.ajax.uninstall_data_disabled', 'order-daemon'),
                    'type' => 'success',
                    'toast' => true,
                    'setting' => 'odcm_remove_all_data_on_uninstall',
                    'value' => $remove_all_data
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('admin.insight_dashboard.ajax.failed_to_update_uninstall_setting', 'order-daemon'),
                    'type' => 'error',
                    'toast' => true
                ]);
            }
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(__('admin.insight_dashboard.ajax.uninstall_setting_error', 'order-daemon'), $e->getMessage()),
                'type' => 'error',
                'toast' => true
            ]);
        }
    }

    /**
     * Update global debug mode setting
     * 
     * Uses the standard debug system that syncs with ODCM_DEBUG constant.
     */
    private function update_global_debug_mode(bool $enabled): void
    {
        // Use the standard debug option that syncs with ODCM_DEBUG constant
        update_option('odcm_debug', $enabled ? 1 : 0, 'no');
        
        // Apply the override immediately for this request
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
     * Uses the standard debug system that syncs with ODCM_DEBUG constant.
     */
    public static function is_global_debug_active(): bool
    {
        // Check for runtime override first 
        if (isset($GLOBALS['odcm_debug_override'])) {
            return (bool) $GLOBALS['odcm_debug_override'];
        }

        // Check for stored debug setting (standard option)
        $debug_setting = get_option('odcm_debug', null);
        if ($debug_setting !== null) {
            return (bool) $debug_setting;
        }

        // Fall back to the ODCM_DEBUG constant
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
     * 
     * Note: Reprocessing orders requires manage_woocommerce (Administrator only)
     * as this is a potentially destructive operation affecting order processing.
     */
    public function handle_reprocess_pending_orders_ajax(): void
    {
        // Check user capabilities - require manage_woocommerce for order reprocessing
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon')]);
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
            odcm_log_message('Error in reprocess pending orders AJAX: ' . $e->getMessage(), 'error');
            
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

        // Don't pass order_id at all for admin actions instead of passing null
        // This prevents the Order #0 issue in ProcessLogger/ProcessIdManager
        $pl = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger(new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer());
        $pl->start('admin_action', [ 'actor_user_id' => get_current_user_id(), 'summary' => 'Admin requested reprocess' ]);
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
            <div class="odcm-loading-state" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('loading && logs.length === 0'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <span class="spinner is-active"></span>
                <span <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('i18n.loading'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
            </div>

            <!-- Error State - Only render when there's actually an error -->
            <template <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('error && logs.length === 0'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <div class="odcm-error-state">
                    <span class="dashicons dashicons-warning"></span>
                    <span <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('error'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                    <button type="button" class="button" <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'fetchLogs()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        <?php echo esc_html__('admin.insight_dashboard.actions.retry', 'order-daemon'); ?>
                    </button>
                </div>
            </template>

            <!-- Empty State with Context Awareness - Only render when appropriate -->
            <template <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('!loading && !error && logs.length === 0 && !initialLoad'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <div class="odcm-empty-state">
                    <!-- Filtered Empty State (No results match current filters/search) -->
                    <template <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('hasActiveFilters'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        <div class="odcm-filtered-empty-state">
                            <span class="dashicons dashicons-filter"></span>
                            <h4><?php echo esc_html__('admin.insight_dashboard.logs.no_results_title', 'order-daemon'); ?></h4>
                            <p><?php echo esc_html__('admin.insight_dashboard.logs.no_results_hint', 'order-daemon'); ?></p>
                            <div class="odcm-empty-actions">
                                <button type="button" class="button" <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'clearFilters()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                    <?php echo esc_html__('admin.insight_dashboard.logs.clear_filters_button', 'order-daemon'); ?>
                                </button>
                                <button type="button" class="button button-secondary" <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'fetchLogs()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                    <?php echo esc_html__('admin.insight_dashboard.logs.refresh_button', 'order-daemon'); ?>
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Welcome State (Fresh Installation) -->
                    <template <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('!hasActiveFilters && isWelcomeScenario'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
                    <template <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('!hasActiveFilters && !isWelcomeScenario'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        <div class="odcm-regular-empty-state">
                            <span class="dashicons dashicons-admin-post"></span>
                            <h4><?php echo esc_html__('admin.insight_dashboard.empty.no_activity.title', 'order-daemon'); ?></h4>
                            <p><?php echo esc_html__('admin.insight_dashboard.empty.no_activity.description', 'order-daemon'); ?></p>
                            <div class="odcm-empty-actions">
                                <button type="button" class="button" <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'fetchLogs()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                    <?php echo esc_html__('admin.insight_dashboard.logs.refresh_button', 'order-daemon'); ?>
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
            <div class="odcm-log-entries" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('logs.length > 0'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <!-- Log Entries Controls Wrapper -->
                <div class="odcm-log-entries-controls">
                    <!-- Batch Selection Controls -->
                    <div class="odcm-batch-controls">
                        <div class="odcm-select-all-container">
                            <input type="checkbox"
                                   id="select-all-logs"
                                    <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('selectAll'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'toggleSelectAll($event.target.checked)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   class="odcm-select-all-checkbox">
                            <label for="select-all-logs" class="odcm-select-all-label">
                                <?php echo esc_html__('admin.insight_dashboard.log_stream.select_all', 'order-daemon'); ?>
                            </label>
                        </div>
                        <div class="odcm-batch-actions" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('hasSelection'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <span class="odcm-selection-count" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('selectedCount + " ' . esc_js(__('admin.insight_dashboard.log_stream.selected_label', 'order-daemon')) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                            <button type="button" 
                                    class="odcm-delete-selected button button-secondary"
                                    <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'deleteSelectedLogs()'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php echo DashboardComponentUIToolkit::createAlpineDisabledBinding('isDeleting'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                <span <?php echo DashboardComponentUIToolkit::createClassAttribute(['dashicons', 'dashicons-trash', 'is-spinning' => 'isDeleting']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                                <span class="odcm-delete-label-desktop" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('isDeleting ? "' . esc_js(__('admin.insight_dashboard.log_stream.deleting', 'order-daemon')) . '" : "' . esc_js(__('admin.insight_dashboard.log_stream.delete_selected', 'order-daemon')) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                                <span class="odcm-delete-label-mobile" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('isDeleting ? "' . esc_js(__('admin.insight_dashboard.log_stream.deleting', 'order-daemon')) . '" : "' . esc_js(__('admin.ui.delete', 'order-daemon')) . ' " + selectedCount'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                            </button>
                        </div>
                        <div class="odcm-controls-divider" aria-hidden="true"></div>
                        <div class="odcm-stream-view-toggle odcm-segmented" role="radiogroup" aria-label="Toggle view mode">
                            <div class="odcm-segmented-track">
                                <div class="odcm-segmented-thumb"
                                        <?php echo DashboardComponentUIToolkit::createAlpineClassBinding("{ 'is-right': viewMode === 'flat', 'is-left': viewMode !== 'flat' }"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                </div>

                                <button type="button"
                                        class="odcm-segmented-option"
                                        role="radio"
                                        <?php echo DashboardComponentUIToolkit::createAlpineBind('aria-checked', 'viewMode === "consolidated"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php echo DashboardComponentUIToolkit::createAlpineClassBinding("{ 'is-active': viewMode === 'consolidated' }"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'setViewMode(\'consolidated\')'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                    <?php echo esc_html__('admin.insight_dashboard.log_stream.view_grouped', 'order-daemon'); ?>
                                </button>

                                <button type="button"
                                        class="odcm-segmented-option"
                                        role="radio"
                                        <?php echo DashboardComponentUIToolkit::createAlpineBind('aria-checked', 'viewMode === "flat"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php echo DashboardComponentUIToolkit::createAlpineClassBinding("{ 'is-active': viewMode === 'flat' }"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'setViewMode(\'flat\')'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        title="<?php echo esc_attr__('admin.insight_dashboard.log_stream.view_individual_title', 'order-daemon'); ?>">
                                    <?php echo esc_html__('admin.insight_dashboard.log_stream.view_individual', 'order-daemon'); ?>
                                </button>
                                
                            </div>
                        </div>
                    </div>
                </div>

                <template x-for="(log, index) in logs" :key="log?.id || ('invalid-' + index)">
                    <div <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('log && log.id'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php echo DashboardComponentUIToolkit::createAlpineClassBinding("log ? getLogEntryClasses(log) : 'odcm-log-entry'"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'log && selectLog(log)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

                        <div class="odcm-log-entry-checkbox" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('log && log.id'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click.stop', 'null'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <input type="checkbox"
                                <?php echo DashboardComponentUIToolkit::createAlpineBind('id', "'log-checkbox-' + (log?.id || 'invalid')"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineCheckedBinding('isLogSelected(log.id)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click.stop', 'toggleLogSelection(log.id)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                class="odcm-log-checkbox">
                            <label <?php echo DashboardComponentUIToolkit::createAlpineBind('for', "'log-checkbox-' + (log?.id || 'invalid')"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    class="odcm-log-checkbox-label">
                                <span class="screen-reader-text"><?php echo esc_html__('admin.insight_dashboard.log_stream.select_log_entry', 'order-daemon'); ?></span>
                            </label>
                        </div>

                        <div class="odcm-log-entry-content">
                            <div class="odcm-log-entry-header">
                                <div class="odcm-log-timestamp js-format-timestamp" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('formatTimestamp(log?.timestamp, $el)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></div>

                                <div <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('log?.order_id'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                    Order #<span <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('log.order_id'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                                </div>

                                <div class="odcm-log-summary">
                                    <span <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('log?.summary || "' . esc_js(__('admin.insight_dashboard.log_stream.no_summary', 'order-daemon')) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                                </div>

                                <span class="odcm-status-pill"
                                        <?php echo DashboardComponentUIToolkit::createAlpineClassBinding("'odcm-status-pill odcm-status-pill--' + ((log?.status && typeof log.status === 'string') ? log.status.toLowerCase() : 'unknown')"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('log?.status || "' . esc_js(__('admin.insight_dashboard.log_stream.unknown_status', 'order-daemon')) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                                    </span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Pagination -->
            <div class="odcm-pagination" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('totalPages > 1'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <div class="tablenav-pages">
                    <span class="displaying-num" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('paginationText'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                    <span class="pagination-links">
                        <button type="button" 
                                class="button first-page"
                                <?php echo DashboardComponentUIToolkit::createAlpineDisabledBinding('currentPage === 1'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'goToPage(1)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>‹‹</button>
                        <button type="button" 
                                class="button prev-page"
                                <?php echo DashboardComponentUIToolkit::createAlpineDisabledBinding('currentPage === 1'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'goToPage(currentPage - 1)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>‹</button>
                        <span class="paging-input">
                            <input type="number" 
                                   <?php echo DashboardComponentUIToolkit::createAlpineModelBinding('currentPage'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('change', 'goToPage(currentPage)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   <?php echo DashboardComponentUIToolkit::createAlpineMinBinding('1'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   <?php echo DashboardComponentUIToolkit::createAlpineAttrBinding('max', 'totalPages'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                   class="current-page">
                            <?php echo esc_html__('admin.insight_dashboard.pagination.of', 'order-daemon'); ?>
                            <span class="total-pages" <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('totalPages'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
                        </span>
                        <button type="button" 
                                class="button next-page"
                                <?php echo DashboardComponentUIToolkit::createAlpineDisabledBinding('currentPage === totalPages'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'goToPage(currentPage + 1)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>›</button>
                        <button type="button" 
                                class="button last-page"
                                <?php echo DashboardComponentUIToolkit::createAlpineDisabledBinding('currentPage === totalPages'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'goToPage(totalPages)'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>››</button>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX request to log Alpine.js failure
     * 
     * This endpoint logs client-side Alpine.js loading failures for debugging.
     */
    public function handle_log_alpine_failure_ajax(): void
    {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon')]);
        }

        // Check user capabilities - use WooCommerce standard for reports (allows Shop Managers)
        if (!current_user_can('view_woocommerce_reports') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        // Get raw data
        $env_raw = isset($_POST['env']) ? sanitize_text_field(wp_unslash($_POST['env'])) : '';
        $issues_raw = isset($_POST['issues']) ? sanitize_text_field(wp_unslash($_POST['issues'])) : '';

        // Validate and sanitize the JSON
        try {
            $env = odcm_validate_and_sanitize_json($env_raw, true);
            $issues = odcm_validate_and_sanitize_json($issues_raw, true);
        } catch (InvalidArgumentException $e) {
            // Log error and provide fallback
            odcm_log_message("JSON validation error: " . $e->getMessage(), 'error');
            $env = [];
            $issues = [];
        }

        // Additional sanitization for array values
        $env = array_map('sanitize_text_field', $env);
        $issues = array_map('sanitize_text_field', $issues);

        // Log the failure for debugging
        $log_data = [
            'type' => 'alpine_js_failure',
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'environment' => is_array($env) ? $env : [],
            'potential_issues' => is_array($issues) ? $issues : [],
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown',
            'referer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : 'unknown'
        ];

        // Log using the plugin's logging system if available
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                'Alpine.js framework failed to load',
                $log_data,
                null,
                'error',
                'frontend_error'
            );
        }

        // Also log to WordPress debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Use WordPress-friendly logging
            if (function_exists('odcm_log_message')) {
                odcm_log_message('ODCM Alpine.js Load Failure: ' . wp_json_encode($log_data), 'error');
            }
        }

        wp_send_json_success([
            'message' => 'Alpine.js failure logged',
            'logged' => true
        ]);
    }

    /**
     * Render the detail pane content
     */
    private function render_detail_pane(): void
    {
        ?>
        <div class="odcm-detail-pane-content">
            <!-- Loading State -->
            <div class="odcm-detail-loading" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('detailLoading'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <span class="spinner is-active"></span>
                <span <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('i18n.loading'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
            </div>

            <!-- Detail Content -->
            <div class="odcm-detail-content" 
                 <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('!detailLoading && selectedLog'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                 <?php echo DashboardComponentUIToolkit::createAlpineHtmlBinding('detailHtml'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            </div>

            <!-- Empty State -->
            <div class="odcm-detail-empty" <?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('!detailLoading && !selectedLog'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <span class="dashicons dashicons-info"></span>
                <span <?php echo DashboardComponentUIToolkit::createAlpineTextBinding('i18n.selectLog'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></span>
            </div>
        </div>
        <?php
    }

}