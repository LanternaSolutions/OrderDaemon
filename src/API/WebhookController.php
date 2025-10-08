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
 * @since   next
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

        // Webhook test endpoint (debug mode only)
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
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
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * Handle incoming webhook requests
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure
     */
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $start_time = microtime(true);
        $gateway = $request->get_param('gateway');
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
     * Test webhook endpoint (debug mode only)
     * 
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure
     */
    public function test_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return new WP_Error('debug_disabled', 'Test endpoint only available in debug mode', ['status' => 404]);
        }

        $gateway = $request->get_param('gateway');
        $test_data = $request->get_json_params() ?: [];

        // Add test markers
        $test_data['_odcm_test'] = true;
        $test_data['_test_timestamp'] = current_time('c');

        // Process as regular webhook
        $test_request = new WP_REST_Request('POST', '/' . self::NAMESPACE . '/' . self::BASE_ROUTE . '/' . $gateway);
        $test_request->set_body(wp_json_encode($test_data));
        $test_request->set_header('Content-Type', 'application/json');

        return $this->handle_webhook($test_request);
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
        // Test endpoints require admin capabilities
        return current_user_can('manage_woocommerce');
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
                $ip = $_SERVER[$header];
                
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
        if (function_exists('odcm_log_custom_event')) {
            odcm_log_custom_event(
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
                false,
                $process_id
            );
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log(sprintf(
                'ODCM Webhook: Received %s webhook from %s',
                $gateway,
                $input_data['ip_address'] ?? 'unknown'
            ));
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
        if (function_exists('odcm_log_custom_event')) {
            odcm_log_custom_event(
                sprintf('Webhook processed successfully: %s (%d events)', $gateway, $events_count),
                [
                    'gateway' => $gateway,
                    'events_processed' => $events_count,
                    'execution_time' => $execution_time,
                    'performance_status' => $execution_time > 0.2 ? 'slow' : 'fast',
                ],
                null,
                'success',
                'webhook_processing',
                false,
                $process_id
            );
        }

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log(sprintf(
                'ODCM Webhook: Successfully processed %s webhook (%d events, %.3fs)',
                $gateway,
                $events_count,
                $execution_time
            ));
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
        if (function_exists('odcm_log_custom_event')) {
            odcm_log_custom_event(
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
                false,
                $process_id
            );
        }

        error_log(sprintf(
            'ODCM Webhook Error: %s webhook failed - %s (%.3fs)',
            $gateway,
            $error->getMessage(),
            $execution_time
        ));
    }
}
