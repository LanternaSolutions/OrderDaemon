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
     * Render Content
     *
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    protected function renderContent(array $data, string $event_type): string
    {
        $toolkit = new PayloadComponentUIToolkit();

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
                return $data['rule_name'] ?? 'Rule Evaluation';

            case 'action_executed':
                return 'Action: ' . ($data['action_id'] ?? 'Executed');

            case 'decision':
                return 'Decision: ' . ($data['outcome'] ?? 'Evaluated');

            case 'validation':
                return 'Validation: ' . ($data['name'] ?? 'Result');

            default:
                return parent::getLabel($data, $event_type);
        }
    }

    /**
     * Get Status Pill
     *
     * Provides event-specific status pills based on event type and outcome.
     *
     * @param array  $data       The payload data
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $data, string $event_type): ?array
    {
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
     * Get Theme
     *
     * All rule events use the 'rule' theme for consistent styling.
     *
     * @param string $event_type The type of event
     * @return string Theme identifier
     */
    protected function getTheme(string $event_type): string
    {
        return 'rule';
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
        return $toolkit->render_code_block(
            json_encode($data, JSON_PRETTY_PRINT),
            'json'
        );
    }
}
