<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\API;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * REST API Diagnostic - Test Order Daemon API Route Registration
 *
 * This diagnostic addresses the critical console log issue:
 * "Failed to get subsystem status for purpose" and 
 * "XHR GET http://localhost:8082/index.php?rest_route=/odcm/v1/audit-log/?page=1&per_page=20 [HTTP/1.1 404 Not Found]"
 *
 * Tests:
 * - WordPress REST API functionality
 * - Order Daemon API route registration
 * - Endpoint accessibility and responses
 * - Authentication and permission checks
 *
 * @package OrderDaemon\DevTools\Diagnostics\API
 */
class RestApiDiagnostic extends AbstractDiagnostic
{
    /**
     * Expected Order Daemon API endpoints
     */
    private const EXPECTED_ENDPOINTS = [
        '/odcm/v1/audit-log',
        '/odcm/v1/audit-log/render-components',
        '/odcm/v1/audit-log/filter-options',
        '/odcm/v1/audit-log/batch-delete'
    ];

    /**
     * Get the diagnostic test name
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'REST API Route Registration';
    }

    /**
     * Get the diagnostic test description
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Tests if Order Daemon REST API routes are properly registered and accessible. Addresses 404 errors in console logs.';
    }

    /**
     * Get the diagnostic category
     *
     * @return string
     */
    public function get_category(): string
    {
        return 'api';
    }

    /**
     * Get the priority level (API issues are critical)
     *
     * @return int
     */
    public function get_priority(): int
    {
        return 10;
    }

    /**
     * Execute the REST API diagnostic test
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $details = [];
        $recommendations = [];
        $issues_found = [];

        // Test 1: Check if WordPress REST API is enabled
        $wp_api_test = $this->test_wordpress_rest_api();
        $details['wordpress_rest_api'] = $wp_api_test;
        if (!$wp_api_test['enabled']) {
            $issues_found[] = 'WordPress REST API is disabled';
            $recommendations[] = 'Enable WordPress REST API functionality';
        }

        // Test 2: Check if Order Daemon core plugin is available
        $core_available = $this->is_core_plugin_available();
        $details['core_plugin_available'] = $core_available;
        if (!$core_available) {
            return DiagnosticResult::failure(
                $this->get_name(),
                'Order Daemon core plugin is not available. API routes cannot be registered.',
                $details,
                ['Ensure Order Daemon core plugin is installed and activated']
            );
        }

        // Test 3: Check if API routes are registered
        $routes_test = $this->test_route_registration();
        $details['route_registration'] = $routes_test;
        if (!empty($routes_test['missing_routes'])) {
            $issues_found[] = 'Missing API routes: ' . implode(', ', $routes_test['missing_routes']);
            $recommendations[] = 'Check plugin initialization order and ensure AuditLogEndpoint is properly registered';
        }

        // Test 4: Test specific endpoint accessibility
        $endpoint_tests = $this->test_endpoint_accessibility();
        $details['endpoint_tests'] = $endpoint_tests;
        foreach ($endpoint_tests as $endpoint => $test_result) {
            if (!$test_result['accessible']) {
                $issues_found[] = "Endpoint {$endpoint} is not accessible: {$test_result['error']}";
                $recommendations[] = "Fix {$endpoint} endpoint registration or permissions";
            }
        }

        // Test 5: Check authentication and permissions
        $auth_test = $this->test_authentication();
        $details['authentication'] = $auth_test;
        if (!$auth_test['user_can_access']) {
            $issues_found[] = 'Current user lacks required permissions (manage_woocommerce)';
            $recommendations[] = 'Ensure user has manage_woocommerce capability for API access';
        }

        // Test 6: Check nonce generation and validation
        $nonce_test = $this->test_nonce_system();
        $details['nonce_system'] = $nonce_test;
        if (!$nonce_test['working']) {
            $issues_found[] = 'Nonce system not working properly';
            $recommendations[] = 'Check WordPress nonce generation and validation';
        }

        // Test 7: Check for plugin conflicts
        $conflict_test = $this->test_plugin_conflicts();
        $details['plugin_conflicts'] = $conflict_test;
        if (!empty($conflict_test['potential_conflicts'])) {
            $issues_found[] = 'Potential plugin conflicts detected';
            $recommendations[] = 'Review conflicting plugins: ' . implode(', ', $conflict_test['potential_conflicts']);
        }

        // Determine overall result
        if (empty($issues_found)) {
            return DiagnosticResult::success(
                $this->get_name(),
                'All REST API routes are properly registered and accessible',
                $details
            );
        } else {
            $message = 'REST API issues detected: ' . implode('; ', array_slice($issues_found, 0, 3));
            if (count($issues_found) > 3) {
                $message .= ' and ' . (count($issues_found) - 3) . ' more issues';
            }

            return DiagnosticResult::failure(
                $this->get_name(),
                $message,
                $details,
                $recommendations
            );
        }
    }

    /**
     * Test if WordPress REST API is enabled and working
     *
     * @return array Test results
     */
    private function test_wordpress_rest_api(): array
    {
        $result = [
            'enabled' => false,
            'rest_url' => '',
            'test_response' => null,
            'error' => null
        ];

        // Check if REST API is disabled
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            $result['error'] = 'REST API disabled via XMLRPC_REQUEST';
            return $result;
        }

        // Get REST URL
        $rest_url = rest_url();
        $result['rest_url'] = $rest_url;

        if (empty($rest_url)) {
            $result['error'] = 'REST URL is empty';
            return $result;
        }

        // Test basic WordPress REST API endpoint
        $test_url = rest_url('wp/v2/types');
        $response = $this->make_request($test_url);

        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $result['test_response'] = [
            'status_code' => $response_code,
            'success' => $response_code === 200
        ];

        if ($response_code === 200) {
            $result['enabled'] = true;
        } else {
            $result['error'] = 'REST API test returned status ' . $response_code;
        }

        return $result;
    }

    /**
     * Test if Order Daemon API routes are registered
     *
     * @return array Test results
     */
    private function test_route_registration(): array
    {
        $result = [
            'total_routes' => 0,
            'odcm_routes' => [],
            'missing_routes' => [],
            'registered_routes' => []
        ];

        // Get all registered routes
        $wp_rest_server = rest_get_server();
        $routes = $wp_rest_server->get_routes();
        $result['total_routes'] = count($routes);

        // Find Order Daemon routes
        $odcm_routes = [];
        foreach ($routes as $route => $handlers) {
            if (strpos($route, '/odcm/') === 0) {
                $odcm_routes[] = $route;
            }
        }

        $result['odcm_routes'] = $odcm_routes;
        $result['registered_routes'] = $odcm_routes;

        // Check for missing expected routes
        foreach (self::EXPECTED_ENDPOINTS as $expected_route) {
            $found = false;
            foreach ($odcm_routes as $registered_route) {
                // Handle regex routes by checking if expected route matches pattern
                $pattern = str_replace(['.', '+', '*', '?', '[', ']', '(', ')', '{', '}', '^', '$'], 
                                     ['\.', '\+', '\*', '\?', '\[', '\]', '\(', '\)', '\{', '\}', '\^', '\$'], 
                                     $registered_route);
                
                if (preg_match('#^' . $pattern . '$#', $expected_route) || 
                    strpos($registered_route, $expected_route) === 0 ||
                    strpos($expected_route, $registered_route) === 0) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $result['missing_routes'][] = $expected_route;
            }
        }

        return $result;
    }

    /**
     * Test endpoint accessibility with actual HTTP requests
     *
     * @return array Test results for each endpoint
     */
    private function test_endpoint_accessibility(): array
    {
        $results = [];

        foreach (self::EXPECTED_ENDPOINTS as $endpoint) {
            $test_url = rest_url($endpoint);
            
            // Add basic query parameters for endpoints that expect them
            if ($endpoint === '/odcm/v1/audit-log') {
                $test_url = add_query_arg([
                    'page' => 1,
                    'per_page' => 1
                ], $test_url);
            }

            $result = [
                'accessible' => false,
                'status_code' => null,
                'error' => null,
                'test_url' => $test_url,
                'requires_auth' => true
            ];

            // Make request with nonce if available
            $args = [];
            if ($this->user_can('manage_woocommerce')) {
                $nonce = wp_create_nonce('wp_rest');
                $args['headers'] = [
                    'X-WP-Nonce' => $nonce
                ];
            }

            $response = $this->make_request($test_url, $args);

            if (is_wp_error($response)) {
                $result['error'] = $response->get_error_message();
            } else {
                $status_code = wp_remote_retrieve_response_code($response);
                $result['status_code'] = $status_code;

                // 200 = success, 401/403 = auth required (expected), 404 = not found (problem)
                if ($status_code === 200) {
                    $result['accessible'] = true;
                } elseif (in_array($status_code, [401, 403])) {
                    $result['accessible'] = true; // Route exists, just needs auth
                    $result['error'] = 'Authentication required (expected)';
                } else {
                    $result['error'] = 'HTTP ' . $status_code;
                }
            }

            $results[$endpoint] = $result;
        }

        return $results;
    }

    /**
     * Test authentication and permission system
     *
     * @return array Authentication test results
     */
    private function test_authentication(): array
    {
        $result = [
            'user_logged_in' => is_user_logged_in(),
            'user_can_access' => false,
            'user_id' => get_current_user_id(),
            'user_roles' => [],
            'required_capabilities' => ['manage_woocommerce'],
            'missing_capabilities' => []
        ];

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $result['user_roles'] = $user->roles;

            $required_caps = ['manage_woocommerce'];
            foreach ($required_caps as $cap) {
                if (!current_user_can($cap)) {
                    $result['missing_capabilities'][] = $cap;
                } else {
                    $result['user_can_access'] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Test WordPress nonce system functionality
     *
     * @return array Nonce test results
     */
    private function test_nonce_system(): array
    {
        $result = [
            'working' => false,
            'can_create' => false,
            'can_verify' => false,
            'error' => null
        ];

        try {
            // Test nonce creation
            $test_nonce = wp_create_nonce('test_action');
            if (!empty($test_nonce)) {
                $result['can_create'] = true;

                // Test nonce verification
                $verified = wp_verify_nonce($test_nonce, 'test_action');
                if ($verified) {
                    $result['can_verify'] = true;
                    $result['working'] = true;
                } else {
                    $result['error'] = 'Nonce verification failed';
                }
            } else {
                $result['error'] = 'Nonce creation failed';
            }
        } catch (\Throwable $e) {
            $result['error'] = 'Nonce system error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Test for potential plugin conflicts that might affect API routes
     *
     * @return array Plugin conflict test results
     */
    private function test_plugin_conflicts(): array
    {
        $result = [
            'potential_conflicts' => [],
            'security_plugins' => [],
            'caching_plugins' => [],
            'api_plugins' => []
        ];

        // Get active plugins
        $active_plugins = get_option('active_plugins', []);
        
        // Known problematic plugins that can interfere with REST API
        $known_conflicts = [
            'security' => [
                'wordfence/wordfence.php' => 'Wordfence Security',
                'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
                'better-wp-security/better-wp-security.php' => 'iThemes Security',
                'sucuri-scanner/sucuri.php' => 'Sucuri Security'
            ],
            'caching' => [
                'wp-super-cache/wp-cache.php' => 'WP Super Cache',
                'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
                'wp-rocket/wp-rocket.php' => 'WP Rocket',
                'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache'
            ],
            'api' => [
                'disable-json-api/disable-json-api.php' => 'Disable JSON API',
                'disable-wp-rest-api/disable-wp-rest-api.php' => 'Disable WP REST API'
            ]
        ];

        foreach ($known_conflicts as $category => $plugins) {
            foreach ($plugins as $plugin_path => $plugin_name) {
                if (in_array($plugin_path, $active_plugins, true)) {
                    $result[$category . '_plugins'][] = $plugin_name;
                    $result['potential_conflicts'][] = $plugin_name;
                }
            }
        }

        return $result;
    }
}
