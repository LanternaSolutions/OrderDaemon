<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Payment Renderer
 *
 * Handles rendering of all payment-related events:
 * - payment_completed / payment_failed
 * - refund_created
 * - stripe_event / paypal_event
 * - order_partially_refunded / order_fully_refunded
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class PaymentRenderer extends BaseRenderer
{
    /**
     * Constructor
     *
     * Sets the payment-specific theme.
     */
    public function __construct()
    {
        parent::__construct();
        $this->theme = 'payment';
    }

    /**
     * Render Content
     *
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    /**
     * Render Specific Content
     *
     * Implements the template method to provide payment-specific rendering logic.
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array                    $data       The payload data to render
     * @param string                   $event_type The type of event being rendered
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @return string HTML content
     */
    protected function renderSpecificContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string
    {
        switch ($event_type) {
            case 'payment_completed':
            case 'payment_failed':
                return $this->renderPayment($data, $toolkit);

            case 'refund_created':
                return $this->renderRefund($data, $toolkit);

            case 'stripe_event':
                return $this->renderStripeEvent($data, $toolkit);

            case 'paypal_event':
                return $this->renderPayPalEvent($data, $toolkit);

            case 'order_partially_refunded':
            case 'order_fully_refunded':
                return $this->renderOrderRefund($data, $toolkit, $event_type === 'order_fully_refunded');

            default:
                return $this->renderGenericPayment($data, $toolkit);
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
            case 'payment_completed':
                return 'Payment Completed';

            case 'payment_failed':
                return 'Payment Failed';

            case 'refund_created':
                return 'Refund Created';

            case 'stripe_event':
                // Stripe's API uses 'type' for event types (e.g., 'charge.succeeded', 'payment_intent.created')
                // See: https://stripe.com/docs/api/events/object#event_object-type
                return 'Stripe: ' . ($data['type'] ?? 'Event');

            case 'paypal_event':
                // PayPal's API uses 'event_type' for event types (e.g., 'PAYMENT.CAPTURE.COMPLETED')
                // See: https://developer.paypal.com/docs/api/webhooks/v1/#webhooks-events
                return 'PayPal: ' . ($data['event_type'] ?? 'Event');

            case 'order_partially_refunded':
                return 'Order Partially Refunded';

            case 'order_fully_refunded':
                return 'Order Fully Refunded';

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
            case 'payment_completed':
                return ['label' => 'COMPLETED', 'type' => 'success'];

            case 'payment_failed':
                return ['label' => 'FAILED', 'type' => 'error'];

            case 'refund_created':
                return ['label' => 'REFUND', 'type' => 'warning'];

            case 'stripe_event':
                return ['label' => 'STRIPE', 'type' => 'gateway'];

            case 'paypal_event':
                return ['label' => 'PAYPAL', 'type' => 'gateway'];

            case 'order_partially_refunded':
                return ['label' => 'PARTIAL REFUND', 'type' => 'warning'];

            case 'order_fully_refunded':
                return ['label' => 'FULL REFUND', 'type' => 'warning'];

            default:
                return null;
        }
    }

    /**
     * Get Theme
     *
     * All payment events use the 'payment' theme for consistent styling.
     *
     * @param string $event_type The type of event
     * @return string Theme identifier
     */
    /**
     * Render Payment
     *
     * Renders payment completion or failure details.
     *
     * @param array                    $data    The payment data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderPayment(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Business-relevant payment information
        $payment_data = [
            'Amount' => isset($data['amount'], $data['currency']) 
                ? $this->formatCurrency($data['amount'], $data['currency']) 
                : '',
            'Transaction ID' => $data['transaction_id'] ?? '',
            'Payment Method' => $data['payment_method'] ?? '',
            'Gateway' => ucfirst($data['source_gateway'] ?? ''),
        ];

        // Add order ID if available
        if (isset($data['order_id'])) {
            $payment_data['Order'] = '#' . $data['order_id'];
        }

        // Add error details for failed payments
        if (isset($data['error'])) {
            $payment_data['Error'] = $data['error'];
        }
        
        // Determine section title based on status
        $sectionTitle = isset($data['error']) ? 'Payment Failed' : 'Payment Completed';

        $content = $toolkit->render_key_value_list($payment_data, $sectionTitle);

        // Move technical details to debug section
        $data['technical_details'] = [
            'raw_status' => $data['status'] ?? '',
            'event_id' => $data['event_id'] ?? '',
            'correlation_id' => $data['correlation_id'] ?? '',
            'request_id' => $data['request_id'] ?? '',
        ];
        
        // Add gateway response to the debug section
        if (!empty($data['gateway_response'])) {
            $data['technical_details']['gateway_response'] = $data['gateway_response'];
        }

        return $content;
    }

    /**
     * Render Refund
     *
     * Renders refund creation details.
     *
     * @param array                    $data    The refund data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderRefund(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $refund_data = [
            'Amount' => isset($data['amount'], $data['currency']) 
                ? $this->formatCurrency($data['amount'], $data['currency']) 
                : '',
            'Refund ID' => isset($data['refund_id']) ? '#' . $data['refund_id'] : '',
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Reason' => $data['reason'] ?? '',
            'User ID' => isset($data['user_id']) ? $this->getUserName($data['user_id']) : '',
        ];

        $content = $toolkit->render_key_value_list($refund_data, 'Refund Details');

        // Add refunded items in expandable section if available
        if (!empty($data['items'])) {
            $items_json = json_encode($data['items'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($items_json, 'json');
            $content .= $toolkit->render_expandable_section('Refunded Items', $code_block);
        }

        return $content;
    }

    /**
     * Render Stripe Event
     *
     * Renders Stripe-specific event details.
     *
     * @param array                    $data    The Stripe event data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderStripeEvent(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Business-relevant Stripe information (keep original event type as-is)
        $stripe_data = [
            'Event Type' => $data['type'] ?? '',
            'Event ID' => $data['id'] ?? '',
            'Amount' => isset($data['amount'], $data['currency']) 
                ? $this->formatCurrency($data['amount'], $data['currency']) 
                : '',
        ];
        
        // Add order information if available
        if (isset($data['order_id'])) {
            $stripe_data['Order'] = '#' . $data['order_id'];
        }

        $content = $toolkit->render_key_value_list($stripe_data, 'Stripe Event');

        // Move technical details to debug section
        $data['technical_details'] = [
            'stripe_object' => $data['object'] ?? '',
            'api_version' => $data['api_version'] ?? '',
            'created' => $data['created'] ?? '',
            'livemode' => $data['livemode'] ?? '',
        ];
        
        // Add full event data to debug section
        if (!empty($data['event_data'])) {
            $data['technical_details']['event_data'] = $data['event_data'];
        }

        return $content;
    }

    /**
     * Render PayPal Event
     *
     * Renders PayPal-specific event details.
     *
     * @param array                    $data    The PayPal event data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderPayPalEvent(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Business-relevant PayPal information (keep original event type as-is)
        $paypal_data = [
            'Event Type' => $data['event_type'] ?? '',
            'Resource Type' => $data['resource_type'] ?? '',
            'Amount' => isset($data['amount'], $data['currency']) 
                ? $this->formatCurrency($data['amount'], $data['currency']) 
                : '',
        ];
        
        // Add order information if available
        if (isset($data['order_id'])) {
            $paypal_data['Order'] = '#' . $data['order_id'];
        }

        $content = $toolkit->render_key_value_list($paypal_data, 'PayPal Event');

        // Move technical details to debug section
        $data['technical_details'] = [
            'webhook_id' => $data['webhook_id'] ?? '',
            'create_time' => $data['create_time'] ?? '',
            'resource_id' => $data['resource_id'] ?? '',
            'api_version' => $data['api_version'] ?? '',
        ];
        
        // Add full resource data to debug section
        if (!empty($data['resource'])) {
            $data['technical_details']['resource'] = $data['resource'];
        }

        return $content;
    }

    /**
     * Render Order Refund
     *
     * Renders order refund details, handling both partial and full refunds.
     *
     * @param array                    $data    The refund data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @param bool                     $is_full Whether this is a full refund
     * @return string HTML content
     */
    private function renderOrderRefund(array $data, PayloadComponentUIToolkit $toolkit, bool $is_full): string
    {
        $refund_data = [
            'Amount' => isset($data['amount'], $data['currency']) 
                ? $this->formatCurrency($data['amount'], $data['currency']) 
                : '',
            'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Refund ID' => isset($data['refund_id']) ? '#' . $data['refund_id'] : '',
        ];

        // Add percentage for partial refunds
        if (!$is_full && isset($data['impact']['refund_percentage'])) {
            $refund_data['Percentage'] = $data['impact']['refund_percentage'] . '%';
        }
        
        // Add refund reason if available
        if (!empty($data['reason'])) {
            $refund_data['Reason'] = $data['reason'];
        }
        
        // Add user info if available
        if (!empty($data['user_id']) || !empty($data['created_by'])) {
            $userId = $data['user_id'] ?? $data['created_by'] ?? '';
            $refund_data['Processed By'] = $this->getUserName($userId);
        }

        $title = $is_full ? 'Full Refund' : 'Partial Refund';
        $content = $toolkit->render_key_value_list($refund_data, $title);

        // Move technical details to debug section
        $data['technical_details'] = [
            'transaction_id' => $data['transaction_id'] ?? '',
            'gateway' => $data['gateway'] ?? '',
            'correlation_id' => $data['correlation_id'] ?? '',
        ];
        
        // Add refunded items to debug section
        if (!empty($data['impact']['items_refunded'])) {
            $data['technical_details']['items_refunded'] = $data['impact']['items_refunded'];
        }
        
        if (!empty($data['impact'])) {
            $data['technical_details']['impact'] = $data['impact'];
        }

        return $content;
    }

    /**
     * Render Generic Payment
     *
     * Fallback renderer for unrecognized payment events.
     *
     * @param array                    $data    The payment data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderGenericPayment(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        return $toolkit->render_code_block(
            json_encode($data, JSON_PRETTY_PRINT),
            'json'
        );
    }
}
