# Comprehensive Payload Rendering Refactor Plan

## Overview
This document outlines our plan to completely overhaul the payload rendering system, eliminating unnecessary abstraction layers and simplifying the design. The goal is to create a direct mapping from event_type to rendering logic, with each renderer optimized for its specific business purpose.

## Phase 1: Event Type Discovery & Cataloging

**Objective:** Create a complete inventory of every event_type actually used in the codebase.

**Method:**
1. Search for all `add_component()` calls (ProcessLogger)
2. Search for all `odcm_log_event()` calls (legacy logging)
3. Search for event_type assignments in data structures
4. Document the file location and context for each

**Deliverable:** A markdown file listing every unique event_type with:
- Where it's created (file + line)
- The context (rule evaluation, payment processing, etc.)
- Frequency/importance (common vs edge case)

## Phase 2: Data Structure Analysis

**Objective:** For each event_type, document exactly what data is logged.

**Method:**
1. For each event from Phase 1, examine the `$data` array passed to logging
2. List all possible fields/keys with example values
3. Note which fields are always present vs optional
4. Identify fields that are business-critical vs debug info

**Deliverable:** For each event_type, a schema showing:
```markdown
## condition_passed
**Context:** Rule evaluation - when a condition succeeds
**Data Fields:**
- order_id (required): int - Order being evaluated
- condition_label (required): string - Human-readable condition name
- expected_value (required): mixed - What the condition expected
- actual_value (required): mixed - What was actually found
- operator (optional): string - Comparison operator used
- result (required): string - "pass"
```

## Phase 3: Business Value Analysis

**Objective:** For each event, answer "Why would a merchant care about this?"

**Method:**
1. Consider the merchant's perspective
2. Identify what action/decision this data enables
3. Determine if this should be prominent or hidden

**Deliverable:** For each event_type:
```markdown
## condition_passed
**Business Value:**
- Helps merchants understand WHY an order was/wasn't completed
- Enables debugging of rule logic without code knowledge
- Shows transparency in automated decision-making

**User Action Enabled:**
- Adjust rule conditions based on actual vs expected values
- Identify misconfigured rules
- Verify automation is working correctly

**Priority:** HIGH (core feature functionality)
```

## Phase 4: UI Recommendation

**Objective:** Design the optimal UI for each event's data.

**Method:**
1. Match data structure to UI Toolkit components
2. Consider information hierarchy (what's most important)
3. Design for scannability and quick comprehension

**Deliverable:** For each event_type:
```markdown
## condition_passed
**Recommended UI:**
- Status pill: "✓ Passed" (success type)
- Key-value list for core data:
  - Condition: {condition_label}
  - Expected: {expected_value}
  - Actual: {actual_value}
  - Operator: {operator}
- Minimal, scannable layout
- Green accent for success state

**UI Toolkit Methods:**
- render_status_pill('Passed', 'success')
- render_key_value_list($data, 'Condition Result')
```

## Phase 5: Renderer Consolidation Plan

**Objective:** Based on Phases 1-4, determine the minimal set of renderers needed.

**Analysis Questions:**
- Which events share similar data structures?
- Which events serve the same business purpose?
- Can we have one renderer handle multiple related events?

**Deliverable:** A mapping like:
```markdown
## Proposed Renderer Structure

### RuleRenderer (handles all rule-related events)
Events: condition_passed, condition_failed, rule_matched, rule_no_match
Rationale: All show rule evaluation results with similar data

### PaymentRenderer (handles all payment events)
Events: payment_completed, payment_failed, refund_created
Rationale: All show payment transactions with amount/gateway/status

### OrderRenderer (handles WooCommerce events)
Events: order_loaded, status_changed, meta_updated
Rationale: All show order state changes

### SystemRenderer (handles infrastructure events)
Events: action_scheduled, process_started, info, error
Rationale: Generic system events, minimal UI needs
```

## Phase 6: Implementation

**Objective:** Build the simplified renderer system.

**Tasks:**
1. Create new minimal renderers based on Phase 5
2. Update registry to map event_types directly to renderers
3. Remove unnecessary abstraction layers
4. Test with real data from production logs

## Expected Outcome

When completed, this refactor will result in:

1. A significantly simpler system where `event_type` directly maps to the correct renderer
2. Elimination of complex abstractions, aliasing, and multi-tier lookups
3. Renderers optimized for specific business purposes
4. Improved UI that prioritizes user needs over technical representations
5. More maintainable, easy-to-understand code
6. Better performance through reduced layers
