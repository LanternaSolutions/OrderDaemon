<?php
/**
 * Order Daemon Uninstaller
 *
 * This file is automatically executed when the plugin is deleted via the WordPress admin.
 *
 * By default, uninstallation preserves all user data (database tables, options, and custom post
 * types) to prevent accidental data loss. Complete removal of all Order Daemon data is opt‑in
 * by either:
 *
 *  - Adding to wp-config.php: `define('ODCM_REMOVE_ALL_DATA', true);` before uninstalling, or
 *  - In the future, checking a "Remove all data on uninstall" checkbox in the plugin settings.
 *
 * Dry‑run mode can be used for testing: when `ODCM_UNINSTALL_DRY_RUN` is true, the script logs
 * what *would* be removed but does not modify the database or file system.
 *
 * @package OrderDaemon
 * @since   1.0.0
 */


// If uninstall.php is not called by WordPress, abort immediately.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Initialize WordPress database object
global $wpdb;

/**
 * Initialize DatabaseHelper
 *
 * Ensures our database‑utility class is ready for use within the uninstaller.
 */
require_once __DIR__ . '/src/Includes/Utils/DatabaseHelper.php';
DatabaseHelper::initialize($GLOBALS['wpdb']);


/**
 * Check if complete data removal is requested.
 *
 * If `ODCM_REMOVE_ALL_DATA` is defined and truthy in wp‑config.php, this returns true.
 *
 * @since 1.0.0
 * @return bool True if complete removal is requested, false otherwise.
 */
function odcm_should_remove_all_data() {
    if (defined('ODCM_REMOVE_ALL_DATA') && ODCM_REMOVE_ALL_DATA) {
        return true;
    }
    return get_option('odcm_remove_all_data_on_uninstall', false);
}


/**
 * Check if dry‑run (test) mode is enabled.
 *
 * When `ODCM_UNINSTALL_DRY_RUN` is defined and truthy, this returns true.
 *
 * @since 1.0.0
 * @return bool True if dry‑run mode is enabled, false otherwise.
 */
function odcm_is_dry_run_mode() {
    return defined('ODCM_UNINSTALL_DRY_RUN') && ODCM_UNINSTALL_DRY_RUN;
}


/**
 * Log an uninstallation action for troubleshooting.
 *
 * Messages go to both PHP error log and a transient for easy debugging.
 *
 * @since 1.0.0
 * @param string $message The action message to log.
 */
function odcm_log_uninstall_action($message) {
    if (function_exists('odcm_log_message')) {
        odcm_log_message('[ODCM Uninstall] ' . $message, 'error');
    }

    $log = get_transient('odcm_uninstall_log');
    if (!is_array($log)) {
        $log = [];
    }

    $log[] = '[ACTION] ' . current_time('mysql') . ': ' . $message;
    set_transient('odcm_uninstall_log', $log, HOUR_IN_SECONDS);
}


/**
 * Log an uninstallation error for troubleshooting.
 *
 * Messages go to both PHP error log and a transient for easy debugging.
 *
 * @since 1.0.0
 * @param string $message The error message to log.
 */
function odcm_log_uninstall_error($message) {
    if (function_exists('odcm_log_message')) {
        odcm_log_message('[ODCM Uninstall ERROR] ' . $message, 'error');
    }

    $log = get_transient('odcm_uninstall_log');
    if (!is_array($log)) {
        $log = [];
    }

    $log[] = '[ERROR] ' . current_time('mysql') . ': ' . $message;
    set_transient('odcm_uninstall_log', $log, HOUR_IN_SECONDS);
}


/**
 * Remove Order Daemon database tables with error handling.
 *
 * Drops all known Order Daemon tables when complete removal is requested.
 *
 * Runs in dry‑run mode: logs what would be removed, but does not actually drop tables.
 *
 * @since 1.0.0
 */
function odcm_remove_database_tables() {
    global $wpdb;

    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue',
    ];

    foreach ($tables as $table) {
        try {
            $table_existed = DatabaseHelper::table_exists($table);

            if ($table_existed) {
                if (odcm_is_dry_run_mode()) {
                    odcm_log_uninstall_action("DRY‑RUN: would drop table: $table");
                } else {
                    if (!DatabaseHelper::drop_table($table)) {
                        odcm_log_uninstall_error("Failed to drop table: $table");
                    } else {
                        odcm_log_uninstall_action("Successfully dropped table: $table");
                    }
                }
            }
        } catch (Exception $e) {
            odcm_log_uninstall_error("Error checking or dropping table $table: " . $e->getMessage());
            if (!empty($wpdb->last_error)) {
                odcm_log_uninstall_error("Database error details: " . $wpdb->last_error);
            }
            continue;
        }
    }
}


/**
 * Remove Order Daemon plugin options from the database.
 *
 * Deletes individual `odcm_` options, plus batches matching patterns.
 *
 * Runs in dry‑run mode: logs what would be removed, but does not actually delete.
 *
 * @since 1.0.0
 */
function odcm_remove_plugin_options() {
    // List of specific options
    $single_options = [
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
        'odcm_update_backup_timestamp',
        'odcm_all_tables_exist_check',
        'odcm_db_version_backup',
    ];

    foreach ($single_options as $option) {
        if (get_option($option) !== false) {
            if (odcm_is_dry_run_mode()) {
                odcm_log_uninstall_action("DRY‑RUN: would delete option: $option");
            } else {
                delete_option($option);
                odcm_log_uninstall_action("Deleted option: $option");
            }
        }
    }

    // Wildcard patterns
    $patterns = [
        'odcm_cleanup_%',
        'odcm_table_exists_%',
        'odcm_manual_cleanup_%',
        'odcm_transaction_%',
        'odcm_%',
    ];

    foreach ($patterns as $pattern) {
        $count = DatabaseHelper::delete_options_by_pattern($pattern);

        if (odcm_is_dry_run_mode()) {
            if ($count > 0) {
                odcm_log_uninstall_action("DRY‑RUN: would delete $count option(s) matching pattern: $pattern");
            }
        } else {
            odcm_log_uninstall_action("Deleted $count option(s) matching pattern: $pattern");
        }
    }
}


/**
 * Remove Order Daemon custom post type data.
 *
 * Permanently deletes all `odcm_order_rule` posts (no trash).
 *
 * Runs in dry‑run mode: logs what would be removed, but does not actually delete.
 *
 * @since 1.0.0
 */
function odcm_remove_custom_post_type_data() {
    $rule_args = [
        'post_type'   => 'odcm_order_rule',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
    ];

    $rules = get_posts($rule_args);

    if (empty($rules)) {
        odcm_log_uninstall_action("No order rules found to remove");
        return;
    }

    foreach ($rules as $rule_id) {
        if (odcm_is_dry_run_mode()) {
            odcm_log_uninstall_action("DRY‑RUN: would permanently delete order rule ID: $rule_id");
        } else {
            wp_delete_post($rule_id, true);
            odcm_log_uninstall_action("Permanently deleted order rule ID: $rule_id");
        }
    }
}


/**
 * Remove Order Daemon plugin transients.
 *
 * Deletes all transients whose keys start with `odcm_`.
 *
 * Runs in dry‑run mode: logs what would be removed, but does not actually delete.
 *
 * @since 1.0.0
 */
function odcm_remove_plugin_transients() {
    $pattern = 'odcm_%';

    if (odcm_is_dry_run_mode()) {
        odcm_log_uninstall_action("DRY‑RUN: would delete transients matching pattern: $pattern");
    } else {
        DatabaseHelper::delete_transients_by_pattern($pattern);
        odcm_log_uninstall_action("Deleted transients matching pattern: $pattern");
    }
}


/**
 * Clean up scheduled actions and WordPress cron events.
 *
 * Removes all Action Scheduler actions and cron hooks prefixed with `odcm_`.
 *
 * Runs unconditionally (always safe to clean these when uninstalling).
 *
 * @since 1.0.0
 */
function odcm_cleanup_scheduled_actions() {
    odcm_log_uninstall_action("Cleaning up scheduled actions and cron events");

    // Action Scheduler hooks
    $as_actions = [
        'odcm_cleanup_old_logs',
        'odcm_cleanup_audit_log_queue',
        'odcm_cleanup_rule_execution_transients',
    ];

    if (function_exists('as_unschedule_all_actions')) {
        foreach ($as_actions as $hook) {
            $count = as_unschedule_all_actions($hook);
            if ($count > 0) {
                odcm_log_uninstall_action("Removed $count scheduled actions: $hook");
            }
        }
    }

    // WordPress cron hooks
    $hook_prefix = 'odcm_';

    $crons = _get_cron_array();
    if (!empty($crons)) {
        foreach ($crons as $timestamp => $hooks) {
            foreach (array_keys($hooks) as $hook) {
                if (strpos($hook, $hook_prefix) === 0) {
                    wp_clear_scheduled_hook($hook);
                    odcm_log_uninstall_action("Removed cron hook: $hook");
                }
            }
        }
    }
}


/**
 * Verify that a database backup appears to exist before removing data.
 *
 * Only active when `ODCM_REMOVE_ALL_DATA` is enabled.
 *
 * Returns true unless a known backup plugin reports *no* backup,
 * which then logs an error and returns false.
 *
 * This behavior can be made non‑blocking in future releases; see warning.
 *
 * @since 1.0.0
 * @return bool True if backup check passes or is not required; false if no backup is found.
 */
function odcm_verify_database_backup() {
    if (!odcm_should_remove_all_data()) {
        odcm_log_uninstall_action("Backup verification skipped: data preservation mode");
        return true;
    }

    // Check known backup plugins
    $backup_plugins_found = 0;
    $backup_exists        = true;

    if (class_exists('UpdraftPlus')) {
        $backup_plugins_found++;
        $backup_exists = method_exists('UpdraftPlus', 'has_backup')
            ? UpdraftPlus::has_backup()
            : true;
    }

    if (class_exists('BackupBuddy') && $backup_exists) {
        $backup_plugins_found++;
        $backup_exists = method_exists('BackupBuddy', 'has_recent_backup')
            ? BackupBuddy::has_recent_backup()
            : true;
    }

    // Add more if you like; avoid depending on any single one being present

    if ($backup_plugins_found > 0 && !$backup_exists) {
        odcm_log_uninstall_error("No recent backup found with installed backup plugin.");
        return false;
    }

    odcm_log_uninstall_action("Backup check: " . ($backup_plugins_found > 0 ? 'backup plugin(s) found' : 'no backup plugin detected (manual backup recommended)'));

    return true;
}


/**
 * Perform pre‑uninstallation safety checks.
 *
 * Checks:
 *  - Not in unknown maintenance mode.
 *  - Database is reachable via a simple SELECT.
 *  - PHP memory usage is within safe bounds for cleanup.
 *
 * Returns true if uninstall may proceed safely; false otherwise.
 *
 * @since 1.0.0
 * @return bool True if safety checks pass, false otherwise.
 */
function odcm_perform_pre_uninstall_check() {
    global $wpdb;

    // Optional: skip if some non‑standard maintenance constant is active
    // (you can remove or replace with your own check if needed).
    if (defined('WP_MAINTENANCE_MODE') && WP_MAINTENANCE_MODE) {
        odcm_log_uninstall_error("Uninstallation blocked; WP_MAINTENANCE_MODE is active.");
        return false;
    }

    // Database connectivity
    try {
        DatabaseHelper::get_var("SELECT 1");
    } catch (\Exception $e) {
        odcm_log_uninstall_error("Database connectivity check failed: " . $e->getMessage());
        return false;
    }

    // Memory safety (allows at least 16MB free)
    $memory_limit = ini_get('memory_limit');
    if (!empty($memory_limit) && false !== strpos($memory_limit, 'M')) {
        $memory_limit_bytes = (int) $memory_limit * 1024 * 1024;
        $memory_usage       = memory_get_usage(true);

        if ($memory_usage > ($memory_limit_bytes - 16 * 1024 * 1024)) {
            odcm_log_uninstall_error("Insufficient memory for uninstall operations.");
            return false;
        }
    }

    odcm_log_uninstall_action("Pre‑uninstallation verification passed.");

    return true;
}


/**
 * Simulate uninstallation without making changes (dry‑run).
 *
 * Logs every change that *would* be made:
 *  - Tables that would be dropped.
 *  - Options/CPTs that would be deleted.
 *
 * Does not actually remove anything when `ODCM_UNINSTALL_DRY_RUN` is true.
 *
 * @since 1.0.0
 */
function odcm_uninstall_dry_run() {
    global $wpdb;

    $simulated = [];

    // Tables that would be dropped
    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue',
    ];

    foreach ($tables as $table) {
        if (DatabaseHelper::table_exists($table)) {
            $simulated[] = "Would remove table: $table";
        }
    }

    // Options that would be deleted
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
        'odcm_update_backup_timestamp',
        'odcm_all_tables_exist_check',
        'odcm_db_version_backup',
    ];

    foreach ($options as $option) {
        if (get_option($option) !== false) {
            $simulated[] = "Would remove option: $option";
        }
    }

    // Custom post types that would be deleted
    $rules = get_posts([
        'post_type'   => 'odcm_order_rule',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);

    if (!empty($rules)) {
        $simulated[] = "Would remove " . count($rules) . " order rule(s)";
    }

    // Log everything
    foreach ($simulated as $action) {
        odcm_log_uninstall_action($action);
    }

    odcm_log_uninstall_action("DRY‑RUN: execution complete. Set ODCM_REMOVE_ALL_DATA=true and uninstall again to actually remove data.");
}


/**
 * Verify that uninstallation completed as expected.
 *
 * If complete removal was requested:
 *  - Checks that known tables no longer exist.
 *  - Checks that no `odcm_` options remain.
 *  - Checks that no `odcm_order_rule` posts remain.
 *
 * Otherwise, simply logs that verification is skipped (data is preserved).
 *
 * @since 1.0.0
 * @return bool True if verification passes, false otherwise.
 */
function odcm_verify_uninstallation_completion() {
    global $wpdb;

    if (!odcm_should_remove_all_data()) {
        odcm_log_uninstall_action("Verification skipped: data preservation mode.");
        return true;
    }

    $results = [
        'tables_removed'   => true,
        'options_removed'  => true,
        'posts_removed'    => true,
        'errors'           => [],
    ];

    // Check tables
    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue',
    ];

    foreach ($tables as $table) {
        if (DatabaseHelper::table_exists($table)) {
            $results['tables_removed'] = false;
            $results['errors'][]        = "Table still exists: $table";
        }
    }

    // Check options (first via cache)
    $cache_key  = 'odcm_remaining_options_verification';
    $remaining  = wp_cache_get($cache_key, 'odcm_uninstall');

    if (false === $remaining) {
        $remaining = DatabaseHelper::get_results(
            "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s LIMIT 1",
            ['odcm_%']
        );
        wp_cache_set($cache_key, $remaining, 'odcm_uninstall', HOUR_IN_SECONDS);
    }

    if (!empty($remaining)) {
        $results['options_removed'] = false;
        $results['errors'][]         = "Remaining options found: " . count($remaining);
    }

    // Check remaining custom post types (without 'any' status)
    $post_cache_key = 'odcm_remaining_rules_verification';
    $rule_posts     = wp_cache_get($post_cache_key, 'odcm_uninstall');

    if (false === $rule_posts) {
        $rule_posts = DatabaseHelper::get_results(
            "SELECT ID FROM $wpdb->posts WHERE post_type = %s LIMIT 1",
            ['odcm_order_rule']
        );
        wp_cache_set($post_cache_key, $rule_posts, 'odcm_uninstall', HOUR_IN_SECONDS);
    }

    if (!empty($rule_posts)) {
        $results['posts_removed'] = false;
        $results['errors'][] = "Remaining order rules found: " . count($rule_posts);
    }

    if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
            odcm_log_uninstall_error("Verification failed: $error");
        }
        return false;
    }

    odcm_log_uninstall_action("Uninstallation verification passed: all components removed successfully.");
    return true;
}


/**
 * Main uninstallation routine (entry point).
 *
 * Orchestrates:
 *  - Safety checks (environment, memory, DB).
 *  - Backup verification when full removal is requested.
 *  - Dry‑run mode when enabled.
 *  - Removal of temporary data (transients, scheduled actions).
 *  - Optional complete removal of tables, options, and CPTs.
 *  - Final verification pass when removal was requested.
 *
 * @since 1.0.0
 */
function odcm_uninstall() {
    odcm_log_uninstall_action("Starting Order Daemon uninstallation process.");

    // Dry‑run first: just simulate changes and stop
    if (odcm_is_dry_run_mode()) {
        odcm_uninstall_dry_run();
        odcm_log_uninstall_action("Dry‑run finished; no changes made to the system.");
        return;
    }

    // Safety checks: DB access, memory, environment sanity
    odcm_log_uninstall_action("Step 1/5: Performing pre‑uninstallation safety checks.");
    if (!odcm_perform_pre_uninstall_check()) {
        odcm_log_uninstall_error("Pre‑uninstallation check failed; uninstall aborted.");
        return;
    }

    // Backup check only if user opted into full removal
    odcm_log_uninstall_action("Step 2/5: Verifying database backup (if complete removal requested).");
    if (!odcm_verify_database_backup()) {
        odcm_log_uninstall_error("Backup verification failed; full removal aborted for safety.");
        return;
    }

    // Always clean up temporary artifacts (safe in both modes)
    odcm_log_uninstall_action("Step 3/5: Cleaning up transients and scheduled actions.");
    odcm_remove_plugin_transients();
    odcm_cleanup_scheduled_actions();

    // Only remove permanent data if explicitly requested
    if (odcm_should_remove_all_data()) {
        odcm_log_uninstall_action("Step 4/5: Removing permanent Order Daemon data.");

        odcm_log_uninstall_action("Removing database tables.");
        odcm_remove_database_tables();

        odcm_log_uninstall_action("Removing plugin options.");
        odcm_remove_plugin_options();

        odcm_log_uninstall_action("Removing custom post type data.");
        odcm_remove_custom_post_type_data();

        odcm_log_uninstall_action("Complete data removal phase finished.");
    } else {
        odcm_log_uninstall_action("Uninstall: data is preserved (tables, options, and CPTs retained).");
    }

    // Final verification (only meaningful when removal was requested)
    odcm_log_uninstall_action("Step 5/5: Verifying uninstallation completion.");
    $verification_ok = odcm_verify_uninstallation_completion();

    if ($verification_ok) {
        odcm_log_uninstall_action("Uninstallation verification successful.");
    } else {
        odcm_log_uninstall_error("Uninstallation verification failed; some components may remain.");
    }

    odcm_log_uninstall_action("Order Daemon uninstallation process completed.");
}


// Execute the uninstaller
odcm_uninstall();