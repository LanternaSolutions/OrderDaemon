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

// Defensive requires for environments without Composer/autoloaders
// Ensure timeline classes are available when REST endpoint is invoked
// Load interfaces and value objects first (to satisfy implements/typing)
if (!interface_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\TimelineBuilderInterface')) {
    require_once dirname(__DIR__) . '/API/Timeline/TimelineBuilderInterface.php';
}
if (!interface_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\TimelineRendererInterface')) {
    require_once dirname(__DIR__) . '/API/Timeline/TimelineRendererInterface.php';
}
if (!interface_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\ComponentExtractorInterface')) {
    require_once dirname(__DIR__) . '/API/Timeline/ComponentExtractorInterface.php';
}
if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\TimelineData')) {
    require_once dirname(__DIR__) . '/API/Timeline/TimelineData.php';
}
if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\TimelineRequest')) {
    require_once dirname(__DIR__) . '/API/Timeline/TimelineRequest.php';
}
if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\DatabaseTimelineBuilder')) {
    require_once dirname(__DIR__) . '/API/Timeline/DatabaseTimelineBuilder.php';
}
if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\ProcessLoggerComponentExtractor')) {
    require_once dirname(__DIR__) . '/API/Timeline/ProcessLoggerComponentExtractor.php';
}
if (!class_exists('OrderDaemon\\CompletionManager\\API\\Timeline\\RegistryTimelineRenderer')) {
    require_once dirname(__DIR__) . '/API/Timeline/RegistryTimelineRenderer.php';
}

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
    
    private ?TimelineBuilderInterface $timelineBuilder = null;
    private ?TimelineRendererInterface $timelineRenderer = null;
    
    /**
     * Constructor with dependency injection
     */
    public function __construct(
        TimelineBuilderInterface $timelineBuilder = null,
        TimelineRendererInterface $timelineRenderer = null
    ) {
        // Dependency injection with sensible defaults, but be defensive about class availability
        try {
            $this->timelineBuilder = $timelineBuilder ?: new DatabaseTimelineBuilder(
                new ProcessLoggerComponentExtractor()
            );
        } catch (\Throwable $e) {
            // Defer initialization to runtime in render_components()
            $this->timelineBuilder = $timelineBuilder ?: null;
        }

        try {
            $this->timelineRenderer = $timelineRenderer ?: new RegistryTimelineRenderer();
        } catch (\Throwable $e) {
            // Defer initialization to runtime in render_components()
            $this->timelineRenderer = $timelineRenderer ?: null;
        }
    }

    /**
     * Log a debug message using WordPress-compatible logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    private function logDebugMessage(string $message, string $level = 'debug'): void
    {
        // Only log if debug mode is enabled
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }

        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($message, $level);
            return;
        }

        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
            return;
        }

        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_' . $level, $message);
            return;
        }

        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
            $debug_file = WP_CONTENT_DIR . '/debug.log';
            @file_put_contents(
                $debug_file,
                '[' . current_time('mysql') . '] ' . $message . PHP_EOL,
                FILE_APPEND
            );
            return;
        }
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
                    'view_mode' => [
                        'type'              => 'string',
                        'default'           => 'consolidated',
                        'enum'              => ['consolidated', 'flat'],
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function($value) {
                            return in_array($value, ['consolidated', 'flat'], true);
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
            
            // Special raw data diagnostic route
            register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/raw-data/(?P<log_id>\d+)', [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_raw_timeline_data'],
                    'permission_callback' => '__return_true', // Public for debugging
                ],
            ]);
        }
    }

    /**
     * Define the arguments schema for the logs endpoint
     *
     * @return array The arguments schema
     */
    public function get_logs_args(): array
    {
        return [
            'page' => [
                'description' => 'Current page of results',
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
                'maximum'     => 1000,
            ],
            'per_page' => [
                'description' => 'Number of results per page',
                'type'        => 'integer',
                'default'     => 20,
                'minimum'     => 1,
                'maximum'     => 200,
            ],
            'view' => [
                'description' => 'View mode (consolidated or flat)',
                'type'        => 'string',
                'enum'        => ['consolidated', 'flat'],
                'default'     => 'consolidated',
            ],
            'include_debug' => [
                'description' => 'Whether to include debug events',
                'type'        => 'boolean',
                'default'     => false,
            ],
            'include_test' => [
                'description' => 'Whether to include test events',
                'type'        => 'boolean',
                'default'     => false,
            ],
        ];
    }

    /**
     * Check API permissions (Core policy)
     *
     * - GET routes: require view_woocommerce_reports capability (aligned with WooCommerce reports).
     * - POST routes: require view_woocommerce_reports + valid REST nonce.
     *
     * Insight Dashboard is a core free feature; no premium entitlement checks apply here.
     * Uses WooCommerce standard capability for report viewing, allowing both Admin and Shop Manager access.
     *
     * @param WP_REST_Request $request The REST request
     * @return bool True if permitted; false otherwise
     */
    public function check_permissions(WP_REST_Request $request): bool
    {
        // Enhanced permission debugging for 403 troubleshooting
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage('ODCM API Permission Check (Free):');
            $this->logDebugMessage('- User ID: ' . get_current_user_id());
            $this->logDebugMessage('- User roles: ' . implode(', ', wp_get_current_user()->roles ?? []));
            $this->logDebugMessage('- view_woocommerce_reports: ' . (current_user_can('view_woocommerce_reports') ? 'YES' : 'NO'));
            $this->logDebugMessage('- manage_woocommerce (fallback): ' . (current_user_can('manage_woocommerce') ? 'YES' : 'NO'));
            $this->logDebugMessage('- manage_options (admin): ' . (current_user_can('manage_options') ? 'YES' : 'NO'));
            $this->logDebugMessage('- is_user_logged_in: ' . (is_user_logged_in() ? 'YES' : 'NO'));
            $this->logDebugMessage('- Request method: ' . $request->get_method());
            $this->logDebugMessage('- Request URL: ' . $request->get_route());
            $this->logDebugMessage('- User agent: ' . (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown'));
            $this->logDebugMessage('- Referer: ' . (isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : 'unknown'));
        }

        // TEMPORARY DEBUG: Allow any logged-in user to access API for troubleshooting
        // This helps us identify if it's a capability issue vs other auth problems
        if (!is_user_logged_in()) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM API: Permission denied - user not logged in');
            }
            return false;
        }

        // Use WooCommerce standard capability for reports (allows Shop Manager access)
        // Fall back to manage_woocommerce for sites where view_woocommerce_reports isn't available
        // Add manage_options as additional fallback for WordPress admins
        $has_permission = current_user_can('view_woocommerce_reports') || 
                         current_user_can('manage_woocommerce') || 
                         current_user_can('manage_options');

        if (!$has_permission) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM API: Permission denied - user lacks required capabilities');
            }
            return false;
        }

        // Nonce verification for POST requests (state-changing)
        if ($request->get_method() === 'POST') {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage('ODCM API: Permission denied - invalid or missing nonce for POST request');
                    $this->logDebugMessage('- Nonce provided: ' . ($nonce ? 'YES' : 'NO'));
                    $this->logDebugMessage('- Nonce value: ' . ($nonce ?: 'none'));
                }
                return false;
            }
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage('ODCM API: Permission check passed');
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
                    __('audit.logs.render.error.invalid_log_ids_provided', 'order-daemon'),
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

            // Determine view mode: consolidated (default) or flat (raw chronological)
            $view = $request->get_param('view') ?: 'consolidated';
            if ($view === 'flat') {
                // Flat view: paginate raw events directly, no consolidation
                $page_logs = $this->get_filtered_logs($request, $per_page, $page);
                $total = $this->get_filtered_log_count($request);
                $total_pages = max(1, (int) ceil($total / $per_page));
                if ($page > $total_pages) {
                    $page = $total_pages;
                }
                $offset = ($page - 1) * $per_page;
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
                    'timestamp' => current_time('mysql'),
                    'consolidated_pagination' => false,
                    'pagination_basis' => 'raw',
                    ],
                ];

                return new WP_REST_Response($response_data, 200);
            }

            // Consolidated view (default): fetch all filtered, consolidate by process, then paginate
            $all_logs = $this->get_all_filtered_logs($request);
            
            // DETAILED DEBUG: Track every step
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM: DEBUG - Result from get_all_filtered_logs: ' . (is_wp_error($all_logs) ? 'WP_Error: ' . $all_logs->get_error_message() : 'Array with ' . count($all_logs) . ' items'), 'debug');
            }
            
            if (is_wp_error($all_logs)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage('ODCM: DEBUG - Converting WP_Error to empty array', 'debug');
                }
                // Normalize erroneous 404/other errors into empty data so UI can render empty state
                $all_logs = [];
            }

            // Apply UI-only consolidation by process_id for lifecycle events
            try {
                $include_debug = (bool) $request->get_param('include_debug');
                $all_logs = $this->apply_process_id_consolidation($all_logs, $include_debug);
                
            } catch (\Throwable $e) {
                // Fail-safe: keep original logs ungrouped
                $this->logDebugMessage('ODCM: Process ID consolidation failed: ' . $e->getMessage(), 'error');
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
            // Enhanced debugging for order completion events
            $log_id = $request->get_param('log_id');
            $include_debug = (bool) $request->get_param('include_debug');

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: render_components called for log_id: " . $log_id, 'debug');
                $this->logDebugMessage("ODCM: Request parameters: " . json_encode($request->get_params()), 'debug');
            }

            // Ensure services are initialized (lazy init to avoid early fatals)
            if (!$this->timelineBuilder instanceof TimelineBuilderInterface) {
                try {
                    $this->timelineBuilder = new DatabaseTimelineBuilder(new ProcessLoggerComponentExtractor());
                } catch (\Throwable $e) {
                    throw $e; // Re-throw to be caught by main catch block
                }
            }
            
            if (!$this->timelineRenderer instanceof TimelineRendererInterface) {
                try {
                    $this->timelineRenderer = new RegistryTimelineRenderer();
                } catch (\Throwable $e) {
                    throw $e; // Re-throw to be caught by main catch block
                }
            }
            // Registry loading is handled internally by RegistryTimelineRenderer::ensureRegistryLoaded()

            // Start performance monitoring
            $start_time = microtime(true);

            // Create immutable request object
            try {
                $timelineRequest = TimelineRequest::fromRestRequest($request);
            } catch (\Throwable $e) {
                throw $e;
            }

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: TimelineRequest created: log_id=" . $timelineRequest->logId . ", include_debug=" . ($timelineRequest->includeDebug ? 'true' : 'false'), 'debug');
            }

            // Build timeline data using injected services
            try {
                $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);

                // IMPORTANT: Verify that timelineData is the correct type and fully qualified
                if (!($timelineData instanceof \OrderDaemon\CompletionManager\API\Timeline\TimelineData)) {
                    // This is a critical error that should be logged
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage("ODCM TIMELINE: WARNING - timelineData is not the expected class. Actual class: " . get_class($timelineData), 'error');
                    }
                }
            } catch (\Throwable $e) {
                throw $e;
            }

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: TimelineData created with " . $timelineData->getComponentCount() . " components", 'debug');
                $this->logDebugMessage("ODCM: TimelineData metadata: " . json_encode($timelineData->metadata), 'debug');
                $this->logDebugMessage("ODCM: TimelineData type: " . ($timelineData->isProcessGroup() ? 'process_group' : 'individual'), 'debug');
            }

            // Filter debug components if needed
            if (!$timelineRequest->includeDebug) {
                try {
                    $timelineData = $this->filter_debug_components($timelineData);
                } catch (\Throwable $e) {
                    throw $e;
                }

                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: After debug filtering: " . $timelineData->getComponentCount() . " components", 'debug');
                }
            }

            // DIAGNOSTIC: Check if any components have malformed data before rendering
            $componentCheck = $this->checkComponentsBeforeRendering($timelineData);

            // Render timeline using injected renderer
            try {
                $html = $this->timelineRenderer->renderTimeline($timelineData);
            } catch (\Throwable $e) {
                throw $e;
            }

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: Rendered HTML length: " . strlen($html), 'debug');
                $this->logDebugMessage("ODCM: HTML empty: " . (empty($html) ? 'YES' : 'NO'), 'debug');
            }

            // Performance monitoring
            $execution_time = microtime(true) - $start_time;
            $this->log_api_performance('render_components', $execution_time, [
                'log_id' => $timelineRequest->logId,
                'is_process_timeline' => $timelineData->isProcessGroup(),
                'component_count' => $timelineData->getComponentCount(),
                'html_size' => strlen($html),
                'debug_filtered' => !$timelineRequest->includeDebug,
                'component_check' => $componentCheck
            ]);

            return new WP_REST_Response([
                'html' => $html,
                'meta' => array_merge($timelineData->metadata, [
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                    'components_filtered' => !$timelineRequest->includeDebug,
                    'debug_mode' => $timelineRequest->includeDebug,
                    'component_diagnostics' => $componentCheck
                ]),
            ], 200);

        } catch (\Throwable $e) {
            // Always log exceptions for this critical endpoint
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: Exception in render_components: " . $e->getMessage(), 'error');
                $this->logDebugMessage("ODCM: Exception class: " . get_class($e), 'error');
                $this->logDebugMessage("ODCM: Exception file: " . $e->getFile() . ":" . $e->getLine(), 'error');
                $this->logDebugMessage("ODCM: Stack trace: " . $e->getTraceAsString(), 'error');
            }

            $this->log_api_error('render_components', $e, [
                'log_id' => $request->get_param('log_id'),
                'include_debug' => $request->get_param('include_debug')
            ]);

            // SPECIAL HANDLING: Instead of empty HTML, provide a clear error template that shows information
            $error_template = $this->generateErrorTemplate($e, $request);

            // CRITICAL FLAG: This tells the frontend to render our error template directly instead of showing a generic error
            // This bypass will ensure our detailed error template is displayed to the user
            $response_body = [
                'html' => $error_template,
                'error' => 'odcm_render_error',
                'use_error_template' => true, // Critical flag to instruct frontend to use our template
                'meta' => [
                    'timestamp' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
                    'debug_mode' => defined('ODCM_DEBUG') && ODCM_DEBUG,
                    'exception_message' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'render_directly' => true, // Secondary flag to ensure template is rendered
                ],
            ];
            
            // Add detailed information in debug mode
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $response_body['developer_message'] = $e->getMessage();
                $response_body['exception_class'] = get_class($e);
                $response_body['exception_file'] = $e->getFile();
                $response_body['exception_line'] = $e->getLine();
                $response_body['stack_trace'] = $e->getTraceAsString();
            }
            
            return new WP_REST_Response($response_body, 200);
        }
    }
    
    /**
     * Check components for potential issues before rendering
     * 
     * @param \OrderDaemon\CompletionManager\API\Timeline\TimelineData $timelineData The timeline data to check
     * @return array Diagnostic information about components
     */
    private function checkComponentsBeforeRendering(\OrderDaemon\CompletionManager\API\Timeline\TimelineData $timelineData): array
    {
        $diagnostics = [
            'total_components' => $timelineData->getComponentCount(),
            'issues_found' => 0,
            'issues' => []
        ];
        
        try {
            // Safely iterate through components with extra error handling
            foreach ($timelineData->components as $idx => $component) {
                // Check type before accessing array keys
                if (!is_array($component)) {
                    $diagnostics['issues'][] = "Component #{$idx} is not an array, it's a " . gettype($component);
                    $diagnostics['issues_found']++;
                    continue;
                }

                // Check for common issues
                if (!isset($component['event_type'])) {
                    $diagnostics['issues'][] = "Component #{$idx} missing event_type";
                    $diagnostics['issues_found']++;
                }

                if (!isset($component['data']) || !is_array($component['data'])) {
                    $diagnostics['issues'][] = "Component #{$idx} missing data array";
                    $diagnostics['issues_found']++;
                }

                if (!isset($component['ts'])) {
                    $diagnostics['issues'][] = "Component #{$idx} missing timestamp (ts)";
                    $diagnostics['issues_found']++;
                }
            }
        } catch (\Throwable $e) {
            // Catch any exceptions during component checking
            $diagnostics['exception'] = $e->getMessage();
            $diagnostics['issues_found']++;
        }
        
        return $diagnostics;
    }
    
    /**
     * Generate a user-friendly error template with diagnostic information
     * 
     * @param \Throwable $e The exception that was thrown
     * @param WP_REST_Request $request The original request
     * @return string HTML error template
     */
    private function generateErrorTemplate(\Throwable $e, WP_REST_Request $request): string
    {
        $log_id = $request->get_param('log_id');
        $debug_mode = defined('ODCM_DEBUG') && ODCM_DEBUG;
        
        $html = '<div class="odcm-timeline-error">';
        $html .= '<div class="odcm-timeline-error-header">';
        $html .= '<h3>Timeline Rendering Error</h3>';
        $html .= '</div>';
        $html .= '<div class="odcm-timeline-error-body">';
        $html .= '<p>There was an error rendering the timeline for log entry #' . esc_html($log_id) . '</p>';
        
        // Include basic error information
        $html .= '<div class="odcm-timeline-error-details">';
        $html .= '<p><strong>Error Type:</strong> ' . esc_html(get_class($e)) . '</p>';
        
        // Sanitize and shorten the error message
        $error_message = $e->getMessage();
        if (empty($error_message)) {
            $error_message = 'Unknown error';
        }
        // Limit the size of the error message to avoid huge outputs
        if (strlen($error_message) > 200) {
            $error_message = substr($error_message, 0, 200) . '...';
        }
        $html .= '<p><strong>Error Message:</strong> ' . esc_html($error_message) . '</p>';
        
        // Include more details in debug mode
        if ($debug_mode) {
            $html .= '<div class="odcm-timeline-error-debug">';
            $html .= '<h4>Debug Information</h4>';
            $html .= '<p><strong>File:</strong> ' . esc_html($e->getFile()) . ':' . esc_html($e->getLine()) . '</p>';
            
            // Format stack trace for readability
            $trace_lines = explode("\n", $e->getTraceAsString());
            $html .= '<div class="odcm-timeline-error-trace">';
            $html .= '<p><strong>Stack Trace:</strong></p>';
            $html .= '<pre>';
            foreach ($trace_lines as $line) {
                $html .= esc_html($line) . "\n";
            }
            $html .= '</pre>';
            $html .= '</div>'; // trace
            
            $html .= '</div>'; // debug
        }
        
        $html .= '</div>'; // details
        
        // Add helpful information for users
        $html .= '<div class="odcm-timeline-error-help">';
        $html .= '<p>If this error persists, please try the following:</p>';
        $html .= '<ul>';
        $html .= '<li>Refresh the page and try again</li>';
        $html .= '<li>Check if there are plugin updates available</li>';
        $html .= '<li>Contact support and provide the error details above</li>';
        $html .= '</ul>';
        $html .= '</div>'; // help
        
        $html .= '</div>'; // body
        $html .= '</div>'; // container
        
        return $html;
    }

    /**
     * Batch render components for multiple log entries
     *
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response|WP_Error Response with rendered HTML for multiple logs
     */
    public function render_components_batch(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $start_time = microtime(true);
            $log_ids = $request->get_param('log_ids');
            $include_debug = (bool) $request->get_param('include_debug');
            
            if (empty($log_ids) || !is_array($log_ids)) {
                return new WP_Error(
                    'odcm_invalid_log_ids',
                    __('audit.logs.render.error.invalid_log_ids_provided', 'order-daemon'),
                    ['status' => 400]
                );
            }
            
            $results = [];
            foreach ($log_ids as $log_id) {
                try {
                    $timelineRequest = new TimelineRequest((int) $log_id, $include_debug);
                    $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);
                    
                    if (!$include_debug) {
                        $timelineData = $this->filter_debug_components($timelineData);
                    }
                    
                    $html = $this->timelineRenderer->renderTimeline($timelineData);
                    
                    $results[$log_id] = [
                        'success' => true,
                        'html' => $html,
                        'meta' => $timelineData->metadata,
                    ];
                } catch (\Throwable $e) {
                    $results[$log_id] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            $execution_time = microtime(true) - $start_time;
            
            return new WP_REST_Response([
                'results' => $results,
                'meta' => [
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                    'total_requested' => count($log_ids),
                    'successful' => count(array_filter($results, fn($r) => $r['success'])),
                ],
            ], 200);
            
        } catch (\Throwable $e) {
            $this->log_api_error('render_components_batch', $e, [
                'log_ids' => $request->get_param('log_ids'),
            ]);
            
            return new WP_Error(
                'odcm_batch_render_error',
                __('audit.logs.render.failure.batch', 'order-daemon'),
                ['status' => 500]
            );
        }
    }

    /**
     * Get logs by process ID
     *
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response|WP_Error Response with logs for the process
     */
    public function get_logs_by_process(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            global $wpdb;
            $start_time = microtime(true);
            
            $process_id = $request->get_param('process_id');
            
            if (empty($process_id)) {
                return new WP_Error(
                    'odcm_invalid_process_id',
                    __('audit.logs.process.error.invalid_process_id', 'order-daemon'),
                    ['status' => 400]
                );
            }
            
            // Secure table identifiers
            $logTableName = '`' . esc_sql($wpdb->prefix . 'odcm_audit_log') . '`';
            $payloadTableName = '`' . esc_sql($wpdb->prefix . 'odcm_audit_log_payloads') . '`';
            
            $query = $wpdb->prepare(
                "SELECT l.log_id,
                    l.timestamp,
                    l.status,
                    l.summary,
                    l.order_id,
                    l.event_type,
                    l.source,
                    l.payload_id,
                    l.is_test,
                    l.process_id,
                    COALESCE(p.payload, l.details, %s) as payload
                FROM " . $logTableName . " l
                    LEFT JOIN " . $payloadTableName . " p ON l.payload_id = p.payload_id
                WHERE l.process_id = %s
                ORDER BY l.timestamp ASC",
                '',
                $process_id
            );
            
            $logs = $wpdb->get_results($query, ARRAY_A);
            
            if ($logs === false) {
                throw new \Exception('Database query failed: ' . ($wpdb->last_error ?: 'Unknown error'));
            }
            
            $logs = $logs ?: [];
            $execution_time = microtime(true) - $start_time;
            
            return new WP_REST_Response([
                'logs' => $this->format_logs_for_api($logs),
                'meta' => [
                    'process_id' => $process_id,
                    'total_logs' => count($logs),
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                ],
            ], 200);
            
        } catch (\Throwable $e) {
            $this->log_api_error('get_logs_by_process', $e, [
                'process_id' => $request->get_param('process_id'),
            ]);
            
            return new WP_Error(
                'odcm_process_logs_error',
                __('audit.logs.process.error.fetch_failed', 'order-daemon'),
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
     * Extract components from payload data
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
     * Get all filtered logs based on request parameters
     * 
     * @param WP_REST_Request $request The REST request with filter parameters
     * @return array|WP_Error Array of log entries or WP_Error
     */
    private function get_all_filtered_logs(WP_REST_Request $request): array|WP_Error
    {
        global $wpdb;

        try {
            // Build query conditions from request parameters
            $where_clauses = $this->build_filter_where_clauses($request);

            // Build the SQL query safely
            $sql = "SELECT l.*, p.payload
                    FROM {$wpdb->prefix}odcm_audit_log l
                    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id";

            // Add WHERE clause if there are conditions
            if (!empty($where_clauses)) {
                $sql .= ' WHERE ' . implode(' AND ', $where_clauses);
            }

            $sql .= ' ORDER BY l.timestamp DESC';

            // Execute the query
            $logs = $wpdb->get_results($sql, ARRAY_A);

            if ($logs === false) {
                throw new \Exception($wpdb->last_error ?: 'Database query failed');
            }

            $result = $logs ?: [];

            return $result;

        } catch (\Exception $e) {
            $this->logDebugMessage("ODCM: Failed to get all filtered logs: " . $e->getMessage(), 'error');
            return new WP_Error('audit_log_query_failed', $e->getMessage());
        }
    }

    /**
     * Get filtered logs with pagination
     * 
     * @param WP_REST_Request $request The REST request
     * @param int $per_page Items per page
     * @param int $page Current page
     * @return array Array of log entries
     */
    private function get_filtered_logs(WP_REST_Request $request, int $per_page, int $page): array
    {
        global $wpdb;

        try {
            // Build query conditions from request parameters
            $where_clauses = $this->build_filter_where_clauses($request);
            $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

            // Calculate offset based on pagination
            $offset = ($page - 1) * $per_page;

            // Get paginated filtered logs
            $sql = $wpdb->prepare("
                SELECT l.*, p.payload
                FROM {$wpdb->prefix}odcm_audit_log l
                LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
                {$where_sql}
                ORDER BY l.timestamp DESC
                LIMIT %d OFFSET %d
            ", $per_page, $offset);

            $logs = $wpdb->get_results($sql, ARRAY_A);

            if ($logs === false) {
                throw new \Exception($wpdb->last_error ?: 'Database query failed');
            }

            $result = $logs ?: [];

            return $result;

        } catch (\Exception $e) {
            $this->logDebugMessage("ODCM: Failed to get filtered logs: " . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Get count of filtered logs
     * 
     * @param WP_REST_Request $request The REST request
     * @return int Count of filtered logs
     */
    private function get_filtered_log_count(WP_REST_Request $request): int
    {
        global $wpdb;

        try {
            // Build query conditions from request parameters
            $where_clauses = $this->build_filter_where_clauses($request);
            $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

            // Get count of filtered logs
            $sql = "
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}odcm_audit_log l
                {$where_sql}
            ";

            $count = $wpdb->get_var($sql);

            if ($count === false) {
                throw new \Exception($wpdb->last_error ?: 'Database query failed');
            }

            $result = (int) $count;

            return $result;

        } catch (\Exception $e) {
            $this->logDebugMessage("ODCM: Failed to get filtered log count: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * Build WHERE clauses for filtering logs
     * 
     * @param WP_REST_Request $request The REST request
     * @return array Array of WHERE clauses
     */
    private function build_filter_where_clauses(WP_REST_Request $request): array
    {
        global $wpdb;
        $where_clauses = [];
        
        // Resolve default flags for debug/test inclusion
        // If the param is not explicitly provided, allow a site option to set a default for troubleshooting
        $include_debug_param = $request->get_param('include_debug');
        $include_test_param  = $request->get_param('include_test');

        $include_debug_default = function_exists('get_option') ? (bool) get_option('odcm_dashboard_include_debug_by_default', false) : false;
        $include_test_default  = function_exists('get_option') ? (bool) get_option('odcm_dashboard_include_test_by_default', false) : false;

        $include_debug = ($include_debug_param === null) ? $include_debug_default : (bool) $include_debug_param;
        $include_test  = ($include_test_param === null)  ? $include_test_default  : (bool) $include_test_param;

        // Include debug events
        if (!$include_debug) {
            $where_clauses[] = "l.event_type NOT LIKE 'debug_%'";
            $where_clauses[] = "l.status != 'debug'";
        }
        
        // Include test events
        if (!$include_test) {
            $where_clauses[] = "l.is_test = 0";
        }
        
        // Order ID filter
        $order_id = $request->get_param('order_id');
        if (!empty($order_id) && is_numeric($order_id)) {
            $where_clauses[] = $wpdb->prepare("l.order_id = %d", (int) $order_id);
        }
        
        // Status filter
        $status = $request->get_param('status');
        if (!empty($status)) {
            $where_clauses[] = $wpdb->prepare("l.status = %s", sanitize_key($status));
        }
        
        // Event type filter
        $event_type = $request->get_param('event_type');
        if (!empty($event_type)) {
            $where_clauses[] = $wpdb->prepare("l.event_type = %s", sanitize_key($event_type));
        }
        
        // Date range filters
        $date_from = $request->get_param('date_from');
        if (!empty($date_from)) {
            $where_clauses[] = $wpdb->prepare("l.timestamp >= %s", sanitize_text_field($date_from));
        }
        
        $date_to = $request->get_param('date_to');
        if (!empty($date_to)) {
            $where_clauses[] = $wpdb->prepare("l.timestamp <= %s", sanitize_text_field($date_to));
        }
        
        // Search filter
        $search = $request->get_param('search');
        if (!empty($search)) {
            $where_clauses[] = $wpdb->prepare("l.summary LIKE %s", '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%');
        }
        
        // Debug logging of where clauses to help diagnose empty dashboards
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            try {
                $this->logDebugMessage('ODCM: build_filter_where_clauses => ' . implode(' AND ', $where_clauses));
            } catch (\Throwable $e) {
                // noop
            }
        }

        return $where_clauses;
    }

    /**
     * Apply process ID consolidation for lifecycle events
     * 
     * @param array $logs Array of log entries
     * @param bool $include_debug Whether to include debug events
     * @return array Consolidated log entries
     */
    private function apply_process_id_consolidation(array $logs, bool $include_debug): array
    {
        if (empty($logs)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM Consolidation: Empty input logs array', 'warning');
            }
            return [];
        }

        // Group logs by process_id if available
        $grouped_logs = [];
        $ungrouped_logs = [];

        foreach ($logs as $log) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM Consolidation: Processing log ' . ($log['log_id'] ?? 'NO_ID') .
                                      ' with process_id: ' . ($log['process_id'] ?? 'NULL'), 'debug');
            }

            if (!empty($log['process_id'])) {
                $process_id = $log['process_id'];
                $grouped_logs[$process_id][] = $log;
            } else {
                $ungrouped_logs[] = $log;
            }
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage('ODCM Consolidation: Grouped logs by process_id: ' . count($grouped_logs) .
                                  ' groups, Ungrouped logs: ' . count($ungrouped_logs), 'debug');
        }

        // Merge grouped and ungrouped logs
        $consolidated_logs = [];

        // Process grouped logs - THIS IS WHERE PLACEHOLDER ENTRIES ARE CREATED
        foreach ($grouped_logs as $process_id => $process_logs) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM Consolidation: Creating placeholder for process_id ' . $process_id .
                                      ' with ' . count($process_logs) . ' logs', 'debug');
            }

            // Sort process logs by timestamp (descending)
            usort($process_logs, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });

            // Use the most recent log as the representative entry (placeholder)
            $primary_log = reset($process_logs);
            $primary_log['_is_process_group'] = true;
            $primary_log['_process_count'] = count($process_logs);
            $primary_log['_process_logs'] = $process_logs;

            // CRITICAL: Ensure the placeholder has a valid log_id
            if (empty($primary_log['log_id']) || !is_numeric($primary_log['log_id'])) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage('ODCM Consolidation: WARNING - Placeholder log missing valid log_id for process_id ' . $process_id, 'error');
                }

                // Try to find a valid log_id from the process logs
                foreach ($process_logs as $process_log) {
                    if (!empty($process_log['log_id']) && is_numeric($process_log['log_id'])) {
                        $primary_log['log_id'] = (int) $process_log['log_id'];
                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            $this->logDebugMessage('ODCM Consolidation: Found valid log_id ' . $primary_log['log_id'] .
                                                  ' for placeholder', 'debug');
                        }
                        break;
                    }
                }

                // If still no valid log_id, this placeholder will cause issues
                if (empty($primary_log['log_id']) || !is_numeric($primary_log['log_id'])) {
                    $primary_log['log_id'] = 0; // Fallback that will cause frontend issues
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage('ODCM Consolidation: CRITICAL - No valid log_id found, using fallback 0', 'error');
                    }
                }
            }

            $consolidated_logs[] = $primary_log;
        }

        // Add ungrouped logs
        foreach ($ungrouped_logs as $log) {
            $log['_is_process_group'] = false;
            $log['_process_count'] = 1;
            $consolidated_logs[] = $log;
        }

        // Sort all logs by timestamp (descending)
        usort($consolidated_logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return $consolidated_logs;
    }

    /**
     * Get cache status for the request
     *
     * @param WP_REST_Request $request The REST request
     * @return array Cache status information
     */
    private function get_cache_status(WP_REST_Request $request): array
    {
        return [
            'enabled' => false,
            'hit' => false,
        ];
    }

    /**
     * Get applied filters from request
     * 
     * @param WP_REST_Request $request The REST request
     * @return array Applied filters
     */
    private function get_applied_filters(WP_REST_Request $request): array
    {
        $filters = [];
        $params = $request->get_params();
        
        // Map request parameters to filter names
        $filter_map = [
            'include_debug' => 'include_debug_events',
            'include_test' => 'include_test_events',
            'order_id' => 'order_id',
            'status' => 'status',
            'event_type' => 'event_type',
            'date_from' => 'date_from',
            'date_to' => 'date_to',
            'search' => 'search',
        ];
        
        // Build filter list
        foreach ($filter_map as $param => $filter_name) {
            if (isset($params[$param]) && !empty($params[$param])) {
                $filters[$filter_name] = $params[$param];
            }
        }
        
        return $filters;
    }

    /**
     * Format logs for API response
     * 
     * @param array $logs Array of log entries
     * @return array Formatted logs
     */
    private function format_logs_for_api(array $logs): array
    {
        $formatted_logs = [];

        foreach ($logs as $log) {
            // DEFENSIVE: Handle missing or invalid log_id in consolidated entries
            // This can happen when process consolidation creates representative entries
            $log_id = $log['log_id'] ?? null;

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM Format: Processing log for formatting, log_id: ' .
                                      ($log_id ?? 'NULL') . ', is_process_group: ' .
                                      (isset($log['_is_process_group']) ? ($log['_is_process_group'] ? 'true' : 'false') : 'false'), 'debug');
            }

            // If log_id is missing or invalid, try to find a valid one
            if (empty($log_id) || !is_numeric($log_id)) {
                // For process groups, try to get log_id from the process logs
                if (isset($log['_is_process_group']) && $log['_is_process_group'] && isset($log['_process_logs'])) {
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage('ODCM Format: Placeholder log missing valid log_id, searching in process logs', 'warning');
                    }

                    foreach ($log['_process_logs'] as $process_log) {
                        if (!empty($process_log['log_id']) && is_numeric($process_log['log_id'])) {
                            $log_id = $process_log['log_id'];
                            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                                $this->logDebugMessage('ODCM Format: Found valid log_id ' . $log_id . ' in process logs', 'debug');
                            }
                            break;
                        }
                    }
                }

                // If still no valid log_id, use a fallback
                if (empty($log_id) || !is_numeric($log_id)) {
                    $log_id = 0; // This will cause issues, but at least it won't be null
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage('ODCM Format: CRITICAL - No valid log_id found for placeholder, using fallback 0', 'error');
                    }
                }
            }

            $formatted_log = [
                'id' => (int) $log_id,
                'timestamp' => $log['timestamp'],
                'status' => $log['status'],
                'summary' => $log['summary'],
                'event_type' => $log['event_type'],
            ];

            // Add order_id if present
            if (!empty($log['order_id'])) {
                $formatted_log['order_id'] = (int) $log['order_id'];
            }

            // Add process group info if available
            if (isset($log['_is_process_group']) && $log['_is_process_group']) {
                $formatted_log['is_process_group'] = true;
                $formatted_log['process_id'] = $log['process_id'];
                $formatted_log['process_count'] = $log['_process_count'];
            }

            // Add payload_id if available (for rendering components)
            if (!empty($log['payload_id'])) {
                $formatted_log['payload_id'] = (int) $log['payload_id'];
            }

            // Add debug information for consolidated entries
            if (isset($log['_is_process_group']) && $log['_is_process_group'] && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM Format: Formatted consolidated entry - ID: ' . $formatted_log['id'] . ', Process ID: ' . ($log['process_id'] ?? 'N/A') . ', Process Count: ' . ($log['_process_count'] ?? 'N/A'), 'debug');
            }

            $formatted_logs[] = $formatted_log;
        }

        return $formatted_logs;
    }

    /**
     * Validate log IDs for deletion
     * 
     * @param array $log_ids Array of log IDs
     * @return array Array of valid log IDs
     */
    private function validate_log_ids_for_deletion(array $log_ids): array
    {
        global $wpdb;

        if (empty($log_ids)) {
            return [];
        }

        // Convert to integers and get unique values
        $log_ids = array_unique(array_map('intval', $log_ids));

            // Get comma-separated list for SQL
            $ids_list = implode(',', $log_ids);

            // Validate log IDs exist
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for performance-critical batch operations
            $sql = "
                SELECT log_id
                FROM {$wpdb->prefix}odcm_audit_log
                WHERE log_id IN ({$ids_list})
            ";

            $valid_ids = $wpdb->get_col($sql);
            $result = array_map('intval', $valid_ids);

        return $result;
    }

    /**
     * Perform batch deletion of logs
     * 
     * @param array $log_ids Array of log IDs
     * @return int Number of deleted logs
     */
    private function perform_batch_deletion(array $log_ids): int
    {
        global $wpdb;

        if (empty($log_ids)) {
            return 0;
        }

        // Get comma-separated list for SQL
        $ids_list = implode(',', $log_ids);

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Get payload IDs to delete
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for performance-critical batch operations
            $payload_ids = $wpdb->get_col("
                SELECT DISTINCT payload_id
                FROM {$wpdb->prefix}odcm_audit_log
                WHERE log_id IN ({$ids_list}) AND payload_id IS NOT NULL
            ");

            // Delete logs
            $deleted = $wpdb->query("
                DELETE FROM {$wpdb->prefix}odcm_audit_log
                WHERE log_id IN ({$ids_list})
            ");

            // Delete orphaned payloads
            if (!empty($payload_ids)) {
                $payload_ids_list = implode(',', $payload_ids);

            // Get payloads still in use
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for performance-critical batch operations
            $used_payloads = $wpdb->get_col("
                SELECT DISTINCT payload_id
                FROM {$wpdb->prefix}odcm_audit_log
                WHERE payload_id IN ({$payload_ids_list})
            ");

                // Calculate orphaned payloads
                $orphaned_payloads = array_diff($payload_ids, $used_payloads);

                // Delete orphaned payloads
                if (!empty($orphaned_payloads)) {
                    $orphaned_ids_list = implode(',', $orphaned_payloads);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    // Direct query is needed for performance-critical batch operations
                    $wpdb->query("
                        DELETE FROM {$wpdb->prefix}odcm_audit_log_payloads
                        WHERE id IN ({$orphaned_ids_list})
                    ");
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            return $deleted;
        } catch (\Exception $e) {
            // Rollback transaction
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Log batch deletion operation
     * 
     * @param array $log_ids Array of deleted log IDs
     * @param int $count Number of deleted logs
     * @return void
     */
    private function log_batch_deletion(array $log_ids, int $count): void
    {
        // Log deletion event
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf(
                    /* translators: %d: number of logs deleted */
                    _n(
                        '%d audit log entry was deleted',
                        '%d audit log entries were deleted',
                        $count,
                        'order-daemon'
                    ),
                    $count
                ),
                [
                    'deleted_count' => $count,
                    'deleted_ids' => $log_ids,
                    'deleted_by' => get_current_user_id(),
                ],
                null,
                'info',
                'audit_log_deletion'
            );
        }
    }

    /**
     * Log API performance metrics for monitoring
     * 
     * @param string $endpoint API endpoint name
     * @param float $execution_time Execution time in seconds
     * @param array $context Additional context data
     * @return void
     */
    private function log_api_performance(string $endpoint, float $execution_time, array $context = []): void
    {
        // Only log if debug or performance monitoring is enabled
        if (!(defined('ODCM_DEBUG') && ODCM_DEBUG) && !(defined('ODCM_PERFORMANCE_MONITORING') && ODCM_PERFORMANCE_MONITORING)) {
            return;
        }
        
        // Format execution time with precision
        $time_ms = round($execution_time * 1000, 2);
        
        // Log using structured logging
        $this->logDebugMessage(
            sprintf('API Performance: %s completed in %s ms', $endpoint, $time_ms),
            'info'
        );
        
        // Log detailed performance metrics if monitoring is enabled
        if (defined('ODCM_PERFORMANCE_MONITORING') && ODCM_PERFORMANCE_MONITORING) {
            // Add performance data to context
            $context['execution_time_ms'] = $time_ms;
            $context['endpoint'] = $endpoint;
            $context['timestamp'] = current_time('mysql');
            $context['user_id'] = get_current_user_id();
            
            // Log to performance monitoring system
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    sprintf('API: %s completed in %s ms', $endpoint, $time_ms),
                    $context,
                    null,
                    'info',
                    'api_performance'
                );
            }
        }
    }

    /**
     * Log API errors for troubleshooting
     * 
     * @param string $endpoint API endpoint name
     * @param \Throwable $exception The exception/error that occurred
     * @param array $context Additional context data
     * @return void
     */
    private function log_api_error(string $endpoint, \Throwable $exception, array $context = []): void
    {
        // Get exception details
        $error_message = $exception->getMessage();
        $error_trace = $exception->getTraceAsString();
        
        // Log using structured logging
        $this->logDebugMessage(
            sprintf('API Error: %s - %s', $endpoint, $error_message),
            'error'
        );
        
        // Log to error tracking system
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('API error in %s: %s', $endpoint, $error_message),
                [
                    'endpoint' => $endpoint,
                    'error_message' => $error_message,
                    'error_code' => $exception->getCode(),
                    'error_file' => $exception->getFile(),
                    'error_line' => $exception->getLine(),
                    'error_trace' => $error_trace,
                    'context' => $context,
                ],
                null,
                'error',
                'api_error'
            );
        }
    }

    /**
     * Render empty entry fallback
     * 
     * @param array $log The log entry
     * @return string HTML output
     */
    private function render_empty_entry_fallback(array $log): string
    {
        $summary = esc_html($log['summary'] ?? 'Unknown event');
        $status = esc_attr($log['status'] ?? 'info');
        $event_type = esc_html($log['event_type'] ?? 'event');
        $timestamp = esc_html($log['timestamp'] ?? current_time('mysql'));
        
        return "
            <div class='odcm-timeline'>
                <div class='odcm-timeline-component odcm-status-{$status}'>
                    <div class='odcm-timeline-header'>
                        <div class='odcm-timeline-timestamp'>{$timestamp}</div>
                        <div class='odcm-timeline-title'>{$event_type}</div>
                    </div>
                    <div class='odcm-timeline-body'>
                        <div class='odcm-timeline-message'>{$summary}</div>
                    </div>
                </div>
            </div>
        ";
    }

    /**
     * Render component timeline
     * 
     * @param array $components Array of components
     * @return string HTML output
     */
    private function render_component_timeline(array $components): string
    {
        if (empty($components)) {
            return "<div class='odcm-timeline odcm-timeline-empty'>No components found</div>";
        }
        
        // Sort components by timestamp (descending)
        usort($components, function($a, $b) {
            $a_ts = $a['ts'] ?? 0;
            $b_ts = $b['ts'] ?? 0;
            return $b_ts - $a_ts;
        });
        
        $html = "<div class='odcm-timeline'>";
        
        foreach ($components as $component) {
            $label = esc_html($component['label'] ?? 'Event');
            $level = esc_attr($component['level'] ?? 'info');
            $timestamp = is_numeric($component['ts'] ?? null) ?
                         gmdate('Y-m-d H:i:s', $component['ts']) :
                         esc_html($component['ts'] ?? current_time('mysql'));
            
            $html .= "
                <div class='odcm-timeline-component odcm-level-{$level}'>
                    <div class='odcm-timeline-header'>
                        <div class='odcm-timeline-timestamp'>{$timestamp}</div>
                        <div class='odcm-timeline-title'>{$label}</div>
                    </div>
                    <div class='odcm-timeline-body'>
            ";
            
            // Add component data if available
            if (isset($component['data']) && is_array($component['data'])) {
                $html .= "<div class='odcm-timeline-data'>";
                $html .= $this->render_component_data($component['data']);
                $html .= "</div>";
            }
            
            $html .= "
                    </div>
                </div>
            ";
        }
        
        $html .= "</div>";
        
        return $html;
    }

    /**
     * Render component data
     * 
     * @param array $data Component data
     * @return string HTML output
     */
    private function render_component_data(array $data): string
    {
        $html = "<dl class='odcm-data-list'>";
        
        foreach ($data as $key => $value) {
            // Skip internal or complex objects
            if ($key === 'components' || is_resource($value)) {
                continue;
            }
            
            $key_html = esc_html($key);
            
            if (is_array($value)) {
                // Render nested array
                $value_html = "<div class='odcm-data-nested'>" . $this->render_component_data($value) . "</div>";
            } elseif (is_object($value)) {
                // Convert object to array
                $value_html = "<pre>" . esc_html(json_encode($value, JSON_PRETTY_PRINT)) . "</pre>";
            } elseif (is_bool($value)) {
                // Format boolean
                $value_html = $value ? 'true' : 'false';
            } else {
                // Default rendering
                $value_html = esc_html((string) $value);
            }
            
            $html .= "<dt>{$key_html}</dt><dd>{$value_html}</dd>";
        }
        
        $html .= "</dl>";
        
        return $html;
    }


    /**
     * Add diagnostic endpoint for debugging empty dashboards
     */
    public function diagnostic_check(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        
        $diagnostics = [];
        
        try {
            // Check if audit log table exists
            $audit_table = $wpdb->prefix . 'odcm_audit_log';
            $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';
            
            $table_check = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $audit_table
            ));
            
            $diagnostics['tables'] = [
                'audit_log_exists' => $table_check === '1',
                'audit_log_table' => $audit_table,
            ];
            
            if ($table_check === '1') {
            // Get basic stats
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for diagnostic purposes
            $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$audit_table}");
            $recent_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$audit_table} WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $completion_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$audit_table} WHERE event_type LIKE '%completion%'");
            $debug_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$audit_table} WHERE status = 'debug' OR event_type LIKE 'debug_%'");
                
                $diagnostics['log_stats'] = [
                    'total_logs' => (int) $total_logs,
                    'recent_logs_7_days' => (int) $recent_logs,
                    'completion_logs' => (int) $completion_logs,
                    'debug_logs' => (int) $debug_logs,
                ];
                
                // Get recent log samples
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                // Direct query is needed for diagnostic purposes
                $sample_logs = $wpdb->get_results("
                    SELECT id, timestamp, status, event_type, summary, order_id
                    FROM {$audit_table}
                    ORDER BY timestamp DESC
                    LIMIT 10
                ", ARRAY_A);
                
                $diagnostics['sample_logs'] = $sample_logs ?: [];
                
                // Check for filtering issues
                $filter_test = $this->build_filter_where_clauses(new \WP_REST_Request());
                $diagnostics['filter_test'] = [
                    'default_where_clauses' => $filter_test,
                    'filters_count' => count($filter_test),
                ];
            }
            
            // Cache status (caching has been removed)
            $diagnostics['cache'] = [
                'enabled' => false,
                'message' => 'Caching has been removed from AuditLogEndpoint',
            ];
            
            // Check WordPress and plugin versions
            $diagnostics['environment'] = [
                'wp_version' => get_bloginfo('version'),
                'odcm_version' => defined('ODCM_VERSION') ? ODCM_VERSION : 'unknown',
                'odcm_debug' => defined('ODCM_DEBUG') ? ODCM_DEBUG : false,
                'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'not_installed',
            ];
            
        } catch (\Exception $e) {
            $diagnostics['error'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
        
        return new WP_REST_Response([
            'diagnostics' => $diagnostics,
            'timestamp' => current_time('mysql'),
            'debug_mode' => defined('ODCM_DEBUG') && ODCM_DEBUG,
        ], 200);
    }

    /**
     * Get raw timeline data for diagnostic purposes
     * This endpoint is only available in debug mode and returns the raw timeline data
     * without rendering, which helps diagnose rendering issues
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response Raw timeline data
     */
    public function get_raw_timeline_data(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Get the log ID from the request
            $log_id = (int)$request->get_param('log_id');
            
            // Create immutable request object
            $timelineRequest = new TimelineRequest($log_id, true); // Include debug components
            
            // Ensure services are initialized
            if (!$this->timelineBuilder) {
                $this->timelineBuilder = new DatabaseTimelineBuilder(new ProcessLoggerComponentExtractor());
            }
            
            // Get the raw timeline data
            $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);
            
            // Check component data for potential issues
            $componentDiagnostics = [];
            foreach ($timelineData->components as $idx => $component) {
                // Create a diagnostic report for each component
                $componentDiagnostics[] = [
                    'index' => $idx,
                    'event_type' => $component['event_type'] ?? '-- MISSING --',
                    'label' => $component['label'] ?? '-- MISSING --',
                    'level' => $component['level'] ?? '-- MISSING --',
                    'ts' => $component['ts'] ?? '-- MISSING --',
                    'has_data' => isset($component['data']),
                    'data_is_array' => isset($component['data']) && is_array($component['data']),
                    'data_keys' => isset($component['data']) && is_array($component['data']) 
                        ? array_keys($component['data']) 
                        : [],
                    'data_sample' => isset($component['data']) && is_array($component['data'])
                        ? json_encode(array_slice($component['data'], 0, 3, true))
                        : (isset($component['data']) ? gettype($component['data']) : 'NULL'),
                ];
            }
            
            // Build the diagnostic response
            $diagnosticData = [
                'log_id' => $log_id,
                'timeline_type' => $timelineData->isProcessGroup() ? 'process_group' : 'individual',
                'component_count' => $timelineData->getComponentCount(),
                'metadata' => $timelineData->metadata,
                'component_diagnostics' => $componentDiagnostics,
                'first_component_sample' => !empty($timelineData->components) 
                    ? json_encode($timelineData->components[0], JSON_PRETTY_PRINT)
                    : 'No components found',
            ];
            
            // Add raw components if they exist but are limited to avoid huge responses
            if (!empty($timelineData->components)) {
                if (count($timelineData->components) <= 10) {
                    $diagnosticData['raw_components'] = $timelineData->components;
                } else {
                    // Just include a sample of components to avoid huge responses
                    $diagnosticData['raw_components_sample'] = array_slice($timelineData->components, 0, 5);
                    $diagnosticData['raw_components_count'] = count($timelineData->components);
                    $diagnosticData['raw_components_truncated'] = true;
                }
            }
            
            return new WP_REST_Response([
                'diagnostic_data' => $diagnosticData,
                'timestamp' => current_time('mysql'),
                'debug_mode' => true,
            ], 200);
            
        } catch (\Throwable $e) {
            // Return detailed error information
            return new WP_REST_Response([
                'error' => true,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile() . ':' . $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'timestamp' => current_time('mysql'),
            ], 200);
        }
    }
    
    /**
     * Get filter options for dynamic filters
     */
    public function get_filter_options(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;

            // Start performance monitoring
            $start_time = microtime(true);

            // Get available statuses
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for performance-critical filter options
            $statuses = $wpdb->get_col("
                SELECT DISTINCT status
                FROM {$wpdb->prefix}odcm_audit_log
                WHERE status != ''
                ORDER BY status
            ");

            // Get available event types
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for performance-critical filter options
            $event_types = $wpdb->get_col("
                SELECT DISTINCT event_type
                FROM {$wpdb->prefix}odcm_audit_log
                WHERE event_type != ''
                ORDER BY event_type
            ");

            // Get order IDs with logs
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for performance-critical filter options
            $order_ids = $wpdb->get_col("
                SELECT DISTINCT order_id
                FROM {$wpdb->prefix}odcm_audit_log
                WHERE order_id IS NOT NULL
                ORDER BY order_id DESC
                LIMIT 100
            ");

            // Format response
            $response_data = [
                'statuses' => array_values(array_filter($statuses)),
                'event_types' => array_values(array_filter($event_types)),
                'order_ids' => array_map('intval', array_filter($order_ids)),
                'meta' => [
                    'execution_time' => microtime(true) - $start_time,
                    'timestamp' => current_time('mysql'),
                    'max_results' => 100,
                ],
            ];

            return new WP_REST_Response($response_data, 200);

        } catch (\Throwable $e) {
            // Log error for debugging
            $this->log_api_error('get_filter_options', $e, []);

            return new WP_Error(
                'odcm_api_error',
                __('audit.logs.filter_options.failure', 'order-daemon'),
                ['status' => 500]
            );
        }
    }
}
