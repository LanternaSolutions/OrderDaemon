<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Generic Event Display Adapter
 *
 * Fallback adapter for unknown event types that provides basic data extraction
 * and formatting. Serves as a catch-all for events that don't have specialized
 * adapters, ensuring graceful degradation and consistent display.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
class GenericEventAdapter extends DisplayAdapter
{
    /**
     * Extract specialized fields for generic/unknown events
     *
     * This method provides basic field extraction that works for any event type,
     * focusing on common patterns and graceful handling of unknown data structures,
     * showing minimal essential info and relying on technical section for complete details.
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return array Extracted specialized fields
     */
    protected function extractSpecializedFields(array &$payload): array
    {
        try {
            $fields = [];

            // Extract event type for processing
            $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'unknown_event';

            // Special handling for rule_no_match events
            if ($eventType === 'rule_no_match') {
                return $this->extractRuleNoMatchFields($payload);
            }

            // Enhanced handling for specific event categories
            if (strpos($eventType, 'webhook_') !== false || $eventType === 'universal_event_processing') {
                $this->addWebhookFields($fields, $payload);
            }
            elseif (strpos($eventType, 'email_') !== false || strpos($eventType, 'custom_email') !== false) {
                $this->addEmailFields($fields, $payload);
            }
            elseif (strpos($eventType, 'config_') !== false) {
                $this->addSystemOperationFields($fields, $payload);
            }
            elseif (in_array($eventType, ['info', 'warning', 'error', 'admin_action'])) {
                $this->addSystemEventFields($fields, $payload);
            }
            elseif ($eventType === 'metrics') {
                $this->addMetricsFields($fields, $payload);
            }
            else {
                // Event description - format the event type nicely
                $fields['event_description'] = [
                    'label' => $this->translate('Event'),
                    'value' => $this->formatGenericEventDescription($eventType),
                    'section' => 'primary'
                ];

                // Order ID - use enhanced extraction from base class
                $order_id = $this->extractOrderId($payload);
                if ($order_id > 0) {
                    $fields['order_id'] = [
                        'label' => $this->translate('Order'),
                        'value' => '#' . $order_id,
                        'section' => 'primary'
                    ];
                }

                // Extract status if available
                $status = $this->extractGenericStatus($payload);
                if ($status) {
                    $fields['status'] = [
                        'label' => $this->translate('Status'),
                        'value' => ucfirst($status),
                        'section' => 'primary'
                    ];
                }

                // Extract any action or result information
                $action = $this->extractGenericAction($payload);
                if ($action) {
                    $fields['action'] = [
                        'label' => $this->translate('Action'),
                        'value' => $action,
                        'section' => 'primary'
                    ];
                }

                // Extract any message or description
                $message = $this->extractGenericMessage($payload);
                if ($message) {
                    $fields['message'] = [
                        'label' => $this->translate('Message'),
                        'value' => $message,
                        'section' => 'primary'
                    ];
                }

                // Add common generic fields - only essential business information
                $this->addCommonGenericFields($fields, $payload);
            }

            return $fields;
        } catch (\Throwable $e) {
            // Log the error but don't throw
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM: GenericEventAdapter error: ' . $e->getMessage());
            }

            // Return minimal valid data - ensures rendering can continue
            return [
                'event_description' => [
                    'label' => $this->translate('Event'),
                    'value' => ucwords(str_replace('_', ' ', $payload['event_type'] ?? 'Unknown Event')),
                    'section' => 'primary'
                ]
            ];
        }
    }
    
    /**
     * Format generic event description
     *
     * @since 1.2.0
     *
     * @param string $eventType The event type
     * @param array $payload The event payload
     * @return string Formatted event description
     */
    private function formatGenericEventDescription(string $eventType, array $payload): string
    {
        // Use the debug event title generation for universal_event_processing_debug events
        if ($eventType === 'universal_event_processing_debug') {
            return DisplayAdapter::generateDebugEventTitle($payload);
        }

        // Handle common patterns
        if ($eventType === 'unknown_event') {
            return $this->translate('System Event');
        }
        
        // Handle system/debug events
        if (strpos($eventType, 'system_') === 0) {
            $action = str_replace('system_', '', $eventType);
            return sprintf($this->translate('System %s'), ucfirst(str_replace('_', ' ', $action)));
        }
        
        // Handle process events
        if (strpos($eventType, 'process_') === 0) {
            $action = str_replace('process_', '', $eventType);
            return sprintf($this->translate('Process %s'), ucfirst(str_replace('_', ' ', $action)));
        }
        
        // Handle data events
        if (strpos($eventType, 'data_') === 0) {
            $action = str_replace('data_', '', $eventType);
            return sprintf($this->translate('Data %s'), ucfirst(str_replace('_', ' ', $action)));
        }
        
        // Handle user events
        if (strpos($eventType, 'user_') === 0) {
            $action = str_replace('user_', '', $eventType);
            return sprintf($this->translate('User %s'), ucfirst(str_replace('_', ' ', $action)));
        }
        
        // Handle admin events
        if (strpos($eventType, 'admin_') === 0) {
            $action = str_replace('admin_', '', $eventType);
            return sprintf($this->translate('Admin %s'), ucfirst(str_replace('_', ' ', $action)));
        }
        
        // Generic formatting - convert underscores to spaces and capitalize
        return ucwords(str_replace('_', ' ', $eventType));
    }
    
    /**
     * Extract generic status from various payload locations
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return string|null The status value or null
     */
    private function extractGenericStatus(array $payload): ?string
    {
        // Common status field locations
        $statusSources = [
            $payload['status'] ?? null,
            $payload['state'] ?? null,
            $payload['result'] ?? null,
            $payload['outcome'] ?? null,
            $payload['data']['status'] ?? null,
            $payload['data']['state'] ?? null,
            $payload['data']['result'] ?? null,
            $payload['event_data']['status'] ?? null,
        ];
        
        foreach ($statusSources as $status) {
            if (!empty($status) && is_string($status)) {
                return $status;
            }
        }
        
        return null;
    }
    
    /**
     * Extract generic action information
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return string|null The action description or null
     */
    private function extractGenericAction(array $payload): ?string
    {
        // Common action field locations
        $actionSources = [
            $payload['action'] ?? null,
            $payload['operation'] ?? null,
            $payload['task'] ?? null,
            $payload['activity'] ?? null,
            $payload['data']['action'] ?? null,
            $payload['data']['operation'] ?? null,
            $payload['event_data']['action'] ?? null,
        ];
        
        foreach ($actionSources as $action) {
            if (!empty($action) && is_string($action)) {
                return ucfirst(str_replace('_', ' ', $action));
            }
        }
        
        return null;
    }
    
    /**
     * Extract generic message or description
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return string|null The message or null
     */
    private function extractGenericMessage(array $payload): ?string
    {
        // Common message field locations
        $messageSources = [
            $payload['message'] ?? null,
            $payload['description'] ?? null,
            $payload['summary'] ?? null,
            $payload['details'] ?? null,
            $payload['data']['message'] ?? null,
            $payload['data']['description'] ?? null,
            $payload['event_data']['message'] ?? null,
            $payload['event_data']['description'] ?? null,
        ];
        
        foreach ($messageSources as $message) {
            if (!empty($message) && is_string($message)) {
                // Truncate very long messages
                if (strlen($message) > 200) {
                    $message = substr($message, 0, 197) . '...';
                }
                return $message;
            }
        }
        
        return null;
    }
    
    /**
     * Add webhook-specific fields for webhook events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addWebhookFields(array &$fields, array $payload): void
    {
        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->translate('Webhook Event'),
            'section' => 'primary'
        ];

        // Extract webhook source/integration name
        $source = $payload['source'] ?? 
                 $payload['integration'] ?? 
                 $payload['webhook_source'] ?? 
                 $payload['data']['source'] ?? 
                 $payload['data']['integration'] ?? null;

        if ($source) {
            $fields['webhook_source'] = [
                'label' => $this->translate('Webhook Source'),
                'value' => ucfirst($source),
                'section' => 'primary'
            ];
        }

        // Extract payload type
        $payloadType = $payload['payload_type'] ?? 
                      $payload['event_type'] ?? 
                      $payload['data']['payload_type'] ?? 
                      $payload['data']['event_type'] ?? null;

        if ($payloadType) {
            $fields['payload_type'] = [
                'label' => $this->translate('Payload Type'),
                'value' => ucfirst(str_replace('_', ' ', $payloadType)),
                'section' => 'primary'
            ];
        }

        // Extract processing status
        $status = $payload['status'] ?? 
                 $payload['processing_status'] ?? 
                 $payload['result'] ?? 
                 $payload['data']['status'] ?? 
                 $payload['data']['processing_status'] ?? null;

        if ($status) {
            $fields['processing_status'] = [
                'label' => $this->translate('Processing Status'),
                'value' => ucfirst($status),
                'section' => 'primary'
            ];
        }

        // Extract response time if available
        $responseTime = $payload['response_time'] ?? 
                       $payload['elapsed_time'] ?? 
                       $payload['duration'] ?? 
                       $payload['data']['response_time'] ?? null;

        if ($responseTime !== null && is_numeric($responseTime)) {
            $fields['response_time'] = [
                'label' => $this->translate('Response Time'),
                'value' => $this->formatDuration($responseTime),
                'section' => 'event_details'
            ];
        }

        // Extract webhook URL/endpoint
        $endpoint = $payload['endpoint'] ?? 
                   $payload['url'] ?? 
                   $payload['webhook_url'] ?? 
                   $payload['data']['endpoint'] ?? null;

        if ($endpoint) {
            $fields['webhook_endpoint'] = [
                'label' => $this->translate('Webhook Endpoint'),
                'value' => $endpoint,
                'section' => 'event_details'
            ];
        }

        // Extract error details for failed webhooks
        $error = $payload['error'] ?? 
                $payload['error_message'] ?? 
                $payload['data']['error'] ?? null;

        if ($error) {
            $fields['error'] = [
                'label' => $this->translate('Error'),
                'value' => is_string($error) ? $error : $this->translate('Error occurred'),
                'section' => 'event_details'
            ];
        }
    }

    /**
     * Add email-specific fields for email events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addEmailFields(array &$fields, array $payload): void
    {
        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->translate('Email Event'),
            'section' => 'primary'
        ];

        // Extract recipient email addresses
        $recipients = $payload['recipients'] ?? 
                     $payload['to'] ?? 
                     $payload['email_to'] ?? 
                     $payload['data']['recipients'] ?? 
                     $payload['data']['to'] ?? null;

        if ($recipients) {
            $fields['recipients'] = [
                'label' => $this->translate('Recipients'),
                'value' => is_array($recipients) ? implode(', ', $recipients) : $recipients,
                'section' => 'primary'
            ];
        }

        // Extract email subject
        $subject = $payload['subject'] ?? 
                  $payload['email_subject'] ?? 
                  $payload['data']['subject'] ?? 
                  $payload['data']['email_subject'] ?? null;

        if ($subject) {
            $fields['email_subject'] = [
                'label' => $this->translate('Subject'),
                'value' => $subject,
                'section' => 'primary'
            ];
        }

        // Extract email type
        $emailType = $payload['email_type'] ?? 
                    $payload['type'] ?? 
                    $payload['data']['email_type'] ?? 
                    $payload['data']['type'] ?? null;

        if ($emailType) {
            $fields['email_type'] = [
                'label' => $this->translate('Email Type'),
                'value' => ucfirst(str_replace('_', ' ', $emailType)),
                'section' => 'primary'
            ];
        }

        // Extract delivery status
        $status = $payload['status'] ?? 
                 $payload['delivery_status'] ?? 
                 $payload['result'] ?? 
                 $payload['data']['status'] ?? 
                 $payload['data']['delivery_status'] ?? null;

        if ($status) {
            $fields['delivery_status'] = [
                'label' => $this->translate('Delivery Status'),
                'value' => ucfirst($status),
                'section' => 'primary'
            ];
        }

        // Extract send timestamp
        $timestamp = $payload['timestamp'] ?? 
                    $payload['sent_at'] ?? 
                    $payload['data']['timestamp'] ?? 
                    $payload['data']['sent_at'] ?? null;

        if ($timestamp) {
            $fields['sent_timestamp'] = [
                'label' => $this->translate('Sent At'),
                'value' => is_numeric($timestamp) ? date('Y-m-d H:i:s', $timestamp) : $timestamp,
                'section' => 'event_details'
            ];
        }

        // Extract error details for failed emails
        $error = $payload['error'] ?? 
                $payload['error_message'] ?? 
                $payload['data']['error'] ?? null;

        if ($error) {
            $fields['error'] = [
                'label' => $this->translate('Error'),
                'value' => is_string($error) ? $error : $this->translate('Error occurred'),
                'section' => 'event_details'
            ];
        }
    }

    /**
     * Add system operation fields for config events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addSystemOperationFields(array &$fields, array $payload): void
    {
        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->translate('System Operation'),
            'section' => 'primary'
        ];

        // Extract operation type
        $operationType = $payload['operation_type'] ?? 
                        $payload['action'] ?? 
                        $payload['data']['operation_type'] ?? 
                        $payload['data']['action'] ?? null;

        if ($operationType) {
            $fields['operation_type'] = [
                'label' => $this->translate('Operation Type'),
                'value' => ucfirst(str_replace('_', ' ', $operationType)),
                'section' => 'primary'
            ];
        }

        // Extract user/actor information
        $actor = $this->extractActor($payload);
        if ($actor) {
            $fields['performed_by'] = [
                'label' => $this->translate('Performed By'),
                'value' => $actor,
                'section' => 'primary'
            ];
        }

        // Extract operation status
        $status = $payload['status'] ?? 
                 $payload['operation_status'] ?? 
                 $payload['result'] ?? 
                 $payload['data']['status'] ?? 
                 $payload['data']['operation_status'] ?? null;

        if ($status) {
            $fields['operation_status'] = [
                'label' => $this->translate('Operation Status'),
                'value' => ucfirst($status),
                'section' => 'primary'
            ];
        }

        // Extract items processed count
        $count = $payload['items_processed'] ?? 
                $payload['count'] ?? 
                $payload['total_items'] ?? 
                $payload['data']['items_processed'] ?? null;

        if ($count !== null && is_numeric($count)) {
            $fields['items_processed'] = [
                'label' => $this->translate('Items Processed'),
                'value' => (string)$count,
                'section' => 'event_details'
            ];
        }

        // Extract operation timestamp
        $timestamp = $payload['timestamp'] ?? 
                    $payload['operation_timestamp'] ?? 
                    $payload['data']['timestamp'] ?? 
                    $payload['data']['operation_timestamp'] ?? null;

        if ($timestamp) {
            $fields['operation_timestamp'] = [
                'label' => $this->translate('Operation Timestamp'),
                'value' => is_numeric($timestamp) ? date('Y-m-d H:i:s', $timestamp) : $timestamp,
                'section' => 'event_details'
            ];
        }

        // Extract error details if applicable
        $error = $payload['error'] ?? 
                $payload['error_message'] ?? 
                $payload['data']['error'] ?? null;

        if ($error) {
            $fields['error'] = [
                'label' => $this->translate('Error'),
                'value' => is_string($error) ? $error : $this->translate('Error occurred'),
                'section' => 'event_details'
            ];
        }
    }

    /**
     * Add system event fields for info/warning/error/admin_action events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addSystemEventFields(array &$fields, array $payload): void
    {
        // Extract event type for specific handling
        $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'system_event';

        // Event description based on type
        $eventDescriptions = [
            'info' => $this->translate('System Information'),
            'warning' => $this->translate('System Warning'),
            'error' => $this->translate('System Error'),
            'admin_action' => $this->translate('Admin Action'),
        ];

        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $eventDescriptions[$eventType] ?? $this->translate('System Event'),
            'section' => 'primary'
        ];

        // Extract severity/priority
        $severity = $payload['severity'] ?? 
                   $payload['priority'] ?? 
                   $payload['level'] ?? 
                   $payload['data']['severity'] ?? 
                   $payload['data']['priority'] ?? null;

        if ($severity) {
            $fields['severity'] = [
                'label' => $this->translate('Severity'),
                'value' => ucfirst($severity),
                'section' => 'primary'
            ];
        }

        // Extract operation details
        $operationDetails = $payload['operation_details'] ?? 
                           $payload['details'] ?? 
                           $payload['message'] ?? 
                           $payload['data']['operation_details'] ?? 
                           $payload['data']['details'] ?? null;

        if ($operationDetails) {
            $fields['operation_details'] = [
                'label' => $this->translate('Operation Details'),
                'value' => $operationDetails,
                'section' => 'primary'
            ];
        }

        // Extract user/actor information
        $actor = $this->extractActor($payload);
        if ($actor) {
            $fields['performed_by'] = [
                'label' => $this->translate('Performed By'),
                'value' => $actor,
                'section' => 'primary'
            ];
        }

        // Extract timestamp
        $timestamp = $payload['timestamp'] ?? 
                    $payload['data']['timestamp'] ?? null;

        if ($timestamp) {
            $fields['event_timestamp'] = [
                'label' => $this->translate('Timestamp'),
                'value' => is_numeric($timestamp) ? date('Y-m-d H:i:s', $timestamp) : $timestamp,
                'section' => 'event_details'
            ];
        }

        // Extract error details if applicable
        $error = $payload['error'] ?? 
                $payload['error_message'] ?? 
                $payload['data']['error'] ?? null;

        if ($error) {
            $fields['error'] = [
                'label' => $this->translate('Error'),
                'value' => is_string($error) ? $error : $this->translate('Error occurred'),
                'section' => 'event_details'
            ];
        }
    }

    /**
     * Add metrics fields for debug-only metrics events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addMetricsFields(array &$fields, array $payload): void
    {
        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->translate('Performance Metrics'),
            'section' => 'primary'
        ];

        // Extract metric name
        $metricName = $payload['metric_name'] ?? 
                     $payload['name'] ?? 
                     $payload['data']['metric_name'] ?? 
                     $payload['data']['name'] ?? null;

        if ($metricName) {
            $fields['metric_name'] = [
                'label' => $this->translate('Metric Name'),
                'value' => ucfirst(str_replace('_', ' ', $metricName)),
                'section' => 'primary'
            ];
        }

        // Extract formatted value with unit
        $value = $payload['value'] ?? 
                $payload['metric_value'] ?? 
                $payload['data']['value'] ?? 
                $payload['data']['metric_value'] ?? null;

        $unit = $payload['unit'] ?? 
               $payload['data']['unit'] ?? 'ms';

        if ($value !== null) {
            $fields['metric_value'] = [
                'label' => $this->translate('Value'),
                'value' => $value . ' ' . $unit,
                'section' => 'primary'
            ];
        }

        // Extract collection context
        $context = $payload['collection_context'] ?? 
                  $payload['context'] ?? 
                  $payload['data']['collection_context'] ?? 
                  $payload['data']['context'] ?? null;

        if ($context) {
            $fields['collection_context'] = [
                'label' => $this->translate('Collection Context'),
                'value' => $context,
                'section' => 'event_details'
            ];
        }

        // Technical details in expandable section
        $technicalDetails = $payload['technical_details'] ?? 
                           $payload['data']['technical_details'] ?? null;

        if ($technicalDetails) {
            $fields['technical_details'] = [
                'label' => $this->translate('Technical Details'),
                'value' => is_array($technicalDetails) ? json_encode($technicalDetails) : $technicalDetails,
                'section' => 'event_details'
            ];
        }
    }

    /**
     * Add common generic fields that might be useful across different event types
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addCommonGenericFields(array &$fields, array $payload): void
    {
        // Source information
        $source = $payload['source'] ?? 
                 $payload['origin'] ?? 
                 $payload['data']['source'] ?? 
                 $payload['event_source'] ?? null;

        if ($source) {
            $fields['source'] = [
                'label' => $this->translate('Source'),
                'value' => ucfirst($source),
                'section' => 'event_details'
            ];
        }

        // User or actor information
        $actor = $this->extractActor($payload);
        if ($actor) {
            $fields['actor'] = [
                'label' => $this->translate('Actor'),
                'value' => $actor,
                'section' => 'event_details'
            ];
        }

        // Target or object information
        $target = $this->extractTarget($payload);
        if ($target) {
            $fields['target'] = [
                'label' => $this->translate('Target'),
                'value' => $target,
                'section' => 'event_details'
            ];
        }

        // Duration if it's a timed event
        $duration = $payload['duration'] ?? 
                   $payload['elapsed_time'] ?? 
                   $payload['execution_time'] ?? 
                   $payload['data']['duration'] ?? null;

        if ($duration !== null && is_numeric($duration)) {
            $fields['duration'] = [
                'label' => $this->translate('Duration'),
                'value' => $this->formatDuration($duration),
                'section' => 'event_details'
            ];
        }

        // Error information if present
        $error = $payload['error'] ?? 
                $payload['error_message'] ?? 
                $payload['data']['error'] ?? null;

        if ($error) {
            $fields['error'] = [
                'label' => $this->translate('Error'),
                'value' => is_string($error) ? $error : $this->translate('Error occurred'),
                'section' => 'event_details'
            ];
        }

        // Count or quantity information
        $count = $payload['count'] ?? 
                $payload['quantity'] ?? 
                $payload['total'] ?? 
                $payload['data']['count'] ?? null;

        if ($count !== null && is_numeric($count)) {
            $fields['count'] = [
                'label' => $this->translate('Count'),
                'value' => (string)$count,
                'section' => 'event_details'
            ];
        }
    }
    
    /**
     * Extract actor (user, system, etc.) information
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return string|null The actor information or null
     */
    private function extractActor(array $payload): ?string
    {
        $actorSources = [
            $payload['user_id'] ?? null,
            $payload['actor'] ?? null,
            $payload['initiated_by'] ?? null,
            $payload['data']['user_id'] ?? null,
            $payload['data']['actor'] ?? null,
        ];
        
        foreach ($actorSources as $actor) {
            if (!empty($actor)) {
                if (is_numeric($actor)) {
                    // Try to get user info if WordPress functions are available with defensive check
                    if (function_exists('get_userdata')) {
                        try {
                            $user = get_userdata((int)$actor);
                            if ($user) {
                                return $user->display_name;
                            }
                        } catch (\Throwable $e) {
                            // Fallback if WordPress function fails
                            return sprintf($this->translate('User ID: %s'), $actor);
                        }
                    }
                    return sprintf($this->translate('User ID: %s'), $actor);
                } elseif (is_string($actor)) {
                    return $actor;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract target (what the event acted upon) information
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return string|null The target information or null
     */
    private function extractTarget(array $payload): ?string
    {
        $targetSources = [
            $payload['target'] ?? null,
            $payload['object'] ?? null,
            $payload['subject'] ?? null,
            $payload['target_id'] ?? null,
            $payload['object_id'] ?? null,
            $payload['data']['target'] ?? null,
            $payload['data']['object'] ?? null,
        ];
        
        foreach ($targetSources as $target) {
            if (!empty($target) && is_string($target)) {
                return $target;
            } elseif (is_numeric($target)) {
                return sprintf($this->translate('ID: %s'), $target);
            }
        }
        
        return null;
    }
    
    /**
     * Format duration for display
     *
     * @since 1.2.0
     *
     * @param mixed $duration The duration value (usually in seconds)
     * @return string Formatted duration
     */
    private function formatDuration($duration): string
    {
        $seconds = (float)$duration;

        if ($seconds < 1) {
            return sprintf($this->translate('%d ms'), (int)($seconds * 1000));
        } elseif ($seconds < 60) {
            return sprintf($this->translate('%.2f seconds'), $seconds);
        } elseif ($seconds < 3600) {
            $minutes = (int)($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return sprintf($this->translate('%d min %.1f sec'), $minutes, $remainingSeconds);
        } else {
            $hours = (int)($seconds / 3600);
            $remainingMinutes = (int)(($seconds % 3600) / 60);
            return sprintf($this->translate('%d hr %d min'), $hours, $remainingMinutes);
        }
    }

    /**
     * Add status evaluation fields for debug status evaluation events
     *
     * @since 1.2.0
     *
     * @param array &$fields Reference to fields array
     * @param array $payload The event payload
     * @return void
     */
    private function addStatusEvaluationFields(array &$fields, array $payload): void
    {
        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->translate('Status Evaluation'),
            'section' => 'primary'
        ];

        // Extract status change information
        $fromStatus = $payload['from'] ?? $payload['data']['from'] ?? null;
        $toStatus = $payload['to'] ?? $payload['data']['to'] ?? null;

        if ($fromStatus && $toStatus) {
            $fields['status_change'] = [
                'label' => $this->translate('Status Change'),
                'value' => sprintf('%s → %s', ucfirst($fromStatus), ucfirst($toStatus)),
                'section' => 'primary'
            ];
        }

        // Extract order ID if available
        $orderId = $payload['order_id'] ?? $payload['data']['order_id'] ?? null;
        if ($orderId) {
            $fields['order_id'] = [
                'label' => $this->translate('Order'),
                'value' => '#' . $orderId,
                'section' => 'primary'
            ];
        }

        // Extract debug mode flag
        $debugMode = $payload['debug_mode'] ?? $payload['data']['debug_mode'] ?? false;
        if ($debugMode) {
            $fields['debug_mode'] = [
                'label' => $this->translate('Debug Mode'),
                'value' => $this->translate('Enabled'),
                'section' => 'event_details'
            ];
        }

        // Extract evaluation details
        $evaluationDetails = $payload['evaluation_details'] ?? $payload['data']['evaluation_details'] ?? null;
        if ($evaluationDetails && is_array($evaluationDetails)) {
            // Extract timestamp
            if (!empty($evaluationDetails['timestamp'])) {
                $fields['evaluation_timestamp'] = [
                    'label' => $this->translate('Evaluation Timestamp'),
                    'value' => $evaluationDetails['timestamp'],
                    'section' => 'event_details'
                ];
            }

            // Extract source
            if (!empty($evaluationDetails['source'])) {
                $fields['evaluation_source'] = [
                    'label' => $this->translate('Evaluation Source'),
                    'value' => ucfirst($evaluationDetails['source']),
                    'section' => 'event_details'
                ];
            }

            // Extract purpose
            if (!empty($evaluationDetails['purpose'])) {
                $fields['evaluation_purpose'] = [
                    'label' => $this->translate('Purpose'),
                    'value' => $evaluationDetails['purpose'],
                    'section' => 'event_details'
                ];
            }
        }

        // Extract timestamp
        $timestamp = $payload['timestamp'] ?? $payload['ts'] ?? null;
        if ($timestamp) {
            $fields['evaluation_timestamp'] = [
                'label' => $this->translate('Timestamp'),
                'value' => is_numeric($timestamp) ? date('Y-m-d H:i:s', $timestamp) : $timestamp,
                'section' => 'event_details'
            ];
        }
    }

    /**
     * Extract specialized fields for rule_no_match events (defensive)
     *
     * @since 1.2.0
     *
     * @param array $payload The event payload
     * @return array Extracted specialized fields
     */
    private function extractRuleNoMatchFields(array &$payload): array
    {
        $fields = [];

        // Event description
        $fields['event_description'] = [
            'label' => $this->translate('Event'),
            'value' => $this->translate('Rule No Match'),
            'section' => 'primary'
        ];

        // Debug indicator
        $fields['debug_indicator'] = [
            'label' => $this->translate('Type'),
            'value' => $this->translate('Debug Event'),
            'section' => 'primary'
        ];

        // Rule name if available
        if (!empty($payload['rule_name'])) {
            $fields['rule_name'] = [
                'label' => $this->translate('Rule'),
                'value' => $payload['rule_name'],
                'section' => 'primary'
            ];
        }

        // Failed condition
        if (!empty($payload['failed_condition'])) {
            $fields['failed_condition'] = [
                'label' => $this->translate('Failed Condition'),
                'value' => $payload['failed_condition'],
                'section' => 'primary'
            ];
        }

        // Event type that triggered the rule
        if (!empty($payload['event_type'])) {
            $fields['trigger_event'] = [
                'label' => $this->translate('Trigger Event'),
                'value' => $payload['event_type'],
                'section' => 'primary'
            ];
        }

        // Source gateway if available
        if (!empty($payload['source_gateway'])) {
            $fields['source_gateway'] = [
                'label' => $this->translate('Source'),
                'value' => ucfirst($payload['source_gateway']),
                'section' => 'primary'
            ];
        }

        return $fields;
    }
}
