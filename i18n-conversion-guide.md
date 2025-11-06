# Order Daemon - Internationalization Conversion Guide

## Task Overview
Convert all direct English msgids in the codebase to structured hierarchical keys to maintain consistency with the existing internationalization system.

## Current State Analysis

### Two msgid Systems Identified
The current `.pot` file shows two different internationalization patterns:

#### ✅ Structured Keys (Target System)
```
msgid "audit.logs.render.error.invalid_log_ids_provided"
msgstr "Invalid log IDs provided"

msgid "audit.logs.delete.success.single" 
msgid_plural "audit.logs.delete.success.plural"
msgstr[0] "Successfully deleted %d log entry"
msgstr[1] "Successfully deleted %d log entries"

msgid "status.success"
msgstr "Success"

msgid "status.error"
msgstr "Error"
```

#### ❌ Direct English Keys (Need Conversion)
```
msgid "No timeline data available"
msgstr ""

msgid "Invalid process ID"
msgstr ""

msgid "Failed to fetch process logs"
msgstr ""
```

## Conversion Strategy

### Hierarchical Key Structure
Follow these patterns for consistent organization:

| Category | Pattern | Examples |
|----------|---------|----------|
| Audit/Logs | `audit.logs.*` | `audit.logs.timeline.empty`, `audit.logs.process.invalid_id` |
| Status Labels | `status.*` | `status.success`, `status.error`, `status.warning` |
| Admin UI | `admin.*` | `admin.rules.create`, `admin.dashboard.filters` |
| API Messages | `api.*` | `api.validation.failed`, `api.permission.denied` |
| Rule Builder | `rules.*` | `rules.condition.invalid`, `rules.action.missing` |

### File-by-File Conversion Plan

#### Priority Files (Start Here)
1. **src/API/AuditLogEndpoint.php** - High concentration of direct English strings
2. **src/API/RuleBuilderApiController.php** - API error messages
3. **src/Admin/InsightDashboard.php** - Admin interface strings
4. **src/Admin/RuleBuilder.php** - Rule builder interface

## Current Direct English msgids Requiring Conversion

### From AuditLogEndpoint.php
```php
// Current → Structured Key
"No timeline data available" → "audit.logs.timeline.empty"
"Invalid process ID" → "audit.logs.process.invalid_id"
"Failed to fetch process logs" → "audit.logs.process.fetch_failure"
"No events found for this process" → "audit.logs.process.no_events"
"All process events filtered (debug mode disabled)" → "audit.logs.process.debug_filtered"
"No process components available" → "audit.logs.process.no_components"
"Error rendering process timeline" → "audit.logs.process.render_error"
```

### From RuleBuilderApiController.php
```php
"Unique identifier for the rule." → "rules.field.unique_identifier"
"Data source to search (products, categories, posts, etc.)" → "rules.field.data_source"
"Search term" → "rules.field.search_term"
"Maximum number of results" → "rules.field.max_results"
"Failed to load rule builder components" → "rules.error.components_load_failed"
"Invalid completion rule ID." → "rules.error.invalid_rule_id"
"Invalid or empty rule data provided." → "rules.error.invalid_rule_data"
"Rule saved successfully." → "rules.success.saved"
```

## Implementation Steps

### Step 1: Identify All Direct English msgids
```bash
# Extract direct English msgids from .pot file
grep -A1 'src/' languages/order-daemon.pot | grep 'msgid "' | grep -v '\.' 
```

### Step 2: Create Structured Key Mappings
For each direct English msgid:
1. Identify the functional area (audit, rules, admin, api, etc.)
2. Determine the component/action context
3. Create hierarchical key: `area.component.action`
4. Ensure consistency with existing structured keys

### Step 3: Update Code Files
For each PHP file containing direct English msgids:
1. Search for `__('Direct English String', 'order-daemon')`
2. Replace with `__('structured.key', 'order-daemon')`
3. Update any `_n()`, `_x()`, or other translation function variants
4. Test to ensure no syntax errors

### Step 4: Document Changes
- Keep detailed log of all conversions made
- Note any edge cases or complex translations
- Update this guide with completed files

## Code Conversion Examples

### Before (Direct English)
```php
return new WP_Error(
    'odcm_invalid_process_id',
    __('Invalid process ID', 'order-daemon'),
    ['status' => 400]
);
```

### After (Structured Key)
```php
return new WP_Error(
    'odcm_invalid_process_id',
    __('audit.logs.process.invalid_id', 'order-daemon'),
    ['status' => 400]
);
```

## Quality Control Checklist

For each file conversion:
- [ ] All `__()` function calls use structured keys
- [ ] No direct English strings remain in msgids
- [ ] Pluralization `_n()` calls properly structured
- [ ] Context `_x()` calls maintain hierarchical pattern
- [ ] File syntax remains valid (no missing quotes, etc.)
- [ ] Translation domain 'order-daemon' preserved

## Progress Tracking

### Completed Files
- [ ] src/API/AuditLogEndpoint.php
- [ ] src/API/RuleBuilderApiController.php
- [ ] src/Admin/InsightDashboard.php
- [ ] src/Admin/RuleBuilder.php
- [ ] src/Admin/Admin.php
- [ ] src/Admin/CompletionRulesListTable.php
- [ ] src/Core/LogRegistries.php
- [ ] src/Core/RuleComponents/RuleConditions/ProductTypeCondition.php
- [ ] src/Core/RuleComponents/RuleConditions/ProductCategoryCondition.php
- [ ] src/Core/RuleComponents/RuleConditions/OrderTotalAmountCondition.php
- [ ] src/Core/RuleComponents/RuleActions/CompleteOrderAction.php
- [ ] src/Core/RuleComponents/RuleTriggers/OrderProcessingTrigger.php

### Files with Minimal/No Changes Needed
These files already follow structured key patterns or have minimal direct English strings.

## Post-Conversion Tasks

**After all code conversions are complete:**
1. Another developer will regenerate the .pot file
2. Verify all structured keys appear correctly in new .pot
3. Update translation files (.po files) if they exist
4. Test plugin functionality to ensure no broken translations

## Notes for Future Sessions

### Quick Start Command
```bash
# Search for files with direct English msgids
grep -r "__('[^']*[A-Z]" src/ --include="*.php" | grep -v '\.'
```

### Key Patterns to Remember
- Use lowercase with dots: `audit.logs.timeline.empty`
- Keep hierarchy consistent: `module.component.action`
- Follow existing patterns in .pot file
- Maintain WordPress translation function syntax

### Common Pitfalls
- Don't change the 'order-daemon' text domain
- Preserve sprintf placeholders (%s, %d, etc.)
- Maintain plural forms structure for `_n()` calls
- Keep translator comments when present
