<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Core;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * Environment Diagnostic - Checks Server Environment Configuration
 *
 * This diagnostic verifies that the server environment is properly configured
 * for Order Daemon including file permissions, memory limits, and debug settings.
 *
 * @package OrderDaemon\CompletionManager\Diagnostics\Core
 */
class EnvironmentDiagnostic extends AbstractDiagnostic
{
    /**
     * Minimum recommended memory limit in bytes
     */
    private const MIN_MEMORY_LIMIT = 268435456; // 256MB

    /**
     * Minimum recommended PHP version
     */
    private const MIN_PHP_VERSION = '7.4.0';

    /**
     * Get the diagnostic name
     *
     * @return string
     */
    public function get_name(): string
    {
        return __('Environment Check', 'order-daemon');
    }

    /**
     * Get the diagnostic description
     *
     * @return string
     */
    public function get_description(): string
    {
        return __('Checks file permissions, memory limits, and debug mode status', 'order-daemon');
    }

    /**
     * Get the diagnostic category
     *
     * @return string
     */
    public function get_category(): string
    {
        return 'core';
    }

    /**
     * Get the diagnostic priority (lower = higher priority)
     *
     * @return int
     */
    public function get_priority(): int
    {
        return 3; // High priority for core functionality
    }

    /**
     * Check if this diagnostic requires the core plugin
     *
     * @return bool
     */
    public function requires_core_plugin(): bool
    {
        return false; // Can run without core plugin
    }

    /**
     * Execute the environment diagnostic
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $start_time = microtime(true);
        $issues = [];
        $warnings = [];
        $recommendations = [];

        // Test 1: PHP Version Check
        $php_version = PHP_VERSION;
        $php_compatible = version_compare($php_version, self::MIN_PHP_VERSION, '>=');
        
        if (!$php_compatible) {
            $issues[] = sprintf(
                /* translators: 1: Current PHP version, 2: Minimum required version */
                            /* translators: 1: Current PHP version, 2: Required PHP version */
                            __('PHP version %1$s is incompatible (requires %2$s+)', 'order-daemon'),
                $php_version,
                self::MIN_PHP_VERSION
            );
        }

        // Test 2: Memory Limit Check
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_sufficient = $memory_limit >= self::MIN_MEMORY_LIMIT || $memory_limit == -1; // -1 means unlimited
        
        if (!$memory_sufficient) {
            $warnings[] = sprintf(
                /* translators: 1: Current memory limit, 2: Recommended memory limit */
                __('Memory limit: %s (recommended: 256M+)', 'order-daemon'),
                $this->format_bytes($memory_limit),
                $this->format_bytes(self::MIN_MEMORY_LIMIT)
            );
            $recommendations[] = __('Increase memory limit to 256MB or higher for optimal performance', 'order-daemon');
        }

        // Test 3: WordPress Debug Mode Check
        $debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $debug_log_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        
        if ($debug_enabled && !$this->is_development_environment()) {
            $warnings[] = __('Debug mode should be disabled in production for better performance', 'order-daemon');
            $recommendations[] = __('Disable debug mode in production for better performance and security', 'order-daemon');
        }

        // Test 4: File Permissions Check
        $upload_dir = wp_upload_dir();
        $upload_writable = wp_is_writable($upload_dir['basedir']);
        
        if (!$upload_writable) {
            $issues[] = __('File permission issues detected', 'order-daemon');
            $recommendations[] = __('Ensure wp-content/uploads/ is writable by the web server', 'order-daemon');
        }

        // Test 5: Essential PHP Extensions
        $required_extensions = ['json', 'curl', 'mbstring'];
        $missing_extensions = [];
        
        foreach ($required_extensions as $extension) {
            if (!extension_loaded($extension)) {
                $missing_extensions[] = $extension;
            }
        }

        if (!empty($missing_extensions)) {
            $issues[] = sprintf(
                /* translators: %s: Comma-separated list of missing PHP extensions */
                __('Missing required PHP extensions: %s', 'order-daemon'),
                implode(', ', $missing_extensions)
            );
        }

        // Test 6: Max Execution Time
        $max_execution_time = ini_get('max_execution_time');
        if ($max_execution_time > 0 && $max_execution_time < 30) {
            $warnings[] = sprintf(
                /* translators: %d: Current max execution time in seconds */
                __('Max execution time is low (%d seconds) - may cause timeouts', 'order-daemon'),
                $max_execution_time
            );
        }

        // Compile detailed results
        $details = [
            'php_version' => $php_version,
            'php_compatible' => $php_compatible,
            'memory_limit' => $this->format_bytes($memory_limit),
            'memory_sufficient' => $memory_sufficient,
            'debug_enabled' => $debug_enabled,
            'debug_log_enabled' => $debug_log_enabled,
            'upload_writable' => $upload_writable,
            'upload_dir' => $upload_dir['basedir'],
            'missing_extensions' => $missing_extensions,
            'max_execution_time' => $max_execution_time,
            'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
        ];

        // Determine overall result
        $has_critical_issues = !empty($issues);

        if ($has_critical_issues) {
            $result = DiagnosticResult::failure(
                __('Environment Configuration Issue', 'order-daemon'),
                __('Server environment has critical configuration issues', 'order-daemon'),
                $details,
                array_merge($issues, $recommendations)
            );
        } elseif (!empty($warnings)) {
            $result = DiagnosticResult::warning(
                __('Environment Configuration Warning', 'order-daemon'),
                __('Server environment has configuration warnings that should be addressed', 'order-daemon'),
                $details,
                array_merge($warnings, $recommendations)
            );
        } else {
            $result = DiagnosticResult::success(
                __('Environment Configuration OK', 'order-daemon'),
                __('Server environment is properly configured for Order Daemon', 'order-daemon'),
                $details
            );
        }

        $result->setExecutionTime(microtime(true) - $start_time);
        
        return $result;
    }

    /**
     * Check if this appears to be a development environment
     *
     * @return bool
     */
    private function is_development_environment(): bool
    {
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        $development_indicators = [
            'localhost',
            '.local',
            '.dev',
            '.test',
            '127.0.0.1',
            '192.168.',
            '10.0.',
            '172.'
        ];

        foreach ($development_indicators as $indicator) {
            if (strpos($server_name, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }
}
