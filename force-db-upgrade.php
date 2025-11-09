<?php
/**
 * Force database upgrade script for Order Daemon
 * This script forces the database upgrade to add the missing 'source' column
 */

// Load WordPress
require_once '/var/www/html/wp-config.php';

// Load the plugin's Installer class
require_once __DIR__ . '/src/Includes/Installer.php';

use OrderDaemon\CompletionManager\Includes\Installer;

echo wp_kses("=== Order Daemon Database Upgrade Script ===\n", 'post');
echo wp_kses("Current time: " . gmdate('Y-m-d H:i:s') . "\n\n", 'post');

// Check current database version
$current_version = get_option(Installer::DB_VERSION_OPTION_KEY);
echo wp_kses("Current database version: " . ($current_version ?: 'Not set') . "\n", 'post');
echo wp_kses("Target database version: " . Installer::DB_VERSION . "\n\n", 'post');

// Check if source column exists
global $wpdb;
$log_table = $wpdb->prefix . 'odcm_audit_log';

$source_column_exists = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'source'",
        DB_NAME,
        $log_table
    )
);

echo wp_kses("Source column exists: " . ($source_column_exists ? 'YES' : 'NO') . "\n\n", 'post');

if (!$source_column_exists) {
    echo wp_kses("Adding source column...\n", 'post');
    
    // Add the source column
    $result = $wpdb->query("ALTER TABLE {$log_table} ADD COLUMN source varchar(50) DEFAULT 'system' AFTER event_type");
    
    if ($result !== false) {
        echo wp_kses("✅ Source column added successfully\n", 'post');
        
        // Add the index
        $index_result = $wpdb->query("ALTER TABLE {$log_table} ADD KEY idx_source_timestamp (source, timestamp)");
        if ($index_result !== false) {
            echo wp_kses("✅ Source index added successfully\n", 'post');
        } else {
            echo wp_kses("⚠️  Source index may already exist or failed to add\n", 'post');
        }
    } else {
        echo wp_kses("❌ Failed to add source column\n", 'post');
        echo wp_kses("Error: " . $wpdb->last_error . "\n", 'post');
        exit(1);
    }
} else {
    echo wp_kses("✅ Source column already exists\n", 'post');
}

// Force the installer to run
echo wp_kses("\nRunning full database upgrade...\n", 'post');
Installer::install();

// Verify the upgrade
$new_version = get_option(Installer::DB_VERSION_OPTION_KEY);
echo wp_kses("New database version: " . $new_version . "\n", 'post');

// Check source column again
$source_column_exists_after = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'source'",
        DB_NAME,
        $log_table
    )
);

echo wp_kses("Source column exists after upgrade: " . ($source_column_exists_after ? 'YES' : 'NO') . "\n", 'post');

if ($source_column_exists_after) {
    echo wp_kses("\n✅ Database upgrade completed successfully!\n", 'post');
    echo wp_kses("The audit log page should now work properly.\n", 'post');
} else {
    echo wp_kses("\n❌ Database upgrade failed!\n", 'post');
    exit(1);
}

echo wp_kses("\n=== Upgrade Complete ===\n", 'post');
