# Insight Dashboard Payload Renderer Debugging Plan

## Problem Summary
In the Order Daemon Core plugin's insight dashboard details pane, all payloads (except `block_checkout_processed`) are rendering as fallbacks instead of using their specialized renderers. The three-tier renderer lookup system is failing to match events to their appropriate renderers.

## System Architecture Overview

### Three-Tier Renderer Lookup System
1. **Tier 1**: Registry lookup using event_type (fast path for exact/alias matches)
2. **Tier 2**: Capability-based lookup using renderer's `canHandle()` methods
3. **Tier 3**: Fallback renderer (guaranteed fallback)

### Key Components
- `PayloadComponentRegistry.php` - Central registry for component definitions
- `RegistryTimelineRenderer.php` - Handles rendering components in the timeline
- `ProcessLoggerComponentExtractor.php` - Extracts components from raw data
- Individual renderer classes in `src/View/PayloadRenderer/`

## Root Cause Analysis

### 1. Namespace Resolution Issue
**Problem**: Renderer class names in registry are not fully qualified namespaces.
- Registry entries use simple names like `'PaymentEventRenderer'`
- Code attempts to add namespace: `'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class`
- Class existence check may fail due to autoloading issues

**Location**: `PayloadComponentRegistry.php:odcm_find_best_renderer_for_data()`

### 2. Component ID Inconsistency
**Problem**: Renderer `getComponentId()` methods don't match registry entries.
- `PaymentEventRenderer::getComponentId()` returns `'payment_event'`
- Registry has entries for `'payment_completed'`, `'payment_failed'`, `'refund_created'`
- No registry entry exists for `'payment_event'`

**Impact**: Tier 1 lookup fails for payment events

### 3. Component Data Structure Issues
**Problem**: Extracted component data may lack fields expected by renderer detection.
- `canHandle()` methods look for specific keys (e.g., payment-related fields)
- Component extraction may not include necessary data structure
- Event types may not be properly set during extraction

### 4. Debug Logging Not Working
**Problem**: Debug logs from `RegistryTimelineRenderer.php` are not appearing.
- `ODCM_DEBUG` may not be properly enabled
- Log destination unclear (WordPress debug.log vs container logs)

## Debugging Strategy

### Phase 1: Enable Comprehensive Debug Logging
**Objective**: Get visibility into the renderer selection process

1. **Verify Debug Configuration**
   - Confirm `ODCM_DEBUG` is set to `true` in wp-config.php
   - Verify debug log location and accessibility
   
2. **Enhance Debug Logging**
   - Add detailed logging in `odcm_find_best_renderer_for_data()`
   - Log Tier 1, Tier 2, and Tier 3 lookup attempts
   - Track class existence checks and instantiation attempts
   - Log component data structure for analysis

3. **Test Debug Output**
   - Trigger timeline rendering
   - Verify debug logs are appearing
   - Document log patterns for different event types

### Phase 2: Fix Namespace Resolution
**Objective**: Ensure renderer classes can be properly loaded

1. **Audit Class Loading**
   - Verify all renderer classes exist in expected locations
   - Test class_exists() checks with full namespaces
   - Check for any autoloading issues

2. **Fix Namespace Handling**
   - Ensure consistent namespace usage in registry lookup
   - Add error handling for class loading failures
   - Implement fallback mechanisms

### Phase 3: Fix Component ID Mapping
**Objective**: Align renderer IDs with registry entries

1. **Audit All Renderers**
   - Check `getComponentId()` return values for all renderers
   - Map to corresponding registry entries
   - Identify mismatches

2. **Fix PaymentEventRenderer First**
   - Either update `getComponentId()` to match existing registry entries
   - Or create unified `'payment_event'` registry entry
   - Choose approach that maintains consistency

3. **Fix Other Mismatched Renderers**
   - Apply same fix pattern to WooCommerceRenderer, etc.
   - Ensure all renderer IDs have corresponding registry entries

### Phase 4: Enhance Capability Detection
**Objective**: Improve Tier 2 fallback when Tier 1 fails

1. **Audit canHandle() Methods**
   - Review logic in each renderer's `canHandle()` method
   - Ensure they check for appropriate data keys
   - Test with actual component data structures

2. **Improve Component Data Structure**
   - Ensure event_type is properly set during extraction
   - Add required fields for renderer detection
   - Test with various payload formats

### Phase 5: Verification and Testing
**Objective**: Confirm all event types render with correct specialized renderers

1. **Test Each Event Type**
   - Payment events (payment_completed, payment_failed, refund_created)
   - Rule evaluation events
   - WooCommerce events (order_loaded, status_changed, etc.)
   - System events (action_scheduled, info, etc.)
   - Error events

2. **Verify Renderer Selection**
   - Confirm Tier 1 lookup works for all expected cases
   - Test Tier 2 fallback for edge cases
   - Ensure Tier 3 fallback only triggers for truly unknown events

## Implementation Checklist

### Phase 1: Debug Logging
- [ ] Verify ODCM_DEBUG configuration
- [ ] Add detailed logging to `odcm_find_best_renderer_for_data()`
- [ ] Add logging to `RegistryTimelineRenderer::renderComponent()`
- [ ] Test debug output with various event types
- [ ] Document debug log patterns

### Phase 2: Namespace Resolution
- [ ] Audit all renderer class locations
- [ ] Test class_exists() with full namespaces
- [ ] Fix namespace handling in registry lookup
- [ ] Add error handling for class loading
- [ ] Test renderer instantiation

### Phase 3: Component ID Mapping
- [ ] Audit all renderer `getComponentId()` methods
- [ ] Fix PaymentEventRenderer ID mapping
- [ ] Fix WooCommerceRenderer ID mapping
- [ ] Fix other renderer ID mappings
- [ ] Update registry entries if needed

### Phase 4: Capability Detection
- [ ] Audit all `canHandle()` method implementations
- [ ] Test with actual component data structures
- [ ] Improve component data extraction if needed
- [ ] Enhance renderer detection logic

### Phase 5: Verification
- [ ] Test payment event rendering
- [ ] Test rule evaluation event rendering
- [ ] Test WooCommerce event rendering
- [ ] Test system event rendering
- [ ] Test error event rendering
- [ ] Verify debug logs show correct renderer selection

## Expected Outcomes

### Success Criteria
1. **Debug Visibility**: Clear logs showing renderer selection process
2. **Payment Events**: payment_completed, payment_failed, refund_created use PaymentEventRenderer
3. **Rule Events**: rule_evaluated, condition_passed/failed use RuleEvaluationRenderer
4. **WooCommerce Events**: order_loaded, status_changed, etc. use WooCommerceRenderer
5. **System Events**: action_scheduled, info, etc. use SystemRenderer
6. **Error Events**: error, warning use ErrorRenderer
7. **Fallback Usage**: FallbackRenderer only for truly unrecognized event types

### Performance Considerations
- Renderer lookup should prefer Tier 1 (registry) for performance
- Tier 2 (capability) should only activate when Tier 1 fails
- Debug logging should be minimal in production

## Notes and References

### Key Files
- `src/Core/PayloadComponentRegistry.php` - Central registry and lookup logic
- `src/API/Timeline/RegistryTimelineRenderer.php` - Timeline rendering controller
- `src/API/Timeline/ProcessLoggerComponentExtractor.php` - Component extraction
- `src/View/PayloadRenderer/PaymentEventRenderer.php` - Payment event renderer
- `src/View/PayloadRenderer/WooCommerceRenderer.php` - WooCommerce renderer

### Working Example
- `block_checkout_processed` events render correctly with WooCommerceRenderer
- Can be used as reference for proper registry/renderer alignment

### Debug Environment
- Docker containers for WordPress environment
- Debug logs in `/var/www/html/wp-content/debug.log` inside WordPress container
- wp-cli available in cron container (docker ps for details)

## Future Improvements

### Registry Enhancement
- Consider adding renderer validation during registry initialization
- Add automatic component ID consistency checks
- Implement renderer performance monitoring

### Testing Framework
- Create automated tests for renderer selection logic
- Add component data structure validation
- Implement regression testing for renderer changes