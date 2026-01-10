<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API;

use OrderDaemon\CompletionManager\Includes\Odcm_Config;
use OrderDaemon\CompletionManager\API\Timeline\AdapterRegistry;
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
                        // Allow string type for virtual log IDs (e.g. "302_0")
                        'type'              => ['integer', 'string'],
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function($value) {
                            // Accept numeric ID or virtual ID pattern "ID_Index"
                            if (is_numeric($value) && $value > 0) return true;
                            return preg_match('/^\d+_\d+$/', (string)$value);
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
     * Check delete permissions for destructive operations
     *
     * DELETE routes require higher privileges than read operations:
     * - Requires manage_woocommerce capability (not just view_woocommerce_reports)
     * - Requires valid REST nonce for CSRF protection
     * - Includes additional audit logging for security monitoring
     *
     * This implements the principle of least privilege for destructive operations.
     *
     * @param WP_REST_Request $request The REST request
     * @return bool True if permitted; false otherwise
     */
    public function check_delete_permissions(WP_REST_Request $request): bool
    {
        // Enhanced permission debugging for delete operations
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage('ODCM API Delete Permission Check:');
            $this->logDebugMessage('- User ID: ' . get_current_user_id());
            $this->logDebugMessage('- User roles: ' . implode(', ', wp_get_current_user()->roles ?? []));
            $this->logDebugMessage('- manage_woocommerce: ' . (current_user_can('manage_woocommerce') ? 'YES' : 'NO'));
            $this->logDebugMessage('- manage_options (admin): ' . (current_user_can('manage_options') ? 'YES' : 'NO'));
            $this->logDebugMessage('- is_user_logged_in: ' . (is_user_logged_in() ? 'YES' : 'NO'));
            $this->logDebugMessage('- Request method: ' . $request->get_method());
            $this->logDebugMessage('- Request URL: ' . $request->get_route());
        }

        // User must be logged in
        if (!is_user_logged_in()) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM API Delete: Permission denied - user not logged in');
            }
            return false;
        }

        // DELETE operations require manage_woocommerce capability (higher privilege than view)
        // This implements principle of least privilege - only users who can manage WooCommerce can delete logs
        $has_permission = current_user_can('manage_woocommerce') || current_user_can('manage_options');

        if (!$has_permission) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM API Delete: Permission denied - user lacks manage_woocommerce capability');
            }

            // Log delete permission failure for security monitoring
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    'Failed attempt to delete audit logs - insufficient permissions',
                    [
                        'user_id' => get_current_user_id(),
                        'user_roles' => wp_get_current_user()->roles ?? [],
                        'request_method' => $request->get_method(),
                        'request_route' => $request->get_route(),
                    ],
                    null,
                    'warning',
                    'security_delete_attempt'
                );
            }

            return false;
        }

        // Nonce verification for DELETE requests (critical for destructive operations)
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM API Delete: Permission denied - invalid or missing nonce');
                $this->logDebugMessage('- Nonce provided: ' . ($nonce ? 'YES' : 'NO'));
                $this->logDebugMessage('- Nonce value: ' . ($nonce ?: 'none'));
            }

            // Log nonce failure for security monitoring
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    'Failed attempt to delete audit logs - invalid nonce',
                    [
                        'user_id' => get_current_user_id(),
                        'nonce_provided' => $nonce ? 'yes' : 'no',
                        'request_method' => $request->get_method(),
                        'request_route' => $request->get_route(),
                    ],
                    null,
                    'warning',
                    'security_delete_attempt'
                );
            }

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
                // Flat view: NO consolidation, direct pagination of individual events
                $page_logs = $this->get_filtered_logs($request, $per_page, $page);
                $total = $this->get_filtered_log_count($request);

                // Calculate pagination
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
                    'logs' => $this->format_logs_for_flat_view($page_logs),
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
                        'view_mode' => 'flat',
                    ],
                ];

                return new WP_REST_Response($response_data, 200);
            }

            // Consolidated view: apply process grouping
            $all_logs = $this->get_all_filtered_logs($request);

            // DETAILED DEBUG: Track every step
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM: DEBUG - Result from get_all_filtered_logs: ' . (is_wp_error($all_logs) ? 'WP_Error: ' . $all_logs->get_error_message() : 'Array with ' . count($all_logs) . ' items'), 'debug');
            }

            if (is_wp_error($all_logs)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage('ODCM: DEBUG - Returning WP_Error response instead of empty array', 'error');
                }
                // Return proper error response instead of hiding the error
                return new WP_Error(
                    'odcm_database_error',
                    'Database query failed: ' . $all_logs->get_error_message(),
                    ['status' => 500]
                );
            }

            // Apply UI-only consolidation by process_id for lifecycle events
            try {
                $include_debug = (bool) $request->get_param('include_debug');
                $all_logs = $this->apply_process_id_consolidation($all_logs, $include_debug, 'consolidated');

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
                    'view_mode' => 'consolidated',
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
            $log_id_param = $request->get_param('log_id');
            $include_debug = (bool) $request->get_param('include_debug');

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: render_components called for log_id: " . $log_id_param, 'debug');
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

            // Handle virtual IDs (e.g. "302_0") by stripping the virtual suffix
            // The filtering will happen after building the timeline
            $log_id_raw = $request->get_param('log_id');
            $component_index = null;

            // Check if this is a virtual ID
            if (is_string($log_id_raw) && strpos($log_id_raw, '_') !== false) {
                $parts = explode('_', $log_id_raw);
                if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    // It's a virtual ID: use the real ID for lookup, store index for later filtering
                    $request->set_param('log_id', (int)$parts[0]);
                    $component_index = (int)$parts[1];

                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage("ODCM: Virtual ID detected: {$log_id_raw} -> Real ID: {$parts[0]}, Component Index: {$component_index}", 'debug');
                    }
                }
            }

            // Create immutable request object
            try {
                $timelineRequest = TimelineRequest::fromRestRequest($request);
            } catch (\Throwable $e) {
                throw $e;
            }

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: TimelineRequest created: log_id=" . $timelineRequest->logId . ", include_debug=" . ($timelineRequest->includeDebug ? 'true' : 'false') . ", view_mode=" . $timelineRequest->viewMode, 'debug');
            }

            // Build timeline data using injected services
            try {
                $timelineData = $this->timelineBuilder->buildTimeline($timelineRequest);

                // If a specific component index was requested (Virtual ID), filter the timeline to ONLY that component
                if ($component_index !== null) {
                    if (isset($timelineData->components[$component_index])) {
                        // Extract just the target component
                        $target_component = $timelineData->components[$component_index];

                        // Create a new TimelineData with just this single component
                        // We must use reflection or a new instance since properties are readonly
                        $timelineData = \OrderDaemon\CompletionManager\API\Timeline\TimelineData::individual(
                            $timelineData->logId,
                            [$target_component],
                            $timelineData->metadata
                        );

                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            $this->logDebugMessage("ODCM: Filtered timeline to virtual component index: {$component_index}", 'debug');
                        }
                    } else {
                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            $this->logDebugMessage("ODCM: Virtual component index {$component_index} not found in timeline", 'warning');
                        }
                    }
                }

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

            // Render timeline using injected renderer with debug parameter
            try {
                $html = $this->timelineRenderer->renderTimeline($timelineData, $timelineRequest->includeDebug);
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

            // Use proper table name escaping
            $logTableName = esc_sql($wpdb->prefix . 'odcm_audit_log');
            $payloadTableName = esc_sql($wpdb->prefix . 'odcm_audit_log_payloads');

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
                    l.parent_id,
                    l.display_data,
                    l.dedupe_key,
                    COALESCE(p.payload, l.details, %s) as payload
                FROM `{$logTableName}` l
                    LEFT JOIN `{$payloadTableName}` p ON l.payload_id = p.payload_id
                WHERE l.process_id = %s
                ORDER BY l.timestamp ASC",
                '',
                $process_id
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above via $wpdb->prepare()
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
        // CRITICAL: Show all events (including debug) when debug mode is enabled
        // This mirrors the logic from RegistryTimelineRenderer::shouldFilterDebugEvent()
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            return false;
        }

        // Check for explicit debug_only flag (highest priority)
        if (!empty($component['debug_only']) && $component['debug_only'] === true) {
            return true;
        }

        // Check for specific "Rule Processing Started" flag
        if (!empty($component['is_rule_processing_started']) && $component['is_rule_processing_started'] === true) {
            return true;
        }

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

        // Enhanced detection for incomplete rule execution events (Rule Processing Started)
        // This mirrors the logic from EnhancedTimelineBuilder::isDebugOnlyEvent()
        // and RegistryTimelineRenderer::shouldFilterDebugEvent()
        if (isset($component['event_type']) && strpos($component['event_type'], 'rule_execution') !== false) {
            $rawPayload = $component['data'] ?? $component;

            // Check if this has the full rule execution context
            $hasFullRuleExecutionContext = !empty($rawPayload['rule_execution']) && is_array($rawPayload['rule_execution']);

            // Check for processing metadata that indicates this is a processing event
            $hasProcessingData = !empty($rawPayload['data']['correlation_id']) ||
                               !empty($rawPayload['data']['process_type']) ||
                               !empty($rawPayload['data']['status']);

            // It's a debug-only event if it has processing data but lacks the full rule execution context
            if ($hasProcessingData && !$hasFullRuleExecutionContext) {
                return true;
            }

            // Additional check: if it's a rule execution event but lacks complete rule data
            $hasCompleteRuleData = !empty($rawPayload['rule_execution']['rule_name']) ||
                                  !empty($rawPayload['rule_execution']['rule_configuration']['rule_name']) ||
                                  !empty($rawPayload['rule_name']) ||
                                  !empty($rawPayload['data']['rule_name']);

            // If it has processing metadata but no complete rule data, it's a debug event
            if ($hasProcessingData && !$hasCompleteRuleData) {
                return true;
            }
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
     * All query building is inlined directly in this method.
     *
     * @param WP_REST_Request $request The REST request with filter parameters
     * @return array|WP_Error Array of log entries or WP_Error
     */
    private function get_all_filtered_logs(WP_REST_Request $request): array|WP_Error
    {
        global $wpdb;

        try {
            // Use proper table name escaping (cannot use placeholders for table names)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $log_table = esc_sql($wpdb->prefix . 'odcm_audit_log');
            $payload_table = esc_sql($wpdb->prefix . 'odcm_audit_log_payloads');

            // Extract and sanitize all filter parameters directly
            $include_debug_param = $request->get_param('include_debug');
            $include_test_param = $request->get_param('include_test');
            $include_debug = ($include_debug_param === null) ? true : (bool) $include_debug_param;
            $include_test = ($include_test_param === null) ? true : (bool) $include_test_param;

            $order_id = $request->get_param('order_id');
            $status = $request->get_param('status');
            $event_type = $request->get_param('event_type');
            $date_from = $request->get_param('date_from');
            $date_to = $request->get_param('date_to');
            $search = $request->get_param('search');

            // Build the base query
            $base_query = "SELECT l.*, p.payload FROM `{$log_table}` l LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id";

            // Build conditions and params arrays
            $conditions = [];
            $params = [];

            if (!$include_debug) {
                $conditions[] = "l.event_type NOT LIKE %s";
                $params[] = 'debug_%';
                $conditions[] = "l.status != %s";
                $params[] = 'debug';
            }

            if (!$include_test) {
                $conditions[] = "l.is_test = %d";
                $params[] = 0;
            }

            // SINGLE SOURCE OF TRUTH: Filter internal-only events from AdapterRegistry
            // These events are system noise that should never reach the frontend
            $internalOnlyEvents = AdapterRegistry::getInternalOnlyEvents();
            foreach ($internalOnlyEvents as $eventType) {
                $conditions[] = "l.event_type != %s";
                $params[] = $eventType;
            }

            if (!empty($order_id) && is_numeric($order_id)) {
                $conditions[] = "l.order_id = %d";
                $params[] = absint($order_id);
            }

            if (!empty($status)) {
                $conditions[] = "l.status = %s";
                $params[] = sanitize_key($status);
            }

            if (!empty($event_type)) {
                $conditions[] = "l.event_type = %s";
                $params[] = sanitize_key($event_type);
            }

            if (!empty($date_from)) {
                $conditions[] = "l.timestamp >= %s";
                $params[] = sanitize_text_field($date_from);
            }

            if (!empty($date_to)) {
                $conditions[] = "l.timestamp <= %s";
                $params[] = sanitize_text_field($date_to);
            }

            if (!empty($search)) {
                $conditions[] = "l.summary LIKE %s";
                $params[] = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
            }

            // Build final query
            if (!empty($conditions)) {
                $where_clause = '';
                $condition_count = count($conditions);
                for ($i = 0; $i < $condition_count; $i++) {
                    $where_clause .= $conditions[$i];
                    if ($i < $condition_count - 1) {
                        $where_clause .= ' AND ';
                    }
                }
                $full_query = $base_query . " WHERE " . $where_clause . " ORDER BY l.timestamp DESC";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is dynamically built with proper escaping
                $sql = $wpdb->prepare($full_query, ...$params);
            } else {
                $sql = $base_query . " ORDER BY l.timestamp DESC";
            }

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: Executing all_filtered_logs query: " . $sql, 'debug');
            }

            // Execute the query
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built with proper escaping above
            $logs = $wpdb->get_results($sql, ARRAY_A);

            // Check for database errors
            if ($wpdb->last_error) {
                $error_msg = "Database query failed: " . $wpdb->last_error;
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: SQL Error in get_all_filtered_logs: " . $wpdb->last_error, 'error');
                    $this->logDebugMessage("ODCM: Query was: " . $sql, 'debug');
                }
                return new WP_Error('audit_log_query_failed', $error_msg);
            }

            if ($logs === false) {
                $error_msg = "Database query returned false";
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: Query returned false for get_all_filtered_logs", 'error');
                }
                return new WP_Error('audit_log_query_failed', $error_msg);
            }

            $result = $logs ?: [];

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: get_all_filtered_logs returned " . count($result) . " logs", 'debug');
            }

            return $result;

        } catch (\Exception $e) {
            $error_msg = "Exception in get_all_filtered_logs: " . $e->getMessage();
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: " . $error_msg, 'error');
            }
            return new WP_Error('audit_log_query_exception', $error_msg);
        }
    }

    /**
     * Get filtered logs with pagination
     *
     * All query building is inlined directly in this method.
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
            // Generate cache key for this query
            $cache_key = $this->get_cache_key('filtered_logs', $request, ['per_page' => $per_page, 'page' => $page]);
            $cache_group = 'odcm_audit_logs';
            $cache_ttl = 300; // 5 minutes cache TTL

            // Try to get cached result
            $cached_result = wp_cache_get($cache_key, $cache_group);

            if ($cached_result !== false) {
                // Cache hit - return cached data
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: Cache hit for filtered_logs with key: " . $cache_key, 'debug');
                }

                return $cached_result;
            }

            // Cache miss - perform database query
            // Calculate offset based on pagination
            $offset = max(0, ($page - 1) * $per_page);

            // Use proper table name escaping
            $log_table = esc_sql($wpdb->prefix . 'odcm_audit_log');
            $payload_table = esc_sql($wpdb->prefix . 'odcm_audit_log_payloads');

            // Extract and sanitize all filter parameters directly
            $include_debug_param = $request->get_param('include_debug');
            $include_test_param = $request->get_param('include_test');
            $include_debug = ($include_debug_param === null) ? true : (bool) $include_debug_param;
            $include_test = ($include_test_param === null) ? true : (bool) $include_test_param;

            $order_id = $request->get_param('order_id');
            $status = $request->get_param('status');
            $event_type = $request->get_param('event_type');
            $date_from = $request->get_param('date_from');
            $date_to = $request->get_param('date_to');
            $search = $request->get_param('search');

            // Build base query
            $base_query = "SELECT l.*, p.payload FROM `{$log_table}` l LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id";

            // Build conditions and params arrays
            $conditions = [];
            $params = [];

            if (!$include_debug) {
                $conditions[] = "l.event_type NOT LIKE %s";
                $params[] = 'debug_%';
                $conditions[] = "l.status != %s";
                $params[] = 'debug';
            }

            if (!$include_test) {
                $conditions[] = "l.is_test = %d";
                $params[] = 0;
            }

            // Filter out rule_no_match events at database level
            // rule_no_match is an internal system event that is just noise to users
            $conditions[] = "l.event_type != %s";
            $params[] = 'rule_no_match';

            if (!empty($order_id) && is_numeric($order_id)) {
                $conditions[] = "l.order_id = %d";
                $params[] = absint($order_id);
            }

            if (!empty($status)) {
                $conditions[] = "l.status = %s";
                $params[] = sanitize_key($status);
            }

            if (!empty($event_type)) {
                $conditions[] = "l.event_type = %s";
                $params[] = sanitize_key($event_type);
            }

            if (!empty($date_from)) {
                $conditions[] = "l.timestamp >= %s";
                $params[] = sanitize_text_field($date_from);
            }

            if (!empty($date_to)) {
                $conditions[] = "l.timestamp <= %s";
                $params[] = sanitize_text_field($date_to);
            }

            if (!empty($search)) {
                $conditions[] = "l.summary LIKE %s";
                $params[] = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
            }

            // Add pagination params
            $params[] = absint($per_page);
            $params[] = absint($offset);

            // Build final query
            if (!empty($conditions)) {
                $where_clause = '';
                $condition_count = count($conditions);
                for ($i = 0; $i < $condition_count; $i++) {
                    $where_clause .= $conditions[$i];
                    if ($i < $condition_count - 1) {
                        $where_clause .= ' AND ';
                    }
                }
                $full_query = $base_query . " WHERE " . $where_clause . " ORDER BY l.timestamp DESC LIMIT %d OFFSET %d";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is dynamically built with proper escaping
                $sql = $wpdb->prepare($full_query, ...$params);
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is dynamically built with proper escaping
                $sql = $wpdb->prepare($base_query . " ORDER BY l.timestamp DESC LIMIT %d OFFSET %d", absint($per_page), absint($offset));
            }

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: Executing filtered_logs query: " . $sql, 'debug');
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built with proper escaping above
            $logs = $wpdb->get_results($sql, ARRAY_A);

            // Check for database errors
            if ($wpdb->last_error) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: SQL Error in get_filtered_logs: " . $wpdb->last_error, 'error');
                    $this->logDebugMessage("ODCM: Query was: " . $sql, 'debug');
                }
                throw new \Exception($wpdb->last_error ?: 'Database query failed');
            }

            if ($logs === false) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: Query returned false for get_filtered_logs", 'error');
                }
                throw new \Exception('Database query returned false');
            }

            $result = $logs ?: [];

            // Cache the result
            wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: Cached filtered_logs result with key: " . $cache_key, 'debug');
            }

            return $result;

        } catch (\Exception $e) {
            $this->logDebugMessage("ODCM: Failed to get filtered logs: " . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Get count of filtered logs
     *
     * All query building is inlined directly in this method.
     *
     * @param WP_REST_Request $request The REST request
     * @return int Count of filtered logs
     */
    private function get_filtered_log_count(WP_REST_Request $request): int
    {
        global $wpdb;

        try {
            // Generate cache key for this query
            $cache_key = $this->get_cache_key('filtered_log_count', $request);
            $cache_group = 'odcm_audit_logs';
            $cache_ttl = 60; // 1 minute cache TTL (shorter since counts change more frequently)

            // Try to get cached result
            $cached_result = wp_cache_get($cache_key, $cache_group);

            if ($cached_result !== false) {
                // Cache hit - return cached data
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: Cache hit for filtered_log_count with key: " . $cache_key, 'debug');
                }

                return $cached_result;
            }

            // Cache miss - perform database query
            // Use proper table name escaping (cannot use placeholders for table names)
            $log_table = esc_sql($wpdb->prefix . 'odcm_audit_log');

            // Extract and sanitize all filter parameters directly
            $include_debug_param = $request->get_param('include_debug');
            $include_test_param = $request->get_param('include_test');
            $include_debug = ($include_debug_param === null) ? true : (bool) $include_debug_param;
            $include_test = ($include_test_param === null) ? true : (bool) $include_test_param;

            $order_id = $request->get_param('order_id');
            $status = $request->get_param('status');
            $event_type = $request->get_param('event_type');
            $date_from = $request->get_param('date_from');
            $date_to = $request->get_param('date_to');
            $search = $request->get_param('search');

            // Build base query
            $base_query = "SELECT COUNT(*) FROM `{$log_table}` l";

            // Build conditions and params arrays
            $conditions = [];
            $params = [];

            if (!$include_debug) {
                $conditions[] = "l.event_type NOT LIKE %s";
                $params[] = 'debug_%';
                $conditions[] = "l.status != %s";
                $params[] = 'debug';
            }

            if (!$include_test) {
                $conditions[] = "l.is_test = %d";
                $params[] = 0;
            }

            // Filter out rule_no_match events at database level
            // rule_no_match is an internal system event that is just noise to users
            $conditions[] = "l.event_type != %s";
            $params[] = 'rule_no_match';

            if (!empty($order_id) && is_numeric($order_id)) {
                $conditions[] = "l.order_id = %d";
                $params[] = absint($order_id);
            }

            if (!empty($status)) {
                $conditions[] = "l.status = %s";
                $params[] = sanitize_key($status);
            }

            if (!empty($event_type)) {
                $conditions[] = "l.event_type = %s";
                $params[] = sanitize_key($event_type);
            }

            if (!empty($date_from)) {
                $conditions[] = "l.timestamp >= %s";
                $params[] = sanitize_text_field($date_from);
            }

            if (!empty($date_to)) {
                $conditions[] = "l.timestamp <= %s";
                $params[] = sanitize_text_field($date_to);
            }

            if (!empty($search)) {
                $conditions[] = "l.summary LIKE %s";
                $params[] = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
            }

            // Build final query
            if (!empty($conditions)) {
                $where_clause = '';
                $condition_count = count($conditions);
                for ($i = 0; $i < $condition_count; $i++) {
                    $where_clause .= $conditions[$i];
                    if ($i < $condition_count - 1) {
                        $where_clause .= ' AND ';
                    }
                }
                $full_query = $base_query . " WHERE " . $where_clause;
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is dynamically built with proper escaping
                $sql = $wpdb->prepare($full_query, ...$params);
            } else {
                $sql = $base_query;
            }

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: Executing filtered_log_count query: " . $sql, 'debug');
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built with proper escaping above
            $count = $wpdb->get_var($sql);

            // Check for database errors
            if ($wpdb->last_error) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: SQL Error in get_filtered_log_count: " . $wpdb->last_error, 'error');
                    $this->logDebugMessage("ODCM: Query was: " . $sql, 'debug');
                }
                throw new \Exception($wpdb->last_error ?: 'Database query failed');
            }

            if ($count === false) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM: Query returned false for get_filtered_log_count", 'error');
                }
                throw new \Exception('Database query returned false');
            }

            $result = (int) $count;

            // Cache the result
            wp_cache_set($cache_key, $result, $cache_group, $cache_ttl);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM: Cached filtered_log_count result with key: " . $cache_key, 'debug');
            }

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
     * @return array Array of WHERE clauses with parameters for safe query building
     */
    private function build_filter_where_clauses(WP_REST_Request $request): array
    {
        global $wpdb;
        $where_clauses = [];
        $where_params = [];

        // Resolve default flags for debug/test inclusion
        // Default to true (include all) when not explicitly provided to avoid filtering out legitimate events
        $include_debug_param = $request->get_param('include_debug');
        $include_test_param  = $request->get_param('include_test');

        // Default to true (include all) when not explicitly provided
        // This ensures we don't accidentally filter out legitimate events
        $include_debug = ($include_debug_param === null) ? true : (bool) $include_debug_param;
        $include_test  = ($include_test_param === null)  ? true : (bool) $include_test_param;

        // Include debug events
        if (!$include_debug) {
            $where_clauses[] = "l.event_type NOT LIKE %s";
            $where_params[] = 'debug_%';
            $where_clauses[] = "l.status != %s";
            $where_params[] = 'debug';
        }

        // Include test events
        if (!$include_test) {
            $where_clauses[] = "l.is_test = %d";
            $where_params[] = 0;
        }

        // Filter out rule_no_match events at database level
        // rule_no_match is an internal system event that is just noise to users
        $where_clauses[] = "l.event_type != %s";
        $where_params[] = 'rule_no_match';

        // Order ID filter
        $order_id = $request->get_param('order_id');
        if (!empty($order_id) && is_numeric($order_id)) {
            $where_clauses[] = "l.order_id = %d";
            $where_params[] = (int) $order_id;
        }

        // Status filter
        $status = $request->get_param('status');
        if (!empty($status)) {
            $where_clauses[] = "l.status = %s";
            $where_params[] = sanitize_key($status);
        }

        // Event type filter
        $event_type = $request->get_param('event_type');
        if (!empty($event_type)) {
            $where_clauses[] = "l.event_type = %s";
            $where_params[] = sanitize_key($event_type);
        }

        // Date range filters
        $date_from = $request->get_param('date_from');
        if (!empty($date_from)) {
            $where_clauses[] = "l.timestamp >= %s";
            $where_params[] = sanitize_text_field($date_from);
        }

        $date_to = $request->get_param('date_to');
        if (!empty($date_to)) {
            $where_clauses[] = "l.timestamp <= %s";
            $where_params[] = sanitize_text_field($date_to);
        }

        // Search filter
        $search = $request->get_param('search');
        if (!empty($search)) {
            $where_clauses[] = "l.summary LIKE %s";
            $where_params[] = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
        }

        // Debug logging of where clauses to help diagnose empty dashboards
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            try {
                $this->logDebugMessage('ODCM: build_filter_where_clauses => ' . implode(' AND ', $where_clauses));
                $this->logDebugMessage('ODCM: build_filter_where_clauses params => ' . implode(', ', $where_params));
            } catch (\Throwable $e) {
                // noop
            }
        }

        return $where_clauses;
    }

    /**
     * Build WHERE clauses with parameters for safe query building
     *
     * @param WP_REST_Request $request The REST request
     * @return array Array with two elements: [WHERE clauses array, parameters array]
     */
    private function build_filter_where_clauses_with_params(WP_REST_Request $request): array
    {
        global $wpdb;
        $where_clauses = [];
        $where_params = [];

        // Resolve default flags for debug/test inclusion
        // Default to true (include all) when not explicitly provided to avoid filtering out legitimate events
        $include_debug_param = $request->get_param('include_debug');
        $include_test_param  = $request->get_param('include_test');

        // Default to true (include all) when not explicitly provided
        // This ensures we don't accidentally filter out legitimate events
        $include_debug = ($include_debug_param === null) ? true : (bool) $include_debug_param;
        $include_test  = ($include_test_param === null)  ? true : (bool) $include_test_param;

        // Include debug events
        if (!$include_debug) {
            $where_clauses[] = "l.event_type NOT LIKE %s";
            $where_params[] = 'debug_%';
            $where_clauses[] = "l.status != %s";
            $where_params[] = 'debug';
        }

        // Include test events
        if (!$include_test) {
            $where_clauses[] = "l.is_test = %d";
            $where_params[] = 0;
        }

        // Order ID filter
        $order_id = $request->get_param('order_id');
        if (!empty($order_id) && is_numeric($order_id)) {
            $where_clauses[] = "l.order_id = %d";
            $where_params[] = (int) $order_id;
        }

        // Status filter
        $status = $request->get_param('status');
        if (!empty($status)) {
            $where_clauses[] = "l.status = %s";
            $where_params[] = sanitize_key($status);
        }

        // Event type filter
        $event_type = $request->get_param('event_type');
        if (!empty($event_type)) {
            $where_clauses[] = "l.event_type = %s";
            $where_params[] = sanitize_key($event_type);
        }

        // Date range filters
        $date_from = $request->get_param('date_from');
        if (!empty($date_from)) {
            $where_clauses[] = "l.timestamp >= %s";
            $where_params[] = sanitize_text_field($date_from);
        }

        $date_to = $request->get_param('date_to');
        if (!empty($date_to)) {
            $where_clauses[] = "l.timestamp <= %s";
            $where_params[] = sanitize_text_field($date_to);
        }

        // Search filter
        $search = $request->get_param('search');
        if (!empty($search)) {
            $where_clauses[] = "l.summary LIKE %s";
            $where_params[] = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
        }

        return [$where_clauses, $where_params];
    }

    /**
     * Apply process ID consolidation for lifecycle events
     *
     * @param array $logs Array of log entries
     * @param bool $include_debug Whether to include debug events
     * @param string $view_mode The current view mode ('flat' or 'consolidated')
     * @return array Consolidated log entries
     */
    private function apply_process_id_consolidation(array $logs, bool $include_debug, string $view_mode = 'consolidated'): array
    {
        // If flat view mode, return logs unchanged (no consolidation)
        if ($view_mode === 'flat') {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM Consolidation: SKIPPED - flat view mode requested', 'debug');
            }
            return $logs;
        }

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
     * Generate a cache key based on request parameters
     *
     * @param string $prefix Cache key prefix
     * @param WP_REST_Request|null $request The REST request
     * @param array $additional_params Additional parameters to include in cache key
     * @return string Generated cache key
     */
    private function get_cache_key(string $prefix, ?WP_REST_Request $request = null, array $additional_params = []): string
    {
        $params = $additional_params;

        if ($request instanceof WP_REST_Request) {
            // Get relevant request parameters for cache key
            $request_params = $request->get_params();

            // Filter out parameters that shouldn't affect caching
            $cache_relevant_params = [
                'page', 'per_page', 'view', 'include_debug', 'include_test',
                'order_id', 'status', 'event_type', 'date_from', 'date_to', 'search'
            ];

            foreach ($cache_relevant_params as $param) {
                if (isset($request_params[$param])) {
                    $params[$param] = $request_params[$param];
                }
            }
        }

        // Sort parameters for consistent cache key generation
        ksort($params);

        // Generate cache key hash
        $cache_key = $prefix . '_' . md5(serialize($params));

        return $cache_key;
    }

    /**
     * Invalidate caches related to audit logs
     *
     * @param string|null $specific_key Optional specific cache key to invalidate
     * @return void
     */
    private function invalidate_related_caches(?string $specific_key = null): void
    {
        // Invalidate specific cache if provided
        if ($specific_key !== null) {
            wp_cache_delete($specific_key, 'odcm_audit_logs');
        }

        // Invalidate common cache patterns
        $cache_patterns = [
            'all_filtered_logs',
            'filtered_logs',
            'filtered_log_count',
            'filter_options',
            'logs_by_process'
        ];

        foreach ($cache_patterns as $pattern) {
            // This will clear all caches with this prefix
            // Note: WordPress doesn't support wildcard cache deletion natively,
            // so we rely on cache expiration for non-specific invalidation
        }
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
            'enabled' => true,
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
     * Format logs for API response - FLAT/INDIVIDUAL VIEW
     * This method ensures that each log entry is treated individually without any consolidation.
     * CRITICAL: It also explodes composite log entries (which essentially contain multiple
     * events in their payload) into distinct virtual entries.
     *
     * @param array $logs Array of log entries
     * @return array Formatted logs
     */
    private function format_logs_for_flat_view(array $logs): array
    {
        $formatted_logs = [];

        foreach ($logs as $log) {
            // For flat view, ensure we have a valid log_id
            $log_id = $log['log_id'] ?? 0;

            // Check if payload contains multiple components to explode
            $payload_data = null;
            if (!empty($log['payload'])) {
                $payload_data = json_decode($log['payload'], true);
            }

            // Check if this is a composite entry that needs explosion
            // Only explode if 'components' exists and has more than 1 item
            if (isset($payload_data['components']) && is_array($payload_data['components']) && count($payload_data['components']) > 1) {

                foreach ($payload_data['components'] as $index => $component) {
                    // Create virtual ID: "realID_index" (e.g. 302_0, 302_1)
                    // Frontend treats strings as IDs fine
                    $virtual_id = $log_id . '_' . $index;

                    $formatted_log = [
                        'id' => $virtual_id,
                        'original_id' => (int) $log_id,
                        // Use component timestamp if available, else row timestamp
                        'timestamp' => $component['ts'] ? gmdate('Y-m-d H:i:s', (int)$component['ts']) : $log['timestamp'],
                        // Use component label/message as summary
                        'summary' => $component['label'] ?? ($component['data']['message'] ?? $log['summary']),
                        'status' => $component['level'] ?? $log['status'],
                        'event_type' => $component['event_type'] ?? $log['event_type'],
                        'is_process_group' => false,
                        'is_virtual' => true,
                        'component_index' => $index
                    ];

                    if (!empty($log['order_id'])) {
                        $formatted_log['order_id'] = (int) $log['order_id'];
                    }

                    if (!empty($log['payload_id'])) {
                        $formatted_log['payload_id'] = (int) $log['payload_id'];
                    }

                    $formatted_logs[] = $formatted_log;
                }

                continue; // Skip the standard processing since we added virtual ones
            }

            // Standard processing for atomic or empty logs
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

            // Add payload_id if available (for rendering components)
            if (!empty($log['payload_id'])) {
                $formatted_log['payload_id'] = (int) $log['payload_id'];
            }

            $formatted_log['is_process_group'] = false;

            // Also explicitly remove any process-related metadata that might exist
            unset($formatted_log['process_id'], $formatted_log['process_count']);
            unset($formatted_log['_is_process_group'], $formatted_log['_process_count']);
            unset($formatted_log['_process_logs']);

            $formatted_logs[] = $formatted_log;
        }

        return $formatted_logs;
    }

    /**
     * Extract the true execution status for rule execution events
     *
     * @param array $log The log entry
     * @return string The true execution status
     */
    private function extractRuleExecutionStatus(array $log): string
    {
        // Only process rule execution events
        if (($log['event_type'] ?? '') !== 'rule_execution') {
            return $log['status'] ?? 'unknown';
        }

        // Try to extract status from the payload if available
        $payload = $log['payload'] ?? '';
        if (!empty($payload)) {
            $payload_data = json_decode($payload, true);
            if (is_array($payload_data)) {
                // Check for execution_status first (SUCCESS/FAILED from action results)
                if (!empty($payload_data['execution_status'])) {
                    return $payload_data['execution_status'];
                }

                // Check for rule_execution.status
                if (!empty($payload_data['rule_execution']['status'])) {
                    return $payload_data['rule_execution']['status'];
                }

                // Check for status in data
                if (!empty($payload_data['status'])) {
                    return $payload_data['status'];
                }
            }
        }

        // Fallback: use 'executed' for complete rule execution events
        // This matches the logic in RuleExecutionAdapter
        return 'executed';
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

            // For consolidated entries, use the highest priority event's summary if available
            $summary = $log['summary'];
            if (isset($log['_is_process_group']) && $log['_is_process_group'] && isset($log['_process_logs'])) {
                $highestPrioritySummary = $this->getHighestPrioritySummaryFromProcessLogs($log['_process_logs']);
                if ($highestPrioritySummary !== null) {
                    $summary = $highestPrioritySummary;
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage('ODCM Format: Using highest priority summary for consolidated entry: ' . $summary, 'debug');
                    }
                }
            }

            // Extract the true status for rule execution events
            $status = $this->extractRuleExecutionStatus($log);

            $formatted_log = [
                'id' => (int) $log_id,
                'timestamp' => $log['timestamp'],
                'status' => $status,
                'summary' => $summary,
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

            // Add timeline redesign fields
            if (isset($log['parent_id']) && $log['parent_id'] !== null) {
                $formatted_log['parent_id'] = (int) $log['parent_id'];
            }

            if (isset($log['display_data']) && !empty($log['display_data'])) {
                $formatted_log['display_data'] = json_decode($log['display_data'], true);
            }

            if (isset($log['dedupe_key']) && !empty($log['dedupe_key'])) {
                $formatted_log['dedupe_key'] = $log['dedupe_key'];
            }

            // Add debug information for consolidated entries
            if (isset($log['_is_process_group']) && $log['_is_process_group'] && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage('ODCM Format: Formatted consolidated entry - ID: ' . $formatted_log['id'] . ', Process ID: ' . ($log['process_id'] ?? 'N/A') . ', Process Count: ' . ($log['_process_count'] ?? 'N/A') . ', Summary: ' . $summary, 'debug');
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

        // Use proper table name escaping
        $logTableName = esc_sql($wpdb->prefix . 'odcm_audit_log');

        // Build a safe IN clause with proper escaping
        $placeholders = array_fill(0, count($log_ids), '%d');
        $placeholder_string = implode(',', $placeholders);

        // Validate log IDs exist using prepared statement with proper parameter binding
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        // Direct query is needed for performance-critical batch operations
        $sql = $wpdb->prepare(
            "SELECT log_id
            FROM `{$logTableName}`
            WHERE log_id IN ({$placeholder_string})",
            ...$log_ids
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above via $wpdb->prepare()
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

        // Use proper table name escaping
        $logTableName = esc_sql($wpdb->prefix . 'odcm_audit_log');
        $payloadTableName = esc_sql($wpdb->prefix . 'odcm_audit_log_payloads');

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Build safe IN clause for log IDs
            $log_placeholders = array_fill(0, count($log_ids), '%d');
            $log_placeholder_string = implode(',', $log_placeholders);

            // Get payload IDs to delete using prepared statement
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Direct query is needed for performance-critical batch operations
            $payload_ids_query = $wpdb->prepare(
                "SELECT DISTINCT payload_id
                FROM `{$logTableName}`
                WHERE log_id IN ({$log_placeholder_string}) AND payload_id IS NOT NULL",
                ...$log_ids
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $payload_ids_query is prepared above via $wpdb->prepare()
            $payload_ids = $wpdb->get_col($payload_ids_query);

            // Delete logs using prepared statement
            $delete_logs_query = $wpdb->prepare(
                "DELETE FROM `{$logTableName}`
                WHERE log_id IN ({$log_placeholder_string})",
                ...$log_ids
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $delete_logs_query is prepared above via $wpdb->prepare()
            $deleted = $wpdb->query($delete_logs_query);

            // Delete orphaned payloads
            if (!empty($payload_ids)) {
                // Build safe IN clause for payload IDs
                $payload_placeholders = array_fill(0, count($payload_ids), '%d');
                $payload_placeholder_string = implode(',', $payload_placeholders);

                // Get payloads still in use using prepared statement
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                // Direct query is needed for performance-critical batch operations
                $used_payloads_query = $wpdb->prepare(
                    "SELECT DISTINCT payload_id
                    FROM `{$logTableName}`
                    WHERE payload_id IN ({$payload_placeholder_string})",
                    ...$payload_ids
                );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $used_payloads_query is prepared above via $wpdb->prepare()
                $used_payloads = $wpdb->get_col($used_payloads_query);

                // Calculate orphaned payloads
                $orphaned_payloads = array_diff($payload_ids, $used_payloads);

                // Delete orphaned payloads using prepared statement
                if (!empty($orphaned_payloads)) {
                    $orphaned_placeholders = array_fill(0, count($orphaned_payloads), '%d');
                    $orphaned_placeholder_string = implode(',', $orphaned_placeholders);

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    // Direct query is needed for performance-critical batch operations
                    $delete_payloads_query = $wpdb->prepare(
                        "DELETE FROM `{$payloadTableName}`
                        WHERE id IN ({$orphaned_placeholder_string})",
                        ...$orphaned_payloads
                    );
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $delete_payloads_query is prepared above via $wpdb->prepare()
                    $wpdb->query($delete_payloads_query);
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
            $audit_table = esc_sql($wpdb->prefix . 'odcm_audit_log');
            $payload_table = esc_sql($wpdb->prefix . 'odcm_audit_log_payloads');

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
            $recent_logs = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table} WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)"
            ));
            $completion_logs = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table} WHERE event_type LIKE %s",
                '%completion%'
            ));
            $debug_logs = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table} WHERE status = %s OR event_type LIKE %s",
                'debug', 'debug_%'
            ));

                $diagnostics['log_stats'] = [
                    'total_logs' => (int) $total_logs,
                    'recent_logs_7_days' => (int) $recent_logs,
                    'completion_logs' => (int) $completion_logs,
                    'debug_logs' => (int) $debug_logs,
                ];

                // Get recent log samples
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                // Direct query is needed for diagnostic purposes
                $sample_logs = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, timestamp, status, event_type, summary, order_id
                    FROM {$audit_table}
                    ORDER BY timestamp DESC
                    LIMIT %d",
                    10
                ), ARRAY_A);

                $diagnostics['sample_logs'] = $sample_logs ?: [];

                // Check for filtering issues
                $filter_test = $this->build_filter_where_clauses(new \WP_REST_Request());
                $diagnostics['filter_test'] = [
                    'default_where_clauses' => $filter_test,
                    'filters_count' => count($filter_test),
                ];
            }

            // Cache status
            $diagnostics['cache'] = [
                'enabled' => true,
                'message' => 'WordPress object caching is enabled for audit log queries',
                'cache_groups' => ['odcm_audit_logs'],
                'cache_ttl' => [
                    'filtered_logs' => '5 minutes',
                    'filtered_log_count' => '1 minute',
                ],
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
     * Get the highest priority summary from process logs for consolidated entries
     *
     * @param array $processLogs Array of log entries in a process group
     * @return string|null The highest priority summary, or null if no suitable summary found
     */
    private function getHighestPrioritySummaryFromProcessLogs(array $processLogs): ?string
    {
        if (empty($processLogs)) {
            return null;
        }

        // Helper functions for event type checking
        $isRuleExecutionEvent = function($log) {
            return $log['event_type'] === 'rule_execution' ||
                   strpos($log['event_type'], 'rule_execution_') === 0;
        };

        $isRuleErrorEvent = function($log) use ($isRuleExecutionEvent) {
            // Check for rule execution events with error status
            if ($isRuleExecutionEvent($log)) {
                $status = $log['status'] ?? '';
                return strtolower($status) === 'error' || strtolower($status) === 'failed';
            }

            // Check for specific rule error event types
            return strpos($log['event_type'], 'rule_error_') === 0 ||
                   strpos($log['event_type'], 'rule_failed_') === 0;
        };

        $isErrorEvent = function($log) use ($isRuleErrorEvent) {
            $status = $log['status'] ?? '';
            return strtolower($status) === 'error' ||
                   strtolower($status) === 'failed' ||
                   strpos($log['event_type'], 'error_') === 0 ||
                   strpos($log['event_type'], '_error') !== false;
        };

        $isOrderStatusChangeEvent = function($log) {
            return $log['event_type'] === 'status_changed' ||
                   strpos($log['event_type'], 'status_change_') === 0 ||
                   strpos($log['event_type'], 'order_status_') === 0;
        };

        $isPaymentEvent = function($log) {
            return strpos($log['event_type'], 'payment_') === 0 ||
                   strpos($log['event_type'], '_payment') !== false ||
                   $log['event_type'] === 'checkout_processed';
        };

        $isOrderEvent = function($log) use ($isOrderStatusChangeEvent, $isPaymentEvent) {
            return strpos($log['event_type'], 'order_') === 0 ||
                   strpos($log['event_type'], '_order') !== false ||
                   $log['event_type'] === 'status_changed';
        };

        // Priority 1: Rule execution events (most recent)
        $ruleExecutionLogs = array_filter($processLogs, $isRuleExecutionEvent);
        if (!empty($ruleExecutionLogs)) {
            usort($ruleExecutionLogs, function($a, $b) {
                return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
            });
            return $ruleExecutionLogs[0]['summary'] ?? null;
        }

        // Priority 2: Rule errors (oldest)
        $ruleErrorLogs = array_filter($processLogs, $isRuleErrorEvent);
        if (!empty($ruleErrorLogs)) {
            usort($ruleErrorLogs, function($a, $b) {
                return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
            });
            return $ruleErrorLogs[0]['summary'] ?? null;
        }

        // Priority 3: Any other errors (oldest)
        $errorLogs = array_filter($processLogs, function($log) use ($isErrorEvent, $isRuleErrorEvent) {
            return $isErrorEvent($log) && !$isRuleErrorEvent($log);
        });
        if (!empty($errorLogs)) {
            usort($errorLogs, function($a, $b) {
                return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
            });
            return $errorLogs[0]['summary'] ?? null;
        }

        // Priority 4: Most recent order status change
        $statusChangeLogs = array_filter($processLogs, $isOrderStatusChangeEvent);
        if (!empty($statusChangeLogs)) {
            usort($statusChangeLogs, function($a, $b) {
                return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
            });
            return $statusChangeLogs[0]['summary'] ?? null;
        }

        // Priority 5: Most recent payment event
        $paymentLogs = array_filter($processLogs, $isPaymentEvent);
        if (!empty($paymentLogs)) {
            usort($paymentLogs, function($a, $b) {
                return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
            });
            return $paymentLogs[0]['summary'] ?? null;
        }

        // Priority 6: Most recent order event
        $orderLogs = array_filter($processLogs, $isOrderEvent);
        if (!empty($orderLogs)) {
            usort($orderLogs, function($a, $b) {
                return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
            });
            return $orderLogs[0]['summary'] ?? null;
        }

        // Fallback: Return the most recent log's summary
        usort($processLogs, function($a, $b) {
            return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
        });
        return $processLogs[0]['summary'] ?? null;
    }

    /**
     * Apply filters to query based on request parameters
     * This is a shared filtering method used by both API endpoints and export functionality
     *
     * @param WP_REST_Request $request The REST request with filter parameters
     * @param array &$where_conditions Array to populate with WHERE conditions
     * @param array &$where_values Array to populate with parameter values for prepared statements
     * @return void
     */
    public static function apply_filters_to_query(WP_REST_Request $request, array &$where_conditions, array &$where_values): void
    {
        global $wpdb;

        // Extract and sanitize all filter parameters directly
        $include_debug_param = $request->get_param('include_debug');
        $include_test_param = $request->get_param('include_test');
        $include_debug = ($include_debug_param === null) ? true : (bool) $include_debug_param;
        $include_test = ($include_test_param === null) ? true : (bool) $include_test_param;

        $order_id = $request->get_param('order_id');
        $status = $request->get_param('status');
        $event_type = $request->get_param('event_type');
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');
        $search = $request->get_param('search');

        // Include debug events
        if (!$include_debug) {
            $where_conditions[] = "l.event_type NOT LIKE %s";
            $where_values[] = 'debug_%';
            $where_conditions[] = "l.status != %s";
            $where_values[] = 'debug';
        }

        // Include test events
        if (!$include_test) {
            $where_conditions[] = "l.is_test = %d";
            $where_values[] = 0;
        }

        // Order ID filter
        if (!empty($order_id) && is_numeric($order_id)) {
            $where_conditions[] = "l.order_id = %d";
            $where_values[] = (int) $order_id;
        }

        // Status filter
        if (!empty($status)) {
            $where_conditions[] = "l.status = %s";
            $where_values[] = sanitize_key($status);
        }

        // Event type filter
        if (!empty($event_type)) {
            $where_conditions[] = "l.event_type = %s";
            $where_values[] = sanitize_key($event_type);
        }

        // Date range filters
        if (!empty($date_from)) {
            $where_conditions[] = "l.timestamp >= %s";
            $where_values[] = sanitize_text_field($date_from);
        }

        if (!empty($date_to)) {
            $where_conditions[] = "l.timestamp <= %s";
            $where_values[] = sanitize_text_field($date_to);
        }

        // Search filter
        $search = $request->get_param('search');
        if (!empty($search)) {
            $where_conditions[] = "l.summary LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
        }
    }

    /**
     * Enhanced search method that searches both summary and payload content
     *
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response Response with search results
     */
    public function search_logs_comprehensive(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            $start_time = microtime(true);

            $search_term = $request->get_param('search');
            $search_term = trim($search_term);

            if (empty($search_term)) {
                return new WP_REST_Response([
                    'logs' => [],
                    'pagination' => [
                        'total' => 0,
                        'total_pages' => 0,
                        'current_page' => 1,
                        'per_page' => 20,
                    ],
                    'meta' => [
                        'execution_time' => microtime(true) - $start_time,
                        'timestamp' => current_time('mysql'),
                    ],
                ], 200);
            }

            // Sanitize and prepare search term
            $safe_search_term = '%' . $wpdb->esc_like(sanitize_text_field($search_term)) . '%';

            // Get pagination parameters
            $per_page = $request->get_param('per_page') ?: 20;
            $page = $request->get_param('page') ?: 1;
            $per_page = max(1, min(200, (int) $per_page));
            $page = max(1, (int) $page);
            $offset = max(0, ($page - 1) * $per_page);

            // Use proper table name escaping
            $log_table = esc_sql($wpdb->prefix . 'odcm_audit_log');
            $payload_table = esc_sql($wpdb->prefix . 'odcm_audit_log_payloads');

            // First, search in summaries (fast)
            $summary_query = $wpdb->prepare(
                "SELECT l.*, p.payload
                FROM `{$log_table}` l
                LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id
                WHERE l.summary LIKE %s
                ORDER BY l.timestamp DESC
                LIMIT %d OFFSET %d",
                $safe_search_term,
                $per_page,
                $offset
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $summary_query is prepared above via $wpdb->prepare()
            $summary_results = $wpdb->get_results($summary_query, ARRAY_A);

            // If we have enough results from summary search, return them
            if (count($summary_results) >= $per_page) {
                $total_query = $wpdb->prepare(
                    "SELECT COUNT(*)
                    FROM `{$log_table}` l
                    WHERE l.summary LIKE %s",
                    $safe_search_term
                );

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $total_query is prepared above via $wpdb->prepare()
                $total = (int) $wpdb->get_var($total_query);

                $execution_time = microtime(true) - $start_time;

                return new WP_REST_Response([
                    'logs' => $this->format_logs_for_api($summary_results),
                    'pagination' => [
                        'total' => $total,
                        'total_pages' => max(1, (int) ceil($total / $per_page)),
                        'current_page' => $page,
                        'per_page' => $per_page,
                    ],
                    'meta' => [
                        'execution_time' => $execution_time,
                        'timestamp' => current_time('mysql'),
                        'search_method' => 'summary_only',
                    ],
                ], 200);
            }

            // If we need more results, search in payload content
            $payload_query = $wpdb->prepare(
                "SELECT l.*, p.payload
                FROM `{$log_table}` l
                LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id
                WHERE p.payload LIKE %s
                AND l.log_id NOT IN (
                    SELECT log_id
                    FROM `{$log_table}`
                    WHERE summary LIKE %s
                )
                ORDER BY l.timestamp DESC
                LIMIT %d",
                $safe_search_term,
                $safe_search_term,
                $per_page - count($summary_results)
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $payload_query is prepared above via $wpdb->prepare()
            $payload_results = $wpdb->get_results($payload_query, ARRAY_A);

            // Combine results
            $combined_results = array_merge($summary_results, $payload_results);

            // Get total count
            $total_query = $wpdb->prepare(
                "SELECT COUNT(DISTINCT l.log_id)
                FROM `{$log_table}` l
                LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id
                WHERE l.summary LIKE %s OR p.payload LIKE %s",
                $safe_search_term,
                $safe_search_term
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $total_query is prepared above via $wpdb->prepare()
            $total = (int) $wpdb->get_var($total_query);

            $execution_time = microtime(true) - $start_time;

            return new WP_REST_Response([
                'logs' => $this->format_logs_for_api($combined_results),
                'pagination' => [
                    'total' => $total,
                    'total_pages' => max(1, (int) ceil($total / $per_page)),
                    'current_page' => $page,
                    'per_page' => $per_page,
                ],
                'meta' => [
                    'execution_time' => $execution_time,
                    'timestamp' => current_time('mysql'),
                    'search_method' => 'comprehensive',
                    'summary_results' => count($summary_results),
                    'payload_results' => count($payload_results),
                ],
            ], 200);

        } catch (\Throwable $e) {
            $this->log_api_error('search_logs_comprehensive', $e, [
                'search_term' => $request->get_param('search'),
            ]);

            return new WP_Error(
                'odcm_search_error',
                __('audit.logs.search.failure', 'order-daemon'),
                ['status' => 500]
            );
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
