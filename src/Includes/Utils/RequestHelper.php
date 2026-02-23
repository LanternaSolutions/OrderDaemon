<?php
/**
 * Request Helper - Input Validation and Sanitization
 *
 * Provides a unified interface for validating and sanitizing user input
 * from $_REQUEST, $_POST, and $_GET arrays. Implements WordPress security
 * best practices including nonce verification, capability checks, and
 * proper input sanitization.
 *
 * This abstraction layer ensures all user input is properly validated
 * and sanitized before being used in the application.
 *
 * @package OrderDaemon\CompletionManager\Includes\Utils
 * @since   2.0.3
 */

declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes\Utils;

use OrderDaemon\CompletionManager\Core\Security\NonceGuard;
use OrderDaemon\CompletionManager\Core\Security\SecurityException;

/**
 * Request Helper Class
 *
 * Handles all user input validation and sanitization using WordPress
 * security best practices. Provides methods for nonce verification,
 * capability checks, and input sanitization.
 */
class RequestHelper
{
    /**
     * Validate and sanitize request parameters
     *
     * @param array $params Parameters to validate
     * @param array $rules Validation rules
     * @return array Sanitized parameters
     * @throws \InvalidArgumentException When validation fails
     */
    public static function validate_and_sanitize(array $params, array $rules): array
    {
        $sanitized = [];

        foreach ($rules as $key => $rule) {
            if (!isset($params[$key])) {
                if ($rule['required'] ?? false) {
                    throw new \InvalidArgumentException(esc_html("Required parameter '{$key}' is missing"));
                }
                continue;
            }

            $value = wp_unslash($params[$key]);

            // Apply type-specific sanitization
            switch ($rule['type'] ?? 'string') {
                case 'string':
                    $value = sanitize_text_field($value);
                    break;
                case 'integer':
                    $value = intval($value);
                    break;
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'array':
                    $value = is_array($value) ? array_map('sanitize_text_field', $value) : [];
                    break;
                case 'email':
                    $value = sanitize_email($value);
                    break;
                case 'url':
                    $value = esc_url_raw($value);
                    break;
            }

            // Apply custom validation rules
            if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
                throw new \InvalidArgumentException(esc_html("Parameter '{$key}' must be at least {$rule['min_length']} characters"));
            }

            if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                throw new \InvalidArgumentException(esc_html("Parameter '{$key}' must not exceed {$rule['max_length']} characters"));
            }

            if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                throw new \InvalidArgumentException(esc_html("Parameter '{$key}' does not match required format"));
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Verify WordPress nonce
     *
     * @param string $nonce Nonce value to verify
     * @param string $action Action name for nonce
     * @return bool True if nonce is valid, false otherwise
     */
    public static function verify_nonce(string $nonce, string $action = '-1'): bool
    {
        return wp_verify_nonce($nonce, $action);
    }

    /**
     * Check user capabilities
     *
     * @param string|array $capabilities Capability or array of capabilities to check
     * @return bool True if user has required capabilities, false otherwise
     */
    public static function check_capabilities($capabilities): bool
    {
        if (is_string($capabilities)) {
            return current_user_can($capabilities);
        }

        if (is_array($capabilities)) {
            foreach ($capabilities as $capability) {
                if (!current_user_can($capability)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Validate and sanitize $_REQUEST parameters
     *
     * @param array $rules Validation rules
     * @return array Sanitized $_REQUEST parameters
     * @throws \InvalidArgumentException When validation fails
     */
    public static function validate_request(array $rules): array
    {
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'odcm_request')) {
            throw new \InvalidArgumentException(esc_html('Invalid security token'));
        }

        return self::validate_and_sanitize($_REQUEST, $rules);
    }

    /**
     * Validate and sanitize $_POST parameters
     *
     * @param array $rules Validation rules
     * @return array Sanitized $_POST parameters
     * @throws \InvalidArgumentException When validation fails
     */
    public static function validate_post(array $rules): array
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'odcm_post')) {
            throw new \InvalidArgumentException(esc_html('Invalid security token'));
        }

        return self::validate_and_sanitize($_POST, $rules);
    }

    /**
     * Validate and sanitize $_GET parameters
     *
     * @param array $rules Validation rules
     * @return array Sanitized $_GET parameters
     * @throws \InvalidArgumentException When validation fails
     */
    public static function validate_get(array $rules): array
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'odcm_get')) {
            throw new \InvalidArgumentException(esc_html('Invalid security token'));
        }

        return self::validate_and_sanitize($_GET, $rules);
    }

    /**
     * Check if request method matches
     *
     * @param string $method HTTP method to check (GET, POST, PUT, DELETE, etc.)
     * @return bool True if request method matches, false otherwise
     */
    public static function is_method(string $method): bool
    {
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        return strtoupper($request_method) === strtoupper($method);
    }

    /**
     * Get sanitized request parameter with default
     *
     * @param string $key Parameter key
     * @param mixed $default Default value if parameter not found
     * @param array $rules Optional validation rules
     * @return mixed Sanitized parameter value
     */
    public static function get_param(string $key, $default = null, array $rules = [])
    {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        $value = wp_unslash($_REQUEST[$key]);

        // Sanitize based on type if no rules provided
        if (empty($rules)) {
            return sanitize_text_field($value);
        }

        try {
            $validated = self::validate_and_sanitize([$key => $value], [$key => $rules]);
            return $validated[$key] ?? $default;
        } catch (\InvalidArgumentException $e) {
            return $default;
        }
    }

    /**
     * Log validation error
     *
     * @param string $message Error message
     * @param string $operation Operation that failed
     * @param array $context Additional context
     */
    private static function log_error(string $message, string $operation = '', array $context = []): void
    {
        // Build a detailed error message
        $error_message = '[ODCM RequestHelper ERROR] ' . $message;
        if (!empty($operation)) {
            $error_message .= " (Operation: {$operation})";
        }

        // Add context information if provided
        if (!empty($context)) {
            $error_message .= ' | Context: ' . json_encode($context);
        }

        // Use WordPress debug logger when available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($error_message);
        }

        // Persist the error in a transient for debugging purposes
        $log = get_transient('odcm_request_log');
        if (!is_array($log)) {
            $log = [];
        }

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'message'   => $message,
            'operation' => $operation,
            'context'   => $context,
            'error_type'=> 'error',
        ];

        $log[] = $log_entry;
        set_transient('odcm_request_log', $log, HOUR_IN_SECONDS);

        // Store the most recent error in an option for quick access
        update_option('odcm_last_request_error', $log_entry, 'no');
    }

    /**
     * Validate input for security
     *
     * @param string $input Input to validate
     * @return bool True if input is safe, false otherwise
     */
    private static function validate_input(string $input): bool
    {
        // Only allow specific safe operations
        $allowed_operations = '/^\s*(SELECT|INSERT|UPDATE|DELETE|SHOW|DESCRIBE|EXPLAIN)\s+/i';

        if (! preg_match($allowed_operations, $input)) {
            return false;
        }

        // No dangerous patterns - rely on WordPress security functions
        return true;
    }

    /**
     * Log security error
     *
     * @param string $message Error message
     * @param string $operation Operation that failed
     * @param array $context Additional context
     */
    private static function log_security_error(string $message, string $operation = '', array $context = []): void
    {
        // Build detailed error message
        $error_message = '[ODCM RequestHelper SECURITY ERROR] ' . $message;
        if (!empty($operation)) {
            $error_message .= " (Operation: {$operation})";
        }

        // Add context information
        if (!empty($context)) {
            $error_message .= ' | Context: ' . json_encode($context);
        }

        // Use WordPress debug logger
        if (function_exists('wp_debug_log')) {
            wp_debug_log($error_message);
        }

        // Persist error for debugging
        $log = get_transient('odcm_request_security_log');
        if (!is_array($log)) {
            $log = [];
        }

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'message'   => $message,
            'operation' => $operation,
            'context'   => $context,
            'error_type'=> 'security',
        ];

        $log[] = $log_entry;
        set_transient('odcm_request_security_log', $log, HOUR_IN_SECONDS);
    }
}