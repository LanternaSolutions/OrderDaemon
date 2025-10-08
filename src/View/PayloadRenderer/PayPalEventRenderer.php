<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

use InvalidArgumentException;
use RuntimeException;

/**
 * PayPal Event Renderer
 *
 * Renders PayPal-specific Universal Event data with rich gateway context for the
 * Insight Dashboard timeline. Provides comprehensive PayPal lifecycle visibility
 * including IPN events, subscription management, payment processing, and dispute tracking.
 *
 * PAYPAL EVENT COVERAGE:
 * =====================
 * 
 * Payment Events:
 * - payment_completed, payment_pending, payment_failed
 * - payment_refunded, payment_reversed, payment_denied
 * 
 * Subscription Events:
 * - subscription_created, subscription_approved, subscription_cancelled
 * - subscription_suspended, subscription_reactivated, subscription_completed
 * 
 * Recurring Payment Events:
 * - recurring_payment, recurring_payment_profile_created
 * - recurring_payment_failed, recurring_payment_skipped
 * 
 * Dispute Events:
 * - dispute_opened, dispute_resolved, dispute_won, dispute_lost
 * 
 * DISPLAY FEATURES:
 * ================
 * 
 * - Compact timeline cards with PayPal branding
 * - Transaction summary (amount, status, payment method)
 * - PayPal-specific IDs (transaction, subscription, profile)
 * - User-friendly event type labels
 * - Expandable technical details and raw IPN data
 * - Error code display with TODO for future explanation lookup
 * - Subscription lifecycle context
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.1.1
 */
class PayPalEventRenderer extends PayloadComponentRenderer
{
    /**
     * {@inheritdoc}
     */
    protected function getComponentId(): string
    {
        return 'paypal_event';
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle(array $data): bool
    {
        // Handle Universal Events with PayPal as source gateway
        if (isset($data['sourceGateway']) && $data['sourceGateway'] === 'paypal') {
            return true;
        }

        // Handle legacy PayPal IPN data structures
        if (isset($data['paypal_transaction_id']) || isset($data['txn_id'])) {
            return true;
        }

        // Handle PayPal-specific event types
        $paypal_events = [
            'payment_completed', 'payment_pending', 'payment_failed',
            'subscription_created', 'subscription_cancelled', 'recurring_payment',
            'dispute_opened', 'dispute_resolved'
        ];

        if (isset($data['eventType']) && in_array($data['eventType'], $paypal_events, true)) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function renderContent(array $data): string
    {
        try {
            $toolkit = new PayloadComponentUIToolkit();
            $content = '';

            // Extract PayPal-specific data
            $paypal_data = $this->extractPayPalData($data);
            
            // Render main transaction summary
            $content .= $this->renderTransactionSummary($paypal_data, $toolkit);
            
            // Render PayPal identifiers section
            if (!empty($paypal_data['identifiers'])) {
                $content .= $this->renderPayPalIdentifiers($paypal_data['identifiers'], $toolkit);
            }
            
            // Render event-specific details
            $content .= $this->renderEventDetails($paypal_data, $toolkit);
            
            // Render error information if present
            if (!empty($paypal_data['errors'])) {
                $content .= $this->renderErrorDetails($paypal_data['errors'], $toolkit);
            }
            
            // Render expandable raw data section
            $content .= $this->renderRawDataSection($data, $toolkit);

            return $content;

        } catch (\Throwable $e) {
            // Fallback to basic rendering if PayPal-specific rendering fails
            return $this->renderFallbackContent($data, $e);
        }
    }

    /**
     * Extract and normalize PayPal data from various Universal Event structures
     *
     * @param array $data Universal Event or legacy PayPal data
     * @return array Normalized PayPal data structure
     */
    private function extractPayPalData(array $data): array
    {
        $paypal_data = [
            'event_type' => '',
            'amount' => null,
            'currency' => '',
            'status' => '',
            'identifiers' => [],
            'customer_info' => [],
            'subscription_info' => [],
            'errors' => [],
            'raw_data' => []
        ];

        // Extract from Universal Event structure
        if (isset($data['eventType'])) {
            $paypal_data['event_type'] = $data['eventType'];
            $paypal_data['amount'] = $data['amount'] ?? null;
            $paypal_data['currency'] = $data['currency'] ?? '';
            $paypal_data['status'] = $data['status'] ?? '';
            
            // Extract from rawData field
            if (isset($data['rawData']) && is_array($data['rawData'])) {
                $paypal_data['raw_data'] = $data['rawData'];
                $this->extractFromRawData($paypal_data, $data['rawData']);
            }
        }

        // Extract from legacy IPN structure
        if (isset($data['txn_id']) || isset($data['paypal_transaction_id'])) {
            $this->extractFromLegacyIPN($paypal_data, $data);
        }

        return $paypal_data;
    }

    /**
     * Extract PayPal data from Universal Event rawData field
     *
     * @param array &$paypal_data PayPal data array to populate
     * @param array $raw_data Raw data from Universal Event
     */
    private function extractFromRawData(array &$paypal_data, array $raw_data): void
    {
        // PayPal transaction identifiers
        $id_fields = [
            'paypal_transaction_id' => 'Transaction ID',
            'txn_id' => 'Transaction ID',
            'paypal_subscription_id' => 'Subscription ID',
            'subscr_id' => 'Subscription ID',
            'recurring_payment_id' => 'Recurring Payment ID',
            'profile_id' => 'Profile ID',
            'parent_txn_id' => 'Parent Transaction ID',
            'case_id' => 'Dispute Case ID'
        ];

        foreach ($id_fields as $field => $label) {
            if (!empty($raw_data[$field])) {
                $paypal_data['identifiers'][$label] = sanitize_text_field((string) $raw_data[$field]);
            }
        }

        // Customer information
        $customer_fields = [
            'payer_email' => 'Payer Email',
            'payer_id' => 'Payer ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name'
        ];

        foreach ($customer_fields as $field => $label) {
            if (!empty($raw_data[$field])) {
                $paypal_data['customer_info'][$label] = sanitize_text_field((string) $raw_data[$field]);
            }
        }

        // Subscription-specific information
        if (!empty($raw_data['period1']) || !empty($raw_data['period3'])) {
            $paypal_data['subscription_info']['Billing Period'] = $raw_data['period3'] ?? $raw_data['period1'] ?? '';
        }
        if (!empty($raw_data['amount3']) || !empty($raw_data['amount1'])) {
            $paypal_data['subscription_info']['Billing Amount'] = $raw_data['amount3'] ?? $raw_data['amount1'] ?? '';
        }

        // Error information
        if (!empty($raw_data['payment_status']) && $raw_data['payment_status'] !== 'Completed') {
            $paypal_data['errors']['Payment Status'] = sanitize_text_field((string) $raw_data['payment_status']);
        }
        if (!empty($raw_data['reason_code'])) {
            $paypal_data['errors']['Reason Code'] = sanitize_text_field((string) $raw_data['reason_code']);
        }
        if (!empty($raw_data['pending_reason'])) {
            $paypal_data['errors']['Pending Reason'] = sanitize_text_field((string) $raw_data['pending_reason']);
        }
    }

    /**
     * Extract PayPal data from legacy IPN structure
     *
     * @param array &$paypal_data PayPal data array to populate
     * @param array $data Legacy IPN data
     */
    private function extractFromLegacyIPN(array &$paypal_data, array $data): void
    {
        $paypal_data['event_type'] = $data['txn_type'] ?? 'paypal_ipn';
        $paypal_data['amount'] = isset($data['mc_gross']) ? (float) $data['mc_gross'] : null;
        $paypal_data['currency'] = $data['mc_currency'] ?? '';
        $paypal_data['status'] = $data['payment_status'] ?? '';

        // Use the entire data array as raw data for legacy structures
        $paypal_data['raw_data'] = $data;
        $this->extractFromRawData($paypal_data, $data);
    }

    /**
     * Render transaction summary section
     *
     * @param array $paypal_data Normalized PayPal data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderTransactionSummary(array $paypal_data, PayloadComponentUIToolkit $toolkit): string
    {
        $summary_data = [];

        // Event type with user-friendly label
        if (!empty($paypal_data['event_type'])) {
            $summary_data['Event Type'] = $this->getEventTypeLabel($paypal_data['event_type']);
        }

        // Amount and currency
        if ($paypal_data['amount'] !== null && !empty($paypal_data['currency'])) {
            $summary_data['Amount'] = sprintf(
                '%s %s',
                number_format((float) $paypal_data['amount'], 2),
                esc_html($paypal_data['currency'])
            );
        }

        // Payment status
        if (!empty($paypal_data['status'])) {
            $status_class = $this->getStatusClass($paypal_data['status']);
            $summary_data['Status'] = sprintf(
                '<span class="odcm-status-badge odcm-status-badge--%s">%s</span>',
                esc_attr($status_class),
                esc_html($paypal_data['status'])
            );
        }

        if (empty($summary_data)) {
            return '';
        }

        return $toolkit->render_key_value_list($summary_data, 'Transaction Summary');
    }

    /**
     * Render PayPal identifiers section
     *
     * @param array $identifiers PayPal ID data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderPayPalIdentifiers(array $identifiers, PayloadComponentUIToolkit $toolkit): string
    {
        if (empty($identifiers)) {
            return '';
        }

        // Format identifiers with monospace styling for better readability
        $formatted_identifiers = [];
        foreach ($identifiers as $label => $value) {
            $formatted_identifiers[$label] = sprintf(
                '<code class="odcm-paypal-id">%s</code>',
                esc_html($value)
            );
        }

        return $toolkit->render_key_value_list($formatted_identifiers, 'PayPal Identifiers');
    }

    /**
     * Render event-specific details section
     *
     * @param array $paypal_data Normalized PayPal data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderEventDetails(array $paypal_data, PayloadComponentUIToolkit $toolkit): string
    {
        $details = [];

        // Customer information
        if (!empty($paypal_data['customer_info'])) {
            foreach ($paypal_data['customer_info'] as $label => $value) {
                // Sanitize customer data (privacy-safe fields only)
                if ($label === 'Payer Email') {
                    // Mask email for privacy
                    $details[$label] = $this->maskEmail($value);
                } else {
                    $details[$label] = esc_html($value);
                }
            }
        }

        // Subscription information
        if (!empty($paypal_data['subscription_info'])) {
            foreach ($paypal_data['subscription_info'] as $label => $value) {
                $details[$label] = esc_html($value);
            }
        }

        if (empty($details)) {
            return '';
        }

        return $toolkit->render_key_value_list($details, 'Event Details');
    }

    /**
     * Render error details section
     *
     * @param array $errors Error data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderErrorDetails(array $errors, PayloadComponentUIToolkit $toolkit): string
    {
        if (empty($errors)) {
            return '';
        }

        $error_content = '';
        foreach ($errors as $label => $value) {
            $error_content .= sprintf(
                '<div class="odcm-error-item"><strong>%s:</strong> <code>%s</code></div>',
                esc_html($label),
                esc_html($value)
            );
        }

        // TODO: Add PayPal error code lookup/explanation functionality
        $error_content .= '<div class="odcm-error-note"><em>Note: Error code explanations will be added in a future update.</em></div>';

        return $toolkit->render_expandable_section('Error Information', $error_content);
    }

    /**
     * Render expandable raw data section
     *
     * @param array $data Original event data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderRawDataSection(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Sanitize raw data for privacy
        $sanitized_data = $this->sanitizeRawData($data);
        
        $raw_data_json = json_encode($sanitized_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $raw_data_code = $toolkit->render_code_block($raw_data_json, 'json');
        
        return $toolkit->render_expandable_section('Raw PayPal Data', $raw_data_code);
    }

    /**
     * Render fallback content when PayPal-specific rendering fails
     *
     * @param array $data Original data
     * @param \Throwable $e Exception that occurred
     * @return string HTML content
     */
    private function renderFallbackContent(array $data, \Throwable $e): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        
        $error_info = [
            'Error' => 'PayPal renderer failed, using fallback',
            'Details' => $e->getMessage()
        ];
        
        $content = $toolkit->render_key_value_list($error_info, 'Rendering Error');
        
        // Still show the raw data
        $sanitized_data = $this->sanitizeRawData($data);
        $raw_data_json = json_encode($sanitized_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $raw_data_code = $toolkit->render_code_block($raw_data_json, 'json');
        $content .= $toolkit->render_expandable_section('PayPal Event Data', $raw_data_code);
        
        return $content;
    }

    /**
     * Get user-friendly label for PayPal event types
     *
     * @param string $event_type PayPal event type
     * @return string User-friendly label
     */
    private function getEventTypeLabel(string $event_type): string
    {
        $labels = [
            // Payment events
            'payment_completed' => 'Payment Completed',
            'payment_pending' => 'Payment Pending',
            'payment_failed' => 'Payment Failed',
            'payment_refunded' => 'Payment Refunded',
            'payment_reversed' => 'Payment Reversed',
            'payment_denied' => 'Payment Denied',
            
            // Subscription events
            'subscription_created' => 'Subscription Created',
            'subscription_approved' => 'Subscription Approved',
            'subscription_cancelled' => 'Subscription Cancelled',
            'subscription_suspended' => 'Subscription Suspended',
            'subscription_reactivated' => 'Subscription Reactivated',
            'subscription_completed' => 'Subscription Completed',
            
            // Recurring payment events
            'recurring_payment' => 'Recurring Payment',
            'recurring_payment_profile_created' => 'Recurring Profile Created',
            'recurring_payment_failed' => 'Recurring Payment Failed',
            'recurring_payment_skipped' => 'Recurring Payment Skipped',
            
            // Dispute events
            'dispute_opened' => 'Dispute Opened',
            'dispute_resolved' => 'Dispute Resolved',
            'dispute_won' => 'Dispute Won',
            'dispute_lost' => 'Dispute Lost',
            
            // Legacy IPN types
            'web_accept' => 'Website Payment',
            'subscr_signup' => 'Subscription Signup',
            'subscr_payment' => 'Subscription Payment',
            'subscr_cancel' => 'Subscription Cancelled',
            'subscr_eot' => 'Subscription End of Term',
            'paypal_ipn' => 'PayPal IPN Event'
        ];

        return $labels[$event_type] ?? ucwords(str_replace('_', ' ', $event_type));
    }

    /**
     * Get CSS class for payment status
     *
     * @param string $status Payment status
     * @return string CSS class suffix
     */
    private function getStatusClass(string $status): string
    {
        $status_lower = strtolower($status);
        
        $status_classes = [
            'completed' => 'success',
            'pending' => 'warning',
            'failed' => 'error',
            'denied' => 'error',
            'refunded' => 'warning',
            'reversed' => 'error',
            'cancelled' => 'error',
            'expired' => 'warning'
        ];

        return $status_classes[$status_lower] ?? 'info';
    }

    /**
     * Mask email address for privacy
     *
     * @param string $email Email address to mask
     * @return string Masked email address
     */
    private function maskEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '[Invalid Email]';
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '[Invalid Email]';
        }

        $username = $parts[0];
        $domain = $parts[1];

        // Mask username (show first and last character if long enough)
        if (strlen($username) <= 2) {
            $masked_username = str_repeat('*', strlen($username));
        } else {
            $masked_username = $username[0] . str_repeat('*', strlen($username) - 2) . substr($username, -1);
        }

        return $masked_username . '@' . $domain;
    }

    /**
     * Sanitize raw data for privacy and security
     *
     * @param array $data Raw data to sanitize
     * @return array Sanitized data
     */
    private function sanitizeRawData(array $data): array
    {
        // Use existing ComponentSanitizer if available
        if (class_exists('\OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer')) {
            $sanitizer = new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer();
            return $sanitizer->sanitize('paypal_event', $data);
        }

        // Fallback manual sanitization
        $sanitized = $data;
        
        // Remove or mask sensitive fields
        $sensitive_fields = [
            'payer_email' => true,
            'first_name' => true,
            'last_name' => true,
            'address_street' => true,
            'address_city' => true,
            'address_zip' => true,
            'contact_phone' => true
        ];

        foreach ($sensitive_fields as $field => $mask) {
            if (isset($sanitized[$field])) {
                if ($field === 'payer_email') {
                    $sanitized[$field] = $this->maskEmail($sanitized[$field]);
                } else {
                    $sanitized[$field] = '[MASKED]';
                }
            }
        }

        return $sanitized;
    }
}
