# Renderer System Debug & Fix Summary

## Root Cause Analysis

The primary issue with the renderer system is a **mismatch between event_type values in the database and component IDs in the PayloadComponentRegistry**. 

### Event Types Used in Code
From the codebase, I found these actual event types being logged:

1. **Rule events:**
   - `rule_matched` (in UniversalEventProcessor.php)
   - `rule_no_match` (in UniversalEventProcessor.php)
   - `rule_execution` (legacy/in database)

2. **Condition events:**
   - `condition_passed` (in Evaluator.php)
   - `condition_failed` (in Evaluator.php)

### Component IDs in Registry
From PayloadComponentRegistry.php, we have these defined component IDs:

1. **Rule parent component:**
   - `rule_evaluation` (parent component)

2. **Rule child components:**
   - `rule_evaluated` (with aliases: `rule_matched`, `rule_check`, etc.)
   - `decision` (with alias: `rule_evaluation`)

3. **Condition components:**
   - `condition_passed` (with alias: `rule_evaluation`)
   - `condition_failed` (with aliases: `rule_no_match`, `condition_not_met`)

### The Problem

When the timeline renderer gets an event with `event_type="rule_execution"` or `event_type="rule_matched"`:

1. It passes this exact string to `renderTimelineItem()` in PayloadComponentRenderer
2. PayloadComponentRenderer sets `$this->overrideComponentId = "rule_execution"`
3. The CSS class becomes `odcm-component--ruleexecution` (wrong)
4. The renderer can't find `rule_execution` in the registry

## The Fix: Event Type Normalization

The fix should be implemented in `RegistryTimelineRenderer.php` to normalize event types before passing them to `renderTimelineItem()`:

```php
// Before:
$event_type = $component['event_type'] ?? 'info';
$renderer->renderTimelineItem($event_type, $label, $ts, $level, $data);

// After:
$event_type = $component['event_type'] ?? 'info';
$normalized_event_type = $this->normalizeEventType($event_type);
$renderer->renderTimelineItem($normalized_event_type, $label, $ts, $level, $data);
```

### Event Type Mapping

Add a normalization function with these mappings:
- `rule_execution` → `rule_evaluation`
- `rule_matched` → `rule_evaluated`
- `rule_no_match` → `condition_failed` 

## Implementation Plan

1. Add an `normalizeEventType()` method to RegistryTimelineRenderer.php
2. Update the `renderComponent()` method to use the normalized event type
3. Test with real data to verify fix works

This approach preserves all existing code while adding a centralized translation layer for consistent component ID mapping.
