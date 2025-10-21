<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API;

use OrderDaemon\CompletionManager\Includes\Odcm_Config;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API Endpoints for Insight Dashboard
 *
 * Provides secure, high-performance API endpoints for the insight dashboard,
 * leveraging existing audit trail infrastructure for maximum code reuse.
 *
 * Endpoints:
 * - GET /wp-json/odcm/v1/audit-log/ - Fetch filtered log entries
 * - POST /wp-json/odcm/v1/audit-log/render-components/ - Render log components
 *
 * @package OrderDaemon\CompletionManager\API
 * @since   1.0.0
 */
class AuditLogEndpoint extends WP_REST_Controller
{
    /**
     * API namespace
     */
    const NAMESPACE = 'odcm/v1';

    /**
     * API base route
     */
    const BASE_ROUTE = 'audit-log';

    /**
     * Register REST API routes
     */
    public function register_routes(): void
    {
        // Main audit log endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_logs'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => $this->get_logs_args(),
            ],
        ]);

        // Component rendering endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/render-components', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'render_components'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'log_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function($value) {
                            return is_numeric($value) && $value > 0;
                        },
                    ],
                ],
            ],
        ]);

        // Batch component rendering endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/render-components/batch', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'render_components_batch'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'log_ids' => [
                        'required'          => true,
                        'type'              => 'array',
                        'items'             => [ 'type' => 'integer', 'minimum' => 1 ],
                        'minItems'          => 1,
                        'maxItems'          => 50,
                        'sanitize_callback' => function($value) {
                            if (!is_array($value)) { return []; }
                            $ids = array_map('absint', $value);
                            $ids = array_values(array_unique(array_filter($ids, function($v){ return is_int($v) && $v > 0; })));
                            return array_slice($ids, 0, 50);
                        },
                        'validate_callback' => function($value) {
                            return is_array($value) && count($value) >= 1 && count($value) <= 50;
                        },
                    ],
                    'include_debug' => [
                        'type'              => 'boolean',
                        'default'           => false,
                        'sanitize_callback' => function($value) {
                            if (is_bool($value)) { return $value; }
                            if (is_string($value)) { return in_array(strtolower($value), ['1','true','yes'], true); }
                            return (bool)$value;
                        },
                        'validate_callback' => function($value) {
                            return is_bool($value) || is_string($value) || is_numeric($value);
                        },
                    ],
                ],
            ],
        ]);

        // Filter options endpoint for dynamic filter population
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/filter-options', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_filter_options'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        ]);

        // Batch delete endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/batch-delete', [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'batch_delete_logs'],
                'permission_callback' => [$this, 'check_delete_permissions'],
                'args'                => [
                    'log_ids' => [
                        'required'          => true,
                        'type'              => 'array',
                        'items'             => [
                            'type'    => 'integer',
                            'minimum' => 1,
                        ],
                        'minItems'          => 1,
                        'maxItems'          => 100, // Limit batch size for performance
                        'sanitize_callback' => function($value) {
                            if (!is_array($value)) {
                                return [];
                            }
                            return array_map('absint', array_filter($value, function($id) {
                                return is_numeric($id) && $id > 0;
                            }));
                        },
                        'validate_callback' => function($value) {
                            return is_array($value) && count($value) > 0 && count($value) <= 100;
                        },
                    ],
                ],
            ],
        ]);

        // Find-by-process endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/process/(?P<process_id>[^/]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_logs_by_process'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'process_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // Diagnostic endpoint for route verification (debug mode only)
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/diagnostic', [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'diagnostic_check'],
                    'permission_callback' => '__return_true', // Public for debugging
                ],
            ]);
        }
    }

    /**
     * Check API permissions (Core policy)
     *
     * - GET routes: require manage_woocommerce capability.
     * - POST routes: require manage_woocommerce + valid REST nonce.
     *
     * Insight Dashboard is a core free feature; no premium entitlement checks apply here.
     *
     * @param WP_REST_Request $request The REST request
     * @return bool True if permitted; false otherwise
     */
    public function check_permissions(WP_REST_Request $request): bool
    {
        // Enhanced permission debugging for 404 troubleshooting
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM API Permission Check (Free):');
            error_log('- User ID: ' . get_current_user_id());
            error_log('- User roles: ' . implode(', ', wp_get_current_user()->roles ?? []));
            error_log('- manage_woocommerce: ' . (current_user_can('manage_woocommerce') ? 'YES' : 'NO'));
            error_log('- Request method: ' . $request->get_method());
            error_log('- Request URL: ' . $request->get_route());
            error_log('- User agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
            error_log('- Referer: ' . ($_SERVER['HTTP_REFERER'] ?? 'unknown'));
        }

        // Strict capability requirement for all routes
        if (!current_user_can('manage_woocommerce')) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM API: Permission denied - user lacks manage_woocommerce capability');
            }
            return false;
        }

        // Nonce verification for POST requests (state-changing)
        if ($request->get_method() === 'POST') {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM API: Permission denied - invalid or missing nonce for POST request');
                    error_log('- Nonce provided: ' . ($nonce ? 'YES' : 'NO'));
                    error_log('- Nonce value: ' . ($nonce ?: 'none'));
                }
                return false;
            }
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM API: Permission check passed');
        }

        return true;
    }

    /**
     * Check delete permissions for batch operations
     *
     * Enhanced security for destructive operations:
     * 1. All standard permission checks
     * 2. Additional capability check for log deletion
     * 3. Mandatory nonce verification
     */
    public function check_delete_permissions(WP_REST_Request $request): bool
    {
        // Run standard permission checks first
        if (!$this->check_permissions($request)) {
            return false;
        }

        // Additional capability check for deletion operations
        if (!current_user_can('delete_posts')) {
            return false;
        }

        // Mandatory nonce verification for DELETE operations
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }

        return true;
    }

    /**
     * Batch delete audit log entries
     *
     * Performs secure batch deletion with transaction support and audit logging
     */
    public function batch_delete_logs(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $log_ids = $request->get_param('log_ids');

            if (empty($log_ids) || !is_array($log_ids)) {
                return new WP_Error(
                    'odcm_invalid_log_ids',
                    __('Invalid log IDs provided', Odcm_Config::$text_domain),
                    ['status' => 400]
                );
            }

            // Start performance monitoring
            $start_time = microtime(true);

            // Validate log IDs exist and user has permission to delete them
            $valid_log_ids = $this->validate_log_ids_for_deletion($log_ids);

            if (empty($valid_log_ids)) {
                return new WP_Error(
                    'odcm_no_valid_logs',
                    __('No valid log entries found for deletion', Odcm_Config::$text_domain),
                    ['status' => 404]
                );
            }

            // Perform batch deletion in transaction
            $deleted_count = $this->perform_batch_deletion($valid_log_ids);

            // Log the batch deletion operation
            $this->log_batch_deletion($valid_log_ids, $deleted_count);

            // Performance monitoring
            $execution_time = microtime(true) - $start_time;
            $this->log_api_performance('batch_delete_logs', $execution_time, [
                'requested_count' => count($log_ids),
                'deleted_count' => $deleted_count,
                'valid_ids_count' => count($valid_log_ids)
            ]);

            return new WP_REST_Response([
                'success' => true,
                'deleted_count' => $deleted_count,
                'requested_count' => count($log_ids),
                'message' => sprintf(
                    _n(
                        'Successfully deleted %d log entry',
                        'Successfully deleted %d log entries',
                        $deleted_count,
                        Odcm_Config::$text_domain
                    ),
                    $deleted_count
                ),
                'meta' => [
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                ],
            ], 200);

        } catch (\Exception $e) {
            // Log error for debugging
            $this->log_api_error('batch_delete_logs', $e, ['log_ids' => $log_ids ?? []]);

            return new WP_Error(
                'odcm_batch_delete_error',
                __('Failed to delete log entries', Odcm_Config::$text_domain),
                ['status' => 500]
            );
        }
    }

    /**
     * Get audit logs with filtering and pagination
     *
     * Uses direct database queries for optimal performance and consistency
     * with the insight dashboard requirements.
     */
    public function get_logs(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Start performance monitoring
            $start_time = microtime(true);

            // Get pagination parameters
            $per_page = $request->get_param('per_page') ?: 20;
            $page = $request->get_param('page') ?: 1;

            // Validate pagination parameters
            $per_page = max(1, min(200, (int) $per_page));
            $page = max(1, (int) $page);

            // Fetch all filtered logs (no pagination), then consolidate and paginate the consolidated list
            $all_logs = $this->get_all_filtered_logs($request);
            if (is_wp_error($all_logs)) {
                // Normalize erroneous 404/other errors into empty data so UI can render empty state
                $all_logs = [];
            }

            // Apply UI-only consolidation by process_id for lifecycle events
            try {
                $all_logs = $this->apply_process_id_consolidation($all_logs);
            } catch (\Throwable $e) {
                // Fail-safe: keep original logs ungrouped
                error_log('ODCM: Process ID consolidation failed: ' . $e->getMessage());
            }

            // Compute consolidated totals and slice current page
            $total = is_array($all_logs) ? count($all_logs) : 0;
            $total_pages = max(1, (int) ceil($total / $per_page));
            if ($page > $total_pages) {
                $page = $total_pages; // Clamp to last page
            }
            $offset = ($page - 1) * $per_page;
            $page_logs = $total > 0 ? array_slice($all_logs, $offset, $per_page) : [];
            $start_item = $total > 0 ? ($offset + 1) : 0;
            $end_item = $total > 0 ? min($offset + $per_page, $total) : 0;

            // Performance monitoring
            $execution_time = microtime(true) - $start_time;
            $this->log_api_performance('get_logs', $execution_time, [
                'total_logs' => $total,
                'per_page' => $per_page,
                'page' => $page,
                'filters_applied' => count(array_filter($request->get_params()))
            ]);

            // Build response
            $response_data = [
                'logs' => $this->format_logs_for_api($page_logs),
                'pagination' => [
                    'total' => $total,
                    'total_pages' => $total_pages,
                    'current_page' => $page,
                    'per_page' => $per_page,
                    'start_item' => $start_item,
                    'end_item' => $end_item,
                    'has_previous' => $page > 1,
                    'has_next' => $page < $total_pages,
                ],
                'filters' => $this->get_applied_filters($request),
                'meta' => [
                    'execution_time' => $execution_time,
                    'cache_status' => $this->get_cache_status($request),
                    'timestamp' => current_time('mysql'),
                    'consolidated_pagination' => true,
                    'pagination_basis' => 'consolidated',
                ],
            ];

            return new WP_REST_Response($response_data, 200);

        } catch (\Exception $e) {
            // Log error for debugging
            $this->log_api_error('get_logs', $e, $request->get_params());

            return new WP_Error(
                'odcm_api_error',
                __('Failed to fetch audit logs', Odcm_Config::$text_domain),
                ['status' => 500]
            );
        }
    }

    /**
     * Render log components for detail view
     * SIMPLIFIED ARCHITECTURE: Single decision point using process_id
     */
    public function render_components(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $log_id = $request->get_param('log_id');
            $include_debug = $request->get_param('include_debug');
            
            // Normalize include_debug
            if (!is_bool($include_debug)) {
                if (is_string($include_debug)) {
                    $include_debug = in_array(strtolower($include_debug), ['1','true','yes'], true);
                } else {
                    $include_debug = (bool) $include_debug;
                }
            }

            // Get log entry
            $log = $this->get_log_by_id((int)$log_id);
            if (!$log) {
                return new WP_Error(
                    'odcm_log_not_found',
                    __('Log entry not found', Odcm_Config::$text_domain),
                    ['status' => 404]
                );
            }

            // Start performance monitoring
            $start_time = microtime(true);

            // ===== SINGLE DECISION POINT =====
            // Check if this represents a process timeline (multiple events)
            if (!empty($log['is_process_representative']) && !empty($log['process_id'])) {
                // Render timeline for all events in this process
                $html = $this->render_process_timeline($log['process_id'], $include_debug);
            } else {
                // Render individual entry
                $html = $this->render_individual_entry($log, $include_debug);
            }

            // Performance monitoring
            $execution_time = microtime(true) - $start_time;
            $this->log_api_performance('render_components', $execution_time, [
                'log_id' => $log_id,
                'is_process_timeline' => !empty($log['process_id']),
                'html_size' => strlen($html)
            ]);

            return new WP_REST_Response([
                'html' => $html,
                'meta' => [
                    'log_id' => (int)$log_id,
                    'is_process_timeline' => !empty($log['process_id']),
                    'process_id' => $log['process_id'] ?? null,
                    'components_filtered' => !$include_debug,
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                ],
            ], 200);

        } catch (\Exception $e) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM: Exception in render_components: " . $e->getMessage());
            }
            
            $this->log_api_error('render_components', $e, ['log_id' => $log_id ?? null]);

            return new WP_Error(
                'odcm_render_error',
                __('Failed to render log components', Odcm_Config::$text_domain),
                ['status' => 500]
            );
        }
    }

    /**
     * Render timeline for all events in a process
     * 
     * @param string $process_id The process ID to query
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the process timeline
     */
    private function render_process_timeline(string $process_id, bool $include_debug): string
    {
        if (empty($process_id)) {
            return '<div class="odcm-empty-data">' . esc_html__('Invalid process ID', Odcm_Config::$text_domain) . '</div>';
        }

        try {
            // Query all events with this process_id
            global $wpdb;
            $log_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
            $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

            if ($payload_table_exists) {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               COALESCE(p.payload, l.details, '') as payload 
                        FROM {$log_table} l 
                        LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id
                        WHERE l.process_id = %s 
                        ORDER BY l.timestamp ASC";
            } else {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               l.details as payload 
                        FROM {$log_table} l
                        WHERE l.process_id = %s 
                        ORDER BY l.timestamp ASC";
            }

            $process_events = $wpdb->get_results($wpdb->prepare($sql, $process_id), 'ARRAY_A');

            if (empty($process_events)) {
                return '<div class="odcm-empty-data">' . esc_html__('No events found for this process', Odcm_Config::$text_domain) . '</div>';
            }

            // Extract all components from process events
            $all_components = [];
            foreach ($process_events as $event) {
                $components = $this->extract_components_from_single_event($event, $include_debug);
                $all_components = array_merge($all_components, $components);
            }

            // Render unified timeline
            return $this->render_component_timeline($all_components);

        } catch (\Throwable $e) {
            error_log('ODCM: render_process_timeline failed: ' . $e->getMessage());
            return '<div class="odcm-empty-data">' . esc_html__('Error rendering process timeline', Odcm_Config::$text_domain) . '</div>';
        }
    }

    /**
     * Render individual log entry
     * 
     * @param array $log The log entry
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output
     */
    private function render_individual_entry(array $log, bool $include_debug): string
    {
        $payload_raw = $log['payload'] ?? '';
        
        // Handle empty payload
        if (empty($payload_raw)) {
            return $this->render_empty_entry_fallback($log);
        }
        
        // Parse payload
        $payload = json_decode($payload_raw, true);
        if (!is_array($payload)) {
            return $this->render_empty_entry_fallback($log);
        }
        
        // Extract components from payload
        $components = $this->extract_components_from_payload($payload, $include_debug);
        
        // Render timeline
        return $this->render_component_timeline($components);
    }

    /**
     * Extract components from a single event
     * UNIFIED HELPER: Works for both process and individual entries
     * 
     * @param array $event Event data
     * @param bool $include_debug Whether to include debug components
     * @return array Array of components
     */
    private function extract_components_from_single_event(array $event, bool $include_debug): array
    {
        $payload_raw = $event['payload'] ?? '';
        
        if (empty($payload_raw)) {
            // Create synthetic component for empty payload
            return [[
                'kind' => 'info',
                'label' => $event['summary'] ?? 'Event',
                'ts' => $event['timestamp'] ?? current_time('mysql'),
                'level' => $event['status'] ?? 'info',
                'data' => [
                    'message' => $event['summary'] ?? 'No details available',
                    'event_type' => $event['event_type'] ?? '',
                ],
            ]];
        }
        
        $payload = json_decode($payload_raw, true);
        if (!is_array($payload)) {
            return [];
        }
        
        return $this->extract_components_from_payload($payload, $include_debug);
    }

    /**
     * Extract components from payload data
     * CORE EXTRACTION LOGIC: Works with ProcessLogger and legacy formats
     * 
     * @param array $payload Decoded payload
     * @param bool $include_debug Whether to include debug components
     * @return array Array of components
     */
    private function extract_components_from_payload(array $payload, bool $include_debug): array
    {
        // ProcessLogger format (preferred)
        if (isset($payload['components']) && is_array($payload['components'])) {
            $components = $payload['components'];
            
            // Filter debug components if needed
            if (!$include_debug) {
                $components = array_filter($components, function($c) {
                    return is_array($c) && !$this->is_debug_component($c);
                });
            }
            
            return array_values($components);
        }
        
        // Legacy/unknown format: create generic component
        return [[
            'kind' => 'info',
            'label' => 'Event Data',
            'ts' => current_time('mysql'),
            'level' => 'info',
            'data' => $payload,
        ]];
    }

    /**
     * Render component timeline using registry-driven renderers
     * CORE RENDERING: Uses PayloadComponentRegistry for specialized rendering
     * 
     * @param array $components Array of components to render
     * @return string HTML output
     */
    private function render_component_timeline(array $components): string
    {
        if (empty($components)) {
            return '<div class="odcm-empty-data">' . esc_html__('No timeline data', Odcm_Config::$text_domain) . '</div>';
        }
        
        // Sort chronologically
        usort($components, function($a, $b) {
            return strcmp($a['ts'] ?? '', $b['ts'] ?? '');
        });
        
        // Load registry
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }
        
        $html = '<div class="odcm-narrative-timeline">';
        
        foreach ($components as $component) {
            $kind = sanitize_key($component['kind'] ?? 'info');
            $label = (string) ($component['label'] ?? ucfirst($kind));
            $ts = (string) ($component['ts'] ?? '');
            $level = sanitize_key($component['level'] ?? 'info');
            $data = is_array($component['data'] ?? null) ? $component['data'] : [];
            
            if (empty($data)) {
                continue; // Skip empty components
            }
            
            // Registry lookup for specialized renderer
            $def = odcm_get_payload_component_type($kind);
            $renderer_html = '';
            
            if (is_array($def) && isset($def['renderer_class'])) {
                $renderer_class = $def['renderer_class'];
                if (strpos($renderer_class, '\\') === false) {
                    $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
                }
                
                if (class_exists($renderer_class)) {
                    try {
                        $renderer = new $renderer_class();
                        
                        if (method_exists($renderer, 'renderTimelineItem')) {
                            $renderer_html = $renderer->renderTimelineItem($kind, $label, $ts ?: null, $level, $data);
                        } elseif (method_exists($renderer, 'render')) {
                            $renderer_html = $renderer->render($data);
                        }
                    } catch (\Throwable $e) {
                        error_log('ODCM: Renderer error for ' . $renderer_class . ': ' . $e->getMessage());
                    }
                }
            }
            
            // Use rendered content or fallback
            if (!empty($renderer_html)) {
                $html .= $renderer_html;
            } else {
                $html .= $this->render_fallback_component($kind, $label, $data);
            }
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Render fallback for empty log entries
     * 
     * @param array $log Log entry data
     * @return string HTML output
     */
    private function render_empty_entry_fallback(array $log): string
    {
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        }
        $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
        
        $content = '<div class="odcm-fallback-content">';
        $content .= '<p><strong>Summary:</strong> ' . esc_html($log['summary'] ?? 'No summary') . '</p>';
        $content .= '<p><strong>Status:</strong> ' . esc_html($log['status'] ?? 'Unknown') . '</p>';
        $content .= '<p><strong>Timestamp:</strong> ' . esc_html($log['timestamp'] ?? 'Unknown') . '</p>';
        if (!empty($log['order_id'])) {
            $content .= '<p><strong>Order:</strong> #' . esc_html($log['order_id']) . '</p>';
        }
        $content .= '<p><em>No payload data available</em></p>';
        $content .= '</div>';
        
        return $toolkit->render_component_shell(
            'Log Entry',
            'fallback',
            $content,
            ['status' => $log['status'] ?? 'info']
        );
    }

    /**
     * Render fallback component when renderer fails
     * 
     * @param string $kind Component kind
     * @param string $label Component label
     * @param array $data Component data
     * @return string HTML output
     */
    private function render_fallback_component(string $kind, string $label, array $data): string
    {
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        }
        $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
        
        $content = '<div class="odcm-fallback-component">';
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $formatted_key = ucfirst(str_replace('_', ' ', $key));
                $content .= '<p><strong>' . esc_html($formatted_key) . ':</strong> ' . esc_html((string)$value) . '</p>';
            }
        }
        $content .= '</div>';
        
        return $toolkit->render_component_shell(
            $label,
            'fallback',
            $content,
            []
        );
    }

    /**
     * Batch render log components for multiple log IDs.
     *
     * Accepts up to 50 IDs and returns an array of per-item results,
     * reusing the same renderer pipeline as render_components().
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function render_components_batch(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $log_ids = $request->get_param('log_ids');
            if (!is_array($log_ids) || empty($log_ids)) {
                return new WP_Error('odcm_invalid_log_ids', __('Invalid log IDs provided', Odcm_Config::$text_domain), ['status' => 400]);
            }
            // Normalize IDs (absint, unique, cap to 50)
            $ids = array_values(array_unique(array_map('absint', array_filter($log_ids, function($v){ return is_numeric($v) && (int)$v > 0; }))));
            if (empty($ids)) {
                return new WP_Error('odcm_no_valid_ids', __('No valid log IDs provided', Odcm_Config::$text_domain), ['status' => 400]);
            }
            if (count($ids) > 50) {
                $ids = array_slice($ids, 0, 50);
            }

            // Normalize include_debug similar to render_components()
            $include_debug = $request->get_param('include_debug');
            if (!is_bool($include_debug)) {
                if (is_string($include_debug)) {
                    $include_debug = in_array(strtolower($include_debug), ['1','true','yes'], true);
                } else {
                    $include_debug = (bool) $include_debug;
                }
            }

            $t0 = microtime(true);
            $logs_map = $this->fetch_logs_by_ids($ids);
            $items = [];

            foreach ($ids as $id) {
                if (!isset($logs_map[$id])) {
                    $items[] = [
                        'log_id' => (int) $id,
                        'success' => false,
                        'error' => [ 'code' => 'not_found', 'message' => __('Log entry not found', Odcm_Config::$text_domain) ]
                    ];
                    continue;
                }
                $log = $logs_map[$id];
                $payload_raw = $log['payload'] ?? '';
                $details = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
                if (!is_array($details)) { $details = []; }

                // Respect debug-only visibility per item
                if ($this->is_process_logger_entry($details) && !$include_debug && $this->is_debug_only_process($details)) {
                    $items[] = [
                        'log_id' => (int) $id,
                        'success' => false,
                        'error' => [ 'code' => 'debug_filtered', 'message' => __('Debug log entry filtered', Odcm_Config::$text_domain) ]
                    ];
                    continue;
                }

                // Filter debug components when include_debug is false
                if (isset($details['components']) && is_array($details['components']) && !$include_debug) {
                    $filtered = [];
                    foreach ($details['components'] as $component) {
                        if (!is_array($component)) { continue; }
                        if ($this->is_debug_component($component)) { continue; }
                        $filtered[] = $component;
                    }
                    $details['components'] = $filtered;
                }

                // Render using the same logic as single-item
                try {
                    if ($this->is_process_logger_entry($details)) {
                        $html = $this->render_narrative_timeline($details, $include_debug);
                    } else {
                        $html = $this->render_log_components($log);
                    }
                    $items[] = [ 'log_id' => (int) $id, 'success' => true, 'html' => $html ];
                } catch (\Throwable $e) {
                    $items[] = [
                        'log_id' => (int) $id,
                        'success' => false,
                        'error' => [ 'code' => 'render_error', 'message' => __('Failed to render components', Odcm_Config::$text_domain) ]
                    ];
                }
            }

            $exec = microtime(true) - $t0;
            return new WP_REST_Response([
                'items' => $items,
                'meta' => [
                    'count' => count($items),
                    'execution_time' => $exec,
                    'timestamp' => current_time('mysql'),
                ],
            ], 200);
        } catch (\Throwable $e) {
            $this->log_api_error('render_components_batch', $e, [ 'ids' => $request->get_param('log_ids') ]);
            return new WP_Error('odcm_render_batch_error', __('Failed to render batch components', Odcm_Config::$text_domain), ['status' => 500]);
        }
    }

    /**
     * Get filter options for dynamic filter population
     *
     * Queries the audit log table for distinct values and returns
     * structured, UX-friendly value/label pairs with performance meta.
     *
     * @param WP_REST_Request $request Request object (nonce and auth already checked)
     * @return WP_REST_Response
     */
    public function get_filter_options(WP_REST_Request $request): WP_REST_Response
    {
        $start_time = microtime(true);

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'odcm_audit_log';

            // Build queries with proper NULL/empty handling and ordering.
            // Note: Table name cannot be parameterized in $wpdb->prepare.
            $sql_statuses = "SELECT DISTINCT status FROM {$table_name} WHERE status IS NOT NULL AND status != %s ORDER BY status ASC";
            $sql_event_types = "SELECT DISTINCT event_type FROM {$table_name} WHERE event_type IS NOT NULL AND event_type != %s ORDER BY event_type ASC";
            $sql_sources = "SELECT DISTINCT source FROM {$table_name} WHERE source IS NOT NULL AND source != %s ORDER BY source ASC";

            // Execute queries. We use prepared statements for any values (here: empty string filter),
            // even though there is no user input, to adhere to security guidelines.
            $statuses_raw = $wpdb->get_col($wpdb->prepare($sql_statuses, '')) ?: [];
            $event_types_raw = $wpdb->get_col($wpdb->prepare($sql_event_types, '')) ?: [];
            $sources_raw = $wpdb->get_col($wpdb->prepare($sql_sources, '')) ?: [];

            // Normalize to strings and unique trim (defensive)
            $statuses_raw = array_values(array_unique(array_filter(array_map(function($v){ return is_string($v) ? trim($v) : ''; }, $statuses_raw))));
            $event_types_raw = array_values(array_unique(array_filter(array_map(function($v){ return is_string($v) ? trim($v) : ''; }, $event_types_raw))));
            $sources_raw = array_values(array_unique(array_filter(array_map(function($v){ return is_string($v) ? trim($v) : ''; }, $sources_raw))));

            // Transform into value/label pairs with UX-friendly labels
            $statuses = array_map(function(string $status){
                return [
                    'value' => $status,
                    'label' => $this->format_status_label($status),
                ];
            }, $statuses_raw);

            $event_types = array_map(function(string $value){
                return [
                    'value' => $value,
                    'label' => $this->format_filter_label($value),
                ];
            }, $event_types_raw);

            $sources = array_map(function(string $value){
                return [
                    'value' => $value,
                    'label' => $this->format_filter_label($value),
                ];
            }, $sources_raw);

            // Premium access check (feature-gated advanced filters)
            $can_use_premium = function_exists('odcm_can_use') ? odcm_can_use('audit_log_filter_advanced') : false;

            $execution_time = microtime(true) - $start_time;

            // Performance monitoring and slow-query logging
            if ($execution_time > 0.5) {
                $this->log_api_performance('get_filter_options', $execution_time, [
                    'status_count' => count($statuses),
                    'event_type_count' => count($event_types),
                    'source_count' => count($sources),
                    'slow' => true,
                ]);
            } else {
                $this->log_api_performance('get_filter_options', $execution_time, [
                    'status_count' => count($statuses),
                    'event_type_count' => count($event_types),
                    'source_count' => count($sources),
                ]);
            }

            // Cache hint: Clients can cache for 5 minutes (configurable later)
            $cache_ttl = 300; // seconds
            $cache_expires = time() + $cache_ttl;

            $response = [
                'success' => true,
                'filter_options' => [
                    'sources' => $sources,
                    'event_types' => $event_types,
                    'statuses' => $statuses,
                ],
                'premium_access' => $can_use_premium,
                'meta' => [
                    'execution_time' => $execution_time,
                    'result_counts' => [
                        'sources' => count($sources),
                        'event_types' => count($event_types),
                        'statuses' => count($statuses),
                    ],
                    'timestamp' => current_time('mysql'),
                    'cache_ttl' => $cache_ttl,
                    'cache_expires' => $cache_expires,
                ],
            ];

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            // Enhanced error logging with context and timing
            $context = [ 'execution_time' => isset($start_time) ? (microtime(true) - $start_time) : null ];
            $this->log_api_error('get_filter_options', $e, $context);

            // Graceful degradation: return empty options but preserve premium_access flag
            $fallback = [
                'success' => false,
                'filter_options' => [
                    'sources' => [],
                    'event_types' => [],
                    'statuses' => [],
                ],
                'premium_access' => function_exists('odcm_can_use') ? odcm_can_use('audit_log_filter_advanced') : false,
                'meta' => [
                    'execution_time' => isset($start_time) ? (microtime(true) - $start_time) : null,
                    'timestamp' => current_time('mysql'),
                    'cache_ttl' => 300,
                    'cache_expires' => time() + 300,
                    'error' => 'Failed to fetch filter options',
                ],
            ];

            return new WP_REST_Response($fallback, 200);
        }
    }

    /**
     * Convert snake_case or kebab-case to Title Case for better UX labels.
     *
     * @param string $value Raw value from database
     * @return string Title-cased label
     */
    private function format_filter_label(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Replace underscores/dashes with spaces, collapse whitespace, and ucwords
        $normalized = preg_replace('/[_-]+/', ' ', strtolower($value));
        $normalized = trim(preg_replace('/\s+/', ' ', (string) $normalized));
        return ucwords($normalized);
    }

    /**
     * Special formatting for status labels with predefined mappings
     * (e.g., 'error' => 'Error'). Falls back to generic formatter.
     *
     * @param string $status Status string
     * @return string Human-friendly label
     */
    private function format_status_label(string $status): string
    {
        $map = [
            'success' => __('Success', Odcm_Config::$text_domain),
            'error'   => __('Error', Odcm_Config::$text_domain),
            'warning' => __('Warning', Odcm_Config::$text_domain),
            'info'    => __('Info', Odcm_Config::$text_domain),
        ];
        $key = strtolower(trim($status));
        if (isset($map[$key])) {
            return $map[$key];
        }
        return $this->format_filter_label($status);
    }

    /**
     * Get argument schema for logs endpoint
     */
    private function get_logs_args(): array
    {
        return [
            'page' => [
                'type'              => 'integer',
                'minimum'           => 1,
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'type'              => 'integer',
                'minimum'           => 1,
                'maximum'           => 200,
                'default'           => 20,
                'sanitize_callback' => 'absint',
            ],
            's' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'type'              => 'string',
                'enum'              => ['success', 'error', 'warning', 'info'],
                'sanitize_callback' => 'sanitize_key',
            ],
            'event_type' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
            'source' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order_id' => [
                'type'              => 'integer',
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'date_start' => [
                'type'              => 'string',
                'format'            => 'date',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_end' => [
                'type'              => 'string',
                'format'            => 'date',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'include_tests' => [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => function($value) {
                    // Handle both boolean and string inputs gracefully
                    if (is_bool($value)) {
                        return $value;
                    }
                    if (is_string($value)) {
                        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
                    }
                    return (bool) $value;
                },
                'validate_callback' => function($value) {
                    return is_bool($value) || is_string($value) || is_numeric($value);
                },
            ],
            'include_debug' => [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => function($value) {
                    // Handle both boolean and string inputs gracefully
                    if (is_bool($value)) {
                        return $value;
                    }
                    if (is_string($value)) {
                        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
                    }
                    return (bool) $value;
                },
                'validate_callback' => function($value) {
                    return is_bool($value) || is_string($value) || is_numeric($value);
                },
            ],
            'include_consolidation_diag' => [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => function($value) {
                    if (is_bool($value)) {
                        return $value;
                    }
                    if (is_string($value)) {
                        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
                    }
                    return (bool) $value;
                },
                'validate_callback' => function($value) {
                    return is_bool($value) || is_string($value) || is_numeric($value);
                },
            ],
            'since' => [
                'type'              => 'string',
                'format'            => 'date-time',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'type'              => 'string',
                'enum'              => ['timestamp', 'status', 'order_id', 'event_type', 'source'],
                'default'           => 'timestamp',
                'sanitize_callback' => 'sanitize_key',
            ],
            'order' => [
                'type'              => 'string',
                'enum'              => ['asc', 'desc'],
                'default'           => 'desc',
                'sanitize_callback' => function($value) {
                    return in_array(strtolower($value), ['asc', 'desc']) ? strtolower($value) : 'desc';
                },
            ],
        ];
    }


    /**
     * Format logs for API response
     */
    private function format_logs_for_api(array $logs): array
    {
        return array_map(function($log) {
            $formatted = [
                'id' => (int) $log['id'],
                'timestamp' => $log['timestamp'],
                'status' => $log['status'],
                'summary' => $log['summary'],
                'order_id' => !empty($log['order_id']) ? (int) $log['order_id'] : null,
                'event_type' => $log['event_type'],
                'source' => $log['source'] ?? 'system',
                'is_test' => !empty($log['is_test']) && (int) $log['is_test'] === 1,
                'has_payload' => !empty($log['payload']),
            ];

            // Pass through process identifiers if present
            if (!empty($log['process_id'])) {
                $formatted['process_id'] = (string) $log['process_id'];
            }
            if (!empty($log['process_id_display'])) {
                $formatted['process_id_display'] = (string) $log['process_id_display'];
            }

            // Include consolidation data for UI rendering when available
            if (!empty($log['consolidation_data']) && is_array($log['consolidation_data'])) {
                $formatted['consolidation_data'] = $log['consolidation_data'];
            }

            return $formatted;
        }, $logs);
    }

    /**
     * Get applied filters from request
     */
    private function get_applied_filters(WP_REST_Request $request): array
    {
        $filters = [];
        $filter_params = ['s', 'status', 'event_type', 'source', 'order_id', 'date_start', 'date_end', 'include_tests', 'include_debug', 'include_consolidation_diag'];

        foreach ($filter_params as $param) {
            $value = $request->get_param($param);
            if (!empty($value)) {
                $filters[$param] = $value;
            }
        }

        return $filters;
    }

    /**
     * Get cache status for debugging
     */
    private function get_cache_status(WP_REST_Request $request): string
    {
        $cache_key = 'odcm_audit_logs_' . md5(serialize($request->get_params()));
        return get_transient($cache_key) !== false ? 'HIT' : 'MISS';
    }

    /**
     * Get log entry by ID
     */
    private function get_log_by_id(int $log_id): ?array
    {
        global $wpdb;
        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Check if payload table exists
        $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

        if ($payload_table_exists) {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test,
                           COALESCE(p.payload, l.details, '') as payload 
                    FROM {$log_table} l 
                    LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id
                    WHERE l.log_id = %d";
        } else {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test,
                           l.details as payload 
                    FROM {$log_table} l
                    WHERE l.log_id = %d";
        }

        $result = $wpdb->get_row($wpdb->prepare($sql, $log_id), 'ARRAY_A');
        return $result ?: null;
    }

    /**
     * Fetch multiple logs by IDs in a single query (payload-aware)
     *
     * @param int[] $ids
     * @return array<int,array> keyed by log id
     */
    private function fetch_logs_by_ids(array $ids): array
    {
        if (empty($ids)) { return []; }
        global $wpdb;
        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
        $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        if ($payload_table_exists) {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id,
                           l.event_type, l.source, l.payload_id, l.is_test,
                           COALESCE(p.payload, l.details, '') as payload
                    FROM {$log_table} l
                    LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id
                    WHERE l.log_id IN ({$placeholders})";
        } else {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id,
                           l.event_type, l.source, l.payload_id, l.is_test,
                           l.details as payload
                    FROM {$log_table} l
                    WHERE l.log_id IN ({$placeholders})";
        }

        // Prepare with dynamic placeholders
        $prepared = $wpdb->prepare($sql, $ids);
        $rows = $wpdb->get_results($prepared, 'ARRAY_A');
        if (!is_array($rows) || empty($rows)) { return []; }
        $map = [];
        foreach ($rows as $r) {
            if (isset($r['id'])) {
                $map[(int) $r['id']] = $r;
            }
        }
        return $map;
    }

    /**
     * Render log components using existing system
     */
    private function render_log_components(array $log): string
    {
        if (empty($log['payload'])) {
            // Render a single fallback timeline item instead of empty message
            if (!function_exists('odcm_get_payload_component_type')) {
                require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
            }
            $def = function_exists('odcm_get_payload_component_type') ? \odcm_get_payload_component_type('fallback') : null;
            if (is_array($def) && isset($def['renderer_class'])) {
                $renderer_class = $def['renderer_class'];
                if (strpos($renderer_class, '\\') === false) {
                    $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
                }
                if (class_exists($renderer_class)) {
                    try {
                        $renderer = new $renderer_class();
                        $item_html = method_exists($renderer, 'renderTimelineItem')
                            ? $renderer->renderTimelineItem('fallback', __('Additional Data', Odcm_Config::$text_domain), null, 'info', [])
                            : (method_exists($renderer, 'renderWithComponentId')
                                ? $renderer->renderWithComponentId('fallback', [])
                                : $renderer->render([]));
                        $html  = '<div class="odcm-narrative-timeline">';
                        $html .= '<ul class="odcm-timeline-list">';
                        $html .= '<li class="odcm-timeline-item odcm-level-info"><div class="odcm-timeline-item-inner">' . $item_html . '</div></li>';
                        $html .= '</ul></div>';
                        return $html;
                    } catch (\Throwable $e) {
                        // Fall through to minimal message on renderer failure
                    }
                }
            }
            // Final safeguard: use FallbackRenderer directly if registry lookup failed
            $renderer_fqcn = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer';
            if (class_exists($renderer_fqcn)) {
                try {
                    $renderer = new $renderer_fqcn();
                    $item_html = method_exists($renderer, 'renderTimelineItem')
                        ? $renderer->renderTimelineItem('fallback', __('Additional Data', Odcm_Config::$text_domain), null, 'info', [])
                        : $renderer->render([]);
                    $html  = '<div class="odcm-narrative-timeline">';
                    $html .= '<ul class="odcm-timeline-list">';
                    $html .= '<li class="odcm-timeline-item odcm-level-info"><div class="odcm-timeline-item-inner">' . $item_html . '</div></li>';
                    $html .= '</ul></div>';
                    return $html;
                } catch (\Throwable $e) {
                    // fall through to minimal placeholder
                }
            }
            // Minimal placeholder component using UIToolkit for consistent structure
            if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
                require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
            }
            $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
            $content = '<em>' . esc_html__('No timeline data available', Odcm_Config::$text_domain) . '</em>';
            return $toolkit->render_component_shell(
                esc_html__('Additional Data', Odcm_Config::$text_domain),
                'fallback',
                $content
            );
        }

        // Decode payload if it's JSON
        $data = json_decode($log['payload'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = ['raw_data' => $log['payload']];
        }

        // Always use narrative timeline rendering for both consolidated and legacy entries
        if (is_array($data) && !empty($data)) {
            return $this->render_narrative_timeline($data);
        }

        // If no payload data at all, show empty message
        return '<div class="odcm-empty-data">' . esc_html__('No timeline data', Odcm_Config::$text_domain) . '</div>';
    }

    /**
     * Render a narrative timeline for components.
     *
     * @param array $envelope The decoded payload envelope containing components and metadata.
     * @return string HTML output for the details pane.
     */
    private function render_narrative_timeline(array $envelope, bool $include_debug = false): string
    {
        // Always log method entry with basic info
        error_log("ODCM TIMELINE: render_narrative_timeline called with envelope keys: " . json_encode(array_keys($envelope)));
        
        try {
        // Check if we have components
        if (!isset($envelope['components']) || !is_array($envelope['components'])) {
            error_log("ODCM TIMELINE: No components found, using fallback");
            return $this->render_fallback_timeline($envelope);
        }

        $components = $envelope['components'];
            error_log("ODCM TIMELINE: Found " . count($components) . " components");

            // Filter debug components if needed
            if (!$include_debug) {
                $filtered = [];
                foreach ($components as $component) {
                    if (!is_array($component)) { continue; }
                    if (!$this->is_debug_component($component)) {
                        $filtered[] = $component;
                    }
                }
                $components = $filtered;
                error_log("ODCM TIMELINE: After debug filtering: " . count($components) . " components");
            }

            // If no components after filtering, return appropriate message
            if (empty($components)) {
                error_log("ODCM TIMELINE: No components after filtering, returning empty message");
                if (!$include_debug) {
                    return '<div class="odcm-empty-data">' . esc_html__('All events filtered (debug mode disabled)', Odcm_Config::$text_domain) . '</div>';
                } else {
                    return '<div class="odcm-empty-data">' . esc_html__('No timeline components available', Odcm_Config::$text_domain) . '</div>';
                }
            }

            // Try to render using the existing timeline logic
            if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
                require_once dirname(__DIR__) . '/Core/ProcessLifecycleDiscovery.php';
            }
            $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
            $families = $discovery->get_process_families();

            // Group logs by business families
            $grouped_logs = $this->group_logs_by_families($envelope, $families, $include_debug);
            error_log("ODCM TIMELINE: Grouped into " . count($grouped_logs) . " groups");

            // Render consolidated or individual entries
            $result = $this->render_grouped_timeline($grouped_logs, $include_debug);
            error_log("ODCM TIMELINE: render_grouped_timeline returned " . strlen($result) . " characters");

            // If the result is just an empty timeline div, use fallback
            if ($result === '<div class="odcm-narrative-timeline"></div>' || empty(trim($result))) {
                error_log("ODCM TIMELINE: Empty result from render_grouped_timeline, using fallback");
                return $this->render_fallback_timeline($envelope);
            }

            return $result;

        } catch (\Throwable $e) {
            error_log("ODCM TIMELINE: Exception in render_narrative_timeline: " . $e->getMessage());
            return $this->render_fallback_timeline($envelope);
        }
    }

    /**
     * Render fallback timeline when normal rendering fails
     *
     * @param array $envelope The payload envelope
     * @return string HTML fallback timeline
     */
    private function render_fallback_timeline(array $envelope): string
    {
        error_log("ODCM FALLBACK: render_fallback_timeline called");
        
        // Load UI toolkit for consistent rendering
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        }
        $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();

        $html = '<div class="odcm-narrative-timeline">';
        
        // Show basic envelope information
        $content = '<div class="odcm-fallback-envelope">';
        $content .= '<h4>Envelope Information</h4>';
        
        if (isset($envelope['type'])) {
            $content .= '<p><strong>Type:</strong> ' . esc_html($envelope['type']) . '</p>';
        }
        if (isset($envelope['oid'])) {
            $content .= '<p><strong>Order ID:</strong> #' . esc_html($envelope['oid']) . '</p>';
        }
        if (isset($envelope['ts'])) {
            // Handle Unix timestamp display
            $timestamp_display = is_numeric($envelope['ts']) 
                ? date('Y-m-d H:i:s', (int)$envelope['ts']) 
                : (string)$envelope['ts'];
            $content .= '<p><strong>Started At:</strong> ' . esc_html($timestamp_display) . '</p>';
        }
        if (isset($envelope['cid'])) {
            $content .= '<p><strong>Correlation ID:</strong> ' . esc_html($envelope['cid']) . '</p>';
        }
        
        // Show components summary if they exist
        if (isset($envelope['components']) && is_array($envelope['components'])) {
            $component_count = count($envelope['components']);
            $content .= '<p><strong>Components:</strong> ' . $component_count . ' components</p>';
            
            // Show component types
            $component_kinds = [];
            foreach ($envelope['components'] as $component) {
                if (is_array($component) && isset($component['kind'])) {
                    $component_kinds[] = $component['kind'];
                }
            }
            if (!empty($component_kinds)) {
                $unique_kinds = array_unique($component_kinds);
                $content .= '<p><strong>Component Types:</strong> ' . esc_html(implode(', ', $unique_kinds)) . '</p>';
            }
        } else {
            $content .= '<p><em>No components found in envelope.</em></p>';
        }
        
        $content .= '</div>';
        
        $html .= $toolkit->render_component_shell(
            'Event Data (Fallback)',
            'fallback_envelope',
            $content,
            ['status' => 'info']
        );
        
        $html .= '</div>';
        
        error_log("ODCM FALLBACK: Generated " . strlen($html) . " characters of fallback HTML");
        
        return $html;
    }

    /**
     * Group a narrative envelope by business families, possibly enriching it with
     * related order lifecycle entries within a time window.
     *
     * @param array $envelope
     * @param array $families
     * @return array<int, array{title:string|null, envelopes:array<int,array>}> Groups of envelopes
     */
    private function group_logs_by_families(array $envelope, array $families, bool $include_debug = false): array
    {
        // Defensive checks
        $type = isset($envelope['type']) ? (string)$envelope['type'] : '';
        $order_id = isset($envelope['oid']) ? (int)$envelope['oid'] : 0;
        $ts = isset($envelope['ts']) ? $envelope['ts'] : '';

        $order_family = $families['order_lifecycle'] ?? null;
        $order_types = is_array($order_family) && isset($order_family['process_types']) && is_array($order_family['process_types'])
            ? $order_family['process_types']
            : [];
        $time_window = is_array($order_family) && isset($order_family['time_window_minutes']) ? (int)$order_family['time_window_minutes'] : 30;

        // If this envelope is not an order lifecycle process or we cannot identify order/time, keep single group
        if ($type === '' || !in_array($type, $order_types, true) || $order_id <= 0 || $ts === '') {
            return [ [ 'title' => null, 'envelopes' => [ $envelope ] ] ];
        }

        // Try to fetch related envelopes for the same order within the time window and belonging to the family
        $related = [];
        try {
            global $wpdb;
            $log_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
            $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");
            // Compute window bounds in MySQL using INTERVAL
            // We fetch candidates by order_id and timestamp close to ts, then filter by envelope.type in PHP
            if ($payload_table_exists) {
                $sql = "SELECT COALESCE(p.payload, l.details) AS payload, l.timestamp FROM {$log_table} l LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id WHERE l.order_id = %d AND l.timestamp BETWEEN (STR_TO_DATE(%s, '%Y-%m-%dT%H:%i:%sZ') - INTERVAL %d MINUTE) AND (STR_TO_DATE(%s, '%Y-%m-%dT%H:%i:%sZ') + INTERVAL %d MINUTE) ORDER BY l.timestamp ASC";
            } else {
                $sql = "SELECT l.details AS payload, l.timestamp FROM {$log_table} l WHERE l.order_id = %d AND l.timestamp BETWEEN (STR_TO_DATE(%s, '%Y-%m-%dT%H:%i:%sZ') - INTERVAL %d MINUTE) AND (STR_TO_DATE(%s, '%Y-%m-%dT%H:%i:%sZ') + INTERVAL %d MINUTE) ORDER BY l.timestamp ASC";
            }
            // Accept both Z and offset by also supporting direct string compare when STR_TO_DATE fails; fallback below if needed
            $prepared = $wpdb->prepare($sql, $order_id, $ts, $time_window, $ts, $time_window);
            $rows = $wpdb->get_results($prepared, 'ARRAY_A');

            if (!is_array($rows)) { $rows = []; }
            foreach ($rows as $row) {
                $payload_raw = $row['payload'] ?? '';
                if (!is_string($payload_raw) || $payload_raw === '') { continue; }
                $det = json_decode($payload_raw, true);
                if (!is_array($det)) { continue; }
                $det_type = isset($det['type']) ? (string)$det['type'] : '';
                if ($det_type === '' || !in_array($det_type, $order_types, true)) { continue; }
                // Only include envelopes that have components (ProcessLogger entries)
                if (!isset($det['components']) || !is_array($det['components'])) { continue; }
                $related[] = $det;
            }
        } catch (\Throwable $e) {
            // Fail-safe: do not break UX if DB access/parsing fails
            error_log('ODCM: group_logs_by_families discovery failed: ' . $e->getMessage());
        }

        // Ensure the current envelope is included and remove duplicates by cid
        $all = array_merge([ $envelope ], $related);
        $seen = [];
        $unique = [];
        foreach ($all as $env) {
            $cid = isset($env['cid']) ? (string)$env['cid'] : md5(serialize($env));
            if (isset($seen[$cid])) { continue; }
            $seen[$cid] = true;
            $unique[] = $env;
        }

        return [ [ 'title' => null, 'envelopes' => $unique ] ];
    }

    /**
     * Render grouped envelopes into a consolidated timeline.
     * Uses direct payload renderer output with embedded context data.
     *
     * @param array<int, array{title:string|null, envelopes:array<int,array>}> $grouped_logs
     * @return string
     */
    private function render_grouped_timeline(array $grouped_logs, bool $include_debug = false): string
    {
        // Merge all payload components from envelopes in the first group (current scope only specifies one consolidated group)
        if (empty($grouped_logs)) {
            return '<div class="odcm-empty-data">' . esc_html__('No timeline data', Odcm_Config::$text_domain) . '</div>';
        }

        $group = $grouped_logs[0];
        $envelopes = isset($group['envelopes']) && is_array($group['envelopes']) ? $group['envelopes'] : [];
        $components = [];

        // Respect include_debug flag provided by the client
        foreach ($envelopes as $env) {
            // Handle ProcessLogger entries (with components)
            if (isset($env['components']) && is_array($env['components'])) {
                $pcs = $env['components'];
                foreach ($pcs as $pc) {
                    if (!$include_debug) {
                        $lvl = isset($pc['level']) ? (string)$pc['level'] : '';
                        $kind = isset($pc['kind']) ? (string)$pc['kind'] : '';
                        if ($lvl === 'debug' || $kind === 'process_started') { continue; }
                    }
                    $components[] = $pc;
                }
            } else {
                // Handle custom event entries (without components) using existing renderer system
                // Create a timeline component that uses the existing registry-driven renderers
                $custom_component = [
                    'kind' => 'custom_event',
                    'label' => 'Custom Event Data',
                    'ts' => current_time('mysql'),
                    'level' => 'info',
                    'data' => $env,
                ];

                // Filter debug components if not including debug
                if (!$include_debug) {
                    // Check if this custom event should be considered debug
                    $is_debug = false;
                    if (isset($env['level']) && $env['level'] === 'debug') {
                        $is_debug = true;
                    }
                    if (isset($env['source']) && strpos($env['source'], 'debug_') === 0) {
                        $is_debug = true;
                    }

                    if (!$is_debug) {
                        $components[] = $custom_component;
                    }
                } else {
                    $components[] = $custom_component;
                }
            }
        }

        // Sort chronologically by ts
        usort($components, static function($a, $b) {
            return strcmp((string)($a['ts'] ?? ''), (string)($b['ts'] ?? ''));
        });

        // Separate primary events from context-only components
        $primary_events = [];
        $context_components = [];

        foreach ($components as $component) {
            $kind = sanitize_key($component['kind'] ?? 'info');
            if ($this->isContextOnlyComponent($kind)) {
                $context_components[] = $component;
            } else {
                $primary_events[] = $component;
            }
        }

        // Embed context components into primary events based on proximity and relevance
        $enriched_events = $this->embedContextIntoEvents($primary_events, $context_components);

        // Load registry for renderer lookup
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }

        $html = '<div class="odcm-narrative-timeline">';

        $first_meta = true;
        // We use the first envelope to provide timeline meta (ts/trigger) if available
        $meta_env = !empty($envelopes) ? $envelopes[0] : [];

        foreach ($enriched_events as $event) {
            $kind  = sanitize_key($event['kind'] ?? 'info');
            $label = (string) ($event['label'] ?? ucfirst($kind));
            $ts    = (string) ($event['ts'] ?? '');
            $level = sanitize_key($event['level'] ?? 'info');
            $data  = is_array($event['data'] ?? null) ? $event['data'] : [];

            // Add embedded context content to data for renderer
            if (isset($event['embedded_context_content']) && is_array($event['embedded_context_content'])) {
                $data['embedded_context_content'] = $event['embedded_context_content'];
            }

            // Skip rendering if data is empty (prevents "Payload data cannot be empty" error)
            if (empty($data)) {
                continue;
            }

            // Lookup registry for renderer
            $def = function_exists('odcm_get_payload_component_type') ? \odcm_get_payload_component_type($kind) : null;

            $renderer = null;
            $renderer_html = '';
            if (is_array($def) && isset($def['renderer_class'])) {
                $renderer_class = $def['renderer_class'];
                if (strpos($renderer_class, '\\') === false) {
                    $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
                }
                if (class_exists($renderer_class)) {
                    try {
                        $renderer = new $renderer_class();
                        if ($first_meta && method_exists($renderer, 'setTimelineMeta')) {
                            $startedAt = isset($meta_env['ts']) ? (string)$meta_env['ts'] : null;
                            $trigger   = isset($meta_env['trigger']) ? (string)$meta_env['trigger'] : null;
                            $renderer->setTimelineMeta($startedAt, $trigger);
                        }
                        if (method_exists($renderer, 'renderTimelineItem')) {
                            $renderer_html = $renderer->renderTimelineItem($kind, $label, $ts !== '' ? $ts : null, $level, $data);
                            if ($first_meta) { $first_meta = false; }
                        } elseif (method_exists($renderer, 'renderWithComponentId')) {
                            $renderer_html = $renderer->renderWithComponentId($kind, $data);
                        } else {
                            $renderer_html = $renderer->render($data);
                        }
                    } catch (\Throwable $e) {
                        error_log('ODCM Timeline(grouped): Renderer error for ' . $renderer_class . ': ' . $e->getMessage());
                    }
                }
            }

            // Render component directly without timeline wrappers
            if ($renderer_html !== '') {
                $html .= $renderer_html;
            } else {
                // Fallback rendering for components without renderers
                if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
                    require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
                }
                $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
                $content = '<em>' . esc_html__('No renderer output', 'order-daemon') . '</em>';
                $html .= $toolkit->render_component_shell(
                    esc_html(ucfirst($kind)),
                    'fallback',
                    $content,
                    []
                );
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Find logs by process_id
     * Returns a chronologically ordered list of logs for a given process_id.
     * process_id is backend-only; no new filters are added elsewhere.
     */
    public function get_logs_by_process(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            $process_id = sanitize_text_field((string)$request->get_param('process_id'));
            if ($process_id === '') {
                return new WP_Error('odcm_invalid_process_id', __('Invalid process ID', Odcm_Config::$text_domain), ['status' => 400]);
            }

            $log_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
            $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

            if ($payload_table_exists) {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id,
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               COALESCE(p.payload, l.details, '') as payload
                        FROM {$log_table} l
                        LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id
                        WHERE l.process_id = %s
                        ORDER BY l.timestamp ASC";
            } else {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id,
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               l.details as payload
                        FROM {$log_table} l
                        WHERE l.process_id = %s
                        ORDER BY l.timestamp ASC";
            }

            $rows = $wpdb->get_results($wpdb->prepare($sql, $process_id), 'ARRAY_A');
            if (!$rows) {
                return new WP_REST_Response([
                    'process_id' => $process_id,
                    'count' => 0,
                    'logs' => [],
                    'meta' => ['timestamp' => current_time('mysql')],
                ], 200);
            }

            // Return concise list with payload length to avoid heavy payload transfer if UI doesn’t need full HTML
            $logs = array_map(function($r) {
                $payload_len = isset($r['payload']) ? strlen((string)$r['payload']) : 0;
                return [
                    'id'         => (int) $r['id'],
                    'timestamp'  => $r['timestamp'],
                    'status'     => $r['status'],
                    'summary'    => $r['summary'],
                    'order_id'   => isset($r['order_id']) ? (int)$r['order_id'] : null,
                    'event_type' => $r['event_type'],
                    'source'     => $r['source'] ?? null,
                    'is_test'    => isset($r['is_test']) ? (bool)$r['is_test'] : false,
                    'payload'    => $r['payload'],
                    'payload_len'=> $payload_len,
                ];
            }, $rows);

            return new WP_REST_Response([
                'process_id' => $process_id,
                'count'      => count($logs),
                'logs'       => $logs,
                'meta'       => [ 'timestamp' => current_time('mysql') ],
            ], 200);
        } catch (\Throwable $e) {
            $this->log_api_error('get_logs_by_process', $e, [ 'process_id' => $request->get_param('process_id') ]);
            return new WP_Error('odcm_process_fetch_error', __('Failed to fetch process logs', Odcm_Config::$text_domain), ['status' => 500]);
        }
    }

    /**
     * Log API performance metrics
     */
    private function log_api_performance(string $endpoint, float $execution_time, array $context): void
    {
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }

        // Log slow API calls (>1 second)
        if ($execution_time > 1.0) {
            error_log(sprintf(
                'ODCM API Slow Call: %s took %.3fs - Context: %s',
                $endpoint,
                $execution_time,
                json_encode($context)
            ));
        }

        // Store performance metrics for analysis
        $performance_log = get_option('odcm_api_performance_log', []);
        $performance_log[] = [
            'endpoint' => $endpoint,
            'execution_time' => $execution_time,
            'context' => $context,
            'timestamp' => current_time('mysql'),
        ];

        // Keep only last 100 entries
        if (count($performance_log) > 100) {
            $performance_log = array_slice($performance_log, -100);
        }

        update_option('odcm_api_performance_log', $performance_log, false);
    }

    /**
     * Get filtered logs with custom insight dashboard filtering
     */
    private function get_filtered_logs(WP_REST_Request $request, int $per_page, int $page): array
    {
        global $wpdb;
        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Check if payload table exists
        $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

        // Build base query
        if ($payload_table_exists) {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                           COALESCE(p.payload, l.details, '') as payload 
                    FROM {$log_table} l 
                    LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id";
        } else {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                           l.details as payload 
                    FROM {$log_table} l";
        }

        // Build WHERE clause
        $where_conditions = [];
        $where_values = [];

        // Apply filters
        $this->apply_filters_to_query($request, $where_conditions, $where_values);

        // Add WHERE clause if we have conditions
        if (!empty($where_conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Add ordering
        $orderby = $request->get_param('orderby') ?: 'timestamp';
        $order = $request->get_param('order') ?: 'desc';
        $sql .= " ORDER BY l.{$orderby} {$order}";

        // Add pagination
        $offset = ($page - 1) * $per_page;
        $sql .= " LIMIT %d OFFSET %d";
        $where_values[] = $per_page;
        $where_values[] = $offset;

        // Execute query
        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_values), 'ARRAY_A');
        } else {
            $results = $wpdb->get_results($sql, 'ARRAY_A');
        }

        return $results ?: [];
    }

    /**
     * Build and execute a query for all filtered logs without pagination.
     * Always returns a list (possibly empty). Never throws or returns WP_Error.
     *
     * @param WP_REST_Request $request The REST request with filter params.
     * @return array A list of logs (empty array when no matching data).
     */
    private function get_all_filtered_logs(WP_REST_Request $request): array
    {
        try {
            global $wpdb;
            $log_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

            // Check if payload table exists
            $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

            // Build base query
            if ($payload_table_exists) {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               COALESCE(p.payload, l.details, '') as payload 
                        FROM {$log_table} l 
                        LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id";
            } else {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               l.details as payload 
                        FROM {$log_table} l";
            }

            // Build WHERE clause
            $where_conditions = [];
            $where_values = [];

            // Apply filters
            $this->apply_filters_to_query($request, $where_conditions, $where_values);

            // Add WHERE clause if we have conditions
            if (!empty($where_conditions)) {
                $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
            }

            // Add ordering
            $orderby = $request->get_param('orderby') ?: 'timestamp';
            $order = $request->get_param('order') ?: 'desc';
            $sql .= " ORDER BY l.{$orderby} {$order}";

            // Execute query
            if (!empty($where_values)) {
                $results = $wpdb->get_results($wpdb->prepare($sql, $where_values), 'ARRAY_A');
            } else {
                $results = $wpdb->get_results($sql, 'ARRAY_A');
            }

            // Normalize to an array (empty when no rows)
            return is_array($results) ? $results : [];
        } catch (\Throwable $e) {
            // Log and return empty result to allow frontend empty state rendering
            error_log('ODCM API: get_all_filtered_logs failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get filtered log count for pagination
     */
    private function get_filtered_log_count(WP_REST_Request $request): int
    {
        global $wpdb;
        $log_table = $wpdb->prefix . 'odcm_audit_log';

        // Build count query
        $sql = "SELECT COUNT(*) FROM {$log_table} l";

        // Build WHERE clause
        $where_conditions = [];
        $where_values = [];

        // Apply filters
        $this->apply_filters_to_query($request, $where_conditions, $where_values);

        // Add WHERE clause if we have conditions
        if (!empty($where_conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Execute query
        if (!empty($where_values)) {
            $count = $wpdb->get_var($wpdb->prepare($sql, $where_values));
        } else {
            $count = $wpdb->get_var($sql);
        }

        return (int) $count;
    }

    /**
     * Apply filters to SQL query
     */
    private function apply_filters_to_query(WP_REST_Request $request, array &$where_conditions, array &$where_values): void
    {
        // Enhanced debugging for the "Server error - include test/debug log settings" issue
        error_log("ODCM API FILTER DEBUG: apply_filters_to_query called");
        error_log("ODCM API FILTER DEBUG: Request parameters: " . json_encode($request->get_params()));
        
        try {
            global $wpdb;

            // Search filter with debugging
            $search = $request->get_param('s');
            error_log("ODCM API FILTER DEBUG: Search parameter: " . ($search ?: 'empty'));
            if (!empty($search)) {
                $where_conditions[] = "(l.summary LIKE %s OR l.order_id = %s)";
                $where_values[] = '%' . $wpdb->esc_like($search) . '%';
                $where_values[] = is_numeric($search) ? (int) $search : 0;
                error_log("ODCM API FILTER DEBUG: Search filter applied");
            }

            // Status filter (premium) with debugging
            $status = $request->get_param('status');
            error_log("ODCM API FILTER DEBUG: Status parameter: " . ($status ?: 'empty'));
            if (!empty($status) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
                $where_conditions[] = "l.status = %s";
                $where_values[] = $status;
                error_log("ODCM API FILTER DEBUG: Status filter applied");
            }

            // Event type filter (premium) with debugging
            $event_type = $request->get_param('event_type');
            error_log("ODCM API FILTER DEBUG: Event type parameter: " . ($event_type ?: 'empty'));
            if (!empty($event_type) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
                $where_conditions[] = "l.event_type = %s";
                $where_values[] = $event_type;
                error_log("ODCM API FILTER DEBUG: Event type filter applied");
            }

            // Source filter (premium) with debugging
            $source = $request->get_param('source');
            error_log("ODCM API FILTER DEBUG: Source parameter: " . ($source ?: 'empty'));
            if (!empty($source) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
                $where_conditions[] = "l.source = %s";
                $where_values[] = $source;
                error_log("ODCM API FILTER DEBUG: Source filter applied");
            }

            // Order ID filter (premium) with debugging
            $order_id = $request->get_param('order_id');
            error_log("ODCM API FILTER DEBUG: Order ID parameter: " . ($order_id ?: 'empty'));
            if (!empty($order_id) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
                $where_conditions[] = "l.order_id = %d";
                $where_values[] = (int) $order_id;
                error_log("ODCM API FILTER DEBUG: Order ID filter applied");
            }

            // Date range filters (premium) with debugging
            $date_start = $request->get_param('date_start');
            $date_end = $request->get_param('date_end');
            error_log("ODCM API FILTER DEBUG: Date range - start: " . ($date_start ?: 'empty') . ", end: " . ($date_end ?: 'empty'));
            if (!empty($date_start) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
                $where_conditions[] = "l.timestamp >= %s";
                $where_values[] = $date_start . ' 00:00:00';
                error_log("ODCM API FILTER DEBUG: Date start filter applied");
            }
            if (!empty($date_end) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
                $where_conditions[] = "l.timestamp <= %s";
                $where_values[] = $date_end . ' 23:59:59';
                error_log("ODCM API FILTER DEBUG: Date end filter applied");
            }

            // Include tests filter (always available) - ENHANCED DEBUGGING
            $include_tests = $request->get_param('include_tests');
            error_log("ODCM API FILTER DEBUG: Include tests parameter: " . ($include_tests ? 'true' : 'false') . " (type: " . gettype($include_tests) . ")");
            
            if (!$include_tests) {
                error_log("ODCM API FILTER DEBUG: Applying test exclusion filter");
                try {
                    // Check if is_test column exists before using it
                    $log_table = $wpdb->prefix . 'odcm_audit_log';
                    error_log("ODCM API FILTER DEBUG: Checking is_test column existence in table: $log_table");
                    
                    $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$log_table} LIKE 'is_test'");
                    error_log("ODCM API FILTER DEBUG: is_test column exists: " . ($column_exists ? 'YES' : 'NO'));
                    error_log("ODCM API FILTER DEBUG: WPDB last error after column check: " . ($wpdb->last_error ?: 'none'));

                    if ($column_exists && !$wpdb->last_error) {
                        $where_conditions[] = "(l.is_test IS NULL OR l.is_test = 0)";
                        error_log("ODCM API FILTER DEBUG: Test exclusion condition added successfully");
                    } else {
                        error_log("ODCM API FILTER DEBUG: Test exclusion condition NOT added - column missing or DB error");
                    }
                } catch (\Exception $e) {
                    error_log("ODCM API FILTER DEBUG: Exception in test filter: " . $e->getMessage());
                    error_log("ODCM API FILTER DEBUG: Exception trace: " . $e->getTraceAsString());
                    // Continue without test exclusion
                }
            } else {
                error_log("ODCM API FILTER DEBUG: Including test logs - no test exclusion applied");
            }

            // Include debug filter (always available) - ENHANCED DEBUGGING
            $include_debug = $request->get_param('include_debug');
            error_log("ODCM API FILTER DEBUG: Include debug parameter: " . ($include_debug ? 'true' : 'false') . " (type: " . gettype($include_debug) . ")");
            
            if (!$include_debug) {
                error_log("ODCM API FILTER DEBUG: Applying debug exclusion filter");
                try {
                    // Build debug exclusion condition with defensive checks
                    $debug_condition = $this->build_debug_exclusion_condition();
                    error_log("ODCM API FILTER DEBUG: Debug condition result: " . json_encode($debug_condition));
                    
                    if (!empty($debug_condition['condition'])) {
                        $where_conditions[] = $debug_condition['condition'];
                        if (!empty($debug_condition['values'])) {
                            $where_values = array_merge($where_values, $debug_condition['values']);
                        }
                        error_log("ODCM API FILTER DEBUG: Debug exclusion condition added successfully");
                    } else {
                        error_log("ODCM API FILTER DEBUG: Debug exclusion condition NOT added - empty condition returned");
                    }
                } catch (\Exception $e) {
                    error_log("ODCM API FILTER DEBUG: Exception in debug filter: " . $e->getMessage());
                    error_log("ODCM API FILTER DEBUG: Exception trace: " . $e->getTraceAsString());
                    // Continue without debug exclusion - better to show some debug logs than fail completely
                }
            } else {
                error_log("ODCM API FILTER DEBUG: Including debug logs - no debug exclusion applied");
            }

            // Exclude consolidation diagnostics by default unless explicitly opted-in and in debug
            $include_consolidation_diag = (bool) $request->get_param('include_consolidation_diag');
            $debug_on = (defined('ODCM_DEBUG') && ODCM_DEBUG);
            error_log("ODCM API FILTER DEBUG: Consolidation diag - include: " . ($include_consolidation_diag ? 'true' : 'false') . ", debug_on: " . ($debug_on ? 'true' : 'false'));
            
            if (!($include_consolidation_diag && $debug_on)) {
                $where_conditions[] = "(l.event_type IS NULL OR l.event_type <> %s)";
                $where_values[] = 'consolidation_diag';
                error_log("ODCM API FILTER DEBUG: Consolidation diagnostic exclusion applied");
            } else {
                error_log("ODCM API FILTER DEBUG: Including consolidation diagnostics");
            }

            // Since filter for incremental updates
            $since = $request->get_param('since');
            error_log("ODCM API FILTER DEBUG: Since parameter: " . ($since ?: 'empty'));
            if (!empty($since)) {
                $where_conditions[] = "l.timestamp > %s";
                $where_values[] = $since;
                error_log("ODCM API FILTER DEBUG: Since filter applied");
            }

            error_log("ODCM API FILTER DEBUG: Final filter state - conditions: " . count($where_conditions) . ", values: " . count($where_values));
            error_log("ODCM API FILTER DEBUG: Where conditions: " . json_encode($where_conditions));

        } catch (\Exception $e) {
            error_log("ODCM API FILTER DEBUG: CRITICAL EXCEPTION in apply_filters_to_query: " . $e->getMessage());
            error_log("ODCM API FILTER DEBUG: Exception trace: " . $e->getTraceAsString());
            
            // Reset to basic state if filter application fails
            $where_conditions = [];
            $where_values = [];

            // At minimum, try to apply search filter if it exists
            $search = $request->get_param('s');
            if (!empty($search)) {
                try {
                    $where_conditions[] = "(l.summary LIKE %s OR l.order_id = %s)";
                    $where_values[] = '%' . $wpdb->esc_like($search) . '%';
                    $where_values[] = is_numeric($search) ? (int) $search : 0;
                    error_log("ODCM API FILTER DEBUG: Applied basic search filter in exception recovery");
                } catch (\Exception $search_e) {
                    error_log("ODCM API FILTER DEBUG: Even basic search filter failed: " . $search_e->getMessage());
                    // Complete fallback - no filters
                    $where_conditions = [];
                    $where_values = [];
                }
            }
        }
    }

    /**
     * Build debug exclusion condition with defensive checks
     *
     * Creates a safe SQL condition to exclude debug logs based on available columns
     * and patterns, with fallback handling for different database schemas.
     *
     * @since 1.0.0
     *
     * @return array Array with 'condition' and 'values' keys for SQL building
     */
    private function build_debug_exclusion_condition(): array
    {
        try {
            global $wpdb;
            $log_table = $wpdb->prefix . 'odcm_audit_log';

            // Ensure the details column exists; if not, bail out safely
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$log_table}");
            if ($wpdb->last_error || empty($columns)) {
                if ($wpdb->last_error) {
                    error_log("ODCM API: Error getting table columns: " . $wpdb->last_error);
                }
                return ['condition' => '', 'values' => []];
            }
            $available_columns = array_map('strtolower', $columns);
            if (!in_array('details', $available_columns, true)) {
                // Fallback: cannot reliably filter without details JSON
                return ['condition' => '', 'values' => []];
            }

            // Exclude entries that are debug-only processes by checking details JSON
            // Handle empty/NULL details as non-debug (should pass filter)
            $condition = "(l.details IS NULL OR l.details = '' OR NOT (l.details LIKE %s OR l.details LIKE %s OR l.details LIKE %s))";
            $values = [
                '%"level":"debug"%',
                '%"source":"debug_%',
                '%"type":"debug_%'
            ];

            return [
                'condition' => $condition,
                'values'    => $values,
            ];
        } catch (\Exception $e) {
            // Log error but don't fail the entire query
            error_log("ODCM API: Error building debug exclusion condition: " . $e->getMessage());
            return [
                'condition' => '',
                'values' => []
            ];
        }
    }

    /**
     * Log API errors for debugging
     */
    private function log_api_error(string $endpoint, \Exception $e, array $context): void
    {
        // Log to WordPress error log
        error_log(sprintf(
            'ODCM API Error in %s: %s - Context: %s',
            $endpoint,
            $e->getMessage(),
            json_encode($context)
        ));

        // Log to plugin's audit trail if available
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                "API Error in {$endpoint}: " . $e->getMessage(),
                array_merge($context, [
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'stack_trace' => $e->getTraceAsString(),
                ]),
                null,
                'error',
                'api_error',
                true
            );
        }
    }

    /**
     * Validate log IDs for deletion
     *
     * Ensures log IDs exist and user has permission to delete them
     */
    private function validate_log_ids_for_deletion(array $log_ids): array
    {
        global $wpdb;
        $log_table = $wpdb->prefix . 'odcm_audit_log';

        // Sanitize and validate log IDs
        $sanitized_ids = array_map('absint', array_filter($log_ids, function($id) {
            return is_numeric($id) && $id > 0;
        }));

        if (empty($sanitized_ids)) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($sanitized_ids), '%d'));

        // Check which log IDs actually exist
        $sql = "SELECT log_id FROM {$log_table} WHERE log_id IN ({$placeholders})";
        $existing_ids = $wpdb->get_col($wpdb->prepare($sql, $sanitized_ids));

        return array_map('intval', $existing_ids);
    }

    /**
     * Perform batch deletion with transaction support
     *
     * Deletes logs and associated payload data in a database transaction
     */
    private function perform_batch_deletion(array $log_ids): int
    {
        global $wpdb;
        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Check if payload table exists
        $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            $deleted_count = 0;

            // Create placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($log_ids), '%d'));

            // Delete associated payload data first (if payload table exists)
            if ($payload_table_exists) {
                // Get payload IDs to delete
                $payload_sql = "SELECT DISTINCT payload_id FROM {$log_table} WHERE log_id IN ({$placeholders}) AND payload_id IS NOT NULL";
                $payload_ids = $wpdb->get_col($wpdb->prepare($payload_sql, $log_ids));

                if (!empty($payload_ids)) {
                    $payload_placeholders = implode(',', array_fill(0, count($payload_ids), '%d'));
                    $delete_payload_sql = "DELETE FROM {$payload_table} WHERE payload_id IN ({$payload_placeholders})";
                    $wpdb->query($wpdb->prepare($delete_payload_sql, $payload_ids));
                }
            }

            // Delete log entries
            $delete_logs_sql = "DELETE FROM {$log_table} WHERE log_id IN ({$placeholders})";
            $deleted_count = $wpdb->query($wpdb->prepare($delete_logs_sql, $log_ids));

            // Commit transaction
            $wpdb->query('COMMIT');

            return (int) $deleted_count;

        } catch (\Exception $e) {
            // Rollback transaction on error
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Log batch deletion operation for audit trail
     */
    private function log_batch_deletion(array $log_ids, int $deleted_count): void
    {
        // Only log this admin UX operation when ODCM_DEBUG is explicitly true
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }

        // Log the batch deletion operation if audit logging is available
            if (function_exists('odcm_log_event')) {
                $user = wp_get_current_user();

                odcm_log_event(
                    sprintf(
                        'Batch deleted %d log entries via Insight Dashboard',
                        $deleted_count
                    ),
                    [
                        'deleted_log_ids' => $log_ids,
                        'deleted_count' => $deleted_count,
                        'requested_count' => count($log_ids),
                        'user_id' => $user->ID,
                        'user_login' => $user->user_login,
                        'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    ],
                    null,
                    'info',
                    'batch_delete',
                    false // Don't include this in test logs
                );
            }
    }

    /**
     * Check if component data contains embedded context that should be rendered inline
     *
     * @param array $data Component data array
     * @return bool True if embedded context is present
     */
    private function hasEmbeddedContext(array $data): bool
    {
        return isset($data['attribution']) ||
            isset($data['attribution_context']) ||
            isset($data['performance']) ||
            isset($data['user_context']);
    }

    /**
     * Render embedded context using dual-mode rendering strategy.
     * This method will assemble compact, inline HTML fragments for supported
     * context blocks such as attribution badges and performance metrics.
     *
     * @param array $data      Component data (may contain nested context arrays)
     * @param mixed $renderer  Primary renderer instance for the component (optional)
     * @return string          HTML to be inlined within the primary timeline item
     */
    private function renderEmbeddedContext(array $data, $renderer): string
    {
        $embeddedParts = [];

        // Attribution context (badges, actors). Prefer explicit attribution_context if provided.
        if (isset($data['attribution']) || isset($data['attribution_context'])) {
            $attrData = null;
            if (isset($data['attribution']) && is_array($data['attribution'])) {
                $attrData = $data['attribution'];
            } elseif (isset($data['attribution_context']) && is_array($data['attribution_context'])) {
                $attrData = $data['attribution_context'];
            }

            if (is_array($attrData) && !empty($attrData)) {
                $systemRendererClass = '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\SystemRenderer';
                if (class_exists($systemRendererClass)) {
                    try {
                        $systemRenderer = new $systemRendererClass();
                        // SystemRenderer expects attribution_context key
                        $embeddedParts[] = $systemRenderer->renderEmbeddedContent(['attribution_context' => $attrData]);
                    } catch (\Throwable $e) {
                        error_log('ODCM EmbeddedContext: SystemRenderer error: ' . $e->getMessage());
                    }
                }
            }
        }

        // Performance context (inline metrics)
        if (isset($data['performance']) && is_array($data['performance'])) {
            $perfRendererClass = '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PerformanceRenderer';
            if (class_exists($perfRendererClass)) {
                try {
                    $perfRenderer = new $perfRendererClass();
                    // Pass the inner metrics array to match renderer expectations
                    $embeddedParts[] = $perfRenderer->renderEmbeddedContent($data['performance']);
                } catch (\Throwable $e) {
                    error_log('ODCM EmbeddedContext: PerformanceRenderer error: ' . $e->getMessage());
                }
            }
        }

        // In future: user_context or additional contexts can be embedded here

        return implode('', array_filter(array_map(function($part){
            return is_string($part) ? $part : '';
        }, $embeddedParts)));
    }

    /**
     * Determine if a payload component kind is context-only and should not render as a standalone timeline item.
     *
     * Context-only kinds carry supplemental data that is embedded into primary events (e.g., status change)
     * and must be skipped from standalone rendering in the consolidated timeline.
     *
     * @param string $kind Component kind slug.
     * @return bool True when the component is context-only.
     */
    private function isContextOnlyComponent(string $kind): bool
    {
        $contextOnlyKinds = [
            'attribution',      // Attribution badges - embed in parent events
            'performance',      // Performance metrics - embed in parent events
            'user_context',     // User context data - embed in parent events
            'info',             // Info data - embed in parent events
            'action_executed',  // Action execution details - embed in parent events
            'process_started',  // Debug-only, skip entirely
        ];
        return in_array($kind, $contextOnlyKinds, true);
    }

    /**
     * Check if a component is debug-flagged
     *
     * @param array $component
     * @return bool
     */
    private function is_debug_component(array $component): bool
    {
        $level = isset($component['level']) ? (string)$component['level'] : '';
        $kind  = isset($component['kind']) ? (string)$component['kind'] : '';
        return ($level === 'debug') || ($kind === 'process_started');
    }

    /**
     * Check if this is a consolidated entry
     *
     * @param array $log
     * @return bool
     */
    private function is_consolidated_entry(array $log): bool
    {
        // Check if this log entry has consolidation data indicating it's a consolidated entry
        return isset($log['consolidation_data']['is_consolidated']) && 
               $log['consolidation_data']['is_consolidated'] === true;
    }

    /**
     * Check if this log entry represents a frontend consolidated entry
     * 
     * This detects when we're dealing with a log entry that was likely used as a representative
     * for a consolidated group in the frontend, but doesn't have the consolidation data itself.
     *
     * @param array $log
     * @return bool
     */
    private function is_frontend_consolidated_entry(array $log): bool
    {
        $payload_raw = $log['payload'] ?? '';
        $order_id = isset($log['order_id']) ? (int) $log['order_id'] : 0;
        $event_type = $log['event_type'] ?? '';
        
        // Check if this looks like a representative entry from consolidation:
        // 1. Empty or minimal payload
        // 2. Has order_id 
        // 3. Event type suggests it's part of lifecycle (like process_started)
        if (empty($payload_raw) && $order_id > 0) {
            // Get process families to check if this event type is part of lifecycle
            if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
                require_once dirname(__DIR__) . '/Core/ProcessLifecycleDiscovery.php';
            }
            
            $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
            $families = $discovery->get_process_families();
            $lifecycle_family = $families['order_lifecycle'] ?? null;
            
            if ($lifecycle_family && !empty($lifecycle_family['process_types'])) {
                $lifecycle_types = array_values(array_unique(array_filter((array) ($lifecycle_family['process_types'] ?? []))));
                return in_array($event_type, $lifecycle_types, true);
            }
        }
        
        return false;
    }

    /**
     * Render a process-representative entry by querying all events with the same process_id
     *
     * @param array $log The representative log entry 
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the process timeline
     */
    private function render_frontend_consolidated_entry(array $log, bool $include_debug = false): string
    {
        // Check if this is a process representative entry
        if (!empty($log['is_process_representative']) && !empty($log['process_id'])) {
            // Query all events with the same process_id
            return $this->render_process_timeline_by_process_id($log['process_id'], $include_debug);
        }
        
        // Fallback: render as individual log entry
        return $this->render_log_components($log);
    }

    /**
     * Render timeline for all events with a specific process_id
     *
     * @param string $process_id The process ID to query
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the process timeline
     */
    private function render_process_timeline_by_process_id(string $process_id, bool $include_debug = false): string
    {
        if (empty($process_id)) {
            return '<div class="odcm-empty-data">' . esc_html__('Invalid process ID', Odcm_Config::$text_domain) . '</div>';
        }

        try {
            // Query all events with this process_id
            global $wpdb;
            $log_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
            $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

            if ($payload_table_exists) {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               COALESCE(p.payload, l.details, '') as payload 
                        FROM {$log_table} l 
                        LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id
                        WHERE l.process_id = %s 
                        ORDER BY l.timestamp ASC";
            } else {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               l.details as payload 
                        FROM {$log_table} l
                        WHERE l.process_id = %s 
                        ORDER BY l.timestamp ASC";
            }

            $process_events = $wpdb->get_results($wpdb->prepare($sql, $process_id), 'ARRAY_A');

            if (empty($process_events)) {
                return '<div class="odcm-empty-data">' . esc_html__('No events found for this process', Odcm_Config::$text_domain) . '</div>';
            }

            // Extract all payload components from the process events
            $all_components = [];
            $first_event = $process_events[0];
            $envelope_meta = [
                'type' => 'process_timeline',
                'order_id' => $first_event['order_id'] ?? 0,
                'ts' => $first_event['timestamp'] ?? current_time('mysql'),
                'trigger' => 'process_lifecycle',
                'cid' => $process_id,
            ];

            // Process each event to extract payload components
            foreach ($process_events as $event) {
                $payload_raw = $event['payload'] ?? '';
                if (empty($payload_raw)) {
                    continue;
                }

                $event_details = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
                if (!is_array($event_details)) {
                    continue;
                }

                // Extract payload components if they exist (ProcessLogger entries)
                if (isset($event_details['components']) && is_array($event_details['components'])) {
                    foreach ($event_details['components'] as $component) {
                        if (!is_array($component)) {
                            continue;
                        }

                        // Apply debug filtering
                        if (!$include_debug && $this->is_debug_component($component)) {
                            continue;
                        }

                        $all_components[] = $component;
                    }
                } else {
                    // Handle non-ProcessLogger entries by creating synthetic components
                    $synthetic_component = [
                        'kind' => 'process_event',
                        'label' => $event['summary'] ?? ($event['event_type'] ?? 'Event'),
                        'ts' => $event['timestamp'] ?? current_time('mysql'),
                        'level' => $event['status'] ?? 'info',
                        'data' => array_merge($event_details, [
                            'event_type' => $event['event_type'] ?? '',
                            'source' => $event['source'] ?? '',
                            'log_id' => $event['id'] ?? 0,
                        ]),
                    ];

                    // Apply debug filtering for synthetic components
                    if (!$include_debug && isset($event_details['level']) && $event_details['level'] === 'debug') {
                        continue;
                    }

                    $all_components[] = $synthetic_component;
                }
            }

            // If no components after filtering, show appropriate message
            if (empty($all_components)) {
                if (!$include_debug) {
                    return '<div class="odcm-empty-data">' . esc_html__('All process events filtered (debug mode disabled)', Odcm_Config::$text_domain) . '</div>';
                } else {
                    return '<div class="odcm-empty-data">' . esc_html__('No process components available', Odcm_Config::$text_domain) . '</div>';
                }
            }

            // Create the envelope structure for render_narrative_timeline
            $process_envelope = array_merge($envelope_meta, [
                'components' => $all_components,
            ]);

            // Use the existing narrative timeline rendering
            return $this->render_narrative_timeline($process_envelope, $include_debug);

        } catch (\Throwable $e) {
            error_log('ODCM: render_process_timeline_by_process_id failed: ' . $e->getMessage());
            return '<div class="odcm-empty-data">' . esc_html__('Error rendering process timeline', Odcm_Config::$text_domain) . '</div>';
        }
    }

    /**
     * Render all entries for an order as a unified timeline (fallback method)
     *
     * @param array $order_entries All log entries for an order
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the order timeline
     */
    private function render_order_timeline(array $order_entries, bool $include_debug = false): string
    {
        if (empty($order_entries)) {
            return '<div class="odcm-empty-data">' . esc_html__('No entries found', Odcm_Config::$text_domain) . '</div>';
        }

        // Extract all payload components from the order entries
        $all_components = [];
        $first_entry = $order_entries[0];
        $envelope_meta = [
            'type' => 'order_timeline',
            'order_id' => $first_entry['order_id'] ?? 0,
            'ts' => $first_entry['timestamp'] ?? current_time('mysql'),
            'trigger' => 'order_processing',
            'cid' => 'order_' . ($first_entry['order_id'] ?? 0),
        ];

        // Process each entry to extract payload components
        foreach ($order_entries as $entry) {
            $payload_raw = $entry['payload'] ?? '';
            if (empty($payload_raw)) {
                continue;
            }

            $entry_details = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
            if (!is_array($entry_details)) {
                continue;
            }

            // Extract payload components if they exist (ProcessLogger entries)
            if (isset($entry_details['components']) && is_array($entry_details['components'])) {
                foreach ($entry_details['components'] as $component) {
                    if (!is_array($component)) {
                        continue;
                    }

                    // Apply debug filtering
                    if (!$include_debug && $this->is_debug_component($component)) {
                        continue;
                    }

                    $all_components[] = $component;
                }
            } else {
                // Handle non-ProcessLogger entries by creating synthetic components
                $synthetic_component = [
                    'kind' => 'order_event',
                    'label' => $entry['summary'] ?? ($entry['event_type'] ?? 'Event'),
                    'ts' => $entry['timestamp'] ?? current_time('mysql'),
                    'level' => $entry['status'] ?? 'info',
                    'data' => array_merge($entry_details, [
                        'event_type' => $entry['event_type'] ?? '',
                        'source' => $entry['source'] ?? '',
                        'log_id' => $entry['id'] ?? 0,
                    ]),
                ];

                // Apply debug filtering for synthetic components
                if (!$include_debug && isset($entry_details['level']) && $entry_details['level'] === 'debug') {
                    continue;
                }

                $all_components[] = $synthetic_component;
            }
        }

        // If no components after filtering, show appropriate message
        if (empty($all_components)) {
            if (!$include_debug) {
                return '<div class="odcm-empty-data">' . esc_html__('All order events filtered (debug mode disabled)', Odcm_Config::$text_domain) . '</div>';
            } else {
                return '<div class="odcm-empty-data">' . esc_html__('No order components available', Odcm_Config::$text_domain) . '</div>';
            }
        }

        // Create the envelope structure for render_narrative_timeline
        $order_envelope = array_merge($envelope_meta, [
            'components' => $all_components,
        ]);

        // Use the existing narrative timeline rendering
        return $this->render_narrative_timeline($order_envelope, $include_debug);
    }

    /**
     * Detect if this log entry is part of a lifecycle group that should be rendered together
     *
     * @param array $log The individual log entry
     * @return array|null Array of related lifecycle entries or null if not part of a group
     */
    private function detect_lifecycle_group(array $log): ?array
    {
        // Only check for lifecycle groups if this entry has an empty payload but has order_id
        $payload_raw = $log['payload'] ?? '';
        $order_id = isset($log['order_id']) ? (int) $log['order_id'] : 0;

        if (!empty($payload_raw) || $order_id <= 0) {
            return null; // Not a candidate for lifecycle grouping
        }

        // Get process families to identify lifecycle event types
        if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
            require_once dirname(__DIR__) . '/Core/ProcessLifecycleDiscovery.php';
        }

        $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
        $families = $discovery->get_process_families();
        $lifecycle_family = $families['order_lifecycle'] ?? null;

        if (!$lifecycle_family || empty($lifecycle_family['process_types'])) {
            return null;
        }

        $lifecycle_types = array_values(array_unique(array_filter((array) ($lifecycle_family['process_types'] ?? []))));
        $event_type = $log['event_type'] ?? '';

        // Check if this entry's event type is part of the lifecycle family
        if (!in_array($event_type, $lifecycle_types, true)) {
            return null;
        }

        // Fetch all related lifecycle entries for this order within a time window
        try {
            global $wpdb;
            $log_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
            $payload_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$payload_table}'");

            // Get timestamp for time window calculation
            $timestamp = $log['timestamp'] ?? current_time('mysql');
            $time_window_minutes = isset($lifecycle_family['time_window_minutes']) ? (int)$lifecycle_family['time_window_minutes'] : 30;

            if ($payload_table_exists) {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               COALESCE(p.payload, l.details, '') as payload 
                        FROM {$log_table} l 
                        LEFT JOIN {$payload_table} p ON l.payload_id = p.payload_id
                        WHERE l.order_id = %d 
                        AND l.timestamp BETWEEN (STR_TO_DATE(%s, '%%Y-%%m-%%d %%H:%%i:%%s') - INTERVAL %d MINUTE) 
                        AND (STR_TO_DATE(%s, '%%Y-%%m-%%d %%H:%%i:%%s') + INTERVAL %d MINUTE)
                        ORDER BY l.timestamp ASC";
            } else {
                $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                               l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                               l.details as payload 
                        FROM {$log_table} l
                        WHERE l.order_id = %d 
                        AND l.timestamp BETWEEN (STR_TO_DATE(%s, '%%Y-%%m-%%d %%H:%%i:%%s') - INTERVAL %d MINUTE) 
                        AND (STR_TO_DATE(%s, '%%Y-%%m-%%d %%H:%%i:%%s') + INTERVAL %d MINUTE)
                        ORDER BY l.timestamp ASC";
            }

            $related_entries = $wpdb->get_results(
                $wpdb->prepare($sql, $order_id, $timestamp, $time_window_minutes, $timestamp, $time_window_minutes),
                'ARRAY_A'
            );

            if (!is_array($related_entries) || count($related_entries) <= 1) {
                return null; // No group found or only single entry
            }

            // Filter to only include lifecycle event types and entries with meaningful payloads
            $lifecycle_entries = [];
            foreach ($related_entries as $entry) {
                $entry_type = $entry['event_type'] ?? '';
                $entry_payload = $entry['payload'] ?? '';

                if (in_array($entry_type, $lifecycle_types, true) && !empty($entry_payload)) {
                    $lifecycle_entries[] = $entry;
                }
            }

            return count($lifecycle_entries) > 0 ? $lifecycle_entries : null;

        } catch (\Throwable $e) {
            error_log('ODCM: detect_lifecycle_group failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Render consolidated entry by processing its timeline events with enhanced payload extraction
     *
     * @param array $log The consolidated log entry
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the consolidated timeline
     */
    private function render_consolidated_entry(array $log, bool $include_debug = false): string
    {
        error_log("ODCM CONSOLIDATED: render_consolidated_entry called for log ID: " . ($log['id'] ?? 'unknown'));
        
        // Extract timeline events from consolidation data
        $timeline_events = $log['consolidation_data']['timeline_events'] ?? [];

        if (empty($timeline_events) || !is_array($timeline_events)) {
            error_log("ODCM CONSOLIDATED: No timeline events found");
            return '<div class="odcm-empty-data">' . esc_html__('No timeline data available for consolidated entry', Odcm_Config::$text_domain) . '</div>';
        }

        error_log("ODCM CONSOLIDATED: Processing " . count($timeline_events) . " timeline events");

        // Enhanced payload component extraction with robust error handling
        $all_components = [];
        $processed_events = 0;
        $failed_events = 0;
        $total_components_extracted = 0;

        foreach ($timeline_events as $event_index => $event) {
            $processed_events++;
            $event_id = $event['id'] ?? "event_$event_index";
            
            try {
                // Extract components from this specific timeline event
                $event_components = $this->extract_components_from_timeline_event($event, $include_debug, $event_index);
                
                if (!empty($event_components)) {
                    $all_components = array_merge($all_components, $event_components);
                    $total_components_extracted += count($event_components);
                    error_log("ODCM CONSOLIDATED: Extracted " . count($event_components) . " components from event $event_id");
                } else {
                    error_log("ODCM CONSOLIDATED: No components extracted from event $event_id");
                }
                
            } catch (\Throwable $e) {
                $failed_events++;
                error_log("ODCM CONSOLIDATED: Failed to process timeline event $event_id: " . $e->getMessage());
                
                // Create a fallback component for this failed event to maintain timeline continuity
                $fallback_component = $this->create_fallback_component_for_event($event, $event_index);
                if ($fallback_component) {
                    $all_components[] = $fallback_component;
                    $total_components_extracted++;
                }
            }
        }

        error_log("ODCM CONSOLIDATED: Extraction complete - Total components: $total_components_extracted, Failed events: $failed_events/$processed_events");

        // If no components after processing, provide meaningful fallback
        if (empty($all_components)) {
            if (!$include_debug) {
                error_log("ODCM CONSOLIDATED: No components after debug filtering");
                return '<div class="odcm-empty-data">' . esc_html__('All timeline events filtered (debug mode disabled)', Odcm_Config::$text_domain) . '</div>';
            } else {
                error_log("ODCM CONSOLIDATED: No components available at all");
                // Last resort: show simple timeline events
                return $this->render_simple_timeline_events($timeline_events, $include_debug);
            }
        }

        // Sort all components chronologically for proper timeline ordering
        usort($all_components, function($a, $b) {
            $ts_a = strtotime($a['ts'] ?? '');
            $ts_b = strtotime($b['ts'] ?? '');
            return $ts_a <=> $ts_b;
        });

        error_log("ODCM CONSOLIDATED: Rendering " . count($all_components) . " components in chronological order");

        // Render using the enhanced component rendering pipeline
        return $this->render_consolidated_component_timeline($all_components, $log, $include_debug);
    }

    /**
     * Render a lifecycle group by combining all related entries into a unified timeline
     *
     * @param array $lifecycle_entries Array of related lifecycle log entries
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the lifecycle group timeline
     */
    private function render_lifecycle_group(array $lifecycle_entries, bool $include_debug = false): string
    {
        if (empty($lifecycle_entries)) {
            return '<div class="odcm-empty-data">' . esc_html__('No lifecycle entries found', Odcm_Config::$text_domain) . '</div>';
        }

        // Extract all payload components from the lifecycle entries
        $all_components = [];
        $first_entry = $lifecycle_entries[0];
        $envelope_meta = [
            'type' => 'lifecycle_group',
            'order_id' => $first_entry['order_id'] ?? 0,
            'ts' => $first_entry['timestamp'] ?? current_time('mysql'),
            'trigger' => 'order_lifecycle',
            'cid' => 'order_' . ($first_entry['order_id'] ?? 0),
        ];

        // Process each lifecycle entry to extract payload components
        foreach ($lifecycle_entries as $entry) {
            $payload_raw = $entry['payload'] ?? '';
            if (empty($payload_raw)) {
                continue;
            }

            $entry_details = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
            if (!is_array($entry_details)) {
                continue;
            }

            // Extract components if they exist
            if (isset($entry_details['components']) && is_array($entry_details['components'])) {
                foreach ($entry_details['components'] as $component) {
                    if (!is_array($component)) {
                        continue;
                    }

                    // Apply debug filtering
                    if (!$include_debug && $this->is_debug_component($component)) {
                        continue;
                    }

                    $all_components[] = $component;
                }
            } else {
                // Handle non-ProcessLogger entries by creating synthetic components
                $synthetic_component = [
                    'kind' => 'lifecycle_event',
                    'label' => $entry['summary'] ?? ($entry['event_type'] ?? 'Event'),
                    'ts' => $entry['timestamp'] ?? current_time('mysql'),
                    'level' => $entry['status'] ?? 'info',
                    'data' => array_merge($entry_details, [
                        'event_type' => $entry['event_type'] ?? '',
                        'source' => $entry['source'] ?? '',
                        'log_id' => $entry['id'] ?? 0,
                    ]),
                ];

                // Apply debug filtering for synthetic components
                if (!$include_debug && isset($entry_details['level']) && $entry_details['level'] === 'debug') {
                    continue;
                }

                $all_components[] = $synthetic_component;
            }
        }

        // If no components after filtering, show appropriate message
        if (empty($all_components)) {
            if (!$include_debug) {
                return '<div class="odcm-empty-data">' . esc_html__('All lifecycle events filtered (debug mode disabled)', Odcm_Config::$text_domain) . '</div>';
            } else {
                return '<div class="odcm-empty-data">' . esc_html__('No lifecycle components available', Odcm_Config::$text_domain) . '</div>';
            }
        }

        // Create the envelope structure for render_narrative_timeline
        $lifecycle_envelope = array_merge($envelope_meta, [
            'components' => $all_components,
        ]);

        // Use the existing narrative timeline rendering
        return $this->render_narrative_timeline($lifecycle_envelope, $include_debug);
    }

    /**
     * Check if this has components
     *
     * @param array $details
     * @return bool
     */
    private function is_process_logger_entry(array $details): bool
    {
        return isset($details['components']) && is_array($details['components']) && !empty($details['components']);
    }

    /**
     * Check if this is a debug-only process based on existing metadata
     *
     * @param array $details
     * @return bool
     */
    private function is_debug_only_process(array $details): bool
    {
        $source = isset($details['source']) ? (string)$details['source'] : '';
        if ($source !== '' && strpos($source, 'debug_') === 0) {
            return true;
        }

        $type = isset($details['type']) ? (string)$details['type'] : '';
        $debug_only_types = ['debug_info_dump', 'system_diagnostics', 'performance_profiling'];
        if ($type !== '' && in_array($type, $debug_only_types, true)) {
            return true;
        }

        $components = isset($details['components']) && is_array($details['components'])
            ? $details['components']
            : [];
        if (!empty($components)) {
            foreach ($components as $component) {
                if (!is_array($component)) { continue; }
                if (!$this->is_debug_component($component)) {
                    // Found a non-debug component -> not debug-only
                    return false;
                }
            }
            // No non-debug components found -> debug-only
            return true;
        }

        return false;
    }

    /**
     * Embed context components into primary events based on temporal proximity and relevance.
     *
     * This method associates context-only components (info, action_executed, attribution, etc.)
     * with their most relevant primary events (status_change, rule_evaluated, etc.) to create
     * enriched timeline items that contain all related context data.
     *
     * Uses direct renderContent() calls to generate context content without wrappers.
     *
     * @param array $primary_events Array of primary timeline events
     * @param array $context_components Array of context-only components to embed
     * @return array Array of enriched events with embedded context content
     */
    private function embedContextIntoEvents(array $primary_events, array $context_components): array
    {
        if (empty($context_components)) {
            return $primary_events;
        }

        // Load registry for renderer lookup
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }

        $enriched_events = [];

        foreach ($primary_events as $event) {
            $event_ts = strtotime($event['ts'] ?? '');
            $event_kind = $event['kind'] ?? '';

            // Find context components that should be embedded in this event
            $relevant_context = [];
            $context_content = [];

            foreach ($context_components as $context) {
                $context_ts = strtotime($context['ts'] ?? '');
                $context_kind = $context['kind'] ?? '';
                $context_data = is_array($context['data'] ?? null) ? $context['data'] : [];

                // Embed context based on temporal proximity and logical relevance
                $should_embed = false;

                // Always embed if timestamps are very close (within 1 second)
                if (abs($event_ts - $context_ts) <= 1) {
                    $should_embed = true;
                }
                // Embed info and action_executed into status changes
                elseif ($event_kind === 'status_change' && in_array($context_kind, ['info', 'action_executed'])) {
                    // Embed if context is within 5 seconds of the status change
                    if (abs($event_ts - $context_ts) <= 5) {
                        $should_embed = true;
                    }
                }
                // Embed attribution data into any primary event within 10 seconds
                elseif ($context_kind === 'attribution' && abs($event_ts - $context_ts) <= 10) {
                    $should_embed = true;
                }
                // Embed performance data into any primary event within 2 seconds
                elseif ($context_kind === 'performance' && abs($event_ts - $context_ts) <= 2) {
                    $should_embed = true;
                }

                if ($should_embed) {
                    $relevant_context[] = $context;

                    // Generate context content using direct renderContent() call
                    $context_html = $this->generateContextContent($context_kind, $context_data);
                    if (!empty($context_html)) {
                        $context_content[] = $context_html;
                    }
                }
            }

            // Add embedded context content to the event data for the primary renderer
            if (!empty($context_content)) {
                $event['embedded_context_content'] = $context_content;
            }

            $enriched_events[] = $event;
        }

        return $enriched_events;
    }

    /**
     * Generate context content using direct renderContent() calls.
     *
     * This method looks up the appropriate renderer for a context component
     * and calls its renderContent() method directly to get content without wrapper.
     *
     * @param string $kind The component kind (info, action_executed, etc.)
     * @param array $data The component data
     * @return string HTML content for embedding within primary events
     */
    private function generateContextContent(string $kind, array $data): string
    {
        // Load registry for renderer lookup
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }

        $def = function_exists('odcm_get_payload_component_type') ? \odcm_get_payload_component_type($kind) : null;

        if (is_array($def) && isset($def['renderer_class'])) {
            $renderer_class = $def['renderer_class'];
            if (strpos($renderer_class, '\\') === false) {
                $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
            }

            if (class_exists($renderer_class)) {
                try {
                    $renderer = new $renderer_class();

                    // Use direct renderContent() call to get content without wrapper
                    if (method_exists($renderer, 'renderContent')) {
                        return $renderer->renderContent($data);
                    }
                } catch (\Throwable $e) {
                    error_log('ODCM generateContextContent: Renderer error for ' . $renderer_class . ': ' . $e->getMessage());
                }
            }
        }

        // No renderer or no renderContent method: return empty to avoid inconsistent inline rendering
        return '';
    }

    /**
     * Render a context component without its timeline item wrapper.
     *
     * This method renders context-only components (like info or action_executed)
     * as compact HTML fragments that can be embedded within primary timeline events.
     *
     * @param string $kind The component kind (info, action_executed, etc.)
     * @param array $data The component data
     * @return string HTML fragment for the context component
     */
    private function renderContextComponent(string $kind, array $data): string
    {
        // Load registry for renderer lookup
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }

        $def = function_exists('odcm_get_payload_component_type') ? \odcm_get_payload_component_type($kind) : null;

        if (is_array($def) && isset($def['renderer_class'])) {
            $renderer_class = $def['renderer_class'];
            if (strpos($renderer_class, '\\') === false) {
                $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
            }

            if (class_exists($renderer_class)) {
                try {
                    $renderer = new $renderer_class();

                    // Try to use embedded content rendering if available
                    if (method_exists($renderer, 'renderEmbeddedContent')) {
                        return $renderer->renderEmbeddedContent($data);
                    }
                    // Fall back to regular rendering
                    elseif (method_exists($renderer, 'render')) {
                        return $renderer->render($data);
                    }
                } catch (\Throwable $e) {
                    error_log('ODCM renderContextComponent: Renderer error for ' . $renderer_class . ': ' . $e->getMessage());
                }
            }
        }

        // Fallback: render as simple key-value pairs
        if (empty($data)) {
            return '';
        }

        $html = '<div class="odcm-context-data">';
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $html .= '<span class="odcm-context-item">';
                $html .= '<strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong> ';
                $html .= esc_html((string)$value);
                $html .= '</span> ';
            }
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Diagnostic endpoint for route verification (debug mode only)
     *
     * Provides system information to help troubleshoot 404 errors and route registration issues.
     * Only available when ODCM_DEBUG is enabled.
     */
    public function diagnostic_check(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();
        $current_time = current_time('mysql');

        // Basic system information
        $diagnostic_data = [
            'timestamp' => $current_time,
            'plugin_version' => 'Free Version',
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'debug_mode' => defined('ODCM_DEBUG') && ODCM_DEBUG,

            // User information
            'user' => [
                'id' => $user->ID,
                'login' => $user->user_login,
                'roles' => $user->roles ?? [],
                'capabilities' => [
                    'manage_woocommerce' => current_user_can('manage_woocommerce'),
                    'delete_posts' => current_user_can('delete_posts'),
                ],
            ],

            // Route information
            'routes' => [
                'namespace' => self::NAMESPACE,
                'base_route' => self::BASE_ROUTE,
                'full_route' => '/' . self::NAMESPACE . '/' . self::BASE_ROUTE,
                'rest_url' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE),
            ],

            // Database information
            'database' => [
                'audit_log_table' => $this->check_table_exists('odcm_audit_log'),
                'payload_table' => $this->check_table_exists('odcm_audit_log_payloads'),
            ],

            // Function availability
            'functions' => [
                'odcm_can_use' => function_exists('odcm_can_use'),
                'odcm_log_event' => function_exists('odcm_log_event'),
                'rest_url' => function_exists('rest_url'),
                'current_user_can' => function_exists('current_user_can'),
            ],

            // Server environment
            'server' => [
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'http_host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ],
        ];

        // Test basic API functionality
        try {
            $test_logs = $this->get_all_filtered_logs($request);
            $diagnostic_data['api_test'] = [
                'get_all_filtered_logs' => 'success',
                'log_count' => is_array($test_logs) ? count($test_logs) : 0,
            ];
        } catch (\Throwable $e) {
            $diagnostic_data['api_test'] = [
                'get_all_filtered_logs' => 'error',
                'error_message' => $e->getMessage(),
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'ODCM Free Version Diagnostic Check',
            'data' => $diagnostic_data,
        ], 200);
    }

    /**
     * Filter consolidated results to show only consolidated entries and non-consolidated individuals
     *
     * The consolidation service returns a mixed array of individual and consolidated entries.
     * This method filters out individual entries that were consolidated and only keeps:
     * 1. Consolidated entries (which represent groups)
     * 2. Individual entries that were NOT part of any consolidation
     *
     * @param array $consolidated_logs Output from LogConsolidationService::consolidate_logs_for_display()
     * @param array $original_logs Original logs before consolidation
     * @return array Filtered array with only consolidated entries and non-consolidated individuals
     */
    private function filter_consolidated_results(array $consolidated_logs, array $original_logs): array
    {
        if (empty($consolidated_logs)) {
            return [];
        }

        // Build a set of all log IDs that were consolidated (appear in timeline_events)
        $consolidated_log_ids = [];
        
        foreach ($consolidated_logs as $entry) {
            if (isset($entry['consolidation_data']['is_consolidated']) && 
                $entry['consolidation_data']['is_consolidated'] === true &&
                isset($entry['consolidation_data']['timeline_events']) &&
                is_array($entry['consolidation_data']['timeline_events'])) {
                
                // Collect all individual log IDs that are part of this consolidated entry
                foreach ($entry['consolidation_data']['timeline_events'] as $timeline_event) {
                    if (isset($timeline_event['id'])) {
                        $consolidated_log_ids[(int)$timeline_event['id']] = true;
                    }
                }
            }
        }

        // Debug logging to understand what's happening
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: filter_consolidated_results - Input count: ' . count($consolidated_logs));
            error_log('ODCM: filter_consolidated_results - Consolidated IDs: ' . json_encode(array_keys($consolidated_log_ids)));
            
            $consolidated_count = 0;
            $individual_count = 0;
            foreach ($consolidated_logs as $entry) {
                $is_consolidated = isset($entry['consolidation_data']['is_consolidated']) && 
                                  $entry['consolidation_data']['is_consolidated'] === true;
                if ($is_consolidated) {
                    $consolidated_count++;
                    error_log('ODCM: Consolidated entry ID: ' . ($entry['id'] ?? 'none') . ' Summary: ' . ($entry['summary'] ?? 'none'));
                } else {
                    $individual_count++;
                    error_log('ODCM: Individual entry ID: ' . ($entry['id'] ?? 'none') . ' Summary: ' . ($entry['summary'] ?? 'none'));
                }
            }
            error_log('ODCM: filter_consolidated_results - Consolidated entries: ' . $consolidated_count . ', Individual entries: ' . $individual_count);
        }

        // Filter the consolidated results to only include:
        // 1. Consolidated entries (is_consolidated = true)
        // 2. Individual entries whose IDs are NOT in the consolidated set
        $filtered_results = [];
        
        foreach ($consolidated_logs as $entry) {
            $entry_id = $entry['id'] ?? '';
            $is_consolidated = isset($entry['consolidation_data']['is_consolidated']) && 
                              $entry['consolidation_data']['is_consolidated'] === true;
            
            if ($is_consolidated) {
                // Always include consolidated entries (they have unique IDs like 'consolidated_123')
                $filtered_results[] = $entry;
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM: Including consolidated entry ID: ' . $entry_id);
                }
            } else {
                // For individual entries, check if their numeric ID was consolidated
                $numeric_id = is_numeric($entry_id) ? (int)$entry_id : 0;
                if ($numeric_id > 0 && !isset($consolidated_log_ids[$numeric_id])) {
                    // Include individual entries that were NOT consolidated
                    $filtered_results[] = $entry;
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        error_log('ODCM: Including individual entry ID: ' . $entry_id);
                    }
                } else {
                    // Skip individual entries that were consolidated
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        error_log('ODCM: Skipping consolidated individual entry ID: ' . $entry_id);
                    }
                }
            }
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: filter_consolidated_results - Output count: ' . count($filtered_results));
        }

        return $filtered_results;
    }

    /**
     * Render simple timeline events when no payload components are available
     *
     * This creates a basic timeline view showing all events in chronological order
     * when the consolidated entry doesn't have extractable payload components.
     *
     * @param array $timeline_events Array of timeline events from consolidation data
     * @param bool $include_debug Whether to include debug events
     * @return string HTML output for the simple timeline
     */
    private function render_simple_timeline_events(array $timeline_events, bool $include_debug = false): string
    {
        if (empty($timeline_events)) {
            return '<div class="odcm-empty-data">' . esc_html__('No timeline events available', Odcm_Config::$text_domain) . '</div>';
        }

        // Filter events based on debug setting
        $filtered_events = [];
        foreach ($timeline_events as $event) {
            // Apply debug filtering
            if (!$include_debug) {
                $summary = strtolower($event['summary'] ?? '');
                $event_type = strtolower($event['event_type'] ?? '');
                $source = strtolower($event['source'] ?? '');
                
                // Skip debug-related events
                if (strpos($summary, 'debug') !== false || 
                    strpos($event_type, 'debug') !== false || 
                    strpos($source, 'debug') !== false ||
                    $event['status'] === 'debug') {
                    continue;
                }
            }
            
            $filtered_events[] = $event;
        }

        if (empty($filtered_events)) {
            if (!$include_debug) {
                return '<div class="odcm-empty-data">' . esc_html__('All timeline events filtered (debug mode disabled)', Odcm_Config::$text_domain) . '</div>';
            } else {
                return '<div class="odcm-empty-data">' . esc_html__('No timeline events available', Odcm_Config::$text_domain) . '</div>';
            }
        }

        // Sort events chronologically
        usort($filtered_events, function($a, $b) {
            return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
        });

        // Load UI toolkit for consistent rendering
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        }
        $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();

        $html = '<div class="odcm-narrative-timeline">';
        $html .= '<div class="odcm-simple-timeline">';

        foreach ($filtered_events as $event) {
            $timestamp = $event['timestamp'] ?? '';
            $status = $event['status'] ?? 'info';
            $summary = $event['summary'] ?? 'Event';
            $event_type = $event['event_type'] ?? '';
            $source = $event['source'] ?? '';

            // Format timestamp for display
            $formatted_time = '';
            if (!empty($timestamp)) {
                try {
                    $time = new \DateTime($timestamp);
                    $formatted_time = $time->format('H:i:s');
                } catch (\Exception $e) {
                    $formatted_time = $timestamp;
                }
            }

            // Create event content
            $event_content = '<div class="odcm-simple-event-header">';
            $event_content .= '<span class="odcm-event-time">' . esc_html($formatted_time) . '</span>';
            $event_content .= '<span class="odcm-event-summary">' . esc_html($summary) . '</span>';
            $event_content .= '</div>';

            if (!empty($event_type) || !empty($source)) {
                $event_content .= '<div class="odcm-simple-event-meta">';
                if (!empty($event_type)) {
                    $event_content .= '<span class="odcm-event-type">' . esc_html(ucfirst(str_replace('_', ' ', $event_type))) . '</span>';
                }
                if (!empty($source)) {
                    $event_content .= '<span class="odcm-event-source">(' . esc_html($source) . ')</span>';
                }
                $event_content .= '</div>';
            }

            // Render using toolkit for consistent styling
            $html .= $toolkit->render_component_shell(
                '', // No title for individual events
                'simple_event',
                $event_content,
                ['status' => $status]
            );
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if this is a custom error event that should use specialized rendering
     *
     * @param array $details The payload details
     * @return bool True if this is a custom error event
     */
    private function is_custom_error_event(array $details): bool
    {
        // Check if this has error indicators
        $status = $details['status'] ?? '';
        $summary = $details['summary'] ?? '';
        
        if ($status === 'error') {
            return true;
        }
        
        if (strpos(strtolower($summary), 'failed') !== false) {
            return true;
        }
        
        if (strpos(strtolower($summary), 'error') !== false) {
            return true;
        }
        
        // Check if any payload components indicate an error
        $components = $details['components'] ?? [];
        if (is_array($components)) {
            foreach ($components as $component) {
                if (!is_array($component)) { continue; }
                
                $level = $component['level'] ?? '';
                $label = $component['label'] ?? '';
                
                if ($level === 'error') {
                    return true;
                }
                
                if (strpos(strtolower($label), 'failed') !== false || 
                    strpos(strtolower($label), 'error') !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Render custom error event with simplified component-based rendering
     *
     * @param array $details The payload details  
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the custom error event
     */
    private function render_custom_error_event(array $details, bool $include_debug = false): string
    {
        error_log("ODCM CUSTOM: render_custom_error_event called");
        
        // Load UI toolkit for consistent rendering
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        }
        $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();

        $html = '<div class="odcm-narrative-timeline">';
        
        // Render envelope summary first
        $summary_content = '<div class="odcm-error-summary">';
        $summary_content .= '<h4>Error Details</h4>';
        
        if (isset($details['summary'])) {
            $summary_content .= '<p><strong>Summary:</strong> ' . esc_html($details['summary']) . '</p>';
        }
        
        if (isset($details['status'])) {
            $summary_content .= '<p><strong>Status:</strong> <span class="odcm-status-' . esc_attr($details['status']) . '">' . esc_html(ucfirst($details['status'])) . '</span></p>';
        }
        
        if (isset($details['ts'])) {
            // Handle Unix timestamp display
            $timestamp_display = is_numeric($details['ts']) 
                ? date('Y-m-d H:i:s', (int)$details['ts']) 
                : (string)$details['ts'];
            $summary_content .= '<p><strong>Occurred At:</strong> ' . esc_html($timestamp_display) . '</p>';
        }
        
        if (isset($details['cid'])) {
            $summary_content .= '<p><strong>Correlation ID:</strong> <code>' . esc_html($details['cid']) . '</code></p>';
        }
        
        $summary_content .= '</div>';
        
        $html .= $toolkit->render_component_shell(
            'Error Event',
            'error_summary', 
            $summary_content,
            ['status' => $details['status'] ?? 'error']
        );

        // Render individual components
        $components = $details['components'] ?? [];
        if (is_array($components) && !empty($components)) {
            // Load registry for renderer lookup
            if (!function_exists('odcm_get_payload_component_type')) {
                require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
            }

            foreach ($components as $component) {
                if (!is_array($component)) { continue; }
                
                // Apply debug filtering
                if (!$include_debug && $this->is_debug_component($component)) {
                    continue;
                }
                
                $kind = sanitize_key($component['kind'] ?? 'info');
                $label = (string) ($component['label'] ?? ucfirst($kind));
                $level = sanitize_key($component['level'] ?? 'info');
                $data = is_array($component['data'] ?? null) ? $component['data'] : [];

                // Skip rendering if data is empty
                if (empty($data)) {
                    continue;
                }

                // Lookup registry for renderer
                $def = function_exists('odcm_get_payload_component_type') ? \odcm_get_payload_component_type($kind) : null;

                $renderer_html = '';
                if (is_array($def) && isset($def['renderer_class'])) {
                    $renderer_class = $def['renderer_class'];
                    if (strpos($renderer_class, '\\') === false) {
                        $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
                    }
                    if (class_exists($renderer_class)) {
                        try {
                            $renderer = new $renderer_class();
                            if (method_exists($renderer, 'render')) {
                                $renderer_html = $renderer->render($data);
                            }
                        } catch (\Throwable $e) {
                            error_log('ODCM CustomError: Renderer error for ' . $renderer_class . ': ' . $e->getMessage());
                        }
                    }
                }

                // Use UIToolkit for consistent shell if no renderer or renderer failed
                if (empty($renderer_html)) {
                    $content = '<div class="odcm-component-data">';
                    foreach ($data as $key => $value) {
                        if (is_scalar($value)) {
                            $content .= '<p><strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html((string)$value) . '</p>';
                        } elseif (is_array($value)) {
                            $content .= '<p><strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong></p>';
                            $content .= '<pre>' . esc_html(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
                        }
                    }
                    $content .= '</div>';
                    
                    $renderer_html = $toolkit->render_component_shell(
                        $label,
                        $kind,
                        $content,
                        ['status' => $level]
                    );
                }

                $html .= $renderer_html;
            }
        }

        $html .= '</div>';
        
        error_log("ODCM CUSTOM: Generated " . strlen($html) . " characters of custom error HTML");
        
        return $html;
    }

    /**
     * Extract components from a single timeline event with robust error handling
     *
     * @param array $event The timeline event to process
     * @param bool $include_debug Whether to include debug components
     * @param int $event_index Index of the event for logging purposes
     * @return array Array of extracted components
     */
    private function extract_components_from_timeline_event(array $event, bool $include_debug, int $event_index): array
    {
        $event_id = $event['id'] ?? "event_$event_index";
        $components = [];
        
        try {
            $payload_raw = $event['payload'] ?? '';
            
            // Skip events with no payload
            if (empty($payload_raw)) {
                error_log("ODCM EXTRACT: Event $event_id has empty payload, creating synthetic component");
                return $this->create_synthetic_event_component($event, $event_index);
            }
            
            // Parse payload JSON with error handling
            $event_details = is_string($payload_raw) ? json_decode($payload_raw, true) : null;
            if (!is_array($event_details)) {
                error_log("ODCM EXTRACT: Event $event_id payload is not valid JSON, creating synthetic component");
                return $this->create_synthetic_event_component($event, $event_index);
            }
            
            // Extract ProcessLogger components (preferred method)
            if (isset($event_details['components']) && is_array($event_details['components'])) {
                error_log("ODCM EXTRACT: Event $event_id has " . count($event_details['components']) . " components");
                
                foreach ($event_details['components'] as $component_index => $component) {
                    if (!is_array($component)) {
                        error_log("ODCM EXTRACT: Event $event_id component $component_index is not an array, skipping");
                        continue;
                    }
                    
                    // Apply debug filtering
                    if (!$include_debug && $this->is_debug_component($component)) {
                        continue;
                    }
                    
                    // Enrich component with source event metadata
                    $enriched_component = $this->enrich_component_with_event_metadata($component, $event, $event_index);
                    $components[] = $enriched_component;
                }
                
                error_log("ODCM EXTRACT: Event $event_id contributed " . count($components) . " components after filtering");
                return $components;
            }
            
            // Fallback: create synthetic component from event details
            error_log("ODCM EXTRACT: Event $event_id has no components, creating synthetic component");
            return $this->create_synthetic_event_component($event, $event_index, $event_details);
            
        } catch (\Throwable $e) {
            error_log("ODCM EXTRACT: Exception processing event $event_id: " . $e->getMessage());
            // Return fallback component to maintain timeline continuity
            return $this->create_synthetic_event_component($event, $event_index);
        }
    }
    
    /**
     * Create synthetic component from timeline event when payload components aren't available
     *
     * @param array $event The timeline event
     * @param int $event_index Event index
     * @param array|null $event_details Parsed event details if available
     * @return array Array containing single synthetic component
     */
    private function create_synthetic_event_component(array $event, int $event_index, ?array $event_details = null): array
    {
        $event_id = $event['id'] ?? "event_$event_index";
        $summary = $event['summary'] ?? 'Timeline Event';
        $timestamp = $event['timestamp'] ?? current_time('mysql');
        $status = $event['status'] ?? 'info';
        $event_type = $event['event_type'] ?? 'unknown';
        $source = $event['source'] ?? 'system';
        
        // Determine appropriate component kind based on event characteristics
        $kind = $this->determine_synthetic_component_kind($event, $event_details);
        
        // Build component data from available event information
        $data = [
            'event_summary' => $summary,
            'event_type' => $event_type,
            'source' => $source,
            'log_id' => $event_id,
            'original_status' => $status,
        ];
        
        // Include parsed details if available
        if (is_array($event_details) && !empty($event_details)) {
            $data['event_details'] = $event_details;
        }
        
        // Add order context if available
        if (!empty($event['order_id'])) {
            $data['order_id'] = (int) $event['order_id'];
        }
        
        $synthetic_component = [
            'kind' => $kind,
            'label' => $summary,
            'ts' => $timestamp,
            'level' => $status,
            'data' => $data,
            'source_event_id' => $event_id,
            'is_synthetic' => true,
        ];
        
        error_log("ODCM EXTRACT: Created synthetic component of kind '$kind' for event $event_id");
        
        return [$synthetic_component];
    }
    
    /**
     * Determine appropriate component kind for synthetic components
     *
     * @param array $event Timeline event
     * @param array|null $event_details Parsed event details
     * @return string Component kind
     */
    private function determine_synthetic_component_kind(array $event, ?array $event_details): string
    {
        $event_type = strtolower($event['event_type'] ?? '');
        $summary = strtolower($event['summary'] ?? '');
        $status = strtolower($event['status'] ?? '');
        
        // Map event types to appropriate component kinds
        if (strpos($event_type, 'status') !== false || strpos($summary, 'status') !== false) {
            return 'status_changed';
        }
        
        if (strpos($event_type, 'payment') !== false || strpos($summary, 'payment') !== false) {
            return 'stripe_event'; // or 'paypal_event' based on details
        }
        
        if (strpos($event_type, 'order') !== false || strpos($summary, 'order') !== false) {
            return 'order_loaded';
        }
        
        if (strpos($event_type, 'rule') !== false || strpos($summary, 'rule') !== false) {
            return 'rule_evaluated';
        }
        
        if ($status === 'error' || strpos($summary, 'error') !== false || strpos($summary, 'failed') !== false) {
            return 'error';
        }
        
        if (strpos($event_type, 'webhook') !== false || strpos($summary, 'webhook') !== false) {
            return 'http_webhook';
        }
        
        // Default to info for unrecognized events
        return 'info';
    }
    
    /**
     * Enrich component with metadata from source event
     *
     * @param array $component Original component
     * @param array $event Source timeline event
     * @param int $event_index Event index
     * @return array Enriched component
     */
    private function enrich_component_with_event_metadata(array $component, array $event, int $event_index): array
    {
        // Preserve original component structure
        $enriched = $component;
        
        // Add source event metadata for traceability
        $enriched['source_event'] = [
            'id' => $event['id'] ?? "event_$event_index",
            'summary' => $event['summary'] ?? '',
            'event_type' => $event['event_type'] ?? '',
            'source' => $event['source'] ?? '',
            'timestamp' => $event['timestamp'] ?? '',
        ];
        
        // Ensure timestamp is set (prefer component timestamp, fallback to event timestamp)
        if (empty($enriched['ts']) && !empty($event['timestamp'])) {
            $enriched['ts'] = $event['timestamp'];
        }
        
        // Add order context if available in event but not in component
        if (empty($enriched['data']['order_id']) && !empty($event['order_id'])) {
            if (!isset($enriched['data'])) {
                $enriched['data'] = [];
            }
            $enriched['data']['order_id'] = (int) $event['order_id'];
        }
        
        return $enriched;
    }
    
    /**
     * Create fallback component for failed event processing
     *
     * @param array $event Timeline event that failed to process
     * @param int $event_index Event index
     * @return array|null Fallback component or null if event data is too minimal
     */
    private function create_fallback_component_for_event(array $event, int $event_index): ?array
    {
        $event_id = $event['id'] ?? "event_$event_index";
        $summary = $event['summary'] ?? 'Processing failed for timeline event';
        
        // Only create fallback if we have some meaningful data
        if (empty($summary) && empty($event['event_type'])) {
            error_log("ODCM FALLBACK: Event $event_id has no meaningful data, skipping fallback");
            return null;
        }
        
        $fallback_component = [
            'kind' => 'error',
            'label' => 'Event Processing Error',
            'ts' => $event['timestamp'] ?? current_time('mysql'),
            'level' => 'warning',
            'data' => [
                'message' => 'Failed to process timeline event',
                'original_summary' => $summary,
                'event_type' => $event['event_type'] ?? 'unknown',
                'source' => $event['source'] ?? 'unknown',
                'event_id' => $event_id,
                'processing_error' => 'Component extraction failed',
            ],
            'source_event_id' => $event_id,
            'is_fallback' => true,
        ];
        
        error_log("ODCM FALLBACK: Created fallback component for failed event $event_id");
        
        return $fallback_component;
    }
    
    /**
     * Render consolidated component timeline using registry-driven renderers
     *
     * @param array $all_components All extracted and sorted components
     * @param array $log Original consolidated log entry
     * @param bool $include_debug Whether debug components are included
     * @return string HTML output for the consolidated timeline
     */
    private function render_consolidated_component_timeline(array $all_components, array $log, bool $include_debug): string
    {
        error_log("ODCM RENDER_CONSOLIDATED: Starting render with " . count($all_components) . " components");
        
        // Load required dependencies
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }
        
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once dirname(__DIR__) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        }
        
        $html = '<div class="odcm-narrative-timeline">';
        
        // Group components by proximity and relevance for better rendering
        $grouped_components = $this->group_components_for_rendering($all_components);
        $rendered_count = 0;
        
        foreach ($grouped_components as $group_index => $group) {
            $primary_component = $group['primary'];
            $context_components = $group['context'] ?? [];
            
            $kind = sanitize_key($primary_component['kind'] ?? 'info');
            $label = (string) ($primary_component['label'] ?? ucfirst($kind));
            $ts = (string) ($primary_component['ts'] ?? '');
            $level = sanitize_key($primary_component['level'] ?? 'info');
            $data = is_array($primary_component['data'] ?? null) ? $primary_component['data'] : [];
            
            // Skip rendering if data is empty
            if (empty($data)) {
                error_log("ODCM RENDER_CONSOLIDATED: Skipping component $group_index ($kind) - empty data");
                continue;
            }
            
            // Embed context components into primary component data
            if (!empty($context_components)) {
                $data['embedded_context'] = $this->render_embedded_context_components($context_components);
                error_log("ODCM RENDER_CONSOLIDATED: Embedded " . count($context_components) . " context components into $kind");
            }
            
            // Lookup registry for renderer
            $def = function_exists('odcm_get_payload_component_type') ? \odcm_get_payload_component_type($kind) : null;
            $renderer_html = '';
            
            if (is_array($def) && isset($def['renderer_class'])) {
                $renderer_class = $def['renderer_class'];
                if (strpos($renderer_class, '\\') === false) {
                    $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
                }
                
                if (class_exists($renderer_class)) {
                    try {
                        $renderer = new $renderer_class();
                        
                        // Try different rendering methods in order of preference
                        if (method_exists($renderer, 'renderTimelineItem')) {
                            $renderer_html = $renderer->renderTimelineItem($kind, $label, $ts !== '' ? $ts : null, $level, $data);
                        } elseif (method_exists($renderer, 'render')) {
                            $renderer_html = $renderer->render($data);
                        }
                        
                        if (!empty($renderer_html)) {
                            $rendered_count++;
                            error_log("ODCM RENDER_CONSOLIDATED: Successfully rendered component $group_index ($kind) using $renderer_class");
                        }
                        
                    } catch (\Throwable $e) {
                        error_log("ODCM RENDER_CONSOLIDATED: Renderer error for $renderer_class: " . $e->getMessage());
                    }
                }
            }
            
            // Fallback rendering using UIToolkit
            if (empty($renderer_html)) {
                error_log("ODCM RENDER_CONSOLIDATED: Using fallback UIToolkit rendering for component $group_index ($kind)");
                $renderer_html = $this->render_component_using_ui_toolkit($kind, $label, $data, $level, $ts);
                if (!empty($renderer_html)) {
                    $rendered_count++;
                }
            }
            
            $html .= $renderer_html;
        }
        
        $html .= '</div>';
        
        error_log("ODCM RENDER_CONSOLIDATED: Completed rendering $rendered_count components, total HTML length: " . strlen($html));
        
        // If no components were successfully rendered, provide a meaningful fallback
        if ($rendered_count === 0) {
            error_log("ODCM RENDER_CONSOLIDATED: No components rendered successfully, using simple timeline fallback");
            $timeline_events = $log['consolidation_data']['timeline_events'] ?? [];
            return $this->render_simple_timeline_events($timeline_events, $include_debug);
        }
        
        return $html;
    }
    
    /**
     * Group components for optimal rendering by combining primary events with related context
     *
     * @param array $all_components All components to group
     * @return array Array of component groups with primary and context components
     */
    private function group_components_for_rendering(array $all_components): array
    {
        $groups = [];
        $context_components = [];
        
        // Separate primary components from context-only components
        foreach ($all_components as $component) {
            $kind = $component['kind'] ?? 'info';
            
            if ($this->isContextOnlyComponent($kind)) {
                $context_components[] = $component;
            } else {
                // Each primary component gets its own group
                $groups[] = [
                    'primary' => $component,
                    'context' => [],
                ];
            }
        }
        
        // Distribute context components to their most relevant primary components
        foreach ($context_components as $context) {
            $best_group_index = $this->find_best_group_for_context($context, $groups);
            if ($best_group_index !== null) {
                $groups[$best_group_index]['context'][] = $context;
            }
        }
        
        error_log("ODCM GROUP: Created " . count($groups) . " component groups, distributed " . count($context_components) . " context components");
        
        return $groups;
    }
    
    /**
     * Find the best primary component group for a context component
     *
     * @param array $context_component Context component to assign
     * @param array $groups Existing component groups
     * @return int|null Index of best group or null if no good match
     */
    private function find_best_group_for_context(array $context_component, array $groups): ?int
    {
        if (empty($groups)) {
            return null;
        }
        
        $context_ts = strtotime($context_component['ts'] ?? '');
        $context_kind = $context_component['kind'] ?? '';
        $best_score = -1;
        $best_index = null;
        
        foreach ($groups as $index => $group) {
            $primary = $group['primary'];
            $primary_ts = strtotime($primary['ts'] ?? '');
            $primary_kind = $primary['kind'] ?? '';
            
            $score = 0;
            
            // Time proximity scoring (closer is better)
            if ($context_ts > 0 && $primary_ts > 0) {
                $time_diff = abs($context_ts - $primary_ts);
                if ($time_diff <= 1) {
                    $score += 10; // Very close
                } elseif ($time_diff <= 5) {
                    $score += 5;  // Close
                } elseif ($time_diff <= 30) {
                    $score += 2;  // Somewhat close
                }
            }
            
            // Logical relevance scoring
            if ($context_kind === 'attribution' || $context_kind === 'performance') {
                $score += 3; // These are generally relevant to any primary event
            }
            
            if ($context_kind === 'info' && strpos($primary_kind, 'status') !== false) {
                $score += 5; // Info components are especially relevant to status changes
            }
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_index = $index;
            }
        }
        
        // Only assign if we found a reasonably good match
        return $best_score > 0 ? $best_index : 0; // Default to first group if no good match
    }
    
    /**
     * Render embedded context components as inline content
     *
     * @param array $context_components Array of context components
     * @return string HTML content for embedding
     */
    private function render_embedded_context_components(array $context_components): string
    {
        $embedded_html = '';
        
        foreach ($context_components as $context) {
            $kind = $context['kind'] ?? '';
            $data = is_array($context['data'] ?? null) ? $context['data'] : [];
            
            if (empty($data)) {
                continue;
            }
            
            // Use simplified rendering for context components
            $context_html = $this->generateContextContent($kind, $data);
            if (!empty($context_html)) {
                $embedded_html .= '<div class="odcm-embedded-context">' . $context_html . '</div>';
            }
        }
        
        return $embedded_html;
    }
    
    /**
     * Render component using UIToolkit as fallback when no specific renderer is available
     *
     * @param string $kind Component kind
     * @param string $label Component label
     * @param array $data Component data
     * @param string $level Component level
     * @param string $ts Component timestamp
     * @return string HTML output
     */
    private function render_component_using_ui_toolkit(string $kind, string $label, array $data, string $level, string $ts): string
    {
        $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
        
        // Build content based on data structure
        $content = '<div class="odcm-component-fallback">';
        
        // Show synthetic/fallback indicators
        if (!empty($data['is_synthetic'])) {
            $content .= '<p class="odcm-synthetic-notice"><em>Synthetic component (generated from event data)</em></p>';
        } elseif (!empty($data['is_fallback'])) {
            $content .= '<p class="odcm-fallback-notice"><em>Fallback component (processing error occurred)</em></p>';
        }
        
        // Render key data points
        foreach ($data as $key => $value) {
            // Skip metadata keys
            if (in_array($key, ['is_synthetic', 'is_fallback', 'source_event', 'embedded_context'], true)) {
                continue;
            }
            
            if (is_scalar($value) && $value !== '') {
                $formatted_key = ucfirst(str_replace('_', ' ', $key));
                $content .= '<p><strong>' . esc_html($formatted_key) . ':</strong> ' . esc_html((string)$value) . '</p>';
            } elseif (is_array($value) && !empty($value)) {
                $formatted_key = ucfirst(str_replace('_', ' ', $key));
                $content .= '<p><strong>' . esc_html($formatted_key) . ':</strong></p>';
                $content .= '<pre class="odcm-json-data">' . esc_html(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
            }
        }
        
        // Include embedded context if available
        if (!empty($data['embedded_context'])) {
            $content .= '<div class="odcm-context-section">';
            $content .= '<h5>Related Context</h5>';
            $content .= $data['embedded_context'];
            $content .= '</div>';
        }
        
        $content .= '</div>';
        
        // Determine appropriate theme based on component kind and level
        $theme = $this->determine_component_theme($kind, $level);
        
        return $toolkit->render_component_shell(
            $label,
            $theme,
            $content,
            ['timestamp' => $ts, 'status' => $level]
        );
    }
    
    /**
     * Determine appropriate theme for component rendering
     *
     * @param string $kind Component kind
     * @param string $level Component level
     * @return string Theme identifier
     */
    private function determine_component_theme(string $kind, string $level): string
    {
        // Level-based themes take priority for errors/warnings
        if ($level === 'error' || $kind === 'error') {
            return 'error';
        }
        
        if ($level === 'warning') {
            return 'warning';
        }
        
        // Kind-based themes
        $theme_map = [
            'status_changed' => 'woocommerce',
            'order_loaded' => 'woocommerce',
            'stock_adjusted' => 'woocommerce',
            'meta_updated' => 'woocommerce',
            'rule_evaluated' => 'rule',
            'stripe_event' => 'api',
            'paypal_event' => 'api',
            'http_webhook' => 'api',
            'email_action' => 'api',
            'database_query' => 'database',
            'metrics' => 'performance',
            'performance' => 'performance',
            'system_info' => 'system',
            'info' => 'system',
        ];
        
        return $theme_map[$kind] ?? 'system';
    }

    /**
     * Apply pure UI-only consolidation by process_id for lifecycle events
     * 
     * Groups events by process_id for display without storing any consolidation data.
     * This maintains the "one event per DB row" architecture principle.
     * Uses the latest event from each process as the representative for the list view.
     *
     * @param array $logs Array of log entries from database
     * @return array Array with representative entries for each process_id group
     */
    private function apply_process_id_consolidation(array $logs): array
    {
        if (empty($logs)) {
            return [];
        }

        // Get process families to identify lifecycle events
        if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
            require_once dirname(__DIR__) . '/Core/ProcessLifecycleDiscovery.php';
        }

        $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
        $families = $discovery->get_process_families();
        $lifecycle_family = $families['order_lifecycle'] ?? null;

        if (!$lifecycle_family || empty($lifecycle_family['consolidate_ui'])) {
            // Consolidation disabled, return logs as-is
            return $logs;
        }

        $lifecycle_types = array_values(array_unique(array_filter((array) ($lifecycle_family['process_types'] ?? []))));
        
        // Group logs by process_id
        $by_process_id = [];
        $individual_entries = [];

        foreach ($logs as $log) {
            $process_id = $log['process_id'] ?? '';
            $event_type = $log['event_type'] ?? '';

            // Only consolidate lifecycle events that have a process_id
            if (!empty($process_id) && in_array($event_type, $lifecycle_types, true)) {
                $by_process_id[$process_id][] = $log;
            } else {
                // Keep non-lifecycle events and events without process_id as individuals
                $individual_entries[] = $log;
            }
        }

        // Create representative entries for each process_id group (no consolidation data stored)
        $representative_entries = [];
        foreach ($by_process_id as $process_id => $process_logs) {
            if (count($process_logs) <= 1) {
                // Single entries remain individual
                $individual_entries = array_merge($individual_entries, $process_logs);
                continue;
            }

            // Sort by timestamp for proper ordering
            usort($process_logs, function($a, $b) {
                return strtotime($a['timestamp'] ?? '') <=> strtotime($b['timestamp'] ?? '');
            });

            // Use the latest log as the representative entry with updated summary
            $last_log = $process_logs[count($process_logs) - 1];
            $order_id = $process_logs[0]['order_id'] ?? 0;

            // Create representative entry (no consolidation_data stored!)
            $representative_entry = $last_log; // Start with the actual log entry
            
            // Update only the summary to indicate it represents multiple events
            $representative_entry['summary'] = $this->create_process_summary($process_logs, $order_id);
            $representative_entry['status'] = $this->determine_process_status($process_logs);
            $representative_entry['is_process_representative'] = true; // Simple flag for detail rendering
            $representative_entry['process_event_count'] = count($process_logs);

            $representative_entries[] = $representative_entry;
        }

        // Merge representative and individual entries
        $all_entries = array_merge($representative_entries, $individual_entries);

        // Sort by timestamp (desc for stream list)
        usort($all_entries, function($a, $b) {
            return strtotime($b['timestamp'] ?? '') <=> strtotime($a['timestamp'] ?? '');
        });

        return $all_entries;
    }

    /**
     * Create a business-relevant summary for a process group
     *
     * @param array $process_logs Array of logs in the process
     * @param int $order_id Order ID
     * @return string Process summary
     */
    private function create_process_summary(array $process_logs, int $order_id): string
    {
        $event_count = count($process_logs);
        $has_completion = false;
        $has_error = false;
        $final_status = null;
        $payment_gateway = null;

        // Analyze logs to determine process outcome
        foreach ($process_logs as $log) {
            $summary = strtolower($log['summary'] ?? '');
            $status = $log['status'] ?? '';

            if (strpos($summary, 'complet') !== false) {
                $has_completion = true;
            }

            if ($status === 'error') {
                $has_error = true;
            }

            // Extract payment gateway info
            if (preg_match('/\b(stripe|paypal|square)\b/i', $summary, $matches)) {
                $payment_gateway = ucfirst(strtolower($matches[1]));
            }

            // Track final status changes
            if (preg_match('/status.*changed.*to\s+"([^"]+)"/', $summary, $matches)) {
                $final_status = $matches[1];
            }
        }

        // Build summary
        $summary_parts = ["Order #$order_id:"];

        if ($has_error) {
            $summary_parts[] = "processing errors occurred";
        } elseif ($has_completion) {
            if ($payment_gateway) {
                $summary_parts[] = "$payment_gateway payment completed";
            } else {
                $summary_parts[] = "completion processing";
            }
        } elseif ($final_status) {
            $summary_parts[] = "status updated to \"$final_status\"";
        } else {
            $summary_parts[] = "lifecycle processing";
        }

        return implode(' ', $summary_parts) . " ($event_count events)";
    }

    /**
     * Determine overall status for a process group
     *
     * @param array $process_logs Array of logs in the process
     * @return string Overall status
     */
    private function determine_process_status(array $process_logs): string
    {
        $statuses = array_map(function($log) {
            return $log['status'] ?? 'info';
        }, $process_logs);

        if (in_array('error', $statuses, true)) return 'error';
        if (in_array('warning', $statuses, true)) return 'warning';
        if (in_array('success', $statuses, true)) return 'success';

        return 'info';
    }

    /**
     * Check if any logs in the process are test entries
     * 
     * @param array $process_logs Array of logs in the process
     * @return bool True if any log is marked as test
     */
    private function any_test_entries(array $process_logs): bool
    {
        foreach ($process_logs as $log) {
            if (!empty($log['is_test']) && (int)$log['is_test'] === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a database table exists
     */
    private function check_table_exists(string $table_name): array
    {
        global $wpdb;
        $full_table_name = $wpdb->prefix . $table_name;

        try {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'");
            $row_count = 0;

            if ($exists) {
                $row_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$full_table_name}");
            }

            return [
                'exists' => !empty($exists),
                'full_name' => $full_table_name,
                'row_count' => $row_count,
                'error' => $wpdb->last_error ?: null,
            ];
        } catch (\Throwable $e) {
            return [
                'exists' => false,
                'full_name' => $full_table_name,
                'row_count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

}
