<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Rule Management Renderer
 *
 * Specialized renderer for rule editing and management audit events. This renderer
 * provides intelligent, developer-focused visualization of rule changes with semantic
 * color coding, before/after comparisons, and comprehensive user context information.
 *
 * RENDERING PHILOSOPHY:
 * ====================
 * 
 * This renderer implements a smart adaptive display system:
 * - **Compact Mode**: Human-readable change summaries with semantic color coding
 * - **Expanded Mode**: Detailed before/after comparisons with visual highlighting
 * - **Developer Focus**: Technical metadata, user context, and validation status
 * - **Change Impact**: Visual indicators for addition, modification, and removal
 * 
 * SEMANTIC COLOR CODING:
 * =====================
 * 
 * - **Green**: Additions (new conditions, actions added)
 * - **Blue**: Modifications (settings changed, trigger updated)
 * - **Orange**: Removals (conditions removed, actions deleted)
 * - **Purple**: Structural changes (rule created/published/unpublished)
 * 
 * DATA STRUCTURE EXPECTATIONS:
 * ===========================
 * 
 * The renderer expects payload data with the following structure:
 * ```php
 * [
 *     'rule_id' => 123,
 *     'action' => 'rule_modified', // or 'rule_created'
 *     'before_data' => [...], // Rule data before changes (null for new rules)
 *     'after_data' => [...],  // Rule data after changes
 *     'changes_summary' => 'trigger: none → order_processing, +1 condition',
 *     'component_counts' => [
 *         'has_trigger' => true,
 *         'conditions_count' => 3,
 *         'has_primary_action' => true,
 *         'secondary_actions_count' => 1
 *     ],
 *     'user_context' => [
 *         'timestamp' => '2024-01-15 10:30:00',
 *         'user_id' => 1,
 *         'user_agent' => 'Mozilla/5.0...',
 *         'ip_address' => '192.168.1.100',
 *         'page_url' => 'https://example.com/wp-admin/post.php?post=123&action=edit'
 *     ]
 * ]
 * ```
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   2.0.2
 * @author  OrderDaemon Development Team
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Rule Management Renderer Class
 *
 * Renders rule editing and management events with intelligent change visualization
 * and comprehensive developer context information.
 *
 * @since 1.0.0
 */
class RuleManagementRenderer extends PayloadComponentRenderer
{
    /**
     * Get Component ID
     *
     * @since 1.0.0
     *
     * @return string The component identifier for registry lookup.
     */
    protected function getComponentId(): string
    {
        return 'rule_management';
    }

    /**
     * Check if Renderer Can Handle Data
     *
     * Determines if this renderer is appropriate for the provided data by checking
     * for rule management specific keys and patterns.
     *
     * @since 1.0.0
     *
     * @param array $data The payload data to analyze.
     * @return bool True if this renderer can handle the data.
     */
    public function canHandle(array $data): bool
    {
        // Check for rule management specific keys
        $required_keys = ['rule_id', 'action'];
        $optional_keys = ['before_data', 'after_data', 'changes_summary', 'component_counts', 'user_context'];
        
        // Must have required keys
        foreach ($required_keys as $key) {
            if (!isset($data[$key])) {
                return false;
            }
        }
        
        // Must have at least one optional key to be meaningful
        foreach ($optional_keys as $key) {
            if (isset($data[$key])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Render Component Content
     *
     * Generates the complete rule management component content with change summaries,
     * before/after comparisons, and user context information.
     *
     * @since 1.0.0
     *
     * @param array $data The validated payload data for this component.
     * @return string HTML content for the component body.
     * 
     * @throws InvalidArgumentException If required data is missing.
     * @throws RuntimeException If content rendering fails.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $content = '';
        
        // Validate required data
        if (!isset($data['rule_id']) || !isset($data['action'])) {
            throw new InvalidArgumentException('Rule management data must include rule_id and action');
        }
        
        // 1. Rule Overview Section
        $content .= $this->renderRuleOverview($data, $toolkit);
        
        // 2. Change Summary Section (always visible)
        $content .= $this->renderChangeSummary($data, $toolkit);
        
        // 3. Component Counts Section
        if (isset($data['component_counts'])) {
            $content .= $this->renderComponentCounts($data['component_counts'], $toolkit);
        }
        
        // 4. Before/After Comparison (expandable)
        if (isset($data['before_data']) || isset($data['after_data'])) {
            $content .= $this->renderBeforeAfterComparison($data, $toolkit);
        }
        
        // 5. User Context Section (expandable)
        if (isset($data['user_context'])) {
            $content .= $this->renderUserContext($data['user_context'], $toolkit);
        }
        
        return $content;
    }

    /**
     * Render Rule Overview Section
     *
     * Displays basic rule information and action type with semantic styling.
     *
     * @since 1.0.0
     *
     * @param array $data The rule management data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML content for the rule overview.
     */
    private function renderRuleOverview(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $rule_id = (int) $data['rule_id'];
        $action = sanitize_text_field($data['action']);
        
        // Get rule title if available
        $rule_title = get_the_title($rule_id) ?: "Rule #{$rule_id}";
        
        // Create action status pill with semantic color
        $action_pill = $this->createActionStatusPill($action, $toolkit);
        
        $overview_data = [
            'Rule' => esc_html($rule_title),
            'Action' => $action_pill,
            'Rule ID' => $rule_id
        ];
        
        return $toolkit->render_key_value_list($overview_data, 'Rule Management Event');
    }

    /**
     * Render Change Summary Section
     *
     * Displays the human-readable change summary with semantic highlighting.
     *
     * @since 1.0.0
     *
     * @param array $data The rule management data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML content for the change summary.
     */
    private function renderChangeSummary(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $changes_summary = $data['changes_summary'] ?? 'No change details available';
        
        // Apply semantic highlighting to the change summary
        $highlighted_summary = $this->applySemanticHighlighting($changes_summary);
        
        return '<div class="odcm-section">' .
               '<div class="odcm-section-title">Changes Made</div>' .
               '<div class="odcm-change-summary">' . $highlighted_summary . '</div>' .
               '</div>';
    }

    /**
     * Render Component Counts Section
     *
     * Displays rule component statistics in a compact format.
     *
     * @since 1.0.0
     *
     * @param array $component_counts Component count data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML content for component counts.
     */
    private function renderComponentCounts(array $component_counts, PayloadComponentUIToolkit $toolkit): string
    {
        $counts_data = [];
        
        // Format component counts for display
        if (isset($component_counts['has_trigger'])) {
            $counts_data['Trigger'] = $component_counts['has_trigger'] ? 'Yes' : 'None';
        }
        
        if (isset($component_counts['conditions_count'])) {
            $counts_data['Conditions'] = (int) $component_counts['conditions_count'];
        }
        
        if (isset($component_counts['has_primary_action'])) {
            $counts_data['Primary Action'] = $component_counts['has_primary_action'] ? 'Yes' : 'None';
        }
        
        if (isset($component_counts['secondary_actions_count'])) {
            $counts_data['Secondary Actions'] = (int) $component_counts['secondary_actions_count'];
        }
        
        if (empty($counts_data)) {
            return '';
        }
        
        return $toolkit->render_key_value_list($counts_data, 'Rule Components');
    }

    /**
     * Render Before/After Comparison Section
     *
     * Creates an expandable section showing detailed before and after rule data.
     *
     * @since 1.0.0
     *
     * @param array $data The rule management data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML content for the before/after comparison.
     */
    private function renderBeforeAfterComparison(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $before_data = $data['before_data'] ?? null;
        $after_data = $data['after_data'] ?? null;
        
        $comparison_content = '';
        
        // Before data section
        if ($before_data !== null) {
            $before_json = json_encode($before_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $before_code = $toolkit->render_code_block($before_json, 'json');
            $comparison_content .= '<div class="odcm-section">' .
                                  '<div class="odcm-section-title">Before Changes</div>' .
                                  $before_code .
                                  '</div>';
        } else {
            $comparison_content .= '<div class="odcm-section">' .
                                  '<div class="odcm-section-title">Before Changes</div>' .
                                  $toolkit->render_text_block('New rule - no previous data') .
                                  '</div>';
        }
        
        // After data section
        if ($after_data !== null) {
            $after_json = json_encode($after_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $after_code = $toolkit->render_code_block($after_json, 'json');
            $comparison_content .= '<div class="odcm-section">' .
                                  '<div class="odcm-section-title">After Changes</div>' .
                                  $after_code .
                                  '</div>';
        }
        
        return $toolkit->render_expandable_section('Before/After Comparison', $comparison_content);
    }

    /**
     * Render User Context Section
     *
     * Creates an expandable section with comprehensive user and session information.
     *
     * @since 1.0.0
     *
     * @param array $user_context User context data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML content for the user context.
     */
    private function renderUserContext(array $user_context, PayloadComponentUIToolkit $toolkit): string
    {
        $context_data = [];
        
        // User information
        if (isset($user_context['user_id'])) {
            $user_id = (int) $user_context['user_id'];
            $user = get_user_by('id', $user_id);
            $user_display = $user ? $user->display_name . " ({$user->user_login})" : "User ID: {$user_id}";
            $context_data['User'] = $user_display;
        }
        
        // Timestamp information
        if (isset($user_context['timestamp'])) {
            $context_data['Timestamp'] = sanitize_text_field($user_context['timestamp']);
        }
        
        if (isset($user_context['frontend_timestamp'])) {
            $context_data['Frontend Time'] = sanitize_text_field($user_context['frontend_timestamp']);
        }
        
        // Network information
        if (isset($user_context['ip_address'])) {
            $context_data['IP Address'] = sanitize_text_field($user_context['ip_address']);
        }
        
        // Page context
        if (isset($user_context['page_url'])) {
            $page_url = esc_url($user_context['page_url']);
            $context_data['Page URL'] = $page_url;
        }
        
        // User agent (expandable due to length)
        $context_content = $toolkit->render_key_value_list($context_data, 'Session Information');
        
        if (isset($user_context['user_agent'])) {
            $user_agent = sanitize_text_field($user_context['user_agent']);
            $user_agent_code = $toolkit->render_code_block($user_agent, 'none');
            $context_content .= $toolkit->render_expandable_section('User Agent', $user_agent_code);
        }
        
        return $toolkit->render_expandable_section('User Context', $context_content);
    }

    /**
     * Create Action Status Pill
     *
     * Creates a semantically colored status pill for the rule action type.
     *
     * @since 1.0.0
     *
     * @param string $action The action type.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for the status pill.
     */
    private function createActionStatusPill(string $action, PayloadComponentUIToolkit $toolkit): string
    {
        // Map actions to semantic colors and labels
        $action_map = [
            'rule_created' => ['label' => 'CREATED', 'type' => 'success'],
            'rule_modified' => ['label' => 'MODIFIED', 'type' => 'info'],
            'rule_published' => ['label' => 'PUBLISHED', 'type' => 'success'],
            'rule_unpublished' => ['label' => 'UNPUBLISHED', 'type' => 'warning'],
            'rule_deleted' => ['label' => 'DELETED', 'type' => 'error'],
        ];
        
        $action_info = $action_map[$action] ?? ['label' => strtoupper($action), 'type' => 'info'];
        
        return $toolkit->render_status_pill($action_info['label'], $action_info['type']);
    }

    /**
     * Apply Semantic Highlighting
     *
     * Applies semantic color coding to change summary text using HTML spans.
     *
     * @since 1.0.0
     *
     * @param string $summary The change summary text.
     * @return string HTML with semantic highlighting applied.
     */
    private function applySemanticHighlighting(string $summary): string
    {
        // Escape the summary first
        $safe_summary = esc_html($summary);
        
        // Apply semantic highlighting patterns
        $patterns = [
            // Additions (green)
            '/(\+\d+\s+\w+|\badded?\b|\bnew\b)/i' => '<span class="odcm-change-addition">$1</span>',
            
            // Removals (orange)
            '/(-\d+\s+\w+|\bremoved?\b|\bdeleted?\b)/i' => '<span class="odcm-change-removal">$1</span>',
            
            // Modifications (blue)
            '/(\w+:\s*[^→]+\s*→\s*[^,]+|\bchanged?\b|\bmodified?\b|\bupdated?\b)/i' => '<span class="odcm-change-modification">$1</span>',
            
            // Structural changes (purple)
            '/(\bcreated?\b|\bpublished?\b|\bunpublished?\b)/i' => '<span class="odcm-change-structural">$1</span>',
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $safe_summary = preg_replace($pattern, $replacement, $safe_summary);
        }
        
        return $safe_summary;
    }
}
