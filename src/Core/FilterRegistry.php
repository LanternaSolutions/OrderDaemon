<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Filter Registry Class - Audit Log Filter Registry
 *
 * This class serves as the central registry for all audit log filters available
 * in the Order Daemon For Woocommerce plugin. It acts as a single source of truth
 * for filter components.
 *
 * Key Features:
 * - Centralized registration of all audit log filters
 * - Built-in validation of filter data structure
 * - Type-safe implementation with strict typing
 * - Extensible architecture for future filter additions
 *
 * Architecture Overview:
 * The registry uses a simple array-based storage system where each filter
 * is stored with a strict data structure that includes:
 * - id: Unique identifier for the filter (e.g., 'date_range')
 * - label: Human-readable display name
 * - render_callback: Function to render filter-specific UI
 *
 * Usage Example:
 * ```php
 * $registry = odcm_get_filter_registry_instance();
 *
 * // Register a new filter
 * $registry->register_filter([
 *     'id'              => 'date_range',
 *     'label'           => __('Date Range', 'order-daemon'),
 *     'render_callback' => 'render_date_range_filter',
 * ]);
 *
 * // Retrieve all filters
 * $filters = $registry->get_filters();
 * ```
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/audit-log-filters
 */
final class FilterRegistry
{
    /**
     * Registered filter options
     *
     * Stores all registered filter options that define how audit log entries
     * can be filtered and searched. Filters provide advanced search capabilities
     * for the audit log interface.
     *
     * Array structure:
     * ```php
     * [
     *     'filter_id' => [
     *         'id'              => 'filter_id',
     *         'label'           => 'Human Readable Name',
     *         'render_callback' => callable,
     *     ],
     *     // ... more filters
     * ]
     * ```
     *
     * @since 1.0.0
     * @var   array<string, array<string, mixed>> Associative array of filter options keyed by ID
     */
    private $filters = [];

    /**
     * Register a new filter option
     *
     * Registers a filter that can be used in the audit log interface. Filters define
     * how users can search and narrow down audit log entries.
     *
     * The filter will be validated to ensure it contains all required fields and that
     * the render callback is callable. Once registered, the filter will appear in the
     * audit log filter bar.
     *
     * Required Arguments:
     * - id: Unique string identifier (e.g., 'date_range')
     * - label: Translatable display name (e.g., __('Date Range', 'domain'))
     * - render_callback: Callable that renders the filter's input UI
     *
     * @since 1.0.0
     *
     * @param array $args {
     *     Filter registration arguments.
     *
     *     @type string   $id              Unique identifier for the filter. Must be unique across
     *                                     all filters. Use lowercase with underscores.
     *     @type string   $label           Human-readable label displayed in the UI. Should be
     *                                     internationalized using __() function.
     *     @type callable $render_callback Function or method that renders the filter's input UI.
     * }
     *
     * @return void
     *
     * @throws \InvalidArgumentException If required keys are missing or invalid.
     *
     * @example
     * ```php
     * // Register a filter
     * $registry->register_filter([
     *     'id'              => 'basic_search',
     *     'label'           => __('Search', 'order-daemon'),
     *     'render_callback' => [$this, 'render_basic_search_filter'],
     * ]);
     * ```
     */
    public function register_filter(array $args): void
    {
        $this->validate_filter_args($args);
        $this->filters[$args['id']] = $args;
    }

    /**
     * Retrieve all registered filter options
     *
     * Returns an associative array of all registered filters, keyed by their unique IDs.
     * This method is typically used by the UI rendering system to display available filters
     * in the log filter bar.
     *
     * The returned array maintains the original registration order and includes all filter data.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> {
     *     Associative array of filter options keyed by filter ID.
     *
     *     @type array $filter_id {
     *         Individual filter data.
     *
     *         @type string   $id              Unique filter identifier.
     *         @type string   $label           Human-readable filter name.
     *         @type callable $render_callback Function to render filter input UI.
     *     }
     * }
     *
     * @example
     * ```php
     * $registry = odcm_get_filter_registry_instance();
     * $filters = $registry->get_filters();
     *
     * foreach ($filters as $filter_id => $filter) {
     *     echo '<div class="filter-container">';
     *     echo '<label>' . esc_html($filter['label']) . '</label>';
     *
     *     // Call the render callback
     *     call_user_func($filter['render_callback']);
     *
     *     echo '</div>';
     * }
     * ```
     */
    public function get_filters(): array
    {
        return $this->filters;
    }

    /**
     * Get a specific filter by ID
     *
     * Retrieves a single filter's registration data by its unique identifier.
     * Returns null if the filter is not found.
     *
     * @since 1.0.0
     *
     * @param string $filter_id The unique identifier of the filter to retrieve.
     *
     * @return array|null {
     *     Filter data array or null if not found.
     *
     *     @type string   $id              Unique filter identifier.
     *     @type string   $label           Human-readable filter name.
     *     @type callable $render_callback Function to render filter input UI.
     * }
     *
     * @example
     * ```php
     * $registry = odcm_get_filter_registry_instance();
     * $date_filter = $registry->get_filter('date_range');
     *
     * if ($date_filter) {
     *     // Use the date range filter
     *     call_user_func($date_filter['render_callback']);
     * }
     * ```
     */
    public function get_filter(string $filter_id): ?array
    {
        return $this->filters[$filter_id] ?? null;
    }

    /**
     * Check if a filter is registered
     * 
     * Determines whether a filter with the given ID has been registered.
     * 
     * @since 1.0.0
     * 
     * @param string $filter_id The unique identifier of the filter to check.
     * 
     * @return bool True if the filter is registered, false otherwise.
     * 
     * @example
     * ```php
     * $registry = odcm_get_filter_registry_instance();
     * 
     * if ($registry->has_filter('date_range')) {
     *     // Date range filter is available
     *     $filter = $registry->get_filter('date_range');
     * }
     * ```
     */
    public function has_filter(string $filter_id): bool
    {
        return isset($this->filters[$filter_id]);
    }

   
    /**
     * Validate filter registration arguments for data integrity and security
     *
     * Performs comprehensive validation of filter registration data to ensure all required
     * fields are present and properly formatted. This validation prevents registration of malformed filters.
     *
     * Validation Rules:
     * - All required keys must be present: id, label, render_callback
     * - ID must be a non-empty string (used as array key and HTML attributes)
     * - Label must be a non-empty string (displayed in UI)
     * - Render callback must be callable (used to generate filter UI)
     *
     * Security Considerations:
     * - IDs are validated to prevent injection attacks when used in HTML
     * - Callbacks are validated to prevent execution of non-callable values
     * - All string fields are checked for proper type to prevent type confusion
     *
     * @since 1.0.0
     *
     * @param array $args {
     *     Filter registration arguments to validate.
     *
     *     @type string   $id              Required. Unique identifier for the filter.
     *     @type string   $label           Required. Human-readable display name.
     *     @type callable $render_callback Required. Function to render filter input UI.
     * }
     *
     * @return void
     *
     * @throws \InvalidArgumentException {
     *     Thrown when validation fails with specific error details.
     *
     *     Possible error scenarios:
     *     - Missing required key: "Missing required key 'id' when registering filter."
     *     - Invalid ID: "The 'id' key must be a non-empty string when registering filter."
     *     - Invalid label: "The 'label' key must be a non-empty string when registering filter."
     *     - Invalid callback: "The 'render_callback' key must be callable when registering filter."
     * }
     *
     * @example
     * ```php
     * // This will pass validation
     * $valid_args = [
     *     'id'              => 'date_range',
     *     'label'           => __('Date Range', 'domain'),
     *     'render_callback' => [$this, 'render_date_range'],
     * ];
     *
     * // This will throw InvalidArgumentException
     * $invalid_args = [
     *     'id' => '', // Empty ID - invalid
     *     // Missing required keys - invalid
     * ];
     * ```
     */
    private function validate_filter_args(array $args): void
    {
        $required_keys = ['id', 'label', 'render_callback'];

        foreach ($required_keys as $key) {
            if (!isset($args[$key])) {
                throw new \InvalidArgumentException(
                    sprintf('Missing required key "%s" when registering filter.', esc_html($key))
                );
            }
        }

        // Validate that id is a non-empty string
        if (!is_string($args['id']) || empty(trim($args['id']))) {
            throw new \InvalidArgumentException(
                'The "id" key must be a non-empty string when registering filter.'
            );
        }

        // Validate that label is a non-empty string
        if (!is_string($args['label']) || empty(trim($args['label']))) {
            throw new \InvalidArgumentException(
                'The "label" key must be a non-empty string when registering filter.'
            );
        }

        // Validate that render_callback is callable
        if (!is_callable($args['render_callback'])) {
            throw new \InvalidArgumentException(
                'The "render_callback" key must be callable when registering filter.'
            );
        }
    }
}
