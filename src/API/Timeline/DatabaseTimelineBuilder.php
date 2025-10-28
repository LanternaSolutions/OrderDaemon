<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Database-driven timeline builder
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
final class DatabaseTimelineBuilder implements TimelineBuilderInterface
{
    public function __construct(
        private ComponentExtractorInterface $extractor
    ) {}
    
    /**
     * Build timeline data from a log entry request
     */
    public function buildTimeline(TimelineRequest $request): TimelineData
    {
        $logEntry = $this->fetchLogEntry($request->logId);
        
        if (!$logEntry) {
            throw new \Exception("Log entry {$request->logId} not found");
        }
        
        // Determine if this should be rendered as a process group or individual entry
        if ($this->shouldRenderAsProcessGroup($logEntry)) {
            return $this->buildProcessGroupTimeline($logEntry, $request->includeDebug);
        }
        
        return $this->buildIndividualTimeline($logEntry, $request->includeDebug);
    }
    
    /**
     * Fetch single log entry from database
     */
    private function fetchLogEntry(int $logId): ?array
    {
        global $wpdb;
        $logTable = $wpdb->prefix . 'odcm_audit_log';
        $payloadTable = $wpdb->prefix . 'odcm_audit_log_payloads';
        
        // Check if payload table exists
        $payloadTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$payloadTable}'");
        
        if ($payloadTableExists) {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                           COALESCE(p.payload, l.details, '') as payload 
                    FROM {$logTable} l 
                    LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id
                    WHERE l.log_id = %d";
        } else {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                           l.details as payload 
                    FROM {$logTable} l
                    WHERE l.log_id = %d";
        }
        
        $result = $wpdb->get_row($wpdb->prepare($sql, $logId), 'ARRAY_A');
        return $result ?: null;
    }
    
    /**
     * Fetch all log entries for a process ID
     */
    private function fetchProcessLogEntries(string $processId): array
    {
        global $wpdb;
        $logTable = $wpdb->prefix . 'odcm_audit_log';
        $payloadTable = $wpdb->prefix . 'odcm_audit_log_payloads';
        
        // Check if payload table exists
        $payloadTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$payloadTable}'");
        
        if ($payloadTableExists) {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                           COALESCE(p.payload, l.details, '') as payload 
                    FROM {$logTable} l 
                    LEFT JOIN {$payloadTable} p ON l.payload_id = p.payload_id
                    WHERE l.process_id = %s 
                    ORDER BY l.timestamp ASC";
        } else {
            $sql = "SELECT l.log_id as id, l.timestamp, l.status, l.summary, l.order_id, 
                           l.event_type, l.source, l.payload_id, l.is_test, l.process_id,
                           l.details as payload 
                    FROM {$logTable} l
                    WHERE l.process_id = %s 
                    ORDER BY l.timestamp ASC";
        }
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $processId), 'ARRAY_A');
        return $results ?: [];
    }
    
    /**
     * Determine if log entry should be rendered as process group
     */
    private function shouldRenderAsProcessGroup(array $logEntry): bool
    {
        return !empty($logEntry['process_id']);
    }
    
    /**
     * Build individual log entry timeline
     */
    private function buildIndividualTimeline(array $logEntry, bool $includeDebug): TimelineData
    {
        $components = $this->extractComponentsFromLogEntry($logEntry, $includeDebug);
        
        // Sort components chronologically (same logic as process group)
        // This ensures proper chronological order even for individual events with multiple components
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
        
        $metadata = [
            'type' => 'individual',
            'log_id' => (int) $logEntry['id'],
            'timestamp' => $logEntry['timestamp'],
            'order_id' => !empty($logEntry['order_id']) ? (int) $logEntry['order_id'] : null,
            'event_type' => $logEntry['event_type'] ?? null,
            'source' => $logEntry['source'] ?? null,
            'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
        ];
        
        return TimelineData::individual((int) $logEntry['id'], $components, $metadata);
    }
    
    /**
     * Build process group timeline from all entries with same process_id
     */
    private function buildProcessGroupTimeline(array $representativeLogEntry, bool $includeDebug): TimelineData
    {
        $processId = $representativeLogEntry['process_id'];
        $processLogEntries = $this->fetchProcessLogEntries($processId);
        
        // Extract components from all process entries
        $allComponents = [];
        foreach ($processLogEntries as $logEntry) {
            $entryComponents = $this->extractComponentsFromLogEntry($logEntry, $includeDebug);
            $allComponents = array_merge($allComponents, $entryComponents);
        }
        
        // Sort components chronologically (handle microsecond timestamps, Unix timestamps, and datetime strings)
        usort($allComponents, function($a, $b) {
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
        
        $metadata = [
            'type' => 'process_group',
            'process_id' => $processId,
            'representative_log_id' => (int) $representativeLogEntry['id'],
            'total_events' => count($processLogEntries),
            'order_id' => !empty($representativeLogEntry['order_id']) ? (int) $representativeLogEntry['order_id'] : null,
            'start_timestamp' => !empty($processLogEntries) ? $processLogEntries[0]['timestamp'] : null,
            'end_timestamp' => !empty($processLogEntries) ? $processLogEntries[count($processLogEntries) - 1]['timestamp'] : null,
            'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
        ];
        
        return TimelineData::processGroup((int) $representativeLogEntry['id'], $allComponents, $metadata);
    }
    
    /**
     * Extract components from a single log entry
     */
    private function extractComponentsFromLogEntry(array $logEntry, bool $includeDebug): array
    {
        $payloadRaw = $logEntry['payload'] ?? '';
        
        // Handle empty payload
        if (empty($payloadRaw)) {
            return [$this->extractor->createSyntheticComponent($logEntry)];
        }
        
        // Parse payload JSON
        $payloadData = json_decode($payloadRaw, true);
        if (!is_array($payloadData)) {
            return [$this->extractor->createSyntheticComponent($logEntry)];
        }
        
        // Extract components using the extractor
        $components = $this->extractor->extractComponents($payloadData, $includeDebug);
        
        // If no components extracted from payload, create synthetic component
        if (empty($components)) {
            return [$this->extractor->createSyntheticComponent($logEntry)];
        }
        
        return $components;
    }
}
