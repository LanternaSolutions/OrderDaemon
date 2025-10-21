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
    const DB_VERSION = '1.1';

    /**
     * The option key for storing the database version.
     */
    const DB_VERSION_OPTION_KEY = 'odcm_db_version';

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
            
            // Update the database version
            self::update_db_version();

        } catch (Exception $e) {
            // Log installation error
            error_log('Order Daemon Database Setup Error: ' . $e->getMessage());
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
        
        // Verify table creation
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            throw new Exception("Failed to create complete audit log table: $table_name");
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
        
        // Verify table creation
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            throw new Exception("Failed to create complete audit payloads table: $table_name");
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
        
        // Verify table creation
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            throw new \Exception("Failed to create audit log queue table: $table_name");
        }
    }

    /**
     * Updates the stored database version to the current version.
     */
    private static function update_db_version(): void
    {
        update_option(self::DB_VERSION_OPTION_KEY, self::DB_VERSION);
    }
}
