<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

/**
 * WordPress nonce verification guard.
 *
 * This guard implements CSRF protection by verifying WordPress nonces.
 * It supports both form submissions and AJAX requests, providing protection
 * against cross-site request forgery attacks.
 *
 * The guard uses WordPress's built-in wp_verify_nonce() function to ensure
 * compatibility with WordPress security standards and practices.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   1.0.0
 */
class NonceGuard implements Guard {
    /**
     * The nonce value to verify.
     *
     * @var string
     * @since 1.0.0
     */
    private string $nonce;

    /**
     * The nonce action name.
     *
     * @var string
     * @since 1.0.0
     */
    private string $action;

    /**
     * Whether this is an AJAX context.
     *
     * @var bool
     * @since 1.0.0
     */
    private bool $ajax_context;

    /**
     * Construct a new NonceGuard.
     *
     * @param string $nonce        The nonce value to verify
     * @param string $action       The nonce action name
     * @param bool   $ajax_context Whether this is an AJAX request (default: false)
     * @since 1.0.0
     */
    public function __construct(string $nonce, string $action, bool $ajax_context = false) {
        $this->nonce = $nonce;
        $this->action = $action;
        $this->ajax_context = $ajax_context;
    }

    /**
     * Verify the WordPress nonce.
     *
     * This method performs CSRF protection by verifying the provided nonce
     * against the expected action using WordPress's wp_verify_nonce() function.
     *
     * @throws SecurityException When nonce verification fails
     * @since 1.0.0
     */
    public function verify(): void {
        if (empty($this->nonce)) {
            throw new SecurityException('Nonce not provided', [
                'action' => $this->action,
                'context' => $this->ajax_context ? 'ajax' : 'form'
            ]);
        }

        $unslashed_nonce = wp_unslash($this->nonce);
        $sanitized_nonce = sanitize_text_field($unslashed_nonce);
        
        $is_verified = wp_verify_nonce($sanitized_nonce, $this->action);
        if (!$is_verified) {
            throw new SecurityException('Invalid nonce', [
                'action' => $this->action,
                'context' => $this->ajax_context ? 'ajax' : 'form',
                'nonce_length' => strlen($sanitized_nonce)
            ]);
        }
    }

    /**
     * Get the nonce action name.
     *
     * @return string The nonce action name
     * @since 1.0.0
     */
    public function getAction(): string {
        return $this->action;
    }

    /**
     * Check if this is an AJAX context.
     *
     * @return bool True if this is an AJAX context, false otherwise
     * @since 1.0.0
     */
    public function isAjaxContext(): bool {
        return $this->ajax_context;
    }
}
