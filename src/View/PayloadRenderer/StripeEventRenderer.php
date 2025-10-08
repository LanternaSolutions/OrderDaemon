<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Stripe Event Renderer
 *
 * Renders Stripe-specific Universal Event data with rich gateway context for the
 * Insight Dashboard timeline. Provides comprehensive Stripe lifecycle visibility
 * including webhook events, payment processing, subscription management, and dispute tracking.
 *
 * STRIPE EVENT COVERAGE:
 * ======================
 * 
 * Payment Intent Events:
 * - payment_intent.created, payment_intent.succeeded, payment_intent.payment_failed
 * - payment_intent.canceled, payment_intent.requires_action, payment_intent.processing
 * 
 * Charge Events:
 * - charge.succeeded, charge.failed, charge.pending, charge.captured
 * - charge.updated, charge.refunded, charge.dispute.created
 * 
 * Subscription Events:
 * - customer.subscription.created, customer.subscription.updated, customer.subscription.deleted
 * - customer.subscription.trial_will_end, customer.subscription.paused, customer.subscription.resumed
 * 
 * Invoice Events:
 * - invoice.created, invoice.finalized, invoice.payment_succeeded, invoice.payment_failed
 * - invoice.payment_action_required, invoice.upcoming, invoice.voided
 * 
 * Customer Events:
 * - customer.created, customer.updated, customer.deleted
 * - customer.source.created, customer.source.updated, customer.source.deleted
 * 
 * Dispute Events:
 * - charge.dispute.created, charge.dispute.updated, charge.dispute.closed
 * - charge.dispute.funds_withdrawn, charge.dispute.funds_reinstated
 * 
 * DISPLAY FEATURES:
 * ================
 * 
 * - Compact timeline cards with Stripe branding
 * - Transaction summary (amount, status, payment method)
 * - Stripe-specific IDs (payment intent, charge, subscription, customer)
 * - User-friendly event type labels
 * - Expandable technical details and raw webhook data
 * - Error code display with TODO for future explanation lookup
 * - Subscription and invoice lifecycle context
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   next
 */
class StripeEventRenderer extends PayloadComponentRenderer
{
    /**
     * {@inheritdoc}
     */
    protected function getComponentId(): string
    {
        return 'stripe_event';
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle(array $data): bool
    {
        // Handle Universal Events with Stripe as source gateway
        if (isset($data['sourceGateway']) && $data['sourceGateway'] === 'stripe') {
            return true;
        }

        // Handle legacy Stripe webhook data structures
        if (isset($data['stripe_event_id']) || isset($data['id'])) {
            return true;
        }

        // Handle Stripe-specific event types
        $stripe_events = [
            'payment_intent_created', 'payment_intent_succeeded', 'payment_intent_payment_failed',
            'charge_succeeded', 'charge_failed', 'customer_subscription_created',
            'invoice_payment_succeeded', 'invoice_payment_failed', 'charge_dispute_created'
        ];

        if (isset($data['eventType']) && in_array($data['eventType'], $stripe_events, true)) {
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

            // Extract Stripe-specific data
            $stripe_data = $this->extractStripeData($data);
            
            // Render main transaction summary
            $content .= $this->renderTransactionSummary($stripe_data, $toolkit);
            
            // Render Stripe identifiers section
            if (!empty($stripe_data['identifiers'])) {
                $content .= $this->renderStripeIdentifiers($stripe_data['identifiers'], $toolkit);
            }
            
            // Render event-specific details
            $content .= $this->renderEventDetails($stripe_data, $toolkit);
            
            // Render error information if present
            if (!empty($stripe_data['errors'])) {
                $content .= $this->renderErrorDetails($stripe_data['errors'], $toolkit);
            }
            
            // Render expandable raw data section
            $content .= $this->renderRawDataSection($data, $toolkit);

            return $content;

        } catch (\Throwable $e) {
            // Fallback to basic rendering if Stripe-specific rendering fails
            return $this->renderFallbackContent($data, $e);
        }
    }

    /**
     * Extract and normalize Stripe data from various Universal Event structures
     *
     * @param array $data Universal Event or legacy Stripe data
     * @return array Normalized Stripe data structure
     */
    private function extractStripeData(array $data): array
    {
        $stripe_data = [
            'event_type' => '',
            'amount' => null,
            'currency' => '',
            'status' => '',
            'identifiers' => [],
            'customer_info' => [],
            'subscription_info' => [],
            'invoice_info' => [],
            'payment_method_info' => [],
            'errors' => [],
            'raw_data' => []
        ];

        // Extract from Universal Event structure
        if (isset($data['eventType'])) {
            $stripe_data['event_type'] = $data['eventType'];
            $stripe_data['amount'] = $data['amount'] ?? null;
            $stripe_data['currency'] = $data['currency'] ?? '';
            $stripe_data['status'] = $data['status'] ?? '';
            
            // Extract from rawData field
            if (isset($data['rawData']) && is_array($data['rawData'])) {
                $stripe_data['raw_data'] = $data['rawData'];
                $this->extractFromRawData($stripe_data, $data['rawData']);
            }
        }

        // Extract from legacy Stripe webhook structure
        if (isset($data['id']) || isset($data['type'])) {
            $this->extractFromLegacyWebhook($stripe_data, $data);
        }

        return $stripe_data;
    }

    /**
     * Extract Stripe data from Universal Event rawData field
     *
     * @param array &$stripe_data Stripe data array to populate
     * @param array $raw_data Raw data from Universal Event
     */
    private function extractFromRawData(array &$stripe_data, array $raw_data): void
    {
        // Extract object data if it exists
        $object_data = $raw_data['data']['object'] ?? $raw_data;

        // Stripe identifiers
        $id_fields = [
            'id' => 'Object ID',
            'payment_intent' => 'Payment Intent ID',
            'charge' => 'Charge ID',
            'subscription' => 'Subscription ID',
            'customer' => 'Customer ID',
            'invoice' => 'Invoice ID',
            'source' => 'Source ID',
            'payment_method' => 'Payment Method ID'
        ];

        foreach ($id_fields as $field => $label) {
            if (!empty($object_data[$field])) {
                $stripe_data['identifiers'][$label] = sanitize_text_field((string) $object_data[$field]);
            }
        }

        // Customer information
        if (!empty($object_data['customer'])) {
            $stripe_data['customer_info']['Customer ID'] = sanitize_text_field((string) $object_data['customer']);
        }
        if (!empty($object_data['billing_details']['email'])) {
            $stripe_data['customer_info']['Email'] = $this->maskEmail($object_data['billing_details']['email']);
        }
        if (!empty($object_data['billing_details']['name'])) {
            $stripe_data['customer_info']['Name'] = sanitize_text_field((string) $object_data['billing_details']['name']);
        }

        // Subscription information
        if (!empty($object_data['current_period_start'])) {
            $stripe_data['subscription_info']['Current Period Start'] = date('Y-m-d H:i:s', $object_data['current_period_start']);
        }
        if (!empty($object_data['current_period_end'])) {
            $stripe_data['subscription_info']['Current Period End'] = date('Y-m-d H:i:s', $object_data['current_period_end']);
        }
        if (!empty($object_data['trial_end'])) {
            $stripe_data['subscription_info']['Trial End'] = date('Y-m-d H:i:s', $object_data['trial_end']);
        }

        // Invoice information
        if (!empty($object_data['amount_due'])) {
            $stripe_data['invoice_info']['Amount Due'] = number_format($object_data['amount_due'] / 100, 2);
        }
        if (!empty($object_data['amount_paid'])) {
            $stripe_data['invoice_info']['Amount Paid'] = number_format($object_data['amount_paid'] / 100, 2);
        }
        if (!empty($object_data['due_date'])) {
            $stripe_data['invoice_info']['Due Date'] = date('Y-m-d H:i:s', $object_data['due_date']);
        }

        // Payment method information
        if (!empty($object_data['payment_method_types'])) {
            $stripe_data['payment_method_info']['Payment Methods'] = implode(', ', $object_data['payment_method_types']);
        }
        if (!empty($object_data['last4'])) {
            $stripe_data['payment_method_info']['Card Last 4'] = sanitize_text_field((string) $object_data['last4']);
        }
        if (!empty($object_data['brand'])) {
            $stripe_data['payment_method_info']['Card Brand'] = sanitize_text_field((string) $object_data['brand']);
        }

        // Error information
        if (!empty($object_data['status']) && !in_array($object_data['status'], ['succeeded', 'paid', 'active'], true)) {
            $stripe_data['errors']['Status'] = sanitize_text_field((string) $object_data['status']);
        }
        if (!empty($object_data['failure_code'])) {
            $stripe_data['errors']['Failure Code'] = sanitize_text_field((string) $object_data['failure_code']);
        }
        if (!empty($object_data['failure_reason'])) {
            $stripe_data['errors']['Failure Reason'] = sanitize_text_field((string) $object_data['failure_reason']);
        }
        if (!empty($object_data['decline_code'])) {
            $stripe_data['errors']['Decline Code'] = sanitize_text_field((string) $object_data['decline_code']);
        }
        if (!empty($object_data['last_payment_error']['code'])) {
            $stripe_data['errors']['Payment Error Code'] = sanitize_text_field((string) $object_data['last_payment_error']['code']);
        }
    }

    /**
     * Extract Stripe data from legacy webhook structure
     *
     * @param array &$stripe_data Stripe data array to populate
     * @param array $data Legacy webhook data
     */
    private function extractFromLegacyWebhook(array &$stripe_data, array $data): void
    {
        $stripe_data['event_type'] = $data['type'] ?? 'stripe_webhook';
        
        // Extract object data
        $object_data = $data['data']['object'] ?? $data;
        
        if (isset($object_data['amount'])) {
            $stripe_data['amount'] = (float) $object_data['amount'] / 100; // Convert from cents
        }
        $stripe_data['currency'] = isset($object_data['currency']) ? strtoupper($object_data['currency']) : '';
        $stripe_data['status'] = $object_data['status'] ?? '';

        // Use the entire data array as raw data for legacy structures
        $stripe_data['raw_data'] = $data;
        $this->extractFromRawData($stripe_data, $data);
    }

    /**
     * Render transaction summary section
     *
     * @param array $stripe_data Normalized Stripe data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderTransactionSummary(array $stripe_data, PayloadComponentUIToolkit $toolkit): string
    {
        $summary_data = [];

        // Event type with user-friendly label
        if (!empty($stripe_data['event_type'])) {
            $summary_data['Event Type'] = $this->getEventTypeLabel($stripe_data['event_type']);
        }

        // Amount and currency
        if ($stripe_data['amount'] !== null && !empty($stripe_data['currency'])) {
            $summary_data['Amount'] = sprintf(
                '%s %s',
                number_format((float) $stripe_data['amount'], 2),
                esc_html($stripe_data['currency'])
            );
        }

        // Payment status
        if (!empty($stripe_data['status'])) {
            $status_class = $this->getStatusClass($stripe_data['status']);
            $summary_data['Status'] = sprintf(
                '<span class="odcm-status-badge odcm-status-badge--%s">%s</span>',
                esc_attr($status_class),
                esc_html($stripe_data['status'])
            );
        }

        if (empty($summary_data)) {
            return '';
        }

        return $toolkit->render_key_value_list($summary_data, 'Transaction Summary');
    }

    /**
     * Render Stripe identifiers section
     *
     * @param array $identifiers Stripe ID data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderStripeIdentifiers(array $identifiers, PayloadComponentUIToolkit $toolkit): string
    {
        if (empty($identifiers)) {
            return '';
        }

        // Format identifiers with monospace styling for better readability
        $formatted_identifiers = [];
        foreach ($identifiers as $label => $value) {
            $formatted_identifiers[$label] = sprintf(
                '<code class="odcm-stripe-id">%s</code>',
                esc_html($value)
            );
        }

        return $toolkit->render_key_value_list($formatted_identifiers, 'Stripe Identifiers');
    }

    /**
     * Render event-specific details section
     *
     * @param array $stripe_data Normalized Stripe data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderEventDetails(array $stripe_data, PayloadComponentUIToolkit $toolkit): string
    {
        $details = [];

        // Customer information
        if (!empty($stripe_data['customer_info'])) {
            foreach ($stripe_data['customer_info'] as $label => $value) {
                $details[$label] = esc_html($value);
            }
        }

        // Subscription information
        if (!empty($stripe_data['subscription_info'])) {
            foreach ($stripe_data['subscription_info'] as $label => $value) {
                $details[$label] = esc_html($value);
            }
        }

        // Invoice information
        if (!empty($stripe_data['invoice_info'])) {
            foreach ($stripe_data['invoice_info'] as $label => $value) {
                $details[$label] = esc_html($value);
            }
        }

        // Payment method information
        if (!empty($stripe_data['payment_method_info'])) {
            foreach ($stripe_data['payment_method_info'] as $label => $value) {
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

        // TODO: Add Stripe error code lookup/explanation functionality
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
        
        return $toolkit->render_expandable_section('Raw Stripe Data', $raw_data_code);
    }

    /**
     * Render fallback content when Stripe-specific rendering fails
     *
     * @param array $data Original data
     * @param \Throwable $e Exception that occurred
     * @return string HTML content
     */
    private function renderFallbackContent(array $data, \Throwable $e): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        
        $error_info = [
            'Error' => 'Stripe renderer failed, using fallback',
            'Details' => $e->getMessage()
        ];
        
        $content = $toolkit->render_key_value_list($error_info, 'Rendering Error');
        
        // Still show the raw data
        $sanitized_data = $this->sanitizeRawData($data);
        $raw_data_json = json_encode($sanitized_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $raw_data_code = $toolkit->render_code_block($raw_data_json, 'json');
        $content .= $toolkit->render_expandable_section('Stripe Event Data', $raw_data_code);
        
        return $content;
    }

    /**
     * Get user-friendly label for Stripe event types
     *
     * @param string $event_type Stripe event type
     * @return string User-friendly label
     */
    private function getEventTypeLabel(string $event_type): string
    {
        $labels = [
            // Payment Intent events
            'payment_intent_created' => 'Payment Intent Created',
            'payment_intent_succeeded' => 'Payment Succeeded',
            'payment_intent_payment_failed' => 'Payment Failed',
            'payment_intent_canceled' => 'Payment Cancelled',
            'payment_intent_requires_action' => 'Payment Requires Action',
            'payment_intent_processing' => 'Payment Processing',
            
            // Charge events
            'charge_succeeded' => 'Charge Succeeded',
            'charge_failed' => 'Charge Failed',
            'charge_pending' => 'Charge Pending',
            'charge_captured' => 'Charge Captured',
            'charge_updated' => 'Charge Updated',
            'charge_refunded' => 'Charge Refunded',
            'charge_dispute_created' => 'Dispute Created',
            
            // Subscription events
            'customer_subscription_created' => 'Subscription Created',
            'customer_subscription_updated' => 'Subscription Updated',
            'customer_subscription_deleted' => 'Subscription Cancelled',
            'customer_subscription_trial_will_end' => 'Trial Ending Soon',
            'customer_subscription_paused' => 'Subscription Paused',
            'customer_subscription_resumed' => 'Subscription Resumed',
            
            // Invoice events
            'invoice_created' => 'Invoice Created',
            'invoice_finalized' => 'Invoice Finalized',
            'invoice_payment_succeeded' => 'Invoice Paid',
            'invoice_payment_failed' => 'Invoice Payment Failed',
            'invoice_payment_action_required' => 'Invoice Requires Action',
            'invoice_upcoming' => 'Upcoming Invoice',
            'invoice_voided' => 'Invoice Voided',
            
            // Customer events
            'customer_created' => 'Customer Created',
            'customer_updated' => 'Customer Updated',
            'customer_deleted' => 'Customer Deleted',
            'customer_source_created' => 'Payment Method Added',
            'customer_source_updated' => 'Payment Method Updated',
            'customer_source_deleted' => 'Payment Method Removed',
            
            // Dispute events
            'charge_dispute_updated' => 'Dispute Updated',
            'charge_dispute_closed' => 'Dispute Resolved',
            'charge_dispute_funds_withdrawn' => 'Dispute Funds Withdrawn',
            'charge_dispute_funds_reinstated' => 'Dispute Funds Reinstated',
            
            // Refund events
            'refund_created' => 'Refund Created',
            'refund_updated' => 'Refund Updated',
            'refund_failed' => 'Refund Failed',
            
            // Payout events
            'payout_created' => 'Payout Created',
            'payout_updated' => 'Payout Updated',
            'payout_paid' => 'Payout Completed',
            'payout_failed' => 'Payout Failed',
            'payout_canceled' => 'Payout Cancelled',
            
            // Setup Intent events
            'setup_intent_created' => 'Setup Intent Created',
            'setup_intent_succeeded' => 'Setup Intent Completed',
            'setup_intent_setup_failed' => 'Setup Intent Failed',
            'setup_intent_canceled' => 'Setup Intent Cancelled',
            
            // Universal event mappings
            'payment_created' => 'Payment Created',
            'payment_completed' => 'Payment Completed',
            'payment_failed' => 'Payment Failed',
            'payment_cancelled' => 'Payment Cancelled',
            'payment_requires_action' => 'Payment Requires Action',
            'payment_processing' => 'Payment Processing',
            'subscription_created' => 'Subscription Created',
            'subscription_updated' => 'Subscription Updated',
            'subscription_cancelled' => 'Subscription Cancelled',
            'renewal_payment_completed' => 'Renewal Payment Completed',
            'renewal_payment_failed' => 'Renewal Payment Failed',
            'dispute_opened' => 'Dispute Opened',
            'dispute_resolved' => 'Dispute Resolved'
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
            'succeeded' => 'success',
            'paid' => 'success',
            'active' => 'success',
            'pending' => 'warning',
            'requires_action' => 'warning',
            'requires_payment_method' => 'warning',
            'processing' => 'info',
            'failed' => 'error',
            'canceled' => 'error',
            'cancelled' => 'error',
            'incomplete' => 'warning',
            'incomplete_expired' => 'error',
            'trialing' => 'info',
            'past_due' => 'warning',
            'unpaid' => 'warning',
            'voided' => 'error'
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
            return $sanitizer->sanitize('stripe_event', $data);
        }

        // Fallback manual sanitization
        $sanitized = $data;
        
        // Remove or mask sensitive fields
        $sensitive_fields = [
            'email' => true,
            'name' => true,
            'phone' => true,
            'address' => true,
            'billing_details' => true,
            'shipping' => true,
            'receipt_email' => true
        ];

        $this->sanitizeArrayRecursive($sanitized, $sensitive_fields);

        return $sanitized;
    }

    /**
     * Recursively sanitize array data
     *
     * @param array &$data Array to sanitize
     * @param array $sensitive_fields Fields to mask
     */
    private function sanitizeArrayRecursive(array &$data, array $sensitive_fields): void
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $this->sanitizeArrayRecursive($value, $sensitive_fields);
            } elseif (isset($sensitive_fields[$key])) {
                if ($key === 'email' || $key === 'receipt_email') {
                    $value = $this->maskEmail((string) $value);
                } else {
                    $value = '[MASKED]';
                }
            }
        }
    }
}
