# Rule System Architecture (Core + Pro)

This document describes the internal architecture of the Order Daemon rule system: how rules are defined, registered, matched, evaluated, and executed across event sources. It is written for backend engineers working on Core and Pro.

Audience: internal development team
Scope: code-accurate to Core as of 2025-11-15 (see src/Core/*)

---

## 1. Goals and non‑goals

Goals
- Provide a deterministic, explainable automation engine for WooCommerce orders and related objects.
- Unify diverse event sources (status changes, new orders, subscription events, webhooks) into a single evaluation pipeline.
- Make components (triggers, conditions, actions) discoverable, permissioned, and extensible.
- Ensure robust auditability (timeline + diagnostics) and safe error handling.

Non‑goals
- Not a general workflow engine; the domain is commerce events and order lifecycle.
- Not a replacement for core WooCommerce business logic; actions should cooperate with WC APIs.

---

## 2. Core concepts

- Rule: configuration object created via the Rule Builder (CPT `odcm_order_rule`). A rule contains:
  - Trigger: describes when the rule is considered for evaluation.
  - Conditions: predicates evaluated against an order or a universal event context.
  - Actions: effects applied when conditions pass.
  - Metadata: status (enabled/disabled), priority, labels, etc.

- Component: building block used by rules. Three component types:
  - Trigger implements Interfaces\TriggerInterface and ComponentInterface
  - Condition implements Interfaces\ConditionInterface and ComponentInterface
  - Action implements Interfaces\ActionInterface and ComponentInterface

- Universal Event: normalized event envelope used across sources
  - Class: Core\Events\UniversalEvent
  - Encapsulates type, channel, object_type/id, monetary fields, timestamps, attribution, and raw data
  - Provides validation, idempotency key generation, and humanized summaries

- Evaluation Context: materialized environment for evaluation
  - Class: Core\Events\EvaluationContext
  - Wraps UniversalEvent plus resolved entities (WC_Order, subscription, payment data, etc.)

---

## 3. Component contracts and registration

Interfaces (src/Core/RuleComponents/Interfaces):
- ComponentInterface
  - get_id(): string — unique, lowercase snake_case identifier (used in DB/API/UI)
  - get_label(): string — translatable label for UI
  - get_description(): string — translatable help text
  - get_capability(): string — capability key for entitlement checks (odcm_can_use())
  - get_settings_schema(): ?array — JSON schema (as PHP array) for UI form generation; null or [] if no settings

- TriggerInterface extends ComponentInterface
  - should_trigger(array $context, array $settings = []): bool

- ConditionInterface extends ComponentInterface
  - evaluate(WC_Order $order, array $settings): bool

- ActionInterface extends ComponentInterface
  - execute(...) signature is defined in the concrete Action class; actions are executed by the engine with the action’s settings and context (see UniversalEventProcessor::executeUniversalEventAction)

Registry (src/Core/RuleComponents/RuleComponentRegistry.php):
- Discovery
  - Loads component classes by scanning directories under src/Core/RuleComponents/{RuleTriggers,RuleConditions,RuleActions}
  - Skips abstract classes (see is_abstract)
- Accessors
  - get_triggers(), get_conditions(), get_actions()
  - get_trigger(id), get_condition(id), get_action(id)
- Lifecycle
  - Singleton-style helper via RuleComponentRegistry::instance() is used within engine code and admin

Registration helpers and UI wiring
- src/Core/options.php registers renderers and associates components with Rule Builder UI meta boxes and REST structures
- Admin Rule Builder, API, and ComponentSummaryBuilder depend on the registry for available components

Capabilities/entitlement
- Each component advertises a capability via get_capability(); UI should hide/disable components without permission
- Premium components (Pro) may not exist in Core; see PremiumComponentFallback

---

## 4. Event ingestion and normalization

Primary orchestration: src/Core/Core.php and src/Core/Events/UniversalEventProcessor.php

Event sources (non‑exhaustive):
- Order status transitions (Core::handle_general_order_status_change)
- Payment complete hooks (Core::handle_payment_complete)
- Order creation/checkout points (Core::handle_new_order, handle_checkout_order_processed)
- Subscription events (Core::handle_subscription_status_change, handle_renewal_payment) — if related extensions are active
- Webhooks / external signals via API\WebhookController (registered in Plugin::initialize_api_endpoints)

Normalization pipeline:
1) Core listens to Woo hooks and synthesizes a UniversalEvent where needed (e.g., synthesize_status_change_event, synthesize_payment_complete_event, synthesize_order_created_event).
2) Core creates an EvaluationContext from the UniversalEvent (create_evaluation_context_from_event) to resolve entities and enrich context.
3) Core delegates execution to Core\Events\UniversalEventProcessor::processEvent() or processUniversalEventRules().

Idempotency and deduplication:
- UniversalEvent::generateIdempotencyKey() provides a consistent key
- UniversalEventProcessor::isDuplicateEvent() rejects previously processed events based on the key and timeline state

Attribution and timestamps:
- Core determines change source (determine_change_source) and derives realistic occurrence timestamps (derive_real_occurrence_timestamp, get_real_payment_timestamp)

---

## 5. Rule matching and evaluation lifecycle

High-level sequence for a universal event:
1) Receive UniversalEvent and construct EvaluationContext
2) Identify candidate rules:
   - For status-change events, Core::get_matching_rules_for_status_change() fetches enabled rules whose trigger matches the from→to pair, including the special AnyStatusChangeTrigger path (see should_trigger_any_status_change_rules and evaluate_any_status_change_trigger)
   - For other event types, UniversalEventProcessor builds the candidate set via registry and rule metadata (see processUniversalEventRules)
3) Evaluate conditions:
   - Engine uses Evaluator (src/Core/Evaluator.php)
   - For order-backed events: evaluateRuleAgainstOrder(WC_Order, rule, RuleComponentRegistry)
   - For universal events: evaluateRuleAgainstUniversalEvent(EvaluationContext, rule, RuleComponentRegistry)
   - Evaluator extracts expected/actual values, applies comparison operator, and logs each condition evaluation (formatting via formatValueForLogging)
4) Execute actions for rules whose conditions passed:
   - UniversalEventProcessor::executeUniversalEventAction() locates the action by id via the registry and executes it with the current EvaluationContext and action settings
   - Action execution results are collected and formatted (formatActionExecutionDetails)
5) Persist processing result and audit:
   - UniversalEventProcessor::enhancePayloadWithRuleData() enriches payload for storage
   - ProcessLogger aggregates components and final status (success/partial/failure) and writes to diagnostic/audit facilities

Short-circuiting and batching:
- The engine evaluates each matched rule independently
- Within a rule, all configured conditions must pass (logical AND). Condition groups/OR logic are implemented at the rule schema level if present; in core paths, conditions are treated conjunctively.

Special handling: AnyStatusChangeTrigger
- Core provides a fast-path to determine whether any AnyStatusChangeTrigger-based rule should run for a given from→to transition (should_trigger_any_status_change_rules)
- This avoids needless database work on transitions irrelevant to rules

Circuit breaker and health:
- Core contains protections such as is_circuit_breaker_open(), execution time monitoring (monitor_execution_time), and emergency_fallback_processing for degraded scenarios

---

## 6. Logging, audit trail, and diagnostics

- ProcessLogger (src/Core/Logging/ProcessLogger.php)
  - start(type, context) → returns process metadata and correlation id
  - add_component(event_type, label, data, level, key?) → structured timeline components (conditions, actions, outcomes)
  - finish(final_status, summary) → closes the process with a mapped status
  - Universal-event context flag to improve labeling in logs (set_universal_event_context)

- ManualStatusTracker (src/Core/ManualStatusTracker.php)
  - Tracks manual status changes and provides “chain of custody” context

- Core logging helpers in Core.php log: evaluation starts, no-match scenarios, result summaries, exceptions via log_safe_error

- REST and Dashboards (Admin\InsightDashboard, Diagnostics)
  - Expose derived metrics via API endpoints (API\Timeline, Diagnostics APIs)

---

## 7. Error handling and resilience

- All engine surfaces catch \Throwable and log safe diagnostics without breaking checkout/admin flows
- UniversalEventProcessor::logProcessingError captures and formats errors with context, including a business-facing message mapping for gateways (createBusinessErrorMessage)
- PremiumComponentFallback initialization is guarded and non‑fatal if missing or failing
- Security Guard system is initialized if available; absence is non‑fatal
- Duplicate event detection prevents reprocessing loops

---

## 8. Extensibility and Pro

Extensibility points
- Add new components by shipping new classes under the appropriate namespace/path; the registry auto-discovers them if they implement the proper interfaces and are not abstract
- Use get_capability() to gate premium components; core UI must respect capability checks
- Hooks/filters: components and processors can expose WP filters/actions in their implementations (see component files) to allow customization

Pro layer
- Pro provides additional triggers/conditions/actions, entitlement checks, and possibly alternative action strategies
- When Pro is absent but rules reference Pro components, PremiumComponentFallback (if available) prevents fatal failures and degrades gracefully (e.g., display placeholders, block execution of missing components)

---

## 9. Data model notes (CPT + storage)

- Rules are stored as posts of type `odcm_order_rule`
- Component configuration is stored as post meta structured according to the component IDs and settings schemas (see Admin Rule Builder and options.php wiring)
- Rule status (enabled/disabled), priority, and labels are kept in post status/meta; API controllers mirror these structures for the Rule Builder UI

---

## 10. Security and permissions

- Guard system (Core\Security\GuardChecker) is initialized when available and can be obtained via Plugin::get_guard_checker()
- Component get_capability() integrates with odcm_can_use() in UI/API to restrict premium/entitled components
- REST endpoints validate permissions server-side for rule reads/writes and diagnostics access

---

## 11. Testing and troubleshooting

- To validate component registration, call RuleComponentRegistry::instance()->get_*()
- For end-to-end event tests, construct a UniversalEvent and call UniversalEventProcessor::processEvent(); inspect ProcessLogger output
- Use Core::get_matching_rules_for_status_change() and related helpers to debug status transition matching
- Common issues
  - Invalid post type: ensure Plugin::register_post_type() runs at init priority 5 (see A2 doc)
  - Missing components: confirm file/class names and namespaces; registry skips abstract and non-matching types
  - Duplicate processing: verify idempotency key source in UniversalEvent and event producer logic

---

## 12. Open TODOs

- Pro-specific component catalogs and capability mapping: document exact IDs in Pro repo
- Multisite behavior: confirm isolation and registry discovery across networks
- Formalize error taxonomy and machine-readable action result codes for richer dashboards
