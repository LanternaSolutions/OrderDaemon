# Detailed Plan: Fixing Log Stream Consolidated Events

## 1. Introduction

This document outlines the plan to address several issues with the rendering of consolidated log stream events in the Order Daemon Insight Dashboard. The goal is to improve the relevance, accuracy, and clarity of the information presented to users.

## 2. Problem Analysis

Based on the code review of `src/API/AuditLogEndpoint.php`, `src/Core/ProcessLifecycleDiscovery.php`, and other related files, the core issues have been identified:

1.  **Irrelevant Summary Text:** The `create_process_summary` method in `AuditLogEndpoint.php` currently prioritizes payment gateway information over rule evaluation details, which is contrary to the dashboard's primary purpose.
2.  **Incorrect Event Count:** The discrepancy in the event count when toggling "include debug logs" stems from a flaw in how debug events are filtered and counted within the `apply_process_id_consolidation` method. It seems that more than just the debug logs are being filtered.
3.  **Unhelpful Metadata:** The UI displays raw, unformatted metadata like "rule_evaluation_non_canonical logger" and "Timeline". These labels originate from the raw log data and are not being transformed into user-friendly information.
4.  **Redundant Labels:** The "logger" and "Timeline" labels provide no real value to the end-user and clutter the interface.
5.  **Opportunity for Better User Information:** There's a clear opportunity to provide more useful information to merchants and developers.

## 3. Proposed Solutions

### 3.1. Refactor `create_process_summary`

The logic in `create_process_summary` within `src/API/AuditLogEndpoint.php` will be refactored to prioritize rule-related events.

-   **Current Logic:** `has_error` -> `has_completion` (with payment gateway info) -> `final_status` -> generic "lifecycle processing".
  -   **Proposed Logic:** `has_error` -> `has_rule_evaluation` -> `has_payment_event` -> `final_order_status_in_timeline` -> generic "Order lifecycle processing".

This change will ensure that if a rule evaluation occurred, it will be the centerpiece of the summary.

### 3.2. Correct Event Count Logic

To fix the event count, the filtering logic when `include_debug` is `false` needs to be addressed. The problem likely lies in the `apply_filters_to_query` method in `AuditLogEndpoint.php`.

The current filtering seems too broad:
```php
if (!$request->get_param('include_debug')) {
    $where_conditions[] = "(l.status != 'debug')";
    $where_conditions[] = "(l.details IS NULL OR (l.details NOT LIKE %s AND l.details NOT LIKE %s AND l.details NOT LIKE %s))";
    $where_values[] = '%"level":"debug"%';
    $where_values[] = '%"event_type":"debug%';
    $where_values[] = '%"source":"debug%';
}
```
This will be corrected to specifically target only logs with a status of 'debug'.

Additionally, the event count in the summary string itself will be calculated *after* filtering for debug logs within the `apply_process_id_consolidation` function.

### 3.3. Improve Metadata Display

The unhelpful metadata will be addressed at the source.

-   **"logger":** This seems to be part of the `source` field in the log data. We will investigate where this is being added and either remove it or replace it with more meaningful information. It may involve changes to how the `source` is displayed in the UI.
-   **"rule_evaluation_non_canonical":** This is an `event_type`. We will create a mapping from these technical event types to more human-readable labels for display in the UI. This can be done in the frontend JavaScript that renders the log stream or in the PHP that prepares the data. A PHP-side solution in `format_logs_for_api` is preferable for consistency.
-   **"Timeline":** This label will be removed.

## 4. Implementation Steps

1.  **Modify `src/API/AuditLogEndpoint.php`:**
    -   In `create_process_summary`:
        -   Add logic to detect rule evaluation events.
        -   Change the priority of summary parts to show rule information first.
    -   In `apply_process_id_consolidation`:
        -   When `include_debug` is false, correctly filter out *only* the debug logs from the `$process_logs` array *before* generating the summary and the event count.
    -   In `apply_filters_to_query`:
        -   Refine the SQL `WHERE` clause for `include_debug` to be more specific.
    -   In `format_logs_for_api`:
        -   Transform technical `event_type` and `source` values into user-friendly strings.
        -   Keep `event_type` for non-consolidated logs.
        -   Remove `logger` and `Timeline` metadata.

2.  **Execute the plan by modifying the specified files.**

## 5. Testing and Verification

After implementation, the following will be tested:

1.  Consolidated log entries with rule evaluations will show rule-centric summaries.
2.  The `(x events)` count will be accurate both when "include debug logs" is checked and unchecked.
3.  The UI will no longer show "logger" or "Timeline" labels in the metadata section.
4.  The `event_type` will be displayed for non-consolidated events and done in a user-friendly format for consolidated events.

This plan provides a clear path to resolving the identified issues and improving the user experience of the Insight Dashboard.
