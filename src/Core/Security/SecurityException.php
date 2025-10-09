<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

/**
 * Security exception for guard system failures.
 *
 * This exception is thrown when security verification fails in the guard system.
 * It extends the standard PHP Exception class and adds contextual information
 * about the security failure for debugging and audit logging purposes.
 *
 * The context array can contain any relevant information about the security
 * failure, such as user details, request information, or specific guard data.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   1.0.0
 */
class SecurityException extends \Exception {
    /**
     * Additional context information about the security failure.
     *
     * @var array
     * @since 1.0.0
     */
    private array $context;

    /**
     * Construct a new SecurityException.
     *
     * @param string          $message  The exception message
     * @param array           $context  Additional context information about the failure
     * @param int             $code     The exception code (default: 0)
     * @param \Throwable|null $previous Previous exception for chaining (default: null)
     * @since 1.0.0
     */
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the additional context information about the security failure.
     *
     * @return array The context array containing additional failure information
     * @since 1.0.0
     */
    public function getContext(): array {
        return $this->context;
    }
}
