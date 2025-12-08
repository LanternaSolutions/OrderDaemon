<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics;

use WP_Error;

/**
 * Abstract Diagnostic Base Class
 *
 * Provides common functionality for all diagnostic tests, including
 * timing measurement, error handling, and standardized result creation.
 *
 * @package OrderDaemon\CompletionManager\Diagnostics
 */
abstract class AbstractDiagnostic implements DiagnosticInterface
{
    /**
     * The start time for execution timing
     *
     * @var float|null
     */
    private ?float $start_time = null;

    /**
     * Run the diagnostic test with timing and error handling
     *
     * @return DiagnosticResult
     */
    final public function run(): DiagnosticResult
    {
        $this->start_time = microtime(true);

        try {
            $result = $this->execute();
            
            // Set execution time
            $execution_time = microtime(true) - $this->start_time;
            $result->setExecutionTime($execution_time);
            
            return $result;
            
        } catch (\Throwable $e) {
            $execution_time = microtime(true) - $this->start_time;
            
            return DiagnosticResult::failure(
                $this->get_name(),
                'Diagnostic test encountered an error: ' . $e->getMessage(),
                [
                    'error_type' => get_class($e),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'stack_trace' => $e->getTraceAsString()
                ],
                ['Contact support with this error information'],
                ['execution_time' => $execution_time]
            );
        }
    }

    /**
     * Execute the actual diagnostic test logic
     *
     * This method must be implemented by concrete diagnostic classes
     *
     * @return DiagnosticResult
     */
    abstract protected function execute(): DiagnosticResult;

    /**
     * Get the priority level of this diagnostic (default: 5)
     *
     * Override in subclasses to set different priorities
     *
     * @return int Priority level (1-10, where 10 is most critical)
     */
    public function get_priority(): int
    {
        return 5;
    }

    /**
     * Check if this diagnostic requires the core plugin to be active (default: true)
     *
     * Override in subclasses that can run without the core plugin
     *
     * @return bool True if core plugin is required
     */
    public function requires_core_plugin(): bool
    {
        return true;
    }

    /**
     * Helper method to check if the Order Daemon core plugin is available
     *
     * @return bool True if core plugin is available
     */
    protected function is_core_plugin_available(): bool
    {
        return class_exists('OrderDaemon\\CompletionManager\\Plugin');
    }

    /**
     * Helper method to check if WooCommerce is available
     *
     * @return bool True if WooCommerce is available
     */
    protected function is_woocommerce_available(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Helper method to check if user has required capabilities
     *
     * @param string $capability The capability to check
     * @return bool True if user has the capability
     */
    protected function user_can(string $capability): bool
    {
        return current_user_can($capability);
    }

    /**
     * Helper method to make HTTP requests for testing
     *
     * @param string $url The URL to request
     * @param array $args Optional request arguments
     * @return array|WP_Error Response array or WP_Error on failure
     */
    protected function make_request(string $url, array $args = [])
    {
        // Handle Docker environment - convert localhost URLs to internal requests
        $url = $this->resolve_docker_url($url);
        
        $default_args = [
            'timeout' => 10,
            'user-agent' => 'Order Daemon DevTools/' . (defined('ODDT_VERSION') ? ODDT_VERSION : '1.0.0'),
            'headers' => [
                'Accept' => 'application/json',
            ]
        ];

        $args = wp_parse_args($args, $default_args);
        
        return wp_remote_request($url, $args);
    }

    /**
     * Resolve URLs for Docker environment
     *
     * When running inside Docker, localhost URLs need to be converted to use
     * the current server instead of trying to reach the host's localhost
     *
     * @param string $url The original URL
     * @return string The resolved URL
     */
    private function resolve_docker_url(string $url): string
    {
        // Check if we're likely in a Docker environment
        if (!$this->is_docker_environment()) {
            return $url;
        }
        
        // Convert localhost URLs to use the current server
        if (preg_match('/^https?:\/\/localhost(:\d+)?(.*)/', $url, $matches)) {
            $port = $matches[1] ?? '';
            $path = $matches[2] ?? '';
            
            // Use the current server's protocol and host
            $current_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $protocol = is_ssl() ? 'https' : 'http';
            
            // For internal requests, we can use localhost without the external port
            $resolved_url = $protocol . '://localhost' . $path;
            
            return $resolved_url;
        }
        
        return $url;
    }

    /**
     * Check if we're running in a Docker environment
     *
     * @return bool True if running in Docker
     */
    private function is_docker_environment(): bool
    {
        // Check for common Docker indicators
        if (file_exists('/.dockerenv')) {
            return true;
        }
        
        // Check if cgroup indicates Docker
        if (is_readable('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if ($cgroup && (strpos($cgroup, 'docker') !== false || strpos($cgroup, '/lxc/') !== false)) {
                return true;
            }
        }
        
        // Check environment variables that might indicate Docker
        if (getenv('WORDPRESS_DB_HOST') === 'db') {
            return true;
        }
        
        return false;
    }

    /**
     * Helper method to check database table existence
     *
     * @param string $table_name The table name (without prefix)
     * @return bool True if table exists
     */
    protected function table_exists(string $table_name): bool
    {
        global $wpdb;
        $full_table_name = $wpdb->prefix . $table_name;
        $result = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)
        );
        return $result === $full_table_name;
    }

    /**
     * Helper method to get table row count
     *
     * @param string $table_name The table name (without prefix)
     * @return int Row count or 0 if table doesn't exist
     */
    protected function get_table_row_count(string $table_name): int
    {
        if (!$this->table_exists($table_name)) {
            return 0;
        }

        global $wpdb;
        $full_table_name = $wpdb->prefix . $table_name;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$full_table_name}");
    }

    /**
     * Helper method to format bytes for display
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., "1.5 MB")
     */
    protected function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        
        if ($factor >= count($units)) {
            $factor = count($units) - 1;
        }
        
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Helper method to format execution time for display
     *
     * @param float $seconds Execution time in seconds
     * @return string Formatted string (e.g., "1.5s", "250ms")
     */
    protected function format_time(float $seconds): string
    {
        if ($seconds >= 1) {
            return sprintf("%.2fs", $seconds);
        } else {
            return sprintf("%dms", (int)($seconds * 1000));
        }
    }

    /**
     * Helper method to check if a constant is defined and true
     *
     * @param string $constant_name The constant name
     * @return bool True if constant is defined and true
     */
    protected function is_constant_true(string $constant_name): bool
    {
        return defined($constant_name) && constant($constant_name) === true;
    }

    /**
     * Helper method to safely get a constant value
     *
     * @param string $constant_name The constant name
     * @param mixed $default Default value if constant is not defined
     * @return mixed The constant value or default
     */
    protected function get_constant($constant_name, $default = null)
    {
        return defined($constant_name) ? constant($constant_name) : $default;
    }
}
