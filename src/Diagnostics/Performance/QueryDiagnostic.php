<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Performance;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * Query Performance Diagnostic - Test Database Query Performance
 *
 * This diagnostic addresses the console log performance issue:
 * "ODCM: Slow filter-options fetch - 2178ms" and "ODCM: Slow initial load - 2179ms"
 *
 * Tests:
 * - Database table optimization and indexing
 * - Query execution times for key endpoints
 * - Filter options query performance
 * - Table sizes and data volume impact
 * - MySQL configuration and performance
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

        // Test 6: Check MySQL configuration
        $mysql_config = $this->check_mysql_configuration();
        $details['mysql_configuration'] = $mysql_config;
        if (!empty($mysql_config['performance_issues'])) {
            foreach ($mysql_config['performance_issues'] as $issue) {
                $issues_found[] = "MySQL config issue: {$issue}";
            }
            $recommendations[] = 'Review MySQL configuration for performance optimization';
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
            
            // Get table size information
            $size_mb = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
                    FROM information_schema.TABLES 
                    WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $full_table_name
                )
            ) ?? 0;

            $table_info = [
                'row_count' => $row_count,
                'size_mb' => (float)$size_mb,
                'avg_row_size_bytes' => $row_count > 0 ? ($size_mb * 1024 * 1024) / $row_count : 0
            ];

            $result['tables'][$table] = $table_info;
            $result['total_rows'] += $row_count;
            $result['total_size_mb'] += $size_mb;
        }

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
        $start_time = microtime(true);

        try {
            // Execute the same queries that the filter-options endpoint runs
            $queries = [
                'statuses' => "SELECT DISTINCT status FROM $table_name WHERE status IS NOT NULL AND status != '' ORDER BY status ASC",
                'event_types' => "SELECT DISTINCT event_type FROM $table_name WHERE event_type IS NOT NULL AND event_type != '' ORDER BY event_type ASC",
                'sources' => "SELECT DISTINCT source FROM $table_name WHERE source IS NOT NULL AND source != '' ORDER BY source ASC"
            ];

            foreach ($queries as $type => $query) {
                $query_start = microtime(true);
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This loop runs multiple queries, none of which use any user input.
                $results = $wpdb->get_col($query);
                $query_time = (microtime(true) - $query_start) * 1000;

                $result['queries_executed'][] = [
                    'type' => $type,
                    'execution_time_ms' => round($query_time, 2),
                    'result_count' => count($results ?? []),
                    'query' => $query
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

        // Test scenarios that match the insight dashboard usage
        $test_scenarios = [
            'basic_select' => "SELECT COUNT(*) FROM $table_name",
            'recent_logs' => "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT 20",
            'with_filters' => "SELECT * FROM $table_name WHERE status = 'success' ORDER BY timestamp DESC LIMIT 20",
            'date_range' => "SELECT * FROM $table_name WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY timestamp DESC LIMIT 20"
        ];

        // Add payload join test if payload table exists
        if ($payload_exists) {
            $test_scenarios['with_payload'] =
                "SELECT l.*,
                    COALESCE(p.payload, l.details, '') as payload
                FROM $table_name l
                    LEFT JOIN $payload_table p ON l.payload_id = p.payload_id
                ORDER BY l.timestamp DESC
                LIMIT 20";
        }

        foreach ($test_scenarios as $test_name => $query) {
            $start_time = microtime(true);
            
            try {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This loop runs multiple queries, none of which use any user input.
                $results = $wpdb->get_results($query, ARRAY_A);
                $execution_time = (microtime(true) - $start_time) * 1000;
                
                $result['tests'][$test_name] = [
                    'execution_time_ms' => round($execution_time, 2),
                    'result_count' => count($results ?? []),
                    'success' => true,
                    'query' => $query
                ];
                
            } catch (\Throwable $e) {
                $execution_time = (microtime(true) - $start_time) * 1000;
                
                $result['tests'][$test_name] = [
                    'execution_time_ms' => round($execution_time, 2),
                    'result_count' => 0,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'query' => $query
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

        try {
            // Get existing indexes
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name", ARRAY_A);
            
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
     * Check MySQL configuration for performance issues
     *
     * @return array MySQL configuration analysis
     */
    private function check_mysql_configuration(): array
    {
        global $wpdb;
        
        $result = [
            'version' => '',
            'performance_issues' => [],
            'recommendations' => [],
            'variables' => []
        ];

        try {
            // Get MySQL version
            $result['version'] = $wpdb->get_var("SELECT VERSION()");

            // Check important performance variables
            $important_vars = [
                'innodb_buffer_pool_size',
                'key_buffer_size',
                'max_connections',
                'query_cache_size',
                'query_cache_type',
                'slow_query_log',
                'long_query_time'
            ];

            foreach ($important_vars as $var) {
                $value = $wpdb->get_var($wpdb->prepare("SHOW VARIABLES LIKE %s", $var));
                if ($value !== null) {
                    $result['variables'][$var] = $value;
                }
            }

            // Analyze for common performance issues
            if (isset($result['variables']['query_cache_type']) && 
                $result['variables']['query_cache_type'] === 'OFF') {
                $result['performance_issues'][] = 'Query cache is disabled';
                $result['recommendations'][] = 'Consider enabling query cache for better performance';
            }

            if (isset($result['variables']['slow_query_log']) && 
                $result['variables']['slow_query_log'] === 'OFF') {
                $result['recommendations'][] = 'Enable slow query log to identify performance bottlenecks';
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Test query cache effectiveness
     *
     * @return array Query cache test results
     */
    private function test_query_cache(): array
    {
        global $wpdb;
        
        $result = [
            'effective' => false,
            'first_execution_ms' => 0,
            'second_execution_ms' => 0,
            'cache_improvement_percent' => 0
        ];

        if (!$this->table_exists('odcm_audit_log')) {
            return $result;
        }

        $table_name = $wpdb->prefix . 'odcm_audit_log';
        $test_query =
            "SELECT COUNT(*)
            FROM $table_name
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        try {
            // First execution
            $start_time = microtime(true);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The same query is run twice and does not use any user input.
            $wpdb->get_var($test_query);
            $result['first_execution_ms'] = round((microtime(true) - $start_time) * 1000, 2);

            // Second execution (should hit cache)
            $start_time = microtime(true);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The same query is run twice and does not use any user input.
            $wpdb->get_var($test_query);
            $result['second_execution_ms'] = round((microtime(true) - $start_time) * 1000, 2);

            // Calculate improvement
            if ($result['first_execution_ms'] > 0) {
                $improvement = (($result['first_execution_ms'] - $result['second_execution_ms']) / $result['first_execution_ms']) * 100;
                $result['cache_improvement_percent'] = round($improvement, 1);
                
                // Consider cache effective if second query is at least 20% faster
                $result['effective'] = $improvement > 20;
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
