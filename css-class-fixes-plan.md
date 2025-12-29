# CSS Class Fixes Implementation Plan

## Overview
This document outlines the detailed plan to fix CSS class issues in the debug mode timeline for the Order Daemon plugin.

## Issues Identified

### 1. Duplicate Class Structure
**Problem**: Component headers have redundant nested structure where `odcm-component__title` contains another `odcm-component__header-left` div.

**Current Structure**:
```html
<div class="odcm-component__title">
    <div class="odcm-component__header-left">
        <span class="odcm-component-icon dashicons dashicons-plus-alt"></span>
        <span class="odcm-component__title">Order Created</span>
    </div>
</div>
```

**Should Be**:
```html
<div class="odcm-component__header-left">
    <span class="odcm-component-icon dashicons dashicons-plus-alt"></span>
    <span class="odcm-component__title">Order Created</span>
</div>
```

### 2. Incorrect Parent/Child Class Application
**Problem**: Some components have both `is-parent` and `is-child` classes applied simultaneously.

**Example**:
```html
<div class="odcm-component odcm-component--payment is-parent is-child">
```

**Fix**: Remove conflicting classes - a component should be either parent OR child, not both.

### 3. Missing Debug Component Classes
**Problem**: The "Rule Processing Started" component should have debug-related classes but doesn't have `odcm-component--debug` class.

**Current**:
```html
<div class="odcm-component odcm-component--rule is-child">
```

**Should Be**:
```html
<div class="odcm-component odcm-component--rule odcm-component--debug is-child">
```

### 4. Inconsistent Status Evaluation Classes
**Problem**: The "_status_evaluation" components use `odcm-component--error` class but these are debug/informational events, not errors.

**Current**:
```html
<div class="odcm-component odcm-component--error">
```

**Should Be**:
```html
<div class="odcm-component odcm-component--debug">
```

### 5. Missing Debug Status Pills
**Problem**: Debug components should have debug status pills (`odcm-status-pill--debug`) but some are missing them.

**Fix**: Add debug status pills to all debug components.

### 6. Incorrect Component Type for Rule Evaluation Non-Canonical
**Problem**: This component uses `odcm-component--rule` but should be completely hidden from end users as it's purely technical.

**Current**:
```html
<div class="odcm-component odcm-component--rule">
```

**Should Be**: Completely hidden from end user view, only available in raw technical data

### 7. Status Evaluation Events Need Better Display
**Problem**: Status evaluation events need proper display configuration to show meaningful information to users.

**Current**: Shows redundant status change information that's already displayed elsewhere

**Should Be**: Clear display showing:
- That a status evaluation occurred
- Which status change was evaluated (for clarity with multiple evaluations)
- Why the evaluation happened (parent event triggered it)
- The purpose of the evaluation (checking if rules should trigger)

**Enhanced Display**:
```
Status Evaluation: Order #103
Evaluated status change: checkout-draft → pending
Triggered by: Parent event status change
Purpose: Checked if rule should trigger
```

### 8. Rule Evaluation Non-Canonical Events Should Be Hidden
**Problem**: These purely technical events provide no value to end users and should be hidden.

**Solution**: Implement clean filtering to hide these events from end users while preserving them for debugging purposes.

## Implementation Steps

### Step 1: Create Debug Component CSS Class
- Use existing `odcm-component--debug` theme in `insight-dashboard.css`
- Define appropriate theme for debug components
- Use existing debug status pill styling

### Step 2: Fix Duplicate Class Structure
- Update PHP template generation in `DisplayAdapter.php`
- Modify the header rendering method to remove redundant nesting
- Ensure all component headers follow the correct structure

### Step 3: Fix Parent/Child Class Conflicts
- Update hierarchy logic in `AdapterRegistry.php`
- Fix the allocation logic of the classes to prevent both parent and child classes and ensure the parents get the parent class and the children get the child class
- Clean up existing components with conflicting classes, rule evaluation for sure

### Step 4: Add Debug Component Classes
- Update event type configuration in `DisplayAdapter.php`
- Add `odcm-component--debug` class to appropriate event types
- Ensure debug events are properly categorized

### Step 5: Fix Status Evaluation Classes
- Update `_status_evaluation` event configuration
- Change from `odcm-component--error` to `odcm-component--debug`
- Update any related logic that depends on error class

### Step 6: Add Debug Status Pills
- Update status pill rendering logic in `DisplayAdapter.php`
- Add `odcm-status-pill--debug` to debug components
- Ensure status pills are displayed consistently

### Step 7: Fix Rule Evaluation Non-Canonical Component Type
- Update event type configuration for `rule_evaluation_non_canonical`
- Change from `odcm-component--rule` to `odcm-component--debug`
- Update any related display logic

### Step 8: Improve Display Titles
- Update title generation for debug events
- Make titles more user-friendly and descriptive
- Ensure titles explain the purpose of debug events

## Files to Modify

1. **assets/css/insight-dashboard.css**
   - Add debug component styling
   - Use existing `odcm-component--debug` theme variables
   - Add debug status pill styling

2. **src/API/Timeline/DisplayAdapter.php**
   - Update event type configurations
   - Fix class generation logic
   - Add debug component support

3. **src/API/Timeline/RuleExecutionAdapter.php**
   - Fix duplicate class structure in rule components
   - Update debug event handling

4. **src/API/Timeline/AdapterRegistry.php**
   - Fix parent/child class conflicts
   - Update event type detection

## Testing Plan

1. **Visual Inspection**: Verify CSS classes are applied correctly
2. **Functional Testing**: Ensure all components display properly
3. **Debug Mode Testing**: Confirm debug events show appropriate styling
4. **Regression Testing**: Verify existing functionality still works
5. **Cross-browser Testing**: Check compatibility across browsers

## Expected Outcome

After implementing these fixes, the debug mode timeline will:
- Have proper CSS class structure without redundancy
- Display correct parent/child relationships
- Show appropriate debug styling for debug events
- Provide clear, user-friendly information
- Maintain consistent styling across all components
- Improve overall readability and usability

## Timeline

1. **Phase 1**: CSS Updates (30 minutes)
2. **Phase 2**: PHP Logic Updates (60 minutes)
3. **Phase 3**: Testing and Validation (30 minutes)
4. **Phase 4**: Final Review and Documentation (15 minutes)

## Success Criteria

- All CSS class issues are resolved
- Debug components display with proper styling
- No duplicate or conflicting classes
- Improved user experience in debug mode
- All existing functionality preserved
- keep in mind that ALL of the css themes for debug that you need already exist in insight-dashboard.css. You must simply apply them correctly
- make the debug events display text more clearly for end users, ensure that the text displayed is concise

