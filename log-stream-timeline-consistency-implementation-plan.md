# Log Stream and Timeline Consistency Implementation Plan

## Problem Statement

The log stream entries in individual view mode and timeline events in consolidated view show different titles for the same events, making it difficult for users to correlate events between the two views.

### Current State Example

**Log Stream (Individual View):**
- "Checkout Completed"
- "Payment Event"
- "Rule 'virtual rule' evaluated successfully for Order #105"

**Timeline (Consolidated View):**
- "Checkout Processed"
- "Payment.stripe.checkout processed"
- "Rule Processing Started"
- "Rule Executed: virtual rule"

## Root Cause Analysis

The discrepancy occurs because:
1. **Log Stream**: Uses the `summary` field directly from the database (set during original logging)
2. **Timeline**: Uses adapter-generated titles that are more accurate and consistent

The timeline adapters provide better, more specific titles, so we should update the original logging calls to use these improved titles.

## Solution Approach

Update the summary fields at the source (in ProcessLogger calls) to use the same title generation logic as the timeline adapters. This ensures both views show identical, accurate titles.

## Implementation Plan

### Phase 1: Create Title Generation Service

1. **Create TitleGenerator class** that encapsulates title generation logic
2. **Extract title generation methods** from existing adapters
3. **Create unified interface** for consistent title generation

### Phase 2: Update Logging Calls

1. **Identify key logging locations** in the codebase
2. **Update ProcessLogger calls** to use TitleGenerator
3. **Ensure all event types** use consistent titles

### Phase 3: Update Timeline Adapters

1. **Refactor adapters** to use TitleGenerator
2. **Ensure consistency** between logging and timeline rendering

### Phase 4: Testing and Verification

1. **Create test cases** for title consistency
2. **Verify both views** show identical titles
3. **Test edge cases** and error conditions

## Detailed Implementation Steps

### Step 1: Create TitleGenerator Service

```php
// src/Core/Logging/TitleGenerator.php
class TitleGenerator {
    public function generateOrderEventTitle(string $eventType, array $payload): string
    public function generatePaymentEventTitle(string $eventType, array $payload): string
    public function generateRuleEventTitle(string $eventType, array $payload): string
    public function generateGenericEventTitle(string $eventType, array $payload): string
}
```

### Step 2: Update Core Logging Locations

**Key files to update:**
- `src/Core/Core.php` - Order status changes
- `src/Core/Events/UniversalEventProcessor.php` - Rule executions
- `src/Core/CheckoutCircuitBreaker.php` - Checkout processing
- `src/API/WebhookController.php` - Payment events

### Step 3: Update ProcessLogger Calls

**Before:**
```php
$pl->start('rule_execution', [
    'order_id' => $order_id,
    'summary' => 'Rule execution started'
]);
```

**After:**
```php
$titleGenerator = new TitleGenerator();
$pl->start('rule_execution', [
    'order_id' => $order_id,
    'summary' => $titleGenerator->generateRuleEventTitle('rule_execution', $payload)
]);
```

### Step 4: Update Timeline Adapters

Refactor adapters to use the same TitleGenerator service for consistency.

## Expected Results

After implementation, both views will show identical, accurate titles:

**Log Stream (Individual View):**
- "Checkout Processed"
- "Payment.stripe.checkout processed"
- "Rule Executed: virtual rule"

**Timeline (Consolidated View):**
- "Checkout Processed"
- "Payment.stripe.checkout processed"
- "Rule Executed: virtual rule"

## Benefits

1. **Consistency**: Users can easily correlate events between views
2. **Accuracy**: Uses the more precise adapter-generated titles
3. **Maintainability**: Centralized title generation logic
4. **Future-proof**: Easy to update titles in one place

## Risk Assessment

- **Low Risk**: Changes are localized to title generation
- **Backward Compatible**: Existing logs remain unchanged
- **Testable**: Easy to verify consistency

## Timeline

- **Phase 1**: 1 day (TitleGenerator implementation)
- **Phase 2**: 2 days (Update logging calls)
- **Phase 3**: 1 day (Update adapters)
- **Phase 4**: 1 day (Testing and verification)
- **Total**: 5 days

## Success Criteria

✅ Log stream and timeline show identical titles for the same events
✅ All event types are covered
✅ No regression in existing functionality
✅ Performance impact is minimal
✅ Code is well-documented and maintainable
