# Order Daemon Uninstallation Guide

This document explains how Order Daemon handles plugin deactivation and uninstallation, including data preservation and removal options.

## Default Behavior: Data Preservation

By default, Order Daemon is designed to **preserve all your data** when you deactivate or uninstall the plugin. This safety-first approach prevents accidental data loss.

### What Gets Preserved

When you deactivate or uninstall Order Daemon, the following data is **kept intact**:

1. **Database Tables**:
   - `wp_odcm_audit_log` - All audit log entries and order processing history
   - `wp_odcm_audit_log_payloads` - Detailed payload data for audit entries
   - `wp_odcm_audit_log_queue` - Queue data for async processing

2. **Custom Post Types**:
   - All your `odcm_order_rule` posts (your custom completion rules)

3. **Plugin Settings**:
   - All Order Daemon options and configurations
   - User preferences and dashboard settings

4. **Processing History**:
   - Complete record of all order processing events
   - Timeline data and diagnostic information

### What Gets Cleaned Up

Even with data preservation, Order Daemon cleans up temporary data:

- WordPress transients and cache entries
- Scheduled actions and cron jobs
- Temporary processing queue data

## Complete Data Removal (Optional)

If you want to **completely remove all Order Daemon data** when uninstalling, you can use the `ODCM_REMOVE_ALL_DATA` constant.

### How to Enable Complete Removal

Add this line to your `wp-config.php` file **before uninstalling**:

```php
define('ODCM_REMOVE_ALL_DATA', true);
```

### What Gets Removed with Complete Removal

When `ODCM_REMOVE_ALL_DATA` is set to `true`, the following will be **permanently deleted**:

1. **All Database Tables**:
   - `wp_odcm_audit_log`
   - `wp_odcm_audit_log_payloads`
   - `wp_odcm_audit_log_queue`

2. **All Plugin Options**:
   - Database version tracking
   - Rule indexes status
   - User preferences and settings
   - Debug and diagnostic options

3. **All Custom Post Type Data**:
   - All your order completion rules
   - Rule configurations and settings

4. **All Temporary Data**:
   - Transients and cache entries
   - Scheduled actions
   - Processing queue data

⚠️ **Warning**: This action is **permanent and irreversible**. All your Order Daemon data will be completely removed.

## Deactivation vs Uninstallation

### Deactivation

- **Temporary**: Plugin functionality is disabled but can be re-enabled
- **Data Preserved**: All data remains intact
- **Cleanup**: Only temporary data (transients, cache, scheduled actions) is removed
- **Reversible**: Reactivating the plugin restores full functionality with all data intact

### Uninstallation

- **Permanent**: Plugin is completely removed from your WordPress installation
- **Default Behavior**: All data is preserved (tables, options, post types)
- **Optional Complete Removal**: Use `ODCM_REMOVE_ALL_DATA` constant to remove everything
- **Reinstallation**: You can reinstall the plugin later and your data will still be available (unless you used complete removal)

## Best Practices

### For Most Users (Recommended)

1. **Deactivate** the plugin if you want to temporarily disable it
2. **Keep default behavior** when uninstalling to preserve your data
3. **Reinstall** later if needed - your rules and history will be available

### For Developers/Testers

1. Use `ODCM_REMOVE_ALL_DATA` when you need a clean slate
2. **Use dry-run mode first**: `define('ODCM_UNINSTALL_DRY_RUN', true);` to test what will be removed
3. Remember to remove the constants from `wp-config.php` after uninstallation
4. Consider backing up your database before complete removal

### For Site Migration

1. **Deactivate** the plugin on the old site (data preserved)
2. **Migrate** your WordPress database to the new site
3. **Install** Order Daemon on the new site - your data will be available
4. **Activate** the plugin to resume functionality

## Advanced Features

### Dry-Run Mode

The dry-run mode allows you to test the uninstallation process without making any changes to your database. This is particularly useful for:

- **Testing**: Verify what data will be affected before actual removal
- **Debugging**: Troubleshoot uninstallation issues without risk
- **Training**: Understand the cleanup process
- **Safety**: Confirm the scope of data removal

**How to Use Dry-Run Mode:**

1. Add this line to your `wp-config.php` file:
   ```php
   define('ODCM_UNINSTALL_DRY_RUN', true);
   ```

2. Uninstall the plugin via WordPress admin

3. Check the uninstallation logs to see what would be removed

4. Remove the constant from `wp-config.php` and uninstall again to actually remove data

**What Dry-Run Mode Shows:**

- All database tables that would be removed
- All plugin options that would be deleted
- All custom post types that would be cleaned up
- Detailed logging of the entire process

### Backup Verification

The enhanced uninstallation system includes automatic backup verification to prevent accidental data loss:

**How Backup Verification Works:**

1. When complete data removal is requested, the system checks for existing backups
2. It supports popular WordPress backup plugins (UpdraftPlus, BackupBuddy, etc.)
3. If no recent backup is found, it provides warnings and may abort the process
4. For manual backups, it provides recommendations

**Backup Verification Process:**

- **Automatic Detection**: Checks for installed backup plugins
- **Recent Backup Check**: Verifies if recent backups exist
- **Warning System**: Provides clear warnings if no backups are found
- **Safety Net**: Can abort uninstallation if backup verification fails

### Enhanced Error Handling

The upgraded uninstallation system includes comprehensive error handling:

**Error Handling Features:**

- **Database Operation Safety**: Try-catch blocks for all database operations
- **Detailed Error Logging**: Comprehensive logging of all errors
- **Graceful Failure**: Continues with other operations despite individual failures
- **Meaningful Messages**: Clear error messages for troubleshooting

**Common Errors Handled:**

- Database connectivity issues
- Table removal failures
- Memory limitations
- Backup verification problems
- Plugin option cleanup issues

## Troubleshooting Enhanced Features

### "Dry-run mode didn't work"

1. Verify the constant is correctly defined in `wp-config.php`:
   ```php
   define('ODCM_UNINSTALL_DRY_RUN', true);
   ```

2. Check that the constant is defined before WordPress loads

3. Verify no caching plugins are interfering

4. Check the uninstallation logs for dry-run output

### "Backup verification failed"

1. Install a supported backup plugin (UpdraftPlus, BackupBuddy, etc.)

2. Create a fresh backup before attempting uninstallation

3. Check backup plugin compatibility with Order Daemon

4. Consider manual database backup if plugin detection fails

### "Error during uninstallation"

1. Check WordPress debug logs for detailed error information

2. Review the uninstallation transient logs

3. Try dry-run mode to identify potential issues

4. Contact support with the error details

## Viewing Uninstallation Logs

The enhanced uninstallation system provides detailed logging:

**Where to Find Logs:**

1. **WordPress Debug Log**: Check `wp-content/debug.log` for `[ODCM Uninstall]` entries

2. **Transient Logs**: The system stores logs in a transient that can be accessed programmatically:
   ```php
   $logs = get_transient('odcm_uninstall_log');
   print_r($logs);
   ```

3. **Admin Notices**: Some systems may display uninstallation status in admin area

**Log Format:**

```
[ACTION] [timestamp]: Action description
[ERROR] [timestamp]: Error description with details
```

## Command Reference

### Constants for wp-config.php

```php
// Complete data removal
define('ODCM_REMOVE_ALL_DATA', true);

// Dry-run mode (testing)
define('ODCM_UNINSTALL_DRY_RUN', true);
```

### Checking Uninstallation Status

```php
// Check if complete removal is enabled
$complete_removal = defined('ODCM_REMOVE_ALL_DATA') && ODCM_REMOVE_ALL_DATA;

// Check if dry-run mode is enabled
$dry_run = defined('ODCM_UNINSTALL_DRY_RUN') && ODCM_UNINSTALL_DRY_RUN;

// Get uninstallation logs
$logs = get_transient('odcm_uninstall_log');
```

## Support

If you have any questions about the enhanced uninstallation features or need assistance, please contact our support team with:

- Details about your WordPress environment
- Any error messages received
- Whether you're using dry-run or complete removal mode

## Troubleshooting

### "My data wasn't preserved after uninstallation"

This should not happen with the default behavior. If it does:

1. Check if `ODCM_REMOVE_ALL_DATA` was defined in `wp-config.php`
2. Verify that the uninstallation completed successfully
3. Check your database for the Order Daemon tables

### "I want to remove data but keep my rules"

Currently, the complete removal option removes everything. If you need selective removal:

1. Export your rules manually before uninstallation
2. Use complete removal to clean up
3. Reinstall and recreate your rules

### "I accidentally used complete removal"

If you accidentally removed all data:

1. Restore from a database backup if available
2. Contact support if you need assistance with data recovery
3. Consider this a reminder to always back up before major operations

## Support

If you have any questions about the uninstallation process or need assistance, please contact our support team with details about your specific situation.
