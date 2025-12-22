<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

/**
 * Handles plugin installation and updates, including database table creation.
 */
class Installer
{
    /**
     * The current database version.
     */
    const DB_VERSION = '1.2';

    /**
     * The option key for storing the database version.
     */
    const DB_VERSION_OPTION_KEY = 'odcm_db_version';
    
    /**
     * Cache of table existence checks to prevent redundant queries
     *
     * @var array<string, bool>
     */
    private static array $table_existence_cache = [];

    /**
     * Activation hook callback.
     * This is called when the plugin is activated.
     */
    public static function activate(): void
    {
        self::install();
    }

    /**
     * Main installation function.
     * Sets up the complete database structure for Order Daemon.
     */
    public static function install(): void
    {
        self::setup_database();
    }

    /**
     * Sets up the complete database structure.
     * Creates both audit log and payloads tables with all columns and indexes.
     */
    private static function setup_database(): void
    {
        try {
            // Create both tables with their complete structure
            self::create_complete_audit_log_table();
            self::create_complete_audit_payloads_table();
            self::create_audit_log_queue_table();

            // Apply timeline redesign schema updates
            self::apply_timeline_redesign_schema_updates();

            // Update the database version
            self::update_db_version();

        } catch (Exception $e) {
            // Log installation error (debug-gated)
            odcm_log_message('Database Setup Error: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Creates the complete audit log table with all columns and indexes.
     * This includes all features that were added incrementally in previous versions.
     * Used for fresh installations to avoid complex migration logic.
     */
    private static function create_complete_audit_log_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'odcm_audit_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            status varchar(20) NOT NULL,
            summary text NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            source varchar(50) DEFAULT 'system',
            details longtext,
            payload_id bigint(20) unsigned DEFAULT NULL,
            log_category varchar(20) NOT NULL DEFAULT 'custom',
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            duplicate_hash varchar(32) DEFAULT NULL,
            process_id varchar(64) DEFAULT NULL,
            gateway_name varchar(50) DEFAULT NULL,
            transaction_id varchar(100) DEFAULT NULL,
            primary_object_type varchar(50) DEFAULT NULL,
            primary_object_id bigint(20) unsigned DEFAULT NULL,
            secondary_object_type varchar(50) DEFAULT NULL,
            secondary_object_id bigint(20) unsigned DEFAULT NULL,
            idempotency_key varchar(255) DEFAULT NULL,
            PRIMARY KEY (log_id),
            KEY order_id (order_id),
            KEY event_type (event_type),
            KEY timestamp (timestamp),
            KEY payload_id (payload_id),
            KEY log_category (log_category),
            KEY is_test (is_test),
            KEY duplicate_hash (duplicate_hash),
            KEY process_id (process_id),
            KEY idx_timestamp_status (timestamp, status),
            KEY idx_timestamp_event_type (timestamp, event_type),
            KEY idx_order_timestamp (order_id, timestamp),
            KEY idx_status_event_type (status, event_type),
            KEY idx_summary_search (summary(100)),
            KEY idx_source_timestamp (source, timestamp),
            KEY idx_filter_source (source),
            KEY idx_filter_event_type (event_type),
            KEY idx_filter_status (status),
            KEY idx_filter_combo_primary (source, event_type, status),
            KEY idx_filter_combo_source_status (source, status),
            KEY idx_filter_combo_type_status (event_type, status),
            KEY idx_filter_date_source (timestamp, source),
            KEY idx_filter_date_type (timestamp, event_type),
            KEY idx_order_process (order_id, process_id),
            KEY idx_process_timestamp (process_id, timestamp),
            KEY idx_event_type_order (event_type, order_id, timestamp),
            KEY idx_gateway_transaction (gateway_name, transaction_id),
            KEY idx_primary_entity (primary_object_type, primary_object_id),
            KEY idx_secondary_entity (secondary_object_type, secondary_object_id),
            KEY idx_idempotency (idempotency_key),
            KEY idx_cross_entity_process (primary_object_id, secondary_object_id, process_id),
            UNIQUE KEY unique_duplicate_hash (duplicate_hash),
            UNIQUE KEY idx_idempotency_unique (idempotency_key)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Verify table creation with caching
        $table_exists = self::verify_table_exists($table_name);
        if (!$table_exists) {
            throw new \Exception("Failed to create complete audit log table: " . esc_html($table_name));
        }
    }

    /**
     * Creates the complete audit payloads table with all features.
     * Used for fresh installations to avoid complex migration logic.
     */
    private static function create_complete_audit_payloads_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'odcm_audit_log_payloads';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            payload_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payload longtext,
            format varchar(10) NOT NULL DEFAULT 'json',
            PRIMARY KEY (payload_id),
            FULLTEXT KEY idx_payload_search (payload)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Verify table creation with caching
        $table_exists = self::verify_table_exists($table_name);
        if (!$table_exists) {
            throw new \Exception("Failed to create complete audit payloads table: " . esc_html($table_name));
        }
    }

    /**
     * Creates the audit log queue table for two-phase logging.
     * Stores full event data temporarily before async processing.
     */
    private static function create_audit_log_queue_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'odcm_audit_log_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            queue_id VARCHAR(50) NOT NULL,
            event_data LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            retry_count INT DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            PRIMARY KEY (queue_id),
            KEY status_created (status, created_at),
            KEY processed_at (processed_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Verify table creation with caching
        $table_exists = self::verify_table_exists($table_name);
        if (!$table_exists) {
            throw new \Exception("Failed to create audit log queue table: " . esc_html($table_name));
        }
    }

    /**
     * Apply timeline redesign schema updates
     * Adds parent_id, display_data columns and related indexes
     */
    private static function apply_timeline_redesign_schema_updates(): void
    {
        global $wpdb;

        // Add parent_id and display_data columns to audit log table
        $audit_log_table = $wpdb->prefix . 'odcm_audit_log';

        // Check if parent_id column already exists
        $parent_id_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'parent_id'",
            DB_NAME,
            $audit_log_table
        )) > 0;

        if (!$parent_id_exists) {
            // Add parent_id column
            $sql = "ALTER TABLE $audit_log_table
                    ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER log_id";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);

            // Add index for parent_id
            $sql = "ALTER TABLE $audit_log_table ADD INDEX idx_parent (parent_id)";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);

            // Add composite index for process_id and parent_id
            $sql = "ALTER TABLE $audit_log_table ADD INDEX idx_process_parent (process_id, parent_id)";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);
        }

        // Check if display_data column already exists
        $display_data_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'display_data'",
            DB_NAME,
            $audit_log_table
        )) > 0;

        if (!$display_data_exists) {
            // Add display_data column
            $sql = "ALTER TABLE $audit_log_table
                    ADD COLUMN display_data TEXT NULL DEFAULT NULL AFTER details";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);
        }

        // Add dedupe_key column for deterministic deduplication
        $dedupe_key_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'dedupe_key'",
            DB_NAME,
            $audit_log_table
        )) > 0;

        if (!$dedupe_key_exists) {
            // Add dedupe_key column
            $sql = "ALTER TABLE $audit_log_table
                    ADD COLUMN dedupe_key VARCHAR(255) NULL DEFAULT NULL AFTER details";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);

            // Add unique index for dedupe_key
            $sql = "ALTER TABLE $audit_log_table ADD UNIQUE INDEX idx_dedupe_key (dedupe_key)";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);
        }

        // Update payload table with display data caching columns
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Check if processed_display_data column already exists
        $processed_display_data_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'processed_display_data'",
            DB_NAME,
            $payload_table
        )) > 0;

        if (!$processed_display_data_exists) {
            // Add processed_display_data column
            $sql = "ALTER TABLE $payload_table
                    ADD COLUMN processed_display_data TEXT NULL DEFAULT NULL COMMENT 'Cached display sections in JSON format'";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);
        }

        // Check if last_processed column already exists
        $last_processed_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'last_processed'",
            DB_NAME,
            $payload_table
        )) > 0;

        if (!$last_processed_exists) {
            // Add last_processed column
            $sql = "ALTER TABLE $payload_table
                    ADD COLUMN last_processed TIMESTAMP NULL DEFAULT NULL";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Direct query required for schema modification
            $wpdb->query($sql);
        }
    }

    /**
     * Updates the stored database version to the current version.
     */
    private static function update_db_version(): void
    {
        update_option(self::DB_VERSION_OPTION_KEY, self::DB_VERSION);

        // Clear table existence cache after updating database version
        self::$table_existence_cache = [];
        wp_cache_delete('odcm_all_tables_exist_check');
    }
    
    /**
     * Verify if a table exists with caching to prevent redundant queries
     * 
     * This method implements multi-level caching:
     * 1. Static class cache for the current request
     * 2. WordPress persistent cache for short-term caching during installation
     * 
     * @param string $table_name The full table name to check
     * @return bool True if the table exists, false otherwise
     */
    private static function verify_table_exists(string $table_name): bool
    {
        global $wpdb;
        
        // Check static cache first (fastest)
        if (isset(self::$table_existence_cache[$table_name])) {
            return self::$table_existence_cache[$table_name];
        }
        
        // Create a cache key for WordPress persistent cache
        $cache_key = 'odcm_table_exists_' . md5($table_name);
        
        // Check persistent cache
        $table_exists = wp_cache_get($cache_key);
        if (false !== $table_exists) {
            // Store in static cache for future use
            self::$table_existence_cache[$table_name] = (bool)$table_exists;
            return (bool)$table_exists;
        }
        
        // Cache miss - perform the table existence check
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        $table_exists = ($exists === $table_name);
        
        // Cache the result - short duration since this is for installation
        wp_cache_set($cache_key, (int)$table_exists, '', 5 * MINUTE_IN_SECONDS);
        
        // Store in static cache for future use
        self::$table_existence_cache[$table_name] = $table_exists;
        
        return $table_exists;
    }
}
