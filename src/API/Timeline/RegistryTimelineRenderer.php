<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Timeline renderer using the DisplayAdapter system
 *
 * This class implements the modern timeline rendering system using DisplayAdapters
 * and has been migrated away from the legacy PayloadComponentRegistry system.
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
        // Use the unified method to get consistent title and status
        $unifiedData = DisplayAdapter::generateUnifiedEventData($rawPayload, []);

        $title = $unifiedData['summary'];
        $statusData = $unifiedData['status'];

        // Extract primary status for status pill
        $statusPill = null;
        if ($statusData) {
            $statusPill = DisplayAdapter::renderStatusPill($statusData['label'], $statusData['type']);
        }

        // Get event type configuration for icon
        $eventType = $rawPayload['event_type'] ?? 'unknown';
        $eventConfig = DisplayAdapter::getEventTypeConfig($eventType);
        $dashicon = $eventConfig['dashicon'] ?? 'dashicons-admin-generic';
        $themeClass = $eventConfig['theme_class'] ?? 'odcm-component--system';

        $html = '<div class="odcm-component__header-left">';
        $html .= '<span class="odcm-component-icon dashicons ' . esc_attr($dashicon) . '"></span>';
        $html .= '<span class="odcm-component__title">' . esc_html($title) . '</span>';

        // Add status pill in right-aligned container if available
        if ($statusPill) {
            $html .= '<div class="odcm-component__header-right">' . $statusPill . '</div>';
        }

        $html .= '</div>';

        return $html;
    }
    /**
     * Render timeline data to HTML
     */
    public function renderTimeline(TimelineData $timeline, bool $includeDebug = false): string
    {
        if (!$timeline->hasComponents()) {
            return $this->renderEmptyTimeline($timeline);
        }

        $hierarchyMap = $this->buildHierarchyMap($timeline->components);
        $nodes = [];

        foreach ($timeline->components as $idx => $component) {
            try {
                $isParent = isset($hierarchyMap['parents'][$idx]);
                $isChild  = isset($hierarchyMap['children'][$idx]);
                $rendered = $this->renderComponent($component, $isParent, $isChild, $includeDebug);
            } catch (\Throwable $e) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage('Component render threw: ' . $e->getMessage(), 'error');
                }
                $rendered = '';
            }

            if (!empty($rendered)) {
                $nodes[] = ['html' => $rendered, 'ts' => $this->extractTimestamp($component)];
            }
        }

        if (empty($nodes)) {
            return $this->renderEmptyTimeline($timeline);
        }

        $lastIdx = count($nodes) - 1;
        $nodes[$lastIdx]['html'] = str_replace(
            'class="odcm-tl-node ',
            'class="odcm-tl-node odcm-tl-node--last ',
            $nodes[$lastIdx]['html']
        );

        $html = '<div class="odcm-timeline">';
        foreach ($nodes as $i => $node) {
            $html .= $node['html'];
            if ($i < $lastIdx) {
                $html .= $this->renderDelta($node['ts'], $nodes[$i + 1]['ts']);
            }
        }
        $html .= '</div>';

        return $html;
    }

    private function extractTimestamp(array $component): int
    {
        $ts = $component['ts'] ?? $component['data']['ts'] ?? $component['timestamp'] ?? time();
        if (!is_numeric($ts)) {
            $ts = strtotime((string) $ts) ?: time();
        }
        return (int) $ts;
    }

    private function renderDelta(int $prevTs, int $nextTs): string
    {
        $diff = max(0, $nextTs - $prevTs);
        if ($diff < 60) {
            $label = '+ ' . $diff . 's';
        } elseif ($diff < 3600) {
            $label = '+ ' . round($diff / 60, 1) . 'm';
        } else {
            $label = '+ ' . round($diff / 3600, 1) . 'h';
        }
        return '<div class="odcm-tl-delta">' .
               '<div class="odcm-tl-delta__spine"></div>' .
               '<span class="odcm-tl-delta__value">' . esc_html($label) . '</span>' .
               '</div>';
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
        
        // Log the final hierarchy map for debugging
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Final hierarchy map - Parents: " . count($hierarchyMap['parents']) . ", Children: " . count($hierarchyMap['children']), 'debug');
        }
        
        return $hierarchyMap;
    }
    
    /**
     * Render individual component using DisplayAdapter system with three-tier architecture
     *
     * @param array $payload The component payload data
     * @param bool $isParent Whether this component is a parent (has children)
     * @param bool $isChild Whether this component is a child (has parent_id)
     * @param bool $includeDebug Whether to include debug events (from dashboard toggle)
     * @return string Rendered HTML with hierarchy CSS classes applied
     */
    private function renderComponent(array $payload, bool $isParent = false, bool $isChild = false, bool $includeDebug = false): string
    {
        // Debug Event Filtering - hide debug events when dashboard toggle is OFF
        if ($this->shouldFilterDebugEvent($payload, $includeDebug)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - Debug event hidden (include_debug=" . ($includeDebug ? 'true' : 'false') . ")");
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
            // Log the primary adapter failure
            $this->logDebugMessage('Primary adapter failed: ' . $e->getMessage(), 'warning');
            
            // Use GenericEventAdapter as THE fallback - it's guaranteed not to throw
            $adapter = new GenericEventAdapter();
            $displayData = $adapter->extractDisplayData($payload);
        }

        // ALWAYS use the standard rendering pipeline - no exceptions
        $result = $this->renderThreeTierComponent($displayData, $payload);
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
        $eventType = $rawPayload['event_type'] ?? 'unknown';
        $eventConfig = DisplayAdapter::getEventTypeConfig($eventType);
        $themeClass  = $eventConfig['theme_class'] ?? 'odcm-component--system';

        if (RuleExecutionAdapter::isIncompleteRuleEvent($rawPayload)) {
            $themeClass = 'odcm-component--debug';
        }

        $variant = $this->mapThemeToNodeVariant($themeClass, $rawPayload);

        // Timestamp — formatted server-side in WP timezone
        $ts = $rawPayload['ts'] ?? time();
        if (!is_numeric($ts)) {
            $ts = strtotime((string) $ts) ?: time();
        }
        $timeDisplay = wp_date('H:i:s', (int) $ts) ?: '';

        // Title and status pill
        $unifiedData = DisplayAdapter::generateUnifiedEventData($rawPayload, []);
        $title       = $unifiedData['summary'] ?? '';
        $statusData  = $unifiedData['status']  ?? null;
        $pillHtml    = $statusData ? $this->renderNodePill($statusData['label'], $statusData['type']) : '';

        // Key-value rows as DL content
        $rowsHtml = $this->renderNodeRows($displayData['display_sections'] ?? [], $rawPayload);

        $html  = '<div class="odcm-tl-node odcm-tl-node--' . esc_attr($variant) . '">';
        $html .= '<div class="odcm-tl-node__spine"><span class="odcm-tl-node__dot"></span></div>';
        $html .= '<div>';
        $html .= '<div class="odcm-tl-node__card">';
        $html .= '<div class="odcm-tl-node__head">';
        $html .= '<span class="odcm-tl-node__time">' . esc_html($timeDisplay) . '</span>';
        $html .= $pillHtml;
        $html .= '</div>';
        $html .= '<div class="odcm-tl-node__title">' . esc_html($title) . '</div>';
        if ($rowsHtml) {
            $html .= '<dl class="odcm-tl-node__rows">' . $rowsHtml . '</dl>';
        }
        $html .= $this->renderNodeJson($rawPayload);
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function mapThemeToNodeVariant(string $themeClass, array $rawPayload): string
    {
        $level = strtolower($rawPayload['level'] ?? 'info');
        if ($level === 'error' || $level === 'critical') return 'danger';
        if ($level === 'warning' || $level === 'warn')   return 'warn';

        return match ($themeClass) {
            'odcm-component--error'   => 'danger',
            'odcm-component--payment' => 'success',
            default                   => 'info',
        };
    }

    private function renderNodePill(string $label, string $type): string
    {
        $variantMap = [
            'error'     => 'danger',
            'warning'   => 'warn',
            'success'   => 'success',
            'completed' => 'success',
            'info'      => 'info',
            'pending'   => 'warn',
            'skipped'   => 'info',
            'debug'     => 'info',
        ];
        $variant = $variantMap[strtolower($type)] ?? 'info';
        return '<span class="odcm-pill odcm-pill--' . esc_attr($variant) . '">' . esc_html($label) . '</span>';
    }

    private function renderNodeRows(array $displaySections, array $rawPayload): string
    {
        if (empty($displaySections)) return '';

        $rows = '';
        foreach ($displaySections as $key => $section) {
            if ('event_description' === $key || 'order_id' === $key) continue;

            $label = $section['label'] ?? '';
            $value = $section['value'] ?? '';

            if ('timestamp' === $key || str_contains((string) $label, 'Timestamp')) {
                $rawTs = $rawPayload['ts'] ?? time();
                if (!is_numeric($rawTs)) $rawTs = strtotime((string) $rawTs) ?: time();
                $rows .= '<dt>' . esc_html($label) . '</dt>';
                $rows .= '<dd><span class="js-format-timestamp" x-text="formatTimestamp(' . esc_attr((string)(int) $rawTs) . ', $el)"></span></dd>';
            } else {
                $rows .= '<dt>' . esc_html($label) . '</dt>';
                $rows .= '<dd>' . esc_html($value) . '</dd>';
            }
        }
        return $rows;
    }

    private function renderNodeJson(array $rawPayload): string
    {
        $json = wp_json_encode($rawPayload, JSON_PRETTY_PRINT);
        return '<button type="button" class="odcm-tl-node__expand" aria-expanded="false">' .
               esc_html__('api.timeline.display_mode.show_raw_json', 'order-daemon') .
               '</button>' .
               '<div class="odcm-tl-node__json">' .
               '<div class="odcm-code-block"><pre><code class="language-json">' . esc_html($json) . '</code></pre></div>' .
               '</div>';
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
            if ('event_description' === $key || 'order_id' === $key) {
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
     * Timestamps are rendered with client-side formatting for consistency with log stream.
     *
     * @param array $displaySections The display sections containing business data
     * @param array $rawPayload The original event payload for accessing raw timestamp
     * @return string Rendered unified business section HTML
     */
    private function renderUnifiedBusinessSection(array $displaySections, array $rawPayload): string
    {
        if (empty($displaySections)) {
            return '';
        }

        $html = '<div class="odcm-key-value-list">';

        foreach ($displaySections as $key => $section) {
            // Skip event_description as it's already shown in the header
            if ('event_description' === $key || 'order_id' === $key) {
                continue;
            }

            // Handle timestamp with client-side formatting
            if ('timestamp' === $key || strpos($section['label'], 'Timestamp') !== false) {
                $rawTimestamp = $rawPayload['ts'] ?? time();
                $html .= '<div class="odcm-key">' . esc_html($section['label']) . '</div>';
                $html .= '<div class="odcm-value">';
                
                // Ensure we pass a numeric timestamp to JS to avoid syntax errors and ensure correct formatting
                // If it's a string (e.g. "2026-02-22..."), convert to unix timestamp
                $jsTimestamp = $rawTimestamp;
                if (!is_numeric($rawTimestamp) && is_string($rawTimestamp)) {
                    $jsTimestamp = strtotime($rawTimestamp) ?: time();
                }
                
                $html .= '<span class="js-format-timestamp" x-text="formatTimestamp(' . esc_attr($jsTimestamp) . ', $el)"></span>';
                $html .= '</div>';
            } else {
                $html .= '<div class="odcm-key">' . esc_html($section['label']) . '</div>';
                $html .= '<div class="odcm-value">' . esc_html($section['value']) . '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render improved technical section with clearer labeling
     *
     * This method creates a "raw event json" section that clearly indicates
     * it contains complete raw event data for debugging and analysis.
     *
     * @param array $rawPayload The raw event payload
     * @return string Rendered improved technical section HTML
     */
    private function renderImprovedTechnicalSection(array $rawPayload): string
    {
        $html = '<div class="odcm-expandable-section">';
        $html .= '<button type="button" class="odcm-icon-button odcm-tier-toggle" data-target="technical" aria-expanded="false">' .
                 esc_html__('api.timeline.display_mode.show_raw_json', 'order-daemon') . '</button>';
        $html .= '<div class="odcm-tier-content">';

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
                 esc_html__('api.timeline.display_mode.show_raw_json', 'order-daemon') . '</button>';
        $html .= '<div class="odcm-tier-content">';

        // Format raw payload as JSON with proper prism.js classes
        $jsonPayload = wp_json_encode($rawPayload, JSON_PRETTY_PRINT);
        $html .= '<div class="odcm-code-block"><pre><code class="language-json">' . esc_html($jsonPayload) . '</code></pre></div>';

        $html .= '</div>';
        $html .= '</div>';

        return $html;
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
        
        $pattern = '/(<div[^>]*class="[^"]*odcm-tl-node[^"]*")/i';
        
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
                $wrapperClasses = 'odcm-tl-node ' . $classString;
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
     * Enhanced debug event filtering - check both event type and completeness
     * Uses dashboard include_debug parameter instead of global ODCM_DEBUG constant
     *
     * This method delegates to AdapterRegistry::shouldFilterForRendering() for the
     * canonical list of internal-only events (single source of truth).
     *
     * @param array $payload The component payload to check
     * @param bool $includeDebug Whether to include debug events (from dashboard toggle)
     * @return bool True if this event should be filtered out (not shown)
     */
    private function shouldFilterDebugEvent(array $payload, bool $includeDebug = false): bool
    {
        // Check multiple paths where event_type might be stored
        // This handles nested components, different payload structures, and legacy formats
        $event_type = '';
        
        // Priority 1: Direct event_type on payload
        if (!empty($payload['event_type'])) {
            $event_type = $payload['event_type'];
        }
        // Priority 2: Nested in data.event_type
        elseif (!empty($payload['data']['event_type'])) {
            $event_type = $payload['data']['event_type'];
        }
        // Priority 3: Check 'type' field (sometimes used instead of event_type)
        elseif (!empty($payload['type'])) {
            $event_type = $payload['type'];
        }
        // Priority 4: Check label for patterns (fallback for malformed components)
        elseif (!empty($payload['label'])) {
            $label = strtolower($payload['label']);
            if (strpos($label, 'rule no match') !== false || strpos($label, 'no rules matched') !== false) {
                $event_type = 'rule_no_match';
            }
        }

        // Enhanced debug logging to trace filtering decisions
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: Filtering event - type: {$event_type}, include_debug: " . ($includeDebug ? 'true' : 'false'));
        }

        // FIRST: Use AdapterRegistry as single source of truth for internal-only events
        // Internal-only events are ALWAYS filtered, regardless of includeDebug setting
        if (AdapterRegistry::isInternalOnlyEvent($event_type)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - internal-only event: {$event_type}");
            }
            return true;
        }

        // SECOND: If dashboard says include debug events, show everything else
        if ($includeDebug) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: NOT FILTERED - include_debug=true: {$event_type}");
            }
            return false;
        }

        // THIRD: Check for explicit debug_only flag set by adapters
        if (!empty($payload['debug_only']) && $payload['debug_only'] === true) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - debug_only flag is true");
            }
            return true;
        }

        // FOURTH: Check for specific "Rule Processing Started" flag
        if (!empty($payload['is_rule_processing_started']) && $payload['is_rule_processing_started'] === true) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - is_rule_processing_started flag is true");
            }
            return true;
        }

        // FIFTH: Check for incomplete rule execution events ("Rule Processing Started")
        // These have event_type "rule_execution" but lack complete rule data
        if ('rule_execution' === $event_type) {
            $hasCompleteRuleData = !empty($payload['rule_execution']['rule_name']) ||
                                  !empty($payload['rule_execution']['rule_configuration']['rule_name']) ||
                                  !empty($payload['rule_name']) ||
                                  !empty($payload['data']['rule_name']);

            // Use only ProcessLogger-specific fields as "processing started" indicators.
            // Do NOT include data['status'] — in EnhancedTimelineBuilder components that key
            // holds the DB row's status column, causing false positives for every rule event.
            $hasProcessingMetadata = !empty($payload['data']['correlation_id']) ||
                                   !empty($payload['data']['process_type']);

            // Only filter if it's an incomplete rule event (processing started)
            // Complete rule execution events should be treated as business events, not debug events
            if ($hasProcessingMetadata && !$hasCompleteRuleData) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - incomplete rule execution event (Rule Processing Started)");
                }
                return true;
            }

            // IMPORTANT: If this is a complete rule execution event, it should NEVER be filtered as debug
            // Rule Executed events are business events, not debug events
            if ($hasCompleteRuleData) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM TIMELINE DEBUG: NOT FILTERED - complete rule execution event (Rule Executed)");
                }
                return false;
            }
        }

        // SIXTH: Use AdapterRegistry for any remaining debug event filtering
        // Pass the payload for sophisticated rule execution filtering
        if (AdapterRegistry::shouldFilterForRendering($event_type, $includeDebug, $payload)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("ODCM TIMELINE DEBUG: FILTERED - AdapterRegistry debug filter");
            }
            return true;
        }

        // Not filtered - it's a business event
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM TIMELINE DEBUG: NOT FILTERED - business event: {$event_type}");
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

        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file using safe file operation
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $debug_file = odcm_get_safe_debug_file_path();
            odcm_safe_file_put_contents($debug_file, '[' . gmdate('Y-m-d H:i:s') . '] ' . $prefix . $message . PHP_EOL, FILE_APPEND);
        }
    }
}
