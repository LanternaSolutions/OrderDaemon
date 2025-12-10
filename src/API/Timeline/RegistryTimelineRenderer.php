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
                '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
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
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM DEBUG: RegistryTimelineRenderer - Timeline has no components, rendering empty timeline", 'debug');
                $this->logDebugMessage("ODCM DEBUG: RegistryTimelineRenderer - Timeline metadata: " . json_encode($timeline->metadata), 'debug');
            }
            return $this->renderEmptyTimeline($timeline);
        }
        
        // Load the existing registry system
        $this->ensureRegistryLoaded();
        
        // Order event tracking
        $isOrderEvent = false;
        $eventType = '';
        if (isset($timeline->metadata['event_type']) && is_string($timeline->metadata['event_type'])) {
            $eventType = $timeline->metadata['event_type'];
            $isOrderEvent = strpos($eventType, 'checkout') !== false || 
                            strpos($eventType, 'order_') !== false || 
                            strpos($eventType, 'complete') !== false ||
                            strpos($eventType, 'completion') !== false ||
                            strpos($eventType, 'order_completed') !== false ||
                            strpos($eventType, 'checkout_processed') !== false ||
                            strpos($eventType, 'checkout_completed') !== false;
            
            if ($isOrderEvent && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM DEBUG: RegistryTimelineRenderer - Rendering order timeline for event: " . $eventType, 'debug');
                $this->logDebugMessage("ODCM DEBUG: RegistryTimelineRenderer - Timeline has " . count($timeline->components) . " components", 'debug');
            }
        }
        
        $html = '<div class="odcm-narrative-timeline">';
        $renderedComponentCount = 0;
        
        foreach ($timeline->components as $idx => $component) {
            try {
                if ($isOrderEvent && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM DEBUG: RegistryTimelineRenderer - Processing component #" . $idx, 'debug');
                    if (isset($component['event_type'])) {
                        $this->logDebugMessage("ODCM DEBUG: Component event_type: " . $component['event_type'], 'debug');
                    }
                }
                $renderedComponent = $this->renderComponent($component);
            } catch (\Throwable $e) {
                // Never let a single component break the whole timeline
                $this->logDebugMessage("ODCM TIMELINE DEBUG: Component render threw exception: " . $e->getMessage(), 'error');
                $this->logDebugMessage("ODCM TIMELINE DEBUG: Exception stack trace: " . $e->getTraceAsString(), 'error');
                
                // For order events, add fallback rendering instead of empty content
                if ($isOrderEvent) {
                    $this->logDebugMessage("ODCM DEBUG: Providing fallback for order event with exception: " . $e->getMessage(), 'warning');
                    $renderedComponent = $this->generateOrderEventFallback($component, $eventType);
                } else {
                    $renderedComponent = '';
                }
            }
            
            if (!empty($renderedComponent)) {
                $html .= $renderedComponent;
                $renderedComponentCount++;
            } else if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM DEBUG: RegistryTimelineRenderer - Component #" . $idx . " rendered empty content", 'warning');
                
                // For order events with empty rendering, add a basic fallback
                if ($isOrderEvent) {
                    $this->logDebugMessage("ODCM DEBUG: Adding fallback for empty order component render", 'warning');
                    $fallback = $this->generateOrderEventFallback($component, $eventType);
                    $html .= $fallback;
                    $renderedComponentCount++;
                }
            }
        }
        
        $html .= '</div>';
        
        // If we didn't render any components for an order event, provide a zero-error fallback
        if ($isOrderEvent && $renderedComponentCount == 0) {
            $this->logDebugMessage("ODCM DEBUG: No components rendered for order event, providing zero-error fallback", 'warning');
            return $this->generateEmptyOrderFallback($eventType, $timeline->metadata);
        }
        
        return $html;
    }
    
    /**
     * Generate a fallback component for order events that failed to render
     * 
     * @param array $component The component that failed to render
     * @param string $eventType The overall event type
     * @return string Basic HTML to show key order information
     */
    private function generateOrderEventFallback(array $component, string $eventType): string
    {
        $label = $component['label'] ?? ucfirst($eventType);
        $timestamp = $this->formatTimestamp($component['ts'] ?? time());
        $level = $component['level'] ?? 'info';
        $orderId = $component['order_id'] ?? ($component['data']['order_id'] ?? null);
        
        $html = '<div class="odcm-timeline-component odcm-level-' . esc_attr($level) . ' odcm-fallback">';
        $html .= '<div class="odcm-timeline-header">';
        $html .= '<div class="odcm-timeline-timestamp">' . esc_html($timestamp) . '</div>';
        $html .= '<div class="odcm-timeline-title">' . esc_html($label) . ' <span class="odcm-fallback-badge">Fallback View</span></div>';
        $html .= '</div>';
        $html .= '<div class="odcm-timeline-body">';
        $html .= '<div class="odcm-timeline-message">';
        
        // Show order ID if available
        if ($orderId) {
            $html .= '<p><strong>Order ID:</strong> ' . esc_html($orderId) . '</p>';
        }
        
        // Show event type
        $componentEventType = $component['event_type'] ?? $eventType;
        $html .= '<p><strong>Event Type:</strong> ' . esc_html($componentEventType) . '</p>';
        
        // Add a standard message
        $html .= '<p>Order event details are available. This is a fallback view.</p>';
        
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Generate an empty order fallback when no components rendered successfully
     * 
     * @param string $eventType The overall event type
     * @param array $metadata The timeline metadata
     * @return string Basic HTML showing order information
     */
    private function generateEmptyOrderFallback(string $eventType, array $metadata): string
    {
        $orderId = $metadata['order_id'] ?? null;
        $label = odcm_get_component_label($eventType) ?? ucfirst(str_replace('_', ' ', $eventType));
        
        $html = '<div class="odcm-narrative-timeline">';
        $html .= '<div class="odcm-timeline-component odcm-level-info odcm-zero-error-fallback">';
        $html .= '<div class="odcm-timeline-header">';
        $html .= '<div class="odcm-timeline-timestamp">' . gmdate('Y-m-d H:i:s') . '</div>';
        $html .= '<div class="odcm-timeline-title">' . esc_html($label) . ' <span class="odcm-fallback-badge">Zero-Error Fallback</span></div>';
        $html .= '</div>';
        $html .= '<div class="odcm-timeline-body">';
        $html .= '<div class="odcm-timeline-message">';
        
        if ($orderId) {
            $html .= '<p><strong>Order ID:</strong> ' . esc_html($orderId) . '</p>';
        }
        
        $html .= '<p><strong>Event Type:</strong> ' . esc_html($eventType) . '</p>';
        $html .= '<p>This order event was processed, but detailed component visualization is not available.</p>';
        
        // Add any additional metadata that might be useful
        if (isset($metadata['timestamp'])) {
            $html .= '<p><strong>Timestamp:</strong> ' . esc_html($metadata['timestamp']) . '</p>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Format a timestamp value for display
     *
     * @param mixed $ts The timestamp to format
     * @return string Formatted timestamp
     */
    private function formatTimestamp($ts): string
    {
        if (is_numeric($ts)) {
            return gmdate('Y-m-d H:i:s', (int)$ts);
        } elseif (is_string($ts)) {
            return $ts;
        }

        return gmdate('Y-m-d H:i:s');
    }
    
    /**
     * Render individual component using registry system with debug filtering
     */
    private function renderComponent(array $payload): string
    {
        // Enhanced debugging for specific event types
        $isOrderEvent = false;
        if (isset($payload['event_type'])) {
            $eventType = $payload['event_type'];
            $isOrderEvent = strpos($eventType, 'checkout') !== false || 
                            strpos($eventType, 'order_') !== false || 
                            strpos($eventType, 'complete') !== false ||
                            strpos($eventType, 'completion') !== false;
            
            if ($isOrderEvent && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM DEBUG: RegistryTimelineRenderer - renderComponent for order event: " . $eventType, 'debug');
                $this->logDebugMessage("ODCM DEBUG: Order event payload keys: " . implode(', ', array_keys($payload)), 'debug');
            }
        }
        
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
                
                // Special debug logging for order events
                if ($isOrderEvent && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM DEBUG: Order event renderer mapping - event_type: '$event_type' -> renderer: '$rendererClass'", 'debug');
                }
            } catch (\Throwable $e) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: Registry lookup failed: " . $e->getMessage(), 'error');
                if ($isOrderEvent && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM DEBUG: Order event renderer lookup FAILED: " . $e->getMessage(), 'error');
                }
                $rendererClass = '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer';
            }
        } else {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Registry function missing, using FallbackRenderer", 'warning');
            if ($isOrderEvent && defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM DEBUG: Order event failed - odcm_get_renderer_for_event_type function missing", 'error');
            }
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
            
            if ($isOrderEvent) {
                $this->logDebugMessage("ODCM DEBUG: Class not found for order event renderer: " . $rendererClass, 'error');
                
                // For order events, use the OrderRenderer as first fallback before using the generic fallback
                $orderRendererClass = '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\OrderRenderer';
                if (class_exists($orderRendererClass)) {
                    $this->logDebugMessage("ODCM DEBUG: Using OrderRenderer as fallback", 'debug');
                    $renderer = new $orderRendererClass();
                } else {
                    $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
                }
            } else {
                $renderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
            }
            
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
                    // For order events, provide a fallback instead of returning empty
                    if ($isOrderEvent) {
                        $this->logDebugMessage("ODCM TIMELINE DEBUG: Providing fallback for empty order event render", 'warning');
                        if ($rendererClass !== '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer' &&
                            class_exists('\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\OrderRenderer')) {
                            // Try OrderRenderer as a fallback
                            $fallbackRenderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\OrderRenderer();
                            $fallbackResult = $fallbackRenderer->render($payload, $event_type, $timeline);
                            if (!empty($fallbackResult)) {
                                return $fallbackResult;
                            }
                        }
                        // If OrderRenderer also fails, use the generic fallback
                        $fallbackRenderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\FallbackRenderer();
                        return $fallbackRenderer->render($payload, $event_type, $timeline);
                    }
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
            
            // For order events, use OrderRenderer as fallback before generic fallback
            if ($isOrderEvent && 
                $rendererClass !== '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\OrderRenderer' &&
                $rendererClass !== '\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer' &&
                class_exists('\\OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\OrderRenderer')) {
                try {
                    $this->logDebugMessage("ODCM DEBUG: Attempting OrderRenderer as exception fallback");
                    $orderRenderer = new \OrderDaemon\CompletionManager\View\PayloadRenderer\OrderRenderer();
                    $timeline = [
                        'label' => $label,
                        'ts' => $ts,
                        'level' => $level
                    ];
                    $result = $orderRenderer->render($payload, $event_type, $timeline);
                    if (!empty($result)) {
                        return $result;
                    }
                } catch (\Throwable $orderExp) {
                    $this->logDebugMessage("ODCM DEBUG: OrderRenderer fallback also failed: " . $orderExp->getMessage(), 'error');
                }
            }
            
            // Final fallback is FallbackRenderer
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
        // Ensure OrderRenderer is available for order event fallbacks
        if (!class_exists('OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\OrderRenderer')) {
            try { require_once $renderer_dir . 'OrderRenderer.php'; } catch (\Throwable $e) {
                $this->logDebugMessage('ODCM TIMELINE DEBUG: Failed to load OrderRenderer.php: ' . $e->getMessage(), 'error');
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
        if (in_array($event_type, ['order_created', 'order_check_scheduled', 'order_loaded', 'checkout_processed'])) {
            return true;
        }

        return false;
    }
}
