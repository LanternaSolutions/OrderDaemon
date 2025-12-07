    /**
     * Get filtered data based on request parameters.
     * Implements the same filtering logic as AuditLogEndpoint but without calling its private method.
     *
     * @return array Array of audit log entries.
     */
    public function get_filtered_data(): array
    {
        $log_table = $this->wpdb->prefix . 'odcm_audit_log';
        $payload_table = $this->wpdb->prefix . 'odcm_audit_log_payloads';

        // Use the same query pattern as AuditLogEndpoint for consistency
        $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                COALESCE(p.payload, l.details, '') as payload
                FROM {$log_table} l
                LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id";

        $where_conditions = [];
        $where_values = [];

        // Apply filters manually (same logic as AuditLogEndpoint but inline)
        // Search filter
        if (!empty($_REQUEST['s'])) {
            $search = '%' . $this->wpdb->esc_like(sanitize_text_field($_REQUEST['s'])) . '%';
            $where_conditions[] = "(l.summary LIKE %s OR l.details LIKE %s OR l.order_id LIKE %s)";
            $where_values[] = $search;
            $where_values[] = $search;
            $where_values[] = $search;
        }

        // Status filter
        if (!empty($_REQUEST['status'])) {
            $where_conditions[] = "l.status = %s";
            $where_values[] = sanitize_text_field($_REQUEST['status']);
        }

        // Event type filter
        if (!empty($_REQUEST['event_type'])) {
            $where_conditions[] = "l.event_type = %s";
            $where_values[] = sanitize_text_field($_REQUEST['event_type']);
        }

        // Source filter
        if (!empty($_REQUEST['source'])) {
            $where_conditions[] = "l.source = %s";
            $where_values[] = sanitize_text_field($_REQUEST['source']);
        }

        // Order ID filter
        if (!empty($_REQUEST['order_id'])) {
            $where_conditions[] = "l.order_id = %d";
            $where_values[] = absint($_REQUEST['order_id']);
        }

        // Date range filters
        if (!empty($_REQUEST['date_start'])) {
            $where_conditions[] = "l.timestamp >= %s";
            $where_values[] = sanitize_text_field($_REQUEST['date_start']) . ' 00:00:00';
        }
        if (!empty($_REQUEST['date_end'])) {
            $where_conditions[] = "l.timestamp <= %s";
            $where_values[] = sanitize_text_field($_REQUEST['date_end']) . ' 23:59:59';
        }

        // Test logs filter
        if (empty($_REQUEST['include_tests']) || $_REQUEST['include_tests'] !== '1') {
            $where_conditions[] = "l.is_test = 0";
        }

        // Debug logs filter
        if (empty($_REQUEST['include_debug']) || $_REQUEST['include_debug'] !== '1') {
            $where_conditions[] = "l.event_type != 'debug'";
        }

        // Add WHERE clause if we have conditions
        if (!empty($where_conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Get sorting parameters
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'timestamp';
        $order = !empty($_REQUEST['order']) ? strtoupper(sanitize_key($_REQUEST['order'])) : 'DESC';
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        // Add ordering
        $sql .= " ORDER BY l.{$orderby} {$order}";

        // Execute query
        if (!empty($where_values)) {
            $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $where_values), ARRAY_A);
        } else {
            $results = $this->wpdb->get_results($sql, ARRAY_A);
        }

        return $results ?: [];
    }
