<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Order Deletion Renderer
 *
 * Renders deletion and restoration notices for orders in the narrative timeline.
 * Displays order identifiers, status, phase, and any available actor information.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 */
final class OrderDeletionRenderer extends PayloadComponentRenderer
{
    /**
     * Registry component ID.
     *
     * @return string
     */
    protected function getComponentId(): string
    {
        return 'order_deletion';
    }

    /**
     * Basic compatibility detector.
     *
     * @param array $data
     * @return bool
     */
    public function canHandle(array $data): bool
    {
        return isset($data['event_type']) && (isset($data['order_id']) || isset($data['order_status']));
    }

    /**
     * Render inner content for deletion/restoration items.
     *
     * Expected data:
     * - event_type: order_deleted|order_trashed|order_restored
     * - order_id: int
     * - order_status: string
     * - phase: string|null
     * - Optional actor fields if provided by the producer (who, ip, ua, referer)
     *
     * @param array $data
     * @return string
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $parts = [];

        $event  = isset($data['event_type']) ? sanitize_text_field((string)$data['event_type']) : '';
        $orderId= isset($data['order_id']) ? absint((int)$data['order_id']) : null;
        $status = isset($data['order_status']) ? sanitize_text_field((string)$data['order_status']) : '';
        $phase  = isset($data['phase']) ? sanitize_text_field((string)$data['phase']) : '';

        // Header-like key-value section
        $kv = [];
        if ($orderId) { $kv[__('Order ID', 'order-daemon')] = '#' . $orderId; }
        if ($status !== '') { $kv[__('Order Status', 'order-daemon')] = $status; }
        if ($phase !== '')  { $kv[__('Phase', 'order-daemon')] = $phase; }
        if (!empty($kv)) {
            // Add a high-level status pill for visual emphasis
            $badge = '';
            if ($event === 'order_restored') {
                $badge = $toolkit->render_status_pill(__('Restored', 'order-daemon'), 'success');
            } elseif ($event === 'order_trashed') {
                $badge = $toolkit->render_status_pill(__('Trashed', 'order-daemon'), 'warning');
            } else {
                $badge = $toolkit->render_status_pill(__('Deleted', 'order-daemon'), 'error');
            }
            $parts[] = $badge . $toolkit->render_key_value_list($kv, __('Deletion Event', 'order-daemon'));
        }

        // Actor information if provided by the producer
        // Some producers may include 'actor' or 'who' directly on the data
        $actorKVs = [];
        $who = isset($data['who']) ? absint((int)$data['who']) : null;
        if ($who) {
            $label = '#' . $who;
            if (function_exists('get_userdata')) {
                $u = get_userdata($who);
                if ($u && isset($u->display_name)) {
                    $label .= ' — ' . sanitize_text_field((string)$u->display_name);
                }
            }
            $actorKVs[__('Actor User', 'order-daemon')] = $label;
        }
        if (!empty($data['ip'])) { $actorKVs[__('IP', 'order-daemon')] = sanitize_text_field((string)$data['ip']); }
        if (!empty($data['ua'])) { $actorKVs[__('User Agent', 'order-daemon')] = sanitize_text_field((string)$data['ua']); }
        if (!empty($data['referer'])) { $actorKVs[__('Referer', 'order-daemon')] = esc_url_raw((string)$data['referer']); }
        if (!empty($actorKVs)) {
            $parts[] = $toolkit->render_key_value_list($actorKVs, __('Actor', 'order-daemon'));
        }

        if (empty($parts)) {
            // Fallback to raw data for visibility
            $json = (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return $toolkit->render_expandable_section(__('Deletion Details', 'order-daemon'), $toolkit->render_code_block($json, 'json'));
        }

        return implode('', $parts);
    }
}
