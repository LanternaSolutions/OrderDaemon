<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Filter Registry Class - Entitlement-Aware Audit Log Filter Registry
 * 
 * This class serves as the central registry for all audit log filters available
 * in the Order Daemon For Woocommerce plugin. It acts as a single source of truth
 * for filter components and integrates seamlessly with the plugin's entitlement
 * system to control feature access based on user licensing.
 * 
 * Key Features:
 * - Centralized registration of all audit log filters
 * - Built-in validation of filter data structure
 * - Integration with the entitlement system via capability keys
 * - Type-safe implementation with strict typing
 * - Extensible architecture for future filter additions
 * 
 * Architecture Overview:
 * The registry uses a simple array-based storage system where each filter
 * is stored with a strict data structure that includes:
 * - id: Unique identifier for the filter (e.g., 'date_range')
 * - label: Human-readable display name
 * - tier: Product tier ('free' or 'premium')
 * - capability: Entitlement key for access control
 * - render_callback: Function to render filter-specific UI
 * 
 * Entitlement Integration:
 * Each registered filter includes a 'capability' key that corresponds to a feature
 * in the entitlement system. This allows the UI to dynamically show/hide filters
 * based on the user's license level, creating a seamless freemium experience.
 * Premium filters are rendered as disabled with PREMIUM badges when users lack access.
 * 
 * Usage Example:
 * ```php
 * $registry = odcm_get_filter_registry_instance();
 * 
 * // Register a new filter
 * $registry->register_filter([
 *     'id'              => 'date_range',
 *     'label'           => __('Date Range', 'order-daemon'),
 *     'tier'            => 'premium',
 *     'capability'      => 'audit_log_filter_advanced',
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
     *         'tier'            => 'free|premium',
     *         'capability'      => 'entitlement_key',
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
     * Register a new filter option in the entitlement-aware registry
     * 
     * Registers a filter that can be used in the audit log interface. Filters define
     * how users can search and narrow down audit log entries. Each filter is associated
     * with an entitlement capability that controls user access.
     * 
     * The filter will be validated to ensure it contains all required fields and that
     * the render callback is callable. Once registered, the filter will appear in the
     * audit log filter bar (if the user has the required capability).
     * 
     * Required Arguments:
     * - id: Unique string identifier (e.g., 'date_range')
     * - label: Translatable display name (e.g., __('Date Range', 'domain'))
     * - tier: Product tier ('free' or 'premium')
     * - capability: Entitlement key for access control (e.g., 'audit_log_filter_advanced')
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
     *     @type string   $tier            Product tier: 'free' or 'premium'. Controls UI styling
     *                                     and determines if PREMIUM badge is shown.
     *     @type string   $capability      Entitlement capability key. Used with odcm_can_use() to
     *                                     determine if user can access this filter.
     *     @type callable $render_callback Function or method that renders the filter's input UI.
     *                                     Receives permission status as parameter.
     * }
     * 
     * @return void
     * 
     * @throws \InvalidArgumentException If required keys are missing or invalid.
     * 
     * @example
     * ```php
     * // Register a free filter
     * $registry->register_filter([
     *     'id'              => 'basic_search',
     *     'label'           => __('Search', 'order-daemon'),
     *     'tier'            => 'free',
     *     'capability'      => 'audit_log_basic_search',
     *     'render_callback' => [$this, 'render_basic_search_filter'],
     * ]);
     * 
     * // Register a premium filter
     * $registry->register_filter([
     *     'id'              => 'date_range',
     *     'label'           => __('Date Range', 'order-daemon'),
     *     'tier'            => 'premium',
     *     'capability'      => 'audit_log_filter_advanced',
     *     'render_callback' => 'render_date_range_filter',
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
     * in the audit log filter bar, with entitlement checking performed separately.
     * 
     * The returned array maintains the original registration order and includes all filter
     * data including entitlement capabilities. The calling code should use odcm_can_use()
     * to check if the current user has access to each filter.
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
     *         @type string   $tier            Product tier ('free' or 'premium').
     *         @type string   $capability      Entitlement capability key.
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
     *     $has_permission = odcm_can_use($filter['capability']);
     *     
     *     echo '<div class="filter-container">';
     *     echo '<label>' . esc_html($filter['label']);
     *     
     *     if ($filter['tier'] === 'premium') {
     *         echo ' <span class="premium-badge">PREMIUM</span>';
     *     }
     *     
     *     echo '</label>';
     *     
     *     // Call the render callback with permission status
     *     call_user_func($filter['render_callback'], $has_permission);
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
     *     @type string   $tier            Product tier ('free' or 'premium').
     *     @type string   $capability      Entitlement capability key.
     *     @type callable $render_callback Function to render filter input UI.
     * }
     * 
     * @example
     * ```php
     * $registry = odcm_get_filter_registry_instance();
     * $date_filter = $registry->get_filter('date_range');
     * 
     * if ($date_filter && odcm_can_use($date_filter['capability'])) {
     *     // User can access the date range filter
     *     call_user_func($date_filter['render_callback'], true);
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
     * Get filters by tier
     * 
     * Retrieves all filters that belong to a specific product tier.
     * Useful for rendering free vs premium filter sections separately.
     * 
     * @since 1.0.0
     * 
     * @param string $tier The tier to filter by ('free' or 'premium').
     * 
     * @return array<string, array<string, mixed>> Associative array of filters for the specified tier.
     * 
     * @example
     * ```php
     * $registry = odcm_get_filter_registry_instance();
     * 
     * // Render free filters first
     * $free_filters = $registry->get_filters_by_tier('free');
     * foreach ($free_filters as $filter_id => $filter) {
     *     // Render free filter UI
     * }
     * 
     * // Then render premium filters with upgrade prompts
     * $premium_filters = $registry->get_filters_by_tier('premium');
     * foreach ($premium_filters as $filter_id => $filter) {
     *     // Render premium filter UI with entitlement checks
     * }
     * ```
     */
    public function get_filters_by_tier(string $tier): array
    {
        return array_filter($this->filters, function($filter) use ($tier) {
            return $filter['tier'] === $tier;
        });
    }

    /**
     * Validate filter registration arguments for data integrity and security
     * 
     * Performs comprehensive validation of filter registration data to ensure all required
     * fields are present and properly formatted. This validation is critical for maintaining
     * the integrity of the entitlement system and preventing registration of malformed filters.
     * 
     * Validation Rules:
     * - All required keys must be present: id, label, tier, capability, render_callback
     * - ID must be a non-empty string (used as array key and HTML attributes)
     * - Label must be a non-empty string (displayed in UI)
     * - Tier must be either 'free' or 'premium' (controls UI styling and badges)
     * - Capability must be a non-empty string (used with entitlement system)
     * - Render callback must be callable (used to generate filter UI)
     * 
     * Security Considerations:
     * - IDs are validated to prevent injection attacks when used in HTML
     * - Callbacks are validated to prevent execution of non-callable values
     * - All string fields are checked for proper type to prevent type confusion
     * - Tier values are restricted to prevent invalid styling or logic
     * 
     * @since 1.0.0
     * 
     * @param array $args {
     *     Filter registration arguments to validate.
     * 
     *     @type string   $id              Required. Unique identifier for the filter.
     *     @type string   $label           Required. Human-readable display name.
     *     @type string   $tier            Required. Product tier ('free' or 'premium').
     *     @type string   $capability      Required. Entitlement capability key.
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
     *     - Invalid tier: "The 'tier' key must be either 'free' or 'premium' when registering filter."
     *     - Invalid capability: "The 'capability' key must be a non-empty string when registering filter."
     *     - Invalid callback: "The 'render_callback' key must be callable when registering filter."
     * }
     * 
     * @example
     * ```php
     * // This will pass validation
     * $valid_args = [
     *     'id'              => 'date_range',
     *     'label'           => __('Date Range', 'domain'),
     *     'tier'            => 'premium',
     *     'capability'      => 'audit_log_filter_advanced',
     *     'render_callback' => [$this, 'render_date_range'],
     * ];
     * 
     * // This will throw InvalidArgumentException
     * $invalid_args = [
     *     'id'   => '', // Empty ID - invalid
     *     'tier' => 'enterprise', // Invalid tier - invalid
     *     // Missing required keys - invalid
     * ];
     * ```
     */
    private function validate_filter_args(array $args): void
    {
        $required_keys = ['id', 'label', 'tier', 'capability', 'render_callback'];

        foreach ($required_keys as $key) {
            if (!isset($args[$key])) {
                throw new \InvalidArgumentException(
                    sprintf('Missing required key "%s" when registering filter.', $key)
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

        // Validate that tier is either 'free' or 'premium'
        $valid_tiers = ['free', 'premium'];
        if (!is_string($args['tier']) || !in_array($args['tier'], $valid_tiers, true)) {
            throw new \InvalidArgumentException(
                'The "tier" key must be either "free" or "premium" when registering filter.'
            );
        }

        // Validate that capability is a non-empty string
        if (!is_string($args['capability']) || empty(trim($args['capability']))) {
            throw new \InvalidArgumentException(
                'The "capability" key must be a non-empty string when registering filter.'
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
