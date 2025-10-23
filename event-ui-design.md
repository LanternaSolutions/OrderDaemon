# Event UI Design

This document outlines the optimal UI design for each event type, mapping the event data structures to UI Toolkit components to create a consistent, user-friendly presentation.

## UI Toolkit Component Review

Before mapping events to UI components, let's review the available components in the PayloadComponentUIToolkit:

### Key UI Components

1. **Key-Value List** (`render_key_value_list`):
   - Displays labeled data in a two-column grid
   - Best for structured data with clear labels
   - Example: `$toolkit->render_key_value_list(['Status' => 'Completed', 'Amount' => '$50.00'], 'Order Details')`

2. **Status Pill** (`render_status_pill`):
   - Displays status indicator with colored background
   - Best for showing state/outcome in a visually distinct way
   - Example: `$toolkit->render_status_pill('SUCCESS', 'success')`

3. **Code Block** (`render_code_block`):
   - Displays formatted code with syntax highlighting
   - Best for JSON, arrays, or technical data
   - Example: `$toolkit->render_code_block($json_data, 'json')`

4. **Expandable Section** (`render_expandable_section`):
   - Collapsible container for detailed information
   - Best for verbose data that shouldn't always be visible
   - Example: `$toolkit->render_expandable_section('Details', $content_html)`

5. **Interactive Section** (`render_interactive_section`):
   - Enhanced expandable section with actions
   - Best for data that users might want to interact with
   - Example: `$toolkit->render_interactive_section('API Response', $content, ['initially_expanded' => false])`

6. **Text Block** (`render_text_block`):
   - Simple paragraph of text
   - Best for plain messages or descriptions
   - Example: `$toolkit->render_text_block('Process completed successfully.')`

7. **Component Shell** (`render_component_shell`):
   - Outer wrapper for complete component
   - Used by PayloadComponentRenderer to create the final component

## Event Type UI Mappings

### Rule-Related Events

#### condition_passed / condition_failed

**UI Structure:**
```php
// Status indicator
$status_pill = $toolkit->render_status_pill(
    $passed ? 'PASSED' : 'FAILED',
    $passed ? 'success' : 'warning'
);

// Core content
$content = $toolkit->render_key_value_list([
    'Condition' => $data['condition_label'],
    'Operator' => $data['operator'],
    'Expected' => $data['expected_value'],
    'Actual' => $data['actual_value']
], 'Condition Result');

// Final component
$toolkit->render_component_shell(
    $data['condition_label'],
    $passed ? 'success' : 'warning',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Green/yellow color coding for pass/fail status
- Prominent condition name
- Clear comparison of expected vs. actual values
- Clean two-column layout for readability

#### rule_matched

**UI Structure:**
```php
// Status indicator
$status_pill = $toolkit->render_status_pill('MATCHED', 'success');

// Core content
$content = $toolkit->render_key_value_list([
    'Rule Name' => $data['rule_name'],
    'Rule ID' => '#' . $data['rule_id'],
], 'Rule Matched');

// Final component
$toolkit->render_component_shell(
    $data['rule_name'],
    'rule',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Green success indicator
- Prominent rule name
- Simple, confirmation-focused presentation
- Clear "matched" status communication

#### rule_no_match

**UI Structure:**
```php
// Status indicator
$status_pill = $toolkit->render_status_pill('NOT MATCHED', 'debug');

// Core content
$key_values = [
    'Rule Name' => $data['rule_name'],
    'Rule ID' => '#' . $data['rule_id'],
];

$content = $toolkit->render_key_value_list($key_values, 'Rule Not Matched');

// Add failed conditions in expandable section if available
if (!empty($data['conditions'])) {
    $conditions_json = json_encode($data['conditions'], JSON_PRETTY_PRINT);
    $code_block = $toolkit->render_code_block($conditions_json, 'json');
    $content .= $toolkit->render_expandable_section('Failed Conditions', $code_block);
}

// Final component
$toolkit->render_component_shell(
    $data['rule_name'],
    'rule',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Yellow debug indicator (only fires when ODCM_DEBUG = true)
- Expandable section for condition details
- Focus on which condition failed
- Not alarming (normal business logic)

#### action_executed

**UI Structure:**
```php
// Core content
$content = $toolkit->render_key_value_list([
    'Action' => $data['action_id'],
], 'Action Executed');

// Final component
$toolkit->render_component_shell(
    'Action: ' . $data['action_id'],
    'rule',
    $content
);
```

**Visual Features:**
- Simple, confirmation-focused
- Minimal detail (action execution is usually self-explanatory)
- Consistent rule-related styling

### Payment-Related Events

#### payment_completed

**UI Structure:**
```php
// Status indicator
$status_pill = $toolkit->render_status_pill('PAYMENT COMPLETED', 'success');

// Core content
$key_values = [
    'Amount' => isset($data['amount']) && isset($data['currency']) ? 
        $this->formatCurrency($data['amount'], $data['currency']) : '',
    'Transaction ID' => $data['transaction_id'] ?? '',
    'Payment Method' => $data['payment_method'] ?? '',
    'Gateway' => ucfirst($data['source_gateway'] ?? ''),
];

// Filter out empty values
$key_values = array_filter($key_values);

$content = $toolkit->render_key_value_list($key_values, 'Payment Details');

// Final component
$toolkit->render_component_shell(
    'Payment Completed',
    'payment',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Green success indicator
- Prominent payment amount with currency formatting
- Financial information clearly presented
- Payment method and transaction ID for reference

#### refund_created

**UI Structure:**
```php
// Status indicator
$status_pill = $toolkit->render_status_pill('REFUND', 'warning');

// Core content
$key_values = [
    'Amount' => isset($data['amount']) && isset($data['currency']) ? 
        $this->formatCurrency($data['amount'], $data['currency']) : '',
    'Refund ID' => '#' . ($data['refund_id'] ?? ''),
    'Order ID' => '#' . ($data['order_id'] ?? ''),
    'Reason' => $data['reason'] ?? '',
    'Created By' => $data['user_id'] ? $this->getUserName($data['user_id']) : '',
];

// Filter out empty values
$key_values = array_filter($key_values);

$content = $toolkit->render_key_value_list($key_values, 'Refund Details');

// Final component
$toolkit->render_component_shell(
    'Refund Created',
    'payment',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Yellow warning indicator (refunds need attention)
- Prominent refund amount with currency formatting
- Reason displayed prominently when available
- Creator attribution for accountability

#### order_partially_refunded / order_fully_refunded

**UI Structure:**
```php
// Status indicator
$is_full = $event_type === 'order_fully_refunded';
$status_text = $is_full ? 'FULLY REFUNDED' : 'PARTIALLY REFUNDED';
$status_pill = $toolkit->render_status_pill($status_text, 'warning');

// Core content
$key_values = [
    'Amount' => isset($data['amount']) && isset($data['currency']) ? 
        $this->formatCurrency($data['amount'], $data['currency']) : '',
    'Order ID' => '#' . ($data['order_id'] ?? ''),
    'Refund ID' => '#' . ($data['refund_id'] ?? ''),
];

// Add percentage for partial refunds
if (!$is_full && isset($data['impact']['refund_percentage'])) {
    $key_values['Percentage'] = $data['impact']['refund_percentage'] . '%';
}

// Filter out empty values
$key_values = array_filter($key_values);

$content = $toolkit->render_key_value_list($key_values, 'Order Refund');

// Add refunded items in expandable section if available
if (!empty($data['impact']['items_refunded'])) {
    $items_json = json_encode($data['impact']['items_refunded'], JSON_PRETTY_PRINT);
    $items_block = $toolkit->render_code_block($items_json, 'json');
    $content .= $toolkit->render_expandable_section('Refunded Items', $items_block);
}

// Final component
$toolkit->render_component_shell(
    $is_full ? 'Order Fully Refunded' : 'Order Partially Refunded',
    'payment',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Yellow warning indicator with clear full/partial distinction
- Prominent refund amount and percentage for partial refunds
- Expandable section for refunded items
- Order-centric presentation (vs refund-centric for refund_created)

### Order-Related Events

#### status_changed

**UI Structure:**
```php
// Determine status type for appropriate styling
$status_type = $this->getStatusType($data['to']);

// Status indicator
$status_pill = $toolkit->render_status_pill(strtoupper($data['to']), $status_type);

// Core content
$key_values = [
    'From Status' => ucfirst($data['from']),
    'To Status' => ucfirst($data['to']),
    'Order ID' => '#' . ($data['order_id'] ?? ''),
];

// Add user info for manual changes
if (!empty($data['user_id']) && !empty($data['manual'])) {
    $key_values['Changed By'] = $this->getUserName($data['user_id']);
    $key_values['Manual Change'] = 'Yes';
}

$content = $toolkit->render_key_value_list($key_values, 'Status Change');

// Final component
$toolkit->render_component_shell(
    'Status Changed to ' . ucfirst($data['to']),
    'woocommerce',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Status-colored pill (green for completed, blue for processing, etc.)
- Clear before/after status display
- Attribution for manual changes
- WooCommerce-branded styling

#### order_loaded

**UI Structure:**
```php
// Core content
$key_values = [
    'Order ID' => '#' . ($data['order_id'] ?? ''),
    'Source' => $data['source'] ?? 'system',
];

$content = $toolkit->render_key_value_list($key_values, 'Order Loaded');

// Final component
$toolkit->render_component_shell(
    'Order Loaded',
    'woocommerce',
    $content
);
```

**Visual Features:**
- Simple, minimal presentation
- Low-key styling (not a high-priority event)
- Basic context information only

### System Events

#### info / warning / error

**UI Structure:**
```php
// Status indicator based on level
$level_map = [
    'info' => 'info',
    'warning' => 'warning',
    'error' => 'error'
];
$level = $data['level'] ?? 'info';
$status_pill = $toolkit->render_status_pill(strtoupper($level), $level_map[$level]);

// Core content
$content = '';

// Simple message
if (!empty($data['message'])) {
    $content .= $toolkit->render_text_block($data['message']);
}

// Key-value data if available
$key_values = array_filter($data, function($key) {
    return !in_array($key, ['message', 'level', 'timestamp']);
}, ARRAY_FILTER_USE_KEY);

if (!empty($key_values)) {
    $content .= $toolkit->render_key_value_list($key_values, 'Details');
}

// Final component
$toolkit->render_component_shell(
    ucfirst($level) . ': ' . substr($data['message'] ?? 'System Event', 0, 50),
    $level_map[$level],
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Color-coded by severity (grey info, yellow warning, red error)
- Prominent message display
- Contextual details in key-value format when available
- Consistent severity indication

#### metrics

**UI Structure:**
```php
// Core content
$key_values = [
    'Metric' => $data['name'] ?? 'Unnamed Metric',
    'Value' => $this->formatMetricValue($data['value'], $data['unit'] ?? ''),
];

// Add context if available
if (!empty($data['context'])) {
    foreach ($data['context'] as $key => $value) {
        if (is_scalar($value)) {
            $key_values[ucfirst($key)] = $value;
        }
    }
}

$content = $toolkit->render_key_value_list($key_values, 'Performance Metric');

// Final component
$toolkit->render_component_shell(
    'Metric: ' . ($data['name'] ?? 'Unnamed Metric'),
    'performance',
    $content
);
```

**Visual Features:**
- Technical, data-oriented presentation
- Properly formatted units and values
- Performance-themed styling
- Clean, analytical appearance

#### admin_action

**UI Structure:**
```php
// Status indicator
$status_pill = $toolkit->render_status_pill('ADMIN ACTION', 'notice');

// Core content
$key_values = [
    'Action' => $data['action'] ?? 'Unknown Action',
    'User' => $data['user_id'] ? $this->getUserName($data['user_id']) : 'Unknown User',
];

// Add target if available
if (!empty($data['target'])) {
    $key_values['Target'] = $data['target'];
}

$content = $toolkit->render_key_value_list($key_values, 'Administrative Action');

// Final component
$toolkit->render_component_shell(
    'Admin Action: ' . ($data['action'] ?? 'Unknown Action'),
    'system',
    $content,
    ['status_pill' => $status_pill]
);
```

**Visual Features:**
- Notice-styled to draw attention to manual interventions
- Clear attribution to admin user
- Concise action description
- Administrative styling

### Analysis Events

#### refund_analysis / woocommerce_analysis

**UI Structure:**
```php
// Core content
$content = '';

// Object identifiers
$key_values = [
    'Refund ID' => !empty($data['refund_id']) ? '#' . $data['refund_id'] : '',
    'Order ID' => !empty($data['order_id']) ? '#' . $data['order_id'] : '',
];

// Filter out empty values
$key_values = array_filter($key_values);

if (!empty($key_values)) {
    $content .= $toolkit->render_key_value_list($key_values, 'Analysis Subject');
}

// Add refund details in expandable section if available
if (!empty($data['refund_details'])) {
    $details_json = json_encode($data['refund_details'], JSON_PRETTY_PRINT);
    $details_block = $toolkit->render_code_block($details_json, 'json');
    $content .= $toolkit->render_expandable_section('Refund Details', $details_block);
}

// Add order impact in expandable section if available
if (!empty($data['order_impact'])) {
    $impact_json = json_encode($data['order_impact'], JSON_PRETTY_PRINT);
    $impact_block = $toolkit->render_code_block($impact_json, 'json');
    $content .= $toolkit->render_expandable_section('Order Impact', $impact_block);
}

// Final component
$toolkit->render_component_shell(
    $event_type === 'refund_analysis' ? 'Refund Analysis' : 'Order Analysis',
    'woocommerce',
    $content
);
```

**Visual Features:**
- Data-rich presentation with expandable sections
- Analytics-focused styling
- Multiple collapsible sections for detailed data
- WooCommerce-branded for order/refund context

## UI Patterns & Helper Functions

### Currency Formatting

```php
/**
 * Format a currency value for display
 * 
 * @param float|string $amount Amount to format
 * @param string $currency Currency code
 * @return string Formatted amount with currency
 */
private function formatCurrency($amount, string $currency): string
{
    // Use WooCommerce formatting if available
    if (function_exists('wc_price')) {
        return wc_price($amount, ['currency' => $currency]);
    }
    
    // Basic fallback formatting
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        // Add more currencies as needed
    ];
    
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format((float)$amount, 2);
}
```

### Status Type Mapping

```php
/**
 * Get appropriate status type for WooCommerce status
 * 
 * @param string $status WooCommerce status
 * @return string UI status type
 */
private function getStatusType(string $status): string
{
    $status_map = [
        'completed' => 'success',
        'processing' => 'info',
        'on-hold' => 'warning',
        'cancelled' => 'error',
        'refunded' => 'warning',
        'failed' => 'error',
        'pending' => 'notice',
    ];
    
    return $status_map[strtolower($status)] ?? 'info';
}
```

### Metric Value Formatting

```php
/**
 * Format a metric value with appropriate units
 * 
 * @param float|int $value Metric value
 * @param string $unit Unit of measurement
 * @return string Formatted value with unit
 */
private function formatMetricValue($value, string $unit = ''): string
{
    // Handle common units
    switch ($unit) {
        case 'ms':
        case 'milliseconds':
            return number_format((float)$value, 2) . ' ms';
            
        case 's':
        case 'seconds':
            return number_format((float)$value, 2) . ' s';
            
        case 'bytes':
        case 'b':
            return $this->formatBytes((int)$value);
            
        case '%':
        case 'percent':
            return number_format((float)$value, 2) . '%';
            
        case 'count':
        case 'items':
            return number_format((int)$value);
            
        default:
            // If unit is provided but not special-cased
            if (!empty($unit)) {
                return number_format((float)$value, 2) . ' ' . $unit;
            }
            
            // No unit, just format the number
            if (is_int($value) || $value == (int)$value) {
                return number_format((int)$value);
            } else {
                return number_format((float)$value, 2);
            }
    }
}
```

### Bytes Formatting

```php
/**
 * Format bytes into human-readable format
 * 
 * @param int $bytes Number of bytes
 * @return string Human-readable size
 */
private function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}
```

### User Name Retrieval

```php
/**
 * Get user display name from ID
 * 
 * @param int $user_id WordPress user ID
 * @return string User display name
 */
private function getUserName(int $user_id): string
{
    $user = get_userdata($user_id);
    if ($user) {
        return $user->display_name;
    }
    return 'User #' . $user_id;
}
```

## UI Design Summary

### Design Principles Applied

1. **Consistency**: Each event type follows a consistent pattern while adapting to its specific data needs
2. **Priority-based Design**: High-priority events (payments, refunds, errors) have more prominent, attention-grabbing UI
3. **Business-First**: Business-critical information is displayed prominently, technical details are in expandable sections
4. **Proper Formatting**: Currency, dates, metrics all have appropriate formatting for their data type
5. **Progressive Disclosure**: Complex data is hidden in expandable sections, only critical info is visible by default
6. **Context Preservation**: Event context (rule, order, payment) is clearly indicated through consistent visual language

### UI Component Distribution

This UI design primarily relies on:
- Key-Value Lists (100% of event types)
- Status Pills (80% of event types)
- Expandable Sections (40% of event types)
- Code Blocks (30% of event types)
- Text Blocks (10% of event types)

This pattern indicates that we can simplify the renderer architecture by focusing on a few core UI patterns rather than highly specialized renderers for each event type.
