<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

use OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry;

/**
 * Centralized system for generating dynamic, friendly summaries of rule components.
 *
 * Summary format pattern:
 *   [Component Label] [Match Mode/Operation] [Selected Values/List]
 *
 * This class is intentionally generic and relies on component settings schema
 * to extract meaningful labels and values. Components may later provide
 * specialized logic by extending this behavior (future enhancement).
 *
 * @package OrderDaemon\CompletionManager\Admin
 * @since   1.0.0
 */
final class ComponentSummaryBuilder
{
    /**
     * @var RuleComponentRegistry
     */
    private RuleComponentRegistry $component_registry;

    /**
     * Constructor.
     *
     * @param RuleComponentRegistry|null $component_registry Optional registry override (mainly for tests).
     */
    public function __construct(?RuleComponentRegistry $component_registry = null)
    {
        $this->component_registry = $component_registry ?? new RuleComponentRegistry();
    }

    /**
     * Generate a friendly summary for any component.
     *
     * @param array  $component_data Component data with 'id' and optional 'settings'.
     * @param string $component_type One of: 'trigger', 'condition', 'action', 'primaryAction'.
     * @return string Sanitized HTML summary.
     */
    public function generate_summary(array $component_data, string $component_type): string
    {
        if (empty($component_data['id']) || !is_string($component_data['id'])) {
            return $this->get_fallback_summary($component_type);
        }

        $component_id = $component_data['id'];
        $component = $this->get_component($component_id, $component_type);
        if (!$component) {
            return $this->get_fallback_summary($component_type);
        }

        $settings = is_array($component_data['settings'] ?? null) ? $component_data['settings'] : [];
        
        // Merge settings with schema defaults to ensure match mode is always available
        $settings_with_defaults = $this->merge_settings_with_defaults($component, $settings);

        $title = method_exists($component, 'get_label') ? (string) $component->get_label() : '';
        $mode  = $this->extract_match_mode($settings_with_defaults);
        $details = $this->extract_details_from_schema($component, $component_id, $settings_with_defaults);

        // Allow light per-component post-processing for more natural sentences
        $custom = $this->apply_component_specific_phrasing($component_id, $title, $mode, $details, $settings_with_defaults, $component_type);
        if ($custom !== null) {
            return $this->sanitize_summary_html($custom);
        }

        return $this->build_summary_html([
            'title' => $title,
            'match_mode' => $mode,
            'details' => $details,
        ]);
    }

    /**
     * Merge user settings with component schema defaults.
     *
     * @param object $component Component instance providing get_settings_schema().
     * @param array  $settings  User-provided settings.
     * @return array Settings merged with schema defaults.
     */
    private function merge_settings_with_defaults(object $component, array $settings): array
    {
        if (!method_exists($component, 'get_settings_schema')) {
            return $settings;
        }

        $schema = $component->get_settings_schema();
        if (!is_array($schema) || !isset($schema['properties']) || !is_array($schema['properties'])) {
            return $settings;
        }

        $merged = $settings;
        
        // Apply defaults from schema properties
        foreach ($schema['properties'] as $key => $property) {
            if (!array_key_exists($key, $merged) && isset($property['default'])) {
                $merged[$key] = $property['default'];
            }
        }

        return $merged;
    }

    /**
     * Extract match mode description from settings using common keys.
     *
     * @param array $settings Component settings.
     * @return string Match mode phrase like "is only", "includes", etc.
     */
    private function extract_match_mode(array $settings): string
    {
        // Support multiple keys that imply mode-like semantics
        if (isset($settings['match_type'])) {
            // Used by ProductCategoryCondition
            $val = (string) $settings['match_type'];
            $map = [
                'any' => __('component.operator.includes', 'order-daemon'),
                'all' => __('component.operator.includes_all', 'order-daemon'),
            ];
            return $map[$val] ?? '';
        }

        $match_mode = '';
        if (isset($settings['match_mode'])) {
            $match_mode = (string) $settings['match_mode'];
        } elseif (isset($settings['operator'])) { // alternative naming used in some components
            $match_mode = (string) $settings['operator'];
        }

        $mode_map = [
            'all'           => __('component.operator.is_only', 'order-daemon'),
            'any'           => __('component.operator.includes', 'order-daemon'),
            'none'          => __('component.operator.is_not', 'order-daemon'),
            'only'          => __('component.operator.only', 'order-daemon'),
            'equals'        => __('component.operator.equals', 'order-daemon'),
            'not_equals'    => __('component.operator.is_not', 'order-daemon'),
            'greater_than'  => __('component.operator.greater_than', 'order-daemon'),
            'less_than'     => __('component.operator.less_than', 'order-daemon'),
            'gte'           => __('component.operator.at_least', 'order-daemon'),
            'lte'           => __('component.operator.at_most', 'order-daemon'),
            'contains'      => __('component.operator.contains', 'order-daemon'),
            'amount_gt'     => __('component.operator.more_than', 'order-daemon'),
            'amount_lt'     => __('component.operator.less_than', 'order-daemon'),
            'amount_eq'     => __('component.operator.equals', 'order-daemon'),
            'amount_gte'    => __('component.operator.at_least', 'order-daemon'),
            'amount_lte'    => __('component.operator.at_most', 'order-daemon'),
            'count_gt'      => __('component.operator.more_than', 'order-daemon'),
            'count_lt'      => __('component.operator.less_than', 'order-daemon'),
            'count_eq'      => __('component.operator.equals', 'order-daemon'),
            'count_gte'     => __('component.operator.at_least', 'order-daemon'),
            'count_lte'     => __('component.operator.at_most', 'order-daemon'),
        ];

        return $mode_map[$match_mode] ?? '';
    }

    /**
     * Extract details from the component's settings schema and current settings.
     *
     * @param object $component Component instance providing get_settings_schema().
     * @param string $component_id The component identifier.
     * @param array  $settings  Selected settings values.
     * @return array List of detail strings.
     */
    private function extract_details_from_schema(object $component, string $component_id, array $settings): array
    {
        if (!method_exists($component, 'get_settings_schema')) {
            return [];
        }

        $schema = $component->get_settings_schema();
        if (!is_array($schema) || !isset($schema['properties']) || !is_array($schema['properties'])) {
            return [];
        }

        $details = [];
        foreach ($schema['properties'] as $key => $property) {
            if ($key === 'match_mode' || $key === 'operator' || $key === 'match_type') {
                // Already reflected by extract_match_mode
                continue;
            }

            $value = $settings[$key] ?? null;
            if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
                continue;
            }

            if (!is_array($property)) {
                continue;
            }

            // Component-specific boolean phrasing
            if (($property['type'] ?? '') === 'boolean') {
                if ($component_id === 'product_selection' && $key === 'include_variations' && $value) {
                    $details[] = __('component.behavior.including_product_variations', 'order-daemon');
                    continue;
                }
                if ($component_id === 'customer_role' && $key === 'include_guests' && $value) {
                    $details[] = __('component.behavior.including_guests', 'order-daemon');
                    continue;
                }
                // Skip other booleans to avoid awkward wording
                continue;
            }

            $formatted = $this->format_setting_value($value, $property);
            if ($formatted !== '') {
                $details[] = $formatted;
            }
        }

        return $details;
    }

    /**
     * Format a single setting value according to its property schema.
     *
     * @param mixed $value    Selected value.
     * @param array $property Property schema.
     * @return string Human-readable value.
     */
    private function format_setting_value($value, array $property): string
    {
        // Arrays: map each item to its label if enum provided
        if (is_array($value)) {
            return $this->format_array_value($value, $property);
        }

        // Enums: map scalar to label
        if (isset($property['enum']) && is_array($property['enum']) && isset($property['enum'][(string) $value])) {
            return (string) $property['enum'][(string) $value];
        }

        // Booleans
        if (($property['type'] ?? '') === 'boolean') {
            return $value ? __('component.state.enabled', 'order-daemon') : __('component.state.disabled', 'order-daemon');
        }

        return (string) $value;
    }

    /**
     * Format array values, mapping enums to their display labels.
     *
     * @param array $values   Selected values.
     * @param array $property Property schema containing enum definitions.
     * @return string Comma-separated list of all values.
     */
    private function format_array_value(array $values, array $property): string
    {
        if (empty($values)) {
            return '';
        }

        $enum_options = [];
        if (isset($property['items']['enum']) && is_array($property['items']['enum'])) {
            $enum_options = $property['items']['enum'];
        } elseif (isset($property['enum']) && is_array($property['enum'])) {
            $enum_options = $property['enum'];
        }

        $labels = [];
        foreach ($values as $val) {
            $key = (string) $val;
            $labels[] = isset($enum_options[$key]) ? (string) $enum_options[$key] : (string) $val;
        }

        // Return all items without truncation
        return implode(', ', $labels);
    }


    /**
     * Build final summary string.
     *
     * @param array $parts { title: string, match_mode: string, details: string[] }
     * @return string
     */
    private function build_summary_string(array $parts): string
    {
        // Retained for backward compatibility, not used for final HTML output.
        $segments = [];
        if (!empty($parts['title'])) {
            $segments[] = (string) $parts['title'];
        }
        if (!empty($parts['match_mode'])) {
            $segments[] = (string) $parts['match_mode'];
        }
        if (!empty($parts['details']) && is_array($parts['details'])) {
            $details_str = implode(', ', $parts['details']);
            $segments[] = $details_str;
        }
        $summary = trim(implode(' ', $segments));
        if ($summary === '') {
            $summary = __('component.label.component', 'order-daemon');
        }
        return $summary;
    }

    /**
     * Build structured HTML summary from component parts.
     *
     * @param array $parts Array with keys: 'title', 'match_mode', 'details'.
     * @return string Sanitized HTML summary.
     */
    private function build_summary_html(array $parts): string
    {
        $html_segments = [];

        // Title is always present and required
        $title = esc_html($parts['title'] ?? '');
        if (!empty($title)) {
            $html_segments[] = sprintf('<span class="odcm-summary-title">%s</span>', $title);
        }

        // Match mode/operator (if present)
        $match_mode = esc_html($parts['match_mode'] ?? '');
        if (!empty($match_mode)) {
            $html_segments[] = sprintf('<span class="odcm-summary-operator">%s</span>', $match_mode);
        }

        // Details list (if present)
        if (!empty($parts['details']) && is_array($parts['details'])) {
            $details_text = $this->format_details_list($parts['details']);
            if (!empty($details_text)) {
                $html_segments[] = sprintf('<span class="odcm-summary-details">%s</span>', esc_html($details_text));
            }
        }

        // Join segments with spaces
        $html = implode(' ', $html_segments);

        return $this->sanitize_summary_html($html);
    }

    /**
     * Format details array into a readable string.
     *
     * @param array $details Array of detail strings.
     * @return string Formatted details string.
     */
    private function format_details_list(array $details): string
    {
        if (empty($details)) {
            return '';
        }

        // Remove empty details
        $details = array_filter($details, function($detail) {
            return !empty(trim($detail));
        });

        if (empty($details)) {
            return '';
        }

        return implode(', ', $details);
    }

    /**
     * Sanitize summary HTML with allowed tags and attributes.
     *
     * @param string $html Raw HTML string.
     * @return string Sanitized HTML string.
     */
    private function sanitize_summary_html(string $html): string
    {
        $allowed_tags = [
            'span' => ['class' => []],
            'strong' => ['class' => []],
        ];

        return wp_kses($html, $allowed_tags);
    }


    /**
     * Retrieve a component by ID and type from the registry.
     *
     * @param string $component_id
     * @param string $component_type
     * @return object|null
     */
    private function get_component(string $component_id, string $component_type)
    {
        switch ($component_type) {
            case 'trigger':
                $components = $this->component_registry->get_triggers();
                break;
            case 'condition':
                $components = $this->component_registry->get_conditions();
                break;
            case 'action':
            case 'primaryAction':
                $components = $this->component_registry->get_actions();
                break;
            default:
                return null;
        }

        return $components[$component_id] ?? null;
    }

    /**
     * Apply light per-component phrasing rules to produce more natural sentences.
     * Returns a fully built string when applied; otherwise null to use defaults.
     *
     * @param string $component_id
     * @param string $title
     * @param string $mode
     * @param array  $details
     * @param array  $settings
     * @param string $component_type
     * @return string|null
     */
    private function apply_component_specific_phrasing(string $component_id, string $title, string $mode, array $details, array $settings, string $component_type): ?string
    {
        // Triggers: Usually no settings. Title alone is fine in the WHEN block.
        if ($component_type === 'trigger') {
            return $this->build_summary_html([
                'title' => $title,
                'match_mode' => '',
                'details' => [],
            ]);
        }

        // Actions: Title alone is usually a complete imperative sentence.
        if ($component_type === 'primaryAction' || ($component_type === 'action')) {
            return $this->build_summary_html([
                'title' => $title,
                'match_mode' => '',
                'details' => $details,
            ]);
        }

        // Conditions specialized phrasing
        switch ($component_id) {
            case 'product_category':
                // Product category uses single select (category), not multi-select
                $category = $settings['category'] ?? '';
                $category_enum = $component->get_settings_schema()['properties']['category']['enum'] ?? [];

                if (!empty($category) && $category !== '0') {
                    // Specific category selected
                    $category_label = $category_enum[$category] ?? $category;
                    return $this->build_summary_html([
                        'title' => $title,
                        'match_mode' => '',
                        'details' => [$category_label],
                    ]);
                } else {
                    // No category selected means match all orders
                    return $this->build_summary_html([
                        'title' => $title,
                        'match_mode' => '',
                        'details' => [__('Any category', 'order-daemon')],
                    ]);
                }

            case 'product_selection':
                // e.g., "Specific Products includes A, B (including product variations)"
                return $this->build_summary_html([
                    'title' => $title,
                    'match_mode' => $this->extract_match_mode($settings),
                    'details' => $details,
                ]);

            case 'customer_role':
                // e.g., "Customer Role includes Administrator, Shop Manager (including guests)"
                $mode_text = __('component.operator.includes', 'order-daemon');
                return $this->build_summary_html([
                    'title' => $title,
                    'match_mode' => $mode_text,
                    'details' => $details,
                ]);

            case 'order_item_count':
                // Only display the number tied to the selected radio option (via ui:radio_inputs mapping)
                $count_value = null;
                
                // Map count_type to human-readable label
                $count_type = isset($settings['count_type']) ? (string) $settings['count_type'] : 'unique_products';
                $count_type_label = $count_type === 'total_quantity'
                    /* translators: Text describing the total number of items in an order (e.g. if someone buys 3 apples and 2 oranges, this would be: 5) */
                    ? __('component.label.total_quantity', 'order-daemon')
                    /* translators: Text describing the number of different products in an order (e.g. if someone buys 3 apples and 2 oranges, this would be: 2) */
                    : __('component.label.unique_products', 'order-daemon');
                
                // Compute the sibling key directly from the selected operator (matches schema ui:radio_inputs)
                $sibling_key = isset($settings['operator']) ? (string) $settings['operator'] . '_value' : null;
                
                // Resolve count value using sibling mapping, fallback to generic 'count'
                if ($sibling_key && isset($settings[$sibling_key]) && $settings[$sibling_key] !== '') {
                    $count_value = (int) $settings[$sibling_key];
                } elseif (isset($settings['count']) && $settings['count'] !== '') {
                    $count_value = (int) $settings['count'];
                }
                
                $mode_text = $this->extract_match_mode($settings); // maps count_* operators to phrases
                
                // Build details: only the selected value with its type label
                $detail_strs = [];
                if ($count_value !== null) {
                    $detail_strs[] = sprintf('%s %d', $count_type_label, $count_value);
                } else {
                    // No numeric value to display; avoid listing all numbers
                    $detail_strs[] = $count_type_label;
                }
                
                return $this->build_summary_html([
                    'title' => $title,
                    'match_mode' => $mode_text,
                    'details' => $detail_strs,
                ]);

            default:
                return null; // Use default builder
        }
    }

     /**
     * Get fallback summary for invalid/missing components.
     *
     * @param string $component_type Component type for context.
     * @return string HTML fallback summary.
     */
    private function get_fallback_summary(string $component_type): string
    {
        $fallback_labels = [
            /* translators: Default text for order triggers (what event starts the automation, like "when order is paid") */
            'trigger' => __('component.label.trigger', 'order-daemon'),
            /* translators: Default text for order conditions (requirements that must be met, like "order total is greater than $50") */
            'condition' => __('component.label.condition', 'order-daemon'),
            /* translators: Default text for order actions (what the system does, like "save order note" or "mark as complete") */
            'action' => __('component.label.action', 'order-daemon'),
            /* translators: Default text for the main order action (the primary thing that happens when all conditions are met) */
            'primaryAction' => __('component.label.primary_action', 'order-daemon'),
        ];

        /* translators: Generic default text when the system can't identify what type of rule component this is */
        $label = $fallback_labels[$component_type] ?? __('component.label.component', 'order-daemon');
        
        return $this->sanitize_summary_html(
            sprintf('<span class="odcm-summary-title">%s</span>', esc_html($label))
        );
    }
}
