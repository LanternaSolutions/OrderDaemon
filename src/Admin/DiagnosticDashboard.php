<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use OrderDaemon\CompletionManager\Diagnostics\DiagnosticRunner;
use OrderDaemon\CompletionManager\Includes\Odcm_Config;

/**
 * Diagnostic Dashboard - UI for Running and Viewing Diagnostic Results
 *
 * Provides a simple WordPress admin interface for running diagnostics
 * and viewing results. Integrates with the existing DevToolbar system.
 *
 * @package OrderDaemon\DevTools\UI
 */
class DiagnosticDashboard
{
    /**
     * The diagnostic runner instance
     *
     * @var DiagnosticRunner
     */
    private DiagnosticRunner $runner;

    /**
     * Initialize the diagnostic dashboard
     */
    public function __construct()
    {
        $this->runner = new DiagnosticRunner();
    }

    /**
     * Initialize dashboard hooks
     *
     * @return void
     */
    public function init(): void
    {
        // Only load for users with manage_options capability
        if (!current_user_can('manage_options')) {
            return;
        }

        // Register AJAX handlers
        add_action('wp_ajax_odcm_run_diagnostics', [$this, 'ajax_run_diagnostics']);
        add_action('wp_ajax_odcm_run_single_diagnostic', [$this, 'ajax_run_single_diagnostic']);
        add_action('wp_ajax_odcm_generate_dual_report', [$this, 'ajax_generate_dual_report']);
        add_action('wp_ajax_odcm_toggle_debug_option', [$this, 'ajax_toggle_debug_option']);
        
        // Enqueue assets on our page
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue JavaScript and CSS assets
     *
     * @param string $hook The current admin page hook
     * @return void
     */
    public function enqueue_assets(string $hook): void
    {
        // Load diagnostic page under Order Daemon menu
        if ($hook !== 'order-daemon_page_odcm-diagnostics') {
            return;
        }

        $script_version = defined('ODCM_VERSION') ? ODCM_VERSION : '1.0.0';
        $assets_url = defined('ODCM_PLUGIN_URL') ? ODCM_PLUGIN_URL . 'assets/' : '';

        // Enqueue Prism.js for syntax highlighting
        wp_enqueue_style(
            'odcm-prism-css',
            $assets_url . 'css/vendor/prism.css',
            [],
            $script_version
        );

        wp_enqueue_script(
            'odcm-prism-js',
            $assets_url . 'js/vendor/prism.js',
            [],
            $script_version,
            true
        );

        // Enqueue diagnostic dashboard assets
        wp_enqueue_script(
            'odcm-diagnostics',
            $assets_url . 'js/diagnostics.js',
            ['jquery', 'odcm-prism-js'], // Add prism dependency
            $script_version,
            true
        );

        // Enqueue design system CSS first (contains shared styles and CSS variables)
        $ds_path = defined('ODCM_PLUGIN_DIR') ? ODCM_PLUGIN_DIR . 'assets/css/odcm-design-system.css' : '';
        $ds_version = (file_exists($ds_path)) ? filemtime($ds_path) : $script_version;
        wp_enqueue_style(
            'odcm-design-system',
            $assets_url . 'css/odcm-design-system.css',
            [],
            $ds_version
        );

        wp_enqueue_style(
            'odcm-admin-styles',
            $assets_url . 'css/admin.css',
            ['odcm-design-system'],
            $script_version
        );

        wp_enqueue_style(
            'odcm-diagnostics',
            $assets_url . 'css/diagnostics.css',
            ['odcm-design-system', 'odcm-admin-styles', 'odcm-prism-css'],
            $script_version
        );

        // Localize script with AJAX data
        wp_localize_script('odcm-diagnostics', 'odcmDiagnostics', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('odcm_diagnostics'),
            'strings' => [
                'running' => __('diagnostics.ui.status.running', 'order-daemon'),
                'success' => __('diagnostics.ui.status.completed_successfully', 'order-daemon'),
                'error' => __('diagnostics.ui.status.error_running', 'order-daemon'),
                'passed' => __('diagnostics.ui.status.passed', 'order-daemon'),
                'issuesDetected' => __('diagnostics.ui.status.issues_detected', 'order-daemon'),
                'executed' => __('diagnostics.ui.status.executed', 'order-daemon'),
                'runningTests' => __('diagnostics.ui.status.running_tests', 'order-daemon'),
                /* translators: 1: current test number, 2: total number of tests */
                'testProgress' => __('diagnostics.ui.status.test_progress', 'order-daemon'),
                'timestampLabel' => __('diagnostics.ui.label.executed', 'order-daemon'),
                'systemHealthy' => __('diagnostics.ui.status.system_healthy', 'order-daemon'),
                'warningsFound' => __('diagnostics.ui.status.warnings_found', 'order-daemon'),
                'testsCompleted' => __('diagnostics.ui.status.tests_completed', 'order-daemon'),
                'preparingTests' => __('diagnostics.ui.status.preparing_tests', 'order-daemon'),
                'buttonRunning' => __('diagnostics.ui.button.running', 'order-daemon'),
                'justNow' => __('diagnostics.ui.time.just_now', 'order-daemon'),
                /* translators: %d: number of minutes */
                'minuteAgo' => __('diagnostics.ui.time.minute_ago', 'order-daemon'),
                /* translators: %d: number of minutes */
                'minutesAgo' => __('diagnostics.ui.time.minutes_ago', 'order-daemon'),
                /* translators: %d: number of hours */
                'hourAgo' => __('diagnostics.ui.time.hour_ago', 'order-daemon'),
                /* translators: %d: number of hours */
                'hoursAgo' => __('diagnostics.ui.time.hours_ago', 'order-daemon'),
                /* translators: %d: number of days */
                'dayAgo' => __('diagnostics.ui.time.day_ago', 'order-daemon'),
                /* translators: %d: number of days */
                'daysAgo' => __('diagnostics.ui.time.days_ago', 'order-daemon'),
                'selectTest' => __('diagnostics.ui.select_test_prompt', 'order-daemon'),
                /* translators: %s: test name */
                'testCompleted' => __('diagnostics.ui.status.test_completed', 'order-daemon'),
                'failedRunTest' => __('diagnostics.ui.error.failed_run_test', 'order-daemon'),
                'failedRunDiagnostics' => __('diagnostics.ui.error.failed_run_diagnostics', 'order-daemon'),
                'failedGenerateReport' => __('diagnostics.ui.error.failed_generate_report', 'order-daemon'),
                'executedPrefix' => __('diagnostics.ui.label.executed', 'order-daemon'),
                'errorTitle' => __('diagnostics.ui.error.title', 'order-daemon'),
                'diagnosticError' => __('diagnostics.ui.error.diagnostic_error', 'order-daemon'),
                'recommendationsIcon' => __('diagnostics.ui.label.recommendations', 'order-daemon'),
                'tryRefresh' => __('diagnostics.ui.error.try_refresh', 'order-daemon'),
                'checkConsole' => __('diagnostics.ui.error.check_console', 'order-daemon'),
                'contactSupport' => __('diagnostics.ui.error.contact_support', 'order-daemon'),
                'autoCopyFailed' => __('diagnostics.ui.error.auto_copy_failed', 'order-daemon'),
                'singleTestTitle' => __('diagnostics.ui.label.individual_test_result', 'order-daemon'),
                'recommendationsLabel' => __('diagnostics.ui.label.recommendations', 'order-daemon'),
                'detailsLabel' => __('diagnostics.ui.label.details', 'order-daemon'),
                'testsCompletedSuccessfully' => __('diagnostics.ui.status.tests_completed_successfully', 'order-daemon'),
                'copying' => __('diagnostics.ui.button.copying', 'order-daemon'),
                'copied' => __('diagnostics.ui.button.copied', 'order-daemon'),
                'copySuccess' => __('diagnostics.ui.clipboard.copy_success', 'order-daemon'),
            ],
        ]);
    }

    /**
     * Render the main diagnostic dashboard page
     *
     * @return void
     */
    public function render_dashboard_page(): void
    {
        $results = $this->runner->run_all_diagnostics();
        $report  = $this->runner->generate_report($results);

        $total_passed   = (int) ($report['summary']['passed'] ?? 0);
        $total_failed   = (int) ($report['summary']['failed'] ?? 0);
        $total_warnings = (int) ($report['summary']['warnings'] ?? 0);
        $total_tests    = (int) ($report['summary']['total_tests'] ?? 0);

        if ($total_failed > 0) {
            $banner_mod   = 'dx__banner--danger';
            $status_text  = sprintf('%d error%s', $total_failed, $total_failed > 1 ? 's' : '');
            $status_class = 'value--warn';
        } elseif ($total_warnings > 0) {
            $banner_mod   = 'dx__banner--warn';
            $status_text  = sprintf('%d warning%s', $total_warnings, $total_warnings > 1 ? 's' : '');
            $status_class = 'value--warn';
        } else {
            $banner_mod   = '';
            $status_text  = esc_html__('diagnostics.ui.status.system_healthy', 'order-daemon');
            $status_class = '';
        }

        $sys        = $report['system_info'];
        $od_version = $sys['order_daemon_version'] ?? (defined('ODCM_VERSION') ? ODCM_VERSION : 'unknown');
        $wp_version = $sys['wordpress_version'] ?? get_bloginfo('version');
        $php_version = $sys['php_version'] ?? PHP_VERSION;
        $wc_active  = (bool) ($sys['woocommerce_active'] ?? false);
        $wc_version = $wc_active && defined('WC_VERSION') ? WC_VERSION : 'inactive';

        $is_multisite = is_multisite() ? 'yes' : 'no';
        $active_theme = wp_get_theme();
        $theme_name   = $active_theme->get('Name') . ' ' . $active_theme->get('Version');
        $site_url     = site_url();
        $tz_string    = get_option('timezone_string');
        $tz           = $tz_string ?: 'UTC' . get_option('gmt_offset', '0');
        $hpos_on      = class_exists('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController');
        $wp_debug_on  = defined('WP_DEBUG') && WP_DEBUG;

        $active_rules  = wp_count_posts('odcm_order_rule');
        $rules_publish = (int) ($active_rules->publish ?? 0);
        $rules_draft   = (int) ($active_rules->draft ?? 0);
        $rules_total   = $rules_publish + $rules_draft;

        $debug_on = InsightDashboard::is_global_debug_active();
        $nonce    = wp_create_nonce('odcm_diagnostics');
        $ajax_url = admin_url('admin-ajax.php');

        $chevron = '<svg class="dx__section-toggle" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
        ?>
        <div class="odcm-diagnostics-page odcm-scope">

        <!-- Unified brand header -->
        <div class="odcm-unified-header">
          <div class="odcm-unified-header__brand">
            <svg width="22" height="22" viewBox="0 0 128 128" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" clip-rule="evenodd" d="M115.575 38.0075C116.022 37.9874 116.467 38.0076 116.911 38.0682C117.074 38.4076 117.013 38.7113 116.728 38.9793C110.372 46.5109 104.341 54.2856 98.6351 62.3033C96.4699 65.5014 94.365 68.7407 92.3207 72.0217C91.9153 72.364 91.4499 72.4652 90.9242 72.3254C90.2886 71.689 89.7422 70.9803 89.2849 70.1995C86.957 66.4332 84.6903 62.6269 82.4847 58.7804C82.0502 57.6476 82.4348 57.2225 83.6383 57.5049C84.8632 58.3251 85.9359 59.3171 86.8562 60.4811C88.0448 62.0349 89.2794 63.5533 90.5599 65.0366C90.8192 65.1137 91.062 65.0732 91.2885 64.9151C93.8523 61.257 96.4631 57.6329 99.1209 54.0427C102.193 50.037 105.492 46.2104 109.018 42.563C110.486 41.1616 112.064 39.9062 113.753 38.7971C114.358 38.4955 114.965 38.2322 115.575 38.0075Z"/>
              <path fill-rule="evenodd" clip-rule="evenodd" d="M35.5513 43.3525C42.0969 42.9637 47.8042 44.9275 52.6732 49.2443C55.272 51.6805 57.5994 54.3328 59.6555 57.2011C59.9332 57.5996 60.1558 58.0248 60.3234 58.4767C59.7453 59.6333 59.0167 60.6861 58.1376 61.6351C57.9623 61.73 57.8004 61.7097 57.6519 61.5744C55.3649 57.9691 52.5719 54.7904 49.2731 52.0383C45.2311 48.7875 40.6167 47.3702 35.4299 47.7865C28.6765 48.9939 24.2036 52.8611 22.0117 59.3878C20.1255 67.2545 22.4934 73.4702 29.1154 78.0348C36.2941 81.8873 42.9729 81.1584 49.1517 75.8482C51.201 73.9197 53.1236 71.8747 54.9197 69.7135C57.8808 65.82 60.7952 61.8921 63.6628 57.93C66.5835 53.9957 69.9633 50.4931 73.8023 47.4221C79.2353 43.7409 85.1045 42.85 91.41 44.7495C93.6407 45.4694 95.5633 46.6639 97.178 48.3332C97.8828 49.5333 97.5387 50.0395 96.1458 49.8517C88.3096 45.9676 81.0641 46.818 74.4095 52.4027C71.581 55.1104 68.9702 58.0056 66.5771 61.0885C63.2644 65.6579 59.9047 70.1931 56.4983 74.6941C53.4913 78.5538 49.7472 81.449 45.2659 83.3799C36.2004 86.3776 28.4086 84.4137 21.8902 77.4882C17.7008 72.3713 16.2032 66.5403 17.3973 59.9952C19.2203 52.0575 23.9764 46.7731 31.6655 44.1421C32.9642 43.8115 34.2594 43.5482 35.5513 43.3525Z"/>
              <path fill-rule="evenodd" clip-rule="evenodd" d="M103.31 62.6677C103.51 62.6417 103.692 62.6823 103.857 62.7892C104.012 63.0585 104.133 63.3421 104.221 63.6396C104.425 70.9849 101.692 76.9577 96.0244 81.5578C89.3454 85.7966 82.4643 86.1206 75.381 82.5296C70.5663 79.657 66.6198 75.8708 63.5414 71.1713C63.4421 70.7505 63.4827 70.3455 63.6628 69.9565C63.8724 69.8258 64.095 69.8055 64.3307 69.8958C67.5089 73.4036 71.1114 76.3595 75.1381 78.7637C79.4853 81.2028 84.0998 81.9317 88.9813 80.9504C93.9758 79.5176 97.7605 76.5414 100.335 72.0216C101.837 69.3362 102.728 66.4613 103.007 63.3966C103.076 63.1365 103.177 62.8936 103.31 62.6677Z"/>
            </svg>
            <span class="odcm-unified-header__title">Order Daemon</span>
          </div>
          <span class="odcm-unified-header__sep" aria-hidden="true">/</span>
          <span class="odcm-unified-header__crumb"><?php echo esc_html__('diagnostics.page.title', 'order-daemon'); ?></span>
        </div>

        <div class="dx odcm-page-body">

          <div class="dx__header">
            <div class="dx__header-text">
              <h3 class="dx__title odcm-page-title"><?php echo esc_html__('diagnostics.page.title', 'order-daemon'); ?></h3>
              <p class="dx__sub odcm-page-sub"><?php echo esc_html__('diagnostics.page.description', 'order-daemon'); ?></p>
            </div>
            <div class="dx__header-actions">
              <button class="odcm-btn odcm-btn--secondary odcm-btn--sm" id="odcm-run-health-check"
                      data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php echo esc_html__('diagnostics.page.button.run_full', 'order-daemon'); ?>
              </button>
              <button class="odcm-btn odcm-btn--primary odcm-btn--sm" id="odcm-copy-full-report"
                      data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php echo esc_html__('diagnostics.page.button.copy_to_clipboard', 'order-daemon'); ?>
              </button>
            </div>
          </div>

          <div class="dx__banner <?php echo esc_attr($banner_mod); ?>">
            <div class="dx__banner-status">
              <span class="label"><?php echo esc_html__('diagnostics.page.label.status', 'order-daemon'); ?></span>
              <span class="value <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_text); ?></span>
            </div>
            <div class="dx__banner-stats">
              <div class="dx__stat">
                <span class="label"><?php echo esc_html__('diagnostics.page.stat.health_checks', 'order-daemon'); ?></span>
                <span class="value"><?php echo esc_html("$total_passed / $total_tests passing"); ?></span>
              </div>
              <div class="dx__stat">
                <span class="label"><?php echo esc_html__('diagnostics.page.stat.rules_active', 'order-daemon'); ?></span>
                <span class="value"><?php echo esc_html("$rules_publish of $rules_total"); ?></span>
              </div>
            </div>
          </div>

          <!-- Health checks -->
          <section class="dx__section" data-collapsed="false">
            <div class="dx__section-head" data-toggle-section>
              <?php echo $chevron; // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <h4 class="dx__section-title"><?php echo esc_html__('diagnostics.page.section.health_checks', 'order-daemon'); ?></h4>
              <div class="dx__section-meta">
                <?php if ($total_warnings > 0): ?>
                <span class="odcm-pill odcm-pill--warn"><span class="odcm-pill__dot"></span><?php echo esc_html(sprintf('%d warning%s', $total_warnings, $total_warnings > 1 ? 's' : '')); ?></span>
                <?php endif; ?>
                <?php if ($total_failed > 0): ?>
                <span class="odcm-pill odcm-pill--danger"><?php echo esc_html(sprintf('%d failed', $total_failed)); ?></span>
                <?php endif; ?>
                <span class="odcm-pill odcm-pill--success"><?php echo esc_html(sprintf('%d passing', $total_passed)); ?></span>
                <button class="dx__copy" data-copy-source="hc-copy-text">Copy</button>
              </div>
            </div>
            <pre id="hc-copy-text" hidden><?php echo esc_html($this->generate_health_checks_copy_text($report)); ?></pre>
            <div class="dx__section-body">
              <?php foreach ($report['categories'] as $cat_name => $cat_data): ?>
                <?php
                $cat_passed   = (int) ($cat_data['passed'] ?? 0);
                $cat_total    = (int) ($cat_data['total'] ?? 0);
                $cat_warnings = 0;
                $cat_failures = 0;
                foreach ($cat_data['tests'] as $t) {
                    if ($t['status'] === 'warning') $cat_warnings++;
                    if ($t['status'] === 'error' || $t['status'] === 'failed') $cat_failures++;
                }
                $cat_collapsed = ($cat_warnings === 0 && $cat_failures === 0) ? 'true' : 'false';
                ?>
                <div class="dx__cat" data-collapsed="<?php echo esc_attr($cat_collapsed); ?>">
                  <div class="dx__cat-head" data-toggle-cat>
                    <svg class="dx__cat-toggle" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    <span class="dx__cat-title"><?php echo esc_html($cat_name); ?></span>
                    <div class="dx__cat-meta">
                      <?php if ($cat_warnings > 0): ?>
                      <span class="odcm-pill odcm-pill--warn odcm-pill--xs"><span class="odcm-pill__dot"></span><?php echo esc_html($cat_warnings); ?></span>
                      <?php endif; ?>
                      <?php if ($cat_failures > 0): ?>
                      <span class="odcm-pill odcm-pill--danger odcm-pill--xs"><?php echo esc_html($cat_failures); ?> failed</span>
                      <?php endif; ?>
                      <span class="odcm-pill odcm-pill--success odcm-pill--xs"><?php echo esc_html("$cat_passed / $cat_total"); ?></span>
                    </div>
                  </div>
                  <div class="dx__cat-body">
                    <?php foreach ($cat_data['tests'] as $test_key => $test): ?>
                      <?php
                      $is_warn       = ($test['status'] === 'warning');
                      $is_fail       = ($test['status'] === 'error' || $test['status'] === 'failed');
                      $mod           = $is_warn ? ' dx__check--warn' : ($is_fail ? ' dx__check--fail' : '');
                      $icon          = $is_warn ? '!' : ($is_fail ? '✕' : '✓');
                      $pill_mod      = $is_warn ? 'odcm-pill--warn' : ($is_fail ? 'odcm-pill--danger' : 'odcm-pill--success');
                      $pill_lbl      = $is_warn ? 'warn' : ($is_fail ? 'fail' : 'pass');
                      $has_verbose   = !empty($test['message']) || !empty($test['recommendations']) || !empty($test['details']);
                      $collapsed_val = ($is_warn || $is_fail) ? 'false' : 'true';
                      $expander_svg  = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
                      ?>
                      <div class="dx__check<?php echo esc_attr($mod); ?>"<?php echo $has_verbose ? ' data-collapsed="' . esc_attr($collapsed_val) . '"' : ''; ?>>
                        <span class="dx__check-icon"><?php echo esc_html($icon); ?></span>
                        <div class="dx__check-name"><?php echo esc_html($test['name']); ?></div>
                        <span class="odcm-pill <?php echo esc_attr($pill_mod); ?>"><?php echo esc_html($pill_lbl); ?></span>
                        <?php if ($has_verbose): ?>
                        <button class="dx__check-expander" aria-label="Toggle details" type="button"><?php echo $expander_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
                        <?php else: ?>
                        <span></span>
                        <?php endif; ?>
                        <?php if ($has_verbose): ?><div class="dx__check-detail"><?php if (!empty($test['message'])): ?><div class="detail-message"><?php echo esc_html(trim($test['message'])); ?></div><?php endif; ?><?php if (!empty($test['recommendations'])): ?><ul class="detail-recs"><?php foreach ($test['recommendations'] as $rec): ?><li><?php echo esc_html(trim($rec)); ?></li><?php endforeach; ?></ul><?php endif; ?><?php if (!empty($test['details'])): ?><div class="detail-tree"><?php echo wp_kses_post($this->render_nested_details($test['details'])); ?></div><?php endif; ?></div><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <!-- Environment -->
          <section class="dx__section" data-collapsed="true">
            <div class="dx__section-head" data-toggle-section>
              <?php echo $chevron; // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <h4 class="dx__section-title"><?php echo esc_html__('diagnostics.page.section.environment', 'order-daemon'); ?></h4>
              <div class="dx__section-meta">
                <button class="dx__copy" data-copy-source="env-copy-text">Copy</button>
              </div>
            </div>
            <pre id="env-copy-text" hidden><?php echo esc_html($this->generate_environment_copy_text($od_version, $wp_version, $php_version, $is_multisite, $theme_name, $wp_debug_on, $site_url, $tz, $wc_active, $wc_version, $hpos_on)); ?></pre>
            <div class="dx__section-body">

              <div class="dx__group">
                <span class="dx__group-label"><?php echo esc_html__('diagnostics.page.env.plugin', 'order-daemon'); ?></span>
                <div class="dx__group-body">
                  <dl class="dx__row"><dt>order-daemon</dt><dd><?php echo esc_html('v' . $od_version); ?></dd><span></span></dl>
                  <?php if (defined('ODCM_PLUGIN_FILE')): ?>
                  <dl class="dx__row"><dt>install path</dt><dd><?php echo esc_html(plugin_dir_path(ODCM_PLUGIN_FILE)); ?></dd><span></span></dl>
                  <?php endif; ?>
                </div>
              </div>

              <div class="dx__group">
                <span class="dx__group-label">WordPress</span>
                <div class="dx__group-body">
                  <dl class="dx__row"><dt>wp version</dt><dd><?php echo esc_html($wp_version); ?></dd><span></span></dl>
                  <dl class="dx__row"><dt>multisite</dt><dd><?php echo esc_html($is_multisite); ?></dd><span></span></dl>
                  <dl class="dx__row"><dt>active theme</dt><dd><?php echo esc_html($theme_name); ?></dd><span></span></dl>
                  <dl class="dx__row"><dt>wp_debug</dt><dd><?php echo esc_html($wp_debug_on ? 'on' : 'off'); ?></dd><span></span></dl>
                  <dl class="dx__row"><dt>site_url</dt><dd><?php echo esc_html($site_url); ?></dd><span></span></dl>
                  <dl class="dx__row"><dt>timezone</dt><dd><?php echo esc_html($tz); ?></dd><span></span></dl>
                </div>
              </div>

              <?php if ($wc_active): ?>
              <div class="dx__group">
                <span class="dx__group-label">WooCommerce</span>
                <div class="dx__group-body">
                  <dl class="dx__row"><dt>woocommerce</dt><dd><?php echo esc_html($wc_version); ?></dd><span></span></dl>
                  <dl class="dx__row"><dt>hpos</dt><dd><?php echo esc_html($hpos_on ? 'enabled' : 'disabled'); ?></dd><span></span></dl>
                </div>
              </div>
              <?php endif; ?>

              <div class="dx__group">
                <span class="dx__group-label">Server</span>
                <div class="dx__group-body">
                  <dl class="dx__row"><dt>php</dt><dd><?php echo esc_html($php_version); ?></dd><span></span></dl>
                </div>
              </div>

            </div>
          </section>

          <!-- Debug controls -->
          <section class="dx__section dx__section--danger" data-collapsed="true">
            <div class="dx__section-head" data-toggle-section>
              <?php echo $chevron; // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <h4 class="dx__section-title"><?php echo esc_html__('diagnostics.page.section.debug_controls', 'order-daemon'); ?></h4>
              <div class="dx__section-meta">
                <span class="odcm-pill"><span class="odcm-pill__dot"></span><?php echo esc_html($debug_on ? '1 enabled' : '0 enabled'); ?></span>
              </div>
            </div>
            <div class="dx__section-body">
              <div class="dx__controls">
                <div class="dx__control-row">
                  <span class="desc">
                    <?php echo esc_html__('diagnostics.page.debug.verbose_logging.label', 'order-daemon'); ?>
                    <span class="sub"><?php echo esc_html__('diagnostics.page.debug.verbose_logging.desc', 'order-daemon'); ?></span>
                  </span>
                  <button class="odcm-toggle" role="switch"
                          aria-checked="<?php echo esc_attr($debug_on ? 'true' : 'false'); ?>"
                          data-toggle-option="odcm_debug"
                          data-nonce="<?php echo esc_attr($nonce); ?>">
                    <span class="odcm-toggle__thumb"></span>
                  </button>
                </div>
              </div>
              <div class="dx__actions">
                <button class="odcm-btn odcm-btn--secondary odcm-btn--sm" id="odcm-export-logs"
                        data-nonce="<?php echo esc_attr($nonce); ?>">
                  <?php echo esc_html__('diagnostics.page.action.export_logs', 'order-daemon'); ?>
                </button>
                <button class="odcm-btn odcm-btn--secondary odcm-btn--sm" id="odcm-flush-caches"
                        data-nonce="<?php echo esc_attr($nonce); ?>">
                  <?php echo esc_html__('diagnostics.page.action.flush_caches', 'order-daemon'); ?>
                </button>
              </div>
            </div>
          </section>

        </div><!-- .dx -->
        </div><!-- .odcm-diagnostics-page -->
        <script>
        (function(){
          // Shared clipboard helper with textarea fallback
          function odcmCopyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              return navigator.clipboard.writeText(text);
            }
            return new Promise(function(resolve, reject) {
              var ta = document.createElement('textarea');
              ta.value = text;
              ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px';
              document.body.appendChild(ta);
              ta.focus(); ta.select();
              try { document.execCommand('copy') ? resolve() : reject(new Error('execCommand failed')); }
              catch(e) { reject(e); }
              document.body.removeChild(ta);
            });
          }

          // ODCMToasts wrapper
          function odcmToast(msg, type) {
            if (window.ODCMToasts && typeof window.ODCMToasts.show === 'function') {
              window.ODCMToasts.show(msg, type);
            }
          }

          // Section collapse/expand
          document.querySelectorAll('[data-toggle-section]').forEach(function(head) {
            head.addEventListener('click', function() {
              var sect = head.closest('.dx__section');
              sect.setAttribute('data-collapsed', sect.getAttribute('data-collapsed') === 'true' ? 'false' : 'true');
            });
          });

          // Category sub-section collapse/expand
          document.querySelectorAll('[data-toggle-cat]').forEach(function(head) {
            head.addEventListener('click', function(e) {
              if (e.target.closest('[data-copy-source]')) return;
              var cat = head.closest('.dx__cat');
              cat.setAttribute('data-collapsed', cat.getAttribute('data-collapsed') === 'true' ? 'false' : 'true');
            });
          });

          // Individual check expand/collapse
          document.querySelectorAll('.dx__check[data-collapsed]').forEach(function(check) {
            check.addEventListener('click', function(e) {
              if (e.target.closest('[data-copy-source]')) return;
              if (e.target.closest('[data-toggle-cat]')) return;
              check.setAttribute('data-collapsed', check.getAttribute('data-collapsed') === 'true' ? 'false' : 'true');
            });
          });

          // Per-section copy buttons
          document.querySelectorAll('[data-copy-source]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
              e.stopPropagation();
              var src = document.getElementById(btn.dataset.copySource);
              if (!src) return;
              var orig = btn.textContent;
              odcmCopyText(src.textContent.trim()).then(function() {
                btn.textContent = 'Copied!';
                btn.classList.add('dx__copy--copied');
                odcmToast('Copied to clipboard', 'success');
                setTimeout(function() { btn.textContent = orig; btn.classList.remove('dx__copy--copied'); }, 1400);
              }).catch(function() {
                odcmToast('Copy failed — try selecting the text manually', 'error');
              });
            });
          });

          // Debug option toggle
          document.querySelectorAll('[data-toggle-option]').forEach(function(btn) {
            btn.addEventListener('click', function() {
              var checked = btn.getAttribute('aria-checked') === 'true';
              var newVal  = !checked;
              btn.setAttribute('aria-checked', String(newVal));
              var fd = new FormData();
              fd.append('action', 'odcm_toggle_debug_option');
              fd.append('nonce',  btn.dataset.nonce);
              fd.append('option', btn.dataset.toggleOption);
              fd.append('value',  newVal ? '1' : '0');
              fetch(<?php echo wp_json_encode($ajax_url); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(d){
                  if (d.success) {
                    odcmToast(newVal ? 'Verbose logging enabled' : 'Verbose logging disabled', 'success');
                  }
                });
            });
          });

          // Run health check (page reload)
          var runBtn = document.getElementById('odcm-run-health-check');
          if (runBtn) {
            runBtn.addEventListener('click', function() {
              runBtn.disabled = true;
              location.reload();
            });
          }

          // Copy full report
          var copyBtn = document.getElementById('odcm-copy-full-report');
          if (copyBtn) {
            copyBtn.addEventListener('click', function() {
              var origText = copyBtn.textContent;
              copyBtn.disabled = true;
              var fd = new FormData();
              fd.append('action', 'odcm_generate_dual_report');
              fd.append('nonce',  copyBtn.dataset.nonce);
              fetch(<?php echo wp_json_encode($ajax_url); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(d){
                  if (d.success && d.data && d.data.report) {
                    odcmCopyText(d.data.report).then(function() {
                      copyBtn.textContent = <?php echo wp_json_encode(__('diagnostics.ui.button.copied', 'order-daemon')); ?>;
                      odcmToast('Report copied to clipboard', 'success');
                      setTimeout(function(){ copyBtn.textContent = origText; copyBtn.disabled = false; }, 1400);
                    }).catch(function() {
                      odcmToast('Copy failed — try again', 'error');
                      copyBtn.textContent = origText;
                      copyBtn.disabled = false;
                    });
                  } else {
                    odcmToast('Failed to generate report', 'error');
                    copyBtn.textContent = origText;
                    copyBtn.disabled = false;
                  }
                }).catch(function() {
                  odcmToast('Failed to generate report', 'error');
                  copyBtn.textContent = origText;
                  copyBtn.disabled = false;
                });
            });
          }
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler for toggling a debug option
     *
     * @return void
     */
    public function ajax_toggle_debug_option(): void
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'odcm_diagnostics')) {
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('security.permission_denied', 'order-daemon')]);
        }
        $allowed_options = ['odcm_debug'];
        $option = sanitize_text_field(wp_unslash($_POST['option'] ?? ''));
        if (!in_array($option, $allowed_options, true)) {
            wp_send_json_error(['message' => 'Invalid option.']);
        }
        $value = !empty($_POST['value']) && $_POST['value'] !== '0' ? 1 : 0;
        update_option($option, $value, 'no');
        wp_send_json_success(['option' => $option, 'value' => $value]);
    }

    /**
     * AJAX handler for running all diagnostics
     *
     * @return void
     */
    public function ajax_run_diagnostics(): void
    {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'odcm_diagnostics')) {
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
        }

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('security.permission_denied', 'order-daemon')]);
        }

        try {
            $category = sanitize_text_field( wp_unslash($_POST['category'] ?? '') );
            
            if ($category && $category !== 'all') {
                $results = $this->runner->run_category_diagnostics($category);
            } else {
                $results = $this->runner->run_all_diagnostics();
            }

            $report = $this->runner->generate_report($results);

            wp_send_json_success([
                'results' => array_map(function($result) {
                    return $result->toArray();
                }, $results),
                'report' => $report,
                'html' => $this->render_results_html($report)
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error([
                /* translators: %s: The error message that occurred while running diagnostics */
                'message' => sprintf(__('diagnostics.ajax.error.failed_run_diagnostics_detail', 'order-daemon'), $e->getMessage())
            ]);
        }
    }

    /**
     * AJAX handler for running a single diagnostic
     *
     * @return void
     */
    public function ajax_run_single_diagnostic(): void
    {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'odcm_diagnostics')) {
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
        }

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('security.permission_denied', 'order-daemon')]);
        }

        try {
            $diagnostic_key = sanitize_text_field( wp_unslash($_POST['diagnostic'] ?? '') );
            
            if (empty($diagnostic_key)) {
                wp_send_json_error(['message' => __('diagnostics.ajax.error.no_diagnostic_specified', 'order-daemon')]);
            }

            $result = $this->runner->run_diagnostic($diagnostic_key);

            if (!$result) {
                wp_send_json_error(['message' => __('diagnostics.ajax.error.diagnostic_not_found', 'order-daemon')]);
            }

            wp_send_json_success([
                'result' => $result->toArray(),
                'html' => $this->render_single_result_html($result->toArray())
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error([
                /* translators: %s: The error message that occurred while running a single diagnostic */
                'message' => sprintf(__('diagnostics.ajax.error.failed_run_diagnostic_detail', 'order-daemon'), $e->getMessage())
            ]);
        }
    }

    /**
     * AJAX handler for generating dual-audience report
     *
     * @return void
     */
    public function ajax_generate_dual_report(): void
    {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'odcm_diagnostics')) {
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
        }

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('security.permission_denied', 'order-daemon')]);
        }

        try {
            // Run fresh diagnostics to get complete data
            $results = $this->runner->run_all_diagnostics();
            $report = $this->runner->generate_report($results);
            
            // Generate complete formatted text report that matches the visual display
            $formatted_report = $this->generate_complete_text_report($report);

            wp_send_json_success([
                'report' => $formatted_report,
                'message' => __('diagnostics.ajax.report_generated_successfully', 'order-daemon')
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %s: The error message that occurred while generating dual-audience report */
                        __('diagnostics.ajax.error.failed_generate_report_detail', 'order-daemon'),
                        $e->getMessage()
                    )
            ]);
        }
    }

    /**
     * Render HTML for diagnostic results
     *
     * @param array $report The diagnostic report
     * @return string HTML output
     */
    private function render_results_html(array $report): string
    {
        ob_start();
        ?>
        <div class="odcm-report">
            <div class="odcm-report-summary">
                <h4><?php esc_html_e('diagnostics.results.section.summary', 'order-daemon'); ?></h4>
                    <p>
                    <?php
                    /* translators: 1: Number of tests run, 2: Number passed, 3: Number failed */
                    printf(esc_html__('diagnostics.results.summary.tests_ran', 'order-daemon'),
                           esc_html($report['summary']['total_tests']),
                           esc_html($report['summary']['passed']),
                           esc_html($report['summary']['failed']));
                    ?>
                </p>
            </div>

            <?php if (!empty($report['critical_issues'])): ?>
            <div class="odcm-critical-issues">
                <h4><?php esc_html_e('diagnostics.results.section.critical_issues', 'order-daemon'); ?></h4>
                <ul>
                    <?php foreach ($report['critical_issues'] as $issue): ?>
                    <li class="odcm-error">
                        <strong><?php echo esc_html($issue['name']); ?>:</strong> 
                        <?php echo esc_html($issue['message']); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($report['recommendations'])): ?>
            <div class="odcm-all-recommendations">
                <h4><?php esc_html_e('diagnostics.results.section.all_recommendations', 'order-daemon'); ?></h4>
                <ul>
                    <?php foreach ($report['recommendations'] as $rec): ?>
                    <li>
                        <strong><?php echo esc_html( $this->format_category_name($rec['category'] ?? 'general') ); ?>:</strong> 
                        <?php echo esc_html($rec['recommendation']); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($report['categories'])): ?>
            <div class="odcm-detailed-results">
                <h4><?php esc_html_e('diagnostics.results.section.detailed_results', 'order-daemon'); ?></h4>
                <?php foreach ($report['categories'] as $category_name => $category_data): ?>
                <div class="odcm-category-results">
                    <h5><?php
                    printf(
                           /* translators: 1: Category name, 2: Number passed, 3: Total number */
                           esc_html__('diagnostics.results.category.passed_count', 'order-daemon'),
                           esc_html( $this->format_category_name($category_name) ),
                           esc_html($category_data['passed']),
                           esc_html($category_data['total'])
                    );
                    ?></h5>
                    
                    <?php foreach ($category_data['tests'] as $test_key => $test_result): ?>
                    <?php 
                    // Determine the appropriate icon based on status
                    $status_icon = '❌'; // default
                    if ($test_result['status'] === 'success' || $test_result['status'] === 'passed') {
                        $status_icon = (!empty($test_result['recommendations'])) ? '⚠️' : '✅';
                    } elseif ($test_result['status'] === 'warning') {
                        $status_icon = '⚠️';
                    } elseif ($test_result['status'] === 'error' || $test_result['status'] === 'failed') {
                        $message_lower = strtolower($test_result['message']);
                        if (strpos($message_lower, 'critical') !== false || strpos($message_lower, 'fatal') !== false) {
                            $status_icon = '🔴';
                        } elseif (strpos($message_lower, 'warning') !== false || strpos($message_lower, 'recommend') !== false) {
                            $status_icon = '⚠️';
                        }
                    }
                    ?>
                    <div class="odcm-test-result odcm-test-result--<?php echo esc_attr($test_result['status']); ?>">
                        <div class="odcm-test-result-header">
                            <span class="odcm-test-icon"><?php echo esc_html($status_icon); ?></span>
                            <h4 class="odcm-test-name"><?php echo esc_html($test_result['name']); ?></h4>
                        </div>
                        
                        <p class="odcm-test-message">
                            <?php echo esc_html($test_result['message']); ?>
                        </p>
                        
                        <?php if (!empty($test_result['recommendations'])): ?>
                        <div class="odcm-test-recommendations">
                            <strong><?php esc_html_e('diagnostics.results.label.recommendations', 'order-daemon'); ?>:</strong>
                            <ul>
                                <?php foreach ($test_result['recommendations'] as $rec): ?>
                                <li><?php echo esc_html($rec); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($test_result['details'])): ?>
                        <div class="odcm-test-details">
                            <h6><?php esc_html_e('diagnostics.results.label.technical_details', 'order-daemon'); ?>:</h6>
                            <div class="odcm-technical-info">
                                <?php 
                                $rendered_output = $this->render_nested_details($test_result['details']);
                                
                                // Output the full details without truncation. Escaping is already handled in render_nested_details().
                                echo wp_kses_post($rendered_output);
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render HTML for a single diagnostic result
     *
     * @param array $result The diagnostic result
     * @return string HTML output
     */
    private function render_single_result_html(array $result): string
    {
        ob_start();
        $status_icon = $result['successful'] ? '✅' : '❌';
        $status_class = $result['successful'] ? 'success' : 'error';
        ?>
        <div class="odcm-results-category">
            <h3 class="odcm-category-title"><?php echo esc_html__('diagnostics.ui.label.individual_test_result', 'order-daemon'); ?></h3>
            <div class="odcm-test-result odcm-test-result--<?php echo esc_attr($status_class); ?>">
                <div class="odcm-test-result-header">
                    <span class="odcm-test-icon"><?php echo esc_html($status_icon); ?></span>
                    <h4 class="odcm-test-name"><?php echo esc_html($result['name']); ?></h4>
                </div>
                
                <p class="odcm-test-message"><?php echo esc_html($result['message']); ?></p>
                
                <?php if (!empty($result['recommendations'])): ?>
                <div class="odcm-test-recommendations">
                    <strong><?php esc_html_e('diagnostics.results.label.recommendations', 'order-daemon'); ?>:</strong>
                    <ul>
                        <?php foreach ($result['recommendations'] as $rec): ?>
                        <li><?php echo esc_html($rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($result['details'])): ?>
                <div class="odcm-test-details">
                    <h6><?php esc_html_e('diagnostics.results.label.technical_details', 'order-daemon'); ?>:</h6>
                    <div class="odcm-technical-info">
                        <?php echo wp_kses_post( $this->render_nested_details($result['details']) ); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render nested details with unified logic for both HTML and text output
     *
     * @param array $details The details array to render
     * @param int $level Current nesting level (for indentation)
     * @param bool $for_html Whether to format for HTML display (true) or plain text (false)
     * @param array $ancestry_path Track which ancestor levels have remaining siblings
     * @return string Formatted output
     */
    private function render_nested_details(array $details, int $level = 0, bool $for_html = true, array $ancestry_path = []): string
    {
        if (empty($details)) {
            $no_details_text = __('diagnostics.results.label.no_details', 'order-daemon');
            return $for_html ? '<pre><code class="language-bash">' . $no_details_text . '</code></pre>' : $no_details_text;
        }

        $lines = [];
        $keys = array_keys($details);
        $total_keys = count($keys);

        foreach ($keys as $index => $key) {
            $value = $details[$key];
            $is_last = ($index + 1) === $total_keys;
            
            $this->render_detail_line($key, $value, $level, $is_last, $lines, $for_html, $ancestry_path);
        }

        $plain_text = implode("\n", $lines);
        
                // Only return content if we have actual data
                $trimmed = trim($plain_text);
                if ($trimmed) {
                    // Cache detailed output for better performance
                    $cache_key = 'odcm_detail_output_' . md5($trimmed);
                    $cached_output = wp_cache_get($cache_key);

                    if (false === $cached_output && $for_html) {
                        $cached_output = '<pre><code class="language-bash">' . esc_html($trimmed) . '</code></pre>';
                        wp_cache_set($cache_key, $cached_output, '', HOUR_IN_SECONDS);
                    }

                    return $for_html ? ($cached_output ?: '<pre><code class="language-bash">' . esc_html($trimmed) . '</code></pre>') : $trimmed;
                }
        
        return $for_html ? '' : '';
    }

    /**
     * Render a single detail line with unified logic for both HTML and text output
     *
     * @param string|int $key The detail key  
     * @param mixed $value The detail value
     * @param int $level Current nesting level (for indentation)
     * @param bool $is_last Whether this is the last item at this level
     * @param array &$lines Array to append lines to
     * @param bool $for_html Whether formatting for HTML display or plain text
     * @param array $ancestry_path Track which ancestor levels have remaining siblings
     * @return void
     */
    private function render_detail_line($key, $value, int $level, bool $is_last, array &$lines, bool $for_html = true, array $ancestry_path = []): void
    {
        // Build the correct indentation based on ancestry path
        $indent = '';
        for ($i = 0; $i < $level; $i++) {
            // Only show vertical line if this ancestor level has more siblings coming
            if (isset($ancestry_path[$i]) && $ancestry_path[$i]) {
                $indent .= '│  ';
            } else {
                $indent .= '   '; // Three spaces to match the width of '│  '
            }
        }
        
        $connector = $is_last ? '└─' : '├─';
        $line = $indent . $connector . ' ';
        $line .= $this->format_detail_key($key) . ': ';
        
        // Format the value
        if (is_null($value)) {
            $line .= 'null';
            $lines[] = $line;
        } elseif (is_bool($value)) {
            $line .= $value ? 'true' : 'false';
            $lines[] = $line;
        } elseif (is_string($value) || is_numeric($value)) {
            $line .= (string)$value;
            $lines[] = $line;
        } elseif (is_array($value)) {
            if (empty($value)) {
                $line .= '(empty array)';
                $lines[] = $line;
            } else {
                $count = count($value);
                if ($this->is_associative_array($value)) {
                    $line .= '{' . $count . ' items}';
                } else {
                    $line .= '[' . $count . ' items]';
                }
                $lines[] = $line;
                
                // Add child lines with updated ancestry path
                $child_keys = array_keys($value);
                $total_children = count($child_keys);
                
                foreach ($child_keys as $child_index => $child_key) {
                    $child_value = $value[$child_key];
                    $is_last_child = ($child_index + 1) === $total_children;
                    
                    // Update ancestry path: current level has siblings unless this is the last item
                    $child_ancestry_path = $ancestry_path;
                    $child_ancestry_path[$level] = !$is_last;
                    
                    $this->render_detail_line($child_key, $child_value, $level + 1, $is_last_child, $lines, $for_html, $child_ancestry_path);
                }
            }
        } elseif (is_object($value)) {
            $line .= get_class($value) . ' object';
            $lines[] = $line;
        } else {
            $line .= gettype($value);
            $lines[] = $line;
        }
    }

    /**
     * Check if array is associative
     *
     * @param array $array The array to check
     * @return bool True if associative, false if indexed
     */
    private function is_associative_array(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Format detail key for display
     *
     * @param string|int $key The key to format
     * @return string Formatted key
     */
    private function format_detail_key($key): string
    {
        if (is_numeric($key)) {
            return (string)$key;
        }
        
        // Convert snake_case and kebab-case to Title Case
        $formatted = str_replace(['_', '-'], ' ', (string)$key);
        $formatted = ucwords($formatted);
        
        return $formatted;
    }

    /**
     * Generate plain-text copy content for the Health Checks section.
     *
     * @param array $report The diagnostic report from DiagnosticRunner::generate_report()
     * @return string Plain text suitable for clipboard
     */
    private function generate_health_checks_copy_text(array $report): string
    {
        $out = "HEALTH CHECKS\n=============\n";

        foreach ($report['categories'] as $cat_name => $cat_data) {
            foreach ($cat_data['tests'] as $test_key => $test) {
                $is_fail = ($test['status'] === 'error' || $test['status'] === 'failed');
                $is_warn = ($test['status'] === 'warning');
                $icon    = $is_fail ? '✕' : ($is_warn ? '!' : '✓');

                $out .= "\n{$icon} {$test['name']} [{$cat_name}] — {$test['status']}\n";

                if (!empty($test['message'])) {
                    $out .= trim($test['message']) . "\n";
                }

                if (!empty($test['recommendations'])) {
                    $out .= "Recommendations:\n";
                    foreach ($test['recommendations'] as $rec) {
                        $out .= "  • " . trim($rec) . "\n";
                    }
                }

                if (!empty($test['details'])) {
                    $details_text = $this->render_nested_details($test['details'], 0, false);
                    if (trim($details_text)) {
                        $out .= "Technical details:\n";
                        $out .= trim($details_text) . "\n";
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Generate plain-text copy content for the Environment section.
     *
     * @param string $od_version
     * @param string $wp_version
     * @param string $php_version
     * @param string $is_multisite
     * @param string $theme_name
     * @param bool   $wp_debug_on
     * @param string $site_url
     * @param string $tz
     * @param bool   $wc_active
     * @param string $wc_version
     * @param bool   $hpos_on
     * @return string Plain text suitable for clipboard
     */
    private function generate_environment_copy_text(
        string $od_version,
        string $wp_version,
        string $php_version,
        string $is_multisite,
        string $theme_name,
        bool   $wp_debug_on,
        string $site_url,
        string $tz,
        bool   $wc_active,
        string $wc_version,
        bool   $hpos_on
    ): string {
        $out  = "ENVIRONMENT\n===========\n";

        $out .= "\nPlugin:\n";
        $out .= "  order-daemon: v{$od_version}\n";
        if (defined('ODCM_PLUGIN_FILE')) {
            $out .= '  install path: ' . plugin_dir_path(ODCM_PLUGIN_FILE) . "\n";
        }

        $out .= "\nWordPress:\n";
        $out .= "  wp version:   {$wp_version}\n";
        $out .= "  multisite:    {$is_multisite}\n";
        $out .= "  active theme: {$theme_name}\n";
        $out .= '  wp_debug:     ' . ($wp_debug_on ? 'on' : 'off') . "\n";
        $out .= "  site_url:     {$site_url}\n";
        $out .= "  timezone:     {$tz}\n";

        if ($wc_active) {
            $out .= "\nWooCommerce:\n";
            $out .= "  woocommerce: {$wc_version}\n";
            $out .= '  hpos:        ' . ($hpos_on ? 'enabled' : 'disabled') . "\n";
        }

        $out .= "\nServer:\n";
        $out .= "  php: {$php_version}\n";

        return $out;
    }

    /**
     * Generate a complete text report that matches the visual display
     *
     * @param array $report The diagnostic report data
     * @return string Formatted text report
     */
    private function generate_complete_text_report(array $report): string
    {
        $output = '';
        
        // Header
        $output .= __('diagnostics.report.title', 'order-daemon') . "\n";
        /* translators: %s: Date and time when the report was generated */
        $output .= sprintf(__('diagnostics.report.generated_at', 'order-daemon'), current_time('Y-m-d H:i:s T')) . "\n";
        /* translators: %s: The Order Daemon plugin version number */
        $output .= sprintf(__('diagnostics.report.plugin_version', 'order-daemon'), defined('ODCM_VERSION') ? ODCM_VERSION : __('diagnostics.report.unknown_plugin_version', 'order-daemon')) . "\n";
        $output .= "\n";

        // Report Summary
        $output .= __('diagnostics.report.section.summary', 'order-daemon') . "\n";
        $output .= "-------\n";
                $output .= sprintf(
                    /* translators: 1: Total tests run, 2: Number passed, 3: Number failed */
                    __('diagnostics.report.summary.tests_run', 'order-daemon'),
                    $report['summary']['total_tests'],
                    $report['summary']['passed'],
                    $report['summary']['failed']
                ) . "\n";
        $output .= "\n";

        // Critical Issues Section (if any)
        if (!empty($report['critical_issues'])) {
            $output .= __('diagnostics.report.section.critical_issues', 'order-daemon') . "\n";
            $output .= "---------------\n";
            foreach ($report['critical_issues'] as $issue) {
                $output .= sprintf("❌ %s: %s\n", $issue['name'], $issue['message']);
            }
            $output .= "\n";
        }

        // All Recommendations Section (if any)
        if (!empty($report['recommendations'])) {
            $output .= __('diagnostics.report.section.all_recommendations', 'order-daemon') . "\n";
            $output .= "-------------------\n";
            foreach ($report['recommendations'] as $rec) {
                $category_label = $this->format_category_name($rec['category'] ?? 'general');
                $output .= sprintf("💡 %s: %s\n", $category_label, $rec['recommendation']);
            }
            $output .= "\n";
        }

        // Detailed Results by Category
        if (!empty($report['categories'])) {
            $output .= __('diagnostics.report.section.detailed_results', 'order-daemon') . "\n";
            $output .= "----------------\n";
            
            foreach ($report['categories'] as $category_name => $category_data) {
                $category_label = $this->format_category_name($category_name);
                /* translators: 1: Category name (uppercase), 2: Number passed, 3: Total number */
                $output .= sprintf(
                    // translators: 1: Category name in uppercase, 2: Number of tests passed, 3: Total number of tests
                    __('diagnostics.report.category.header', 'order-daemon'),
                    strtoupper($category_label),
                    $category_data['passed'],
                    $category_data['total']
                ) . "\n";
                $output .= str_repeat('=', strlen($category_label) + 25) . "\n";
                
                foreach ($category_data['tests'] as $test_key => $test_result) {
                    // Determine status icon
                    $status_icon = $this->get_status_icon_for_text($test_result);
                    
                    $output .= sprintf("\n%s %s\n", $status_icon, $test_result['name']);
                    /* translators: %s: The test status (e.g., Success, Error, Warning) */
                    $output .= sprintf(__('diagnostics.report.test.status', 'order-daemon'), ucfirst($test_result['status'])) . "\n";
                    /* translators: %s: The diagnostic message text */
                    $output .= sprintf(__('diagnostics.report.test.message', 'order-daemon'), $test_result['message']) . "\n";

                    // Add recommendations if any
                    if (!empty($test_result['recommendations'])) {
                        $output .= __('diagnostics.report.label.recommendations', 'order-daemon') . "\n";
                        foreach ($test_result['recommendations'] as $rec) {
                            $output .= sprintf("   • %s\n", $rec);
                        }
                    }
                    
                    // Add technical details if any
                    if (!empty($test_result['details'])) {
                        $output .= __('diagnostics.report.label.technical_details', 'order-daemon') . "\n";
                        $details_text = $this->render_nested_details($test_result['details'], 1, false);
                        if (trim($details_text)) {
                            // Add proper indentation for text output
                            $indented_details = "   " . str_replace("\n", "\n   ", trim($details_text));
                            $output .= $indented_details . "\n";
                        }
                    }
                    
                    $output .= "\n";
                }
            }
        }

        // System Information
        if (!empty($report['system_info'])) {
            $output .= "\n" . __('diagnostics.report.section.system_info', 'order-daemon') . "\n";
            $output .= "------------------\n";
            /* translators: %s: The WordPress version number */
            $output .= sprintf(__('diagnostics.report.system_info.wordpress_version', 'order-daemon'), $report['system_info']['wordpress_version'] ?? __('diagnostics.report.system_info.unknown_wordpress_version', 'order-daemon')) . "\n";
            /* translators: %s: The PHP version number */
            $output .= sprintf(__('diagnostics.report.system_info.php_version', 'order-daemon'), $report['system_info']['php_version'] ?? __('diagnostics.report.system_info.unknown_php_version', 'order-daemon')) . "\n";
            /* translators: %s: The Order Daemon plugin version number */
            $output .= sprintf(__('diagnostics.report.system_info.order_daemon_version', 'order-daemon'), $report['system_info']['order_daemon_version'] ?? __('diagnostics.report.unknown_plugin_version', 'order-daemon')) . "\n";
            /* translators: %s: Whether WooCommerce is active (Yes/No) */
            $output .= sprintf(__('diagnostics.report.system_info.woocommerce_active', 'order-daemon'), ($report['system_info']['woocommerce_active'] ?? false) ? __('diagnostics.report.system_info.yes', 'order-daemon') : __('diagnostics.report.system_info.no', 'order-daemon')) . "\n";
            /* translators: %s: Whether debug mode is enabled (Enabled/Disabled) */
            $output .= sprintf(__('diagnostics.report.system_info.debug_mode', 'order-daemon'), ($report['system_info']['debug_mode'] ?? false) ? __('diagnostics.report.system_info.enabled', 'order-daemon') : __('diagnostics.report.system_info.disabled', 'order-daemon')) . "\n";
            $output .= "\n";
        }

        $output .= "=== " . __('diagnostics.report.end_of_report', 'order-daemon') . " ===\n";
        
        return $output;
    }

    /**
     * Get status icon for text output
     *
     * @param array $test_result The test result data
     * @return string Status icon
     */
    private function get_status_icon_for_text(array $test_result): string
    {
        $status_icon = '❌'; // default
        
        if ($test_result['status'] === 'success' || $test_result['status'] === 'passed') {
            $status_icon = (!empty($test_result['recommendations'])) ? '⚠️' : '✅';
        } elseif ($test_result['status'] === 'warning') {
            $status_icon = '⚠️';
        } elseif ($test_result['status'] === 'error' || $test_result['status'] === 'failed') {
            $message_lower = strtolower($test_result['message']);
            if (strpos($message_lower, 'critical') !== false || strpos($message_lower, 'fatal') !== false) {
                $status_icon = '🔴';
            } elseif (strpos($message_lower, 'warning') !== false || strpos($message_lower, 'recommend') !== false) {
                $status_icon = '⚠️';
            }
        }
        
        return $status_icon;
    }


    /**
     * Format category name for display
     *
     * @param string $category The category name
     * @return string Formatted category name
     */
    private function format_category_name(string $category): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $category));
    }
}
