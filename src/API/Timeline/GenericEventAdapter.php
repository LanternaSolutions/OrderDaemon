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
        $fields = [];

        // Extract event type for processing
        $eventType = $payload['event_type'] ?? $payload['data']['event_type'] ?? 'unknown_event';

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

        return $fields;
    }
    
    /**
     * Format generic event description
     *
     * @since 1.2.0
     *
     * @param string $eventType The event type
     * @return string Formatted event description
     */
    private function formatGenericEventDescription(string $eventType): string
    {
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
}
