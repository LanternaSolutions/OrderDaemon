# English-Based Translations That Need Converting

## Overview
These translation calls are using English text instead of structured keys and need to be converted to match your key-based i18n system.

## Files Requiring Conversion

### src/Core/DashboardComponentRegistry.php
**Problem:** Uses English text as msgid instead of keys

| Current English Text | Suggested Key | Line Context |
|---------------------|---------------|--------------|
| `'Unified Header'` | `'core.dashboard.component.unified_header'` | Component label |
| `'Filter Pane'` | `'core.dashboard.component.filter_pane'` | Component label |
| `'Log Stream'` | `'core.dashboard.component.log_stream'` | Component label |
| `'Detail Pane'` | `'core.dashboard.component.detail_pane'` | Component label |
| `'Filters Tab'` | `'core.dashboard.component.filters_tab'` | Component label |
| `'Settings Tab'` | `'core.dashboard.component.settings_tab'` | Component label |
| `'Welcome State'` | `'core.dashboard.component.welcome_state'` | Component label |
| `'Empty State'` | `'core.dashboard.component.empty_state'` | Component label |
| `'Pagination'` | `'core.dashboard.component.pagination'` | Component label |

### src/Core/ProcessLifecycleDiscovery.php
**Problem:** Uses English text as msgid instead of keys

| Current English Text | Suggested Key | Line Context |
|---------------------|---------------|--------------|
| `'Order Processing'` | `'core.process.lifecycle.order_processing'` | Process group label |
| `'Payment Gateway Events'` | `'core.process.lifecycle.payment_gateway_events'` | Process group label |
| `'Subscription Lifecycle'` | `'core.process.lifecycle.subscription_lifecycle'` | Process group label |

### src/Core/PremiumComponentFallback.php
**Problem:** Uses direct English text in _n() function

| Current Code | Suggested Key | Line Context |
|-------------|---------------|--------------|
| `_n('Warning: %d completion rule uses premium components...', 'Warning: %d completion rules use premium components...', $count, 'order-daemon')` | `_n('core.premium.warning.single', 'core.premium.warning.plural', $count, 'order-daemon')` | Premium warning message |
| `__('These rules will not function until the pro plugin is activated...', 'order-daemon')` | `__('core.premium.rules_disabled_message', 'order-daemon')` | Premium rules disabled message |

### src/Plugin.php
**Problem:** Uses English text instead of key

| Current English Text | Suggested Key | Line Context |
|---------------------|---------------|--------------|
| `'Order Daemon for WooCommerce requires WooCommerce to be installed and active.'` | `'core.plugin.dependency.woocommerce_required'` | Dependency error message |

### src/Admin/Notices.php
**Problem:** Uses English text instead of keys

| Current English Text | Suggested Key | Line Context |
|---------------------|---------------|--------------|
| `'Order Daemon:'` | `'admin.notices.prefix'` | Notice prefix text |
| `'Dismiss this notice.'` | `'admin.notices.dismiss'` | Screen reader text |
| `'Security check failed.'` | `'admin.notices.error.security_check_failed'` | Security error |
| `'Notice ID is required.'` | `'admin.notices.error.notice_id_required'` | Validation error |
| `'Notice dismissed successfully.'` | `'admin.notices.success.notice_dismissed'` | Success message |
| `'Notice not found.'` | `'admin.notices.error.notice_not_found'` | Error message |

### src/Admin/DiagnosticDashboard.php
**Problem:** Uses English text instead of keys

This file has multiple English-based translations that should be converted:

| Current English Text | Suggested Key | Context |
|---------------------|---------------|---------|
| `'Running diagnostics...'` | `'admin.diagnostics.running'` | Status message |
| `'Diagnostics completed successfully'` | `'admin.diagnostics.completed_successfully'` | Success status |
| `'Error running diagnostics'` | `'admin.diagnostics.error_running'` | Error status |
| Various other diagnostic strings | Need individual key assignments | Multiple contexts |

## Conversion Examples

### Before (English-based):
```php
'label' => __('Unified Header', 'order-daemon'),
```

### After (Key-based):
```php
'label' => __('core.dashboard.component.unified_header', 'order-daemon'),
```

## Next Steps

1. **Update .pot file** - Add the new key-based entries to replace English ones
2. **Update PHP files** - Replace __('English Text', 'order-daemon') with __('structured.key', 'order-daemon')
3. **Update .po files** - Ensure all new keys have proper translations
4. **Test translations** - Verify that key-based system works consistently

## Priority Files

**High Priority** (affecting dashboard functionality):
1. `src/Core/DashboardComponentRegistry.php`
2. `src/Core/ProcessLifecycleDiscovery.php`

**Medium Priority** (affecting admin experience):
1. `src/Admin/Notices.php`
2. `src/Admin/DiagnosticDashboard.php`

**Low Priority** (rarely seen):
1. `src/Core/PremiumComponentFallback.php`
2. `src/Plugin.php`

## Notes

- Your .pot file already contains the correct key-based structure for most strings
- These English-based calls are preventing the translation system from working
- Once converted, your existing .po/.mo files should work correctly
- Some of these keys might already exist in your .pot file and just need the PHP code updated
