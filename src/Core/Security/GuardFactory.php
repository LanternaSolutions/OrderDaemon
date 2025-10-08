<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

/**
 * Factory for creating common guard combinations.
 *
 * This factory provides convenient methods for creating standard guard
 * combinations used throughout the plugin. It encapsulates common security
 * patterns and reduces code duplication while ensuring consistent security
 * implementations across different contexts.
 *
 * The factory supports form submissions, AJAX requests, REST API endpoints,
 * and other common WordPress security scenarios.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   2.1.1
 */
class GuardFactory {
    /**
     * Create guards for form submissions.
     *
     * This method creates a composite guard suitable for form submissions,
     * combining nonce verification and capability checking. This is the most
     * common security pattern for WordPress admin forms.
     *
     * @param string   $nonce      The nonce value from the form
     * @param string   $action     The nonce action name
     * @param string   $capability The required WordPress capability
     * @param int|null $object_id  Optional object ID for object-specific capabilities
     * @return CompositeGuard Combined guard for form security
     * @since 1.0.0
     */
    public static function createFormGuards(string $nonce, string $action, string $capability, ?int $object_id = null): CompositeGuard {
        return new CompositeGuard(
            new NonceGuard($nonce, $action, false),
            new CapabilityGuard($capability, 'form_submission', $object_id)
        );
    }

    /**
     * Create guards for AJAX requests.
     *
     * This method creates a composite guard suitable for AJAX requests,
     * combining nonce verification and capability checking with AJAX context.
     *
     * @param string   $nonce      The nonce value from the AJAX request
     * @param string   $action     The nonce action name
     * @param string   $capability The required WordPress capability
     * @param int|null $object_id  Optional object ID for object-specific capabilities
     * @return CompositeGuard Combined guard for AJAX security
     * @since 1.0.0
     */
    public static function createAjaxGuards(string $nonce, string $action, string $capability, ?int $object_id = null): CompositeGuard {
        return new CompositeGuard(
            new NonceGuard($nonce, $action, true),
            new CapabilityGuard($capability, 'ajax_request', $object_id)
        );
    }

    /**
     * Create guards for REST API endpoints.
     *
     * This method creates a composite guard suitable for REST API endpoints,
     * combining REST nonce verification and capability checking.
     *
     * @param string   $nonce      The REST nonce value (typically from X-WP-Nonce header)
     * @param string   $action     The nonce action name (typically 'wp_rest')
     * @param string   $capability The required WordPress capability
     * @param int|null $object_id  Optional object ID for object-specific capabilities
     * @return CompositeGuard Combined guard for REST API security
     * @since 1.0.0
     */
    public static function createRestGuards(string $nonce, string $action, string $capability, ?int $object_id = null): CompositeGuard {
        return new CompositeGuard(
            new NonceGuard($nonce, $action, false),
            new CapabilityGuard($capability, 'rest_api', $object_id)
        );
    }

    /**
     * Create a simple nonce guard.
     *
     * This method creates a standalone nonce guard for cases where only
     * CSRF protection is needed without capability checking.
     *
     * @param string $nonce        The nonce value to verify
     * @param string $action       The nonce action name
     * @param bool   $ajax_context Whether this is an AJAX context (default: false)
     * @return NonceGuard Nonce verification guard
     * @since 1.0.0
     */
    public static function createNonceGuard(string $nonce, string $action, bool $ajax_context = false): NonceGuard {
        return new NonceGuard($nonce, $action, $ajax_context);
    }

    /**
     * Create a simple capability guard.
     *
     * This method creates a standalone capability guard for cases where only
     * authorization is needed without nonce verification.
     *
     * @param string   $capability The required WordPress capability
     * @param string   $context    Context description for logging (default: 'general')
     * @param int|null $object_id  Optional object ID for object-specific capabilities
     * @return CapabilityGuard Capability verification guard
     * @since 1.0.0
     */
    public static function createCapabilityGuard(string $capability, string $context = 'general', ?int $object_id = null): CapabilityGuard {
        return new CapabilityGuard($capability, $context, $object_id);
    }

    /**
     * Create a composite guard from multiple guards.
     *
     * This method creates a composite guard from an array of individual guards,
     * useful for complex security requirements that don't fit standard patterns.
     *
     * @param Guard ...$guards Variable number of guards to combine
     * @return CompositeGuard Combined guard containing all provided guards
     * @since 1.0.0
     */
    public static function createCompositeGuard(Guard ...$guards): CompositeGuard {
        return new CompositeGuard(...$guards);
    }
}
