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
                return 'Metrics: ' . ($data['name'] ?? 'Performance Data');

            case 'admin_action':
                return 'Admin Action: ' . ($data['action'] ?? 'Performed');

            case 'process_started':
                return 'Process Started: ' . ($data['process_type'] ?? 'System Process');

            case 'process_event':
                return 'Process Event: ' . ($data['event'] ?? 'System Event');

            case 'lifecycle_event':
                return 'Lifecycle: ' . ($data['stage'] ?? 'Event');

            case 'custom_event':
                return $data['label'] ?? 'Custom Event';

            case 'action_scheduled':
                return 'Action Scheduled: ' . ($data['hook'] ?? 'System Action');

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
    protected function getTheme(string $event_type): string
    {
        return 'system';
    }

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
     * Render Metrics
     *
     * Renders performance metrics with proper formatting.
     *
     * @param array                    $data    The metrics data
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance
     * @return string HTML content
     */
    private function renderMetrics(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $metric_data = [
            'Metric' => $data['name'] ?? 'Unnamed Metric',
            'Value' => $this->formatMetricValue($data['value'] ?? 0, $data['unit'] ?? ''),
        ];

        // Add any additional context
        if (!empty($data['context']) && is_array($data['context'])) {
            foreach ($data['context'] as $key => $value) {
                if (is_scalar($value)) {
                    $metric_data[ucfirst($key)] = (string)$value;
                }
            }
        }

        return $toolkit->render_key_value_list($metric_data, 'Performance Metric');
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
        $process_data = [
            'Process Type' => $data['process_type'] ?? '',
            'Status' => $data['status'] ?? '',
            'Event' => $data['event'] ?? '',
        ];

        $content = $toolkit->render_key_value_list($process_data, 'Process Details');

        // Add process data in expandable section if available
        if (!empty($data['process_data'])) {
            $process_json = json_encode($data['process_data'], JSON_PRETTY_PRINT);
            $code_block = $toolkit->render_code_block($process_json, 'json');
            $content .= $toolkit->render_expandable_section('Process Data', $code_block);
        }

        return $content;
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