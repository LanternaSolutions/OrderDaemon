<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

use OrderDaemon\CompletionManager\Includes\AuditTrailLogger;
use OrderDaemon\CompletionManager\Core\AttributionTracker;

/**
 * Security guard verification service with audit logging.
 *
 * This service provides centralized security verification with comprehensive
 * audit logging capabilities. It executes guard verification and automatically
 * logs all security events for compliance and debugging purposes.
 *
 * The service enriches security events with user context, request information,
 * and performance metrics to provide complete audit trails.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   2.1.1
 */
class GuardChecker {
    /**
     * Audit trail logger instance.
     *
     * @var AuditTrailLogger
     * @since 1.0.0
     */
    private AuditTrailLogger $logger;

    /**
     * Attribution tracker for IP detection.
     *
     * @var AttributionTracker|null
     * @since 1.0.0
     */
    private ?AttributionTracker $attribution_tracker;

    /**
     * Construct a new GuardChecker.
     *
     * @param AuditTrailLogger $logger The audit trail logger instance
     * @since 1.0.0
     */
    public function __construct(AuditTrailLogger $logger) {
        $this->logger = $logger;
        // Initialize attribution tracker safely
        try {
            $this->attribution_tracker = AttributionTracker::instance();
        } catch (\Throwable $e) {
            // If AttributionTracker fails, we'll handle it in the methods that use it
            $this->attribution_tracker = null;
        }
    }

    /**
     * Check security guards and log the result.
     *
     * This method executes the provided guard's verification and automatically
     * logs the security event to the audit trail. Both successful and failed
     * verifications are logged with comprehensive context information.
     *
     * @param Guard $guard   The guard(s) to check
     * @param array $context Additional context for logging
     * @throws SecurityException When any guard fails
     * @since 1.0.0
     */
    public function check(Guard $guard, array $context = []): void {
        $start_time = microtime(true);

        try {
            $guard->verify();

            $execution_time = (microtime(true) - $start_time) * 1000; // Convert to milliseconds

            // Log successful verification
            $this->logger->log(
                'security_check_passed',
                'security',
                'Security verification successful',
                array_merge($context, [
                    'guard_type' => get_class($guard),
                    'guard_details' => $this->extractGuardDetails($guard),
                    'execution_time_ms' => round($execution_time, 2),
                    'user_context' => $this->getUserContext(),
                    'request_context' => $this->getRequestContext()
                ])
            );

        } catch (SecurityException $e) {
            $execution_time = (microtime(true) - $start_time) * 1000;

            // Log security failure with comprehensive context
            $this->logger->log(
                'security_check_failed',
                'error',
                'Security verification failed: ' . $e->getMessage(),
                array_merge($context, [
                    'guard_type' => get_class($guard),
                    'guard_details' => $this->extractGuardDetails($guard),
                    'error_context' => $e->getContext(),
                    'execution_time_ms' => round($execution_time, 2),
                    'user_context' => $this->getUserContext(),
                    'request_context' => $this->getRequestContext(),
                    'stack_trace' => $e->getTraceAsString()
                ])
            );

            // Re-throw for handling by calling code
            throw $e;
        }
    }

    /**
     * Extract guard-specific details for logging.
     *
     * This method extracts relevant information from different guard types
     * to provide meaningful context in audit logs.
     *
     * @param Guard $guard The guard to extract details from
     * @return array Guard-specific details
     * @since 1.0.0
     */
    private function extractGuardDetails(Guard $guard): array {
        $details = ['type' => get_class($guard)];

        if ($guard instanceof NonceGuard) {
            $details['action'] = $guard->getAction();
            $details['ajax_context'] = $guard->isAjaxContext();
        } elseif ($guard instanceof CapabilityGuard) {
            $details['capability'] = $guard->getCapability();
            $details['context'] = $guard->getContext();
            $details['object_id'] = $guard->getObjectId();
        } elseif ($guard instanceof CompositeGuard) {
            $details['guard_count'] = $guard->getGuardCount();
            $details['guard_types'] = array_map('get_class', $guard->getGuards());
        }

        return $details;
    }

    /**
     * Get user context information for logging.
     *
     * This method collects relevant user information for security audit logs,
     * including user ID, roles, and IP address.
     *
     * @return array User context information
     * @since 1.0.0
     */
    private function getUserContext(): array {
        return [
            'user_id' => get_current_user_id(),
            'user_roles' => is_user_logged_in() ? wp_get_current_user()->roles : [],
            'ip_address' => $this->attribution_tracker ? $this->attribution_tracker->detect_ip() : 'unknown'
        ];
    }

    /**
     * Get request context information for logging.
     *
     * This method collects relevant request information for security audit logs,
     * including HTTP method, user agent, referer, and request URI.
     *
     * @return array Request context information
     * @since 1.0.0
     */
    private function getRequestContext(): array {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referer' => esc_url_raw($_SERVER['HTTP_REFERER'] ?? ''),
            'request_uri' => esc_url_raw($_SERVER['REQUEST_URI'] ?? '')
        ];
    }
}
