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
    protected function getTheme(string $event_type): string
    {
        return 'payment';
    }

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

        $content = $toolkit->render_key_value_list($payment_data, 'Payment Details');

        // Add gateway response in expandable section if available
        if (!empty($data['gateway_response'])) {
            $response_json = json_encode($data['gateway_response'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($response_json, 'json');
            $content .= $toolkit->render_expandable_section('Gateway Response', $code_block);
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
        $stripe_data = [
            'Event Type' => $data['type'] ?? '',
            'Event ID' => $data['id'] ?? '',
            'Amount' => isset($data['amount'], $data['currency']) 
                ? $this->formatCurrency($data['amount'], $data['currency']) 
                : '',
        ];

        $content = $toolkit->render_key_value_list($stripe_data, 'Stripe Event');

        // Add event data in expandable section
        if (!empty($data['event_data'])) {
            $event_json = json_encode($data['event_data'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($event_json, 'json');
            $content .= $toolkit->render_expandable_section('Event Data', $code_block);
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
        $paypal_data = [
            'Event Type' => $data['event_type'] ?? '',
            'Resource Type' => $data['resource_type'] ?? '',
            'Amount' => isset($data['amount'], $data['currency']) 
                ? $this->formatCurrency($data['amount'], $data['currency']) 
                : '',
        ];

        $content = $toolkit->render_key_value_list($paypal_data, 'PayPal Event');

        // Add resource data in expandable section
        if (!empty($data['resource'])) {
            $resource_json = json_encode($data['resource'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($resource_json, 'json');
            $content .= $toolkit->render_expandable_section('Resource Data', $code_block);
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

        $content = $toolkit->render_key_value_list($refund_data, 'Order Refund');

        // Add refunded items in expandable section if available
        if (!empty($data['impact']['items_refunded'])) {
            $items_json = json_encode($data['impact']['items_refunded'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($items_json, 'json');
            $content .= $toolkit->render_expandable_section('Refunded Items', $code_block);
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
