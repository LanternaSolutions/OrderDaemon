<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Logging;

/**
 * Class ProcessLogger
 *
 * Unified process-based logger to create and manage narrative logs across all types.
 *
 * This implementation collects components in-memory during the request and performs
 * a single durable write on finish(). This keeps the change minimal and avoids
 * introducing mid-process DB appends. It still produces a single narrative entry
 * with an ordered payload_components array for the details pane.
 *
 * @package OrderDaemon\CompletionManager\Core
 */
final class ProcessLogger
{
    /**
     * Recursion guard for logging operations to prevent infinite loops
     * when the logger itself encounters an error and could re-enter.
     *
     * Acts as a simple circuit breaker: if true, public methods return early.
     *
     * @var bool
     */
    private static bool $is_logging = false;

    /**
     * Context flag to coordinate with UniversalEventProcessor
     * When true, prevents ProcessLogger from creating timeline events
     * since UniversalEventProcessor will create enhanced events instead.
     *
     * @var bool
     */
    private static bool $universal_event_context = false;

    /** @var ComponentSanitizer */
    private ComponentSanitizer $sanitizer;

    /** @var string */
    private string $type;

    /** @var array */
    private array $context;

    /** @var string */
    private string $correlation_id;

    /** @var int */
    private int $started_at;

    /** @var array<int, array> */
    private array $components = [];

    /** @var array<string, array> */
    private array $deferred_context = [];

    /**
     * @param ComponentSanitizer|null $sanitizer Optional custom sanitizer.
     */
    public function __construct(?ComponentSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?: new ComponentSanitizer();
    }

    /**
     * Start a process log.
     *
     * @param string $type Canonical process type (e.g., 'rule_execution', 'manual_status_change').
     * @param array  $context { order_id?:int, actor_user_id?:int, actor_name?:string, actor_role?:string, summary?:string }
     * @return array{ correlation_id:string }
     */
    public function start(string $type, array $context = []): array
    {
        // 1) Recursion Guard
        if (self::$is_logging) {
            return ['correlation_id' => $this->correlation_id ?? ''];
        }

        try {
            // 2) Set the Lock
            self::$is_logging = true;

            $this->type = sanitize_key($type);
            $this->context = [
                'order_id' => isset($context['order_id']) ? absint($context['order_id']) : null,
                'actor'    => $this->resolve_actor($context),
                'summary'  => sanitize_text_field($context['summary'] ?? __('Process started', 'order-daemon')),
                'source'   => isset($context['source']) ? sanitize_key((string) $context['source']) : 'system',
            ];

            // Determine a stable correlation/process ID for lifecycle types to enable UI consolidation.
            $order_id_for_pid = isset($this->context['order_id']) ? (int) $this->context['order_id'] : 0;
            $shared_process_id = null;
            try {
                if ($order_id_for_pid > 0) {
                    // Discover lifecycle family and check if current type is part of it
                    if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessLifecycleDiscovery')) {
                        require_once dirname(__DIR__) . '/../ProcessLifecycleDiscovery.php';
                    }
                    $discovery = \OrderDaemon\CompletionManager\Core\ProcessLifecycleDiscovery::instance();
                    $families = $discovery->get_process_families();
                    $lifecycle_types = isset($families['order_lifecycle']['process_types']) && is_array($families['order_lifecycle']['process_types'])
                        ? $families['order_lifecycle']['process_types']
                        : [];

                    // Use a union of discovered and canonical lifecycle slugs to avoid dependency on DB discovery timing
                    $canonical_types = [
                        'checkout_processing',
                        'block_checkout_processed',
                        'status_change_processing',
                        'manual_status_change',
                        'rule_execution',
                        'order_completion',
                        'process_started',
                    ];
                    $lifecycle_union = array_values(array_unique(array_merge($lifecycle_types, $canonical_types)));

                    if (in_array($this->type, $lifecycle_union, true)) {
                        if (!class_exists('OrderDaemon\\CompletionManager\\Core\\ProcessIdManager')) {
                            require_once dirname(__DIR__) . '/../ProcessIdManager.php';
                        }
                        $shared_process_id = \OrderDaemon\CompletionManager\Core\ProcessIdManager::instance()->get_or_create_process_id($order_id_for_pid);
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to ephemeral ID if discovery fails
            }

            $this->correlation_id = $shared_process_id ?: $this->build_correlation_id($this->type, $order_id_for_pid ?: null);
            $this->started_at = time();

            // Add process started as first component with debug flag (bypass recursion guard safely)
            $this->components[] = [
                'k'     => odcm_component_key('process_started'),
                'event_type'  => 'process_started',
                'ts'    => time(),
                'label' => 'Process started',
                'level' => 'debug',
                'data'  => $this->sanitizer->sanitize('process_started', [
                    'message'      => sprintf('Process %s started', $this->type),
                    'process_type' => $this->type,
                    'context'      => $context,
                ]),
            ];

            return ['correlation_id' => $this->correlation_id];
        } catch (\Throwable $e) {
            // 3) Last Resort Error Handler (do not call internal logger again)
            $this->critical_log('CRITICAL LOGGER FAILURE in ' . __METHOD__ . ': ' . $e->getMessage());
            return ['correlation_id' => $this->correlation_id ?? ''];
        } finally {
            // 4) Release the Lock
            self::$is_logging = false;
        }
    }

    /**
     * Append a sanitized component and return its key.
     *
     * @param string      $event_type  Component event type.
     * @param string      $label Human-readable label.
     * @param array       $data  Component data.
     * @param string      $level info|warning|error|debug
     * @param string|null $key   Optional stable key override.
     * @return string The component key for deferred context attachment.
     */
    public function add_component(string $event_type, string $label, array $data, string $level = 'info', ?string $key = null): string
    {
        // 1) Recursion Guard
        if (self::$is_logging) {
            return $key ?: '';
        }

        try {
            // 2) Set the Lock
            self::$is_logging = true;

            $component_key = $key ?: odcm_component_key($event_type);
            $this->components[] = [
                'k'     => $component_key,         // Optimized field name
                'event_type'  => $this->sanitize_event_type_safely($event_type),
                'ts'    => time(),                 // Use Unix timestamp for optimization
                'label' => sanitize_text_field($label),
                'level' => sanitize_key($level),
                'data'  => $this->sanitizer->sanitize($event_type, $data),
            ];
            return $component_key;
        } catch (\Throwable $e) {
            // 3) Last Resort Error Handler
            $this->critical_log('CRITICAL LOGGER FAILURE in ' . __METHOD__ . ': ' . $e->getMessage());
            return $key ?: '';
        } finally {
            // 4) Release the Lock
            self::$is_logging = false;
        }
    }

    /**
     * Finish the process and persist a single canonical narrative entry.
     *
     * @param string $final_status success|failed|partial|canceled|warning|info (mapped to core statuses)
     * @param string $summary      Human-readable summary.
     * @return int|false Log ID or false on failure.
     */
    public function finish(string $final_status, string $summary)
    {
        // 1) Recursion Guard
        if (self::$is_logging) {
            // Critical: Log recursion guard triggers as they indicate serious bugs
            $this->critical_log('Recursion guard triggered in finish() - possible infinite loop');
            return false;
        }

        try {
            // 2) Set the Lock
            self::$is_logging = true;

            $final_status = in_array($final_status, ['success','failed','partial','canceled','warning','info'], true)
                ? $final_status
                : 'success';

            // Capture attribution context and measure performance (graceful fallback)
            $attribution = null;
            $attr_ms = 0.0;
            try {
                $t_attr = microtime(true);
                if (class_exists('OrderDaemon\\CompletionManager\\Core\\AttributionTracker')) {
                    $attribution = \OrderDaemon\CompletionManager\Core\AttributionTracker::instance()->capture_context();
                }
                $attr_ms = (microtime(true) - $t_attr) * 1000.0;
            } catch (\Throwable $e) {
                $attribution = null;
            }

            // Infer source intelligently if not explicitly provided or set to system
            $existing_source = isset($this->context['source']) ? (string) $this->context['source'] : '';
            $inferred_source = $this->map_source_from_attribution(is_array($attribution) ? $attribution : [], $existing_source);
            $final_source = sanitize_key($existing_source !== '' && $existing_source !== 'system' ? $existing_source : $inferred_source);
            if ($final_source === '') {
                $final_source = 'system';
            }

            $envelope = [
                'type'        => $this->type,
                'cid'         => $this->correlation_id,  // Optimized field name
                'oid'         => $this->context['order_id'], // Optimized field name
                'actor'       => $this->context['actor'],
                'ts'          => $this->started_at,      // Optimized field name (Unix timestamp)
                // 'finished_at' removed for optimization
                'status'      => $final_status,
                'source'      => $final_source,
                'summary'     => sanitize_text_field($summary),
                'attribution_context' => $attribution,
                'metrics'     => [ 'attribution_capture_ms' => $attr_ms ],
                'components'  => $this->components,      // Optimized field name
            ];

            // NEW: Merge deferred context into components before writing
            foreach ($this->components as &$component) {
                if (isset($component['k']) && isset($this->deferred_context[$component['k']])) { // Use optimized field name
                    $component['data'] = array_merge(
                        is_array($component['data']) ? $component['data'] : [],
                        $this->deferred_context[$component['k']]
                    );
                }
            }
            unset($component); // break reference
            // Update envelope with merged components
            $envelope['components'] = $this->components; // Use optimized field name
            // Clear deferred context to free memory
            $this->deferred_context = [];

            $simple_data = [
                'process_type' => $this->type,
                'correlation_id' => $this->correlation_id,
                'status' => $final_status,
                'source' => $final_source,
                'component_count' => count($this->components),
                'actor' => $this->context['actor']['name'] ?? 'system',
                'metrics' => $envelope['metrics']
            ];
            
            // Additional validation to prevent Order #0 issues - never log events for invalid order IDs
            if (isset($this->context['order_id']) && (empty($this->context['order_id']) || (int)$this->context['order_id'] <= 0)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->critical_log('CRITICAL: Preventing timeline event creation for invalid order ID: ' . 
                        (isset($this->context['order_id']) ? (int)$this->context['order_id'] : 'null'));
                }
                return false;
            }
            
            // Check if universal event context is active - if so, skip timeline event creation
            // since UniversalEventProcessor will create enhanced events instead
            if (self::$universal_event_context) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $trace_source = '';
                    if ($this->context['order_id']) {
                        $trace_source = " for Order #{$this->context['order_id']}";
                    }
                    $this->critical_log('Skipping timeline event creation due to universal event context' . $trace_source);
                }
                // CRITICAL FIX: ALWAYS Return false during universal_event_context instead of correlation_id
                // This prevents Order #0 issues by avoiding the inclusion of process_id in logging calls
                return false;
            }
            
            $log_result = odcm_log_event(
                sanitize_text_field($summary),
                $simple_data, // Simplified data structure
                $this->context['order_id'],
                $this->map_status($final_status),
                $this->type,
                false, // not a test
                $this->correlation_id // process_id for correlation
            );
            
            // Critical: Log logging failures as they indicate serious system issues
            if (!$log_result) {
                $this->critical_log('odcm_log_event() failed for process: ' . $this->type);
            }
            
            return $log_result;
        } catch (\Throwable $e) {
            // 3) Last Resort Error Handler
            $this->critical_log('CRITICAL LOGGER FAILURE in ' . __METHOD__ . ': ' . $e->getMessage());
            return false;
        } finally {
            // 4) Release the Lock
            self::$is_logging = false;
        }
    }

    /**
     * Build a correlation ID (process_id) for grouping.
     * Uses optimized format: {order_id}:{timestamp}
     *
     * @param string   $type
     * @param int|null $order_id
     * @return string
     */
    private function build_correlation_id(string $type, ?int $order_id): string
    {
        // Enhanced validation for order ID
        if ($order_id === null || $order_id <= 0) {
            // Use an explicit non-numeric prefix to avoid 'na' being interpreted as Order #0
            $primary = 'invalid_order_id';
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->critical_log("WARNING: Building correlation ID with invalid order ID: " . 
                    (is_null($order_id) ? 'null' : $order_id));
            }
        } else {
            $primary = (string)$order_id;
        }
        
        return sprintf('%s:%d', $primary, time()); // Optimized format
    }

    /**
     * Resolve actor from context or fallback to system.
     *
     * @param array $context
     * @return array{id:int|null,role:string|null,name:string|null}
     */
    private function resolve_actor(array $context): array
    {
        $uid = isset($context['actor_user_id']) ? absint($context['actor_user_id']) : 0;
        if ($uid > 0) {
            $u = get_user_by('id', $uid);
            return [
                'id'   => $uid,
                'role' => is_object($u) && is_array($u->roles) ? ($u->roles[0] ?? null) : null,
                'name' => is_object($u) ? $u->display_name : null,
            ];
        }
        return ['id' => null, 'role' => 'system', 'name' => 'system'];
    }

    /**
     * Add deferred context to an existing component.
     *
     * This allows embedding attribution, performance, and other context data
     * into components after they've been added, solving the timing constraint
     * where context data is only available after the component was logged.
     *
     * @param string $component_key The key returned from add_component()
     * @param array $context_data Context data to merge into the component
     * @return void
     */
    public function add_deferred_context(string $component_key, array $context_data): void
    {
        if (!isset($this->deferred_context[$component_key])) {
            $this->deferred_context[$component_key] = [];
        }
        $this->deferred_context[$component_key] = array_merge(
            $this->deferred_context[$component_key],
            $context_data
        );
    }

    /**
     * Determine if an event type should be preserved unchanged
     *
     * @param string $event_type The event type to check
     * @return bool True if this event type should be preserved
     */
    private function should_preserve_event_type(string $event_type): bool
    {
        // Check for payment prefix
        if (strpos($event_type, 'payment') === 0) {
            return true;
        }

        // Check for specific gateway sources (even without payment prefix)
        $gateway_patterns = ['stripe', 'paypal', 'square', 'net.authorize'];
        foreach ($gateway_patterns as $pattern) {
            if (strpos($event_type, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safely sanitize event types while preserving payment gateway event formats
     *
     * @param string $event_type The original event type
     * @return string The sanitized event type (unchanged for payment events)
     */
    private function sanitize_event_type_safely(string $event_type): string
    {
        // Check if this is a payment gateway event that should be preserved
        if ($this->should_preserve_event_type($event_type)) {
            // For payment events: validate but don't modify the string
            // Use sanitize_text_field to ensure it's safe, but only if it doesn't change the value
            $validated = sanitize_text_field($event_type);
            return $validated === $event_type ? $event_type : $event_type;
        }

        // For internal events: use existing sanitization
        return sanitize_key($event_type);
    }

    /**
     * Map process final statuses to core logger statuses for UI styling.
     *
     * @param string $final_status
     * @return string
     */
    private function map_status(string $final_status): string
    {
        // Reuse the same status set as the UI understands
        switch ($final_status) {
            case 'failed':
                return 'error';
            case 'partial':
                return 'warning';
            case 'canceled':
                return 'warning';
            case 'warning':
                return 'warning';
            case 'info':
                return 'info';
            case 'success':
            default:
                return 'success';
        }
    }

    /**
     * Map attribution context to the main-table source label.
     *
     * Priority order:
     * - manual (logged-in admin/ajax)
     * - webhook
     * - api (REST)
     * - scheduled (cron/action_scheduler/cli/wp_cli)
     * - system (fallback)
     *
     * @param array $attr Attribution context array.
     * @param string $existing Optional existing source hint.
     * @return string One of manual|webhook|api|scheduled|system
     */
    private function map_source_from_attribution(array $attr, string $existing = ''): string
    {
        // Respect explicit non-system value from caller
        if ($existing !== '' && $existing !== 'system') {
            return sanitize_key($existing);
        }

        $request_type = isset($attr['request_type']) ? sanitize_key((string) $attr['request_type']) : '';
        $is_logged_in = isset($attr['user_context']['is_logged_in']) ? (bool) $attr['user_context']['is_logged_in'] : false;
        $external_name = isset($attr['external_service']['name']) ? sanitize_key((string) $attr['external_service']['name']) : '';

        if ($is_logged_in && in_array($request_type, ['admin','ajax'], true)) {
            return 'manual';
        }
        if ($request_type === 'webhook' || $external_name !== '') {
            return 'webhook';
        }
        if ($request_type === 'rest') {
            return 'api';
        }
        if (in_array($request_type, ['action_scheduler','cron','cli','wp_cli'], true)) {
            return 'scheduled';
        }
        return 'system';
    }

    /**
     * Set universal event context flag to coordinate with UniversalEventProcessor
     * When enabled, ProcessLogger will skip creating timeline events since
     * UniversalEventProcessor will create enhanced events instead.
     *
     * @param bool $enabled Whether universal event context is active
     * @return void
     */
    public static function set_universal_event_context(bool $enabled): void
    {
        self::$universal_event_context = $enabled;
    }

    /**
     * Check if universal event context is active
     *
     * @return bool True if UniversalEventProcessor will handle timeline events
     */
    public static function is_universal_event_context(): bool
    {
        return self::$universal_event_context;
    }
    
    /**
     * Safe critical logging that respects WordPress debugging settings
     * 
     * This is used as a last resort error handler when the standard logging
     * mechanisms fail. It's designed to avoid recursive logging loops while
     * still providing important error information.
     * 
     * @param string $message Message to log
     * @return void
     */
    private function critical_log(string $message): void
    {
        // Only log when debugging is enabled, with safety checks
        if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('ODCM_DEBUG') && ODCM_DEBUG)) {
            // Use WordPress logging function if available
            if (function_exists('odcm_log_message')) {
                // Keep this separate from the ProcessLogger main functions to avoid recursion
                odcm_log_message('ProcessLogger: ' . $message, 'error');
            } else {
                // Use WordPress action hook if available for centralized error handling
                if (function_exists('do_action')) {
                    do_action('odcm_log_error', 'ProcessLogger: ' . $message);
                }
                
                        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
                        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
                            // Write to WordPress debug.log file using WordPress constants
                            $debug_file = WP_CONTENT_DIR . '/debug.log';
                            @file_put_contents(
                                $debug_file,
                                '[' . gmdate('Y-m-d H:i:s') . '] ODCM ProcessLogger: ' . $message . PHP_EOL,
                                FILE_APPEND
                            );
                        }
                
                // Use WordPress debug log function if available
                if (function_exists('wp_debug_log')) {
                    wp_debug_log('ODCM ProcessLogger: ' . $message);
                }
            }
        }
    }
}
