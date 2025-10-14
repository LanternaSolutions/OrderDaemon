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
            if (strpos($key, $category . '_') === 0) {
                return $category;
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
                'status' => $result->isSuccessful() ? 'passed' : 'failed',
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
            'rest_api_enabled' => !defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST
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
        $critical_tests = ['core_environment', 'core_pluginstate', 'api_restapi', 'api_endpoint'];
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
}
