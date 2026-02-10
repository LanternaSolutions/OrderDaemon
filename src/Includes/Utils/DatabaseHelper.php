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
        $cache_key = 'odcm_table_exists_' . md5($table_name);
        $cached_result = wp_cache_get($cache_key, 'odcm_database');

        if ($cached_result !== false) {
            return $cached_result;
        }

        try {
            $result = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    '%' . $this->wpdb->esc_like($table_name) . '%'
                )
            );

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
        if (empty($table_name) || !$this->table_exists($table_name) || !self::validate_table_name($table_name)) {
            return true; // Table doesn't exist or invalid name, consider it successful
        }

        try {
            $result = $this->wpdb->query($this->wpdb->prepare("DROP TABLE IF EXISTS %s", $table_name));

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
     * @param mixed $default Default value if option doesn't exist
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
        $cache_key = 'odcm_option_' . $option_name;
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
     * @param mixed $value Option value to set
     * @param string $autoload Whether to autoload option (default: 'yes')
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
        if (empty($pattern) || !$this->validate_option_name($pattern)) {
            return 0;
        }

        try {
            $deleted_count = 0;
            $options = $this->get_results(
                $this->wpdb->prepare(
                    "SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE %s",
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

            // Delete regular transients
            $deleted_count += $this->delete_options_by_pattern('_transient_' . $pattern);

            // Delete transient timeouts
            $deleted_count += $this->delete_options_by_pattern('_transient_timeout_' . $pattern);

            // Delete site transients
            $deleted_count += $this->delete_options_by_pattern('_site_transient_' . $pattern);

            // Delete site transient timeouts
            $deleted_count += $this->delete_options_by_pattern('_site_transient_timeout_' . $pattern);

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
     * @param array $args Query arguments
     * @return mixed Query result
     */
    public static function query(string $query, array $args = [])
    {
        return self::get_instance()->_query($query, $args);
    }

    private function _query(string $query, array $args = [])
    {
        try {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            return $this->wpdb->query($this->wpdb->prepare($query, ...$args));
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::query failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a single value from database with error handling
     *
     * @param string $query SQL query to execute
     * @param array $args Query arguments
     * @return mixed Single value result
     */
    public static function get_var(string $query, array $args = [])
    {
        return self::get_instance()->_get_var($query, $args);
    }

    private function _get_var(string $query, array $args = [])
    {
        try {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            return $this->wpdb->get_var($this->wpdb->prepare($query, ...$args));
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::get_var failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a row from database with error handling
     *
     * @param string $query SQL query to execute
     * @param array $args Query arguments
     * @param string $output Output type (OBJECT, ARRAY_A, ARRAY_N)
     * @return mixed Row result
     */
    public static function get_row(string $query, array $args = [], string $output = 'OBJECT')
    {
        return self::get_instance()->_get_row($query, $args, $output);
    }

    private function _get_row(string $query, array $args = [], string $output = 'OBJECT')
    {
        try {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            return $this->wpdb->get_row($this->wpdb->prepare($query, ...$args), $output);
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::get_row failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get multiple rows from database with error handling
     *
     * @param string $query SQL query to execute
     * @param array $args Query arguments
     * @param string $output Output type (OBJECT, ARRAY_A, ARRAY_N)
     * @return array Row results
     */
    public static function get_results(string $query, array $args = [], string $output = 'OBJECT'): ?array
    {
        return self::get_instance()->_get_results($query, $args, $output);
    }

    private function _get_results(string $query, array $args = [], string $output = 'OBJECT'): ?array
    {
        try {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $results = $this->wpdb->get_results($this->wpdb->prepare($query, ...$args), $output);

            // Return null if no results found or if results is false (error)
            return $results === false || empty($results) ? null : $results;
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::get_results failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a single column from database with error handling
     *
     * @param string $query SQL query to execute
     * @param array $args Query arguments
     * @param int $column_offset Column offset (0 for first column)
     * @return array Column results
     */
    public static function get_col(string $query, array $args = [], int $column_offset = 0): array
    {
        return self::get_instance()->_get_col($query, $args, $column_offset);
    }

    private function _get_col(string $query, array $args = [], int $column_offset = 0): array
    {
        try {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            return $this->wpdb->get_col($this->wpdb->prepare($query, ...$args), $column_offset);
        } catch (\Throwable $e) {
            $this->log_error("DatabaseHelper::get_col failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Insert data into database with error handling
     *
     * @param string $table_name Table name to insert into
     * @param array $data Data to insert (column => value pairs)
     * @param array $format Optional format array for data types
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
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $this->wpdb->insert($table_name, $data, $format);

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
     * @param string $table_name Table name to update
     * @param array $data Data to update (column => value pairs)
     * @param array $where Where conditions (column => value pairs)
     * @param array $format Optional format array for data types
     * @param array $where_format Optional format array for where conditions
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
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $this->wpdb->update($table_name, $data, $where, $format, $where_format);

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
     * Log an error message with additional context
     *
     * @param string $message Error message to log
     * @param string $operation The database operation that failed
     * @param array $context Additional context information
     * @return void
     */
    private function log_error(string $message, string $operation = '', array $context = []): void
    {
        // Build detailed error message
        $error_message = '[ODCM DatabaseHelper ERROR] ' . $message;
        if (!empty($operation)) {
            $error_message .= " (Operation: {$operation})";
        }

        // Add context information
        if (!empty($context)) {
            $error_message .= " | Context: " . json_encode($context);
        }

        // Use WordPress error logging if available
        if (function_exists('error_log')) {
            error_log($error_message);
        }

        // Also store in a transient for potential debugging
        $log = get_transient('odcm_database_log');
        if (!is_array($log)) {
            $log = [];
        }

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'operation' => $operation,
            'context' => $context,
            'error_type' => 'error'
        ];

        $log[] = $log_entry;
        set_transient('odcm_database_log', $log, HOUR_IN_SECONDS);

        // Store last error for debugging
        update_option('odcm_last_database_error', $log_entry, 'no');
    }

    /**
     * Log a warning message
     *
     * @param string $message Warning message to log
     * @param string $operation The database operation that generated warning
     * @param array $context Additional context information
     * @return void
     */
    private function log_warning(string $message, string $operation = '', array $context = []): void
    {
        // Build detailed warning message
        $warning_message = '[ODCM DatabaseHelper WARNING] ' . $message;
        if (!empty($operation)) {
            $warning_message .= " (Operation: {$operation})";
        }

        // Add context information
        if (!empty($context)) {
            $warning_message .= " | Context: " . json_encode($context);
        }

        // Use WordPress error logging if available
        if (function_exists('error_log')) {
            error_log($warning_message);
        }

        // Also store in a transient for potential debugging
        $log = get_transient('odcm_database_log');
        if (!is_array($log)) {
            $log = [];
        }

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'operation' => $operation,
            'context' => $context,
            'error_type' => 'warning'
        ];

        $log[] = $log_entry;
        set_transient('odcm_database_log', $log, HOUR_IN_SECONDS);
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
            $result = $this->wpdb->get_var("SELECT 1");
            return $result === '1';
        } catch (\Throwable $e) {
            $this->log_error("Database connection check failed: " . $e->getMessage());
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