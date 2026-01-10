# Migration Plan: Remove Legacy Renderer System

## Overview

This plan removes the legacy renderer system and consolidates on the DisplayAdapter system for timeline rendering. Each stage is designed to be independently completable, testable, and rollback-able.

---

## Architecture Context

### Current Systems (Two Parallel Systems)

| System | Location | Entry Point | Status |
|--------|----------|-------------|--------|
| **NEW Adapter System** | `src/API/Timeline/` | `AdapterRegistry::getAdapterForEvent()` | PRIMARY - keep |
| **LEGACY Renderer System** | `src/View/PayloadRenderer/` | `odcm_get_renderer_for_event_type()` | LEGACY - evaluate |

### Current Timeline Rendering Flow (Before Migration)

```
RegistryTimelineRenderer::renderComponent()
    ↓
AdapterRegistry::getAdapterForEvent($payload)
    ↓
$adapter->extractDisplayData($payload)
    ↓
renderThreeTierComponent($displayData, $payload)
    ↓
[On Exception] → renderFallbackComponent()  ← PROBLEM: Separate HTML template
```

### Target Timeline Rendering Flow (After Migration)

```
RegistryTimelineRenderer::renderComponent()
    ↓
AdapterRegistry::getAdapterForEvent($payload)
    ↓                                              ↓ [On Exception]
$adapter->extractDisplayData($payload)    →    GenericEventAdapter::extractDisplayData()
    ↓                                              ↓
    └──────────────── ALWAYS ──────────────────────┘
                        ↓
          renderThreeTierComponent($displayData, $payload)
                        ↓
              Single HTML rendering path
```

### Files in Scope

**Adapter System (KEEP & ENHANCE):**
- `src/API/Timeline/DisplayAdapter.php` - Base adapter class
- `src/API/Timeline/AdapterRegistry.php` - Adapter routing
- `src/API/Timeline/OrderEventAdapter.php` - Order events
- `src/API/Timeline/PaymentEventAdapter.php` - Payment events
- `src/API/Timeline/RuleExecutionAdapter.php` - Rule events
- `src/API/Timeline/GenericEventAdapter.php` - Fallback adapter
- `src/API/Timeline/RegistryTimelineRenderer.php` - Main renderer

**Legacy System (EVALUATE & REMOVE):**
- `src/Core/PayloadComponentRegistry.php` - Legacy routing functions
- `src/View/PayloadRenderer/BaseRenderer.php` - Legacy base class
- `src/View/PayloadRenderer/OrderRenderer.php` - Legacy order renderer
- `src/View/PayloadRenderer/PaymentRenderer.php` - Legacy payment renderer
- `src/View/PayloadRenderer/RuleRenderer.php` - Legacy rule renderer
- `src/View/PayloadRenderer/SystemRenderer.php` - Legacy system renderer
- `src/View/PayloadRenderer/FallbackRenderer.php` - Legacy fallback
- `src/View/PayloadRenderer/AnalysisRenderer.php` - Legacy analysis renderer

---

## Stage 1: Remove Dead Code

**Priority: Safe - No functional impact expected**

### Prerequisite
- Stage 0 completed and verified

### Dead Code Identified

The following methods in `RegistryTimelineRenderer` are defined but **never called anywhere in the codebase**:

| Method | Line Location | Status |
|--------|--------------|--------|
| `generateOrderEventFallback()` | ~lines 200-240 | ORPHANED - remove |
| `generateEmptyOrderFallback()` | ~lines 242-285 | ORPHANED - remove |
| `formatTimestamp()` | ~lines 287-300 | DEPRECATED - remove |

### Files to Modify

- `src/API/Timeline/RegistryTimelineRenderer.php`

### Tasks

1. [ ] Open `src/API/Timeline/RegistryTimelineRenderer.php`
2. [ ] Locate method `generateOrderEventFallback()` (search for this exact string)
3. [ ] Delete the entire method including its docblock (approximately 40 lines)
4. [ ] Locate method `generateEmptyOrderFallback()` (search for this exact string)
5. [ ] Delete the entire method including its docblock (approximately 45 lines)
6. [ ] Locate method `formatTimestamp()` (search for this exact string)
7. [ ] Delete the entire method including its docblock (approximately 15 lines)

### Success Criteria

- [ ] PHP lint passes on modified file
- [ ] No references to removed methods exist in codebase
- [ ] Timeline rendering still functions (manual test)

### Verification Commands

```bash
# Check for PHP syntax errors
php -l src/API/Timeline/RegistryTimelineRenderer.php

# Verify no remaining references to removed methods
grep -r "generateOrderEventFallback" src/
grep -r "generateEmptyOrderFallback" src/
grep -r "formatTimestamp" src/API/Timeline/

# Should return no results (or only the deleted method definitions if not yet saved)
```

### Rollback
```bash
git checkout src/API/Timeline/RegistryTimelineRenderer.php
```

---

## Stage 2: Consolidate Event Type Configuration

**Priority: Refactor - Reduces duplication, improves maintainability**

### Prerequisite
- Stage 1 completed and verified

### Issue: Duplicate Event Configurations

`getEventTypeConfig()` exists in TWO files with similar but inconsistent mappings:

| File | Method Location |
|------|-----------------|
| `src/API/Timeline/RegistryTimelineRenderer.php` | ~lines 650-850 |
| `src/API/Timeline/DisplayAdapter.php` | ~lines 450-650 |

**Example inconsistency:**
- `RegistryTimelineRenderer`: `order_created` → `dashicons-plus-alt`
- `DisplayAdapter`: `order_created` → `dashicons-cart`

### Strategy

Keep configuration in `DisplayAdapter` (base class accessible to all adapters) and remove from `RegistryTimelineRenderer`.

### Files to Modify

- `src/API/Timeline/RegistryTimelineRenderer.php` - Remove duplicate method
- `src/API/Timeline/DisplayAdapter.php` - Ensure comprehensive config (no changes if already complete)

### Tasks

1. [ ] Open `src/API/Timeline/DisplayAdapter.php`
2. [ ] Review `getEventTypeConfig()` method - ensure it has all event types from both files
3. [ ] Note any event types in `RegistryTimelineRenderer::getEventTypeConfig()` not in `DisplayAdapter`
4. [ ] Add any missing event types to `DisplayAdapter::getEventTypeConfig()` with consistent icons/themes
5. [ ] Open `src/API/Timeline/RegistryTimelineRenderer.php`
6. [ ] Locate `getEventTypeConfig()` method
7. [ ] Delete the entire method including docblock
8. [ ] Find all calls to `$this->getEventTypeConfig()` in `RegistryTimelineRenderer`
9. [ ] Replace with `(new DisplayAdapter())->getEventTypeConfig()` OR refactor to pass adapter to renderer

**Alternative approach (cleaner):**
Instead of instantiating DisplayAdapter, make `getEventTypeConfig()` a static method:

```php
// In DisplayAdapter.php, change method signature:
public static function getEventTypeConfig(string $event_type): array

// In RegistryTimelineRenderer.php, update calls:
$eventConfig = DisplayAdapter::getEventTypeConfig($eventType);
```

### Success Criteria

- [ ] Single source of truth for event type configuration
- [ ] All event types render with correct icons and themes
- [ ] PHP lint passes on both files
- [ ] No duplicate `getEventTypeConfig()` methods exist

### Verification Commands

```bash
# Check syntax
php -l src/API/Timeline/DisplayAdapter.php
php -l src/API/Timeline/RegistryTimelineRenderer.php

# Count occurrences of getEventTypeConfig (should be 1 definition)
grep -r "function getEventTypeConfig" src/API/Timeline/
```

### Rollback
```bash
git checkout src/API/Timeline/RegistryTimelineRenderer.php
git checkout src/API/Timeline/DisplayAdapter.php
```

---

## Stage 3: Consolidate Status Pill Methods

**Priority: Refactor - Further deduplication**

### Prerequisite
- Stage 2 completed and verified

### Issue: Duplicate Status Pill Methods

Similar duplication exists for status pill rendering:

| Method | Files |
|--------|-------|
| `renderStatusPill()` | `RegistryTimelineRenderer.php`, `DisplayAdapter.php` |
| `mapStatusToPillType()` | `RegistryTimelineRenderer.php`, `DisplayAdapter.php` |
| `extractPrimaryStatus()` | `RegistryTimelineRenderer.php`, `DisplayAdapter.php` |

### Files to Modify

- `src/API/Timeline/RegistryTimelineRenderer.php`
- `src/API/Timeline/DisplayAdapter.php`

### Tasks

1. [ ] Verify `DisplayAdapter` has complete implementations of:
   - `renderStatusPill()`
   - `mapStatusToPillType()`
   - `extractPrimaryStatus()`
2. [ ] Make these methods `public static` in `DisplayAdapter` if not already
3. [ ] In `RegistryTimelineRenderer.php`:
   - Delete `renderStatusPill()` method
   - Delete `mapStatusToPillType()` method
   - Delete `extractPrimaryStatus()` method
4. [ ] Update all calls in `RegistryTimelineRenderer` to use `DisplayAdapter::methodName()`

### Success Criteria

- [ ] No duplicate status pill methods
- [ ] Status pills render correctly for all event types
- [ ] PHP lint passes

### Verification Commands

```bash
php -l src/API/Timeline/RegistryTimelineRenderer.php
php -l src/API/Timeline/DisplayAdapter.php

# Count method definitions
grep -c "function renderStatusPill" src/API/Timeline/*.php
grep -c "function mapStatusToPillType" src/API/Timeline/*.php
# Each should return 1
```

### Rollback
```bash
git checkout src/API/Timeline/RegistryTimelineRenderer.php
git checkout src/API/Timeline/DisplayAdapter.php
```

---

## Stage 4: Eliminate Separate Fallback Path

**Priority: Prepare - Consolidates rendering through single pipeline**

### Prerequisite
- Stage 3 completed and verified

### Design Principle

**Do NOT create duplicate HTML templates.** Instead of enhancing `renderFallbackComponent()` with custom HTML, we eliminate the separate fallback path entirely by:

1. Making `GenericEventAdapter` bulletproof (never throws)
2. Using `GenericEventAdapter` as the fallback when other adapters fail
3. ALL rendering flows through `renderThreeTierComponent()` - single source of truth for HTML structure

### Files to Modify

- `src/API/Timeline/GenericEventAdapter.php` - Make exception-safe
- `src/API/Timeline/RegistryTimelineRenderer.php` - Modify fallback logic

### Tasks

#### Task 4.1: Make GenericEventAdapter Exception-Safe

1. [ ] Open `src/API/Timeline/GenericEventAdapter.php`
2. [ ] Wrap `extractSpecializedFields()` in try-catch at the top level
3. [ ] On ANY exception, return minimal valid display data
4. [ ] Add defensive null checks throughout

**Implementation:**
```php
protected function extractSpecializedFields(array &$payload): array
{
    try {
        // ... existing logic ...
    } catch (\Throwable $e) {
        // Log the error but don't throw
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('GenericEventAdapter error: ' . $e->getMessage());
        }
        
        // Return minimal valid data - ensures rendering can continue
        return [
            'event_description' => [
                'label' => $this->translate('Event'),
                'value' => ucwords(str_replace('_', ' ', $payload['event_type'] ?? 'Unknown Event')),
                'section' => 'primary'
            ]
        ];
    }
}
```

#### Task 4.2: Modify Rendering Flow - Use GenericEventAdapter as Absolute Fallback

1. [ ] Open `src/API/Timeline/RegistryTimelineRenderer.php`
2. [ ] Locate `renderComponent()` method
3. [ ] Modify the catch block to use `GenericEventAdapter` directly
4. [ ] Remove the call to `renderFallbackComponent()` entirely

**Implementation:**
```php
private function renderComponent(array $payload, bool $isParent = false, bool $isChild = false, bool $includeDebug = false): string
{
    // ... existing filtering logic ...

    try {
        $adapter = AdapterRegistry::getAdapterForEvent($payload);
        $displayData = $adapter->extractDisplayData($payload);
    } catch (\Throwable $e) {
        // Log the primary adapter failure
        $this->logDebugMessage('Primary adapter failed: ' . $e->getMessage(), 'warning');
        
        // Use GenericEventAdapter as THE fallback - it's guaranteed not to throw
        $adapter = new GenericEventAdapter();
        $displayData = $adapter->extractDisplayData($payload);
    }

    // ALWAYS use the standard rendering pipeline - no exceptions
    $result = $this->renderThreeTierComponent($displayData, $payload);
    return $this->applyHierarchyClasses($result, $isParent, $isChild);
}
```

#### Task 4.3: Delete renderFallbackComponent() Method

1. [ ] Locate `renderFallbackComponent()` method in `RegistryTimelineRenderer.php`
2. [ ] Delete the entire method including its docblock
3. [ ] Verify no other code references this method

### Success Criteria

- [ ] GenericEventAdapter never throws exceptions (verified via testing with malformed payloads)
- [ ] All rendering flows through `renderThreeTierComponent()` - no separate fallback path
- [ ] `renderFallbackComponent()` method has been deleted
- [ ] Unknown event types render correctly via GenericEventAdapter
- [ ] PHP lint passes on all modified files

### Verification Commands

```bash
php -l src/API/Timeline/GenericEventAdapter.php
php -l src/API/Timeline/RegistryTimelineRenderer.php
```

### Rollback
```bash
git checkout src/API/Timeline/GenericEventAdapter.php
git checkout src/API/Timeline/RegistryTimelineRenderer.php
```

---

## Stage 5: Remove Legacy File Loading

**Priority: Clean - Removes unnecessary dependencies**

### Prerequisite
- Stage 4 completed and verified

### Current State

`RegistryTimelineRenderer::ensureRegistryLoaded()` loads legacy files that are no longer used in the timeline rendering path:

```php
private function ensureRegistryLoaded(): void
{
    // Loads PayloadComponentRegistry.php
    // Loads PayloadComponentUIToolkit.php
    // Loads BaseRenderer.php
    // Loads FallbackRenderer.php
    // Loads OrderRenderer.php
}
```

### Analysis Required

Before removing, verify these files are not needed:

1. `PayloadComponentRegistry.php` - Contains `odcm_get_renderer_for_event_type()` - search for usages
2. `PayloadComponentUIToolkit.php` - May be used by DisplayAdapter for timestamp formatting
3. Legacy renderer classes - Confirm not called from timeline rendering

### Files to Modify

- `src/API/Timeline/RegistryTimelineRenderer.php`

### Tasks

1. [ ] Search codebase for usages of `odcm_get_renderer_for_event_type`:
   ```bash
   grep -r "odcm_get_renderer_for_event_type" src/
   ```
2. [ ] Search for usages of `PayloadComponentUIToolkit` outside legacy renderers:
   ```bash
   grep -r "PayloadComponentUIToolkit" src/API/
   ```
3. [ ] If no usages found in timeline code, locate `ensureRegistryLoaded()` method
4. [ ] Remove legacy file loading, keep only what's needed:
   ```php
   private function ensureRegistryLoaded(): void
   {
       // Only load UI toolkit if needed for timestamp formatting
       $renderer_dir = dirname(__DIR__, 2) . '/View/PayloadRenderer/';
       
       if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
           require_once $renderer_dir . 'PayloadComponentUIToolkit.php';
       }
   }
   ```
5. [ ] If `PayloadComponentUIToolkit` is only used by legacy renderers, remove this loading entirely

### Success Criteria

- [ ] No PHP errors when rendering timeline
- [ ] Reduced file loading on timeline render
- [ ] Legacy renderer files not loaded unless needed elsewhere

### Verification Commands

```bash
php -l src/API/Timeline/RegistryTimelineRenderer.php

# Check dependency usage
grep -r "odcm_get_renderer_for_event_type" src/
grep -r "PayloadComponentUIToolkit" src/API/Timeline/
```

### Rollback
```bash
git checkout src/API/Timeline/RegistryTimelineRenderer.php
```

---

## Stage 6: Final Cleanup - Deprecate Legacy System

**Priority: Final - Marks legacy code for future removal**

### Prerequisite
- Stage 5 completed and verified
- All timeline rendering working correctly with adapter system only

### Tasks

1. [ ] Add deprecation notices to legacy files that are still loaded elsewhere:
   
   **In `src/Core/PayloadComponentRegistry.php`:**
   ```php
   /**
    * @deprecated 1.2.1 Use AdapterRegistry::getAdapterForEvent() instead
    */
   function odcm_get_renderer_for_event_type(string $event_type): string
   ```

2. [ ] Add deprecation notices to legacy renderer base class:
   
   **In `src/View/PayloadRenderer/BaseRenderer.php`:**
   ```php
   /**
    * @deprecated 1.2.1 Use DisplayAdapter system instead
    * @see \OrderDaemon\CompletionManager\API\Timeline\DisplayAdapter
    */
   class BaseRenderer
   ```

3. [ ] Document remaining usages of legacy system for future cleanup:
   - `PayloadAnalyzer.php` uses `FallbackRenderer`
   - Any other discovered usages

4. [ ] Create follow-up task to migrate `PayloadAnalyzer` to adapter system

### Success Criteria

- [ ] All legacy methods have `@deprecated` annotations
- [ ] Documentation exists for remaining legacy usages
- [ ] No new code should use legacy system

### Verification Commands

```bash
# Check deprecation notices are in place
grep -r "@deprecated" src/Core/PayloadComponentRegistry.php
grep -r "@deprecated" src/View/PayloadRenderer/BaseRenderer.php
```

### Rollback
```bash
git checkout src/Core/PayloadComponentRegistry.php
git checkout src/View/PayloadRenderer/BaseRenderer.php
```

---

## Testing Checklist

After completing all stages, verify:

### Functional Tests

- [ ] Order events render correctly (status_changed, order_created, etc.)
- [ ] Payment events render correctly (checkout_processed, payment_completed, etc.)
- [ ] Rule execution events render correctly
- [ ] Subscription events render correctly
- [ ] Unknown event types fall back gracefully
- [ ] Malformed payloads don't crash renderer

### Visual Tests

- [ ] Event icons display correctly
- [ ] Status pills show correct colors
- [ ] Timestamps format correctly (client-side)
- [ ] Expandable sections work
- [ ] Parent/child hierarchy styling works

### Edge Cases

- [ ] Empty timeline handles gracefully
- [ ] Very large payloads render without timeout
- [ ] Missing event_type field handled
- [ ] Null/undefined nested fields handled

### Regression Tests

- [ ] Debug mode toggle works (shows/hides debug events)
- [ ] Process grouping still works
- [ ] Timeline pagination works
- [ ] Filter controls work

---

## Summary

| Stage | Risk Level | Estimated Time | Dependencies |
|-------|-----------|----------------|--------------|
| 1: Dead Code | LOW | 30 min | Stage 0 |
| 2: Event Config | MEDIUM | 1 hour | Stage 1 |
| 3: Status Pills | MEDIUM | 45 min | Stage 2 |
| 4: Fallback | MEDIUM | 1.5 hours | Stage 3 |
| 5: Legacy Loading | MEDIUM | 1 hour | Stage 4 |
| 6: Deprecation | LOW | 30 min | Stage 5 |

**Total Estimated Time: 5-6 hours**

**Recommended Approach:**
1. Complete Stage 1 in first session (safe, quick wins)
2. Complete Stages 2-3 in second session (refactoring)
3. Complete Stages 4-6 in third session (cleanup)
4. Final testing in fourth session
