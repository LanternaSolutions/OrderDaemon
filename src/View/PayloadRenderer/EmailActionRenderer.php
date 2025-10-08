<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Email Action Renderer
 *
 * Renders details of outbound email actions (recipients, subject, template,
 * payload excerpts, and success flags). Uses PayloadComponentUIToolkit for
 * consistent markup, proper escaping, and code blocks.
 *
 * Security:
 * - Treat all $data as untrusted; escape via toolkit helpers.
 * - Only safe HTML via wp_kses_post for known-safe content if needed.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 */
final class EmailActionRenderer extends PayloadComponentRenderer
{
    /**
     * Get Component ID used for non-narrative contexts.
     * Narrative rendering will override this via renderWithComponentId().
     *
     * @return string
     */
    protected function getComponentId(): string
    {
        return 'email_action';
    }

    /**
     * Render inner content for an email action timeline item.
     *
     * Expected narrative data keys (best-effort):
     * - to: string|array
     * - subject: string
     * - template: string|null
     * - payload: array|string|null (email body/template variables)
     * - success: bool|null
     * - error: array{ code?:string, message?:string }|null
     *
     * @param array $data Email action payload data
     * @return string HTML content for component body
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $parts = [];

        // Recipients and subject summary
        $to = $this->normalizeRecipients($data['to'] ?? null);
        $subject = isset($data['subject']) ? sanitize_text_field((string)$data['subject']) : '';
        $template = isset($data['template']) ? sanitize_text_field((string)$data['template']) : '';
        $success = isset($data['success']) ? (bool)$data['success'] : null;

        $kv = [];
        if ($to !== '') {
            $kv['To'] = $to;
        }
        if ($subject !== '') {
            $kv['Subject'] = $subject;
        }
        if ($template !== '') {
            $kv['Template'] = $template;
        }
        if ($success !== null) {
            $kv['Result'] = $success ? 'success' : 'failure';
        }
        if (!empty($kv)) {
            $parts[] = $toolkit->render_key_value_list($kv, __('Email Summary', 'order-daemon'));
        }

        // Error block if provided
        if (!empty($data['error']) && is_array($data['error'])) {
            $errKv = [];
            if (!empty($data['error']['code'])) {
                $errKv['Code'] = sanitize_text_field((string)$data['error']['code']);
            }
            if (!empty($data['error']['message'])) {
                $errKv['Message'] = sanitize_text_field((string)$data['error']['message']);
            }
            if (!empty($errKv)) {
                $parts[] = $toolkit->render_key_value_list($errKv, __('Error', 'order-daemon'));
            }
        }

        // Payload preview (JSON pretty)
        if (isset($data['payload'])) {
            $json = $this->toPrettyJson($data['payload']);
            $parts[] = $toolkit->render_expandable_section(
                __('Email Payload', 'order-daemon'),
                $toolkit->render_code_block($json, 'json')
            );
        }

        if (empty($parts)) {
            // Minimal fallback
            return $toolkit->render_notice(__('No email details available', 'order-daemon'));
        }

        return implode('', $parts);
    }

    /**
     * Determine if this renderer can handle provided data (legacy analyzer usage).
     * Not used in narrative-only flow, but kept for interface completeness.
     *
     * @param array $data
     * @return bool
     */
    public function canHandle(array $data): bool
    {
        return isset($data['subject']) || isset($data['to']);
    }

    /**
     * Normalize recipients to a safe, comma-separated string.
     *
     * @param mixed $to
     * @return string
     */
    private function normalizeRecipients($to): string
    {
        if (is_array($to)) {
            $safe = array_map(static function($v) { return sanitize_text_field((string)$v); }, $to);
            return implode(', ', array_filter($safe, static fn($s) => $s !== ''));
        }
        if (is_string($to)) {
            return sanitize_text_field($to);
        }
        return '';
    }

    /**
     * Encode mixed value as pretty JSON for code block display.
     *
     * @param mixed $value
     * @return string
     */
    private function toPrettyJson($value): string
    {
        if (is_string($value)) {
            // Try decode to pretty-print; otherwise escape the string as JSON
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            return (string) wp_json_encode($value);
        }
        return (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
