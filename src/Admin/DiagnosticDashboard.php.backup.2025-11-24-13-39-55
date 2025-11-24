<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

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
        wp_enqueue_style(
            'odcm-design-system',
            $assets_url . 'css/odcm-design-system.css',
            [],
            $script_version
        );

        wp_enqueue_style(
            'odcm-diagnostics',
            $assets_url . 'css/diagnostics.css',
            ['odcm-design-system', 'odcm-prism-css'], // Depend on design system and Prism CSS
            $script_version
        );

        // Localize script with AJAX data
        wp_localize_script('odcm-diagnostics', 'odcmDiagnostics', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('odcm_diagnostics'),
            'strings' => [
                'running' => __('admin.diagnostics.status.running', 'order-daemon'),
                'success' => __('admin.diagnostics.status.completed_successfully', 'order-daemon'),
                'error' => __('admin.diagnostics.status.error_running', 'order-daemon'),
                'passed' => __('admin.diagnostics.status.passed', 'order-daemon'),
                'issuesDetected' => __('admin.diagnostics.status.issues_detected', 'order-daemon'),
                'executed' => __('admin.diagnostics.status.executed', 'order-daemon'),
                'runningTests' => __('admin.diagnostics.loading.running_tests', 'order-daemon'),
                'testProgress' => __('admin.diagnostics.loading.test_progress', 'order-daemon'),
                'timestampLabel' => __('admin.diagnostics.results.timestamp_label', 'order-daemon'),
                'systemHealthy' => __('admin.diagnostics.status.system_healthy', 'order-daemon'),
                'warningsFound' => __('admin.diagnostics.status.warnings_found', 'order-daemon'),
                'testsCompleted' => __('admin.diagnostics.loading.tests_completed', 'order-daemon'),
                'preparingTests' => __('admin.diagnostics.loading.preparing_tests', 'order-daemon'),
                'buttonRunning' => __('admin.diagnostics.button.running', 'order-daemon'),
                'justNow' => __('admin.diagnostics.time.just_now', 'order-daemon'),
                'minuteAgo' => __('admin.diagnostics.time.minutes_ago', 'order-daemon'),
                'minutesAgo' => __('admin.diagnostics.time.minutes_ago_plural', 'order-daemon'),
                'hourAgo' => __('admin.diagnostics.time.hours_ago', 'order-daemon'),
                'hoursAgo' => __('admin.diagnostics.time.hours_ago_plural', 'order-daemon'),
                'dayAgo' => __('admin.diagnostics.time.days_ago', 'order-daemon'),
                'daysAgo' => __('admin.diagnostics.time.days_ago_plural', 'order-daemon'),
                'selectTest' => __('admin.diagnostics.error.select_test', 'order-daemon'),
                'testCompleted' => __('admin.diagnostics.success.test_completed', 'order-daemon'),
                'failedRunTest' => __('admin.diagnostics.error.failed_run_test', 'order-daemon'),
                'failedRunDiagnostics' => __('admin.diagnostics.error.failed_run_diagnostics', 'order-daemon'),
                'failedGenerateReport' => __('admin.diagnostics.error.failed_generate_report', 'order-daemon'),
                'executedPrefix' => __('admin.diagnostics.results.executed_prefix', 'order-daemon'),
                'errorTitle' => __('admin.diagnostics.error.category_title', 'order-daemon'),
                'diagnosticError' => __('admin.diagnostics.error.diagnostic_error', 'order-daemon'),
                'recommendationsIcon' => __('admin.diagnostics.error.recommendations_icon', 'order-daemon'),
                'tryRefresh' => __('admin.diagnostics.error.try_refresh', 'order-daemon'),
                'checkConsole' => __('admin.diagnostics.error.check_console', 'order-daemon'),
                'contactSupport' => __('admin.diagnostics.error.contact_support', 'order-daemon'),
                'autoCopyFailed' => __('admin.diagnostics.error.auto_copy_failed', 'order-daemon'),
                'singleTestTitle' => __('admin.diagnostics.single_test.result_title', 'order-daemon'),
                'recommendationsLabel' => __('admin.diagnostics.format.recommendations_label', 'order-daemon'),
                'detailsLabel' => __('admin.diagnostics.format.details_label', 'order-daemon'),
                'testsCompletedSuccessfully' => __('admin.diagnostics.success.tests_completed_successfully', 'order-daemon'),
                'copying' => __('admin.diagnostics.copy_paste.copying', 'order-daemon'),
                'copied' => __('admin.diagnostics.copy_paste.copied', 'order-daemon'),
                'copySuccess' => __('admin.diagnostics.copy_paste.copy_success', 'order-daemon'),
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
        $health_status = $this->runner->get_health_status();
        $available_diagnostics = $this->runner->get_available_diagnostics();
        
        ?>
        <div class="wrap odcm-diagnostics-unified">
            
            <!-- Hero Section -->
            <div class="odcm-diagnostic-hero">
                <div class="odcm-hero-left">
                    <h1><?php echo esc_html__('admin.diagnostics.hero.title', 'order-daemon'); ?></h1>
                    <p class="odcm-hero-description">
                        <?php echo esc_html__('admin.diagnostics.hero.description', 'order-daemon'); ?>
                    </p>
                    <button class="button button-primary button-hero" id="run-diagnostics">
                        <?php echo esc_html__('admin.diagnostics.hero.run_full_button', 'order-daemon'); ?>
                    </button>
                    <div class="odcm-hero-meta">
                        <span><?php echo esc_html__('admin.diagnostics.hero.last_run_label', 'order-daemon'); ?> <time id="last-run-time"><?php echo esc_html__('admin.diagnostics.hero.last_run_never', 'order-daemon'); ?></time></span>
                        <span><?php echo esc_html__('admin.diagnostics.hero.status_label', 'order-daemon'); ?> <strong id="status-summary"><?php echo esc_html__('admin.diagnostics.hero.status_pending', 'order-daemon'); ?></strong></span>
                    </div>
                </div>
                
                <div class="odcm-hero-right">
                    <!-- Advanced Options Box -->
                    <div class="odcm-hero-advanced-options">
                        <h3><?php echo esc_html__('admin.diagnostics.hero.advanced_options', 'order-daemon'); ?></h3>
                        <div class="odcm-hero-advanced-content">
                            <div class="odcm-hero-advanced-section">
                                <h4><?php echo esc_html__('admin.diagnostics.hero.run_by_category', 'order-daemon'); ?></h4>
                                <div class="odcm-button-group">
                                    <?php
                                    $categories = ['core', 'api', 'performance', 'frontend'];
                                    foreach ($categories as $category):
                                        if (empty(array_filter($available_diagnostics, function($diag) use ($category) { return $diag['category'] === $category; }))) continue;
                                    ?>
                                    <button class="button" data-category="<?php echo esc_attr($category); ?>" id="run-category-<?php echo esc_attr($category); ?>">
                                        <?php echo esc_html__("admin.diagnostics.category.{$category}", 'order-daemon'); ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="odcm-hero-advanced-section">
                                <h4><?php echo esc_html__('admin.diagnostics.hero.run_individual_test', 'order-daemon'); ?></h4>
                                <select id="individual-test-select">
                                    <option value=""><?php echo esc_html__('admin.diagnostics.hero.select_test', 'order-daemon'); ?></option>
                                    <?php foreach ($available_diagnostics as $key => $diagnostic): ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($diagnostic['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="button" id="run-individual"><?php echo esc_html__('admin.diagnostics.hero.run_selected', 'order-daemon'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Banner (shown after run) -->
            <div class="odcm-status-banner" id="status-banner" style="display: none;">
                <div class="odcm-status-banner-left">
                    <span class="odcm-status-icon" id="banner-status-icon">✅</span>
                    <span class="odcm-status-text" id="banner-status-text"><?php esc_html_e('admin.diagnostics.status.system_healthy', 'order-daemon'); ?></span>
                </div>
                <div class="odcm-status-banner-center" id="banner-status-summary">
                    <?php
                    /* translators: 1: Number of tests passed, 2: Number of tests failed */
                    printf(esc_html__('admin.diagnostics.banner.tests_summary', 'order-daemon'), 0, 0); 
                    ?>
                </div>
                <div class="odcm-status-banner-right">
                    <button class="button button-secondary" id="copy-report">
                        <span class="dashicons dashicons-clipboard"></span>
                        <?php esc_html_e('admin.diagnostics.copy_paste.copy_button', 'order-daemon'); ?>
                    </button>
                </div>
            </div>

            <!-- Loading State -->
            <div class="odcm-loading-state" id="loading-state" style="display: none;">
                <div class="odcm-loading-hero">
                    <h2><?php esc_html_e('admin.diagnostics.loading.title', 'order-daemon'); ?></h2>
                    <div class="odcm-loading-progress">
                        <div class="odcm-progress-bar">
                            <div class="odcm-progress-fill" id="progress-bar"></div>
                        </div>
                        <span class="odcm-progress-text" id="progress-text">0/8 tests</span>
                    </div>
                    <p class="odcm-current-test" id="current-test"><?php esc_html_e('admin.diagnostics.loading.preparing', 'order-daemon'); ?></p>
                </div>
            </div>

            <!-- Unified Results Container -->
            <div class="odcm-unified-results" id="unified-results" style="display: none;">
                <div class="odcm-results-header">
                    <h2><?php esc_html_e('admin.diagnostics.results.title', 'order-daemon'); ?></h2>
                    <span class="odcm-results-timestamp" id="results-timestamp"><?php printf(esc_html__('admin.diagnostics.results.timestamp_format', 'order-daemon'), 'Nov 21, 12:30 PM'); ?></span>
                </div>

                <div class="odcm-results-content" id="odcm-results-content">
                    <!-- Dynamic results will be inserted here -->
                </div>
            </div>
            
        </div>
        <?php
    }

    /**
     * AJAX handler for running all diagnostics
     *
     * @return void
     */
    public function ajax_run_diagnostics(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'odcm_diagnostics')) {
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
        }

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('security.permission_denied', 'order-daemon')]);
        }

        try {
            $category = sanitize_text_field($_POST['category'] ?? '');
            
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
                'message' => sprintf(__('admin.diagnostics.error.failed_to_run_diagnostics', 'order-daemon'), $e->getMessage())
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
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'odcm_diagnostics')) {
            wp_send_json_error(['message' => __('admin.ajax.security_check_failed', 'order-daemon')]);
        }

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('security.permission_denied', 'order-daemon')]);
        }

        try {
            $diagnostic_key = sanitize_text_field($_POST['diagnostic'] ?? '');
            
            if (empty($diagnostic_key)) {
                wp_send_json_error(['message' => __('admin.diagnostics.error.no_diagnostic_specified', 'order-daemon')]);
            }

            $result = $this->runner->run_diagnostic($diagnostic_key);
            
            if (!$result) {
                wp_send_json_error(['message' => __('admin.diagnostics.error.diagnostic_not_found', 'order-daemon')]);
            }

            wp_send_json_success([
                'result' => $result->toArray(),
                'html' => $this->render_single_result_html($result->toArray())
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error([
                /* translators: %s: The error message that occurred while running a single diagnostic */
                'message' => sprintf(__('admin.diagnostics.error.failed_to_run_diagnostic', 'order-daemon'), $e->getMessage())
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
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'odcm_diagnostics')) {
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
                'message' => __('admin.diagnostics.copy_paste.report_success', 'order-daemon')
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: The error message that occurred while generating dual-audience report */
                    __('admin.diagnostics.copy_paste.report_failed', 'order-daemon'),
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
                <h4><?php esc_html_e('admin.diagnostics.results.summary_title', 'order-daemon'); ?></h4>
                <p>
                    <?php
                    /* translators: 1: Number of tests run, 2: Number passed, 3: Number failed */
                    printf(esc_html__('admin.diagnostics.results.test_summary', 'order-daemon'), 
                           esc_html($report['summary']['total_tests']),
                           esc_html($report['summary']['passed']),
                           esc_html($report['summary']['failed']));
                    ?>
                </p>
            </div>

            <?php if (!empty($report['critical_issues'])): ?>
            <div class="odcm-critical-issues">
                <h4><?php esc_html_e('admin.diagnostics.results.critical_issues_title', 'order-daemon'); ?></h4>
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
                <h4><?php esc_html_e('admin.diagnostics.results.all_recommendations_title', 'order-daemon'); ?></h4>
                <ul>
                    <?php foreach ($report['recommendations'] as $rec): ?>
                    <li>
                        <strong><?php echo esc_html__("admin.diagnostics.category.{$rec['category']}", 'order-daemon'); ?>:</strong> 
                        <?php echo esc_html($rec['recommendation']); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($report['categories'])): ?>
            <div class="odcm-detailed-results">
                <h4><?php esc_html_e('admin.diagnostics.results.detailed_results_title', 'order-daemon'); ?></h4>
                <?php foreach ($report['categories'] as $category_name => $category_data): ?>
                <div class="odcm-category-results">
                    <h5><?php
                    /* translators: 1: Category name, 2: Number passed, 3: Total number */
                    printf(esc_html__('admin.diagnostics.results.category_status', 'order-daemon'),
                           esc_html__("admin.diagnostics.category.{$category_name}", 'order-daemon'),
                           esc_html($category_data['passed']),
                           esc_html($category_data['total']));
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
                            <span class="odcm-test-icon"><?php echo $status_icon; ?></span>
                            <h4 class="odcm-test-name"><?php echo esc_html($test_result['name']); ?></h4>
                        </div>
                        
                        <p class="odcm-test-message">
                            <?php echo esc_html($test_result['message']); ?>
                        </p>
                        
                        <?php if (!empty($test_result['recommendations'])): ?>
                        <div class="odcm-test-recommendations">
                            <strong><?php esc_html_e('admin.diagnostics.results.recommendations_title', 'order-daemon'); ?>:</strong>
                            <ul>
                                <?php foreach ($test_result['recommendations'] as $rec): ?>
                                <li><?php echo esc_html($rec); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        
                        <?php 
                        // DEBUG: Log details check
                        error_log("DEBUG render_results_html: Test '{$test_result['name']}' has details: " . (!empty($test_result['details']) ? 'YES' : 'NO'));
                        if (!empty($test_result['details'])) {
                            error_log("DEBUG render_results_html: Details structure: " . print_r($test_result['details'], true));
                        }
                        ?>
                        <?php if (!empty($test_result['details'])): ?>
                        <div class="odcm-test-details">
                            <h6><?php esc_html_e('admin.diagnostics.results.technical_details_title', 'order-daemon'); ?>:</h6>
                            <div class="odcm-technical-info">
                                <?php 
                                error_log("DEBUG render_results_html: About to call render_nested_details for '{$test_result['name']}'");
                                $rendered_output = $this->render_nested_details($test_result['details']);
                                error_log("DEBUG render_results_html: Rendered output length: " . strlen($rendered_output));
                                
                                // For very large outputs, truncate to prevent browser issues
                                if (strlen($rendered_output) > 50000) {
                                    error_log("DEBUG render_results_html: Output too large, truncating");
                                    $rendered_output = substr($rendered_output, 0, 50000) . 
                                        '<div class="odcm-detail-truncated"><em>Output truncated due to size (showing first 50KB of ' . 
                                        round(strlen($rendered_output)/1024) . 'KB total)</em></div>';
                                }
                                
                                echo $rendered_output;
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
            <h3 class="odcm-category-title"><?php echo esc_html__('admin.diagnostics.single_test.title', 'order-daemon'); ?></h3>
            <div class="odcm-test-result odcm-test-result--<?php echo esc_attr($status_class); ?>">
                <div class="odcm-test-result-header">
                    <span class="odcm-test-icon"><?php echo $status_icon; ?></span>
                    <h4 class="odcm-test-name"><?php echo esc_html($result['name']); ?></h4>
                </div>
                
                <p class="odcm-test-message"><?php echo esc_html($result['message']); ?></p>
                
                <?php if (!empty($result['recommendations'])): ?>
                <div class="odcm-test-recommendations">
                    <strong><?php esc_html_e('admin.diagnostics.results.recommendations_title', 'order-daemon'); ?>:</strong>
                    <ul>
                        <?php foreach ($result['recommendations'] as $rec): ?>
                        <li><?php echo esc_html($rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($result['details'])): ?>
                <div class="odcm-test-details">
                    <h6><?php esc_html_e('admin.diagnostics.results.technical_details_title', 'order-daemon'); ?>:</h6>
                    <div class="odcm-technical-info">
                        <?php echo $this->render_nested_details($result['details']); ?>
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
            $no_details_text = __('admin.diagnostics.technical.no_details', 'order-daemon');
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
        if (trim($plain_text)) {
            return $for_html ? '<pre><code class="language-bash">' . esc_html($plain_text) . '</code></pre>' : $plain_text;
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
     * Generate a complete text report that matches the visual display
     *
     * @param array $report The diagnostic report data
     * @return string Formatted text report
     */
    private function generate_complete_text_report(array $report): string
    {
        $output = '';
        
        // Header
        $output .= __('admin.diagnostics.report.header', 'order-daemon') . "\n";
        $output .= sprintf(__('admin.diagnostics.report.generated_timestamp', 'order-daemon'), current_time('Y-m-d H:i:s T')) . "\n";
        $output .= sprintf(__('admin.diagnostics.report.plugin_version', 'order-daemon'), defined('ODCM_VERSION') ? ODCM_VERSION : __('admin.diagnostics.report.unknown', 'order-daemon')) . "\n";
        $output .= "\n";

        // Report Summary
        $output .= __('admin.diagnostics.report.summary_header', 'order-daemon') . "\n";
        $output .= "-------\n";
        $output .= sprintf(
            __('admin.diagnostics.report.tests_summary', 'order-daemon'),
            $report['summary']['total_tests'],
            $report['summary']['passed'],
            $report['summary']['failed']
        ) . "\n";
        $output .= "\n";

        // Critical Issues Section (if any)
        if (!empty($report['critical_issues'])) {
            $output .= __('admin.diagnostics.report.critical_header', 'order-daemon') . "\n";
            $output .= "---------------\n";
            foreach ($report['critical_issues'] as $issue) {
                $output .= sprintf("❌ %s: %s\n", $issue['name'], $issue['message']);
            }
            $output .= "\n";
        }

        // All Recommendations Section (if any)
        if (!empty($report['recommendations'])) {
            $output .= __('admin.diagnostics.report.recommendations_header', 'order-daemon') . "\n";
            $output .= "-------------------\n";
            foreach ($report['recommendations'] as $rec) {
                $category_label = $this->format_category_name($rec['category'] ?? 'general');
                $output .= sprintf("💡 %s: %s\n", $category_label, $rec['recommendation']);
            }
            $output .= "\n";
        }

        // Detailed Results by Category
        if (!empty($report['categories'])) {
            $output .= __('admin.diagnostics.report.detailed_header', 'order-daemon') . "\n";
            $output .= "----------------\n";
            
            foreach ($report['categories'] as $category_name => $category_data) {
                $category_label = $this->format_category_name($category_name);
                $output .= sprintf(
                    __('admin.diagnostics.report.diagnostics_format', 'order-daemon'),
                    strtoupper($category_label),
                    $category_data['passed'],
                    $category_data['total']
                ) . "\n";
                $output .= str_repeat('=', strlen($category_label) + 25) . "\n";
                
                foreach ($category_data['tests'] as $test_key => $test_result) {
                    // Determine status icon
                    $status_icon = $this->get_status_icon_for_text($test_result);
                    
                    $output .= sprintf("\n%s %s\n", $status_icon, $test_result['name']);
                    $output .= sprintf(__('admin.diagnostics.report.status_format', 'order-daemon'), ucfirst($test_result['status'])) . "\n";
                    $output .= sprintf(__('admin.diagnostics.report.message_format', 'order-daemon'), $test_result['message']) . "\n";
                    
                    // Add recommendations if any
                    if (!empty($test_result['recommendations'])) {
                        $output .= __('admin.diagnostics.report.recommendations_label', 'order-daemon') . "\n";
                        foreach ($test_result['recommendations'] as $rec) {
                            $output .= sprintf("   • %s\n", $rec);
                        }
                    }
                    
                    // Add technical details if any
                    if (!empty($test_result['details'])) {
                        $output .= __('admin.diagnostics.report.technical_details_label', 'order-daemon') . "\n";
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
            $output .= "\n" . __('admin.diagnostics.report.system_info_header', 'order-daemon') . "\n";
            $output .= "------------------\n";
            /* translators: %s: The WordPress version number */
            $output .= sprintf(__('admin.diagnostics.report.wordpress_version', 'order-daemon'), $report['system_info']['wordpress_version'] ?? __('admin.diagnostics.report.unknown', 'order-daemon')) . "\n";
            /* translators: %s: The PHP version number */
            $output .= sprintf(__('admin.diagnostics.report.php_version', 'order-daemon'), $report['system_info']['php_version'] ?? __('admin.diagnostics.report.unknown', 'order-daemon')) . "\n";
            /* translators: %s: The Order Daemon plugin version number */
            $output .= sprintf(__('admin.diagnostics.report.order_daemon_version', 'order-daemon'), $report['system_info']['order_daemon_version'] ?? __('admin.diagnostics.report.unknown', 'order-daemon')) . "\n";
            /* translators: %s: Whether WooCommerce is active (Yes/No) */
            $output .= sprintf(__('admin.diagnostics.report.woocommerce_active', 'order-daemon'), ($report['system_info']['woocommerce_active'] ?? false) ? __('admin.diagnostics.report.yes', 'order-daemon') : __('admin.diagnostics.report.no', 'order-daemon')) . "\n";
            /* translators: %s: Whether debug mode is enabled (Enabled/Disabled) */
            $output .= sprintf(__('admin.diagnostics.report.debug_mode', 'order-daemon'), ($report['system_info']['debug_mode'] ?? false) ? __('admin.diagnostics.report.enabled', 'order-daemon') : __('admin.diagnostics.report.disabled', 'order-daemon')) . "\n";
            $output .= "\n";
        }

        $output .= "=== " . __('admin.diagnostics.report.end_header', 'order-daemon') . " ===\n";
        
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
