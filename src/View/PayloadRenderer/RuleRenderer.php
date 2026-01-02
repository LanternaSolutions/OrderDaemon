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
     * Log a debug message using WordPress-compatible logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    protected function logDebugMessage(string $message, string $level = 'debug'): void
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
     * @param array                    $payload    The full payload data to render (including rawData)
     * @param string                   $event_type The type of event being rendered
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @return string HTML content
     */
    protected function renderSpecificContent(array $payload, string $event_type, PayloadComponentUIToolkit $toolkit): string
    {
        // For rule_execution events, ALWAYS use enhanced rendering
        // The enhanced renderer is robust enough to extract data from multiple sources
        if ($event_type === 'rule_execution') {
            return $this->renderEnhancedRuleExecution($payload, $toolkit);
        }
        
        // Extract data from payload structure for other event types
        $data = $payload['data'] ?? $payload;
        
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

            case 'rule_evaluation_non_canonical':
                return $this->renderNonCanonicalRuleEvaluation($payload, $toolkit);

            default:
                return $this->renderGenericRule($payload, $toolkit);
        }
    }

    /**
     * Render Rich Rule Execution
     *
     * Provides a complete business-focused story of rule execution with progressive
     * disclosure of technical details. Designed for store owners first, developers second.
     *
     * @param array                    $payload Full payload including rawData with rule execution details
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderEnhancedRuleExecution(array $payload, PayloadComponentUIToolkit $toolkit): string
    {
        $data = $payload['data'] ?? $payload;
        $rawData = $payload['rawData'] ?? [];
        $ruleExecution = $rawData['rule_execution'] ?? [];
        
        // === BUSINESS-FIRST MAIN DISPLAY ===
        
        // Extract rule execution details
        $rule_config = $ruleExecution['rule_configuration'] ?? [];
        $order_context = $ruleExecution['order_evaluation_context'] ?? [];
        $trigger_context = $ruleExecution['trigger_event_context'] ?? [];
        $action_execution = $ruleExecution['action_execution'] ?? [];
        $condition_eval = $ruleExecution['condition_evaluation'] ?? [];
        
        // Build business summary - exactly as user specified
        $business_summary = [];
        
        // Execution Summary: "Completed Order (status changed from Processing → Completed)"
        $execution_summary = $this->formatExecutionSummary($action_execution, $trigger_context);
        if (!empty($execution_summary)) {
            $business_summary['Execution Summary'] = $execution_summary;
        }
        
        // Trigger: "payment completion via Stripe"
        $trigger_desc = $this->formatTriggerContext($trigger_context);
        if (!empty($trigger_desc)) {
            $business_summary['Trigger'] = $trigger_desc;
        }
        
        // Actions: "Complete Order, Send Completion Email"
        $actions_desc = $this->formatActionsExecuted($action_execution);
        if (!empty($actions_desc)) {
            $business_summary['Actions'] = $actions_desc;
        }
        
        $content = $toolkit->render_key_value_list($business_summary, 'Rule Execution');
        
        // === SMART PROGRESSIVE DISCLOSURE (deduplicated sections) ===
        
        $sections_added = [];
        
        // 1. Rule Evaluation Summary (combines evaluation + trigger info)
        if (!empty($condition_eval) || !empty($trigger_context)) {
            $evaluation_summary = $this->buildCombinedEvaluationSummary($condition_eval, $order_context, $trigger_context);
            if (!empty($evaluation_summary)) {
                $content .= $toolkit->render_expandable_key_value_section('Rule Evaluation Summary', $evaluation_summary);
                $sections_added[] = 'evaluation';
            }
        }
        
        // 2. Action Results (only if not already covered in evaluation)
        if (!empty($action_execution) && !in_array('evaluation', $sections_added)) {
            $action_details = $this->buildActionDetails($action_execution);
            if (!empty($action_details)) {
                $content .= $toolkit->render_expandable_key_value_section('Action Results', $action_details);
                $sections_added[] = 'actions';
            }
        }
        
        // 3. Complete Technical Data (single comprehensive section)
        $technical_details = $this->buildSingleTechnicalSection($ruleExecution, $data);
        if (!empty($technical_details)) {
            $content .= $toolkit->render_expandable_key_value_section('Technical Execution Details', $technical_details);
        }
        
        return $content;
    }
    
    /**
     * Format execution summary
     * "Completed Order (status changed from Processing → Completed)"
     */
    private function formatExecutionSummary(array $action_execution, array $trigger_context): string
    {
        $summary_parts = [];
        
        // Determine what happened based on actions
        if (isset($action_execution['primary_action']['action_label'])) {
            $primary_action = $action_execution['primary_action']['action_label'];
            if (stripos($primary_action, 'complete') !== false) {
                $summary_parts[] = 'Completed Order';
            } else {
                $summary_parts[] = $primary_action;
            }
        }
        
        // Add status transition if available
        $status_transition = $trigger_context['status_transition'] ?? [];
        if (!empty($status_transition['from_status']) && !empty($status_transition['to_status'])) {
            $from = ucfirst($status_transition['from_status']);
            $to = ucfirst($status_transition['to_status']);
            $summary_parts[] = "(status changed from {$from} → {$to})";
        }
        
        return implode(' ', $summary_parts);
    }

    /**
     * Format trigger context description
     * "payment completion via Stripe"
     */
    private function formatTriggerContext(array $trigger_context): string
    {
        $triggering_event = $trigger_context['triggering_event'] ?? '';
        $event_source = $trigger_context['event_source'] ?? '';
        $event_channel = $trigger_context['event_channel'] ?? '';
        
        $parts = [];
        
        // Format the event type
        switch ($triggering_event) {
            case 'payment_completed':
                $parts[] = 'payment completion';
                break;
            case 'order_status_changed':
                $parts[] = 'status change';
                break;
            case 'checkout_processed':
                $parts[] = 'checkout completion';
                break;
            case 'order_created':
                $parts[] = 'order creation';
                break;
            default:
                $parts[] = str_replace('_', ' ', $triggering_event);
        }
        
        // Add source gateway if available
        if (!empty($event_source) && $event_source !== 'unknown') {
            $parts[] = 'via ' . ucfirst($event_source);
        }
        
        return implode(' ', $parts);
    }

    /**
     * Format actions executed description
     * "Complete Order, Send Completion Email"
     */
    private function formatActionsExecuted(array $action_execution): string
    {
        $actions = [];
        
        // Add primary action
        if (isset($action_execution['primary_action']['action_label'])) {
            $actions[] = $action_execution['primary_action']['action_label'];
        }
        
        // Add secondary actions
        if (!empty($action_execution['secondary_actions'])) {
            foreach ($action_execution['secondary_actions'] as $action) {
                if (isset($action['action_label'])) {
                    $actions[] = $action['action_label'];
                }
            }
        }
        
        return implode(', ', $actions);
    }

    /**
     * Build evaluation details for progressive disclosure
     */
    private function buildEvaluationDetails(array $condition_eval, array $order_context): array
    {
        $details = [];
        
        // Show evaluation logic summary
        $total_conditions = $condition_eval['total_conditions'] ?? 0;
        $conditions_passed = $condition_eval['conditions_passed'] ?? 0;
        $evaluation_logic = $condition_eval['evaluation_logic'] ?? 'ALL';
        
        $details['Evaluation Result'] = "{$conditions_passed}/{$total_conditions} conditions passed";
        $details['Evaluation Logic'] = $evaluation_logic . ' conditions must pass';
        
        // Show order context at evaluation time
        if (!empty($order_context['order_status'])) {
            $details['Order Status'] = ucfirst($order_context['order_status']);
        }
        
        if (!empty($order_context['order_total'])) {
            $currency = $order_context['order_currency'] ?? 'USD';
            $details['Order Total'] = strtoupper($currency) . ' ' . number_format((float)$order_context['order_total'], 2);
        }
        
        if (!empty($order_context['payment_method_title'])) {
            $details['Payment Method'] = $order_context['payment_method_title'];
        }
        
        if (!empty($order_context['customer_type'])) {
            $details['Customer Type'] = ucfirst($order_context['customer_type']);
        }
        
        return $details;
    }

    /**
     * Build trigger details for progressive disclosure
     */
    private function buildTriggerDetails(array $trigger_context): array
    {
        $details = [];
        
        $details['Event Type'] = $trigger_context['triggering_event'] ?? '';
        $details['Event Source'] = ucfirst($trigger_context['event_source'] ?? 'unknown');
        $details['Event Channel'] = ucfirst($trigger_context['event_channel'] ?? 'system');
        
        if (!empty($trigger_context['event_timestamp'])) {
            $details['Event Time'] = gmdate('Y-m-d H:i:s', strtotime($trigger_context['event_timestamp']));
        }
        
        if (!empty($trigger_context['idempotency_key'])) {
            // Truncate long keys for readability
            $key = $trigger_context['idempotency_key'];
            if (strlen($key) > 20) {
                $key = substr($key, 0, 8) . '...' . substr($key, -8);
            }
            $details['Event ID'] = $key;
        }
        
        // Add status transition if available
        $status_transition = $trigger_context['status_transition'] ?? [];
        if (!empty($status_transition['from_status'])) {
            $details['From Status'] = ucfirst($status_transition['from_status']);
        }
        if (!empty($status_transition['to_status'])) {
            $details['To Status'] = ucfirst($status_transition['to_status']);
        }
        
        return $details;
    }

    /**
     * Build action details for progressive disclosure
     */
    private function buildActionDetails(array $action_execution): array
    {
        $details = [];
        
        // Primary action
        if (isset($action_execution['primary_action'])) {
            $primary = $action_execution['primary_action'];
            $details['Primary Action'] = $primary['action_label'] ?? $primary['action_id'] ?? 'Unknown';
            $details['Primary Result'] = ucfirst($primary['execution_result'] ?? 'unknown');
        }
        
        // Secondary actions count
        if (!empty($action_execution['secondary_actions'])) {
            $secondary_count = count($action_execution['secondary_actions']);
            $details['Secondary Actions'] = "{$secondary_count} additional actions";
            
            // Show success rate for secondary actions
            $successful = 0;
            foreach ($action_execution['secondary_actions'] as $action) {
                if (($action['execution_result'] ?? '') === 'success') {
                    $successful++;
                }
            }
            $details['Secondary Success Rate'] = "{$successful}/{$secondary_count} successful";
        }
        
        return $details;
    }

    /**
     * Build technical details for progressive disclosure
     */
    private function buildTechnicalDetails(array $ruleExecution, array $data): array
    {
        $details = [];
        
        // Rule configuration
        $rule_config = $ruleExecution['rule_configuration'] ?? [];
        if (!empty($rule_config['rule_id'])) {
            $details['Rule ID'] = '#' . $rule_config['rule_id'];
        }
        if (!empty($rule_config['trigger_type'])) {
            $details['Trigger Type'] = $rule_config['trigger_type'];
        }
        
        // Execution metrics
        $execution_metrics = $ruleExecution['execution_metrics'] ?? [];
        if (!empty($execution_metrics['evaluation_time_ms'])) {
            $details['Evaluation Time'] = number_format((float)$execution_metrics['evaluation_time_ms'], 2) . ' ms';
        }
        if (isset($execution_metrics['first_match_wins'])) {
            $details['First Match Wins'] = $execution_metrics['first_match_wins'] ? 'Yes' : 'No';
        }
        
        // Add correlation data
        if (!empty($data['correlation_id'])) {
            $correlation_id = $data['correlation_id'];
            if (strlen($correlation_id) > 20) {
                $correlation_id = substr($correlation_id, 0, 8) . '...' . substr($correlation_id, -8);
            }
            $details['Correlation ID'] = $correlation_id;
        }
        
        if (!empty($data['process_id'])) {
            $process_id = $data['process_id'];
            if (strlen($process_id) > 20) {
                $process_id = substr($process_id, 0, 8) . '...' . substr($process_id, -8);
            }
            $details['Process ID'] = $process_id;
        }
        
        return $details;
    }

    /**
     * Build combined evaluation summary - SAFE METHOD for deduplication
     * Combines existing evaluation + trigger info into one section to eliminate duplication
     *
     * @param array $condition_eval Rule condition evaluation data
     * @param array $order_context Order evaluation context
     * @param array $trigger_context Trigger event context
     * @return array Combined evaluation details
     */
    private function buildCombinedEvaluationSummary(array $condition_eval, array $order_context, array $trigger_context): array
    {
        $combined = [];
        
        // Add evaluation summary from condition_eval
        if (!empty($condition_eval)) {
            $evaluation_details = $this->buildEvaluationDetails($condition_eval, $order_context);
            $combined = array_merge($combined, $evaluation_details);
        }
        
        // Add trigger details for timing context
        if (!empty($trigger_context)) {
            $trigger_details = $this->buildTriggerDetails($trigger_context);
            // Only add trigger details that aren't already covered
            foreach ($trigger_details as $key => $value) {
                if (!isset($combined[$key])) {
                    $combined[$key] = $value;
                }
            }
        }
        
        // Enhance with condition type display if available
        if (!empty($condition_eval['condition_details'])) {
            $condition_summaries = [];
            foreach ($condition_eval['condition_details'] as $condition) {
                $condition_display = $this->formatConditionForDisplay($condition);
                if (!empty($condition_display)) {
                    $condition_summaries[] = $condition_display;
                }
            }
            
            if (!empty($condition_summaries)) {
                $combined['Conditions'] = implode(', ', $condition_summaries);
            }
        }
        
        return $combined;
    }

    /**
     * Build single technical section - SAFE METHOD for deduplication
     * Creates one comprehensive technical section instead of multiple overlapping ones
     *
     * @param array $ruleExecution Complete rule execution data
     * @param array $data Additional component data
     * @return array Flattened technical details
     */
    private function buildSingleTechnicalSection(array $ruleExecution, array $data): array
    {
        $flattened = [];
        
        // Rule configuration
        $rule_config = $ruleExecution['rule_configuration'] ?? [];
        if (!empty($rule_config['rule_id'])) {
            $flattened['Rule ID'] = '#' . $rule_config['rule_id'];
        }
        if (!empty($rule_config['trigger_type'])) {
            $flattened['Trigger Type'] = $rule_config['trigger_type'];
        }
        
        // Execution metrics
        $metrics = $ruleExecution['execution_metrics'] ?? [];
        if (!empty($metrics['evaluation_time_ms'])) {
            $flattened['Evaluation Time'] = number_format((float)$metrics['evaluation_time_ms'], 2) . ' ms';
        }
        if (isset($metrics['first_match_wins'])) {
            $flattened['First Match Wins'] = $metrics['first_match_wins'] ? 'Yes' : 'No';
        }
        if (isset($metrics['rule_position_in_queue'])) {
            $flattened['Rule Position'] = '#' . $metrics['rule_position_in_queue'];
        }
        
        // Trigger context technical details
        $trigger_context = $ruleExecution['trigger_event_context'] ?? [];
        if (!empty($trigger_context['idempotency_key'])) {
            $key = $trigger_context['idempotency_key'];
            if (strlen($key) > 30) {
                $key = substr($key, 0, 12) . '...' . substr($key, -12);
            }
            $flattened['Event Idempotency Key'] = $key;
        }
        
        // Add correlation data
        if (!empty($data['correlation_id'])) {
            $correlation_id = $data['correlation_id'];
            if (strlen($correlation_id) > 30) {
                $correlation_id = substr($correlation_id, 0, 12) . '...' . substr($correlation_id, -12);
            }
            $flattened['Correlation ID'] = $correlation_id;
        }
        
        if (!empty($data['process_id'])) {
            $process_id = $data['process_id'];
            if (strlen($process_id) > 30) {
                $process_id = substr($process_id, 0, 12) . '...' . substr($process_id, -12);
            }
            $flattened['Process ID'] = $process_id;
        }
        
        // Action execution summary
        $action_execution = $ruleExecution['action_execution'] ?? [];
        if (isset($action_execution['primary_action']['execution_result'])) {
            $flattened['Primary Action Result'] = ucfirst($action_execution['primary_action']['execution_result']);
        }
        
        return $flattened;
    }

    /**
     * Format condition for display - SAFE METHOD for better condition type display
     * Reads existing condition data and formats for user-friendly display
     *
     * @param array $condition Condition evaluation data
     * @return string Formatted condition display
     */
    private function formatConditionForDisplay(array $condition): string
    {
        $condition_type = $condition['condition_type'] ?? 'unknown';
        $condition_label = $condition['condition_label'] ?? '';
        $result = strtoupper($condition['result'] ?? 'UNKNOWN');
        
        // Use better display name
        $display_name = $this->getConditionDisplayName($condition_type, $condition_label);
        
        return "{$display_name}: {$result}";
    }

    /**
     * Get condition display name - SAFE METHOD for display mapping
     * Maps technical condition types to user-friendly names
     *
     * @param string $condition_type Technical condition type
     * @param string $condition_label Condition label if available
     * @return string User-friendly display name
     */
    private function getConditionDisplayName(string $condition_type, string $condition_label): string
    {
        // Use label if available and meaningful
        if (!empty($condition_label) && $condition_label !== 'unknown') {
            return $condition_label;
        }
        
        // Map common technical types to friendly names
        $display_mapping = [
            'product_type' => 'Product Type',
            'order_total' => 'Order Total',
            'customer_type' => 'Customer Type',
            'payment_method' => 'Payment Method',
            'shipping_country' => 'Shipping Country',
            'product_category' => 'Product Category',
            'unknown' => 'Condition Check'
        ];
        
        return $display_mapping[$condition_type] ?? ucwords(str_replace('_', ' ', $condition_type));
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
     * @param array  $payload    The full payload data (not just nested data)
     * @param string $event_type The type of event
     * @return string Component label
     */
    protected function getLabel(array $payload, string $event_type): string
    {
        // For rule_execution events, robustly extract rule name from multiple sources
        if ($event_type === 'rule_execution') {
            // Check top-level payload for enhanced rule data (from UniversalEventProcessor)
            $rule_name = $payload['rule_name'] ?? null;
            
            // If not found at top level, check nested data structure
            if (empty($rule_name)) {
                $data = $payload['data'] ?? $payload;
                $rule_name = $data['rule_name'] ?? null;
            }
            
            // If still not found, check rawData.rule_execution.rule_configuration.rule_name
            if (empty($rule_name) && isset($payload['rawData']['rule_execution']['rule_configuration']['rule_name'])) {
                $rule_name = $payload['rawData']['rule_execution']['rule_configuration']['rule_name'];
            }
            
            // Final fallback
            if (empty($rule_name)) {
                $rule_name = 'unnamed rule';
            }
            
            // Extract order ID using the improved extraction method
            $order_id = $this->extractOrderId($payload);
            
            // Generate business-friendly summary based on available data
            if ($rule_name !== 'unnamed rule' && $order_id > 0) {
                return sprintf('Rule "%s" evaluated successfully for Order #%d', $rule_name, $order_id);
            } elseif ($rule_name !== 'unnamed rule') {
                return sprintf('Rule "%s" evaluated successfully', $rule_name);
            } else {
                return sprintf("Rule evaluation completed for Order #%d", $order_id > 0 ? $order_id : 0);
            }
        }
        
        // For other event types, use nested data structure
        $data = $payload['data'] ?? $payload;
        
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

            default:
                return parent::getLabel($payload, $event_type);
        }
    }

    /**
     * Get Status Pill
     *
     * Provides event-specific status pills based on event type and outcome.
     * Prioritizes debug pills for debug events.
     *
     * @param array  $payload    The full payload data (not just nested data)
     * @param string $event_type The type of event
     * @return array|null Status pill config
     */
    protected function getStatusPill(array $payload, string $event_type): ?array
    {
        // First, check if this is a debug event - if so, return debug pill
        if ($this->isDebugEvent($payload)) {
            return ['label' => 'DEBUG', 'type' => 'debug'];
        }
        
        // For rule_execution events, check top-level payload first for enhanced data
        if ($event_type === 'rule_execution') {
            // Determine execution status from multiple possible locations
            $execution_status = '';
            $pill_type = 'info';
            
            // Check top-level first (enhanced rule data from UniversalEventProcessor)
            if (isset($payload['execution_status'])) {
                $execution_status = strtoupper($payload['execution_status']);
                $pill_type = strtolower($payload['execution_status']) === 'executed' ? 'success' : 'error';
            } elseif (isset($payload['status'])) {
                $status = strtolower($payload['status']);
                if ($status === 'success') {
                    $execution_status = 'EXECUTED';
                    $pill_type = 'success';
                } elseif (in_array($status, ['error', 'failed', 'failure'])) {
                    $execution_status = 'FAILED';
                    $pill_type = 'error';
                } else {
                    $execution_status = strtoupper($status);
                    $pill_type = 'info';
                }
            } else {
                // Check nested data structure
                $data = $payload['data'] ?? $payload;
                if (isset($data['execution_status'])) {
                    $execution_status = strtoupper($data['execution_status']);
                    $pill_type = strtolower($data['execution_status']) === 'executed' ? 'success' : 'error';
                } elseif (isset($data['status'])) {
                    $status = strtolower($data['status']);
                    if ($status === 'success') {
                        $execution_status = 'EXECUTED';
                        $pill_type = 'success';
                    } elseif (in_array($status, ['error', 'failed', 'failure'])) {
                        $execution_status = 'FAILED';
                        $pill_type = 'error';
                    } else {
                        $execution_status = strtoupper($status);
                        $pill_type = 'info';
                    }
                } else {
                    $execution_status = 'EVALUATED';
                    $pill_type = 'info';
                }
            }
            
            return ['label' => $execution_status, 'type' => $pill_type];
        }
        
        // For other event types, use nested data structure
        $data = $payload['data'] ?? $payload;
        
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
     * Extract rule name from multiple data sources in the payload
     *
     * @param array $payload Full payload data
     * @return string|null Rule name or null if not found
     */
    private function extractRuleName(array $payload): ?string
    {
        // Check top-level payload for enhanced rule data
        $rule_name = $payload['rule_name'] ?? null;
        
        // If not found at top level, check nested data structure
        if (empty($rule_name)) {
            $data = $payload['data'] ?? $payload;
            $rule_name = $data['rule_name'] ?? null;
        }
        
        // If still not found, check rawData.rule_execution.rule_configuration.rule_name
        if (empty($rule_name) && isset($payload['rawData']['rule_execution']['rule_configuration']['rule_name'])) {
            $rule_name = $payload['rawData']['rule_execution']['rule_configuration']['rule_name'];
        }
        
        return $rule_name;
    }

    /**
     * Extract order ID from multiple data sources in the payload - ENHANCED VERSION
     *
     * @param array $payload Full payload data
     * @return int Order ID or 0 if not found
     */
    private function extractOrderId(array $payload): int
    {
        // Add enhanced debugging to understand the payload structure
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM DEBUG - RuleRenderer extractOrderId payload keys: " . implode(', ', array_keys($payload)));
            if (isset($payload['rawData'])) {
                $this->logDebugMessage("ODCM DEBUG - RuleRenderer rawData keys: " . implode(', ', array_keys($payload['rawData'])));
                if (isset($payload['rawData']['rule_execution'])) {
                    $this->logDebugMessage("ODCM DEBUG - RuleRenderer rule_execution keys: " . implode(', ', array_keys($payload['rawData']['rule_execution'])));
                }
            }
            if (isset($payload['data'])) {
                $this->logDebugMessage("ODCM DEBUG - RuleRenderer data keys: " . implode(', ', array_keys($payload['data'])));
            }
        }
        
        // EXPANDED sources list for reliable order ID extraction
        $sources = [
            // Priority 1: Rule execution context (most reliable for rule events)
            $payload['rawData']['rule_execution']['order_evaluation_context']['order_id'] ?? null,
            
            // Priority 2: Rule trigger context (frequently contains order ID)
            $payload['rawData']['rule_execution']['trigger_event_context']['order_id'] ?? null,
            
            // Priority 3: Direct in rawData (common format for many events)
            $payload['rawData']['order_id'] ?? null,
            $payload['rawData']['primary_object_id'] ?? null,
            $payload['rawData']['oid'] ?? null,
            
            // Priority 4: Universal Events fields (for new structure)
            $payload['primaryObjectID'] ?? null,
            $payload['primary_object_id'] ?? null,
            
            // Priority 5: Top-level payload  
            $payload['order_id'] ?? null,
            $payload['oid'] ?? null, // ProcessLogger format uses 'oid'
            
            // Priority 6: Nested data structure
            ($payload['data'] ?? [])['order_id'] ?? null,
            ($payload['data'] ?? [])['primary_object_id'] ?? null,
            ($payload['data'] ?? [])['oid'] ?? null,
            
            // Priority 7: Look in technical_details
            ($payload['technical_details'] ?? [])['order_id'] ?? null,
            
            // Priority 8: Event data summary (sometimes contains order ID)
            ($payload['event_data_summary'] ?? [])['order_id'] ?? null,
            ($payload['event_data_summary'] ?? [])['primary_object_id'] ?? null
        ];
        
        // Extended debug logging to trace all checked sources
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM DEBUG - RuleRenderer checking " . count($sources) . " possible order ID sources");
            foreach ($sources as $i => $source) {
                if (is_numeric($source)) {
                    $this->logDebugMessage("ODCM DEBUG - Source $i value: $source (type: " . gettype($source) . ")");
                } else {
                    $this->logDebugMessage("ODCM DEBUG - Source $i: " . (is_null($source) ? "NULL" : "non-numeric"));
                }
            }
        }
        
        foreach ($sources as $i => $source) {
            if (is_numeric($source) && (int)$source > 0) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    $this->logDebugMessage("ODCM DEBUG - RuleRenderer found order ID {$source} from source index {$i}");
                }
                return (int)$source;
            }
        }
        
        // Enhanced warning when no order ID is found
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("ODCM DEBUG - RuleRenderer CRITICAL: NO ORDER ID FOUND in payload! This will cause 'Order #0' issues.", 'warning');
            $this->logDebugMessage("ODCM DEBUG - RuleRenderer event_type: " . ($payload['event_type'] ?? 'unknown'), 'warning');

            // Log complete payload for debugging if it's not too large
            $payload_json = json_encode($payload, JSON_PRETTY_PRINT);
            if (strlen($payload_json) < 2000) {
                $this->logDebugMessage("ODCM DEBUG - Payload that failed order_id extraction: " . $payload_json, 'debug');
            } else {
                $this->logDebugMessage("ODCM DEBUG - Payload too large to log: " . strlen($payload_json) . " bytes", 'debug');
            }
        }
        
        return 0;
    }

    /**
     * Extract event context for more descriptive summaries
     *
     * @param array $payload Full payload data
     * @return string|null Event context description
     */
    private function extractEventContext(array $payload): ?string
    {
        // Check for triggering event type in rawData
        $event_type = $payload['rawData']['rule_execution']['trigger_event_context']['triggering_event'] ?? null;
        $gateway = $payload['rawData']['rule_execution']['trigger_event_context']['event_source'] ?? null;
        
        if (!$event_type) {
            // Fallback to top-level event_type
            $data = $payload['data'] ?? $payload;
            $event_type = $data['event_type'] ?? null;
            $gateway = $data['source_gateway'] ?? null;
        }
        
        if (!$event_type) {
            return null;
        }
        
        // Create context-specific descriptions
        switch ($event_type) {
            case 'payment_completed':
            case 'payment_processing':
            case 'payment_pending':
            case 'payment_failed':
                $gateway_name = $gateway ? ucfirst($gateway) : '';
                return trim($gateway_name . ' payment completion');
                
            case 'order_status_changed':
                return 'order status change';
                
            case 'checkout_completed':
            case 'block_checkout_processed':
                return 'checkout completion';
                
            case 'order_created':
                return 'order creation';
                
            default:
                return null;
        }
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

    /**
     * Render Non-Canonical Rule Evaluation
     *
     * Renders debug entries for rule evaluation on non-canonical events.
     * Designed to show detailed debugging information for developers.
     *
     * @param array                    $payload Full payload data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderNonCanonicalRuleEvaluation(array $payload, PayloadComponentUIToolkit $toolkit): string
    {
        // Extract data from the payload
        $rule_name = $payload['rule_name'] ?? 'unnamed rule';
        $event_type = $payload['event_type'] ?? 'unknown';
        $explanation = $payload['explanation'] ?? '';
        $purpose = $payload['purpose'] ?? '';
        $note = $payload['note'] ?? '';
        $debug_context = $payload['debug_context'] ?? [];
        $canonical_event = $payload['canonical_event'] ?? false;
        $timeline_behavior = $payload['timeline_behavior'] ?? '';

        // Build main information section
        $main_info = [
            'Rule Name' => $rule_name,
            'Event Type' => $event_type,
            'Canonical Event' => $canonical_event ? 'Yes' : 'No',
            'Timeline Behavior' => $timeline_behavior,
        ];

        $content = $toolkit->render_key_value_list($main_info, 'Rule Evaluation Debug');

        // Add explanation in expandable section
        if (!empty($explanation)) {
            $content .= $toolkit->render_expandable_section('Explanation', '<p>' . esc_html($explanation) . '</p>');
        }

        // Add purpose in expandable section
        if (!empty($purpose)) {
            $content .= $toolkit->render_expandable_section('Purpose', '<p>' . esc_html($purpose) . '</p>');
        }

        // Add note in expandable section
        if (!empty($note)) {
            $content .= $toolkit->render_expandable_section('Note', '<p>' . esc_html($note) . '</p>');
        }

        // Add debug context in expandable section
        if (!empty($debug_context)) {
            $debug_info = [];
            foreach ($debug_context as $key => $value) {
                if (is_scalar($value)) {
                    $debug_info[ucfirst(str_replace('_', ' ', $key))] = $value;
                }
            }

            if (!empty($debug_info)) {
                $debug_content = $toolkit->render_key_value_list($debug_info, 'Debug Context Details');
                $content .= $toolkit->render_expandable_section('Debug Context', $debug_content);
            }
        }

        // Add raw payload in expandable section for advanced debugging
        $raw_payload_json = json_encode($payload, JSON_PRETTY_PRINT);
        $raw_payload_content = $toolkit->render_code_block($raw_payload_json, 'json');
        $content .= $toolkit->render_expandable_section('Raw Payload Data', $raw_payload_content);

        return $content;
    }
}
