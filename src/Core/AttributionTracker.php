<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

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
     * @return self
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
     * @return array<string,mixed>
     */
    public function capture_context(): array
    {
        $perf_start = microtime(true);
        self::$served_from_cache = false;

        $cache_enabled = (bool) apply_filters('odcm_enable_context_cache', true);
        if ($cache_enabled && is_array(self::$cached_context)) {
            self::$served_from_cache = true;
            $context = self::$cached_context;
            $context['performance']['cache'] = true;
            return $context;
        }

        $headers = $this->get_normalized_headers();

        // Layered detectors
        $request_type      = $this->detect_request_type($headers);
        $user_context      = $this->capture_user_context($headers);
        $external_service  = $this->detect_external_service($headers);

        // Plugin attribution can be relatively expensive; guard with time budget
        $bt_start = microtime(true);
        $source_plugin     = $this->detect_source_plugin();
        $bt_ms             = (microtime(true) - $bt_start) * 1000.0;

        // Environment & HTTP info (sanitized)
        $environment = [
            'wp_version'  => sanitize_text_field((string) get_bloginfo('version')),
            'wc_version'  => sanitize_text_field((string) get_option('woocommerce_version')),
            'php_version' => sanitize_text_field((string) PHP_VERSION),
        ];

        $http = [
            'method'  => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : null,
            'uri'     => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : null,
            'query'   => isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash((string) $_SERVER['QUERY_STRING'])) : null,
            'headers' => $headers,
        ];

        $perf_end  = microtime(true);
        $mem_delta = function_exists('memory_get_usage') ? (int) (memory_get_usage() - (self::$last_captured_at ? 0 : 0)) : 0; // simplified, avoids heavy baselines

        $context = [
            'request_type'     => $request_type,
            'source_plugin'    => $source_plugin,
            'user_context'     => $user_context,
            'external_service' => $external_service,
            'environment'      => $environment,
            'http'             => $http,
            'performance'      => [
                'build_ms'     => ($perf_end - $perf_start) * 1000.0,
                'memory_delta' => $mem_delta,
                'cache'        => false,
                'backtrace_ms' => $bt_ms,
            ],
            'timestamp'        => odcm_iso8601_now(),
        ];

        // Allow last-minute customization
        $context = (array) apply_filters('odcm_attribution_context', $context);

        if ($cache_enabled) {
            self::$cached_context   = $context;
            self::$last_captured_at = microtime(true);
        }

        return $context;
    }

    /**
     * Detect the request type with layered heuristics.
     *
     * @param array<string,string> $headers
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
     * @return array<string,mixed> ['type'=>..., 'slug'=>..., 'file'=>..., 'frame'=>int, 'confidence'=>float]
     */
    public function detect_source_plugin(): array
    {
        $allowed = (bool) apply_filters('odcm_enable_deep_attribution', true);
        $limit   = (int) apply_filters('odcm_attribution_backtrace_limit', 20);
        $budget  = (int) apply_filters('odcm_attribution_time_budget_ms', 25); // ms

        $result = [
            'type'       => 'unknown',
            'slug'       => null,
            'file'       => null,
            'frame'      => null,
            'confidence' => 0.0,
        ];

        if (!$allowed) {
            return $result;
        }

        // Get backtrace using production-safe methods
        $trace = [];
        $t0 = microtime(true);

        // Use debug_backtrace only when absolutely necessary and wrap in error suppression
        if (function_exists('debug_backtrace') && apply_filters('odcm_allow_backtrace_for_attribution', false)) {
            $trace = @debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, max(1, $limit));
        }
        
        if (!is_array($trace) || empty($trace)) {
            return $result;
        }

        $content_dir = defined('WP_CONTENT_DIR') ? wp_normalize_path((string) constant('WP_CONTENT_DIR')) : (defined('ABSPATH') ? wp_normalize_path((string) (rtrim(ABSPATH, '/\\') . '/wp-content')) : 'wp-content');
        $plugins_dir = defined('WP_PLUGIN_DIR') ? wp_normalize_path((string) constant('WP_PLUGIN_DIR')) : ($content_dir . '/plugins');
        $mu_dir      = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path((string) constant('WPMU_PLUGIN_DIR')) : ($content_dir . '/mu-plugins');
        $themes_dir  = function_exists('get_theme_root') ? get_theme_root() : ($content_dir . '/themes');
        $themes_dir  = is_string($themes_dir) ? wp_normalize_path($themes_dir) : ($content_dir . '/themes');

        $best = $result;
        $frame_index = -1;
        foreach ($trace as $i => $frame) {
            // Circuit breaker on time budget
            $elapsed_ms = (microtime(true) - $t0) * 1000.0;
            if ($elapsed_ms > $budget) {
                break;
            }

            if (!is_array($frame) || empty($frame['file'])) {
                continue;
            }
            $file = wp_normalize_path((string) $frame['file']);

            // Skip core
            if (strpos($file, '/wp-includes/') !== false || strpos($file, '/wp-admin/') !== false) {
                continue;
            }

            $matched = null;
            $confidence = 0.5; // base confidence
            $type = 'vendor';
            $slug = null;

            if (strpos($file, $plugins_dir) === 0) {
                $type = 'plugin';
                $slug = $this->extract_slug($file, $plugins_dir);
                $confidence = 0.85;
            } elseif (strpos($file, $mu_dir) === 0) {
                $type = 'mu-plugin';
                $slug = $this->extract_slug($file, $mu_dir);
                $confidence = 0.8;
            } elseif (strpos($file, $themes_dir) === 0) {
                $type = 'theme';
                $slug = $this->extract_slug($file, $themes_dir);
                $confidence = 0.7;
            } elseif (strpos($file, $content_dir) === 0) {
                $type = 'content';
                $slug = $this->extract_slug($file, $content_dir);
                $confidence = 0.6;
            } else {
                $type = 'vendor';
                $slug = $this->guess_vendor_slug($file);
                $confidence = 0.4;
            }

            $matched = [
                'type'       => $type,
                'slug'       => $slug,
                'file'       => $file,
                'frame'      => $i,
                'confidence' => $confidence,
            ];

            // Prefer earlier frames that live inside plugins/mu-plugins/themes
            if ($best['confidence'] < $matched['confidence']) {
                $best = $matched;
                $frame_index = $i;
            }
        }

        if ($frame_index >= 0 && empty($best['file'])) {
            $best['file']  = (string) $trace[$frame_index]['file'];
            $best['frame'] = $frame_index;
        }

        return $best;
    }

    /**
     * Capture user context with minimal sensitive data and sanitization.
     *
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function capture_user_context(array $headers = []): array
    {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $is_logged_in = function_exists('is_user_logged_in') ? (bool) is_user_logged_in() : false;

        $roles = [];
        $caps  = [];
        if ($is_logged_in && function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if ($user && isset($user->roles)) {
                foreach ((array) $user->roles as $role) {
                    $roles[] = sanitize_text_field((string) $role);
                }
            }
            // Minimal capability snapshot; avoid iterating all caps
            $key_caps = [
                'manage_woocommerce',
                'view_woocommerce_reports',
                'edit_shop_orders',
                'manage_options',
            ];
            foreach ($key_caps as $cap) {
                $caps[$cap] = function_exists('current_user_can') ? (bool) current_user_can($cap) : false;
            }
        }

        // IP address detection with sanitization
        $ip = $this->detect_ip();

        $user_agent = isset($headers['user-agent']) ? sanitize_text_field($headers['user-agent']) : (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : null);
        $referer    = isset($headers['referer']) ? esc_url_raw($headers['referer']) : (isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash((string) $_SERVER['HTTP_REFERER'])) : null);

        // Session indicators
        $session = [
            'php_session' => isset($_COOKIE[session_name()]) ? true : false,
            'wc_session'  => $this->has_wc_session_cookie(),
        ];
        if (function_exists('WC') && WC()) {
            $wc = WC();
            if (isset($wc->session) && method_exists($wc->session, 'get_customer_id')) {
                $sid = (string) $wc->session->get_customer_id();
                $session['wc_customer_id'] = $sid !== '' ? sanitize_text_field($sid) : null;
            }
        }

        return [
            'user_id'      => $user_id ?: null,
            'roles'        => $roles,
            'caps'         => $caps,
            'is_logged_in' => $is_logged_in,
            'ip'           => $ip,
            'user_agent'   => $user_agent,
            'referer'      => $referer,
            'session'      => $session,
        ];
    }

    /**
     * Detect external services (e.g., Stripe, PayPal) based on headers and UA.
     *
     * @param array<string,string> $headers
     * @return array<string,mixed>|null ['name'=>..., 'indicators'=>[], 'confidence'=>float]
     */
    public function detect_external_service(array $headers = []): ?array
    {
        $ua = isset($headers['user-agent']) ? strtolower($headers['user-agent']) : '';

        // Stripe
        if (isset($headers['stripe-signature']) || strpos($ua, 'stripe') !== false) {
            return [
                'name'       => 'stripe',
                'indicators' => [
                    'stripe-signature' => isset($headers['stripe-signature']),
                    'ua_contains'      => strpos($ua, 'stripe') !== false,
                ],
                'confidence' => isset($headers['stripe-signature']) ? 0.99 : 0.8,
            ];
        }

        // PayPal
        if (isset($headers['paypal-transmission-sig']) || isset($headers['paypal-auth-algo']) || strpos($ua, 'paypal') !== false) {
            return [
                'name'       => 'paypal',
                'indicators' => [
                    'paypal-transmission-sig' => isset($headers['paypal-transmission-sig']),
                    'paypal-auth-algo'        => isset($headers['paypal-auth-algo']),
                    'ua_contains'             => strpos($ua, 'paypal') !== false,
                ],
                'confidence' => (isset($headers['paypal-transmission-sig']) || isset($headers['paypal-auth-algo'])) ? 0.98 : 0.75,
            ];
        }

        // Mollie
        if (isset($headers['x-mollie-signature']) || strpos($ua, 'mollie') !== false) {
            return [
                'name'       => 'mollie',
                'indicators' => [
                    'x-mollie-signature' => isset($headers['x-mollie-signature']),
                    'ua_contains'        => strpos($ua, 'mollie') !== false,
                ],
                'confidence' => isset($headers['x-mollie-signature']) ? 0.96 : 0.7,
            ];
        }

        // Square
        if (isset($headers['x-square-signature']) || strpos($ua, 'square') !== false) {
            return [
                'name'       => 'square',
                'indicators' => [
                    'x-square-signature' => isset($headers['x-square-signature']),
                    'ua_contains'        => strpos($ua, 'square') !== false,
                ],
                'confidence' => isset($headers['x-square-signature']) ? 0.95 : 0.65,
            ];
        }

        // Generic webhook signals
        if ($this->looks_like_webhook($headers)) {
            return [
                'name'       => 'webhook',
                'indicators' => [
                    'content-type' => $headers['content-type'] ?? null,
                    'event'        => $headers['x-event'] ?? ($headers['x-webhook-event'] ?? null),
                ],
                'confidence' => 0.6,
            ];
        }

        return null;
    }

    // ------------------------
    // Internal helper methods
    // ------------------------

    /**
     * Determine if headers/route looks like a webhook.
     *
     * @param array<string,string> $headers
     * @return bool
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
     * @param array<string,string> $headers
     * @return bool
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
        $headers = [];
        // Prefer getallheaders if available
        if (function_exists('getallheaders')) {
            $raw = @getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    $lk = strtolower(str_replace('_', '-', (string) $key));
                    $headers[$lk] = is_string($value) ? sanitize_text_field($value) : '';
                }
            }
        }
        // Fallback to $_SERVER
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['content-length'] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
            }
        }
        return $headers;
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
        $candidates = [];
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $candidates[] = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_CLIENT_IP']));
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // First IP from list
            $parts = explode(',', sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            if (!empty($parts)) {
                $candidates[] = trim((string) $parts[0]);
            }
        }
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }
        foreach ($candidates as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return sanitize_text_field($ip);
            }
        }
        return null;
    }

    /**
     * Check presence of WooCommerce session cookie without exposing values.
     *
     * @return bool
     */
    private function has_wc_session_cookie(): bool
    {
        foreach ($_COOKIE as $name => $v) {
            if (is_string($name) && strpos($name, 'wp_woocommerce_session_') === 0) {
                return true;
            }
        }
        return false;
    }
}
