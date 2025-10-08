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
 * @since   2.1.0
 */
class DashboardComponentUIToolkit
{
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
