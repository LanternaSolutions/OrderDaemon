<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents;

/**
 * RuleIndexBuilder: derives read-only admin indexes from _odcm_rule_data
 *
 * These indexes are used only for fast admin filtering/search. Runtime
 * evaluation must never consume them.
 */
final class RuleIndexBuilder
{
    /**
     * Build all derived indexes from the decoded rule array and persist as post meta.
     *
     * @param int   $post_id Rule post ID
     * @param array $rule    Decoded rule structure from _odcm_rule_data
     * @return void
     */
    public function build_and_save(int $post_id, array $rule): void
    {
        // Start with a clean slate to avoid stale keys
        $this->clear_indexes($post_id);

        $trigger = isset($rule['trigger']) && is_array($rule['trigger']) ? $rule['trigger'] : [];
        $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];

        // Presence list of condition ids
        $condition_ids = [];

        // Buckets
        $product_categories = [];
        $product_ids        = [];
        $product_types      = [];
        $product_type_mode  = null;

        $payment_methods = [];
        $payment_mode    = null;

        $shipping_methods = [];
        $shipping_mode    = null;

        $item_count_operator = null;
        $item_count_value    = null;
        $item_count_type     = null;

        $order_total_operator   = null;
        $order_total_threshold  = null; // minor units
        $order_total_currency   = $this->get_store_currency();

        $customer_roles  = [];
        $include_guests  = null;

        $has_payment_method  = false;
        $has_shipping_method = false;

        foreach ($conditions as $cond) {
            $id = is_string($cond['id'] ?? null) ? $cond['id'] : '';
            if ($id === '') {
                continue;
            }
            $condition_ids[] = sanitize_text_field($id);
            $settings = is_array($cond['settings'] ?? null) ? $cond['settings'] : [];

            switch ($id) {
                case 'product_category':
                case 'product_categories':
                    $cats = $this->sanitize_int_array($settings['categories'] ?? $settings['ids'] ?? []);
                    $product_categories = array_values(array_unique(array_merge($product_categories, $cats)));
                    break;

                case 'product_ids':
                case 'products_in_list':
                case 'product_in_list':
                    $ids = $this->sanitize_int_array($settings['product_ids'] ?? $settings['ids'] ?? $settings['products'] ?? []);
                    $product_ids = array_values(array_unique(array_merge($product_ids, $ids)));
                    break;

                case 'product_type':
                    $product_types = array_values(array_unique(array_merge(
                        $product_types,
                        $this->sanitize_string_array($settings['types'] ?? [])
                    )));
                    $product_type_mode = $this->sanitize_string($settings['match_mode'] ?? $product_type_mode);
                    break;

                case 'payment_method':
                    $has_payment_method = true;
                    $payment_methods = array_values(array_unique(array_merge(
                        $payment_methods,
                        $this->sanitize_string_array($settings['methods'] ?? [])
                    )));
                    $payment_mode = $this->sanitize_string($settings['match_mode'] ?? $payment_mode);
                    break;

                case 'shipping_method':
                case 'shipping_methods':
                    $has_shipping_method = true;
                    $shipping_methods = array_values(array_unique(array_merge(
                        $shipping_methods,
                        $this->sanitize_string_array($settings['methods'] ?? [])
                    )));
                    $shipping_mode = $this->sanitize_string($settings['match_mode'] ?? $shipping_mode);
                    break;

                case 'order_item_count':
                    $item_count_operator = $this->sanitize_string($settings['operator'] ?? $item_count_operator);
                    $item_count_value    = $this->sanitize_int($settings['count'] ?? $item_count_value ?? 0);
                    $item_count_type     = $this->sanitize_string($settings['count_type'] ?? $item_count_type);
                    break;

                case 'order_total_amount':
                    $order_total_operator = $this->sanitize_string($settings['operator'] ?? $order_total_operator);
                    $amount = $this->sanitize_number($settings['amount'] ?? null);
                    if ($amount !== null) {
                        $order_total_threshold = $this->to_minor_units($amount);
                    }
                    // store currency at save time
                    $order_total_currency = $this->get_store_currency();
                    break;

                case 'customer_role':
                case 'user_role':
                    $customer_roles = array_values(array_unique(array_merge(
                        $customer_roles,
                        $this->sanitize_string_array($settings['roles'] ?? [])
                    )));
                    $include_guests = $this->sanitize_bool($settings['include_guests'] ?? $include_guests ?? false);
                    break;

                default:
                    // Unknown condition indexes ignored but condition id is stored above
                    break;
            }
        }

        // Process trigger settings for indexing
        $trigger_id = is_string($trigger['id'] ?? null) ? $trigger['id'] : '';
        $trigger_settings = is_array($trigger['settings'] ?? null) ? $trigger['settings'] : [];
        
        // Trigger-specific indexing - all statuses involved in trigger
        $trigger_statuses = [];
        
        if ($trigger_id === 'order_status_any_change') {
            // Extract all statuses from both from_statuses and to_statuses
            // Since they're both WooCommerce status arrays, create a single index
            $all_statuses = array_merge(
                $this->sanitize_string_array($trigger_settings['from_statuses'] ?? []),
                $this->sanitize_string_array($trigger_settings['to_statuses'] ?? [])
            );
            $trigger_statuses = array_values(array_unique($all_statuses));
        }

        // Save presence/flags
        $this->save_array_meta($post_id, '_odcm_idx_conditions', $condition_ids);
        if ($has_payment_method) {
            update_post_meta($post_id, '_odcm_idx_has_payment_method', '1');
        }
        if ($has_shipping_method) {
            update_post_meta($post_id, '_odcm_idx_has_shipping_method', '1');
        }

        // Catalog/product indexes
        $this->save_array_meta($post_id, '_odcm_idx_condition_product_categories', $product_categories);
        $this->save_array_meta($post_id, '_odcm_idx_condition_product_ids', $product_ids);

        // Product type
        $this->save_array_meta($post_id, '_odcm_idx_condition_product_types', $product_types);
        $this->save_string_meta($post_id, '_odcm_idx_product_type_match_mode', $product_type_mode);

        // Payment
        $this->save_array_meta($post_id, '_odcm_idx_condition_payment_methods', $payment_methods);
        $this->save_string_meta($post_id, '_odcm_idx_payment_match_mode', $payment_mode);

        // Shipping
        $this->save_array_meta($post_id, '_odcm_idx_condition_shipping_methods', $shipping_methods);
        $this->save_string_meta($post_id, '_odcm_idx_shipping_match_mode', $shipping_mode);

        // Item count
        $this->save_string_meta($post_id, '_odcm_idx_item_count_operator', $item_count_operator);
        if ($item_count_value !== null) {
            update_post_meta($post_id, '_odcm_idx_item_count_value', $item_count_value);
        }
        $this->save_string_meta($post_id, '_odcm_idx_item_count_type', $item_count_type);

        // Order total
        $this->save_string_meta($post_id, '_odcm_idx_order_total_operator', $order_total_operator);
        if ($order_total_threshold !== null) {
            update_post_meta($post_id, '_odcm_idx_order_total_threshold', $order_total_threshold);
        }
        $this->save_string_meta($post_id, '_odcm_idx_order_total_currency', $order_total_currency);

        // Customer role
        $this->save_array_meta($post_id, '_odcm_idx_customer_roles', $customer_roles);
        if ($include_guests !== null) {
            update_post_meta($post_id, '_odcm_idx_include_guests', $include_guests ? '1' : '0');
        }

        // Trigger statuses
        $this->save_array_meta($post_id, '_odcm_idx_trigger_statuses', $trigger_statuses);

        // Version
        update_post_meta($post_id, '_odcm_idx_version', 1);
    }

    /**
     * Clear all known index meta keys for a rule.
     *
     * @param int $post_id
     * @return void
     */
    public function clear_indexes(int $post_id): void
    {
        $keys = [
            '_odcm_idx_conditions',
            '_odcm_idx_has_shipping_method',
            '_odcm_idx_has_payment_method',
            '_odcm_idx_condition_product_categories',
            '_odcm_idx_condition_product_ids',
            '_odcm_idx_condition_product_types',
            '_odcm_idx_product_type_match_mode',
            '_odcm_idx_condition_payment_methods',
            '_odcm_idx_payment_match_mode',
            '_odcm_idx_condition_shipping_methods',
            '_odcm_idx_shipping_match_mode',
            '_odcm_idx_item_count_operator',
            '_odcm_idx_item_count_value',
            '_odcm_idx_item_count_type',
            '_odcm_idx_order_total_operator',
            '_odcm_idx_order_total_threshold',
            '_odcm_idx_order_total_currency',
            '_odcm_idx_customer_roles',
            '_odcm_idx_include_guests',
            '_odcm_idx_trigger_statuses',
            '_odcm_idx_version',
        ];
        foreach ($keys as $k) {
            delete_post_meta($post_id, $k);
        }
    }

    /**
     * Backfill job: rebuild indexes for all rules.
     * Safe and idempotent.
     *
     * @return void
     */
    public static function backfill_all(): void
    {
        // Only proceed if post type exists
        if (!post_type_exists('odcm_order_rule')) {
            return;
        }
        $q = new \WP_Query([
            'post_type' => 'odcm_order_rule',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        if (!$q->have_posts()) {
            update_option('odcm_indexes_built', 'yes');
            return;
        }
        $builder = new self();
        foreach ($q->posts as $pid) {
            $json = get_post_meta((int)$pid, '_odcm_rule_data', true);
            $rule = is_string($json) ? json_decode($json, true) : null;
            if (is_array($rule)) {
                try {
                    $builder->build_and_save((int)$pid, $rule);
                } catch (\Throwable $e) {
                    // Only log errors in debug mode
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        if (function_exists('odcm_log_message')) {
                            odcm_log_message('Index backfill error for post ' . (int)$pid . ': ' . $e->getMessage(), 'error');
                        } elseif (function_exists('wp_debug_log')) {
                            wp_debug_log('ODCM: Index backfill error for post ' . (int)$pid . ': ' . $e->getMessage());
                        } elseif (function_exists('do_action')) {
                            do_action('odcm_log_error', 'ODCM: Index backfill error for post ' . (int)$pid . ': ' . $e->getMessage());
                        } elseif (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                            $debug_file = odcm_get_uploads_dir() . '/debug.log';
                            @file_put_contents(
                                $debug_file,
                                '[' . gmdate('Y-m-d H:i:s') . '] ODCM: Index backfill error for post ' . (int)$pid . ': ' . $e->getMessage() . PHP_EOL,
                                FILE_APPEND
                            );
                        }
                    }
                }
            } else {
                try {
                    $builder->clear_indexes((int)$pid);
                } catch (\Throwable $e) {
                    // noop
                }
            }
        }
        update_option('odcm_indexes_built', 'yes');
    }

    // -------- Helpers --------

    private function save_array_meta(int $post_id, string $key, array $values): void
    {
        $values = array_values(array_unique($values));
        if (empty($values)) {
            delete_post_meta($post_id, $key);
            return;
        }
        update_post_meta($post_id, $key, $values);
    }

    private function save_string_meta(int $post_id, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            delete_post_meta($post_id, $key);
            return;
        }
        update_post_meta($post_id, $key, sanitize_text_field($value));
    }

    private function sanitize_string_array($arr): array
    {
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $v) {
            if (is_string($v)) {
                $sv = sanitize_text_field($v);
                if ($sv !== '') $out[] = $sv;
            }
        }
        return $out;
    }

    private function sanitize_int_array($arr): array
    {
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $v) {
            $out[] = absint(is_numeric($v) ? (int)$v : 0);
        }
        return array_values(array_filter($out, static function($n){ return $n > 0; }));
    }

    private function sanitize_string($v): ?string
    {
        if (!is_string($v)) return null;
        $s = sanitize_text_field($v);
        return $s === '' ? null : $s;
    }

    private function sanitize_int($v): ?int
    {
        if ($v === null || $v === '') return null;
        return absint((int)$v);
    }

    private function sanitize_bool($v): ?bool
    {
        if ($v === null) return null;
        return (bool)$v;
    }

    private function sanitize_number($v): ?float
    {
        if ($v === null || $v === '') return null;
        $n = is_numeric($v) ? (float)$v : 0.0;
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        return round($n, $decimals);
    }

    private function to_minor_units(float $amount): int
    {
        $dec = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        $mult = pow(10, $dec);
        return (int) round($amount * $mult);
    }

    private function get_store_currency(): string
    {
        if (function_exists('get_woocommerce_currency')) {
            $code = (string) get_woocommerce_currency();
            if ($code) return $code;
        }
        $opt = get_option('woocommerce_currency');
        return is_string($opt) && $opt !== '' ? $opt : 'USD';
    }
}
