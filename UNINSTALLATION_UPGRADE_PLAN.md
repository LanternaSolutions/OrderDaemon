# Order Daemon Uninstallation System Upgrade Plan

## Executive Summary

This document outlines a comprehensive plan to upgrade the Order Daemon uninstallation system to address identified issues, improve safety, enhance reliability, and provide better user experience. The upgrade will focus on robustness, completeness, and user safety.

## Current System Analysis

### Current Uninstallation Architecture

**Files Involved:**
- `uninstall.php` - Main uninstallation script
- `src/Includes/Installer.php` - Installation logic (reference for cleanup)
- `UNINSTALLATION.md` - User documentation

**Current Process Flow:**
1. Entry point validation (WP_UNINSTALL_PLUGIN check)
2. Pre-uninstallation safety checks
3. Temporary data cleanup (transients, scheduled actions)
4. Conditional complete data removal (if ODCM_REMOVE_ALL_DATA is true)
5. Database table removal
6. Plugin option removal
7. Custom post type cleanup

### Identified Issues and Gaps

**Critical Issues:**
1. **Incomplete Database Cleanup**: Missing tables/columns from Installer.php
2. **Insufficient Error Handling**: No proper SQL error recovery
3. **Memory Safety Issues**: Non-standard memory limit format handling
4. **SQL Injection Risk**: Unprepared statements in DROP operations
5. **Missing Verification**: No post-uninstallation success verification
6. **No Backup Mechanism**: Irreversible data loss risk

**Missing Components:**
- Dry-run/testing mode
- Progress feedback for large installations
- Comprehensive logging verification
- Database backup checks
- Rollback capability
- Pre-uninstallation checklist
- Post-uninstallation verification

## Upgrade Objectives

### Primary Goals

1. **Completeness**: Ensure all installed components are properly removed
2. **Safety**: Prevent accidental data loss and provide recovery options
3. **Reliability**: Robust error handling and verification
4. **User Experience**: Clear feedback and progress indicators
5. **Maintainability**: Clean, well-documented code

### Success Criteria

- All database tables and columns created by installer are removed
- All plugin options and transients are cleaned up
- Comprehensive error handling with meaningful feedback
- Backup verification before destructive operations
- Dry-run mode for testing
- Progress indicators for long operations
- Post-uninstallation verification
- Clear documentation updates

## Detailed Implementation Plan

### Phase 1: Analysis and Preparation

**Tasks:**
1. **Complete Component Inventory**
   - Document all database tables created by Installer.php
   - List all columns added by schema updates
   - Identify all WordPress options used
   - Catalog all custom post types
   - Document all scheduled actions and cron jobs
   - List all transients and cache entries

2. **Dependency Mapping**
   - Map relationships between components
   - Identify safe removal order
   - Document potential conflicts

3. **Risk Assessment**
   - Identify high-risk operations
   - Develop mitigation strategies
   - Create fallback procedures

### Phase 2: Core System Upgrades

**Task 1: Complete Database Cleanup**
- Add missing table removal for all installer-created tables
- Implement column-specific cleanup for schema updates
- Add verification that tables exist before removal attempts
- Use prepared statements for all SQL operations

**Task 2: Robust Error Handling**
- Implement try-catch blocks for all database operations
- Add transaction-like behavior with rollback capability
- Create comprehensive error logging
- Develop meaningful error messages for users

**Task 3: Safety Mechanisms**
- Add database backup verification
- Implement dry-run mode with detailed reporting
- Create pre-uninstallation checklist
- Add memory and resource safety checks
- Implement timeout handling for long operations

**Task 4: Progress and Feedback**
- Add progress indicators for large installations
- Implement detailed logging with timestamps
- Create user-friendly status messages
- Add estimated time remaining calculations

**Task 5: Verification System**
- Implement post-uninstallation verification
- Add component existence checks
- Create success/failure reporting
- Develop cleanup completion metrics

### Phase 3: Enhanced Features

**Task 1: Selective Removal Options**
- Add granular control over what gets removed
- Implement component-specific removal flags
- Create preservation options for critical data

**Task 2: Backup and Restore**
- Add automatic backup creation
- Implement restore capability
- Create backup verification system
- Add backup integrity checking

**Task 3: User Interface Improvements**
- Enhance admin notices for uninstallation
- Add uninstallation progress dashboard
- Create detailed removal reports
- Implement user confirmation dialogs

### Phase 4: Testing and Quality Assurance

**Testing Strategy:**
1. **Unit Testing**: Individual function validation
2. **Integration Testing**: Component interaction verification
3. **Regression Testing**: Ensure existing functionality preserved
4. **Performance Testing**: Large database handling
5. **Edge Case Testing**: Error conditions and recovery
6. **User Experience Testing**: Interface and feedback

**Test Cases:**
- Normal uninstallation with data preservation
- Complete removal with ODCM_REMOVE_ALL_DATA
- Error conditions (database failures, memory issues)
- Large database scenarios
- Partial installation cleanup
- Backup and restore verification
- Dry-run mode testing

### Phase 5: Documentation and Deployment

**Documentation Updates:**
1. **User Documentation**:
   - Update UNINSTALLATION.md with new features
   - Add troubleshooting guide
   - Create backup/restore instructions
   - Document dry-run usage

2. **Developer Documentation**:
   - Code comments and inline documentation
   - Architecture diagrams
   - Error handling guide
   - Testing procedures

3. **Technical Documentation**:
   - Component removal specifications
   - Safety mechanism details
   - Verification process documentation
   - Performance considerations

**Deployment Plan:**
1. **Staged Rollout**:
   - Internal testing phase
   - Beta release with opt-in
   - Full release with fallback

2. **Migration Path**:
   - Backward compatibility assurance
   - Data preservation during upgrade
   - Rollback procedure documentation

3. **Monitoring**:
   - Error tracking implementation
   - Usage analytics (opt-in)
   - Performance monitoring

## Technical Implementation Details

### Database Cleanup Enhancements

**Current Tables to Remove:**
```php
$tables = [
    $wpdb->prefix . 'odcm_audit_log',
    $wpdb->prefix . 'odcm_audit_log_payloads',
    $wpdb->prefix . 'odcm_audit_log_queue'
];
```

**Missing Tables/Columns from Installer.php:**
- `parent_id` column in audit_log table
- `display_data` column in audit_log table
- `dedupe_key` column in audit_log table
- `processed_display_data` column in audit_log_payloads table
- `last_processed` column in audit_log_payloads table
- Various indexes created during schema updates

**Enhanced Table Removal:**
```php
function odcm_remove_database_tables_comprehensive() {
    global $wpdb;

    // All tables created by installer
    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue'
    ];

    // Verify each table exists before attempting removal
    foreach ($tables as $table) {
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if ($table_exists === $table) {
            try {
                // Use prepared statement for safety
                $result = $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %s", $table));

                if ($result === false) {
                    throw new Exception("Failed to drop table: $table");
                }

                odcm_log_uninstall_action("Successfully dropped table: $table");
            } catch (Exception $e) {
                odcm_log_uninstall_error("Error dropping table $table: " . $e->getMessage());
                // Continue with other tables despite failure
            }
        }
    }
}
```

### Error Handling Improvements

**Current Error Handling:**
```php
if ($result === false) {
    odcm_log_uninstall_error("Failed to drop table: $table");
    continue;
}
```

**Enhanced Error Handling:**
```php
try {
    // Database operation
    $result = $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %s", $table));

    if ($result === false) {
        throw new Exception("Database operation failed for table: $table");
    }

    odcm_log_uninstall_action("Successfully completed operation on: $table");
} catch (Exception $e) {
    odcm_log_uninstall_error("Critical error: " . $e->getMessage());

    // Attempt recovery or provide meaningful feedback
    if (method_exists($wpdb, 'last_error')) {
        odcm_log_uninstall_error("Database error: " . $wpdb->last_error);
    }

    // Continue with other operations despite failure
}
```

### Safety Mechanism Implementation

**Backup Verification:**
```php
function odcm_verify_database_backup() {
    // Check if backup plugin is active
    if (class_exists('BackupPlugin')) {
        $backup_exists = BackupPlugin::verify_backup_exists('order_daemon_data');

        if (!$backup_exists) {
            odcm_log_uninstall_error("No recent backup found. Consider creating a backup before uninstallation.");
            return false;
        }

        odcm_log_uninstall_action("Verified database backup exists");
        return true;
    }

    // Fallback: check for manual backups
    odcm_log_uninstall_action("No backup plugin detected. Manual backup recommended.");
    return true;
}
```

**Dry-Run Mode:**
```php
function odcm_uninstall_dry_run() {
    odcm_log_uninstall_action("Starting dry-run mode - no changes will be made");

    // Simulate all operations and log what would be removed
    $simulated_actions = [];

    // Database tables
    $tables = odcm_get_installed_tables();
    foreach ($tables as $table) {
        $simulated_actions[] = "Would remove table: $table";
    }

    // Options
    $options = odcm_get_plugin_options();
    foreach ($options as $option) {
        $simulated_actions[] = "Would remove option: $option";
    }

    // Log all simulated actions
    foreach ($simulated_actions as $action) {
        odcm_log_uninstall_action($action);
    }

    odcm_log_uninstall_action("Dry-run completed. Use ODCM_REMOVE_ALL_DATA=true to actually remove data.");
}
```

### Progress Feedback System

**Progress Tracking:**
```php
function odcm_uninstall_with_progress() {
    $total_steps = 5;
    $current_step = 0;

    // Step 1: Pre-uninstallation checks
    $current_step++;
    odcm_log_uninstall_action("Step $current_step/$total_steps: Performing safety checks");
    if (!odcm_perform_pre_uninstall_check()) {
        return false;
    }

    // Step 2: Temporary data cleanup
    $current_step++;
    odcm_log_uninstall_action("Step $current_step/$total_steps: Cleaning temporary data");
    odcm_remove_plugin_transients();
    odcm_cleanup_scheduled_actions();

    // Step 3: Database cleanup (if requested)
    if (odcm_should_remove_all_data()) {
        $current_step++;
        odcm_log_uninstall_action("Step $current_step/$total_steps: Removing database tables");
        odcm_remove_database_tables_comprehensive();

        $current_step++;
        odcm_log_uninstall_action("Step $current_step/$total_steps: Removing plugin options");
        odcm_remove_plugin_options();

        $current_step++;
        odcm_log_uninstall_action("Step $current_step/$total_steps: Removing custom post types");
        odcm_remove_custom_post_type_data();
    }

    // Final verification
    odcm_log_uninstall_action("Uninstallation process completed");
    return odcm_verify_uninstallation_completion();
}
```

### Verification System

**Post-Uninstallation Verification:**
```php
function odcm_verify_uninstallation_completion() {
    global $wpdb;

    $verification_results = [
        'tables_removed' => true,
        'options_removed' => true,
        'post_types_removed' => true,
        'errors' => []
    ];

    // Check if tables still exist
    $tables = [
        $wpdb->prefix . 'odcm_audit_log',
        $wpdb->prefix . 'odcm_audit_log_payloads',
        $wpdb->prefix . 'odcm_audit_log_queue'
    ];

    foreach ($tables as $table) {
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($table_exists === $table) {
            $verification_results['tables_removed'] = false;
            $verification_results['errors'][] = "Table still exists: $table";
        }
    }

    // Check for remaining options
    $remaining_options = $wpdb->get_results(
        "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'odcm_%' LIMIT 1"
    );

    if (!empty($remaining_options)) {
        $verification_results['options_removed'] = false;
        $verification_results['errors'][] = "Remaining options found: " . count($remaining_options);
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
```

## Implementation Roadmap

### Week 1: Foundation and Safety
- Implement comprehensive error handling
- Add backup verification system
- Create dry-run mode
- Enhance logging system

### Week 2: Database and Cleanup
- Complete database cleanup implementation
- Add all missing table/column removal
- Implement verification system
- Add progress tracking

### Week 3: User Experience and Testing
- Create admin interface improvements
- Implement progress feedback
- Develop comprehensive test suite
- Perform internal testing

### Week 4: Documentation and Deployment
- Update all documentation
- Create user guides
- Prepare deployment packages
- Final testing and validation

## Risk Mitigation Strategies

### Data Loss Prevention
- Backup verification before destructive operations
- Dry-run mode for testing
- Comprehensive error handling
- Detailed logging for troubleshooting

### Performance Optimization
- Batch processing for large datasets
- Memory management improvements
- Timeout handling
- Progress feedback to prevent timeouts

### Compatibility Assurance
- Backward compatibility checks
- WordPress version compatibility
- Database engine compatibility
- PHP version compatibility

## Success Metrics

### Technical Metrics
- 100% cleanup completion rate
- 0 critical errors in production
- <5% user-reported issues
- <100ms overhead for verification

### User Metrics
- 90% user satisfaction with uninstallation process
- <10% support requests related to uninstallation
- 80% successful first-attempt uninstallations
- 95% successful data preservation when requested

## Budget and Resources

### Development Resources
- 1 Senior PHP Developer (4 weeks)
- 1 QA Engineer (2 weeks)
- 1 Technical Writer (1 week)
- 1 DevOps Engineer (1 week)

### Infrastructure Requirements
- Testing environment with various WordPress versions
- Large database test instances
- Error monitoring and logging system
- Performance testing tools

## Conclusion

This comprehensive upgrade plan addresses all identified issues in the Order Daemon uninstallation system while adding significant safety features, improved reliability, and better user experience. The phased implementation approach ensures minimal disruption while delivering maximum benefit. The enhanced system will provide users with confidence in the uninstallation process and reduce support burden related to data loss or incomplete cleanup.
