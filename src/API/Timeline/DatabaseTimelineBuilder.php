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

        // Check if this is a process group entry and build appropriate timeline
        // For individual view mode, always build individual timeline regardless of process group status
        if ('flat' === $request->viewMode) {
            return $this->buildIndividualTimeline($logEntry, $request->includeDebug);
        } elseif ($this->shouldRenderAsProcessGroup($logEntry, $request->viewMode)) {
            return $this->buildProcessGroupTimeline($logEntry, $request->includeDebug);
        } else {
            return $this->buildIndividualTimeline($logEntry, $request->includeDebug);
        }
    }

    /**
     * Get event type for a specific log ID
     */
    private function getEventTypeForLog(int $logId): string
    {
        $logEntry = $this->fetchLogEntry($logId);
        if (!$logEntry) {
            return 'unknown';
        }
        return $logEntry['event_type'] ?? 'unknown';
    }

    /**
     * Check if event is an order completion event
     */
    private function isOrderCompletionEvent(string $eventType): bool
    {
        return strpos($eventType, 'order_') !== false ||
               strpos($eventType, 'checkout') !== false ||
               strpos($eventType, 'complete') !== false ||
               strpos($eventType, 'completion') !== false;
    }

    /**
     * Check if this log ID should be rendered as a process group
     */
    private function isProcessGroup(int $logId, string $viewMode): bool
    {
        $logEntry = $this->fetchLogEntry($logId);
        if (!$logEntry) {
            return false;
        }

        return $this->shouldRenderAsProcessGroup($logEntry, $viewMode);
    }

    /**
     * Determine if log entry should be rendered as process group
     *
     * @param array $logEntry The log entry to check
     * @param string $viewMode The current view mode ('flat' or 'consolidated')
     * @return bool True if should render as process group, false otherwise
     */
    private function shouldRenderAsProcessGroup(array $logEntry, string $viewMode): bool
    {
        // In flat view mode, never render as process group regardless of process_id
        if ('flat' === $viewMode) {
            return false;
        }

        // In consolidated view mode, only render as process group if process_id exists
        $shouldGroup = !empty($logEntry['process_id']);

        return $shouldGroup;
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

        // Cache miss - perform database query using WordPress database abstraction
        global $wpdb;

        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Validate table names
        if (!DatabaseHelper::validate_table_name($log_table) || !DatabaseHelper::validate_table_name($payload_table)) {
            throw new \Exception("Invalid table names in DatabaseTimelineBuilder::fetchLogEntry");
        }

        // Use DatabaseHelper for secure database operations
        $result = DatabaseHelper::get_row(
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
            FROM `{$log_table}` l
                LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id
            WHERE l.log_id = %d",
            ['', $logId],
            'ARRAY_A'
        );

        // Check for database errors
        if ($wpdb->last_error) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                if (function_exists('odcm_log_message')) {
                    odcm_log_message("ODCM DatabaseTimelineBuilder: SQL Error in fetchLogEntry for log_id {$logId}: " . $wpdb->last_error, 'error');
                }
            }
            // Don't cache errors, allow retry
            return null;
        }

        if (!$result) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                if (function_exists('odcm_log_message')) {
                    odcm_log_message("ODCM DatabaseTimelineBuilder: No log entry found for log_id {$logId}", 'debug');
                }
            }
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

        // Cache miss - perform database query using WordPress database abstraction
        global $wpdb;

        // Extra safety check to prevent querying with empty process ids
        if (empty($processId)) {
            return [];
        }

        $log_table = $wpdb->prefix . 'odcm_audit_log';
        $payload_table = $wpdb->prefix . 'odcm_audit_log_payloads';

        // Validate table names
        if (!DatabaseHelper::validate_table_name($log_table) || !DatabaseHelper::validate_table_name($payload_table)) {
            throw new \Exception("Invalid table names in DatabaseTimelineBuilder::fetchProcessLogEntries");
        }

        // Use DatabaseHelper for secure database operations
        $results = DatabaseHelper::get_results(
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
            FROM `{$log_table}` l
                LEFT JOIN `{$payload_table}` p ON l.payload_id = p.payload_id
            WHERE l.process_id = %s
            ORDER BY l.timestamp ASC",
            ['', $processId],
            'ARRAY_A'
        );

        // Check for database errors
        if ($wpdb->last_error) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                if (function_exists('odcm_log_message')) {
                    odcm_log_message("ODCM DatabaseTimelineBuilder: SQL Error in fetchProcessLogEntries for process_id {$processId}: " . $wpdb->last_error, 'error');
                }
            }
            // Don't cache errors, return empty array
            return [];
        }

        $results = $results ?: [];

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            if (function_exists('odcm_log_message')) {
                odcm_log_message("ODCM DatabaseTimelineBuilder: Found " . count($results) . " log entries for process_id {$processId}", 'debug');
            }
        }

        // Process entries may receive updates during active processes
        // Use shorter cache duration than individual log entries
        $cache_duration = 60; // 1 minute - short enough for active processes

        // Adjust cache duration based on process status - longer for "finished" processes
        $has_finished_status = false;
        foreach ($results as $entry) {
            if (isset($entry['status']) && in_array($entry['status'], ['success', 'error', 'complete', 'cancelled', 'failed'], true)) {
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
     * Build individual log entry timeline
     */
    private function buildIndividualTimeline(array $logEntry, bool $includeDebug): TimelineData
    {
        // CRITICAL FIX: Extract components ONLY from the specific log entry, not from the entire process
        // This ensures that in individual view, each log entry shows only its own components
        $components = $this->extractComponentsFromLogEntry($logEntry, $includeDebug);

        // For order events with no components, create a synthetic component to ensure something renders
        if (empty($components) && $this->isOrderCompletionEvent($logEntry['event_type'] ?? '')) {
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
        }

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
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) sanitize_text_field(wp_unslash($_SERVER['REQUEST_TIME_FLOAT'])) : microtime(true)),
        ];

        return TimelineData::individual((int) $logEntry['log_id'], $components, $metadata);
    }

    /**
     * Build process group timeline from all entries with same process_id
     */
    private function buildProcessGroupTimeline(array $representativeLogEntry, bool $includeDebug): TimelineData
    {
        // DEFENSIVE FIX: Ensure representative log entry has valid log_id
        // The issue is that consolidation may create representative entries without proper log_id
        $logId = $this->extractValidLogId($representativeLogEntry);
        if ($logId <= 0) {
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

            // For order events with no components, add a synthetic component
            if (empty($entryComponents) && $this->isOrderCompletionEvent($logEntry['event_type'] ?? '')) {
                $allComponents[] = $this->extractor->createSyntheticComponent($logEntry);
            }
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
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) sanitize_text_field(wp_unslash($_SERVER['REQUEST_TIME_FLOAT'])) : microtime(true)),
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

        // Handle empty payload
        if (empty($payloadRaw)) {
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
            $componentsCache[$cacheKey] = $components;
            return $components;
        }

        // Parse payload JSON with detailed error handling
        $payloadData = json_decode($payloadRaw, true);
        if (!is_array($payloadData)) {
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
            $componentsCache[$cacheKey] = $components;
            return $components;
        }

        // Add top-level payload fields to pass extra context to component extractor
        if (!isset($payloadData['event_type']) && isset($logEntry['event_type'])) {
            $payloadData['event_type'] = $logEntry['event_type'];
        }
        if (!isset($payloadData['order_id']) && isset($logEntry['order_id'])) {
            $payloadData['order_id'] = $logEntry['order_id'];
        }
        if (!isset($payloadData['log_id']) && isset($logEntry['log_id'])) {
            $payloadData['log_id'] = $logEntry['log_id'];
        }

        // Extract components using the component extractor, passing log entry context for hierarchy data
        $components = $this->extractor->extractComponents($payloadData, $includeDebug, $logEntry);

        // If no components extracted from payload, create synthetic component
        if (empty($components)) {
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
        }

        // Cache the extracted components for this request
        $componentsCache[$cacheKey] = $components;

        return $components;
    }
}
