<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use WC_Order;
use WC_Order_Refund;
use OrderDaemon\CompletionManager\Core\Events\UniversalEvent;
use OrderDaemon\CompletionManager\Core\Events\UniversalEventProcessor;

/**
 * Refund & Deletion Diagnostics (observation-only).
 *
 * Registers WooCommerce hooks to observe refund lifecycle and order deletion/restore
 * events. Handlers capture essential identifiers and emit audit log entries via
 * odcm_log_event() with narrative payloads. Heavy/expensive logic is explicitly avoided.
 *
 * Security & Standards:
 * - Namespaced under OrderDaemon\\CompletionManager.
 * - Uses odcm_ prefix for custom/global elements as required elsewhere in plugin.
 * - Full DocBlocks for all methods. Strict types enabled.
 * - Defensively checks for function availability and object validity.
 */
final class RefundDeletionDiagnostics
{
    // Performance thresholds and counters
    private const PERF_WARN_SECONDS = 2.0; // seconds
    private const PERF_DB_SLOW_MS = 500.0; // milliseconds
    private const PERF_MEM_ERROR_BYTES = 10485760; // 10MB

    private static int $refundConcurrency = 0;
    private static int $deletionConcurrency = 0;
    /**
     * Capture detailed refund context for analytics and compliance.
     * Includes lightweight performance metrics for context build.
     *
     * @param int $refund_id Refund post ID.
     * @param WC_Order|null $order Optional order object to avoid reloading.
     * @return array<string,mixed> Sanitized context array.
     */
    public function capture_refund_context(int $refund_id, ?WC_Order $order = null): array
    {
        $perf_start = microtime(true);
        $mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
        $timings = [
            'refund_load_ms' => 0.0,
            'order_load_ms'  => 0.0,
            'db_meta_ms'     => 0.0,
        ];

        $context = [
            'refund'    => [],
            'impact'    => [],
            'items'     => [],
            'actor'     => [],
            'technical' => [],
        ];

        if (!function_exists('wc_get_order') || $refund_id <= 0) {
            return $context;
        }

        $t0 = microtime(true);
        $refund = wc_get_order($refund_id);
        $timings['refund_load_ms'] = (microtime(true) - $t0) * 1000.0;
        if (!($refund instanceof WC_Order_Refund)) {
            return $context;
        }

        $order_id = (int) $refund->get_parent_id();
        if (!$order instanceof WC_Order) {
            $t1 = microtime(true);
            $order = $order_id > 0 ? wc_get_order($order_id) : null;
            $timings['order_load_ms'] = (microtime(true) - $t1) * 1000.0;
        }

        // Refund details
        $m0 = microtime(true);
        $refunded_by_id = (int) get_post_meta($refund_id, '_refund_user_id', true);
        $timings['db_meta_ms'] += (microtime(true) - $m0) * 1000.0;
        $reason_raw     = $refund->get_reason();
        $context['refund'] = [
            'id'          => $refund_id,
            'order_id'    => $order_id,
            'amount'      => (float) $refund->get_amount(),
            'reason'      => is_string($reason_raw) ? sanitize_text_field($reason_raw) : null,
            'refunded_by' => $refunded_by_id > 0 ? $refunded_by_id : null,
            'date'        => $refund->get_date_created() ? odcm_iso8601_from_timestamp($refund->get_date_created()->getTimestamp()) : odcm_iso8601_now(),
        ];

        // Order impact analysis
        if ($order instanceof WC_Order) {
            $total       = (float) $order->get_total();
            $ref_total   = method_exists($order, 'get_total_refunded') ? (float) $order->get_total_refunded() : (float) (function_exists('wc_get_order_total_refunded') ? wc_get_order_total_refunded($order_id) : 0.0);
            $remaining   = max(0.0, $total - $ref_total);
            $percentage  = $total > 0 ? round(($ref_total / $total) * 100, 2) : null;
            $is_full     = $total > 0 ? ($ref_total >= $total) : null;
            $context['impact'] = [
                'original_total'  => $total,
                'refunded_total'  => $ref_total,
                'remaining_total' => $remaining,
                'refund_percentage' => $percentage,
                'is_full_refund'  => $is_full,
            ];
        }

        // Refunded items breakdown (compact)
        $items_breakdown = [];
        foreach ($refund->get_items() as $item) {
            $pid   = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $name  = method_exists($item, 'get_name') ? sanitize_text_field((string) $item->get_name()) : '';
            $qty   = method_exists($item, 'get_quantity') ? (float) $item->get_quantity() : 0;
            $total = method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
            $items_breakdown[] = [
                'product_id' => $pid ?: null,
                'name'       => $name !== '' ? $name : null,
                'qty'        => $qty,
                'line_total' => $total,
            ];
            if (count($items_breakdown) >= 50) { // safety cap
                break;
            }
        }
        $context['items'] = $items_breakdown;

        // Actor context
        $user_id = get_current_user_id();
        $context['actor'] = [
            'user_id'    => $user_id ?: null,
            'user_roles' => is_user_logged_in() ? array_values(array_map('sanitize_text_field', (array) wp_get_current_user()->roles)) : [],
            'ip'         => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : null,
            'referer'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw((string) $_SERVER['HTTP_REFERER']) : null,
        ];

        // Technical context
        $context['technical'] = [
            'wp_version'    => sanitize_text_field((string) get_bloginfo('version')),
            'wc_version'    => sanitize_text_field((string) get_option('woocommerce_version')), 
            'php_version'   => sanitize_text_field((string) PHP_VERSION),
            'memory_usage'  => function_exists('memory_get_usage') ? (int) memory_get_usage() : null,
            'backtrace'     => $this->get_trimmed_backtrace(6),
        ];

        // Performance context
        $perf_end = microtime(true);
        $mem_end  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
        $context['performance'] = [
            'context_build_ms' => ($perf_end - $perf_start) * 1000.0,
            'memory_delta'     => $mem_end - $mem_start,
            'items_count'      => isset($items_breakdown) ? count($items_breakdown) : 0,
            'refund_load_ms'   => $timings['refund_load_ms'],
            'order_load_ms'    => $timings['order_load_ms'],
            'db_meta_ms'       => $timings['db_meta_ms'],
        ];

        return $context;
    }

    /**
     * Capture an order snapshot suitable for pre-deletion preservation.
     *
     * @param WC_Order $order Order object.
     * @param array<string,mixed> $extra Additional context like who/when/how.
     * @return array<string,mixed> Sanitized snapshot.
     */
    public function capture_order_snapshot(WC_Order $order, array $extra = []): array
    {
        $perf_start = microtime(true);
        $mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
        $timings = [
            'notes_query_ms' => 0.0,
            'db_meta_ms'     => 0.0,
        ];
        $order_id = (int) $order->get_id();
        $basics = [
            'id'              => $order_id,
            'number'          => sanitize_text_field((string) $order->get_order_number()),
            'status'          => sanitize_text_field((string) $order->get_status()),
            'currency'        => sanitize_text_field((string) $order->get_currency()),
            'total'           => (float) $order->get_total(),
            'subtotal'        => method_exists($order, 'get_subtotal') ? (float) $order->get_subtotal() : null,
            'discount_total'  => (float) $order->get_discount_total(),
            'shipping_total'  => (float) $order->get_shipping_total(),
            'payment_method'  => sanitize_text_field((string) $order->get_payment_method()),
            'payment_title'   => sanitize_text_field((string) $order->get_payment_method_title()),
            'date_created'    => $order->get_date_created() ? odcm_iso8601_from_timestamp($order->get_date_created()->getTimestamp()) : null,
            'date_modified'   => $order->get_date_modified() ? odcm_iso8601_from_timestamp($order->get_date_modified()->getTimestamp()) : null,
            'date_paid'       => method_exists($order, 'get_date_paid') && $order->get_date_paid() ? odcm_iso8601_from_timestamp($order->get_date_paid()->getTimestamp()) : null,
        ];

        // GDPR-safe customer info (avoid full addresses)
        $email    = (string) $order->get_billing_email();
        $masked   = $this->mask_email($email);
        $customer = [
            'user_id'        => (int) $order->get_user_id() ?: null,
            'billing_email'  => $masked,
            'billing_city'   => sanitize_text_field((string) $order->get_billing_city()),
            'billing_country'=> sanitize_text_field((string) $order->get_billing_country()),
        ];

        // Items snapshot (compact)
        $items = [];
        foreach ($order->get_items() as $item) {
            $pid   = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $name  = method_exists($item, 'get_name') ? sanitize_text_field((string) $item->get_name()) : '';
            $qty   = method_exists($item, 'get_quantity') ? (float) $item->get_quantity() : 0;
            $total = method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
            $items[] = [
                'product_id' => $pid ?: null,
                'name'       => $name !== '' ? $name : null,
                'qty'        => $qty,
                'line_total' => $total,
            ];
            if (count($items) >= 100) { // safety cap
                break;
            }
        }

        // Last 10 order notes (customer-visible + private)
        $notes_snapshot = [];
        if (function_exists('wc_get_order_notes')) {
            $n0 = microtime(true);
            $notes = wc_get_order_notes(['order_id' => $order_id, 'limit' => 10]);
            $timings['notes_query_ms'] = (microtime(true) - $n0) * 1000.0;
            foreach ($notes as $note) {
                $notes_snapshot[] = [
                    'date'    => isset($note->date_created) && $note->date_created ? odcm_iso8601_from_timestamp($note->date_created->getTimestamp()) : null,
                    'content' => isset($note->content) ? wp_kses_post((string) $note->content) : null,
                    'type'    => isset($note->type) ? sanitize_text_field((string) $note->type) : null,
                ];
            }
        }

        // Relevant meta (whitelisted minimal keys only)
        $meta = [];
        $whitelist = ['_payment_method', '_transaction_id', '_order_currency'];
        foreach ($whitelist as $key) {
            $m0 = microtime(true);
            $meta[$key] = sanitize_text_field((string) get_post_meta($order_id, $key, true));
            $timings['db_meta_ms'] += (microtime(true) - $m0) * 1000.0;
        }

        $snapshot = [
            'basics'   => $basics,
            'customer' => $customer,
            'items'    => $items,
            'notes'    => $notes_snapshot,
            'meta'     => $meta,
            'extra'    => $this->sanitize_array($extra),
        ];
        // Approximate snapshot size (pre-perf)
        $size_before_perf = null;
        try {
            $json_tmp = wp_json_encode($snapshot);
            if (is_string($json_tmp)) { $size_before_perf = strlen($json_tmp); }
        } catch (\Throwable $e) { /* ignore */ }
        // Perf block
        $perf_end = microtime(true);
        $mem_end  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
        $snapshot['perf'] = [
            'snapshot_build_ms' => ($perf_end - $perf_start) * 1000.0,
            'memory_delta'      => $mem_end - $mem_start,
            'items_count'       => count($items),
            'notes_count'       => count($notes_snapshot),
            'db_meta_ms'        => $timings['db_meta_ms'],
            'db_notes_ms'       => $timings['notes_query_ms'],
            'snapshot_size_bytes' => $size_before_perf,
        ];
        return $snapshot;
    }

    /**
     * Build a trimmed backtrace for diagnostics.
     *
     * @param int $limit Max frames to include.
     * @return array<int,array<string,string|int|null>>
     */
    private function get_trimmed_backtrace(int $limit = 6): array
    {
        $trace = function_exists('wp_debug_backtrace_summary') ? [] : [];
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, max(3, $limit));
        $out = [];
        foreach ($frames as $f) {
            $out[] = [
                'file' => isset($f['file']) ? sanitize_text_field((string) $f['file']) : null,
                'line' => isset($f['line']) ? (int) $f['line'] : null,
                'func' => isset($f['function']) ? sanitize_text_field((string) $f['function']) : null,
            ];
        }
        return $out;
    }

    /**
     * Sanitize a generic array recursively (shallow for performance with scalars).
     *
     * @param array $arr
     * @return array
     */
    private function sanitize_array(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = is_string($k) ? sanitize_text_field($k) : $k;
            if (is_string($v)) {
                $out[$key] = sanitize_text_field($v);
            } elseif (is_numeric($v) || is_bool($v) || is_null($v)) {
                $out[$key] = $v;
            } elseif (is_array($v)) {
                $out[$key] = $this->sanitize_array($v);
            } else {
                $out[$key] = null;
            }
        }
        return $out;
    }

    /**
     * Mask an email for GDPR safety.
     *
     * @param string $email
     * @return string|null
     */
    private function mask_email(string $email): ?string
    {
        $email = sanitize_email($email);
        if (empty($email) || strpos($email, '@') === false) {
            return null;
        }
        [$user, $domain] = explode('@', $email, 2);
        $user_mask = strlen($user) > 2 ? substr($user, 0, 1) . str_repeat('*', strlen($user) - 2) . substr($user, -1) : str_repeat('*', strlen($user));
        $domain_mask = preg_replace('/^[^\.]+/', '***', $domain);
        return $user_mask . '@' . $domain_mask;
    }

    /**
     * Check if WooCommerce APIs are available in the current runtime.
     *
     * @return bool
     */
    private function is_woocommerce_available(): bool
    {
        return function_exists('wc_get_order');
    }

    /**
     * Lightweight recursion guard using in-memory lock keys.
     *
     * @param string $key
     * @return bool True if lock acquired; false if already held.
     */
    private function acquire_lock(string $key): bool
    {
        static $locks = [];
        if (isset($locks[$key]) && $locks[$key] === true) {
            return false;
        }
        $locks[$key] = true;
        return true;
    }

    /**
     * Release a previously acquired lock.
     *
     * @param string $key
     * @return void
     */
    private function release_lock(string $key): void
    {
        static $locks = [];
        unset($locks[$key]);
    }

    /**
     * Safe error logging that avoids database logging paths and throttles duplicates.
     *
     * @param string          $message
     * @param \Throwable|null $e
     * @return void
     */
    private function safe_error_log(string $message, ?\Throwable $e = null): void
    {
        static $count = 0;
        if ($count >= 10) {
            return; // throttle to avoid log flooding
        }
        $count++;
        $suffix = $e ? (' | ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : '';
        // Use PHP error_log to avoid triggering WP db writes or hooks
        error_log('[ODCM RefundDeletionDiagnostics] ' . $message . $suffix);
    }

    /**
     * Decide performance level based on execution time, memory usage, and DB timings.
     *
     * @param float $execution_ms
     * @param int   $memory_bytes
     * @param array<string,float> $db_timings_ms
     * @return string info|warning|error
     */
    private function decide_perf_level(float $execution_ms, int $memory_bytes, array $db_timings_ms = []): string
    {
        if ($memory_bytes >= self::PERF_MEM_ERROR_BYTES) {
            return 'error';
        }
        if ($execution_ms > (self::PERF_WARN_SECONDS * 1000.0)) {
            return 'warning';
        }
        foreach ($db_timings_ms as $ms) {
            if ($ms > self::PERF_DB_SLOW_MS) {
                return 'warning';
            }
        }
        return 'info';
    }

    /**
     * Create a metrics component array for the timeline.
     *
     * @param string $label
     * @param string $operation
     * @param float  $execution_ms
     * @param int    $memory_bytes
     * @param array<string,mixed> $extra
     * @param string|null $level_override
     * @return array<string,mixed>
     */
    private function create_metrics_component(string $label, string $operation, float $execution_ms, int $memory_bytes, array $extra = [], ?string $level_override = null): array
    {
        $db_timings = [];
        foreach (['db_meta_ms','db_notes_ms','transient_set_ms','transient_get_ms'] as $k) {
            if (isset($extra[$k]) && is_numeric($extra[$k])) {
                $db_timings[$k] = (float) $extra[$k];
            }
        }
        $level = $level_override ?: $this->decide_perf_level($execution_ms, $memory_bytes, $db_timings);
        return [
            'key'   => 'performance-' . uniqid('', true),
            'kind'  => 'metrics',
            'ts'    => odcm_iso8601_now(),
            'label' => $label,
            'level' => $level,
            'data'  => array_merge([
                'execution_time_ms' => round($execution_ms, 2),
                'memory_usage'      => $memory_bytes,
                'operation_type'    => $operation,
            ], $extra),
        ];
    }

    /**
     * Merge overall status with performance-derived severity.
     *
     * @param string $base  info|warning|error
     * @param string $perf  info|warning|error
     * @return string
     */
    private function merge_status(string $base, string $perf): string
    {
        $rank = ['debug' => 0, 'info' => 1, 'success' => 1, 'warning' => 2, 'error' => 3];
        $baseR = $rank[$base] ?? 1;
        $perfR = $rank[$perf] ?? 1;
        return ($perfR > $baseR) ? $perf : $base;
    }

    /**
     * Initialize diagnostics by registering WooCommerce hooks based on settings.
     *
     * @return void
     */
    public function init(): void
    {
        // Respect settings toggles; default to enabled for diagnostics unless explicitly disabled
        if ($this->is_refund_tracking_enabled()) {
            // Refund lifecycle hooks
            add_action('woocommerce_order_refunded', [$this, 'handle_order_refunded'], 10, 2);
            add_action('woocommerce_order_partially_refunded', [$this, 'handle_order_partially_refunded'], 10, 2);
            add_action('woocommerce_order_fully_refunded', [$this, 'handle_order_fully_refunded'], 10, 2);
            add_action('woocommerce_refund_created', [$this, 'handle_refund_created'], 10, 2);
            add_action('woocommerce_refund_deleted', [$this, 'handle_refund_deleted'], 10, 2);
        }

        if ($this->is_deletion_tracking_enabled()) {
            // Order deletion / trash hooks
            add_action('woocommerce_before_delete_order', [$this, 'handle_before_delete_order'], 10, 2);
            add_action('woocommerce_delete_order', [$this, 'handle_delete_order'], 10, 1);
            add_action('woocommerce_before_trash_order', [$this, 'handle_before_trash_order'], 10, 1);
            add_action('woocommerce_trash_order', [$this, 'handle_trash_order'], 10, 1);
            add_action('untrashed_post', [$this, 'handle_untrashed_post'], 10, 1);
        }
    }

    /**
     * Handle generic order refunded event.
     *
     * @param int $order_id Order ID.
     * @param int $refund_id Refund (post) ID.
     * @return void
     */
    public function handle_order_refunded($order_id, $refund_id): void
    {
        try {
            $__odcm_perf_start = microtime(true);
            $__odcm_mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
            $order_id  = (int) $order_id;
            $refund_id = (int) $refund_id;
            $lockKey = 'order_refunded_' . $order_id . '_' . $refund_id;
            $lockStart = microtime(true);
            if (!$this->acquire_lock($lockKey)) {
                return; // prevent recursion
            }
            $lock_acquire_ms = (microtime(true) - $lockStart) * 1000.0;
            self::$refundConcurrency++;
            if (!$this->is_woocommerce_available() || $order_id <= 0 || $refund_id <= 0) {
                return;
            }
            $order     = wc_get_order($order_id);
            $refund    = wc_get_order($refund_id);
            $context   = $this->capture_refund_context($refund_id, $order instanceof WC_Order ? $order : null);

            // Generate UniversalEvent for refund
            try {
                if ($order instanceof WC_Order && $refund instanceof WC_Order_Refund) {
                    $universal_event = $this->synthesize_refund_event($order, $refund, 'order_refunded');
                    $this->process_universal_event_from_hook($universal_event);
                    odcm_log_message("Order #{$order_id} refunded (Refund #{$refund_id}), processed as universal event", 'info');
                }
            } catch (\Throwable $e) {
                odcm_log_message('Refund universal event processing failed for order #' . $order_id . ': ' . $e->getMessage(), 'error');
            }

            // Build canonical narrative envelope and log directly
            if (function_exists('odcm_log_registered_event')) {
                $now = odcm_iso8601_now();
                $status = 'info';
                $event_type = 'refund_created';

                $refund_data = [
                    'refund'    => $context['refund'] ?? [],
                    'items'     => $context['items'] ?? [],
                    'actor'     => $context['actor'] ?? [],
                    'technical' => $context['technical'] ?? [],
                ];
                $components = [
                    [
                        'key'   => 'refund_details-' . uniqid('', true),
                        'kind'  => 'refund_analysis',
                        'ts'    => $now,
                        'label' => 'Refund Details',
                        'level' => $status,
                        'data'  => $refund_data,
                    ],
                ];
                if (!empty($context['impact'])) {
                    $components[] = [
                        'key'   => 'order_impact-' . uniqid('', true),
                        'kind'  => 'woocommerce_analysis',
                        'ts'    => $now,
                        'label' => 'Order Impact',
                        'level' => 'info',
                        'data'  => $context['impact'],
                    ];
                }

                // Performance metrics component
                $__odcm_exec_ms = (microtime(true) - $__odcm_perf_start) * 1000.0;
                $__odcm_mem_used = function_exists('memory_get_usage') ? ((int) memory_get_usage() - $__odcm_mem_start) : 0;
                $perf_extra = [
                    'lock_acquire_ms' => isset($lock_acquire_ms) ? $lock_acquire_ms : null,
                    'context_build_ms' => isset($context['performance']['context_build_ms']) ? (float)$context['performance']['context_build_ms'] : null,
                    'refund_load_ms'   => isset($context['performance']['refund_load_ms']) ? (float)$context['performance']['refund_load_ms'] : null,
                    'order_load_ms'    => isset($context['performance']['order_load_ms']) ? (float)$context['performance']['order_load_ms'] : null,
                    'db_meta_ms'       => isset($context['performance']['db_meta_ms']) ? (float)$context['performance']['db_meta_ms'] : null,
                    'concurrency'      => self::$refundConcurrency,
                ];
                $metrics_component = $this->create_metrics_component('Performance Metrics', 'refund_processing', (float)$__odcm_exec_ms, (int) max(0, $__odcm_mem_used), array_filter($perf_extra, static function($v){ return $v !== null; }));
                $components[] = $metrics_component;
                $status = $this->merge_status($status, (string)$metrics_component['level']);

                $summary = $this->build_summary($event_type, $order_id, $refund_id);
                $envelope = [
                    'type'               => 'refund_event',
                    'correlation_id'     => 'odcm:refund:' . $order_id . ':' . $refund_id . ':' . uniqid('', true),
                    'order_id'           => $order_id,
                    'started_at'         => $now,
                    'finished_at'        => $now,
                    'status'             => $status,
                    'summary'            => $summary,
                    'payload_components' => $components,
                ];

                $amount_val = isset($context['refund']['amount']) ? (float)$context['refund']['amount'] : null;
                $currency = ($order instanceof WC_Order) ? (string)$order->get_currency() : '';
                $formatted_amount = $amount_val !== null ? (function_exists('wc_price') ? wc_price($amount_val, $currency !== '' ? ['currency' => $currency] : []) : number_format_i18n($amount_val, 2)) : '';
                odcm_log_registered_event($event_type, [
                    'order_id'     => $order_id,
                    'details'      => $envelope,
                    'status'       => $status,
                    'summary_args' => [$refund_id, $order_id, $formatted_amount],
                    'source'       => 'refund_diagnostics',
                ]);
            }
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_order_refunded error', $e);
        } finally {
            // Memory cleanup and concurrency decrement
            if (isset($context)) { unset($context); }
            if (isset($order)) { unset($order); }
            if (function_exists('memory_get_usage')) {
                $peak_delta = (int) memory_get_usage() - ($__odcm_mem_start ?? 0);
                if ($peak_delta > self::PERF_MEM_ERROR_BYTES && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            if (self::$refundConcurrency > 0) { self::$refundConcurrency--; }
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle order partially refunded event.
     *
     * @param int $order_id Order ID.
     * @param int $refund_id Refund ID.
     * @return void
     */
    public function handle_order_partially_refunded($order_id, $refund_id): void
    {
        try {
            $__odcm_perf_start = microtime(true);
            $__odcm_mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
            $order_id  = (int) $order_id;
            $refund_id = (int) $refund_id;
            $lockKey = 'order_partially_refunded_' . $order_id . '_' . $refund_id;
            $lockStart = microtime(true);
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            $lock_acquire_ms = (microtime(true) - $lockStart) * 1000.0;
            self::$refundConcurrency++;
            if ($order_id <= 0 || $refund_id <= 0) {
                return;
            }
            $order   = $this->is_woocommerce_available() ? wc_get_order($order_id) : null;
            $context = $this->is_woocommerce_available() ? $this->capture_refund_context($refund_id, $order instanceof WC_Order ? $order : null) : [];
            if (function_exists('odcm_log_registered_event')) {
                $now = odcm_iso8601_now();
                $event_type = 'order_partially_refunded';
                $status = 'warning';
                $refund_data = [
                    'refund'    => $context['refund'] ?? [],
                    'items'     => $context['items'] ?? [],
                    'actor'     => $context['actor'] ?? [],
                    'technical' => $context['technical'] ?? [],
                ];
                $components = [
                    [
                        'key'   => 'refund_details-' . uniqid('', true),
                        'kind'  => 'refund_analysis',
                        'ts'    => $now,
                        'label' => 'Refund Details',
                        'level' => 'warning',
                        'data'  => $refund_data,
                    ],
                ];
                if (!empty($context['impact'])) {
                    $components[] = [
                        'key'   => 'order_impact-' . uniqid('', true),
                        'kind'  => 'woocommerce_analysis',
                        'ts'    => $now,
                        'label' => 'Order Impact',
                        'level' => 'info',
                        'data'  => $context['impact'],
                    ];
                }
                // Performance metrics component
                $__odcm_exec_ms = (microtime(true) - $__odcm_perf_start) * 1000.0;
                $__odcm_mem_used = function_exists('memory_get_usage') ? ((int) memory_get_usage() - $__odcm_mem_start) : 0;
                $perf_extra = [
                    'lock_acquire_ms' => isset($lock_acquire_ms) ? $lock_acquire_ms : null,
                    'context_build_ms' => isset($context['performance']['context_build_ms']) ? (float)$context['performance']['context_build_ms'] : null,
                    'refund_load_ms'   => isset($context['performance']['refund_load_ms']) ? (float)$context['performance']['refund_load_ms'] : null,
                    'order_load_ms'    => isset($context['performance']['order_load_ms']) ? (float)$context['performance']['order_load_ms'] : null,
                    'db_meta_ms'       => isset($context['performance']['db_meta_ms']) ? (float)$context['performance']['db_meta_ms'] : null,
                    'concurrency'      => self::$refundConcurrency,
                ];
                $metrics_component = $this->create_metrics_component('Performance Metrics', 'refund_processing', (float)$__odcm_exec_ms, (int) max(0, $__odcm_mem_used), array_filter($perf_extra, static function($v){ return $v !== null; }));
                $components[] = $metrics_component;
                $status = $this->merge_status($status, (string)$metrics_component['level']);
                $summary = $this->build_summary($event_type, $order_id, $refund_id);
                $envelope = [
                    'type'               => 'refund_event',
                    'correlation_id'     => 'odcm:refund:' . $order_id . ':' . $refund_id . ':' . uniqid('', true),
                    'order_id'           => $order_id,
                    'started_at'         => $now,
                    'finished_at'        => $now,
                    'status'             => $status,
                    'summary'            => $summary,
                    'payload_components' => $components,
                ];
                $amount_val = isset($context['refund']['amount']) ? (float)$context['refund']['amount'] : null;
                $currency = ($order instanceof WC_Order) ? (string)$order->get_currency() : '';
                $formatted_amount = $amount_val !== null ? (function_exists('wc_price') ? wc_price($amount_val, $currency !== '' ? ['currency' => $currency] : []) : number_format_i18n($amount_val, 2)) : '';
                $percent = isset($context['impact']['refund_percentage']) && is_numeric($context['impact']['refund_percentage']) ? (int) round((float)$context['impact']['refund_percentage']) : 0;
                odcm_log_registered_event($event_type, [
                    'order_id'     => $order_id,
                    'details'      => $envelope,
                    'status'       => $status,
                    'summary_args' => [$order_id, $formatted_amount, $percent],
                    'source'       => 'refund_diagnostics',
                ]);
            }
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_order_partially_refunded error', $e);
        } finally {
            // Memory cleanup and concurrency decrement
            if (isset($context)) { unset($context); }
            if (isset($order)) { unset($order); }
            if (function_exists('memory_get_usage')) {
                $peak_delta = (int) memory_get_usage() - ($__odcm_mem_start ?? 0);
                if ($peak_delta > self::PERF_MEM_ERROR_BYTES && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            if (self::$refundConcurrency > 0) { self::$refundConcurrency--; }
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle order fully refunded event.
     *
     * @param int $order_id Order ID.
     * @param int $refund_id Refund ID.
     * @return void
     */
    public function handle_order_fully_refunded($order_id, $refund_id): void
    {
        try {
            $__odcm_perf_start = microtime(true);
            $__odcm_mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
            $order_id  = (int) $order_id;
            $refund_id = (int) $refund_id;
            $lockKey = 'order_fully_refunded_' . $order_id . '_' . $refund_id;
            $lockStart = microtime(true);
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            $lock_acquire_ms = (microtime(true) - $lockStart) * 1000.0;
            self::$refundConcurrency++;
            if ($order_id <= 0 || $refund_id <= 0) {
                return;
            }
            $order   = $this->is_woocommerce_available() ? wc_get_order($order_id) : null;
            $context = $this->is_woocommerce_available() ? $this->capture_refund_context($refund_id, $order instanceof WC_Order ? $order : null) : [];
            if (function_exists('odcm_log_registered_event')) {
                $now = odcm_iso8601_now();
                $event_type = 'order_fully_refunded';
                $status = 'warning';
                $refund_data = [
                    'refund'    => $context['refund'] ?? [],
                    'items'     => $context['items'] ?? [],
                    'actor'     => $context['actor'] ?? [],
                    'technical' => $context['technical'] ?? [],
                ];
                $components = [
                    [
                        'key'   => 'refund_details-' . uniqid('', true),
                        'kind'  => 'refund_analysis',
                        'ts'    => $now,
                        'label' => 'Refund Details',
                        'level' => 'warning',
                        'data'  => $refund_data,
                    ],
                ];
                if (!empty($context['impact'])) {
                    $components[] = [
                        'key'   => 'order_impact-' . uniqid('', true),
                        'kind'  => 'woocommerce_analysis',
                        'ts'    => $now,
                        'label' => 'Order Impact',
                        'level' => 'info',
                        'data'  => $context['impact'],
                    ];
                }
                // Performance metrics component
                $__odcm_exec_ms = (microtime(true) - $__odcm_perf_start) * 1000.0;
                $__odcm_mem_used = function_exists('memory_get_usage') ? ((int) memory_get_usage() - $__odcm_mem_start) : 0;
                $perf_extra = [
                    'lock_acquire_ms' => isset($lock_acquire_ms) ? $lock_acquire_ms : null,
                    'context_build_ms' => isset($context['performance']['context_build_ms']) ? (float)$context['performance']['context_build_ms'] : null,
                    'refund_load_ms'   => isset($context['performance']['refund_load_ms']) ? (float)$context['performance']['refund_load_ms'] : null,
                    'order_load_ms'    => isset($context['performance']['order_load_ms']) ? (float)$context['performance']['order_load_ms'] : null,
                    'db_meta_ms'       => isset($context['performance']['db_meta_ms']) ? (float)$context['performance']['db_meta_ms'] : null,
                    'concurrency'      => self::$refundConcurrency,
                ];
                $metrics_component = $this->create_metrics_component('Performance Metrics', 'refund_processing', (float)$__odcm_exec_ms, (int) max(0, $__odcm_mem_used), array_filter($perf_extra, static function($v){ return $v !== null; }));
                $components[] = $metrics_component;
                $status = $this->merge_status($status, (string)$metrics_component['level']);
                $summary = $this->build_summary($event_type, $order_id, $refund_id);
                $envelope = [
                    'type'               => 'refund_event',
                    'correlation_id'     => 'odcm:refund:' . $order_id . ':' . $refund_id . ':' . uniqid('', true),
                    'order_id'           => $order_id,
                    'started_at'         => $now,
                    'finished_at'        => $now,
                    'status'             => $status,
                    'summary'            => $summary,
                    'payload_components' => $components,
                ];
                $amount_val = isset($context['refund']['amount']) ? (float)$context['refund']['amount'] : null;
                $currency = ($order instanceof WC_Order) ? (string)$order->get_currency() : '';
                $formatted_amount = $amount_val !== null ? (function_exists('wc_price') ? wc_price($amount_val, $currency !== '' ? ['currency' => $currency] : []) : number_format_i18n($amount_val, 2)) : '';
                odcm_log_registered_event($event_type, [
                    'order_id'     => $order_id,
                    'details'      => $envelope,
                    'status'       => $status,
                    'summary_args' => [$order_id, $formatted_amount],
                    'source'       => 'refund_diagnostics',
                ]);
            }
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_order_fully_refunded error', $e);
        } finally {
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle refund created event.
     *
     * @param int               $refund_id Refund ID.
     * @param array|WC_Order    $args_or_order Optional args or order object depending on WC version.
     * @return void
     */
    public function handle_refund_created($refund_id, $args_or_order = null): void
    {
        try {
            $__odcm_perf_start = microtime(true);
            $__odcm_mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
            $refund_id = (int) $refund_id;
            $lockKey = 'refund_created_' . $refund_id;
            $lockStart = microtime(true);
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            $lock_acquire_ms = (microtime(true) - $lockStart) * 1000.0;
            self::$refundConcurrency++;
            if ($refund_id <= 0) {
                return;
            }
            $order_id  = $this->resolve_order_id_from_refund($refund_id);
            $order     = ($order_id > 0 && $this->is_woocommerce_available()) ? wc_get_order($order_id) : null;
            $context   = $this->is_woocommerce_available() ? $this->capture_refund_context($refund_id, $order instanceof WC_Order ? $order : null) : [];
            if (function_exists('odcm_log_registered_event')) {
                $now = odcm_iso8601_now();
                $event_type = 'refund_created';
                $status = 'info';
                $refund_data = [
                    'refund'    => $context['refund'] ?? [],
                    'items'     => $context['items'] ?? [],
                    'actor'     => $context['actor'] ?? [],
                    'technical' => $context['technical'] ?? [],
                ];
                $components = [
                    [
                        'key'   => 'refund_details-' . uniqid('', true),
                        'kind'  => 'refund_analysis',
                        'ts'    => $now,
                        'label' => 'Refund Details',
                        'level' => 'info',
                        'data'  => $refund_data,
                    ],
                ];
                if (!empty($context['impact'])) {
                    $components[] = [
                        'key'   => 'order_impact-' . uniqid('', true),
                        'kind'  => 'woocommerce_analysis',
                        'ts'    => $now,
                        'label' => 'Order Impact',
                        'level' => 'info',
                        'data'  => $context['impact'],
                    ];
                }
                // Performance metrics component
                $__odcm_exec_ms = (microtime(true) - $__odcm_perf_start) * 1000.0;
                $__odcm_mem_used = function_exists('memory_get_usage') ? ((int) memory_get_usage() - $__odcm_mem_start) : 0;
                $perf_extra = [
                    'lock_acquire_ms' => isset($lock_acquire_ms) ? $lock_acquire_ms : null,
                    'context_build_ms' => isset($context['performance']['context_build_ms']) ? (float)$context['performance']['context_build_ms'] : null,
                    'refund_load_ms'   => isset($context['performance']['refund_load_ms']) ? (float)$context['performance']['refund_load_ms'] : null,
                    'order_load_ms'    => isset($context['performance']['order_load_ms']) ? (float)$context['performance']['order_load_ms'] : null,
                    'db_meta_ms'       => isset($context['performance']['db_meta_ms']) ? (float)$context['performance']['db_meta_ms'] : null,
                    'concurrency'      => self::$refundConcurrency,
                ];
                $metrics_component = $this->create_metrics_component('Performance Metrics', 'refund_processing', (float)$__odcm_exec_ms, (int) max(0, $__odcm_mem_used), array_filter($perf_extra, static function($v){ return $v !== null; }));
                $components[] = $metrics_component;
                $status = $this->merge_status($status, (string)$metrics_component['level']);
                $summary = $this->build_summary($event_type, $order_id, $refund_id);
                $envelope = [
                    'type'               => 'refund_event',
                    'correlation_id'     => 'odcm:refund:' . $order_id . ':' . $refund_id . ':' . uniqid('', true),
                    'order_id'           => $order_id,
                    'started_at'         => $now,
                    'finished_at'        => $now,
                    'status'             => $status,
                    'summary'            => $summary,
                    'payload_components' => $components,
                ];
                $amount_val = isset($context['refund']['amount']) ? (float)$context['refund']['amount'] : null;
                $currency = ($order instanceof WC_Order) ? (string)$order->get_currency() : '';
                $formatted_amount = $amount_val !== null ? (function_exists('wc_price') ? wc_price($amount_val, $currency !== '' ? ['currency' => $currency] : []) : number_format_i18n($amount_val, 2)) : '';
                odcm_log_registered_event($event_type, [
                    'order_id'     => $order_id,
                    'details'      => $envelope,
                    'status'       => $status,
                    'summary_args' => [$refund_id, $order_id, $formatted_amount],
                    'source'       => 'refund_diagnostics',
                ]);
            }
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_refund_created error', $e);
        } finally {
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle refund deleted event.
     *
     * @param int               $refund_id Refund ID.
     * @param array|WC_Order    $args_or_order Optional args or order object depending on WC version.
     * @return void
     */
    public function handle_refund_deleted($refund_id, $args_or_order = null): void
    {
        try {
            $__odcm_perf_start = microtime(true);
            $__odcm_mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
            $refund_id = (int) $refund_id;
            $lockKey = 'refund_deleted_' . $refund_id;
            $lockStart = microtime(true);
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            $lock_acquire_ms = (microtime(true) - $lockStart) * 1000.0;
            self::$refundConcurrency++;
            if ($refund_id <= 0) {
                return;
            }
            $order_id  = $this->resolve_order_id_from_refund($refund_id);
            $order     = ($order_id > 0 && $this->is_woocommerce_available()) ? wc_get_order($order_id) : null;
            $context   = $this->is_woocommerce_available() ? $this->capture_refund_context($refund_id, $order instanceof WC_Order ? $order : null) : [];
            if (function_exists('odcm_log_registered_event')) {
                $now = odcm_iso8601_now();
                $event_type = 'refund_deleted';
                $refund_data = [
                    'refund'    => $context['refund'] ?? [],
                    'items'     => $context['items'] ?? [],
                    'actor'     => $context['actor'] ?? [],
                    'technical' => $context['technical'] ?? [],
                ];
                $components = [
                    [
                        'key'   => 'refund_details-' . uniqid('', true),
                        'kind'  => 'refund_analysis',
                        'ts'    => $now,
                        'label' => 'Refund Details',
                        'level' => 'warning',
                        'data'  => $refund_data,
                    ],
                ];
                if (!empty($context['impact'])) {
                    $components[] = [
                        'key'   => 'order_impact-' . uniqid('', true),
                        'kind'  => 'woocommerce_analysis',
                        'ts'    => $now,
                        'label' => 'Order Impact',
                        'level' => 'info',
                        'data'  => $context['impact'],
                    ];
                }
                // Performance metrics component
                $__odcm_exec_ms = (microtime(true) - $__odcm_perf_start) * 1000.0;
                $__odcm_mem_used = function_exists('memory_get_usage') ? ((int) memory_get_usage() - $__odcm_mem_start) : 0;
                $perf_extra = [
                    'lock_acquire_ms' => isset($lock_acquire_ms) ? $lock_acquire_ms : null,
                    'context_build_ms' => isset($context['performance']['context_build_ms']) ? (float)$context['performance']['context_build_ms'] : null,
                    'refund_load_ms'   => isset($context['performance']['refund_load_ms']) ? (float)$context['performance']['refund_load_ms'] : null,
                    'order_load_ms'    => isset($context['performance']['order_load_ms']) ? (float)$context['performance']['order_load_ms'] : null,
                    'db_meta_ms'       => isset($context['performance']['db_meta_ms']) ? (float)$context['performance']['db_meta_ms'] : null,
                    'concurrency'      => self::$refundConcurrency,
                ];
                $metrics_component = $this->create_metrics_component('Performance Metrics', 'refund_processing', (float)$__odcm_exec_ms, (int) max(0, $__odcm_mem_used), array_filter($perf_extra, static function($v){ return $v !== null; }));
                $components[] = $metrics_component;
                $status = 'warning';
                $status = $this->merge_status($status, (string)$metrics_component['level']);

                $summary = $this->build_summary($event_type, $order_id, $refund_id);
                $envelope = [
                    'type'               => 'refund_event',
                    'correlation_id'     => 'odcm:refund:' . $order_id . ':' . $refund_id . ':' . uniqid('', true),
                    'order_id'           => $order_id,
                    'started_at'         => $now,
                    'finished_at'        => $now,
                    'status'             => $status,
                    'summary'            => $summary,
                    'payload_components' => $components,
                ];
                odcm_log_registered_event($event_type, [
                    'order_id'     => $order_id,
                    'details'      => $envelope,
                    'status'       => $status,
                    'summary_args' => [$refund_id, $order_id],
                    'source'       => 'refund_diagnostics',
                ]);
            }
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_refund_deleted error', $e);
        } finally {
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle before delete order event.
     *
     * @param int           $order_id Order ID.
     * @param WC_Order|null $order    Order object (if provided by WC version).
     * @return void
     */
    public function handle_before_delete_order($order_id, $order = null): void
    {
        try {
            $order_id = (int) $order_id;
            $lockKey = 'before_delete_' . $order_id;
            $lockStart = microtime(true);
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            $lock_acquire_ms = (microtime(true) - $lockStart) * 1000.0;
            self::$deletionConcurrency++;
            if ($order_id <= 0) {
                return;
            }
            // Create and store a pre-deletion snapshot for 300 seconds
            if ($this->is_woocommerce_available()) {
                $order_obj = $order instanceof WC_Order ? $order : wc_get_order($order_id);
                if ($order_obj instanceof WC_Order) {
                    $extra = [
                        'deletion' => [
                            'phase'  => 'before_delete',
                            'who'    => get_current_user_id() ?: null,
                            'ip'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : null,
                            'ua'     => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : null,
                            'ref'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw((string) $_SERVER['HTTP_REFERER']) : null,
                            'ts'     => odcm_iso8601_now(),
                        ],
                    ];
                    $snapshot = $this->capture_order_snapshot($order_obj, $extra);
                    // enrich perf with lock/concurrency
                    if (is_array($snapshot)) {
                        if (!isset($snapshot['perf']) || !is_array($snapshot['perf'])) { $snapshot['perf'] = []; }
                        $snapshot['perf']['lock_acquire_ms'] = isset($lock_acquire_ms) ? $lock_acquire_ms : null;
                        $snapshot['perf']['concurrency'] = self::$deletionConcurrency;
                    }
                    $tset0 = microtime(true);
                    set_transient('odcm_pre_delete_order_' . $order_id, $snapshot, 300);
                    $tset_ms = (microtime(true) - $tset0) * 1000.0;
                    if (isset($snapshot['perf'])) { $snapshot['perf']['transient_set_ms'] = $tset_ms; }
                }
            }
            $this->log_deletion_event('order_deleted', $order_id, 'error', 'Before Delete', isset($snapshot) && is_array($snapshot) ? $snapshot : null);
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_before_delete_order error', $e);
        } finally {
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle order delete event.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function handle_delete_order($order_id): void
    {
        $lockKey = null;
        try {
            $order_id = (int) $order_id;
            $lockKey = 'delete_' . $order_id;
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            if ($order_id <= 0) {
                return;
            }
            $snapshot = get_transient('odcm_pre_delete_order_' . $order_id);
            $this->log_deletion_event('order_deleted', $order_id, 'error', 'Deleted', is_array($snapshot) ? $snapshot : null);
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_delete_order error', $e);
        } finally {
            delete_transient('odcm_pre_delete_order_' . (int)$order_id);
            if ($lockKey) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle before trash order event.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function handle_before_trash_order($order_id): void
    {
        try {
            $order_id = (int) $order_id;
            $lockKey = 'before_trash_' . $order_id;
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            if ($order_id <= 0) {
                return;
            }
            // Create and store a pre-trash snapshot for 300 seconds
            if ($this->is_woocommerce_available()) {
                $order_obj = wc_get_order($order_id);
                if ($order_obj instanceof WC_Order) {
                    $extra = [
                        'deletion' => [
                            'phase'  => 'before_trash',
                            'who'    => get_current_user_id() ?: null,
                            'ip'     => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : null,
                            'ua'     => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : null,
                            'ref'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw((string) $_SERVER['HTTP_REFERER']) : null,
                            'ts'     => odcm_iso8601_now(),
                        ],
                    ];
                    $snapshot = $this->capture_order_snapshot($order_obj, $extra);
                    set_transient('odcm_pre_delete_order_' . $order_id, $snapshot, 300);
                }
            }
            $this->log_deletion_event('order_trashed', $order_id, 'warning', 'Before Trash');
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_before_trash_order error', $e);
        } finally {
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle order trashed event.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function handle_trash_order($order_id): void
    {
        $lockKey = null;
        try {
            $order_id = (int) $order_id;
            $lockKey = 'trash_' . $order_id;
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            if ($order_id <= 0) {
                return;
            }
            $snapshot = get_transient('odcm_pre_delete_order_' . $order_id);
            $this->log_deletion_event('order_trashed', $order_id, 'warning', 'Trashed', is_array($snapshot) ? $snapshot : null);
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_trash_order error', $e);
        } finally {
            delete_transient('odcm_pre_delete_order_' . (int)$order_id);
            if ($lockKey) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Handle untrashed post event (restore from trash). Only for shop_order post type.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function handle_untrashed_post($post_id): void
    {
        try {
            $post_id = (int) $post_id;
            $lockKey = 'untrash_' . $post_id;
            if (!$this->acquire_lock($lockKey)) {
                return;
            }
            if ($post_id <= 0) {
                return;
            }
            if (get_post_type($post_id) !== 'shop_order') {
                return;
            }
            $this->log_deletion_event('order_restored', $post_id, 'info', 'Restored');
        } catch (\Throwable $e) {
            $this->safe_error_log('handle_untrashed_post error', $e);
        } finally {
            if (isset($lockKey)) {
                $this->release_lock($lockKey);
            }
        }
    }

    /**
     * Logs a refund-related event with a narrative payload.
     *
     * @param string   $event_type Event type slug registered in LogRegistries.
     * @param int      $order_id   Order ID (0 if unknown).
     * @param int      $refund_id  Refund ID (0 if unknown).
     * @param string   $status     Log status (default 'info').
     * @return void
     */
    private function log_refund_event(string $event_type, int $order_id, int $refund_id, string $status = 'info', ?array $refund_context = null): void
    {
        if (!function_exists('odcm_log_event')) {
            return;
        }

        try {
            $order   = ($order_id > 0 && function_exists('wc_get_order')) ? wc_get_order($order_id) : null;
            $refund  = ($refund_id > 0 && function_exists('wc_get_order')) ? wc_get_order($refund_id) : null;
            $o_valid = $order instanceof WC_Order;
            $r_valid = $refund instanceof WC_Order_Refund;

            $amount  = $r_valid ? (float) $refund->get_amount() : null;

            // Build timeline components per Insight Dashboard requirements
        $refund_details = [
            'event_type'   => $event_type,
            'order_id'     => $order_id,
            'refund_id'    => $refund_id,
            'amount'       => $amount,
            'order_status' => $o_valid ? $order->get_status() : null,
        ];
        if (is_array($refund_context)) {
            $refund_details['reason']      = $refund_context['refund']['reason'] ?? null;
            $refund_details['refunded_by'] = $refund_context['refund']['refunded_by'] ?? null;
            $refund_details['date']        = $refund_context['refund']['date'] ?? null;
        }

        $components = [
            [
                'key'   => 'refund-details-' . uniqid('', true),
                'kind'  => 'warning',
                'ts'    => odcm_iso8601_now(),
                'label' => 'Refund Details',
                'level' => $status,
                'data'  => $refund_details,
            ],
        ];
        if (is_array($refund_context) && !empty($refund_context['impact'])) {
            $components[] = [
                'key'   => 'order-impact-' . uniqid('', true),
                'kind'  => 'fallback',
                'ts'    => odcm_iso8601_now(),
                'label' => 'Order Impact',
                'level' => 'info',
                'data'  => $refund_context['impact'],
            ];
        }
        if (is_array($refund_context) && !empty($refund_context['items'])) {
            $components[] = [
                'key'   => 'refunded-items-' . uniqid('', true),
                'kind'  => 'fallback',
                'ts'    => odcm_iso8601_now(),
                'label' => 'Refunded Items',
                'level' => 'info',
                'data'  => $refund_context['items'],
            ];
        }

        $summary = $this->build_summary($event_type, $order_id, $refund_id);

        // Canonical narrative-first payload submission
        $envelope = [
            'type'               => 'refund_event',
            'correlation_id'     => 'odcm:refund:' . ($order_id ?: 0) . ':' . uniqid('', true),
            'order_id'           => $order_id,
            'refund_id'          => $refund_id,
            'started_at'         => odcm_iso8601_now(),
            'finished_at'        => odcm_iso8601_now(),
            'status'             => $status,
            'summary'            => $summary,
            'payload_components' => $components,
        ];
        if (is_array($refund_context)) {
            $envelope['refund_context'] = $refund_context;
        }

        $event_data = [
            'canonical'  => true,
            'summary'    => $summary,
            'event_type' => $event_type,
            'status'     => $status,
            'data'       => [
                'order_id'     => $order_id,
                'details'      => $envelope,
                'log_category' => 'core',
                'is_test'      => false,
                'source'       => 'refund_diagnostics',
            ],
        ];

        odcm_log_event($event_data);
        } catch (\Throwable $e) {
            $this->safe_error_log('log_refund_event error', $e);
        }
    }

    /**
     * Logs an order deletion-related event with a narrative payload.
     *
     * @param string               $event_type Event type slug registered in LogRegistries.
     * @param int                  $order_id   Order ID.
     * @param string               $status     Log status.
     * @param string               $phase      Optional phase label.
     * @param array<string,mixed>|null $snapshot Optional pre-deletion snapshot to include.
     * @return void
     */
    private function log_deletion_event(string $event_type, int $order_id, string $status = 'warning', string $phase = '', ?array $snapshot = null): void
    {
        if (!function_exists('odcm_log_registered_event')) {
            return;
        }
        $perf_start = microtime(true);
        $mem_start  = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
        try {
        $order   = ($order_id > 0 && function_exists('wc_get_order')) ? wc_get_order($order_id) : null;
        $o_valid = $order instanceof WC_Order;

        // Determine notice component kind based on event type (canonical kinds)
        $notice_kind = ($event_type === 'order_restored') ? 'system_info' : 'order_deletion';
        $label = ($event_type === 'order_restored') ? 'Order Restoration' : 'Order Deletion';

        $components = [
            [
                'key'   => 'deletion-notice-' . uniqid('', true),
                'kind'  => $notice_kind,
                'ts'    => odcm_iso8601_now(),
                'label' => $label . ($phase ? ' - ' . $phase : ''),
                'level' => $status,
                'data'  => [
                    'event_type'   => $event_type,
                    'order_id'     => $order_id,
                    'order_status' => $o_valid ? $order->get_status() : null,
                    'phase'        => $phase ?: null,
                ],
            ],
        ];
        if (is_array($snapshot) && !empty($snapshot)) {
            $components[] = [
                'key'   => 'order-snapshot-' . uniqid('', true),
                'kind'  => 'fallback',
                'ts'    => odcm_iso8601_now(),
                'label' => 'Order Snapshot',
                'level' => 'info',
                'data'  => $snapshot,
            ];
            if (!empty($snapshot['customer'])) {
                $components[] = [
                    'key'   => 'customer-info-' . uniqid('', true),
                    'kind'  => 'fallback',
                    'ts'    => odcm_iso8601_now(),
                    'label' => 'Customer Info',
                    'level' => 'info',
                    'data'  => $snapshot['customer'],
                ];
            }
        }
        // Performance metrics for deletion/restoration
        $exec_ms = (microtime(true) - $perf_start) * 1000.0;
        $mem_used = function_exists('memory_get_usage') ? ((int) memory_get_usage() - $mem_start) : 0;
        $op = ($event_type === 'order_restored') ? 'order_restoration' : 'order_deletion';
        $perf_extra = [];
        if (is_array($snapshot) && isset($snapshot['perf']) && is_array($snapshot['perf'])) {
            $perf_extra = $snapshot['perf'];
        }
        $metrics_component = $this->create_metrics_component('Performance Metrics', $op, (float)$exec_ms, (int) max(0, $mem_used), $perf_extra);
        $components[] = $metrics_component;
        $status = $this->merge_status($status, (string)$metrics_component['level']);

        $summary = $this->build_summary($event_type, $order_id, 0);

        // Canonical narrative-first envelope
        $envelope = [
            'type'               => 'deletion_event',
            'correlation_id'     => 'odcm:deletion:' . ($order_id ?: 0) . ':' . uniqid('', true),
            'order_id'           => $order_id,
            'started_at'         => odcm_iso8601_now(),
            'finished_at'        => odcm_iso8601_now(),
            'status'             => $status,
            'summary'            => $summary,
            'payload_components' => $components,
        ];
        if (is_array($snapshot)) {
            $envelope['snapshot'] = $snapshot;
        }

        // Determine actor display for summary templates
        $actor_id = get_current_user_id();
        $actor_display = $actor_id ? ('#' . $actor_id) : __('system', 'order-daemon');
        if ($actor_id) {
            $u = function_exists('get_userdata') ? get_userdata($actor_id) : null;
            if ($u && isset($u->display_name) && is_string($u->display_name) && $u->display_name !== '') {
                $actor_display .= ' — ' . sanitize_text_field((string)$u->display_name);
            }
        }
        // Registry-based logging with templated summary
        odcm_log_registered_event($event_type, [
            'order_id'     => $order_id,
            'details'      => $envelope,
            'status'       => $status,
            'summary_args' => [$order_id, $actor_display],
            'source'       => 'deletion_diagnostics',
        ]);
        } catch (\Throwable $e) {
            $this->safe_error_log('log_deletion_event error', $e);
        }
    }

    /**
     * Resolve order ID from a refund ID.
     *
     * @param int $refund_id Refund post ID.
     * @return int Order ID or 0 if unknown.
     */
    private function resolve_order_id_from_refund(int $refund_id): int
    {
        if ($refund_id <= 0 || !function_exists('wc_get_order')) {
            return 0;
        }
        $refund = wc_get_order($refund_id);
        if ($refund instanceof WC_Order_Refund) {
            $parent_id = (int) $refund->get_parent_id();
            return $parent_id > 0 ? $parent_id : 0;
        }
        return 0;
    }

    /**
     * Build a human-readable summary for the event.
     *
     * @param string $event_type Event type slug.
     * @param int    $order_id   Order ID.
     * @param int    $refund_id  Refund ID.
     * @return string Summary string.
     */
    private function build_summary(string $event_type, int $order_id, int $refund_id): string
    {
        switch ($event_type) {
            case 'order_partially_refunded':
                return sprintf('Order #%d partially refunded (Refund #%d)', $order_id, $refund_id);
            case 'order_fully_refunded':
                return sprintf('Order #%d fully refunded (Refund #%d)', $order_id, $refund_id);
            case 'refund_created':
                return sprintf('Refund #%d created for order #%d', $refund_id, $order_id);
            case 'refund_deleted':
                return sprintf('Refund #%d deleted for order #%d', $refund_id, $order_id);
            case 'order_deleted':
                return sprintf('Order #%d deleted', $order_id);
            case 'order_trashed':
                return sprintf('Order #%d moved to trash', $order_id);
            case 'order_restored':
                return sprintf('Order #%d restored from trash', $order_id);
            default:
                return sprintf('Order #%d refunded (Refund #%d)', $order_id, $refund_id);
        }
    }

    /**
     * Determines if refund tracking is enabled via option.
     * Accepts values like 'yes', 'true', '1', 1 to enable. Defaults to enabled.
     *
     * @return bool True if enabled.
     */
    private function is_refund_tracking_enabled(): bool
    {
        $val = get_option('odcm_enable_refund_tracking', 'yes');
        return $this->normalize_bool($val, true);
    }

    /**
     * Determines if deletion tracking is enabled via option.
     * Accepts values like 'yes', 'true', '1', 1 to enable. Defaults to enabled.
     *
     * @return bool True if enabled.
     */
    private function is_deletion_tracking_enabled(): bool
    {
        $val = get_option('odcm_enable_deletion_tracking', 'yes');
        return $this->normalize_bool($val, true);
    }

    /**
     * Normalize common truthy/falsey option values to boolean.
     *
     * @param mixed $value Raw option value.
     * @param bool  $default Default if not parsable.
     * @return bool
     */
    private function normalize_bool($value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    /**
     * Synthesize refund event from WooCommerce order and refund data
     *
     * @param \WC_Order $order WooCommerce order object
     * @param \WC_Order_Refund $refund WooCommerce refund object
     * @param string $event_type Event type
     * @return UniversalEvent
     */
    private function synthesize_refund_event(\WC_Order $order, \WC_Order_Refund $refund, string $event_type): UniversalEvent
    {
        return new UniversalEvent([
            'eventType' => $event_type,
            'sourceGateway' => $this->normalize_gateway_name($order->get_payment_method()),
            'channel' => 'system',
            'primaryObjectType' => 'refund',
            'primaryObjectID' => $refund->get_id(),
            'secondaryObjectType' => 'order',
            'secondaryObjectID' => $order->get_id(),
            'transactionID' => $order->get_transaction_id(),
            'status' => 'refunded',
            'amount' => (float) $refund->get_amount(),
            'currency' => $order->get_currency(),
            'reason' => $refund->get_reason(),
            'occurredAt' => current_time('c'),
            'rawData' => [
                'refund_reason' => $refund->get_reason(),
                'refunded_by' => get_post_meta($refund->get_id(), '_refund_user_id', true),
                'order_status' => $order->get_status(),
                'source' => $this->determine_change_source()
            ]
        ]);
    }

    /**
     * Normalize gateway name to standard format
     *
     * @param string $payment_method WooCommerce payment method ID
     * @return string Normalized gateway name
     */
    private function normalize_gateway_name(string $payment_method): string
    {
        $gateway_mapping = [
            'paypal' => 'paypal',
            'ppcp-gateway' => 'paypal',
            'ppcp-credit-card-gateway' => 'paypal',
            'stripe' => 'stripe',
            'stripe_cc' => 'stripe',
            'stripe_sepa' => 'stripe',
            'bacs' => 'bank_transfer',
            'cheque' => 'check',
            'cod' => 'cash_on_delivery',
        ];

        return $gateway_mapping[$payment_method] ?? $payment_method;
    }

    /**
     * Determine the source of the change
     *
     * @return string Change source
     */
    private function determine_change_source(): string
    {
        try {
            if (class_exists('OrderDaemon\\CompletionManager\\Core\\AttributionTracker')) {
                $attr = \OrderDaemon\CompletionManager\Core\AttributionTracker::instance()->capture_context();
                $request_type = is_array($attr) ? sanitize_key((string)($attr['request_type'] ?? '')) : '';
                $external_service_name = (is_array($attr) && isset($attr['external_service']['name'])) ? sanitize_key((string)$attr['external_service']['name']) : null;

                if (is_user_logged_in()) {
                    return 'manual';
                } elseif ($request_type === 'webhook' || !empty($external_service_name)) {
                    return 'webhook';
                } elseif ($request_type === 'rest' || $request_type === 'ajax') {
                    return 'api';
                } elseif (in_array($request_type, ['action_scheduler','cron','cli','wp_cli'], true)) {
                    return 'scheduled';
                } else {
                    return 'system';
                }
            }
        } catch (\Throwable $e) {
            // Fall back to basic detection
        }

        // Basic fallback detection
        if (is_user_logged_in()) {
            return 'manual';
        } elseif (wp_doing_ajax()) {
            return 'api';
        } elseif (wp_doing_cron()) {
            return 'scheduled';
        } else {
            return 'system';
        }
    }

    /**
     * Process universal event from hook through the universal event pipeline
     *
     * @param UniversalEvent $event Universal event to process
     * @return void
     */
    private function process_universal_event_from_hook(UniversalEvent $event): void
    {
        try {
            // Schedule universal event processing through Action Scheduler
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'odcm_process_lifecycle_event',
                    ['event' => $event->toArray()],
                    'odcm-universal-events'
                );
            } else {
                // Fallback: process directly if Action Scheduler not available
                $processor = UniversalEventProcessor::instance();
                $processor->processEvent($event->toArray());
            }
        } catch (\Throwable $e) {
            // Log error but don't let it break the refund process
            odcm_log_message('Payment gateway event processing error: ' . $e->getMessage(), 'error');
            odcm_log_message('Payment gateway event processing error details: ' . $e->getFile() . ':' . $e->getLine(), 'error');
            // Continue execution without throwing the exception
        }
    }
}
