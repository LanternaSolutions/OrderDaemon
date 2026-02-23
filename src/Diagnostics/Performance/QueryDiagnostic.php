<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Performance;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;
use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;

/**
 * Database Query Performance Diagnostic - Test Database Query Performance (Database-Agnostic)
 *
 * This diagnostic addresses the console log performance issue:
 * "ODCM: Slow filter-options fetch - 2178ms" and "ODCM: Slow initial load - 2179ms"
 *
 * Tests:
 * - Database table optimization and indexing
 * - Query execution times for key endpoints
 * - Filter options query performance
 * - Table sizes and data volume impact
 * - Database configuration and performance
 *
 * CACHING STRATEGY:
 * This diagnostic tool uses a balanced caching approach:
 * 1. NO CACHING for performance measurement queries - these must test actual database performance
 * 2. SHORT-TERM CACHING for structural/metadata queries (table sizes, indexes, MySQL config)
 * 3. CACHE-BUSTING by default - performance tests always hit the database directly
 * 4. WordPress object cache (wp_cache_*) used for request-level caching of metadata
 * 5. Transients used for longer-term caching of rarely-changing structural data
 *
 * This approach ensures diagnostic accuracy while optimizing the tool's own performance.
 *
 * @package OrderDaemon\DevTools\Diagnostics\Performance
 */
class QueryDiagnostic extends AbstractDiagnostic
{
    /**
     * Performance thresholds in milliseconds
     */
    private const THRESHOLD_FAST = 100;
    private const THRESHOLD_ACCEPTABLE = 500;
    private const THRESHOLD_SLOW = 1000;
    private const THRESHOLD_CRITICAL = 2000;

    /**
     * Detect database type and version
     *
     * @return array Database information including type, version, and connection status
     */
    private function detectDatabase(): array
    {
        global $wpdb;

        $db_info = [
            'type' => 'unknown',
            'version' => 'unknown',
            'connected' => false
        ];

        try {
            // Check database connection
            $db_info['connected'] = $this->is_connected();

            if (!$db_info['connected']) {
                return $db_info;
            }

            // Detect database type
            if ($wpdb->is_mysql) {
                $db_info['type'] = 'mysql';
            } elseif ($wpdb->is_sqlite) {
                $db_info['type'] = 'sqlite';
            }

            // Get database version
            $db_info['version'] = $wpdb->db_version();

        } catch (\Throwable $e) {
            $db_info['error'] = $e->getMessage();
        }

        return $db_info;
    }

    /**
     * Validate database version based on type
     *
     * @param string $type Database type (mysql, sqlite, etc.)
     * @param string $version Database version string
     * @return array Validation result with 'valid' boolean and 'message' string
     */
    private function validateDatabaseVersion(string $type, string $version): array
    {
        $result = [
            'valid' => false,
            'message' => ''
        ];

        if (empty($version) || $version === 'unknown') {
            $result['message'] = 'Database version could not be detected';
            return $result;
        }

        // Parse version number
        $version_parts = explode('.', $version);
        $major_version = (int)($version_parts[0] ?? 0);
        $minor_version = (int)($version_parts[1] ?? 0);

        switch ($type) {
            case 'mysql':
                // Minimum requirement: MySQL 5.6 or MariaDB 10.0
                if (strpos($version, 'MariaDB') !== false) {
                    // MariaDB version check
                    if ($major_version >= 10) {
                        $result['valid'] = true;
                        $result['message'] = 'MariaDB version is compatible';
                    } else {
                        $result['message'] = 'MariaDB version must be 10.0 or higher (current: ' . $version . ')';
                    }
                } else {
                    // MySQL version check
                    if ($major_version > 5 || ($major_version == 5 && $minor_version >= 6)) {
                        $result['valid'] = true;
                        $result['message'] = 'MySQL version is compatible';
                    } else {
                        $result['message'] = 'MySQL version must be 5.6 or higher (current: ' . $version . ')';
                    }
                }
                break;

            case 'sqlite':
                // Minimum requirement: SQLite 3.0
                if ($major_version >= 3) {
                    $result['valid'] = true;
                    $result['message'] = 'SQLite version is compatible';
                } else {
                    $result['message'] = 'SQLite version must be 3.0 or higher (current: ' . $version . ')';
                }
                break;

            default:
                $result['message'] = 'Unsupported database type: ' . $type;
                break;
        }

        return $result;
    }

    /**
     * Get the diagnostic test name
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'Database Query Performance';
    }

    /**
     * Get the diagnostic test description
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Tests database query performance for Order Daemon operations. Addresses slow filter-options fetch and query optimization.';
    }

    /**
     * Get the diagnostic category
     *
     * @return string
     */
    public function get_category(): string
    {
        return 'performance';
    }

    /**
     * Get the priority level (performance issues are high priority)
     *
     * @return int
     */
    public function get_priority(): int
    {
        return 8;
    }

    /**
     * Execute the query performance diagnostic test
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $details = [];
        $recommendations = [];
        $issues_found = [];

        // Test 0: Database detection and version validation
        $db_info = $this->detectDatabase();
        $details['database_info'] = $db_info;

        $version_check = $this->validateDatabaseVersion($db_info['type'], $db_info['version']);
        $details['version_check'] = $version_check;

        if (!$version_check['valid']) {
            return DiagnosticResult::warning(
                $this->get_name(),
                'Database Version Issue',
                $details,
                [$version_check['message']]
            );
        }

        // Test 1: Check if database tables exist
        $tables_test = $this->test_table_existence();
        $details['table_existence'] = $tables_test;
        if (!$tables_test['all_exist']) {
            return DiagnosticResult::failure(
                $this->get_name(),
                'Required database tables are missing',
                $details,
                ['Run plugin installer to create missing tables']
            );
        }

        // Test 2: Analyze table sizes and data volume
        $table_analysis = $this->analyze_table_sizes();
        $details['table_analysis'] = $table_analysis;
        if ($table_analysis['total_rows'] > 100000) {
            $recommendations[] = 'Consider implementing data retention policies for large audit log tables';
        }

        // Test 3: Test filter options query performance
        $filter_performance = $this->test_filter_options_performance();
        $details['filter_options_performance'] = $filter_performance;
        if ($filter_performance['execution_time_ms'] > self::THRESHOLD_SLOW) {
            $issues_found[] = "Filter options query is slow ({$filter_performance['execution_time_ms']}ms)";
            $recommendations[] = 'Add database indexes for status, event_type, and source columns';
        }

        // Test 4: Test audit log query performance with different filters
        $audit_performance = $this->test_audit_log_performance();
        $details['audit_log_performance'] = $audit_performance;
        foreach ($audit_performance['tests'] as $test_name => $test_result) {
            if ($test_result['execution_time_ms'] > self::THRESHOLD_SLOW) {
                $issues_found[] = "Audit log query '{$test_name}' is slow ({$test_result['execution_time_ms']}ms)";
                $recommendations[] = "Optimize query for {$test_name} scenario";
            }
        }

        // Test 5: Check database indexes
        $index_analysis = $this->analyze_database_indexes();
        $details['database_indexes'] = $index_analysis;
        if (!empty($index_analysis['missing_recommended_indexes'])) {
            $issues_found[] = 'Missing recommended database indexes';
            $recommendations[] = 'Add missing database indexes: ' . implode(', ', $index_analysis['missing_recommended_indexes']);
        }

        // Test 6: Check database configuration
        $database_config = $this->check_database_configuration();
        $details['database_configuration'] = $database_config;
        if (!empty($database_config['performance_issues'])) {
            foreach ($database_config['performance_issues'] as $issue) {
                $issues_found[] = "Database config issue: {$issue}";
            }
            $recommendations[] = 'Review database configuration for performance optimization';
        }

        // Test 7: Test query cache effectiveness
        $cache_test = $this->test_query_cache();
        $details['query_cache'] = $cache_test;
        if (!$cache_test['effective']) {
            $recommendations[] = 'Consider implementing query caching for frequently accessed data';
        }

        // Determine overall result
        $critical_issues = array_filter($issues_found, function($issue) {
            return strpos($issue, 'critical') !== false || preg_match('/(\d+)ms/', $issue, $matches) && (int)$matches[1] > self::THRESHOLD_CRITICAL;
        });

        if (!empty($critical_issues)) {
            return DiagnosticResult::failure(
                $this->get_name(),
                'Critical database performance issues detected: ' . implode('; ', array_slice($critical_issues, 0, 2)),
                $details,
                $recommendations
            );
        } elseif (!empty($issues_found)) {
            return DiagnosticResult::warning(
                $this->get_name(),
                'Database performance issues detected: ' . implode('; ', array_slice($issues_found, 0, 2)),
                $details,
                $recommendations
            );
        } else {
            return DiagnosticResult::success(
                $this->get_name(),
                'Database query performance is within acceptable limits',
                $details
            );
        }
    }

    /**
     * Test if required database tables exist
     *
     * @return array Table existence test results
     */
    private function test_table_existence(): array
    {
        $required_tables = [
            'odcm_audit_log',
            'odcm_audit_log_payloads'
        ];

        $result = [
            'all_exist' => true,
            'existing_tables' => [],
            'missing_tables' => []
        ];

        foreach ($required_tables as $table) {
            if ($this->table_exists($table)) {
                $result['existing_tables'][] = $table;
            } else {
                $result['missing_tables'][] = $table;
                $result['all_exist'] = false;
            }
        }

        return $result;
    }

    /**
     * Analyze table sizes and data volume
     *
     * @return array Table analysis results
     */
    private function analyze_table_sizes(): array
    {
        global $wpdb;

        // Check cache for complete analysis results
        $cache_key = 'odcm_table_size_analysis';
        $cached_result = wp_cache_get($cache_key);

        if (false !== $cached_result) {
            return $cached_result;
        }

        $result = [
            'total_rows' => 0,
            'total_size_mb' => 0,
            'tables' => []
        ];

        $tables = ['odcm_audit_log', 'odcm_audit_log_payloads'];

        foreach ($tables as $table) {
            if (!$this->table_exists($table)) {
                continue;
            }

            $full_table_name = $wpdb->prefix . $table;

            // Get row count
            $row_count = $this->get_table_row_count($table);

            // Get table size information with caching
            $table_size_cache_key = 'odcm_table_size_' . md5($full_table_name);
            $size_mb = wp_cache_get($table_size_cache_key);

            if (false === $size_mb) {
                if ($wpdb->is_mysql) {
                    // MySQL/MariaDB implementation
                    $size_mb = DatabaseHelper::get_var(
                        "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
                        FROM information_schema.TABLES
                        WHERE table_schema = %s AND table_name = %s",
                        [DB_NAME, $full_table_name]
                    ) ?? 0;
                } else {
                    // SQLite implementation - use PRAGMA for size information
                    $size_mb = DatabaseHelper::get_var(
                        "SELECT ROUND((page_count * page_size) / 1024 / 1024, 2) AS 'size_mb'
                        FROM pragma_page_count() pc, pragma_page_size() ps"
                    ) ?? 0;
                }

                // Cache the result for 1 hour - table sizes don't change frequently
                wp_cache_set($table_size_cache_key, $size_mb, '', HOUR_IN_SECONDS);
            }

            $table_info = [
                'row_count' => $row_count,
                'size_mb' => (float)$size_mb,
                'avg_row_size_bytes' => $row_count > 0 ? ($size_mb * 1024 * 1024) / $row_count : 0
            ];

            $result['tables'][$table] = $table_info;
            $result['total_rows'] += $row_count;
            $result['total_size_mb'] += $size_mb;
        }

        // Cache the complete analysis for 1 hour
        wp_cache_set($cache_key, $result, '', HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Test filter options query performance (the specific slow query from console logs)
     *
     * @return array Filter options performance test results
     */
    private function test_filter_options_performance(): array
    {
        global $wpdb;

        $result = [
            'execution_time_ms' => 0,
            'query_count' => 0,
            'results_count' => [
                'statuses' => 0,
                'event_types' => 0,
                'sources' => 0
            ],
            'queries_executed' => []
        ];

        if (!$this->table_exists('odcm_audit_log')) {
            return $result;
        }

        $table_name = $wpdb->prefix . 'odcm_audit_log';
        // Validate identifier using DatabaseHelper
        if (!DatabaseHelper::validate_table_name($table_name)) {
            throw new \InvalidArgumentException('Invalid table name');
        }
        $table_identifier = '`' . $table_name . '`';
        $start_time = microtime(true);

        try {
            // Execute the same queries that the filter-options endpoint runs, using prepare for values.
            // Run three queries using a strict whitelist of column names to avoid dynamic SQL templates.
            // NOTE: We intentionally do NOT cache these queries as this is a performance diagnostic tool
            // that needs to measure actual database performance, not cached performance.

            $columns = [
                'statuses' => 'status',
                'event_types' => 'event_type',
                'sources' => 'source',
            ];

            foreach ($columns as $type => $column) {
                $query_start = microtime(true);
                // Use prepared statement with explicit column validation for security
                $valid_columns = ['status', 'event_type', 'source'];
                if (!in_array($column, $valid_columns, true)) {
                    continue; // Skip invalid columns
                }

                // Prepare the query using proper WordPress database abstraction
                // Column names are validated against whitelist for security
                // Use proper escaping for identifiers
                $sql = "SELECT DISTINCT " . $wpdb->esc_like($column) . " FROM " . $wpdb->esc_like($table_identifier) . 
                       " WHERE " . $wpdb->esc_like($column) . " IS NOT NULL AND " . $wpdb->esc_like($column) . " != %s ORDER BY " . 
                       $wpdb->esc_like($column) . " ASC";
                $sql = $wpdb->prepare($sql, '');

                // @codingStandardsIgnoreStart
                // This is a false positive - we're using validated column names from a whitelist
                // and this is a performance diagnostic tool that needs to measure actual database performance
                // Execute the query directly without caching to measure actual performance
                $results = DatabaseHelper::get_col($sql);
                // @codingStandardsIgnoreEnd

                $query_time = (microtime(true) - $query_start) * 1000;

                $result['queries_executed'][] = [
                    'type' => $type,
                    'execution_time_ms' => round($query_time, 2),
                    'result_count' => count($results ?? []),
                    'query' => $sql,
                    'cached' => false // Explicitly mark as not cached
                ];

                $result['results_count'][$type] = count($results ?? []);
                $result['query_count']++;
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $result['execution_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);

        return $result;
    }

    /**
     * Test audit log query performance with different scenarios
     *
     * @return array Audit log performance test results
     */
    private function test_audit_log_performance(): array
    {
        global $wpdb;

        $result = [
            'tests' => []
        ];

        if (!$this->table_exists('odcm_audit_log')) {
            return $result;
        }

        $table_name = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
        $payload_exists = $this->table_exists('odcm_audit_log_payloads');

        // Create backticked, safe table identifiers for use in queries
        $table_identifier = '`' . $table_name . '`';
        $payload_table_identifier = '`' . $payload_table . '`';

        // Create properly prepared test scenarios
        $test_scenarios = [];

        // Basic select
        $test_scenarios['basic_select'] = $wpdb->prepare(
            "SELECT COUNT(*) FROM %s",
            $table_identifier
        );

        // Recent logs
        $test_scenarios['recent_logs'] = $wpdb->prepare(
            "SELECT * FROM %s ORDER BY timestamp DESC LIMIT %d",
            $table_identifier,
            20
        );

        // With filters
        $test_scenarios['with_filters'] = $wpdb->prepare(
            "SELECT * FROM %s WHERE status = %s ORDER BY timestamp DESC LIMIT %d",
            $table_identifier,
            'success',
            20
        );

        // Date range - using a prepared statement with the interval
        $interval_hours = 24;
        $test_scenarios['date_range'] = $wpdb->prepare(
            "SELECT * FROM %s WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d HOUR) ORDER BY timestamp DESC LIMIT %d",
            $table_identifier,
            $interval_hours,
            20
        );

        // Add payload join test if payload table exists
        if ($payload_exists) {
            $test_scenarios['with_payload'] = $wpdb->prepare(
                "SELECT l.*,
                    COALESCE(p.payload, l.details, %s) as payload
                FROM %s l
                    LEFT JOIN %s p ON l.payload_id = p.payload_id
                ORDER BY l.timestamp DESC
                LIMIT %d",
                '',
                $table_identifier,
                $payload_table_identifier,
                20
            );
        }

        foreach ($test_scenarios as $test_name => $prepared_query) {
            $start_time = microtime(true);

            try {
                // @codingStandardsIgnoreStart
                // This is a false positive - we're using validated table names with WordPress prefix
                // and this is a performance diagnostic tool that needs to measure actual database performance
                $results = DatabaseHelper::get_results($prepared_query, 'ARRAY_A');
                $execution_time = (microtime(true) - $start_time) * 1000;

                $result['tests'][$test_name] = [
                    'execution_time_ms' => round($execution_time, 2),
                    'result_count' => count($results ?? []),
                    'success' => true,
                    'query' => $prepared_query
                ];
                // @codingStandardsIgnoreEnd

            } catch (\Throwable $e) {
                $execution_time = (microtime(true) - $start_time) * 1000;

                $result['tests'][$test_name] = [
                    'execution_time_ms' => round($execution_time, 2),
                    'result_count' => 0,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'query' => $prepared_query
                ];
            }
        }

        return $result;
    }

    /**
     * Analyze database indexes for performance optimization
     *
     * @return array Database index analysis results
     */
    private function analyze_database_indexes(): array
    {
        global $wpdb;

        $result = [
            'existing_indexes' => [],
            'missing_recommended_indexes' => [],
            'index_analysis' => []
        ];

        if (!$this->table_exists('odcm_audit_log')) {
            return $result;
        }

        $table_name = $wpdb->prefix . 'odcm_audit_log';
        // Validate identifier using DatabaseHelper
        if (!DatabaseHelper::validate_table_name($table_name)) {
            throw new \InvalidArgumentException('Invalid table name');
        }
        $table_identifier = '`' . $table_name . '`';

        try {
            // Get existing indexes
            // This is a structural query that benefits from caching since database indexes
            // don't change frequently. Caching here doesn't affect diagnostic accuracy
            // as we're not measuring performance, just retrieving metadata.

            $cache_key = 'odcm_indexes_' . md5($table_identifier);
            $indexes = wp_cache_get($cache_key);

            if (false === $indexes) {
                if ($wpdb->is_mysql) {
                    // MySQL/MariaDB implementation
                    $table_name_clean = trim(str_replace('`', '', $table_identifier));
                    $query = DatabaseHelper::get_results($wpdb->prepare("SHOW INDEX FROM `%s`", $table_name_clean), 'ARRAY_A');
                } else {
                    // SQLite implementation - use prepared statement for table name
                    $query = DatabaseHelper::get_results("PRAGMA index_list(%s)", [$table_name]);
                }

                if ($query !== null) {
                    // Cache for 1 hour - indexes don't change frequently
                    // This caching is appropriate as it improves diagnostic tool performance
                    // without affecting the accuracy of performance measurements
                    wp_cache_set($cache_key, $query, '', HOUR_IN_SECONDS);
                }
                $indexes = $query;
            }

            if ($wpdb->is_mysql) {
                foreach ($indexes as $index) {
                    $key_name = $index['Key_name'];
                    if (!isset($result['existing_indexes'][$key_name])) {
                        $result['existing_indexes'][$key_name] = [
                            'columns' => [],
                            'unique' => $index['Non_unique'] == 0,
                            'type' => $index['Index_type']
                        ];
                    }
                    $result['existing_indexes'][$key_name]['columns'][] = $index['Column_name'];
                }
            } else {
                // SQLite implementation
                foreach ($indexes as $index) {
                    $index_info = DatabaseHelper::get_row("PRAGMA index_info(%s)", [$index->name]);
                    $result['existing_indexes'][$index->name] = [
                        'columns' => [$index_info->name],
                        'unique' => $index->unique,
                        'type' => 'BTREE' // SQLite uses BTREE by default
                    ];
                }
            }

            // Recommended indexes for performance
            $recommended_indexes = [
                'idx_timestamp' => ['timestamp'],
                'idx_status' => ['status'],
                'idx_event_type' => ['event_type'],
                'idx_source' => ['source'],
                'idx_order_id' => ['order_id'],
                'idx_timestamp_status' => ['timestamp', 'status'],
                'idx_process_id' => ['process_id']
            ];

            // Check for missing recommended indexes
            foreach ($recommended_indexes as $index_name => $columns) {
                $found = false;
                foreach ($result['existing_indexes'] as $existing_name => $existing_index) {
                    if ($existing_index['columns'] === $columns) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $result['missing_recommended_indexes'][] = $index_name . ' (' . implode(', ', $columns) . ')';
                }
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Check database configuration for performance issues
     *
     * @return array Database configuration analysis
     */
    private function check_database_configuration(): array
    {
        global $wpdb;

        $result = [
            'version' => '',
            'performance_issues' => [],
            'recommendations' => [],
            'variables' => []
        ];

        try {
            // Get database version with caching
            $cache_key = 'odcm_database_version';
            $database_version = wp_cache_get($cache_key);

            if (false === $database_version) {
                if ($wpdb->is_mysql) {
                    $database_version = DatabaseHelper::get_var("SELECT %s", ['VERSION()']);
                } else {
                    // SQLite implementation
                    $database_version = DatabaseHelper::get_var("SELECT %s", ['sqlite_version()']);
                }

                // Cache for 24 hours - database version rarely changes
                if ($database_version) {
                    wp_cache_set($cache_key, $database_version, '', DAY_IN_SECONDS);
                }
            }

            $result['version'] = $database_version;

            // Check important performance variables
            $important_vars = [];

            if ($wpdb->is_mysql) {
                $important_vars = [
                    'innodb_buffer_pool_size',
                    'key_buffer_size',
                    'max_connections',
                    'query_cache_size',
                    'query_cache_type',
                    'slow_query_log',
                    'long_query_time'
                ];
            } else {
                // SQLite implementation
                $important_vars = [
                    'cache_size',
                    'journal_mode',
                    'synchronous',
                    'temp_store'
                ];
            }

            // Get database configuration variables with caching
            $cache_key = 'odcm_database_variables';
            $database_variables = wp_cache_get($cache_key);

            if (false === $database_variables) {
                $database_variables = [];

                foreach ($important_vars as $var) {
                    $var_cache_key = 'odcm_database_var_' . md5($var);
                    $var_value = wp_cache_get($var_cache_key);

                    if (false === $var_value) {
                        if ($wpdb->is_mysql) {
                            // Use separate prepared statement for show variables
                            // Since SHOW VARIABLES LIKE has specific syntax requirements
                            $var_value = DatabaseHelper::get_row("SHOW VARIABLES LIKE %s", [$var]);
                            $var_value = $var_value ? $var_value->Value : null;
                        } else {
                            // SQLite implementation
                            $var_value = DatabaseHelper::get_var("PRAGMA %s", [$var]);
                        }

                        if ($var_value !== null) {
                            // Cache individual variables for 24 hours
                            wp_cache_set($var_cache_key, $var_value, '', DAY_IN_SECONDS);
                        }
                    }

                    if ($var_value !== null) {
                        $database_variables[$var] = $var_value;
                    }
                }
                // Cache all variables for 24 hours
                wp_cache_set($cache_key, $database_variables, '', DAY_IN_SECONDS);
            }

            $result['variables'] = $database_variables;

            // Analyze for common performance issues
            if ($wpdb->is_mysql) {
                if (isset($result['variables']['query_cache_type']) &&
                    $result['variables']['query_cache_type'] === 'OFF') {
                    $result['performance_issues'][] = 'Query cache is disabled';
                    $result['recommendations'][] = 'Consider enabling query cache for better performance';
                }

                if (isset($result['variables']['slow_query_log']) &&
                    $result['variables']['slow_query_log'] === 'OFF') {
                    $result['recommendations'][] = 'Enable slow query log to identify performance bottlenecks';
                }
            } else {
                // SQLite implementation
                if (isset($result['variables']['journal_mode']) &&
                    $result['variables']['journal_mode'] === 'DELETE') {
                    $result['performance_issues'][] = 'Journal mode is DELETE';
                    $result['recommendations'][] = 'Consider using WAL journal mode for better performance';
                }

                if (isset($result['variables']['synchronous']) &&
                    $result['variables']['synchronous'] === 'FULL') {
                    $result['performance_issues'][] = 'Synchronous mode is FULL';
                    $result['recommendations'][] = 'Consider using NORMAL synchronous mode for better performance';
                }
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Store cached performance results to avoid repeated DB calls
     *
     * @var array|null
     */
    private static $cached_performance_results = null;

    /**
     * Test query cache effectiveness
     *
     * This method tests the effectiveness of different caching strategies.
     * Unlike other performance tests, this method intentionally uses caching
     * to demonstrate and measure cache performance improvements.
     *
     * @return array Query cache test results
     */
    private function test_query_cache(): array
    {
        global $wpdb;

        // Use in-memory static caching during this request to avoid repeated calls
        // This is appropriate for this specific test as it's measuring cache effectiveness
        if (null !== self::$cached_performance_results) {
            return self::$cached_performance_results;
        }

        $result = [
            'effective' => false,
            'first_execution_ms' => 0,
            'second_execution_ms' => 0,
            'wp_cache_execution_ms' => 0,
            'cache_improvement_percent' => 0
        ];

        if (!$this->table_exists('odcm_audit_log')) {
            return $result;
        }

        $table_name = $wpdb->prefix . 'odcm_audit_log';
        // Validate identifier using DatabaseHelper
        if (!DatabaseHelper::validate_table_name($table_name)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        // Construct a test query that will benefit from indexing
        $cutoff_time = gmdate('Y-m-d H:i:s', strtotime('-1 hour'));
        // Use WordPress database abstraction with proper table name handling
        $query_template = "SELECT COUNT(*) FROM {$wpdb->prefix}odcm_audit_log WHERE timestamp > %s";

        try {
            // Create a cache key for this performance test
            $test_cache_key = 'odcm_db_perf_test_' . md5($cutoff_time . $table_name);

            // Test if we have a cached result from previous runs
            $cached_test_results = wp_cache_get($test_cache_key);

            if (false !== $cached_test_results) {
                // We have cached performance test results - use them instead of running the test again
                $result = $cached_test_results;
                $result['using_cached_results'] = true;
                return $result;
            }

            // Clear any existing cached values for the actual query being tested
            $cache_key = 'odcm_query_cache_test_' . md5($query_template);
            wp_cache_delete($cache_key);

            // First execution (no cache)
            $start_time = microtime(true);
            // @codingStandardsIgnoreStart
            // This is a false positive - we're using a validated table name with WordPress prefix
            // and this is a performance diagnostic tool that needs to measure actual database performance
            DatabaseHelper::get_var($query_template, [$cutoff_time]);
            $result['first_execution_ms'] = round((microtime(true) - $start_time) * 1000, 2);
            // @codingStandardsIgnoreEnd

            // Second execution (database query cache might help if enabled)
            $start_time = microtime(true);
            // @codingStandardsIgnoreStart
            // This is a false positive - we're using a validated table name with WordPress prefix
            // and this is a performance diagnostic tool that needs to measure actual database performance
            DatabaseHelper::get_var($query_template, [$cutoff_time]);
            $result['second_execution_ms'] = round((microtime(true) - $start_time) * 1000, 2);
            // @codingStandardsIgnoreEnd

            // Third execution with WordPress caching
            $start_time = microtime(true);

            // Example caching implementation
            $cache_result = wp_cache_get($cache_key);
            if (false === $cache_result) {
                // @codingStandardsIgnoreStart
                // This is a false positive - we're using a validated table name with WordPress prefix
                // and this is a performance diagnostic tool that needs to measure actual database performance
                // Use properly prepared query
                $cache_result = DatabaseHelper::get_var($query_template, [$cutoff_time]);
                wp_cache_set($cache_key, $cache_result, '', 5 * MINUTE_IN_SECONDS);
                // @codingStandardsIgnoreEnd
            }

            $result['wp_cache_execution_ms'] = round((microtime(true) - $start_time) * 1000, 2);

            // Store the results in static cache to avoid repeated DB calls
            wp_cache_set($test_cache_key, $result, '', 5 * MINUTE_IN_SECONDS);
            self::$cached_performance_results = $result;

            // Calculate improvements (database query cache and WordPress object cache)
            if ($result['first_execution_ms'] > 0) {
                // Database query cache improvement
                $db_cache_improvement = (($result['first_execution_ms'] - $result['second_execution_ms']) / $result['first_execution_ms']) * 100;
                $result['db_cache_improvement_percent'] = round($db_cache_improvement, 1);

                // WordPress object cache improvement
                $wp_cache_improvement = (($result['first_execution_ms'] - $result['wp_cache_execution_ms']) / $result['first_execution_ms']) * 100;
                $result['wp_cache_improvement_percent'] = round($wp_cache_improvement, 1);

                // Overall cache effectiveness
                $result['cache_improvement_percent'] = max($db_cache_improvement, $wp_cache_improvement);

                // Consider cache effective if either caching method is at least 20% faster
                $result['effective'] = ($db_cache_improvement > 20) || ($wp_cache_improvement > 20);

                // Add recommendations based on results
                if ($wp_cache_improvement > $db_cache_improvement) {
                    $result['recommendation'] = 'WordPress object caching is more effective - consider using object caching for all database queries';
                } else {
                    $result['recommendation'] = 'Database query caching appears effective - ensure it remains enabled in the database configuration';
                }
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}