<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics;

/**
 * Diagnostic Runner - Central Orchestration for All Diagnostic Tests
 *
 * This class coordinates the execution of all diagnostic tests and provides
 * a unified interface for running diagnostics from the DevTools interface.
 *
 * Designed to address the specific console log issues identified:
 * - API Route 404 errors (/odcm/v1/audit-log/ not found)
 * - Slow filter-options fetch (2+ seconds)
 * - Double dashboard initialization 
 * - Auto-refresh network failures
 * - Permission/authentication issues
 *
 * @package OrderDaemon\CompletionManager\Diagnostics
 */
class DiagnosticRunner
{
    /**
     * Available diagnostic test categories
     */
    private const DIAGNOSTIC_CATEGORIES = [
        'Core' => [
            'WpCliDiagnostic',
            'WooCommerceIntegrationDiagnostic',
            'EnvironmentDiagnostic',
            'PluginStateDiagnostic',
            'CheckoutFlowDiagnostic'
        ],
        'API' => [
            'RestApiDiagnostic',
            'NetworkDiagnostic'
        ],
        'Performance' => [
            'QueryDiagnostic'
        ],
        'Frontend' => [
            'ConfigDiagnostic'
        ]
    ];

    /**
     * Registered diagnostic instances
     *
     * @var array<string, DiagnosticInterface>
     */
    private array $diagnostics = [];

    /**
     * Initialize the diagnostic runner
     */
    public function __construct()
    {
        $this->register_diagnostics();
    }

    /**
     * Register all available diagnostic tests
     *
     * @return void
     */
    private function register_diagnostics(): void
    {
        foreach (self::DIAGNOSTIC_CATEGORIES as $category => $diagnostic_classes) {
            foreach ($diagnostic_classes as $diagnostic_class) {
                $this->register_diagnostic($category, $diagnostic_class);
            }
        }
    }

    /**
     * Register a single diagnostic test
     *
     * @param string $category The diagnostic category
     * @param string $diagnostic_class The diagnostic class name
     * @return void
     */
    private function register_diagnostic(string $category, string $diagnostic_class): void
    {
        $full_class_name = "OrderDaemon\\CompletionManager\\Diagnostics\\{$category}\\{$diagnostic_class}";
        
        if (class_exists($full_class_name)) {
            $instance = new $full_class_name();
            if ($instance instanceof DiagnosticInterface) {
                $key = strtolower($category . '_' . str_replace('Diagnostic', '', $diagnostic_class));
                $this->diagnostics[$key] = $instance;
            }
        }
    }

    /**
     * Run all diagnostic tests
     *
     * @return array<string, DiagnosticResult> Array of diagnostic results keyed by test name
     */
    public function run_all_diagnostics(): array
    {
        $results = [];
        
        foreach ($this->diagnostics as $key => $diagnostic) {
            try {
                $results[$key] = $diagnostic->run();
            } catch (\Throwable $e) {
                $results[$key] = new DiagnosticResult(
                    $diagnostic->get_name(),
                    false,
                    'Diagnostic test failed to execute: ' . $e->getMessage(),
                    [],
                    ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
                );
            }
        }

        return $results;
    }

    /**
     * Run diagnostics by category
     *
     * @param string $category The category to run (core, api, performance, frontend)
     * @return array<string, DiagnosticResult> Array of diagnostic results for the category
     */
    public function run_category_diagnostics(string $category): array
    {
        $results = [];
        
        foreach ($this->diagnostics as $key => $diagnostic) {
            if (strpos($key, $category . '_') === 0) {
                try {
                    $results[$key] = $diagnostic->run();
                } catch (\Throwable $e) {
                    $results[$key] = new DiagnosticResult(
                        $diagnostic->get_name(),
                        false,
                        'Diagnostic test failed to execute: ' . $e->getMessage(),
                        [],
                        ['exception' => $e->getMessage()]
                    );
                }
            }
        }

        return $results;
    }

    /**
     * Run a specific diagnostic test
     *
     * @param string $diagnostic_key The diagnostic test key
     * @return DiagnosticResult|null The diagnostic result or null if not found
     */
    public function run_diagnostic(string $diagnostic_key): ?DiagnosticResult
    {
        if (!isset($this->diagnostics[$diagnostic_key])) {
            return null;
        }

        try {
            return $this->diagnostics[$diagnostic_key]->run();
        } catch (\Throwable $e) {
            return new DiagnosticResult(
                $this->diagnostics[$diagnostic_key]->get_name(),
                false,
                'Diagnostic test failed to execute: ' . $e->getMessage(),
                [],
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Get all available diagnostic test names and descriptions
     *
     * @return array<string, array> Array of diagnostic info keyed by test key
     */
    public function get_available_diagnostics(): array
    {
        $available = [];
        
        foreach ($this->diagnostics as $key => $diagnostic) {
            $available[$key] = [
                'name' => $diagnostic->get_name(),
                'description' => $diagnostic->get_description(),
                'category' => $this->get_diagnostic_category($key),
                'class' => get_class($diagnostic)
            ];
        }

        return $available;
    }

    /**
     * Get the category for a diagnostic key
     *
     * @param string $key The diagnostic key
     * @return string The category name
     */
    private function get_diagnostic_category(string $key): string
    {
        foreach (array_keys(self::DIAGNOSTIC_CATEGORIES) as $category) {
            if (strpos($key, strtolower($category) . '_') === 0) {
                return strtolower($category);
            }
        }
        return 'unknown';
    }

    /**
     * Generate a comprehensive diagnostic report
     *
     * @param array<string, DiagnosticResult>|null $results Optional pre-run results
     * @return array Comprehensive diagnostic report
     */
    public function generate_report(?array $results = null): array
    {
        if ($results === null) {
            $results = $this->run_all_diagnostics();
        }

        $report = [
            'timestamp' => current_time('mysql'),
            'summary' => [
                'total_tests' => count($results),
                'passed' => 0,
                'failed' => 0,
                'warnings' => 0
            ],
            'categories' => [],
            'critical_issues' => [],
            'recommendations' => [],
            'system_info' => $this->get_system_info()
        ];

        // Process results
        foreach ($results as $key => $result) {
            $category = $this->get_diagnostic_category($key);
            
            if (!isset($report['categories'][$category])) {
                $report['categories'][$category] = [
                    'total' => 0,
                    'passed' => 0,
                    'failed' => 0,
                    'tests' => []
                ];
            }

            $report['categories'][$category]['total']++;
            $report['categories'][$category]['tests'][$key] = [
                'name' => $result->getName(),
                'status' => $result->isSuccessful() ? 'success' : 'error',
                'message' => $result->getMessage(),
                'details' => $result->getDetails(),
                'recommendations' => $result->getRecommendations()
            ];

            if ($result->isSuccessful()) {
                $report['summary']['passed']++;
                $report['categories'][$category]['passed']++;
            } else {
                $report['summary']['failed']++;
                $report['categories'][$category]['failed']++;
                
                // Track critical issues (API and core issues)
                if (in_array($category, ['api', 'core'])) {
                    $report['critical_issues'][] = [
                        'test' => $key,
                        'name' => $result->getName(),
                        'message' => $result->getMessage(),
                        'category' => $category
                    ];
                }
            }

            // Collect recommendations
            foreach ($result->getRecommendations() as $recommendation) {
                $report['recommendations'][] = [
                    'test' => $key,
                    'category' => $category,
                    'recommendation' => $recommendation
                ];
            }
        }

        return $report;
    }

    /**
     * Get basic system information for the report
     *
     * @return array System information
     */
    private function get_system_info(): array
    {
        return [
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'order_daemon_version' => defined('ODCM_VERSION') ? ODCM_VERSION : 'unknown',
            'devtools_version' => defined('ODDT_VERSION') ? ODDT_VERSION : 'unknown',
            'debug_mode' => defined('ODCM_DEBUG') && ODCM_DEBUG,
            'woocommerce_active' => class_exists('WooCommerce'),
            'order_daemon_active' => class_exists('OrderDaemon\\CompletionManager\\Plugin'),
            'current_user_can_manage_woocommerce' => current_user_can('manage_woocommerce'),
            'rest_api_enabled' => !defined('XMLRPC_REQUEST')
        ];
    }

    /**
     * Check if Order Daemon core plugin is available
     *
     * @return bool True if core plugin is available
     */
    public function is_core_plugin_available(): bool
    {
        return class_exists('OrderDaemon\\CompletionManager\\Plugin');
    }

    /**
     * Get quick health check status
     *
     * @return array Quick health status
     */
    public function get_health_status(): array
    {
        $critical_tests = ['core_wpcli', 'core_woocommerceintegration', 'core_pluginstate', 'api_restapi'];
        $status = [
            'overall' => 'healthy',
            'issues' => 0,
            'critical_issues' => 0,
            'last_check' => current_time('mysql')
        ];

        foreach ($critical_tests as $test_key) {
            if (isset($this->diagnostics[$test_key])) {
                $result = $this->run_diagnostic($test_key);
                if ($result && !$result->isSuccessful()) {
                    $status['issues']++;
                    $status['critical_issues']++;
                    $status['overall'] = 'critical';
                }
            }
        }

        if ($status['critical_issues'] === 0 && $status['issues'] > 0) {
            $status['overall'] = 'warning';
        }

        return $status;
    }

    /**
     * Generate dual-audience diagnostic report for copy-paste sharing
     *
     * Creates a formatted report suitable for both store owners and support teams
     * with anonymized sensitive data and clear action items.
     *
     * @return string Formatted diagnostic report
     */
    public function generate_dual_audience_report(): string
    {
        $results = $this->run_all_diagnostics();
        
        if (empty($results)) {
            return __('admin.diagnostics.report.no_results', 'order-daemon');
        }

        $report = "=== " . __('admin.diagnostics.report.title', 'order-daemon') . " ===\n\n";
        
        // Header with timestamp and version
        $report .= sprintf(
            /* translators: %s: Current date and time */
            __('admin.diagnostics.report.generated_timestamp', 'order-daemon'),
            current_time('Y-m-d H:i T')
        ) . "\n";
        
        $report .= sprintf(
            /* translators: %s: Plugin version number */
            __('admin.diagnostics.report.plugin_version', 'order-daemon'),
            defined('ODCM_VERSION') ? ODCM_VERSION : 'unknown'
        ) . "\n\n";

        // System status overview with visual indicators
        $critical_count = $this->count_critical_issues($results);
        $warning_count = $this->count_warnings($results);
        
        if ($critical_count > 0) {
            $report .= sprintf(
                /* translators: %d: Number of critical issues found */
                __('admin.diagnostics.report.system_status.critical', 'order-daemon'),
                $critical_count
            ) . "\n\n";
        } elseif ($warning_count > 0) {
            $report .= sprintf(
                /* translators: %d: Number of warnings found */
                __('admin.diagnostics.report.system_status.warning', 'order-daemon'),
                $warning_count
            ) . "\n\n";
        } else {
            $report .= __('admin.diagnostics.report.system_status.healthy', 'order-daemon') . "\n\n";
        }

        // Critical issues section (user-friendly explanations)
        if ($critical_count > 0) {
            $report .= __('admin.diagnostics.report.critical_issues.header', 'order-daemon') . "\n";
            foreach ($this->get_critical_issues($results) as $issue) {
                $report .= "❌ " . $issue['title'] . "\n";
                $report .= "   " . sprintf(
                    /* translators: %s: Impact description */
                    __('admin.diagnostics.report.impact_label', 'order-daemon') . ": %s",
                    $issue['explanation']
                ) . "\n";
                if (!empty($issue['recommendations'])) {
                    $report .= "   " . sprintf(
                        /* translators: %s: Recommended solution */
                        __('admin.diagnostics.report.solution_label', 'order-daemon') . ": %s",
                        implode(', ', $issue['recommendations'])
                    ) . "\n";
                }
                $report .= "\n";
            }
        }

        // System overview
        $report .= __('admin.diagnostics.report.system_overview.header', 'order-daemon') . "\n";
        $system_info = $this->get_system_info();
        
        $report .= sprintf(
            /* translators: %s: WordPress version */
            __('admin.diagnostics.report.system_info.wordpress', 'order-daemon'),
            $system_info['wordpress_version']
        ) . "\n";
        
        if ($system_info['woocommerce_active']) {
            $wc_version = defined('WC_VERSION') ? constant('WC_VERSION') : 'unknown';
            $report .= sprintf(
                /* translators: %s: WooCommerce version */
                __('admin.diagnostics.report.system_info.woocommerce', 'order-daemon'),
                $wc_version
            ) . "\n";
        }
        
        $report .= sprintf(
            /* translators: %s: PHP version */
            __('admin.diagnostics.report.system_info.php_version', 'order-daemon'),
            $system_info['php_version']
        ) . "\n";
        
        $report .= sprintf(
            /* translators: %s: Memory limit */
            __('admin.diagnostics.report.system_info.memory_limit', 'order-daemon'),
            ini_get('memory_limit')
        ) . "\n";
        
        // WP-CLI status for Pro features
        $wp_cli_status = $this->get_wp_cli_status($results);
        $report .= sprintf(
            /* translators: %s: WP-CLI status */
            __('admin.diagnostics.report.system_info.wp_cli', 'order-daemon'),
            $wp_cli_status
        ) . "\n";
        
        $report .= sprintf(
            /* translators: %s: Debug mode status */
            __('admin.diagnostics.report.system_info.debug_mode', 'order-daemon'),
            $system_info['debug_mode'] ? 
                __('admin.diagnostics.status.enabled', 'order-daemon') : 
                __('admin.diagnostics.status.disabled', 'order-daemon')
        ) . "\n\n";

        // Recommendations section
        $recommendations = $this->get_all_recommendations($results);
        if (!empty($recommendations)) {
            $report .= __('admin.diagnostics.report.recommendations.header', 'order-daemon') . "\n";
            foreach ($recommendations as $recommendation) {
                $report .= "• " . $recommendation . "\n";
            }
            $report .= "\n";
        }

        // Technical details section (for support)
        $report .= __('admin.diagnostics.report.technical_details.header', 'order-daemon') . "\n";
        
        foreach ($results as $key => $result) {
            $status_icon = $result->isSuccessful() ? '✅' : '❌';
            $report .= sprintf("%s %s: %s\n", $status_icon, $result->getName(), 
                $result->isSuccessful() ? 
                    __('admin.diagnostics.result.passed', 'order-daemon') : 
                    __('admin.diagnostics.result.failed', 'order-daemon')
            );
            
            if (!$result->isSuccessful()) {
                $details = $result->getDetails();
                if (!empty($details) && is_array($details)) {
                    foreach ($details as $detail_key => $detail_value) {
                        if (is_string($detail_value) || is_numeric($detail_value)) {
                            $sanitized_value = $this->anonymize_sensitive_data($detail_value);
                            $report .= "   {$detail_key}: {$sanitized_value}\n";
                        }
                    }
                }
            }
        }

        return $this->anonymize_sensitive_data($report);
    }

    /**
     * Count critical issues in diagnostic results
     *
     * @param array $results Diagnostic results
     * @return int Number of critical issues
     */
    private function count_critical_issues(array $results): int
    {
        $count = 0;
        foreach ($results as $result) {
            if (!$result->isSuccessful()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Count warnings in diagnostic results
     *
     * @param array $results Diagnostic results
     * @return int Number of warnings
     */
    private function count_warnings(array $results): int
    {
        $count = 0;
        foreach ($results as $result) {
            if ($result->isSuccessful() && !empty($result->getRecommendations())) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get critical issues from diagnostic results
     *
     * @param array $results Diagnostic results
     * @return array Critical issues
     */
    private function get_critical_issues(array $results): array
    {
        $issues = [];
        foreach ($results as $result) {
            if (!$result->isSuccessful()) {
                $issues[] = [
                    'title' => $result->getName(),
                    'explanation' => $result->getMessage(),
                    'recommendations' => $result->getRecommendations()
                ];
            }
        }
        return $issues;
    }

    /**
     * Get all recommendations from diagnostic results
     *
     * @param array $results Diagnostic results
     * @return array All recommendations
     */
    private function get_all_recommendations(array $results): array
    {
        $recommendations = [];
        foreach ($results as $result) {
            $recommendations = array_merge($recommendations, $result->getRecommendations());
        }
        return array_unique($recommendations);
    }

    /**
     * Get WP-CLI status from diagnostic results
     *
     * @param array $results Diagnostic results
     * @return string WP-CLI status description
     */
    private function get_wp_cli_status(array $results): string
    {
        if (isset($results['core_wpcli'])) {
            $result = $results['core_wpcli'];
            if ($result->isSuccessful()) {
                $details = $result->getDetails();
                return $details['wp_cli_version'] ?? __('admin.diagnostics.status.available', 'order-daemon');
            } else {
                return __('admin.diagnostics.status.not_available', 'order-daemon') . ' (' . 
                       __('admin.diagnostics.status.required_for_pro', 'order-daemon') . ')';
            }
        }
        return __('admin.diagnostics.status.not_available', 'order-daemon');
    }

    /**
     * Anonymize sensitive data in the report
     *
     * @param string $content Report content
     * @return string Anonymized content
     */
    private function anonymize_sensitive_data(string $content): string
    {
        // Anonymize domains and URLs
        $content = preg_replace('/https?:\/\/[^\s\/]+/', 'https://[DOMAIN]', $content);
        
        // Anonymize file paths (keep structure but remove identifying parts)
        $content = preg_replace('/\/home\/[^\/]+/', '/home/[USER]', $content);
        $content = preg_replace('/\/wp-content\/[^\/]+/', '/wp-content/[SITE]', $content);
        
        // Anonymize email addresses
        $content = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $content);
        
        // Anonymize API keys and tokens (preserve format but hide values)
        $content = preg_replace('/[\'\"][a-zA-Z0-9_-]{20,}[\'\"]/', '"[API_KEY]"', $content);
        
        return $content;
    }
}
