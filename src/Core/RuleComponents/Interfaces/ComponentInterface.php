<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces;

/**
 * Interface for all Rule Builder components (Triggers, Conditions, Actions).
 *
 * This interface defines the basic contract that every component in the
 * rule builder must follow. It ensures that each component can provide
 * the necessary metadata for the UI and the backend to function correctly.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces
 * @since   1.0.0
 */
interface ComponentInterface
{
    /**
     * Get the unique identifier for the component.
     *
     * This ID is used in the API, the database, and the frontend.
     * It should be a simple, lowercase, snake_case string.
     *
     * @return string The unique component ID.
     */
    public function get_id(): string;

    /**
     * Get the human-readable label for the component.
     *
     * This label is displayed in the UI. It should be translatable.
     *
     * @return string The display label.
     */
    public function get_label(): string;

    /**
     * Get the detailed description for the component.
     *
     * This description is used as help text in the UI to explain
     * what the component does. It should be translatable.
     *
     * @return string The component description.
     */
    public function get_description(): string;

    /**
     * Get the capability required to use this component.
     *
     * This integrates with the entitlement system. The UI will check this
     * capability using odcm_can_use() before displaying the component.
     *
     * @return string The capability key.
     */
    public function get_capability(): string;

    /**
     * Get the JSON Schema for the component's settings.
     *
     * This schema defines the structure of the settings form that will be
     * dynamically rendered on the frontend. Returning an empty array
     * or null indicates that the component has no settings.
     *
     * @return array|null The JSON schema as a PHP array, or null if no settings.
     */
    public function get_settings_schema(): ?array;
}
