<?php
declare(strict_types=1);

// Note: This file intentionally does NOT use a namespace
// so that the functions are available globally for WordPress compatibility

use OrderDaemon\CompletionManager\View\PayloadRenderer\AnalysisRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\OrderRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\PaymentRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\RuleRenderer;
use OrderDaemon\CompletionManager\View\PayloadRenderer\SystemRenderer;

/**
 * Payload Component Registry
 *
 * Provides direct mapping from event_type to renderer class.
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Get Renderer for Event Type
 *
 * Direct mapping from event_type to renderer class.
 * No complex lookups or capability checks - just a simple array lookup.
 *
 * @param string $event_type The event type to get a renderer for
 * @return string Renderer class name
 */
function odcm_get_renderer_for_event_type(string $event_type): string
{
    // Handle hierarchical payment events: payment.{gateway}.{original_event_type}
    if (strpos($event_type, 'payment.') === 0) {
        return PaymentRenderer::class;
    }
    
    $renderers = [
        // Process events -> Appropriate Renderers
        'rule_execution' => RuleRenderer::class,
        'status_change_processing' => OrderRenderer::class,
        'manual_status_change' => OrderRenderer::class,

        // Rule events -> RuleRenderer
        'rule_matched' => RuleRenderer::class,
        'rule_no_match' => RuleRenderer::class,
        'rule_evaluated' => RuleRenderer::class,
        'rule_evaluation' => RuleRenderer::class,
        'condition_passed' => RuleRenderer::class,
        'condition_failed' => RuleRenderer::class,
        'action_executed' => RuleRenderer::class,
        'decision' => RuleRenderer::class,
        'validation' => RuleRenderer::class,

        // Order events -> OrderRenderer
        'status_changed' => OrderRenderer::class,
        'order_loaded' => OrderRenderer::class,
        'block_checkout_processed' => OrderRenderer::class,
        'checkout_processed' => OrderRenderer::class,  // UniversalEvent checkout events
        'meta_updated' => OrderRenderer::class,
        'woocommerce_data' => OrderRenderer::class,
        'no_rules_matched' => OrderRenderer::class,
        
        // Subscription events -> OrderRenderer
        'subscription_created' => OrderRenderer::class,
        'subscription_approved' => OrderRenderer::class,
        'subscription_cancelled' => OrderRenderer::class,
        'subscription_suspended' => OrderRenderer::class,
        'subscription_reactivated' => OrderRenderer::class,
        'subscription_completed' => OrderRenderer::class,
        'subscription_expired' => OrderRenderer::class,
        'subscription_paused' => OrderRenderer::class,
        'subscription_resumed' => OrderRenderer::class,
        'subscription_updated' => OrderRenderer::class,
        'trial_ending' => OrderRenderer::class,
        'renewal_payment_completed' => OrderRenderer::class,
        'renewal_payment_failed' => OrderRenderer::class,
        'renewal_payment_processing' => OrderRenderer::class,
        'renewal_payment_pending' => OrderRenderer::class,

        // System events -> SystemRenderer
        'info' => SystemRenderer::class,
        'warning' => SystemRenderer::class,
        'error' => SystemRenderer::class,
        'metrics' => SystemRenderer::class,
        'admin_action' => SystemRenderer::class,
        'process_started' => SystemRenderer::class,
        'process_event' => SystemRenderer::class,
        'lifecycle_event' => SystemRenderer::class,
        'custom_event' => SystemRenderer::class,
        'action_scheduled' => SystemRenderer::class,

        // Analysis events -> AnalysisRenderer
        'refund_analysis' => AnalysisRenderer::class,
        'woocommerce_analysis' => AnalysisRenderer::class,
        'dedup' => AnalysisRenderer::class,
    ];

    return $renderers[$event_type] ?? FallbackRenderer::class;
}

/**
 * Get Component Theme
 *
 * Gets the theme identifier for a component type.
 * Used for consistent styling across components.
 *
 * @param string $event_type The event type to get theme for
 * @return string Theme identifier
 */
function odcm_get_component_theme(string $event_type): string
{
    // Handle hierarchical payment events: payment.{gateway}.{original_event_type}
    if (strpos($event_type, 'payment.') === 0) {
        return 'payment';
    }
    
    $themes = [
        // Process events use appropriate themes
        'rule_execution' => 'rule',
        'status_change_processing' => 'woocommerce',
        'manual_status_change' => 'woocommerce',

        // Rule events use rule theme
        'rule_matched' => 'rule',
        'rule_no_match' => 'rule',
        'rule_evaluated' => 'rule',
        'rule_evaluation' => 'rule',
        'condition_passed' => 'rule',
        'condition_failed' => 'rule',
        'action_executed' => 'rule',
        'decision' => 'rule',
        'validation' => 'rule',


        // Order events use woocommerce theme
        'status_changed' => 'woocommerce',
        'order_loaded' => 'woocommerce',
        'block_checkout_processed' => 'woocommerce',
        'checkout_processed' => 'woocommerce',  // UniversalEvent checkout events
        'meta_updated' => 'woocommerce',
        'woocommerce_data' => 'woocommerce',
        'no_rules_matched' => 'woocommerce',
        
        // Subscription events use woocommerce theme
        'subscription_created' => 'woocommerce',
        'subscription_approved' => 'woocommerce',
        'subscription_cancelled' => 'woocommerce',
        'subscription_suspended' => 'woocommerce',
        'subscription_reactivated' => 'woocommerce',
        'subscription_completed' => 'woocommerce',
        'subscription_expired' => 'woocommerce',
        'subscription_paused' => 'woocommerce',
        'subscription_resumed' => 'woocommerce',
        'subscription_updated' => 'woocommerce',
        'trial_ending' => 'woocommerce',
        'renewal_payment_completed' => 'woocommerce',
        'renewal_payment_failed' => 'woocommerce',
        'renewal_payment_processing' => 'woocommerce',
        'renewal_payment_pending' => 'woocommerce',

        // System events use system theme
        'info' => 'system',
        'warning' => 'system',
        'error' => 'system',
        'metrics' => 'system',
        'admin_action' => 'system',
        'process_started' => 'system',
        'process_event' => 'system',
        'lifecycle_event' => 'system',
        'custom_event' => 'system',
        'action_scheduled' => 'system',

        // Analysis events use context-specific themes
        'refund_analysis' => 'payment',
        'woocommerce_analysis' => 'woocommerce',
        'dedup' => 'system',
    ];

    return $themes[$event_type] ?? 'default';
}

/**
 * Get Status Pill Config
 *
 * Gets status pill configuration for an event type.
 * Used for consistent status indicators across components.
 *
 * @param string $event_type The event type to get status pill for
 * @return array|null Status pill config with 'label' and 'type', or null for no pill
 */
function odcm_get_status_pill_config(string $event_type): ?array
{
    $pills = [
        // Process events
        'rule_execution' => ['label' => 'RULE', 'type' => 'info'],
        'status_change_processing' => ['label' => 'STATUS', 'type' => 'woocommerce'],
        'manual_status_change' => ['label' => 'STATUS', 'type' => 'woocommerce'],

        // Rule events
        'rule_matched' => ['label' => 'MATCHED', 'type' => 'success'],
        'rule_no_match' => ['label' => 'NOT MATCHED', 'type' => 'notice'],
        'condition_passed' => ['label' => 'PASSED', 'type' => 'success'],
        'condition_failed' => ['label' => 'FAILED', 'type' => 'warning'],
        'action_executed' => ['label' => 'EXECUTED', 'type' => 'info'],


        // Order events
        'status_changed' => null, // Dynamic based on status
        'order_loaded' => ['label' => 'LOADED', 'type' => 'info'],
        'block_checkout_processed' => ['label' => 'CHECKOUT', 'type' => 'woocommerce'],
        'checkout_processed' => ['label' => 'CHECKOUT', 'type' => 'woocommerce'],  // UniversalEvent checkout events
        'meta_updated' => ['label' => 'UPDATED', 'type' => 'info'],
        'woocommerce_data' => ['label' => 'WOOCOMMERCE', 'type' => 'woocommerce'],
        
        // Subscription events
        'subscription_created' => ['label' => 'CREATED', 'type' => 'success'],
        'subscription_approved' => ['label' => 'APPROVED', 'type' => 'success'],
        'subscription_cancelled' => ['label' => 'CANCELLED', 'type' => 'error'],
        'subscription_suspended' => ['label' => 'SUSPENDED', 'type' => 'warning'],
        'subscription_reactivated' => ['label' => 'REACTIVATED', 'type' => 'success'],
        'subscription_completed' => ['label' => 'COMPLETED', 'type' => 'success'],
        'subscription_expired' => ['label' => 'EXPIRED', 'type' => 'error'],
        'subscription_paused' => ['label' => 'PAUSED', 'type' => 'warning'],
        'subscription_resumed' => ['label' => 'RESUMED', 'type' => 'success'],
        'subscription_updated' => ['label' => 'UPDATED', 'type' => 'info'],
        'trial_ending' => ['label' => 'TRIAL ENDING', 'type' => 'warning'],
        'renewal_payment_completed' => ['label' => 'RENEWAL PAID', 'type' => 'success'],
        'renewal_payment_failed' => ['label' => 'RENEWAL FAILED', 'type' => 'error'],
        'renewal_payment_processing' => ['label' => 'PROCESSING', 'type' => 'info'],
        'renewal_payment_pending' => ['label' => 'PENDING', 'type' => 'warning'],

        // System events
        'info' => ['label' => 'INFO', 'type' => 'info'],
        'warning' => ['label' => 'WARNING', 'type' => 'warning'],
        'error' => ['label' => 'ERROR', 'type' => 'error'],
        'metrics' => ['label' => 'METRICS', 'type' => 'info'],
        'admin_action' => ['label' => 'ADMIN', 'type' => 'notice'],
        'process_started' => ['label' => 'STARTED', 'type' => 'info'],
        'action_scheduled' => ['label' => 'SCHEDULED', 'type' => 'pending'],

        // Analysis events
        'refund_analysis' => ['label' => 'ANALYSIS', 'type' => 'warning'],
        'woocommerce_analysis' => ['label' => 'IMPACT', 'type' => 'woocommerce'],
        'dedup' => ['label' => 'DEDUP', 'type' => 'debug'],
    ];

    return $pills[$event_type] ?? null;
}

/**
 * Get Component Label
 *
 * Gets human-readable label for a component type.
 * Used for consistent labeling across components.
 *
 * @param string $event_type The event type to get label for
 * @return string Human-readable label
 */
function odcm_get_component_label(string $event_type): string
{
    $labels = [
        // Rule events
        'rule_matched' => 'Rule Matched',
        'rule_no_match' => 'Rule Not Matched',
        'rule_evaluated' => 'Rule Evaluated',
        'rule_evaluation' => 'Rule Evaluation',
        'condition_passed' => 'Condition Passed',
        'condition_failed' => 'Condition Failed',
        'action_executed' => 'Action Executed',
        'decision' => 'Decision',
        'validation' => 'Validation',


        // Order events
        'status_changed' => 'Status Changed',
        'order_loaded' => 'Order Loaded',
        'block_checkout_processed' => 'Checkout Processed (Block)',
        'checkout_processed' => 'Checkout Completed',  // UniversalEvent checkout events
        'meta_updated' => 'Meta Updated',
        'woocommerce_data' => 'WooCommerce Data',
        
        // Subscription events
        'subscription_created' => 'Subscription Created',
        'subscription_approved' => 'Subscription Approved',
        'subscription_cancelled' => 'Subscription Cancelled',
        'subscription_suspended' => 'Subscription Suspended',
        'subscription_reactivated' => 'Subscription Reactivated',
        'subscription_completed' => 'Subscription Completed',
        'subscription_expired' => 'Subscription Expired',
        'subscription_paused' => 'Subscription Paused',
        'subscription_resumed' => 'Subscription Resumed',
        'subscription_updated' => 'Subscription Updated',
        'trial_ending' => 'Trial Ending Soon',
        'renewal_payment_completed' => 'Renewal Payment Completed',
        'renewal_payment_failed' => 'Renewal Payment Failed',
        'renewal_payment_processing' => 'Renewal Payment Processing',
        'renewal_payment_pending' => 'Renewal Payment Pending',

        // System events
        'info' => 'Info',
        'warning' => 'Warning',
        'error' => 'Error',
        'metrics' => 'Performance Metrics',
        'admin_action' => 'Admin Action',
        'process_started' => 'Process Started',
        'process_event' => 'Process Event',
        'lifecycle_event' => 'Lifecycle Event',
        'custom_event' => 'Custom Event',
        'action_scheduled' => 'Action Scheduled',

        // Analysis events
        'refund_analysis' => 'Refund Analysis',
        'woocommerce_analysis' => 'Order Impact Analysis',
        'dedup' => 'Deduplication Analysis',
    ];

    return $labels[$event_type] ?? ucwords(str_replace('_', ' ', $event_type));
}
