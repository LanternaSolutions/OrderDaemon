<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

use OrderDaemon\CompletionManager\Includes\Utils\DatabaseHelper;

/**
 * Rule Execution Deduplicator
 *
 * Implements the two-stage idempotency with deterministic keys for rule execution events
 * to solve the duplicate rule execution events issue described in the timeline redesign.
 *
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   1.2.0
 */
class RuleExecutionDeduplicator
{
    /**
     * Generate deterministic rule execution key
     *
     * This implements the deterministic key generation described in the redesign:
     * hash('sha256', sprintf('odcm:rule_exec:v1:%d:%d:%s', $order_id, $rule_id, $process_id))
     *
     * @param int $order_id The order ID
     * @param int $rule_id The rule ID
     * @param string $process_id The process ID
     * @return string Deterministic deduplication key
     */
    public function generateRuleExecutionKey(int $order_id, int $rule_id, string $process_id): string
    {
        return hash('sha256', sprintf('odcm:rule_exec:v1:%d:%d:%s', $order_id, $rule_id, $process_id));
    }

    /**
     * Create or update rule execution with async-safe deduplication
     *
     * This method implements the two-layer deduplication architecture:
     * 1. Queue Table Deduplication (Fast "Claim")
     * 2. Audit Log Deduplication (Final Authority)
     *
     * @param int $order_id The order ID
     * @param int $rule_id The rule ID
     * @param array $context The evaluation context
     * @param string $process_id The process ID
     * @return void
     */
    public function createRuleExecutionEvent(int $order_id, int $rule_id, array $context, string $process_id): void
    {
        // Generate deterministic key before any async operations
        $ruleExecutionKey = $this->generateRuleExecutionKey($order_id, $rule_id, $process_id);

        // Get triggering business event ID
        $triggeringEvent = $this->getTriggeringEventId($context['event']->eventType, $order_id);

        // Prepare event data with deduplication key
        $eventData = [
            'order_id' => $order_id,
            'rule_id' => $rule_id,
            'rule_name' => $context['matched_rule_data']['rule']->post_title ?? 'unnamed rule',
            'parent_id' => $triggeringEvent,
            'process_id' => $process_id,
            'primary_trigger' => $context['event']->eventType,
            'execution_status' => 'EXECUTED',
            'actions_taken' => $this->formatActionsTaken($context['matched_rule_data']),
            'first_seen_at' => current_time('mysql'),
            'last_seen_at' => current_time('mysql'),
            'dedupe_key' => $ruleExecutionKey, // Critical: deterministic key
        ];

        // Attempt to enqueue with deduplication
        $this->enqueueWithDeduplication($ruleExecutionKey, $eventData, $order_id, $process_id);
    }

    /**
     * Enqueue with queue-level deduplication
     *
     * @param string $dedupeKey The deduplication key
     * @param array $eventData The event data
     * @param int $order_id The order ID
     * @param string $process_id The process ID
     * @return void
     */
    private function enqueueWithDeduplication(string $dedupeKey, array $eventData, int $order_id, string $process_id): void
    {
        global $wpdb;

        // Check cache first to avoid unnecessary DB operations
        $cacheKey = 'odcm_deduped_rule_' . $dedupeKey;
        $cached = wp_cache_get($cacheKey, 'odcm_rule_execution');
        
        if ($cached !== false) {
            return; // Already processed recently
        }

        // Try to insert into queue with unique constraint
        // Direct DB query required for custom table with ON DUPLICATE KEY UPDATE
        $result = DatabaseHelper::query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}odcm_audit_log_queue
             (dedupe_key, payload, order_id, process_id, status, created_at)
             VALUES (%s, %s, %d, %s, 'pending', NOW())
             ON DUPLICATE KEY UPDATE
             payload = VALUES(payload),
             updated_at = NOW()",
            $dedupeKey,
            json_encode($eventData),
            $order_id,
            $process_id
        ));

        // Cache the deduplication key for 1 hour to avoid repeated operations
        wp_cache_set($cacheKey, true, 'odcm_rule_execution', HOUR_IN_SECONDS);

        // Only schedule Action Scheduler job if this is a new entry
        if ($wpdb->rows_affected === 1) {
            as_schedule_single_action(time(), 'odcm_process_audit_log_item', [$dedupeKey]);
        }
    }

    /**
     * Queue processor with final audit log deduplication
     *
     * @param string $dedupeKey The deduplication key
     * @return void
     */
    public function processQueueItem(string $dedupeKey): void
    {
        global $wpdb;

        // Check cache for already processed items
        $processCacheKey = 'odcm_processed_' . $dedupeKey;
        if (wp_cache_get($processCacheKey, 'odcm_rule_execution')) {
            return; // Already processed recently
        }

        // Get queued item - direct DB query required for custom table
        $queueItem = DatabaseHelper::get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}odcm_audit_log_queue WHERE dedupe_key = %s",
            $dedupeKey
        ));

        if (!$queueItem) {
            return; // Already processed or doesn't exist
        }

        $eventData = json_decode($queueItem->payload, true);

        // Check cache for existing event to avoid duplicate queries
        $eventCacheKey = 'odcm_existing_' . $dedupeKey;
        $existingEvent = wp_cache_get($eventCacheKey, 'odcm_rule_execution');
        
        if ($existingEvent === false) {
            // Insert or update in final audit log with deduplication - direct DB query required for custom table
            $existingEvent = DatabaseHelper::get_row($wpdb->prepare(
                "SELECT log_id, details FROM {$wpdb->prefix}odcm_audit_log WHERE dedupe_key = %s",
                $dedupeKey
            ));
            
            // Cache for 30 minutes to avoid repeated lookups
            wp_cache_set($eventCacheKey, $existingEvent ?: 'not_found', 'odcm_rule_execution', 30 * MINUTE_IN_SECONDS);
        } elseif ($existingEvent === 'not_found') {
            $existingEvent = null;
        }

        if ($existingEvent) {
            // Update existing record (enrich with new data)
            $this->enrichExistingRuleExecution($existingEvent, $eventData);
        } else {
            // Create new audit log entry
            $this->insertNewRuleExecution($eventData, $dedupeKey);
        }

        // Mark queue item as processed - use DatabaseHelper for update operation
        DatabaseHelper::update(
            "{$wpdb->prefix}odcm_audit_log_queue",
            ['status' => 'processed', 'processed_at' => current_time('mysql')],
            ['dedupe_key' => $dedupeKey]
        );

        // Cache that this item has been processed
        wp_cache_set($processCacheKey, true, 'odcm_rule_execution', HOUR_IN_SECONDS);
    }

    /**
     * Enrich existing rule execution with additional data
     *
     * @param object $existingEvent The existing event
     * @param array $newEventData The new event data
     * @return void
     */
    private function enrichExistingRuleExecution($existingEvent, array $newEventData): void
    {
        $existingDetails = json_decode($existingEvent->details, true) ?: [];

        // Merge enrichment data (actions, triggers, status updates)
        $enrichedDetails = $this->mergeRuleExecutionData($existingDetails, $newEventData);

        // Update with precedence rules (ERROR > PARTIAL > EXECUTED)
        $newStatus = $this->determineStatusPrecedence(
            $existingDetails['execution_status'] ?? 'UNKNOWN',
            $newEventData['execution_status'] ?? 'EXECUTED'
        );

        $enrichedDetails['execution_status'] = $newStatus;
        $enrichedDetails['last_seen_at'] = current_time('mysql');

        // Use DatabaseHelper for update operation
        DatabaseHelper::update(
            "{$wpdb->prefix}odcm_audit_log",
            ['details' => json_encode($enrichedDetails)],
            ['log_id' => $existingEvent->log_id]
        );

        // Invalidate cache for this event since it was updated
        $eventCacheKey = 'odcm_existing_' . ($newEventData['dedupe_key'] ?? '');
        if ($eventCacheKey !== 'odcm_existing_') {
            wp_cache_delete($eventCacheKey, 'odcm_rule_execution');
        }
    }

    /**
     * Insert new rule execution
     *
     * @param array $eventData The event data
     * @param string $dedupeKey The deduplication key
     * @return void
     */
    private function insertNewRuleExecution(array $eventData, string $dedupeKey): void
    {
        global $wpdb;

        // Direct DB query required for custom table insert
        DatabaseHelper::insert(
            "{$wpdb->prefix}odcm_audit_log",
            [
                'order_id' => $eventData['order_id'],
                'rule_id' => $eventData['rule_id'],
                'event_type' => 'rule_execution',
                'status' => 'success',
                'summary' => 'Rule execution completed',
                'details' => json_encode($eventData),
                'process_id' => $eventData['process_id'],
                'timestamp' => current_time('mysql'),
                'dedupe_key' => $dedupeKey,
                'parent_id' => $eventData['parent_id'] ?? null
            ]
        );

        // Cache the new event to avoid future lookups
        $eventCacheKey = 'odcm_existing_' . $dedupeKey;
        $newEvent = (object)[
            'log_id' => $wpdb->insert_id,
            'details' => json_encode($eventData)
        ];
        wp_cache_set($eventCacheKey, $newEvent, 'odcm_rule_execution', 30 * MINUTE_IN_SECONDS);
    }

    /**
     * Get triggering event ID
     *
     * @param string $eventType The event type
     * @param int $order_id The order ID
     * @return int|null The triggering event ID or null
     */
    private function getTriggeringEventId(string $eventType, int $order_id): ?int
    {
        global $wpdb;

        // Check cache for triggering event to avoid repeated queries
        $triggerCacheKey = 'odcm_trigger_' . $order_id . '_' . $eventType;
        $cached = wp_cache_get($triggerCacheKey, 'odcm_rule_execution');
        
        if ($cached !== false) {
            return $cached === 'not_found' ? null : (int)$cached;
        }

        // Look for the most recent event of this type for this order - direct DB query required for custom table
        $result = DatabaseHelper::get_row($wpdb->prepare(
            "SELECT log_id FROM {$wpdb->prefix}odcm_audit_log
             WHERE order_id = %d AND event_type = %s
             ORDER BY timestamp DESC LIMIT 1",
            $order_id,
            $eventType
        ));

        $logId = $result ? (int)$result->log_id : null;
        
        // Cache for 15 minutes to avoid repeated lookups for the same event type/order
        wp_cache_set($triggerCacheKey, $logId ?: 'not_found', 'odcm_rule_execution', 15 * MINUTE_IN_SECONDS);

        return $logId;
    }

    /**
     * Format actions taken
     *
     * @param array $ruleData The rule data
     * @return array Formatted actions
     */
    private function formatActionsTaken(array $ruleData): array
    {
        $actions = [];

        if (isset($ruleData['actions']) && is_array($ruleData['actions'])) {
            foreach ($ruleData['actions'] as $action) {
                $actions[] = [
                    'action_label' => $action['label'] ?? 'Unknown action',
                    'action_type' => $action['type'] ?? 'unknown',
                    'execution_result' => 'success',
                    'timestamp' => current_time('mysql')
                ];
            }
        }

        return $actions;
    }

    /**
     * Merge rule execution data
     *
     * @param array $existingData Existing data
     * @param array $newData New data
     * @return array Merged data
     */
    private function mergeRuleExecutionData(array $existingData, array $newData): array
    {
        // Merge actions taken
        if (isset($newData['actions_taken']) && !empty($newData['actions_taken'])) {
            $existingData['actions_taken'] = array_merge(
                $existingData['actions_taken'] ?? [],
                $newData['actions_taken']
            );
        }

        // Update last seen timestamp
        $existingData['last_seen_at'] = current_time('mysql');

        return $existingData;
    }

    /**
     * Determine status precedence
     *
     * @param string $existingStatus Existing status
     * @param string $newStatus New status
     * @return string Status with highest precedence
     */
    private function determineStatusPrecedence(string $existingStatus, string $newStatus): string
    {
        $precedence = ['ERROR' => 3, 'PARTIAL' => 2, 'EXECUTED' => 1, 'UNKNOWN' => 0];

        $existingScore = $precedence[strtoupper($existingStatus)] ?? 0;
        $newScore = $precedence[strtoupper($newStatus)] ?? 0;

        return $existingScore >= $newScore ? $existingStatus : $newStatus;
    }
}
