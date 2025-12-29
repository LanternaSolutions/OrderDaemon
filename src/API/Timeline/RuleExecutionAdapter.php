<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Rule Execution Display Adapter
 *
 * Specialized adapter for rule execution events that extracts and organizes
 * rule-specific data for consistent display. (Implements enhanced order ID
 * extraction to solve the "Order #0" issue.)
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
class RuleExecutionAdapter extends DisplayAdapter
{
    /**
     * Check if debug mode is enabled
     */
    protected function isDebugMode(): bool
    {
        return defined('ODCM_DEBUG') && ODCM_DEBUG;
    }

    /**
     * Check if this is an incomplete rule event
     */
    private function isIncompleteRuleEvent(array $payload): bool
    {
        // Must be a rule execution event
        if (strpos($payload['event_type'] ?? '', 'rule_execution') === false) {
            return false;
        }

        // Check for complete rule data
        $hasCompleteData = !empty($payload['rule_execution']['rule_name']) ||
                          !empty($payload['rule_execution']['rule_configuration']['rule_name']) ||
                          !empty($payload['rule_name']) ||
                          !empty($payload['data']['rule_name']);

        // If no complete rule data but has processing metadata, it's incomplete
        $hasProcessingData = !empty($payload['data']['correlation_id']) ||
                            !empty($payload['data']['process_type']) ||
                            !empty($payload['data']['status']);

        return !$hasCompleteData && $hasProcessingData;
    }

    /**
     * Extract fields for incomplete rule events (debug only)
     */
    private function extractProcessingStartedFields(array $payload): array
    {
        $fields = [];

        // Event description - clearly indicate this is a processing event
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->translate('Rule Processing Started'),
            'section' => 'primary'
        ];

        // Extract order ID if available
        $order_id = $this->extractRuleExecutionOrderId($payload);
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => $this->translate('Order'),
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }

        // Add correlation ID for tracking
        if (!empty($payload['data']['correlation_id'])) {
            $fields['correlation_id'] = [
                'label' => $this->translate('Processing ID'),
                'value' => $payload['data']['correlation_id'],
                'section' => 'primary'
            ];
        }

        // Processing status
        $fields['processing_status'] = [
            'label' => $this->translate('Status'),
            'value' => $this->translate('Processing'),
            'section' => 'primary'
        ];

        // Add debug indicator
        $fields['debug_indicator'] = [
            'label' => $this->translate('Type'),
            'value' => $this->translate('Debug Event'),
            'section' => 'primary'
        ];

        return $fields;
    }

    /**
     * Extract specialized fields for rule execution events
     *
     * @param array $payload The event payload
     * @return array Extracted specialized fields
     */
    protected function extractSpecializedFields(array &$payload): array
    {
        // Check if this is an incomplete processing event
        if ($this->isIncompleteRuleEvent($payload)) {
            // Add debug flag to payload for filtering
            $payload['debug_only'] = true;
            return $this->extractProcessingStartedFields($payload);
        }

        // Original logic for complete rule execution events
        $fields = [];

        // Enhanced order ID extraction specifically for rule executions
        $order_id = $this->extractRuleExecutionOrderId($payload);

        // Event description with context
        $ruleName = $this->extractRuleName($payload);
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => sprintf($this->translate('Rule Executed: %s'), $ruleName),
            'section' => 'primary'
        ];

        // Order ID (critical for fixing Order #0)
        if ($order_id > 0) {
            $fields['order_id'] = [
                'label' => $this->translate('Order'),
                'value' => '#' . $order_id,
                'section' => 'primary'
            ];
        }

        // Rule name
        $fields['rule_name'] = [
            'label' => $this->translate('Rule'),
            'value' => $ruleName,
            'section' => 'primary'
        ];

        // Actions taken
        $actions = $this->extractActionsTaken($payload);
        if (!empty($actions)) {
            $fields['actions_taken'] = [
                'label' => $this->translate('Action Taken'),
                'value' => implode(', ', $actions),
                'section' => 'primary'
            ];
        }

        // Trigger information
        $trigger = $payload['trigger'] ?? $payload['rule_execution']['trigger'] ?? '';
        if (!empty($trigger)) {
            $fields['trigger'] = [
                'label' => $this->translate('Trigger'),
                'value' => $trigger,
                'section' => 'primary'
            ];
        }

        // Execution status
        $status = $payload['rule_execution']['status'] ?? 
                 $payload['execution_status'] ?? 
                 $payload['status'] ?? 
                 'EXECUTED';
        $fields['execution_status'] = [
            'label' => $this->translate('Execution Status'),
            'value' => ucfirst(strtolower($status)),
            'section' => 'primary'
        ];

        // Status changes with context - use actual status data if available
        $fromStatus = $payload['rule_execution']['order_evaluation_context']['from_status'] ?? 
                     $payload['from_status'] ?? 
                     $payload['data']['from_status'] ?? 
                     'pending';

        $toStatus = $payload['rule_execution']['order_evaluation_context']['to_status'] ?? 
                   $payload['to_status'] ?? 
                   $payload['data']['to_status'] ?? 
                   'completed';

        $fields['status_change'] = [
            'label' => $this->translate('Status Change'),
            'value' => $this->formatStatusChange($fromStatus, $toStatus),
            'section' => 'primary'
        ];

        // Additional rule execution details (detail sections) - only essential business information
        $this->addRuleExecutionDetails($fields, $payload);

        return $fields;
    }

    /**
     * Enhanced order ID extraction specifically for rule execution events
     *
     * This method implements a priority-based search through multiple payload
     * locations to find the most reliable order ID for rule execution contexts.
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload containing rule execution data.
     * @return int The extracted order ID, or 0 if none found.
     */
    private function extractRuleExecutionOrderId(array $payload): int
    {
        // Extended sources list for rule executions - implementing the guide's enhanced extraction
        $sources = [
            // Priority 1: Rule execution context (most reliable for rule events)
            $payload['rule_execution']['order_evaluation_context']['order_id'] ?? null,
            $payload['rule_execution']['trigger_event_context']['order_id'] ?? null,
            $payload['rule_execution']['context']['order_id'] ?? null,

            // Priority 2: Event trigger context
            $payload['trigger_event_context']['order_id'] ?? null,
            $payload['trigger_context']['order_id'] ?? null,

            // Priority 3: Direct payload
            $payload['order_id'] ?? null,
            $payload['primary_object_id'] ?? null,

            // Priority 4: Data nested
            ($payload['data'] ?? [])['order_id'] ?? null,
            ($payload['data'] ?? [])['primary_object_id'] ?? null,

            // Priority 5: Technical details
            ($payload['technical_details'] ?? [])['order_id'] ?? null,

            // Priority 6: Event data summary
            ($payload['event_data_summary'] ?? [])['order_id'] ?? null,

            // Priority 7: Rule-specific locations
            ($payload['rule_execution'] ?? [])['rule_configuration']['target_order_id'] ?? null,
            ($payload['rule_execution'] ?? [])['evaluation_context']['order'] ?? null,
        ];

        foreach ($sources as $source) {
            if (is_numeric($source) && (int)$source > 0) {
                return (int)$source;
            }
            // Handle case where order ID might be in an object/array format
            if (is_array($source) && isset($source['id']) && is_numeric($source['id']) && (int)$source['id'] > 0) {
                return (int)$source['id'];
            }
        }

        return 0;
    }

    /**
     * Extract rule name from payload
     *
     * @param array $payload The event payload
     * @return string The rule name
     */
    private function extractRuleName(array $payload): string
    {
        return $payload['rule_execution']['rule_name'] ?? 
               $payload['rule_execution']['rule_configuration']['rule_name'] ?? 
               $payload['rule_name'] ?? 
               $payload['data']['rule_name'] ?? 
               $this->translate('Unknown Rule', 'order-daemon');
    }

    /**
     * Extract actions taken by the rule
     *
     * @param array $payload The event payload
     * @return array Array of action descriptions
     */
    private function extractActionsTaken(array $payload): array
    {
        $actions = [];

        // Check various locations for actions
        $actionData = $payload['rule_execution']['actions'] ?? 
                     $payload['rule_execution']['action_execution'] ?? 
                     $payload['actions_taken'] ?? 
                     $payload['data']['actions'] ?? 
                     [];

        if (is_array($actionData)) {
            // Handle different action data formats
            if (isset($actionData['primary_action'])) {
                $primaryAction = $actionData['primary_action'];
                if (is_string($primaryAction)) {
                    $actions[] = $primaryAction;
                } elseif (is_array($primaryAction)) {
                    $actions[] = $primaryAction['action_label'] ?? 
                                $primaryAction['description'] ?? 
                                $primaryAction['type'] ?? 
                                $this->translate('Action Executed');
                }
            } else {
                // Handle flat array of actions
                foreach ($actionData as $action) {
                    if (is_string($action)) {
                        $actions[] = $action;
                    } elseif (is_array($action)) {
                        $actions[] = $action['description'] ?? 
                                    $action['action_label'] ?? 
                                    $action['type'] ?? 
                                    $this->translate('Action Executed');
                    }
                }
            }
        }

        // Fallback: if no actions found, try to infer from rule execution status
        if (empty($actions)) {
            $status = $payload['rule_execution']['status'] ?? 
                     $payload['execution_status'] ?? 
                     $payload['status'] ?? '';

            if (strtolower($status) === 'executed' || strtolower($status) === 'success') {
                $actions[] = $this->translate('Rule Actions Executed');
            }
        }

        return array_unique($actions);
    }

    /**
     * Add detailed rule execution information to detail sections
     *
     * @param array &$fields Reference to fields array to add details to
     * @param array $payload The event payload
     * @return void
     */
    private function addRuleExecutionDetails(array &$fields, array $payload): void
    {
        $ruleExecution = $payload['rule_execution'] ?? [];
        $triggerContext = $ruleExecution['trigger_event_context'] ?? [];
        $conditionEval = $ruleExecution['condition_evaluation'] ?? [];
        $actionExecution = $ruleExecution['action_execution'] ?? [];

        // Trigger details section
        if (!empty($triggerContext['triggering_event'])) {
            $fields['trigger_event'] = [
                'label' => $this->translate('Trigger Event'),
                'value' => $this->formatTriggerEvent($triggerContext['triggering_event']),
                'section' => 'trigger_details'
            ];
        }

        if (!empty($triggerContext['event_source'])) {
            $fields['event_source'] = [
                'label' => $this->translate('Event Source'),
                'value' => ucfirst($triggerContext['event_source']),
                'section' => 'trigger_details'
            ];
        }

        // Condition evaluation section
        if (!empty($conditionEval['conditions_passed']) && !empty($conditionEval['total_conditions'])) {
            $fields['condition_summary'] = [
                'label' => $this->translate('Conditions Passed'),
                'value' => $conditionEval['conditions_passed'] . '/' . $conditionEval['total_conditions'],
                'section' => 'evaluation_details'
            ];
        }

        // Action execution section
        if (!empty($actionExecution['primary_action']['execution_result'])) {
            $fields['action_result'] = [
                'label' => $this->translate('Action Result'),
                'value' => ucfirst($actionExecution['primary_action']['execution_result']),
                'section' => 'action_details'
            ];
        }

        // Rule configuration details
        $ruleConfig = $ruleExecution['rule_configuration'] ?? [];
        if (!empty($ruleConfig['rule_id'])) {
            $fields['rule_id'] = [
                'label' => $this->translate('Rule ID'),
                'value' => '#' . $ruleConfig['rule_id'],
                'section' => 'rule_details'
            ];
        }
    }

    /**
     * Format trigger event for display
     *
     * @param string $triggerEvent The trigger event
     * @return string Formatted trigger label
     */
    private function formatTriggerEvent(string $triggerEvent): string
    {
        $eventLabels = [
            'payment_completed' => $this->translate('Payment Completed'),
            'order_status_changed' => $this->translate('Order Status Changed'),
            'checkout_completed' => $this->translate('Checkout Completed'),
            'order_created' => $this->translate('Order Created'),
            'order_updated' => $this->translate('Order Updated'),
            'status_changed' => $this->translate('Status Changed'),
        ];

        return $eventLabels[$triggerEvent] ?? ucwords(str_replace('_', ' ', $triggerEvent));
    }
}
