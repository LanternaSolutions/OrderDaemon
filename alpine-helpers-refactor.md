## Implementation Plan

### Phase 1: Update AlpineJsSecurity Class
**Action:** Add missing Alpine.js attributes to the allowed HTML list
**Changes needed:**
- Add `x-on:click`, `x-on:change`, `x-on:input` to allowed attributes
- Add `x-bind:value`, `x-bind:class`, `x-bind:disabled` to allowed attributes
- Ensure all other necessary Alpine.js attributes are included

### Phase 2: Refactor JavaScript Files
**Action:** Systematically replace all shorthand codes with full syntax

**For rule-builder.js:**
1. Replace all `@click` with `x-on:click="..."`
2. Replace all `@change` and `@input` with `x-on:change="..."` and `x-on:input="..."`
3. Replace all `:value` with `x-bind:value="..."`
4. Replace all `:class` with `x-bind:class="..."`
5. Replace all `:disabled` with `x-bind:disabled="..."`

**For insight-dashboard.js:**
1. Replace all `@click` with `x-on:click="..."`
2. Replace all `@change` and `@input` with `x-on:change="..."` and `x-on:input="..."`
3. Replace all `:value` with `x-bind:value="..."`
4. Replace all `:class` with `x-bind:class="..."`
5. Replace all `:disabled` with `x-bind:disabled="..."`

### Phase 3: Update HTML Generation
**Action:** Ensure all HTML generation uses helper methods
- Use existing `createAlpineEventBinding()` for event handlers
- Use existing `createAlpineBind()` for property bindings
- Use existing `createAlpineShowAttribute()` for show conditions

## Key Findings

1. **Excellent Foundation**: The codebase already has comprehensive helper methods in `DashboardComponentUIToolkit.php`
2. **Security Ready**: `AlpineJsSecurity.php` already allows all necessary Alpine.js attributes
3. **Consistent Pattern**: The existing code shows a clear, consistent pattern for using helper methods
4. **No New Methods Needed**: All shorthand codes can be handled with existing methods
5. **HTML Generation Method**
The HTML is currently generated using **static strings** in the JavaScript files. The templates are built as JavaScript strings with inline Alpine.js shorthand codes (like `@click`, `:value`, etc.) that are then inserted into the DOM.
6. **Existing Helper Methods**
I found excellent examples in `src/Admin/InsightDashboard.php` showing how helper methods are used:
**Event Bindings:**
```php
<?php echo DashboardComponentUIToolkit::createAlpineEventBinding('click', 'closeFilterPane()'); ?>
```
**Property Bindings:**
```php
<?php echo DashboardComponentUIToolkit::createAlpineBind('class', "{ 'odcm-active': activeFilterTab === 'filters' }"); ?>
```
**Text Bindings:**
```php
<?php echo DashboardComponentUIToolkit::createAlpineTextBinding('i18n.loading'); ?>
```
**Show Attributes:**
```php
<?php echo DashboardComponentUIToolkit::createAlpineShowAttribute('filterPaneVisible'); ?>
```
7. **Unique Cases**
All shorthand codes can be handled with existing helper methods:
- `@click`, `@change`, `@input` → `createAlpineEventBinding()`
- `:value`, `:class`, `:disabled` → `createAlpineBind()`
- `x-show` → `createAlpineShowAttribute()`
- `x-text` → `createAlpineTextBinding()`

The plan is comprehensive and should address all the shorthand code replacements while maintaining security and using the existing helper methods effectively to ensure compliance with wordpress.org security requirements.