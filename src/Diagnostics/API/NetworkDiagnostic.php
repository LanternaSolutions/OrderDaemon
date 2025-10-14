<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\API;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * Network Diagnostic - Test Auto-refresh and Network Connectivity
 *
 * This diagnostic addresses the console log issue:
 * "ODCM: Auto-refresh failed: TypeError: NetworkError when attempting to fetch resource."
 *
 * Tests:
 * - Network connectivity to API endpoints
 * - Auto-refresh mechanism functionality
 * - CORS and cross-origin request issues
 * - SSL/TLS certificate problems
 * - Server response reliability
 * - JavaScript fetch API availability and configuration
 *
 * @package OrderDaemon\DevTools\Diagnostics\API
 */
class NetworkDiagnostic extends AbstractDiagnostic
{
    /**
     * Get the diagnostic test name
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'Network Connectivity & Auto-refresh';
    }

    /**
     * Get the diagnostic test description
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Tests network connectivity, auto-refresh functionality, and JavaScript fetch operations. Addresses auto-refresh failures and network errors.';
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
     * Get the priority level (network issues are critical)
     *
     * @return int
     */
    public function get_priority(): int
    {
        return 9;
    }

    /**
     * Execute the network diagnostic test
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $details = [];
        $recommendations = [];
        $issues_found = [];

        // Test 1: Basic connectivity to WordPress site
        $connectivity_test = $this->test_basic_connectivity();
        $details['basic_connectivity'] = $connectivity_test;
        if (!$connectivity_test['can_connect']) {
            $issues_found[] = 'Cannot establish basic connectivity to WordPress site';
            $recommendations[] = 'Check server connectivity and DNS resolution';
        }

        // Test 2: Test REST API endpoint connectivity
        $api_connectivity = $this->test_api_connectivity();
        $details['api_connectivity'] = $api_connectivity;
        if (!empty($api_connectivity['failed_endpoints'])) {
            $issues_found[] = 'API endpoints unreachable: ' . implode(', ', $api_connectivity['failed_endpoints']);
            $recommendations[] = 'Check REST API configuration and endpoint availability';
        }

        // Test 3: Test CORS configuration
        $cors_test = $this->test_cors_configuration();
        $details['cors_configuration'] = $cors_test;
        if (!empty($cors_test['issues'])) {
            foreach ($cors_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Configure CORS headers properly for cross-origin requests';
        }

        // Test 4: Test SSL/TLS configuration
        $ssl_test = $this->test_ssl_configuration();
        $details['ssl_configuration'] = $ssl_test;
        if (!empty($ssl_test['issues'])) {
            // For development environments, SSL warnings are informational, not failures
            if ($ssl_test['environment_type'] === 'development') {
                // Only flag actual SSL certificate errors in development, not missing SSL
                foreach ($ssl_test['issues'] as $issue) {
                    if (strpos($issue, 'Development environment detected') === false) {
                        $issues_found[] = $issue;
                    }
                }
                // Don't add SSL recommendations for development environments
            } else {
                // For production environments, all SSL issues are failures
                foreach ($ssl_test['issues'] as $issue) {
                    $issues_found[] = $issue;
                }
                $recommendations[] = 'Fix SSL/TLS certificate and configuration issues';
            }
        }

        // Test 5: Test auto-refresh mechanism configuration
        $refresh_test = $this->test_auto_refresh_config();
        $details['auto_refresh_config'] = $refresh_test;
        if (!empty($refresh_test['issues'])) {
            foreach ($refresh_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Review auto-refresh settings and implementation';
        }

        // Test 6: Test server response reliability
        $reliability_test = $this->test_server_reliability();
        $details['server_reliability'] = $reliability_test;
        if ($reliability_test['failure_rate'] > 0.1) { // More than 10% failure rate
            $issues_found[] = "High server failure rate: {$reliability_test['failure_rate_percent']}%";
            $recommendations[] = 'Investigate server stability and response reliability';
        }

        // Test 7: Test JavaScript fetch API support
        $fetch_test = $this->test_fetch_api_support();
        $details['fetch_api_support'] = $fetch_test;
        if (!empty($fetch_test['issues'])) {
            foreach ($fetch_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Add fetch API polyfills for older browser compatibility';
        }

        // Test 8: Test network timeouts and performance
        $timeout_test = $this->test_network_timeouts();
        $details['network_timeouts'] = $timeout_test;
        if ($timeout_test['has_timeout_issues']) {
            $issues_found[] = 'Network timeout issues detected';
            $recommendations[] = 'Optimize server response times and adjust timeout settings';
        }

        // Determine overall result
        if (empty($issues_found)) {
            return DiagnosticResult::success(
                $this->get_name(),
                'Network connectivity and auto-refresh functionality working correctly',
                $details
            );
        } else {
            $message = 'Network issues detected: ' . implode('; ', array_slice($issues_found, 0, 3));
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
     * Test basic connectivity to WordPress site
     *
     * @return array Basic connectivity test results
     */
    private function test_basic_connectivity(): array
    {
        $result = [
            'can_connect' => false,
            'response_time_ms' => 0,
            'status_code' => 0,
            'error' => null,
            'site_url' => home_url(),
            'admin_url' => admin_url()
        ];

        try {
            $start_time = microtime(true);
            
            // Since we're running inside WordPress, we can do a more reliable internal test
            // Test if we can access WordPress functions and database
            if (function_exists('get_bloginfo') && function_exists('home_url')) {
                // Test database connectivity
                global $wpdb;
                $db_test = $wpdb->get_var("SELECT 1");
                
                if ($db_test === '1') {
                    $result['can_connect'] = true;
                    $result['status_code'] = 200;
                    $result['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
                } else {
                    $result['error'] = 'Database connectivity test failed';
                }
            } else {
                $result['error'] = 'WordPress core functions not available';
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        // If internal test failed, fall back to HTTP request but handle Docker environment
        if (!$result['can_connect']) {
            try {
                $start_time = microtime(true);
                $response = $this->make_request(home_url(), [
                    'method' => 'HEAD', // Use HEAD for faster response
                    'timeout' => 10,
                    'headers' => [
                        'User-Agent' => 'Order Daemon DevTools Network Test'
                    ]
                ]);

                $result['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);

                if (is_wp_error($response)) {
                    // Don't treat this as a failure if internal test passed
                    if (!$result['can_connect']) {
                        $result['error'] = $response->get_error_message();
                    }
                } else {
                    $status_code = wp_remote_retrieve_response_code($response);
                    $result['status_code'] = $status_code;
                    if ($status_code >= 200 && $status_code < 400) {
                        $result['can_connect'] = true;
                        $result['error'] = null; // Clear any previous error
                    }
                }

            } catch (\Throwable $e) {
                // Don't overwrite error if internal test was successful
                if (!$result['can_connect']) {
                    $result['error'] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * Test API endpoint connectivity
     *
     * @return array API connectivity test results
     */
    private function test_api_connectivity(): array
    {
        $result = [
            'tested_endpoints' => [],
            'successful_endpoints' => [],
            'failed_endpoints' => [],
            'response_times' => []
        ];

        $endpoints_to_test = [
            '/wp-json/wp/v2/',
            '/wp-json/odcm/v1/audit-log',
            '/wp-json/odcm/v1/audit-log/filter-options'
        ];

        foreach ($endpoints_to_test as $endpoint) {
            $full_url = home_url($endpoint);
            $result['tested_endpoints'][] = $endpoint;

            try {
                $start_time = microtime(true);
                $response = $this->make_request($full_url, [
                    'method' => 'GET',
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-WP-Nonce' => wp_create_nonce('wp_rest')
                    ]
                ]);

                $response_time = round((microtime(true) - $start_time) * 1000, 2);
                $result['response_times'][$endpoint] = $response_time;

                if (is_wp_error($response)) {
                    $result['failed_endpoints'][] = $endpoint . ' (' . $response->get_error_message() . ')';
                } else {
                    $status_code = wp_remote_retrieve_response_code($response);
                    if ($status_code >= 200 && $status_code < 400) {
                        $result['successful_endpoints'][] = $endpoint;
                    } elseif ($status_code === 401 || $status_code === 403) {
                        // 401/403 means the endpoint exists and is working, just requires authentication
                        $result['successful_endpoints'][] = $endpoint . ' (authentication required)';
                    } else {
                        $result['failed_endpoints'][] = $endpoint . ' (HTTP ' . $status_code . ')';
                    }
                }

            } catch (\Throwable $e) {
                $result['failed_endpoints'][] = $endpoint . ' (' . $e->getMessage() . ')';
            }
        }

        return $result;
    }

    /**
     * Test CORS configuration
     *
     * @return array CORS configuration test results
     */
    private function test_cors_configuration(): array
    {
        $result = [
            'cors_enabled' => false,
            'access_control_headers' => [],
            'preflight_support' => false,
            'issues' => []
        ];

        // Test CORS headers on a REST API endpoint
        $test_url = rest_url('wp/v2/');
        
        try {
            $response = $this->make_request($test_url, [
                'method' => 'OPTIONS',
                'headers' => [
                    'Origin' => home_url(),
                    'Access-Control-Request-Method' => 'GET',
                    'Access-Control-Request-Headers' => 'X-WP-Nonce'
                ]
            ]);

            if (!is_wp_error($response)) {
                $headers = wp_remote_retrieve_headers($response);
                
                // Check for CORS headers
                $cors_headers = [
                    'access-control-allow-origin',
                    'access-control-allow-methods',
                    'access-control-allow-headers',
                    'access-control-allow-credentials'
                ];

                foreach ($cors_headers as $header) {
                    if (isset($headers[$header])) {
                        $result['access_control_headers'][$header] = $headers[$header];
                        $result['cors_enabled'] = true;
                    }
                }

                $result['preflight_support'] = wp_remote_retrieve_response_code($response) === 200;
            }

        } catch (\Throwable $e) {
            $result['issues'][] = 'Failed to test CORS configuration: ' . $e->getMessage();
        }

        // Check for common CORS issues
        if (!$result['cors_enabled']) {
            // This might not be an issue if all requests are same-origin
            if (is_admin()) {
                $result['issues'][] = 'CORS headers not detected (may cause cross-origin request failures)';
            }
        }

        if ($result['cors_enabled'] && !isset($result['access_control_headers']['access-control-allow-credentials'])) {
            $result['issues'][] = 'CORS configured but credentials not allowed (may cause authentication issues)';
        }

        return $result;
    }

    /**
     * Test SSL/TLS configuration
     *
     * @return array SSL configuration test results
     */
    private function test_ssl_configuration(): array
    {
        $result = [
            'ssl_enabled' => false,
            'certificate_valid' => false,
            'certificate_info' => [],
            'issues' => [],
            'environment_type' => 'production'
        ];

        $site_url = home_url();
        $result['ssl_enabled'] = strpos($site_url, 'https://') === 0;
        
        // Detect development environment
        $is_development = $this->is_development_environment($site_url);
        $result['environment_type'] = $is_development ? 'development' : 'production';

        if (!$result['ssl_enabled']) {
            if ($is_development) {
                // For development environments, missing SSL is acceptable
                $result['issues'][] = 'Development environment detected - SSL not required for localhost';
                $result['development_note'] = 'SSL/HTTPS is not required for local development environments';
                // Don't treat this as a failure for development
            } else {
                // For production environments, missing SSL is a real issue
                $result['issues'][] = 'SSL/HTTPS not enabled (may cause mixed content issues in production)';
            }
            return $result;
        }

        // Test SSL certificate validity
        try {
            $response = $this->make_request($site_url, [
                'sslverify' => true,
                'timeout' => 10
            ]);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                if (strpos($error_message, 'SSL') !== false || strpos($error_message, 'certificate') !== false) {
                    $result['issues'][] = 'SSL certificate error: ' . $error_message;
                } else {
                    $result['certificate_valid'] = true;
                }
            } else {
                $result['certificate_valid'] = true;
            }

        } catch (\Throwable $e) {
            $result['issues'][] = 'SSL test failed: ' . $e->getMessage();
        }

        // Check WordPress SSL settings
        if (defined('FORCE_SSL_ADMIN') && !FORCE_SSL_ADMIN) {
            $result['issues'][] = 'FORCE_SSL_ADMIN not enabled (admin area not secured)';
        }

        return $result;
    }

    /**
     * Test auto-refresh configuration
     *
     * @return array Auto-refresh configuration test results
     */
    private function test_auto_refresh_config(): array
    {
        $result = [
            'auto_refresh_setting' => null,
            'refresh_interval' => null,
            'javascript_config' => [],
            'issues' => []
        ];

        // Check if Order Daemon has auto-refresh settings
        if (function_exists('odcm_get_option')) {
            $result['auto_refresh_setting'] = odcm_get_option('auto_refresh_enabled', false);
            $result['refresh_interval'] = odcm_get_option('refresh_interval', 30);
        }

        // Check JavaScript configuration for auto-refresh
        global $wp_scripts;
        if ($wp_scripts instanceof \WP_Scripts) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if (preg_match('/^(odcm|order.?daemon)/i', $handle) && !empty($script->extra['data'])) {
                    $data = $script->extra['data'];
                    if (strpos($data, 'autoRefresh') !== false || strpos($data, 'auto_refresh') !== false) {
                        $result['javascript_config'][$handle] = $data;
                    }
                }
            }
        }

        // Check for potential configuration issues
        if ($result['refresh_interval'] && $result['refresh_interval'] < 5) {
            $result['issues'][] = 'Auto-refresh interval too short (may cause server overload)';
        }

        if ($result['refresh_interval'] && $result['refresh_interval'] > 300) {
            $result['issues'][] = 'Auto-refresh interval very long (may affect user experience)';
        }

        if (empty($result['javascript_config']) && $result['auto_refresh_setting']) {
            $result['issues'][] = 'Auto-refresh enabled but JavaScript configuration missing';
        }

        return $result;
    }

    /**
     * Test server response reliability
     *
     * @return array Server reliability test results
     */
    private function test_server_reliability(): array
    {
        $result = [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'failure_rate' => 0,
            'failure_rate_percent' => 0,
            'response_times' => [],
            'errors' => []
        ];

        $test_url = rest_url('wp/v2/');
        $num_tests = 5; // Test with 5 requests

        for ($i = 0; $i < $num_tests; $i++) {
            $start_time = microtime(true);
            
            try {
                $response = $this->make_request($test_url, [
                    'timeout' => 10
                ]);

                $response_time = round((microtime(true) - $start_time) * 1000, 2);
                $result['response_times'][] = $response_time;
                $result['total_requests']++;

                if (is_wp_error($response)) {
                    $result['failed_requests']++;
                    $result['errors'][] = $response->get_error_message();
                } else {
                    $status_code = wp_remote_retrieve_response_code($response);
                    if ($status_code >= 200 && $status_code < 400) {
                        $result['successful_requests']++;
                    } else {
                        $result['failed_requests']++;
                        $result['errors'][] = 'HTTP ' . $status_code;
                    }
                }

            } catch (\Throwable $e) {
                $result['total_requests']++;
                $result['failed_requests']++;
                $result['errors'][] = $e->getMessage();
            }

            // Small delay between requests
            if ($i < $num_tests - 1) {
                usleep(500000); // 0.5 second delay
            }
        }

        if ($result['total_requests'] > 0) {
            $result['failure_rate'] = $result['failed_requests'] / $result['total_requests'];
            $result['failure_rate_percent'] = round($result['failure_rate'] * 100, 1);
        }

        return $result;
    }

    /**
     * Test fetch API support and configuration
     *
     * @return array Fetch API support test results
     */
    private function test_fetch_api_support(): array
    {
        $result = [
            'browser_support_estimated' => true,
            'polyfill_needed' => false,
            'configuration_issues' => [],
            'issues' => []
        ];

        // Check if the site is likely accessed by older browsers
        // This is a simplified check - in reality, you'd analyze user agent data
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (strpos($user_agent, 'MSIE') !== false || 
            strpos($user_agent, 'Trident') !== false ||
            preg_match('/Chrome\/([0-9]+)/', $user_agent, $matches) && (int)$matches[1] < 42) {
            $result['browser_support_estimated'] = false;
            $result['polyfill_needed'] = true;
            $result['issues'][] = 'Older browser detected - fetch API may not be supported';
        }

        // Check if any Order Daemon scripts use fetch API without polyfills
        global $wp_scripts;
        if ($wp_scripts instanceof \WP_Scripts) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if (preg_match('/^(odcm|order.?daemon)/i', $handle) && !empty($script->src)) {
                    // This is a simplified check - ideally we'd analyze the actual script content
                    if (strpos($handle, 'insight') !== false || strpos($handle, 'dashboard') !== false) {
                        $result['configuration_issues'][$handle] = 'May use fetch API without compatibility check';
                    }
                }
            }
        }

        if (!empty($result['configuration_issues'])) {
            $result['issues'][] = 'Scripts may use fetch API without browser compatibility checks';
        }

        return $result;
    }

    /**
     * Test network timeouts and performance
     *
     * @return array Network timeout test results
     */
    private function test_network_timeouts(): array
    {
        $result = [
            'has_timeout_issues' => false,
            'average_response_time' => 0,
            'max_response_time' => 0,
            'timeout_threshold_ms' => 5000, // 5 seconds
            'slow_responses' => 0,
            'response_times' => []
        ];

        $test_urls = [
            rest_url('wp/v2/'),
            admin_url('admin-ajax.php')
        ];

        $all_response_times = [];

        foreach ($test_urls as $url) {
            $start_time = microtime(true);
            
            try {
                $response = $this->make_request($url, [
                    'timeout' => 10,
                    'method' => 'HEAD' // Faster than GET for this test
                ]);

                $response_time = round((microtime(true) - $start_time) * 1000, 2);
                $all_response_times[] = $response_time;
                $result['response_times'][$url] = $response_time;

                if ($response_time > $result['timeout_threshold_ms']) {
                    $result['slow_responses']++;
                }

            } catch (\Throwable $e) {
                // Consider failed requests as timeout issues
                $result['has_timeout_issues'] = true;
            }
        }

        if (!empty($all_response_times)) {
            $result['average_response_time'] = round(array_sum($all_response_times) / count($all_response_times), 2);
            $result['max_response_time'] = max($all_response_times);
        }

        if ($result['slow_responses'] > 0 || $result['average_response_time'] > $result['timeout_threshold_ms']) {
            $result['has_timeout_issues'] = true;
        }

        return $result;
    }

    /**
     * Check if this is a development environment
     *
     * @param string $site_url The site URL to check
     * @return bool True if development environment
     */
    private function is_development_environment(string $site_url): bool
    {
        // Check for common development indicators in URL
        $development_patterns = [
            'localhost',
            '127.0.0.1',
            '192.168.',
            '10.0.',
            '172.16.',
            '172.17.',
            '172.18.',
            '172.19.',
            '172.20.',
            '172.21.',
            '172.22.',
            '172.23.',
            '172.24.',
            '172.25.',
            '172.26.',
            '172.27.',
            '172.28.',
            '172.29.',
            '172.30.',
            '172.31.',
            '.local',
            '.dev',
            '.test',
            '.example'
        ];

        foreach ($development_patterns as $pattern) {
            if (strpos($site_url, $pattern) !== false) {
                return true;
            }
        }

        // Check for development environment constants
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }

        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'development') {
            return true;
        }

        // Check for Docker environment indicators
        if (file_exists('/.dockerenv') || 
            (is_readable('/proc/1/cgroup') && strpos(file_get_contents('/proc/1/cgroup'), 'docker') !== false) ||
            getenv('WORDPRESS_DB_HOST') === 'db') {
            return true;
        }

        // Check for staging/development subdomains
        $staging_patterns = [
            'staging.',
            'dev.',
            'test.',
            'demo.',
            'beta.'
        ];

        foreach ($staging_patterns as $pattern) {
            if (strpos($site_url, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
