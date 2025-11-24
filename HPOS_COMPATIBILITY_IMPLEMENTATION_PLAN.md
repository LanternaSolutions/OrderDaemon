# Order Daemon HPOS Compatibility Implementation Plan

## Overview

This document outlines the implementation plan to make Order Daemon compatible with WooCommerce High-Performance Order Storage (HPOS) while maintaining full backwards compatibility with the legacy WordPress posts system.

## Problem Statement

Order Daemon currently uses WordPress post meta functions (`get_post_meta`, `update_post_meta`) to store order-related metadata. When WooCommerce HPOS is enabled, orders are stored in custom tables rather than as WordPress posts, making these functions ineffective and causing plugin functionality to fail.

## Solution Approach

Implement an **OrderMetaManager** abstraction layer that automatically detects the active storage system and uses the appropriate methods for order metadata operations.

## Implementation Strategy

### 1. Core Abstraction Layer ✅ COMPLETED

**File**: `src/Includes/Utils/OrderMetaManager.php`

#### Class Responsibilities
- Detect whether HPOS or legacy post system is active
- Provide unified interface for order metadata operations
- Handle order object retrieval and caching
- Manage metadata transactions and error handling

#### Key Methods
```php
class OrderMetaManager {
    public static function get_meta($order_id, $key, $single = true)
    public static function update_meta($order_id, $key, $value) 
    public static function delete_meta($order_id, $key)
    public static function add_meta($order_id, $key, $value, $unique = false)
    public static function get_order($order_id): ?WC_Order
    public static function is_hpos_enabled(): bool
    private static function get_order_cached($order_id): ?WC_Order
}
```

#### Detection Logic
```php
public static function is_hpos_enabled(): bool {
    return class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
           \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}
```

#### Implementation Logic
- **HPOS Mode**: Use `$order->get_meta($key)` and `$order->update_meta_data($key, $value); $order->save()`
- **Legacy Mode**: Use `get_post_meta($order_id, $key)` and `update_post_meta($order_id, $key, $value)`

### 2. HPOS Compatibility Declaration ✅ COMPLETED

**File**: `order-daemon.php` (main plugin file)

Add compatibility declaration before plugin initialization:
```php
// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', 
            __FILE__, 
            true
        );
    }
});
```

### 3. File-by-File Migration Plan

#### Core Plugin Files Requiring Updates

**Priority 1 - Critical Order Processing Files** ✅ COMPLETED

1. **`src/Core/Core.php`** ✅ COMPLETED (23 order metadata calls updated)
   - `update_last_status_processed_meta()` - Status tracking metadata
   - `get_last_status_processed()` - Status retrieval
   - `mark_specific_status_processed()` - Status hook tracking
   - `has_specific_status_processed()` - Deduplication checks
   - `queue_traditional_checkout_data()` - Checkout data storage
   - `queue_basic_checkout_data()` - Basic checkout storage

2. **`src/Core/ProcessIdManager.php`** ✅ COMPLETED (6 metadata calls updated)
   - `get_or_create_process_id()` - Process tracking
   - `close_process()` - Process completion
   - Order process lifecycle management

3. **`src/Core/ManualStatusTracker.php`** ✅ COMPLETED (2 metadata calls updated)
   - Manual status change detection and tracking

4. **`src/Core/BlockCheckoutCompatibility.php`** ✅ COMPLETED (6 metadata calls updated)
   - Block checkout observation and data storage
   - Checkout queue management

**Priority 2 - Diagnostic and Analysis Files** ✅ COMPLETED

5. **`src/Core/RefundDeletionDiagnostics.php`** ✅ COMPLETED (1 order metadata call updated)
   - Refund analysis and order metadata inspection
   - *Note: 3 refund-related metadata calls correctly left unchanged as refunds remain as posts*

6. **`src/Includes/actions.php`** ✅ COMPLETED (3 order metadata calls updated)
   - Queue ID retrieval for checkout processing

7. **`src/Includes/functions.php`** ✅ COMPLETED (HPOS compatibility added)
   - `odcm_get_post_meta_by_ids()` utility function enhanced with HPOS support

**Priority 3 - Rule and Component Management** ❌ PENDING

8. **`src/Core/RuleComponents/RuleIndexBuilder.php`** ❌ PENDING (12 occurrences)
   - Rule indexing and metadata management
   - Component analysis and storage

9. **`src/Admin/RuleBuilder.php`** ❌ PENDING (2 occurrences)
   - Rule data storage and retrieval

10. **`src/API/RuleBuilderApiController.php`** ❌ PENDING (4 occurrences)
    - Rule API operations and metadata management

11. **`src/Core/Events/UniversalEventProcessor.php`** ❌ PENDING (2 occurrences)
    - Event processing and rule data access

#### Pro Plugin Files (Minimal Impact) ❌ PENDING

12. **`src/CLI/DebugCommand.php`** ❌ PENDING (2 occurrences)
    - Rule debugging and analysis (rule posts, not order posts)

13. **`src/CLI/Exporters/RuleExporter.php`** ❌ PENDING (2 occurrences)
    - Rule export functionality (rule posts, not order posts)

### 4. Migration Code Examples

#### Before (Current Implementation)
```php
// Status tracking
update_post_meta($order_id, '_odcm_last_status_processed', $payload);
$last_status = get_post_meta($order_id, '_odcm_last_status_processed', true);

// Process ID management
$existing_id = get_post_meta($order_id, '_odcm_active_process_id', true);
update_post_meta($order_id, '_odcm_active_process_id', $process_id);

// Checkout data queuing
update_post_meta($order_id, '_odcm_checkout_queue_id', $queue_id);
update_post_meta($order_id, '_odcm_checkout_data_queued', '1');
```

#### After (With OrderMetaManager)
```php
// Status tracking
OrderMetaManager::update_meta($order_id, '_odcm_last_status_processed', $payload);
$last_status = OrderMetaManager::get_meta($order_id, '_odcm_last_status_processed');

// Process ID management
$existing_id = OrderMetaManager::get_meta($order_id, '_odcm_active_process_id');
OrderMetaManager::update_meta($order_id, '_odcm_active_process_id', $process_id);

// Checkout data queuing
OrderMetaManager::update_meta($order_id, '_odcm_checkout_queue_id', $queue_id);
OrderMetaManager::update_meta($order_id, '_odcm_checkout_data_queued', '1');
```

### 5. Performance Considerations

#### Order Object Caching
```php
private static $order_cache = [];

private static function get_order_cached($order_id): ?WC_Order {
    if (!isset(self::$order_cache[$order_id])) {
        self::$order_cache[$order_id] = wc_get_order($order_id);
    }
    return self::$order_cache[$order_id];
}
```

#### Batch Operations
For operations affecting multiple metadata keys on the same order:
- Cache the order object
- Perform all metadata operations
- Save once at the end (HPOS mode)

#### Memory Management
- Clear order cache periodically
- Use weak references for long-running processes

### 6. Error Handling and Fallbacks

#### Graceful Degradation
```php
public static function update_meta($order_id, $key, $value): bool {
    try {
        if (self::is_hpos_enabled()) {
            $order = self::get_order_cached($order_id);
            if (!$order) {
                error_log("OrderMetaManager: Could not load order #{$order_id}");
                return false;
            }
            $order->update_meta_data($key, $value);
            $order->save();
            return true;
        } else {
            return update_post_meta($order_id, $key, $value) !== false;
        }
    } catch (\Throwable $e) {
        error_log("OrderMetaManager: Failed to update meta for order #{$order_id}: " . $e->getMessage());
        return false;
    }
}
```

#### Fallback Strategy
- Log all metadata operation failures
- Provide admin notices for critical failures
- Implement retry mechanisms for transient failures

### 7. Testing Strategy

#### Unit Tests
- Test detection logic with mocked HPOS states
- Test metadata operations in both modes
- Test error handling and edge cases

#### Integration Tests
- Full plugin functionality with HPOS enabled
- Full plugin functionality with legacy posts
- Migration scenarios (legacy → HPOS)

#### Manual Testing
- Install on fresh WooCommerce (HPOS enabled)
- Install on legacy WooCommerce (HPOS disabled)
- Test order processing workflows
- Test rule creation and execution
- Test diagnostic tools

### 8. Implementation Order

#### Step 1: Foundation ✅ COMPLETED
- ✅ Create OrderMetaManager class (`src/Includes/Utils/OrderMetaManager.php`)
- ✅ Add HPOS compatibility declaration to main plugin file

#### Step 2: Core Order Processing (Priority 1) ✅ COMPLETED
- ✅ Update `src/Core/Core.php` (23 order metadata calls updated)
- ✅ Update `src/Core/ProcessIdManager.php` (6 metadata calls updated) 
- ✅ Update `src/Core/ManualStatusTracker.php` (2 metadata calls updated)
- ✅ Update `src/Core/BlockCheckoutCompatibility.php` (6 metadata calls updated)

#### Step 3: Diagnostic and Utility Files (Priority 2) ✅ COMPLETED
- ✅ Update `src/Core/RefundDeletionDiagnostics.php` (1 order metadata call updated)
- ✅ Update `src/Includes/actions.php` (3 metadata calls updated)
- ✅ Update `src/Includes/functions.php` (HPOS compatibility added)

#### Step 4: Rule Management (Priority 3) ❌ PENDING
- ❌ Update `src/Core/RuleComponents/RuleIndexBuilder.php` (12 occurrences)
- ❌ Update `src/Admin/RuleBuilder.php` (2 occurrences)
- ❌ Update `src/API/RuleBuilderApiController.php` (4 occurrences)
- ❌ Update `src/Core/Events/UniversalEventProcessor.php` (2 occurrences)

#### Step 5: Pro Plugin Files ❌ PENDING
- ❌ Update `../order-daemon-pro/src/CLI/DebugCommand.php` (2 occurrences)
- ❌ Update `../order-daemon-pro/src/CLI/Exporters/RuleExporter.php` (2 occurrences)

#### Step 6: Testing and Validation ❌ PENDING
- ❌ Test core functionality with legacy system
- ❌ Test core functionality with HPOS enabled
- ❌ Verify no breaking changes

### 9. Documentation Requirements

#### User Documentation
- HPOS compatibility announcement
- Migration guide for existing users
- Troubleshooting guide

#### Developer Documentation
- OrderMetaManager API reference
- Migration guide for custom integrations
- Best practices for order metadata

#### Support Documentation
- Common issues and resolutions
- Debugging procedures
- Performance optimization tips

### 10. Risk Mitigation

#### Data Safety
- Always test metadata operations in development
- Provide data export tools before migration
- Implement rollback procedures

#### Performance Impact
- Monitor memory usage during development
- Benchmark critical operations
- Implement performance safeguards

#### Compatibility Issues
- Maintain extensive test coverage
- Test with popular plugin combinations
- Provide compatibility warnings for known issues

## Success Criteria

1. **Functionality**: All plugin features work identically on both HPOS and legacy systems
2. **Performance**: No significant performance degradation in either mode
3. **Compatibility**: Zero breaking changes for existing installations
4. **Reliability**: Robust error handling prevents data loss or corruption
5. **Maintenance**: Clean, maintainable code that's easy to extend

## Implementation Progress

**COMPLETED STEPS:**

1. ✅ **Foundation**: Created OrderMetaManager abstraction layer and HPOS compatibility declaration
2. ✅ **Critical Files**: Updated all core order processing files that handle metadata (Priority 1)
3. ✅ **Supporting Files**: Updated all diagnostic and utility files (Priority 2)

**REMAINING STEPS:**

4. ❌ **Rule System**: Update rule management and API files (Priority 3)
5. ❌ **Pro Plugin**: Update minimal pro plugin occurrences
6. ❌ **Validation**: Test both legacy and HPOS systems

**COMPLETION STATUS:** 69% Complete (9 of 13 planned files)
- ✅ **Critical order processing functionality**: Fully HPOS-compatible
- ❌ **Rule management functionality**: Pending HPOS updates
- ❌ **Developer/CLI tools**: Pending HPOS updates

## Post-Implementation

### Monitoring
- Track adoption rates of HPOS vs legacy
- Monitor performance metrics
- Collect user feedback

### Future Enhancements
- Consider deprecating legacy support in future versions
- Optimize specifically for HPOS performance
- Extend abstraction for other WooCommerce storage changes
