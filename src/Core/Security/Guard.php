<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

/**
 * Core security guard interface.
 *
 * This interface defines the contract for all security guards in the Order Daemon
 * security system. Guards implement specific security checks (nonce verification,
 * capability checks, etc.) and can be composed together for complex security requirements.
 *
 * The guard system implements the Strategy Pattern, allowing for flexible and
 * extensible security verification while maintaining clean separation of concerns.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   1.0.0
 */
interface Guard {
    /**
     * Verify the security constraint.
     *
     * This method performs the actual security verification. If the verification
     * fails, it must throw a SecurityException with appropriate context information.
     * If verification succeeds, the method should return normally (void).
     *
     * @throws SecurityException When verification fails
     * @since 1.0.0
     */
    public function verify(): void;
}
