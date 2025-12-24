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

        // Build parent-child relationship map for hierarchy visualization
        $hierarchyMap = $this->buildHierarchyMap($timeline->components);

        $html = '<div class="odcm-timeline-list">';
        $renderedComponentCount = 0;

        foreach ($timeline->components as $idx => $component) {
            try {
                // Get hierarchy info for this component
                $isParent = isset($hierarchyMap['parents'][$idx]);
                $isChild = isset($hierarchyMap['children'][$idx]);

                $renderedComponent = $this->renderComponent($component, $isParent, $isChild);
            } catch (\Throwable $e) {
                // Never let a single component break the whole timeline
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: Component render threw exception: " . $e->getMessage(), 'error');
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: Exception stack trace: " . $e->getTraceAsString(), 'error');
                }

                $renderedComponent = '';
            }

            if (!empty($renderedComponent)) {
                $html .= $renderedComponent;
                $renderedComponentCount++;
            }
        }

        $html .= '</div>';

        return $html;
    }
    
    /**
     * Build parent-child relationship map from timeline components
     * 
     * @param array $components The timeline components
     * @return array Map with 'parents' and 'children' indexes
     */
    private function buildHierarchyMap(array $components): array
    {
        $hierarchyMap = [
            'parents' => [], // component index => array of child indexes
            'children' => [] // component index => parent index
        ];
        
        // Add debug output when in debug mode
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Building hierarchy map for " . count($components) . " components", 'debug');
        }
        
        // Map component IDs to their array indexes for faster lookup
        $idToIndexMap = [];
        foreach ($components as $idx => $component) {
            // First check for direct ID field
            if (isset($component['id']) && $component['id'] !== null) {
                $idToIndexMap[$component['id']] = $idx;
            }
            // Also check log_id which is often used
            if (isset($component['log_id']) && $component['log_id'] !== null) {
                $idToIndexMap[$component['log_id']] = $idx;
            }
            // Some components use event_id
            if (isset($component['event_id']) && $component['event_id'] !== null) {
                $idToIndexMap[$component['event_id']] = $idx;
            }
            // Check nested data structure too
            if (isset($component['data']['id']) && $component['data']['id'] !== null) {
                $idToIndexMap[$component['data']['id']] = $idx;
            }
        }
        
        // Build parent-child relationships based on parent_id field and other related fields
        foreach ($components as $idx => $component) {
            // 1. Check for direct parent_id field at the top level
            $parentId = $component['parent_id'] ?? null;
            
            // 2. Check for parent_id in data array if available
            if (!$parentId && isset($component['data']['parent_id'])) {
                $parentId = $component['data']['parent_id'];
            }
            
            // 3. Check for parent_event_id field which is sometimes used
            if (!$parentId && isset($component['parent_event_id'])) {
                $parentId = $component['parent_event_id'];
            }
            
            // 4. Check for related_event_id field which is also used for hierarchy
            if (!$parentId && isset($component['related_event_id'])) {
                $parentId = $component['related_event_id'];
            }
            
            // 5. For rule executions, check for triggered_by_event
            if (!$parentId && isset($component['data']['triggered_by_event'])) {
                $parentId = $component['data']['triggered_by_event'];
            }
            
            // 6. Check the relation between status_changed and rule_execution events
            if (!$parentId && 
                isset($component['event_type']) && 
                $component['event_type'] === 'rule_execution' && 
                isset($component['data']['source_event_id'])) {
                $parentId = $component['data']['source_event_id'];
            }
            
            // If any valid parent ID was found
            if ($parentId) {
                // Try fast lookup using the id-to-index map
                if (isset($idToIndexMap[$parentId])) {
                    $parentIdx = $idToIndexMap[$parentId];
                    
                    // Mark this component as a child
                    $hierarchyMap['children'][$idx] = $parentIdx;
                    
                    // Mark the parent as having children
                    if (!isset($hierarchyMap['parents'][$parentIdx])) {
                        $hierarchyMap['parents'][$parentIdx] = [];
                    }
                    $hierarchyMap['parents'][$parentIdx][] = $idx;
                    
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage("ODCM TIMELINE DEBUG: Established parent-child: parent_idx=$parentIdx, child_idx=$idx", 'debug');
                    }
                } else {
                    // Fallback to full scan if not found in map - might be a different ID format
                    foreach ($components as $parentIdx => $parentComponent) {
                        // Try different ID fields to find a match
                        $componentId = $parentComponent['id'] ?? $parentComponent['log_id'] ?? 
                                       $parentComponent['event_id'] ?? 
                                       ($parentComponent['data']['id'] ?? null);
                        
                        if ($componentId && $componentId == $parentId) {
                            // Mark this component as a child
                            $hierarchyMap['children'][$idx] = $parentIdx;
                            
                            // Mark the parent as having children
                            if (!isset($hierarchyMap['parents'][$parentIdx])) {
                                $hierarchyMap['parents'][$parentIdx] = [];
                            }
                            $hierarchyMap['parents'][$parentIdx][] = $idx;
                            
                            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                                $this->logDebugMessage("ODCM TIMELINE DEBUG: Found parent via full scan: parent_idx=$parentIdx, child_idx=$idx", 'debug');
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        // 7. Use heuristics for components that don't have explicit parent IDs
        // For example: Rules executed right after a status change are likely related
        
        // Create timestamp-based index to match events happening in sequence
        $timeBasedComponents = [];
        foreach ($components as $idx => $component) {
            $ts = $component['ts'] ?? null;
            if ($ts) {
                if (!isset($timeBasedComponents[$ts])) {
                    $timeBasedComponents[$ts] = [];
                }
                $timeBasedComponents[$ts][] = $idx;
            }
        }
        
        // Process components chronologically
        $orderedTimestamps = array_keys($timeBasedComponents);
        sort($orderedTimestamps);
        
        $lastStatusChangeIdx = null;
        $lastCheckoutProcessedIdx = null;
        $lastOrderEventIdx = null;
        
        foreach ($orderedTimestamps as $ts) {
            foreach ($timeBasedComponents[$ts] as $idx) {
                $component = $components[$idx];
                $eventType = $component['event_type'] ?? '';
                
                // Skip components that already have a parent
                if (isset($hierarchyMap['children'][$idx])) {
                    continue;
                }
                
                // Track status change events
                if (strpos($eventType, 'status_changed') !== false) {
                    $lastStatusChangeIdx = $idx;
                }
                
                // Track checkout processed events
                if (strpos($eventType, 'checkout_processed') !== false) {
                    $lastCheckoutProcessedIdx = $idx;
                }
                
                // Track order events
                if (strpos($eventType, 'order_') !== false) {
                    $lastOrderEventIdx = $idx;
                }
                
                // Connect rule executions to status changes
                if (strpos($eventType, 'rule_execution') !== false && $lastStatusChangeIdx !== null &&
                    !isset($hierarchyMap['children'][$idx])) {
                    
                    // Check if the rule is responding to the last status change
                    $ruleCreatedTime = $component['ts'] ?? 0;
                    $statusChangeTime = $components[$lastStatusChangeIdx]['ts'] ?? 0;
                    
                    // If rule was executed right after a status change (within 5 seconds)
                    if (abs($ruleCreatedTime - $statusChangeTime) <= 5) {
                        $hierarchyMap['children'][$idx] = $lastStatusChangeIdx;
                        
                        if (!isset($hierarchyMap['parents'][$lastStatusChangeIdx])) {
                            $hierarchyMap['parents'][$lastStatusChangeIdx] = [];
                        }
                        $hierarchyMap['parents'][$lastStatusChangeIdx][] = $idx;
                        
                        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                            $this->logDebugMessage("ODCM TIMELINE DEBUG: Inferred rule-status relationship: parent=$lastStatusChangeIdx, child=$idx", 'debug');
                        }
                    }
                }
                
                // Connect payment processing to checkout events
                if (strpos($eventType, 'payment') !== false && $lastCheckoutProcessedIdx !== null &&
                    !isset($hierarchyMap['children'][$idx])) {
                    
                    $hierarchyMap['children'][$idx] = $lastCheckoutProcessedIdx;
                    
                    if (!isset($hierarchyMap['parents'][$lastCheckoutProcessedIdx])) {
                        $hierarchyMap['parents'][$lastCheckoutProcessedIdx] = [];
                    }
                    $hierarchyMap['parents'][$lastCheckoutProcessedIdx][] = $idx;
                    
                    if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                        $this->logDebugMessage("ODCM TIMELINE DEBUG: Inferred payment-checkout relationship: parent=$lastCheckoutProcessedIdx, child=$idx", 'debug');
                    }
                }
            }
        }
        
        // Log the final hierarchy map for debugging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Final hierarchy map - Parents: " . count($hierarchyMap['parents']) . ", Children: " . count($hierarchyMap['children']), 'debug');
        }
        
        return $hierarchyMap;
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
        
        $html = '<div class="odcm-component odcm-level-' . esc_attr($level) . ' odcm-fallback">';
        $html .= '<div class="odcm-component__header">';
        $html .= '<div class="odcm-component__header-top">';
        $html .= '<div class="odcm-component__header-left">';
        $html .= '<div class="odcm-component__title">' . esc_html($label) . ' <span class="odcm-fallback-badge">Fallback View</span></div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="odcm-component__header-bottom">';
        $html .= '<span class="odcm-component__ts">' . esc_html($timestamp) . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="odcm-component__body">';
        
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
        
        $html = '<div class="odcm-timeline-list">';
        $html .= '<div class="odcm-component odcm-level-info odcm-zero-error-fallback">';
        $html .= '<div class="odcm-component__header">';
        $html .= '<div class="odcm-component__header-top">';
        $html .= '<div class="odcm-component__header-left">';
        $html .= '<div class="odcm-component__title">' . esc_html($label) . ' <span class="odcm-fallback-badge">Zero-Error Fallback</span></div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="odcm-component__header-bottom">';
        $html .= '<span class="odcm-component__ts">' . gmdate('Y-m-d H:i:s') . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="odcm-component__body">';
        
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
     * Render individual component using DisplayAdapter system with three-tier architecture
     *
     * @param array $payload The component payload data
     * @param bool $isParent Whether this component is a parent (has children)
     * @param bool $isChild Whether this component is a child (has parent_id)
     * @return string Rendered HTML with hierarchy CSS classes applied
     */
    private function renderComponent(array $payload, bool $isParent = false, bool $isChild = false): string
    {
        // Debug Event Filtering - hide debug events in production
        if ($this->shouldFilterDebugEvent($payload)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - Debug event hidden in production mode");
            }
            return '';
        }

        // Extract event type for debugging
        $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'unknown';
        $this->logDebugMessage("ODCM TIMELINE DEBUG: Processing event type: {$eventType}", 'debug');

        // NEW: Get appropriate adapter and extract standardized data
        try {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Calling AdapterRegistry::getAdapterForEvent()", 'debug');
            $adapter = AdapterRegistry::getAdapterForEvent($payload);
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Got adapter: " . get_class($adapter), 'debug');

            $this->logDebugMessage("ODCM TIMELINE DEBUG: Calling extractDisplayData()", 'debug');
            $displayData = $adapter->extractDisplayData($payload);
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Successfully extracted display data", 'debug');
        } catch (\Throwable $e) {
            $this->logDebugMessage('Failed to extract display data: ' . $e->getMessage(), 'error');
            $this->logDebugMessage('Exception trace: ' . $e->getTraceAsString(), 'error');
            return $this->renderFallbackComponent($payload, $isParent, $isChild);
        }

        // NEW: Render using three-tier architecture
        $result = $this->renderThreeTierComponent($displayData, $payload);

        // EXISTING: Apply hierarchy classes
        return $this->applyHierarchyClasses($result, $isParent, $isChild);
    }

    /**
     * Render component using three-tier architecture
     *
     * @param array $displayData The extracted display data from adapter
     * @param array $rawPayload The original event payload
     * @return string Rendered HTML component
     */
    private function renderThreeTierComponent(array $displayData, array $rawPayload): string
    {
        $html = '<div class="odcm-component">';
        
        // Extract basic info
        $timestamp = $this->formatTimestamp($rawPayload['ts'] ?? time());
        $level = $rawPayload['level'] ?? 'info';
        
        // Header with component header structure
        $html .= '<div class="odcm-component__header">';
        $html .= '<div class="odcm-component__header-top">';
        $html .= '<div class="odcm-component__header-left">';
        $html .= '<div class="odcm-component__title">' . $this->renderPrimaryInfo($displayData) . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="odcm-component__header-bottom">';
        $html .= '<span class="odcm-component__ts">' . esc_html($timestamp) . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Body with three tiers
        $html .= '<div class="odcm-component__body">';
        
        // Tier 1: Primary (always visible)
        $html .= $this->renderPrimaryTier($displayData['display_sections'] ?? []);
        
        // Tier 2: Contextual (expandable)
        if (!empty($displayData['detail_sections'])) {
            $html .= $this->renderContextualTier($displayData['detail_sections']);
        }
        
        // Tier 3: Technical (expandable)
        $html .= $this->renderTechnicalTier($rawPayload);
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Render primary information for header
     *
     * @param array $displayData The display data from adapter
     * @return string Rendered primary info HTML
     */
    private function renderPrimaryInfo(array $displayData): string
    {
        $sections = $displayData['display_sections'] ?? [];
        
        // Extract key information for header
        $title = $sections['event_description']['value'] ?? 
                $sections['event_type']['value'] ?? 
                __('Timeline Event', 'order-daemon');
        $orderId = $sections['order_id']['value'] ?? null;
        
        $html = esc_html($title);
        if ($orderId && $orderId !== 0) {
            $orderDisplay = is_numeric($orderId) ? '#' . $orderId : $orderId;
            $html .= ' <span class="odcm-status-pill">' . esc_html($orderDisplay) . '</span>';
        }
        
        return $html;
    }

    /**
     * Render primary tier (always visible)
     *
     * @param array $displaySections The display sections
     * @return string Rendered primary tier HTML
     */
    private function renderPrimaryTier(array $displaySections): string
    {
        if (empty($displaySections)) {
            return '';
        }
        
        $html = '<div class="odcm-key-value-list">';
        
        foreach ($displaySections as $key => $section) {
            if ($key === 'event_description' || $key === 'order_id') {
                continue; // Already shown in header
            }
            
            $html .= '<div class="odcm-key">' . esc_html($section['label']) . '</div>';
            $html .= '<div class="odcm-value">' . esc_html($section['value']) . '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Render contextual tier (expandable)
     *
     * @param array $detailSections The detail sections
     * @return string Rendered contextual tier HTML
     */
    private function renderContextualTier(array $detailSections): string
    {
        $html = '<div class="odcm-expandable-section">';
        $html .= '<button type="button" class="odcm-icon-button odcm-tier-toggle" data-target="contextual" aria-expanded="false">' . 
                 esc_html__('Show Details', 'order-daemon') . '</button>';
        $html .= '<div class="odcm-tier-content" style="display: none;">';
        
        foreach ($detailSections as $sectionKey => $section) {
            $html .= '<div class="odcm-detail-section">';
            $html .= '<h4 class="odcm-section-title">' . esc_html($section['label']) . '</h4>';
            
            if (!empty($section['data'])) {
                $html .= '<div class="odcm-key-value-list">';
                foreach ($section['data'] as $field) {
                    $html .= '<div class="odcm-key">' . esc_html($field['label']) . '</div>';
                    $html .= '<div class="odcm-value">' . esc_html($field['value']) . '</div>';
                }
                $html .= '</div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Render technical tier (expandable)
     *
     * @param array $rawPayload The raw event payload
     * @return string Rendered technical tier HTML
     */
    private function renderTechnicalTier(array $rawPayload): string
    {
        $html = '<div class="odcm-expandable-section">';
        $html .= '<button type="button" class="odcm-icon-button odcm-tier-toggle" data-target="technical" aria-expanded="false">' .
                 esc_html__('Show Technical Details', 'order-daemon') . '</button>';
        $html .= '<div class="odcm-tier-content" style="display: none;">';
        
        // Format raw payload as JSON with proper prism.js classes
        $jsonPayload = wp_json_encode($rawPayload, JSON_PRETTY_PRINT);
        $html .= '<div class="odcm-code-block"><pre><code class="language-json">' . esc_html($jsonPayload) . '</code></pre></div>';
        
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render fallback component when adapter fails
     *
     * @param array $payload The component payload data
     * @param bool $isParent Whether this component is a parent
     * @param bool $isChild Whether this component is a child
     * @return string Rendered fallback HTML
     */
    private function renderFallbackComponent(array $payload, bool $isParent, bool $isChild): string
    {
        $label = $payload['label'] ?? $payload['event_type'] ?? __('Timeline Event', 'order-daemon');
        $timestamp = $this->formatTimestamp($payload['ts'] ?? time());
        $level = $payload['level'] ?? 'info';
        
        $html = '<div class="odcm-component odcm-fallback">';
        $html .= '<div class="odcm-component__header">';
        $html .= '<div class="odcm-component__header-top">';
        $html .= '<div class="odcm-component__header-left">';
        $html .= '<div class="odcm-component__title">' . esc_html($label) . 
                 ' <span class="odcm-fallback-badge">' . esc_html__('Fallback View', 'order-daemon') . '</span></div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="odcm-component__header-bottom">';
        $html .= '<span class="odcm-component__ts">' . esc_html($timestamp) . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="odcm-component__body">';
        $html .= '<p>' . esc_html__('Event data is available but could not be processed normally.', 'order-daemon') . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $this->applyHierarchyClasses($html, $isParent, $isChild);
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
     * Apply hierarchy CSS classes to rendered timeline component HTML
     * 
     * @param string $html The rendered HTML to modify
     * @param bool $isParent Whether this component is a parent (has children)
     * @param bool $isChild Whether this component is a child (has parent_id)
     * @return string The HTML with hierarchy CSS classes applied
     */
    private function applyHierarchyClasses(string $html, bool $isParent, bool $isChild): string
    {
        // If neither parent nor child, return original HTML
        if (!$isParent && !$isChild) {
            return $html;
        }
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Applying hierarchy classes - isParent: " . ($isParent ? 'YES' : 'NO') . ", isChild: " . ($isChild ? 'YES' : 'NO'), 'debug');
        }
        
        // Build classes to add
        $hierarchyClasses = [];
        if ($isParent) {
            $hierarchyClasses[] = 'is-parent';
        }
        if ($isChild) {
            $hierarchyClasses[] = 'is-child';
        }
        
        // Convert classes array to string
        $classString = implode(' ', $hierarchyClasses);
        
        // Look for component containers to add classes to
        // The main pattern is: <div class="odcm-component ...">
        $pattern = '/(<div[^>]*class="[^"]*odcm-component[^"]*")/i';
        
        $html = preg_replace_callback($pattern, function($matches) use ($classString) {
            $openingTag = $matches[1];
            
            // Add hierarchy classes to the existing class attribute
            $modifiedTag = preg_replace(
                '/(class="[^"]*)"/',
                '$1 ' . $classString . '"',
                $openingTag
            );
            
            return $modifiedTag;
        }, $html);
        
        // Add debug output after regex replacement attempt
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $result = (strpos($html, 'is-parent') !== false || strpos($html, 'is-child') !== false);
            $this->logDebugMessage("ODCM TIMELINE DEBUG: First regex replacement result: " . ($result ? 'SUCCESS' : 'FAILED'), 'debug');
            
            // Debug the HTML pattern we're trying to match
            $debug_pattern = preg_match('/class="[^"]*odcm-component[^"]*"/i', $html);
            $this->logDebugMessage("ODCM TIMELINE DEBUG: HTML contains odcm-component class: " . ($debug_pattern ? 'YES' : 'NO'), 'debug');
            
            // Output a sample of the HTML for debugging
            $htmlSample = substr($html, 0, 200) . '...';
            $this->logDebugMessage("ODCM TIMELINE DEBUG: HTML sample: " . $htmlSample, 'debug');
        }
        
        // Fallback: if no odcm-component found, look for any component or timeline container
        if (strpos($html, 'is-parent') === false && strpos($html, 'is-child') === false) {
            // Try broader patterns for other component containers
            $patterns = [
                '/(<div[^>]*class="[^"]*component[^"]*")/i',
                '/(<div[^>]*class="[^"]*timeline[^"]*")/i',
                '/(<div[^>]*class="[^"]*odcm-[^"]*")/i'
            ];
            
            foreach ($patterns as $pattern) {
                $html = preg_replace_callback($pattern, function($matches) use ($classString) {
                    $openingTag = $matches[1];
                    
                    // Add hierarchy classes to the existing class attribute
                    $modifiedTag = preg_replace(
                        '/(class="[^"]*)"/',
                        '$1 ' . $classString . '"',
                        $openingTag
                    );
                    
                    return $modifiedTag;
                }, $html);
                
                // If we successfully added classes, break out of the loop
                if (strpos($html, 'is-parent') !== false || strpos($html, 'is-child') !== false) {
                    break;
                }
            }
        }
        
        // Final fallback: if still no classes applied, wrap the entire content
        if (strpos($html, 'is-parent') === false && strpos($html, 'is-child') === false) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: All regex patterns failed, using wrapper fallback", 'debug');
            }
            
            // Final desperate attempt - directly inject the classes into the first div
            if (preg_match('/<div[^>]*class="[^"]*"/', $html)) {
                $html = preg_replace(
                    '/<div([^>]*?)class="([^"]*)"/', 
                    '<div$1class="$2 ' . $classString . '"', 
                    $html, 
                    1  // Replace only the first occurrence
                );
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $result = (strpos($html, 'is-parent') !== false || strpos($html, 'is-child') !== false);
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: Direct class injection: " . ($result ? 'SUCCESS' : 'FAILED'), 'debug');
                }
            }
            
            // If still nothing worked, wrap everything
            if (strpos($html, 'is-parent') === false && strpos($html, 'is-child') === false) {
                $wrapperClasses = 'odcm-component ' . $classString;
                $html = '<div class="' . esc_attr($wrapperClasses) . '">' . $html . '</div>';
                
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: Applied wrapper div with classes", 'debug');
                }
            }
        } else if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Successfully applied hierarchy classes", 'debug');
        }
        
        return $html;
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

        // Hide ONLY truly technical debug events (not business events)
        if (in_array($event_type, [
            'order_check_scheduled',  // Internal scheduling, not business-relevant
            'rule_evaluation_non_canonical', // Debug traces for rule evaluation
            '_status_evaluation',     // Debug events for status change evaluation
            'process_started',        // Technical process lifecycle events
            'order_loaded'           // Purely technical loading event
        ])) {
            return true;
        }

        return false;
    }
}
