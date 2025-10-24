<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Rule Renderer
 *
 * Handles rendering of all rule-related events:
 * - rule_matched / rule_no_match
 * - condition_passed / condition_failed
 * - action_executed
 * - decision
 * - validation
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class RuleRenderer extends BaseRenderer
{
    /**
     * Constructor
     *
     * Sets the rule-specific theme.
     */
    public function __construct()
    {
        parent::__construct();
        $this->theme = 'rule';
    }

    /**
     * Render Specific Content
     *
     * Implements the template method to provide rule-specific rendering logic.
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array                    $data       The payload data to render
     * @param string                   $event_type The type of event being rendered
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @return string HTML content
     */
    protected function renderSpecificContent(array $data, string $event_type, PayloadComponentUIToolkit $toolkit): string
    {
        switch ($event_type) {
            case 'condition_passed':
            case 'condition_failed':
                return $this->renderCondition($data, $toolkit);

            case 'rule_matched':
            case 'rule_no_match':
                return $this->renderRuleMatch($data, $toolkit);

            case 'action_executed':
                return $this->renderAction($data, $toolkit);

            case 'decision':
                return $this->renderDecision($data, $toolkit);

            case 'validation':
                return $this->renderValidation($data, $toolkit);

            default:
                return $this->renderGenericRule($data, $toolkit);
        }
    }

    /**
     * Creates a precise, context-specific summary for rule execution debugging
     * 
     * Generates clear, actionable summaries that show exactly which event triggered the rule
     * 
     * @param array $data The rule execution data containing event context
     * @return string A detailed, context-aware summary for debugging
     */
    private function createDebugRuleSummary(array $data): string
    {
        $order_id = $data['order_id'] ?? 0;
        $rule_name = $data['rule_name'] ?? 'unnamed rule';
        $event_type = $data['event_type'] ?? '';
        $gateway = isset($data['source_gateway']) ? ucfirst($data['source_gateway']) : 'Unknown gateway';
        
        // Create different summary formats based on event type
        switch ($event_type) {
            case 'payment_completed':
            case 'payment_processing':
            case 'payment_pending':
            case 'payment_failed':
                // Payment-related events
                $amount_display = '';
                if (isset($data['amount']) && isset($data['currency'])) {
                    $amount_display = ' of ' . strtoupper($data['currency']) . ' ' . number_format((float)$data['amount'], 2);
                }
                return sprintf('Rule "%s" evaluated on %s payment%s for Order #%d', 
                    $rule_name, $gateway, $amount_display, $order_id);
                
            case 'order_created':
                return sprintf('Rule "%s" evaluated on new order creation for Order #%d', 
                    $rule_name, $order_id);
                
            case 'checkout_completed':
            case 'block_checkout_processed':
                return sprintf('Rule "%s" evaluated on checkout completion for Order #%d', 
                    $rule_name, $order_id);
                
            case 'order_status_changed':
                // Try to extract status change from raw data
                $from_status = $data['from_status'] ?? 'unknown';
                $to_status = $data['to_status'] ?? 'unknown';
                return sprintf('Rule "%s" evaluated on order status change (%s → %s) for Order #%d', 
                    $rule_name, $from_status, $to_status, $order_id);
                
            case 'order_check_scheduled':
                return sprintf('Rule "%s" evaluated on scheduled order check for Order #%d', 
                    $rule_name, $order_id);
                
            default:
                // Generic fallback for any other event type
                return sprintf('Rule "%s" evaluated on %s event for Order #%d', 
                    $rule_name, $event_type ?: 'unknown', $order_id);
        }
    }

    /**
     * Get Label
     *
     * Provides event-specific labels based on event type and data.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $data, string $event_type): string
    {
        switch ($event_type) {
            case 'condition_passed':
            case 'condition_failed':
                return $data['condition_label'] ?? 'Condition Evaluation';

            case 'rule_matched':
            case 'rule_no_match':
                if (!empty($data['rule_name'])) {
                    return 'Rule: ' . $data['rule_name'];
                }
                return $data['result'] === 'matched' ? 'Rule Matched' : 'Rule Not Matched';

            case 'action_executed':
                if (!empty($data['action_label'])) {
                    return 'Action: ' . $data['action_label'];
                }
                return 'Action Executed';

            case 'decision':
                return 'Decision: ' . ($data['outcome'] ?? 'Evaluated');

            case 'validation':
                return 'Validation: ' . ($data['name'] ?? 'Result');
                
            case 'rule_execution':
                // Generate a detailed context summary for rule execution events
                if (!empty($data['event_type']) || !empty($data['source_gateway']) || !empty($data['order_id'])) {
                    return $this->createDebugRuleSummary($data);
                } else if (!empty($data['summary'])) {
                    // Use provided summary if available
                    return $data['summary'];
                } else if (!empty($data['rule_name'])) {
                    return 'Rule: ' . $data['rule_name'];
                }
                // More specific than just "Rule Applied"
                return 'Rule Evaluation';

            default:
                return parent::getLabel($data, $event_type);
        }
    }

    /**
     * Get Status Pill
     *
     * Provides event-specific status pills based on event type and outcome.
     * Prioritizes debug pills for debug events.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
        // First, check if this is a debug event - if so, return debug pill
        if ($this->isDebugEvent($data)) {
            return ['label' => 'DEBUG', 'type' => 'debug'];
        }
        
        // Otherwise, use event-specific pills
        switch ($event_type) {
            case 'condition_passed':
                return ['label' => 'PASSED', 'type' => 'success'];

            case 'condition_failed':
                return ['label' => 'FAILED', 'type' => 'warning'];

            case 'rule_matched':
                return ['label' => 'MATCHED', 'type' => 'success'];

            case 'rule_no_match':
                return ['label' => 'NOT MATCHED', 'type' => 'notice'];

            case 'action_executed':
                return ['label' => 'EXECUTED', 'type' => 'info'];

            case 'decision':
                $outcome = $data['outcome'] ?? '';
                return ['label' => strtoupper($outcome), 'type' => 'info'];

            case 'validation':
                $result = $data['result'] ?? '';
                $type = strtolower($result) === 'passed' ? 'success' : 'warning';
                return ['label' => strtoupper($result), 'type' => $type];

            default:
                return null;
        }
    }

    /**
     * Render Condition
     *
     * Renders condition evaluation results with expected vs actual values.
     *
     * @param array                    $data    The condition data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderCondition(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $condition_data = [
            'Condition' => $data['condition_label'] ?? '',
            'Operator' => $data['operator'] ?? '',
            'Expected' => $data['expected_value'] ?? '',
            'Actual' => $data['actual_value'] ?? '',
        ];

        // Add order ID if available
        if (isset($data['order_id'])) {
            $condition_data['Order'] = '#' . $data['order_id'];
        }

        return $toolkit->render_key_value_list($condition_data, 'Condition Evaluation');
    }

    /**
     * Render Rule Match
     *
     * Renders rule match/no-match results with rule details.
     *
     * @param array                    $data    The rule data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderRuleMatch(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $rule_data = [
            'Rule ID' => isset($data['rule_id']) ? '#' . $data['rule_id'] : '',
            'Rule Name' => $data['rule_name'] ?? '',
        ];

        if (isset($data['priority'])) {
            $rule_data['Priority'] = (string)$data['priority'];
        }

        if (isset($data['reason'])) {
            $rule_data['Reason'] = $data['reason'];
        }

        $content = $toolkit->render_key_value_list($rule_data, 'Rule Information');

        // Add conditions in expandable section if available
        if (!empty($data['conditions'])) {
            $conditions_json = json_encode($data['conditions'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($conditions_json, 'json');
            $content .= $toolkit->render_expandable_section('Conditions', $code_block);
        }

        return $content;
    }

    /**
     * Render Action
     *
     * Renders action execution details.
     *
     * @param array                    $data    The action data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderAction(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $action_data = [
            'Action ID' => $data['action_id'] ?? '',
            'Type' => $data['action_type'] ?? '',
            'Status' => $data['status'] ?? 'Executed',
        ];

        $content = $toolkit->render_key_value_list($action_data, 'Action Details');

        // Add action parameters in expandable section if available
        if (!empty($data['parameters'])) {
            $params_json = json_encode($data['parameters'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($params_json, 'json');
            $content .= $toolkit->render_expandable_section('Parameters', $code_block);
        }

        return $content;
    }

    /**
     * Render Decision
     *
     * Renders decision outcomes with context.
     *
     * @param array                    $data    The decision data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderDecision(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $decision_data = [
            'Subject' => $data['subject'] ?? '',
            'Outcome' => $data['outcome'] ?? '',
            'Reason' => $data['reason'] ?? '',
        ];

        return $toolkit->render_key_value_list($decision_data, 'Decision Details');
    }

    /**
     * Render Validation
     *
     * Renders validation results with details.
     *
     * @param array                    $data    The validation data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderValidation(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $validation_data = [
            'Name' => $data['name'] ?? '',
            'Step' => $data['step'] ?? '',
            'Result' => $data['result'] ?? '',
        ];

        $content = $toolkit->render_key_value_list($validation_data, 'Validation Details');

        // Add validation details in expandable section if available
        if (!empty($data['details'])) {
            $details_json = json_encode($data['details'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($details_json, 'json');
            $content .= $toolkit->render_expandable_section('Details', $code_block);
        }

        return $content;
    }

    /**
     * Render Generic Rule
     *
     * Fallback renderer for unrecognized rule events.
     *
     * @param array                    $data    The rule data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderGenericRule(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // If this is a rule execution event, render it with all details
        if (isset($data['process_type']) && $data['process_type'] === 'rule_execution') {
            // Organize data - business-relevant details first, followed by technical details
            $rule_data = [
                'Status' => $data['status'] ?? '',
            ];
            
            // Add rule name if available (business-relevant)
            if (!empty($data['rule_name'])) {
                $rule_data['Rule'] = $data['rule_name'];
            }
            
            // Add event context if available (critical for debugging)
            if (!empty($data['event_type'])) {
                $rule_data['Event Type'] = $data['event_type'];
            }
            
            // Add source gateway if available
            if (!empty($data['source_gateway'])) {
                $rule_data['Gateway'] = ucfirst($data['source_gateway']);
            }
            
            // Add payment amount if available
            if (!empty($data['amount']) && !empty($data['currency'])) {
                $rule_data['Amount'] = strtoupper($data['currency']) . ' ' . number_format((float)$data['amount'], 2);
            }
            
            // Add from/to status if available (for status changes)
            if (!empty($data['from_status']) && !empty($data['to_status'])) {
                $rule_data['Status Change'] = $data['from_status'] . ' → ' . $data['to_status'];
            }
            
            // Add rule ID if available (business-relevant)
            if (!empty($data['rule_id'])) {
                $rule_data['Rule ID'] = '#' . $data['rule_id'];
            }
            
            // Add order ID if available (business-relevant)
            if (!empty($data['order_id'])) {
                $rule_data['Order'] = '#' . $data['order_id'];
            }
            
            // Add technical details to the debug_data array (for debug section)
            $data['technical_details'] = [
                'source' => $data['source'] ?? '',
                'component_count' => $data['component_count'] ?? '',
                'actor' => $data['actor'] ?? '',
                'correlation_id' => $data['correlation_id'] ?? '',
                'process_id' => $data['process_id'] ?? $data['correlation_id'] ?? '',
            ];
            
            // Use a more descriptive section title based on the data
            $section_title = 'Rule Evaluation Context';
            if (!empty($data['event_type'])) {
                $event_type = $data['event_type'];
                if (strpos($event_type, 'payment') === 0) {
                    $section_title = 'Payment Rule Evaluation';
                } elseif (strpos($event_type, 'checkout') !== false) {
                    $section_title = 'Checkout Rule Evaluation';
                } elseif ($event_type === 'order_created') {
                    $section_title = 'Order Creation Rule Evaluation';
                } elseif (strpos($event_type, 'status') !== false) {
                    $section_title = 'Status Change Rule Evaluation';
                } elseif ($event_type === 'order_check_scheduled') {
                    $section_title = 'Scheduled Order Check Evaluation';
                }
            }
            
            $content = $toolkit->render_key_value_list($rule_data, $section_title);
            
            // Technical details will be automatically added to the debug section by BaseRenderer
            return $content;
        }

        // For other data, render as key-value list
        $scalar_data = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $formattedKey = ucfirst(str_replace('_', ' ', $key));
                $scalar_data[$formattedKey] = (string)$value;
            }
        }
        
        $content = '';
        if (!empty($scalar_data)) {
            $content .= $toolkit->render_key_value_list($scalar_data, 'Details');
        }
        
        // Add complex data in expandable sections
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
}
