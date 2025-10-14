<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics;

/**
 * Diagnostic Interface - Contract for All Diagnostic Tests
 *
 * This interface defines the standard contract that all diagnostic tests
 * must implement. It ensures consistent behavior across all diagnostic
 * implementations.
 *
 * @package OrderDaemon\CompletionManager\Diagnostics
 */
interface DiagnosticInterface
{
    /**
     * Get the human-readable name of this diagnostic test
     *
     * @return string The diagnostic test name
     */
    public function get_name(): string;

    /**
     * Get a detailed description of what this diagnostic test checks
     *
     * @return string The diagnostic test description
     */
    public function get_description(): string;

    /**
     * Run the diagnostic test and return the result
     *
     * @return DiagnosticResult The test result
     */
    public function run(): DiagnosticResult;

    /**
     * Get the category this diagnostic belongs to
     *
     * @return string The category (core, api, performance, frontend)
     */
    public function get_category(): string;

    /**
     * Get the priority level of this diagnostic (higher = more critical)
     *
     * @return int Priority level (1-10, where 10 is most critical)
     */
    public function get_priority(): int;

    /**
     * Check if this diagnostic requires the core plugin to be active
     *
     * @return bool True if core plugin is required
     */
    public function requires_core_plugin(): bool;
}
