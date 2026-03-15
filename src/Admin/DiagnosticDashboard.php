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
        $health_status = $this->runner->get_health_status();
        $available_diagnostics = $this->runner->get_available_diagnostics();
        
        ?>
        <div class="wrap odcm-diagnostics-unified">
            
            <!-- Hero Section -->
            <div class="odcm-diagnostic-hero">
                <div class="odcm-hero-left">
                    <h1><?php echo esc_html__('diagnostics.page.title', 'order-daemon'); ?></h1>
                    <p class="odcm-hero-description">
                        <?php echo esc_html__('diagnostics.page.description', 'order-daemon'); ?>
                    </p>
                    <button class="button button-primary button-hero" id="run-diagnostics">
                        <?php echo esc_html__('diagnostics.page.button.run_full', 'order-daemon'); ?>
                    </button>
                    <div class="odcm-hero-meta">
                        <span><?php echo esc_html__('diagnostics.page.label.last_run', 'order-daemon'); ?> <time id="last-run-time"><?php echo esc_html__('diagnostics.page.label.never', 'order-daemon'); ?></time></span>
                        <span><?php echo esc_html__('diagnostics.page.label.status', 'order-daemon'); ?> <strong id="status-summary"><?php echo esc_html__('diagnostics.page.label.pending', 'order-daemon'); ?></strong></span>
                    </div>
                </div>
                
                <div class="odcm-hero-right">
                    <!-- Advanced Options Box -->
                    <div class="odcm-hero-advanced-options">
                        <h3><?php echo esc_html__('diagnostics.page.advanced_options.title', 'order-daemon'); ?></h3>
                        <div class="odcm-hero-advanced-content">
                            <div class="odcm-hero-advanced-section">
                                <h4><?php echo esc_html__('diagnostics.page.advanced_options.run_by_category', 'order-daemon'); ?></h4>
                                <div class="odcm-button-group">
                                    <?php
                                    // Get all unique categories dynamically from available diagnostics
                                    $categories = [];
                                    foreach ($available_diagnostics as $diag) {
                                        $category = $diag['category'];
                                        if (!in_array($category, $categories)) {
                                            $categories[] = $category;
                                        }
                                    }
                                    // Sort categories for consistent display
                                    sort($categories);
                                    foreach ($categories as $category):
                                        if (empty(array_filter($available_diagnostics, function($diag) use ($category) { return $diag['category'] === $category; }))) continue;
                                    ?>
                                    <button class="button" data-category="<?php echo esc_attr($category); ?>" id="run-category-<?php echo esc_attr($category); ?>">
                                        <?php echo esc_html( $this->format_category_name($category) ); ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="odcm-hero-advanced-section">
                                <h4><?php echo esc_html__('diagnostics.page.advanced_options.run_individual', 'order-daemon'); ?></h4>
                                <select id="individual-test-select">
                                    <option value=""><?php echo esc_html__('diagnostics.page.advanced_options.select_test', 'order-daemon'); ?></option>
                                    <?php foreach ($available_diagnostics as $key => $diagnostic): ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($diagnostic['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="button" id="run-individual"><?php echo esc_html__('diagnostics.page.button.run_selected', 'order-daemon'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Banner (shown after run) -->
            <div class="odcm-status-banner" id="status-banner" style="display: none;">
                <div class="odcm-status-banner-left">
                    <span class="odcm-status-icon" id="banner-status-icon">✅</span>
                        <span class="odcm-status-text" id="banner-status-text"><?php esc_html_e('diagnostics.ui.status.system_healthy', 'order-daemon'); ?></span>
                </div>
                <div class="odcm-status-banner-center" id="banner-status-summary">
                    <?php
                    /* translators: 1: Number of tests passed, 2: Number of tests failed */
                    printf(esc_html__('diagnostics.page.summary.passed_failed', 'order-daemon'), 0, 0);
                    ?>
                </div>
                <div class="odcm-status-banner-right">
                    <button class="button button-secondary" id="copy-report">
                        <span class="dashicons dashicons-clipboard"></span>
                        <?php esc_html_e('diagnostics.page.button.copy_to_clipboard', 'order-daemon'); ?>
                    </button>
                </div>
            </div>

            <!-- Loading State -->
            <div class="odcm-loading-state" id="loading-state" style="display: none;">
                <div class="odcm-loading-hero">
                    <h2><?php esc_html_e('diagnostics.page.loading.title', 'order-daemon'); ?></h2>
                    <div class="odcm-loading-progress">
                        <div class="odcm-progress-bar">
                            <div class="odcm-progress-fill" id="progress-bar"></div>
                        </div>
                        <span class="odcm-progress-text" id="progress-text">0/8 tests</span>
                    </div>
                    <p class="odcm-current-test" id="current-test"><?php esc_html_e('diagnostics.ui.status.preparing_tests', 'order-daemon'); ?></p>
                </div>
            </div>

            <!-- Unified Results Container -->
            <div class="odcm-unified-results" id="unified-results" style="display: none;">
                    <div class="odcm-results-header">
                    <h2><?php esc_html_e('diagnostics.page.results.title', 'order-daemon'); ?></h2>
                    <span class="odcm-results-timestamp" id="results-timestamp"><?php 
                                        /* translators: %s: timestamp of when diagnostics were executed */
                                        printf(esc_html__('diagnostics.page.label.executed_at', 'order-daemon'), 'Nov 21, 12:30 PM');
                                        ?></span>
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
                if (trim($plain_text)) {
                    // Cache detailed output for better performance
                    $cache_key = 'odcm_detail_output_' . md5($plain_text);
                    $cached_output = wp_cache_get($cache_key);
                    
                    if (false === $cached_output && $for_html) {
                        $cached_output = '<pre><code class="language-bash">' . esc_html($plain_text) . '</code></pre>';
                        wp_cache_set($cache_key, $cached_output, '', HOUR_IN_SECONDS); // Cache for 1 hour
                    }
                    
                    return $for_html ? ($cached_output ?: '<pre><code class="language-bash">' . esc_html($plain_text) . '</code></pre>') : $plain_text;
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
