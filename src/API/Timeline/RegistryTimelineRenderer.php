<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Timeline renderer using the existing PayloadComponentRegistry system
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
final class RegistryTimelineRenderer implements TimelineRendererInterface
{
    /**
     * Render timeline data to HTML
     */
    public function renderTimeline(TimelineData $timeline): string
    {
        if (!$timeline->hasComponents()) {
            return $this->renderEmptyTimeline($timeline);
        }
        
        // Load the existing registry system
        $this->ensureRegistryLoaded();
        
        $html = '<div class="odcm-narrative-timeline">';
        
        foreach ($timeline->components as $component) {
            $renderedComponent = $this->renderComponent($component);
            if (!empty($renderedComponent)) {
                $html .= $renderedComponent;
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render individual component using registry system
     */
    private function renderComponent(array $component): string
    {
        $event_type = $component['event_type'] ?? 'info';
        $data = $component['data'] ?? [];
        $label = $component['label'] ?? ucfirst($event_type);
        $ts = $component['ts'] ?? null;
        $level = $component['level'] ?? 'info';
        
        // Skip components with empty data
        if (empty($data)) {
            return '';
        }
        
        // Use the existing smart lookup system
        $rendererDefinition = odcm_find_best_renderer_for_data($event_type, $data);
        
        if (!$rendererDefinition || !isset($rendererDefinition['renderer_class'])) {
            return $this->renderFallbackComponent($event_type, $label, $data, $level);
        }
        
        $rendererClass = $rendererDefinition['renderer_class'];
        
        // Ensure full namespace if not provided
        if (strpos($rendererClass, '\\') === false) {
            $rendererClass = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $rendererClass;
        }
        
        if (!class_exists($rendererClass)) {
            return $this->renderFallbackComponent($event_type, $label, $data, $level);
        }
        
        try {
            $renderer = new $rendererClass();
            
            // Try different rendering methods in order of preference
            if (method_exists($renderer, 'renderTimelineItem')) {
                return $renderer->renderTimelineItem($event_type, $label, $ts, $level, $data);
            }
            
            if (method_exists($renderer, 'render')) {
                return $renderer->render($data);
            }
            
            // Fallback if renderer doesn't have expected methods
            return $this->renderFallbackComponent($event_type, $label, $data, $level);
            
        } catch (\Throwable $e) {
            // Log error and use fallback
            error_log("ODCM Timeline Renderer Error for {$rendererClass}: " . $e->getMessage());
            return $this->renderFallbackComponent($event_type, $label, $data, $level);
        }
    }
    
    /**
     * Render fallback component when specific renderer fails or is unavailable
     */
    private function renderFallbackComponent(string $event_type, string $label, array $data, string $level): string
    {
        // Load UI toolkit for consistent rendering
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once dirname(__DIR__, 2) . '/View/PayloadRenderer/PayloadComponentUIToolkit.php';
        }
        
        try {
            $toolkit = new \OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentUIToolkit();
            
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
            
            return $toolkit->render_component_shell(
                $label,
                'fallback',
                $content,
                ['status' => $level]
            );
            
        } catch (\Throwable $e) {
            error_log("ODCM Timeline Fallback Renderer Error: " . $e->getMessage());
            // Ultra-minimal fallback
            return '<div class="odcm-error-component">' . 
                   '<p><strong>' . esc_html($label) . '</strong></p>' .
                   '<p>Error rendering component</p>' .
                   '</div>';
        }
    }
    
    /**
     * Render empty timeline message
     */
    private function renderEmptyTimeline(TimelineData $timeline): string
    {
        $message = $timeline->isProcessGroup() 
            ? __('No timeline data available for this process group', 'order-daemon')
            : __('No timeline data available for this log entry', 'order-daemon');
            
        return '<div class="odcm-empty-data">' . esc_html($message) . '</div>';
    }
    
    /**
     * Ensure the registry system is loaded
     */
    private function ensureRegistryLoaded(): void
    {
        if (!function_exists('odcm_find_best_renderer_for_data')) {
            require_once dirname(__DIR__, 2) . '/Core/PayloadComponentRegistry.php';
        }
        
        // Also ensure all payload renderer classes are available
        $renderer_dir = dirname(__DIR__, 2) . '/View/PayloadRenderer/';
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once $renderer_dir . 'PayloadComponentUIToolkit.php';
        }
    }
}
