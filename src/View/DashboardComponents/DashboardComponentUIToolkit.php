<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\DashboardComponents;

/**
 * UI Toolkit for Insight Dashboard Components.
 *
 * Provides small HTML helpers used by dashboard component renderers to keep
 * structure consistent. This is intentionally minimal; most exact markup is
 * preserved by delegating to legacy renderers inside InsightDashboard.
 *
 * @package OrderDaemon\CompletionManager\View\DashboardComponents
 * @since   1.0.0
 */
class DashboardComponentUIToolkit
{
    public static function escapeAlpineHtml(string $content, array $allowed_alpine_attributes = []): string {
        // Default allowed Alpine.js attributes
        $default_alpine = [
            // Alpine core directives
            'x-data', 'x-init', 'x-show', 'x-bind', 'x-model', 'x-on', 'x-text', 'x-html',
            'x-ref', 'x-cloak', 'x-transition', 'x-effect', 'x-ignore', 'x-modelable', 'x-teleport',
            'x-if', 'x-for',

            // Alpine "directive with argument" forms commonly used
            'x-transition:enter', 'x-transition:leave',
            'x-transition:enter-start', 'x-transition:enter-end',
            'x-transition:leave-start', 'x-transition:leave-end',

            // x-bind / x-on explicit forms (handy if you ever output them)
            'x-bind:class', 'x-bind:id', 'x-bind:for', 'x-bind:aria-checked',
            'x-on:click', 'x-on:change', 'x-on:input', 'x-on:submit',
            // Add missing x-bind attributes used in dashboard
            'x-bind:disabled', 'x-bind:title', 'x-bind:key', 'x-bind:min', 'x-bind:max', 'x-bind:checked', 'x-bind:value', 'x-bind:style',

        ];

        $allowed = array_merge($default_alpine, $allowed_alpine_attributes);

        // Use wp_kses with custom allowed HTML and Alpine.js attributes
        $allowed_html = [
            'div' => array_merge(['class' => [], 'id' => [], 'style' => []], array_fill_keys($allowed, [])),
            'span' => array_merge(['class' => [], 'id' => [], 'style' => []], array_fill_keys($allowed, [])),
            'p' => array_merge(['class' => [], 'id' => [], 'style' => []], array_fill_keys($allowed, [])),
            'a' => array_merge(['href' => [], 'class' => [], 'id' => [], 'target' => [], 'rel' => []], array_fill_keys($allowed, [])),
            'button' => array_merge(
                [
                    'type' => [],
                    'class' => [],
                    'id' => [],
                    'data-filter' => [],
                    'data-target' => [],
                ],
                array_fill_keys($allowed, [])
            ),
            'input' => array_merge(['type' => [], 'name' => [], 'value' => [], 'class' => [], 'id' => [], 'checked' => []], array_fill_keys($allowed, [])),
            'form' => array_merge(['method' => [], 'action' => [], 'class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'label' => array_merge(['for' => [], 'class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'select' => array_merge(['name' => [], 'class' => [], 'id' => [], 'disabled' => []], array_fill_keys($allowed, [])),
            'option' => array_merge(['value' => [], 'selected' => []], array_fill_keys($allowed, [])),
            'textarea' => array_merge(['name' => [], 'class' => [], 'id' => [], 'rows' => [], 'cols' => [], 'placeholder' => [], 'disabled' => []], array_fill_keys($allowed, [])),
            'img' => array_merge(['src' => [], 'alt' => [], 'class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'ul' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'ol' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'li' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'h1' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'h2' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'h3' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'h4' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'h5' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'h6' => array_merge(['class' => [], 'id' => []], array_fill_keys($allowed, [])),
            'strong' => array_fill_keys($allowed, []),
            'em' => array_fill_keys($allowed, []),
            'code' => array_fill_keys($allowed, []),
            'pre' => array_fill_keys($allowed, []),
            'br' => array_fill_keys($allowed, []),
            'hr' => array_fill_keys($allowed, []),
            'template' => array_fill_keys($allowed, [])
        ];
        
        return wp_kses($content, $allowed_html);
    }

    /**
     * Create Alpine.js data attribute with proper escaping
     *
     * @param array $data Data to include in x-data
     * @return string Escaped x-data attribute
     */
    public static function createAlpineDataAttribute(array $data): string {
        $json = wp_json_encode($data);
        return 'x-data="' . esc_attr($json) . '"';
    }
    
    /**
     * Create Alpine.js event binding with proper escaping
     *
     * @param string $event Event name (click, submit, etc.)
     * @param string $handler Event handler
     * @return string Escaped event binding
     */
    public static function createAlpineEventBinding(string $event, string $handler): string {
        return sprintf('x-on:%s="%s"', esc_attr($event), esc_attr($handler));
    }

    /**
     * Create Alpine.js x-bind attribute (avoids shorthand :attr)
     */
    public static function createAlpineBind(string $attribute, string $expression): string
    {
        $attribute = trim($attribute);
        if ($attribute === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', $attribute)) {
            return '';
        }

        return 'x-bind:' . esc_attr($attribute) . '="' . esc_attr($expression) . '"';
    }

    /**
     * Create dynamic class attribute with proper escaping
     *
     * @param array $classes Array of classes to include
     * @return string Escaped class attribute
     */
    public static function createClassAttribute(array $classes): string {
        $class_string = implode(' ', array_filter($classes));
        return 'class="' . esc_attr($class_string) . '"';
    }

    /**
     * Create Alpine.js x-show attribute with proper escaping
     *
     * This method is specifically designed for creating x-show attributes
     * that work correctly with both WordPress security requirements and
     * Alpine.js functionality.
     *
     * IMPORTANT: Use this method instead of escapeAlpineHtml() for bare
     * x-show attributes. The escapeAlpineHtml() method is designed for
     * complete HTML snippets, not individual attributes.
     *
     * @param string $expression The expression to evaluate (e.g., "filterPaneVisible" or "!filterPaneVisible")
     * @return string Escaped x-show attribute
     */
    public static function createAlpineShowAttribute(string $expression): string {
        return 'x-show="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js model binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js model bindings
     * (like x-model) that work correctly with both WordPress security 
     * requirements and Alpine.js functionality.
     *
     * @param string $expression The model expression (e.g., "filters.search")
     * @return string Escaped model binding attribute
     */
    public static function createAlpineModelBinding(string $expression): string {
        return 'x-model="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js text binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js text bindings
     * (like x-text) that work correctly with both WordPress security 
     * requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped text binding attribute
     */
    public static function createAlpineTextBinding(string $expression): string {
        return 'x-text="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js HTML binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js HTML bindings
     * (like x-html) that work correctly with both WordPress security 
     * requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped HTML binding attribute
     */
    public static function createAlpineHtmlBinding(string $expression): string {
        return 'x-html="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js class binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js class bindings
     * (like :class) that work correctly with both WordPress security 
     * requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped class binding attribute
     */
    public static function createAlpineClassBinding(string $expression): string {
        return 'x-bind:class="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js disabled binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js disabled bindings
     * that work correctly with both WordPress security requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped disabled binding attribute
     */
    public static function createAlpineDisabledBinding(string $expression): string {
        return 'x-bind:disabled="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js title binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js title bindings
     * that work correctly with both WordPress security requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped title binding attribute
     */
    public static function createAlpineTitleBinding(string $expression): string {
        return 'x-bind:title="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js key binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js key bindings
     * that work correctly with both WordPress security requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped key binding attribute
     */
    public static function createAlpineKeyBinding(string $expression): string {
        return 'x-bind:key="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js min binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js min bindings
     * that work correctly with both WordPress security requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped min binding attribute
     */
    public static function createAlpineMinBinding(string $expression): string {
        return 'x-bind:min="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js checked binding attribute with proper escaping
     *
     * This method is specifically designed for creating Alpine.js checked bindings
     * that work correctly with both WordPress security requirements and Alpine.js functionality.
     *
     * @param string $expression The expression to evaluate
     * @return string Escaped checked binding attribute
     */
    public static function createAlpineCheckedBinding(string $expression): string {
        return 'x-bind:checked="' . esc_attr($expression) . '"';
    }

    /**
     * Create Alpine.js generic attribute binding (e.g. :aria-checked, :min, :max)
     *
     * @param string $attribute Attribute name without ":" prefix (e.g. "aria-checked", "min", "max")
     * @param string $expression Alpine expression (e.g. "viewMode === 'flat'")
     * @return string Escaped Alpine bound attribute
     */
    public static function createAlpineAttrBinding(string $attribute, string $expression): string
    {
        // Allow common HTML attribute name characters (letters, numbers, dash, underscore, colon)
        // (we add ":" ourselves, but allow it anyway for safety)
        $attribute = trim($attribute);
        if ($attribute === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', $attribute)) {
            // Fail closed: return empty string rather than outputting something unsafe/broken.
            return '';
        }

        return 'x-bind:' . esc_attr($attribute) . '="' . esc_attr($expression) . '"';
    }

    /**
     * Wrap raw HTML content with a component container.
     *
     * @param string $content HTML content (already escaped appropriately).
     * @param string $additional_classes Space-separated class names.
     * @return string
     */
    public function wrap_component(string $content, string $additional_classes = ''): string
    {
        $classes = 'odcm-dashboard-component';
        if ($additional_classes !== '') {
            $classes .= ' ' . esc_attr($additional_classes);
        }
        return '<div class="' . $classes . '">' . $content . '</div>';
    }

    /**
     * Render a simple button. Label is escaped as text; attributes must be safe.
     *
     * @param string $label
     * @param array $attrs
     * @return string
     */
    public function button(string $label, array $attrs = []): string
    {
        $attr_html = '';
        foreach ($attrs as $k => $v) {
            $attr_html .= ' ' . esc_attr((string) $k) . '="' . esc_attr((string) $v) . '"';
        }
        return '<button type="button"' . $attr_html . '>' . esc_html($label) . '</button>';
    }

    /**
     * Render a basic accordion section used in settings.
     *
     * @param string $title
     * @param string $content
     * @param bool   $open
     * @return string
     */
    public function accordion_section(string $title, string $content, bool $open = false): string
    {
        $open_class = $open ? ' is-open' : '';
        return '<div class="odcm-accordion' . $open_class . '">' .
               '<div class="odcm-accordion__header">' . esc_html($title) . '</div>' .
               '<div class="odcm-accordion__content">' . $content . '</div>' .
               '</div>';
    }
}