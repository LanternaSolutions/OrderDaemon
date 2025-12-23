# Timeline Redesign Implementation Guide

**Date:** December 23, 2025  
**Objective:** Complete implementation of the Order Daemon timeline system redesign to solve the Order #0 issue and implement three-tier information architecture.

**Status:** Ready for implementation - no backward compatibility required (unpublished plugin)

---

## Executive Summary

This guide provides complete implementation instructions for the Order Daemon timeline redesign. The current system shows "Order #0" for rule execution events due to inadequate order ID extraction, and lacks visual hierarchy for parent-child relationships. The solution implements a DisplayAdapter system with three-tier information display and enhanced order ID extraction.

**Key Problems Solved:**
1. **Order #0 Issue**: Rule execution events display "Order #0" instead of actual order numbers
2. **Missing Visual Hierarchy**: No parent-child relationship visualization between events
3. **Information Overload**: All event data displayed at same visual priority
4. **Inconsistent Display**: Different event types have inconsistent presentation

**Architecture:** Direct migration from legacy renderer system to new DisplayAdapter system with three-tier UI architecture.

---

## Current System Analysis

### **Problem Root Cause**
The `RegistryTimelineRenderer` delegates to individual renderer classes via:
```php
$rendererClass = odcm_get_renderer_for_event_type($event_type);
$renderer = new $rendererClass();
$result = $renderer->render($payload, $event_type, $timeline);
```

Individual renderers (like `RuleRenderer.php`) have limited order ID extraction that misses rule execution context data, causing "Order #0" display.

### **Existing Architecture**
- `RegistryTimelineRenderer.php` - Main timeline renderer using registry system
- `DisplayAdapter.php` - ✅ EXISTS but NOT INTEGRATED with renderer
- Individual renderer classes (`RuleRenderer.php`, `OrderRenderer.php`, etc.)
- `PayloadComponentRegistry` - Maps event types to renderer classes

### **Integration Gap**
The enhanced `DisplayAdapter::extractOrderId()` method exists with 10+ source paths but is never called because `RegistryTimelineRenderer` bypasses it completely.

---

## Implementation Plan

### **Timeline: 1-2 Weeks**
- **Week 1**: Core system replacement + DisplayAdapter integration
- **Week 2**: UI three-tier architecture + visual hierarchy + polish

### **Direct Migration Approach**
Since the plugin is unpublished, we can make breaking changes without backward compatibility concerns.

---

## Phase 1: Core System Replacement (Days 1-3)

### **Task 1.1: Create AdapterRegistry System**

**File:** `src/API/Timeline/AdapterRegistry.php` (NEW)

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Registry for mapping event types to appropriate display adapters
 */
class AdapterRegistry
{
    private static array $adapters = [];
    
    /**
     * Get appropriate adapter for event payload
     */
    public static function getAdapterForEvent(array $payload): DisplayAdapter
    {
        $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'unknown';
        
        // Priority-based adapter selection
        if (strpos($eventType, 'rule_execution') !== false) {
            return new RuleExecutionAdapter();
        }
        if (strpos($eventType, 'order_') !== false || strpos($eventType, 'status_changed') !== false) {
            return new OrderEventAdapter();  
        }
        if (strpos($eventType, 'payment') !== false || strpos($eventType, 'checkout') !== false) {
            return new PaymentEventAdapter();
        }
        
        return new GenericEventAdapter();
    }
}
```

### **Task 1.2: Replace RegistryTimelineRenderer Core Logic**

**File:** `src/API/Timeline/RegistryTimelineRenderer.php` (MODIFY)

**Replace the `renderComponent()` method:**

```php
/**
 * Render individual component using DisplayAdapter system with three-tier architecture
 */
private function renderComponent(array $payload, bool $isParent = false, bool $isChild = false): string
{
    // Debug Event Filtering - hide debug events in production
    if ($this->shouldFilterDebugEvent($payload)) {
        return '';
    }

    // NEW: Get appropriate adapter and extract standardized data
    $adapter = AdapterRegistry::getAdapterForEvent($payload);
    $displayData = $adapter->extractDisplayData($payload);
    
    // NEW: Render using three-tier architecture
    $result = $this->renderThreeTierComponent($displayData, $payload);
    
    // EXISTING: Apply hierarchy classes
    return $this->applyHierarchyClasses($result, $isParent, $isChild);
}

/**
 * Render component using three-tier architecture
 */
private function renderThreeTierComponent(array $displayData, array $rawPayload): string
{
    $html = '<div class="odcm-component">';
    
    // Extract basic info
    $timestamp = $this->formatTimestamp($rawPayload['ts'] ?? time());
    $level = $rawPayload['level'] ?? 'info';
    
    // Header with timestamp and primary info
    $html .= '<div class="odcm-timeline-header">';
    $html .= '<div class="odcm-timeline-timestamp">' . esc_html($timestamp) . '</div>';
    $html .= '<div class="odcm-timeline-title">' . $this->renderPrimaryInfo($displayData) . '</div>';
    $html .= '</div>';
    
    // Body with three tiers
    $html .= '<div class="odcm-timeline-body">';
    
    // Tier 1: Primary (always visible)
    $html .= $this->renderPrimaryTier($displayData['display_sections']);
    
    // Tier 2: Contextual (expandable)
    if (!empty($displayData['detail_sections'])) {
        $html .= $this->renderContextualTier($displayData['detail_sections']);
    }
    
    // Tier 3: Technical (expandable)
    $html .= $this->renderTechnicalTier($rawPayload);
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Render primary information for header
 */
private function renderPrimaryInfo(array $displayData): string
{
    $sections = $displayData['display_sections'] ?? [];
    
    // Extract key information for header
    $title = $sections['event_description']['value'] ?? 'Timeline Event';
    $orderId = $sections['order_id']['value'] ?? null;
    
    $html = esc_html($title);
    if ($orderId && $orderId !== 0) {
        $html .= ' <span class="order-badge">' . esc_html($orderId) . '</span>';
    }
    
    return $html;
}

/**
 * Render primary tier (always visible)
 */
private function renderPrimaryTier(array $displaySections): string
{
    if (empty($displaySections)) {
        return '';
    }
    
    $html = '<div class="primary-tier">';
    
    foreach ($displaySections as $key => $section) {
        if ($key === 'event_description' || $key === 'order_id') {
            continue; // Already shown in header
        }
        
        $html .= '<div class="field-row">';
        $html .= '<span class="field-label">' . esc_html($section['label']) . ':</span>';
        $html .= '<span class="field-value">' . esc_html($section['value']) . '</span>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Render contextual tier (expandable)
 */
private function renderContextualTier(array $detailSections): string
{
    $html = '<div class="contextual-tier expandable-tier" style="display: none;">';
    $html .= '<button type="button" class="tier-toggle" data-target="contextual">Show Details</button>';
    $html .= '<div class="tier-content">';
    
    foreach ($detailSections as $sectionKey => $section) {
        $html .= '<div class="detail-section">';
        $html .= '<h4>' . esc_html($section['label']) . '</h4>';
        
        if (!empty($section['data'])) {
            foreach ($section['data'] as $field) {
                $html .= '<div class="field-row">';
                $html .= '<span class="field-label">' . esc_html($field['label']) . ':</span>';
                $html .= '<span class="field-value">' . esc_html($field['value']) . '</span>';
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Render technical tier (expandable)
 */
private function renderTechnicalTier(array $rawPayload): string
{
    $html = '<div class="technical-tier expandable-tier" style="display: none;">';
    $html .= '<button type="button" class="tier-toggle" data-target="technical">Show Technical Details</button>';
    $html .= '<div class="tier-content">';
    
    // Format raw payload as JSON
    $jsonPayload = wp_json_encode($rawPayload, JSON_PRETTY_PRINT);
    $html .= '<pre class="raw-payload">' . esc_html($jsonPayload) . '</pre>';
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}
```

---

## Phase 2: Event-Specific Adapters (Days 4-6)

### **Task 2.1: Create RuleExecutionAdapter (Fixes Order #0)**

**File:** `src/API/Timeline/RuleExecutionAdapter.php` (NEW)

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Display adapter for rule execution events
 * Solves the Order #0 issue with enhanced order ID extraction
 */
class RuleExecutionAdapter extends DisplayAdapter
{
    protected function extractSpecializedFields(array $payload): array
    {
        $fields = [];
        
        // Enhanced order ID extraction specifically for rule executions
        $order_id = $this->extractRuleExecutionOrderId($payload);
        
        // Event description
        $ruleName = $this->extractRuleName($payload);
        $fields['event_description'] = [
            'label' => 'Event',
            'value' => "Rule \"{$ruleName}\" executed",
            'section' => 'primary'
        ];
        
        // Order ID (critical for fixing Order #0)
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => 'Order', 
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }
        
        // Rule name
        $fields['rule_name'] = [
            'label' => 'Rule',
            'value' => $ruleName,
            'section' => 'primary'
        ];
        
        // Actions taken
        $actions = $this->extractActionsTaken($payload);
        if (!empty($actions)) {
            $fields['actions_taken'] = [
                'label' => 'Actions',
                'value' => implode(', ', $actions),
                'section' => 'primary'
            ];
        }
        
        // Execution status
        $status = $payload['rule_execution']['status'] ?? 'EXECUTED';
        $fields['execution_status'] = [
            'label' => 'Status',
            'value' => $status,
            'section' => 'primary'
        ];
        
        return $fields;
    }
    
    /**
     * Enhanced order ID extraction specifically for rule execution events
     */
    private function extractRuleExecutionOrderId(array $payload): int
    {
        // Extended sources list for rule executions
        $sources = [
            // Priority 1: Rule execution context (most reliable for rule events)
            $payload['rule_execution']['order_evaluation_context']['order_id'] ?? null,
            $payload['rule_execution']['trigger_event_context']['order_id'] ?? null,
            $payload['rule_execution']['context']['order_id'] ?? null,
            
            // Priority 2: Event trigger context  
            $payload['trigger_event_context']['order_id'] ?? null,
            $payload['trigger_context']['order_id'] ?? null,
            
            // Priority 3: Direct payload
            $payload['order_id'] ?? null,
            $payload['primary_object_id'] ?? null,
            
            // Priority 4: Data nested
            ($payload['data'] ?? [])['order_id'] ?? null,
            ($payload['data'] ?? [])['primary_object_id'] ?? null,
            
            // Priority 5: Technical details
            ($payload['technical_details'] ?? [])['order_id'] ?? null,
            
            // Priority 6: Event data summary
            ($payload['event_data_summary'] ?? [])['order_id'] ?? null,
        ];
        
        foreach ($sources as $source) {
            if (is_numeric($source) && (int)$source > 0) {
                return (int)$source;
            }
        }
        
        return 0;
    }
    
    /**
     * Extract rule name from payload
     */
    private function extractRuleName(array $payload): string
    {
        return $payload['rule_execution']['rule_name'] ?? 
               $payload['rule_name'] ?? 
               $payload['data']['rule_name'] ?? 
               'Unknown Rule';
    }
    
    /**
     * Extract actions taken by the rule
     */
    private function extractActionsTaken(array $payload): array
    {
        $actions = [];
        
        // Check various locations for actions
        $actionData = $payload['rule_execution']['actions'] ?? 
                     $payload['actions_taken'] ?? 
                     $payload['data']['actions'] ?? 
                     [];
        
        if (is_array($actionData)) {
            foreach ($actionData as $action) {
                if (is_string($action)) {
                    $actions[] = $action;
                } elseif (is_array($action) && isset($action['description'])) {
                    $actions[] = $action['description'];
                } elseif (is_array($action) && isset($action['type'])) {
                    $actions[] = ucfirst(str_replace('_', ' ', $action['type']));
                }
            }
        }
        
        return $actions;
    }
}
```

### **Task 2.2: Create OrderEventAdapter**

**File:** `src/API/Timeline/OrderEventAdapter.php` (NEW)

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Display adapter for order-related events
 */
class OrderEventAdapter extends DisplayAdapter
{
    protected function extractSpecializedFields(array $payload): array
    {
        $fields = [];
        
        // Event description
        $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'order_event';
        $fields['event_description'] = [
            'label' => 'Event',
            'value' => $this->formatEventDescription($eventType, $payload),
            'section' => 'primary'
        ];
        
        // Order ID
        $order_id = $this->extractOrderId($payload);
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => 'Order',
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }
        
        // Status change specifics
        if (strpos($eventType, 'status_changed') !== false) {
            $from = $payload['data']['from_status'] ?? $payload['from_status'] ?? '';
            $to = $payload['data']['to_status'] ?? $payload['to_status'] ?? '';
            
            if ($from && $to) {
                $fields['status_change'] = [
                    'label' => 'Status Change',
                    'value' => "{$from} → {$to}",
                    'section' => 'primary'
                ];
            }
        }
        
        // Order total if available
        $total = $payload['order_total'] ?? $payload['data']['order_total'] ?? null;
        if ($total) {
            $fields['order_total'] = [
                'label' => 'Total',
                'value' => $this->formatCurrency($total),
                'section' => 'contextual'
            ];
        }
        
        return $fields;
    }
    
    private function formatEventDescription(string $eventType, array $payload): string
    {
        switch ($eventType) {
            case 'status_changed':
                return 'Order Status Changed';
            case 'order_created':
                return 'Order Created';
            case 'order_updated':
                return 'Order Updated';
            default:
                return ucfirst(str_replace('_', ' ', $eventType));
        }
    }
    
    private function formatCurrency($amount): string
    {
        if (is_numeric($amount)) {
            return '$' . number_format((float)$amount, 2);
        }
        return (string)$amount;
    }
}
```

### **Task 2.3: Create PaymentEventAdapter**

**File:** `src/API/Timeline/PaymentEventAdapter.php` (NEW)

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Display adapter for payment-related events
 */
class PaymentEventAdapter extends DisplayAdapter
{
    protected function extractSpecializedFields(array $payload): array
    {
        $fields = [];
        
        // Event description
        $eventType = $payload['event_type'] ?? 'payment_event';
        $fields['event_description'] = [
            'label' => 'Event',
            'value' => $this->formatPaymentEventDescription($eventType, $payload),
            'section' => 'primary'
        ];
        
        // Order ID
        $order_id = $this->extractOrderId($payload);
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => 'Order',
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }
        
        // Payment method
        $paymentMethod = $payload['payment_method'] ?? 
                        $payload['data']['payment_method'] ?? 
                        $payload['payment_context']['payment_method'] ?? '';
        if ($paymentMethod) {
            $fields['payment_method'] = [
                'label' => 'Payment Method',
                'value' => $paymentMethod,
                'section' => 'primary'
            ];
        }
        
        // Amount
        $amount = $payload['total_amount'] ?? 
                 $payload['amount'] ?? 
                 $payload['payment_context']['total_amount'] ?? null;
        if ($amount) {
            $fields['amount'] = [
                'label' => 'Amount',
                'value' => $this->formatCurrency($amount),
                'section' => 'primary'
            ];
        }
        
        return $fields;
    }
    
    private function formatPaymentEventDescription(string $eventType, array $payload): string
    {
        switch ($eventType) {
            case 'payment_completed':
                return 'Payment Completed';
            case 'payment_failed':
                return 'Payment Failed';
            case 'checkout_processed':
                return 'Checkout Processed';
            default:
                return ucfirst(str_replace('_', ' ', $eventType));
        }
    }
    
    private function formatCurrency($amount): string
    {
        if (is_numeric($amount)) {
            return '$' . number_format((float)$amount, 2);
        }
        return (string)$amount;
    }
}
```

### **Task 2.4: Create GenericEventAdapter**

**File:** `src/API/Timeline/GenericEventAdapter.php` (NEW)

```php
<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Generic display adapter for unknown event types
 */
class GenericEventAdapter extends DisplayAdapter
{
    protected function extractSpecializedFields(array $payload): array
    {
        $fields = [];
        
        // Event description
        $eventType = $payload['event_type'] ?? 'unknown_event';
        $fields['event_description'] = [
            'label' => 'Event',
            'value' => ucfirst(str_replace('_', ' ', $eventType)),
            'section' => 'primary'
        ];
        
        // Order ID if available
        $order_id = $this->extractOrderId($payload);
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => 'Order',
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }
        
        return $fields;
    }
}
```

---

## Phase 3: UI Implementation (Days 7-10)

### **Task 3.1: Upgrade Expand/Collapse Functionality**

**Note:** User feedback indicates existing expand/collapse CSS and JS exists but needs upgrade.

**File:** Update existing JavaScript file (probably in `plugin/assets/js/`)

```javascript
/**
 * Enhanced three-tier expand/collapse system for timeline components
 */
(function($) {
    'use strict';
    
    /**
     * Initialize the enhanced timeline UI
     */
    function initTimelineUI() {
        // Remove any old expand/collapse handlers
        $(document).off('click', '.tier-toggle');
        
        // Enhanced tier toggle handler
        $(document).on('click', '.tier-toggle', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const target = $btn.data('target');
            const $timelineItem = $btn.closest('.odcm-component');
            const $targetTier = $timelineItem.find(`.${target}-tier .tier-content`);
            
            if ($targetTier.is(':visible')) {
                $targetTier.slideUp(200);
                $btn.text($btn.text().replace('Hide', 'Show'));
                $timelineItem.removeClass(`${target}-expanded`);
            } else {
                $targetTier.slideDown(200);
                $btn.text($btn.text().replace('Show', 'Hide'));
                $timelineItem.addClass(`${target}-expanded`);
            }
        });
        
        // Enhanced keyboard accessibility
        $(document).on('keydown', '.tier-toggle', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).click();
            }
        });
    }
    
    // Initialize on document ready
    $(document).ready(initTimelineUI);
    
    // Re-initialize after AJAX content loads
    $(document).on('odcm:timeline-updated', initTimelineUI);
    
})(jQuery);
```

### **Task 3.2: Verify and Use Existing CSS Classes**

**Note:** User confirms the class is `odcm-component` (not `odcm-timeline-component`) and styles are already refined.

Verify the following CSS classes exist and are being used correctly:

```css
/* Expected existing classes - verify these exist and work with new structure */
.odcm-component.is-parent {
    /* Parent event styling */
}

.odcm-component.is-child {
    /* Child event styling */
}
```

**Additional CSS for three-tier system (add to existing stylesheets):**

```css
/* Three-tier architecture styles */
.odcm-component .primary-tier {
    margin-bottom: 10px;
}

.odcm-component .expandable-tier {
    margin-top: 10px;
    border-top: 1px solid #e1e1e1;
    padding-top: 8px;
}

.odcm-component .tier-toggle {
    background: none;
    border: none;
    color: #0073aa;
    cursor: pointer;
    font-size: 12px;
    text-decoration: underline;
    padding: 0;
}

.odcm-component .tier-toggle:hover {
    color: #005a87;
}

.odcm-component .tier-content {
    margin-top: 8px;
}

.odcm-component .field-row {
    margin-bottom: 5px;
}

.odcm-component .field-label {
    font-weight: bold;
    margin-right: 8px;
}

.odcm-component .order-badge {
    background: #0073aa;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    margin-left: 8px;
}

.odcm-component .raw-payload {
    background: #f1f1f1;
    padding: 10px;
    font-size: 11px;
    margin: 0;
    max-height: 300px;
    overflow-y: auto;
}
```

---

## Phase 4: Remove Legacy System (Days 11-12)

### **Task 4.1: Remove Old Renderer Classes**

Since this is a direct migration with no backward compatibility needed:

**Delete these files:**
- `src/View/PayloadRenderer/RuleRenderer.php`
- `src/View/PayloadRenderer/OrderRenderer.php`
- Any other specific renderer classes that are no longer needed

**Keep these files:**
- `src/View/PayloadRenderer/BaseRenderer.php` (if other parts of system use it)
- `src/View/PayloadRenderer/FallbackRenderer.php` (for error cases)

### **Task 4.2: Update AdapterRegistry to autoload**

Add autoloading for new adapter classes in appropriate composer.json or bootstrap file.

---

## Testing Plan (Days 13-14)

### **Test Scenarios**

1. **Order #0 Fix Test:**
   - Create order that triggers rule execution
   - Verify timeline shows actual order number (not "Order #0")
   - Test with different rule types

2. **Visual Hierarchy Test:**
   - Verify parent-child relationships display correctly
   - Check `is-parent` and `is-child` CSS classes are applied
   - Test with nested event chains

3. **Three-Tier UI Test:**
   - Verify primary tier always visible
   - Test expand/collapse for contextual and technical tiers
   - Check keyboard accessibility

4. **Multiple Event Types Test:**
   - Test RuleExecutionAdapter with rule events
   - Test OrderEventAdapter with order status changes
   - Test PaymentEventAdapter with payment events
   - Test GenericEventAdapter fallback

### **Success Criteria**

**Phase 1 Success:**
- ✅ Zero "Order #0" events in timeline
- ✅ All events show correct order numbers
- ✅ No PHP errors in timeline rendering

**Phase 2 Success:**
- ✅ All event types use appropriate adapters
- ✅ DisplayData extracted correctly for all events
- ✅ Consistent information presentation

**Phase 3 Success:**  
- ✅ Three-tier UI working (Primary/Contextual/Technical)
- ✅ Expand/collapse functionality smooth
- ✅ Visual hierarchy clear for parent-child relationships

**Phase 4 Success:**
- ✅ No legacy code remnants
- ✅ Clean adapter-based architecture
- ✅ All tests passing

---

## WordPress Compliance Notes

### **Security Requirements**
- All output must use `esc_html()`, `esc_attr()`, or `wp_kses_post()`  
- All database queries must use `$wpdb->prepare()`
- All user inputs must be sanitized

### **Coding Standards**
- Follow WordPress coding standards
- Use proper PHPDoc blocks
- Implement proper error handling
- Follow naming conventions

### **Performance**
- Minimize database queries
- Use appropriate caching where needed
- Avoid N+1 query patterns

---

## Implementation Checkpoints

### **After Phase 1:**
- [ ] AdapterRegistry created and functional
- [ ] RegistryTimelineRenderer modified to use DisplayAdapter
- [ ] Order #0 issue resolved
- [ ] No regressions in timeline display

### **After Phase 2:**  
- [ ] All adapter classes created (Rule, Order, Payment, Generic)
- [ ] Order ID extraction working for all event types
- [ ] Consistent data extraction across events

### **After Phase 3:**
- [ ] Three-tier UI fully implemented
- [ ] Existing CSS classes verified and used
- [ ] Enhanced expand/collapse functionality working
- [ ] Visual hierarchy clear

### **After Phase 4:**
- [ ] Legacy renderer classes removed
- [ ] Clean adapter-based architecture
- [ ] All tests passing
- [ ] Timeline redesign complete

---

## Final Notes

This implementation completely replaces the legacy renderer system with a modern DisplayAdapter architecture. The Order #0 issue is solved through enhanced order ID extraction in the RuleExecutionAdapter. The three-tier UI provides better information organization while maintaining the existing `odcm-component` CSS framework.

**Key Success Metric:** No "Order #0" events should appear in the timeline after implementation, and all events should display with clear visual hierarchy and appropriate information organization.
