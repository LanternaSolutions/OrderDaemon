**Summary of the issues reported in `fixes.md`**

The static‑analysis report highlights three main problem areas across the code‑base:

| Category | What the issue is | Why it matters | Typical locations |
|----------|-------------------|----------------|-------------------|
| **Filesystem‑API misuse** | Direct calls to PHP’s `is_writable()` (and similar functions) | WordPress expects all file‑system operations to go through the `WP_Filesystem` abstraction, which handles FTP/SSH/Direct‑FS transparently and respects permissions. Bypassing it can break on servers where direct FS access is disabled. | `src/Includes/functions.php` – lines 1691, 1731 |
| **SQL‑preparation problems** | • Queries built with string interpolation or concatenation (`{$var}`) instead of `$wpdb->prepare()`.<br>• Placeholders missing or unfinished (`$wpdb->prepare()` called but no `%s`, `%d`, etc.). | Unprepared queries expose the plugin to SQL‑injection attacks and make the code harder to read/maintain. WordPress coding standards require the use of `$wpdb->prepare()` with proper placeholders. | `src/Includes/Utils/DatabaseHelper.php`, `src/API/AuditLogEndpoint.php`, `src/API/Timeline/DatabaseTimelineBuilder.php`, `src/Diagnostics/Performance/QueryDiagnostic.php`, `src/Core/LogCleanup.php`, `src/Core/Events/UniversalEventProcessor.php`, `src/Core/AttributionTracker.php`, `src/Core/Core.php`, `src/Admin/Admin.php`, `src/Admin/InsightDashboard.php`, `src/Core/ManualStatusTracker.php`, `src/Core/audit-filters.php`, `src/API/Timeline/GenericEventAdapter.php`, `src/API/Timeline/ProcessLoggerComponentExtractor.php`, `src/Diagnostics/Performance/QueryDiagnostic.php`, `src/Core/LogCleanup.php`, `src/Core/AttributionTracker.php`, `src/Core/Core.php`, `src/Core/ManualStatusTracker.php`, `src/Core/audit-filters.php`, `src/API/Timeline/DatabaseTimelineBuilder.php`, `src/API/Timeline/GenericEventAdapter.php`, `src/API/Timeline/ProcessLoggerComponentExtractor.php`, `src/Diagnostics/Performance/QueryDiagnostic.php` |
| **Debug‑function usage** | Calls to `error_log()` and `debug_backtrace()` scattered throughout many files. | Debug output should not be left in production code; it can leak internal state and affect performance. Use proper logging facilities (`WC_Logger`, `error_log` only behind a debug flag, or remove entirely). | `src/Includes/Utils/DatabaseHelper.php`, `src/API/AuditLogEndpoint.php`, `src/API/Timeline/GenericEventAdapter.php`, `src/API/Timeline/ProcessLoggerComponentExtractor.php`, `src/Core/AttributionTracker.php`, `src/Core/Core.php`, `src/Core/ManualStatusTracker.php`, `src/Core/audit-filters.php`, `src/Admin/Admin.php`, `src/Admin/InsightDashboard.php`, `src/Core/LogCleanup.php`, `src/Diagnostics/Performance/QueryDiagnostic.php`, `src/Core/Events/UniversalEventProcessor.php` |
| **Unsanitized input** | Direct use of `$_SERVER`, `$_REQUEST`, `$_POST` variables without `wp_unslash()` or sanitization functions. | Unsanitized inputs can lead to XSS, SQL injection, or other injection attacks. WordPress recommends unslashing first, then sanitizing (`sanitize_text_field`, `intval`, etc.). | `src/Core/Events/UniversalEventProcessor.php`, `src/Core/Core.php`, `src/Admin/Admin.php`, `src/Admin/InsightDashboard.php`, `src/Core/AttributionTracker.php`, `src/Core/ManualStatusTracker.php`, `src/Core/audit-filters.php` |
| **Missing nonce verification** | Form handling in `ManualStatusTracker.php` processes data without checking a nonce. | Nonces protect against CSRF attacks. Any state‑changing request should verify a nonce. | `src/Core/ManualStatusTracker.php` |
| **Hook‑naming convention** | Dynamic hook names (e.g., `$filter`) are not prefixed with the plugin/theme slug. | Prefixing prevents collisions with other plugins/themes. | `src/Core/AttributionTracker.php` |

**What needs to be fixed**

1. **Replace direct filesystem calls** with the `WP_Filesystem` API (e.g., `$wp_filesystem->is_writable()` after initializing the filesystem object). [COMPLETE] 
2. **Rewrite all raw SQL** to use `$wpdb->prepare()` with proper placeholders (`%s`, `%d`, `%f`). For queries that already use `$wpdb->prepare()` but lack placeholders, add the appropriate ones or restructure the query. [COMPLETE]
3. **Remove or guard debug statements** (`error_log()`, `debug_backtrace()`). If they are needed for development, wrap them in a conditional that checks a debug constant.  
4. **Sanitize and unslash all super‑global inputs** before use. Apply the appropriate sanitization function based on the expected data type.  
5. **Add nonce checks** (`check_admin_referer()` or `wp_verify_nonce()`) to any form‑processing code that currently lacks them.  [COMPLETE]
6. **Prefix dynamic hook names** with a unique plugin identifier (e.g., `odcm_`) to follow WordPress naming conventions. [COMPLETE]

**Next steps (if you wish to proceed with fixes)**  
- Prioritize fixing the SQL preparation issues, as they affect security most critically.  
- Follow up with filesystem API changes and input sanitization.  
- Clean up debug calls and add nonce verification.  
- Finally, address naming conventions for hooks.

If you would like me to generate concrete code changes for any of these items, let me know which files you want to start with, and I can produce the necessary `replace_in_file` or `write_to_file` operations.