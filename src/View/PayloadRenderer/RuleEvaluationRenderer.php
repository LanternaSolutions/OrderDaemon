<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Rule Evaluation Renderer
 *
 * Renders rule evaluation data including rule details, conditions,
 * actions, and evaluation results with proper formatting.
 *
 * This renderer focuses purely on content rendering while the base class
 * handles all structural concerns (headers, icons, component wrapper).
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.3.0
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Rule Evaluation Renderer Class
 *
 * Handles rendering of rule evaluation data with proper formatting
 * for rules, conditions, and actions.
 *
 * @since 1.0.0
 */
class RuleEvaluationRenderer extends PayloadComponentRenderer
{
    /**
     * Get Component ID for Registry Lookup
     *
     * @since 1.0.0
     *
     * @return string Component identifier.
     */
    protected function getComponentId(): string
    {
        return 'rule_evaluation';
    }

    /**
     * Render compact embedded summary for rule evaluations.
     *
     * Provides a one-line, condensed representation suitable for high-volume
     * events in embedded contexts. If a concise summary cannot be determined,
     * falls back to the base implementation (full component).
     *
     * @param array $data Rule evaluation data
     * @return string HTML
     */
    public function renderEmbeddedContent(array $data): string
    {
        $parts = [];

        // Primary: rule evaluated shape
        $ruleId  = isset($data['rule_id']) ? absint($data['rule_id']) : 0;
        $matched = array_key_exists('matched', $data) ? (bool)$data['matched'] : null;
        $reason  = isset($data['reason']) ? sanitize_text_field((string)$data['reason']) : '';

        if ($ruleId || $matched !== null || $reason !== '') {
            if ($ruleId) {
                $parts[] = sprintf(__('Rule #%d', 'order-daemon'), $ruleId);
            } else {
                $parts[] = __('Rule', 'order-daemon');
            }
            if ($matched !== null) {
                $parts[] = $matched ? __('matched', 'order-daemon') : __('not matched', 'order-daemon');
            }
            if ($reason !== '') {
                $parts[] = '– ' . $reason;
            }
        }

        // Decision shape
        if (empty($parts)) {
            $outcome = isset($data['outcome']) ? sanitize_text_field((string)$data['outcome']) : '';
            if ($outcome !== '') {
                $parts[] = sprintf(__('Decision: %s', 'order-daemon'), $outcome);
            }
        }

        // Condition evaluation shape
        if (empty($parts)) {
            $label  = isset($data['condition_label']) ? sanitize_text_field((string)$data['condition_label']) : '';
            $passed = null;
            if (isset($data['result'])) {
                $r = $data['result'];
                if (is_string($r)) {
                    $rl = strtolower($r);
                    $passed = in_array($rl, ['passed','true','yes','1'], true);
                } elseif (is_bool($r)) {
                    $passed = $r;
                } elseif (is_int($r)) {
                    $passed = $r === 1;
                }
            }
            if ($label !== '' || $passed !== null) {
                if ($label !== '') {
                    $parts[] = $label . ':';
                }
                if ($passed !== null) {
                    $parts[] = $passed ? __('Passed', 'order-daemon') : __('Failed', 'order-daemon');
                }
            }
        }

        if (!empty($parts)) {
            $text = trim(implode(' ', $parts));
            return '<span class="odcm-rule-inline">' . esc_html($text) . '</span>';
        }

        return parent::renderEmbeddedContent($data);
    }

    /**
     * Render Rule Evaluation Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform complex rule evaluation data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing Alpine.js interactive features for rule analysis and debugging
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data Rule evaluation data.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];

        // Narrative-first: branch for specific rule kinds when present
        $kind = $this->getCurrentComponentId();
        if ($kind === 'rule_evaluated') {
            $rule_id = isset($data['rule_id']) ? absint($data['rule_id']) : null;
            $priority = isset($data['priority']) ? (int)$data['priority'] : null;
            $matched = isset($data['matched']) ? (bool)$data['matched'] : null;
            $reason  = isset($data['reason']) ? sanitize_text_field((string)$data['reason']) : '';
            $kv = [];
            if ($rule_id)  { $kv['Rule ID'] = '#' . $rule_id; }
            if ($priority !== null) { $kv['Priority'] = (string)$priority; }
            if ($matched !== null)  { $kv['Matched'] = $matched ? 'yes' : 'no'; }
            if ($reason !== '')     { $kv['Reason']  = $reason; }
            if (!empty($kv)) {
                return $toolkit->render_key_value_list($kv, __('Rule Evaluated', 'order-daemon'));
            }
        } elseif ($kind === 'decision') {
            $subject = isset($data['subject']) ? sanitize_text_field((string)$data['subject']) : '';
            $outcome = isset($data['outcome']) ? sanitize_text_field((string)$data['outcome']) : '';
            $reason  = isset($data['reason']) ? sanitize_text_field((string)$data['reason']) : '';
            $kv = [];
            if ($subject !== '') { $kv['Subject'] = $subject; }
            if ($outcome !== '') { $kv['Outcome'] = $outcome; }
            if ($reason !== '')  { $kv['Reason']  = $reason; }
            if (!empty($kv)) {
                return $toolkit->render_key_value_list($kv, __('Decision', 'order-daemon'));
            }
        } elseif ($kind === 'validation') {
            $name   = isset($data['name']) ? sanitize_text_field((string)$data['name']) : '';
            $step   = isset($data['step']) ? sanitize_text_field((string)$data['step']) : '';
            $result = isset($data['result']) ? sanitize_text_field((string)$data['result']) : '';
            $kv = [];
            if ($name !== '')   { $kv['Name']   = $name; }
            if ($step !== '')   { $kv['Step']   = $step; }
            if ($result !== '') { $kv['Result'] = $result; }
            $content = '';
            if (!empty($kv)) {
                $content .= $toolkit->render_key_value_list($kv, __('Validation', 'order-daemon'));
            }
            if (!empty($data['details']) && is_array($data['details'])) {
                $json = (string) wp_json_encode($data['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $content .= $toolkit->render_expandable_section(__('Details', 'order-daemon'), $toolkit->render_code_block($json, 'json'));
            }
            if ($content !== '') {
                return $content;
            }
        } elseif ($kind === 'condition_passed' || $kind === 'condition_failed') {
            $condition_label = isset($data['condition_label']) ? sanitize_text_field((string)$data['condition_label']) : '';
            $expected_value  = isset($data['expected_value']) ? sanitize_text_field((string)$data['expected_value']) : '';
            $actual_value    = isset($data['actual_value']) ? sanitize_text_field((string)$data['actual_value']) : '';
            $operator        = isset($data['operator']) ? sanitize_text_field((string)$data['operator']) : '';
            $order_id        = isset($data['order_id']) ? absint($data['order_id']) : 0;
            $result_text     = $kind === 'condition_passed' ? '✓ Passed' : '⚠ Failed';

            $kv = [];
            if ($condition_label !== '') { $kv['Condition'] = $condition_label; }
            if ($operator !== '') { $kv['Operator'] = $operator; }
            if ($expected_value !== '') { $kv['Expected'] = $expected_value; }
            if ($actual_value !== '') { $kv['Actual'] = $actual_value; }
            if ($order_id > 0) { $kv['Order'] = '#' . $order_id; }
            $kv['Result'] = $result_text;

            if (!empty($kv)) {
                return $toolkit->render_key_value_list($kv, __('Condition Evaluation', 'order-daemon'));
            }
        }
        
        // === DATA ADAPTATION PHASE ===
        // Transform complex rule evaluation data into simple, clean formats using private adapters
        
        // Adapt rule information
        $rule_html = $this->adaptRuleInformation($data, $toolkit);
        if ($rule_html !== null) {
            $html_parts[] = $rule_html;
        }
        
        // Adapt evaluation result status
        $result_html = $this->adaptEvaluationResult($data, $toolkit);
        if ($result_html !== null) {
            $html_parts[] = $result_html;
        }
        
        // Adapt trigger information
        $trigger_html = $this->adaptTriggerInformation($data, $toolkit);
        if ($trigger_html !== null) {
            $html_parts[] = $trigger_html;
        }
        
        // Adapt conditions with interactive features
        $conditions_html = $this->adaptConditions($data, $toolkit);
        if ($conditions_html !== null) {
            $html_parts[] = $conditions_html;
        }
        
        // Adapt actions with interactive features
        $actions_html = $this->adaptActions($data, $toolkit);
        if ($actions_html !== null) {
            $html_parts[] = $actions_html;
        }
        
        // Adapt execution details
        $execution_html = $this->adaptExecutionDetails($data, $toolkit);
        if ($execution_html !== null) {
            $html_parts[] = $execution_html;
        }
        
        // Adapt rule timing/performance data
        $timing_html = $this->adaptRuleTiming($data, $toolkit);
        if ($timing_html !== null) {
            $html_parts[] = $timing_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific rule components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        return implode('', $html_parts);
    }

    /**
     * Adapt Rule Information
     *
     * Transforms rule metadata into clean key-value pairs for display.
     * Handles rule ID, name, status, and other rule properties.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for rule information or null if no rule data found.
     */
    private function adaptRuleInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $rule_info = [];
        
        // Defensive programming: Extract rule from various possible keys
        $rule = $data['rule'] ?? $data['rule_data'] ?? null;
        $rule_id = $data['rule_id'] ?? null;
        
        if (is_array($rule)) {
            // Extract rule details from rule object
            if (isset($rule['id']) && !empty($rule['id'])) {
                $rule_info['Rule ID'] = (string)$rule['id'];
            }
            
            if (isset($rule['name']) && !empty($rule['name'])) {
                $rule_info['Rule Name'] = (string)$rule['name'];
            }
            
            if (isset($rule['status']) && !empty($rule['status'])) {
                $rule_info['Status'] = strtoupper((string)$rule['status']);
            }
            
            if (isset($rule['priority']) && is_numeric($rule['priority'])) {
                $rule_info['Priority'] = (string)$rule['priority'];
            }
            
            if (isset($rule['description']) && !empty($rule['description'])) {
                $rule_info['Description'] = (string)$rule['description'];
            }
            
        } elseif ($rule_id !== null) {
            $rule_info['Rule ID'] = (string)$rule_id;
        }
        
        // Extract additional rule fields from root level
        $rule_name = $data['rule_name'] ?? null;
        if ($rule_name !== null && !isset($rule_info['Rule Name'])) {
            $rule_info['Rule Name'] = (string)$rule_name;
        }
        
        // Only render if we have meaningful rule data
        if (empty($rule_info)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($rule_info, 'Rule Information');
    }

    /**
     * Adapt Evaluation Result
     *
     * Creates status indicators for rule evaluation results.
     * Maps evaluation outcomes to appropriate visual indicators.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for evaluation result or null if no result found.
     */
    private function adaptEvaluationResult(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $result = $data['result'] ?? $data['evaluation_result'] ?? $data['outcome'] ?? null;
        
        if ($result === null) {
            return null;
        }
        
        $status_data = $this->mapEvaluationResultToStatus($result);
        return $toolkit->render_status_pill($status_data['label'], $status_data['type']);
    }

    /**
     * Adapt Trigger Information
     *
     * Transforms trigger data into formatted display.
     * Handles trigger types, events, and trigger metadata.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for trigger information or null if no trigger data found.
     */
    private function adaptTriggerInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $trigger_data = [];
        
        // Defensive programming: Extract trigger from various possible keys
        $trigger = $data['trigger'] ?? $data['trigger_type'] ?? $data['event'] ?? null;
        
        if (is_array($trigger)) {
            // Extract trigger details
            foreach ($trigger as $key => $value) {
                if (!empty($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $trigger_data[$formatted_key] = (string)$value;
                }
            }
        } elseif ($trigger !== null) {
            $trigger_data['Trigger Type'] = strtoupper((string)$trigger);
        }
        
        // Extract additional trigger fields from root level
        $event_type = $data['event_type'] ?? null;
        if ($event_type !== null && !isset($trigger_data['Event Type'])) {
            $trigger_data['Event Type'] = (string)$event_type;
        }
        
        // Only render if we have meaningful trigger data
        if (empty($trigger_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($trigger_data, 'Trigger Information');
    }

    /**
     * Adapt Conditions
     *
     * Transforms rule conditions into interactive display.
     * Handles condition logic, operators, and condition evaluation results.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for conditions or null if no conditions found.
     */
    private function adaptConditions(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $conditions = $data['conditions'] ?? null;
        
        if (!is_array($conditions) || empty($conditions)) {
            return null;
        }
        
        $json_content = json_encode($conditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Rule Conditions', $code_html, [
            'initially_expanded' => true, // Conditions are often important for debugging
            'theme' => 'rule',
            'action_buttons' => [
                [
                    'label' => 'Copy Conditions',
                    'action' => 'copyRuleConditions',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Test Conditions',
                    'action' => 'testRuleConditions',
                    'icon' => 'dashicons-yes'
                ],
                [
                    'label' => 'Debug Logic',
                    'action' => 'debugConditionLogic',
                    'icon' => 'dashicons-search'
                ]
            ]
        ]);
    }

    /**
     * Adapt Actions
     *
     * Transforms rule actions into interactive display.
     * Handles action types, parameters, and execution results.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for actions or null if no actions found.
     */
    private function adaptActions(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $actions = $data['actions'] ?? null;
        
        if (!is_array($actions) || empty($actions)) {
            return null;
        }
        
        $json_content = json_encode($actions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Rule Actions', $code_html, [
            'initially_expanded' => true, // Actions are often important for understanding what happened
            'theme' => 'rule',
            'action_buttons' => [
                [
                    'label' => 'Copy Actions',
                    'action' => 'copyRuleActions',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Replay Actions',
                    'action' => 'replayRuleActions',
                    'icon' => 'dashicons-controls-play'
                ],
                [
                    'label' => 'Validate Actions',
                    'action' => 'validateRuleActions',
                    'icon' => 'dashicons-yes'
                ]
            ]
        ]);
    }

    /**
     * Adapt Execution Details
     *
     * Transforms rule execution details into interactive display.
     * Handles execution logs, errors, and detailed execution information.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for execution details or null if no details found.
     */
    private function adaptExecutionDetails(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $execution_details = $data['execution_details'] ?? $data['execution_log'] ?? $data['debug_info'] ?? null;
        
        if (!is_array($execution_details) || empty($execution_details)) {
            return null;
        }
        
        $json_content = json_encode($execution_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Execution Details', $code_html, [
            'initially_expanded' => false,
            'theme' => 'rule',
            'action_buttons' => [
                [
                    'label' => 'Copy Details',
                    'action' => 'copyExecutionDetails',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Export Log',
                    'action' => 'exportExecutionLog',
                    'icon' => 'dashicons-download'
                ]
            ]
        ]);
    }

    /**
     * Adapt Rule Timing
     *
     * Transforms rule timing and performance data into display format.
     * Handles execution time, performance metrics, and timing analysis.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for rule timing or null if no timing data found.
     */
    private function adaptRuleTiming(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $timing_data = [];
        
        // Defensive programming: Check each timing field individually
        $execution_time = $data['execution_time'] ?? $data['time'] ?? null;
        if ($execution_time !== null && is_numeric($execution_time)) {
            $timing_data['Execution Time'] = $this->formatTime((float)$execution_time);
        }
        
        $start_time = $data['start_time'] ?? null;
        if ($start_time !== null && !empty($start_time)) {
            $timing_data['Start Time'] = $this->formatTimestamp($start_time);
        }
        
        $end_time = $data['end_time'] ?? null;
        if ($end_time !== null && !empty($end_time)) {
            $timing_data['End Time'] = $this->formatTimestamp($end_time);
        }
        
        $memory_usage = $data['memory_usage'] ?? null;
        if ($memory_usage !== null && is_numeric($memory_usage)) {
            $timing_data['Memory Usage'] = $this->formatBytes((int)$memory_usage);
        }
        
        // Only render if we have meaningful timing data
        if (empty($timing_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($timing_data, 'Performance Metrics');
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized rule evaluation data into JSON format as a fallback.
     * Ensures that all rule data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw rule evaluation data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for fallback data display.
     */
    private function adaptFallbackData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        return $toolkit->render_code_block($json_content, 'json');
    }

    /**
     * Format Time
     *
     * Formats time values into human-readable format.
     *
     * @since 1.0.0
     *
     * @param float $time Time in seconds.
     * @return string Formatted time string.
     */
    private function formatTime(float $time): string
    {
        if ($time >= 1.0) {
            return number_format($time, 3) . 's';
        } elseif ($time >= 0.001) {
            return number_format($time * 1000, 2) . 'ms';
        } else {
            return number_format($time * 1000000, 0) . 'μs';
        }
    }

    /**
     * Format Timestamp
     *
     * Formats timestamp values according to WordPress site settings.
     * Uses the centralized formatting utility from PayloadComponentUIToolkit.
     *
     * @since 1.0.0
     *
     * @param mixed $timestamp Timestamp value.
     * @return string Formatted timestamp string.
     */
    private function formatTimestamp($timestamp): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        return $toolkit->format_timestamp($timestamp);
    }

    /**
     * Format Bytes
     *
     * Formats byte values into human-readable format.
     *
     * @since 1.0.0
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' B';
    }

    /**
     * Check if this renderer can handle the provided data
     *
     * @since 1.0.0
     *
     * @param array $data Data to check.
     * @return bool True if this renderer can handle the data.
     */
    public function canHandle(array $data): bool
    {
        // Check for rule-related keys
        $rule_keys = [
            'rule', 'rule_id', 'rule_name', 'conditions', 'actions',
            'evaluation_result', 'trigger', 'trigger_type', 'rule_evaluation'
        ];
        
        foreach ($rule_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract Rule Info
     *
     * Extracts and formats rule information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted rule information.
     */
    private function extractRuleInfo(array $data): array
    {
        $rule_info = [];
        
        // Extract rule from various possible keys
        $rule = null;
        if (isset($data['rule'])) {
            $rule = $data['rule'];
        } elseif (isset($data['rule_id'])) {
            $rule = $data['rule_id'];
        }
        
        if (is_array($rule)) {
            if (isset($rule['id'])) {
                $rule_info['Rule ID'] = $rule['id'];
            }
            if (isset($rule['name'])) {
                $rule_info['Rule Name'] = $rule['name'];
            }
            if (isset($rule['status'])) {
                $rule_info['Status'] = strtoupper((string)$rule['status']);
            }
        } elseif (!is_null($rule)) {
            $rule_info['Rule ID'] = (string)$rule;
        }
        
        // Extract rule name from root level if not in rule object
        if (isset($data['rule_name']) && !isset($rule_info['Rule Name'])) {
            $rule_info['Rule Name'] = $data['rule_name'];
        }
        
        return $rule_info;
    }

    /**
     * Map Evaluation Result to Status
     *
     * Maps evaluation result to appropriate status pill data.
     *
     * @since 1.0.0
     *
     * @param mixed $result Evaluation result.
     * @return array Status label and type.
     */
    private function mapEvaluationResultToStatus($result): array
    {
        if (is_bool($result)) {
            return $result 
                ? ['label' => 'PASSED', 'type' => 'success']
                : ['label' => 'FAILED', 'type' => 'error'];
        }
        
        $result_lower = strtolower((string)$result);
        switch ($result_lower) {
            case 'passed':
            case 'success':
            case 'true':
                return ['label' => 'PASSED', 'type' => 'success'];
            case 'failed':
            case 'failure':
            case 'false':
                return ['label' => 'FAILED', 'type' => 'error'];
            case 'pending':
            case 'waiting':
                return ['label' => 'PENDING', 'type' => 'warning'];
            default:
                return ['label' => strtoupper((string)$result), 'type' => 'info'];
        }
    }

    /**
     * Extract Trigger Info
     *
     * Extracts and formats trigger information from various data structures.
     *
     * @since 1.0.0
     *
     * @param mixed $trigger Trigger data.
     * @return array Formatted trigger information.
     */
    private function extractTriggerInfo($trigger): array
    {
        $trigger_info = [];
        
        if (is_array($trigger)) {
            foreach ($trigger as $key => $value) {
                $formatted_key = ucwords(str_replace('_', ' ', $key));
                $trigger_info[$formatted_key] = $value;
            }
        } elseif (!is_null($trigger)) {
            $trigger_info['Trigger Type'] = strtoupper((string)$trigger);
        }
        
        return $trigger_info;
    }

}
