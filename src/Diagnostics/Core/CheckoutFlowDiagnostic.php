<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Core;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * Checkout Flow Diagnostic
 *
 * This diagnostic tests the checkout flow to identify potential issues
 * that could cause "Something went wrong" errors during checkout.
 */
class CheckoutFlowDiagnostic extends AbstractDiagnostic
{
    public function get_name(): string
    {
        return 'Checkout Flow Health';
    }

    public function get_description(): string
    {
        return 'Tests checkout flow integrity and identifies potential blocking issues';
    }

    public function get_category(): string
    {
        return 'core';
    }

    public function get_priority(): int
    {
        return 100; // High priority for checkout issues
    }

    protected function execute(): DiagnosticResult
    {
        $result = DiagnosticResult::success($this->get_name(), 'Checkout flow analysis completed');
        
        // Test 1: Check Order Daemon hooks registration
        $this->test_hook_registration($result);
        
        // Test 2: Check Action Scheduler availability
        $this->test_action_scheduler($result);
        
        // Test 3: Test checkout hook execution simulation
        $this->test_checkout_hook_simulation($result);
        
        // Test 4: Check circuit breaker status
        $this->test_circuit_breaker_status($result);
        
        // Test 5: Check for conflicting plugins
        $this->test_plugin_conflicts($result);
        
        // Test 6: Test WooCommerce checkout availability
        $this->test_woocommerce_checkout($result);
        
        // Test 7: Check database connectivity during checkout
        $this->test_database_performance($result);
        
        return $result;
    }

    private function test_hook_registration(DiagnosticResult $result): void
    {
        try {
            $core_hooks = [
                'woocommerce_checkout_order_processed',
                'woocommerce_payment_complete',
                'woocommerce_new_order'
            ];
            
            $registered_hooks = [];
            $missing_hooks = [];
            
            foreach ($core_hooks as $hook) {
                if (has_action($hook)) {
                    $registered_hooks[] = $hook;
                } else {
                    $missing_hooks[] = $hook;
                }
            }
            
            $result->addDetail('registered_hooks', $registered_hooks);
            $result->addDetail('missing_hooks', $missing_hooks);
            
            if (!empty($missing_hooks)) {
                $result->addRecommendation('Critical hooks are not registered: ' . implode(', ', $missing_hooks));
            }
            
        } catch (\Throwable $e) {
            $result->addDetail('hook_registration_error', $e->getMessage());
            $result->addRecommendation('Hook registration check failed - plugin may not be properly initialized');
        }
    }

    private function test_action_scheduler(DiagnosticResult $result): void
    {
        try {
            $as_available = function_exists('as_enqueue_async_action');
            $result->addDetail('action_scheduler_available', $as_available);
            
            if (!$as_available) {
                $result->addRecommendation('Action Scheduler not available - async processing will fail');
                return;
            }
            
            // Test scheduling a dummy action
            $test_action_id = as_enqueue_async_action(
                'odcm_diagnostic_test_action',
                ['test' => true, 'timestamp' => time()],
                'odcm-diagnostics'
            );
            
            $result->addDetail('test_action_scheduled', $test_action_id ? 'success' : 'failed');
            $result->addDetail('test_action_id', $test_action_id);
            
            if (!$test_action_id) {
                $result->addRecommendation('Action Scheduler scheduling test failed');
            }
            
        } catch (\Throwable $e) {
            $result->addDetail('action_scheduler_error', $e->getMessage());
            $result->addRecommendation('Action Scheduler test failed: ' . $e->getMessage());
        }
    }

    private function test_checkout_hook_simulation(DiagnosticResult $result): void
    {
        try {
            // Create a test order for simulation
            if (!$this->is_woocommerce_available()) {
                $result->addDetail('checkout_simulation', 'skipped - WooCommerce not available');
                return;
            }
            
            // Get the Core instance if available
            $core_class = \OrderDaemon\CompletionManager\Core\Core::class;
            if (!class_exists($core_class)) {
                $result->addDetail('checkout_simulation_error', 'Core class not available');
                return;
            }
            
            // Test the fail-safe methods exist
            $core_methods = get_class_methods($core_class);
            $required_methods = [
                'log_checkout_event_minimal',
                'record_checkout_success',
                'record_checkout_failure',
                'emergency_fallback_processing',
                'monitor_execution_time'
            ];
            
            $missing_methods = [];
            foreach ($required_methods as $method) {
                if (!in_array($method, $core_methods, true)) {
                    $missing_methods[] = $method;
                }
            }
            
            $result->addDetail('fail_safe_methods_available', empty($missing_methods));
            $result->addDetail('missing_fail_safe_methods', $missing_methods);
            
            if (!empty($missing_methods)) {
                $result->addRecommendation('Required fail-safe methods missing: ' . implode(', ', $missing_methods));
            }
            
        } catch (\Throwable $e) {
            $result->addDetail('checkout_simulation_error', $e->getMessage());
            $result->addRecommendation('Checkout simulation failed: ' . $e->getMessage());
        }
    }

    private function test_circuit_breaker_status(DiagnosticResult $result): void
    {
        try {
            $failures = get_transient('odcm_checkout_failures') ?: 0;
            $successes = get_transient('odcm_checkout_successes') ?: 0;
            $circuit_open = (int) $failures >= 5;
            
            $result->addDetail('checkout_failures', (int) $failures);
            $result->addDetail('checkout_successes', (int) $successes);
            $result->addDetail('circuit_breaker_open', $circuit_open);
            
            if ($circuit_open) {
                $result->addRecommendation('Circuit breaker is OPEN - checkout processing is disabled due to failures');
            }
            
            if ($failures > 0) {
                $result->addRecommendation("Recent checkout failures detected: {$failures}");
            }
            
        } catch (\Throwable $e) {
            $result->addDetail('circuit_breaker_error', $e->getMessage());
        }
    }

    private function test_plugin_conflicts(DiagnosticResult $result): void
    {
        try {
            $active_plugins = get_option('active_plugins', []);
            $checkout_sensitive_plugins = [
                'woocommerce-checkout-manager',
                'checkout-field-editor',
                'woocommerce-one-page-checkout',
                'cartflows',
                'funnel-builder'
            ];
            
            $potential_conflicts = [];
            foreach ($active_plugins as $plugin) {
                foreach ($checkout_sensitive_plugins as $sensitive_plugin) {
                    if (strpos($plugin, $sensitive_plugin) !== false) {
                        $potential_conflicts[] = $plugin;
                    }
                }
            }
            
            $result->addDetail('potential_plugin_conflicts', $potential_conflicts);
            
            if (!empty($potential_conflicts)) {
                $result->addRecommendation('Potential checkout plugin conflicts detected: ' . implode(', ', $potential_conflicts));
            }
            
        } catch (\Throwable $e) {
            $result->addDetail('plugin_conflict_check_error', $e->getMessage());
        }
    }

    private function test_woocommerce_checkout(DiagnosticResult $result): void
    {
        try {
            if (!$this->is_woocommerce_available()) {
                $result->addDetail('woocommerce_available', false);
                $result->addRecommendation('WooCommerce is not available');
                return;
            }
            
            $result->addDetail('woocommerce_available', true);
            
            // Check if WooCommerce checkout is accessible
            $checkout_url = wc_get_checkout_url();
            $result->addDetail('checkout_url', $checkout_url);
            
            // Check if cart is available
            $cart_available = function_exists('WC') && WC()->cart !== null;
            $result->addDetail('cart_available', $cart_available);
            
            // Check if session is available
            $session_available = function_exists('WC') && WC()->session !== null;
            $result->addDetail('session_available', $session_available);
            
            if (!$cart_available) {
                $result->addRecommendation('WooCommerce cart not available');
            }
            
            if (!$session_available) {
                $result->addRecommendation('WooCommerce session not available');
            }
            
        } catch (\Throwable $e) {
            $result->addDetail('woocommerce_checkout_error', $e->getMessage());
            $result->addRecommendation('WooCommerce checkout test failed: ' . $e->getMessage());
        }
    }

    private function test_database_performance(DiagnosticResult $result): void
    {
        try {
            global $wpdb;
            
            // Check cache first for previous test results
            $cache_key = 'odcm_db_connectivity_test';
            $cached_result = wp_cache_get($cache_key);
            
            if (false !== $cached_result) {
                // Use cached results
                $result->addDetail('database_response_time', $cached_result['response_time']);
                $result->addDetail('database_connection', $cached_result['connection_status']);
                $result->addDetail('using_cached_result', true);
                
                // Add recommendation for slow database if cached result indicates it
                $response_time_value = (float) str_replace('ms', '', $cached_result['response_time']);
                if ($response_time_value > 100) { // 100ms threshold
                    $result->addRecommendation('Database response time is slow: ' . $cached_result['response_time']);
                }
            } else {
                $start_time = microtime(true);
                
                // Test basic database connectivity using WordPress option functions
                // This avoids direct database queries while still testing DB performance
                $test_option_name = 'odcm_db_connectivity_test_' . wp_generate_password(8, false);
                $test_value = 'test_' . time();
                
                // Test write operation
                $write_success = update_option($test_option_name, $test_value, false);
                
                // Test read operation
                $read_value = get_option($test_option_name);
                
                // Clean up test option
                delete_option($test_option_name);
                
                $db_time = microtime(true) - $start_time;
                
                $response_time = round($db_time * 1000, 2) . 'ms';
                $connection_status = ($write_success && $read_value === $test_value) ? 'success' : 'failed';
                
                $result->addDetail('database_response_time', $response_time);
                $result->addDetail('database_connection', $connection_status);
                
                // Add recommendation for slow database if needed
                if ($db_time > 0.1) { // 100ms threshold
                    $result->addRecommendation('Database response time is slow: ' . $response_time);
                }
                
                // Cache the result for 5 minutes - database performance tests shouldn't run too frequently
                wp_cache_set($cache_key, [
                    'response_time' => $response_time,
                    'connection_status' => $connection_status
                ], '', 5 * MINUTE_IN_SECONDS);
            }
            
            // Test transient operations (used by circuit breaker)
            $start_time = microtime(true);
            set_transient('odcm_diagnostic_test', 'test_value', 60);
            $transient_value = get_transient('odcm_diagnostic_test');
            delete_transient('odcm_diagnostic_test');
            $transient_time = microtime(true) - $start_time;
            
            $result->addDetail('transient_response_time', round($transient_time * 1000, 2) . 'ms');
            $result->addDetail('transient_test', $transient_value === 'test_value' ? 'success' : 'failed');
            
            if ($transient_time > 0.05) { // 50ms threshold
                $result->addRecommendation('Transient operations are slow: ' . round($transient_time * 1000, 2) . 'ms');
            }
            
        } catch (\Throwable $e) {
            $result->addDetail('database_performance_error', $e->getMessage());
            $result->addRecommendation('Database performance test failed: ' . $e->getMessage());
        }
    }
}
