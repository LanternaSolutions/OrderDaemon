<?php
/**
 * Database Helper - WordPress Database Abstraction Layer
 *
 * Provides a unified interface for database operations that automatically
 * uses WordPress database functions and implements proper caching mechanisms.
 *
 * This abstraction layer ensures all database operations follow WordPress
 * best practices and eliminates direct database query warnings.
 *
 * @package OrderDaemon\CompletionManager\Includes\Utils
 * @since   2.0.3
 */

declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

/**
 * Database Helper Class
 *
 * Handles all database operations using WordPress database abstraction
 * and implements proper caching and error handling.
 */
class DatabaseHelper
{
    /**
     * WordPress database object
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    private static ?DatabaseHelper $instance = null;

    /**
     * Initialize the database helper and sets the wpdb object.
     */
    private function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public static function get_instance(): DatabaseHelper
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if a database table exists with caching
     *
     * @param string $table_name Table name to check
     * @return bool True if table exists, false otherwise
     */
    public static function table_exists(string $table_name): bool
    {
        return self::get_instance()->_table_exists($table_name);
    }

    private function _table_exists(string $table_name): bool
    {
        if (empty($table_name)) {
            return false;
        }

        // Use WordPress caching for table existence checks
        $cache_key      = 'odcm_table_exists_' . md5($table_name);
        $cached_result  = wp_cache_get($cache_key, 'odcm_database');

        if ($cached_result !== false) {
            return $cached_result;
        }

        try {
            $query  = $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                '%' . $this->wpdb->esc_like($table_name) . '%'
            );
            $result = $this->get_var($query);

            $table_exists = ! empty($result);
            wp_cache_set($cache_key, $table_exists, 'odcm_database', HOUR_IN_SECONDS);

            return $table_exists;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::table_exists failed for table '{$table_name}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Drop a database table safely with error handling
     *
     * @param string $table_name Table name to drop
     * @return bool True on success, false on failure
     */
    public static function drop_table(string $table_name): bool
    {
        return self::get_instance()->_drop_table($table_name);
    }

    private function _drop_table(string $table_name): bool
    {
        if (empty($table_name) || ! $this->table_exists($table_name) || ! self::validate_table_name($table_name)) {
            return true; // Table doesn't exist or invalid name, consider it successful
        }

        try {
            // WordPress prepare() cannot be used for table names (identifiers).
            $result = $this->query("DROP TABLE IF EXISTS `{$table_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            if ($result === false) {
                $this->log_error("DatabaseHelper::drop_table failed for table '{$table_name}'");
                return false;
            }

            // Clear cache after table deletion
            $cache_key = 'odcm_table_exists_' . md5($table_name);
            wp_cache_delete($cache_key, 'odcm_database');

            return true;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::drop_table failed for table '{$table_name}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get option value with caching
     *
     * @param string $option_name Option name to retrieve
     * @param mixed  $default     Default value if option doesn't exist
     * @return mixed Option value or default value
     */
    public static function get_option(string $option_name, $default = false)
    {
        return self::get_instance()->_get_option($option_name, $default);
    }

    private function _get_option(string $option_name, $default = false)
    {
        if (empty($option_name)) {
            return $default;
        }

        // Use WordPress caching for options
        $cache_key     = 'odcm_option_' . $option_name;
        $cached_result = wp_cache_get($cache_key, 'odcm_options');

        if ($cached_result !== false) {
            return $cached_result;
        }

        try {
            $value = get_option($option_name, $default);
            wp_cache_set($cache_key, $value, 'odcm_options', HOUR_IN_SECONDS);

            return $value;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::get_option failed for option '{$option_name}': " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Update option value with caching
     *
     * @param string $option_name Option name to update
     * @param mixed  $value       Option value to set
     * @param string $autoload    Whether to autoload option (default: 'yes')
     * @return bool True on success, false on failure
     */
    public static function update_option(string $option_name, $value, string $autoload = 'yes'): bool
    {
        return self::get_instance()->_update_option($option_name, $value, $autoload);
    }

    private function _update_option(string $option_name, $value, string $autoload = 'yes'): bool
    {
        if (empty($option_name)) {
            return false;
        }

        try {
            $result = update_option($option_name, $value, $autoload);

            if ($result) {
                // Clear cache after option update
                $cache_key = 'odcm_option_' . $option_name;
                wp_cache_set($cache_key, $value, 'odcm_options', HOUR_IN_SECONDS);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::update_option failed for option '{$option_name}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete option value with caching
     *
     * @param string $option_name Option name to delete
     * @return bool True on success, false on failure
     */
    public static function delete_option(string $option_name): bool
    {
        return self::get_instance()->_delete_option($option_name);
    }

    private function _delete_option(string $option_name): bool
    {
        if (empty($option_name)) {
            return false;
        }

        try {
            $result = delete_option($option_name);

            if ($result) {
                // Clear cache after option deletion
                $cache_key = 'odcm_option_' . $option_name;
                wp_cache_delete($cache_key, 'odcm_options');
            }

            return $result;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::delete_option failed for option '{$option_name}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete options matching a pattern with caching
     *
     * @param string $pattern Pattern to match option names
     * @return int Number of options deleted
     */
    public static function delete_options_by_pattern(string $pattern): int
    {
        return self::get_instance()->_delete_options_by_pattern($pattern);
    }

    private function _delete_options_by_pattern(string $pattern): int
    {
        if (empty($pattern) || ! $this->validate_option_name($pattern)) {
            return 0;
        }

        try {
            $deleted_count = 0;

            // Options table name from $wpdb is trusted.
            $option_table = $this->wpdb->options;

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name is trusted.
            $sql = "SELECT option_name FROM `{$option_table}` WHERE option_name LIKE %s";

            $options = $this->get_results(
                $this->wpdb->prepare(
                    $sql,
                    '%' . $this->wpdb->esc_like(sanitize_text_field($pattern)) . '%'
                )
            );

            // Add explicit escaping for LIKE queries
            $pattern = $this->wpdb->esc_like($pattern);

            foreach ($options as $option) {
                if ($this->delete_option($option->option_name)) {
                    $deleted_count++;
                }
            }

            return $deleted_count;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::delete_options_by_pattern failed for pattern '{$pattern}': " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete transients matching a pattern
     *
     * @param string $pattern Pattern to match transient names
     * @return int Number of transients deleted
     */
    public static function delete_transients_by_pattern(string $pattern): int
    {
        return self::get_instance()->_delete_transients_by_pattern($pattern);
    }

    private function _delete_transients_by_pattern(string $pattern): int
    {
        if (empty($pattern)) {
            return 0;
        }

        try {
            $deleted_count = 0;

            // Sanitize and escape pattern for safe concatenation
            $sanitized_pattern = sanitize_text_field($pattern);
            $escaped_pattern   = $this->wpdb->esc_like($sanitized_pattern);

            // Delete regular transients
            $deleted_count += $this->delete_options_by_pattern('_transient_' . $escaped_pattern);

            // Delete transient timeouts
            $deleted_count += $this->delete_options_by_pattern('_transient_timeout_' . $escaped_pattern);

            // Delete site transients
            $deleted_count += $this->delete_options_by_pattern('_site_transient_' . $escaped_pattern);

            // Delete site transient timeouts
            $deleted_count += $this->delete_options_by_pattern('_site_transient_timeout_' . $escaped_pattern);

            // Clear WordPress object cache
            wp_cache_flush();

            return $deleted_count;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::delete_transients_by_pattern failed for pattern '{$pattern}': " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Execute a safe database query with error handling
     *
     * @param string $query SQL query to execute
     * @param array  $args  Query arguments
     * @return mixed Query result
     */
    public static function query(string $query, array $args = [])
    {
        return self::get_instance()->_query($query, $args);
    }

    private function _query(string $query, array $args = [])
    {
        if (! $this->validate_query($query)) {
            return false;
        }

        try {
            $sanitized_args = $this->sanitize_query_args($args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamic by design in this helper.
            $prepared_query = $this->wpdb->prepare($query, $sanitized_args);
            
            if (empty($prepared_query)) {
                $this->log_warning(
                    'DatabaseHelper::query failed to prepare query.',
                    'query',
                    ['query' => $query]
                );
                return false;
            }

            $this->log_query($query, $sanitized_args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
            return $this->wpdb->query($prepared_query);
            
        } catch (\Throwable $e) {
            $this->log_error('DatabaseHelper::query failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a single value from database with error handling
     *
     * @param string $query SQL query to execute
     * @param array  $args  Query arguments
     * @return mixed Single value result
     */
    public static function get_var(string $query, array $args = [])
    {
        return self::get_instance()->_get_var($query, $args);
    }

    private function _get_var(string $query, array $args = [])
    {
        if (! $this->validate_query($query)) {
            return null;
        }

        try {
            $sanitized_args = $this->sanitize_query_args($args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamic.
            $prepared_query = $this->wpdb->prepare($query, $sanitized_args);

            if (empty($prepared_query)) {
                $this->log_warning(
                    'DatabaseHelper::get_var failed to prepare query.',
                    'get_var',
                    ['query' => $query]
                );
                return null;
            }

            $this->log_query($query, $sanitized_args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
            return $this->wpdb->get_var($prepared_query);

        } catch (\Throwable $e) {
            $this->log_error('DatabaseHelper::get_var failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a row from database with error handling
     *
     * @param string $query  SQL query to execute
     * @param array  $args   Query arguments
     * @param string $output Output type (OBJECT, ARRAY_A, ARRAY_N)
     * @return mixed Row result
     */
    public static function get_row(string $query, array $args = [], string $output = 'OBJECT')
    {
        return self::get_instance()->_get_row($query, $args, $output);
    }

    private function _get_row(string $query, array $args = [], string $output = 'OBJECT')
    {
        if (! $this->validate_query($query)) {
            return null;
        }

        try {
            $sanitized_args = $this->sanitize_query_args($args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamic.
            $prepared_query = $this->wpdb->prepare($query, $sanitized_args);

            if (empty($prepared_query)) {
                $this->log_warning(
                    'DatabaseHelper::get_row failed to prepare query.',
                    'get_row',
                    ['query' => $query]
                );
                return null;
            }

            $this->log_query($query, $sanitized_args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
            return $this->wpdb->get_row($prepared_query, $output);

        } catch (\Throwable $e) {
            $this->log_error('DatabaseHelper::get_row failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get multiple rows from database with error handling
     *
     * @param string $query  SQL query to execute
     * @param array  $args   Query arguments
     * @param string $output Output type (OBJECT, ARRAY_A, ARRAY_N)
     * @return array|null Row results
     */
    public static function get_results(string $query, array $args = [], string $output = 'OBJECT'): ?array
    {
        return self::get_instance()->_get_results($query, $args, $output);
    }

    private function _get_results(string $query, array $args = [], string $output = 'OBJECT'): ?array
    {
        if (! $this->validate_query($query)) {
            return null;
        }

        try {
            $sanitized_args = $this->sanitize_query_args($args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamic.
            $prepared_query = $this->wpdb->prepare($query, $sanitized_args);

            if (empty($prepared_query)) {
                $this->log_warning(
                    'DatabaseHelper::get_results failed to prepare query.',
                    'get_results',
                    ['query' => $query]
                );
                return null;
            }

            $this->log_query($query, $sanitized_args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
            $results = $this->wpdb->get_results($prepared_query, $output);

            // Return null if no results found or if results is false (error)
            return $results === false || empty($results) ? null : $results;
        } catch (\Throwable $e) {
            $this->log_error('DatabaseHelper::get_results failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a single column from database with error handling
     *
     * @param string $query         SQL query to execute
     * @param array  $args          Query arguments
     * @param int    $column_offset Column offset (0 for first column)
     * @return array Column results
     */
    public static function get_col(string $query, array $args = [], int $column_offset = 0): array
    {
        return self::get_instance()->_get_col($query, $args, $column_offset);
    }

    private function _get_col(string $query, array $args = [], int $column_offset = 0): array
    {
        if (! $this->validate_query($query)) {
            return [];
        }

        try {
            $sanitized_args = $this->sanitize_query_args($args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamic.
            $prepared_query = $this->wpdb->prepare($query, $sanitized_args);

            if (empty($prepared_query)) {
                $this->log_warning(
                    'DatabaseHelper::get_col failed to prepare query.',
                    'get_col',
                    ['query' => $query]
                );
                return [];
            }

            $this->log_query($query, $sanitized_args);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
            return $this->wpdb->get_col($prepared_query, $column_offset);

        } catch (\Throwable $e) {
            $this->log_error('DatabaseHelper::get_col failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Insert data into database with error handling
     *
     * @param string     $table_name Table name to insert into
     * @param array      $data       Data to insert (column => value pairs)
     * @param array|null $format     Optional format array for data types
     * @return int|false The number of rows inserted, or false on error
     */
    public static function insert(string $table_name, array $data, array $format = null)
    {
        return self::get_instance()->_insert($table_name, $data, $format);
    }

    private function _insert(string $table_name, array $data, array $format = null)
    {
        if (empty($table_name) || empty($data)) {
            return false;
        }

        try {
            $result = $this->wpdb->insert($table_name, $data, $format); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            if ($result === false) {
                $this->log_error("DatabaseHelper::insert failed for table '{$table_name}': " . $this->wpdb->last_error);
                return false;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::insert failed for table '{$table_name}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update data in database with error handling
     *
     * @param string     $table_name   Table name to update
     * @param array      $data         Data to update (column => value pairs)
     * @param array      $where        Where conditions (column => value pairs)
     * @param array|null $format       Optional format array for data types
     * @param array|null $where_format Optional format array for where conditions
     * @return int|false The number of rows updated, or false on error
     */
    public static function update(string $table_name, array $data, array $where, array $format = null, array $where_format = null)
    {
        return self::get_instance()->_update($table_name, $data, $where, $format, $where_format);
    }

    private function _update(string $table_name, array $data, array $where, array $format = null, array $where_format = null)
    {
        if (empty($table_name) || empty($data) || empty($where)) {
            return false;
        }

        try {
            $result = $this->wpdb->update($table_name, $data, $where, $format, $where_format); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            if ($result === false) {
                $this->log_error("DatabaseHelper::update failed for table '{$table_name}': " . $this->wpdb->last_error);
                return false;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::update failed for table '{$table_name}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate SQL query for security
     *
     * @param string $query SQL query to validate
     * @return bool True if query is safe, false otherwise
     */
    private function validate_query(string $query): bool
    {
        // Only allow SELECT, INSERT, UPDATE, DELETE, and specific safe operations
        $allowed_operations = '/^\s*(SELECT|INSERT|UPDATE|DELETE|SHOW|DESCRIBE|EXPLAIN)\s+/i';

        if (! preg_match($allowed_operations, $query)) {
            $this->log_error(
                'DatabaseHelper: Invalid SQL operation in query',
                'query_validation',
                ['query' => $query]
            );
            return false;
        }

        // Check for dangerous patterns
        $dangerous_patterns = [
            '/DROP\s+TABLE/i',
            '/TRUNCATE\s+TABLE/i',
            '/ALTER\s+TABLE/i',
            '/RENAME\s+TABLE/i',
            '/CREATE\s+TABLE/i',
            '/DELETE\s+FROM\s+WHERE\s+1=1/i',
            '/UPDATE\s+SET\s+WHERE\s+1=1/i',
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $this->log_error(
                    'DatabaseHelper: Dangerous SQL pattern detected',
                    'query_validation',
                    ['query' => $query]
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize query parameters before preparation
     *
     * @param array $args Query arguments to sanitize
     * @return array Sanitized arguments
     */
    private function sanitize_query_args(array $args): array
    {
        $sanitized_args = [];

        foreach ($args as $arg) {
            if (is_string($arg)) {
                $sanitized_args[] = sanitize_text_field($arg);
            } elseif (is_int($arg) || is_float($arg)) {
                $sanitized_args[] = $arg;
            } elseif (is_array($arg)) {
                $sanitized_args[] = $this->sanitize_query_args($arg);
            } else {
                $sanitized_args[] = null;
            }
        }

        return $sanitized_args;
    }

    /**
     * Log SQL query for debugging purposes
     *
     * @param string $query SQL query to log
     * @param array  $args  Query arguments
     * @return void
     */
    private function log_query(string $query, array $args = []): void
    {
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $log_message = 'SQL Query: ' . $query;
            if (! empty($args)) {
                $log_message .= ' | Args: ' . json_encode($args);
            }
            $this->log_debug($log_message, 'sql_query');
        }
    }

    /**
     * Validate table name for SQL queries
     *
     * @param string $table_name Table name to validate
     * @return bool True if valid, false otherwise
     */
    public static function validate_table_name(string $table_name): bool
    {
        // Only allow alphanumeric characters, underscores, and hyphens
        return preg_match('/^[a-zA-Z0-9_-]+$/', $table_name) === 1;
    }

    /**
     * Validate option name for SQL queries
     *
     * @param string $option_name Option name to validate
     * @return bool True if valid, false otherwise
     */
    private function validate_option_name(string $option_name): bool
    {
        // Only allow alphanumeric characters, underscores, hyphens, and periods
        return preg_match('/^[a-zA-Z0-9_.-]+$/', $option_name) === 1;
    }

    /**
     * Log an error message with additional context.
     *
     * @param string $message   The error message to log.
     * @param string $operation The database operation that failed (optional).
     * @param array  $context   Additional context data (optional).
     */
    private function log_error(string $message, string $operation = '', array $context = []): void
    {
        // Build a detailed error message.
        $error_message = '[ODCM DatabaseHelper ERROR] ' . $message;
        if (! empty($operation)) {
            $error_message .= " (Operation: {$operation})";
        }

        // Add context information if provided.
        if (! empty($context)) {
            $error_message .= ' | Context: ' . json_encode($context);
        }

        // Use WordPress debug logger when available; otherwise do nothing.
        if (function_exists('wp_debug_log')) {
            wp_debug_log($error_message);
        }

        // Persist the error in a transient for debugging purposes.
        $log = get_transient('odcm_database_log');
        if (! is_array($log)) {
            $log = [];
        }

        $log_entry = [
            'timestamp'  => current_time('mysql'),
            'message'    => $message,
            'operation'  => $operation,
            'context'    => $context,
            'error_type' => 'error',
        ];

        $log[] = $log_entry;
        set_transient('odcm_database_log', $log, HOUR_IN_SECONDS);

        // Store the most recent error in an option for quick access.
        update_option('odcm_last_database_error', $log_entry, 'no');
    }

    /**
     * Log a warning message with additional context.
     *
     * @param string $message   The warning message to log.
     * @param string $operation The database operation that generated the warning (optional).
     * @param array  $context   Additional context data (optional).
     */
    private function log_warning(string $message, string $operation = '', array $context = []): void
    {
        // Build a detailed warning message.
        $warning_message = '[ODCM DatabaseHelper WARNING] ' . $message;
        if (! empty($operation)) {
            $warning_message .= " (Operation: {$operation})";
        }

        // Add context information if provided.
        if (! empty($context)) {
            $warning_message .= ' | Context: ' . json_encode($context);
        }

        // Use WordPress debug logger when available; otherwise do nothing.
        if (function_exists('wp_debug_log')) {
            wp_debug_log($warning_message);
        }

        // Persist the warning in a transient for debugging purposes.
        $log = get_transient('odcm_database_log');
        if (! is_array($log)) {
            $log = [];
        }

        $log_entry = [
            'timestamp'  => current_time('mysql'),
            'message'    => $message,
            'operation'  => $operation,
            'context'    => $context,
            'error_type' => 'warning',
        ];

        $log[] = $log_entry;
        set_transient('odcm_database_log', $log, HOUR_IN_SECONDS);
    }

    /**
     * Log a debug message
     *
     * @param string $message   Debug message to log
     * @param string $operation The database operation that generated debug message
     * @param array  $context   Additional context information
     * @return void
     */
    private function log_debug(string $message, string $operation = '', array $context = []): void
    {
        // Build a detailed debug message.
        $debug_message = '[ODCM DatabaseHelper DEBUG] ' . $message;
        if (! empty($operation)) {
            $debug_message .= " (Operation: {$operation})";
        }

        // Add context information if provided.
        if (! empty($context)) {
            $debug_message .= ' | Context: ' . json_encode($context);
        }

        // Use WordPress debug logger when available; otherwise do nothing.
        if (function_exists('wp_debug_log')) {
            wp_debug_log($debug_message);
        }
    }

    /**
     * Check if database connection is active
     *
     * @return bool True if connected, false otherwise
     */
    public function is_connected(): bool
    {
        try {
            // Simple test query to check connection
            $result = $this->wpdb->get_var('SELECT 1');
            return $result === '1';
        } catch (\Throwable $e) {
            $this->log_error('Database connection check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get database logs
     *
     * @return array Array of log entries
     */
    public function get_logs(): array
    {
        $logs = get_transient('odcm_database_log');
        return is_array($logs) ? $logs : [];
    }

    /**
     * Clear database logs
     *
     * @return bool True if logs were cleared, false otherwise
     */
    public function clear_logs(): bool
    {
        return delete_transient('odcm_database_log');
    }
}
