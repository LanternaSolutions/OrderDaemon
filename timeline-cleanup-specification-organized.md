# Timeline Cleanup Specification - Organized Version

## Table of Contents
1. [Overview & Goals](#overview--goals)
2. [General Requirements](#general-requirements)
3. [Component-Specific Requirements](#component-specific-requirements)
4. [Visual Design Requirements](#visual-design-requirements)
5. [Event Type Mapping System](#event-type-mapping-system)
6. [Technical Implementation](#technical-implementation)
7. [Quality Assurance](#quality-assurance)
8. [Appendices](#appendices)

## Overview & Goals

This document defines the requirements and specifications for cleaning up the timeline display in the Order Daemon insight dashboard. The primary objectives are:

- Remove duplicate information from timeline components
- Show only business-relevant data in main sections
- Preserve technical details in collapsed sections for debugging
- Implement consistent visual design with icons and theming
- Ensure WordPress Plugin Checker compliance

## General Requirements

### 1. Debug Mode Control
- **Event Type Visibility**: All `event_type` fields should only be visible when `odcm_debug = true`
- **Implementation**: Use conditional rendering based on the debug flag
- **Data Preservation**: Technical data must remain in raw data sections regardless of debug mode

### 2. Field Formatting Requirements
- **Amount and Currency**: Combine into single formatted string (e.g., "10 USD")
- **Customer Information**: Format as "Customer: [first name] [last name] [or username as fallback] (ID: [customer id])"
- **Status Changes**: Display transitions as "[from status] → [to status]"

### 3. Duplicate Removal
- Remove all duplicate key-value pairs within the same component
- Prioritize business-relevant information in main sections
- Keep technical details in collapsed technical sections
- Maintain data integrity by preserving all original data

## Component-Specific Requirements

### Order Created Component
**Main Section (always visible):**
- Timestamp: [actual timestamp]
- Customer: [formatted customer name with ID]
- Payment Method: [payment method]
- Amount: [amount] [currency]

**Additional Details:**
- Order: [order ID]
- Status: [order status]

**Debug Mode Only:**
- Event Type: order_created

### Order Status Changed Component
**Main Section:**
- Timestamp: [actual timestamp]
- Previous Status: [from status]
- New Status: [to status]
- Change Type: [change type]

**Debug Mode Only:**
- Event Type: status_changed

### Checkout Processed Component
**Main Section:**
- Timestamp: [actual timestamp]
- Payment Method: [payment method]
- Payment Status: [payment status]
- Amount: [amount] [currency]

**Payment Details:**
- Checkout Type: [checkout type]

**Debug Mode Only:**
- Event Type: checkout_processed

### Payment Events Component
**Main Section:**
- Timestamp: [actual timestamp]
- Payment Method: [payment method]
- Amount: [amount] [currency]

**Debug Mode Only:**
- Event Type: payment.[gateway].checkout_processed

### Rule Execution Component
**Main Section:**
- Timestamp: [actual timestamp]
- Rule: [rule name]
- Execution Status: [execution status]
- Status Change: [from status] → [to status]

**Debug Mode Only:**
- Event Type: rule_execution

## Visual Design Requirements

### Status Pill Enhancements

#### Current Implementation Issues
- Redundant information in status pills (order numbers already in component title)
- Poor visual hierarchy disrupting component headers
- Left-aligned pills inconsistent with timeline flow
- Missing semantic status information (success, error, warning, etc.)
- Inconsistent usage across different event types

#### New Requirements
1. **Status Display**: Replace order numbers with meaningful event status information
   - Show primary status relevant to each event type
   - Prioritize business-relevant status for events with multiple statuses
   - Use existing status pill color coding system

2. **Right Alignment**: Move status pills to right side of component headers
   - Improves visual scanning of timeline
   - Creates consistent right-aligned status column
   - Maintains left side for component titles and icons

3. **Status Selection Logic**:
   - **Order Created**: Show "pending" or initial order status
   - **Status Changed**: Show "→ completed" or transition indicator
   - **Checkout Processed**: Show no status pill
   - **Payment Events**: Show payment status ("completed", "failed", etc.)
   - **Rule Execution**: Show "success" or execution result
   Confirm all status pill choices with the user, especially when there are several options for statuses to chose from within a single event's data.

### Status Pill Implementation Specification

#### Core Issue Analysis

The primary issue is in the `RegistryTimelineRenderer::renderPrimaryInfo()` method where status pills are being used incorrectly:

```php
// Current incorrect implementation
$html .= ' <span class="odcm-status-pill">' . esc_html($orderDisplay) . '</span>';
```

This shows order IDs in status pills without proper semantic status types, which violates the status pill design pattern.

#### Targeted Solution Approach

The status pill implementation should focus on **header-only usage** with semantic status information, avoiding excessive use throughout component bodies.

#### Status Pill Component Usage

The `odcm-status-pill` component should be used **primarily in component headers** to display semantic status information following this pattern:

```html
<span class="odcm-status-pill odcm-status-pill--{status_type}">{label}</span>
```

#### Status Type Mapping

| Status Type | CSS Class | Usage Context | Color Theme |
|-------------|-----------|---------------|-------------|
| success | `odcm-status-pill--success` | Successful operations, completed events | Green |
| error | `odcm-status-pill--error` | Failed operations, critical issues | Red |
| warning | `odcm-status-pill--warning` | Warning conditions, potential issues | Yellow |
| info | `odcm-status-pill--info` | Informational status, neutral events | Grey |
| completed | `odcm-status-pill--completed` | Completed processes, final states | Green |
| pending | `odcm-status-pill--pending` | Pending operations, waiting states | Yellow |
| skipped | `odcm-status-pill--skipped` | Skipped operations, bypassed events | Grey |

#### Focused Implementation Requirements

1. **RegistryTimelineRenderer Fix** (Primary Issue):
   - Update `renderPrimaryInfo()` method to use proper status pill rendering
   - Replace plain order ID spans with semantic status pills showing actual event status
   - Extract meaningful status information from display data

2. **DisplayAdapter Enhancement**:
   - Add `renderStatusPill()` helper method for consistent status pill generation
   - Add status mapping logic to convert event statuses to appropriate pill types

3. **Status Extraction Logic**:
   - Extract primary status from each event type
   - Map status values to semantic pill types
   - Ensure status pills show meaningful information, not just IDs

#### Status Pill Generation Method

```php
/**
 * Generate status pill HTML for timeline component headers
 *
 * @param string $label Display text for the status pill
 * @param string $status_type Status type for CSS theming
 * @return string HTML status pill element
 */
protected function renderStatusPill(string $label, string $status_type): string
{
    // Map semantic types to existing pill variants
    $pill_variant_map = [
        'error' => 'error',
        'warning' => 'warning',
        'success' => 'success',
        'info' => 'info',
        'completed' => 'completed',
        'pending' => 'pending',
        'skipped' => 'skipped'
    ];

    // Get the appropriate pill variant, default to 'info' for unknown types
    $pill_class = $pill_variant_map[strtolower($status_type)] ?? 'info';

    return '<span class="odcm-status-pill odcm-status-pill--' . esc_attr($pill_class) . '">' .
           esc_html($label) . '</span>';
}
```

#### Status Extraction and Mapping Logic

```php
/**
 * Extract primary status from display data for status pill
 *
 * @param array $displayData The display data from adapter
 * @param array $rawPayload The original event payload
 * @return array|null Array with 'label' and 'type' for status pill, or null if no status
 */
protected function extractPrimaryStatus(array $displayData, array $rawPayload): ?array
{
    $eventType = $rawPayload['event_type'] ?? 'unknown';

    // Try to extract status from display sections first
    $statusFields = ['status', 'order_status', 'payment_status', 'execution_status', 'status_change'];

    foreach ($statusFields as $field) {
        if (isset($displayData['display_sections'][$field])) {
            $statusValue = $displayData['display_sections'][$field]['value'] ?? '';

            // Map status to pill type based on event type
            $pillType = $this->mapStatusToPillType($eventType, $statusValue);

            return [
                'label' => $statusValue,
                'type' => $pillType
            ];
        }
    }

    // Fallback: try to extract from raw payload
    if (isset($rawPayload['status'])) {
        $pillType = $this->mapStatusToPillType($eventType, $rawPayload['status']);
        return [
            'label' => $rawPayload['status'],
            'type' => $pillType
        ];
    }

    return null;
}

/**
 * Map status value to appropriate status pill type
 *
 * @param string $eventType The event type
 * @param string $statusValue The status value
 * @return string Status pill type
 */
protected function mapStatusToPillType(string $eventType, string $statusValue): string
{
    $statusMap = [
        // Order statuses
        'pending' => 'info',
        'processing' => 'info',
        'on-hold' => 'warning',
        'completed' => 'success',
        'cancelled' => 'warning',
        'refunded' => 'info',
        'failed' => 'error',

        // Payment statuses
        'paid' => 'success',
        'completed' => 'success',
        'failed' => 'error',
        'pending' => 'info',
        'processing' => 'info',
        'refunded' => 'info',
        'cancelled' => 'warning',

        // Rule execution statuses
        'success' => 'success',
        'failed' => 'error',
        'skipped' => 'info',
        'executed' => 'success',

        // Generic statuses
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
        'debug' => 'info'
    ];

    return $statusMap[strtolower($statusValue)] ?? 'info';
}
```

#### Updated RegistryTimelineRenderer Implementation

```php
/**
 * Render primary information for header with proper status pill
 *
 * @param array $displayData The display data from adapter
 * @return string Rendered primary info HTML
 */
private function renderPrimaryInfo(array $displayData): string
{
    $sections = $displayData['display_sections'] ?? [];

    // Extract key information for header
    $title = $sections['event_description']['value'] ?? 
            $sections['event_type']['value'] ?? 
            __('Timeline Event', 'order-daemon');

    // Extract primary status for status pill
    $statusPill = null;
    if (method_exists($this, 'extractPrimaryStatus')) {
        $statusData = $this->extractPrimaryStatus($displayData, $payload ?? []);
        if ($statusData && method_exists($this, 'renderStatusPill')) {
            $statusPill = $this->renderStatusPill($statusData['label'], $statusData['type']);
        }
    }

    $html = esc_html($title);

    // Add status pill if available
    if ($statusPill) {
        $html .= ' ' . $statusPill;
    }

    return $html;
}
```

#### CSS Implementation for Status Pills

```css
/* Status Pills - Using Three-Tier System */
.odcm-status-pill {
    display: inline-block;
    padding: 0.25em 0.6em;
    font-size: var(--odcm-theme-font-size-sm);
    border-radius: var(--odcm-component-border-radius);
    background-color: var(--odcm-status-bg);
    color: var(--odcm-status-text-color);
    font-weight: 500;
    text-transform: capitalize;
    margin-left: 8px;
}

/* Status variants override the semantic status variables */
.odcm-status-pill--success {
    --odcm-status-bg: var(--odcm-theme-green-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--error {
    --odcm-status-bg: var(--odcm-theme-red-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--warning {
    --odcm-status-bg: var(--odcm-theme-yellow-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--info {
    --odcm-status-bg: var(--odcm-theme-grey-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}
```

#### Focused Implementation Checklist

1. **DisplayAdapter Base Class**:
   - [ ] Add `renderStatusPill()` method for consistent status pill generation
   - [ ] Add `mapStatusToPillType()` method for status mapping
   - [ ] Add `extractPrimaryStatus()` method for status extraction

2. **RegistryTimelineRenderer** (Primary Fix):
   - [ ] Update `renderPrimaryInfo()` method to use proper status pill rendering
   - [ ] Replace plain order ID spans with semantic status pills
   - [ ] Ensure status pills show meaningful status information

3. **CSS Updates**:
   - [ ] Verify status pill styling is consistent
   - [ ] Ensure proper spacing and alignment
   - [ ] Test responsive behavior

#### Testing Requirements

1. **Visual Consistency**:
   - Verify status pills appear in component headers only
   - Check color coding matches status semantics
   - Ensure proper spacing and alignment

2. **Semantic Accuracy**:
   - Verify status pill types match event statuses correctly
   - Ensure status pills show meaningful information, not just IDs
   - Confirm no redundant information in status pills

3. **Functional Testing**:
   - Test status extraction from different event types
   - Verify status mapping logic works correctly
   - Check fallback behavior for events without explicit status

This focused status pill implementation addresses the core issue while maintaining a clean, minimal approach that avoids excessive use of status pills throughout the components.
## Visual Design Requirements

### Status Pill Enhancements

#### Current Implementation Issues
- Redundant information in status pills (order numbers already in component title)
- Poor visual hierarchy disrupting component headers
- Left-aligned pills inconsistent with timeline flow
- Missing semantic status information (success, error, warning, etc.)
- Inconsistent usage across different event types

#### New Requirements
1. **Status Display**: Replace order numbers with meaningful event status information
   - Show primary status relevant to each event type
   - Prioritize business-relevant status for events with multiple statuses
   - Use existing status pill color coding system

2. **Right Alignment**: Move status pills to right side of component headers
   - Improves visual scanning of timeline
   - Creates consistent right-aligned status column
   - Maintains left side for component titles and icons

3. **Status Selection Logic**:
   - **Order Created**: Show "pending" or initial order status
   - **Status Changed**: Show "→ completed" or transition indicator
   - **Checkout Processed**: Show no status pill
   - **Payment Events**: Show payment status ("completed", "failed", etc.)
   - **Rule Execution**: Show "success" or execution result
   Confirm all status pill choices with the user, especially when there are several options for statuses to chose from within a single event's data.

### Status Pill Implementation Specification

#### Status Pill Component Usage

The `odcm-status-pill` component should be used consistently across all timeline components to display semantic status information. The component follows this pattern:

```html
<span class="odcm-status-pill odcm-status-pill--{status_type}">{label}</span>
```

#### Status Type Mapping

| Status Type | CSS Class | Usage Context | Color Theme |
|-------------|-----------|---------------|-------------|
| success | `odcm-status-pill--success` | Successful operations, completed events | Green |
| error | `odcm-status-pill--error` | Failed operations, critical issues | Red |
| warning | `odcm-status-pill--warning` | Warning conditions, potential issues | Yellow |
| info | `odcm-status-pill--info` | Informational status, neutral events | Grey |
| completed | `odcm-status-pill--completed` | Completed processes, final states | Green |
| pending | `odcm-status-pill--pending` | Pending operations, waiting states | Yellow |
| skipped | `odcm-status-pill--skipped` | Skipped operations, bypassed events | Grey |
| woocommerce | `odcm-status-pill--woocommerce` | WooCommerce-specific statuses | Purple |

#### Event Type to Status Pill Mapping

| Event Type Category | Status Field | Status Pill Type | Display Format |
|---------------------|--------------|------------------|----------------|
| **Order Events** | order_status | Based on status value | "pending", "completed", "cancelled", etc. |
| **Status Changes** | status_change | success | "[from] → [to]" |
| **Payment Events** | payment_status | Based on status value | "completed", "failed", "pending" |
| **Rule Execution** | execution_status | Based on status value | "success", "failed", "skipped" |
| **Checkout Events** | checkout_status | success | "completed" |
| **Error Events** | error_status | error | "error", "failed" |
| **Debug Events** | debug_level | debug | "debug", "trace" |

#### Implementation Requirements for Display Adapters

1. **OrderEventAdapter**:
   - Use status pills for order status fields
   - Map order statuses to appropriate pill types (pending→info, completed→success, cancelled→warning, failed→error)
   - Show status transitions as "[from] → [to]" with success pill

2. **PaymentEventAdapter**:
   - Use status pills for payment status fields
   - Map payment statuses (completed→success, failed→error, pending→info)
   - Include payment gateway-specific statuses when available

3. **RuleExecutionAdapter**:
   - Use status pills for execution status
   - Map execution results (success→success, failed→error, skipped→info)
   - Show rule execution outcomes clearly

4. **RegistryTimelineRenderer**:
   - Update `renderPrimaryInfo()` method to use proper status pill rendering
   - Replace plain order ID spans with semantic status pills
   - Ensure status pills are right-aligned in component headers

#### Status Pill Generation Method

```php
/**
 * Generate status pill HTML for timeline components
 *
 * @param string $label Display text for the status pill
 * @param string $status_type Status type for CSS theming
 * @return string HTML status pill element
 */
protected function renderStatusPill(string $label, string $status_type): string
{
    // Map semantic types to existing pill variants
    $pill_variant_map = [
        'error' => 'error',
        'warning' => 'warning',
        'success' => 'success',
        'woocommerce' => 'woocommerce',
        'completion' => 'completed',
        'critical' => 'critical',
        'info' => 'info',
        'debug' => 'debug',
        'pending' => 'pending',
        'skipped' => 'skipped',
        'notice' => 'notice'
    ];

    // Get the appropriate pill variant, default to 'info' for unknown types
    $pill_class = $pill_variant_map[strtolower($status_type)] ?? 'info';

    return '<span class="odcm-status-pill odcm-status-pill--' . esc_attr($pill_class) . '">' .
           esc_html($label) . '</span>';
}
```

#### Status Mapping Logic

```php
/**
 * Map event status to appropriate status pill type
 *
 * @param string $event_type The event type
 * @param string $status_value The status value
 * @return string Status pill type
 */
protected function mapStatusToPillType(string $event_type, string $status_value): string
{
    // Order status mapping
    if (strpos($event_type, 'order_') === 0) {
        $order_status_map = [
            'pending' => 'info',
            'processing' => 'info',
            'on-hold' => 'warning',
            'completed' => 'success',
            'cancelled' => 'warning',
            'refunded' => 'info',
            'failed' => 'error'
        ];
        return $order_status_map[strtolower($status_value)] ?? 'info';
    }

    // Payment status mapping
    if (strpos($event_type, 'payment_') === 0) {
        $payment_status_map = [
            'completed' => 'success',
            'failed' => 'error',
            'pending' => 'info',
            'processing' => 'info',
            'refunded' => 'info',
            'cancelled' => 'warning'
        ];
        return $payment_status_map[strtolower($status_value)] ?? 'info';
    }

    // Rule execution status mapping
    if (strpos($event_type, 'rule_') === 0) {
        $rule_status_map = [
            'success' => 'success',
            'failed' => 'error',
            'skipped' => 'info',
            'executed' => 'success',
            'completed' => 'success'
        ];
        return $rule_status_map[strtolower($status_value)] ?? 'info';
    }

    // Default mapping for other event types
    $default_status_map = [
        'success' => 'success',
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
        'debug' => 'debug',
        'critical' => 'error'
    ];

    return $default_status_map[strtolower($status_value)] ?? 'info';
}
```

#### CSS Implementation for Status Pills

```css
/* Status Pills - Using Three-Tier System */
.odcm-status-pill {
    display: inline-block;
    padding: 0.25em 0.6em;
    font-size: var(--odcm-theme-font-size-sm);
    border-radius: var(--odcm-component-border-radius);
    background-color: var(--odcm-status-bg);
    color: var(--odcm-status-text-color);
    font-weight: 500;
    text-transform: capitalize;
}

/* Status variants override the semantic status variables */
.odcm-status-pill--critical {
    --odcm-status-bg: var(--odcm-theme-red-900);
    --odcm-status-text-color: var(--odcm-theme-white);
}

.odcm-status-pill--error {
    --odcm-status-bg: var(--odcm-theme-red-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--warning {
    --odcm-status-bg: var(--odcm-theme-yellow-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--success {
    --odcm-status-bg: var(--odcm-theme-green-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--notice {
    --odcm-status-bg: var(--odcm-theme-green-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--info {
    --odcm-status-bg: var(--odcm-theme-grey-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--debug {
    --odcm-status-bg: var(--odcm-theme-yellow-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--completed {
    --odcm-status-bg: var(--odcm-theme-green-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--pending {
    --odcm-status-bg: var(--odcm-theme-yellow-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--skipped {
    --odcm-status-bg: var(--odcm-theme-grey-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}

.odcm-status-pill--woocommerce {
    --odcm-status-bg: var(--odcm-theme-purple-400);
    --odcm-status-text-color: var(--odcm-theme-grey-900);
}
```

#### Component Header Layout with Status Pills

```css
/* Right-aligned status pill container */
.odcm-component__header-right {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
}

/* Component header restructuring */
.odcm-component__header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Component header with icon integration */
.odcm-component__header-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.odcm-component-icon.dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: inherit;
}
```

#### Implementation Checklist

1. **DisplayAdapter Base Class**:
   - [ ] Add `renderStatusPill()` method
   - [ ] Add `mapStatusToPillType()` method
   - [ ] Update `organizeIntoSections()` to include status pill data

2. **OrderEventAdapter**:
   - [ ] Modify status field extraction to use status pills
   - [ ] Update status change formatting to use proper pill types
   - [ ] Ensure order statuses map to appropriate pill variants

3. **PaymentEventAdapter**:
   - [ ] Add status pill generation for payment statuses
   - [ ] Map payment statuses to appropriate pill types
   - [ ] Include gateway-specific status handling

4. **RuleExecutionAdapter**:
   - [ ] Update execution status display to use status pills
   - [ ] Map rule execution results to appropriate pill types
   - [ ] Ensure status transitions use proper formatting

5. **RegistryTimelineRenderer**:
   - [ ] Update `renderPrimaryInfo()` to use proper status pill rendering
   - [ ] Replace plain spans with semantic status pills
   - [ ] Ensure right-alignment of status pills in headers

6. **CSS Updates**:
   - [ ] Verify status pill styling is consistent
   - [ ] Ensure right-alignment works correctly
   - [ ] Test responsive behavior

#### Testing Requirements for Status Pills

1. **Visual Consistency**:
   - Verify status pills appear right-aligned in component headers
   - Check color coding matches status semantics
   - Ensure proper spacing and alignment

2. **Semantic Accuracy**:
   - Verify status pill types match event statuses correctly
   - Check status transitions are properly formatted
   - Ensure no redundant information in status pills

3. **Responsive Behavior**:
   - Test status pill display on different screen sizes
   - Verify mobile responsiveness
   - Check overflow handling for long status texts

4. **Accessibility**:
   - Ensure status pills have proper contrast ratios
   - Verify keyboard navigation works correctly
   - Check screen reader compatibility

5. **Performance**:
   - Measure rendering performance impact
   - Verify no significant performance degradation
   - Check DOM complexity remains reasonable

This comprehensive status pill implementation will ensure consistent, semantic, and visually appealing status display across all timeline components while maintaining the existing design system and component architecture.

### Dashicons Integration

#### Legacy System Analysis
- Existing renderer system used WordPress dashicons for visual event identification
- Icons provide quick visual recognition of event types
- Icons are not consistently used in the new timeline system

#### New Requirements
1. **Event Type Mapping**: Assign specific dashicons to each event type
   - Use WordPress dashicons library for consistency
   - Map icons based on event semantics and user expectations

2. **Positioning**: Place icons in top-left corner of component headers
   - Left of component title
   - Aligned with header content
   - Consistent spacing and sizing

3. **Color Theming**: Use component theme colors for icons
   - Inherit from component theme variables
   - Maintain visual consistency with component borders

## Event Type Mapping System

### Comprehensive Event Type Configuration

This centralized mapping system defines all visual attributes for each event type to ensure consistency and maintainability.

#### Event Type Attributes
| Attribute | Description | Example |
|-----------|-------------|---------|
| **Event Type** | Unique identifier | `order_created` |
| **Dashicon** | WordPress dashicon class | `dashicons-cart` |
| **Theme Class** | CSS theme class | `odcm-component--order` |
| **Primary Color** | Theme color variable | `var(--odcm-theme-purple-700)` |
| **Status Display** | How to format status | "pending" |
| **Priority** | Visual importance (1-5) | 3 |
| **Category** | Event classification | Order Lifecycle |

#### Complete Event Type Mapping

| Event Type Category | Specific Event Types | Dashicon | Theme Class | Primary Color | Status Display | Priority | Category |
|---------------------|----------------------|----------|-------------|---------------|----------------|----------|----------|
| **Order Creation** | `order_created` | `dashicons-cart` | `odcm-component--order` | `purple-700` | "pending" | 4 | Order Lifecycle |
| **Order Status** | `order_updated`, `order_completed`, `order_cancelled`, `order_refunded`, `order_processing`, `order_on_hold`, `order_pending`, `order_failed` | `dashicons-update` | `odcm-component--order` | `purple-700` | Event-specific | 3 | Order Lifecycle |
| **Status Changes** | `status_changed` | `dashicons-migrate` | `odcm-component--order` | `purple-700` | "→ [to status]" | 3 | Order Lifecycle |
| **Checkout** | `checkout_processed` | `dashicons-money-alt` | `odcm-component--payment` | `green-700` | "checkout-draft" | 3 | Payment |
| **Payments** | `payment.*`, `payment_completed`, `payment_failed` | `dashicons-payment` | `odcm-component--payment` | `green-700` | Payment status | 3 | Payment |
| **Stripe Payments** | `payment.stripe.*` | `dashicons-credit-card` | `odcm-component--payment` | `green-700` | Payment status | 3 | Payment |
| **PayPal Payments** | `payment.paypal.*` | `dashicons-paypal` | `odcm-component--payment` | `green-700` | Payment status | 3 | Payment |
| **Rule Execution** | `rule_execution`, `rule_evaluation_non_canonical` | `dashicons-admin-generic` | `odcm-component--rule` | `blue-700` | "success"/"failed" | 2 | Rule |
| **System Events** | `admin_action`, `process_started`, `info`, `metrics`, `system_info` | `dashicons-admin-tools` | `odcm-component--system` | `grey-700` | Event-specific | 1 | System |
| **Refunds** | `refund_created`, `refund_deleted`, `refund_analysis` | `dashicons-undo` | `odcm-component--order` | `purple-700` | "refunded" | 3 | Order Lifecycle |
| **Subscriptions** | `subscription_*` | `dashicons-calendar-alt` | `odcm-component--system` | `grey-700` | "recurring" | 2 | System |
| **Webhooks** | `webhook_*` | `dashicons-networking` | `odcm-component--system` | `grey-700` | "webhook" | 1 | System |
| **Conditions** | `condition_passed`, `condition_failed` | `dashicons-yes-alt`, `dashicons-no-alt` | `odcm-component--rule` | `blue-700` | "passed"/"failed" | 2 | Rule |
| **Errors/Debug** | `fallback`, `debug`, `_status_evaluation` | `dashicons-warning` | `odcm-component--error` | `red-700` | "error" | 1 | System |
| **Universal Events** | `universal_event_processing`, `universal_event_duplicate` | `dashicons-admin-site` | `odcm-component--system` | `grey-700` | "processed" | 1 | System |

#### Implementation Strategy

```php
/**
 * Get comprehensive event type configuration
 *
 * @param string $event_type The event type
 * @return array Event configuration
 */
private function getEventTypeConfig(string $event_type): array {
    $event_configs = [
        // Order events
        'order_created' => [
            'dashicon' => 'dashicons-cart',
            'theme_class' => 'odcm-component--order',
            'primary_color' => 'purple-700',
            'status_display' => 'pending',
            'priority' => 4,
            'category' => 'Order Lifecycle'
        ],
        // ... additional event type configurations
    ];

    // Check for exact match
    if (isset($event_configs[$event_type])) {
        return $event_configs[$event_type];
    }

    // Check for patterns
    if (strpos($event_type, 'payment.stripe.') === 0) {
        return [
            'dashicon' => 'dashicons-credit-card',
            'theme_class' => 'odcm-component--payment',
            'primary_color' => 'green-700',
            'status_display' => 'payment',
            'priority' => 3,
            'category' => 'Payment'
        ];
    }

    // Default fallback
    return [
        'dashicon' => 'dashicons-admin-generic',
        'theme_class' => 'odcm-component--system',
        'primary_color' => 'grey-700',
        'status_display' => 'event',
        'priority' => 1,
        'category' => 'System'
    ];
}
```

## Technical Implementation

### Architecture Integration Strategy

The implementation will modify existing adapters rather than creating new ones to ensure:

1. **Consistency**: All existing functionality remains intact
2. **Maintainability**: Changes are localized to existing files
3. **Backward Compatibility**: No breaking changes to the adapter system
4. **Performance**: No additional adapter instantiation overhead
5. **Code Reuse**: All adapters benefit from base class improvements

### Specific Implementation Steps

#### Phase 1: Modify Base DisplayAdapter Class
- **File**: `src/API/Timeline/DisplayAdapter.php`
- **Changes**:
  - Add debug mode filtering to `organizeIntoSections()` method
  - Add `formatCleanCurrency()` method for combined amount/currency formatting
  - Add `formatCleanCustomerReference()` method for customer formatting
  - Modify field extraction logic to remove duplicates
  - Ensure event_type fields are only included when `ODCM_DEBUG = true`

#### Phase 2: Update OrderEventAdapter
- **File**: `src/API/Timeline/OrderEventAdapter.php`
- **Changes**:
  - Update `addOrderCreationFields()` to use new formatting methods
  - Update `addStatusChangeFields()` to remove duplicate status fields
  - Ensure customer information uses new format
  - Combine amount and currency into single field

#### Phase 3: Update PaymentEventAdapter
- **File**: `src/API/Timeline/PaymentEventAdapter.php`
- **Changes**:
  - Update payment field extraction to combine amount/currency
  - Remove duplicate payment method fields
  - Ensure event_type is filtered in non-debug mode

#### Phase 4: Update RuleExecutionAdapter
- **File**: `src/API/Timeline/RuleExecutionAdapter.php`
- **Changes**:
  - Update rule execution display to show only business-relevant fields
  - Implement new status change formatting: "[from] → [to]"
  - Remove technical details from main display sections

#### Phase 5: Update GenericEventAdapter
- **File**: `src/API/Timeline/GenericEventAdapter.php`
- **Changes**:
  - Apply same cleanup logic for generic events
  - Ensure consistent formatting across all event types

### Technical Implementation Details

#### Debug Mode Detection
```php
$debugMode = defined('ODCM_DEBUG') && ODCM_DEBUG;
```

#### Field Filtering Logic
```php
// Skip event_type in non-debug mode
if ($key === 'event_type' && !$debugMode) {
    continue;
}
```

#### Currency Formatting
```php
private function formatCleanCurrency($amount, string $currency): string {
    if (is_numeric($amount)) {
        return number_format((float)$amount, 2, '.', '') . ' ' . strtoupper($currency);
    }
    return (string)$amount;
}
```

#### Customer Formatting
```php
private function formatCleanCustomerReference($customerId, ?string $firstName, ?string $lastName, ?string $email): string {
    $nameParts = [];
    if ($firstName) $nameParts[] = $firstName;
    if ($lastName) $nameParts[] = $lastName;

    if (!empty($nameParts)) {
        $name = implode(' ', $nameParts);
        return sprintf('Customer: %s (ID: %s)', $name, $customerId);
    }

    if ($email) {
        return sprintf('Customer: %s (ID: %s)', $email, $customerId);
    }

    return sprintf('Customer ID: %s', $customerId);
}
```

## Quality Assurance

### WordPress Plugin Checker Compliance
- **Mandatory Requirement**: All implementation changes must pass the WordPress Plugin Checker
- **Code Standards**: Follow WordPress coding standards and best practices
- **Security**: Ensure no security vulnerabilities are introduced
- **Performance**: Maintain or improve existing performance levels

### Testing Requirements

#### Test Scenarios
1. **Debug Mode On**: Verify event_type fields are visible
2. **Debug Mode Off**: Verify event_type fields are hidden
3. **Field Formatting**: Verify combined amount/currency and customer formatting
4. **Duplicate Removal**: Verify no duplicate fields in any component
5. **Data Integrity**: Verify all original data remains in technical sections
6. **Plugin Checker**: Run WordPress Plugin Checker and resolve any issues
7. **Visual Design**: Verify status pills show correct status and are right-aligned
8. **Icon Display**: Verify dashicons appear correctly for each event type
9. **Component Theming**: Verify theming is applied consistently

#### Expected Results
- Clean, business-focused timeline display
- No duplicate information in visible sections
- Proper formatting of all fields
- Technical data preserved for debugging
- Successful WordPress Plugin Checker validation
- Consistent visual hierarchy with right-aligned status pills
- Appropriate dashicons for each event type

## Appendices

### CSS Implementation Examples

```css
/* Right-aligned status pill container */
.odcm-component__header-right {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
}

/* Status pill styling */
.odcm-status-pill {
    margin-left: auto;
    text-transform: capitalize;
}

/* Component header restructuring */
.odcm-component__header-top {
    justify-content: space-between;
}

/* Component header with icon integration */
.odcm-component__header-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.odcm-component-icon.dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: inherit;
}

/* Component-specific icon colors */
.odcm-component--order .odcm-component-icon {
    color: var(--odcm-theme-purple-700);
}

.odcm-component--payment .odcm-component-icon {
    color: var(--odcm-theme-green-700);
}
```

### PHP Implementation Examples

```php
/**
 * Get dashicon for event type using comprehensive mapping
 */
private function getEventIcon(string $event_type): string {
    $config = $this->getEventTypeConfig($event_type);
    return $config['dashicon'];
}

/**
 * Get theme class for event type
 */
private function getEventThemeClass(string $event_type): string {
    $config = $this->getEventTypeConfig($event_type);
    return $config['theme_class'];
}
```

### Event Type Research Summary

The comprehensive analysis identified **over 50 unique event types** organized into logical categories:

1. **Order Lifecycle Events (12 types)**: Complete order journey from creation to completion
2. **Payment Events (8+ types)**: Generic payment patterns and gateway-specific events
3. **Rule Events (3 types)**: Rule execution and evaluation events
4. **System Events (7 types)**: Administrative and informational events
5. **Refund Events (5 types)**: Complete refund lifecycle coverage
6. **Specialized Events**: Subscriptions, webhooks, conditions, and universal processing

This mapping ensures consistent visual representation across all event types while maintaining the existing component theming system.
