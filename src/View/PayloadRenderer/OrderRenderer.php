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
     * Constructor
     *
     * Sets the WooCommerce-specific theme.
     */
    public function __construct()
    {
        parent::__construct();
        $this->theme = 'woocommerce';
    }

    /**
     * Render Specific Content
     *
     * Implements the template method to provide order-specific rendering logic.
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array                    $data       The payload data to render
     * @param string                   $event_type The type of event being rendered
     * @param array                     $payload    The full event payload.
     * @param string                    $event_type The type of event being rendered.
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance.
     * @return string HTML content
     */
    protected function renderSpecificContent(array $payload, string $event_type, PayloadComponentUIToolkit $toolkit): string
    {
        // DEBUG: Log event routing
        $this->logDebugMessage("ODCM DEBUG - OrderRenderer::renderSpecificContent called");
        $this->logDebugMessage("ODCM DEBUG - Event type: '$event_type'");
        $this->logDebugMessage("ODCM DEBUG - Payload keys: " . implode(', ', array_keys($payload)));

        // The actual component data is often nested.
        $data = $payload['data'] ?? $payload;

        // Handle subscription events
        if ($this->isSubscriptionEvent($event_type) || $this->hasSubscriptionData($data)) {
            $this->logDebugMessage("ODCM DEBUG - Routing to subscription renderer");
            return $this->renderSubscriptionEvent($data, $event_type, $toolkit);
        }

        // Check for event_type in data if not provided directly
        if ($event_type === '' && isset($data['event_type']) && is_string($data['event_type'])) {
            $event_type = $data['event_type'];
            $this->logDebugMessage("ODCM DEBUG - Updated event_type from data: '$event_type'");
        }

        // Handle regular order events
        $this->logDebugMessage("ODCM DEBUG - Checking switch for event_type: '$event_type'");
        switch ($event_type) {
            case 'status_changed':
                $this->logDebugMessage("ODCM DEBUG - Routing to renderStatusChange");
                return $this->renderStatusChange($data, $toolkit);

            case 'order_loaded':
                $this->logDebugMessage("ODCM DEBUG - Routing to renderOrderLoaded");
                return $this->renderOrderLoaded($data, $toolkit);

            case 'checkout_processed':
            case 'block_checkout_processed':
                $this->logDebugMessage("ODCM DEBUG - Routing to renderBlockCheckout for event: '$event_type'");
                // Pass the full payload to get access to rawData
                return $this->renderBlockCheckout($payload, $toolkit);

            case 'meta_updated':
                $this->logDebugMessage("ODCM DEBUG - Routing to renderMetaUpdate");
                return $this->renderMetaUpdate($data, $toolkit);

            case 'woocommerce_data':
                $this->logDebugMessage("ODCM DEBUG - Routing to renderWooCommerceData");
                return $this->renderWooCommerceData($data, $toolkit);
                
            case 'order_created':
                $this->logDebugMessage("ODCM DEBUG - Routing to renderOrderCreated");
                return $this->renderOrderCreated($data, $toolkit);
                
            case 'order_check_scheduled':
                $this->logDebugMessage("ODCM DEBUG - Routing to renderOrderCheckScheduled");
                return $this->renderOrderCheckScheduled($data, $toolkit);

            default:
                $this->logDebugMessage("ODCM DEBUG - Routing to renderGenericOrder (default case)");
                return $this->renderGenericOrder($data, $toolkit);
        }
    }

    /**
     * Get Label
     *
     * Provides event-specific labels based on event type and data.
     *
     * @param array  $payload    The full payload data (including nested component data)
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $payload, string $event_type): string
    {
        switch ($event_type) {
            case 'status_changed':
                // Extract nested data the same way renderStatusChange() does
                $component_data = $payload['data'] ?? $payload;
                $from = ucfirst($component_data['from'] ?? 'unknown');
                $to = ucfirst($component_data['to'] ?? 'unknown');
                $to_display = $this->formatStatusForDisplay($to);
                return "Status Changed to {$to_display}";

            case 'order_loaded':
                $component_data = $payload['data'] ?? $payload;
                return isset($component_data['order_id']) 
                    ? "Order #{$component_data['order_id']}"
                    : 'Order Loaded';

            case 'checkout_processed': // Added to handle all checkout types
            case 'block_checkout_processed':
                return 'Checkout Completed';

            case 'order_check_scheduled':
                return 'Rule Evaluation Check';

            case 'meta_updated':
                $component_data = $payload['data'] ?? $payload;
                if (isset($component_data['meta_key'])) {
                    // Make meta keys more user-friendly
                    $meta_label = str_replace('_', ' ', $component_data['meta_key']);
                    return ucwords($meta_label) . " Updated";
                }
                return 'Order Updated';

            case 'woocommerce_data':
                $component_data = $payload['data'] ?? $payload;
                if (isset($component_data['type']) && $component_data['type'] === 'order') {
                    return 'Order Information';
                }
                return 'Order Details';

            default:
                // For unknown event types, show the technical event type instead of generic fallback
                return !empty($event_type) ? ucwords(str_replace('_', ' ', $event_type)) : 'Unknown Event';
        }
    }

    /**
     * Get Status Pill
     *
     * Provides event-specific status pills based on event type and outcome.
     * Prioritizes debug pills for debug events.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
        // First, check if this is a debug event - if so, return debug pill
        if ($this->isDebugEvent($data)) {
            return ['label' => 'DEBUG', 'type' => 'debug'];
        }
        
        switch ($event_type) {
            case 'status_changed':
                // Pills are unnecessary for status change events in timeline
                return null;

            case 'order_loaded':
                return ['label' => 'LOADED', 'type' => 'info'];

            case 'checkout_processed': // Added to handle all checkout types
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
    /**
     * Render Status Change
     *
     * Renders order status change details with manual change attribution.
     *
     * @param array                    $data    The status change data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderStatusChange(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $from_status = $data['from'] ?? 'pending';
        $to_status = $data['to'] ?? '';
        
        // Convert "checkout-draft" to more readable format
        $from_display = $this->formatStatusForDisplay($from_status);
        $to_display = $this->formatStatusForDisplay($to_status);

        $status_data = [
            'From' => $from_display,
            'To' => $to_display,
            'Order' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
        ];

        // Extract manual change attribution from components data
        $is_manual = $data['manual_change'] ?? false;
        $changed_by = $data['changed_by_user_name'] ?? '';
        $bypassed_automation = $data['bypassed_automation'] ?? false;

        // Add manual change context to main timeline display
        if ($is_manual && !empty($changed_by)) {
            $status_data['Changed By'] = $changed_by;
            $status_data['Type'] = 'Manual';
        } else {
            $status_data['Type'] = 'Automatic';
        }

        $content = $toolkit->render_key_value_list($status_data, 'Status Change');

        // Show automation bypass warning if present
        if ($bypassed_automation && isset($data['automation_bypass_warning'])) {
            $warning = $data['automation_bypass_warning'];
            $content .= $toolkit->render_warning_message($warning);
        }

        return $content;
    }
    
    /**
     * Format status for display
     */
    private function formatStatusForDisplay(string $status): string
    {
        $status_map = [
            'checkout-draft' => 'Checkout Draft',
            'pending' => 'Pending',
            'processing' => 'Processing', 
            'completed' => 'Completed',
            'on-hold' => 'On Hold',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            'failed' => 'Failed',
        ];
        
        return $status_map[$status] ?? ucfirst($status);
    }
    
    /**
     * Helper method to get previous status from order history
     */
    private function getPreviousStatusFromOrder($order): ?string
    {
        if (!method_exists($order, 'get_status_transition_notes')) {
            return null;
        }
        
        $notes = $order->get_status_transition_notes();
        if (empty($notes)) {
            return null;
        }
        
        // Parse the most recent note before current status
        foreach ($notes as $note) {
            if (preg_match('/Status changed from (.*?) to /', $note, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
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
    private function renderBlockCheckout(array $payload, PayloadComponentUIToolkit $toolkit): string
    {
        // DEBUG: Log what we're receiving
        $this->logDebugMessage("ODCM DEBUG - renderBlockCheckout called");
        $this->logDebugMessage("ODCM DEBUG - Payload keys: " . implode(', ', array_keys($payload)));
        $this->logDebugMessage("ODCM DEBUG - rawData exists: " . (isset($payload['rawData']) ? 'YES' : 'NO'));
        if (isset($payload['rawData'])) {
            $this->logDebugMessage("ODCM DEBUG - rawData keys: " . implode(', ', array_keys($payload['rawData'])));
            $this->logDebugMessage("ODCM DEBUG - rawData empty: " . (empty($payload['rawData']) ? 'YES' : 'NO'));
        }

        // --- 1. Main Business Content ---
        // The primary component data is still expected in the 'data' sub-array.
        $data = $payload['data'] ?? $payload;
        $total_display = '';
        if (isset($data['total'], $data['currency'])) {
            $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol($data['currency']));
            $total_display = $currency_symbol . number_format((float)$data['total'], 2);
        }

        $checkout_data = [
            'Order ID'       => isset($data['order_id']) ? '#' . $data['order_id'] : '',
            'Status'         => ucfirst($data['status'] ?? ''),
            'Payment Method' => $data['payment_method'] ?? '',
            'Total'          => $total_display,
        ];
        $content = $toolkit->render_key_value_list($checkout_data, 'Checkout Completed');

        // --- 2. Expandable Technical Details Section ---
        // With the pipeline widened at the extractor, rawData is now at the top level of the payload.
        $raw_data = $payload['rawData'] ?? [];

        // DEBUG: Log the condition check
        $this->logDebugMessage("ODCM DEBUG - raw_data empty check: " . (empty($raw_data) ? 'EMPTY (will skip)' : 'NOT EMPTY (will render)'));

        // Use the entire rawData structure as technical details (it contains the full webhook payload).
        // Display the section only if there is data to show.
        if (!empty($raw_data)) {
            $this->logDebugMessage("ODCM DEBUG - Calling render_expandable_key_value_section with " . count($raw_data) . " items");
            // Use the new "smart" rendering method to handle nested data.
            $expandable_content = $toolkit->render_expandable_key_value_section('Technical Details', $raw_data);
            $this->logDebugMessage("ODCM DEBUG - Expandable content length: " . strlen($expandable_content));
            $content .= $expandable_content;
        } else {
            $this->logDebugMessage("ODCM DEBUG - Skipping expandable section - rawData is empty");
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
        // Special case for order_created events
        if (isset($data['event_type']) && $data['event_type'] === 'order_created') {
            return $this->renderOrderCreated($data, $toolkit);
        }
        
        // Special case for order_check_scheduled events
        if (isset($data['event_type']) && $data['event_type'] === 'order_check_scheduled') {
            return $this->renderOrderCheckScheduled($data, $toolkit);
        }

        // Handle status change processing events
        if (isset($data['type']) && in_array($data['type'], ['status_change_processing', 'manual_status_change'])) {
            // Business-relevant status change information
            $order_data = [
                'Order ID' => isset($data['order_id']) ? '#' . $data['order_id'] : '',
                'Status' => 'Status Update',
            ];
            
            // Add actor if human-initiated
            if (isset($data['actor']['name']) && $data['actor']['name'] !== 'system') {
                $order_data['Updated By'] = ucfirst($data['actor']['name']);
            }

            $content = $toolkit->render_key_value_list($order_data, 'Order Status Update');

            // Find status info in components
            $status_from = 'unknown';
            $status_to = 'unknown';
            
            if (!empty($data['components']) && is_array($data['components'])) {
                foreach ($data['components'] as $component) {
                    if (!is_array($component)) {
                        continue;
                    }
                    
                    // Extract status change info
                    if ($component['event_type'] === 'status_changed' && isset($component['data'])) {
                        // Try to get a better previous status if "unknown"
                        $status_from = ucfirst($component['data']['from'] ?? 'unknown');
                        if ($status_from === 'Unknown' && isset($data['order_id']) && function_exists('wc_get_order')) {
                            $order = wc_get_order($data['order_id']);
                            if ($order) {
                                $previous = $this->getPreviousStatusFromOrder($order);
                                if ($previous) {
                                    $status_from = ucfirst($previous);
                                }
                            }
                        }
                        
                        $status_to = ucfirst($component['data']['to'] ?? 'unknown');
                        
                        // Add the status change as primary business information
                        $status_info = [
                            'New Status' => $status_to,
                            'Prev Status' => $status_from
                        ];
                        
                        $content .= $toolkit->render_key_value_list($status_info, 'Status Change');
                        break;
                    }
                }
            }
            
            // Move technical details to debug section
            $data['technical_details'] = [
                'correlation_id' => $data['cid'] ?? '',
                'process_type' => $data['type'] ?? '',
                'actor_id' => isset($data['actor']['id']) ? $data['actor']['id'] : null,
                'actor_role' => isset($data['actor']['role']) ? $data['actor']['role'] : null,
                'components_count' => is_array($data['components'] ?? null) ? count($data['components']) : 0,
            ];
            
            return $content;
        }

        // For other generic data, separate business vs. technical
        $business_data = [];
        $technical_data = [];
        
        // List of keys that should appear in the main business section
        $business_keys = ['order_id', 'status', 'total', 'currency', 'amount', 'customer', 
                         'payment_method', 'gateway', 'payment_status'];
        
        // First pass: collect simple scalar data
        foreach ($data as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $formattedKey = ucfirst(str_replace('_', ' ', $key));
                
                // Determine if this is business or technical data
                if (in_array($key, $business_keys)) {
                    // Business data goes in main section
                    $business_data[$formattedKey] = (string)$value;
                } else {
                    // Technical data goes in debug section
                    $technical_data[$key] = $value;
                }
            }
        }
        
        // Special handling for Order ID
        if (isset($data['order_id'])) {
            $business_data['Order ID'] = '#' . $data['order_id'];
        }
        
        $content = '';
        if (!empty($business_data)) {
            $content .= $toolkit->render_key_value_list($business_data, 'Order Details');
        }
        
        // Add complex data to technical details
        foreach ($data as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $technical_data[$key] = $value;
            }
        }
        
        // Add all technical data to the debug section
        if (!empty($technical_data)) {
            $data['technical_details'] = $technical_data;
        }
        
        // If we have no business content and this is truly an unknown event, show full JSON as fallback
        if (empty($content) || (empty($business_data) && empty($data['components']))) {
            if (empty($content)) {
                $content = $toolkit->render_key_value_list(['Event Type' => 'Unknown Event'], 'Unrecognized Event');
            }
            
            // Add full event data as JSON for developer debugging
            $full_json = json_encode($data, JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($full_json, 'json');
            $content .= $toolkit->render_expandable_section('Full Event Data (Debug)', $code_block);
        }
        
        return $content;
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
     * Render Order Created
     *
     * Renders order creation details.
     *
     * @param array                    $data    The order creation data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderOrderCreated(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Extract business-relevant information
        $order_data = [
            'Order ID' => isset($data['primary_object_id']) ? '#' . $data['primary_object_id'] : 
                         (isset($data['order_id']) ? '#' . $data['order_id'] : ''),
            'Amount' => isset($data['amount'], $data['currency']) 
                       ? $this->formatCurrency($data['amount'], $data['currency']) 
                       : '',
            'Payment Gateway' => ucfirst($data['source_gateway'] ?? ''),
            'Customer ID' => $data['customer_id'] ?? '',
        ];

        $content = $toolkit->render_key_value_list($order_data, 'Order Created');

        // Move all technical details to debug section
        $data['technical_details'] = [
            'event_type' => $data['event_type'] ?? '',
            'channel' => $data['channel'] ?? '',
            'primary_object_type' => $data['primary_object_type'] ?? '',
            'idempotency_key' => $data['idempotency_key'] ?? '',
            'processing_result' => $data['processing_result'] ?? '',
            'execution_time_ms' => $data['execution_time_ms'] ?? '',
            'has_order' => $data['has_order'] ?? '',
            'has_subscription' => $data['has_subscription'] ?? '',
        ];

        return $content;
    }
    
    /**
     * Render Order Check Scheduled
     *
     * Renders order check scheduled details with developer-relevant context.
     * This event indicates that the Order Daemon has scheduled an asynchronous 
     * rule evaluation check for this order.
     *
     * @param array                    $data    The order check data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderOrderCheckScheduled(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Primary information about the scheduled check
        $check_data = [
            'Order ID' => isset($data['primary_object_id']) ? '#' . $data['primary_object_id'] : 
                         (isset($data['order_id']) ? '#' . $data['order_id'] : ''),
            'Purpose' => 'Automated Rule Evaluation',
            'Trigger' => $this->getScheduleTriggerDescription($data),
            'Source' => ucfirst($data['source_gateway'] ?? 'System'),
        ];

        if (isset($data['amount'], $data['currency'])) {
            $check_data['Order Value'] = $this->formatCurrency($data['amount'], $data['currency']);
        }

        $content = $toolkit->render_key_value_list($check_data, 'Rule Evaluation Check');

        // Add purpose explanation
        $purpose_info = [
            'What This Means' => 'Order Daemon will asynchronously evaluate completion rules against this order',
            'When It Runs' => 'Scheduled for background processing via Action Scheduler',
            'Expected Outcome' => 'Rules may trigger order status changes or other automated actions',
        ];
        $content .= $toolkit->render_key_value_list($purpose_info, 'Developer Context');

        // Technical execution details
        $execution_data = [
            'Processing Time' => isset($data['execution_time_ms']) ? $data['execution_time_ms'] . 'ms' : 'N/A',
            'Channel' => ucfirst($data['channel'] ?? 'unknown'),
            'Object Type' => $data['primary_object_type'] ?? 'order',
            'Processing Result' => ucfirst($data['processing_result'] ?? 'pending'),
        ];

        if (!empty($data['idempotency_key'])) {
            $execution_data['Idempotency Key'] = substr($data['idempotency_key'], 0, 12) . '...';
        }

        $content .= $toolkit->render_key_value_list($execution_data, 'Execution Details');

        // Comprehensive technical data in expandable section
        $technical_details = [
            'event_type' => $data['event_type'] ?? '',
            'channel' => $data['channel'] ?? '',
            'primary_object_type' => $data['primary_object_type'] ?? '',
            'primary_object_id' => $data['primary_object_id'] ?? '',
            'source_gateway' => $data['source_gateway'] ?? '',
            'idempotency_key' => $data['idempotency_key'] ?? '',
            'processing_result' => $data['processing_result'] ?? '',
            'execution_time_ms' => $data['execution_time_ms'] ?? '',
            'has_order' => $data['has_order'] ?? '',
            'has_subscription' => $data['has_subscription'] ?? '',
            'customer_id' => $data['customer_id'] ?? '',
            'amount' => $data['amount'] ?? '',
            'currency' => $data['currency'] ?? '',
        ];

        // Remove empty values for cleaner display
        $technical_details = array_filter($technical_details, function($value) {
            return $value !== '' && $value !== null;
        });

        if (!empty($technical_details)) {
            $technical_json = json_encode($technical_details, JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($technical_json, 'json');
            $content .= $toolkit->render_expandable_section('Technical Details', $code_block);
        }

        // Add full event data if there are additional fields
        $full_data = array_diff_key($data, $technical_details);
        if (!empty($full_data)) {
            $full_json = json_encode($full_data, JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($full_json, 'json');
            $content .= $toolkit->render_expandable_section('Additional Event Data', $code_block);
        }

        return $content;
    }

    /**
     * Get description of what triggered the order check scheduling
     *
     * @param array $data The event data
     * @return string Description of the trigger
     */
    private function getScheduleTriggerDescription(array $data): string
    {
        // Determine trigger based on available data
        if (!empty($data['source_gateway'])) {
            return 'Payment Gateway Event (' . ucfirst($data['source_gateway']) . ')';
        }

        if (!empty($data['channel'])) {
            $channel = ucfirst($data['channel']);
            if ($channel === 'Webhook') {
                return 'External Webhook';
            }
            return $channel . ' Event';
        }

        return 'System Event';
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
