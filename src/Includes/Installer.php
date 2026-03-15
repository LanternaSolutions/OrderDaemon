<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;

/**
 * Handles plugin installation and updates, including database table creation.
 */
class Installer
{
    /**
     * The current database version.
     */
    const DB_VERSION = '1.3';

    /**
     * The option key for storing the database version.
     */
    const DB_VERSION_OPTION_KEY = 'odcm_db_version';

    /**
     * Database helper instance
     *
     * @var DatabaseHelper
     */
    private static DatabaseHelper $db_helper;

    /**
     * Cache of table existence checks to prevent redundant queries
     *
     * @var array<string, bool>
     */
    private static array $table_existence_cache = [];

    /**
     * Initialize the database helper if not already initialized
     *
     * @return void
     * @throws \Exception If database helper initialization fails
     */
    public static function initialize_db_helper(): void
    {
        if (!isset(self::$db_helper)) {
            try {
                self::$db_helper = DatabaseHelper::get_instance();
            } catch (\Exception $e) {
                throw new \Exception('Failed to initialize database helper:' . esc_html($e->getMessage()));
            }
        }
    }

    /**
     * Activation hook callback.
     * This is called when the plugin is activated.
     */
    public static function activate(): void
    {
        // Initialize database helper
        self::initialize_db_helper();

        self::install();
    }

    /**
     * Main installation function.
     * Sets up the complete database structure for Order Daemon.
     */
    public static function install(): void
    {
        self::initialize_db_helper();
        self::setup_database();
    }

    /**
     * Sets up the complete database structure.
     * Creates both audit log and payloads tables with all columns and indexes.
     */
    private static function setup_database(): void
    {
        self::initialize_db_helper();

        // Check if update is safe to perform
        if (!self::is_update_safe()) {
            throw new \Exception('Database update is not safe to perform');
        }

        try {
            // Create both tables with their complete structure
            self::create_complete_audit_log_table();
            self::create_complete_audit_payloads_table();
            self::create_audit_log_queue_table();

            // Apply timeline redesign schema updates
            self::apply_timeline_redesign_schema_updates();

            // Update the database version
            self::update_db_version();

        } catch (\Exception $e) {
            // Handle installation failure with rollback
            self::handle_installation_failure($e);
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
            parent_id INT UNSIGNED NULL DEFAULT NULL,
            display_data TEXT NULL DEFAULT NULL,
            dedupe_key VARCHAR(255) NULL DEFAULT NULL,
            processed_display_data TEXT NULL DEFAULT NULL COMMENT 'Cached display sections in JSON format',
            last_processed TIMESTAMP NULL DEFAULT NULL,
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
            KEY idx_event_type_status (event_type, status),
            KEY idx_parent (parent_id),
            KEY idx_process_parent (process_id, parent_id),
            UNIQUE KEY unique_duplicate_hash (duplicate_hash),
            UNIQUE KEY idx_idempotency_unique (idempotency_key)
        ) $charset_collate;";

        require_once wp_normalize_path(ABSPATH . 'wp-admin/includes/upgrade.php');
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
            processed_display_data TEXT NULL DEFAULT NULL COMMENT 'Cached display sections in JSON format',
            last_processed TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (payload_id),
            FULLTEXT KEY idx_payload_search (payload)
        ) $charset_collate;";

        require_once wp_normalize_path(ABSPATH . 'wp-admin/includes/upgrade.php');
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

        require_once wp_normalize_path(ABSPATH . 'wp-admin/includes/upgrade.php');
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
        $parent_id_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'parent_id'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$parent_id_exists) {
            // Add parent_id column
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER log_id";
            self::$db_helper->query($sql);

            // Add index for parent_id
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD INDEX idx_parent (parent_id)";
            self::$db_helper->query($sql);

            // Add composite index for process_id and parent_id
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD INDEX idx_process_parent (process_id, parent_id)";
            self::$db_helper->query($sql);
        }

        // Check if display_data column already exists
        $display_data_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'display_data'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$display_data_exists) {
            // Add display_data column
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN display_data TEXT NULL DEFAULT NULL AFTER details";
            self::$db_helper->query($sql);
        }

        // Add dedupe_key column for deterministic deduplication
        $dedupe_key_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'dedupe_key'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$dedupe_key_exists) {
            // Add dedupe_key column
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN dedupe_key VARCHAR(255) NULL DEFAULT NULL AFTER details";
            self::$db_helper->query($sql);

            // Add unique index for dedupe_key
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD UNIQUE INDEX idx_dedupe_key (dedupe_key)";
            self::$db_helper->query($sql);
        }

        // Add idx_event_type_status index if it doesn't exist
        $event_type_status_index_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_event_type_status'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$event_type_status_index_exists) {
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD INDEX idx_event_type_status (event_type, status)";
            self::$db_helper->query($sql);
        }

        // Update payload table with display data caching columns
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Check if processed_display_data column already exists
        $processed_display_data_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'processed_display_data'",
            [DB_NAME, $payload_table]
        ) > 0;

        if (!$processed_display_data_exists) {
            // Add processed_display_data column
            $safe_table = esc_sql($payload_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN processed_display_data TEXT NULL DEFAULT NULL COMMENT 'Cached display sections in JSON format'";
            self::$db_helper->query($sql);
        }

        // Check if last_processed column already exists
        $last_processed_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'last_processed'",
            [DB_NAME, $payload_table]
        ) > 0;

        if (!$last_processed_exists) {
            // Add last_processed column
            $safe_table = esc_sql($payload_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN last_processed TIMESTAMP NULL DEFAULT NULL";
            self::$db_helper->query($sql);
        }
    }

    /**
     * Get the upgrade path for the current version
     *
     * @param string $current_version The current database version
     * @return array Array of upgrade steps to perform
     */
    private static function get_upgrade_path(string $current_version): array
    {
        $upgrade_steps = [];

        // Define upgrade paths for each version
        switch (true) {
            case version_compare($current_version, '1.1.0', '<'):
                $upgrade_steps[] = 'upgrade_to_1_1';
                // fall through
            case version_compare($current_version, '1.2.0', '<'):
                $upgrade_steps[] = 'upgrade_to_1_2';
                // fall through
            case version_compare($current_version, '1.3.0', '<'):
                $upgrade_steps[] = 'upgrade_to_1_3';
                break;
        }

        return $upgrade_steps;
    }

    /**
     * Perform version-specific upgrades
     *
     * @param string $current_version The current database version
     * @return void
     * @throws \Exception If any upgrade step fails
     */
    private static function perform_upgrades(string $current_version): void
    {
        $upgrade_steps = self::get_upgrade_path($current_version);

        foreach ($upgrade_steps as $step) {
            switch ($step) {
                case 'upgrade_to_1_1':
                    self::upgrade_to_1_1();
                    break;
                case 'upgrade_to_1_2':
                    self::upgrade_to_1_2();
                    break;
                case 'upgrade_to_1_3':
                    self::upgrade_to_1_3();
                    break;
            }
        }
    }

    /**
     * Upgrade to version 1.1
     * Adds parent_id and display_data columns
     */
    private static function upgrade_to_1_1(): void
    {
        global $wpdb;
        $audit_log_table = $wpdb->prefix . 'odcm_audit_log';

        // Add parent_id column if it doesn't exist
        $parent_id_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'parent_id'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$parent_id_exists) {
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER log_id";
            self::$db_helper->query($sql);

            // Add index for parent_id
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD INDEX idx_parent (parent_id)";
            self::$db_helper->query($sql);

            // Add composite index for process_id and parent_id
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD INDEX idx_process_parent (process_id, parent_id)";
            self::$db_helper->query($sql);
        }

        // Add display_data column if it doesn't exist
        $display_data_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'display_data'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$display_data_exists) {
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN display_data TEXT NULL DEFAULT NULL AFTER details";
            self::$db_helper->query($sql);
        }
    }

    /**
     * Upgrade to version 1.2
     * Adds dedupe_key and enhanced indexing
     */
    private static function upgrade_to_1_2(): void
    {
        global $wpdb;
        $audit_log_table = $wpdb->prefix . 'odcm_audit_log';

        // Add dedupe_key column if it doesn't exist
        $dedupe_key_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'dedupe_key'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$dedupe_key_exists) {
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN dedupe_key VARCHAR(255) NULL DEFAULT NULL AFTER details";
            self::$db_helper->query($sql);

            // Add unique index for dedupe_key
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD UNIQUE INDEX idx_dedupe_key (dedupe_key)";
            self::$db_helper->query($sql);
        }

        // Add idx_event_type_status index if it doesn't exist
        $event_type_status_index_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_event_type_status'",
            [DB_NAME, $audit_log_table]
        ) > 0;

        if (!$event_type_status_index_exists) {
            $safe_table = esc_sql($audit_log_table);
            $sql = "ALTER TABLE $safe_table ADD INDEX idx_event_type_status (event_type, status)";
            self::$db_helper->query($sql);
        }
    }

    /**
     * Upgrade to version 1.3
     * Adds payload table enhancements
     */
    private static function upgrade_to_1_3(): void
    {
        global $wpdb;
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Add processed_display_data column if it doesn't exist
        $processed_display_data_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'processed_display_data'",
            [DB_NAME, $payload_table]
        ) > 0;

        if (!$processed_display_data_exists) {
            $safe_table = esc_sql($payload_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN processed_display_data TEXT NULL DEFAULT NULL COMMENT 'Cached display sections in JSON format'";
            self::$db_helper->query($sql);
        }

        // Add last_processed column if it doesn't exist
        $last_processed_exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'last_processed'",
            [DB_NAME, $payload_table]
        ) > 0;

        if (!$last_processed_exists) {
            $safe_table = esc_sql($payload_table);
            $sql = "ALTER TABLE $safe_table ADD COLUMN last_processed TIMESTAMP NULL DEFAULT NULL";
            self::$db_helper->query($sql);
        }
    }

    /**
     * Updates the stored database version to the current version.
     */
    private static function update_db_version(): void
    {
        // Get current database version
        $current_version = self::$db_helper->get_option(self::DB_VERSION_OPTION_KEY, '0.0');

        // Only update if we're actually changing versions
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            // Perform pre-update backup (store current version for rollback)
            self::backup_current_state($current_version);

            // Perform version-specific upgrades
            self::perform_upgrades($current_version);

            // Update the database version
            $update_result = update_option(self::DB_VERSION_OPTION_KEY, self::DB_VERSION);

            if ($update_result) {
                // Clear caches after successful update
                self::$table_existence_cache = [];
                wp_cache_delete('odcm_all_tables_exist_check');
                wp_cache_delete('odcm_db_version_backup');

                // Log successful update
                odcm_log_message('Database updated from version ' . $current_version . ' to ' . self::DB_VERSION, 'info');
            } else {
                // Handle update failure
                odcm_log_message('Failed to update database version from ' . $current_version . ' to ' . self::DB_VERSION, 'error');
                throw new \Exception('Database version update failed');
            }
        }
        // If versions are the same, just ensure caches are cleared
        else {
            self::$table_existence_cache = [];
            wp_cache_delete('odcm_all_tables_exist_check');
        }
    }

    /**
     * Backup current database state before updates
     *
     * @param string $current_version The current database version
     * @return void
     */
    private static function backup_current_state(string $current_version): void
    {
        // Store backup of current version for potential rollback
        update_option('odcm_db_version_backup', $current_version);

        // Store backup timestamp
        update_option('odcm_update_backup_timestamp', current_time('mysql'));

        // Store current table structures for rollback
        self::backup_table_structures();

        // Log backup creation
        odcm_log_message('Created database backup before update from version ' . $current_version, 'info');
    }

    /**
     * Backup current table structures for rollback
     *
     * @return void
     */
    private static function backup_table_structures(): void
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'odcm_audit_log',
            $wpdb->prefix . 'odcm_audit_log_payloads',
            $wpdb->prefix . 'odcm_audit_log_queue'
        ];

        foreach ($tables as $table) {
            if (self::$db_helper->table_exists($table)) {
                // Get table structure
                $structure = self::$db_helper->get_row(
                    "SHOW CREATE TABLE {$table}",
                    [],
                    'ARRAY_A'
                );

                if ($structure && isset($structure['Create Table'])) {
                    // Store table structure
                    update_option('odcm_table_backup_' . md5($table), $structure['Create Table'], 'no');

                    // Log table backup
                    odcm_log_message("Backed up table structure for {$table}", 'info');
                }
            }
        }
    }

    /**
     * Rollback database to previous state
     *
     * @return bool True if rollback succeeded, false otherwise
     */
    private static function rollback_database(): bool
    {
        // Get backup information
        $backup_version = self::$db_helper->get_option('odcm_db_version_backup', '');
        $backup_timestamp = self::$db_helper->get_option('odcm_update_backup_timestamp', '');

        if (empty($backup_version) || empty($backup_timestamp)) {
            odcm_log_message('No valid database backup found for rollback', 'error');
            return false;
        }

        try {
            // Restore table structures
            self::restore_table_structures();

            // Restore database version
            $result = update_option(self::DB_VERSION_OPTION_KEY, $backup_version);

            if ($result) {
                // Clear caches
                self::$table_existence_cache = [];
                wp_cache_delete('odcm_all_tables_exist_check');

                // Log successful rollback
                odcm_log_message('Successfully rolled back database to version ' . $backup_version, 'info');

                return true;
            } else {
                odcm_log_message('Failed to restore database version during rollback', 'error');
                return false;
            }
        } catch (\Exception $e) {
            odcm_log_message('Database rollback failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Restore table structures from backup
     *
     * @return void
     */
    private static function restore_table_structures(): void
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'odcm_audit_log',
            $wpdb->prefix . 'odcm_audit_log_payloads',
            $wpdb->prefix . 'odcm_audit_log_queue'
        ];

        foreach ($tables as $table) {
            $backup_key = 'odcm_table_backup_' . md5($table);
            $table_structure = self::$db_helper->get_option($backup_key, '');

            if (!empty($table_structure)) {
                // Drop existing table
                self::$db_helper->drop_table($table);

                // Recreate table from backup
                self::$db_helper->query($table_structure);

                // Clear table cache
                $cache_key = 'odcm_table_exists_' . md5($table);
                wp_cache_delete($cache_key, 'odcm_database');

                // Log table restoration
                odcm_log_message("Restored table structure for {$table} from backup", 'info');
            }
        }
    }

    /**
     * Handle installation failure with rollback
     *
     * @param \Exception $e The exception that caused the failure
     * @return void
     */
    private static function handle_installation_failure(\Exception $e): void
    {
        // Log the failure
        odcm_log_message('Installation failed: ' . $e->getMessage(), 'error');

        // Attempt rollback
        $rollback_success = self::rollback_database();

        if ($rollback_success) {
            odcm_log_message('Database rollback completed successfully', 'info');
        } else {
            odcm_log_message('Database rollback failed', 'error');
        }

        // Re-throw the exception to propagate the error
        throw $e;
    }

    /**
     * Check if database update is safe to perform
     *
     * @return bool True if update is safe, false otherwise
     */
    private static function is_update_safe(): bool
    {
        // Check if we're in maintenance mode
        if (defined('WP_MAINTENANCE_MODE') && WP_MAINTENANCE_MODE) {
            return false;
        }

        // Check if database is available
        if (!self::$db_helper->is_connected()) {
            odcm_log_message('Database safety check failed: connection not available', 'error');
            return false;
        }

        // Check if we have sufficient memory
        $memory_limit = ini_get('memory_limit');
        if (!empty($memory_limit) && strpos($memory_limit, 'M') !== false) {
            $memory_limit_bytes = (int)$memory_limit * 1024 * 1024;
            $memory_usage = memory_get_usage(true);

            // Require at least 32MB free memory for updates
            if ($memory_usage > ($memory_limit_bytes - 32 * 1024 * 1024)) {
                odcm_log_message('Insufficient memory for database update', 'error');
                return false;
            }
        }

        return true;
    }

    /**
     * Get current database version with fallback
     *
     * @return string Current database version
     */
    public static function get_current_db_version(): string
    {
        $version = DatabaseHelper::get_instance()->get_option(self::DB_VERSION_OPTION_KEY, '0.0');


        // Validate version format
        if (!preg_match('/^\d+\.\d+$/', $version)) {
            $version = '0.0';
            update_option(self::DB_VERSION_OPTION_KEY, $version);
        }

        return $version;
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
        self::initialize_db_helper();
        global $wpdb;

        // Check static cache first (fastest)
        if (isset(self::$table_existence_cache[$table_name])) {
            return self::$table_existence_cache[$table_name];
        }

        // Create a cache key for WordPress persistent cache
        $cache_key = 'odcm_table_exists_' . md5($table_name);

        // Check persistent cache
        $table_exists = wp_cache_get($cache_key, 'odcm_database');
        if (false !== $table_exists) {
            // Store in static cache for future use
            self::$table_existence_cache[$table_name] = (bool)$table_exists;
            return (bool)$table_exists;
        }

        // Initialize DatabaseHelper if not already initialized
        if (!isset(self::$db_helper)) {
            self::$db_helper = new DatabaseHelper();
            
        }

        // Cache miss - perform the table existence check
        $table_exists = self::$db_helper->table_exists($table_name);

        // Cache the result - short duration since this is for installation
        wp_cache_set($cache_key, (int)$table_exists, '', 5 * MINUTE_IN_SECONDS);

        // Store in static cache for future use
        self::$table_existence_cache[$table_name] = $table_exists;

        return $table_exists;
    }

    /**
     * Check and fix index configuration
     *
     * This method checks if an index exists and verifies its column configuration.
     * If the index exists but has different column configuration, it will be dropped and recreated.
     * If the index doesn't exist, it will be created.
     *
     * @param string $table The table name to check
     * @param string $index_name The index name to check
     * @param string $columns The expected column configuration (comma-separated)
     * @return void
     * @throws \Exception If index operation fails
     */
    private static function check_and_fix_index(string $table, string $index_name, string $columns): void
    {
        // Check if index exists
        $exists = self::$db_helper->get_var(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            [DB_NAME, $table, $index_name]
        ) > 0;

        if ($exists) {
            // Verify column order
            $current_columns = self::$db_helper->get_var(
                "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                [DB_NAME, $table, $index_name]
            );

            if ($current_columns !== $columns) {
                // Drop and recreate with correct configuration
                self::$db_helper->query("ALTER TABLE $table DROP INDEX $index_name");
                self::$db_helper->query("ALTER TABLE $table ADD INDEX $index_name ($columns)");
            }
        } else {
            // Create new index
            self::$db_helper->query("ALTER TABLE $table ADD INDEX $index_name ($columns)");
        }
    }
}