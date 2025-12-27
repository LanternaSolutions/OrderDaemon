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
     * Render primary information for header with proper status pill
     *
     * @param array $displayData The display data from adapter
     * @param array $rawPayload The original event payload
     * @return string Rendered primary info HTML
     */
    private function renderPrimaryInfo(array $displayData, array $rawPayload): string
    {
        $sections = $displayData['display_sections'] ?? [];

        // Extract key information for header
        $title = $sections['event_description']['value'] ?? 
                $sections['event_type']['value'] ?? 
                __('Timeline Event', 'order-daemon');

        // Extract primary status for status pill
        $statusData = $this->extractPrimaryStatus($displayData, $rawPayload);
        $statusPill = null;
        if ($statusData) {
            $statusPill = $this->renderStatusPill($statusData['label'], $statusData['type']);
        }

        // Get event type configuration for icon
        $eventType = $rawPayload['event_type'] ?? 'unknown';
        $eventConfig = $this->getEventTypeConfig($eventType);
        $dashicon = $eventConfig['dashicon'] ?? 'dashicons-admin-generic';
        $themeClass = $eventConfig['theme_class'] ?? 'odcm-component--system';

        // Build HTML with proper structure for right-aligned status pills
        $html = '<div class="odcm-component__header-left">';
        $html .= '<span class="odcm-component-icon dashicons ' . esc_attr($dashicon) . '"></span>';
        $html .= '<span class="odcm-component__title">' . esc_html($title) . '</span>';
        $html .= '</div>';

        // Add status pill in right-aligned container if available
        if ($statusPill) {
            $html .= '<div class="odcm-component__header-right">' . $statusPill . '</div>';
        }

        return $html;
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
            // Handle string timestamps - try to parse them first
            if (is_numeric($ts)) {
                // String representation of a number
                return gmdate('Y-m-d H:i:s', (int)$ts);
            } elseif (strtotime($ts) !== false) {
                // Parseable date string
                return gmdate('Y-m-d H:i:s', strtotime($ts));
            } else {
                // Fallback for unparseable strings - return as-is but this shouldn't happen with valid data
                return $ts;
            }
        }

        // For invalid/empty timestamps, return a placeholder instead of current time
        return 'Invalid timestamp';
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
     * Render component using unified business data architecture
     *
     * This method displays all business-relevant data in a single section
     * without subtitles, and technical details are clearly labeled but separate.
     *
     * @param array $displayData The extracted display data from adapter
     * @param array $rawPayload The original event payload
     * @return string Rendered HTML component
     */
    private function renderThreeTierComponent(array $displayData, array $rawPayload): string
    {
        // Get event type configuration for theme class
        $eventType = $rawPayload['event_type'] ?? 'unknown';
        $eventConfig = $this->getEventTypeConfig($eventType);
        $themeClass = $eventConfig['theme_class'] ?? 'odcm-component--system';

        $html = '<div class="odcm-component ' . esc_attr($themeClass) . '">';

        // Extract basic info
        $timestamp = $this->formatTimestamp($rawPayload['ts'] ?? time());
        $level = $rawPayload['level'] ?? 'info';

        // Header with component header structure
        $html .= '<div class="odcm-component__header">';
        $html .= '<div class="odcm-component__header-top">';
        $html .= '<div class="odcm-component__header-left">';
        $html .= '<div class="odcm-component__title">' . $this->renderPrimaryInfo($displayData, $rawPayload) . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        // Body with unified business section and improved technical section
        $html .= '<div class="odcm-component__body">';

        // Unified Business Section: All business-relevant data in one clean section
        $html .= $this->renderUnifiedBusinessSection($displayData['display_sections'] ?? []);

        // Improved Technical Section: Clear labeling, complete raw data
        $html .= $this->renderImprovedTechnicalSection($rawPayload);

        $html .= '</div>';
        $html .= '</div>';

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
     * Render unified business section with all business-relevant data
     *
     * This method creates a section that contains all business-relevant fields.
     *
     * @param array $displaySections The display sections containing business data
     * @return string Rendered unified business section HTML
     */
    private function renderUnifiedBusinessSection(array $displaySections): string
    {
        if (empty($displaySections)) {
            return '';
        }

        $html = '<div class="odcm-key-value-list">';

        foreach ($displaySections as $key => $section) {
            // Skip event_description as it's already shown in the header
            if ($key === 'event_description' || $key === 'order_id') {
                continue;
            }

            $html .= '<div class="odcm-key">' . esc_html($section['label']) . '</div>';
            $html .= '<div class="odcm-value">' . esc_html($section['value']) . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render improved technical section with clearer labeling
     *
     * This method creates a "Technical Information" section that clearly indicates
     * it contains complete raw event data for debugging and analysis.
     *
     * @param array $rawPayload The raw event payload
     * @return string Rendered improved technical section HTML
     */
    private function renderImprovedTechnicalSection(array $rawPayload): string
    {
        $html = '<div class="odcm-expandable-section">';
        $html .= '<button type="button" class="odcm-icon-button odcm-tier-toggle" data-target="technical" aria-expanded="false">' .
                 esc_html__('Show Technical Information', 'order-daemon') . '</button>';
        $html .= '<div class="odcm-tier-content">';

        // Add clear, general header for technical information
        $html .= '<div class="odcm-technical-header">';
        $html .= '<h4>' . esc_html__('Raw event debug data for analysis', 'order-daemon') . '</h4>';
        $html .= '</div>';

        // Format raw payload as JSON with proper prism.js classes
        $jsonPayload = wp_json_encode($rawPayload, JSON_PRETTY_PRINT);
        $html .= '<div class="odcm-code-block"><pre><code class="language-json">' . esc_html($jsonPayload) . '</code></pre></div>';

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render business-relevant detail sections in the primary tier - DEPRECATED
     *
     * This method is kept for backward compatibility but no longer used.
     * The new unified approach uses renderUnifiedBusinessSection() instead.
     *
     * @param array $detailSections The detail sections
     * @return string Rendered business detail sections HTML
     * @deprecated Use renderUnifiedBusinessSection() instead
     */
    private function renderBusinessDetailSections(array $detailSections): string
    {
        if (empty($detailSections)) {
            return '';
        }

        $html = '<div class="odcm-business-details">';

        foreach ($detailSections as $sectionKey => $section) {
            // Only show sections that contain business-relevant data
            // Skip technical sections that should be in the expandable technical tier
            if (isset($section['is_technical']) && $section['is_technical']) {
                continue;
            }

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

        return $html;
    }

    /**
     * Render contextual tier (expandable) - DEPRECATED
     * This method is kept for backward compatibility but no longer used
     *
     * @param array $detailSections The detail sections
     * @return string Rendered contextual tier HTML
     * @deprecated Use renderBusinessDetailSections() instead
     */
    private function renderContextualTier(array $detailSections): string
    {
        // This method is now deprecated as we've consolidated to a single technical tier
        return '';
    }

    /**
     * Render technical tier (expandable) - consolidated to include all developer-focused details
     *
     * @param array $rawPayload The raw event payload
     * @return string Rendered technical tier HTML
     */
    private function renderTechnicalTier(array $rawPayload): string
    {
        $html = '<div class="odcm-expandable-section">';
        $html .= '<button type="button" class="odcm-icon-button odcm-tier-toggle" data-target="technical" aria-expanded="false">' .
                 esc_html__('Show Technical Details', 'order-daemon') . '</button>';
        $html .= '<div class="odcm-tier-content">';

        // Add developer-relevant details header
        $html .= '<div class="odcm-technical-header">';
        $html .= '<h4>' . esc_html__('Developer Details', 'order-daemon') . '</h4>';
        $html .= '<p>' . esc_html__('Technical information and raw data for debugging purposes.', 'order-daemon') . '</p>';
        $html .= '</div>';

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

    /**
     * Log debug messages using WordPress-friendly logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    private function logDebugMessage(string $message, string $level = 'debug'): void
    {
        // Only log if debug is enabled
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }

        // Prefix for all debug messages from this class
        $prefix = "ODCM TIMELINE: [{$level}] ";

        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($prefix . $message, $level);
            return;
        }

        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($prefix . $message);
            return;
        }

        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_' . $level, $prefix . $message);
            return;
        }

        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
            $debug_file = WP_CONTENT_DIR . '/debug.log';
            @file_put_contents(
                $debug_file,
                '[' . gmdate('Y-m-d H:i:s') . '] ' . $prefix . $message . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    /**
     * Generate status pill HTML for timeline component headers
     *
     * @param string $label Display text for the status pill
     * @param string $status_type Status type for CSS theming
     * @return string HTML status pill element
     */
    private function renderStatusPill(string $label, string $status_type): string
    {
        // Map semantic types to existing pill variants
        $pill_variant_map = [
            'error' => 'error',
            'warning' => 'warning',
            'success' => 'success',
            'info' => 'info',
            'completed' => 'completed',
            'pending' => 'pending',
            'skipped' => 'skipped'
        ];

        // Get the appropriate pill variant, default to 'info' for unknown types
        $pill_class = $pill_variant_map[strtolower($status_type)] ?? 'info';

        return '<span class="odcm-status-pill odcm-status-pill--' . esc_attr($pill_class) . '">' .
               esc_html($label) . '</span>';
    }

    /**
     * Map status value to appropriate status pill type
     *
     * @param string $eventType The event type
     * @param string $statusValue The status value
     * @return string Status pill type
     */
    private function mapStatusToPillType(string $eventType, string $statusValue): string
    {
        $statusMap = [
            // Order statuses
            'pending' => 'info',
            'processing' => 'info',
            'on-hold' => 'warning',
            'completed' => 'success',
            'cancelled' => 'warning',
            'refunded' => 'info',
            'failed' => 'error',

            // Payment statuses
            'paid' => 'success',
            'completed' => 'success',
            'failed' => 'error',
            'pending' => 'info',
            'processing' => 'info',
            'refunded' => 'info',
            'cancelled' => 'warning',

            // Rule execution statuses
            'success' => 'success',
            'failed' => 'error',
            'skipped' => 'info',
            'executed' => 'success',

            // Generic statuses
            'error' => 'error',
            'warning' => 'warning',
            'info' => 'info',
            'debug' => 'info'
        ];

        return $statusMap[strtolower($statusValue)] ?? 'info';
    }

    /**
     * Extract primary status from display data for status pill
     *
     * @param array $displayData The display data from adapter
     * @param array $rawPayload The original event payload
     * @return array|null Array with 'label' and 'type' for status pill, or null if no status
     */
    private function extractPrimaryStatus(array $displayData, array $rawPayload): ?array
    {
        $eventType = $rawPayload['event_type'] ?? 'unknown';

        // Special handling for checkout_processed events - should not show status pill per spec
        if ($eventType === 'checkout_processed') {
            return null;
        }

        // Special handling for status_changed events - should not show status pill as it's redundant
        if ($eventType === 'status_changed') {
            return null;
        }

        // Try to extract status from display sections first
        $statusFields = ['status', 'order_status', 'payment_status', 'execution_status', 'status_change'];

        foreach ($statusFields as $field) {
            if (isset($displayData['display_sections'][$field])) {
                $statusValue = $displayData['display_sections'][$field]['value'] ?? '';

                // Map status to pill type based on event type
                $pillType = $this->mapStatusToPillType($eventType, $statusValue);

                return [
                    'label' => $statusValue,
                    'type' => $pillType
                ];
            }
        }

        // Fallback: try to extract from raw payload
        if (isset($rawPayload['status'])) {
            $pillType = $this->mapStatusToPillType($eventType, $rawPayload['status']);
            return [
                'label' => $rawPayload['status'],
                'type' => $pillType
            ];
        }

        return null;
    }

    /**
     * Get event type configuration
     *
     * @param string $event_type The event type
     * @return array Event configuration
     */
    private function getEventTypeConfig(string $event_type): array
    {
        $event_configs = [
            // Order events
            'order_created' => [
                'dashicon' => 'dashicons-plus-alt',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'pending',
                'priority' => 4,
                'category' => 'Order Lifecycle'
            ],
            'order_updated' => [
                'dashicon' => 'dashicons-update',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'updated',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_completed' => [
                'dashicon' => 'dashicons-yes',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'completed',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_cancelled' => [
                'dashicon' => 'dashicons-no',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'cancelled',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_refunded' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refunded',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_processing' => [
                'dashicon' => 'dashicons-update',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'processing',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_on_hold' => [
                'dashicon' => 'dashicons-pause',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'on hold',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'order_pending' => [
                'dashicon' => 'dashicons-cart',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'pending',
                'priority' => 4,
                'category' => 'Order Lifecycle'
            ],
            'order_failed' => [
                'dashicon' => 'dashicons-warning',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'failed',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'status_changed' => [
                'dashicon' => 'dashicons-migrate',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => '→ completed',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'checkout_processed' => [
                'dashicon' => 'dashicons-cart',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'checkout-draft',
                'priority' => 3,
                'category' => 'Payment'
            ],
            // Payment events
            'payment_completed' => [
                'dashicon' => 'dashicons-money-alt',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'completed',
                'priority' => 3,
                'category' => 'Payment'
            ],
            'payment_failed' => [
                'dashicon' => 'dashicons-money-alt',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'failed',
                'priority' => 3,
                'category' => 'Payment'
            ],
            // Rule execution events
            'rule_execution' => [
                'dashicon' => 'dashicons-yes-alt',
                'theme_class' => 'odcm-component--rule',
                'primary_color' => 'blue-700',
                'status_display' => 'success',
                'priority' => 2,
                'category' => 'Rule'
            ],
            'rule_evaluation_non_canonical' => [
                'dashicon' => 'dashicons-admin-generic',
                'theme_class' => 'odcm-component--rule',
                'primary_color' => 'blue-700',
                'status_display' => 'non-canonical',
                'priority' => 2,
                'category' => 'Rule'
            ],
            // System events
            'admin_action' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'admin',
                'priority' => 1,
                'category' => 'System'
            ],
            'process_started' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'started',
                'priority' => 1,
                'category' => 'System'
            ],
            'info' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'info',
                'priority' => 1,
                'category' => 'System'
            ],
            'metrics' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'metrics',
                'priority' => 1,
                'category' => 'System'
            ],
            'system_info' => [
                'dashicon' => 'dashicons-admin-tools',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'system',
                'priority' => 1,
                'category' => 'System'
            ],
            // Refund events
            'refund_created' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refunded',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'refund_deleted' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refund deleted',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            'refund_analysis' => [
                'dashicon' => 'dashicons-undo',
                'theme_class' => 'odcm-component--order',
                'primary_color' => 'purple-700',
                'status_display' => 'refund analysis',
                'priority' => 3,
                'category' => 'Order Lifecycle'
            ],
            // Subscription events
            'subscription_created' => [
                'dashicon' => 'dashicons-calendar',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'recurring',
                'priority' => 2,
                'category' => 'System'
            ],
            // Webhook events
            'webhook_received' => [
                'dashicon' => 'dashicons-networking',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'webhook',
                'priority' => 1,
                'category' => 'System'
            ],
            // Condition events
            'condition_passed' => [
                'dashicon' => 'dashicons-yes-alt',
                'theme_class' => 'odcm-component--rule',
                'primary_color' => 'blue-700',
                'status_display' => 'passed',
                'priority' => 2,
                'category' => 'Rule'
            ],
            'condition_failed' => [
                'dashicon' => 'dashicons-no',
                'theme_class' => 'odcm-component--rule',
                'primary_color' => 'blue-700',
                'status_display' => 'failed',
                'priority' => 2,
                'category' => 'Rule'
            ],
            // Error/Debug events
            'fallback' => [
                'dashicon' => 'dashicons-warning',
                'theme_class' => 'odcm-component--error',
                'primary_color' => 'red-700',
                'status_display' => 'error',
                'priority' => 1,
                'category' => 'System'
            ],
            'debug' => [
                'dashicon' => 'dashicons-warning',
                'theme_class' => 'odcm-component--error',
                'primary_color' => 'red-700',
                'status_display' => 'debug',
                'priority' => 1,
                'category' => 'System'
            ],
            '_status_evaluation' => [
                'dashicon' => 'dashicons-warning',
                'theme_class' => 'odcm-component--error',
                'primary_color' => 'red-700',
                'status_display' => 'evaluation',
                'priority' => 1,
                'category' => 'System'
            ],
            // Universal events
            'universal_event_processing' => [
                'dashicon' => 'dashicons-admin-generic',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'processed',
                'priority' => 1,
                'category' => 'System'
            ],
            'universal_event_duplicate' => [
                'dashicon' => 'dashicons-admin-generic',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'duplicate',
                'priority' => 1,
                'category' => 'System'
            ]
        ];

        // Check for exact match
        if (isset($event_configs[$event_type])) {
            return $event_configs[$event_type];
        }

        // Check for patterns
        if (strpos($event_type, 'payment.stripe.') === 0) {
            return [
                'dashicon' => 'dashicons-money-alt',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'payment',
                'priority' => 3,
                'category' => 'Payment'
            ];
        }

        if (strpos($event_type, 'payment.paypal.') === 0) {
            return [
                'dashicon' => 'dashicons-money-alt',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'payment',
                'priority' => 3,
                'category' => 'Payment'
            ];
        }

        if (strpos($event_type, 'payment.') === 0) {
            return [
                'dashicon' => 'dashicons-money-alt',
                'theme_class' => 'odcm-component--payment',
                'primary_color' => 'green-700',
                'status_display' => 'payment',
                'priority' => 3,
                'category' => 'Payment'
            ];
        }

        if (strpos($event_type, 'subscription_') === 0) {
            return [
                'dashicon' => 'dashicons-calendar',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'recurring',
                'priority' => 2,
                'category' => 'System'
            ];
        }

        if (strpos($event_type, 'webhook_') === 0) {
            return [
                'dashicon' => 'dashicons-networking',
                'theme_class' => 'odcm-component--system',
                'primary_color' => 'grey-700',
                'status_display' => 'webhook',
                'priority' => 1,
                'category' => 'System'
            ];
        }

        // Default fallback
        return [
            'dashicon' => 'dashicons-admin-generic',
            'theme_class' => 'odcm-component--system',
            'primary_color' => 'grey-700',
            'status_display' => 'event',
            'priority' => 1,
            'category' => 'System'
        ];
    }
}
