# Logging, Diagnostics, and Observability (Core + Pro)

Audience: internal development team
Last updated: 2025-11-15
Scope: code-accurate to Core as of this date.

---

## 1) Overview

Order Daemon emits two kinds of logs:
- Developer/system logs for immediate debugging (PHP error_log and selective debug traces).
- Structured audit/diagnostic logs used by dashboards and REST APIs (via odcm_log_event and ProcessLogger payloads).

The structured layer is the source of truth for the Insight/Audit timelines and for tracing rule evaluation, actions, and security events.

Key classes and functions:
- Core\Logging\ProcessLogger — builds per-process timelines (rule evaluation runs) and writes structured entries.
- odcm_log_event(...) — global helper that persists audit/diagnostic events (see src/Includes/functions.php).
- API\AuditLogEndpoint — REST access to logs and timeline details, including ProcessLogger-aware extraction.
- API\Timeline\ProcessLoggerComponentExtractor — renders ProcessLogger components into the timeline API shape.
- Core\ManualStatusTracker — captures manual status changes for chain-of-custody context.
- Core\Security\GuardChecker — logs security check pass/fail events to the audit stream.

---

## 2) Logging strategy and levels

Two layers:
- PHP-level diagnostics (development-only guidance):
  - Some subsystems log to error_log for tracing, e.g., Plugin::load_text_domain() emits path/locale details to help diagnose i18n loading issues.
  - UniversalEventProcessor contains ODCM_DEBUG_TRACE lines during ProcessLogger lifecycle decisions (canonical vs non-canonical events) to aid debugging. These are safe but should be kept minimal.
- Structured audit logs:
  - Use odcm_log_event(message, details_array, object_id, level, event_type).
  - Levels commonly used: success, info, warning, error. Event types are normalized keys (e.g., security_check_failed, rule_evaluation_summary).
  - Structured entries feed dashboards and the REST API. Prefer this over error_log for anything user-facing or support-relevant.

Guidance:
- Use error_log sparingly and only for short-term diagnostics.
- Prefer odcm_log_event for anything that should be visible in the Audit/Insight dashboards or support exports.

---

## 3) ProcessLogger: evaluation timelines

Location: src/Core/Logging/ProcessLogger.php
Purpose: capture a single processing run (e.g., a rule evaluation for a specific event) with components and a final status.

Key behaviors:
- start(type, context): Opens a new process. Returns process metadata/correlation id handled internally.
- add_component(event_type, label, data, level, key?): Adds a structured component (e.g., condition evaluated, action executed, outcome).
- finish(final_status, summary): Closes the process with a final status such as success, partial, failure. Implements recursion and context guards.
- Universal-event context flag: set_universal_event_context(true) will suppress timeline event creation to avoid duplication when a universal event already drives canonical timeline entries via UniversalEventProcessor.

Engine wiring:
- UniversalEventProcessor creates a ProcessLogger only for canonical events (e.g., order_status_changed) and toggles the universal event context appropriately to avoid duplicate timeline entries.
- Evaluator logs each condition evaluation through ProcessLogger when available (see Core/Evaluator.php).

Diagnostics protections:
- Recursion guard and explicit error_log messages exist to surface misuse (e.g., finish() called twice).
- When the universal event context is active, ProcessLogger will skip creating timeline entries and notes this in diagnostics to avoid confusion.

---

## 4) Structured audit logging via odcm_log_event

Location: src/Includes/functions.php
Signature (summary): odcm_log_event(string $message, array $details = [], $object_id = null, string $level = 'info', string $event_type = 'generic')

Responsibilities:
- Persist a normalized audit/diagnostic event with a message and structured details.
- Support taxonomy of event_type keys and optional object correlation (e.g., order id).
- Provide helper registries of event types (see odcm_get_log_event_types and related registries) for dashboards.

Call sites of interest:
- GuardChecker::check(): logs security_check_passed / security_check_failed with user and request context, execution time, guard details, and stack traces on failure.
- Core\RefundDeletionDiagnostics and other diagnostics components: use odcm_log_event with narrative payloads to record investigative findings without heavy computation.
- UniversalEventProcessor and ProcessLogger: ultimately write timeline entries through odcm_log_event.

Best practices:
- Always include enough context in details for support (ids, statuses, configuration snippets) but avoid PII unless necessary and ensure it’s consistent with privacy policies.
- Use stable event_type keys; add new keys to the registry if exposing them in dashboards.

---

## 5) Audit logs: storage, access, and rendering

Storage:
- Structured events are stored via internal mechanisms behind odcm_log_event (implementation details abstracted in Includes/functions.php and supporting registries).

Access:
- API\AuditLogEndpoint registers REST routes to list, filter, and view event details.
- The endpoint recognizes ProcessLogger entries and legacy formats; it builds a consistent response shape for the Insight dashboard.

Rendering and extraction:
- API\Timeline\ProcessLoggerComponentExtractor detects ProcessLogger-format payloads and converts them into displayable timeline components. Non-ProcessLogger events are represented via synthetic components.
- InsightDashboard consumes these APIs to show a log stream and a detail pane. Advanced filters may require entitlements (see A4).

Performance considerations:
- Pagination and per-page limits are enforced in endpoints; UI persists user choices.
- Avoid writing excessively verbose payloads in hot paths. Summarize and attach references rather than full object dumps.
- Log cleanup routines exist (see Core/LogCleanup usages); schedule or trigger them in maintenance flows as appropriate.

---

## 6) Manual status tracking (chain of custody)

Location: src/Core/ManualStatusTracker.php; initialized in Plugin::initialize_core().
Purpose: capture manual order status changes to attribute sources and provide context in timelines and diagnostics.
Notes:
- Manual status changes enrich ProcessLogger and general audit logs to distinguish automated vs manual updates.
- Ensure ManualStatusTracker::init() runs as part of core initialization (already wired in Plugin::initialize_core()).

---

## 7) Diagnostics dashboard and checks

Admin surface: Admin\DiagnosticDashboard (initialized in Plugin::initialize_admin_components()).

What it does (high level):
- Surfaces environment checks and controlled debug operations (implementation spans src/Diagnostics/* and Admin bindings).
- Uses odcm_log_event to record diagnostic runs and outcomes where appropriate.
- May include checks for dependency presence, conflicts, i18n load status, and REST reachability.

Guidance:
- Keep any destructive or heavy operations behind explicit user actions with confirmation and NonceGuard protection.
- Use CapabilityGuard or wp capabilities to restrict access to administrators/managers.
- Ensure messages are internationalized using the 'order-daemon' text domain.

---

## 8) Security event observability

- GuardChecker logs both successful and failed guard verifications with rich context (user id/roles, IP via AttributionTracker, request metadata, execution time).
- Correlate security events with operational timelines by including related object IDs and contextual keys where possible.
- Treat security_check_failed as high priority in dashboards; provide remediation hints in the details.

---

## 9) Troubleshooting

Common symptoms and steps:
- Missing translations or unexpected English strings:
  - Check Plugin::load_text_domain() diagnostics in error_log; verify languages path and ensure JSON script translations are registered for admin bundles.
- No timeline entries for an event:
  - Confirm the event path is canonical (UniversalEventProcessor creates ProcessLogger only for canonical events) and that set_universal_event_context isn’t suppressing entries.
  - Verify odcm_log_event is available and not short-circuited; inspect REST responses from AuditLogEndpoint for raw payload.
- Excessive logs or performance degradation:
  - Reduce error_log traces in hot paths; ensure only necessary ProcessLogger components are added.
  - Review LogCleanup scheduling and per-page limits in UI.
- Premium labels shown but actions blocked:
  - See A4 (entitlements). Server-side will block premium-only triggers/conditions; check violations in REST responses.

---

## 10) Open TODOs

- Document exact storage backend and retention policy for odcm_log_event once finalized in this repo (keep consistent with LogCleanup behavior).
- Expand event_type taxonomy documentation and ensure registries are complete and reflected in dashboards.
- Add correlation IDs across security and rule evaluation flows for easier cross-surface tracing.
- Confirm and document any Diagnostics public/debug endpoints and gate them appropriately outside development contexts.
