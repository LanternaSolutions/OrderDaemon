<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Rule Execution Display Adapter
 *
 * Specialized adapter for rule execution events that extracts and organizes
 * rule-specific data for consistent display.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
class RuleExecutionAdapter extends DisplayAdapter
{
    /**
     * Extract specialized fields for rule execution events
     *
     * @param array $payload The event payload
     * @return array Extracted specialized fields
     */
    protected function extractSpecializedFields(array $payload): array
    {
        $fields = [];

        // Extract rule execution data
        $ruleExecution = $payload['rule_execution'] ?? [];
        $ruleConfig = $ruleExecution['rule_configuration'] ?? [];
        $triggerContext = $ruleExecution['trigger_event_context'] ?? [];
        $actionExecution = $ruleExecution['action_execution'] ?? [];
        $conditionEval = $ruleExecution['condition_evaluation'] ?? [];

        // Rule information (main section)
        if (!empty($ruleConfig['rule_name'])) {
            $fields['rule_name'] = [
                'label' => 'Rule Name',
                'value' => $ruleConfig['rule_name'],
                'section' => 'main'
            ];
        }

        if (!empty($ruleConfig['rule_id'])) {
            $fields['rule_id'] = [
                'label' => 'Rule ID',
                'value' => '#' . $ruleConfig['rule_id'],
                'section' => 'main'
            ];
        }

        // Trigger information (main section)
        if (!empty($triggerContext['triggering_event'])) {
            $triggerEvent = $triggerContext['triggering_event'];
            $triggerLabel = $this->formatTriggerEvent($triggerEvent);
            $fields['trigger_event'] = [
                'label' => 'Trigger',
                'value' => $triggerLabel,
                'section' => 'main'
            ];
        }

        // Execution status (main section)
        $executionStatus = $payload['execution_status'] ?? $payload['status'] ?? 'executed';
        $fields['execution_status'] = [
            'label' => 'Status',
            'value' => ucfirst($executionStatus),
            'section' => 'main'
        ];

        // Actions taken (main section)
        if (!empty($actionExecution['primary_action']['action_label'])) {
            $primaryAction = $actionExecution['primary_action']['action_label'];
            $fields['primary_action'] = [
                'label' => 'Primary Action',
                'value' => $primaryAction,
                'section' => 'main'
            ];
        }

        // Additional details (detail sections)
        if (!empty($triggerContext['event_source'])) {
            $fields['event_source'] = [
                'label' => 'Event Source',
                'value' => ucfirst($triggerContext['event_source']),
                'section' => 'trigger_details'
            ];
        }

        if (!empty($conditionEval['conditions_passed']) && !empty($conditionEval['total_conditions'])) {
            $fields['condition_summary'] = [
                'label' => 'Conditions Passed',
                'value' => $conditionEval['conditions_passed'] . '/' . $conditionEval['total_conditions'],
                'section' => 'evaluation_details'
            ];
        }

        if (!empty($actionExecution['primary_action']['execution_result'])) {
            $fields['action_result'] = [
                'label' => 'Action Result',
                'value' => ucfirst($actionExecution['primary_action']['execution_result']),
                'section' => 'action_details'
            ];
        }

        return $fields;
    }

    /**
     * Format trigger event for display
     *
     * @param string $triggerEvent The trigger event
     * @return string Formatted trigger label
     */
    private function formatTriggerEvent(string $triggerEvent): string
    {
        switch ($triggerEvent) {
            case 'payment_completed':
                return 'Payment Completed';
            case 'order_status_changed':
                return 'Order Status Changed';
            case 'checkout_completed':
                return 'Checkout Completed';
            case 'order_created':
                return 'Order Created';
            default:
                return ucwords(str_replace('_', ' ', $triggerEvent));
        }
    }
}
