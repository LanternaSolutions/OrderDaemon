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
     * Log a debug message using WordPress-compatible logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    private function logDebugMessage(string $message, string $level = 'debug'): void
    {
        // Only log if debug mode is enabled
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }
        
        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($message, $level);
            return;
        }
        
        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
            return;
        }
        
        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_' . $level, $message);
            return;
        }
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
            $debug_file = WP_CONTENT_DIR . '/debug.log';
            @file_put_contents(
                $debug_file,
                '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
                FILE_APPEND
            );
            return;
        }
    }
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
            try {
                $renderedComponent = $this->renderComponent($component);
            } catch (\Throwable $e) {
                // Never let a single component break the whole timeline
                $this->logDebugMessage("ODCM TIMELINE DEBUG: Component render threw exception: " . $e->getMessage(), 'error');
                $renderedComponent = '';
            }
            if (!empty($renderedComponent)) {
                $html .= $renderedComponent;
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render individual component using registry system with debug filtering
     */
    private function renderComponent(array $payload): string
    {
        // Debug Event Filtering - hide debug events in production
        if ($this->shouldFilterDebugEvent($payload)) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - Debug event hidden in production mode");
            return ''; // Hide debug events in production
        }
        
        // The $payload is the full log entry. The renderer needs the component data,
        // which is often nested inside the 'data' key.
        $data = $payload['data'] ?? $payload;
        
        // If this is a universal event, extract the real event type from the data
        if (isset($data['event_type'])) {
            $event_type = $data['event_type'];
            $label = ucfirst(str_replace('_', ' ', $event_type));
        } else {
            $event_type = $payload['event_type'] ?? 'info';
            $label = $payload['label'] ?? ucfirst($event_type);
        }
        
        $ts = $payload['ts'] ?? null;
        $level = $payload['level'] ?? 'info';
        
        // Enhanced debug logging for component rendering
        $this->logDebugMessage("ODCM TIMELINE DEBUG: ========== RENDERING COMPONENT ==========");
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Component event_type: '$event_type'");
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Component label: '$label'");
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Component level: '$level'");
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Component timestamp: " . ($ts ?? 'null'));
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Data keys: " . implode(', ', array_keys($data)));
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Data empty: " . (empty($data) ? 'YES' : 'NO'));
        if (!empty($data)) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Data sample: " . substr(json_encode($data), 0, 300) . (strlen(json_encode($data)) > 300 ? '...' : ''));
        }
        
        // Skip components with empty data, but pass the full payload if it's not empty.
        if (empty($data) && empty($payload)) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: SKIPPING - Component has empty data");
            return '';
        }
        
        // Use new direct mapping system
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Getting renderer for event_type: '$event_type'");

        // Resolve renderer class safely even if registry function is unavailable
        if (function_exists('odcm_get_renderer_for_event_type')) {
            try {
                $rendererClass = odcm_get_renderer_for_event_type($event_type);
            } catch (\Throwable $e) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: Registry lookup failed: " . $e->getMessage(), 'error');
                $rendererClass = '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer';
            }
        } else {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Registry function missing, using FallbackRenderer", 'warning');
            $rendererClass = '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer';
        }
        $originalRendererClass = $rendererClass;
        
        // Debug logging for renderer selection result
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Renderer class selected: '$rendererClass'");
        
        // Ensure full namespace if not provided
        if (strpos($rendererClass, '\\') === false) {
            $rendererClass = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $rendererClass;
        }
        
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Original renderer class: '$originalRendererClass'");
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Full renderer class: '$rendererClass'");
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Checking if class exists...");
        
        if (!class_exists($rendererClass)) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: ERROR - Renderer class '$rendererClass' does not exist", 'error');
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Using fallback renderer");
            $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
            $timeline = [
                'label' => $label,
                'ts' => $ts,
                'level' => $level
            ];
            return $renderer->render($payload, $event_type, $timeline);
        }
        
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Renderer class exists, attempting instantiation...");
        
        try {
            $renderer = new $rendererClass();
            
            $has_renderTimelineItem = method_exists($renderer, 'renderTimelineItem');
            $has_render = method_exists($renderer, 'render');
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Renderer instantiated successfully");
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Has renderTimelineItem(): " . ($has_renderTimelineItem ? 'YES' : 'NO'));
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Has render(): " . ($has_render ? 'YES' : 'NO'));
            
            // Use unified render method with timeline data
            if (method_exists($renderer, 'render')) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: Using render() method with timeline data");

                $timeline = [
                    'label' => $label,
                    'ts' => $ts,
                    'level' => $level
                ];

                $result = $renderer->render($payload, $event_type, $timeline);
                
                $result_length = strlen($result);
                $this->logDebugMessage("ODCM TIMELINE DEBUG: render() completed, result length: $result_length");
                if ($result_length > 0) {
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: SUCCESS - Component rendered with timeline data");
                } else {
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: WARNING - Renderer returned empty result", 'warning');
                }
                
                return $result;
            }
            
            // Fallback if renderer doesn't have expected methods
            $this->logDebugMessage("ODCM TIMELINE DEBUG: ERROR - Renderer '$rendererClass' has no render methods", 'error');
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Using fallback renderer");
            $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
            $timeline = [
                'label' => $label,
                'ts' => $ts,
                'level' => $level
            ];
            return $renderer->render($payload, $event_type, $timeline);
            
        } catch (\Throwable $e) {
            // Log error and use fallback
            $this->logDebugMessage("ODCM TIMELINE DEBUG: EXCEPTION - Renderer instantiation/execution failed", 'error');
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Exception: " . $e->getMessage(), 'error');
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Exception file: " . $e->getFile() . ":" . $e->getLine(), 'error');
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Using fallback renderer");
            
            // Always log renderer errors regardless of debug mode
            if (function_exists('wp_debug_log')) {
                wp_debug_log("ODCM Timeline Renderer Error for {$rendererClass}: " . $e->getMessage());
            }
            $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
            $timeline = [
                'label' => $label,
                'ts' => $ts,
                'level' => $level
            ];
            return $renderer->render($payload, $event_type, $timeline);
        }
    }
    
    
    /**
     * Render empty timeline message
     */
    private function renderEmptyTimeline(TimelineData $timeline): string
    {
        $message = $timeline->isProcessGroup() 
            ? __('audit.logs.timeline.process_group_empty', 'order-daemon')
            : __('audit.logs.timeline.log_entry_empty', 'order-daemon');
            
        return '<div class="odcm-empty-data">' . esc_html($message) . '</div>';
    }
    
    /**
     * Ensure the registry system is loaded
     */
    private function ensureRegistryLoaded(): void
    {
        $core_dir = dirname(__DIR__, 2) . '/Core/';
        $renderer_dir = dirname(__DIR__, 2) . '/View/PayloadRenderer/';
        
        // Load the registry system defensively
        if (!function_exists('odcm_get_renderer_for_event_type')) {
            try {
                require_once $core_dir . 'PayloadComponentRegistry.php';
            } catch (\Throwable $e) {
                $this->logDebugMessage('ODCM TIMELINE DEBUG: Failed to load PayloadComponentRegistry.php: ' . $e->getMessage(), 'error');
            }
        }
        
        // Load UI toolkit defensively
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\PayloadComponentUIToolkit')) {
            try {
                require_once $renderer_dir . 'PayloadComponentUIToolkit.php';
            } catch (\Throwable $e) {
                $this->logDebugMessage('ODCM TIMELINE DEBUG: Failed to load PayloadComponentUIToolkit.php: ' . $e->getMessage(), 'error');
            }
        }

        // Ensure base renderer classes are available for safe fallback
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\BaseRenderer')) {
            try { require_once $renderer_dir . 'BaseRenderer.php'; } catch (\Throwable $e) {
                $this->logDebugMessage('ODCM TIMELINE DEBUG: Failed to load BaseRenderer.php: ' . $e->getMessage(), 'error');
            }
        }
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer')) {
            try { require_once $renderer_dir . 'FallbackRenderer.php'; } catch (\Throwable $e) {
                $this->logDebugMessage('ODCM TIMELINE DEBUG: Failed to load FallbackRenderer.php: ' . $e->getMessage(), 'error');
            }
        }
    }
    
    /**
     * Simple debug event filtering - hide obvious debug events unless debug mode is on
     */
    private function shouldFilterDebugEvent(array $payload): bool
    {
        // Show all events in debug mode
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            return false;
        }
        
        // Get event type
        $event_type = $payload['data']['event_type'] ?? $payload['event_type'] ?? '';
        
        // Hide technical debug events
        if (in_array($event_type, ['order_created', 'order_check_scheduled', 'order_loaded'])) {
            return true;
        }
        
        return false;
    }
}
