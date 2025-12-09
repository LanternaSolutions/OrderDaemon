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
    /**
     * In-memory cache for log entries to prevent duplicate database queries
     *
     * @var array<int, array>
     */
    private static array $logEntryCache = [];
    
    /**
     * In-memory cache for process entries to prevent duplicate database queries
     *
     * @var array<string, array>
     */
    private static array $processEntriesCache = [];
    
    public function __construct(
        private ComponentExtractorInterface $extractor
    ) {}
    
    /**
     * Build timeline data from a log entry request
     */
    public function buildTimeline(TimelineRequest $request): TimelineData
    {
        // Debug the incoming request
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: buildTimeline called with log_id: ' . $request->logId);
        }
        
        $logEntry = $this->fetchLogEntry($request->logId);
        
        if (!$logEntry) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM: Log entry ' . $request->logId . ' not found in database');
            }
            throw new \Exception("Log entry " . esc_html($request->logId) . " not found");
        }
        
        // Determine if this should be rendered as a process group or individual entry
        if ($this->shouldRenderAsProcessGroup($logEntry)) {
            return $this->buildProcessGroupTimeline($logEntry, $request->includeDebug);
        }
        
        return $this->buildIndividualTimeline($logEntry, $request->includeDebug);
    }
    
    /**
     * Fetch single log entry from database with multi-level caching
     * 
     * Implements both in-memory caching for the current request and 
     * persistent caching using WordPress cache API. Individual log
     * entries don't change often, so caching improves performance.
     */
    private function fetchLogEntry(int $logId): ?array
    {
        // Check in-memory cache first (fastest)
        if (isset(self::$logEntryCache[$logId])) {
            return self::$logEntryCache[$logId];
        }
        
        // Create a cache key for WordPress persistent cache
        $cache_key = 'odcm_timeline_log_' . $logId;
        
        // Check persistent cache
        $cached_entry = wp_cache_get($cache_key);
        if (false !== $cached_entry) {
            // Store in static cache for future use in this request
            self::$logEntryCache[$logId] = $cached_entry;
            return $cached_entry;
        }
        
        // Cache miss - perform database query
        global $wpdb;
        // Secure table identifiers with proper escaping
        $logTableName = $wpdb->prefix . 'odcm_audit_log';
        $payloadTableName = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Secure the table names by escaping them
        $logTableEscaped = '`' . esc_sql($logTableName) . '`';
        $payloadTableEscaped = '`' . esc_sql($payloadTableName) . '`';

        // Construct the query with properly escaped table identifiers
        $query = $wpdb->prepare(
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
            FROM " . $logTableEscaped . " l
                LEFT JOIN " . $payloadTableEscaped . " p ON l.payload_id = p.payload_id
            WHERE l.log_id = %d",
            '',
            $logId
        );

        $result = $wpdb->get_row($query, 'ARRAY_A');
        
        // Debug what we got from the database
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: fetchLogEntry(' . $logId . ') query: ' . $query);
            error_log('ODCM: fetchLogEntry(' . $logId . ') result: ' . var_export($result, true));
            if ($result) {
                error_log('ODCM: fetchLogEntry(' . $logId . ') result keys: ' . implode(', ', array_keys($result)));
                error_log('ODCM: fetchLogEntry(' . $logId . ') result log_id: ' . var_export($result['log_id'] ?? 'NOT SET', true));
            }
        }
        
        if (!$result) {
            // Even null results are worth caching to prevent repeated queries for non-existent entries
            // But use a shorter cache time
            wp_cache_set($cache_key, null, '', MINUTE_IN_SECONDS * 5); // 5 minutes
            self::$logEntryCache[$logId] = null;
            return null;
        }
        
        // Cache the result - log entries don't change often once created
        wp_cache_set($cache_key, $result, '', HOUR_IN_SECONDS); // 1 hour
        
        // Store in static cache for future use in this request
        self::$logEntryCache[$logId] = $result;

        return $result;
    }
    
    /**
     * Fetch all log entries for a process ID with multi-level caching
     * 
     * Implements both in-memory caching for the current request and 
     * persistent caching using WordPress cache API. Process entries
     * may be updated while active, so use shorter cache duration.
     */
    private function fetchProcessLogEntries(string $processId): array
    {
        // Check in-memory cache first (fastest)
        if (isset(self::$processEntriesCache[$processId])) {
            return self::$processEntriesCache[$processId];
        }
        
        // Create a cache key for WordPress persistent cache
        $cache_key = 'odcm_timeline_process_' . md5($processId);
        
        // Check persistent cache
        $cached_entries = wp_cache_get($cache_key);
        if (false !== $cached_entries) {
            // Store in static cache for future use in this request
            self::$processEntriesCache[$processId] = $cached_entries;
            return $cached_entries;
        }
        
        // Cache miss - perform database query
        global $wpdb;
        
        // Extra safety check to prevent querying with empty process ids
        if (empty($processId)) {
            return [];
        }
        
        // Secure table identifiers with proper escaping
        $logTableName = $wpdb->prefix . 'odcm_audit_log';
        $payloadTableName = $wpdb->prefix . 'odcm_audit_log_payloads';
        
        // Secure the table names by escaping them
        $logTableEscaped = '`' . esc_sql($logTableName) . '`';
        $payloadTableEscaped = '`' . esc_sql($payloadTableName) . '`';

        $query = $wpdb->prepare(
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
            FROM " . $logTableEscaped . " l
                LEFT JOIN " . $payloadTableEscaped . " p ON l.payload_id = p.payload_id
            WHERE l.process_id = %s
            ORDER BY l.timestamp ASC",
            '',
            $processId
        );

        $results = $wpdb->get_results($query, 'ARRAY_A');
        $results = $results ?: [];
        
        // Process entries may receive updates during active processes
        // Use shorter cache duration than individual log entries
        $cache_duration = 60; // 1 minute - short enough for active processes
        
        // Adjust cache duration based on process status - longer for "finished" processes
        $has_finished_status = false;
        foreach ($results as $entry) {
            if (isset($entry['status']) && in_array($entry['status'], ['success', 'error', 'complete', 'cancelled', 'failed'])) {
                $has_finished_status = true;
                break;
            }
        }
        
        // For completed processes, use longer cache duration
        if ($has_finished_status) {
            $cache_duration = HOUR_IN_SECONDS; // 1 hour for completed processes
        }
        
        // Cache the results
        wp_cache_set($cache_key, $results, '', $cache_duration);
        
        // Store in static cache for future use in this request
        self::$processEntriesCache[$processId] = $results;

        return $results;
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
            'log_id' => (int) $logEntry['log_id'],
            'timestamp' => $logEntry['timestamp'],
            'order_id' => !empty($logEntry['order_id']) ? (int) $logEntry['order_id'] : null,
            'event_type' => $logEntry['event_type'] ?? null,
            'source' => $logEntry['source'] ?? null,
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) wp_unslash($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true)),
        ];
        
        return TimelineData::individual((int) $logEntry['log_id'], $components, $metadata);
    }
    
    /**
     * Build process group timeline from all entries with same process_id
     */
    private function buildProcessGroupTimeline(array $representativeLogEntry, bool $includeDebug): TimelineData
    {
        // Debug log entry structure
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: Representative log entry keys: ' . implode(', ', array_keys($representativeLogEntry)));
            error_log('ODCM: Representative log entry log_id value: ' . var_export($representativeLogEntry['log_id'] ?? 'NOT SET', true));
        }
        
        // DEFENSIVE FIX: Ensure representative log entry has valid log_id
        // The issue is that consolidation may create representative entries without proper log_id
        $logId = $this->extractValidLogId($representativeLogEntry);
        if ($logId <= 0) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM: Invalid log_id in representative entry, fetching fresh data');
            }
            throw new \Exception("Invalid representative log entry - log_id must be positive integer");
        }
        
        // Ensure the representative entry has the correct log_id for TimelineData creation
        $representativeLogEntry['log_id'] = $logId;
        
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
            'representative_log_id' => (int) $representativeLogEntry['log_id'],
            'total_events' => count($processLogEntries),
            'order_id' => !empty($representativeLogEntry['order_id']) ? (int) $representativeLogEntry['order_id'] : null,
            'start_timestamp' => !empty($processLogEntries) ? $processLogEntries[0]['timestamp'] : null,
            'end_timestamp' => !empty($processLogEntries) ? $processLogEntries[count($processLogEntries) - 1]['timestamp'] : null,
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) wp_unslash($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true)),
        ];
        
        return TimelineData::processGroup((int) $representativeLogEntry['log_id'], $allComponents, $metadata);
    }
    
    /**
     * Extract valid log_id from representative log entry with multiple fallback strategies
     * 
     * This defensive method handles cases where consolidation or API formatting 
     * may create representative log entries with missing or malformed log_id fields.
     */
    private function extractValidLogId(array $representativeLogEntry): int
    {
        // Strategy 1: Direct log_id field (most common)
        if (isset($representativeLogEntry['log_id']) && is_numeric($representativeLogEntry['log_id'])) {
            $logId = (int) $representativeLogEntry['log_id'];
            if ($logId > 0) {
                return $logId;
            }
        }
        
        // Strategy 2: Check for 'id' field (dashboard API format)
        if (isset($representativeLogEntry['id']) && is_numeric($representativeLogEntry['id'])) {
            $logId = (int) $representativeLogEntry['id'];
            if ($logId > 0) {
                return $logId;
            }
        }
        
        // Strategy 3: Check for 'original_id' field (deduplication format)
        if (isset($representativeLogEntry['original_id']) && is_numeric($representativeLogEntry['original_id'])) {
            $logId = (int) $representativeLogEntry['original_id'];
            if ($logId > 0) {
                return $logId;
            }
        }
        
        // Strategy 4: If we have process_id, try to fetch the latest log entry for that process
        if (!empty($representativeLogEntry['process_id'])) {
            $processLogEntries = $this->fetchProcessLogEntries($representativeLogEntry['process_id']);
            if (!empty($processLogEntries)) {
                // Use the most recent log entry's ID
                $latestEntry = end($processLogEntries);
                if (isset($latestEntry['log_id']) && is_numeric($latestEntry['log_id'])) {
                    $logId = (int) $latestEntry['log_id'];
                    if ($logId > 0) {
                        return $logId;
                    }
                }
            }
        }
        
        // All strategies failed - return 0 to trigger error handling
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: extractValidLogId failed for entry: ' . var_export($representativeLogEntry, true));
        }
        
        return 0;
    }
    
    /**
     * Extract components from a single log entry
     */
    private function extractComponentsFromLogEntry(array $logEntry, bool $includeDebug): array
    {
        static $componentsCache = [];
        
        // Create cache key based on log entry ID and debug flag
        $logId = $logEntry['log_id'] ?? 0;
        $cacheKey = $logId . '_' . ($includeDebug ? '1' : '0');
        
        // Check if we already extracted these components
        if (isset($componentsCache[$cacheKey])) {
            return $componentsCache[$cacheKey];
        }
        
        $payloadRaw = $logEntry['payload'] ?? '';
        
        // Log debugging information if enabled
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: extractComponentsFromLogEntry for log_id ' . $logId . ' with payload of length: ' . strlen($payloadRaw));
            
            if (empty($payloadRaw)) {
                error_log('ODCM: Empty payload for log_id ' . $logId);
            } else {
                error_log('ODCM: Payload for log_id ' . $logId . ' starts with: ' . substr($payloadRaw, 0, 50) . '...');
            }
        }
        
        // Handle empty payload
        if (empty($payloadRaw)) {
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
            $componentsCache[$cacheKey] = $components;
            return $components;
        }
        
        // Parse payload JSON
        $payloadData = json_decode($payloadRaw, true);
        if (!is_array($payloadData)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM: Failed to decode JSON payload for log_id ' . $logId . ': ' . json_last_error_msg());
            }
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
            $componentsCache[$cacheKey] = $components;
            return $components;
        }
        
        // Extract components using the extractor
        $components = $this->extractor->extractComponents($payloadData, $includeDebug);
        
        // If no components extracted from payload, create synthetic component
        if (empty($components)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM: No components extracted from payload for log_id ' . $logId . ', creating synthetic component');
            }
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
            $componentsCache[$cacheKey] = $components;
            return $components;
        }
        
        // Cache the extracted components for this request
        $componentsCache[$cacheKey] = $components;
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM: Successfully extracted ' . count($components) . ' components from payload for log_id ' . $logId);
        }
        
        return $components;
    }
}
