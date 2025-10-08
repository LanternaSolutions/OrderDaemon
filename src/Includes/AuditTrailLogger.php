<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

/**
 * Provides a robust API for writing to the audit trail database tables.
 * Acts as a specialized logger for audit trail events.
 */
final class AuditTrailLogger
{
    /**
     * Records an audit trail event.
     *
     * This method writes log entries to the wp_odcm_audit_log and wp_odcm_audit_log_payloads tables.
     * It includes the logic from the existing Logger::log method.
     *
     * @param string $status     The status of the event (e.g., 'success', 'failure', 'warning').
     * @param string $event_type A slug-like identifier for the event type.
     * @param string $summary    A brief, human-readable summary of the event.
     * @param array  $data       An associative array with event-specific data.
     *                           - 'order_id': (Optional) The ID of the related WooCommerce order.
     *                           - 'details': (Optional) A detailed payload, typically an array or object to be JSON encoded.
     *                           - 'process_id': (Optional) Correlation ID for a single process narrative.
     *                           - 'log_category': (Optional) Log category string.
     *                           - 'is_test': (Optional) Bool/int flag for test data.
     *                           - 'source': (Optional) Source label (e.g., 'system', 'admin').
     * 
     * @return int|false The ID of the new log entry, or false on failure.
     */
    public static function record(string $status, string $event_type, string $summary, array $data = [])
    {
        static $odcm_log_in_progress = false;
        if ($odcm_log_in_progress) {
            // Prevent recursive logging if an error inside logging triggers another log write
            return false;
        }
        $odcm_log_in_progress = true;
        try {
            global $wpdb;
            $audit_log_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table   = $wpdb->prefix . 'odcm_audit_log_payloads';

        $details    = $data['details'] ?? null;
        $payload_id = null;

        // 1. Insert the detailed payload into the payloads table first.
        if ($details !== null) {
            $payload_data = is_string($details) ? $details : wp_json_encode($details);
            // Best-effort sanitize: ensure string
            $payload_data = is_string($payload_data) ? $payload_data : '';
            // Attempt insert; table may not have created_at/log_payload_data on some envs.
            // Use minimal required column 'payload'.
            $inserted = $wpdb->insert(
                $payload_table,
                [
                    'payload' => $payload_data,
                ],
                ['%s']
            );

            if ($inserted) {
                $payload_id = $wpdb->insert_id;
            }
        }

        // 2. Insert the main entry into the audit log table.
        $log_data = [
            'timestamp'   => current_time('mysql', true),
            'status'      => sanitize_text_field($status),
            'event_type'  => sanitize_key($event_type),
            'summary'     => sanitize_text_field($summary),
            'order_id'    => isset($data['order_id']) ? absint($data['order_id']) : null,
            'payload_id'  => $payload_id,
            'details'     => '', // The 'details' column in the main table is no longer used for payloads.
        ];

        // Optional fields supported by schema (if columns exist)
        if (isset($data['process_id']) && is_string($data['process_id'])) {
            $log_data['process_id'] = substr(sanitize_text_field($data['process_id']), 0, 64);
        }
        if (isset($data['log_category']) && is_string($data['log_category'])) {
            $log_data['log_category'] = substr(sanitize_text_field($data['log_category']), 0, 20);
        }
        if (isset($data['is_test'])) {
            $log_data['is_test'] = (int) (!empty($data['is_test']));
        }
        if (isset($data['source']) && is_string($data['source'])) {
            $log_data['source'] = substr(sanitize_text_field($data['source']), 0, 50);
        }

        // Build formats dynamically to match fields
        $columns = array_keys($log_data);
        $values  = array_values($log_data);
        $formats = [];
        foreach ($columns as $col) {
            switch ($col) {
                case 'order_id':
                case 'payload_id':
                case 'is_test':
                    $formats[] = '%d';
                    break;
                default:
                    $formats[] = '%s';
            }
        }

        $result = $wpdb->insert($audit_log_table, $log_data, $formats);
        
        if (!$result) {
            return false;
        }
        
        $log_id = $wpdb->insert_id;
        
        // 3. Fire a WordPress action with the log ID and data.
        do_action('odcm_audit_log_recorded', $log_id, $log_data);
        
        return $log_id;
        } finally {
            $odcm_log_in_progress = false;
        }
    }
}
