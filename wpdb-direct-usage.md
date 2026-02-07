Based on my analysis of the codebase, I've identified the scope of the issue with direct database queries. There are 83 instances of direct `$wpdb` calls across the codebase, and I need to be strategic about which ones to fix.

The specific warning about line 233 in `src/Includes/Utils/DatabaseHelper.php` is in the `get_all_filtered_logs()` method. However, this method is actually using `$wpdb->get_results()` directly, which contradicts the purpose of the `DatabaseHelper` class.

Here's my refined plan:

## Plan to Fix WordPress Database Warning

### 1. **Fix the specific issue in `DatabaseHelper.php`**
   - Update the `get_all_filtered_logs()` method to use `DatabaseHelper::get_results()` instead of direct `$wpdb->get_results()`
   - This method is ironically not using its own abstraction layer

### 2. **Identify and fix critical database access points**
   - **High Priority**: Methods that directly access the database without using `DatabaseHelper`
     - `src/API/AuditLogEndpoint.php` - Multiple direct calls in diagnostic endpoints
     - `src/Core/LogCleanup.php` - Direct calls for batch operations
     - `src/API/RuleBuilderApiController.php` - Product search functionality
   
   - **Medium Priority**: Methods that could benefit from `DatabaseHelper`'s caching
     - `src/Includes/actions.php` - Queue and event processing
     - `src/Admin/InsightDashboard.php` - Dashboard queries
   
   - **Low Priority**: Legitimate direct queries (schema changes, diagnostics)
     - `src/Includes/Installer.php` - Table creation/modification
     - `src/Diagnostics/` - Diagnostic queries that need direct access

### 3. **Maintain legitimate direct database calls**
   - Some direct calls are justified (e.g., schema changes, diagnostics, transaction control)
   - We'll preserve these while fixing regular data access operations

### 4. **Ensure consistent abstraction**
   - All regular CRUD operations should go through `DatabaseHelper`
   - Preserve direct calls for:
     - Schema modifications (CREATE TABLE, ALTER TABLE)
     - Database diagnostics and health checks
     - Transaction control (BEGIN, COMMIT, ROLLBACK)
     - Complex queries that can't be prepared

### 5. **Implementation approach**
   - Replace direct `$wpdb->get_results()` with `DatabaseHelper::get_results()`
   - Replace direct `$wpdb->get_var()` with `DatabaseHelper::get_var()`
   - Replace direct `$wpdb->get_row()` with `DatabaseHelper::get_row()`
   - Keep transaction control and schema queries as direct calls

The key insight is that the `DatabaseHelper` class exists to provide a consistent layer with caching and error handling, but some parts of the codebase aren't using it, leading to the warnings. By updating these specific calls to use the abstraction layer, we'll resolve the warnings while maintaining performance and functionality.


4 patterns in src/ (*.php)

```
Found 83 results across 3 workspaces.

## Workspace: order-daemon
src/Core/BlockCheckoutCompatibility.php
│----
│            $existing_count = (int) $wpdb->get_var($query);
│
│            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above via $wpdb->prepare()
│----

src/API/AuditLogEndpoint.php
│----
│            $logs = $wpdb->get_results($query, ARRAY_A);
│
│        // Start transaction
│----
│        $wpdb->query('START TRANSACTION');
│
│            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $delete_logs_query is prepared above via $wpdb->prepare()
│----
│            $deleted = $wpdb->query($delete_logs_query);
│
│                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $delete_payloads_query is prepared above via $wpdb->prepare()
│----
│                    $wpdb->query($delete_payloads_query);
│                }
│            // Commit transaction
│----
│            $wpdb->query('COMMIT');
│
│            // Rollback transaction
│----
│            $wpdb->query('ROLLBACK');
│            throw $e;
│
│----
│            $table_check = $wpdb->get_var($wpdb->prepare(
│                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
│            // Direct query is needed for diagnostic purposes
│----
│            $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$audit_table}");
│----
│            $recent_logs = $wpdb->get_var($wpdb->prepare(
│                "SELECT COUNT(*) FROM {$audit_table} WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)"
│            ));
│----
│            $completion_logs = $wpdb->get_var($wpdb->prepare(
│                "SELECT COUNT(*) FROM {$audit_table} WHERE event_type LIKE %s",
│            ));
│----
│            $debug_logs = $wpdb->get_var($wpdb->prepare(
│                "SELECT COUNT(*) FROM {$audit_table} WHERE status = %s OR event_type LIKE %s",
│                // Direct query is needed for diagnostic purposes
│----
│                $sample_logs = $wpdb->get_results($wpdb->prepare(
│                    "SELECT id, timestamp, status, event_type, summary, order_id
│            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $summary_query is prepared above via $wpdb->prepare()
│----
│            $summary_results = $wpdb->get_results($summary_query, ARRAY_A);
│
│                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $total_query is prepared above via $wpdb->prepare()
│----
│                $total = (int) $wpdb->get_var($total_query);
│
│            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $payload_query is prepared above via $wpdb->prepare()
│----
│            $payload_results = $wpdb->get_results($payload_query, ARRAY_A);
│
│            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $total_query is prepared above via $wpdb->prepare()
│----
│            $total = (int) $wpdb->get_var($total_query);
│
│                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
│----
│        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
│                $universal_payloads = $wpdb->get_results("
│                    SELECT DISTINCT p.payload
│----

src/API/Timeline/EnhancedTimelineBuilder.php
│----
│        $result = $wpdb->get_row(
│            $wpdb->prepare(
│        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
│----
│        $results = $wpdb->get_results(
│            $wpdb->prepare(
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
│----

src/Core/Core.php
│----
│            $existing_count = (int) $wpdb->get_var($wpdb->prepare(
│                "SELECT COUNT(*) FROM `%s` WHERE hook = %s AND status IN ('pending', 'in-progress') AND hook_arguments LIKE %s",
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
│----
│        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table with complex JOIN required
│            $job_details = $wpdb->get_results($wpdb->prepare(
│                "SELECT action_id, hook_arguments, status FROM `%s` WHERE hook = %s AND hook_arguments LIKE %s LIMIT 5",
│----

src/API/Timeline/DatabaseTimelineBuilder.php
│----
│        $result = $wpdb->get_row(
│            $wpdb->prepare(
│        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table with complex JOIN required
│----
│        $results = $wpdb->get_results(
│            $wpdb->prepare(
│            // Check if the table exists by querying it directly with a safe query
│----

src/Admin/InsightDashboard.php
│----
│            $table_exists = $wpdb->get_var(
│                $wpdb->prepare(
│            $log_table_escaped = esc_sql($wpdb->prefix . 'odcm_audit_log');
│----
│        // Direct DB query required for custom table with ON DUPLICATE KEY UPDATE
│            $log_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$log_table_escaped}`");
│
│----

src/Core/Events/RuleExecutionDeduplicator.php
│----
│        $result = $wpdb->query($wpdb->prepare(
│            "INSERT INTO {$wpdb->prefix}odcm_audit_log_queue
│        // Get queued item - direct DB query required for custom table
│----
│        $queueItem = $wpdb->get_row($wpdb->prepare(
│            "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue WHERE dedupe_key = %s",
│            // Insert or update in final audit log with deduplication - direct DB query required for custom table
│----
│            $existingEvent = $wpdb->get_row($wpdb->prepare(
│                "SELECT log_id, details FROM {$wpdb->prefix}odcm_audit_log WHERE dedupe_key = %s",
│        // Look for the most recent event of this type for this order - direct DB query required for custom table
│----
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic query with proper caching implementation
│        $result = $wpdb->get_row($wpdb->prepare(
│            "SELECT log_id FROM {$wpdb->prefix}odcm_audit_log
│----

src/Diagnostics/AbstractDiagnostic.php
│----
│            $exists = $wpdb->get_var($wpdb->prepare(
│                "SHOW TABLES LIKE %s",
│        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic query with proper caching implementation
│----
│        $count = (int) $wpdb->get_var($wpdb->prepare(
│            "SELECT COUNT(*) FROM %s",
│        if (false === $pending_count) {
│----

src/Core/Events/RuleExecutionEventUpdater.php
│----
│            $pending_count = $wpdb->get_var($wpdb->prepare(
│                "SELECT COUNT(*) FROM {$wpdb->options}
│        // Check for existing events with this idempotency key in the last 24 hours
│----

src/Core/Events/UniversalEventProcessor.php
│----
│        $existing_count = $wpdb->get_var($wpdb->prepare(
│            "SELECT COUNT(*) FROM `{$audit_log_table}`
│        // Find existing rule execution events for this order+rule combination
│----
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│        $existing_events = $wpdb->get_results($wpdb->prepare(
│            "SELECT l.log_id, l.timestamp, COALESCE(p.payload, l.details) as payload
│----

src/Includes/Installer.php
│----
│            $wpdb->query($sql);
│
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│----
│            $wpdb->query($sql);
│
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│----
│            $wpdb->query($sql);
│        }
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│----
│            $wpdb->query($sql);
│        }
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│----
│            $wpdb->query($sql);
│
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│----
│            $wpdb->query($sql);
│        }
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│----
│            $wpdb->query($sql);
│        }
│            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Table name escaped with esc_sql(), ALTER TABLE cannot use placeholders
│----
│        global $wpdb;
│            $wpdb->query($sql);
│        }
│----

src/Diagnostics/Core/PluginStateDiagnostic.php
│----
│        $db_connection_test = $wpdb->get_var("SELECT 1");
│        $db_connected = $db_connection_test === '1';
│                // and this is a performance diagnostic tool that needs to measure actual database performance
│----

src/Diagnostics/Performance/QueryDiagnostic.php
│----
│                $size_mb = $wpdb->get_var(
│                    $wpdb->prepare(
│                // and this is a performance diagnostic tool that needs to measure actual database performance
│----
│                $results = $wpdb->get_results($prepared_query, 'ARRAY_A');
│                $execution_time = (microtime(true) - $start_time) * 1000;
│                // We can't use $wpdb->prepare() for "SHOW INDEX" with a table name as there's no
│----
│                // placeholder for identifiers, but we can safely use $wpdb->get_results with a properly
│                // validated table name. This is a legitimate use case for direct database calls
│                // Since we can't use prepare() for identifiers, we use esc_sql() to escape the table name
│----
│                // and use $wpdb->get_results() which is the WordPress-approved method for this type of query
│----
│                $query = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM `%s`", $table_name_clean), 'ARRAY_A');
│
│            if (false === $mysql_version) {
│----
│                $mysql_version = $wpdb->get_var($wpdb->prepare("SELECT %s", 'VERSION()'));
│                // Cache for 24 hours - MySQL version rarely changes
│                        // Since SHOW VARIABLES LIKE has specific syntax requirements
│----
│                        $var_value = $wpdb->get_row($wpdb->prepare("SHOW VARIABLES LIKE %s", $var));
│                        $var_value = $var_value ? $var_value->Value : null;
│            $safe_query = $wpdb->prepare($query_template, $cutoff_time);
│----
│            $wpdb->get_var($safe_query);
│            $result['first_execution_ms'] = round((microtime(true) - $start_time) * 1000, 2);
│            $safe_query = $wpdb->prepare($query_template, $cutoff_time);
│----
│            $wpdb->get_var($safe_query);
│            $result['second_execution_ms'] = round((microtime(true) - $start_time) * 1000, 2);
│                // Use properly prepared query
│----
│            $wpdb = self::get_wpdb();
│                $cache_result = $wpdb->get_var($safe_query);
│                wp_cache_set($cache_key, $cache_result, '', 5 * MINUTE_IN_SECONDS);
│----

src/Includes/Utils/DatabaseHelper.php
│----
│            $options = $wpdb->get_results(
│                $wpdb->prepare(
│        // Retrieve queued event from database with caching
│----

src/Includes/actions.php
│----
│        $queue_entry = $wpdb->get_row($wpdb->prepare(
│            "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue
│    if (false === $all_events) {
│----
│        $all_events = $wpdb->get_results($wpdb->prepare(
│            "SELECT log_id, event_type, timestamp, summary FROM {$wpdb->prefix}odcm_audit_log
│    if (false === $result) {
│----
│        $result = $wpdb->get_row($wpdb->prepare(
│            "SELECT log_id, event_type, timestamp FROM {$wpdb->prefix}odcm_audit_log
│        if (false === $result) {
│----
│            $result = $wpdb->get_row($wpdb->prepare(
│                "SELECT log_id, event_type, timestamp FROM {$wpdb->prefix}odcm_audit_log
│        if (false === $similar_events) {
│----
│            $similar_events = $wpdb->get_results($wpdb->prepare(
│                "SELECT log_id, event_type FROM {$wpdb->prefix}odcm_audit_log
│    // Delete processed entries older than 24 hours with proper locking
│----
│    $deleted = $wpdb->query(
│        $wpdb->prepare(
│    // Delete failed entries older than 30 days with proper preparation
│----
│    $deleted_failed = $wpdb->query(
│        $wpdb->prepare(
│        // Retrieve data from queue table with caching
│----
│        $queue_entry = $wpdb->get_row($wpdb->prepare(
│            "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue
│----

## Workspace: order-daemon-pro
src/CLI/Exporters/OptionExporter.php
│----
│        $results = $wpdb->get_results(
│            $wpdb->prepare(
│
│----
│        $results = $wpdb->get_results(
│            $wpdb->prepare(
│        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built with proper placeholders
│----

src/CLI/LogCommand.php
│----
│        $results = $wpdb->get_results(
│            $wpdb->prepare( $sql, $query_args ),
│        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built with proper placeholders
│----
│        $wpdb->query( $wpdb->prepare( $sql, $query_args ) );
│
│            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No dynamic values, query is safe
│----
│            return (int) $wpdb->get_var( $sql );
│        }
│        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built with proper placeholders
│----
│        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $query_args ) );
│    }
│        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built with proper placeholders
│----
│        $wpdb->query( $wpdb->prepare( $sql, $query_args ) );
│
│        $table_name = $wpdb->prefix . 'odcm_audit_log';
│----
│
│        $result = $wpdb->get_row(
│            $wpdb->prepare( "SELECT * FROM {$table_name} WHERE log_id = %d LIMIT 1", $log_id ),
│----

src/CLI/Exporters/RuleExporter.php
│----
│        $count = $wpdb->get_var(
│            $wpdb->prepare(
│
│----
│        $count = $wpdb->get_var(
│            $wpdb->prepare(
│
│----
│        $last_execution = $wpdb->get_var(
│            $wpdb->prepare(
│        // In a real implementation, this would need to be added to the audit log schema
│----
│
│        $avg_time = $wpdb->get_var(
│            $wpdb->prepare(
│----

src/CLI/Exporters/MetadataExporter.php
│----
│        $version = $wpdb->get_var( 'SELECT VERSION()' );
│        return $version ?? 'Unknown';
│
│----
│        $result = $wpdb->get_var(
│            $wpdb->prepare(
│
│----
│            $table_name = $wpdb->prefix . 'odcm_audit_log';
│        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
│        return (int) $count;
│----

src/Admin/InsightDashboardExtensions.php
│----
│            $deleted_count = $wpdb->query(
│                $wpdb->prepare(
│        // Check if table exists
│----
│        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
│            return [
│        // Get total count
│----
│        $total_entries = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
│
│        // Get estimated size (approximate)
│----
│            // Query recent webhook events from audit log
│        $result = $wpdb->get_row("SELECT
│            SUM(LENGTH(log_level) + LENGTH(message) + LENGTH(context) + LENGTH(timestamp) + LENGTH(user_id) + LENGTH(source)) AS estimated_size
│----

src/Admin/WebhookConfiguration.php
│----
│            $results = $wpdb->get_results($wpdb->prepare(
│                "SELECT event_type, context, timestamp
│----
```
