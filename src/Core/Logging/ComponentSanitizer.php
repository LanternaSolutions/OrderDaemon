<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Logging;

/**
 * Class ComponentSanitizer
 *
 * Sanitizes component data by event_type to ensure safe, consistent logging payloads.
 * All scalar fields are sanitized, IDs are cast with absint, and enums are validated.
 * Unknown kinds are sanitized best-effort by scalarizing values.
 *
 * @package OrderDaemon\CompletionManager\Core
 */
final class ComponentSanitizer
{
    /**
     * Sanitize known component data shapes.
     *
     * @param string $event_type Component event_type (e.g., 'status_changed').
     * @param array  $data Raw component data.
     * @return array Sanitized data array.
     */
    public function sanitize(string $event_type, array $data): array
    {
        switch ($event_type) {
            case 'status_changed':
                return [
                    'from' => sanitize_text_field($data['from'] ?? ''),
                    'to'   => sanitize_text_field($data['to'] ?? ''),
                ];

            case 'note_added':
                return [
                    'visibility' => in_array(($data['visibility'] ?? 'private'), ['private', 'customer'], true) ? $data['visibility'] : 'private',
                    'content'    => wp_kses_post($data['content'] ?? ''),
                    'note_id'    => absint($data['note_id'] ?? 0),
                ];

            case 'email_action':
                return [
                    'template' => sanitize_key($data['template'] ?? ''),
                    'action'   => in_array(($data['action'] ?? 'sent'), ['sent', 'suppressed', 'skipped'], true) ? $data['action'] : 'sent',
                    'reason'   => sanitize_text_field($data['reason'] ?? ''),
                ];

            case 'stock_adjusted':
                return [
                    'product_id'      => absint($data['product_id'] ?? 0),
                    'delta'           => (int)($data['delta'] ?? 0),
                    'resulting_stock' => isset($data['resulting_stock']) ? (int)$data['resulting_stock'] : null,
                ];

            case 'meta_updated':
                return [
                    'key'  => sanitize_key($data['key'] ?? ''),
                    'from' => array_key_exists('from', $data) ? sanitize_text_field((string)$data['from']) : null,
                    'to'   => array_key_exists('to', $data) ? sanitize_text_field((string)$data['to']) : null,
                ];

            case 'validation':
                // Narrative schema for validation components expected by RuleEvaluationRenderer
                // Preferred fields: name, step, result, details (array)
                // Back-compat mapping: if legacy fields (rule, passed, message) are provided, map them.
                $name   = isset($data['name']) ? sanitize_key($data['name']) : (isset($data['rule']) ? sanitize_key($data['rule']) : '');
                $step   = isset($data['step']) ? sanitize_text_field($data['step']) : '';
                // Result: prefer explicit 'result'; otherwise derive from boolean 'passed'
                if (isset($data['result'])) {
                    $result = sanitize_text_field((string)$data['result']);
                } elseif (array_key_exists('passed', $data)) {
                    $result = !empty($data['passed']) ? 'pass' : 'fail';
                } else {
                    $result = '';
                }
                $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : null;
                // If no explicit details and legacy 'message' exists, include as details.note
                if ($details === null && isset($data['message']) && $data['message'] !== '') {
                    $details = ['note' => sanitize_text_field((string)$data['message'])];
                }
                return [
                    'name'    => $name,
                    'step'    => $step,
                    'result'  => $result,
                    'details' => $details,
                ];

            case 'decision':
                return [
                    'subject' => sanitize_key($data['subject'] ?? ''),
                    'outcome' => sanitize_key($data['outcome'] ?? ''),
                    'reason'  => sanitize_text_field($data['reason'] ?? ''),
                ];

            case 'action_scheduled':
                return [
                    'hook'      => sanitize_key($data['hook'] ?? ''),
                    'run_at'    => sanitize_text_field($data['run_at'] ?? ''),
                    'args_hash' => sanitize_text_field($data['args_hash'] ?? ''),
                ];

            case 'action_run':
                return [
                    'hook'        => sanitize_key($data['hook'] ?? ''),
                    'outcome'     => in_array(($data['outcome'] ?? 'success'), ['success','failed','skipped'], true) ? $data['outcome'] : 'success',
                    'attempt'     => absint($data['attempt'] ?? 1),
                    'duration_ms' => isset($data['duration_ms']) ? absint($data['duration_ms']) : null,
                ];

            case 'order_loaded':
                return [
                    'id'     => absint($data['id'] ?? 0),
                    'status' => sanitize_text_field($data['status'] ?? ''),
                ];

            case 'rule_evaluated':
                return [
                    'rule_id'  => absint($data['rule_id'] ?? 0),
                    'priority' => absint($data['priority'] ?? 0),
                    'matched'  => !empty($data['matched']),
                    'reason'   => sanitize_text_field($data['reason'] ?? ''),
                ];

            case 'http_webhook':
                return [
                    'provider'    => sanitize_key($data['provider'] ?? ''),
                    'event'       => sanitize_text_field($data['event'] ?? ''),
                    'status'      => sanitize_text_field($data['status'] ?? ''),
                    'delivery_id' => sanitize_text_field($data['delivery_id'] ?? ''),
                ];

            case 'db_write':
                $out = [
                    'operation' => sanitize_key($data['operation'] ?? ''),
                    'count'     => absint($data['count'] ?? 0),
                ];
                if (isset($data['table'])) {
                    $out['table'] = sanitize_key($data['table']);
                }
                if (isset($data['meta_key'])) {
                    $out['meta_key'] = sanitize_key($data['meta_key']);
                }
                return $out;

            case 'metrics':
                return [
                    'name'  => sanitize_key($data['name'] ?? ''),
                    'value' => (float)($data['value'] ?? 0),
                    'unit'  => sanitize_text_field($data['unit'] ?? ''),
                ];

            case 'warning':
                return [
                    'code'    => sanitize_key($data['code'] ?? 'warn'),
                    'message' => sanitize_text_field($data['message'] ?? ''),
                    'context' => is_array($data['context'] ?? null) ? $data['context'] : null,
                ];

            case 'error':
                return [
                    'code'    => sanitize_key($data['code'] ?? 'unknown'),
                    'message' => sanitize_text_field($data['message'] ?? ''),
                    'context' => is_array($data['context'] ?? null) ? $data['context'] : null,
                ];

            case 'info':
                return [
                    'message' => sanitize_text_field($data['message'] ?? ''),
                ];

            default:
                // Best-effort scalar sanitization for unknown kinds
                $out = [];
                foreach ($data as $k => $v) {
                    $out[sanitize_key((string)$k)] = is_scalar($v) ? sanitize_text_field((string)$v) : $v;
                }
                return $out;
        }
    }
}
