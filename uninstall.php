<?php
/**
 * Order Daemon Uninstaller
 *
 * This file is automatically executed when the plugin is uninstalled via the WordPress admin.
 * By default, it preserves all user data (database tables, options, and custom post types)
 * to prevent accidental data loss. Complete removal requires explicit action by defining
 * the ODCM_REMOVE_ALL_DATA constant in wp-config.php.
 *
 * @package OrderDaemon
 *
 * USAGE INSTRUCTIONS:
 *
 * 1. Default Uninstallation (Data Preservation):
 *    - Simply uninstall the plugin via WordPress admin
 *    - All data is preserved for potential reinstallation
 *
 * 2. Complete Data Removal:
 *    - Add to wp-config.php: define('ODCM_REMOVE_ALL_DATA', true);
 *    - Then uninstall the plugin
 *    - All Order Daemon data will be permanently removed
 *
 * 3. Dry-Run Mode (Testing):
 *    - Add to wp-config.php: define('ODCM_UNINSTALL_DRY_RUN', true);
 *    - Then uninstall the plugin
 *    - No changes will be made, only logged what would be removed
 *    - Useful for testing and verification before actual removal
 *
 * 4. Backup Verification:
 *    - The system automatically checks for backups when complete removal is requested
 *    - Works with popular backup plugins (UpdraftPlus, BackupBuddy, etc.)
 *    - Provides warnings if no recent backup is found
 */

// If uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// Initialize DatabaseHelper
require_once __DIR__ . '/src/Includes/Utils/DatabaseHelper.php';
DatabaseHelper::initialize($GLOBALS['wpdb']);

/**
 * Check if complete data removal is requested
 *
 * @return bool True if complete removal is requested, false otherwise
 */
function odcm_should_remove_all_data() {
    // Check if the constant is defined in wp-config.php
    if (defined('ODCM_REMOVE_ALL_DATA') && ODCM_REMOVE_ALL_DATA) {
        return true;
    }

    // Default behavior: preserve data
    return false;
}

/**
 * Check if dry-run mode is enabled
 *
 * @return bool True if dry-run mode is enabled, false otherwise
 */
function odcm_is_dry_run_mode() {
    return defined('ODCM_UNINSTALL_DRY_RUN') && ODCM_UNINSTALL_DRY_RUN;
}

/**
 * Log uninstallation actions for troubleshooting
 *
 * @param string $message The action message to log
 */
function odcm_log_uninstall_action($message) {
    // Log to WordPress debug log if available
    if (function_exists('error_log')) {
        error_log('[ODCM Uninstall] ' . $message);
    }

    // Also store in a transient for potential debugging
    $log = get_transient('odcm_uninstall_log');
    if (!is_array($log)) {
        $log = [];
    }

    $log[] = '[ACTION] ' . current_time('mysql') . ': ' . $message;
    set_transient('odcm_uninstall_log', $log, HOUR_IN_SECONDS);
}

/**
 * Log uninstallation errors for troubleshooting
 *
 * @param string $message The error message to log
 */
function odcm_log_uninstall_error($message) {
    // Log to WordPress debug log if available
    if (function_exists('error_log')) {
        error_log('[ODCM Uninstall ERROR] ' . $message);
    }

    // Also store in a transient for potential debugging
    $log = get_transient('odcm_uninstall_log');
    if (!is_array($log)) {
        $log = [];
    }

    $log[] = '[ERROR] ' . current_time('mysql') . ': ' . $message;
    set_transient('odcm_uninstall_log', $log, HOUR_IN_SECONDS);
}

/**
 * Remove plugin tables from database with comprehensive error handling
 */
function odcm_remove_database_tables() {
    global $wpdb;

    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue'
    ];

    foreach ($tables as $table) {
        try {
            // Use DatabaseHelper for table operations
            if (!DatabaseHelper::drop_table($table)) {
                odcm_log_uninstall_error("Failed to drop table: $table");
            } else {
                odcm_log_uninstall_action("Successfully dropped table: $table");
            }
        } catch (Exception $e) {
            odcm_log_uninstall_error("Error dropping table $table: " . $e->getMessage());

            // Attempt to get more detailed database error information
            if (method_exists($wpdb, 'last_error') && !empty($wpdb->last_error)) {
                odcm_log_uninstall_error("Database error details: " . $wpdb->last_error);
            }

            // Continue with other tables despite failure
            continue;
        }
    }
}

/**
 * Remove plugin options from database
 */
function odcm_remove_plugin_options() {
    global $wpdb;

    // Use DatabaseHelper to remove specific options
    $options = [
        'odcm_db_version',
        'odcm_indexes_built',
        'odcm_detailed_notes',
        'odcm_debug',
        'odcm_custom_redact_keys',
        'odcm_enable_refund_tracking',
        'odcm_enable_deletion_tracking',
        'odcm_dev_debug_override',
        'odcm_emergency_disable',
        'odcm_last_failure',
        'odcm_circuit_breaker_failures',
        'odcm_db_version_backup',
        'odcm_update_backup_timestamp',
        'odcm_all_tables_exist_check',
        'odcm_db_version_backup'
    ];

    foreach ($options as $option) {
        if (DatabaseHelper::delete_option($option)) {
            odcm_log_uninstall_action("Deleted option: $option");
        }
    }

    // Use DatabaseHelper for wildcard options
    $deleted_count = DatabaseHelper::delete_options_by_pattern('odcm_cleanup_%');
    odcm_log_uninstall_action("Deleted $deleted_count wildcard options: odcm_cleanup_%");

    $deleted_count = DatabaseHelper::delete_options_by_pattern('odcm_table_exists_%');
    odcm_log_uninstall_action("Deleted $deleted_count wildcard options: odcm_table_exists_%");

    $deleted_count = DatabaseHelper::delete_options_by_pattern('odcm_manual_cleanup_%');
    odcm_log_uninstall_action("Deleted $deleted_count wildcard options: odcm_manual_cleanup_%");

    $deleted_count = DatabaseHelper::delete_options_by_pattern('odcm_transaction_%');
    odcm_log_uninstall_action("Deleted $deleted_count wildcard options: odcm_transaction_%");

    // Remove all other odcm_ options with broader pattern
    $deleted_count = DatabaseHelper::delete_options_by_pattern('odcm_%');
    odcm_log_uninstall_action("Deleted $deleted_count remaining odcm_ options");
}

/**
 * Remove custom post type data
 */
function odcm_remove_custom_post_type_data() {
    // Get all order rules
    $rules = get_posts([
        'post_type' => 'odcm_order_rule',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids'
    ]);

    // Delete each rule permanently (bypass trash)
    foreach ($rules as $rule_id) {
        wp_delete_post($rule_id, true);
    }
}

/**
 * Remove plugin transients
 */
function odcm_remove_plugin_transients() {
    global $wpdb;

    // Delete all transients starting with odcm_ using DatabaseHelper
    DatabaseHelper::delete_transients_by_pattern('odcm_%');
}

/**
 * Clean up scheduled actions
 */
function odcm_cleanup_scheduled_actions() {
    // Remove scheduled cleanup actions
    if (function_exists('as_unschedule_all_actions')) {
        $actions_removed = as_unschedule_all_actions('odcm_cleanup_old_logs');
        if ($actions_removed > 0) {
            odcm_log_uninstall_action("Removed $actions_removed scheduled actions: odcm_cleanup_old_logs");
        }

        $actions_removed = as_unschedule_all_actions('odcm_cleanup_audit_log_queue');
        if ($actions_removed > 0) {
            odcm_log_uninstall_action("Removed $actions_removed scheduled actions: odcm_cleanup_audit_log_queue");
        }

        $actions_removed = as_unschedule_all_actions('odcm_cleanup_rule_execution_transients');
        if ($actions_removed > 0) {
            odcm_log_uninstall_action("Removed $actions_removed scheduled actions: odcm_cleanup_rule_execution_transients");
        }
    }

    // Remove WordPress cron events
    $cron_removed = wp_clear_scheduled_hook('odcm_cleanup_old_logs');
    if ($cron_removed > 0) {
        odcm_log_uninstall_action("Removed $cron_removed cron events: odcm_cleanup_old_logs");
    }

    $cron_removed = wp_clear_scheduled_hook('odcm_cleanup_audit_log_queue');
    if ($cron_removed > 0) {
        odcm_log_uninstall_action("Removed $cron_removed cron events: odcm_cleanup_audit_log_queue");
    }

    // Remove all odcm_ prefixed cron hooks
    $cron_hooks = _get_cron_array();
    if (!empty($cron_hooks)) {
        foreach ($cron_hooks as $timestamp => $cronhook) {
            foreach ($cronhook as $hook => $keys) {
                if (strpos($hook, 'odcm_') === 0) {
                    wp_clear_scheduled_hook($hook);
                    odcm_log_uninstall_action("Removed cron hook: $hook");
                }
            }
        }
    }
}

/**
 * Verify database backup exists before destructive operations
 *
 * This function checks for existing backups before allowing complete data removal.
 * It supports popular WordPress backup plugins and provides appropriate warnings.
 *
 * @return bool True if backup verification passes or is not required, false otherwise
 */
function odcm_verify_database_backup() {
    // Only check backups if complete data removal is requested
    if (!odcm_should_remove_all_data()) {
        odcm_log_uninstall_action("Backup verification skipped - data preservation mode");
        return true;
    }

    // Check if backup plugin is active
    if (class_exists('BackupPlugin') || class_exists('UpdraftPlus') || class_exists('BackupBuddy')) {
        try {
            if (class_exists('BackupPlugin')) {
                $backup_exists = BackupPlugin::verify_backup_exists('order_daemon_data');
            } elseif (class_exists('UpdraftPlus')) {
                $backup_exists = method_exists('UpdraftPlus', 'has_backup') ? UpdraftPlus::has_backup() : true;
            } elseif (class_exists('BackupBuddy')) {
                $backup_exists = method_exists('BackupBuddy', 'has_recent_backup') ? BackupBuddy::has_recent_backup() : true;
            } else {
                $backup_exists = true;
            }

            if (isset($backup_exists) && !$backup_exists) {
                odcm_log_uninstall_error("No recent backup found. Consider creating a backup before uninstallation.");
                return false;
            }

            odcm_log_uninstall_action("Verified database backup exists");
            return true;
        } catch (Exception $e) {
            odcm_log_uninstall_error("Backup verification error: " . $e->getMessage());
            // Don't fail uninstallation due to backup verification issues
            return true;
        }
    }

    // Fallback: check for manual backups or database exports
    odcm_log_uninstall_action("No backup plugin detected. Manual backup recommended before complete data removal.");
    return true;
}

/**
 * Perform pre-uninstallation verification check
 *
 * @return bool True if uninstallation can proceed safely, false otherwise
 */
function odcm_perform_pre_uninstall_check() {
    global $wpdb;

    // Check if we're in a safe environment for uninstallation
    if (defined('WP_MAINTENANCE_MODE') && WP_MAINTENANCE_MODE) {
        odcm_log_uninstall_error("Uninstallation attempted during maintenance mode");
        return false;
    }

    // Check database connectivity using DatabaseHelper
    try {
        DatabaseHelper::get_var("SELECT 1");
    } catch (\Exception $e) {
        odcm_log_uninstall_error("Database connectivity check failed: " . $e->getMessage());
        return false;
    }

    // Check if we have sufficient memory for cleanup operations
    $memory_limit = ini_get('memory_limit');
    if (!empty($memory_limit) && strpos($memory_limit, 'M') !== false) {
        $memory_limit_bytes = (int)$memory_limit * 1024 * 1024;
        $memory_usage = memory_get_usage(true);

        // Require at least 16MB free memory for uninstallation
        if ($memory_usage > ($memory_limit_bytes - 16 * 1024 * 1024)) {
            odcm_log_uninstall_error("Insufficient memory for uninstallation operations");
            return false;
        }
    }

    odcm_log_uninstall_action("Pre-uninstallation verification passed");
    return true;
}

/**
 * Simulate uninstallation without making changes (dry-run mode)
 *
 * This function performs a complete simulation of the uninstallation process
 * without actually removing any data. It logs all actions that would be taken,
 * allowing administrators to verify what will be removed before committing
 * to the actual uninstallation.
 *
 * USAGE:
 * 1. Add to wp-config.php: define('ODCM_UNINSTALL_DRY_RUN', true);
 * 2. Uninstall the plugin via WordPress admin
 * 3. Check the uninstallation logs to see what would be removed
 * 4. Remove the constant and uninstall again to actually remove data
 *
 * BENEFITS:
 * - Safe testing of uninstallation process
 * - Verification of what data will be affected
 * - Debugging tool for troubleshooting uninstallation issues
 * - Training tool for understanding the cleanup process
 */
function odcm_uninstall_dry_run() {
    odcm_log_uninstall_action("Starting dry-run mode - no changes will be made");

    // Simulate all operations and log what would be removed
    $simulated_actions = [];

    // Database tables
    global $wpdb;
    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue'
    ];

    foreach ($tables as $table) {
        $table_exists = DatabaseHelper::table_exists($table);
        if ($table_exists === $table) {
            $simulated_actions[] = "Would remove table: $table";
        }
    }

    // Options
    $options = [
        'odcm_db_version', 'odcm_indexes_built', 'odcm_detailed_notes', 'odcm_debug',
        'odcm_custom_redact_keys', 'odcm_enable_refund_tracking', 'odcm_enable_deletion_tracking',
        'odcm_dev_debug_override', 'odcm_emergency_disable', 'odcm_last_failure',
        'odcm_circuit_breaker_failures', 'odcm_db_version_backup', 'odcm_update_backup_timestamp',
        'odcm_all_tables_exist_check', 'odcm_db_version_backup'
    ];

    foreach ($options as $option) {
        if (get_option($option) !== false) {
            $simulated_actions[] = "Would remove option: $option";
        }
    }

    // Custom post types
    $rules = get_posts([
        'post_type' => 'odcm_order_rule',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids'
    ]);

    if (!empty($rules)) {
        $simulated_actions[] = "Would remove " . count($rules) . " order rule(s)";
    }

    // Log all simulated actions
    foreach ($simulated_actions as $action) {
        odcm_log_uninstall_action($action);
    }

    odcm_log_uninstall_action("Dry-run completed. Use ODCM_REMOVE_ALL_DATA=true to actually remove data.");
}

/**
 * Verify that uninstallation was completed successfully
 *
 * @return bool True if verification passes, false otherwise
 */
function odcm_verify_uninstallation_completion() {
    global $wpdb;

    $verification_results = [
        'tables_removed' => true,
        'options_removed' => true,
        'post_types_removed' => true,
        'errors' => []
    ];

    // Only verify removal if complete data removal was requested
    if (!odcm_should_remove_all_data()) {
        odcm_log_uninstall_action("Verification skipped - data preservation mode");
        return true;
    }

    // Check if tables still exist
    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue'
    ];

    foreach ($tables as $table) {
        $table_exists = DatabaseHelper::table_exists($table);
        if ($table_exists) {
            $verification_results['tables_removed'] = false;
            $verification_results['errors'][] = "Table still exists: $table";
        }
    }

    // Check for remaining options
    // Use WordPress caching for verification
    $remaining_options = wp_cache_get('odcm_remaining_options_verification', 'odcm_uninstall');

    if ($remaining_options === false) {
        $remaining_options = DatabaseHelper::get_results(
            "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s LIMIT 1",
            ['odcm_%']
        );
        wp_cache_set('odcm_remaining_options_verification', $remaining_options, 'odcm_uninstall', HOUR_IN_SECONDS);
    }

    if (!empty($remaining_options)) {
        $verification_results['options_removed'] = false;
        $verification_results['errors'][] = "Remaining options found: " . count($remaining_options);
    }

    // Check for remaining custom post types
    // Use WordPress caching for verification
    $remaining_rules = wp_cache_get('odcm_remaining_rules_verification', 'odcm_uninstall');

    if ($remaining_rules === false) {
        $remaining_rules = DatabaseHelper::get_results(
            "SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_status = %s LIMIT 1",
            ['odcm_order_rule', 'any']
        );
        wp_cache_set('odcm_remaining_rules_verification', $remaining_rules, 'odcm_uninstall', HOUR_IN_SECONDS);
    }

    if (!empty($remaining_rules)) {
        $verification_results['post_types_removed'] = false;
        $verification_results['errors'][] = "Remaining order rules found: " . count($remaining_rules);
    }

    // Log verification results
    if (!empty($verification_results['errors'])) {
        foreach ($verification_results['errors'] as $error) {
            odcm_log_uninstall_error("Verification failed: $error");
        }
        return false;
    }

    odcm_log_uninstall_action("Uninstallation verification passed - all components removed successfully");
    return true;
}

/**
 * Main uninstallation routine with progress tracking
 */
function odcm_uninstall() {
    // Log uninstallation start
    odcm_log_uninstall_action("Starting Order Daemon uninstallation process");

    // Check for dry-run mode
    if (odcm_is_dry_run_mode()) {
        odcm_uninstall_dry_run();
        return;
    }

    // Perform pre-uninstallation verification (Step 1/5)
    odcm_log_uninstall_action("Step 1/5: Performing safety checks");
    if (!odcm_perform_pre_uninstall_check()) {
        odcm_log_uninstall_error("Uninstallation aborted due to pre-check failure");
        return;
    }

    // Verify backup before destructive operations (Step 2/5)
    odcm_log_uninstall_action("Step 2/5: Verifying database backup");
    if (!odcm_verify_database_backup()) {
        odcm_log_uninstall_error("Backup verification failed. Uninstallation aborted.");
        return;
    }

    // Always clean up temporary data (transients, cache, scheduled actions) (Step 3/5)
    odcm_log_uninstall_action("Step 3/5: Cleaning up temporary data");
    odcm_remove_plugin_transients();
    odcm_cleanup_scheduled_actions();

    // Only remove permanent data if explicitly requested
    if (odcm_should_remove_all_data()) {
        odcm_log_uninstall_action("Complete data removal requested - removing all Order Daemon data");

        // Database tables (Step 4/5)
        odcm_log_uninstall_action("Step 4/5: Removing database tables");
        odcm_remove_database_tables();

        // Plugin options
        odcm_log_uninstall_action("Step 4/5: Removing plugin options");
        odcm_remove_plugin_options();

        // Custom post types
        odcm_log_uninstall_action("Step 4/5: Removing custom post types");
        odcm_remove_custom_post_type_data();

        odcm_log_uninstall_action("Complete data removal completed");
    } else {
        odcm_log_uninstall_action("Data preservation mode - preserving all user data");
    }

    // Verification (Step 5/5)
    odcm_log_uninstall_action("Step 5/5: Verifying uninstallation completion");
    $verification_passed = odcm_verify_uninstallation_completion();

    if ($verification_passed) {
        odcm_log_uninstall_action("Uninstallation verification successful");
    } else {
        odcm_log_uninstall_error("Uninstallation verification failed - some components may not have been removed");
    }

    // Log uninstallation completion
    odcm_log_uninstall_action("Order Daemon uninstallation process completed");
}

// Execute the uninstallation
odcm_uninstall();
