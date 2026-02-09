<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use Exception;

/**
 * Custom exception for attribution tracking errors
 *
 * This exception is thrown when errors occur during the attribution tracking process.
 * It extends the base Exception class and provides additional context for attribution-related failures.
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   2.0.4
 */
class AttributionTrackerException extends Exception
{
    /**
     * Constructor for AttributionTrackerException
     *
     * @param string         $message  The error message
     * @param int            $code     The error code (default: 0)
     * @param Exception|null $previous The previous exception for chaining (default: null)
     */
    public function __construct(string $message, int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Custom exception for validation errors
 *
 * This exception is thrown when validation fails during the attribution tracking process.
 * It extends AttributionTrackerException and is specifically used for validation-related failures.
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   2.0.4
 */
class AttributionValidationException extends AttributionTrackerException
{
    /**
     * Constructor for AttributionValidationException
     *
     * @param string $message The error message
     * @param int    $code    The error code (default: 400)
     */
    public function __construct(string $message, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}

/**
 * Custom exception for configuration errors
 *
 * This exception is thrown when configuration issues occur during the attribution tracking process.
 * It extends AttributionTrackerException and is specifically used for configuration-related failures.
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   2.0.4
 */
class AttributionConfigurationException extends AttributionTrackerException
{
    /**
     * Constructor for AttributionConfigurationException
     *
     * @param string $message The error message
     * @param int    $code    The error code (default: 500)
     */
    public function __construct(string $message, int $code = 500)
    {
        parent::__construct($message, $code);
    }
}

/**
 * AttributionTracker
 *
 * Captures comprehensive context around order-affecting operations. This class
 * is designed to be lightweight, safe, and performance-conscious while still
 * offering detailed attributions (request type, source plugin, user context,
 * external webhook detection, and performance metrics).
 *
 * Architectural notes:
 * - Singleton access via instance() to support centralized, request-scoped usage
 * - Request-level caching to prevent expensive recomputation
 * - Layered detection combining WP environment signals, headers, and call stack
 * - Defensive coding with circuit breakers and time budgets
 * - Strict sanitization of any data derived from request variables
 *
 * Filters (odcm_ prefix in global scope):
 * - odcm_enable_context_cache (bool, default true)
 * - odcm_enable_deep_attribution (bool, default true)
 * - odcm_attribution_backtrace_limit (int, default 20)
 * - odcm_attribution_time_budget_ms (int, default 25)
 * - odcm_attribution_context (array, final context)
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 */
final class AttributionTracker
{
    /** @var self|null */
    private static ?self $instance = null;

    /**
     * In-memory request cache for context. Not persisted between requests.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $cached_context = null;

    /** @var float|null Timestamp when capture_context last ran (microtime true) */
    private static ?float $last_captured_at = null;

    /** @var bool Whether the last context came from cache */
    private static bool $served_from_cache = false;

    /**
     * Get singleton instance.
     *
     * @return self The singleton instance of AttributionTracker
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Main entry: builds or returns cached attribution context for the request.
     *
     * This is the primary method for capturing comprehensive attribution context
     * around order-affecting operations. It handles caching, error handling,
     * and orchestrates all the detection methods to build a complete context.
     *
     * Structure example:
     * [
     *   'request_type'    => 'rest|ajax|admin|cron|cli|action_scheduler|frontend|webhook',
     *   'source_plugin'   => ['type' => 'plugin|mu-plugin|theme|vendor|core', 'slug' => '...', 'file' => '...', 'frame' => 7, 'confidence' => 0.8],
     *   'user_context'    => ['user_id' => 1, 'roles' => [...], 'caps' => [...], 'is_logged_in' => true, 'ip' => '...', 'user_agent' => '...', 'referer' => '...', 'session' => [...]],
     *   'external_service'=> ['name' => 'stripe', 'indicators' => {...}, 'confidence' => 0.95] | null,
     *   'environment'     => ['wp_version' => '...', 'wc_version' => '...', 'php_version' => '...'],
     *   'http'            => ['method' => 'GET', 'uri' => '/wp-json/...', 'query' => '...', 'headers' => {...}],
     *   'performance'     => ['build_ms' => 3.4, 'memory_delta' => 1024, 'cache' => true, 'backtrace_ms' => 1.1],
     *   'timestamp'       => 'RFC3339 string',
     * ]
     *
     * @return array<string,mixed> The complete attribution context array
     */
    public function capture_context(): array
    {
        try {
            $perf_start = microtime(true);
            self::$served_from_cache = false;

            // Validate configuration
            $cache_enabled = $this->validate_cache_configuration();

            if ($cache_enabled && is_array(self::$cached_context)) {
                self::$served_from_cache = true;
                $context = self::$cached_context;
                $context['performance']['cache'] = true;
                return $context;
            }

            // Enhanced error handling for header processing
            try {
                $headers = $this->get_normalized_headers();
            } catch (AttributionValidationException $e) {
                odcm_log_message(esc_html("Header validation failed: " . $e->getMessage()), 'error');
                $headers = []; // Fallback to empty headers
            }

            // Layered detectors with error handling
            $request_type = $this->detect_request_type($headers);
            $user_context = $this->capture_user_context($headers);
            $external_service = $this->detect_external_service($headers);

            // Plugin attribution with timeout handling
            $source_plugin = $this->detect_source_plugin_with_timeout();

            // Environment & HTTP info with validation
            $environment = $this->get_environment_data();
            $http = $this->get_http_data($headers);

            $perf_end = microtime(true);
            $mem_delta = $this->calculate_memory_delta($perf_start);

            $context = [
                'request_type' => $request_type,
                'source_plugin' => $source_plugin,
                'user_context' => $user_context,
                'external_service' => $external_service,
                'environment' => $environment,
                'http' => $http,
                'performance' => [
                    'build_ms' => ($perf_end - $perf_start) * 1000.0,
                    'memory_delta' => $mem_delta,
                    'cache' => false,
                    'backtrace_ms' => $source_plugin['backtrace_ms'] ?? 0,
                ],
                'timestamp' => odcm_iso8601_now(),
            ];

            // Allow last-minute customization with validation
            $context = $this->validate_and_apply_filters($context);

            if ($cache_enabled) {
                self::$cached_context = $context;
                self::$last_captured_at = microtime(true);
            }

            return $context;
        } catch (AttributionTrackerException $e) {
            odcm_critical_log(esc_html("Failed to capture attribution context: " . $e->getMessage()));
            return $this->get_fallback_context($e);
        } catch (Exception $e) {
            odcm_critical_log(esc_html("Unexpected error in capture_context: " . $e->getMessage()));
            return $this->get_fallback_context($e);
        }
    }

    /**
     * Detect the request type with layered heuristics.
     *
     * This method determines the type of request being processed by examining
     * various WordPress environment signals, headers, and server variables.
     * It uses a layered approach to accurately identify the request context.
     *
     * @param array<string,string> $headers Optional headers array for webhook detection
     * @return string One of: cli, wp_cli, cron, action_scheduler, rest, ajax, admin, frontend, webhook
     */
    public function detect_request_type(array $headers = []): string
    {
        // CLI / WP-CLI
        if (php_sapi_name() === 'cli' || defined('STDIN')) {
            if (defined('WP_CLI') && (bool) constant('WP_CLI')) {
                return 'wp_cli';
            }
            return 'cli';
        }

        // Cron
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            // Often used by Action Scheduler
            if ($this->looks_like_action_scheduler($headers)) {
                return 'action_scheduler';
            }
            return 'cron';
        }

        // AJAX
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }

        // REST
        if (defined('REST_REQUEST') && (bool) constant('REST_REQUEST')) {
            // Some gateways deliver webhooks via REST routes
            if ($this->looks_like_webhook($headers)) {
                return 'webhook';
            }
            return 'rest';
        }

        // Webhook heuristics outside REST too
        if ($this->looks_like_webhook($headers)) {
            return 'webhook';
        }

        // Admin
        if (is_admin()) {
            return 'admin';
        }

        // Fallback
        return 'frontend';
    }

    /**
     * Perform call stack analysis to attribute likely source plugin/theme/vendor.
     * Uses WordPress-compatible backtrace approach with limited frames and a small time budget.
     *
     * This method analyzes the call stack to determine which plugin, theme, or
     * vendor code is most likely responsible for the current operation. It uses
     * a time-limited backtrace approach to avoid performance impact.
     *
     * @return array<string,mixed> ['type'=>..., 'slug'=>..., 'file'=>..., 'frame'=>int, 'confidence'=>float]
     */
    public function detect_source_plugin(): array
    {
        try {
            $allowed = (bool) apply_filters('odcm_enable_deep_attribution', true);
            $limit = (int) apply_filters('odcm_attribution_backtrace_limit', 20);
            $budget = (int) apply_filters('odcm_attribution_time_budget_ms', 25);

            if (!$allowed) {
                return $this->get_default_plugin_info();
            }

            // Get backtrace with timeout protection
            $trace = $this->get_backtrace_with_timeout($limit, $budget);

            if (!is_array($trace) || empty($trace)) {
                return $this->get_default_plugin_info();
            }

            // Enhanced file path validation
            $content_dir = $this->validate_directory_path(odcm_get_uploads_dir());
            $plugins_dir = $this->validate_directory_path(odcm_get_plugin_dir());
            $mu_dir = $this->validate_mu_plugin_directory();
            $themes_dir = $this->validate_theme_directory();

            $best = $this->get_default_plugin_info();
            $frame_index = -1;

            foreach ($trace as $i => $frame) {
                if (!is_array($frame) || empty($frame['file'])) {
                    continue;
                }

                $file = wp_normalize_path((string) $frame['file']);

                // Skip core with enhanced validation
                if ($this->is_core_file($file)) {
                    continue;
                }

                $matched = $this->analyze_frame_for_plugin($file, $content_dir, $plugins_dir, $mu_dir, $themes_dir, $i);

                if ($best['confidence'] < $matched['confidence']) {
                    $best = $matched;
                    $frame_index = $i;
                }
            }

            if ($frame_index >= 0 && empty($best['file'])) {
                $best['file'] = (string) $trace[$frame_index]['file'];
                $best['frame'] = $frame_index;
            }

            return $best;
        } catch (AttributionTrackerException $e) {
            odcm_log_message(esc_html("Failed to detect source plugin: " . $e->getMessage()), 'warning');
            return $this->get_default_plugin_info();
        } catch (Exception $e) {
            odcm_critical_log(esc_html("Unexpected error in detect_source_plugin: " . $e->getMessage()));
            return $this->get_default_plugin_info();
        }
    }

    /**
     * Capture user context with minimal sensitive data and sanitization.
     *
     * This method captures user-related context information while minimizing
     * the collection of sensitive data. It includes user ID, roles, capabilities,
     * login status, IP address, user agent, referer, and session information.
     *
     * @param array<string,string> $headers Optional headers array for IP detection
     * @return array<string,mixed> The user context array
     */
    public function capture_user_context(array $headers = []): array
    {
        try {
            $user_id = $this->get_validated_user_id();
            $is_logged_in = $this->check_user_login_status();

            $roles = [];
            $caps = [];

            if ($is_logged_in) {
                $roles = $this->get_user_roles();
                $caps = $this->get_user_capabilities();
            }

            // Enhanced IP detection with validation
            $ip = $this->detect_validated_ip($headers);

            // Enhanced header processing with validation
            $user_agent = $this->get_validated_header($headers, 'user-agent');
            $referer = $this->get_validated_header($headers, 'referer');

            // Enhanced session detection
            $session = $this->get_validated_session_data();

            return [
                'user_id' => $user_id ?: null,
                'roles' => $roles,
                'caps' => $caps,
                'is_logged_in' => $is_logged_in,
                'ip' => $ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'session' => $session,
            ];
        } catch (AttributionValidationException $e) {
            odcm_log_message(esc_html("User context validation failed: " . $e->getMessage()), 'warning');
            return $this->get_default_user_context();
        } catch (Exception $e) {
            odcm_critical_log(esc_html("Unexpected error in capture_user_context: " . $e->getMessage()));
            return $this->get_default_user_context();
        }
    }

    /**
     * Detect external services (e.g., Stripe, PayPal) based on headers and UA.
     *
     * This method detects external payment services and webhooks by examining
     * headers and user agent strings. It supports Stripe, PayPal, Mollie, Square,
     * and generic webhook detection with confidence scoring.
     *
     * @param array<string,string> $headers Optional headers array for service detection
     * @return array<string,mixed>|null ['name'=>..., 'indicators'=>[], 'confidence'=>float] or null if no service detected
     */
    public function detect_external_service(array $headers = []): ?array
    {
        try {
            $ua = isset($headers['user-agent']) ? strtolower($headers['user-agent']) : '';

            // Stripe
            if (isset($headers['stripe-signature']) || strpos($ua, 'stripe') !== false) {
                return [
                    'name' => 'stripe',
                    'indicators' => [
                        'stripe-signature' => isset($headers['stripe-signature']),
                        'ua_contains' => strpos($ua, 'stripe') !== false,
                    ],
                    'confidence' => isset($headers['stripe-signature']) ? 0.99 : 0.8,
                ];
            }

            // PayPal
            if (isset($headers['paypal-transmission-sig']) || isset($headers['paypal-auth-algo']) || strpos($ua, 'paypal') !== false) {
                return [
                    'name' => 'paypal',
                    'indicators' => [
                        'paypal-transmission-sig' => isset($headers['paypal-transmission-sig']),
                        'paypal-auth-algo' => isset($headers['paypal-auth-algo']),
                        'ua_contains' => strpos($ua, 'paypal') !== false,
                    ],
                    'confidence' => (isset($headers['paypal-transmission-sig']) || isset($headers['paypal-auth-algo'])) ? 0.98 : 0.75,
                ];
            }

            // Mollie
            if (isset($headers['x-mollie-signature']) || strpos($ua, 'mollie') !== false) {
                return [
                    'name' => 'mollie',
                    'indicators' => [
                        'x-mollie-signature' => isset($headers['x-mollie-signature']),
                        'ua_contains' => strpos($ua, 'mollie') !== false,
                    ],
                    'confidence' => isset($headers['x-mollie-signature']) ? 0.96 : 0.7,
                ];
            }

            // Square
            if (isset($headers['x-square-signature']) || strpos($ua, 'square') !== false) {
                return [
                    'name' => 'square',
                    'indicators' => [
                        'x-square-signature' => isset($headers['x-square-signature']),
                        'ua_contains' => strpos($ua, 'square') !== false,
                    ],
                    'confidence' => isset($headers['x-square-signature']) ? 0.95 : 0.65,
                ];
            }

            // Generic webhook signals
            if ($this->looks_like_webhook($headers)) {
                return [
                    'name' => 'webhook',
                    'indicators' => [
                        'content-type' => $headers['content-type'] ?? null,
                        'event' => $headers['x-event'] ?? ($headers['x-webhook-event'] ?? null),
                    ],
                    'confidence' => 0.6,
                ];
            }

            return null;
        } catch (Exception $e) {
            odcm_critical_log(esc_html("Error detecting external service: " . $e->getMessage()));
            return null;
        }
    }

    // ------------------------
    // Internal helper methods
    // ------------------------

    /**
     * Determine if headers/route looks like a webhook.
     *
     * This method uses heuristics to determine if the current request is a webhook
     * by examining user agent strings, content types, request URIs, and specific
     * webhook signature headers from various payment gateways.
     *
     * @param array<string,string> $headers The headers array to examine
     * @return bool True if the request appears to be a webhook, false otherwise
     */
    private function looks_like_webhook(array $headers): bool
    {
        $ua = isset($headers['user-agent']) ? strtolower($headers['user-agent']) : '';
        $ct = isset($headers['content-type']) ? strtolower($headers['content-type']) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
        $uri = strtolower($uri);

        if (strpos($ua, 'webhook') !== false) {
            return true;
        }
        if (strpos($ct, 'application/json') !== false && (strpos($uri, '/webhook') !== false || strpos($uri, 'wc-api') !== false)) {
            return true;
        }
        if (isset($headers['stripe-signature']) || isset($headers['paypal-transmission-sig']) || isset($headers['x-mollie-signature']) || isset($headers['x-square-signature'])) {
            return true;
        }
        return false;
    }

    /**
     * Heuristics for Action Scheduler processing.
     *
     * This method uses multiple heuristics to determine if the current request
     * is being processed by Action Scheduler. It checks user agent strings,
     * current filter names, and various WordPress constants and classes.
     *
     * @param array<string,string> $headers The headers array to examine
     * @return bool True if the request appears to be processed by Action Scheduler, false otherwise
     */
    private function looks_like_action_scheduler(array $headers): bool
    {
        // Common hints: UA includes ActionScheduler; WP-Cron + specific args; file paths in backtrace
        $ua = isset($headers['user-agent']) ? strtolower($headers['user-agent']) : '';
        if (strpos($ua, 'actionscheduler') !== false) {
            return true;
        }
        // Check current filter name if available
        if (function_exists('current_filter')) {
            $cf = current_filter();
            if (is_string($cf) && strpos($cf, 'action_scheduler_') !== false) {
                return true;
            }
        }

        // Use a safer approach to detect action scheduler
        $action_scheduler_detected = false;

        // Try to detect if we're running in an action scheduler context
        // Use alternative detection methods that don't rely on debug functions
        if (function_exists('wp_get_current_user') && !is_admin() && !wp_doing_ajax() && wp_doing_cron()) {
            // If we're in cron context but not admin or ajax, it might be action scheduler
            $action_scheduler_detected = true;
        } elseif (defined('ACTION_SCHEDULER_VERSION')) {
            // Direct check for Action Scheduler constant
            $action_scheduler_detected = true;
        } elseif (class_exists('ActionScheduler_Versions')) {
            // Check for Action Scheduler class existence
            $action_scheduler_detected = true;
        }

        return $action_scheduler_detected;
    }

    /**
     * Normalize incoming headers from $_SERVER keys to lowercase header names.
     *
     * @return array<string,string>
     */
    private function get_normalized_headers(): array
    {
        try {
            $allowed_headers = $this->get_allowed_headers();

            $headers = [];

            // Prefer getallheaders if available
            if (function_exists('getallheaders')) {
                $raw = @getallheaders();
                if (is_array($raw)) {
                    foreach ($raw as $key => $value) {
                        $lk = strtolower(str_replace('_', '-', (string) $key));
                        if (isset($allowed_headers[$lk])) {
                            $headers[$lk] = $this->validate_and_sanitize_header($lk, $value, $allowed_headers[$lk]);
                        }
                    }
                }
            }
            
            // Fallback to $_SERVER - Targeted extraction to avoid processing the whole stack
            foreach ($allowed_headers as $name => $rule) {
                $server_key = '';
                if ($name === 'content-type') {
                    $server_key = 'CONTENT_TYPE';
                } elseif ($name === 'content-length') {
                    $server_key = 'CONTENT_LENGTH';
                } else {
                    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
                }

                if (isset($_SERVER[$server_key])) {
                    $headers[$name] = $this->validate_and_sanitize_header($name, wp_unslash($_SERVER[$server_key]), $rule);
                }
            }
            return $headers;
        } catch (AttributionValidationException $e) {
            odcm_log_message(esc_html("Header validation failed: " . $e->getMessage()), 'error');
            throw $e;
        } catch (Exception $e) {
            odcm_critical_log(esc_html("Unexpected error in get_normalized_headers: " . $e->getMessage()));
            throw new AttributionTrackerException(esc_html("Failed to normalize headers", 500, $e));
        }
    }

    // ------------------------
    // Error handling methods
    // ------------------------

    /**
     * Validate cache configuration with error handling
     */
    private function validate_cache_configuration(): bool
    {
        $cache_enabled = (bool) apply_filters('odcm_enable_context_cache', true);
        if (!is_bool($cache_enabled)) {
            throw new AttributionConfigurationException(esc_html("Invalid cache configuration"));
        }
        return $cache_enabled;
    }

    /**
     * Validate directory path with error handling
     */
    private function validate_directory_path(string $path): string
    {
        $normalized = wp_normalize_path($path);
        if (!is_dir($normalized)) {
            throw new AttributionConfigurationException(esc_html("Invalid directory path: $path"));
        }
        return $normalized;
    }

    /**
     * Validate and sanitize header with error handling
     */
    private function validate_and_sanitize_header(string $name, $value, array $rule): string
    {
        try {
            return odcm_validate_and_sanitize_params([$name => $value], [$name => $rule])[$name];
        } catch (InvalidArgumentException $e) {
            throw new AttributionValidationException(esc_html("Invalid header value for $name: " . $e->getMessage()));
        }
    }

    /**
     * Get fallback context when errors occur
     */
    private function get_fallback_context(Exception $e): array
    {
        return [
            'request_type' => 'unknown',
            'source_plugin' => ['type' => 'unknown', 'slug' => null, 'file' => null, 'frame' => null, 'confidence' => 0.0],
            'user_context' => $this->get_default_user_context(),
            'external_service' => null,
            'environment' => $this->get_default_environment(),
            'http' => ['method' => null, 'uri' => null, 'query' => null, 'headers' => []],
            'performance' => ['build_ms' => 0, 'memory_delta' => 0, 'cache' => false, 'backtrace_ms' => 0],
            'timestamp' => odcm_iso8601_now(),
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'timestamp' => time(),
            ],
        ];
    }

    /**
     * Get default plugin info for fallback
     */
    private function get_default_plugin_info(): array
    {
        return [
            'type' => 'unknown',
            'slug' => null,
            'file' => null,
            'frame' => null,
            'confidence' => 0.0,
            'backtrace_ms' => 0,
        ];
    }

    /**
     * Get default user context for fallback
     */
    private function get_default_user_context(): array
    {
        return [
            'user_id' => null,
            'roles' => [],
            'caps' => [],
            'is_logged_in' => false,
            'ip' => null,
            'user_agent' => null,
            'referer' => null,
            'session' => [
                'php_session' => false,
                'wc_session' => false,
                'wc_customer_id' => null,
            ],
        ];
    }

    /**
     * Get default environment for fallback
     */
    private function get_default_environment(): array
    {
        return [
            'wp_version' => null,
            'wc_version' => null,
            'php_version' => null,
        ];
    }

    /**
     * Extract a slug from a file path under a known root.
     *
     * @param string $file
     * @param string $root
     * @return string|null
     */
    private function extract_slug(string $file, string $root): ?string
    {
        $rel = trim(str_replace($root, '', $file), '/');
        $parts = explode('/', $rel);
        return !empty($parts[0]) ? sanitize_text_field($parts[0]) : null;
    }

    /**
     * Guess a vendor slug from a generic path (composer vendor).
     *
     * @param string $file
     * @return string|null
     */
    private function guess_vendor_slug(string $file): ?string
    {
        $file = trim($file);
        if ($file === '') {
            return null;
        }
        $parts = explode('/', $file);
        $vendorIndex = array_search('vendor', $parts, true);
        if ($vendorIndex !== false && isset($parts[$vendorIndex + 1])) {
            return sanitize_text_field((string) $parts[$vendorIndex + 1]);
        }
        return null;
    }

    /**
     * Detect client IP from common headers with validation.
     *
     * @return string|null
     */
    private function detect_ip(): ?string
    {
        $headers = [
            'client-ip' => ['type' => 'string'],
            'x-forwarded-for' => ['type' => 'string'],
            'x-forwarded' => ['type' => 'string'],
            'x-cluster-client-ip' => ['type' => 'string'],
            'forwarded-for' => ['type' => 'string'],
            'forwarded' => ['type' => 'string'],
        ];

        try {
            $validated_headers = odcm_validate_and_sanitize_params($this->headers, $headers);
            $candidates = [];

            if (isset($validated_headers['client-ip'])) {
                $candidates[] = $validated_headers['client-ip'];
            }
            if (isset($validated_headers['x-forwarded-for'])) {
                $parts = explode(',', $validated_headers['x-forwarded-for']);
                if (!empty($parts)) {
                    $candidates[] = trim((string) $parts[0]);
                }
            }
            if (isset($validated_headers['x-forwarded'])) {
                $candidates[] = $validated_headers['x-forwarded'];
            }
            if (isset($validated_headers['x-cluster-client-ip'])) {
                $candidates[] = $validated_headers['x-cluster-client-ip'];
            }
            if (isset($validated_headers['forwarded-for'])) {
                $candidates[] = $validated_headers['forwarded-for'];
            }
            if (isset($validated_headers['forwarded'])) {
                $candidates[] = $validated_headers['forwarded'];
            }

            return !empty($candidates) ? $candidates[0] : null;
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Check presence of WooCommerce session cookie with enhanced validation.
     *
     * @return bool
     */
    private function has_wc_session_cookie(): bool
    {
        $cookie_rules = [
            'wp_woocommerce_session_' => ['type' => 'string', 'required' => true]
        ];

        try {
            foreach ($_COOKIE as $name => $value) {
                if (is_string($name) && strpos($name, 'wp_woocommerce_session_') === 0) {
                    $validated_name = odcm_validate_and_sanitize_params(['name' => $name], $cookie_rules)['name'];
                    if (preg_match('/^wp_woocommerce_session_[a-zA-Z0-9]+$/', $validated_name)) {
                        return true;
                    }
                }
            }
            return false;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Get backtrace with timeout protection
     *
     * @param int $limit Maximum number of frames to retrieve
     * @param int $budget Maximum time budget in milliseconds
     * @return array|null Backtrace array or null if timeout occurs
     */
    private function get_backtrace_with_timeout(int $limit, int $budget): ?array
    {
        $t0 = microtime(true);

        // Use debug_backtrace only when absolutely necessary and wrap in error suppression
        if (function_exists('debug_backtrace') && apply_filters('odcm_allow_backtrace_for_attribution', false)) {
            $trace = @debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, max(1, $limit));

            // Check time budget
            $elapsed_ms = (microtime(true) - $t0) * 1000.0;
            if ($elapsed_ms > $budget) {
                odcm_log_message(esc_html("Backtrace timeout exceeded: {$elapsed_ms}ms (budget: {$budget}ms)"), 'warning');
                return null;
            }

            return is_array($trace) ? $trace : null;
        }

        return null;
    }

    /**
     * Check if a file is a WordPress core file
     *
     * @param string $file File path to check
     * @return bool True if file is a WordPress core file, false otherwise
     */
    private function is_core_file(string $file): bool
    {
        $file = wp_normalize_path($file);
        return strpos($file, '/wp-includes/') !== false || strpos($file, '/wp-admin/') !== false;
    }

    /**
     * Analyze a backtrace frame for plugin information
     *
     * @param string $file File path from backtrace frame
     * @param string $content_dir Content directory path
     * @param string $plugins_dir Plugins directory path
     * @param string $mu_dir MU plugins directory path
     * @param string $themes_dir Themes directory path
     * @param int $frame Frame index
     * @return array Plugin information array
     */
    private function analyze_frame_for_plugin(string $file, string $content_dir, string $plugins_dir, string $mu_dir, string $themes_dir, int $frame): array
    {
        $matched = [
            'type' => 'vendor',
            'slug' => null,
            'file' => $file,
            'frame' => $frame,
            'confidence' => 0.4,
        ];

        if (strpos($file, $plugins_dir) === 0) {
            $matched['type'] = 'plugin';
            $matched['slug'] = $this->extract_slug($file, $plugins_dir);
            $matched['confidence'] = 0.85;
        } elseif (strpos($file, $mu_dir) === 0) {
            $matched['type'] = 'mu-plugin';
            $matched['slug'] = $this->extract_slug($file, $mu_dir);
            $matched['confidence'] = 0.8;
        } elseif (strpos($file, $themes_dir) === 0) {
            $matched['type'] = 'theme';
            $matched['slug'] = $this->extract_slug($file, $themes_dir);
            $matched['confidence'] = 0.7;
        } elseif (strpos($file, $content_dir) === 0) {
            $matched['type'] = 'content';
            $matched['slug'] = $this->extract_slug($file, $content_dir);
            $matched['confidence'] = 0.6;
        } else {
            $matched['type'] = 'vendor';
            $matched['slug'] = $this->guess_vendor_slug($file);
            $matched['confidence'] = 0.4;
        }

        return $matched;
    }

    /**
     * Get validated user ID
     *
     * @return int|null Validated user ID or null if not available
     */
    private function get_validated_user_id(): ?int
    {
        if (!function_exists('get_current_user_id')) {
            return null;
        }

        $user_id = (int) get_current_user_id();
        return $user_id > 0 ? $user_id : null;
    }

    /**
     * Check user login status
     *
     * @return bool True if user is logged in, false otherwise
     */
    private function check_user_login_status(): bool
    {
        if (!function_exists('is_user_logged_in')) {
            return false;
        }

        return (bool) is_user_logged_in();
    }

    /**
     * Get user roles
     *
     * @return array Array of user roles
     */
    private function get_user_roles(): array
    {
        if (!function_exists('wp_get_current_user')) {
            return [];
        }

        $user = wp_get_current_user();
        if (!$user || !isset($user->roles)) {
            return [];
        }

        $roles = [];
        foreach ((array) $user->roles as $role) {
            $roles[] = sanitize_text_field((string) $role);
        }

        return $roles;
    }

    /**
     * Get user capabilities
     *
     * @return array Array of user capabilities
     */
    private function get_user_capabilities(): array
    {
        if (!function_exists('current_user_can')) {
            return [];
        }

        $key_caps = [
            'manage_woocommerce',
            'view_woocommerce_reports',
            'edit_shop_orders',
            'manage_options',
        ];

        $caps = [];
        foreach ($key_caps as $cap) {
            $caps[$cap] = (bool) current_user_can($cap);
        }

        return $caps;
    }

    /**
     * Detect validated IP address
     *
     * @param array $headers Headers array
     * @return string|null Validated IP address or null if not available
     */
    private function detect_validated_ip(array $headers): ?string
    {
        $headers = [
            'client-ip' => ['type' => 'string'],
            'x-forwarded-for' => ['type' => 'string'],
            'x-forwarded' => ['type' => 'string'],
            'x-cluster-client-ip' => ['type' => 'string'],
            'forwarded-for' => ['type' => 'string'],
            'forwarded' => ['type' => 'string'],
        ];

        try {
            $validated_headers = odcm_validate_and_sanitize_params($headers, $headers);
            $candidates = [];

            if (isset($validated_headers['client-ip'])) {
                $candidates[] = $validated_headers['client-ip'];
            }
            if (isset($validated_headers['x-forwarded-for'])) {
                $parts = explode(',', $validated_headers['x-forwarded-for']);
                if (!empty($parts)) {
                    $candidates[] = trim((string) $parts[0]);
                }
            }
            if (isset($validated_headers['x-forwarded'])) {
                $candidates[] = $validated_headers['x-forwarded'];
            }
            if (isset($validated_headers['x-cluster-client-ip'])) {
                $candidates[] = $validated_headers['x-cluster-client-ip'];
            }
            if (isset($validated_headers['forwarded-for'])) {
                $candidates[] = $validated_headers['forwarded-for'];
            }
            if (isset($validated_headers['forwarded'])) {
                $candidates[] = $validated_headers['forwarded'];
            }

            return !empty($candidates) ? $candidates[0] : null;
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Get validated header
     *
     * @param array $headers Headers array
     * @param string $name Header name
     * @return string|null Validated header value or null if not available
     */
    private function get_validated_header(array $headers, string $name): ?string
    {
        if (!isset($headers[$name])) {
            return null;
        }

        try {
            $allowed_headers = $this->get_allowed_headers();
            if (!isset($allowed_headers[$name])) {
                return null;
            }

            return $this->validate_and_sanitize_header($name, $headers[$name], $allowed_headers[$name]);
        } catch (AttributionValidationException $e) {
            odcm_log_message(esc_html("Header validation failed for {$name}: " . $e->getMessage()), 'error');
            return null;
        }
    }

    /**
     * Get validated session data
     *
     * @return array Session data array
     */
    private function get_validated_session_data(): array
    {
        $session = [
            'php_session' => isset($_COOKIE[session_name()]) ? true : false,
            'wc_session' => $this->has_wc_session_cookie(),
            'wc_customer_id' => null,
        ];

        if (function_exists('WC') && WC()) {
            $wc = WC();
            if (isset($wc->session) && method_exists($wc->session, 'get_customer_id')) {
                $sid = (string) $wc->session->get_customer_id();
                $session['wc_customer_id'] = $sid !== '' ? sanitize_text_field($sid) : null;
            }
        }

        return $session;
    }

    /**
     * Get allowed headers configuration
     *
     * @return array Allowed headers configuration
     */
    private function get_allowed_headers(): array
    {
        return [
            // Standard web headers
            'user-agent' => ['type' => 'string'],
            'referer' => ['type' => 'string'],
            'content-type' => ['type' => 'string'],
            'content-length' => ['type' => 'integer'],
            'host' => ['type' => 'string'],
            'origin' => ['type' => 'string'],

            // Security headers
            'x-wp-nonce' => ['type' => 'string'],

            // Webhook headers (specific gateways)
            'stripe-signature' => ['type' => 'string'],
            'paypal-transmission-sig' => ['type' => 'string'],
            'paypal-auth-algo' => ['type' => 'string'],
            'x-mollie-signature' => ['type' => 'string'],
            'x-square-signature' => ['type' => 'string'],

            // CORS headers
            'access-control-allow-origin' => ['type' => 'string'],
            'access-control-allow-methods' => ['type' => 'string'],
            'access-control-allow-headers' => ['type' => 'string'],
            'access-control-allow-credentials' => ['type' => 'string'],

            // API headers
            'authorization' => ['type' => 'string'],
            'accept' => ['type' => 'string'],

            // Email headers
            'from' => ['type' => 'string'],
            'reply-to' => ['type' => 'string'],
            'cc' => ['type' => 'string'],
            'bcc' => ['type' => 'string'],

            // IP detection headers
            'client-ip' => ['type' => 'string'],
            'x-forwarded-for' => ['type' => 'string'],
            'x-forwarded' => ['type' => 'string'],
            'x-cluster-client-ip' => ['type' => 'string'],
            'forwarded-for' => ['type' => 'string'],
            'forwarded' => ['type' => 'string'],
        ];
    }

    /**
     * Get environment data
     *
     * @return array Environment data array
     */
    private function get_environment_data(): array
    {
        return [
            'wp_version' => sanitize_text_field((string) get_bloginfo('version')),
            'wc_version' => sanitize_text_field((string) get_option('woocommerce_version')),
            'php_version' => sanitize_text_field((string) PHP_VERSION),
        ];
    }

    /**
     * Get HTTP data
     *
     * @param array $headers Headers array
     * @return array HTTP data array
     */
    private function get_http_data(array $headers): array
    {
        return [
            'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : null,
            'uri' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : null,
            'query' => isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash((string) $_SERVER['QUERY_STRING'])) : null,
            'headers' => $headers,
        ];
    }

    /**
     * Calculate memory delta
     *
     * @param float $perf_start Performance start time
     * @return int Memory delta in bytes
     */
    private function calculate_memory_delta(float $perf_start): int
    {
        return function_exists('memory_get_usage') ? (int) (memory_get_usage() - (self::$last_captured_at ? 0 : 0)) : 0;
    }

    /**
     * Validate and apply filters
     *
     * @param array $context Context array
     * @return array Validated and filtered context
     */
    private function validate_and_apply_filters(array $context): array
    {
        // Allow last-minute customization
        $context = (array) apply_filters('odcm_attribution_context', $context);

        // Validate the final context
        if (!is_array($context)) {
            odcm_log_message(esc_html("Invalid context after filters - expected array, got " . gettype($context)), 'error');
            return $this->get_fallback_context(new AttributionConfigurationException("Invalid context after filters"));
        }

        return $context;
    }

    /**
     * Validate configuration
     */
    private function validate_configuration(): void
    {
        $filters = [
            'odcm_enable_context_cache' => ['type' => 'boolean', 'required' => false],
            'odcm_enable_deep_attribution' => ['type' => 'boolean', 'required' => false],
            'odcm_attribution_backtrace_limit' => ['type' => 'integer', 'required' => false, 'min' => 1, 'max' => 100],
            'odcm_attribution_time_budget_ms' => ['type' => 'integer', 'required' => false, 'min' => 1, 'max' => 1000],
        ];

        foreach ($filters as $filter => $rule) {
            $value = apply_filters($filter, $rule['required'] ? null : $this->get_default_value($rule));
            odcm_validate_and_sanitize_params([$filter => $value], [$filter => $rule]);
        }
    }

    /**
     * Get default value for validation
     *
     * @param array $rule Validation rule
     * @return mixed Default value
     */
    private function get_default_value(array $rule)
    {
        switch ($rule['type']) {
            case 'boolean':
                return false;
            case 'integer':
                return $rule['min'] ?? 0;
            case 'string':
                return '';
            case 'array':
                return [];
            default:
                return null;
        }
    }
}