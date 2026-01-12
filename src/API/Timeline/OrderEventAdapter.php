<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Order Event Display Adapter
 *
 * Specialized adapter for order-related events that extracts and organizes
 * order-specific data for consistent display. Handles status changes, order
 * creation, updates, and other order lifecycle events.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
class OrderEventAdapter extends DisplayAdapter
{
    /**
     * Extract specialized fields for order-related events
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
        $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'order_event';

        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->formatEventDescription($eventType, $payload),
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

        // Handle subscription events as complex recurring orders
        if (strpos($eventType, 'subscription_') !== false ||
            strpos($eventType, 'renewal_payment_') !== false ||
            $eventType === 'trial_ending') {
            $this->addSubscriptionFields($fields, $payload);
        }

        // Status change specifics
        if (strpos($eventType, 'status_changed') !== false) {
            $this->addStatusChangeFields($fields, $payload);
        }

        // Order creation specifics
        if (strpos($eventType, 'order_created') !== false) {
            $this->addOrderCreationFields($fields, $payload);
        }

        // Order update specifics
        if (strpos($eventType, 'order_updated') !== false) {
            $this->addOrderUpdateFields($fields, $payload);
        }

        // Common order details - only add essential business information
        $this->addCommonOrderFields($fields, $payload);

        return $fields;
    }
    
    /**
     * Format event description based on event type and payload
     *
     * @since 1.2.0
     *
     * @param string $eventType The event type
     * @param array $payload The event payload
     * @return string Formatted event description
     */
    private function formatEventDescription(string $eventType, array $payload): string
    {
        $eventLabels = [
            'status_changed' => $this->translate('Order Status Changed'),
            'order_created' => $this->translate('Order Created'),
            'order_updated' => $this->translate('Order Updated'),
            'order_completed' => $this->translate('Order Completed'),
            'order_cancelled' => $this->translate('Order Cancelled'),
            'order_refunded' => $this->translate('Order Refunded'),
            'order_processing' => $this->translate('Order Processing'),
            'order_on_hold' => $this->translate('Order On Hold'),
            'order_pending' => $this->translate('Order Pending'),
            'order_failed' => $this->translate('Order Failed'),
            'subscription_created' => $this->translate('Subscription Created'),
            'subscription_approved' => $this->translate('Subscription Approved'),
            'subscription_cancelled' => $this->translate('Subscription Cancelled'),
            'subscription_suspended' => $this->translate('Subscription Suspended'),
            'subscription_reactivated' => $this->translate('Subscription Reactivated'),
            'subscription_completed' => $this->translate('Subscription Completed'),
            'subscription_expired' => $this->translate('Subscription Expired'),
            'subscription_paused' => $this->translate('Subscription Paused'),
            'subscription_resumed' => $this->translate('Subscription Resumed'),
            'subscription_updated' => $this->translate('Subscription Updated'),
            'trial_ending' => $this->translate('Trial Ending'),
            'renewal_payment_completed' => $this->translate('Renewal Payment Completed'),
            'renewal_payment_failed' => $this->translate('Renewal Payment Failed'),
            'renewal_payment_processing' => $this->translate('Renewal Payment Processing'),
            'renewal_payment_pending' => $this->translate('Renewal Payment Pending'),
        ];

        if (isset($eventLabels[$eventType])) {
            return $eventLabels[$eventType];
        }

        // For status-specific events, try to extract status from event type
        if (strpos($eventType, 'order_') === 0) {
            $status = str_replace('order_', '', $eventType);
            return sprintf($this->translate('Order %s'), ucfirst(str_replace('_', ' ', $status)));
        }

        // For subscription events
        if (strpos($eventType, 'subscription_') === 0) {
            $status = str_replace('subscription_', '', $eventType);
            return sprintf($this->translate('Subscription %s'), ucfirst(str_replace('_', ' ', $status)));
        }

        return ucfirst(str_replace('_', ' ', $eventType));
    }
    
    /**
     * Add status change specific fields
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addStatusChangeFields(array &$fields, array $payload): void
    {
        // Extract status change information from multiple possible locations
        $fromStatus = $this->extractStatusValue($payload, 'from');
        $toStatus = $this->extractStatusValue($payload, 'to');

        // Fallback to rawData if not found in data
        if (!$fromStatus && isset($payload['rawData']['from_status'])) {
            $fromStatus = $payload['rawData']['from_status'];
        }
        if (!$toStatus && isset($payload['rawData']['to_status'])) {
            $toStatus = $payload['rawData']['to_status'];
        }

        if ($fromStatus && $toStatus) {
            $fields['status_change'] = [
                'label' => $this->translate('Status Change'),
                'value' => sprintf('%s → %s', ucfirst($fromStatus), ucfirst($toStatus)),
                'section' => 'primary'
            ];
        } elseif ($toStatus) {
            $fields['new_status'] = [
                'label' => $this->translate('New Status'),
                'value' => ucfirst($toStatus),
                'section' => 'primary'
            ];
        }

        // Status change reason if available
        $reason = $payload['status_change_reason'] ?? 
                 $payload['data']['reason'] ?? 
                 $payload['change_reason'] ?? null;

        if ($reason) {
            $fields['change_reason'] = [
                'label' => $this->translate('Reason'),
                'value' => $reason,
                'section' => 'order_details'
            ];
        }

        // Who made the change
        $changedBy = $payload['changed_by'] ?? 
                    $payload['data']['changed_by'] ?? 
                    $payload['user_id'] ?? null;

        if ($changedBy) {
            $fields['changed_by'] = [
                'label' => $this->translate('Changed By'),
                'value' => $this->formatUserReference($changedBy),
                'section' => 'order_details'
            ];
        }
    }
    
    /**
     * Add order creation specific fields
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addOrderCreationFields(array &$fields, array $payload): void
    {
        // Order ID - add to primary section
        $order_id = $this->extractOrderId($payload);
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => $this->translate('Order'),
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }

        // Customer information
        $customerId = $payload['customer_id'] ?? 
                     $payload['data']['customer_id'] ?? 
                     $payload['order_data']['customer_id'] ?? null;

        if ($customerId) {
            // Get enriched customer data by fetching from WordPress
            $customerData = $this->getEnrichedCustomerData($customerId);

            $fields['customer'] = [
                'label' => $this->translate('Customer'),
                'value' => $this->formatCleanCustomerReference(
                    $customerId,
                    $customerData['first_name'],
                    $customerData['last_name'],
                    $customerData['username']
                ),
                'section' => 'primary'
            ];
        }

        // Payment method
        $paymentMethod = $payload['payment_method'] ?? 
                        $payload['data']['payment_method'] ?? 
                        $payload['order_data']['payment_method'] ?? null;

        if ($paymentMethod) {
            $fields['payment_method'] = [
                'label' => $this->translate('Payment Method'),
                'value' => $this->formatPaymentMethod($paymentMethod),
                'section' => 'primary'
            ];
        }

        // Order total with currency - use base class method for consistent formatting
        $amount = $payload['order_total'] ?? 
                 $payload['data']['order_total'] ?? 
                 $payload['order_data']['total'] ?? 
                 $payload['total'] ?? 
                 $payload['data']['amount'] ?? null;

        $currency = $payload['currency'] ?? 
                   $payload['data']['currency'] ?? 
                   $payload['order_data']['currency'] ?? 'USD';

        if ($amount) {
            $fields['amount'] = [
                'label' => $this->translate('Amount'),
                'value' => $this->formatCleanCurrency($amount, $currency),
                'section' => 'primary'
            ];
        }
    }
    
    /**
     * Add order update specific fields
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addOrderUpdateFields(array &$fields, array $payload): void
    {
        // What was updated
        $updatedFields = $payload['updated_fields'] ?? 
                        $payload['data']['updated_fields'] ?? 
                        $payload['changes'] ?? [];

        if (!empty($updatedFields)) {
            if (is_array($updatedFields)) {
                $fields['updated_fields'] = [
                    'label' => $this->translate('Updated Fields'),
                    'value' => implode(', ', array_keys($updatedFields)),
                    'section' => 'primary'
                ];
            } else {
                $fields['update_type'] = [
                    'label' => $this->translate('Update Type'),
                    'value' => (string)$updatedFields,
                    'section' => 'primary'
                ];
            }
        }
        
        // Update source
        $updateSource = $payload['update_source'] ?? 
                       $payload['data']['source'] ?? 
                       $payload['source'] ?? null;

        if ($updateSource) {
            $fields['update_source'] = [
                'label' => $this->translate('Update Source'),
                'value' => ucfirst($updateSource),
                'section' => 'order_details'
            ];
        }
    }
    
    /**
     * Add subscription-specific fields for subscription events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addSubscriptionFields(array &$fields, array $payload): void
    {
        // Extract subscription ID
        $subscriptionId = $payload['subscription_id'] ?? 
                         $payload['data']['subscription_id'] ?? 
                         $payload['id'] ?? 
                         $payload['data']['id'] ?? null;

        if ($subscriptionId) {
            $fields['subscription_id'] = [
                'label' => $this->translate('Subscription ID'),
                'value' => '#' . $subscriptionId,
                'section' => 'primary'
            ];
        }

        // Extract subscription status
        $subscriptionStatus = $payload['subscription_status'] ?? 
                            $payload['status'] ?? 
                            $payload['data']['subscription_status'] ?? 
                            $payload['data']['status'] ?? null;

        if ($subscriptionStatus) {
            $fields['subscription_status'] = [
                'label' => $this->translate('Subscription Status'),
                'value' => ucfirst(str_replace('_', ' ', $subscriptionStatus)),
                'section' => 'primary'
            ];
        }

        // Extract subscription type (monthly, annual, etc.)
        $subscriptionType = $payload['subscription_type'] ?? 
                           $payload['billing_period'] ?? 
                           $payload['data']['subscription_type'] ?? 
                           $payload['data']['billing_period'] ?? null;

        if ($subscriptionType) {
            $fields['subscription_type'] = [
                'label' => $this->translate('Subscription Type'),
                'value' => ucfirst(str_replace('_', ' ', $subscriptionType)),
                'section' => 'primary'
            ];
        }

        // Extract billing cycle information
        $billingCycle = $payload['billing_cycle'] ?? 
                       $payload['data']['billing_cycle'] ?? null;

        if ($billingCycle) {
            $fields['billing_cycle'] = [
                'label' => $this->translate('Billing Cycle'),
                'value' => $billingCycle,
                'section' => 'event_details'
            ];
        }

        // Extract next payment date
        $nextPaymentDate = $payload['next_payment_date'] ?? 
                          $payload['data']['next_payment_date'] ?? null;

        if ($nextPaymentDate) {
            $fields['next_payment_date'] = [
                'label' => $this->translate('Next Payment Date'),
                'value' => is_numeric($nextPaymentDate) ? gmdate('Y-m-d', $nextPaymentDate) : $nextPaymentDate,
                'section' => 'primary'
            ];
        }

        // Extract recurring amount
        $recurringAmount = $payload['recurring_amount'] ?? 
                         $payload['amount'] ?? 
                         $payload['data']['recurring_amount'] ?? 
                         $payload['data']['amount'] ?? null;

        $currency = $payload['currency'] ?? 
                   $payload['data']['currency'] ?? 'USD';

        if ($recurringAmount) {
            $fields['recurring_amount'] = [
                'label' => $this->translate('Recurring Amount'),
                'value' => $this->formatCleanCurrency($recurringAmount, $currency),
                'section' => 'primary'
            ];
        }

        // Extract lifecycle state
        $lifecycleState = $payload['lifecycle_state'] ?? 
                        $payload['state'] ?? 
                        $payload['data']['lifecycle_state'] ?? 
                        $payload['data']['state'] ?? null;

        if ($lifecycleState) {
            $fields['lifecycle_state'] = [
                'label' => $this->translate('Lifecycle State'),
                'value' => ucfirst(str_replace('_', ' ', $lifecycleState)),
                'section' => 'primary'
            ];
        }

        // Extract related order ID if applicable
        $relatedOrderId = $payload['related_order_id'] ?? 
                         $payload['order_id'] ?? 
                         $payload['data']['related_order_id'] ?? 
                         $payload['data']['order_id'] ?? null;

        if ($relatedOrderId && $relatedOrderId != $subscriptionId) {
            $fields['related_order_id'] = [
                'label' => $this->translate('Related Order'),
                'value' => '#' . $relatedOrderId,
                'section' => 'event_details'
            ];
        }

        // Extract timestamp
        $timestamp = $payload['timestamp'] ?? 
                    $payload['data']['timestamp'] ?? null;

        if ($timestamp) {
            $fields['subscription_timestamp'] = [
                'label' => $this->translate('Timestamp'),
                'value' => is_numeric($timestamp) ? gmdate('Y-m-d H:i:s', $timestamp) : $timestamp,
                'section' => 'event_details'
            ];
        }

        // Extract error details if applicable
        $error = $payload['error'] ?? 
                $payload['error_message'] ?? 
                $payload['data']['error'] ?? null;

        if ($error) {
            $fields['error'] = [
                'label' => $this->translate('Error'),
                'value' => is_string($error) ? $error : $this->translate('Error occurred'),
                'section' => 'event_details'
            ];
        }
    }

    /**
     * Add common order fields that apply to all order events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addCommonOrderFields(array &$fields, array $payload): void
    {
        // Order ID - only add to detail section if not already in primary section
        $order_id = $this->extractOrderId($payload);
        if ($order_id > 0 && !isset($fields['order_id'])) {
            $fields['order_id'] = [
                'label' => $this->translate('Order'),
                'value' => '#' . $order_id,
                'section' => 'order_details'
            ];
        }

        // Order status (current) - only add if not already added by status change logic
        $currentStatus = $payload['order_status'] ?? 
                        $payload['data']['order_status'] ?? 
                        $payload['status'] ?? 
                        $payload['to_status'] ?? null;

        if ($currentStatus && !isset($fields['status']) && !isset($fields['status_change'])) {
            $fields['current_status'] = [
                'label' => $this->translate('Status'),
                'value' => ucfirst($currentStatus),
                'section' => 'order_details'
            ];
        }

        // Order date
        $orderDate = $payload['order_date'] ?? 
                    $payload['data']['order_date'] ?? 
                    $payload['order_data']['date_created'] ?? null;

        if ($orderDate) {
            $fields['order_date'] = [
                'label' => $this->translate('Order Date'),
                'value' => $this->formatDateTime($orderDate),
                'section' => 'order_details'
            ];
        }

        // Item count
        $itemCount = $payload['item_count'] ?? 
                    $payload['data']['item_count'] ?? 
                    $payload['order_data']['item_count'] ?? null;

        if ($itemCount) {
            $fields['item_count'] = [
                'label' => $this->translate('Items'),
                'value' => $this->pluralize('%d item', '%d items', $itemCount),
                'section' => 'order_details'
            ];
        }
    }
    
    /**
     * Extract status value from various payload locations
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @param string $direction 'from' or 'to'
     * @return string|null The status value or null
     */
    private function extractStatusValue(array $payload, string $direction): ?string
    {
        $field = $direction . '_status';
        
        return $payload[$field] ?? 
               $payload['data'][$field] ?? 
               $payload['status_change'][$field] ?? 
               $payload['order_data'][$field] ?? null;
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
        // Use WooCommerce formatting if available with defensive check
        if (function_exists('wc_price')) {
            try {
                return wp_strip_all_tags(wc_price((float)$amount));
            } catch (\Throwable $e) {
                // Fallback if WooCommerce function fails
                return '$' . number_format((float)$amount, 2);
            }
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
     * Format user reference for display
     *
     * @since 1.2.0
     *
     * @param mixed $userRef The user reference (ID, email, etc.)
     * @return string Formatted user reference
     */
    private function formatUserReference($userRef): string
    {
        if (is_numeric($userRef)) {
            // Try to get user info if WordPress functions are available with defensive check
            if (function_exists('get_userdata')) {
                try {
                    $user = get_userdata((int)$userRef);
                    if ($user) {
                        return $user->display_name . ' (' . $user->user_email . ')';
                    }
                } catch (\Throwable $e) {
                    // Fallback if WordPress function fails
                    return $this->translate('User ID: ') . $userRef;
                }
            }
            return $this->translate('User ID: ') . $userRef;
        }

        return (string)$userRef;
    }
    
    /**
     * Get enriched customer data from WordPress user database
     *
     * This method fetches user data from WordPress to enrich the timeline display
     * with customer names instead of just IDs.
     *
     * @param int $customerId The customer ID
     * @return array Customer data with first_name, last_name, and username
     */
    private function getEnrichedCustomerData(int $customerId): array
    {
        $firstName = null;
        $lastName = null;
        $username = null;

        // Try to get user data from WordPress
        if (function_exists('get_user_by')) {
            try {
                $user = get_user_by('id', $customerId);

                if ($user instanceof \WP_User) {
                    // Get first name from user meta
                    $firstName = $user->first_name;

                    // Get last name from user meta
                    $lastName = $user->last_name;

                    // Use username as fallback if no first/last name
                    if (empty($firstName) && empty($lastName)) {
                        $username = $user->user_login;
                    }
                }
            } catch (\Throwable $e) {
                // If WordPress function fails, continue with null values
                // This ensures the adapter doesn't break if WordPress functions aren't available
            }
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username
        ];
    }

    /**
     * Format customer reference for display
     *
     * @since 1.2.0
     *
     * @param mixed $customerId The customer ID
     * @param array $payload The full payload (for additional customer data)
     * @return string Formatted customer reference
     */
    private function formatCustomerReference($customerId, array $payload): string
    {
        // Try to get customer email from payload
        $customerEmail = $payload['customer_email'] ?? 
                        $payload['data']['customer_email'] ?? 
                        $payload['order_data']['customer_email'] ?? null;

        if ($customerEmail) {
            return sprintf($this->translate('Customer %s (%s)'), $customerId, $customerEmail);
        }

        if (is_numeric($customerId)) {
            return sprintf($this->translate('Customer ID: %s'), $customerId);
        }

        return (string)$customerId;
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
            'bacs' => $this->translate('Bank Transfer'),
            'cheque' => $this->translate('Check Payment'),
            'cod' => $this->translate('Cash on Delivery'),
            'credit_card' => $this->translate('Credit Card'),
        ];

        return $methodLabels[$paymentMethod] ?? ucwords(str_replace('_', ' ', $paymentMethod));
    }
}
