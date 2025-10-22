<?php
// Very simple test script for registry check - no WordPress/error_log dependencies

echo "=== TESTING PAYMENT EVENT RENDERER REGISTRY ENTRIES ===\n\n";

// Simple function to check if registry entries are set up correctly
function check_registry_entry($registry, $event_type) {
    // Direct match?
    if (isset($registry[$event_type])) {
        return [
            'status' => 'DIRECT MATCH',
            'id' => $event_type,
            'renderer_class' => $registry[$event_type]['renderer_class'] ?? 'unknown'
        ];
    }
    
    // Alias match?
    foreach ($registry as $key => $entry) {
        if (isset($entry['aliases']) && in_array($event_type, $entry['aliases'])) {
            return [
                'status' => 'ALIAS MATCH',
                'id' => $key,
                'renderer_class' => $entry['renderer_class'] ?? 'unknown'
            ];
        }
    }
    
    return [
        'status' => 'NO MATCH',
        'id' => null,
        'renderer_class' => null
    ];
}

// Define registry manually for testing - simplified structure
$registry = [
    'payment_event' => [
        'id' => 'payment_event',
        'renderer_class' => 'PaymentEventRenderer',
        'aliases' => []
    ],
    'payment_completed' => [
        'id' => 'payment_completed',
        'renderer_class' => 'PaymentEventRenderer',
        'aliases' => [
            'payment_succeeded',
            'payment_processed',
            'charge_succeeded',
            'payment_event'
        ]
    ],
    'payment_failed' => [
        'id' => 'payment_failed',
        'renderer_class' => 'PaymentEventRenderer',
        'aliases' => [
            'payment_error',
            'charge_failed',
            'payment_declined',
            'payment_event'
        ]
    ],
    'refund_created' => [
        'id' => 'refund_created',
        'renderer_class' => 'PaymentEventRenderer',
        'aliases' => [
            'refund_issued',
            'refund_processed',
            'payment_event'
        ]
    ],
    'block_checkout_processed' => [
        'id' => 'block_checkout_processed',
        'renderer_class' => 'WooCommerceRenderer',
        'aliases' => []
    ]
];

// Test events
$test_events = [
    // Test payment_event direct match
    [
        'event_type' => 'payment_event',
        'data' => [
            'amount' => 100.00,
            'currency' => 'USD',
            'source_gateway' => 'stripe',
            'transaction_id' => 'tx_123456'
        ]
    ],
    // Test payment_completed
    [
        'event_type' => 'payment_completed',
        'data' => [
            'amount' => 100.00,
            'currency' => 'USD',
            'source_gateway' => 'stripe',
            'transaction_id' => 'tx_123456'
        ]
    ],
    // Test payment_failed
    [
        'event_type' => 'payment_failed',
        'data' => [
            'amount' => 100.00,
            'currency' => 'USD',
            'source_gateway' => 'stripe',
            'transaction_id' => 'tx_123456',
            'error' => 'Card declined'
        ]
    ],
    // Test refund_created
    [
        'event_type' => 'refund_created',
        'data' => [
            'amount' => 50.00,
            'currency' => 'USD',
            'source_gateway' => 'stripe',
            'transaction_id' => 'tx_refund_123'
        ]
    ],
    // Test unknown payment event
    [
        'event_type' => 'unknown_payment',
        'data' => [
            'amount' => 100.00,
            'currency' => 'USD',
            'source_gateway' => 'stripe',
            'transaction_id' => 'tx_123456'
        ]
    ],
    // Test without explicit event_type but with payment data
    [
        'event_type' => 'generic_event',
        'data' => [
            'amount' => 100.00,
            'currency' => 'USD',
            'source_gateway' => 'stripe',
            'transaction_id' => 'tx_123456'
        ]
    ],
    // Test block_checkout_processed (known working)
    [
        'event_type' => 'block_checkout_processed',
        'data' => [
            'order_id' => 123
        ]
    ]
];

echo "=== PAYMENT EVENT RENDERER SELECTION TEST ===\n\n";

// Test each event
foreach ($test_events as $index => $event) {
    $event_type = $event['event_type'];
    $data = $event['data'];
    
    echo "\n\n=== TEST CASE #" . ($index + 1) . ": event_type='" . $event_type . "' ===\n";
    
    // Check registry match
    $result = check_registry_entry($registry, $event_type);
    
    echo "REGISTRY LOOKUP: " . json_encode($result) . "\n";
    
    // Check renderer class match with PaymentEventRenderer::getComponentId() = 'payment_event'
    if ($result['status'] === 'NO MATCH') {
        echo "CAPABILITY CHECK: Testing if event would match PaymentEventRenderer...\n";
        
        // Simulate PaymentEventRenderer::canHandle() by checking for payment data
        $payment_keys = ['amount', 'currency', 'source_gateway', 'transaction_id', 'payment_method'];
        $has_payment_data = false;
        foreach ($payment_keys as $key) {
            if (isset($data[$key])) {
                $has_payment_data = true;
                break;
            }
        }
        
        if ($has_payment_data) {
            echo "CAPABILITY CHECK RESULT: MATCH - Has payment data fields, would match PaymentEventRenderer\n";
        } else {
            echo "CAPABILITY CHECK RESULT: NO MATCH - No payment data, would not match PaymentEventRenderer\n";
        }
    } else {
        echo "CAPABILITY CHECK: Not needed (already matched via registry)\n";
    }
}

echo "\n=== TEST COMPLETED ===\n";
