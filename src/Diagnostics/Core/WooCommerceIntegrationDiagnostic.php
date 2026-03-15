<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Core;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * WooCommerce Integration Diagnostic - Checks WooCommerce Hook Registration
 *
 * This diagnostic verifies that WooCommerce hooks required for order completion
 * are properly registered and accessible for Order Daemon to function.
 *
 * @package OrderDaemon\CompletionManager\Diagnostics\Core
 */
class WooCommerceIntegrationDiagnostic extends AbstractDiagnostic
{
    /**
     * Critical WooCommerce hooks required for order completion
     */
    private const REQUIRED_HOOKS = [
        'woocommerce_checkout_order_processed',
        'woocommerce_payment_complete',
        'woocommerce_order_status_changed',
        'woocommerce_new_order'
    ];

    /**
     * Get the diagnostic name
     *
     * @return string
     */
    public function get_name(): string
    {
        return __('diagnostics.woocommerce.name', 'order-daemon');
    }

    /**
     * Get the diagnostic description
     *
     * @return string
     */
    public function get_description(): string
    {
        return __('diagnostics.woocommerce.description', 'order-daemon');
    }

    /**
     * Get the diagnostic category
     *
     * @return string
     */
    public function get_category(): string
    {
        return 'core';
    }

    /**
     * Get the diagnostic priority (lower = higher priority)
     *
     * @return int
     */
    public function get_priority(): int
    {
        return 2; // High priority for core functionality
    }

    /**
     * Check if this diagnostic requires the core plugin
     *
     * @return bool
     */
    public function requires_core_plugin(): bool
    {
        return true;
    }

    /**
     * Execute the WooCommerce integration diagnostic
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $start_time = microtime(true);
        
        // Test 1: Check if WooCommerce is active
        if (!$this->is_woocommerce_available()) {
            return DiagnosticResult::failure(
                __('diagnostics.woocommerce.result.issue', 'order-daemon'),
                __('diagnostics.woocommerce.error.not_active', 'order-daemon'),
                [
                    'woocommerce_active' => false,
                    'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
                ],
                [__('diagnostics.woocommerce.recommendation.install', 'order-daemon')]
            );
        }

        // Test 2: Check hook registration
        $hook_status = [];
        $missing_hooks = [];
        
        foreach (self::REQUIRED_HOOKS as $hook) {
            $has_actions = has_action($hook);
            $hook_status[$hook] = [
                'registered' => $has_actions !== false,
                'priority_count' => $has_actions !== false ? count($GLOBALS['wp_filter'][$hook]->callbacks ?? []) : 0
            ];
            
            if (!$has_actions) {
                $missing_hooks[] = $hook;
            }
        }

        // Test 3: Check WooCommerce version compatibility
        $wc_version = defined('WC_VERSION') ? WC_VERSION : 'unknown';
        $version_compatible = version_compare($wc_version, '3.0.0', '>=');

        // Test 4: Check if critical WooCommerce classes exist
        $critical_classes = [
            'WC_Order',
            'WC_Order_Item_Product',
            'WC_Product',
            'WC_Customer'
        ];
        
        $missing_classes = [];
        foreach ($critical_classes as $class) {
            if (!class_exists($class)) {
                $missing_classes[] = $class;
            }
        }

        // Compile detailed results
        $details = [
            'woocommerce_version' => $wc_version,
            'version_compatible' => $version_compatible,
            'hook_status' => $hook_status,
            'missing_hooks' => $missing_hooks,
            'missing_classes' => $missing_classes,
            'execution_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
        ];

        $recommendations = [];

        // Check for critical issues
        if (!empty($missing_hooks)) {
            $recommendations[] = sprintf(
                /* translators: %s: Comma-separated list of missing hooks */
                __('diagnostics.woocommerce.error.missing_hooks', 'order-daemon'),
                implode(', ', $missing_hooks)
            );
        }

        if (!$version_compatible) {
            $recommendations[] = sprintf(
                /* translators: %s: Current WooCommerce version */
                __('diagnostics.woocommerce.error.version_incompatible', 'order-daemon'),
                $wc_version
            );
        }

        if (!empty($missing_classes)) {
            $recommendations[] = sprintf(
                /* translators: %s: Comma-separated list of missing classes */
                __('diagnostics.woocommerce.error.missing_classes', 'order-daemon'),
                implode(', ', $missing_classes)
            );
        }

        // Determine overall result
        $critical_issues = !empty($missing_hooks) || !$version_compatible || !empty($missing_classes);

        if ($critical_issues) {
            $result = DiagnosticResult::failure(
                __('diagnostics.woocommerce.result.issue', 'order-daemon'),
                __('diagnostics.woocommerce.error.hooks_not_registered', 'order-daemon'),
                $details,
                $recommendations
            );
        } else {
            $result = DiagnosticResult::success(
                __('diagnostics.woocommerce.result.ok', 'order-daemon'),
                __('diagnostics.woocommerce.success', 'order-daemon'),
                $details
            );

            // Add informational recommendations for optimization
            $total_hooks = array_sum(array_column($hook_status, 'priority_count'));
            if ($total_hooks > 20) {
                $result->addRecommendation(__('diagnostics.woocommerce.recommendation.monitor_hooks', 'order-daemon'));
            }
        }

        $result->setExecutionTime(microtime(true) - $start_time);
        
        return $result;
    }
}
