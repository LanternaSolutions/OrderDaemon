<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

/**
 * WordPress capability verification guard.
 *
 * This guard implements authorization by verifying WordPress user capabilities.
 * It supports both general capabilities and object-specific capabilities,
 * providing fine-grained access control for plugin operations.
 *
 * The guard uses WordPress's built-in current_user_can() function to ensure
 * compatibility with WordPress role and capability system.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   2.1.1
 */
class CapabilityGuard implements Guard {
    /**
     * The required WordPress capability.
     *
     * @var string
     * @since 1.0.0
     */
    private string $capability;

    /**
     * Context description for logging purposes.
     *
     * @var string
     * @since 1.0.0
     */
    private string $context;

    /**
     * Optional object ID for object-specific capability checks.
     *
     * @var int|null
     * @since 1.0.0
     */
    private ?int $object_id;

    /**
     * Construct a new CapabilityGuard.
     *
     * @param string   $capability The required WordPress capability
     * @param string   $context    Context description for logging (default: 'general')
     * @param int|null $object_id  Optional object ID for object-specific capabilities
     * @since 1.0.0
     */
    public function __construct(string $capability, string $context = 'general', ?int $object_id = null) {
        $this->capability = $capability;
        $this->context = $context;
        $this->object_id = $object_id;
    }

    /**
     * Verify the WordPress capability.
     *
     * This method performs authorization by checking if the current user has
     * the required capability. For object-specific capabilities, it includes
     * the object ID in the capability check.
     *
     * @throws SecurityException When capability verification fails
     * @since 1.0.0
     */
    public function verify(): void {
        $has_capability = $this->object_id !== null
            ? current_user_can($this->capability, $this->object_id)
            : current_user_can($this->capability);

        if (!$has_capability) {
            throw new SecurityException('Insufficient permissions', [
                'required_capability' => $this->capability,
                'context' => $this->context,
                'object_id' => $this->object_id,
                'user_id' => get_current_user_id()
            ]);
        }
    }

    /**
     * Get the required capability.
     *
     * @return string The required WordPress capability
     * @since 1.0.0
     */
    public function getCapability(): string {
        return $this->capability;
    }

    /**
     * Get the context description.
     *
     * @return string The context description
     * @since 1.0.0
     */
    public function getContext(): string {
        return $this->context;
    }

    /**
     * Get the object ID for object-specific capability checks.
     *
     * @return int|null The object ID, or null for general capabilities
     * @since 1.0.0
     */
    public function getObjectId(): ?int {
        return $this->object_id;
    }
}
