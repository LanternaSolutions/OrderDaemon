<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * HTTP Webhook Renderer
 *
 * Renders details for outbound HTTP webhook calls: method, URL, request,
 * response, status code, and timing. Uses PayloadComponentUIToolkit for
 * consistent markup and escaping.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 */
final class HttpWebhookRenderer extends PayloadComponentRenderer
{
    /**
     * Default component ID (narrative will override with renderWithComponentId()).
     *
     * @return string
     */
    protected function getComponentId(): string
    {
        return 'http_webhook';
    }

    /**
     * Render inner content for a webhook call.
     *
     * Expected keys:
     * - method: string
     * - url: string
     * - request: array|string|null
     * - response: array|string|null
     * - status_code: int|null
     * - duration_ms: float|int|null
     * - headers: array|null
     *
     * @param array $data
     * @return string
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $parts = [];

        $method = isset($data['method']) ? strtoupper(sanitize_text_field((string)$data['method'])) : '';
        $url    = isset($data['url']) ? esc_url_raw((string)$data['url']) : '';
        $code   = isset($data['status_code']) ? (int)$data['status_code'] : null;
        $dur    = isset($data['duration_ms']) ? (float)$data['duration_ms'] : null;

        $kv = [];
        if ($method !== '') { $kv['Method'] = $method; }
        if ($url !== '')    { $kv['URL']    = $url; }
        if ($code !== null) { $kv['Status'] = (string)$code; }
        if ($dur !== null)  { $kv['Duration'] = sprintf('%.2f ms', $dur); }
        if (!empty($kv)) {
            $parts[] = $toolkit->render_key_value_list($kv, __('Webhook Summary', 'order-daemon'));
        }

        // Headers (if array)
        if (!empty($data['headers']) && is_array($data['headers'])) {
            $headersJson = (string) wp_json_encode($data['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = $toolkit->render_expandable_section(
                __('Request Headers', 'order-daemon'),
                $toolkit->render_code_block($headersJson, 'json')
            );
        }

        // Request body preview
        if (array_key_exists('request', $data)) {
            $reqJson = $this->toPrettyJson($data['request']);
            $parts[] = $toolkit->render_expandable_section(
                __('Request', 'order-daemon'),
                $toolkit->render_code_block($reqJson, 'json')
            );
        }

        // Response preview
        if (array_key_exists('response', $data)) {
            $resJson = $this->toPrettyJson($data['response']);
            $parts[] = $toolkit->render_expandable_section(
                __('Response', 'order-daemon'),
                $toolkit->render_code_block($resJson, 'json')
            );
        }

        if (empty($parts)) {
            return $toolkit->render_notice(__('No webhook details available', 'order-daemon'));
        }

        return implode('', $parts);
    }

    /**
     * Determine if this renderer can handle provided data (legacy analyzer usage).
     *
     * @param array $data
     * @return bool
     */
    public function canHandle(array $data): bool
    {
        return isset($data['url']) || isset($data['method']);
    }

    /**
     * Normalize value to pretty JSON string.
     *
     * @param mixed $value
     * @return string
     */
    private function toPrettyJson($value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            return (string) wp_json_encode($value);
        }
        return (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
