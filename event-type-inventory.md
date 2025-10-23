# Event Type Inventory

This document catalogs all event_type values found in the codebase, providing context on where they're used and their purpose.

## Rule Related Events

### rule_matched
**Source:** UniversalEventProcessor.php
**Context:** Logged when a rule matches during rule evaluation
**Example Usage:**
```php
$rule_logger->add_component('rule_matched',
    sprintf('Rule "%s" matched', $rule->post_title),
    ['rule_id' => $rule->ID, 'rule_name' => $rule->post_title]
);
```

### rule_no_match
**Source:** UniversalEventProcessor.php
**Context:** Logged when a rule doesn't match evaluation criteria
**Example Usage:**
```php
$rule_logger->add_component('rule_no_match',
    sprintf('Rule "%s" did not match', $rule->post_title),
    ['rule_id' => $rule->ID, 'rule_name' => $rule->post_title, 'conditions' => $trace['conditions']]
);
```

### rule_evaluation
**Source:** Not directly used as event_type, but exists in registry
**Context:** Parent component ID for rule-related events
**Registry Purpose:** To group rule-related events under a common renderer

### condition_passed
**Source:** Evaluator.php
**Context:** Logged when a specific condition within a rule passes evaluation
**Example Usage:**
```php
$event_type = $passed ? 'condition_passed' : 'condition_failed';
$data = [
    'order_id' => (int) $order_id,
    'condition_label' => (string) $label,
    'operator' => $operator !== null ? (string) $operator : '',
    'expected_value' => $this->formatValueForLogging($expected),
    'actual_value' => $this->formatValueForLogging($actual),
    'result' => $passed ? 'pass' : 'fail',
];
$this->process_logger->add_component($event_type, $label, $data, 'info');
```

### condition_failed
**Source:** Evaluator.php
**Context:** Logged when a specific condition within a rule fails evaluation
**Example Usage:** Same as condition_passed but triggered when !$passed

### action_executed
**Source:** UniversalEventProcessor.php
**Context:** Logged when a rule action is executed
**Example Usage:**
```php
$rule_logger->add_component('action_executed',
    sprintf('Executing primary action: %s', $rule_data['primaryAction']['id']),
    ['action_id' => $rule_data['primaryAction']['id']]
);
```

## Order Related Events

### order_loaded
**Source:** BlockCheckoutCompatibility.php
**Context:** Logged when an order is loaded from the database
**Example Usage:**
```php
[
    'event_type' => 'order_loaded',
    'ts' => time(),
    'k' => 'c' . time() . rand(10,99),
]
```

### status_changed
**Source:** Core.php, ManualStatusTracker.php
**Context:** Logged when an order's status changes
**Example Usage:**
```php
[
    'event_type' => 'status_changed',
    'ts' => odcm_iso8601_now(),
    'k' => 'c' . time() . rand(10,99),
]
```

### order_event
**Source:** AuditLogEndpoint.php
**Context:** Generic order-related event for API responses
**Example Usage:**
```php
[
    'event_type' => 'order_event',
    'label' => $entry['summary'] ?? ($entry['event_type'] ?? 'Event'),
]
```

### order_partially_refunded
**Source:** RefundDeletionDiagnostics.php
**Context:** Logged when an order is partially refunded
**Example Usage:**
```php
$event_type = 'order_partially_refunded';
$status = 'warning';
```

### order_fully_refunded
**Source:** RefundDeletionDiagnostics.php
**Context:** Logged when an order is fully refunded
**Example Usage:**
```php
$event_type = 'order_fully_refunded';
$status = 'warning';
```

## Payment Related Events

### payment_completed
**Source:** WebhookController.php (as default_event_type)
**Context:** Default event type for payment webhook events
**Example Usage:**
```php
[
    'default_event_type' => 'payment_completed',
    'total_types' => count($event_types),
]
```

### refund_created
**Source:** RefundDeletionDiagnostics.php
**Context:** Logged when a refund is created
**Example Usage:**
```php
$event_type = 'refund_created';
```

### refund_deleted
**Source:** RefundDeletionDiagnostics.php
**Context:** Logged when a refund is deleted
**Example Usage:**
```php
$event_type = 'refund_deleted';
$refund_data = [...];
```

### stripe_event
**Source:** BlockCheckoutCompatibility.php
**Context:** Logged for Stripe-specific events
**Example Usage:**
```php
[
    'event_type' => 'stripe_event',
    'ts' => time(),
    'k' => 'c' . time() . rand(10,99),
]
```

## Analysis and Metrics Events

### metrics
**Source:** LogCleanup.php, RefundDeletionDiagnostics.php
**Context:** Performance and numerical metrics for tracking system behavior
**Example Usage:**
```php
$pl->add_component('metrics', 'Records deleted', [ 'name' => 'deleted_count', 'value' => (float)$deleted_count ]);
```

### refund_analysis
**Source:** RefundDeletionDiagnostics.php
**Context:** Detailed analysis of refund operations
**Example Usage:**
```php
[
    'event_type' => 'refund_analysis',
    'ts' => $now,
    'key' => 'refund_details-' . uniqid('', true),
]
```

### woocommerce_analysis
**Source:** RefundDeletionDiagnostics.php
**Context:** WooCommerce-specific analysis data
**Example Usage:**
```php
[
    'event_type' => 'woocommerce_analysis',
    'ts' => $now,
    'key' => 'order_impact-' . uniqid('', true),
]
```

## System and Debug Events

### info
**Source:** Multiple files
**Context:** General informational events
**Example Usage:**
```php
$pl->add_component('info', 'Cleanup details', [ 'message' => sprintf('Type: %s', esc_html($retention_type)) ]);
```

### warning
**Source:** RefundDeletionDiagnostics.php, ManualStatusTracker.php
**Context:** Warning events that need attention but aren't errors
**Example Usage:**
```php
[
    'event_type' => 'warning',
    'ts' => odcm_iso8601_now(),
    'key' => 'order-impact-' . uniqid('', true),
]
```

### error
**Source:** AuditLogEndpoint.php
**Context:** Error events that indicate failures
**Example Usage:**
```php
[
    'event_type' => 'error',
    'label' => 'Event Processing Error',
]
```

### fallback
**Source:** RefundDeletionDiagnostics.php
**Context:** Generic fallback for events that don't have a specific type
**Example Usage:**
```php
[
    'event_type' => 'fallback',
    'ts' => odcm_iso8601_now(),
    'key' => 'refunded-items-' . uniqid('', true),
]
```

### admin_action
**Source:** Core.php, ManualStatusTracker.php
**Context:** Actions performed by admin users
**Example Usage:**
```php
[
    'event_type' => 'admin_action',
    'ts' => time(),
    'k' => 'c' . time() . rand(10,99),
]
```

### process_started
**Source:** ProcessLogger.php, ManualStatusTracker.php
**Context:** Logged at the beginning of a process
**Example Usage:**
```php
[
    'event_type' => 'process_started',
    'ts' => time(),
]
```

### process_event
**Source:** AuditLogEndpoint.php
**Context:** Used for API responses to represent process events
**Example Usage:**
```php
[
    'event_type' => 'process_event',
    'label' => $event['summary'] ?? ($event['event_type'] ?? 'Event'),
]
```

### lifecycle_event
**Source:** AuditLogEndpoint.php
**Context:** Used for API responses to represent lifecycle events
**Example Usage:**
```php
[
    'event_type' => 'lifecycle_event',
    'label' => $entry['summary'] ?? ($entry['event_type'] ?? 'Event'),
]
```

### custom_event
**Source:** GenericAdapter.php, AuditLogEndpoint.php
**Context:** Used for custom events that don't fit other categories
**Example Usage:**
```php
[
    'event_type' => 'custom_event',
    'label' => 'Custom Event Data',
]
```

### dedup
**Source:** Core.php
**Context:** Used for deduplication checks
**Example Usage:**
```php
$pl->add_component('dedup', 'Dedup checks', [ 'specific_hook' => (bool) ($this->has_specific_status_processed($order_id, $to_slug, 30) ?? false) ], 'info');
```

## Summary

This inventory catalogs 25 distinct event types actually used in the codebase. These events generally fall into several broad categories:

1. **Rule Processing** (rule_matched, rule_no_match, condition_passed, condition_failed, action_executed)
2. **Order Handling** (order_loaded, status_changed, order_event, order_partially_refunded, order_fully_refunded)
3. **Payment Processing** (payment_completed, refund_created, refund_deleted, stripe_event)
4. **Analysis & Metrics** (metrics, refund_analysis, woocommerce_analysis)
5. **System Events** (info, warning, error, fallback, admin_action, process_started, process_event, lifecycle_event, custom_event, dedup)

The next step is to analyze the data structures for each event type to understand what fields need to be rendered.
