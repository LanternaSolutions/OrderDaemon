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
                'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) wp_unslash($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true)),
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
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) wp_unslash($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true)),
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

        $filteredCount = count($processLogEntries) - count($components);
        $metadata = [
            'type' => 'process_group',
            'process_id' => $processId,
            'representative_log_id' => (int) $representativeLogEntry['log_id'],
            'total_events' => count($processLogEntries),
            'visible_events' => count($components),
            'filtered_events' => $filteredCount > 0 ? $filteredCount : null,
            'order_id' => !empty($representativeLogEntry['order_id']) ? (int) $representativeLogEntry['order_id'] : null,
            'start_timestamp' => !empty($processLogEntries) ? $processLogEntries[0]['timestamp'] : null,
            'end_timestamp' => !empty($processLogEntries) ? $processLogEntries[count($processLogEntries) - 1]['timestamp'] : null,
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) wp_unslash($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true)),
        ];

        return TimelineData::processGroup((int) $representativeLogEntry['log_id'], $components, $metadata);
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
        global $wpdb;

        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

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
                FROM $log_table l
                    LEFT JOIN $payload_table p ON l.payload_id = p.payload_id
                WHERE l.log_id = %d",
                '',
                $logId
            ),
            'ARRAY_A'
        );

        return $result ?: null;
    }

    /**
     * Fetch all log entries for a process ID
     */
    private function fetchProcessLogEntries(string $processId): array
    {
        global $wpdb;

        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

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
                FROM $log_table l
                    LEFT JOIN $payload_table p ON l.payload_id = p.payload_id
                WHERE l.process_id = %s
                ORDER BY l.timestamp ASC",
                '',
                $processId
            ),
            'ARRAY_A'
        );

        return $results ?: [];
    }
}
