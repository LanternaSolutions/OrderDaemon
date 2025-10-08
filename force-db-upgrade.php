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

echo "=== Order Daemon Database Upgrade Script ===\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n\n";

// Check current database version
$current_version = get_option(Installer::DB_VERSION_OPTION_KEY);
echo "Current database version: " . ($current_version ?: 'Not set') . "\n";
echo "Target database version: " . Installer::DB_VERSION . "\n\n";

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

echo "Source column exists: " . ($source_column_exists ? 'YES' : 'NO') . "\n\n";

if (!$source_column_exists) {
    echo "Adding source column...\n";
    
    // Add the source column
    $result = $wpdb->query("ALTER TABLE {$log_table} ADD COLUMN source varchar(50) DEFAULT 'system' AFTER event_type");
    
    if ($result !== false) {
        echo "✅ Source column added successfully\n";
        
        // Add the index
        $index_result = $wpdb->query("ALTER TABLE {$log_table} ADD KEY idx_source_timestamp (source, timestamp)");
        if ($index_result !== false) {
            echo "✅ Source index added successfully\n";
        } else {
            echo "⚠️  Source index may already exist or failed to add\n";
        }
    } else {
        echo "❌ Failed to add source column\n";
        echo "Error: " . $wpdb->last_error . "\n";
        exit(1);
    }
} else {
    echo "✅ Source column already exists\n";
}

// Force the installer to run
echo "\nRunning full database upgrade...\n";
Installer::install();

// Verify the upgrade
$new_version = get_option(Installer::DB_VERSION_OPTION_KEY);
echo "New database version: " . $new_version . "\n";

// Check source column again
$source_column_exists_after = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'source'",
        DB_NAME,
        $log_table
    )
);

echo "Source column exists after upgrade: " . ($source_column_exists_after ? 'YES' : 'NO') . "\n";

if ($source_column_exists_after) {
    echo "\n✅ Database upgrade completed successfully!\n";
    echo "The audit log page should now work properly.\n";
} else {
    echo "\n❌ Database upgrade failed!\n";
    exit(1);
}

echo "\n=== Upgrade Complete ===\n";
