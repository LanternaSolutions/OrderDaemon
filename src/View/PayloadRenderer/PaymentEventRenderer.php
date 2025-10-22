<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Payment Event Renderer
 *
 * Universal renderer for all payment-related events from any gateway.
 * Handles payment completions, failures, refunds, and other payment lifecycle events.
 *
 * This renderer detects the gateway from the source_gateway field and displays
 * appropriate branding while maintaining consistency across all payment gateways.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

class PaymentEventRenderer extends PayloadComponentRenderer
{
    /**
     * Get Component ID for Registry Lookup
     *
     * @since 1.0.0
     *
     * @return string Component identifier.
     */
    protected function getComponentId(): string
    {
        return 'payment_event';
    }

    /**
     * Render embedded content for payment context.
     *
     * Shows compact payment summary inline. Falls back to parent default 
     * when data doesn't match expected payment structures.
     *
     * @param array $data
     * @return string
     */
    public function renderEmbeddedContent(array $data): string
    {
        // Try to extract payment summary data
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? 'USD';
        $gateway = $data['source_gateway'] ?? $data['gateway'] ?? '';
        $status = $data['processing_result'] ?? $data['status'] ?? null;
        
        if ($amount !== null && $gateway !== '') {
            $parts = [];
            
            // Gateway badge
            $gateway_label = ucfirst($gateway);
            $parts[] = '<span class="odcm-gateway-badge odcm-gateway-' . esc_attr(strtolower($gateway)) . '">' . esc_html($gateway_label) . '</span>';
            
            // Amount
            $formatted_amount = $this->formatCurrency((float)$amount, $currency);
            $parts[] = '<span class="odcm-payment-amount">' . esc_html($formatted_amount) . '</span>';
            
            // Status if available
            if ($status !== null) {
                $status_text = $status === true || $status === 'true' ? __('Success', 'order-daemon') : __('Failed', 'order-daemon');
                $status_class = $status === true || $status === 'true' ? 'success' : 'error';
                $parts[] = '<span class="odcm-payment-status odcm-status-' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span>';
            }
            
            return '<span class="odcm-payment-context">' . implode(' ', $parts) . '</span>';
        }
        
        return parent::renderEmbeddedContent($data);
    }

    /**
     * Render Payment Event Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform payment data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing gateway-agnostic payment event rendering
     * 5. Handling embedded context content from the timeline consolidation system
     *
     * @since 1.0.0
     *
     * @param array $data Payment event data.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];

        // Handle embedded context content from timeline consolidation
        $embedded_context = '';
        if (isset($data['embedded_context_content']) && is_array($data['embedded_context_content'])) {
            $embedded_context = implode('', array_filter($data['embedded_context_content'], 'is_string'));
        }

        // === DATA ADAPTATION PHASE ===
        // Transform payment data into simple, clean formats using private adapters
        
        // Adapt payment summary information
        $payment_html = $this->adaptPaymentSummary($data, $toolkit);
        if ($payment_html !== null) {
            $html_parts[] = $payment_html;
        }
        
        // Adapt gateway information
        $gateway_html = $this->adaptGatewayInformation($data, $toolkit);
        if ($gateway_html !== null) {
            $html_parts[] = $gateway_html;
        }
        
        // Adapt transaction details
        $transaction_html = $this->adaptTransactionDetails($data, $toolkit);
        if ($transaction_html !== null) {
            $html_parts[] = $transaction_html;
        }
        
        // Adapt order context
        $order_html = $this->adaptOrderContext($data, $toolkit);
        if ($order_html !== null) {
            $html_parts[] = $order_html;
        }
        
        // Adapt customer information
        $customer_html = $this->adaptCustomerInformation($data, $toolkit);
        if ($customer_html !== null) {
            $html_parts[] = $customer_html;
        }
        
        // Adapt execution metrics
        $metrics_html = $this->adaptExecutionMetrics($data, $toolkit);
        if ($metrics_html !== null) {
            $html_parts[] = $metrics_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific payment components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        // Append embedded context to the final output
        $final_html = implode('', $html_parts);
        return $final_html . $embedded_context;
    }

    /**
     * Adapt Payment Summary
     *
     * Transforms payment summary data into formatted display.
     * Handles amount, currency, status, and payment type.
     *
     * @since 1.0.0
     *
     * @param array $data Raw payment data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for payment summary or null if no payment data found.
     */
    private function adaptPaymentSummary(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $payment_data = [];
        
        // Extract payment summary information
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? 'USD';
        $status = $data['processing_result'] ?? $data['status'] ?? null;
        $event_type = $data['event_type'] ?? '';
        
        if ($amount !== null) {
            $payment_data['Amount'] = $this->formatCurrency((float)$amount, $currency);
        }
        
        if ($currency !== null) {
            $payment_data['Currency'] = strtoupper((string)$currency);
        }
        
        if ($event_type !== '') {
            $payment_data['Event Type'] = ucwords(str_replace('_', ' ', $event_type));
        }
        
        // Format processing result
        if ($status !== null) {
            if (is_bool($status)) {
                $status_text = $status ? __('Success', 'order-daemon') : __('Failed', 'order-daemon');
            } else {
                $status_text = ucfirst((string)$status);
            }
            $payment_data['Status'] = $status_text;
        }
        
        // Only render if we have meaningful payment data
        if (empty($payment_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($payment_data, 'Payment Summary');
    }

    /**
     * Adapt Gateway Information
     *
     * Transforms gateway data into formatted display with branding.
     * Handles gateway name, channel, and gateway-specific details.
     *
     * @since 1.0.0
     *
     * @param array $data Raw payment data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for gateway information or null if no gateway data found.
     */
    private function adaptGatewayInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $gateway_data = [];
        
        // Extract gateway information
        $gateway = $data['source_gateway'] ?? $data['gateway'] ?? null;
        $channel = $data['channel'] ?? null;
        
        if ($gateway !== null) {
            $gateway_data['Gateway'] = ucfirst((string)$gateway);
        }
        
        if ($channel !== null) {
            $gateway_data['Channel'] = ucfirst((string)$channel);
        }
        
        // Only render if we have meaningful gateway data
        if (empty($gateway_data)) {
            return null;
        }
        
        // Add gateway status pill if we have a gateway
        $gateway_pill = '';
        if ($gateway !== null) {
            $gateway_pill = $toolkit->render_status_pill(strtoupper((string)$gateway), 'gateway');
        }
        
        $content = $toolkit->render_key_value_list($gateway_data, 'Gateway Information');
        return $gateway_pill . $content;
    }

    /**
     * Adapt Transaction Details
     *
     * Transforms transaction data into formatted display.
     * Handles transaction ID, idempotency key, and execution time.
     *
     * @since 1.0.0
     *
     * @param array $data Raw payment data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for transaction details or null if no transaction data found.
     */
    private function adaptTransactionDetails(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $transaction_data = [];
        
        // Extract transaction details
        $transaction_id = $data['transaction_id'] ?? null;
        $idempotency_key = $data['idempotency_key'] ?? null;
        $execution_time = $data['execution_time_ms'] ?? null;
        
        if ($transaction_id !== null && $transaction_id !== 'null' && $transaction_id !== '') {
            $transaction_data['Transaction ID'] = (string)$transaction_id;
        }
        
        if ($idempotency_key !== null) {
            $transaction_data['Idempotency Key'] = (string)$idempotency_key;
        }
        
        if ($execution_time !== null) {
            $transaction_data['Execution Time'] = sprintf('%.2f ms', (float)$execution_time);
        }
        
        // Only render if we have meaningful transaction data
        if (empty($transaction_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($transaction_data, 'Transaction Details');
    }

    /**
     * Adapt Order Context
     *
     * Transforms order context data into formatted display.
     * Handles order ID, primary object type, and order relationships.
     *
     * @since 1.0.0
     *
     * @param array $data Raw payment data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for order context or null if no order data found.
     */
    private function adaptOrderContext(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $order_data = [];
        
        // Extract order context
        $order_id = $data['primary_object_id'] ?? $data['order_id'] ?? null;
        $object_type = $data['primary_object_type'] ?? null;
        $has_order = $data['has_order'] ?? null;
        $has_subscription = $data['has_subscription'] ?? null;
        
        if ($order_id !== null) {
            $order_data['Order ID'] = '#' . $order_id;
        }
        
        if ($object_type !== null) {
            $order_data['Object Type'] = ucfirst((string)$object_type);
        }
        
        if ($has_order !== null) {
            $order_data['Has Order'] = $has_order ? __('Yes', 'order-daemon') : __('No', 'order-daemon');
        }
        
        if ($has_subscription !== null) {
            $order_data['Has Subscription'] = $has_subscription ? __('Yes', 'order-daemon') : __('No', 'order-daemon');
        }
        
        // Only render if we have meaningful order data
        if (empty($order_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($order_data, 'Order Context');
    }

    /**
     * Adapt Customer Information
     *
     * Transforms customer data into formatted display.
     * Handles customer ID and customer relationships.
     *
     * @since 1.0.0
     *
     * @param array $data Raw payment data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for customer information or null if no customer data found.
     */
    private function adaptCustomerInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $customer_data = [];
        
        // Extract customer information
        $customer_id = $data['customer_id'] ?? null;
        
        if ($customer_id !== null) {
            $customer_data['Customer ID'] = (string)$customer_id;
        }
        
        // Only render if we have meaningful customer data
        if (empty($customer_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($customer_data, 'Customer Information');
    }

    /**
     * Adapt Execution Metrics
     *
     * Transforms execution metrics into formatted display.
     * Handles execution time, processing results, and performance data.
     *
     * @since 1.0.0
     *
     * @param array $data Raw payment data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for execution metrics or null if no metrics found.
     */
    private function adaptExecutionMetrics(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $metrics_data = [];
        
        // Extract execution metrics
        $execution_time = $data['execution_time_ms'] ?? null;
        $processing_result = $data['processing_result'] ?? null;
        
        if ($execution_time !== null) {
            $metrics_data['Execution Time'] = sprintf('%.2f ms', (float)$execution_time);
        }
        
        if ($processing_result !== null) {
            $result_text = is_bool($processing_result) 
                ? ($processing_result ? __('Success', 'order-daemon') : __('Failed', 'order-daemon'))
                : ucfirst((string)$processing_result);
            $metrics_data['Processing Result'] = $result_text;
        }
        
        // Only render if we have meaningful metrics data
        if (empty($metrics_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($metrics_data, 'Execution Metrics');
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized payment data into JSON format as a fallback.
     * Ensures that all payment data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw payment data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for fallback data display.
     */
    private function adaptFallbackData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        return $toolkit->render_code_block($json_content, 'json');
    }

    /**
     * Format Currency
     *
     * Formats currency values with appropriate symbols and formatting.
     *
     * @since 1.0.0
     *
     * @param float $amount Currency amount.
     * @param string $currency Currency code.
     * @return string Formatted currency string.
     */
    private function formatCurrency(float $amount, string $currency = 'USD'): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
        ];
        
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }

    /**
     * Check if this renderer can handle the provided data
     *
     * This method provides Tier 2 fallback detection for payment events.
     * It's used when Tier 1 registry lookup fails.
     *
     * @since 1.0.0
     *
     * @param array $data Data to check.
     * @return bool True if this renderer can handle the data.
     */
    public function canHandle(array $data): bool
    {
        // Check for payment-related keys
        $payment_keys = [
            'amount', 'currency', 'source_gateway', 'gateway', 'transaction_id',
            'payment_method', 'processing_result', 'idempotency_key'
        ];
        
        foreach ($payment_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        // Check event_type for payment-related events (merged in from Tier 2 lookup)
        $event_type = $data['event_type'] ?? '';
        $payment_event_types = [
            'payment_completed', 'payment_failed', 'payment_pending',
            'refund_created', 'refund_completed', 'refund_failed',
            'charge_created', 'charge_failed', 'payment_intent_succeeded'
        ];
        
        if (in_array($event_type, $payment_event_types, true)) {
            return true;
        }
        
        return false;
    }
}
