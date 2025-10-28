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
     * Uses pattern matching to handle hierarchical payment.* event types.
     *
     * @param array                    $data       The payload data to render
     * @param string                   $event_type The type of event being rendered
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @return string HTML content
     */
    protected function renderSpecificContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string
    {
        // All payment events use hierarchical naming: payment.{gateway}.{original_event_type}
        if (strpos($event_type, 'payment.') === 0) {
            $gateway = $this->extractGatewayFromEventType($event_type);
            $original_event = $this->extractOriginalEventFromType($event_type);
            
            return $this->renderHierarchicalPayment($data, $event_type, $gateway, $original_event, $toolkit);
        }
        
        // Fallback for non-payment events
        return $this->renderGenericPayment($data, $toolkit);
    }

    /**
     * Get Label
     *
     * Provides event-specific labels based on event type and data.
     * Handles hierarchical payment.* events only.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $data, string $event_type): string
    {
        if (strpos($event_type, 'payment.') === 0) {
            return 'Payment Event';
        }
        
        // Fallback for non-payment events
        return parent::getLabel($data, $event_type);
    }

    /**
     * Get Status Pill
     *
     * Provides event-specific status pills based on event type and outcome.
     * Handles hierarchical payment.* events only.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
        if (strpos($event_type, 'payment.') === 0) {
            $gateway = $this->extractGatewayFromEventType($event_type);
            $original_event = $this->extractOriginalEventFromType($event_type);
            
            // Determine status from original event semantics
            if ($this->isPaymentSuccess($original_event)) {
                return ['label' => 'COMPLETED', 'type' => 'success'];
            } elseif ($this->isPaymentFailure($original_event)) {
                return ['label' => 'FAILED', 'type' => 'error'];
            } elseif ($this->isPaymentWarning($original_event)) {
                return ['label' => 'WARNING', 'type' => 'warning'];
            } else {
                // Fallback for unrecognized events
                return ['label' => 'INFO', 'type' => 'info'];
            }
        }
        
        return null;
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
     * Renders payment completion or failure details with enhanced data extraction.
     *
     * @param array                    $payload The full payload (including rawData)
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderPayment(array $payload, PayloadComponentUIToolkit $toolkit): string
    {
        // Extract data from both component data and rawData
        $data = $payload['data'] ?? $payload;
        $rawData = $payload['rawData'] ?? [];

        // Enhanced data extraction from multiple sources
        $payment_data = [
            'Amount' => $this->extractAmount($data, $rawData),
            'Transaction ID' => $this->extractTransactionId($data, $rawData),
            'Payment Method' => $this->extractPaymentMethod($data, $rawData),
            'Gateway' => $this->extractGateway($data, $rawData),
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

        // Add gateway-specific details in expandable sections
        if (isset($rawData['stripe_data']) && !empty($rawData['stripe_data'])) {
            $stripe_data = array_filter($rawData['stripe_data']); // Remove empty values
            if (!empty($stripe_data)) {
                $content .= $toolkit->render_expandable_key_value_section('Stripe Details', $stripe_data);
            }
        }

        if (isset($rawData['paypal_data']) && !empty($rawData['paypal_data'])) {
            $paypal_data = array_filter($rawData['paypal_data']);
            if (!empty($paypal_data)) {
                $content .= $toolkit->render_expandable_key_value_section('PayPal Details', $paypal_data);
            }
        }

        // Add payment technical details in expandable section if rawData available
        if (!empty($rawData)) {
            $content .= $toolkit->render_expandable_key_value_section('Technical Details', $rawData);
        }

        return $content;
    }

    /**
     * Extract transaction ID from multiple data sources
     */
    private function extractTransactionId(array $data, array $rawData): string
    {
        // Try multiple sources for transaction ID
        $transaction_id = $data['transaction_id'] ?? 
                         $rawData['transaction_id'] ?? 
                         $rawData['stripe_data']['charge_id'] ?? 
                         $rawData['paypal_data']['transaction_id'] ?? 
                         '';
        
        // Try checkout context for Block Checkout events
        if (empty($transaction_id) && isset($rawData['checkout_context'])) {
            $checkout_context = $rawData['checkout_context'];
            $transaction_id = $checkout_context['transaction_id'] ?? 
                             $checkout_context['payment_context']['transaction_id'] ?? 
                             '';
        }
        
        // Try gateway-specific webhook data
        if (empty($transaction_id) && isset($rawData['stripe_webhook_data'])) {
            $stripe_data = $rawData['stripe_webhook_data'];
            $transaction_id = $stripe_data['data']['object']['id'] ?? 
                             $stripe_data['data']['object']['charge'] ?? 
                             '';
        }
        
        if (empty($transaction_id) && isset($rawData['paypal_ipn_data'])) {
            $paypal_data = $rawData['paypal_ipn_data'];
            $transaction_id = $paypal_data['txn_id'] ?? '';
        }
        
        return $transaction_id;
    }

    /**
     * Extract payment method title from multiple data sources
     */
    private function extractPaymentMethod(array $data, array $rawData): string
    {
        return $data['payment_method'] ?? 
               $rawData['payment_method_title'] ?? 
               $rawData['payment_method_id'] ?? 
               '';
    }

    /**
     * Extract gateway name from multiple data sources
     */
    private function extractGateway(array $data, array $rawData): string
    {
        $gateway = $data['source_gateway'] ?? $data['gateway'] ?? '';
        
        if (empty($gateway) && isset($rawData['payment_method_id'])) {
            $gateway = $this->normalizeGatewayName($rawData['payment_method_id']);
        }
        
        return ucfirst($gateway);
    }

    /**
     * Normalize gateway name for display
     */
    private function normalizeGatewayName(string $payment_method): string
    {
        $mapping = [
            'stripe' => 'Stripe',
            'stripe_cc' => 'Stripe',
            'paypal' => 'PayPal', 
            'ppcp-gateway' => 'PayPal',
            'bacs' => 'Bank Transfer',
            'cod' => 'Cash on Delivery',
        ];
        
        return $mapping[$payment_method] ?? $payment_method;
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
     * Extract amount from checkout context or component data
     */
    private function extractAmount(array $data, array $rawData): string
    {
        // Check multiple sources for amount data
        $amount = null;
        $currency = null;
        
        // Try rawData.checkout_context first (most reliable for payment events)
        if (isset($rawData['checkout_context']['total_amount'])) {
            $amount = (float) $rawData['checkout_context']['total_amount'];
        }
        
        // Try payment_context
        if ($amount === null && isset($rawData['payment_context']['amount'])) {
            $amount = (float) $rawData['payment_context']['amount'];
        }
        
        // Try component data
        if ($amount === null && isset($data['amount'])) {
            $amount = (float) $data['amount'];
        }
        
        // Try rawData direct
        if ($amount === null && isset($rawData['amount'])) {
            $amount = (float) $rawData['amount'];
        }
        
        // Get currency
        $currency = $rawData['checkout_context']['currency'] ?? 
                   $rawData['payment_context']['currency'] ?? 
                   $data['currency'] ?? 
                   $rawData['currency'] ?? 
                   'USD';
        
        if ($amount !== null && $amount > 0) {
            // Use the same plain text formatting approach as OrderRenderer
            $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol($currency));
            return $currency_symbol . number_format($amount, 2);
        }
        
        return '';
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

    /**
     * Extract gateway from hierarchical event type
     *
     * @param string $event_type Event type like payment.paypal.web_accept
     * @return string Gateway name (paypal, stripe, etc.)
     */
    private function extractGatewayFromEventType(string $event_type): string
    {
        $parts = explode('.', $event_type);
        return $parts[1] ?? 'unknown';
    }

    /**
     * Extract original event type from hierarchical event type
     *
     * @param string $event_type Event type like payment.paypal.web_accept
     * @return string Original event type (web_accept, charge.succeeded, etc.)
     */
    private function extractOriginalEventFromType(string $event_type): string
    {
        $parts = explode('.', $event_type, 3); // Split into max 3 parts
        return $parts[2] ?? '';
    }

    /**
     * Render hierarchical payment event
     *
     * @param array $data Full payload including rawData
     * @param string $event_type Full hierarchical event type
     * @param string $gateway Gateway name (paypal, stripe, etc.)
     * @param string $original_event Original gateway event type
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderHierarchicalPayment(array $data, string $event_type, string $gateway, string $original_event, PayloadComponentUIToolkit $toolkit): string
    {
        // Extract data from both component data and rawData
        $componentData = $data['data'] ?? $data;
        $rawData = $data['rawData'] ?? [];

        // Build payment display data
        $payment_data = [
            'Gateway' => ucfirst($gateway),
            'Event Type' => $original_event,
        ];

        // Add order info if available
        if (isset($componentData['order_id'])) {
            $payment_data['Order'] = '#' . $componentData['order_id'];
        }

        // Add status information
        if (isset($componentData['status'])) {
            $payment_data['Status'] = ucfirst($componentData['status']);
        }

        $content = $toolkit->render_key_value_list($payment_data, 'Payment Event');

        // Add gateway-specific rawData in expandable section
        if (isset($rawData["{$gateway}_ipn_data"]) && !empty($rawData["{$gateway}_ipn_data"])) {
            $gateway_data = array_filter($rawData["{$gateway}_ipn_data"]);
            if (!empty($gateway_data)) {
                $content .= $toolkit->render_expandable_key_value_section(
                    ucfirst($gateway) . ' IPN Data', 
                    $gateway_data
                );
            }
        }

        if (isset($rawData["{$gateway}_webhook_data"]) && !empty($rawData["{$gateway}_webhook_data"])) {
            $webhook_data = array_filter($rawData["{$gateway}_webhook_data"]);
            if (!empty($webhook_data)) {
                $content .= $toolkit->render_expandable_key_value_section(
                    ucfirst($gateway) . ' Webhook Data', 
                    $webhook_data
                );
            }
        }

        // Add all technical details in expandable section
        if (!empty($rawData)) {
            $content .= $toolkit->render_expandable_key_value_section('Technical Details', $rawData);
        }

        return $content;
    }


    /**
     * Check if original event indicates payment success
     *
     * @param string $original_event Original gateway event type
     * @return bool True if this represents a successful payment
     */
    private function isPaymentSuccess(string $original_event): bool
    {
        $success_patterns = [
            'completed', 'succeeded', 'paid', 'captured', 'web_accept',
            'checkout_processed', 'processed', 'approved', 'confirmed'
        ];
        
        $lower_event = strtolower($original_event);
        foreach ($success_patterns as $pattern) {
            if (strpos($lower_event, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if original event indicates payment failure
     *
     * @param string $original_event Original gateway event type
     * @return bool True if this represents a failed payment
     */
    private function isPaymentFailure(string $original_event): bool
    {
        $failure_patterns = [
            'failed', 'denied', 'declined', 'rejected', 'canceled', 'cancelled'
        ];
        
        $lower_event = strtolower($original_event);
        foreach ($failure_patterns as $pattern) {
            if (strpos($lower_event, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if original event indicates payment warning (refunds, disputes, etc.)
     *
     * @param string $original_event Original gateway event type
     * @return bool True if this represents a warning-level payment event
     */
    private function isPaymentWarning(string $original_event): bool
    {
        $warning_patterns = [
            'refunded', 'refund', 'reversed', 'chargeback', 'dispute'
        ];
        
        $lower_event = strtolower($original_event);
        foreach ($warning_patterns as $pattern) {
            if (strpos($lower_event, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
