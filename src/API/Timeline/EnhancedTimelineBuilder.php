<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Enhanced Timeline Builder
 *
 * This builder implements the new timeline system with dual relationships and display adapters.
 * It builds TimelineData with TimelineEvent objects that support both process_id and parent-child relationships.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
class EnhancedTimelineBuilder implements TimelineBuilderInterface
{
    /**
     * @var AdapterRegistry
     */
    private AdapterRegistry $adapterRegistry;

    /**
     * Constructor
     */
    public function __construct(?AdapterRegistry $adapterRegistry = null)
    {
        $this->adapterRegistry = $adapterRegistry ?? AdapterRegistry::createDefaultRegistry();
    }

    /**
     * Build timeline data from a log entry request
     */
    public function buildTimeline(TimelineRequest $request): TimelineData
    {
        // Fetch the log entry for this request
        $logEntry = $this->fetchLogEntry($request->logId);

        if (!$logEntry) {
            // Return empty timeline data if log entry not found
            $metadata = [
                'type' => 'individual',
                'log_id' => $request->logId,
                'error' => 'Log entry not found',
            ];
            return TimelineData::individual($request->logId, [], $metadata);
        }

        // Check if this should be rendered as a process group
        if ($request->viewMode === 'consolidated' && !empty($logEntry['process_id'])) {
            return $this->buildProcessGroupTimeline($logEntry, $request->includeDebug);
        } else {
            return $this->buildIndividualTimeline($logEntry, $request->includeDebug);
        }
    }

    /**
     * Build individual timeline
     */
    private function buildIndividualTimeline(array $logEntry, bool $includeDebug): TimelineData
    {
        // Create TimelineEvent from log entry
        $timelineEvent = TimelineEvent::fromLegacyLogEntry($logEntry);

        // Extract display data using adapter
        $adapter = $this->adapterRegistry->getAdapterForEvent($timelineEvent->event_type, $timelineEvent->raw_payload);
        $displayData = $adapter->extractDisplayData($timelineEvent->raw_payload);

        // Update TimelineEvent with display data
        $this->updateTimelineEventWithDisplayData($timelineEvent, $displayData);

        // Convert TimelineEvent to component format for backward compatibility
        $component = $this->convertTimelineEventToComponent($timelineEvent, $includeDebug);

        // If the component is null (filtered out as debug-only), return empty timeline
        if ($component === null) {
            $metadata = [
                'type' => 'individual',
                'log_id' => (int) $logEntry['log_id'],
                'timestamp' => $logEntry['timestamp'],
                'order_id' => !empty($logEntry['order_id']) ? (int) $logEntry['order_id'] : null,
                'event_type' => $logEntry['event_type'] ?? null,
                'source' => $logEntry['source'] ?? null,
                'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) sanitize_text_field(wp_unslash($_SERVER['REQUEST_TIME_FLOAT'])) : microtime(true)),
                'filtered_out' => 'Event filtered out as debug-only in non-debug mode',
            ];
            return TimelineData::individual((int) $logEntry['log_id'], [], $metadata);
        }

        $metadata = [
            'type' => 'individual',
            'log_id' => (int) $logEntry['log_id'],
            'timestamp' => $logEntry['timestamp'],
            'order_id' => !empty($logEntry['order_id']) ? (int) $logEntry['order_id'] : null,
            'event_type' => $logEntry['event_type'] ?? null,
            'source' => $logEntry['source'] ?? null,
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) sanitize_text_field(wp_unslash($_SERVER['REQUEST_TIME_FLOAT'])) : microtime(true)),
        ];

        return TimelineData::individual((int) $logEntry['log_id'], [$component], $metadata);
    }

    /**
     * Build process group timeline
     */
    private function buildProcessGroupTimeline(array $representativeLogEntry, bool $includeDebug): TimelineData
    {
        $processId = $representativeLogEntry['process_id'];
        $processLogEntries = $this->fetchProcessLogEntries($processId);

        $timelineEvents = [];
        $components = [];

        // Create TimelineEvents for all entries in the process
        foreach ($processLogEntries as $logEntry) {
            $timelineEvent = TimelineEvent::fromLegacyLogEntry($logEntry);

            // Extract display data using adapter
            $adapter = $this->adapterRegistry->getAdapterForEvent($timelineEvent->event_type, $timelineEvent->raw_payload);
            $displayData = $adapter->extractDisplayData($timelineEvent->raw_payload);

            // Update TimelineEvent with display data
            $this->updateTimelineEventWithDisplayData($timelineEvent, $displayData);

            $timelineEvents[] = $timelineEvent;
        }

        // Establish parent-child relationships
        $this->establishParentChildRelationships($timelineEvents);

        // Convert TimelineEvents to components
        foreach ($timelineEvents as $timelineEvent) {
            $component = $this->convertTimelineEventToComponent($timelineEvent, $includeDebug);
            if ($component !== null) {
                $components[] = $component;
            }
        }

        // Sort components chronologically
        usort($components, function($a, $b) {
            $ts_a = $a['ts'] ?? 0;
            $ts_b = $b['ts'] ?? 0;

            // Convert to float timestamps for microsecond precision comparison
            if (is_float($ts_a) || (is_numeric($ts_a) && strpos((string)$ts_a, '.') !== false)) {
                $time_a = (float)$ts_a;
            } elseif (is_numeric($ts_a)) {
                $time_a = (float)$ts_a;
            } else {
                $time_a = (float)strtotime($ts_a);
            }

            if (is_float($ts_b) || (is_numeric($ts_b) && strpos((string)$ts_b, '.') !== false)) {
                $time_b = (float)$ts_b;
            } elseif (is_numeric($ts_b)) {
                $time_b = (float)$ts_b;
            } else {
                $time_b = (float)strtotime($ts_b);
            }

            return $time_a <=> $time_b;
        });

        // Select the highest priority event for the consolidated summary
        $highestPriorityEvent = $this->selectHighestPriorityEventForConsolidation($timelineEvents);

        // Use the highest priority event's log entry for metadata if available
        $representativeLogEntryForMetadata = $representativeLogEntry;
        if ($highestPriorityEvent !== null) {
            // Find the corresponding log entry for the highest priority event
            foreach ($processLogEntries as $logEntry) {
                if ((int)$logEntry['log_id'] === $highestPriorityEvent->id) {
                    $representativeLogEntryForMetadata = $logEntry;
                    break;
                }
            }
        }

        $filteredCount = count($processLogEntries) - count($components);
        $metadata = [
            'type' => 'process_group',
            'process_id' => $processId,
            'representative_log_id' => (int) $representativeLogEntryForMetadata['log_id'],
            'total_events' => count($processLogEntries),
            'visible_events' => count($components),
            'filtered_events' => $filteredCount > 0 ? $filteredCount : null,
            'order_id' => !empty($representativeLogEntryForMetadata['order_id']) ? (int) $representativeLogEntryForMetadata['order_id'] : null,
            'start_timestamp' => !empty($processLogEntries) ? $processLogEntries[0]['timestamp'] : null,
            'end_timestamp' => !empty($processLogEntries) ? $processLogEntries[count($processLogEntries) - 1]['timestamp'] : null,
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) sanitize_text_field(wp_unslash($_SERVER['REQUEST_TIME_FLOAT'])) : microtime(true)),
            'priority_selection' => [
                'highest_priority_event_type' => $highestPriorityEvent ? $highestPriorityEvent->event_type : null,
                'selection_reason' => $highestPriorityEvent ? $this->getPrioritySelectionReason($highestPriorityEvent, $timelineEvents) : 'no_events_available'
            ]
        ];

        return TimelineData::processGroup((int) $representativeLogEntryForMetadata['log_id'], $components, $metadata);
    }

    /**
     * Update TimelineEvent with display data from adapter
     */
    private function updateTimelineEventWithDisplayData(TimelineEvent $timelineEvent, array $displayData): void
    {
        // Set basic display properties
        if (!empty($displayData['display_sections'])) {
            foreach ($displayData['display_sections'] as $key => $section) {
                $timelineEvent->addDisplaySection($key, $section['label'], $section['value']);
            }
        }

        // Set label and summary
        $timelineEvent->label = $displayData['display_sections']['event_type']['value'] ?? $timelineEvent->event_type;
        $timelineEvent->summary = $this->generateSummary($timelineEvent);

        // Add detail sections
        if (!empty($displayData['detail_sections'])) {
            foreach ($displayData['detail_sections'] as $key => $section) {
                $timelineEvent->addDetailSection($key, $section['label'], $section['data']);
            }
        }

        // Add technical data
        if (!empty($displayData['tech_data'])) {
            foreach ($displayData['tech_data'] as $key => $value) {
                $timelineEvent->addTechData($key, $value);
            }
        }
    }

    /**
     * Generate summary for TimelineEvent
     */
    private function generateSummary(TimelineEvent $timelineEvent): string
    {
        if ($timelineEvent->isRuleExecution()) {
            $ruleName = '';
            foreach ($timelineEvent->display_sections as $section) {
                if ($section['label'] === 'Rule Name') {
                    $ruleName = $section['value'];
                    break;
                }
            }

            if (!empty($ruleName)) {
                return "Rule \"$ruleName\" executed";
            }
        }

        return $timelineEvent->event_type;
    }

    /**
     * Establish parent-child relationships between TimelineEvents
     */
    private function establishParentChildRelationships(array &$timelineEvents): void
    {
        // Sort events chronologically first
        usort($timelineEvents, function($a, $b) {
            return strtotime($a->timestamp) <=> strtotime($b->timestamp);
        });

        // For each rule execution event, find its triggering business event
        foreach ($timelineEvents as $event) {
            if ($event->isRuleExecution()) {
                // Find the most recent business event before this rule execution
                $parentEvent = $this->findTriggeringEvent($event, $timelineEvents);
                if ($parentEvent) {
                    $event->parent_id = $parentEvent->id;
                    $parentEvent->addChild($event->id);
                }
            }
        }
    }

    /**
     * Find the triggering business event for a rule execution
     */
    private function findTriggeringEvent(TimelineEvent $ruleEvent, array $allEvents): ?TimelineEvent
    {
        $ruleTimestamp = strtotime($ruleEvent->timestamp);
        $triggeringEvent = null;

        // Look for business events that happened just before this rule execution
        foreach ($allEvents as $event) {
            if ($event->isBusinessEvent() && !$event->isRuleExecution()) {
                $eventTimestamp = strtotime($event->timestamp);

                // Event must be before the rule execution
                if ($eventTimestamp < $ruleTimestamp) {
                    // If we don't have a triggering event yet, or this one is closer in time
                    if (!$triggeringEvent || ($ruleTimestamp - $eventTimestamp) < ($ruleTimestamp - strtotime($triggeringEvent->timestamp))) {
                        $triggeringEvent = $event;
                    }
                }
            }
        }

        return $triggeringEvent;
    }

    /**
     * Convert TimelineEvent to component format for backward compatibility
     */
    private function convertTimelineEventToComponent(TimelineEvent $timelineEvent, bool $includeDebug): array
    {
        // Check if this is a debug-only event that should be filtered out
        if (!$includeDebug && $this->isDebugOnlyEvent($timelineEvent)) {
            return null;
        }

        $component = [
            'event_type' => $timelineEvent->event_type,
            'label' => $timelineEvent->label,
            'summary' => $timelineEvent->summary,
            'ts' => $timelineEvent->timestamp,
            'data' => $timelineEvent->raw_payload,
            'display_sections' => $timelineEvent->display_sections,
            'detail_sections' => $timelineEvent->detail_sections,
            'actions_taken' => $timelineEvent->actions_taken,
            'tech_data' => $includeDebug ? $timelineEvent->tech_data : [],
        ];

        // Add relationship information
        if ($timelineEvent->hasParent()) {
            $component['parent_id'] = $timelineEvent->parent_id;
        }

        if ($timelineEvent->hasChildren()) {
            $component['children'] = $timelineEvent->children;
        }

        return $component;
    }

    /**
     * Check if an event should only be shown in debug mode
     */
    private function isDebugOnlyEvent(TimelineEvent $timelineEvent): bool
    {
        // First check for _universal_event_debug events (always debug-only)
        if ($timelineEvent->event_type === '_universal_event_debug') {
            return true;
        }

        // First check for explicit debug_only flag (highest priority)
        if (!empty($timelineEvent->raw_payload['debug_only']) && $timelineEvent->raw_payload['debug_only'] === true) {
            return true;
        }

        // Check for specific "Rule Processing Started" flag
        if (!empty($timelineEvent->raw_payload['is_rule_processing_started']) && $timelineEvent->raw_payload['is_rule_processing_started'] === true) {
            return true;
        }

        // Check if this is a rule execution event with incomplete data (processing started)
        if ($timelineEvent->event_type === 'rule_execution') {
            $rawPayload = $timelineEvent->raw_payload;

            // Check if this has the full rule execution context
            $hasFullRuleExecutionContext = !empty($rawPayload['rule_execution']) && is_array($rawPayload['rule_execution']);

            // Check for processing metadata that indicates this is a processing event
            $hasProcessingData = !empty($rawPayload['data']['correlation_id']) ||
                               !empty($rawPayload['data']['process_type']) ||
                               !empty($rawPayload['data']['status']);

            // It's a debug-only event if it has processing data but lacks the full rule execution context
            if ($hasProcessingData && !$hasFullRuleExecutionContext) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch single log entry from database
     */
    private function fetchLogEntry(int $logId): ?array
    {
        // Check cache first
        $cache_key = 'odcm_log_entry_' . $logId;
        $cached_result = wp_cache_get($cache_key, 'order_daemon');

        if ($cached_result !== false) {
            return $cached_result;
        }

        global $wpdb;

        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT l.log_id,
                    l.timestamp,
                    l.status,
                    l.summary,
                    l.order_id,
                    l.event_type,
                    l.source,
                    l.payload_id,
                    l.is_test,
                    l.process_id,
                    COALESCE(p.payload, l.details, %s) as payload
                FROM {$wpdb->prefix}odcm_audit_log l
                    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
                WHERE l.log_id = %d",
                '',
                $logId
            ),
            'ARRAY_A'
        );

        $final_result = $result ?: null;

        // Cache the result for 5 minutes (300 seconds)
        wp_cache_set($cache_key, $final_result, 'order_daemon', 300);

        return $final_result;
    }

    /**
     * Select the highest priority event for consolidated summary display
     *
     * @param TimelineEvent[] $timelineEvents Array of timeline events in the process group
     * @return TimelineEvent|null The highest priority event, or null if no events available
     */
    private function selectHighestPriorityEventForConsolidation(array $timelineEvents): ?TimelineEvent
    {
        if (empty($timelineEvents)) {
            return null;
        }

        // Priority 1: Rule execution events
        $ruleExecutionEvents = $this->getEventsByType($timelineEvents, 'rule_execution');
        if (!empty($ruleExecutionEvents)) {
            return $this->getHighestPriorityRuleExecution($ruleExecutionEvents);
        }

        // Priority 2: Rule errors
        $ruleErrorEvents = $this->getRuleErrorEvents($timelineEvents);
        if (!empty($ruleErrorEvents)) {
            return $this->getOldestEvent($ruleErrorEvents);
        }

        // Priority 3: Any other errors (oldest error has highest priority)
        $errorEvents = $this->getErrorEvents($timelineEvents);
        if (!empty($errorEvents)) {
            return $this->getOldestEvent($errorEvents);
        }

        // Priority 4: Most recent order status change
        $statusChangeEvents = $this->getOrderStatusChangeEvents($timelineEvents);
        if (!empty($statusChangeEvents)) {
            return $this->getMostRecentEvent($statusChangeEvents);
        }

        // Priority 5: Payment events
        $paymentEvents = $this->getPaymentEvents($timelineEvents);
        if (!empty($paymentEvents)) {
            return $this->getMostRecentEvent($paymentEvents);
        }

        // Priority 6: Last order event to occur
        $orderEvents = $this->getOrderEvents($timelineEvents);
        if (!empty($orderEvents)) {
            return $this->getMostRecentEvent($orderEvents);
        }

        // Fallback: Return the most recent event of any type
        return $this->getMostRecentEvent($timelineEvents);
    }

    /**
     * Get a human-readable reason for why an event was selected as highest priority
     *
     * @param TimelineEvent $selectedEvent The event that was selected
     * @param TimelineEvent[] $allEvents All events in the process group
     * @return string Human-readable selection reason
     */
    private function getPrioritySelectionReason(TimelineEvent $selectedEvent, array $allEvents): string
    {
        $eventType = $selectedEvent->event_type;
        $status = $selectedEvent->status ?? '';

        // Check if it's a rule execution
        if ($this->isRuleExecutionEvent($selectedEvent)) {
            return 'rule_execution_highest_priority';
        }

        // Check if it's a rule error
        if ($this->isRuleErrorEvent($selectedEvent)) {
            return 'rule_error_highest_priority';
        }

        // Check if it's any error event
        if ($this->isErrorEvent($selectedEvent)) {
            return 'error_event_oldest_priority';
        }

        // Check if it's a status change event
        if ($this->isOrderStatusChangeEvent($selectedEvent)) {
            return 'most_recent_status_change';
        }

        // Check if it's a payment event
        if ($this->isPaymentEvent($selectedEvent)) {
            return 'most_recent_payment_event';
        }

        // Check if it's an order event
        if ($this->isOrderEvent($selectedEvent)) {
            return 'most_recent_order_event';
        }

        return 'fallback_most_recent_event';
    }

    /**
     * Get events by specific type
     *
     * @param TimelineEvent[] $events Array of events
     * @param string $eventType Event type to filter by
     * @return TimelineEvent[] Filtered events
     */
    private function getEventsByType(array $events, string $eventType): array
    {
        return array_filter($events, function($event) use ($eventType) {
            return $event->event_type === $eventType;
        });
    }

    /**
     * Get rule error events
     *
     * @param TimelineEvent[] $events Array of events
     * @return TimelineEvent[] Rule error events
     */
    private function getRuleErrorEvents(array $events): array
    {
        return array_filter($events, function($event) {
            return $this->isRuleErrorEvent($event);
        });
    }

    /**
     * Get error events (excluding rule errors)
     *
     * @param TimelineEvent[] $events Array of events
     * @return TimelineEvent[] Error events
     */
    private function getErrorEvents(array $events): array
    {
        return array_filter($events, function($event) {
            return $this->isErrorEvent($event) && !$this->isRuleErrorEvent($event);
        });
    }

    /**
     * Get order status change events
     *
     * @param TimelineEvent[] $events Array of events
     * @return TimelineEvent[] Status change events
     */
    private function getOrderStatusChangeEvents(array $events): array
    {
        return array_filter($events, function($event) {
            return $this->isOrderStatusChangeEvent($event);
        });
    }

    /**
     * Get payment events
     *
     * @param TimelineEvent[] $events Array of events
     * @return TimelineEvent[] Payment events
     */
    private function getPaymentEvents(array $events): array
    {
        return array_filter($events, function($event) {
            return $this->isPaymentEvent($event);
        });
    }

    /**
     * Get order events (excluding status changes and payments)
     *
     * @param TimelineEvent[] $events Array of events
     * @return TimelineEvent[] Order events
     */
    private function getOrderEvents(array $events): array
    {
        return array_filter($events, function($event) {
            return $this->isOrderEvent($event) &&
                   !$this->isOrderStatusChangeEvent($event) &&
                   !$this->isPaymentEvent($event);
        });
    }

    /**
     * Get the highest priority rule execution event
     *
     * @param TimelineEvent[] $ruleExecutionEvents Array of rule execution events
     * @return TimelineEvent The highest priority rule execution
     */
    private function getHighestPriorityRuleExecution(array $ruleExecutionEvents): TimelineEvent
    {
        // For rule executions, we want the most recent one as it's likely the most important
        return $this->getMostRecentEvent($ruleExecutionEvents);
    }

    /**
     * Get the oldest event from an array
     *
     * @param TimelineEvent[] $events Array of events
     * @return TimelineEvent The oldest event
     */
    private function getOldestEvent(array $events): TimelineEvent
    {
        if (empty($events)) {
            throw new \InvalidArgumentException('Cannot get oldest event from empty array');
        }

        usort($events, function($a, $b) {
            return strtotime($a->timestamp) <=> strtotime($b->timestamp);
        });

        return reset($events);
    }

    /**
     * Get the most recent event from an array
     *
     * @param TimelineEvent[] $events Array of events
     * @return TimelineEvent The most recent event
     */
    private function getMostRecentEvent(array $events): TimelineEvent
    {
        if (empty($events)) {
            throw new \InvalidArgumentException('Cannot get most recent event from empty array');
        }

        usort($events, function($a, $b) {
            return strtotime($b->timestamp) <=> strtotime($a->timestamp);
        });

        return reset($events);
    }

    /**
     * Check if an event is a rule execution event
     *
     * @param TimelineEvent $event The event to check
     * @return bool True if it's a rule execution event
     */
    private function isRuleExecutionEvent(TimelineEvent $event): bool
    {
        return $event->event_type === 'rule_execution' ||
               strpos($event->event_type, 'rule_execution_') === 0;
    }

    /**
     * Check if an event is a rule error event
     *
     * @param TimelineEvent $event The event to check
     * @return bool True if it's a rule error event
     */
    private function isRuleErrorEvent(TimelineEvent $event): bool
    {
        // Check for rule execution events with error status
        if ($this->isRuleExecutionEvent($event)) {
            $status = $event->status ?? '';
            return strtolower($status) === 'error' || strtolower($status) === 'failed';
        }

        // Check for specific rule error event types
        return strpos($event->event_type, 'rule_error_') === 0 ||
               strpos($event->event_type, 'rule_failed_') === 0;
    }

    /**
     * Check if an event is an error event
     *
     * @param TimelineEvent $event The event to check
     * @return bool True if it's an error event
     */
    private function isErrorEvent(TimelineEvent $event): bool
    {
        $status = $event->status ?? '';
        return strtolower($status) === 'error' ||
               strtolower($status) === 'failed' ||
               strpos($event->event_type, 'error_') === 0 ||
               strpos($event->event_type, '_error') !== false;
    }

    /**
     * Check if an event is an order status change event
     *
     * @param TimelineEvent $event The event to check
     * @return bool True if it's an order status change event
     */
    private function isOrderStatusChangeEvent(TimelineEvent $event): bool
    {
        return $event->event_type === 'status_changed' ||
               strpos($event->event_type, 'status_change_') === 0 ||
               strpos($event->event_type, 'order_status_') === 0;
    }

    /**
     * Check if an event is a payment event
     *
     * @param TimelineEvent $event The event to check
     * @return bool True if it's a payment event
     */
    private function isPaymentEvent(TimelineEvent $event): bool
    {
        return strpos($event->event_type, 'payment_') === 0 ||
               strpos($event->event_type, '_payment') !== false ||
               $event->event_type === 'checkout_processed';
    }

    /**
     * Check if an event is an order event
     *
     * @param TimelineEvent $event The event to check
     * @return bool True if it's an order event
     */
    private function isOrderEvent(TimelineEvent $event): bool
    {
        return strpos($event->event_type, 'order_') === 0 ||
               strpos($event->event_type, '_order') !== false ||
               $event->event_type === 'status_changed';
    }

    /**
     * Fetch all log entries for a process ID
     */
    private function fetchProcessLogEntries(string $processId): array
    {
        // Check cache first
        $cache_key = 'odcm_process_entries_' . md5($processId);
        $cached_result = wp_cache_get($cache_key, 'order_daemon');

        if ($cached_result !== false) {
            return $cached_result;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.log_id,
                    l.timestamp,
                    l.status,
                    l.summary,
                    l.order_id,
                    l.event_type,
                    l.source,
                    l.payload_id,
                    l.is_test,
                    l.process_id,
                    COALESCE(p.payload, l.details, %s) as payload
                FROM {$wpdb->prefix}odcm_audit_log l
                    LEFT JOIN {$wpdb->prefix}odcm_audit_log_payloads p ON l.payload_id = p.payload_id
                WHERE l.process_id = %s
                ORDER BY l.timestamp ASC",
                '',
                $processId
            ),
            'ARRAY_A'
        );

        $final_result = $results ?: [];

        // Cache the result for 5 minutes (300 seconds)
        wp_cache_set($cache_key, $final_result, 'order_daemon', 300);

        return $final_result;
    }
}
