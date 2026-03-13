<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API;

use OrderDaemon\CompletionManager\Core\Events\EventRouter;
use OrderDaemon\CompletionManager\Core\Security\CapabilityGuard;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Webhook REST API Controller
 * 
 * Handles incoming webhook requests from payment gateways and other external
 * services. Routes events to appropriate adapters for normalization and
 * processing through the universal event system.
 * 
 * @package OrderDaemon\CompletionManager\API
 * @since   1.1.1
 */
class WebhookController extends WP_REST_Controller
{
    /**
     * REST API namespace
     */
    private const NAMESPACE = 'odcm/v1';

    /**
     * Base route for webhooks
     */
    private const BASE_ROUTE = 'webhooks';

    /**
     * Event router instance
     * 
     * @var EventRouter
     */
    private EventRouter $event_router;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->event_router = new EventRouter();
    }

    /**
     * Register REST API routes
     * 
     * @return void
     */
    public function register_routes(): void
    {
        // Generic webhook endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/(?P<gateway>[a-zA-Z0-9_-]+)', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_webhook'],
                'permission_callback' => [$this, 'webhook_permissions_check'],
                'args'                => [
                    'gateway' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => [$this, 'validate_gateway_name'],
                    ],
                ],
            ],
        ]);

        // Health check endpoint for webhook monitoring
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/health', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'health_check'],
                'permission_callback' => '__return_true', // Public endpoint
            ],
        ]);

        // Webhook test endpoint (admin access)
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/test/(?P<gateway>[a-zA-Z0-9_-]+)', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'test_webhook'],
                'permission_callback' => [$this, 'test_permissions_check'],
                'args'                => [
                    'gateway' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => [$this, 'validate_gateway_name'],
                    ],
                    'event_type' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => 'payment_completed',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // Webhook test event types endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/test/(?P<gateway>[a-zA-Z0-9_-]+)/events', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_test_event_types'],
                'permission_callback' => [$this, 'test_permissions_check'],
                'args'                => [
                    'gateway' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => [$this, 'validate_gateway_name'],
                    ],
                ],
            ],
        ]);

        // Gateway discovery endpoint
        register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/gateways', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_available_gateways'],
                'permission_callback' => [$this, 'test_permissions_check'],
            ],
        ]);
    }

    /**
     * Handle incoming webhook requests
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure
     */
    /**
     * Handle incoming webhook requests.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function handle_webhook(WP_REST_Request $request)
    {
        $start_time = microtime(true);
        $gateway = $request->get_param('gateway') ?? 'unknown';
        $process_id = 'odcm_webhook_' . uniqid();

        try {
            // Extract request data
            $input_data = $this->extract_webhook_data($request);
            
            // Log webhook reception
            $this->log_webhook_reception($gateway, $input_data, $process_id);

            // Route to appropriate adapter
            $events = $this->event_router->processWebhook($gateway, $input_data);

            if (empty($events)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No events processed',
                    'process_id' => $process_id,
                ], 200); // Return 200 to prevent gateway retries
            }

            // Log successful processing
            $execution_time = microtime(true) - $start_time;
            $this->log_webhook_success($gateway, count($events), $execution_time, $process_id);

            return new WP_REST_Response([
                'success' => true,
                'message' => sprintf('Processed %d event(s)', count($events)),
                'events_processed' => count($events),
                'process_id' => $process_id,
                'execution_time' => round($execution_time * 1000, 2) . 'ms',
            ], 200);

        } catch (\Throwable $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_webhook_error($gateway, $e, $execution_time, $process_id);

            // Return 200 to prevent gateway retries for application errors
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => defined('ODCM_DEBUG') && ODCM_DEBUG ? $e->getMessage() : 'Internal error',
                'process_id' => $process_id,
            ], 200);
        }
    }

    /**
     * Health check endpoint for webhook monitoring
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response Response object
     */
    public function health_check(WP_REST_Request $request): WP_REST_Response
    {
        $health_data = [
            'status' => 'healthy',
            'timestamp' => current_time('c'),
            'version' => '2.2.1',
            'endpoints' => [
                'paypal' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/paypal'),
                'stripe' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/stripe'),
                'generic' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/generic'),
            ],
            'adapters' => $this->event_router->getAvailableAdapters(),
        ];

        return new WP_REST_Response($health_data, 200);
    }

    /**
     * Test webhook endpoint with comprehensive testing capabilities
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure
     */
    public function test_webhook(WP_REST_Request $request)
    {
        $start_time = microtime(true);
        $gateway = $request->get_param('gateway');
        $event_type = $request->get_param('event_type') ?: 'payment_completed';
        $test_id = 'test_' . uniqid();

        try {
            // Validate event type for gateway
            if (!WebhookTestPayloads::isEventTypeSupported($gateway, $event_type)) {
                return new WP_Error(
                    'invalid_event_type',
                    sprintf('Event type "%s" is not supported for gateway "%s"', $event_type, $gateway),
                    ['status' => 400]
                );
            }

            // Generate test payload
            $test_payload = WebhookTestPayloads::generate($gateway, $event_type);

            // Log test initiation
            $this->log_test_initiation($gateway, $event_type, $test_id);

            // Create test request
            $test_request = new WP_REST_Request('POST', '/' . self::NAMESPACE . '/' . self::BASE_ROUTE . '/' . $gateway);
            $test_request->set_body(wp_json_encode($test_payload));
            $test_request->set_header('Content-Type', 'application/json');
            $test_request->set_header('User-Agent', 'ODCM-Webhook-Test/1.0');

            // Process webhook
            $response = $this->handle_webhook($test_request);
            $execution_time = microtime(true) - $start_time;

            // Extract response data
            $response_data = $response->get_data();
            $is_success = $response_data['success'] ?? false;

            // Log test completion
            $this->log_test_completion($gateway, $event_type, $test_id, $is_success, $execution_time, $response_data);

            // Return enhanced test response
            return new WP_REST_Response([
                'test_success' => true,
                'test_id' => $test_id,
                'gateway' => $gateway,
                'event_type' => $event_type,
                'webhook_response' => $response_data,
                'execution_time' => round($execution_time * 1000, 2) . 'ms',
                'test_payload_summary' => $this->summarize_test_payload($test_payload),
                'recommendations' => $this->generate_test_recommendations($is_success, $response_data, $execution_time),
                'timestamp' => current_time('c'),
            ], 200);

        } catch (\Throwable $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_test_error($gateway, $event_type, $test_id, $e, $execution_time);

            return new WP_REST_Response([
                'test_success' => false,
                'test_id' => $test_id,
                'gateway' => $gateway,
                'event_type' => $event_type,
                'error' => $e->getMessage(),
                'execution_time' => round($execution_time * 1000, 2) . 'ms',
                'recommendations' => [
                    'Check server error logs for detailed error information',
                    'Verify that the webhook processing system is properly configured',
                    'Ensure all required dependencies are installed and active',
                ],
                'timestamp' => current_time('c'),
            ], 200);
        }
    }

    /**
     * Get available test event types for a gateway
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response Response object
     */
    public function get_test_event_types(WP_REST_Request $request): WP_REST_Response
    {
        $gateway = $request->get_param('gateway');
        $event_types = WebhookTestPayloads::getAvailableEventTypes($gateway);

        return new WP_REST_Response([
            'gateway' => $gateway,
            'event_types' => $event_types,
            'default_event_type' => 'payment_completed',
            'total_types' => count($event_types),
        ], 200);
    }

    /**
     * Get all available gateways with their capabilities
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response Response object
     */
    public function get_available_gateways(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Get available adapters from EventRouter
            $adapters = $this->event_router->getAvailableAdapters();
            
            // Build gateway information
            $gateways = [];
            
            // Always include generic gateway
            $gateways['generic'] = [
                'name' => 'generic',
                'display_name' => 'Generic',
                'description' => 'Generic webhook endpoint for custom integrations',
                'status' => 'active',
                'has_adapter' => true,
                'webhook_url' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/generic'),
                'test_url' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/test/generic'),
                'event_types' => WebhookTestPayloads::getAvailableEventTypes('generic'),
                'icon' => '🔗',
                'supports_testing' => true,
                'adapter_info' => [
                    'class' => 'Generic',
                    'version' => '1.0.0',
                    'supported_events' => array_keys(WebhookTestPayloads::getAvailableEventTypes('generic')),
                ],
            ];

            // Add discovered adapters
            foreach ($adapters as $gateway_name => $adapter_info) {
                $gateway_key = strtolower($gateway_name);
                
                // Skip if already added
                if (isset($gateways[$gateway_key])) {
                    continue;
                }

                $display_name = $this->formatGatewayDisplayName($gateway_name);
                $icon = $this->getGatewayIcon($gateway_key);
                
                $gateways[$gateway_key] = [
                    'name' => $gateway_key,
                    'display_name' => $display_name,
                    'description' => sprintf('%s payment gateway integration', $display_name),
                    'status' => 'active',
                    'has_adapter' => true,
                    'webhook_url' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/' . $gateway_key),
                    'test_url' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/test/' . $gateway_key),
                    'event_types' => WebhookTestPayloads::getAvailableEventTypes($gateway_key),
                    'icon' => $icon,
                    'supports_testing' => true,
                    'adapter_info' => [
                        'class' => $adapter_info['class'] ?? $gateway_name,
                        'version' => $adapter_info['version'] ?? '1.0.0',
                        'supported_events' => $adapter_info['supported_events'] ?? array_keys(WebhookTestPayloads::getAvailableEventTypes($gateway_key)),
                    ],
                ];
            }

            // Add common gateways that might not have adapters yet but should be shown
            $common_gateways = [
                'paypal' => ['display_name' => 'PayPal', 'icon' => '💳', 'description' => 'PayPal payment gateway integration'],
                'stripe' => ['display_name' => 'Stripe', 'icon' => '💳', 'description' => 'Stripe payment gateway integration'],
                'square' => ['display_name' => 'Square', 'icon' => '⬜', 'description' => 'Square payment gateway integration'],
                'woocommerce_payments' => ['display_name' => 'WooCommerce Payments', 'icon' => '🛒', 'description' => 'WooCommerce Payments integration'],
            ];

            foreach ($common_gateways as $gateway_key => $gateway_config) {
                if (!isset($gateways[$gateway_key])) {
                    $has_adapter = isset($adapters[$gateway_key]) || isset($adapters[ucfirst($gateway_key)]);
                    
                    $gateways[$gateway_key] = [
                        'name' => $gateway_key,
                        'display_name' => $gateway_config['display_name'],
                        'description' => $gateway_config['description'],
                        'status' => $has_adapter ? 'active' : 'coming_soon',
                        'has_adapter' => $has_adapter,
                        'webhook_url' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/' . $gateway_key),
                        'test_url' => rest_url(self::NAMESPACE . '/' . self::BASE_ROUTE . '/test/' . $gateway_key),
                        'event_types' => WebhookTestPayloads::getAvailableEventTypes($gateway_key),
                        'icon' => $gateway_config['icon'],
                        'supports_testing' => true, // We can always generate test payloads
                        'adapter_info' => $has_adapter ? ($adapters[$gateway_key] ?? $adapters[ucfirst($gateway_key)] ?? null) : null,
                    ];
                }
            }

            // Sort gateways by status (active first) then by name
            uasort($gateways, function($a, $b) {
                if ($a['status'] !== $b['status']) {
                    return $a['status'] === 'active' ? -1 : 1;
                }
                return strcmp($a['display_name'], $b['display_name']);
            });

            return new WP_REST_Response([
                'gateways' => $gateways,
                'total_gateways' => count($gateways),
                'active_gateways' => count(array_filter($gateways, fn($g) => $g['status'] === 'active')),
                'timestamp' => current_time('c'),
                'discovery_method' => 'event_router_adapters',
            ], 200);

        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'error' => 'Failed to discover gateways',
                'message' => $e->getMessage(),
                'gateways' => [],
                'total_gateways' => 0,
                'active_gateways' => 0,
                'timestamp' => current_time('c'),
            ], 500);
        }
    }

    /**
     * Permission check for webhook endpoints
     * 
     * Webhooks are public endpoints but we implement basic security measures
     * 
     * @param WP_REST_Request $request The REST request
     * @return bool True if permitted
     */
    public function webhook_permissions_check(WP_REST_Request $request): bool
    {
        // Webhooks are public endpoints by design
        // Security is handled through signature validation in adapters
        return true;
    }

    /**
     * Permission check for test endpoints
     * 
     * @param WP_REST_Request $request The REST request
     * @return bool True if permitted
     */
    public function test_permissions_check(WP_REST_Request $request): bool
    {
        // Test endpoints require admin capabilities - try multiple capability checks
        return current_user_can('manage_options') || 
               current_user_can('manage_woocommerce') || 
               current_user_can('edit_shop_orders') ||
               current_user_can('administrator');
    }

    /**
     * Validate gateway name parameter
     * 
     * @param string $gateway Gateway name
     * @return bool True if valid
     */
    public function validate_gateway_name(string $gateway): bool
    {
        // Allow alphanumeric, underscore, and hyphen
        return preg_match('/^[a-zA-Z0-9_-]+$/', $gateway) === 1;
    }

    /**
     * Extract webhook data from request
     * 
     * @param WP_REST_Request $request The REST request
     * @return array Extracted webhook data
     */
    private function extract_webhook_data(WP_REST_Request $request): array
    {
        // Extract headers
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? $values[0] : $values;
        }

        // Extract payload
        $payload = [];
        $content_type = $headers['content-type'] ?? '';

        if (strpos($content_type, 'application/json') !== false) {
            $payload = $request->get_json_params() ?: [];
        } elseif (strpos($content_type, 'application/x-www-form-urlencoded') !== false) {
            $payload = $request->get_body_params() ?: [];
        } else {
            // Try to parse as JSON first, then as form data
            $body = $request->get_body();
            $json_payload = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $json_payload;
            } else {
                parse_str($body, $payload);
            }
        }

        return [
            'headers' => $headers,
            'payload' => $payload,
            'method' => $request->get_method(),
            'url' => $request->get_route(),
            'user_agent' => $headers['user-agent'] ?? '',
            'ip_address' => $this->get_client_ip(),
            'timestamp' => current_time('c'),
        ];
    }

    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    private function get_client_ip(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
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
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file using safe file operation
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $debug_file = odcm_get_safe_debug_file_path();
            odcm_safe_file_put_contents($debug_file, '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
            return;
        }
    }

    /**
     * Log webhook reception
     * 
     * @param string $gateway Gateway name
     * @param array $input_data Input data
     * @param string $process_id Process ID
     * @return void
     */
    private function log_webhook_reception(string $gateway, array $input_data, string $process_id): void
    {
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('Webhook received from %s gateway', $gateway),
                [
                    'gateway' => $gateway,
                    'ip_address' => $input_data['ip_address'] ?? 'unknown',
                    'user_agent' => $input_data['user_agent'] ?? 'unknown',
                    'content_type' => $input_data['headers']['content-type'] ?? 'unknown',
                    'payload_size' => strlen(wp_json_encode($input_data['payload'] ?? [])),
                ],
                null,
                'info',
                'webhook_reception',
                false
            );
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage(sprintf(
                'ODCM Webhook: Received %s webhook from %s',
                $gateway,
                $input_data['ip_address'] ?? 'unknown'
            ), 'info');
        }
    }

    /**
     * Log successful webhook processing
     * 
     * @param string $gateway Gateway name
     * @param int $events_count Number of events processed
     * @param float $execution_time Execution time in seconds
     * @param string $process_id Process ID
     * @return void
     */
    private function log_webhook_success(string $gateway, int $events_count, float $execution_time, string $process_id): void
    {
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('Payment.%s.checkout processed', $gateway),
                [
                    'gateway' => $gateway,
                    'events_processed' => $events_count,
                    'execution_time' => $execution_time,
                    'performance_status' => $execution_time > 0.2 ? 'slow' : 'fast',
                ],
                null,
                'success',
                'webhook_processing',
                false
            );
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage(sprintf(
                'ODCM Webhook: Successfully processed %s webhook (%d events, %.3fs)',
                $gateway,
                $events_count,
                $execution_time
            ), 'info');
        }
    }

    /**
     * Log webhook processing error
     * 
     * @param string $gateway Gateway name
     * @param \Throwable $error Error that occurred
     * @param float $execution_time Execution time in seconds
     * @param string $process_id Process ID
     * @return void
     */
    private function log_webhook_error(string $gateway, \Throwable $error, float $execution_time, string $process_id): void
    {
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('Webhook processing failed: %s - %s', $gateway, $error->getMessage()),
                [
                    'gateway' => $gateway,
                    'error_message' => $error->getMessage(),
                    'error_file' => $error->getFile(),
                    'error_line' => $error->getLine(),
                    'execution_time' => $execution_time,
                ],
                null,
                'error',
                'webhook_processing',
                false
            );
        }

        $this->logDebugMessage(sprintf(
            'ODCM Webhook Error: %s webhook failed - %s (%.3fs)',
            $gateway,
            $error->getMessage(),
            $execution_time
        ), 'error');
    }

    /**
     * Log webhook test initiation
     * 
     * @param string $gateway Gateway name
     * @param string $event_type Event type being tested
     * @param string $test_id Test ID
     * @return void
     */
    private function log_test_initiation(string $gateway, string $event_type, string $test_id): void
    {
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('Webhook test initiated: %s gateway, %s event', $gateway, $event_type),
                [
                    'gateway' => $gateway,
                    'event_type' => $event_type,
                    'test_id' => $test_id,
                    'user_id' => get_current_user_id(),
                    'user_login' => wp_get_current_user()->user_login,
                    'source' => 'webhook_test',
                ],
                null,
                'info',
                'webhook_test',
                true
            );
        }
    }

    /**
     * Log webhook test completion
     * 
     * @param string $gateway Gateway name
     * @param string $event_type Event type tested
     * @param string $test_id Test ID
     * @param bool $is_success Whether the test was successful
     * @param float $execution_time Execution time in seconds
     * @param array $response_data Response data from webhook processing
     * @return void
     */
    private function log_test_completion(string $gateway, string $event_type, string $test_id, bool $is_success, float $execution_time, array $response_data): void
    {
        if (function_exists('odcm_log_event')) {
            $status = $is_success ? 'success' : 'warning';
            $message = sprintf(
                'Webhook test completed: %s gateway, %s event - %s',
                $gateway,
                $event_type,
                $is_success ? 'SUCCESS' : 'FAILED'
            );

            odcm_log_event(
                $message,
                [
                    'gateway' => $gateway,
                    'event_type' => $event_type,
                    'test_id' => $test_id,
                    'test_success' => $is_success,
                    'execution_time' => $execution_time,
                    'events_processed' => $response_data['events_processed'] ?? 0,
                    'user_id' => get_current_user_id(),
                    'user_login' => wp_get_current_user()->user_login,
                    'source' => 'webhook_test',
                ],
                null,
                $status,
                'webhook_test',
                true
            );
        }
    }

    /**
     * Log webhook test error
     * 
     * @param string $gateway Gateway name
     * @param string $event_type Event type tested
     * @param string $test_id Test ID
     * @param \Throwable $error Error that occurred
     * @param float $execution_time Execution time in seconds
     * @return void
     */
    private function log_test_error(string $gateway, string $event_type, string $test_id, \Throwable $error, float $execution_time): void
    {
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('Webhook test failed: %s gateway, %s event - %s', $gateway, $event_type, $error->getMessage()),
                [
                    'gateway' => $gateway,
                    'event_type' => $event_type,
                    'test_id' => $test_id,
                    'error_message' => $error->getMessage(),
                    'error_file' => $error->getFile(),
                    'error_line' => $error->getLine(),
                    'execution_time' => $execution_time,
                    'user_id' => get_current_user_id(),
                    'user_login' => wp_get_current_user()->user_login,
                    'source' => 'webhook_test',
                ],
                null,
                'error',
                'webhook_test',
                true
            );
        }

        $this->logDebugMessage(sprintf(
            'ODCM Webhook Test Error: %s %s test failed - %s (%.3fs)',
            $gateway,
            $event_type,
            $error->getMessage(),
            $execution_time
        ), 'error');
    }

    /**
     * Summarize test payload for user feedback
     * 
     * @param array $test_payload Test payload data
     * @return array Payload summary
     */
    private function summarize_test_payload(array $test_payload): array
    {
        $summary = [
            'is_test' => $test_payload['_odcm_test'] ?? false,
            'gateway' => $test_payload['_test_gateway'] ?? 'unknown',
            'event_type' => $test_payload['_test_event_type'] ?? 'unknown',
            'generated_by' => $test_payload['_test_user_login'] ?? 'unknown',
        ];

        // Add gateway-specific summary information
        if (isset($test_payload['event_type'])) {
            $summary['paypal_event_type'] = $test_payload['event_type'];
        }
        if (isset($test_payload['type'])) {
            $summary['stripe_event_type'] = $test_payload['type'];
        }
        if (isset($test_payload['event'])) {
            $summary['generic_event_type'] = $test_payload['event'];
        }

        // Add order/transaction information if available
        if (isset($test_payload['resource']['invoice_number'])) {
            $summary['order_id'] = $test_payload['resource']['invoice_number'];
        } elseif (isset($test_payload['data']['object']['metadata']['order_id'])) {
            $summary['order_id'] = $test_payload['data']['object']['metadata']['order_id'];
        } elseif (isset($test_payload['order_id'])) {
            $summary['order_id'] = $test_payload['order_id'];
        }

        return $summary;
    }

    /**
     * Generate test recommendations based on results
     * 
     * @param bool $is_success Whether the test was successful
     * @param array $response_data Response data from webhook processing
     * @param float $execution_time Execution time in seconds
     * @return array Recommendations for the user
     */
    private function generate_test_recommendations(bool $is_success, array $response_data, float $execution_time): array
    {
        $recommendations = [];

        if ($is_success) {
            $recommendations[] = '✅ Webhook test completed successfully';
            
            $events_processed = $response_data['events_processed'] ?? 0;
            if ($events_processed > 0) {
                $recommendations[] = sprintf('✅ Successfully processed %d event(s)', $events_processed);
            } else {
                $recommendations[] = '⚠️ No events were processed - check if order rules match the test scenario';
            }

            if ($execution_time > 0.5) {
                $recommendations[] = '⚠️ Test took longer than expected - consider optimizing webhook processing';
            } else {
                $recommendations[] = '✅ Good performance - webhook processed quickly';
            }

            $recommendations[] = '💡 Check the Insight Dashboard with "Include Test Logs" enabled to see detailed processing information';
        } else {
            $recommendations[] = '❌ Webhook test failed';
            $recommendations[] = '🔍 Check the error message above for specific details';
            $recommendations[] = '📋 Verify that the webhook processing system is properly configured';
            $recommendations[] = '🔧 Check server error logs for additional debugging information';
        }

        return $recommendations;
    }

    /**
     * Format gateway name for display
     * 
     * @param string $gateway_name Raw gateway name
     * @return string Formatted display name
     */
    private function formatGatewayDisplayName(string $gateway_name): string
    {
        // Handle common gateway name patterns
        $formatted = str_replace(['_', '-'], ' ', $gateway_name);
        $formatted = ucwords(strtolower($formatted));
        
        // Handle special cases
        $special_cases = [
            'Paypal' => 'PayPal',
            'Woocommerce' => 'WooCommerce',
            'Api' => 'API',
            'Pos' => 'POS',
            'Sms' => 'SMS',
            'Url' => 'URL',
            'Http' => 'HTTP',
            'Https' => 'HTTPS',
        ];
        
        foreach ($special_cases as $search => $replace) {
            $formatted = str_replace($search, $replace, $formatted);
        }
        
        return $formatted;
    }

    /**
     * Get icon for gateway
     * 
     * @param string $gateway_key Gateway key
     * @return string Icon emoji or symbol
     */
    private function getGatewayIcon(string $gateway_key): string
    {
        $icons = [
            'paypal' => '💳',
            'stripe' => '💳',
            'square' => '⬜',
            'woocommerce_payments' => '🛒',
            'apple_pay' => '🍎',
            'google_pay' => '🔍',
            'amazon_pay' => '📦',
            'klarna' => '🔷',
            'afterpay' => '💰',
            'razorpay' => '⚡',
            'mollie' => '🟠',
            'braintree' => '🧠',
            'authorize_net' => '🔐',
            'worldpay' => '🌍',
            'adyen' => '🔵',
            'checkout_com' => '✅',
            'generic' => '🔗',
        ];
        
        return $icons[$gateway_key] ?? '💳';
    }
}
