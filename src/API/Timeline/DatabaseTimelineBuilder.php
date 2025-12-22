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
        // Enhanced debugging for order completion events
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - buildTimeline called for log_id: ' . $request->logId);
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Request include_debug: ' . ($request->includeDebug ? 'true' : 'false'));
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Request view_mode: ' . $request->viewMode);
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Request is_process_group: ' . ($this->isProcessGroup($request->logId, $request->viewMode) ? 'true' : 'false'));

            $eventType = $this->getEventTypeForLog($request->logId);
            if ($this->isOrderCompletionEvent($eventType)) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Building timeline for order completion event: ' . $eventType);
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Request details: ' . json_encode([
                    'log_id' => $request->logId,
                    'include_debug' => $request->includeDebug,
                    'view_mode' => $request->viewMode,
                    'is_process_group' => $this->isProcessGroup($request->logId, $request->viewMode)
                ]));
            }
        }

        // Fetch the log entry for this request
        $logEntry = $this->fetchLogEntry($request->logId);

        if (!$logEntry) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - No log entry found for log_id: ' . $request->logId);
            }
            // Return empty timeline data if log entry not found
            $metadata = [
                'type' => 'individual',
                'log_id' => $request->logId,
                'error' => 'Log entry not found',
            ];
            return TimelineData::individual($request->logId, [], $metadata);
        }

        // Special handling for order completion events
        $eventType = $logEntry['event_type'] ?? '';
        if ($this->isOrderCompletionEvent($eventType) && defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Processing order completion event: ' . $eventType);
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Log entry keys: ' . implode(', ', array_keys($logEntry)));

            // Log payload summary
            $payload = $logEntry['payload'] ?? '';
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Payload length: ' . strlen($payload));
            if (!empty($payload)) {
                $payloadSample = substr($payload, 0, 100) . (strlen($payload) > 100 ? '...' : '');
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Payload sample: ' . $payloadSample);

                // Check if payload is valid JSON
                $decodedPayload = json_decode($payload, true);
                if ($decodedPayload === null) {
                    error_log('ODCM DEBUG: DatabaseTimelineBuilder - Payload is not valid JSON: ' . json_last_error_msg());
                } else {
                    error_log('ODCM DEBUG: DatabaseTimelineBuilder - Payload decoded successfully, keys: ' .
                          implode(', ', array_keys($decodedPayload)));
                }
            } else {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Payload is empty');
            }
        }

        // Check if this is a process group entry and build appropriate timeline
        // For individual view mode, always build individual timeline regardless of process group status
        if ($request->viewMode === 'flat') {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Individual view mode forced, building individual timeline for log_id: ' . $request->logId);
            }
            return $this->buildIndividualTimeline($logEntry, $request->includeDebug);
        } elseif ($this->shouldRenderAsProcessGroup($logEntry, $request->viewMode)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Building process group timeline for process_id: ' . 
                      ($logEntry['process_id'] ?? 'undefined'));
            }
            return $this->buildProcessGroupTimeline($logEntry, $request->includeDebug);
        } else {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Building individual timeline for log_id: ' . $request->logId);
            }
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
        if ($viewMode === 'flat') {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Flat view mode, forcing individual rendering for log_id: ' . ($logEntry['log_id'] ?? 'unknown'));
            }
            return false;
        }
        
        // In consolidated view mode, only render as process group if process_id exists
        $shouldGroup = !empty($logEntry['process_id']);
        
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Consolidated view mode, process group decision: ' . ($shouldGroup ? 'YES' : 'NO') . ' for log_id: ' . ($logEntry['log_id'] ?? 'unknown'));
        }
        
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
        // Enhanced debugging for DB query issues
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - fetchLogEntry called for log_id: ' . $logId);
        }

        // Check in-memory cache first (fastest)
        if (isset(self::$logEntryCache[$logId])) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - fetchLogEntry cache hit for log_id: ' . $logId);
            }
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

        // Enhanced debugging for DB query issues
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: fetchLogEntry - Performing database query for log_id: ' . $logId);
        }

        // Use proper table references without interpolation
        // and let $wpdb->prepare handle all escaping
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

        // Debug what we got from the database
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: fetchLogEntry(' . $logId . ') query executed successfully');
            if ($result) {
                error_log('ODCM DEBUG: fetchLogEntry(' . $logId . ') result keys: ' . implode(', ', array_keys($result)));
                
                // Check if we have a payload
                if (isset($result['payload'])) {
                    $payloadLength = strlen($result['payload']);
                    error_log('ODCM DEBUG: fetchLogEntry(' . $logId . ') payload length: ' . $payloadLength);
                    
                    // Sample the payload for debugging
                    if ($payloadLength > 0) {
                        $sampleLength = min($payloadLength, 200);
                        $payloadSample = substr($result['payload'], 0, $sampleLength);
                        error_log('ODCM DEBUG: fetchLogEntry(' . $logId . ') payload sample: ' . $payloadSample);
                    }
                } else {
                    error_log('ODCM DEBUG: fetchLogEntry(' . $logId . ') payload NOT SET');
                }
            } else {
                error_log('ODCM DEBUG: fetchLogEntry(' . $logId . ') NO RESULT from database');
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
        // Enhanced debugging for process entries
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - fetchProcessLogEntries called for process_id: ' . $processId);
        }

        // Check in-memory cache first (fastest)
        if (isset(self::$processEntriesCache[$processId])) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - fetchProcessLogEntries cache hit for process_id: ' . $processId);
            }
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

        // Enhanced debugging for process entries
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: fetchProcessLogEntries - Performing database query for process_id: ' . $processId);
        }

        // Extra safety check to prevent querying with empty process ids
        if (empty($processId)) {
            return [];
        }

        // Use proper table references without interpolation
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
     * Build individual log entry timeline
     */
    private function buildIndividualTimeline(array $logEntry, bool $includeDebug): TimelineData
    {
        // Special handling for order completion events
        if ($this->isOrderCompletionEvent($logEntry['event_type'] ?? '') && defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Building individual timeline for order completion event: ' .
                  ($logEntry['event_type'] ?? 'unknown'));
        }

        // CRITICAL FIX: Extract components ONLY from the specific log entry, not from the entire process
        // This ensures that in individual view, each log entry shows only its own components
        $components = $this->extractComponentsFromLogEntry($logEntry, $includeDebug);

        // For order events with no components, create a synthetic component to ensure something renders
        if (empty($components) && $this->isOrderCompletionEvent($logEntry['event_type'] ?? '')) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: DatabaseTimelineBuilder - Creating synthetic component for order event with no components');
            }
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
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) wp_unslash($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true)),
        ];

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Individual timeline built with ' . count($components) . ' components');
        }

        return TimelineData::individual((int) $logEntry['log_id'], $components, $metadata);
    }

    /**
     * Build process group timeline from all entries with same process_id
     */
    private function buildProcessGroupTimeline(array $representativeLogEntry, bool $includeDebug): TimelineData
    {
        // Debug log entry structure
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: Representative log entry keys: ' . implode(', ', array_keys($representativeLogEntry)));
            error_log('ODCM DEBUG: Representative log entry log_id value: ' . var_export($representativeLogEntry['log_id'] ?? 'NOT SET', true));
        }

        // DEFENSIVE FIX: Ensure representative log entry has valid log_id
        // The issue is that consolidation may create representative entries without proper log_id
        $logId = $this->extractValidLogId($representativeLogEntry);
        if ($logId <= 0) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: Invalid log_id in representative entry, fetching fresh data');
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
            
            // For order events with no components, add a synthetic component
            if (empty($entryComponents) && $this->isOrderCompletionEvent($logEntry['event_type'] ?? '')) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: DatabaseTimelineBuilder - Creating synthetic component for order event in process group');
                }
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
            'execution_time' => microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) wp_unslash($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true)),
        ];

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - Process group timeline built with ' . count($allComponents) . ' components');
        }

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
            error_log('ODCM DEBUG: extractValidLogId failed for entry: ' . var_export($representativeLogEntry, true));
        }

        return 0;
    }

    /**
     * Extract components from a single log entry
     */
    private function extractComponentsFromLogEntry(array $logEntry, bool $includeDebug): array
    {
        // Enhanced debugging for payload issues
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - extractComponentsFromLogEntry called for log_id: ' . ($logEntry['log_id'] ?? 'unknown'));
            error_log('ODCM DEBUG: DatabaseTimelineBuilder - extractComponentsFromLogEntry include_debug: ' . ($includeDebug ? 'true' : 'false'));
        }

        static $componentsCache = [];

        // Create cache key based on log entry ID and debug flag
        $logId = $logEntry['log_id'] ?? 0;
        $cacheKey = $logId . '_' . ($includeDebug ? '1' : '0');

        // Check if we already extracted these components
        if (isset($componentsCache[$cacheKey])) {
            return $componentsCache[$cacheKey];
        }

        $payloadRaw = $logEntry['payload'] ?? '';

        // Enhanced debugging information for payload issues
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $event_type = $logEntry['event_type'] ?? 'unknown';

            error_log('ODCM DEBUG: extractComponentsFromLogEntry for log_id ' . $logId . ' with payload of length: ' . strlen($payloadRaw));
            error_log('ODCM DEBUG: Event type: ' . $event_type);

            // Extra logging for order completion-related events
            if ($this->isOrderCompletionEvent($event_type)) {
                error_log('ODCM DEBUG: *ORDER COMPLETION EVENT DETECTED* - Log ID: ' . $logId);
            }

            if (empty($payloadRaw)) {
                error_log('ODCM DEBUG: Empty payload for log_id ' . $logId);

                // For order events with no payload, create a synthetic component
                if ($this->isOrderCompletionEvent($event_type)) {
                    error_log('ODCM DEBUG: Creating synthetic component for order event with empty payload');
                    $components = [$this->extractor->createSyntheticComponent($logEntry)];
                    $componentsCache[$cacheKey] = $components;
                    return $components;
                }
            } else {
                error_log('ODCM DEBUG: Payload for log_id ' . $logId . ' starts with: ' . substr($payloadRaw, 0, 50) . '...');
                error_log('ODCM DEBUG: JSON validity check: ' . (json_decode($payloadRaw) === null ? 'INVALID JSON: ' . json_last_error_msg() : 'VALID JSON'));
            }
        }

        // Handle empty payload
        if (empty($payloadRaw)) {
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
            $componentsCache[$cacheKey] = $components;
            return $components;
        }

        // Parse payload JSON with detailed error handling
        $payloadData = json_decode($payloadRaw, true);
        if (!is_array($payloadData)) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $error_message = json_last_error_msg();
                error_log('ODCM DEBUG: Failed to decode JSON payload for log_id ' . $logId . ': ' . $error_message);

                // Show more details about the actual payload that couldn't be decoded
                error_log('ODCM DEBUG: Payload sample that failed to decode: ' .
                    preg_replace('/\s+/', ' ', substr($payloadRaw, 0, 150)) .
                    (strlen($payloadRaw) > 150 ? '...' : '')
                );

                // For malformed JSON, show the position where it failed if possible
                if (function_exists('json_last_error_pos')) {
                    $pos = json_last_error_pos();
                    if ($pos !== false) {
                        error_log('ODCM DEBUG: JSON error position: ' . $pos);
                        error_log('ODCM DEBUG: Context at error position: ' .
                            substr($payloadRaw, max(0, $pos - 20), 40)
                        );
                    }
                }

                // For order events with invalid JSON, create a synthetic component
                if ($this->isOrderCompletionEvent($logEntry['event_type'] ?? '')) {
                    error_log('ODCM DEBUG: Creating synthetic component for order event with invalid JSON payload');
                    $components = [$this->extractor->createSyntheticComponent($logEntry)];
                    $componentsCache[$cacheKey] = $components;
                    return $components;
                }
            }

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
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                error_log('ODCM DEBUG: No components extracted from payload for log_id ' . $logId . ', creating synthetic component');
                
                // For order events with no components, be more verbose
                if ($this->isOrderCompletionEvent($logEntry['event_type'] ?? '')) {
                    error_log('ODCM DEBUG: Order completion event but no components extracted');
                    error_log('ODCM DEBUG: Order event payload sample: ' . substr(json_encode($payloadData), 0, 200));
                }
            }
            $components = [$this->extractor->createSyntheticComponent($logEntry)];
        }

        // Cache the extracted components for this request
        $componentsCache[$cacheKey] = $components;

        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            error_log('ODCM DEBUG: Successfully extracted ' . count($components) . ' components from payload for log_id ' . $logId);
        }

        return $components;
    }
}
