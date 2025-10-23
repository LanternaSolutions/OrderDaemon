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
        // Theme resolution from registry
        error_log(sprintf(
            "ODCM Debug - FallbackRenderer theme resolution start: event_type=%s",
            $event_type
        ));

        if (function_exists('odcm_get_component_theme')) {
            $registry_theme = odcm_get_component_theme($event_type);
            if (!empty($registry_theme)) {
                $this->theme = $registry_theme;
                error_log(sprintf(
                    "ODCM Debug - Using registry theme: event_type=%s, theme=%s",
                    $event_type,
                    $registry_theme
                ));
            } else {
                error_log(sprintf(
                    "ODCM Debug - No registry theme found: event_type=%s",
                    $event_type
                ));
            }
        } else {
            error_log(sprintf(
                "ODCM Debug - Registry function not available: event_type=%s",
                $event_type
            ));
        }
        
        $toolkit = new PayloadComponentUIToolkit();
        
        // Create separate arrays for different data types
        $scalar_data = [];
        $content = '';
        
        // Process scalar values for key-value list
        foreach ($data as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $formattedKey = ucfirst(str_replace('_', ' ', $key));
                $scalar_data[$formattedKey] = (string)$value;
            }
        }
        
        // Render scalar data as key-value list
        if (!empty($scalar_data)) {
            $content .= $toolkit->render_key_value_list($scalar_data, 'Details');
        }
        
        // Render array data as expandable sections
        foreach ($data as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $formattedKey = ucfirst(str_replace('_', ' ', $key));
                $json = json_encode($value, JSON_PRETTY_PRINT);
                $code_block = $toolkit->render_code_block($json, 'json');
                $content .= $toolkit->render_expandable_section($formattedKey, $code_block);
            }
        }
        
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

}
