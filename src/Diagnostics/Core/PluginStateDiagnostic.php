<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Core;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * Plugin State Diagnostic - Checks Order Daemon Plugin Configuration
 *
 * This diagnostic verifies that Order Daemon plugin is properly installed,
 * configured, and has all necessary database tables and settings.
 *
 * @package OrderDaemon\CompletionManager\Diagnostics\Core
 */
class PluginStateDiagnostic extends AbstractDiagnostic
{
    /**
     * Required Order Daemon database tables
     */
    private const REQUIRED_TABLES = [
        'odcm_audit_log',
        'odcm_audit_log_payloads',
        'odcm_audit_log_queue'
    ];

    /**
     * Get the diagnostic name
     *
     * @return string
     */
    public function get_name(): string
    {
        return __('Plugin State', 'order-daemon');
    }

    /**
     * Get the diagnostic description
     *
     * @return string
     */
    public function get_description(): string
    {
        return __('Verifies Order Daemon database tables and configuration', 'order-daemon');
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
        return 4; // High priority for core functionality
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
     * Execute the plugin state diagnostic
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $start_time = microtime(true);
        $issues = [];
        $warnings = [];
        $recommendations = [];

        // Test 1: Check if core plugin is available
        if (!$this->is_core_plugin_available()) {
            return DiagnosticResult::failure(
                __('Plugin State Issue', 'order-daemon'),
                __('Order Daemon core plugin is not properly loaded', 'order-daemon'),
                [
                    'core_plugin_available' => false,
                    'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
                ],
                [__('Ensure Order Daemon plugin is properly installed and activated', 'order-daemon')]
            );
        }

        // Test 2: Check database tables
        $missing_tables = [];
        $table_status = [];
        
        foreach (self::REQUIRED_TABLES as $table) {
            $exists = $this->table_exists($table);
            $table_status[$table] = $exists;
            
            if (!$exists) {
                $missing_tables[] = $table;
            }
        }

        if (!empty($missing_tables)) {
            $issues[] = sprintf(
                /* translators: %s: Comma-separated list of missing database tables */
                __('Missing database tables: %s', 'order-daemon'),
                implode(', ', $missing_tables)
            );
            $recommendations[] = __('Deactivate and reactivate the plugin to recreate missing tables', 'order-daemon');
        }

        // Test 3: Check database connectivity
        global $wpdb;
        $db_connection_test = $wpdb->get_var("SELECT 1");
        $db_connected = $db_connection_test === '1';
        
        if (!$db_connected) {
            $issues[] = __('Database connectivity issue detected', 'order-daemon');
            $recommendations[] = __('Check database connection and server status', 'order-daemon');
        }

        // Test 4: Check table data integrity (if tables exist)
        $table_data = [];
        if (empty($missing_tables) && $db_connected) {
            foreach (self::REQUIRED_TABLES as $table) {
                $row_count = $this->get_table_row_count($table);
                $table_data[$table] = $row_count;
            }
        }

        // Test 5: Check plugin version
        $plugin_version = defined('ODCM_VERSION') ? ODCM_VERSION : 'unknown';
        $version_valid = $plugin_version !== 'unknown';

        if (!$version_valid) {
            $warnings[] = __('Plugin version could not be determined', 'order-daemon');
        }

        // Test 6: Check essential plugin constants
        $required_constants = ['ODCM_PLUGIN_DIR', 'ODCM_PLUGIN_URL', 'ODCM_VERSION'];
        $missing_constants = [];
        
        foreach ($required_constants as $constant) {
            if (!defined($constant)) {
                $missing_constants[] = $constant;
            }
        }

        if (!empty($missing_constants)) {
            $issues[] = sprintf(
                /* translators: %s: Comma-separated list of missing constants */
                __('Missing required plugin constants: %s', 'order-daemon'),
                implode(', ', $missing_constants)
            );
        }

        // Test 7: Check if any known problematic plugins are active
        $problematic_plugins = [
            'some-conflicting-plugin/plugin.php', // Example - add real conflicting plugins
        ];
        
        $active_problematic = [];
        foreach ($problematic_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                $active_problematic[] = $plugin;
            }
        }

        if (!empty($active_problematic)) {
            $warnings[] = sprintf(
                /* translators: %s: Comma-separated list of problematic active plugins */
                __('Potentially conflicting plugins detected: %s', 'order-daemon'),
                implode(', ', $active_problematic)
            );
            $recommendations[] = __('Monitor for plugin conflicts that may affect functionality', 'order-daemon');
        }

        // Compile detailed results
        $details = [
            'core_plugin_available' => $this->is_core_plugin_available(),
            'plugin_version' => $plugin_version,
            'version_valid' => $version_valid,
            'table_status' => $table_status,
            'table_data' => $table_data,
            'missing_tables' => $missing_tables,
            'missing_constants' => $missing_constants,
            'db_connected' => $db_connected,
            'active_problematic_plugins' => $active_problematic,
            'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
        ];

        // Determine overall result
        $has_critical_issues = !empty($issues);

        if ($has_critical_issues) {
            $result = DiagnosticResult::failure(
                __('Plugin State Issue', 'order-daemon'),
                __('Order Daemon plugin has critical configuration issues', 'order-daemon'),
                $details,
                array_merge($issues, $recommendations)
            );
        } elseif (!empty($warnings)) {
            $result = DiagnosticResult::warning(
                __('Plugin State Warning', 'order-daemon'),
                __('Order Daemon plugin has minor configuration warnings', 'order-daemon'),
                $details,
                array_merge($warnings, $recommendations)
            );
        } else {
            $result = DiagnosticResult::success(
                __('Plugin State OK', 'order-daemon'),
                __('Order Daemon is properly installed and configured', 'order-daemon'),
                $details
            );

            // Add informational note about table data
            if (!empty($table_data)) {
                $total_logs = $table_data['odcm_audit_log'] ?? 0;
                if ($total_logs > 0) {
                    $result->addRecommendation(sprintf(
                        /* translators: %d: Number of log entries in database */
                        __('Database contains %d log entries', 'order-daemon'),
                        $total_logs
                    ));
                }
            }
        }

        $result->setExecutionTime(microtime(true) - $start_time);
        
        return $result;
    }
}
