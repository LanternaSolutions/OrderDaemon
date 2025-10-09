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
     * Maximum summary length in characters to roughly fit ~2 lines in UI.
     */
    private const MAX_SUMMARY_LENGTH = 120;

    /**
     * Maximum number of list items to display before showing ellipsis.
     */
    private const MAX_LIST_ITEMS = 3;

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
                'any' => __('includes', 'order-daemon'),
                'all' => __('includes all of', 'order-daemon'),
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
            'all'           => __('is only', 'order-daemon'),
            'any'           => __('includes', 'order-daemon'),
            'none'          => __('is not', 'order-daemon'),
            'only'          => __('only', 'order-daemon'),
            'equals'        => __('equals', 'order-daemon'),
            'not_equals'    => __('is not', 'order-daemon'),
            'greater_than'  => __('greater than', 'order-daemon'),
            'less_than'     => __('less than', 'order-daemon'),
            'gte'           => __('at least', 'order-daemon'),
            'lte'           => __('at most', 'order-daemon'),
            'contains'      => __('contains', 'order-daemon'),
            'amount_gt'     => __('more than', 'order-daemon'),
            'amount_lt'     => __('less than', 'order-daemon'),
            'amount_eq'     => __('equals', 'order-daemon'),
            'amount_gte'    => __('at least', 'order-daemon'),
            'amount_lte'    => __('at most', 'order-daemon'),
            'count_gt'      => __('more than', 'order-daemon'),
            'count_lt'      => __('less than', 'order-daemon'),
            'count_eq'      => __('equals', 'order-daemon'),
            'count_gte'     => __('at least', 'order-daemon'),
            'count_lte'     => __('at most', 'order-daemon'),
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
                    $details[] = __('including product variations', 'order-daemon');
                    continue;
                }
                if ($component_id === 'customer_role' && $key === 'include_guests' && $value) {
                    $details[] = __('including guests', 'order-daemon');
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
            return $value ? __('enabled', 'order-daemon') : __('disabled', 'order-daemon');
        }

        // Numbers/strings fallback
        $text = (string) $value;
        if (strlen($text) > 60) {
            $text = $this->truncate_string($text, 60);
        }
        return $text;
    }

    /**
     * Format array values, mapping enums and truncating with ellipsis when necessary.
     *
     * @param array $values   Selected values.
     * @param array $property Property schema containing enum definitions.
     * @return string Comma-separated list with possible truncation.
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

        // Limit number of items for readability
        return $this->truncate_list($labels);
    }

    /**
     * Truncate list to max items and add ellipsis with remaining count.
     *
     * @param array $items Labels to display.
     * @return string
     */
    private function truncate_list(array $items): string
    {
        if (count($items) <= self::MAX_LIST_ITEMS) {
            return implode(', ', $items);
        }

        $truncated = array_slice($items, 0, self::MAX_LIST_ITEMS);
        $remaining = count($items) - self::MAX_LIST_ITEMS;
        return implode(', ', $truncated) . ' ' . sprintf( /* translators: %d: remaining count */ __('... and %d more', 'order-daemon'), $remaining);
    }

    /**
     * Build final summary string with length control and graceful truncation.
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
            $current_length = strlen(implode(' ', $segments));
            $available = self::MAX_SUMMARY_LENGTH - $current_length - 1;
            if ($available > 10 && strlen($details_str) > $available) {
                $details_str = $this->truncate_string($details_str, $available);
            }
            $segments[] = $details_str;
        }
        $summary = trim(implode(' ', $segments));
        if ($summary === '') {
            $summary = __('Component', 'order-daemon');
        }
        if (strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            $summary = $this->truncate_string($summary, self::MAX_SUMMARY_LENGTH);
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
     * Format details array into a readable string with truncation.
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

        // Truncate list if too many items
        $displayed_details = array_slice($details, 0, self::MAX_LIST_ITEMS);
        $remaining_count = count($details) - count($displayed_details);

        $details_text = implode(', ', $displayed_details);

        // Add ellipsis if there are more items
        if ($remaining_count > 0) {
            $details_text .= sprintf(' ' . __('and %d more', 'order-daemon'), $remaining_count);
        }

        // Truncate overall length if needed
        if (strlen($details_text) > self::MAX_SUMMARY_LENGTH) {
            $details_text = $this->truncate_string($details_text, self::MAX_SUMMARY_LENGTH);
        }

        return $details_text;
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
     * Truncate string at a word boundary and add ellipsis.
     *
     * @param string $text
     * @param int    $max_length
     * @return string
     */
    private function truncate_string(string $text, int $max_length): string
    {
        if (strlen($text) <= $max_length) {
            return $text;
        }
        $slice = substr($text, 0, max(0, $max_length - 3));
        $last_space = strrpos($slice, ' ');
        if ($last_space !== false && $last_space > (int) floor($max_length * 0.6)) {
            $slice = substr($slice, 0, $last_space);
        }
        return rtrim($slice) . '...';
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
                // Mode derived from match_type: any => includes, all => includes all of
                return $this->build_summary_html([
                    'title' => $title,
                    'match_mode' => $mode,
                    'details' => $details,
                ]);

            case 'product_selection':
                // e.g., "Specific Products includes A, B (including product variations)"
                return $this->build_summary_html([
                    'title' => $title,
                    'match_mode' => $this->extract_match_mode($settings),
                    'details' => $details,
                ]);

            case 'customer_role':
                // e.g., "Customer Role includes Administrator, Shop Manager (including guests)"
                $mode_text = __('includes', 'order-daemon');
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
                    ? __('Total quantity', 'order-daemon')
                    : __('Unique products', 'order-daemon');
                
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
            'trigger' => __('Trigger', 'order-daemon'),
            'condition' => __('Condition', 'order-daemon'),
            'action' => __('Action', 'order-daemon'),
            'primaryAction' => __('Primary Action', 'order-daemon'),
        ];

        $label = $fallback_labels[$component_type] ?? __('Component', 'order-daemon');
        
        return $this->sanitize_summary_html(
            sprintf('<span class="odcm-summary-title">%s</span>', esc_html($label))
        );
    }
}
