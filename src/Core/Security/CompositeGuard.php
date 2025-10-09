<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

/**
 * Composite guard for combining multiple security guards.
 *
 * This guard implements the Composite Pattern, allowing multiple security
 * guards to be combined into a single verification unit. All contained
 * guards must pass verification for the composite guard to succeed.
 *
 * This enables complex security requirements to be built from simple,
 * testable components while maintaining a clean interface.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   1.0.0
 */
class CompositeGuard implements Guard {
    /**
     * Array of guards to verify.
     *
     * @var Guard[]
     * @since 1.0.0
     */
    private array $guards;

    /**
     * Construct a new CompositeGuard.
     *
     * @param Guard ...$guards Variable number of guards to combine
     * @since 1.0.0
     */
    public function __construct(Guard ...$guards) {
        $this->guards = $guards;
    }

    /**
     * Verify all contained guards.
     *
     * This method verifies each contained guard in sequence. If any guard
     * fails verification, the method immediately throws a SecurityException
     * and does not continue with remaining guards.
     *
     * @throws SecurityException When any contained guard fails verification
     * @since 1.0.0
     */
    public function verify(): void {
        foreach ($this->guards as $guard) {
            $guard->verify();
        }
    }

    /**
     * Get all contained guards.
     *
     * @return Guard[] Array of contained guards
     * @since 1.0.0
     */
    public function getGuards(): array {
        return $this->guards;
    }

    /**
     * Get the number of contained guards.
     *
     * @return int The number of guards in this composite
     * @since 1.0.0
     */
    public function getGuardCount(): int {
        return count($this->guards);
    }
}
