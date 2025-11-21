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
        'odcm_logs',
        'odcm_payload_data'
    ];

    /**
     * Get the diagnostic name
     *
     * @return string
     */
    public function get_name(): string
    {
        return __('admin.diagnostics.test.plugin_state.name', 'order-daemon');
    }

    /**
     * Get the diagnostic description
     *
     * @return string
     */
    public function get_description(): string
    {
        return __('admin.diagnostics.test.plugin_state.description', 'order-daemon');
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
                __('admin.diagnostics.test.plugin_state.failure.title', 'order-daemon'),
                __('admin.diagnostics.test.plugin_state.failure.core_not_available', 'order-daemon'),
                [
                    'core_plugin_available' => false,
                    'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
                ],
                [__('admin.diagnostics.test.plugin_state.failure.core_recommendation', 'order-daemon')]
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
                __('admin.diagnostics.test.plugin_state.failure.missing_tables', 'order-daemon'),
                implode(', ', $missing_tables)
            );
            $recommendations[] = __('admin.diagnostics.test.plugin_state.failure.recommendation', 'order-daemon');
        }

        // Test 3: Check database connectivity
        global $wpdb;
        $db_connection_test = $wpdb->get_var("SELECT 1");
        $db_connected = $db_connection_test === '1';
        
        if (!$db_connected) {
            $issues[] = __('admin.diagnostics.test.plugin_state.failure.database_error', 'order-daemon');
            $recommendations[] = __('admin.diagnostics.test.plugin_state.failure.database_recommendation', 'order-daemon');
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
            $warnings[] = __('admin.diagnostics.test.plugin_state.warning.version_unknown', 'order-daemon');
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
                __('admin.diagnostics.test.plugin_state.failure.missing_constants', 'order-daemon'),
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
                __('admin.diagnostics.test.plugin_state.warning.problematic_plugins', 'order-daemon'),
                implode(', ', $active_problematic)
            );
            $recommendations[] = __('admin.diagnostics.test.plugin_state.warning.plugin_recommendation', 'order-daemon');
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
                __('admin.diagnostics.test.plugin_state.failure.title', 'order-daemon'),
                __('admin.diagnostics.test.plugin_state.failure.explanation', 'order-daemon'),
                $details,
                array_merge($issues, $recommendations)
            );
        } elseif (!empty($warnings)) {
            $result = DiagnosticResult::warning(
                __('admin.diagnostics.test.plugin_state.warning.title', 'order-daemon'),
                __('admin.diagnostics.test.plugin_state.warning.explanation', 'order-daemon'),
                $details,
                array_merge($warnings, $recommendations)
            );
        } else {
            $result = DiagnosticResult::success(
                __('admin.diagnostics.test.plugin_state.success.title', 'order-daemon'),
                __('admin.diagnostics.test.plugin_state.success.explanation', 'order-daemon'),
                $details
            );

            // Add informational note about table data
            if (!empty($table_data)) {
                $total_logs = $table_data['odcm_logs'] ?? 0;
                if ($total_logs > 0) {
                    $result->addRecommendation(sprintf(
                        /* translators: %d: Number of log entries in database */
                        __('admin.diagnostics.test.plugin_state.info.log_count', 'order-daemon'),
                        $total_logs
                    ));
                }
            }
        }

        $result->setExecutionTime(microtime(true) - $start_time);
        
        return $result;
    }
}
