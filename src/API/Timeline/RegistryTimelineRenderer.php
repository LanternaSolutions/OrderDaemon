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
        
        // Enhanced debug logging for component rendering
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM TIMELINE DEBUG: ========== RENDERING COMPONENT ==========");
            error_log("ODCM TIMELINE DEBUG: Component event_type: '$event_type'");
            error_log("ODCM TIMELINE DEBUG: Component label: '$label'");
            error_log("ODCM TIMELINE DEBUG: Component level: '$level'");
            error_log("ODCM TIMELINE DEBUG: Component timestamp: " . ($ts ?? 'null'));
            error_log("ODCM TIMELINE DEBUG: Data keys: " . implode(', ', array_keys($data)));
            error_log("ODCM TIMELINE DEBUG: Data empty: " . (empty($data) ? 'YES' : 'NO'));
            if (!empty($data)) {
                error_log("ODCM TIMELINE DEBUG: Data sample: " . substr(json_encode($data), 0, 300) . (strlen(json_encode($data)) > 300 ? '...' : ''));
            }
        }
        
        // Skip components with empty data
        if (empty($data)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM TIMELINE DEBUG: SKIPPING - Component has empty data");
            }
            return '';
        }
        
        // Use new direct mapping system
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM TIMELINE DEBUG: Getting renderer for event_type: '$event_type'");
        }
        
        $rendererClass = odcm_get_renderer_for_event_type($event_type);
        $originalRendererClass = $rendererClass;
        
        // Debug logging for renderer selection result
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM TIMELINE DEBUG: Renderer class selected: '$rendererClass'");
        }
        
        // Ensure full namespace if not provided
        if (strpos($rendererClass, '\\') === false) {
            $rendererClass = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $rendererClass;
        }
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM TIMELINE DEBUG: Original renderer class: '$originalRendererClass'");
            error_log("ODCM TIMELINE DEBUG: Full renderer class: '$rendererClass'");
            error_log("ODCM TIMELINE DEBUG: Checking if class exists...");
        }
        
        if (!class_exists($rendererClass)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM TIMELINE DEBUG: ERROR - Renderer class '$rendererClass' does not exist");
                error_log("ODCM TIMELINE DEBUG: Using fallback renderer");
            }
            $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
            $timeline = [
                'label' => $label,
                'ts' => $ts,
                'level' => $level
            ];
            return $renderer->render($data, $event_type, $timeline);
        }
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log("ODCM TIMELINE DEBUG: Renderer class exists, attempting instantiation...");
        }
        
        try {
            $renderer = new $rendererClass();
            
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $has_renderTimelineItem = method_exists($renderer, 'renderTimelineItem');
                $has_render = method_exists($renderer, 'render');
                error_log("ODCM TIMELINE DEBUG: Renderer instantiated successfully");
                error_log("ODCM TIMELINE DEBUG: Has renderTimelineItem(): " . ($has_renderTimelineItem ? 'YES' : 'NO'));
                error_log("ODCM TIMELINE DEBUG: Has render(): " . ($has_render ? 'YES' : 'NO'));
            }
            
            // Use unified render method with timeline data
            if (method_exists($renderer, 'render')) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log("ODCM TIMELINE DEBUG: Using render() method with timeline data");
                }

                $timeline = [
                    'label' => $label,
                    'ts' => $ts,
                    'level' => $level
                ];

                $result = $renderer->render($data, $event_type, $timeline);
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $result_length = strlen($result);
                    error_log("ODCM TIMELINE DEBUG: render() completed, result length: $result_length");
                    if ($result_length > 0) {
                        error_log("ODCM TIMELINE DEBUG: SUCCESS - Component rendered with timeline data");
                    } else {
                        error_log("ODCM TIMELINE DEBUG: WARNING - Renderer returned empty result");
                    }
                }
                
                return $result;
            }
            
            // Fallback if renderer doesn't have expected methods
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM TIMELINE DEBUG: ERROR - Renderer '$rendererClass' has no render methods");
                error_log("ODCM TIMELINE DEBUG: Using fallback renderer");
            }
            $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
            $timeline = [
                'label' => $label,
                'ts' => $ts,
                'level' => $level
            ];
            return $renderer->render($data, $event_type, $timeline);
            
        } catch (\Throwable $e) {
            // Log error and use fallback
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log("ODCM TIMELINE DEBUG: EXCEPTION - Renderer instantiation/execution failed");
                error_log("ODCM TIMELINE DEBUG: Exception: " . $e->getMessage());
                error_log("ODCM TIMELINE DEBUG: Exception file: " . $e->getFile() . ":" . $e->getLine());
                error_log("ODCM TIMELINE DEBUG: Using fallback renderer");
            }
            error_log("ODCM Timeline Renderer Error for {$rendererClass}: " . $e->getMessage());
            $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
            $timeline = [
                'label' => $label,
                'ts' => $ts,
                'level' => $level
            ];
            return $renderer->render($data, $event_type, $timeline);
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
        // Load the new registry system
        require_once dirname(__DIR__, 2) . '/Core/PayloadComponentRegistry.php';
        
        // Load UI toolkit
        $renderer_dir = dirname(__DIR__, 2) . '/View/PayloadRenderer/';
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            require_once $renderer_dir . 'PayloadComponentUIToolkit.php';
        }
    }
}
