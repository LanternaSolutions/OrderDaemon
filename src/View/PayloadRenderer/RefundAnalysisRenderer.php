<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

use WC_Order;

/**
 * Refund Analysis Renderer
 *
 * Renders refund details and contextual analytics in the Insight Dashboard timeline.
 * Focuses on rich, readable presentation: amounts, percentages, item summary, and actor info.
 *
 * Security: All output is escaped via UIToolkit helpers and WordPress functions.
 * Performance: Lightweight lookups only (optional wc_get_order by ID for currency/total).
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 */
final class RefundAnalysisRenderer extends PayloadComponentRenderer
{
    /**
     * Get the component ID matching the registry key.
     *
     * @return string
     */
    protected function getComponentId(): string
    {
        return 'refund_analysis';
    }

    /**
     * Determine if this renderer can handle provided data.
     *
     * @param array $data Payload component data.
     * @return bool True if refund context appears present.
     */
    public function canHandle(array $data): bool
    {
        return isset($data['refund']) || isset($data['items']);
    }

    /**
     * Render the inner content for a refund analysis component.
     *
     * Expected data shape from diagnostics:
     * - refund: { id, order_id, amount, reason, refunded_by, date }
     * - items: array[] of compact item breakdown
     * - actor: { user_id, user_roles[], ip, user_agent, referer }
     * - technical: array
     *
     * Enhancements:
     * - Attempts to look up order to format currency and compute % of total.
     *
     * @param array $data
     * @return string HTML content
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $parts = [];

        // Extract refund basics
        $refund = is_array($data['refund'] ?? null) ? $data['refund'] : [];
        $refund_id = isset($refund['id']) ? absint((int)$refund['id']) : null;
        $order_id  = isset($refund['order_id']) ? absint((int)$refund['order_id']) : null;
        $amount    = isset($refund['amount']) ? (float)$refund['amount'] : null;
        $reason    = isset($refund['reason']) ? sanitize_text_field((string)$refund['reason']) : '';
        $refunded_by = isset($refund['refunded_by']) ? absint((int)$refund['refunded_by']) : null;
        $date_iso  = isset($refund['date']) ? (string)$refund['date'] : '';

        // Resolve order for currency + total if available
        $currency = null;
        $order_total = null;
        $total_refunded = null;
        $this_refund_pct = null;
        $overall_refunded_pct = null;
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order instanceof WC_Order) {
                $currency = sanitize_text_field((string)$order->get_currency());
                $order_total = (float)$order->get_total();
                if (method_exists($order, 'get_total_refunded')) {
                    $total_refunded = (float)$order->get_total_refunded();
                }
            }
        }

        // Format amounts with WooCommerce if possible
        $format_money = static function (?float $val, ?string $currency_code) {
            if ($val === null) {
                return null;
            }
            if (function_exists('wc_price')) {
                $args = [];
                if (is_string($currency_code) && $currency_code !== '') {
                    $args['currency'] = $currency_code;
                }
                /** @var string $formatted */
                $formatted = wc_price($val, $args);
                return $formatted;
            }
            return number_format_i18n($val, 2);
        };

        $formatted_amount         = $format_money($amount, $currency);
        $formatted_order_total    = $format_money($order_total, $currency);
        $formatted_total_refunded = $format_money($total_refunded, $currency);

        if ($order_total && $order_total > 0 && $amount !== null) {
            $this_refund_pct = round(($amount / $order_total) * 100, 2);
        }
        if ($order_total && $order_total > 0 && $total_refunded !== null) {
            $overall_refunded_pct = round(($total_refunded / $order_total) * 100, 2);
        }

        // Refund details section
        $kv = [];
        if ($refund_id) { $kv[__('Refund ID', 'order-daemon')] = '#' . $refund_id; }
        if ($order_id)  { $kv[__('Order ID', 'order-daemon')]  = '#' . $order_id; }
        if ($formatted_amount !== null) { $kv[__('Amount', 'order-daemon')] = wp_kses_post($formatted_amount); }
        if ($reason !== '') { $kv[__('Reason', 'order-daemon')] = $reason; }
        if ($refunded_by) {
            $display = '#' . $refunded_by;
            if (function_exists('get_userdata')) {
                $u = get_userdata($refunded_by);
                if ($u && isset($u->display_name)) {
                    $display .= ' — ' . sanitize_text_field((string)$u->display_name);
                }
            }
            $kv[__('Refunded By', 'order-daemon')] = $display;
        }
        if ($date_iso !== '') {
            $ts = strtotime($date_iso);
            if ($ts) {
                $kv[__('Refunded At', 'order-daemon')] = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts);
            }
        }
        if (!empty($kv)) {
            $parts[] = $toolkit->render_key_value_list($kv, __('Refund Details', 'order-daemon'));
        }

        // Percentages / impact snapshot
        $impact = [];
        if ($formatted_order_total !== null) { $impact[__('Order Total', 'order-daemon')] = wp_kses_post($formatted_order_total); }
        if ($formatted_total_refunded !== null) { $impact[__('Total Refunded', 'order-daemon')] = wp_kses_post($formatted_total_refunded); }
        if ($this_refund_pct !== null) { $impact[__('This Refund', 'order-daemon')] = sprintf('%.2f%%', $this_refund_pct); }
        if ($overall_refunded_pct !== null) { $impact[__('Refunded Overall', 'order-daemon')] = sprintf('%.2f%%', $overall_refunded_pct); }
        if (!empty($impact)) {
            $parts[] = $toolkit->render_key_value_list($impact, __('Impact Analysis', 'order-daemon'));
        }

        // Items summary (compact)
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!empty($items)) {
            // Render as expandable JSON to keep UI compact
            $json = (string) wp_json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = $toolkit->render_expandable_section(
                __('Refunded Items', 'order-daemon'),
                $toolkit->render_code_block($json, 'json')
            );
        }

        // Actor info with role badges
        $actor = is_array($data['actor'] ?? null) ? $data['actor'] : [];
        if (!empty($actor)) {
            $akv = [];
            $user_id = isset($actor['user_id']) ? absint((int)$actor['user_id']) : null;
            if ($user_id) { $akv[__('Actor User ID', 'order-daemon')] = '#' . $user_id; }
            if (!empty($actor['ip'])) { $akv[__('IP', 'order-daemon')] = sanitize_text_field((string)$actor['ip']); }
            if (!empty($actor['user_agent'])) { $akv[__('User Agent', 'order-daemon')] = sanitize_text_field((string)$actor['user_agent']); }
            if (!empty($actor['referer'])) { $akv[__('Referer', 'order-daemon')] = esc_url_raw((string)$actor['referer']); }
            if (!empty($akv)) {
                $parts[] = $toolkit->render_key_value_list($akv, __('Actor', 'order-daemon'));
            }
            $roles = is_array($actor['user_roles'] ?? null) ? $actor['user_roles'] : [];
            if (!empty($roles)) {
                $badges = '';
                foreach ($roles as $role) {
                    $roleLabel = ucfirst(sanitize_text_field((string)$role));
                    $badges .= $toolkit->render_status_pill($roleLabel, 'info');
                }
                $parts[] = $toolkit->render_interactive_section(__('Roles', 'order-daemon'), $badges);
            }
        }

        // Technical diagnostic (collapsed)
        $technical = is_array($data['technical'] ?? null) ? $data['technical'] : [];
        if (!empty($technical)) {
            $json = (string) wp_json_encode($technical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = $toolkit->render_expandable_section(
                __('Technical Context', 'order-daemon'),
                $toolkit->render_code_block($json, 'json')
            );
        }

        if (empty($parts)) {
            // Fallback: show the raw payload for troubleshooting
            $json = (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return $toolkit->render_expandable_section(__('Refund Data', 'order-daemon'), $toolkit->render_code_block($json, 'json'));
        }

        return implode('', $parts);
    }
}
