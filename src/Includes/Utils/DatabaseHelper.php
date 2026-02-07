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
    private static \wpdb $wpdb;

    /**
     * Initialize the database helper with WordPress database object
     *
     * @param \wpdb $wpdb WordPress database object
     * @return void
     */
    public static function initialize(\wpdb $wpdb): void
    {
        self::$wpdb = $wpdb;
    }

    /**
     * Check if a database table exists with caching
     *
     * @param string $table_name Table name to check
     * @return bool True if table exists, false otherwise
     */
    public static function table_exists(string $table_name): bool
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
            $result = self::$wpdb->get_var("SHOW TABLES LIKE '" . self::$wpdb->esc_like($table_name) . "'");

            $table_exists = ($result === $table_name);
            wp_cache_set($cache_key, $table_exists, 'odcm_database', HOUR_IN_SECONDS);

            return $table_exists;
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::table_exists failed for table '{$table_name}': " . $e->getMessage());
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
        if (empty($table_name) || !self::table_exists($table_name) || !self::validate_table_name($table_name)) {
            return true; // Table doesn't exist or invalid name, consider it successful
        }

        try {
            $result = self::$wpdb->query("DROP TABLE IF EXISTS '" . self::$wpdb->esc_like($table_name) . "'");

            if ($result === false) {
                self::log_error("DatabaseHelper::drop_table failed for table '{$table_name}'");
                return false;
            }

            // Clear cache after table deletion
            $cache_key = 'odcm_table_exists_' . md5($table_name);
            wp_cache_delete($cache_key, 'odcm_database');

            return true;
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::drop_table failed for table '{$table_name}': " . $e->getMessage());
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
            self::log_error("DatabaseHelper::get_option failed for option '{$option_name}': " . $e->getMessage());
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
            self::log_error("DatabaseHelper::update_option failed for option '{$option_name}': " . $e->getMessage());
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
            self::log_error("DatabaseHelper::delete_option failed for option '{$option_name}': " . $e->getMessage());
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
        if (empty($pattern) || !self::validate_option_name($pattern)) {
            return 0;
        }

        try {
            $deleted_count = 0;
            $options = self::$wpdb->get_results(
                "SELECT option_name FROM " . self::$wpdb->options . " WHERE option_name LIKE '%" . self::$wpdb->esc_like($pattern) . "%'"
            );

            foreach ($options as $option) {
                if (self::delete_option($option->option_name)) {
                    $deleted_count++;
                }
            }

            return $deleted_count;
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::delete_options_by_pattern failed for pattern '{$pattern}': " . $e->getMessage());
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
        if (empty($pattern)) {
            return 0;
        }

        try {
            $deleted_count = 0;

            // Delete regular transients
            $deleted_count += self::delete_options_by_pattern('_transient_' . $pattern);

            // Delete transient timeouts
            $deleted_count += self::delete_options_by_pattern('_transient_timeout_' . $pattern);

            // Delete site transients
            $deleted_count += self::delete_options_by_pattern('_site_transient_' . $pattern);

            // Delete site transient timeouts
            $deleted_count += self::delete_options_by_pattern('_site_transient_timeout_' . $pattern);

            // Clear WordPress object cache
            wp_cache_flush();

            return $deleted_count;
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::delete_transients_by_pattern failed for pattern '{$pattern}': " . $e->getMessage());
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
        try {
            return self::$wpdb->query(self::$wpdb->prepare($query, $args));
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::query failed: " . $e->getMessage());
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
        try {
            return self::$wpdb->get_var(self::$wpdb->prepare($query, $args));
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::get_var failed: " . $e->getMessage());
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
        try {
            if (empty($args)) {
                return self::$wpdb->get_row($query, $output);
            }

            return self::$wpdb->get_row(self::$wpdb->prepare($query, $args), $output);
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::get_row failed: " . $e->getMessage());
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
    public static function get_results(string $query, array $args = [], string $output = 'OBJECT')
    {
        try {
            if (empty($args)) {
                return self::$wpdb->get_results($query, $output);
            }

            return self::$wpdb->get_results(self::$wpdb->prepare($query, $args), $output);
        } catch (\Throwable $e) {
            self::log_error("DatabaseHelper::get_results failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Log an error message
     *
     * @param string $message Error message to log
     * @return void
     */
    private static function log_error(string $message): void
    {
        // Use WordPress error logging if available
        if (function_exists('error_log')) {
            error_log('[ODCM DatabaseHelper ERROR] ' . $message);
        }

        // Also store in a transient for potential debugging
        $log = get_transient('odcm_database_log');
        if (!is_array($log)) {
            $log = [];
        }

        $log[] = '[ERROR] ' . current_time('mysql') . ': ' . $message;
        set_transient('odcm_database_log', $log, HOUR_IN_SECONDS);
    }
}