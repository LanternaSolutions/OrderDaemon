<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Core;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * WP-CLI Diagnostic - Checks WP-CLI Availability for Pro Features
 *
 * This diagnostic verifies that WP-CLI is properly installed and accessible
 * on the server, which is required for all Pro plugin features to function.
 *
 * @package OrderDaemon\CompletionManager\Diagnostics\Core
 */
class WpCliDiagnostic extends AbstractDiagnostic
{
    /**
     * Get the diagnostic name
     *
     * @return string
     */
    public function get_name(): string
    {
        return __('WP-CLI Availability', 'order-daemon');
    }

    /**
     * Get the diagnostic description
     *
     * @return string
     */
    public function get_description(): string
    {
        return __('Checks if WP-CLI is installed and accessible for Pro plugin features', 'order-daemon');
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
        return 1; // Highest priority for pro compatibility
    }

    /**
     * Check if this diagnostic requires the core plugin
     *
     * @return bool
     */
    public function requires_core_plugin(): bool
    {
        return true;
    }

    /**
     * Execute the WP-CLI availability diagnostic
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $start_time = microtime(true);
        
        // Test 1: Check if shell_exec is available
        if (!function_exists('shell_exec')) {
            return DiagnosticResult::failure(
                __('WP-CLI Missing', 'order-daemon'),
                __('Shell execution is disabled on this server', 'order-daemon'),
                [
                    'shell_exec_available' => false,
                    'disabled_functions' => ini_get('disable_functions'),
                ],
                [__('Contact your hosting provider to enable shell_exec() function for WP-CLI access', 'order-daemon')]
            );
        }

        // Test 2: Check if WP-CLI is installed and accessible
        $wp_cli_output = shell_exec('wp --version 2>&1');
        
        if (empty($wp_cli_output)) {
            return DiagnosticResult::failure(
                __('WP-CLI Missing', 'order-daemon'),
                __('WP-CLI is required for Pro plugin features but was not found on this server', 'order-daemon'),
                [
                    'command_tested' => 'wp --version',
                    'output' => 'No output returned',
                    'server_path' => isset($_SERVER['PATH']) ? sanitize_text_field(wp_unslash($_SERVER['PATH'])) : 'Unknown',
                    'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
                ],
                [__('Install WP-CLI through your hosting provider or contact your server administrator', 'order-daemon')]
            );
        }

        // Check if output indicates WP-CLI is present
        if (strpos($wp_cli_output, 'WP-CLI') === false) {
            return DiagnosticResult::failure(
                __('WP-CLI Missing', 'order-daemon'),
                __('WP-CLI command returned invalid output', 'order-daemon'),
                [
                    'command_tested' => 'wp --version',
                    'output' => trim($wp_cli_output),
                    'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
                ],
                [__('Install WP-CLI through your hosting provider or contact your server administrator', 'order-daemon')]
            );
        }

        // Test 3: Check if WP-CLI can access this WordPress installation
        $wp_info_output = shell_exec('wp core version 2>&1');
        $can_access_wp = !empty($wp_info_output) && !strpos($wp_info_output, 'Error:');
        
        $details = [
            'wp_cli_version' => trim($wp_cli_output),
            'wp_access_test' => $can_access_wp ? 'success' : 'failed',
            'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
        ];

        if ($can_access_wp) {
            $details['wp_version_via_cli'] = trim($wp_info_output);
        } else {
            $details['wp_access_error'] = trim($wp_info_output);
        }

        // Success result with comprehensive details
        $result = DiagnosticResult::success(
            __('WP-CLI Available', 'order-daemon'),
            /* translators: %s: WP-CLI version information */
            sprintf(
                /* translators: %s: WP-CLI version string */
                __('WP-CLI version %s is properly installed and can access this WordPress installation', 'order-daemon'),
                trim($wp_cli_output)
            ),
            $details
        );

        // Add warning if WP-CLI can't access WordPress
        if (!$can_access_wp) {
            $result->addRecommendation(__('WP-CLI is installed but may not have access to this WordPress installation', 'order-daemon'));
        }

        $result->setExecutionTime(microtime(true) - $start_time);
        
        return $result;
    }
}
