# 🎯 TimingCondition Fix Implementation Plan

## 📋 Executive Summary

**Problem**: The TimingCondition component in the Order Daemon Pro plugin is not working correctly in the frontend. Fields are displayed in a long vertical list instead of using conditional rendering, and UI elements don't show/hide properly when radio options are selected.

**Current State**:
- TimingCondition class is fully implemented with proper schema and evaluation logic
- Component is registered in ProComponentLoader.php
- CSS improvements have been added for horizontal layouts
- Debug scripts created but frontend issues persist

**Goal**: Fix the TimingCondition to work properly with conditional field rendering in the rule builder UI.

## 🔍 Root Cause Analysis

### Primary Issues Identified:

1. **Component Registration/Loading**: TimingCondition may not be loading properly in the rule builder context
2. **Conditional Rendering Failure**: Alpine.js conditional groups not working despite proper schema
3. **Template Integration**: Rule builder may not be using the conditional rendering template
4. **CSS/JS Loading Order**: Styles and scripts may not be properly enqueued

### Secondary Issues:

1. **UI Layout**: Fields display vertically instead of logical horizontal grouping
2. **Visual Feedback**: No clear indication of active/inactive field groups
3. **Debugging Difficulty**: Hard to diagnose what's failing in the frontend

## 🎯 Solution Strategy

### Phase 1: Debugging and Diagnosis (Current Location)

**Objective**: Identify exactly why conditional rendering isn't working before making major changes.

#### Step 1: Comprehensive Debugging
```javascript
// Add to rule-builder.js or create debug script
function debugTimingCondition() {
    console.log('=== TimingCondition Debug Session ===');

    // 1. Check component registration
    const components = window.odcmRuleBuilderConfig?.components?.conditions || [];
    const timingComponent = components.find(c => c.id === 'timing_condition');
    console.log('TimingCondition registered:', !!timingComponent);

    if (timingComponent) {
        console.log('Component schema:', timingComponent.schema);

        // 2. Check conditional groups
        const hasConditionalGroups = timingComponent.schema?.properties?.comparison_type?.['ui:conditional_groups'];
        console.log('Has conditional groups:', hasConditionalGroups);

        if (hasConditionalGroups) {
            console.log('Conditional groups:', hasConditionalGroups);
        }
    }

    // 3. Check current rule state
    const currentRule = window.ruleBuilderInstance?.rule;
    console.log('Current rule:', currentRule);

    // 4. Check DOM structure
    setTimeout(() => {
        const fieldGroups = document.querySelectorAll('.odcm-field-group');
        const conditionalContainers = document.querySelectorAll('.odcm-conditional-field-groups');
        console.log('Field groups in DOM:', fieldGroups.length);
        console.log('Conditional containers in DOM:', conditionalContainers.length);
    }, 2000);
}

// Run debug on page load
document.addEventListener('DOMContentLoaded', debugTimingCondition);
```

#### Step 2: Verify Template Rendering
```javascript
// Check if renderConditionalFieldGroups is being called
const originalRenderSettingsForm = window.ruleBuilderInstance?.renderSettingsForm;

window.ruleBuilderInstance.renderSettingsForm = function(schema, currentSettings, componentType, index) {
    console.log('🔍 renderSettingsForm called for:', componentType, index);

    if (schema?.properties?.comparison_type?.['ui:conditional_groups']) {
        console.log('🎯 Conditional groups detected! Using conditional rendering');
    } else {
        console.log('❌ No conditional groups found, using standard rendering');
    }

    return originalRenderSettingsForm?.call(this, schema, currentSettings, componentType, index);
};
```

#### Step 3: Test Alpine.js Reactivity
```javascript
// Test if Alpine.js is working with x-show
function testAlpineReactivity() {
    const testElement = document.createElement('div');
    testElement.innerHTML = `
        <div x-data="{ show: true }">
            <button @click="show = !show">Toggle</button>
            <div x-show="show">Alpine.js is working!</div>
        </div>
    `;
    document.body.appendChild(testElement);

    console.log('Alpine.js test element added to DOM');
}
```

### Phase 2: Fix Implementation (If Debugging Reveals Issues)

#### Option A: Fix in Current Location
**If the issue is template-related or CSS-related:**

1. **Enhance Conditional Rendering Template**:
```javascript
// Improve renderConditionalFieldGroups method
renderConditionalFieldGroups(schema, currentSettings, componentType, index) {
    const comparisonType = currentSettings?.comparison_type || schema.properties.comparison_type.default;
    const conditionalGroups = schema.properties.comparison_type['ui:conditional_groups'];
    let html = '';

    // Create container with proper Alpine.js binding
    html += `<div x-data="{ activeGroup: '${comparisonType}' }" class="odcm-conditional-field-groups">`;

    // Add debug info
    html += `<div class="odcm-debug-info">Active: ${comparisonType}</div>`;

    // Create each field group with proper x-show binding
    Object.entries(conditionalGroups).forEach(([groupKey, fieldKeys]) => {
        const isActive = groupKey === comparisonType;
        html += `<div class="odcm-field-group odcm-field-group-${groupKey}" x-show="activeGroup === '${groupKey}'">`;

        // Add group header
        const groupLabel = schema.properties.comparison_type.enum[groupKey] || groupKey;
        html += `<div class="odcm-field-group-header">${groupLabel}</div>`;

        // Render fields in this group with horizontal layout where appropriate
        html += `<div class="odcm-horizontal-field-group">`;
        fieldKeys.forEach(fieldKey => {
            if (schema.properties[fieldKey]) {
                // Special handling for related fields
                if (['time_period', 'time_unit'].includes(fieldKey) ||
                    ['range_start_date', 'range_end_date'].includes(fieldKey) ||
                    ['time_range_start', 'time_range_end'].includes(fieldKey)) {
                    html += this.renderFormField(fieldKey, schema.properties[fieldKey], currentSettings[fieldKey], componentType, index);
                }
            }
        });
        html += `</div>`;

        html += '</div>';
    });

    html += '</div>';
    return html;
}
```

2. **Add Active Group Tracking**:
```javascript
// Add to settingsPanel component
initConditionalFields(schema, currentSettings) {
    this.fields = {};
    this.activeComparisonType = currentSettings?.comparison_type || schema.properties.comparison_type.default;

    // Watch for comparison type changes
    this.$watch('activeComparisonType', (newVal) => {
        console.log('Comparison type changed to:', newVal);
    });

    // Rest of initialization...
}
```

#### Option B: Move to Free Plugin (If Debugging Shows Registration Issues)

**Step-by-Step Migration Plan:**

1. **Prepare Free Plugin**:
```bash
# Create TimingCondition directory in free plugin
mkdir -p src/Core/RuleComponents/RuleConditions
```

2. **Move and Update TimingCondition Class**:
```php
// Update namespace and file location
namespace OrderDaemon\CompletionManager\Core\RuleComponents\RuleConditions;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
// ... rest of the class remains the same
```

3. **Register in Free Plugin**:
```php
// In free plugin's component registry (e.g., Core.php or ComponentRegistry.php)
public function registerComponents() {
    // ... existing registrations
    $this->registry->register_condition(new TimingCondition());
}
```

4. **Update Pro Plugin**:
```php
// Remove from ProComponentLoader.php
// $registry->register_condition(new \OrderDaemon\CompletionManager\Pro\Core\RuleComponents\RuleConditions\TimingCondition());

// Add Pro-specific enhancements if needed
```

5. **Test Migration**:
- Verify component works in free plugin
- Test all comparison types
- Ensure no regression in other components

### Phase 3: UI Enhancements (After Core Fix)

1. **Horizontal Field Groups**:
```css
/* Already implemented in rule-builder.css */
.odcm-horizontal-field-group {
    display: flex;
    flex-wrap: wrap;
    gap: var(--odcm-theme-spacing-sm);
    align-items: flex-end;
}

.odcm-time-range-group, .odcm-date-range-group {
    display: flex;
    align-items: center;
    gap: var(--odcm-theme-spacing-sm);
}
```

2. **Visual Feedback**:
```css
/* Active/inactive group styling */
.odcm-field-group.odcm-active {
    border-color: var(--odcm-theme-blue-700);
    background-color: var(--odcm-theme-blue-200);
}

.odcm-field-group:not(.odcm-active) {
    opacity: 0.7;
    border-style: dashed;
}
```

## 📋 Implementation Checklist

### 🔍 Current Status (Completed)
- [x] Analyzed TimingCondition.php implementation - ✅ Properly implemented with conditional groups
- [x] Reviewed rule-builder.js conditional rendering - ✅ Logic exists but has reactivity issues
- [x] Examined rule-builder.css horizontal layouts - ✅ CSS exists but not properly applied
- [x] Analyzed debug_timing_condition.js - ✅ Basic debugging present but needs enhancement
- [x] Identified root cause issues - ✅ Alpine.js reactivity and template problems found

### 🔧 Next Steps (In Progress)

### Phase 1: Enhanced Debugging
- [ ] Add comprehensive debug logging to rule-builder.js
- [ ] Create debug functions to monitor Alpine.js reactivity
- [ ] Add template rendering monitoring
- [ ] Enhance debug_timing_condition.js with detailed diagnostics
- [ ] Test debugging output to identify exact failure points

### Phase 2: Fix Conditional Rendering (Option A - Fix in Current Location)
- [ ] Enhance `renderConditionalFieldGroups()` with proper Alpine.js reactive data
- [ ] Add active group tracking using Alpine.js `$watch`
- [ ] Implement horizontal field layouts for related fields (time_period + time_unit, etc.)
- [ ] Add visual feedback for active/inactive field groups
- [ ] Update CSS to ensure horizontal layouts are properly applied

### Phase 3: Testing and Validation
- [ ] Test absolute date comparison functionality
- [ ] Test relative time comparison functionality
- [ ] Test date range comparison functionality
- [ ] Test time of day comparison functionality
- [ ] Verify horizontal layouts work correctly
- [ ] Confirm conditional rendering shows/hides fields properly
- [ ] Check responsive design on different screen sizes
- [ ] Test edge cases and error conditions

## 🎯 Decision Made: Option A - Fix in Current Location

After analysis, the issues can be resolved in the current Pro plugin location. The problems are:
1. **Alpine.js Reactivity**: `x-show` directives use static values instead of reactive data
2. **Template Issues**: Conditional rendering template doesn't use proper Alpine.js data binding
3. **CSS Application**: Horizontal layouts aren't properly applied to conditional groups
4. **Debugging Gaps**: Need better diagnostics to monitor the rendering process

## 📝 Implementation Notes

**Starting with Phase 1: Enhanced Debugging** - This will help identify exactly where the conditional rendering is failing before making major template changes.

**Key Files to Modify:**
- `assets/js/rule-builder.js` - Add debugging and fix conditional rendering
- `assets/css/rule-builder.css` - Ensure horizontal layouts work
- `debug_timing_condition.js` - Enhance with detailed diagnostics

DO NOT BREAK EXISTING FUNCTIONALITY!!!!!!!

## 🔧 Technical Details

### Current File Structure:
```
order-daemon-pro/
├── src/
│   └── Core/
│       └── RuleComponents/
│           └── RuleConditions/
│               └── TimingCondition.php  # Current location
├── assets/
│   └── css/
│       └── rule-builder.css            # Updated with horizontal layouts
```

### Key Files to Modify:
1. `TimingCondition.php` - May need debugging enhancements
2. `rule-builder.js` - Conditional rendering template
3. `ProComponentLoader.php` - Registration debugging
4. `rule-builder.css` - UI layout improvements

### Expected Outcome:
✅ **Conditional Rendering Works**: Fields show/hide properly when comparison type changes
✅ **Horizontal Layout**: Related fields display side-by-side (time period + unit, etc.)
✅ **Visual Feedback**: Clear indication of active/inactive field groups
✅ **Debugging Available**: Comprehensive logs to diagnose any remaining issues
✅ **Responsive Design**: Works on desktop and mobile devices

## 🎯 Decision Tree

**If debugging shows registration issues** → **Move to free plugin (Option B)**
**If debugging shows template issues** → **Fix template rendering (Option A)**
**If debugging shows CSS issues** → **Enhance UI styling**
**If debugging shows Alpine.js issues** → **Fix reactivity and bindings**

## 📝 Notes for Implementation

1. **Start with debugging** - Don't make major changes until you know exactly what's broken
2. **Test incrementally** - Fix one thing at a time and verify it works
3. **Use console logging** - Add generous debug output to understand the flow
4. **Check browser console** - Look for errors or warnings that might indicate issues
5. **Test on clean install** - Sometimes cached assets can cause issues

This plan provides a systematic approach to identify and fix the TimingCondition issues, whether they can be resolved in the current location or require moving to the free plugin.
