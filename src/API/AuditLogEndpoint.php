<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API;

use OrderDaemon\CompletionManager\Includes\Odcm_Config;
use OrderDaemon\CompletionManager\API\Timeline\TimelineBuilderInterface;
use OrderDaemon\CompletionManager\API\Timeline\TimelineRendererInterface;
use OrderDaemon\CompletionManager\API\Timeline\TimelineRequest;
use OrderDaemon\CompletionManager\API\Timeline\DatabaseTimelineBuilder;
use OrderDaemon\CompletionManager\API\Timeline\ProcessLoggerComponentExtractor;
use OrderDaemon\CompletionManager\API\Timeline\RegistryTimelineRenderer;
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
    
    private TimelineBuilderInterface $timelineBuilder;
    private TimelineRendererInterface $timelineRenderer;
    
    /**
     * Constructor with dependency injection
     */
    public function __construct(
        TimelineBuilderInterface $timelineBuilder = null,
        TimelineRendererInterface $timelineRenderer = null
    ) {
        // Dependency injection with sensible defaults
        $this->timelineBuilder = $timelineBuilder ?? new DatabaseTimelineBuilder(
            new ProcessLoggerComponentExtractor()
        );
        $this->timelineRenderer = $timelineRenderer ?? new RegistryTimelineRenderer();
    }

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
                    __('audit.logs.delete.error.invalid_log_ids_provided', 'order-daemon'),
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
                    __('audit.logs.delete.error.no_valid_log_ids_found_for_deletion', 'order-daemon'),
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
                    /* translators: %d: the number of log entries successfully deleted */
                    _n(
                        'audit.logs.delete.success.single',
                        'audit.logs.delete.success.plural',
                        $deleted_count,
                        'order-daemon'
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
                __('audit.logs.delete.failure', 'order-daemon'),
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
                $include_debug = (bool) $request->get_param('include_debug');
                $all_logs = $this->apply_process_id_consolidation($all_logs, $include_debug);
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
                __('audit.logs.fetch.failure', 'order-daemon'),
                ['status' => 500]
            );
        }
    }

    /**
     * Render log components for detail view
     * CLEAN ARCHITECTURE: Uses dependency injection and immutable data objects
     */
    public function render_components(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            // Start performance monitoring
            $start_time = microtime(true);
            
            // Create immutable request object
            $timelineRequest = TimelineRequest::fromRestRequest($request);
            
            // Build timeline data using injected services
            $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);
            
            // Filter debug components if needed
            if (!$timelineRequest->includeDebug) {
                $timelineData = $this->filter_debug_components($timelineData);
            }
            
            // Render timeline using injected renderer
            $html = $this->timelineRenderer->renderTimeline($timelineData);
            
            // Performance monitoring
            $execution_time = microtime(true) - $start_time;
            $this->log_api_performance('render_components', $execution_time, [
                'log_id' => $timelineRequest->logId,
                'is_process_timeline' => $timelineData->isProcessGroup(),
                'component_count' => $timelineData->getComponentCount(),
                'html_size' => strlen($html),
                'debug_filtered' => !$timelineRequest->includeDebug
            ]);

            return new WP_REST_Response([
                'html' => $html,
                'meta' => array_merge($timelineData->metadata, [
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                    'components_filtered' => !$timelineRequest->includeDebug,
                    'debug_mode' => $timelineRequest->includeDebug
                ]),
            ], 200);

        } catch (\Exception $e) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM: Exception in render_components: " . $e->getMessage());
            }
            
            $this->log_api_error('render_components', $e, [
                'log_id' => $request->get_param('log_id'),
                'include_debug' => $request->get_param('include_debug')
            ]);

            return new WP_Error(
                'odcm_render_error',
                __('audit.logs.render.failure.log_components', 'order-daemon'),
                ['status' => 500]
            );
        }
    }

    /**
     * Filter debug components from timeline data
     *
     * @param \OrderDaemon\CompletionManager\API\Timeline\TimelineData $timelineData
     * @return \OrderDaemon\CompletionManager\API\Timeline\TimelineData Filtered timeline data
     */
    private function filter_debug_components(\OrderDaemon\CompletionManager\API\Timeline\TimelineData $timelineData): \OrderDaemon\CompletionManager\API\Timeline\TimelineData
    {
        $filtered_components = [];
        
        foreach ($timelineData->components as $component) {
            // Skip debug components
            if ($this->is_debug_component($component)) {
                continue;
            }
            
            // For non-debug components, filter any nested debug components
            if (isset($component['data']['components']) && is_array($component['data']['components'])) {
                $component['data']['components'] = array_filter(
                    $component['data']['components'],
                    fn($c) => !$this->is_debug_component($c)
                );
            }
            
            $filtered_components[] = $component;
        }
        
        // Create a new TimelineData object with filtered components instead of modifying the existing one
        if ($timelineData->isIndividual()) {
            return \OrderDaemon\CompletionManager\API\Timeline\TimelineData::individual(
                $timelineData->logId,
                $filtered_components,
                $timelineData->metadata
            );
        } else {
            return \OrderDaemon\CompletionManager\API\Timeline\TimelineData::processGroup(
                $timelineData->logId,
                $filtered_components,
                $timelineData->metadata
            );
        }
    }

    /**
     * Check if a component is a debug component
     *
     * @param array $component The component to check
     * @return bool True if this is a debug component
     */
    private function is_debug_component(array $component): bool
    {
        // Check component level
        if (isset($component['level']) && strtolower($component['level']) === 'debug') {
            return true;
        }

        // Check event type
        if (isset($component['event_type']) && strpos(strtolower($component['event_type']), 'debug_') === 0) {
            return true;
        }

        // Check source
        if (isset($component['source']) && strpos(strtolower($component['source']), 'debug_') === 0) {
            return true;
        }

        // Check data level
        if (isset($component['data']['level']) && strtolower($component['data']['level']) === 'debug') {
            return true;
        }

        // Check data source
        if (isset($component['data']['source']) && strpos(strtolower($component['data']['source']), 'debug_') === 0) {
            return true;
        }

        return false;
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
                'event_type' => 'info',
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
            'event_type' => 'info',
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
            return '<div class="odcm-empty-data">' . esc_html__('audit.logs.timeline.no_data', 'order-daemon') . '</div>';
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
            $event_type = sanitize_key($component['event_type'] ?? 'info');
            $label = (string) ($component['label'] ?? ucfirst($event_type));
            $ts = (string) ($component['ts'] ?? '');
            $level = sanitize_key($component['level'] ?? 'info');
            $data = is_array($component['data'] ?? null) ? $component['data'] : [];
            
            if (empty($data)) {
                continue; // Skip empty components
            }
            
            // Smart renderer lookup with capability detection
            $def = odcm_find_best_renderer_for_data($event_type, $data);
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
                            $renderer_html = $renderer->renderTimelineItem($event_type, $label, $ts ?: null, $level, $data);
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
                $html .= $this->render_fallback_component($event_type, $label, $data);
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
     * @param string $event_type Component event type
     * @param string $label Component label
     * @param array $data Component data
     * @return string HTML output
     */
    private function render_fallback_component(string $event_type, string $label, array $data): string
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
     * using the same timeline system as render_components().
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function render_components_batch(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $log_ids = $request->get_param('log_ids');
            if (!is_array($log_ids) || empty($log_ids)) {
                return new WP_Error('odcm_invalid_log_ids', __('audit.logs.render.error.invalid_log_ids_provided', 'order-daemon'), ['status' => 400]);
            }
            
            // Normalize IDs (absint, unique, cap to 50)
            $ids = array_values(array_unique(array_map('absint', array_filter($log_ids, function($v){ 
                return is_numeric($v) && (int)$v > 0; 
            }))));
            
            if (empty($ids)) {
                return new WP_Error('odcm_no_valid_ids', __('audit.logs.render.error.no_valid_log_ids_provided', 'order-daemon'), ['status' => 400]);
            }
            
            if (count($ids) > 50) {
                $ids = array_slice($ids, 0, 50);
            }

            $t0 = microtime(true);
            $items = [];

            // Process each ID using the same timeline system as render_components()
            foreach ($ids as $id) {
                try {
                    // Create a mock request for each individual log ID
                    $mockRequest = new class($id, $request->get_param('include_debug')) implements WP_REST_Request {
                        private $log_id;
                        private $include_debug;
                        
                        public function __construct($log_id, $include_debug) {
                            $this->log_id = $log_id;
                            $this->include_debug = $include_debug;
                        }
                        
                        public function get_param($key) {
                            if ($key === 'log_id') return $this->log_id;
                            if ($key === 'include_debug') return $this->include_debug;
                            return null;
                        }
                        
                        // Implement required interface methods as no-ops since we only need get_param()
                        public function get_params() { return []; }
                        public function set_param($key, $value) {}
                        public function get_attributes() { return []; }
                        public function set_attributes($attributes) {}
                        public function get_headers() { return []; }
                        public function get_content_type() { return []; }
                        public function get_method() { return 'POST'; }
                        public function get_route() { return ''; }
                        public function set_route($route) {}
                        public function get_url_params() { return []; }
                        public function set_url_params($params) {}
                        public function get_file_params() { return []; }
                        public function set_file_params($params) {}
                        public function get_body() { return null; }
                        public function set_body($data) {}
                        public function get_json_params() { return []; }
                        public function set_headers($headers) {}
                        public function get_header($key) { return null; }
                        public function get_header_as_array($key) { return []; }
                        public function has_valid_params() { return true; }
                        public function sanitize_params() { return true; }
                        public function has_param($key) { return in_array($key, ['log_id', 'include_debug']); }
                        public function set_default_params($defaults) {}
                        public function get_default_params() { return []; }
                    };

                    // Use the timeline system (same as render_components)
                    $timelineRequest = TimelineRequest::fromRestRequest($mockRequest);
                    $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);
                    $html = $this->timelineRenderer->renderTimeline($timelineData);
                    
                    $items[] = [
                        'log_id' => (int) $id,
                        'success' => true,
                        'html' => $html
                    ];
                    
                } catch (\Throwable $e) {
                    $items[] = [
                        'log_id' => (int) $id,
                        'success' => false,
                        'error' => [
                            'code' => 'render_error',
                            'message' => __('audit.logs.render.failure.components', 'order-daemon')
                        ]
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
            $this->log_api_error('render_components_batch', $e, ['ids' => $request->get_param('log_ids')]);
            return new WP_Error('odcm_render_batch_error', __('audit.logs.render.failure.batch_components', 'order-daemon'), ['status' => 500]);
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
            'success' => __('status.success', 'order-daemon'),
            'error'   => __('status.error', 'order-daemon'),
            'warning' => __('status.warning', 'order-daemon'),
            'info'    => __('status.info', 'order-daemon'),
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
     * Format logs for API response with user-friendly metadata
     */
    private function format_logs_for_api(array $logs): array
    {
        return array_map(function($log) {
            $is_consolidated = !empty($log['is_process_representative']);
            
            // Transform event_type for more human-readable display
            $event_type_display = $log['event_type'] ?? '';
            if ($is_consolidated) {
                $event_type_display = $this->format_filter_label($log['status'] ?? 'info');
            } else {
                $event_type_display = $this->format_filter_label($event_type_display);
            }
            
            $formatted = [
                'id' => (int) $log['id'],
                'timestamp' => $log['timestamp'],
                'status' => $log['status'],
                'summary' => $log['summary'],
                'order_id' => !empty($log['order_id']) ? (int) $log['order_id'] : null,
                'event_type' => $event_type_display, // User-friendly display version
                'raw_event_type' => $log['event_type'], // Keep original for filtering
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

            // Include constituent log IDs for bulk deletion support
            if (!empty($log['constituent_log_ids']) && is_array($log['constituent_log_ids'])) {
                $formatted['constituent_log_ids'] = $log['constituent_log_ids'];
                $formatted['is_process_representative'] = true;
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
     * Render log components using clean timeline system
     */
    private function render_log_components(array $log): string
    {
        try {
            // Create a mock request for the log ID
            $mockRequest = new class($log['id'] ?? 0, false) implements WP_REST_Request {
                private $log_id;
                private $include_debug;
                
                public function __construct($log_id, $include_debug) {
                    $this->log_id = $log_id;
                    $this->include_debug = $include_debug;
                }
                
                public function get_param($key) {
                    if ($key === 'log_id') return $this->log_id;
                    if ($key === 'include_debug') return $this->include_debug;
                    return null;
                }
                
                // Implement required interface methods as no-ops since we only need get_param()
                public function get_params() { return []; }
                public function set_param($key, $value) {}
                public function get_attributes() { return []; }
                public function set_attributes($attributes) {}
                public function get_headers() { return []; }
                public function get_content_type() { return []; }
                public function get_method() { return 'POST'; }
                public function get_route() { return ''; }
                public function set_route($route) {}
                public function get_url_params() { return []; }
                public function set_url_params($params) {}
                public function get_file_params() { return []; }
                public function set_file_params($params) {}
                public function get_body() { return null; }
                public function set_body($data) {}
                public function get_json_params() { return []; }
                public function set_headers($headers) {}
                public function get_header($key) { return null; }
                public function get_header_as_array($key) { return []; }
                public function has_valid_params() { return true; }
                public function sanitize_params() { return true; }
                public function has_param($key) { return in_array($key, ['log_id', 'include_debug']); }
                public function set_default_params($defaults) {}
                public function get_default_params() { return []; }
            };

            // Use the clean timeline system
            $timelineRequest = TimelineRequest::fromRestRequest($mockRequest);
            $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);
            return $this->timelineRenderer->renderTimeline($timelineData);
            
        } catch (\Throwable $e) {
            // Fallback for completely broken entries
            error_log('ODCM: render_log_components failed: ' . $e->getMessage());
            return '<div class="odcm-empty-data">' . esc_html__('audit.logs.timeline.empty', 'order-daemon') . '</div>';
        }
    }

    /**
     * LEGACY METHOD REMOVED - Use clean timeline system instead
     */
    private function render_narrative_timeline(array $envelope, bool $include_debug = false): string
    {
        error_log("ODCM: Legacy render_narrative_timeline called - this should use clean timeline system");
        return '<div class="odcm-error">Legacy rendering method called. Please use clean timeline system.</div>';
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
            $component_event_types = [];
            foreach ($envelope['components'] as $component) {
                if (is_array($component) && isset($component['event_type'])) {
                    $component_event_types[] = $component['event_type'];
                }
            }
            if (!empty($component_event_types)) {
                $unique_event_types = array_unique($component_event_types);
                $content .= '<p><strong>Component Types:</strong> ' . esc_html(implode(', ', $unique_event_types)) . '</p>';
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
                return new WP_Error('odcm_invalid_process_id', __('audit.logs.process.invalid_id', 'order-daemon'), ['status' => 400]);
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
            return new WP_Error('odcm_process_fetch_error', __('audit.logs.process.fetch_failure', 'order-daemon'), ['status' => 500]);
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
        global $wpdb;

        // Search filter
        $search = $request->get_param('s');
        if (!empty($search)) {
            $where_conditions[] = "(l.summary LIKE %s OR l.order_id = %s)";
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = is_numeric($search) ? (int) $search : 0;
        }

        // Status filter (premium)
        $status = $request->get_param('status');
        if (!empty($status) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
            $where_conditions[] = "l.status = %s";
            $where_values[] = $status;
        }

        // Event type filter (premium)
        $event_type = $request->get_param('event_type');
        if (!empty($event_type) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
            $where_conditions[] = "l.event_type = %s";
            $where_values[] = $event_type;
        }

        // Source filter (premium)
        $source = $request->get_param('source');
        if (!empty($source) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
            $where_conditions[] = "l.source = %s";
            $where_values[] = $source;
        }

        // Order ID filter (premium)
        $order_id = $request->get_param('order_id');
        if (!empty($order_id) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
            $where_conditions[] = "l.order_id = %d";
            $where_values[] = (int) $order_id;
        }

        // Date range filters (premium)
        $date_start = $request->get_param('date_start');
        $date_end = $request->get_param('date_end');
        if (!empty($date_start) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
            $where_conditions[] = "l.timestamp >= %s";
            $where_values[] = $date_start . ' 00:00:00';
        }
        if (!empty($date_end) && function_exists('odcm_can_use') && odcm_can_use('audit_log_filter_advanced')) {
            $where_conditions[] = "l.timestamp <= %s";
            $where_values[] = $date_end . ' 23:59:59';
        }

        // Test and debug filters (always available)
        if (!$request->get_param('include_tests')) {
            $where_conditions[] = "(l.is_test IS NULL OR l.is_test = 0)";
        }

        // Simplified debug log filtering - only filter by status
        if (!$request->get_param('include_debug')) {
            $where_conditions[] = "l.status != 'debug'";
        }

        // Exclude consolidation diagnostics by default unless explicitly opted-in and in debug
        $include_consolidation_diag = (bool) $request->get_param('include_consolidation_diag');
        $debug_on = (defined('ODCM_DEBUG') && ODCM_DEBUG);
        if (!($include_consolidation_diag && $debug_on)) {
            $where_conditions[] = "(l.event_type IS NULL OR l.event_type <> %s)";
            $where_values[] = 'consolidation_diag';
        }

        // Since filter for incremental updates
        $since = $request->get_param('since');
        if (!empty($since)) {
            $where_conditions[] = "l.timestamp > %s";
            $where_values[] = $since;
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
     * Determine if a payload component event_type is context-only and should not render as a standalone timeline item.
     *
     * Context-only event_types carry supplemental data that is embedded into primary events (e.g., status change)
     * and must be skipped from standalone rendering in the consolidated timeline.
     *
     * @param string $event_type Component event_type slug.
     * @return bool True when the component is context-only.
     */
    private function isContextOnlyComponent(string $event_type): bool
    {
        $contextOnlyEventTypes = [
            'attribution',      // Attribution badges - embed in parent events
            'performance',      // Performance metrics - embed in parent events
            'user_context',     // User context data - embed in parent events
            'info',             // Info data - embed in parent events
            'action_executed',  // Action execution details - embed in parent events
            'process_started',  // Debug-only, skip entirely
        ];
        return in_array($event_type, $contextOnlyEventTypes, true);
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
     * Render timeline for all events with a specific process_id
     *
     * @param string $process_id The process ID to query
     * @param bool $include_debug Whether to include debug components
     * @return string HTML output for the process timeline
     */
    private function render_process_timeline_by_process_id(string $process_id, bool $include_debug = false): string
    {
        if (empty($process_id)) {
            return '<div class="odcm-empty-data">' . esc_html__('audit.logs.process.invalid_id', 'order-daemon') . '</div>';
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
                return '<div class="odcm-empty-data">' . esc_html__('audit.logs.process.no_events', 'order-daemon') . '</div>';
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
                        'event_type' => 'process_event',
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
                    return '<div class="odcm-empty-data">' . esc_html__('audit.logs.process.events_filtered_debug', 'order-daemon') . '</div>';
                } else {
                    return '<div class="odcm-empty-data">' . esc_html__('audit.logs.process.no_components', 'order-daemon') . '</div>';
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
            return '<div class="odcm-empty-data">' . esc_html__('audit.logs.process.timeline_render_error', 'order-daemon') . '</div>';
        }
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
            $event_event_type = $event['event_type'] ?? '';

            // Find context components that should be embedded in this event
            $relevant_context = [];
            $context_content = [];

            foreach ($context_components as $context) {
                $context_ts = strtotime($context['ts'] ?? '');
                $context_event_type = $context['event_type'] ?? '';
                $context_data = is_array($context['data'] ?? null) ? $context['data'] : [];

                // Embed context based on temporal proximity and logical relevance
                $should_embed = false;

                // Always embed if timestamps are very close (within 1 second)
                if (abs($event_ts - $context_ts) <= 1) {
                    $should_embed = true;
                }
                // Embed info and action_executed into status changes
                elseif ($event_event_type === 'status_change' && in_array($context_event_type, ['info', 'action_executed'])) {
                    // Embed if context is within 5 seconds of the status change
                    if (abs($event_ts - $context_ts) <= 5) {
                        $should_embed = true;
                    }
                }
                // Embed attribution data into any primary event within 10 seconds
                elseif ($context_event_type === 'attribution' && abs($event_ts - $context_ts) <= 10) {
                    $should_embed = true;
                }
                // Embed performance data into any primary event within 2 seconds
                elseif ($context_event_type === 'performance' && abs($event_ts - $context_ts) <= 2) {
                    $should_embed = true;
                }

                if ($should_embed) {
                    $relevant_context[] = $context;

                    // Generate context content using direct renderContent() call
                    $context_html = $this->generateContextContent($context_event_type, $context_data);
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
     * @param string $event_type The component event_type (info, action_executed, etc.)
     * @param array $data The component data
     * @return string HTML content for embedding within primary events
     */
    private function generateContextContent(string $event_type, array $data): string
    {
            // Load registry for renderer lookup
            if (!function_exists('odcm_find_best_renderer_for_data')) {
                require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
            }

            $def = function_exists('odcm_find_best_renderer_for_data') ? \odcm_find_best_renderer_for_data($event_type, $data) : null;

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
     * @param string $event_type The component event_type (info, action_executed, etc.)
     * @param array $data The component data
     * @return string HTML fragment for the context component
     */
    private function renderContextComponent(string $event_type, array $data): string
    {
        // Load registry for renderer lookup
        if (!function_exists('odcm_find_best_renderer_for_data')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }

        $def = function_exists('odcm_find_best_renderer_for_data') ? \odcm_find_best_renderer_for_data($event_type, $data) : null;

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
        
        // Determine appropriate component event_type based on event characteristics
        $component_event_type = $this->determine_synthetic_component_event_type($event, $event_details);
        
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
            'event_type' => $component_event_type,
            'label' => $summary,
            'ts' => $timestamp,
            'level' => $status,
            'data' => $data,
            'source_event_id' => $event_id,
            'is_synthetic' => true,
        ];
        
        error_log("ODCM EXTRACT: Created synthetic component of event_type '$component_event_type' for event $event_id");
        
        return [$synthetic_component];
    }
    
    /**
     * Determine appropriate component event_type for synthetic components
     *
     * @param array $event Timeline event
     * @param array|null $event_details Parsed event details
     * @return string Component event_type
     */
    private function determine_synthetic_component_event_type(array $event, ?array $event_details): string
    {
        $event_type = strtolower($event['event_type'] ?? '');
        $summary = strtolower($event['summary'] ?? '');
        $status = strtolower($event['status'] ?? '');
        
        // Map event types to appropriate component event_types
        if (strpos($event_type, 'status') !== false || strpos($summary, 'status') !== false) {
            return 'status_change_processing';
        }
        
        if (strpos($event_type, 'payment') !== false || strpos($summary, 'payment') !== false) {
            return 'stripe_event'; // or 'paypal_event' based on details
        }
        
        if (strpos($event_type, 'order') !== false || strpos($summary, 'order') !== false) {
            return 'order_processing';
        }
        
        if (strpos($event_type, 'rule') !== false || strpos($summary, 'rule') !== false) {
            return 'rule_evaluation';
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
            'event_type' => 'error',
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
        $context_event_type = $context_component['event_type'] ?? '';
        $best_score = -1;
        $best_index = null;
        
        foreach ($groups as $index => $group) {
            $primary = $group['primary'];
            $primary_ts = strtotime($primary['ts'] ?? '');
            $primary_event_type = $primary['event_type'] ?? '';
            
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
            if ($context_event_type === 'attribution' || $context_event_type === 'performance') {
                $score += 3; // These are generally relevant to any primary event
            }
            
            if ($context_event_type === 'info' && strpos($primary_event_type, 'status') !== false) {
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
            $event_type = $context['event_type'] ?? '';
            $data = is_array($context['data'] ?? null) ? $context['data'] : [];
            
            if (empty($data)) {
                continue;
            }
            
            // Use simplified rendering for context components
            $context_html = $this->generateContextContent($event_type, $data);
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
            $content .= '<p class="odcm-fallback-notice"><em>Fallback component (processing error)</em></p>';
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
     * @param bool $include_debug Whether to include debug logs in consolidation
     * @return array Array with representative entries for each process_id group
     */
    private function apply_process_id_consolidation(array $logs, bool $include_debug = false): array
    {
        error_log("ODCM CONSOLIDATION: apply_process_id_consolidation called with " . count($logs) . " logs");
        
        if (empty($logs)) {
            error_log("ODCM CONSOLIDATION: No logs to consolidate");
            return [];
        }

        // Get process families to identify lifecycle events
        if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
            require_once dirname(__DIR__) . '/Core/ProcessLifecycleDiscovery.php';
        }

        $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
        $families = $discovery->get_process_families();
        $lifecycle_family = $families['order_lifecycle'] ?? null;

        error_log("ODCM CONSOLIDATION: Lifecycle family found: " . ($lifecycle_family ? 'YES' : 'NO'));
        error_log("ODCM CONSOLIDATION: Consolidate UI enabled: " . (!empty($lifecycle_family['consolidate_ui']) ? 'YES' : 'NO'));

        if (!$lifecycle_family || empty($lifecycle_family['consolidate_ui'])) {
            // Consolidation disabled, return logs as-is
            error_log("ODCM CONSOLIDATION: Consolidation disabled, returning logs as-is");
            return $logs;
        }

        $lifecycle_types = array_values(array_unique(array_filter((array) ($lifecycle_family['process_types'] ?? []))));
        error_log("ODCM CONSOLIDATION: Lifecycle types: " . json_encode($lifecycle_types));
        
        // Group logs by process_id
        $by_process_id = [];
        $individual_entries = [];

        foreach ($logs as $log) {
            $process_id = $log['process_id'] ?? '';

            // Consolidate ALL events that have a process_id
            // The process_id is the authoritative grouping mechanism - if an event has one,
            // it should be part of that process group regardless of event_type
            if (!empty($process_id)) {
                $by_process_id[$process_id][] = $log;
            } else {
                // Keep events without process_id as individuals
                $individual_entries[] = $log;
            }
        }
        
        error_log("ODCM CONSOLIDATION: Found " . count($by_process_id) . " unique process_ids");
        error_log("ODCM CONSOLIDATION: Found " . count($individual_entries) . " individual entries");

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
            $order_id = (int) ($process_logs[0]['order_id'] ?? 0);

            // Create representative entry (no consolidation_data stored!)
            $representative_entry = $last_log; // Start with the actual log entry
            
            // Filter out debug logs if not including debug
            if (!$include_debug) {
                $filtered_process_logs = array_filter($process_logs, function($log) {
                    return ($log['status'] ?? 'info') !== 'debug';
                });
                // If filtering removed all logs, skip this process group
                if (empty($filtered_process_logs)) {
                    continue;
                }
            } else {
                $filtered_process_logs = $process_logs;
            }
                        
            // Update only the summary to indicate it represents multiple events
            $representative_entry['summary'] = $this->create_process_summary($filtered_process_logs, $order_id);
            $representative_entry['status'] = $this->determine_process_status($filtered_process_logs);
            $representative_entry['is_process_representative'] = true; // Simple flag for detail rendering
            
            // Track constituent log IDs for bulk deletion
            $representative_entry['constituent_log_ids'] = array_map(function($log) {
                return (int) $log['id'];
            }, $process_logs);

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
     * @param int|string $order_id Order ID (flexible for WordPress $wpdb compatibility)
     * @return string Process summary
     */
    private function create_process_summary(array $process_logs, $order_id): string
    {
        // Cast to int for use in summary (WordPress $wpdb returns strings)
        $order_id = (int) $order_id;
        $has_error = false;
        $has_rule_evaluation = false;
        $has_payment_event = false;
        $final_order_status_in_timeline = null;

        // Analyze logs to determine process outcome - with enhanced rule evaluation detection
        foreach ($process_logs as $log) {
            $summary = strtolower($log['summary'] ?? '');
            $event_type = strtolower($log['event_type'] ?? '');
            $status = strtolower($log['status'] ?? '');
            $source = strtolower($log['source'] ?? '');
            
            // Check for errors
            if ($status === 'error') {
                $has_error = true;
            }
            
            // Check for rule evaluations - expanded detection for better matching
            if (strpos($event_type, 'rule') !== false || 
                strpos($summary, 'rule') !== false ||
                strpos($source, 'rule') !== false ||
                strpos($event_type, 'evaluation') !== false ||
                strpos($summary, 'evaluation') !== false) {
                $has_rule_evaluation = true;
            }
            
            // Check for payment events
            if (strpos($event_type, 'payment') !== false || 
                strpos($summary, 'payment') !== false || 
                preg_match('/\b(stripe|paypal|square)\b/i', $summary)) {
                $has_payment_event = true;
            }
            
            // Track final status changes
            if (preg_match('/status.*changed.*to\s+"([^"]+)"/', $summary, $matches)) {
                $final_order_status_in_timeline = $matches[1];
            }
        }

        // Build summary with new prioritization: error -> rule evaluation -> payment -> final status -> generic
        $summary_parts = [];

        if ($has_error) {
            $summary_parts[] = "Processing errors";
        } elseif ($has_rule_evaluation) {
            $summary_parts[] = "Rule evaluation";
        } elseif ($has_payment_event) {
            $summary_parts[] = "Payment processing";
        } elseif ($final_order_status_in_timeline) {
            $summary_parts[] = "Status changed to \"$final_order_status_in_timeline\"";
        } else {
            $summary_parts[] = "Order lifecycle";
        }

        return implode(' ', $summary_parts);
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
