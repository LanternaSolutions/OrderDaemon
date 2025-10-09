<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * WooCommerce Renderer
 *
 * Renders WooCommerce-specific data including order information, product details,
 * customer data, and other WooCommerce-related information.
 *
 * This renderer focuses purely on content rendering while the base class
 * handles all structural concerns (headers, icons, component wrapper).
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

class WooCommerceRenderer extends PayloadComponentRenderer
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
        return 'woocommerce_data';
    }

    /**
     * Render embedded content for WooCommerce context.
     *
     * Shows compact attribution or order summary inline. Falls back to
     * parent default when data doesn't match expected structures.
     *
     * @param array $data
     * @return string
     */
    public function renderEmbeddedContent(array $data): string
    {
        if (isset($data['attribution']) && is_array($data['attribution'])) {
            return $this->renderEmbeddedAttribution($data['attribution']);
        }

        if (isset($data['order_id']) || isset($data['order']) || isset($data['order_data']) || isset($data['wc_order']) || isset($data['order_total'])) {
            return $this->renderEmbeddedOrderContext($data);
        }

        return parent::renderEmbeddedContent($data);
    }

    /**
     * Render compact attribution string for WooCommerce events.
     *
     * @param array $attribution
     * @return string
     */
    private function renderEmbeddedAttribution(array $attribution): string
    {
        $parts = [];

        // Determine source
        $source = '';
        if (!empty($attribution['source'])) {
            $source = sanitize_key((string)$attribution['source']);
        } elseif (!empty($attribution['request_type'])) {
            $rt = sanitize_key((string)$attribution['request_type']);
            if ($rt === 'rest') { $source = 'api'; }
            elseif ($rt === 'webhook') { $source = 'webhook'; }
            elseif (in_array($rt, ['admin','ajax'], true)) { $source = 'manual'; }
            elseif (in_array($rt, ['action_scheduler','cron','cli','wp_cli'], true)) { $source = 'scheduled'; }
            else { $source = 'system'; }
        }

        if ($source !== '') {
            $label_map = [
                'manual'    => __('Manual', 'order-daemon'),
                'webhook'   => __('Webhook', 'order-daemon'),
                'api'       => __('API', 'order-daemon'),
                'scheduled' => __('Scheduled', 'order-daemon'),
                'system'    => __('System', 'order-daemon'),
            ];
            $parts[] = $label_map[$source] ?? ucfirst($source);
        }

        // Enrich for manual with user context
        $user_logged = false;
        if (isset($attribution['user_logged_in'])) {
            $user_logged = (bool)$attribution['user_logged_in'];
        } elseif (!empty($attribution['user_context']['is_logged_in'])) {
            $user_logged = (bool)$attribution['user_context']['is_logged_in'];
        }
        $request_type = isset($attribution['request_type']) ? sanitize_key((string)$attribution['request_type']) : '';
        if ($source === 'manual' && $user_logged) {
            $parts[] = __('by', 'order-daemon');
            $parts[] = $request_type === 'admin' ? __('Admin', 'order-daemon') : __('User', 'order-daemon');
        }

        // For webhook/API, add service name when present
        $service = '';
        if (!empty($attribution['external_service']['name'])) {
            $service = (string)$attribution['external_service']['name'];
        } elseif (!empty($attribution['service']) && is_array($attribution['service']) && !empty($attribution['service']['name'])) {
            $service = (string)$attribution['service']['name'];
        }
        if ($service !== '') {
            $parts[] = esc_html($service);
        }

        $text = trim(implode(' ', array_map('sanitize_text_field', $parts)));
        if ($text === '') {
            return parent::renderEmbeddedContent($attribution);
        }
        return '<span class="odcm-wc-attribution">' . esc_html($text) . '</span>';
    }

    /**
     * Render compact order context (Order #id and total when available).
     *
     * @param array $data
     * @return string
     */
    private function renderEmbeddedOrderContext(array $data): string
    {
        $orderId = null;
        $total = null;
        $currency = 'USD';

        if (isset($data['order']) && is_array($data['order'])) {
            $orderId = isset($data['order']['id']) ? (int)$data['order']['id'] : $orderId;
            if (isset($data['order']['total'])) { $total = (float)$data['order']['total']; }
            if (!empty($data['order']['currency'])) { $currency = (string)$data['order']['currency']; }
        }
        if ($orderId === null && isset($data['order_id'])) { $orderId = (int)$data['order_id']; }
        if ($total === null && isset($data['order_total'])) { $total = (float)$data['order_total']; }
        if (isset($data['order_data']) && is_array($data['order_data'])) {
            if ($orderId === null && isset($data['order_data']['id'])) { $orderId = (int)$data['order_data']['id']; }
            if ($total === null && isset($data['order_data']['total'])) { $total = (float)$data['order_data']['total']; }
            if (!empty($data['order_data']['currency'])) { $currency = (string)$data['order_data']['currency']; }
        }
        if (isset($data['wc_order']) && is_array($data['wc_order'])) {
            if ($orderId === null && isset($data['wc_order']['id'])) { $orderId = (int)$data['wc_order']['id']; }
            if ($total === null && isset($data['wc_order']['total'])) { $total = (float)$data['wc_order']['total']; }
            if (!empty($data['wc_order']['currency'])) { $currency = (string)$data['wc_order']['currency']; }
        }

        $parts = [];
        if ($orderId !== null && $orderId > 0) {
            $parts[] = sprintf(__('Order #%d', 'order-daemon'), $orderId);
        }
        if ($total !== null) {
            $parts[] = '(' . esc_html($this->formatCurrency((float)$total, $currency)) . ')';
        }

        $text = trim(implode(' ', $parts));
        if ($text === '') {
            return parent::renderEmbeddedContent($data);
        }
        return '<span class="odcm-wc-context">' . esc_html($text) . '</span>';
    }

    /**
     * Render WooCommerce Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform complex WooCommerce data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing Alpine.js interactive features for WooCommerce data management
     * 5. Handling embedded context content from the timeline consolidation system
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data WooCommerce data.
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

        // Narrative-first: branch for specific WooCommerce kinds when present
        $kind = $this->getCurrentComponentId();
        if ($kind === 'order_loaded') {
            $order_id = isset($data['id']) ? absint($data['id']) : null;
            $status   = isset($data['status']) ? sanitize_text_field((string)$data['status']) : '';
            $kv = [];
            if ($order_id) { $kv['Order ID'] = '#' . $order_id; }
            if ($status !== '') { $kv['Status'] = $status; }
            if (!empty($kv)) {
                $content = $toolkit->render_key_value_list($kv, __('Order Context', 'order-daemon'));
                return $content . $embedded_context;
            }
            // fall through to generic adapters if minimal fields absent
        } elseif ($kind === 'status_changed') {
            $from = isset($data['from']) ? sanitize_text_field((string)$data['from']) : '';
            $to   = isset($data['to']) ? sanitize_text_field((string)$data['to']) : '';
            $kv = [];
            if ($from !== '') { $kv['From'] = $from; }
            if ($to !== '')   { $kv['To']   = $to; }
            if (!empty($kv)) {
                $content = $toolkit->render_key_value_list($kv, __('Status Change', 'order-daemon'));
                return $content . $embedded_context;
            }
            // fall through
        } elseif ($kind === 'stock_adjusted') {
            $product_id = isset($data['product_id']) ? absint($data['product_id']) : null;
            $delta      = isset($data['delta']) ? (int)$data['delta'] : null;
            $fromQty    = isset($data['from']) ? (int)$data['from'] : null;
            $toQty      = isset($data['to']) ? (int)$data['to'] : null;
            $kv = [];
            if ($product_id) { $kv['Product ID'] = '#' . $product_id; }
            if ($delta !== null) { $kv['Delta'] = (string)$delta; }
            if ($fromQty !== null) { $kv['From Qty'] = (string)$fromQty; }
            if ($toQty !== null) { $kv['To Qty'] = (string)$toQty; }
            if (!empty($kv)) {
                $content = $toolkit->render_key_value_list($kv, __('Stock Adjustment', 'order-daemon'));
                return $content . $embedded_context;
            }
        } elseif ($kind === 'meta_updated') {
            $key  = isset($data['key']) ? sanitize_text_field((string)$data['key']) : '';
            $from = array_key_exists('from', $data) ? $data['from'] : null;
            $to   = array_key_exists('to', $data) ? $data['to'] : null;
            $kv = [];
            if ($key !== '') { $kv['Meta Key'] = $key; }
            // Pretty JSON for values
            $fromJson = $from !== null ? (string) wp_json_encode($from, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : null;
            $toJson   = $to !== null ? (string) wp_json_encode($to, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : null;
            if (!empty($kv)) {
                $content = $toolkit->render_key_value_list($kv, __('Meta Updated', 'order-daemon'));
                if ($fromJson !== null) {
                    $content .= $toolkit->render_expandable_section(__('From', 'order-daemon'), $toolkit->render_code_block($fromJson, 'json'));
                }
                if ($toJson !== null) {
                    $content .= $toolkit->render_expandable_section(__('To', 'order-daemon'), $toolkit->render_code_block($toJson, 'json'));
                }
                return $content . $embedded_context;
            }
        }
        
        // === DATA ADAPTATION PHASE ===
        // Transform complex WooCommerce data into simple, clean formats using private adapters
        
        // Adapt order information
        $order_html = $this->adaptOrderInformation($data, $toolkit);
        if ($order_html !== null) {
            $html_parts[] = $order_html;
        }
        
        // Adapt order status indicator
        $status_html = $this->adaptOrderStatus($data, $toolkit);
        if ($status_html !== null) {
            $html_parts[] = $status_html;
        }
        
        // Adapt product information
        $product_html = $this->adaptProductInformation($data, $toolkit);
        if ($product_html !== null) {
            $html_parts[] = $product_html;
        }
        
        // Adapt customer information
        $customer_html = $this->adaptCustomerInformation($data, $toolkit);
        if ($customer_html !== null) {
            $html_parts[] = $customer_html;
        }
        
        // Adapt payment information
        $payment_html = $this->adaptPaymentInformation($data, $toolkit);
        if ($payment_html !== null) {
            $html_parts[] = $payment_html;
        }
        
        // Adapt shipping information
        $shipping_html = $this->adaptShippingInformation($data, $toolkit);
        if ($shipping_html !== null) {
            $html_parts[] = $shipping_html;
        }
        
        // Adapt order items/line items
        $items_html = $this->adaptOrderItems($data, $toolkit);
        if ($items_html !== null) {
            $html_parts[] = $items_html;
        }
        
        // Adapt order meta/custom fields
        $meta_html = $this->adaptOrderMeta($data, $toolkit);
        if ($meta_html !== null) {
            $html_parts[] = $meta_html;
        }
        
        // Adapt WooCommerce hooks/actions data
        $hooks_html = $this->adaptWooCommerceHooks($data, $toolkit);
        if ($hooks_html !== null) {
            $html_parts[] = $hooks_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific WooCommerce components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        // Append embedded context to the final output
        $final_html = implode('', $html_parts);
        return $final_html . $embedded_context;
    }

    /**
     * Adapt Order Information
     *
     * Transforms order data into clean key-value pairs for display.
     * Handles various order data structures and formats.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for order information or null if no order data found.
     */
    private function adaptOrderInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $order_data = [];
        
        // Defensive programming: Extract order from various possible keys
        $order = $data['order'] ?? $data['order_data'] ?? $data['wc_order'] ?? null;
        $order_id = $data['order_id'] ?? null;
        
        if (is_array($order)) {
            // Extract order details from order object
            if (isset($order['id']) && is_numeric($order['id'])) {
                $order_data['Order ID'] = '#' . $order['id'];
            }
            
            if (isset($order['number']) && !empty($order['number'])) {
                $order_data['Order Number'] = (string)$order['number'];
            }
            
            if (isset($order['total']) && is_numeric($order['total'])) {
                $order_data['Total'] = $this->formatCurrency((float)$order['total'], $order['currency'] ?? 'USD');
            }
            
            if (isset($order['date_created']) && !empty($order['date_created'])) {
                $order_data['Date Created'] = $this->formatDate($order['date_created']);
            }
            
            if (isset($order['date_modified']) && !empty($order['date_modified'])) {
                $order_data['Date Modified'] = $this->formatDate($order['date_modified']);
            }
            
            if (isset($order['currency']) && !empty($order['currency'])) {
                $order_data['Currency'] = strtoupper((string)$order['currency']);
            }
            
        } elseif ($order_id !== null) {
            $order_data['Order ID'] = '#' . $order_id;
        }
        
        // Extract additional order data from root level
        $order_total = $data['order_total'] ?? null;
        if ($order_total !== null && is_numeric($order_total)) {
            $order_data['Total'] = $this->formatCurrency((float)$order_total, $data['currency'] ?? 'USD');
        }
        
        // Only render if we have meaningful order data
        if (empty($order_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($order_data, 'Order Information');
    }

    /**
     * Adapt Order Status
     *
     * Creates status indicators for WooCommerce order status.
     * Maps order statuses to appropriate visual indicators.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for order status or null if no status found.
     */
    private function adaptOrderStatus(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $status = $data['order_status'] ?? $data['status'] ?? null;
        
        if ($status === null) {
            // Try to extract from nested order data
            $order = $data['order'] ?? $data['order_data'] ?? $data['wc_order'] ?? null;
            if (is_array($order) && isset($order['status'])) {
                $status = $order['status'];
            }
        }
        
        if ($status === null) {
            return null;
        }
        
        $status_string = (string)$status;
        $status_type = $this->mapOrderStatusToType($status_string);
        
        return $toolkit->render_status_pill(strtoupper($status_string), $status_type);
    }

    /**
     * Adapt Product Information
     *
     * Transforms product data into formatted display.
     * Handles product details, variations, and metadata.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for product information or null if no product data found.
     */
    private function adaptProductInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $product_data = [];
        
        // Defensive programming: Extract product from various possible keys
        $product = $data['product'] ?? $data['product_data'] ?? $data['wc_product'] ?? null;
        $product_id = $data['product_id'] ?? null;
        
        if (is_array($product)) {
            // Extract product details
            if (isset($product['id']) && is_numeric($product['id'])) {
                $product_data['Product ID'] = (string)$product['id'];
            }
            
            if (isset($product['name']) && !empty($product['name'])) {
                $product_data['Name'] = (string)$product['name'];
            }
            
            if (isset($product['sku']) && !empty($product['sku'])) {
                $product_data['SKU'] = (string)$product['sku'];
            }
            
            if (isset($product['price']) && is_numeric($product['price'])) {
                $product_data['Price'] = $this->formatCurrency((float)$product['price'], $product['currency'] ?? 'USD');
            }
            
            if (isset($product['type']) && !empty($product['type'])) {
                $product_data['Type'] = ucfirst((string)$product['type']);
            }
            
            if (isset($product['status']) && !empty($product['status'])) {
                $product_data['Status'] = ucfirst((string)$product['status']);
            }
            
        } elseif ($product_id !== null) {
            $product_data['Product ID'] = (string)$product_id;
        }
        
        // Only render if we have meaningful product data
        if (empty($product_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($product_data, 'Product Information');
    }

    /**
     * Adapt Customer Information
     *
     * Transforms customer data into formatted display.
     * Handles customer details, billing, and account information.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for customer information or null if no customer data found.
     */
    private function adaptCustomerInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $customer_data = [];
        
        // Defensive programming: Extract customer from various possible keys
        $customer = $data['customer'] ?? $data['customer_data'] ?? null;
        $customer_id = $data['customer_id'] ?? null;
        
        if (is_array($customer)) {
            // Extract customer details with defensive programming
            if (isset($customer['id']) && is_numeric($customer['id'])) {
                $customer_data['Customer ID'] = (string)$customer['id'];
            }
            
            if (isset($customer['email']) && !empty($customer['email'])) {
                $customer_data['Email'] = (string)$customer['email'];
            }
            
            if (isset($customer['first_name']) && !empty($customer['first_name'])) {
                $customer_data['First Name'] = (string)$customer['first_name'];
            }
            
            if (isset($customer['last_name']) && !empty($customer['last_name'])) {
                $customer_data['Last Name'] = (string)$customer['last_name'];
            }
            
            if (isset($customer['username']) && !empty($customer['username'])) {
                $customer_data['Username'] = (string)$customer['username'];
            }
            
            if (isset($customer['role']) && !empty($customer['role'])) {
                $customer_data['Role'] = ucfirst((string)$customer['role']);
            }
            
        } elseif ($customer_id !== null) {
            $customer_data['Customer ID'] = (string)$customer_id;
        }
        
        // Only render if we have meaningful customer data
        if (empty($customer_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($customer_data, 'Customer Information');
    }

    /**
     * Adapt Payment Information
     *
     * Transforms payment data into formatted display.
     * Handles payment methods, transaction details, and payment status.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for payment information or null if no payment data found.
     */
    private function adaptPaymentInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $payment_data = [];
        
        // Defensive programming: Extract payment from various possible keys
        $payment = $data['payment'] ?? $data['payment_data'] ?? null;
        $payment_method = $data['payment_method'] ?? null;
        
        if (is_array($payment)) {
            // Extract payment details
            foreach ($payment as $key => $value) {
                if (!empty($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $payment_data[$formatted_key] = (string)$value;
                }
            }
        } elseif ($payment_method !== null) {
            $payment_data['Payment Method'] = (string)$payment_method;
        }
        
        // Extract additional payment fields from root level
        $transaction_id = $data['transaction_id'] ?? null;
        if ($transaction_id !== null && !empty($transaction_id)) {
            $payment_data['Transaction ID'] = (string)$transaction_id;
        }
        
        // Only render if we have meaningful payment data
        if (empty($payment_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($payment_data, 'Payment Information');
    }

    /**
     * Adapt Shipping Information
     *
     * Transforms shipping data into formatted display.
     * Handles shipping methods, addresses, and delivery details.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for shipping information or null if no shipping data found.
     */
    private function adaptShippingInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $shipping_data = [];
        
        // Defensive programming: Extract shipping from various possible keys
        $shipping = $data['shipping'] ?? $data['shipping_data'] ?? null;
        $shipping_method = $data['shipping_method'] ?? null;
        
        if (is_array($shipping)) {
            // Extract shipping details
            foreach ($shipping as $key => $value) {
                if (!empty($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $shipping_data[$formatted_key] = (string)$value;
                }
            }
        } elseif ($shipping_method !== null) {
            $shipping_data['Shipping Method'] = (string)$shipping_method;
        }
        
        // Only render if we have meaningful shipping data
        if (empty($shipping_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($shipping_data, 'Shipping Information');
    }

    /**
     * Adapt Order Items
     *
     * Transforms order items/line items into interactive display.
     * Handles product line items, quantities, and pricing details.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for order items or null if no items found.
     */
    private function adaptOrderItems(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $items = $data['order_items'] ?? $data['line_items'] ?? $data['items'] ?? null;
        
        if (!is_array($items) || empty($items)) {
            return null;
        }
        
        $json_content = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Order Items', $code_html, [
            'initially_expanded' => false,
            'theme' => 'woocommerce',
            'action_buttons' => [
                [
                    'label' => 'Copy Items',
                    'action' => 'copyOrderItems',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Export CSV',
                    'action' => 'exportOrderItemsCsv',
                    'icon' => 'dashicons-download'
                ]
            ]
        ]);
    }

    /**
     * Adapt Order Meta
     *
     * Transforms order meta/custom fields into interactive display.
     * Handles custom order fields and metadata.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for order meta or null if no meta found.
     */
    private function adaptOrderMeta(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $meta = $data['order_meta'] ?? $data['meta_data'] ?? $data['custom_fields'] ?? null;
        
        if (!is_array($meta) || empty($meta)) {
            return null;
        }
        
        // Handle simple key-value meta
        if ($this->isSimpleKeyValueArray($meta)) {
            return $toolkit->render_key_value_list($meta, 'Order Meta');
        }
        
        // Handle complex meta as JSON
        $json_content = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Order Meta', $code_html, [
            'initially_expanded' => false,
            'theme' => 'woocommerce',
            'action_buttons' => [
                [
                    'label' => 'Copy Meta',
                    'action' => 'copyOrderMeta',
                    'icon' => 'dashicons-clipboard'
                ]
            ]
        ]);
    }

    /**
     * Adapt WooCommerce Hooks
     *
     * Transforms WooCommerce hooks/actions data into display format.
     * Handles hook execution data and action/filter information.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for hooks data or null if no hooks found.
     */
    private function adaptWooCommerceHooks(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $hooks = $data['hooks'] ?? $data['actions'] ?? $data['filters'] ?? null;
        
        if (!is_array($hooks) || empty($hooks)) {
            return null;
        }
        
        $json_content = json_encode($hooks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('WooCommerce Hooks', $code_html, [
            'initially_expanded' => false,
            'theme' => 'woocommerce',
            'action_buttons' => [
                [
                    'label' => 'Copy Hooks',
                    'action' => 'copyWooCommerceHooks',
                    'icon' => 'dashicons-clipboard'
                ]
            ]
        ]);
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized WooCommerce data into JSON format as a fallback.
     * Ensures that all WooCommerce data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw WooCommerce data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for fallback data display.
     */
    private function adaptFallbackData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        return $toolkit->render_code_block($json_content, 'json');
    }

    /**
     * Check if Array is Simple Key-Value
     *
     * Determines if an array contains only simple string/numeric values
     * suitable for key-value list display.
     *
     * @since 1.0.0
     *
     * @param array $array Array to check.
     * @return bool True if array is simple key-value.
     */
    private function isSimpleKeyValueArray(array $array): bool
    {
        foreach ($array as $value) {
            if (!is_string($value) && !is_numeric($value) && !is_bool($value) && $value !== null) {
                return false;
            }
        }
        return true;
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
     * Format Date
     *
     * Formats date strings according to WordPress site settings.
     * Uses the centralized formatting utility from PayloadComponentUIToolkit.
     *
     * @since 1.0.0
     *
     * @param string $date Date string.
     * @return string Formatted date string.
     */
    private function formatDate(string $date): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        return $toolkit->format_timestamp($date);
    }

    /**
     * Check if this renderer can handle the provided data
     *
     * @since 1.0.0
     *
     * @param array $data Data to check.
     * @return bool True if this renderer can handle the data.
     */
    public function canHandle(array $data): bool
    {
        // Check for WooCommerce-related keys
        $woo_keys = [
            'order', 'order_id', 'product', 'product_id', 'customer', 'customer_id',
            'order_status', 'payment_method', 'shipping_method', 'woocommerce',
            'wc_order', 'wc_product', 'order_total', 'order_items'
        ];
        
        foreach ($woo_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract Order Data
     *
     * Extracts and formats order information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted order data.
     */
    private function extractOrderData(array $data): array
    {
        $order_data = [];
        
        // Extract order from various possible keys
        $order = null;
        if (isset($data['order'])) {
            $order = $data['order'];
        } elseif (isset($data['order_id'])) {
            $order = $data['order_id'];
        } elseif (isset($data['order_data'])) {
            $order = $data['order_data'];
        } elseif (isset($data['wc_order'])) {
            $order = $data['wc_order'];
        }
        
        if (is_array($order)) {
            if (isset($order['id'])) {
                $order_data['Order ID'] = '#' . $order['id'];
            }
            if (isset($order['status'])) {
                $order_data['Status'] = $order['status'];
            }
            if (isset($order['total'])) {
                $order_data['Total'] = $order['total'];
            }
            if (isset($order['date_created'])) {
                $order_data['Date Created'] = $order['date_created'];
            }
        } elseif (!is_null($order)) {
            $order_data['Order ID'] = '#' . $order;
        }
        
        // Extract additional order data from root level
        if (isset($data['order_total'])) {
            $order_data['Total'] = $data['order_total'];
        }
        
        return $order_data;
    }

    /**
     * Extract Product Data
     *
     * Extracts and formats product information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted product data.
     */
    private function extractProductData(array $data): array
    {
        $product_data = [];
        
        // Extract product from various possible keys
        $product = null;
        if (isset($data['product'])) {
            $product = $data['product'];
        } elseif (isset($data['product_id'])) {
            $product = $data['product_id'];
        } elseif (isset($data['product_data'])) {
            $product = $data['product_data'];
        } elseif (isset($data['wc_product'])) {
            $product = $data['wc_product'];
        }
        
        if (is_array($product)) {
            foreach ($product as $key => $value) {
                $formatted_key = ucwords(str_replace('_', ' ', $key));
                $product_data[$formatted_key] = $value;
            }
        } elseif (!is_null($product)) {
            $product_data['Product ID'] = $product;
        }
        
        return $product_data;
    }

    /**
     * Extract Customer Data
     *
     * Extracts and formats customer information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted customer data.
     */
    private function extractCustomerData(array $data): array
    {
        $customer_data = [];
        
        // Extract customer from various possible keys
        $customer = null;
        if (isset($data['customer'])) {
            $customer = $data['customer'];
        } elseif (isset($data['customer_id'])) {
            $customer = $data['customer_id'];
        } elseif (isset($data['customer_data'])) {
            $customer = $data['customer_data'];
        }
        
        if (is_array($customer)) {
            foreach ($customer as $key => $value) {
                $formatted_key = ucwords(str_replace('_', ' ', $key));
                $customer_data[$formatted_key] = $value;
            }
        } elseif (!is_null($customer)) {
            $customer_data['Customer ID'] = $customer;
        }
        
        return $customer_data;
    }

    /**
     * Extract Payment Data
     *
     * Extracts and formats payment information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted payment data.
     */
    private function extractPaymentData(array $data): array
    {
        $payment_data = [];
        
        // Extract payment from various possible keys
        $payment = null;
        if (isset($data['payment'])) {
            $payment = $data['payment'];
        } elseif (isset($data['payment_method'])) {
            $payment = $data['payment_method'];
        }
        
        if (is_array($payment)) {
            foreach ($payment as $key => $value) {
                $formatted_key = ucwords(str_replace('_', ' ', $key));
                $payment_data[$formatted_key] = $value;
            }
        } elseif (!is_null($payment)) {
            $payment_data['Payment Method'] = $payment;
        }
        
        return $payment_data;
    }

    /**
     * Extract Shipping Data
     *
     * Extracts and formats shipping information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted shipping data.
     */
    private function extractShippingData(array $data): array
    {
        $shipping_data = [];
        
        // Extract shipping from various possible keys
        $shipping = null;
        if (isset($data['shipping'])) {
            $shipping = $data['shipping'];
        } elseif (isset($data['shipping_method'])) {
            $shipping = $data['shipping_method'];
        }
        
        if (is_array($shipping)) {
            foreach ($shipping as $key => $value) {
                $formatted_key = ucwords(str_replace('_', ' ', $key));
                $shipping_data[$formatted_key] = $value;
            }
        } elseif (!is_null($shipping)) {
            $shipping_data['Shipping Method'] = $shipping;
        }
        
        return $shipping_data;
    }

    /**
     * Map Order Status to Type
     *
     * Maps WooCommerce order status to appropriate status pill types.
     *
     * @since 1.0.0
     *
     * @param mixed $status Order status.
     * @return string Status type for UI toolkit.
     */
    private function mapOrderStatusToType($status): string
    {
        $status_lower = strtolower((string)$status);
        
        switch ($status_lower) {
            case 'completed':
            case 'processing':
                return 'success';
            case 'pending':
            case 'on-hold':
                return 'warning';
            case 'cancelled':
            case 'refunded':
            case 'failed':
                return 'error';
            default:
                return 'info';
        }
    }

}
