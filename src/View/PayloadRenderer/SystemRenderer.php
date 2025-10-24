<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * System Renderer
 *
 * Handles rendering of all system-related events:
 * - info / warning / error
 * - metrics
 * - admin_action
 * - process_started / process_event
 * - lifecycle_event
 * - custom_event
 * - action_scheduled
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */
class SystemRenderer extends BaseRenderer
{
    /**
     * Constructor
     *
     * Sets the system-specific theme.
     */
    public function __construct()
    {
        parent::__construct();
        $this->theme = 'system';
    }

    /**
     * Render Content
     *
     * Uses switch/case to delegate to specific rendering methods based on event type.
     *
     * @param array  $data       The payload data to render
     * @param string $event_type The type of event being rendered
     * @return string HTML content
     */
    /**
     * Render Specific Content
     *
     * Implements the template method to provide system-specific rendering logic.
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
            case 'info':
            case 'warning':
            case 'error':
                return $this->renderMessage($data, $toolkit, $event_type);

            case 'metrics':
                return $this->renderMetrics($data, $toolkit);

            case 'admin_action':
                return $this->renderAdminAction($data, $toolkit);

            case 'process_started':
            case 'process_event':
                return $this->renderProcess($data, $toolkit);

            case 'lifecycle_event':
                return $this->renderLifecycle($data, $toolkit);

            case 'custom_event':
                return $this->renderCustomEvent($data, $toolkit);

            case 'action_scheduled':
                return $this->renderScheduledAction($data, $toolkit);

            default:
                return $this->renderGenericSystem($data, $toolkit);
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
            case 'info':
            case 'warning':
            case 'error':
                return ucfirst($event_type) . ': ' . ($data['message'] ?? 'System Event');

            case 'metrics':
                return 'Performance: ' . ($data['name'] ?? 'System Metrics');

            case 'admin_action':
                $action = isset($data['action']) ? ucfirst(str_replace('_', ' ', $data['action'])) : 'Performed';
                return 'Admin: ' . $action;

            case 'process_started':
                return $this->getBusinessFriendlyProcessType($data['process_type'] ?? 'System Process') . ' Started';

            case 'process_event':
                // Make process events more user-friendly
                if (!empty($data['summary'])) {
                    return $data['summary'];
                }
                if (!empty($data['event'])) {
                    return ucfirst(str_replace('_', ' ', $data['event']));
                }
                return 'Process: ' . $this->getBusinessFriendlyProcessType($data['process_type'] ?? 'System Process');

            case 'lifecycle_event':
                $stage = isset($data['stage']) ? ucfirst(str_replace('_', ' ', $data['stage'])) : 'Event';
                return 'Lifecycle: ' . $stage;

            case 'custom_event':
                return $data['label'] ?? 'Custom Event';

            case 'action_scheduled':
                $hook = isset($data['hook']) ? ucwords(str_replace('_', ' ', $data['hook'])) : 'Action';
                return 'Scheduled: ' . $hook;

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
        
        switch ($event_type) {
            case 'info':
                return ['label' => 'INFO', 'type' => 'info'];

            case 'warning':
                return ['label' => 'WARNING', 'type' => 'warning'];

            case 'error':
                return ['label' => 'ERROR', 'type' => 'error'];

            case 'metrics':
                return ['label' => 'METRICS', 'type' => 'info'];

            case 'admin_action':
                return ['label' => 'ADMIN', 'type' => 'notice'];

            case 'process_started':
                return ['label' => 'STARTED', 'type' => 'info'];

            case 'process_event':
                return ['label' => 'PROCESS', 'type' => 'info'];

            case 'lifecycle_event':
                return ['label' => 'LIFECYCLE', 'type' => 'info'];

            case 'custom_event':
                return ['label' => 'CUSTOM', 'type' => 'info'];

            case 'action_scheduled':
                return ['label' => 'SCHEDULED', 'type' => 'pending'];

            default:
                return null;
        }
    }

    /**
     * Get Theme
     *
     * All system events use the 'system' theme for consistent styling.
     *
     * @param string $event_type The type of event
     * @return string Theme identifier
     */
    /**
     * Render Message
     *
     * Renders info/warning/error messages with context.
     *
     * @param array                    $data       The message data
     * @param PayloadComponentUIToolkit $toolkit    UI toolkit instance
     * @param string                   $event_type The specific event type
     * @return string HTML content
     */
    private function renderMessage(array $data, PayloadComponentUIToolkit $toolkit, string $event_type): string
    {
        // First render the message as a text block
        $content = '';
        if (isset($data['message'])) {
            $content .= $toolkit->render_text_block($data['message']);
        }

        // Extract any additional context data
        $context_data = array_filter($data, function($key) {
            return !in_array($key, ['message', 'level', 'timestamp']);
        }, ARRAY_FILTER_USE_KEY);

        // Add context data if available
        if (!empty($context_data)) {
            $content .= $toolkit->render_key_value_list($context_data, 'Additional Context');
        }

        return $content;
    }

   
    /**
     * Render System Metrics
     *
     * Renders performance metrics with proper formatting. By default, system
     * metrics are considered technical details, so add them to the debug_data.
     *
     * @param array                    $metrics  The metrics data to render
     * @param PayloadComponentUIToolkit $toolkit  UI toolkit instance
     * @return string HTML content
     */
    private function renderMetrics(array $metrics, PayloadComponentUIToolkit $toolkit): string
    {
        $metric_data = [
            'Metric' => $metrics['name'] ?? 'Unnamed Metric',
            'Value' => $this->formatMetricValue($metrics['value'] ?? 0, $metrics['unit'] ?? ''),
        ];

        // Add any additional business-relevant context
        if (!empty($metrics['context']) && is_array($metrics['context'])) {
            foreach ($metrics['context'] as $key => $value) {
                if (is_scalar($value)) {
                    $metric_data[ucfirst($key)] = (string)$value;
                }
            }
        }
        
        // Add technical details to the debug_data array
        $metrics['technical_details'] = [
            'raw_value' => $metrics['value'] ?? 0,
            'unit' => $metrics['unit'] ?? '',
            'collection_method' => $metrics['collection_method'] ?? 'direct',
            'timestamp_ms' => $metrics['timestamp_ms'] ?? time() * 1000,
        ];
        
        // Render the basic metrics information
        return $toolkit->render_key_value_list($metric_data, 'System Metrics');
    }

    /**
     * Render Admin Action
     *
     * Renders administrative action details.
     *
     * @param array                    $data    The action data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderAdminAction(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $action_data = [
            'Action' => $data['action'] ?? 'Unknown Action',
            'User' => isset($data['user_id']) ? $this->getUserName($data['user_id']) : 'Unknown User',
        ];

        // Add target if available
        if (!empty($data['target'])) {
            $action_data['Target'] = $data['target'];
        }

        return $toolkit->render_key_value_list($action_data, 'Administrative Action');
    }

    /**
     * Render Process
     *
     * Renders process event details.
     *
     * @param array                    $data    The process data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderProcess(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        // Business-relevant process information
        $process_data = [
            'Process Type' => $this->getBusinessFriendlyProcessType($data['process_type'] ?? ''),
            'Status' => $data['status'] ?? '',
            'Summary' => $data['summary'] ?? $data['event'] ?? '',
        ];
        
        // Add order ID if available (business-relevant)
        if (!empty($data['order_id'])) {
            $process_data['Order'] = '#' . $data['order_id'];
        }

        $content = $toolkit->render_key_value_list($process_data, 'Process Information');

        // Move technical details to debug section
        $data['technical_details'] = [
            'raw_process_type' => $data['process_type'] ?? '',
            'component_count' => $data['component_count'] ?? '',
            'correlation_id' => $data['correlation_id'] ?? '',
            'source' => $data['source'] ?? '',
        ];
        
        // Add process data to the debug section if available
        if (!empty($data['process_data'])) {
            $data['technical_details']['process_data'] = $data['process_data'];
        }

        return $content;
    }
    
    /**
     * Get business-friendly process type name
     * 
     * Converts technical process type names to user-friendly terms
     *
     * @param string $process_type Technical process type
     * @return string Business-friendly process name
     */
    private function getBusinessFriendlyProcessType(string $process_type): string
    {
        $mapping = [
            'rule_execution' => 'Rule Processing',
            'order_processing' => 'Order Processing',
            'payment_processing' => 'Payment Processing',
            'checkout_completion' => 'Checkout Completion',
            'status_change_processing' => 'Status Change',
            'webhook_processing' => 'Webhook Processing',
            'admin_action' => 'Admin Action',
        ];
        
        return $mapping[$process_type] ?? $process_type;
    }

    /**
     * Render Lifecycle
     *
     * Renders lifecycle event details.
     *
     * @param array                    $data    The lifecycle data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderLifecycle(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $lifecycle_data = [
            'Stage' => $data['stage'] ?? '',
            'Status' => $data['status'] ?? '',
            'Component' => $data['component'] ?? '',
        ];

        $content = $toolkit->render_key_value_list($lifecycle_data, 'Lifecycle Details');

        // Add lifecycle data in expandable section if available
        if (!empty($data['lifecycle_data'])) {
            $lifecycle_json = json_encode($data['lifecycle_data'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($lifecycle_json, 'json');
            $content .= $toolkit->render_expandable_section('Lifecycle Data', $code_block);
        }

        return $content;
    }

    /**
     * Render Custom Event
     *
     * Renders custom event details.
     *
     * @param array                    $data    The custom event data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderCustomEvent(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $event_data = [
            'Label' => $data['label'] ?? 'Custom Event',
            'Type' => $data['type'] ?? '',
            'Source' => $data['source'] ?? '',
        ];

        $content = $toolkit->render_key_value_list($event_data, 'Event Details');

        // Add event data in expandable section if available
        if (!empty($data['event_data'])) {
            $event_json = json_encode($data['event_data'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($event_json, 'json');
            $content .= $toolkit->render_expandable_section('Event Data', $code_block);
        }

        return $content;
    }

    /**
     * Render Scheduled Action
     *
     * Renders scheduled action details.
     *
     * @param array                    $data    The action data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderScheduledAction(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $action_data = [
            'Hook' => $data['hook'] ?? '',
            'Schedule' => $data['schedule'] ?? '',
            'Next Run' => isset($data['next_run']) ? date('Y-m-d H:i:s', $data['next_run']) : '',
        ];

        $content = $toolkit->render_key_value_list($action_data, 'Scheduled Action');

        // Add arguments in expandable section if available
        if (!empty($data['args'])) {
            $args_json = json_encode($data['args'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($args_json, 'json');
            $content .= $toolkit->render_expandable_section('Arguments', $code_block);
        }

        return $content;
    }

    /**
     * Render Generic System
     *
     * Fallback renderer for unrecognized system events.
     *
     * @param array                    $data    The system data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderGenericSystem(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        return $toolkit->render_code_block(
            json_encode($data, JSON_PRETTY_PRINT),
            'json'
        );
    }
}
