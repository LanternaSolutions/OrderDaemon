# Timeline Event Deduplication - Universal Events Migration Plan

## Executive Summary

This document outlines the implementation plan to fix the Order #74 timeline issues by completing the migration to the Universal Events architecture. The core problem is **duplicate event sources** creating 3-4 identical events for each order action, cluttering the timeline with redundant information.

**Root Cause**: Multiple parts of the codebase directly log events instead of using the centralized UniversalEventProcessor, creating duplicate timeline entries.

**Solution**: Complete the Universal Events migration pattern (already implemented for `checkout_processed`) to eliminate duplicate sources and create a single source of truth for all business events.

## ⚠️ **CRITICAL WARNING - Rule Evaluation System Protection**

### **🚨 RULE SYSTEM DEPENDENCY ALERT**

**The Order Daemon rule evaluation system depends entirely on the event processing pipeline to function correctly. Any modifications to event structure, timing, or data integrity could break the automated rule system that merchants rely on for order completion.**

### **Critical Dependencies to Preserve**

#### **1. UniversalEventProcessor Pipeline Integrity**
```php
// THIS FLOW MUST REMAIN INTACT:
WooCommerce Hook → Core.php handler → UniversalEvent creation → 
UniversalEventProcessor::processEvent() → Rule evaluation → Action execution
```

**Why Critical**: The rule engine evaluates UniversalEvents through `UniversalEventProcessor::processEvent()`. If this pipeline is broken or modified incorrectly, rules will stop firing and orders will not be automatically completed.

#### **2. Event Data Structure Preservation**
```php
// THESE FIELDS MUST REMAIN AVAILABLE FOR RULE EVALUATION:
$universal_event = new UniversalEvent([
    'eventType' => 'order_status_changed',        // ← Rules match on this
    'primaryObjectType' => 'order',               // ← Rules filter on this
    'primaryObjectID' => $order->get_id(),        // ← Rules need order access
    'amount' => (float) $order->get_total(),      // ← Conditions check this
    'currency' => $order->get_currency(),         // ← Conditions check this
    'status' => $to_status,                       // ← Triggers match on this
    // rawData is for timeline display only - safe to modify
]);
```

#### **3. Event Type Consistency**
```php
// RULE TRIGGERS DEPEND ON SPECIFIC EVENT TYPES:
'order_status_changed'  // ← OrderProcessingTrigger looks for this
'payment_completed'     // ← PaymentCompleteTrigger looks for this
'checkout_processed'    // ← CheckoutTrigger looks for this

// DO NOT CHANGE these event type strings without updating rule components
```

#### **4. EvaluationContext Creation**
```php
// UniversalEventProcessor creates EvaluationContext for rule evaluation:
$context = new EvaluationContext($universal_event);

// This context MUST have access to:
$context->order         // ← Rule conditions need WC_Order object
$context->event         // ← Rule conditions need UniversalEvent data
$context->customer      // ← Rule conditions may need customer data
```

### **Implementation Safety Guidelines**

#### **🟢 SAFE MODIFICATIONS (Timeline Display Only)**
- ✅ Modify `rawData` structure - used only for timeline rendering
- ✅ Change renderer data extraction logic
- ✅ Add new fields to rawData for timeline context
- ✅ Modify timeline UI components and expandable sections
- ✅ Change event labeling and display formatting

#### **🔴 DANGEROUS MODIFICATIONS (Could Break Rules)**
- ❌ Changing UniversalEvent constructor parameters
- ❌ Modifying `eventType` strings that triggers depend on
- ❌ Altering `primaryObjectType` or `primaryObjectID` fields
- ❌ Breaking the UniversalEventProcessor::processEvent() flow
- ❌ Changing how EvaluationContext accesses order/event data
- ❌ Modifying event timing or sequence that could affect rule evaluation

#### **🟡 REQUIRES CAREFUL TESTING**
- ⚠️ Removing duplicate event sources (verify rules still fire)
- ⚠️ Changing event creation timing in Core.php handlers
- ⚠️ Modifying ManualStatusTracker integration
- ⚠️ Updating payment event synthesis methods

### **Mandatory Testing Protocol**

#### **Rule Evaluation Verification Steps**
```bash
# 1. Create test order with rule conditions
1. Set up order completion rule (e.g., "Virtual products auto-complete")
2. Place test order matching rule conditions
3. Verify rule fires and order is automatically completed
4. Check timeline shows both status change AND rule execution events

# 2. Test all trigger types
1. Test OrderProcessingTrigger with status changes
2. Test PaymentCompleteTrigger with payment completion
3. Test CheckoutTrigger with checkout completion
4. Verify each trigger type still evaluates correctly

# 3. Test rule conditions
1. Test OrderTotalAmountCondition with different order values
2. Test ProductCategoryCondition with various product categories
3. Test ProductTypeCondition with virtual/physical products
4. Verify all conditions still evaluate order data correctly

# 4. Test rule actions
1. Test CompleteOrderAction execution
2. Test custom actions (if any)
3. Verify actions still receive correct order context
4. Check action execution appears in timeline
```

#### **Emergency Rollback Triggers**
If ANY of these symptoms appear, immediately rollback changes:
- Rules stop firing for orders that should match
- "No rules matched" appearing for orders that should trigger rules
- Rule conditions evaluating incorrectly (wrong order total, etc.)
- Actions not executing when rules match
- EvaluationContext errors in logs

### **Safe Implementation Strategy**

#### **Phase-by-Phase Rule Testing**
```php
// After each phase, run this verification:
1. Create test order matching existing rule
2. Verify rule evaluation occurs normally
3. Check UniversalEventProcessor logs for proper event processing
4. Confirm EvaluationContext creation succeeds
5. Validate rule conditions can access order data
6. Test rule actions execute correctly
```

#### **Code Change Safety Checks**
Before implementing any change:
1. ✅ Does this modify UniversalEvent constructor? → **DANGEROUS**
2. ✅ Does this change event type strings? → **DANGEROUS**  
3. ✅ Does this affect UniversalEventProcessor flow? → **REQUIRES TESTING**
4. ✅ Is this only timeline display logic? → **SAFE**

### **Rule System Architecture Reference**

```php
// RULE EVALUATION FLOW (DO NOT BREAK THIS):
Core.php::handle_*() 
  → synthesize_*_event() 
  → process_universal_event_from_hook()
  → UniversalEventProcessor::processEvent()
  → EvaluationContext creation
  → Rule condition evaluation
  → Rule action execution
  → Timeline logging via logProcessingResult()
```

**Remember**: The timeline is a DISPLAY LAYER. The rule system is a BUSINESS LOGIC LAYER. Display changes are safe. Business logic changes require extreme caution.

## 🔍 Root Cause Analysis

### Current Event Duplication Sources

#### 1. Status Change Events (3-4 duplicates per status change)
**Sources creating duplicate `status_changed` events:**

1. **`Core.php::handle_order_status_change()`** (priority 10)
   - Direct `odcm_log_event()` call with `status_change_processing` type
   - Creates status_changed component manually

2. **`Core.php::handle_general_order_status_change()`** (priority 15)  
   - Another direct `odcm_log_event()` call with `status_change_processing` type
   - Creates status_changed component manually

3. **`ManualStatusTracker.php::track_status_change()`** (priority 5)
   - Direct `odcm_log_event()` call with `manual_status_change` type
   - Creates status_changed component manually

4. **UniversalEventProcessor** (when webhook events are processed)
   - Single, proper source through `logProcessingResult()`

#### 2. Payment Events (3 duplicates per payment)
**Sources creating duplicate `payment_completed` events:**

1. **`Core.php::handle_payment_complete()`**
   - Direct background processing that logs payment events

2. **UniversalEventProcessor** (webhook pathway)
   - Proper source through `logProcessingResult()`

3. **Gateway-specific events** (`stripe_event`, `paypal_event`)
   - Separate event types that should be consolidated

#### 3. System Events Showing as "Array"
**Problem**: Attribution and bypass context events display as "System Event (Array)" instead of meaningful information.

**Root Cause**: SystemRenderer doesn't know how to extract and display attribution data from these events.

## 🎯 Target Architecture - Universal Events Single Source

### Universal Events Flow (Correct Pattern)
```
1. WooCommerce Hook Fires → Core.php handler
2. Handler creates UniversalEvent object  
3. UniversalEvent sent to UniversalEventProcessor
4. UniversalEventProcessor::logProcessingResult() creates SINGLE timeline event
5. Renderers display event with proper data extraction
```

### Example: Checkout Events (Already Correct)
```php
// Core.php::handle_checkout_order_processed() - GOOD EXAMPLE
public function handle_checkout_order_processed(int $order_id, array $posted_data, \WC_Order $order): void
{
    // Create UniversalEvent instead of direct logging
    $universal_event = $this->synthesize_checkout_processed_event($order, $posted_data);
    
    // Process through Universal Events pipeline
    $this->process_universal_event_from_hook($universal_event);
    
    // NO direct odcm_log_event() calls!
}
```

**Result**: Single `checkout_processed` event with rich data in expandable sections.

## 📋 Implementation Plan

### Phase 1: Migrate Status Change Events to Universal Events

#### 1.1 Core.php Status Handler Migration

**File**: `src/Core/Core.php`

**Current Code (Lines ~600-700) - REMOVE THIS PATTERN:**
```php
// handle_order_status_change() - WRONG PATTERN
public function handle_order_status_change(int $order_id): void
{
    // ... existing code ...
    
    // ❌ REMOVE: Direct logging creates duplicates
    odcm_log_event(
        $summary,
        [
            'type' => 'status_change_processing',
            'cid' => $order_id . ':' . time(),
            'order_id' => $order_id,
            'actor' => ['id' => null, 'role' => null, 'name' => 'system'],
            'ts' => time(),
            'components' => $components,  // ❌ Manual component creation
        ],
        $order_id,
        'info',
        'status_change_processing'
    );
    
    // Still schedule completion check
    $this->schedule_completion_check($order_id);
}
```

**New Code - UNIVERSAL EVENTS PATTERN:**
```php
// handle_order_status_change() - CORRECT PATTERN
public function handle_order_status_change(int $order_id): void
{
    if ($order_id <= 0) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    $status = $order->get_status();
    $status_slug = sanitize_key((string)$status);

    // Capture attribution context for rawData
    $attribution = $this->capture_attribution_context();
    
    // ✅ CREATE UNIVERSAL EVENT instead of direct logging
    $universal_event = $this->synthesize_status_change_event(
        $order, 
        'unknown',  // Previous status (can be enhanced later)
        $status_slug,
        $attribution
    );
    
    // ✅ PROCESS through Universal Events pipeline
    $this->process_universal_event_from_hook($universal_event);
    
    // Mark processed to prevent general hook duplication
    $this->mark_specific_status_processed($order_id, $status_slug);
    
    // Still schedule completion check
    $this->schedule_completion_check($order_id);
}
```

#### 1.2 Add Status Change Event Synthesis Method

**File**: `src/Core/Core.php`

**Add this method:**
```php
/**
 * Synthesize status change event from WooCommerce order data
 *
 * @param \WC_Order $order WooCommerce order object
 * @param string $from_status Previous status
 * @param string $to_status New status
 * @param array $attribution Attribution context data
 * @return UniversalEvent
 */
private function synthesize_status_change_event(\WC_Order $order, string $from_status, string $to_status, array $attribution = []): UniversalEvent
{
    return new UniversalEvent([
        'eventType' => 'order_status_changed',
        'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
        'channel' => 'system',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order->get_id(),
        'transactionID' => $order->get_transaction_id(),
        'status' => $to_status,
        'amount' => (float) $order->get_total(),
        'currency' => $order->get_currency(),
        'occurredAt' => current_time('c'),
        'rawData' => [
            'from_status' => $from_status,
            'to_status' => $to_status,
            'source' => $this->determine_change_source(),
            'attribution' => $attribution,  // Attribution data for expandable section
            'order_total' => $order->get_total(),
            'customer_id' => $order->get_customer_id(),
        ]
    ]);
}

/**
 * Capture attribution context for status changes
 *
 * @return array Attribution context data
 */
private function capture_attribution_context(): array
{
    try {
        $attr = AttributionTracker::instance()->capture_context();
        
        return [
            'request_type' => $attr['request_type'] ?? 'unknown',
            'user_logged_in' => $attr['user_context']['is_logged_in'] ?? false,
            'source_plugin' => $attr['source_plugin'] ?? null,
            'external_service' => $attr['external_service'] ?? null,
        ];
    } catch (\Throwable $e) {
        return ['error' => 'Attribution capture failed'];
    }
}
```

#### 1.3 Apply Same Pattern to General Status Handler

**File**: `src/Core/Core.php`

**Method**: `handle_general_order_status_change()`

**Current Code - REMOVE THIS PATTERN:**
```php
// ❌ REMOVE: Direct logging with manual components
$sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
$components = [];
$status_data = $sanitizer->sanitize('status_changed', ['from' => $from_slug, 'to' => $to_slug]);
$components[] = [
    'k' => 'c' . time() . rand(10,99),
    'event_type' => 'status_changed',
    'ts' => odcm_iso8601_now(),
    'label' => 'Status changed',
    'level' => 'info',
    'data' => $status_data,
];

odcm_log_event($summary, [...], $order_id, 'info', 'status_change_processing');
```

**New Code - UNIVERSAL EVENTS PATTERN:**
```php
// ✅ REPLACE: Use Universal Events
$attribution = $this->capture_attribution_context();

$universal_event = $this->synthesize_status_change_event(
    $order, 
    $from_slug, 
    $to_slug,
    $attribution
);

$this->process_universal_event_from_hook($universal_event);
```

### Phase 2: Fix Manual Status Tracker Integration

#### 2.1 ManualStatusTracker Refactoring

**File**: `src/Core/ManualStatusTracker.php`

**Current Problem**: ManualStatusTracker creates separate events instead of enhancing the automatic ones.

**Current Code - WRONG PATTERN:**
```php
// ❌ WRONG: Creates separate manual_status_change event
public static function track_status_change(int $order_id, string $from, string $to, WC_Order $order): void
{
    // ... detection logic ...
    
    if ($source === 'manual') {
        // ❌ WRONG: Creates duplicate event
        odcm_log_event(
            $final_summary,
            [...],
            $order_id,
            'success',
            'manual_status_change'  // ❌ Separate event type
        );
    }
}
```

**New Code - INTEGRATION PATTERN:**
```php
/**
 * Track order status changes and enhance Universal Events with manual attribution
 */
public static function track_status_change(int $order_id, string $from, string $to, WC_Order $order): void
{
    // Detect if this is a manual admin change
    $is_manual_change = self::is_manual_admin_action();
    
    if (!$is_manual_change) {
        return; // Let automatic status changes flow through normal Universal Events
    }
    
    // ✅ ENHANCE the Universal Event that will be created by Core.php
    // Store manual change context for Universal Event to pick up
    $manual_context = [
        'is_manual' => true,
        'user_id' => get_current_user_id(),
        'user_display_name' => self::get_current_user_display_name(),
        'bypassed_automation' => self::would_automation_have_triggered($order, $from, $to),
    ];
    
    // Store in order meta for Universal Event synthesis to pick up
    update_post_meta($order_id, '_odcm_manual_status_context', $manual_context);
    
    // Add order note for manual changes
    if ($manual_context['bypassed_automation']) {
        $note = sprintf(
            'Order status manually changed from "%s" to "%s" by %s. This change may have bypassed automatic completion rules.',
            wc_get_order_status_name($from),
            wc_get_order_status_name($to),
            $manual_context['user_display_name']
        );
    } else {
        $note = sprintf(
            'Order status manually changed from "%s" to "%s" by %s.',
            wc_get_order_status_name($from),
            wc_get_order_status_name($to),
            $manual_context['user_display_name']
        );
    }
    
    $order->add_order_note($note, false, true);
}

/**
 * Check if current action is manual admin change
 */
private static function is_manual_admin_action(): bool
{
    return is_user_logged_in() && 
           is_admin() && 
           !self::is_automation_context() &&
           !wp_doing_ajax(); // Exclude AJAX requests that might be automated
}
```

#### 2.2 Enhance Universal Event Synthesis with Manual Context

**File**: `src/Core/Core.php`

**Update the `synthesize_status_change_event()` method:**
```php
private function synthesize_status_change_event(\WC_Order $order, string $from_status, string $to_status, array $attribution = []): UniversalEvent
{
    // Check for manual status change context
    $manual_context = get_post_meta($order->get_id(), '_odcm_manual_status_context', true);
    $is_manual = is_array($manual_context) && ($manual_context['is_manual'] ?? false);
    
    // Clean up the meta after reading
    if ($is_manual) {
        delete_post_meta($order->get_id(), '_odcm_manual_status_context');
    }
    
    $rawData = [
        'from_status' => $from_status,
        'to_status' => $to_status,
        'source' => $this->determine_change_source(),
        'attribution' => $attribution,
        'order_total' => $order->get_total(),
        'customer_id' => $order->get_customer_id(),
    ];
    
    // ✅ ADD MANUAL CHANGE CONTEXT to rawData
    if ($is_manual) {
        $rawData['manual_change'] = true;
        $rawData['changed_by_user_id'] = $manual_context['user_id'];
        $rawData['changed_by_user_name'] = $manual_context['user_display_name'];
        $rawData['bypassed_automation'] = $manual_context['bypassed_automation'];
        
        if ($manual_context['bypassed_automation']) {
            $rawData['automation_bypass_warning'] = 'This manual change may have bypassed automatic completion rules.';
        }
    }
    
    return new UniversalEvent([
        'eventType' => 'order_status_changed',
        'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
        'channel' => $is_manual ? 'manual' : 'system',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order->get_id(),
        'transactionID' => $order->get_transaction_id(),
        'status' => $to_status,
        'amount' => (float) $order->get_total(),
        'currency' => $order->get_currency(),
        'reason' => $is_manual ? 'manual_change' : 'automatic_change',
        'occurredAt' => current_time('c'),
        'rawData' => $rawData
    ]);
}
```

### Phase 3: Payment Event Consolidation

#### 3.1 Remove Direct Payment Logging

**File**: `src/Core/Core.php`

**Method**: `handle_payment_complete()`

**Current Code - REMOVE DIRECT LOGGING:**
```php
// ❌ REMOVE: Background processing that creates duplicate events
public function handle_payment_complete(int $order_id): void
{
    // ... existing code ...
    
    // ❌ REMOVE: This creates duplicate events
    as_enqueue_async_action('odcm_process_payment_completion', [
        'order_id' => $order_id,
        'payment_gateway' => $order->get_payment_method(),
        'scheduled_at' => current_time('c')
    ], 'odcm-payment-processing');
}
```

**New Code - UNIVERSAL EVENTS ONLY:**
```php
// ✅ REPLACE: Use Universal Events pathway only
public function handle_payment_complete(int $order_id): void
{
    if ($order_id <= 0) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    try {
        // ✅ CREATE UNIVERSAL EVENT for payment completion
        $universal_event = $this->synthesize_payment_complete_event($order);
        
        // ✅ PROCESS through Universal Events pipeline
        $this->process_universal_event_from_hook($universal_event);
        
        odcm_log_message("Payment completion for order #{$order_id} processed as universal event", 'info');
        
    } catch (\Throwable $e) {
        // Never break payment completion
        odcm_log_message('Payment completion event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
    }
}
```

#### 3.2 Enhance Payment Event Synthesis

**File**: `src/Core/Core.php`

**Update existing method to include gateway-specific data:**
```php
/**
 * Synthesize payment complete event with gateway-specific data in rawData
 */
private function synthesize_payment_complete_event(\WC_Order $order): UniversalEvent
{
    $payment_method = $order->get_payment_method();
    $gateway_title = $order->get_payment_method_title();
    
    // Gather gateway-specific data for rawData
    $gateway_data = [
        'payment_method_id' => $payment_method,
        'payment_method_title' => $gateway_title,
        'transaction_id' => $order->get_transaction_id(),
        'order_key' => $order->get_order_key(),
        'payment_date' => $order->get_date_paid() ? $order->get_date_paid()->format('c') : null,
    ];
    
    // Add Stripe-specific data if available
    if (strpos($payment_method, 'stripe') !== false) {
        $gateway_data['stripe_data'] = [
            'payment_intent_id' => $order->get_meta('_stripe_intent_id'),
            'charge_id' => $order->get_meta('_stripe_charge_id'),
            'source_id' => $order->get_meta('_stripe_source_id'),
            'customer_id' => $order->get_meta('_stripe_customer_id'),
        ];
    }
    
    // Add PayPal-specific data if available  
    if (strpos($payment_method, 'paypal') !== false || strpos($payment_method, 'ppcp') !== false) {
        $gateway_data['paypal_data'] = [
            'transaction_id' => $order->get_meta('_paypal_transaction_id'),
            'payer_id' => $order->get_meta('_paypal_payer_id'),
            'payment_status' => $order->get_meta('_paypal_status'),
        ];
    }
    
    return new UniversalEvent([
        'eventType' => 'payment_completed',
        'sourceGateway' => $this->normalize_gateway_name($payment_method),
        'channel' => 'system',
        'primaryObjectType' => 'order',
        'primaryObjectID' => $order->get_id(),
        'transactionID' => $order->get_transaction_id(),
        'status' => 'completed',
        'amount' => (float) $order->get_total(),
        'currency' => $order->get_currency(),
        'occurredAt' => current_time('c'),
        'rawData' => $gateway_data  // ✅ Gateway-specific data for expandable sections
    ]);
}
```

### Phase 4: Enhanced Renderer Data Extraction

#### 4.1 OrderRenderer - Manual Change Detection

**File**: `src/View/PayloadRenderer/OrderRenderer.php`

**Update `renderStatusChange()` method:**
```php
/**
 * Render Status Change with manual change detection
 */
private function renderStatusChange(array $payload, PayloadComponentUIToolkit $toolkit): string
{
    $data = $payload['data'] ?? $payload;
    $rawData = $payload['rawData'] ?? [];
    
    // Extract status information
    $from_status = $data['from'] ?? $rawData['from_status'] ?? 'unknown';
    $to_status = $data['to'] ?? $rawData['to_status'] ?? '';
    
    // Detect manual changes from rawData
    $is_manual = $rawData['manual_change'] ?? false;
    $changed_by = $rawData['changed_by_user_name'] ?? '';
    $bypassed_automation = $rawData['bypassed_automation'] ?? false;
    
    $status_data = [
        'New Status' => ucfirst($to_status),
        'Previous Status' => ucfirst($from_status),
        'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
    ];
    
    // ✅ ADD MANUAL CHANGE CONTEXT
    if ($is_manual) {
        $status_data['Changed By'] = $changed_by;
        $status_data['Change Type'] = 'Manual (Admin)';
    } else {
        $status_data['Change Type'] = 'Automatic (System)';
    }
    
    $content = $toolkit->render_key_value_list($status_data, 'Status Change');
    
    // ✅ SHOW AUTOMATION BYPASS WARNING
    if ($bypassed_automation) {
        $warning = $rawData['automation_bypass_warning'] ?? 'This manual change may have bypassed automatic completion rules.';
        $content .= $toolkit->render_warning_message($warning);
    }
    
    // ✅ ADD ATTRIBUTION DATA to expandable section
    if (!empty($rawData['attribution'])) {
        $content .= $toolkit->render_expandable_key_value_section('Attribution Details', $rawData['attribution']);
    }
    
    return $content;
}
```

#### 4.2 PaymentRenderer - Gateway Data Extraction

**File**: `src/View/PayloadRenderer/PaymentRenderer.php`

**Update `renderPayment()` method:**
```php
/**
 * Render Payment with gateway-specific data extraction
 */
private function renderPayment(array $payload, PayloadComponentUIToolkit $toolkit): string
{
    $data = $payload['data'] ?? $payload;
    $rawData = $payload['rawData'] ?? [];
    
    // ✅ EXTRACT from both component data and rawData
    $payment_data = [
        'Amount' => $this->extractAmount($data, $rawData),
        'Transaction ID' => $this->extractTransactionId($data, $rawData),
        'Payment Method' => $this->extractPaymentMethod($data, $rawData),
        'Gateway' => $this->extractGateway($data, $rawData),
        'Status' => ucfirst($data['status'] ?? $rawData['status'] ?? 'completed'),
    ];
    
    $content = $toolkit->render_key_value_list($payment_data, 'Payment Completed');
    
    // ✅ ADD GATEWAY-SPECIFIC DETAILS in expandable sections
    if (isset($rawData['stripe_data']) && !empty($rawData['stripe_data'])) {
        $stripe_data = array_filter($rawData['stripe_data']); // Remove empty values
        if (!empty($stripe_data)) {
            $content .= $toolkit->render_expandable_key_value_section('Stripe Details', $stripe_data);
        }
    }
    
    if (isset($rawData['paypal_data']) && !empty($rawData['paypal_data'])) {
        $paypal_data = array_filter($rawData['paypal_data']);
        if (!empty($paypal_data)) {
            $content .= $toolkit->render_expandable_key_value_section('PayPal Details', $paypal_data);
        }
    }
    
    return $content;
}

/**
 * Extract amount from multiple data sources
 */
private function extractAmount(array $data, array $rawData): string
{
    $amount = $data['amount'] ?? $rawData['amount'] ?? 0;
    $currency = $data['currency'] ?? $rawData['currency'] ?? 'USD';
    
    if ($amount > 0) {
        return $this->formatCurrency($amount, $currency);
    }
    
    return '';
}

/**
 * Extract transaction ID from multiple data sources
 */
private function extractTransactionId(array $data, array $rawData): string
{
    // Try multiple sources
    return $data['transaction_id'] ?? 
           $rawData['transaction_id'] ?? 
           $rawData['stripe_data']['charge_id'] ?? 
           $rawData['paypal_data']['transaction_id'] ?? 
           '';
}

/**
 * Extract payment method title from multiple data sources
 */
private function extractPaymentMethod(array $data, array $rawData): string
{
    return $data['payment_method'] ?? 
           $rawData['payment_method_title'] ?? 
           $rawData['payment_method_id'] ?? 
           '';
}

/**
 * Extract gateway name from multiple data sources
 */
private function extractGateway(array $data, array $rawData): string
{
    $gateway = $data['source_gateway'] ?? $data['gateway'] ?? '';
    
    if (empty($gateway) && isset($rawData['payment_method_id'])) {
        $gateway = $this->normalizeGatewayName($rawData['payment_method_id']);
    }
    
    return ucfirst($gateway);
}

/**
 * Normalize gateway name for display
 */
private function normalizeGatewayName(string $payment_method): string
{
    $mapping = [
        'stripe' => 'Stripe',
        'stripe_cc' => 'Stripe',
        'paypal' => 'PayPal', 
        'ppcp-gateway' => 'PayPal',
        'bacs' => 'Bank Transfer',
        'cod' => 'Cash on Delivery',
    ];
    
    return $mapping[$payment_method] ?? $payment_method;
}
```

#### 4.3 SystemRenderer - Attribution Display Fix

**File**: `src/View/PayloadRenderer/SystemRenderer.php`

**Update `renderMessage()` method:**
```php
/**
 * Render Message with proper attribution data extraction
 */
private function renderMessage(array $data, PayloadComponentUIToolkit $toolkit, string $event_type): string
{
    // ✅ FIX "Array" display issue
    if (isset($data['attribution']) && is_array($data['attribution'])) {
        return $this->renderAttributionContext($data['attribution'], $toolkit);
    }
    
    if (isset($data['automation_bypassed']) && $data['automation_bypassed']) {
        return $this->renderAutomationBypassWarning($data, $toolkit);
    }
    
    // Fallback to standard message rendering
    $content = '';
    if (isset($data['message'])) {
        $content .= $toolkit->render_text_block($data['message']);
    }
    
    return $content;
}

/**
 * Render attribution context in user-friendly format
 */
private function renderAttributionContext(array $attribution, PayloadComponentUIToolkit $toolkit): string
{
    $context_data = [
        'Request Type' => ucfirst($attribution['request_type'] ?? 'unknown'),
        'User Logged In' => ($attribution['user_logged_in'] ?? false) ? 'Yes' : 'No',
    ];
    
    if (isset($attribution['source_plugin']['slug'])) {
        $context_data['Source Plugin'] = $attribution['source_plugin']['slug'];
    }
    
    if (isset($attribution['external_service']['name'])) {
        $context_data['External Service'] = $attribution['external_service']['name'];
    }
    
    return $toolkit->render_key_value_list($context_data, 'Change Attribution');
}

/**
 * Render automation bypass warning
 */
private function renderAutomationBypassWarning(array $data, PayloadComponentUIToolkit $toolkit): string
{
    $warning = $data['automation_bypass_warning'] ?? 'This action may have bypassed automatic completion rules.';
    return $toolkit->render_warning_message($warning);
}
```

## 🧪 Testing Strategy

### 1. Status Change Testing
```bash
# Test automatic status changes
1. Place an order
2. Process payment → Check timeline shows single status_changed event
3. Verify attribution data in expandable section

# Test manual status changes  
1. Login as admin
2. Change order status manually in WooCommerce admin
3. Check timeline shows:
   - Single status_changed event
   - "Changed By: Admin Name"
   - "Change Type: Manual (Admin)"
   - Automation bypass warning (if applicable)
```

### 2. Payment Event Testing
```bash
# Test payment completion
1. Complete payment via Stripe/PayPal
2. Check timeline shows:
   - Single payment_completed event
   - Transaction ID, amount, gateway
   - Gateway-specific details in expandable section
   - NO separate stripe_event or paypal_event
```

### 3. Debug Mode Testing
```bash
# Enable debug mode
define('ODCM_DEBUG', true);

# Verify attribution events show properly
1. Perform status change
2. Check expandable sections show attribution data
3. Verify no "Array" displays in timeline
```

## 📊 Expected Results

### Before Implementation
- **Status Events**: 3-4 duplicate status_changed events per order status change
- **Payment Events**: 3 duplicate payment_completed + stripe_event/paypal_event
- **System Events**: "Info: System Event (Array)" - meaningless displays
- **Timeline**: 15+ events for a simple order, cluttered and confusing

### After Implementation  
- **Status Events**: 1 single status_changed event per status change
- **Payment Events**: 1 single payment_completed event with gateway details in expandable sections
- **Manual Changes**: Clear attribution showing "Changed By: User Name" with automation bypass warnings
- **Timeline**: 3-4 meaningful business events with rich context in expandable sections

### Business Value
- **Merchants** see clean, understandable order timeline
- **Support teams** have clear chain of custody for manual changes
- **Developers** have rich technical details available on demand
- **System performance** improved by eliminating duplicate event processing

## 🔄 Rollback Plan

If issues arise during implementation:

1. **Revert Core.php changes** - restore direct `odcm_log_event()` calls
2. **Disable ManualStatusTracker integration** - comment out meta storage
3. **Restore payment event duplicates** - re-enable background processing
4. **Enable debug logging** - add verbose logging to identify issues

Each phase can be rolled back independently without affecting the others.

## 📝 Code Review Checklist

- [ ] All direct `odcm_log_event()` calls removed from Core.php status handlers
- [ ] UniversalEvent synthesis methods created with proper rawData structure
- [ ] ManualStatusTracker integration uses meta storage pattern
- [ ] PaymentRenderer extracts data from both component data and rawData
- [ ] OrderRenderer detects manual changes and shows proper attribution
- [ ] SystemRenderer no longer shows
