<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Fallback Renderer
 *
 * Handles rendering of unrecognized event types by using the registry's
 * theme mapping and providing a consistent fallback display format.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class FallbackRenderer extends BaseRenderer
{
    /**
     * Render Content
     *
     * Renders event data in a generic format.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    protected function renderContent(array $data, string $event_type): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        
        $content = '<div class="odcm-fallback-component">';
        
        foreach ($data as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $formattedKey = ucfirst(str_replace('_', ' ', $key));
                $content .= '<p><strong>' . esc_html($formattedKey) . ':</strong> ' . esc_html((string)$value) . '</p>';
            } elseif (is_array($value) && !empty($value)) {
                $formattedKey = ucfirst(str_replace('_', ' ', $key));
                $content .= '<p><strong>' . esc_html($formattedKey) . ':</strong></p>';
                $content .= '<pre class="odcm-json-data">' . esc_html(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
            }
        }
        
        $content .= '</div>';
        
        return $content;
    }

    /**
     * Get Label
     *
     * Gets component label from registry or falls back to formatted event type.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $data, string $event_type): string
    {
        if (function_exists('odcm_get_component_label')) {
            $label = odcm_get_component_label($event_type);
            if (!empty($label)) {
                return $label;
            }
        }
        
        return ucwords(str_replace('_', ' ', $event_type));
    }

    /**
     * Get Status Pill
     *
     * Gets status pill configuration from registry.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
        if (function_exists('odcm_get_status_pill_config')) {
            return odcm_get_status_pill_config($event_type);
        }
        
        return null;
    }

    /**
     * Get Theme
     *
     * Gets theme from registry or falls back to default.
     *
     * @param string $event_type The type of event
     * @return string Theme identifier
     */
    protected function getTheme(string $event_type): string
    {
        if (function_exists('odcm_get_component_theme')) {
            return odcm_get_component_theme($event_type);
        }
        
        return 'default';
    }
}
