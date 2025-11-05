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
        
        // Enqueue assets on our page
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add diagnostic dashboard to admin menu
     *
     * @return void
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'tools.php',
            'Order Daemon Diagnostics',
            'OD Diagnostics',
            'manage_options',
            'odcm-diagnostics',
            [$this, 'render_dashboard_page']
        );
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

        // Enqueue diagnostic dashboard assets
        wp_enqueue_script(
            'odcm-diagnostics',
            $assets_url . 'js/diagnostics.js',
            ['jquery'],
            $script_version,
            true
        );

        wp_enqueue_style(
            'odcm-diagnostics',
            $assets_url . 'css/diagnostics.css',
            [],
            $script_version
        );

        // Localize script with AJAX data
        wp_localize_script('odcm-diagnostics', 'odcmDiagnostics', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('odcm_diagnostics'),
            'strings' => [
                'running' => __('Running diagnostics...', 'order-daemon'),
                'success' => __('Diagnostics completed successfully', 'order-daemon'),
                'error' => __('Error running diagnostics', 'order-daemon'),
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
        <div class="wrap">
            <h1>Order Daemon Diagnostics</h1>
            
            <div class="odcm-diagnostics-header">
                <div class="odcm-health-status odcm-status-<?php echo esc_attr($health_status['overall']); ?>">
                    <h2>System Health: <?php echo esc_html(ucfirst($health_status['overall'])); ?></h2>
                    <p>
                        <?php echo esc_html($health_status['issues']); ?> issues found
                        (<?php echo esc_html($health_status['critical_issues']); ?> critical)
                    </p>
                    <p class="description">Last check: <?php echo esc_html($health_status['last_check']); ?></p>
                </div>
                
                <div class="odcm-quick-actions">
                    <button type="button" class="button button-primary" id="run-all-diagnostics">
                        Run All Diagnostics
                    </button>
                    <button type="button" class="button" id="run-critical-diagnostics">
                        Run Critical Tests Only
                    </button>
                </div>
            </div>

            <div class="odcm-diagnostics-content">
                <div class="odcm-diagnostics-categories">
                    <?php
                    $categories = ['core', 'api', 'performance', 'frontend'];
                    foreach ($categories as $category):
                        $category_diagnostics = array_filter($available_diagnostics, function($diag) use ($category) {
                            return $diag['category'] === $category;
                        });
                        
                        if (empty($category_diagnostics)) continue;
                    ?>
                    <div class="odcm-category-section">
                        <h3><?php echo esc_html(ucfirst($category)); ?> Diagnostics</h3>
                        
                        <div class="odcm-category-actions">
                            <button type="button" class="button run-category" data-category="<?php echo esc_attr($category); ?>">
                                Run <?php echo esc_html(ucfirst($category)); ?> Tests
                            </button>
                        </div>
                        
                        <div class="odcm-diagnostics-list">
                            <?php foreach ($category_diagnostics as $key => $diagnostic): ?>
                            <div class="odcm-diagnostic-item" data-diagnostic="<?php echo esc_attr($key); ?>">
                                <div class="odcm-diagnostic-header">
                                    <h4><?php echo esc_html($diagnostic['name']); ?></h4>
                                    <button type="button" class="button button-small run-single" data-diagnostic="<?php echo esc_attr($key); ?>">
                                        Run Test
                                    </button>
                                </div>
                                <p class="description"><?php echo esc_html($diagnostic['description']); ?></p>
                                <div class="odcm-diagnostic-result" style="display: none;"></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>

            <div class="odcm-results-section">
                <h3>Diagnostic Results</h3>
                <div id="odcm-results-content">
                    <p class="description">Click "Run All Diagnostics" to see comprehensive results here.</p>
                </div>
            </div>
        </div>

        <style>
        .odcm-diagnostics-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
        }
        
        .odcm-health-status h2 {
            margin: 0 0 5px 0;
        }
        
        .odcm-status-healthy h2 { color: #46b450; }
        .odcm-status-warning h2 { color: #ffb900; }
        .odcm-status-critical h2 { color: #dc3232; }
        
        .odcm-diagnostics-content {
            display: block;
        }
        
        .odcm-category-section {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            background: #fff;
        }
        
        .odcm-category-section h3 {
            margin: 0;
            padding: 15px;
            background: #f1f1f1;
            border-bottom: 1px solid #ddd;
        }
        
        .odcm-category-actions {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
        }
        
        .odcm-diagnostic-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .odcm-diagnostic-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .odcm-diagnostic-header h4 {
            margin: 0;
        }
        
        .odcm-results-panel {
            background: #fff;
            border: 1px solid #ddd;
            padding: 15px;
            height: fit-content;
            position: sticky;
            top: 32px;
        }
        
        .odcm-loading { opacity: 0.6; }
        .odcm-success { color: #46b450; }
        .odcm-warning { color: #ffb900; }
        .odcm-error { color: #dc3232; }
        </style>
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
            wp_send_json_error(['message' => 'Security check failed']);
        }

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
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
                'message' => 'Failed to run diagnostics: ' . $e->getMessage()
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
            wp_send_json_error(['message' => 'Security check failed']);
        }

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        try {
            $diagnostic_key = sanitize_text_field($_POST['diagnostic'] ?? '');
            
            if (empty($diagnostic_key)) {
                wp_send_json_error(['message' => 'No diagnostic specified']);
            }

            $result = $this->runner->run_diagnostic($diagnostic_key);
            
            if (!$result) {
                wp_send_json_error(['message' => 'Diagnostic not found']);
            }

            wp_send_json_success([
                'result' => $result->toArray(),
                'html' => $this->render_single_result_html($result->toArray())
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => 'Failed to run diagnostic: ' . $e->getMessage()
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
                <h4>Summary</h4>
                <p>
                    <strong><?php echo esc_html($report['summary']['total_tests']); ?></strong> tests run: 
                    <span class="odcm-success"><?php echo esc_html($report['summary']['passed']); ?> passed</span>, 
                    <span class="odcm-error"><?php echo esc_html($report['summary']['failed']); ?> failed</span>
                </p>
            </div>

            <?php if (!empty($report['critical_issues'])): ?>
            <div class="odcm-critical-issues">
                <h4>Critical Issues</h4>
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

            <?php if (!empty($report['categories'])): ?>
            <div class="odcm-detailed-results">
                <h4>Detailed Test Results</h4>
                <?php foreach ($report['categories'] as $category_name => $category_data): ?>
                <div class="odcm-category-results">
                    <h5><?php echo esc_html(ucfirst($category_name)); ?> Tests 
                        <span class="odcm-category-status">
                            (<?php echo esc_html($category_data['passed']); ?>/<?php echo esc_html($category_data['total']); ?> passed)
                        </span>
                    </h5>
                    
                    <?php foreach ($category_data['tests'] as $test_key => $test_result): ?>
                    <div class="odcm-test-result odcm-test-<?php echo esc_attr($test_result['status']); ?>">
                        <div class="odcm-test-header">
                            <span class="odcm-test-name"><?php echo esc_html($test_result['name']); ?></span>
                            <span class="odcm-test-status-badge odcm-badge-<?php echo esc_attr($test_result['status']); ?>">
                                <?php echo esc_html(ucfirst($test_result['status'])); ?>
                            </span>
                        </div>
                        
                        <div class="odcm-test-message">
                            <?php echo esc_html($test_result['message']); ?>
                        </div>
                        
                        <?php if (!empty($test_result['recommendations'])): ?>
                        <div class="odcm-test-recommendations">
                            <strong>Recommendations:</strong>
                            <ul>
                                <?php foreach ($test_result['recommendations'] as $rec): ?>
                                <li><?php echo esc_html($rec); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($test_result['details'])): ?>
                        <details class="odcm-test-details">
                            <summary>Technical Details</summary>
                            <pre><?php echo esc_html(json_encode($test_result['details'], JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($report['recommendations'])): ?>
            <div class="odcm-all-recommendations">
                <h4>All Recommendations</h4>
                <ul>
                    <?php foreach ($report['recommendations'] as $rec): ?>
                    <li>
                        <strong><?php echo esc_html(ucfirst($rec['category'])); ?>:</strong> 
                        <?php echo esc_html($rec['recommendation']); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
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
        $status_class = $result['successful'] ? 'odcm-success' : 'odcm-error';
        ?>
        <div class="odcm-single-result <?php echo esc_attr($status_class); ?>">
            <p><strong><?php echo esc_html($result['message']); ?></strong></p>
            
            <?php if (!empty($result['recommendations'])): ?>
            <div class="odcm-recommendations">
                <strong>Recommendations:</strong>
                <ul>
                    <?php foreach ($result['recommendations'] as $rec): ?>
                    <li><?php echo esc_html($rec); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($result['details'])): ?>
            <details>
                <summary>Technical Details</summary>
                <pre><?php echo esc_html(json_encode($result['details'], JSON_PRETTY_PRINT)); ?></pre>
            </details>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
