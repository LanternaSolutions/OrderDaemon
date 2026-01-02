<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Payment Event Display Adapter
 *
 * Specialized adapter for payment-related events that extracts and organizes
 * payment-specific data for consistent display. Handles payment processing,
 * checkout events, and payment method changes.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
class PaymentEventAdapter extends DisplayAdapter
{
    /**
     * Extract specialized fields for payment-related events
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return array Extracted specialized fields
     */
    protected function extractSpecializedFields(array &$payload): array
    {
        $fields = [];

        // Extract event type for processing
        $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'payment_event';

        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->formatPaymentEventDescription($eventType, $payload),
            'section' => 'primary'
        ];

        // Order ID - use enhanced extraction from base class
        $order_id = $this->extractOrderId($payload);
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => $this->translate('Order'),
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }

        // Payment method
        $paymentMethod = $this->extractPaymentMethod($payload);
        if ($paymentMethod) {
            $fields['payment_method'] = [
                'label' => $this->translate('Payment Method'),
                'value' => $this->formatPaymentMethod($paymentMethod),
                'section' => 'primary'
            ];
        }

        // Transaction amount - use base class method for consistent formatting
        $amount = $this->extractTransactionAmount($payload);
        $currency = $payload['currency'] ?? 
                   $payload['data']['currency'] ?? 
                   $payload['payment_context']['currency'] ?? 'USD';

        if ($amount) {
            $fields['amount'] = [
                'label' => $this->translate('Amount'),
                'value' => $this->formatCleanCurrency($amount, $currency),
                'section' => 'primary'
            ];
        }

        // Payment status
        $paymentStatus = $this->extractPaymentStatus($payload);
        if ($paymentStatus) {
            $fields['payment_status'] = [
                'label' => $this->translate('Payment Status'),
                'value' => ucfirst($paymentStatus),
                'section' => 'primary'
            ];
        }

        // Add event-specific fields - only essential business information
        if (strpos($eventType, 'payment_completed') !== false) {
            $this->addPaymentCompletedFields($fields, $payload);
        } elseif (strpos($eventType, 'payment_failed') !== false) {
            $this->addPaymentFailedFields($fields, $payload);
        } elseif (strpos($eventType, 'checkout') !== false) {
            $this->addCheckoutFields($fields, $payload);
        }

        // Add common payment details - only essential business information
        $this->addCommonPaymentFields($fields, $payload);

        return $fields;
    }
    
    /**
     * Format payment event description based on event type and payload
     *
     * @since 1.2.0
     *
     * @param string $eventType The event type
     * @param array $payload The event payload
     * @return string Formatted event description
     */
    private function formatPaymentEventDescription(string $eventType, array $payload): string
    {
        $eventLabels = [
            'payment_completed' => $this->translate('Payment Completed'),
            'payment_failed' => $this->translate('Payment Failed'),
            'payment_pending' => $this->translate('Payment Pending'),
            'payment_processing' => $this->translate('Payment Processing'),
            'payment_refunded' => $this->translate('Payment Refunded'),
            'payment_cancelled' => $this->translate('Payment Cancelled'),
            'checkout_processed' => $this->translate('Checkout Processed'),
            'checkout_completed' => $this->translate('Checkout Completed'),
            'checkout_started' => $this->translate('Checkout Started'),
            'payment_method_changed' => $this->translate('Payment Method Changed'),
        ];

        if (isset($eventLabels[$eventType])) {
            return $eventLabels[$eventType];
        }

        // Handle generic payment events
        if (strpos($eventType, 'payment_') === 0) {
            $action = str_replace('payment_', '', $eventType);
            return sprintf($this->translate('Payment %s'), ucfirst(str_replace('_', ' ', $action)));
        }

        // Handle generic checkout events
        if (strpos($eventType, 'checkout_') === 0) {
            $action = str_replace('checkout_', '', $eventType);
            return sprintf($this->translate('Checkout %s'), ucfirst(str_replace('_', ' ', $action)));
        }

        return ucfirst(str_replace('_', ' ', $eventType));
    }
    
    /**
     * Extract payment method from various payload locations
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return string|null The payment method or null
     */
    private function extractPaymentMethod(array $payload): ?string
    {
        return $payload['payment_method'] ?? 
               $payload['data']['payment_method'] ?? 
               $payload['payment_context']['payment_method'] ?? 
               $payload['checkout_data']['payment_method'] ?? 
               $payload['transaction']['payment_method'] ?? null;
    }
    
    /**
     * Extract transaction amount from various payload locations
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return mixed The transaction amount or null
     */
    private function extractTransactionAmount(array $payload)
    {
        return $payload['total_amount'] ?? 
               $payload['amount'] ?? 
               $payload['payment_context']['total_amount'] ?? 
               $payload['checkout_data']['total'] ?? 
               $payload['transaction']['amount'] ?? 
               $payload['data']['amount'] ?? null;
    }
    
    /**
     * Extract payment status from various payload locations
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return string|null The payment status or null
     */
    private function extractPaymentStatus(array $payload): ?string
    {
        return $payload['payment_status'] ?? 
               $payload['status'] ?? 
               $payload['data']['status'] ?? 
               $payload['payment_context']['status'] ?? 
               $payload['transaction']['status'] ?? null;
    }
    
    /**
     * Add payment completed specific fields
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addPaymentCompletedFields(array &$fields, array $payload): void
    {
        // Transaction ID
        $transactionId = $payload['transaction_id'] ?? 
                        $payload['data']['transaction_id'] ?? 
                        $payload['payment_context']['transaction_id'] ?? 
                        $payload['transaction']['id'] ?? null;

        if ($transactionId) {
            $fields['transaction_id'] = [
                'label' => $this->translate('Transaction ID'),
                'value' => $transactionId,
                'section' => 'payment_details'
            ];
        }
        
        // Gateway response
        $gatewayResponse = $payload['gateway_response'] ?? 
                          $payload['data']['gateway_response'] ?? 
                          $payload['response'] ?? null;

        if ($gatewayResponse) {
            $fields['gateway_response'] = [
                'label' => $this->translate('Gateway Response'),
                'value' => is_string($gatewayResponse) ? $gatewayResponse : $this->translate('Success'),
                'section' => 'payment_details'
            ];
        }
        
        // Payment date
        $paymentDate = $payload['payment_date'] ?? 
                      $payload['completed_date'] ?? 
                      $payload['data']['completed_date'] ?? null;

        if ($paymentDate) {
            $fields['payment_date'] = [
                'label' => $this->translate('Payment Date'),
                'value' => $this->formatDateTime($paymentDate),
                'section' => 'payment_details'
            ];
        }
    }
    
    /**
     * Add payment failed specific fields
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addPaymentFailedFields(array &$fields, array $payload): void
    {
        // Failure reason
        $failureReason = $payload['failure_reason'] ?? 
                        $payload['error_message'] ?? 
                        $payload['data']['error_message'] ?? 
                        $payload['payment_context']['error'] ?? null;
        
        if ($failureReason) {
            $fields['failure_reason'] = [
                'label' => $this->translate('Failure Reason'),
                'value' => $failureReason,
                'section' => 'primary'
            ];
        }
        
        // Gateway error code
        $errorCode = $payload['error_code'] ?? 
                    $payload['data']['error_code'] ?? 
                    $payload['gateway_error_code'] ?? null;
        
        if ($errorCode) {
            $fields['error_code'] = [
                'label' => $this->translate('Error Code'),
                'value' => $errorCode,
                'section' => 'payment_details'
            ];
        }
        
        // Retry information
        $retryCount = $payload['retry_count'] ?? 
                     $payload['data']['retry_count'] ?? null;
        
        if ($retryCount !== null) {
            $fields['retry_count'] = [
                'label' => $this->translate('Retry Count'),
                'value' => (string)$retryCount,
                'section' => 'payment_details'
            ];
        }
    }
    
    /**
     * Add checkout specific fields
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addCheckoutFields(array &$fields, array $payload): void
    {
        // Checkout method/type - add to primary section
        $checkoutMethod = $payload['checkout_method'] ?? 
                         $payload['data']['checkout_method'] ?? 
                         $payload['checkout_type'] ?? 
                         $payload['data']['checkout_type'] ?? null;

        if ($checkoutMethod) {
            $fields['checkout_type'] = [
                'label' => $this->translate('Checkout Type'),
                'value' => ucfirst(str_replace('_', ' ', $checkoutMethod)),
                'section' => 'primary'
            ];
        }

        // Items count
        $itemCount = $payload['item_count'] ?? 
                    $payload['data']['item_count'] ?? 
                    $payload['checkout_data']['item_count'] ?? null;

        if ($itemCount) {
            $fields['item_count'] = [
                'label' => $this->translate('Items'),
                'value' => $this->pluralize('%d item', '%d items', $itemCount),
                'section' => 'checkout_details'
            ];
        }
    }
    
    /**
     * Add common payment fields that apply to all payment events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addCommonPaymentFields(array &$fields, array $payload): void
    {
        // Gateway/processor name
        $gatewayName = $payload['gateway'] ?? 
                      $payload['processor'] ?? 
                      $payload['data']['gateway'] ?? 
                      $payload['payment_context']['gateway'] ?? null;

        if ($gatewayName) {
            $fields['gateway'] = [
                'label' => $this->translate('Payment Gateway'),
                'value' => $this->formatGatewayName($gatewayName),
                'section' => 'payment_details'
            ];
        }

        // Currency - primary section and combined with amount
        $currency = $payload['currency'] ?? 
                   $payload['data']['currency'] ?? 
                   $payload['payment_context']['currency'] ?? null;

        $amount = $this->extractTransactionAmount($payload);

        // Only add amount field if not already added by the main extraction logic
        if ($amount && $currency && !isset($fields['amount'])) {
            $fields['amount'] = [
                'label' => $this->translate('Amount'),
                'value' => $this->formatCleanCurrency($amount, $currency),
                'section' => 'primary'
            ];
        }

        // Payment session ID
        $sessionId = $payload['session_id'] ?? 
                    $payload['payment_session_id'] ?? 
                    $payload['data']['session_id'] ?? null;

        if ($sessionId) {
            $fields['session_id'] = [
                'label' => $this->translate('Session ID'),
                'value' => $sessionId,
                'section' => 'payment_details'
            ];
        }

        // Customer IP address
        $customerIp = $payload['customer_ip'] ?? 
                     $payload['data']['customer_ip'] ?? 
                     $payload['ip_address'] ?? null;

        if ($customerIp) {
            $fields['customer_ip'] = [
                'label' => $this->translate('Customer IP'),
                'value' => $customerIp,
                'section' => 'payment_details'
            ];
        }
    }
    
    /**
     * Format currency value for display
     *
     * @since 1.2.0
     *
     * @param mixed $amount The currency amount
     * @return string Formatted currency
     */
    private function formatCurrency($amount): string
    {
        if (is_numeric($amount)) {
            // Use WooCommerce formatting if available
            if (function_exists('wc_price')) {
                return wp_strip_all_tags(wc_price((float)$amount));
            }

            // Fallback formatting
            return '$' . number_format((float)$amount, 2);
        }

        return (string)$amount;
    }
    
    /**
     * Format datetime for display
     *
     * @since 1.2.0
     *
     * @param mixed $datetime The datetime value
     * @return string Formatted datetime
     */
    private function formatDateTime($datetime): string
    {
        if (is_numeric($datetime)) {
            return gmdate('Y-m-d H:i:s', (int)$datetime);
        }
        
        if (is_string($datetime)) {
            $timestamp = strtotime($datetime);
            if ($timestamp !== false) {
                return gmdate('Y-m-d H:i:s', $timestamp);
            }
            return $datetime;
        }
        
        return (string)$datetime;
    }
    
    /**
     * Format payment method for display
     *
     * @since 1.2.0
     *
     * @param string $paymentMethod The payment method
     * @return string Formatted payment method
     */
    private function formatPaymentMethod(string $paymentMethod): string
    {
        $methodLabels = [
            'stripe' => $this->translate('Stripe'),
            'paypal' => $this->translate('PayPal'),
            'paypal_express' => $this->translate('PayPal Express'),
            'bacs' => $this->translate('Bank Transfer'),
            'cheque' => $this->translate('Check Payment'),
            'cod' => $this->translate('Cash on Delivery'),
            'credit_card' => $this->translate('Credit Card'),
            'debit_card' => $this->translate('Debit Card'),
            'apple_pay' => $this->translate('Apple Pay'),
            'google_pay' => $this->translate('Google Pay'),
            'square' => $this->translate('Square'),
            'authorize_net' => $this->translate('Authorize.Net'),
        ];
        
        return $methodLabels[$paymentMethod] ?? ucwords(str_replace(['_', '-'], ' ', $paymentMethod));
    }
    
    /**
     * Format gateway name for display
     *
     * @since 1.2.0
     *
     * @param string $gatewayName The gateway name
     * @return string Formatted gateway name
     */
    private function formatGatewayName(string $gatewayName): string
    {
        $gatewayLabels = [
            'stripe' => $this->translate('Stripe'),
            'paypal' => $this->translate('PayPal'),
            'square' => $this->translate('Square'),
            'authorize_net' => $this->translate('Authorize.Net'),
            'woocommerce_payments' => $this->translate('WooCommerce Payments'),
            'braintree' => $this->translate('Braintree'),
        ];
        
        return $gatewayLabels[$gatewayName] ?? ucwords(str_replace(['_', '-'], ' ', $gatewayName));
    }
}
