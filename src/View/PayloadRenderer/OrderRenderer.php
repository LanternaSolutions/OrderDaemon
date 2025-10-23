<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Order Renderer
 *
 * Handles rendering of all WooCommerce order-related events including subscriptions:
 * 
 * Order Events:
 * - status_changed
 * - order_loaded
 * - block_checkout_processed
 * - meta_updated
 * - woocommerce_data
 *
 * Subscription Events:
 * - subscription_created, subscription_approved, subscription_cancelled
 * - subscription_suspended, subscription_reactivated, subscription_completed
 * - subscription_expired, subscription_paused, subscription_resumed
 * - renewal_payment_completed, renewal_payment_failed
 * - renewal_payment_processing, renewal_payment_pending
 * - trial_ending, subscription_updated
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class OrderRenderer extends BaseRenderer
{
    /**
     * Render Content
     *
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    protected function renderContent(array $data, string $event_type): string
    {
        $toolkit = new PayloadComponentUIToolkit();

        // Handle subscription events
        if ($this->isSubscriptionEvent($event_type) || $this->hasSubscriptionData($data)) {
            return $this->renderSubscriptionEvent($data, $event_type, $toolkit);
        }

        // Handle regular order events
        switch ($event_type) {
            case 'status_changed':
                return $this->renderStatusChange($data, $toolkit);

            case 'order_loaded':
                return $this->renderOrderLoaded($data, $toolkit);

            case 'block_checkout_processed':
                return $this->renderBlockCheckout($data, $toolkit);

            case 'meta_updated':
                return $this->renderMetaUpdate($data, $toolkit);

            case 'woocommerce_data':
                return $this->renderWooCommerceData($data, $toolkit);

            default:
                return $this->renderGenericOrder($data, $toolkit);
        }
    }

    /**
     * Get Label
     *
     * Provides event-specific labels based on event type and data.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $data, string $event_type): string
    {
        switch ($event_type) {
            case 'status_changed':
                $from = ucfirst($data['from'] ?? 'unknown');
                $to = ucfirst($data['to'] ?? 'unknown');
                return "New Status: {$to}, Was: {$from}";

            case 'order_loaded':
                return isset($data['order_id']) 
                    ? "Order #{$data['order_id']} Loaded"
                    : 'Order Loaded';

            case 'block_checkout_processed':
                return 'Block Checkout Processed';

            case 'meta_updated':
                return isset($data['meta_key'])
                    ? "Meta Updated: {$data['meta_key']}"
                    : 'Meta Updated';

            case 'woocommerce_data':
                return 'WooCommerce Data';

            default:
                return parent::getLabel($data, $event_type);
        }
    }

    /**
     * Get Status Pill
     *
     * Provides event-specific status pills based on event type and outcome.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
        switch ($event_type) {
            case 'status_changed':
                $status = $data['to'] ?? '';
                return ['label' => strtoupper($status), 'type' => $this->getStatusType($status)];

            case 'order_loaded':
                return ['label' => 'LOADED', 'type' => 'info'];

            case 'block_checkout_processed':
                return ['label' => 'PROCESSED', 'type' => 'woocommerce'];

            case 'meta_updated':
                return ['label' => 'UPDATED', 'type' => 'info'];

            case 'woocommerce_data':
                return ['label' => 'WOOCOMMERCE', 'type' => 'woocommerce'];

            default:
                return null;
        }
    }

    /**
     * Get Theme
     *
     * All order events use the 'woocommerce' theme for consistent styling.
     *
     * @param string $event_type The type of event
     * @return string Theme identifier
     */
    protected function getTheme(string $event_type): string
    {
        return 'woocommerce';
    }

    /**
     * Render Status Change
     *
     * Renders order status change details.
     *
     * @param array                    $data    The status change data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderStatusChange(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $status_data = [
            'New Status' => ucfirst($data['to'] ?? ''),
            'Was Status' => ucfirst($data['from'] ?? ''),
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
        ];

        // Add user info for manual changes
        if (!empty($data['user_id']) && !empty($data['manual'])) {
            $status_data['Changed By'] = $this->getUserName($data['user_id']);
            $status_data['Manual Change'] = 'Yes';
        }

        $content = $toolkit->render_key_value_list($status_data, 'Status Change');

        // Add status change details in expandable section if available
        if (!empty($data['details'])) {
            $details_json = json_encode($data['details'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($details_json, 'json');
            $content .= $toolkit->render_expandable_section('Change Details', $code_block);
        }

        return $content;
    }

    /**
     * Render Order Loaded
     *
     * Renders order loading details.
     *
     * @param array                    $data    The order data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderOrderLoaded(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $order_data = [
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Source' => $data['source'] ?? 'system',
            'Status' => ucfirst($data['status'] ?? ''),
        ];

        // Add customer info if available
        if (!empty($data['customer'])) {
            $order_data['Customer'] = $data['customer'];
        }

        $content = $toolkit->render_key_value_list($order_data, 'Order Details');

        // Add order data in expandable section if available
        if (!empty($data['order'])) {
            $order_json = json_encode($data['order'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($order_json, 'json');
            $content .= $toolkit->render_expandable_section('Order Data', $code_block);
        }

        return $content;
    }

    /**
     * Render Block Checkout
     *
     * Renders block checkout processing details.
     *
     * @param array                    $data    The checkout data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderBlockCheckout(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $checkout_data = [
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Status' => ucfirst($data['status'] ?? ''),
            'Payment Method' => $data['payment_method'] ?? '',
            'Total' => isset($data['total'], $data['currency']) 
                ? $this->formatCurrency($data['total'], $data['currency']) 
                : '',
        ];

        $content = $toolkit->render_key_value_list($checkout_data, 'Checkout Details');

        // Add customer data in expandable section if available
        if (!empty($data['customer_data'])) {
            $customer_json = json_encode($data['customer_data'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($customer_json, 'json');
            $content .= $toolkit->render_expandable_section('Customer Data', $code_block);
        }

        // Add cart data in expandable section if available
        if (!empty($data['cart_data'])) {
            $cart_json = json_encode($data['cart_data'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($cart_json, 'json');
            $content .= $toolkit->render_expandable_section('Cart Data', $code_block);
        }

        return $content;
    }

    /**
     * Render Meta Update
     *
     * Renders order meta update details.
     *
     * @param array                    $data    The meta update data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderMetaUpdate(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $meta_data = [
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Meta Key' => $data['meta_key'] ?? '',
            'New Value' => $data['new_value'] ?? '',
            'Previous Value' => $data['old_value'] ?? '',
        ];

        // Add user info if available
        if (isset($data['user_id'])) {
            $meta_data['Updated By'] = $this->getUserName($data['user_id']);
        }

        $content = $toolkit->render_key_value_list($meta_data, 'Meta Update');

        // Add additional meta context in expandable section if available
        if (!empty($data['meta_context'])) {
            $context_json = json_encode($data['meta_context'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($context_json, 'json');
            $content .= $toolkit->render_expandable_section('Meta Context', $code_block);
        }

        return $content;
    }

    /**
     * Render WooCommerce Data
     *
     * Renders general WooCommerce data.
     *
     * @param array                    $data    The WooCommerce data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderWooCommerceData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Extract any key data points for the main display
        $woo_data = [];
        
        if (isset($data['order_id'])) {
            $woo_data['Order ID'] = '#' . $data['order_id'];
        }

        if (isset($data['type'])) {
            $woo_data['Type'] = ucfirst($data['type']);
        }

        if (isset($data['action'])) {
            $woo_data['Action'] = ucfirst($data['action']);
        }

        if (isset($data['status'])) {
            $woo_data['Status'] = ucfirst($data['status']);
        }

        $content = $toolkit->render_key_value_list($woo_data, 'WooCommerce Data');

        // Add full data in expandable section
        $data_json = json_encode($data, JSON_PRETTY_PRINT);
        $code_block = $toolkit->render_code_block($data_json, 'json');
        $content .= $toolkit->render_expandable_section('Full Data', $code_block);

        return $content;
    }

    /**
     * Render Generic Order
     *
     * Fallback renderer for unrecognized order events.
     *
     * @param array                    $data    The order data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderGenericOrder(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        return $toolkit->render_code_block(
            json_encode($data, JSON_PRETTY_PRINT),
            'json'
        );
    }

    /**
     * Check if event type is subscription-related
     *
     * @param string $event_type The event type to check
     * @return bool True if subscription event
     */
    private function isSubscriptionEvent(string $event_type): bool
    {
        $subscription_events = [
            'subscription_created', 'subscription_approved', 'subscription_cancelled',
            'subscription_suspended', 'subscription_reactivated', 'subscription_completed',
            'subscription_expired', 'subscription_paused', 'subscription_resumed',
            'subscription_updated', 'trial_ending',
            'renewal_payment_completed', 'renewal_payment_failed',
            'renewal_payment_processing', 'renewal_payment_pending'
        ];

        return in_array($event_type, $subscription_events, true);
    }

    /**
     * Check if data contains subscription information
     *
     * @param array $data The data to check
     * @return bool True if contains subscription data
     */
    private function hasSubscriptionData(array $data): bool
    {
        // Check for Universal Event subscription data
        if (isset($data['primaryObjectType']) && $data['primaryObjectType'] === 'subscription') {
            return true;
        }

        // Check for subscription identifiers
        if (isset($data['subscription_id']) || isset($data['subscr_id'])) {
            return true;
        }

        return false;
    }

    /**
     * Render subscription event
     *
     * @param array                    $data       The event data
     * @param string                   $event_type The event type
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @return string HTML content
     */
    private function renderSubscriptionEvent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string
    {
        // Extract subscription data
        $subscription_data = $this->extractSubscriptionData($data);

        $content = '';

        // Render subscription summary
        $summary_data = [
            'Event Type' => $this->getSubscriptionEventLabel($event_type),
            'Subscription' => isset($subscription_data['subscription_id']) ? '#' . $subscription_data['subscription_id'] : '',
            'Status' => ucfirst($subscription_data['status'] ?? ''),
        ];

        if (isset($subscription_data['amount'], $subscription_data['currency'])) {
            $summary_data['Amount'] = $this->formatCurrency($subscription_data['amount'], $subscription_data['currency']);
        }

        if (!empty($subscription_data['gateway'])) {
            $summary_data['Gateway'] = ucfirst($subscription_data['gateway']);
        }

        $content .= $toolkit->render_key_value_list($summary_data, 'Subscription Summary');

        // Render billing information if available
        if (!empty($subscription_data['billing_info'])) {
            $content .= $toolkit->render_key_value_list($subscription_data['billing_info'], 'Billing Information');
        }

        // Render trial information if available
        if (!empty($subscription_data['trial_info'])) {
            $content .= $toolkit->render_key_value_list($subscription_data['trial_info'], 'Trial Information');
        }

        // Render payment information if available
        if (!empty($subscription_data['payment_info'])) {
            $content .= $toolkit->render_key_value_list($subscription_data['payment_info'], 'Payment Information');
        }

        // Add raw data in expandable section
        if (!empty($data)) {
            $json = json_encode($this->sanitizeSubscriptionData($data), JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($json, 'json');
            $content .= $toolkit->render_expandable_section('Raw Data', $code_block);
        }

        return $content;
    }

    /**
     * Extract subscription data from various data structures
     *
     * @param array $data The event data
     * @return array Normalized subscription data
     */
    private function extractSubscriptionData(array $data): array
    {
        $subscription_data = [
            'subscription_id' => null,
            'status' => '',
            'gateway' => '',
            'amount' => null,
            'currency' => '',
            'billing_info' => [],
            'trial_info' => [],
            'payment_info' => [],
        ];

        // Extract from Universal Event structure
        if (isset($data['primaryObjectType']) && $data['primaryObjectType'] === 'subscription') {
            $subscription_data['subscription_id'] = $data['primaryObjectID'] ?? null;
            $subscription_data['status'] = $data['status'] ?? '';
            $subscription_data['gateway'] = $data['sourceGateway'] ?? '';
            $subscription_data['amount'] = $data['amount'] ?? null;
            $subscription_data['currency'] = $data['currency'] ?? '';
        }

        // Extract from legacy structure
        if (isset($data['subscription_id']) || isset($data['subscr_id'])) {
            $subscription_data['subscription_id'] = $data['subscription_id'] ?? $data['subscr_id'];
            $subscription_data['status'] = $data['status'] ?? '';
            $subscription_data['gateway'] = $data['gateway'] ?? '';
            $subscription_data['amount'] = $data['amount'] ?? null;
            $subscription_data['currency'] = $data['currency'] ?? '';
        }

        // Load WooCommerce subscription data if available
        if ($subscription_data['subscription_id'] && function_exists('wcs_get_subscription')) {
            $this->enrichWithWooCommerceData($subscription_data);
        }

        return $subscription_data;
    }

    /**
     * Enrich subscription data with WooCommerce data
     *
     * @param array &$subscription_data The subscription data to enrich
     */
    private function enrichWithWooCommerceData(array &$subscription_data): void
    {
        if (!function_exists('wcs_get_subscription')) {
            return;
        }

        $subscription = wcs_get_subscription($subscription_data['subscription_id']);
        if (!$subscription) {
            return;
        }

        // Update basic information
        $subscription_data['status'] = $subscription->get_status();
        $subscription_data['amount'] = (float)$subscription->get_total();
        $subscription_data['currency'] = $subscription->get_currency();

        // Add billing information
        $subscription_data['billing_info'] = [
            'Next Payment' => $subscription->get_date('next_payment') ?: 'N/A',
            'Billing Period' => $subscription->get_billing_period(),
            'Billing Interval' => $subscription->get_billing_interval(),
        ];

        // Add trial information
        if ($subscription->get_trial_end_date()) {
            $subscription_data['trial_info'] = [
                'Trial End' => $subscription->get_date('trial_end'),
                'Has Trial' => 'Yes',
            ];
        }

        // Add payment information
        $subscription_data['payment_info'] = [
            'Payment Method' => $subscription->get_payment_method_title(),
            'Gateway' => $subscription->get_payment_method(),
        ];
    }

    /**
     * Get user-friendly label for subscription events
     *
     * @param string $event_type The event type
     * @return string User-friendly label
     */
    private function getSubscriptionEventLabel(string $event_type): string
    {
        $labels = [
            'subscription_created' => 'Subscription Created',
            'subscription_approved' => 'Subscription Approved',
            'subscription_cancelled' => 'Subscription Cancelled',
            'subscription_suspended' => 'Subscription Suspended',
            'subscription_reactivated' => 'Subscription Reactivated',
            'subscription_completed' => 'Subscription Completed',
            'subscription_expired' => 'Subscription Expired',
            'subscription_paused' => 'Subscription Paused',
            'subscription_resumed' => 'Subscription Resumed',
            'subscription_updated' => 'Subscription Updated',
            'renewal_payment_completed' => 'Renewal Payment Completed',
            'renewal_payment_failed' => 'Renewal Payment Failed',
            'renewal_payment_processing' => 'Renewal Payment Processing',
            'renewal_payment_pending' => 'Renewal Payment Pending',
            'trial_ending' => 'Trial Ending Soon',
        ];

        return $labels[$event_type] ?? ucwords(str_replace('_', ' ', $event_type));
    }

    /**
     * Sanitize subscription data for privacy
     *
     * @param array $data The data to sanitize
     * @return array Sanitized data
     */
    private function sanitizeSubscriptionData(array $data): array
    {
        $sanitized = $data;

        // Remove or mask sensitive fields
        $sensitive_fields = [
            'email' => true,
            'name' => true,
            'phone' => true,
            'address' => true,
            'billing_details' => true,
            'shipping' => true,
            'customer_email' => true,
            'payer_email' => true,
        ];

        array_walk_recursive($sanitized, function(&$value, $key) use ($sensitive_fields) {
            if (isset($sensitive_fields[$key])) {
                $value = '[MASKED]';
            }
        });

        return $sanitized;
    }
}
