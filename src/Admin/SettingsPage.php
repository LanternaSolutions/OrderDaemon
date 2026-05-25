<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;

/**
 * Settings admin page for Order Daemon.
 *
 * Handles the standalone Settings submenu page — asset enqueueing,
 * page rendering, and AJAX handlers for save and clear-log actions.
 */
class SettingsPage
{
    const PAGE_SLUG = 'odcm-settings';

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_odcm_save_settings', [$this, 'handle_save_settings_ajax']);
        add_action('wp_ajax_odcm_clear_audit_log', [$this, 'handle_clear_audit_log_ajax']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            InsightDashboard::PAGE_SLUG,
            __('admin.settings.page_title', 'order-daemon'),
            __('admin.settings.submenu', 'order-daemon'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (!$this->is_settings_page($hook_suffix)) {
            return;
        }

        $plugin_version = defined('ODCM_VERSION') ? ODCM_VERSION : '2.0.0';
        $assets_url     = defined('ODCM_PLUGIN_URL') ? ODCM_PLUGIN_URL . 'assets/' : '';
        $plugin_dir     = defined('ODCM_PLUGIN_DIR') ? ODCM_PLUGIN_DIR : '';

        // Alpine.js
        wp_enqueue_script(
            'alpine-js',
            $assets_url . 'js/vendor/alpine.min.js',
            [],
            '3.14.9',
            true
        );
        add_filter('script_loader_tag', function (string $tag, string $handle): string {
            if ($handle === 'alpine-js') {
                return str_replace('<script ', '<script defer ', $tag);
            }
            return $tag;
        }, 10, 2);

        // Shared toast system
        wp_enqueue_script(
            'odcm-shared-toasts',
            $assets_url . 'js/shared/toasts.js',
            [],
            $plugin_version,
            true
        );

        // Design system CSS
        $ds_path    = $plugin_dir . 'assets/css/odcm-design-system.css';
        $ds_version = file_exists($ds_path) ? filemtime($ds_path) : $plugin_version;
        wp_enqueue_style('odcm-design-system', $assets_url . 'css/odcm-design-system.css', [], $ds_version);

        // Admin CSS (provides .odcm-unified-header and shared layout)
        $admin_css_path    = $plugin_dir . 'assets/css/admin.css';
        $admin_css_version = file_exists($admin_css_path) ? filemtime($admin_css_path) : $plugin_version;
        wp_enqueue_style(
            'odcm-admin-styles',
            $assets_url . 'css/admin.css',
            ['odcm-design-system'],
            $admin_css_version
        );

        // Page-specific layout CSS (`.st` styles from design handoff)
        wp_add_inline_style('odcm-admin-styles', $this->get_page_css());

        // Pass settings data + i18n to JS
        wp_localize_script('odcm-shared-toasts', 'odcmSettingsConfig', $this->get_js_config());

        // Alpine component
        wp_add_inline_script('odcm-shared-toasts', $this->get_alpine_component(), 'after');
    }

    public function render_page(): void
    {
        $s = $this->get_settings();
        ?>
        <div class="odcm-scope" id="odcm-settings-page" x-data="settingsState()" x-cloak>

            <div class="odcm-unified-header">
                <?php $this->render_header_brand(); ?>
                <span class="odcm-unified-header__sep" aria-hidden="true">/</span>
                <span class="odcm-unified-header__crumb"><?php echo esc_html__('admin.settings.submenu', 'order-daemon'); ?></span>
            </div>

            <div class="st odcm-page-body">
                <h3 class="st__title odcm-page-title"><?php echo esc_html__('admin.settings.page_title', 'order-daemon'); ?></h3>

                <!-- Display -->
                <div class="st__card">
                    <div>
                        <h4 class="st__sect-title"><?php echo esc_html__('admin.insight_dashboard.settings.display_label', 'order-daemon'); ?></h4>
                        <p class="st__sect-sub"><?php echo esc_html__('admin.insight_dashboard.settings.display_sub', 'order-daemon'); ?></p>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.insight_dashboard.settings.log_entries_per_page', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.insight_dashboard.settings.log_entries_per_page_help', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <input class="odcm-input" type="number" x-model.number="perPage"
                                   min="10" max="100" style="width:80px;text-align:center;" />
                        </div>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.settings.display.auto_refresh', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.settings.display.auto_refresh_help', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <input class="odcm-input" type="number" x-model.number="autoRefresh"
                                   min="0" max="60" style="width:80px;text-align:center;" />
                            <span style="font-size:var(--odcm-text-sm);color:var(--odcm-muted);"><?php echo esc_html__('admin.settings.display.auto_refresh_unit', 'order-daemon'); ?></span>
                        </div>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.settings.display.theme', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.settings.display.theme_help', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <select class="odcm-select" x-model="theme" style="width:140px;">
                                <option value="auto"><?php echo esc_html__('admin.settings.display.theme_auto', 'order-daemon'); ?></option>
                                <option value="light"><?php echo esc_html__('admin.settings.display.theme_light', 'order-daemon'); ?></option>
                                <option value="dark"><?php echo esc_html__('admin.settings.display.theme_dark', 'order-daemon'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Audit log -->
                <div class="st__card">
                    <div>
                        <h4 class="st__sect-title"><?php echo esc_html__('admin.settings.audit_log.title', 'order-daemon'); ?></h4>
                        <p class="st__sect-sub"><?php echo esc_html__('admin.settings.audit_log.sub', 'order-daemon'); ?></p>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.settings.audit_log.retention', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.settings.audit_log.retention_help', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <?php if (defined('ODCM_PRO_PLUGIN_FILE')): ?>
                                <select class="odcm-select" x-model.number="retentionDays" style="width:140px;">
                                    <option value="7"><?php echo esc_html__('admin.settings.audit_log.retention_7_days', 'order-daemon'); ?></option>
                                    <option value="14"><?php echo esc_html__('admin.settings.audit_log.retention_14_days', 'order-daemon'); ?></option>
                                    <option value="30"><?php echo esc_html__('admin.settings.audit_log.retention_30_days', 'order-daemon'); ?></option>
                                    <option value="60"><?php echo esc_html__('admin.settings.audit_log.retention_60_days', 'order-daemon'); ?></option>
                                    <option value="90"><?php echo esc_html__('admin.settings.audit_log.retention_90_days', 'order-daemon'); ?></option>
                                    <option value="180"><?php echo esc_html__('admin.settings.audit_log.retention_180_days', 'order-daemon'); ?></option>
                                    <option value="365"><?php echo esc_html__('admin.settings.audit_log.retention_1_year', 'order-daemon'); ?></option>
                                </select>
                            <?php else: ?>
                                <span class="st__field-value"><?php echo esc_html__('admin.settings.audit_log.retention_30_days', 'order-daemon'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.settings.audit_log.capture_rule_execution', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.settings.audit_log.capture_rule_execution_help', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <button type="button" class="odcm-toggle" role="switch"
                                    :aria-checked="captureRuleExecution ? 'true' : 'false'"
                                    @click="captureRuleExecution = !captureRuleExecution">
                                <span class="odcm-toggle__thumb"></span>
                            </button>
                        </div>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.settings.audit_log.capture_checkout_events', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.settings.audit_log.capture_checkout_events_help', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <button type="button" class="odcm-toggle" role="switch"
                                    :aria-checked="captureCheckoutEvents ? 'true' : 'false'"
                                    @click="captureCheckoutEvents = !captureCheckoutEvents">
                                <span class="odcm-toggle__thumb"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Data management -->
                <div class="st__card st__danger">
                    <div>
                        <h4 class="st__sect-title"><?php echo esc_html__('admin.insight_dashboard.settings.danger_zone_title', 'order-daemon'); ?></h4>
                        <p class="st__sect-sub"><?php echo esc_html__('admin.insight_dashboard.settings.danger_zone_sub', 'order-daemon'); ?></p>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.insight_dashboard.settings.uninstall_data.label', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.insight_dashboard.settings.uninstall_data.hint', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <select class="odcm-select" x-model="removeDataOnUninstall" style="width:200px;">
                                <option value="keep"><?php echo esc_html__('admin.insight_dashboard.settings.uninstall_data.keep', 'order-daemon'); ?></option>
                                <option value="delete"><?php echo esc_html__('admin.insight_dashboard.settings.uninstall_data.delete', 'order-daemon'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="st__field">
                        <div>
                            <div class="st__field-label"><?php echo esc_html__('admin.settings.data_management.clear_log', 'order-daemon'); ?></div>
                            <div class="st__field-help"><?php echo esc_html__('admin.settings.data_management.clear_log_help', 'order-daemon'); ?></div>
                        </div>
                        <div class="st__field-input">
                            <button type="button" class="odcm-btn odcm-btn--danger odcm-btn--sm"
                                    @click="clearLog()" :disabled="clearing">
                                <span x-text="clearing ? '<?php echo esc_js(__('admin.insight_dashboard.ajax.processing', 'order-daemon')); ?>' : '<?php echo esc_js(__('admin.settings.data_management.clear_log_button', 'order-daemon')); ?>'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Footer actions -->
                <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:4px;">
                    <button type="button" class="odcm-btn odcm-btn--primary"
                            @click="saveSettings()" :disabled="saving">
                        <span x-text="saving ? '<?php echo esc_js(__('admin.insight_dashboard.ajax.processing', 'order-daemon')); ?>' : '<?php echo esc_js(__('admin.settings.save', 'order-daemon')); ?>'"></span>
                    </button>
                </div>

            </div><!-- /.st -->
        </div><!-- /#odcm-settings-page -->
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function handle_save_settings_ajax(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon')]);
        }

        // Events per page (user-meta)
        if (isset($_POST['per_page'])) {
            $per_page = absint($_POST['per_page']);
            if ($per_page >= 10 && $per_page <= 100) {
                update_user_meta(get_current_user_id(), 'odcm_logs_per_page', $per_page);
            }
        }

        // Auto-refresh interval
        if (isset($_POST['auto_refresh_interval'])) {
            $interval = absint($_POST['auto_refresh_interval']);
            $interval = min(60, $interval);
            update_option('odcm_auto_refresh_interval', $interval, 'no');
        }

        // Default theme
        if (isset($_POST['default_theme'])) {
            $theme = sanitize_key(wp_unslash($_POST['default_theme']));
            if (in_array($theme, ['auto', 'light', 'dark'], true)) {
                update_option('odcm_default_theme', $theme, 'no');
            }
        }

        // Log retention (Pro only — free is locked at 30)
        if (defined('ODCM_PRO_PLUGIN_FILE') && isset($_POST['retention_days'])) {
            $retention = absint($_POST['retention_days']);
            if (in_array($retention, [7, 14, 30, 60, 90, 180, 365], true)) {
                update_option('odcm_log_retention_days', $retention, 'no');
            }
        }

        // Capture rule execution
        if (isset($_POST['capture_rule_execution'])) {
            $val = $_POST['capture_rule_execution'] === '1';
            update_option('odcm_capture_rule_execution', $val, 'no');
        }

        // Capture checkout events
        if (isset($_POST['capture_checkout_events'])) {
            $val = $_POST['capture_checkout_events'] === '1';
            update_option('odcm_capture_checkout_events', $val, 'no');
        }

        // Uninstall data
        if (isset($_POST['remove_data_on_uninstall'])) {
            $val = $_POST['remove_data_on_uninstall'] === '1';
            update_option('odcm_remove_all_data_on_uninstall', $val, 'no');
        }

        wp_send_json_success(['message' => __('admin.settings.ajax.saved', 'order-daemon')]);
    }

    public function handle_clear_audit_log_ajax(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.permission_denied', 'order-daemon')]);
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            wp_send_json_error(['message' => __('admin.insight_dashboard.ajax.security_check_failed', 'order-daemon')]);
        }

        global $wpdb;

        $log_table      = $wpdb->prefix . 'odcm_audit_log';
        $payload_table  = $wpdb->prefix . 'odcm_audit_log_payloads';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("TRUNCATE TABLE `{$log_table}`");       // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("TRUNCATE TABLE `{$payload_table}`");   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable

        wp_send_json_success(['message' => __('admin.settings.ajax.log_cleared', 'order-daemon')]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function is_settings_page(string $hook_suffix): bool
    {
        if (isset($_GET['page']) && !isset($_REQUEST['action'])) {
            $page = sanitize_key(wp_unslash($_GET['page']));
            return str_contains($hook_suffix, self::PAGE_SLUG) || $page === self::PAGE_SLUG;
        }
        return str_contains($hook_suffix, self::PAGE_SLUG);
    }

    private function get_settings(): array
    {
        $user_id = get_current_user_id();
        $per_page_meta = get_user_meta($user_id, 'odcm_logs_per_page', true);

        return [
            'perPage'               => $per_page_meta ? (int) $per_page_meta : 20,
            'autoRefresh'           => (int) get_option('odcm_auto_refresh_interval', 5),
            'theme'                 => get_option('odcm_default_theme', 'auto'),
            'retentionDays'         => (int) get_option('odcm_log_retention_days', 30),
            'captureRuleExecution'  => (bool) get_option('odcm_capture_rule_execution', true),
            'captureCheckoutEvents' => (bool) get_option('odcm_capture_checkout_events', true),
            'removeDataOnUninstall' => (bool) get_option('odcm_remove_all_data_on_uninstall', false),
        ];
    }

    private function get_js_config(): array
    {
        $s = $this->get_settings();
        return [
            'ajaxUrl'               => admin_url('admin-ajax.php'),
            'nonce'                 => wp_create_nonce('wp_rest'),
            'perPage'               => $s['perPage'],
            'autoRefresh'           => $s['autoRefresh'],
            'theme'                 => $s['theme'],
            'retentionDays'         => $s['retentionDays'],
            'captureRuleExecution'  => $s['captureRuleExecution'],
            'captureCheckoutEvents' => $s['captureCheckoutEvents'],
            'removeDataOnUninstall' => $s['removeDataOnUninstall'] ? 'delete' : 'keep',
            'i18n' => [
                'saved'         => __('admin.settings.ajax.saved', 'order-daemon'),
                'saveError'     => __('admin.settings.ajax.save_error', 'order-daemon'),
                'logCleared'    => __('admin.settings.ajax.log_cleared', 'order-daemon'),
                'logClearError' => __('admin.settings.ajax.log_clear_error', 'order-daemon'),
                'confirmClear'  => __('admin.settings.ajax.confirm_clear_log', 'order-daemon'),
            ],
        ];
    }

    private function get_alpine_component(): string
    {
        return <<<'JS'
document.addEventListener('alpine:init', function () {
    Alpine.data('settingsState', function () {
        return {
            perPage:               odcmSettingsConfig.perPage,
            autoRefresh:           odcmSettingsConfig.autoRefresh,
            theme:                 odcmSettingsConfig.theme,
            retentionDays:         odcmSettingsConfig.retentionDays,
            captureRuleExecution:  odcmSettingsConfig.captureRuleExecution,
            captureCheckoutEvents: odcmSettingsConfig.captureCheckoutEvents,
            removeDataOnUninstall: odcmSettingsConfig.removeDataOnUninstall,
            saving:   false,
            clearing: false,

            saveSettings: async function () {
                this.saving = true;
                try {
                    var body = new URLSearchParams({
                        action:                    'odcm_save_settings',
                        _wpnonce:                  odcmSettingsConfig.nonce,
                        per_page:                  this.perPage,
                        auto_refresh_interval:     this.autoRefresh,
                        default_theme:             this.theme,
                        retention_days:            this.retentionDays,
                        capture_rule_execution:    this.captureRuleExecution ? '1' : '0',
                        capture_checkout_events:   this.captureCheckoutEvents ? '1' : '0',
                        remove_data_on_uninstall:  this.removeDataOnUninstall === 'delete' ? '1' : '0',
                    });
                    var res  = await fetch(odcmSettingsConfig.ajaxUrl, { method: 'POST', body: body });
                    var data = await res.json();
                    if (data.success) {
                        if (window.odcmToast) window.odcmToast(odcmSettingsConfig.i18n.saved, 'success');
                    } else {
                        if (window.odcmToast) window.odcmToast(data.data?.message || odcmSettingsConfig.i18n.saveError, 'error');
                    }
                } catch (e) {
                    if (window.odcmToast) window.odcmToast(odcmSettingsConfig.i18n.saveError, 'error');
                } finally {
                    this.saving = false;
                }
            },

            clearLog: async function () {
                if (!confirm(odcmSettingsConfig.i18n.confirmClear)) return;
                this.clearing = true;
                try {
                    var body = new URLSearchParams({
                        action:   'odcm_clear_audit_log',
                        _wpnonce: odcmSettingsConfig.nonce,
                    });
                    var res  = await fetch(odcmSettingsConfig.ajaxUrl, { method: 'POST', body: body });
                    var data = await res.json();
                    if (data.success) {
                        if (window.odcmToast) window.odcmToast(odcmSettingsConfig.i18n.logCleared, 'success');
                    } else {
                        if (window.odcmToast) window.odcmToast(data.data?.message || odcmSettingsConfig.i18n.logClearError, 'error');
                    }
                } catch (e) {
                    if (window.odcmToast) window.odcmToast(odcmSettingsConfig.i18n.logClearError, 'error');
                } finally {
                    this.clearing = false;
                }
            },
        };
    });
});
JS;
    }

    private function get_page_css(): string
    {
        return '
.st { padding: 28px; max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
.st__title { font-size: var(--odcm-text-xl); font-weight: 500; letter-spacing: -0.01em; margin: 0; color: var(--odcm-ink); }
.st__card { background: var(--odcm-surface); border: 1px solid var(--odcm-border); border-radius: var(--odcm-radius-3); padding: 22px; display: flex; flex-direction: column; gap: 18px; }
.st__sect-title { font-size: var(--odcm-text-md); font-weight: 600; margin: 0 0 4px; }
.st__sect-sub { font-size: var(--odcm-text-sm); color: var(--odcm-muted); margin: 0; }
.st__field { display: grid; grid-template-columns: 1fr 200px; gap: 16px; align-items: flex-start; padding-top: 18px; border-top: 1px solid var(--odcm-rule); }
.st__field:first-of-type { padding-top: 0; border-top: 0; }
.st__field-label { font-size: var(--odcm-text-sm); font-weight: 500; color: var(--odcm-ink); margin-bottom: 4px; }
.st__field-help { font-size: var(--odcm-text-xs); color: var(--odcm-muted); line-height: 1.5; }
.st__field-input { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
.st__field-value { font-size: var(--odcm-text-sm); color: var(--odcm-muted); }
.st__danger { border-color: var(--odcm-danger); background: var(--odcm-danger-soft); }
.st__danger .st__sect-title { color: var(--odcm-danger); }
@media (max-width: 767px) { .st__field { grid-template-columns: 1fr; } .st__field-input { justify-content: flex-start; } }
        ';
    }

    private function render_header_brand(): void
    {
        ?>
        <div class="odcm-unified-header__brand">
            <svg width="22" height="22" viewBox="0 0 128 128" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M115.575 38.0075C116.022 37.9874 116.467 38.0076 116.911 38.0682C117.074 38.4076 117.013 38.7113 116.728 38.9793C110.372 46.5109 104.341 54.2856 98.6351 62.3033C96.4699 65.5014 94.365 68.7407 92.3207 72.0217C91.9153 72.364 91.4499 72.4652 90.9242 72.3254C90.2886 71.689 89.7422 70.9803 89.2849 70.1995C86.957 66.4332 84.6903 62.6269 82.4847 58.7804C82.0502 57.6476 82.4348 57.2225 83.6383 57.5049C84.8632 58.3251 85.9359 59.3171 86.8562 60.4811C88.0448 62.0349 89.2794 63.5533 90.5599 65.0366C90.8192 65.1137 91.062 65.0732 91.2885 64.9151C93.8523 61.257 96.4631 57.6329 99.1209 54.0427C102.193 50.037 105.492 46.2104 109.018 42.563C110.486 41.1616 112.064 39.9062 113.753 38.7971C114.358 38.4955 114.965 38.2322 115.575 38.0075Z"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M35.5513 43.3525C42.0969 42.9637 47.8042 44.9275 52.6732 49.2443C55.272 51.6805 57.5994 54.3328 59.6555 57.2011C59.9332 57.5996 60.1558 58.0248 60.3234 58.4767C59.7453 59.6333 59.0167 60.6861 58.1376 61.6351C57.9623 61.73 57.8004 61.7097 57.6519 61.5744C55.3649 57.9691 52.5719 54.7904 49.2731 52.0383C45.2311 48.7875 40.6167 47.3702 35.4299 47.7865C28.6765 48.9939 24.2036 52.8611 22.0117 59.3878C20.1255 67.2545 22.4934 73.4702 29.1154 78.0348C36.2941 81.8873 42.9729 81.1584 49.1517 75.8482C51.201 73.9197 53.1236 71.8747 54.9197 69.7135C57.8808 65.82 60.7952 61.8921 63.6628 57.93C66.5835 53.9957 69.9633 50.4931 73.8023 47.4221C79.2353 43.7409 85.1045 42.85 91.41 44.7495C93.6407 45.4694 95.5633 46.6639 97.178 48.3332C97.8828 49.5333 97.5387 50.0395 96.1458 49.8517C88.3096 45.9676 81.0641 46.818 74.4095 52.4027C71.581 55.1104 68.9702 58.0056 66.5771 61.0885C63.2644 65.6579 59.9047 70.1931 56.4983 74.6941C53.4913 78.5538 49.7472 81.449 45.2659 83.3799C36.2004 86.3776 28.4086 84.4137 21.8902 77.4882C17.7008 72.3713 16.2032 66.5403 17.3973 59.9952C19.2203 52.0575 23.9764 46.7731 31.6655 44.1421C32.9642 43.8115 34.2594 43.5482 35.5513 43.3525Z"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M103.31 62.6677C103.51 62.6417 103.692 62.6823 103.857 62.7892C104.012 63.0585 104.133 63.3421 104.221 63.6396C104.425 70.9849 101.692 76.9577 96.0244 81.5578C89.3454 85.7966 82.4643 86.1206 75.381 82.5296C70.5663 79.657 66.6198 75.8708 63.5414 71.1713C63.4421 70.7505 63.4827 70.3455 63.6628 69.9565C63.8724 69.8258 64.095 69.8055 64.3307 69.8958C67.5089 73.4036 71.1114 76.3595 75.1381 78.7637C79.4853 81.2028 84.0998 81.9317 88.9813 80.9504C93.9758 79.5176 97.7605 76.5414 100.335 72.0216C101.837 69.3362 102.728 66.4613 103.007 63.3966C103.076 63.1365 103.177 62.8936 103.31 62.6677Z"/>
            </svg>
            <span class="odcm-unified-header__title">Order Daemon</span>
        </div>
        <?php
    }
}