# Order Loaded Event Minimal Removal Plan

## Objective
Remove the `order_loaded` event from the system with the smallest possible code changes to eliminate timeline noise and database entries while minimizing risk of introducing new bugs.

## Current Problem
- `order_loaded` events create separate "Order Loaded" timeline entries
- These events are stored in the database as separate payload entries
- The events are already filtered out in production mode, so they provide no user value
- They create unnecessary noise and database bloat

## Minimal Solution
**Simply stop creating the `order_loaded` components entirely.**

This is the smallest possible change that achieves the objective with minimal surface area for new bugs.

## Implementation Plan

### Step 1: Remove from actions.php
**File**: `src/Includes/actions.php`

**Function**: `odcm_synthesize_checkout_processed_event()`

**Action**: Remove the `order_loaded` component from the `$components` array

**Lines to remove** (around line 1045-1052):
```php
[
    'k' => 'cart_analysis_' . str_replace('.', '_', (string)$checkout_timestamp),
    'event_type' => 'order_loaded',
    'ts' => $checkout_timestamp,
    'label' => 'Cart Analysis',
    'level' => 'info',
    'data' => $checkout_context['cart_analysis'] ?? []
]
```

**Function**: `odcm_synthesize_checkout_from_queued_data()`

**Action**: Remove the `order_loaded` component from the `$components` array

**Lines to remove** (around line 1054-1061):
```php
[
    'k' => 'cart_analysis_' . str_replace('.', '_', (string)$checkout_timestamp),
    'event_type' => 'order_loaded',
    'ts' => $checkout_timestamp, // ORIGINAL timestamp
    'label' => 'Cart Analysis',
    'level' => 'info',
    'data' => $checkout_context['cart_analysis'] ?? []
]
```

### Step 2: Remove from BlockCheckoutCompatibility.php
**File**: `src/Core/BlockCheckoutCompatibility.php`

**Method**: `log_block_checkout_event()`

**Action**: Remove the `order_loaded` component from the `$components` array

**Lines to remove** (around line 345-352):
```php
[
    'k' => odcm_component_key(),
    'event_type' => 'order_loaded',
    'ts' => time(),
    'label' => 'Cart Analysis',
    'level' => 'info',
    'data' => $checkout_context['cart_analysis'] ?? []
]
```

**Method**: `synthesize_block_checkout_event()`

**Action**: Remove the `order_loaded` component from the `$components` array

**Lines to remove** (around line 400-407):
```php
[
    'k' => odcm_component_key(),
    'event_type' => 'order_loaded',
    'ts' => time(),
    'label' => 'Cart Analysis',
    'level' => 'info',
    'data' => $checkout_context['cart_analysis'] ?? []
]
```

## Expected Results

### What Will Change
1. ✅ No more "Order Loaded" timeline entries will be created
2. ✅ No more `order_loaded` payload entries will be stored in the database
3. ✅ Cleaner timeline with less noise
4. ✅ Reduced database storage usage

### What Will Stay the Same
1. ✅ Checkout events will continue to work normally
2. ✅ All other timeline entries remain unchanged
3. ✅ Existing rendering logic is untouched
4. ✅ Filtering logic remains unchanged
5. ✅ No new UI elements or rendering paths are added

## Testing Plan

### Test Cases
1. **Standard Checkout**: Place an order and verify no "Order Loaded" entry appears
2. **Block Checkout**: Place a block checkout order and verify no "Order Loaded" entry appears
3. **Database Verification**: Check that no `order_loaded` payload entries are created
4. **Timeline Display**: Verify checkout events display normally without the cart analysis component
5. **Backward Compatibility**: Ensure existing events continue to display properly

### Verification Commands
```bash
# Check for order_loaded entries in database
wp db query "SELECT * FROM wp_odcm_audit_log_payloads WHERE payload LIKE '%order_loaded%'" --allow-root

# Check timeline entries
wp db query "SELECT * FROM wp_odcm_audit_log WHERE event_type = 'order_loaded'" --allow-root
```

## Rollback Plan

### If Issues Occur
1. **UI Display Issues**: If checkout events don't display properly
2. **Data Loss**: If important information is missing from timeline
3. **Performance Issues**: If removal causes unexpected slowdowns

### Rollback Procedure
Simply revert the changes by adding back the removed component creation code:

```php
// Add this back to the components array in all 4 locations
[
    'k' => 'cart_analysis_' . str_replace('.', '_', (string)$checkout_timestamp),
    'event_type' => 'order_loaded',
    'ts' => $checkout_timestamp,
    'label' => 'Cart Analysis',
    'level' => 'info',
    'data' => $checkout_context['cart_analysis'] ?? []
]
```

## Benefits of This Approach

### Minimal Risk
- Only removing existing code, not adding new logic
- No changes to rendering or filtering systems
- Easy to understand and review
- Simple to test and verify

### Minimal Surface Area
- Only 4 small code sections changed
- No new functions or methods added
- No new rendering paths created
- No database schema changes

### Easy Maintenance
- Clear what was changed and why
- Simple to revert if needed
- No ongoing maintenance burden
- No new dependencies created

## Success Criteria

1. ✅ No "Order Loaded" entries appear in timeline
2. ✅ No `order_loaded` entries in database
3. ✅ Checkout events display normally
4. ✅ No errors or warnings in logs
5. ✅ All existing functionality continues to work

## Conclusion

This minimal approach provides the simplest possible solution to eliminate the `order_loaded` event noise while maintaining all existing functionality and minimizing the risk of introducing new bugs. The changes are small, focused, and easy to understand, test, and maintain.
