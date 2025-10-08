<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

use InvalidArgumentException;
use RuntimeException;

/**
 * WooCommerce Subscriptions Event Renderer
 *
 * Renders WooCommerce Subscriptions-specific Universal Event data with rich subscription
 * lifecycle context for the Insight Dashboard timeline. Provides comprehensive subscription
 * visibility including status changes, renewal cycles, trial periods, and payment tracking.
 *
 * SUBSCRIPTION EVENT COVERAGE:
 * ===========================
 * 
 * Subscription Lifecycle Events:
 * - subscription_created, subscription_approved, subscription_cancelled
 * - subscription_suspended, subscription_reactivated, subscription_completed
 * - subscription_expired, subscription_paused, subscription_resumed
 * 
 * Renewal & Payment Events:
 * - renewal_payment_completed, renewal_payment_failed, renewal_payment_processing
 * - renewal_payment_pending, trial_ending, subscription_updated
 * 
 * Cross-Gateway Support:
 * - PayPal Subscriptions (via PayPal IPN/webhooks)
 * - Stripe Subscriptions (via Stripe webhooks)
 * - Manual/System subscription changes
 * 
 * DISPLAY FEATURES:
 * ================
 * 
 * - Subscription lifecycle timeline with status progression
 * - Billing cycle information (period, amount, next payment)
 * - Trial period tracking and expiration alerts
 * - Payment method and gateway context
 * - Customer subscription history
 * - Related order correlation
 * - Expandable subscription details and raw data
 * - Cross-gateway subscription unification
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   next
 */
class SubscriptionEventRenderer extends PayloadComponentRenderer
{
    /**
     * {@inheritdoc}
     */
    protected function getComponentId(): string
    {
        return 'subscription_event';
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle(array $data): bool
    {
        // Handle Universal Events with subscription as primary object
        if (isset($data['primaryObjectType']) && $data['primaryObjectType'] === 'subscription') {
            return true;
        }

        // Handle subscription-specific event types
        $subscription_events = [
            'subscription_created', 'subscription_approved', 'subscription_cancelled',
            'subscription_suspended', 'subscription_reactivated', 'subscription_completed',
            'subscription_expired', 'subscription_paused', 'subscription_resumed',
            'subscription_updated', 'trial_ending',
            'renewal_payment_completed', 'renewal_payment_failed', 'renewal_payment_processing',
            'renewal_payment_pending'
        ];

        if (isset($data['eventType']) && in_array($data['eventType'], $subscription_events, true)) {
            return true;
        }

        // Handle legacy subscription data structures
        if (isset($data['subscription_id']) || isset($data['subscr_id'])) {
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

            // Extract subscription-specific data
            $subscription_data = $this->extractSubscriptionData($data);
            
            // Render subscription summary
            $content .= $this->renderSubscriptionSummary($subscription_data, $toolkit);
            
            // Render billing information
            if (!empty($subscription_data['billing_info'])) {
                $content .= $this->renderBillingInformation($subscription_data['billing_info'], $toolkit);
            }
            
            // Render subscription identifiers
            if (!empty($subscription_data['identifiers'])) {
                $content .= $this->renderSubscriptionIdentifiers($subscription_data['identifiers'], $toolkit);
            }
            
            // Render lifecycle details
            $content .= $this->renderLifecycleDetails($subscription_data, $toolkit);
            
            // Render payment information
            if (!empty($subscription_data['payment_info'])) {
                $content .= $this->renderPaymentInformation($subscription_data['payment_info'], $toolkit);
            }
            
            // Render trial information if present
            if (!empty($subscription_data['trial_info'])) {
                $content .= $this->renderTrialInformation($subscription_data['trial_info'], $toolkit);
            }
            
            // Render error information if present
            if (!empty($subscription_data['errors'])) {
                $content .= $this->renderErrorDetails($subscription_data['errors'], $toolkit);
            }
            
            // Render expandable raw data section
            $content .= $this->renderRawDataSection($data, $toolkit);

            return $content;

        } catch (\Throwable $e) {
            // Fallback to basic rendering if subscription-specific rendering fails
            return $this->renderFallbackContent($data, $e);
        }
    }

    /**
     * Extract and normalize subscription data from various Universal Event structures
     *
     * @param array $data Universal Event or legacy subscription data
     * @return array Normalized subscription data structure
     */
    private function extractSubscriptionData(array $data): array
    {
        $subscription_data = [
            'event_type' => '',
            'subscription_id' => null,
            'status' => '',
            'gateway' => '',
            'amount' => null,
            'currency' => '',
            'identifiers' => [],
            'billing_info' => [],
            'payment_info' => [],
            'trial_info' => [],
            'lifecycle_info' => [],
            'errors' => [],
            'raw_data' => []
        ];

        // Extract from Universal Event structure
        if (isset($data['eventType'])) {
            $subscription_data['event_type'] = $data['eventType'];
            $subscription_data['subscription_id'] = $data['primaryObjectID'] ?? null;
            $subscription_data['status'] = $data['status'] ?? '';
            $subscription_data['gateway'] = $data['sourceGateway'] ?? '';
            $subscription_data['amount'] = $data['amount'] ?? null;
            $subscription_data['currency'] = $data['currency'] ?? '';
            
            // Extract from rawData field
            if (isset($data['rawData']) && is_array($data['rawData'])) {
                $subscription_data['raw_data'] = $data['rawData'];
                $this->extractFromRawData($subscription_data, $data['rawData']);
            }
        }

        // Extract from legacy subscription structure
        if (isset($data['subscription_id']) || isset($data['subscr_id'])) {
            $this->extractFromLegacyData($subscription_data, $data);
        }

        // Load WooCommerce subscription data if available
        if ($subscription_data['subscription_id'] && function_exists('wcs_get_subscription')) {
            $this->enrichWithWooCommerceData($subscription_data);
        }

        return $subscription_data;
    }

    /**
     * Extract subscription data from Universal Event rawData field
     *
     * @param array &$subscription_data Subscription data array to populate
     * @param array $raw_data Raw data from Universal Event
     */
    private function extractFromRawData(array &$subscription_data, array $raw_data): void
    {
        // PayPal subscription data
        if (isset($raw_data['subscr_id'])) {
            $subscription_data['identifiers']['PayPal Subscription ID'] = sanitize_text_field((string) $raw_data['subscr_id']);
        }
        if (isset($raw_data['recurring_payment_id'])) {
            $subscription_data['identifiers']['PayPal Recurring ID'] = sanitize_text_field((string) $raw_data['recurring_payment_id']);
        }

        // Stripe subscription data
        if (isset($raw_data['data']['object']['id']) && strpos($raw_data['data']['object']['id'], 'sub_') === 0) {
            $subscription_data['identifiers']['Stripe Subscription ID'] = sanitize_text_field((string) $raw_data['data']['object']['id']);
        }

        // PayPal billing information
        if (isset($raw_data['period3'])) {
            $subscription_data['billing_info']['Billing Period'] = sanitize_text_field((string) $raw_data['period3']);
        }
        if (isset($raw_data['amount3'])) {
            $subscription_data['billing_info']['Billing Amount'] = sanitize_text_field((string) $raw_data['amount3']);
        }

        // Stripe billing information
        $stripe_object = $raw_data['data']['object'] ?? [];
        if (isset($stripe_object['current_period_start'])) {
            $subscription_data['billing_info']['Current Period Start'] = date('Y-m-d H:i:s', $stripe_object['current_period_start']);
        }
        if (isset($stripe_object['current_period_end'])) {
            $subscription_data['billing_info']['Current Period End'] = date('Y-m-d H:i:s', $stripe_object['current_period_end']);
        }
        if (isset($stripe_object['trial_end'])) {
            $subscription_data['trial_info']['Trial End'] = date('Y-m-d H:i:s', $stripe_object['trial_end']);
        }

        // Payment method information
        if (isset($raw_data['payment_method'])) {
            $subscription_data['payment_info']['Payment Method'] = sanitize_text_field((string) $raw_data['payment_method']);
        }

        // Error information
        if (isset($raw_data['failure_reason'])) {
            $subscription_data['errors']['Failure Reason'] = sanitize_text_field((string) $raw_data['failure_reason']);
        }
    }

    /**
     * Extract subscription data from legacy data structure
     *
     * @param array &$subscription_data Subscription data array to populate
     * @param array $data Legacy subscription data
     */
    private function extractFromLegacyData(array &$subscription_data, array $data): void
    {
        $subscription_data['subscription_id'] = $data['subscription_id'] ?? $data['subscr_id'] ?? null;
        $subscription_data['event_type'] = $data['event_type'] ?? 'subscription_event';
        $subscription_data['status'] = $data['status'] ?? '';
        $subscription_data['gateway'] = $data['gateway'] ?? '';
        $subscription_data['amount'] = $data['amount'] ?? null;
        $subscription_data['currency'] = $data['currency'] ?? '';
        $subscription_data['raw_data'] = $data;
    }

    /**
     * Enrich subscription data with WooCommerce Subscriptions information
     *
     * @param array &$subscription_data Subscription data array to enrich
     */
    private function enrichWithWooCommerceData(array &$subscription_data): void
    {
        if (!$subscription_data['subscription_id'] || !function_exists('wcs_get_subscription')) {
            return;
        }

        try {
            $subscription = wcs_get_subscription($subscription_data['subscription_id']);
            if (!$subscription) {
                return;
            }

            // Basic subscription information
            $subscription_data['status'] = $subscription->get_status();
            $subscription_data['amount'] = (float) $subscription->get_total();
            $subscription_data['currency'] = $subscription->get_currency();

            // Billing information
            $subscription_data['billing_info']['Next Payment'] = $subscription->get_date('next_payment') ?: 'N/A';
            $subscription_data['billing_info']['Billing Period'] = $subscription->get_billing_period();
            $subscription_data['billing_info']['Billing Interval'] = $subscription->get_billing_interval();

            // Trial information
            if ($subscription->get_trial_end_date()) {
                $subscription_data['trial_info']['Trial End'] = $subscription->get_date('trial_end');
                $subscription_data['trial_info']['Has Trial'] = 'Yes';
            }

            // Payment information
            $subscription_data['payment_info']['Payment Method'] = $subscription->get_payment_method_title();
            $subscription_data['payment_info']['Gateway'] = $subscription->get_payment_method();

            // Lifecycle information
            $subscription_data['lifecycle_info']['Created'] = $subscription->get_date('date_created');
            $subscription_data['lifecycle_info']['Start Date'] = $subscription->get_date('start');
            if ($subscription->get_date('end')) {
                $subscription_data['lifecycle_info']['End Date'] = $subscription->get_date('end');
            }

            // Related order information
            if ($subscription->get_parent_id()) {
                $subscription_data['identifiers']['Parent Order'] = '#' . $subscription->get_parent_id();
            }

            // Customer information
            if ($subscription->get_customer_id()) {
                $customer = get_user_by('id', $subscription->get_customer_id());
                if ($customer) {
                    $subscription_data['identifiers']['Customer'] = $this->maskEmail($customer->user_email);
                }
            }

        } catch (\Throwable $e) {
            // Log error but continue with available data
            error_log('SubscriptionEventRenderer: Failed to load WooCommerce subscription data: ' . $e->getMessage());
        }
    }

    /**
     * Render subscription summary section
     *
     * @param array $subscription_data Normalized subscription data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderSubscriptionSummary(array $subscription_data, PayloadComponentUIToolkit $toolkit): string
    {
        $summary_data = [];

        // Event type with user-friendly label
        if (!empty($subscription_data['event_type'])) {
            $summary_data['Event Type'] = $this->getEventTypeLabel($subscription_data['event_type']);
        }

        // Subscription ID
        if ($subscription_data['subscription_id']) {
            $summary_data['Subscription'] = '#' . $subscription_data['subscription_id'];
        }

        // Status with styling
        if (!empty($subscription_data['status'])) {
            $status_class = $this->getStatusClass($subscription_data['status']);
            $summary_data['Status'] = sprintf(
                '<span class="odcm-status-badge odcm-status-badge--%s">%s</span>',
                esc_attr($status_class),
                esc_html($subscription_data['status'])
            );
        }

        // Amount and currency
        if ($subscription_data['amount'] !== null && !empty($subscription_data['currency'])) {
            $summary_data['Amount'] = sprintf(
                '%s %s',
                number_format((float) $subscription_data['amount'], 2),
                esc_html($subscription_data['currency'])
            );
        }

        // Gateway
        if (!empty($subscription_data['gateway'])) {
            $summary_data['Gateway'] = $this->getGatewayLabel($subscription_data['gateway']);
        }

        if (empty($summary_data)) {
            return '';
        }

        return $toolkit->render_key_value_list($summary_data, 'Subscription Summary');
    }

    /**
     * Render billing information section
     *
     * @param array $billing_info Billing information data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderBillingInformation(array $billing_info, PayloadComponentUIToolkit $toolkit): string
    {
        if (empty($billing_info)) {
            return '';
        }

        $formatted_billing = [];
        foreach ($billing_info as $label => $value) {
            $formatted_billing[$label] = esc_html($value);
        }

        return $toolkit->render_key_value_list($formatted_billing, 'Billing Information');
    }

    /**
     * Render subscription identifiers section
     *
     * @param array $identifiers Subscription ID data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderSubscriptionIdentifiers(array $identifiers, PayloadComponentUIToolkit $toolkit): string
    {
        if (empty($identifiers)) {
            return '';
        }

        // Format identifiers with monospace styling for better readability
        $formatted_identifiers = [];
        foreach ($identifiers as $label => $value) {
            if (strpos($label, 'ID') !== false || strpos($label, 'Order') !== false) {
                $formatted_identifiers[$label] = sprintf(
                    '<code class="odcm-subscription-id">%s</code>',
                    esc_html($value)
                );
            } else {
                $formatted_identifiers[$label] = esc_html($value);
            }
        }

        return $toolkit->render_key_value_list($formatted_identifiers, 'Subscription Identifiers');
    }

    /**
     * Render lifecycle details section
     *
     * @param array $subscription_data Normalized subscription data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderLifecycleDetails(array $subscription_data, PayloadComponentUIToolkit $toolkit): string
    {
        $details = [];

        // Lifecycle information
        if (!empty($subscription_data['lifecycle_info'])) {
            foreach ($subscription_data['lifecycle_info'] as $label => $value) {
                $details[$label] = esc_html($value);
            }
        }

        if (empty($details)) {
            return '';
        }

        return $toolkit->render_key_value_list($details, 'Lifecycle Details');
    }

    /**
     * Render payment information section
     *
     * @param array $payment_info Payment information data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderPaymentInformation(array $payment_info, PayloadComponentUIToolkit $toolkit): string
    {
        if (empty($payment_info)) {
            return '';
        }

        $formatted_payment = [];
        foreach ($payment_info as $label => $value) {
            $formatted_payment[$label] = esc_html($value);
        }

        return $toolkit->render_key_value_list($formatted_payment, 'Payment Information');
    }

    /**
     * Render trial information section
     *
     * @param array $trial_info Trial information data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderTrialInformation(array $trial_info, PayloadComponentUIToolkit $toolkit): string
    {
        if (empty($trial_info)) {
            return '';
        }

        $formatted_trial = [];
        foreach ($trial_info as $label => $value) {
            // Highlight trial end dates that are soon
            if ($label === 'Trial End' && $value !== 'N/A') {
                $trial_end = strtotime($value);
                $now = time();
                $days_remaining = ($trial_end - $now) / (24 * 60 * 60);
                
                if ($days_remaining <= 3 && $days_remaining > 0) {
                    $formatted_trial[$label] = sprintf(
                        '<span class="odcm-trial-ending-soon">%s (ending soon!)</span>',
                        esc_html($value)
                    );
                } elseif ($days_remaining <= 0) {
                    $formatted_trial[$label] = sprintf(
                        '<span class="odcm-trial-expired">%s (expired)</span>',
                        esc_html($value)
                    );
                } else {
                    $formatted_trial[$label] = esc_html($value);
                }
            } else {
                $formatted_trial[$label] = esc_html($value);
            }
        }

        return $toolkit->render_key_value_list($formatted_trial, 'Trial Information');
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
        
        return $toolkit->render_expandable_section('Raw Subscription Data', $raw_data_code);
    }

    /**
     * Render fallback content when subscription-specific rendering fails
     *
     * @param array $data Original data
     * @param \Throwable $e Exception that occurred
     * @return string HTML content
     */
    private function renderFallbackContent(array $data, \Throwable $e): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        
        $error_info = [
            'Error' => 'Subscription renderer failed, using fallback',
            'Details' => $e->getMessage()
        ];
        
        $content = $toolkit->render_key_value_list($error_info, 'Rendering Error');
        
        // Still show the raw data
        $sanitized_data = $this->sanitizeRawData($data);
        $raw_data_json = json_encode($sanitized_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $raw_data_code = $toolkit->render_code_block($raw_data_json, 'json');
        $content .= $toolkit->render_expandable_section('Subscription Event Data', $raw_data_code);
        
        return $content;
    }

    /**
     * Get user-friendly label for subscription event types
     *
     * @param string $event_type Subscription event type
     * @return string User-friendly label
     */
    private function getEventTypeLabel(string $event_type): string
    {
        $labels = [
            // Subscription lifecycle events
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
            
            // Renewal and payment events
            'renewal_payment_completed' => 'Renewal Payment Completed',
            'renewal_payment_failed' => 'Renewal Payment Failed',
            'renewal_payment_processing' => 'Renewal Payment Processing',
            'renewal_payment_pending' => 'Renewal Payment Pending',
            
            // Trial events
            'trial_ending' => 'Trial Ending Soon',
            'trial_expired' => 'Trial Expired',
            
            // Generic events
            'subscription_event' => 'Subscription Event',
        ];

        return $labels[$event_type] ?? ucwords(str_replace('_', ' ', $event_type));
    }

    /**
     * Get CSS class for subscription status
     *
     * @param string $status Subscription status
     * @return string CSS class suffix
     */
    private function getStatusClass(string $status): string
    {
        $status_lower = strtolower($status);
        
        $status_classes = [
            'active' => 'success',
            'approved' => 'success',
            'completed' => 'success',
            'pending' => 'warning',
            'on-hold' => 'warning',
            'suspended' => 'warning',
            'paused' => 'warning',
            'cancelled' => 'error',
            'expired' => 'error',
            'failed' => 'error',
            'pending-cancel' => 'warning',
            'trialing' => 'info',
        ];

        return $status_classes[$status_lower] ?? 'info';
    }

    /**
     * Get user-friendly gateway label
     *
     * @param string $gateway Gateway identifier
     * @return string User-friendly gateway name
     */
    private function getGatewayLabel(string $gateway): string
    {
        $gateway_labels = [
            'paypal' => 'PayPal',
            'stripe' => 'Stripe',
            'manual' => 'Manual',
            'bacs' => 'Bank Transfer',
            'cheque' => 'Check',
            'cod' => 'Cash on Delivery',
        ];

        return $gateway_labels[$gateway] ?? ucwords(str_replace('_', ' ', $gateway));
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
            return $sanitizer->sanitize('subscription_event', $data);
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
            'customer_email' => true,
            'payer_email' => true,
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
                if (strpos($key, 'email') !== false) {
                    $value = $this->maskEmail((string) $value);
                } else {
                    $value = '[MASKED]';
                }
            }
        }
    }
}
