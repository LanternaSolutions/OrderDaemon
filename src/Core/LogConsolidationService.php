<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Consolidates log entries for UI display only
 */
final class LogConsolidationService
{
    /**
     * Last diagnostics snapshot from the most recent consolidation run.
     *
     * @var array<string,mixed>|null
     */
    private ?array $last_diag = null;

    /**
     * Consolidate logs by process_id for display
     *
     * @param array<int,array<string,mixed>> $log_entries
     * @return array<int,array<string,mixed>>
     */
    public function consolidate_logs_for_display(array $log_entries): array
    {
        $discovery = ProcessLifecycleDiscovery::instance();
        $families = $discovery->get_process_families();

        // First, try cross-entity consolidation for universal events
        $log_entries = $this->consolidate_cross_entity_events($log_entries);

        $lifecycle_family = $families['order_lifecycle'] ?? null;
        if (!$lifecycle_family || empty($lifecycle_family['consolidate_ui'])) {
            $this->last_diag = [
                'enabled' => false,
                'reason' => 'consolidate_ui_disabled',
                'total_input' => count($log_entries),
            ];
            return $log_entries;
        }

        $lifecycle_types = array_values(array_unique(array_filter((array) ($lifecycle_family['process_types'] ?? []))));
        $consolidated_groups = [];
        $individual_entries = [];

        // Diagnostics counters
        $total_input = count($log_entries);
        $candidate_by_type = 0;
        $missing_process_id = 0;
        $eligible_with_process_id = 0;
        $unknown_types_list = [];

        // Group entries by process_id when lifecycle type
        foreach ($log_entries as $entry) {
            $event_type = isset($entry['event_type']) ? (string) $entry['event_type'] : '';
            $process_id = isset($entry['process_id']) ? (string) $entry['process_id'] : '';

            if (in_array($event_type, $lifecycle_types, true)) {
                $candidate_by_type++;
                if ($process_id !== '') {
                    $eligible_with_process_id++;
                    $consolidated_groups[$process_id][] = $entry;
                } else {
                    $missing_process_id++;
                    $individual_entries[] = $entry;
                }
            } else {
                if ($event_type !== '') { $unknown_types_list[] = $event_type; }
                $individual_entries[] = $entry;
            }
        }

        // Convert groups to consolidated entries
        $consolidated_entries = [];
        $groups_count = 0;
        $non_grouped_candidates = 0;
        foreach ($consolidated_groups as $process_id => $group_entries) {
            if (count($group_entries) > 1) {
                $groups_count++;
                $consolidated_entries[] = $this->create_consolidated_entry($group_entries, $process_id);
            } else {
                $non_grouped_candidates++;
                $individual_entries[] = $group_entries[0];
            }
        }

        // Fallback consolidation: if no multi-entry groups by process_id were found,
        // cluster lifecycle entries by order_id within a time window and consolidate.
        $fallback_groups_used = 0;
        if ($groups_count === 0) {
            $time_window_minutes = isset($lifecycle_family['time_window_minutes']) ? (int)$lifecycle_family['time_window_minutes'] : 30;
            $by_order = [];
            foreach ($individual_entries as $entry) {
                $evt = isset($entry['event_type']) ? (string)$entry['event_type'] : '';
                if (!in_array($evt, $lifecycle_types, true)) { continue; }
                $oid = isset($entry['order_id']) ? (int)$entry['order_id'] : 0;
                if ($oid <= 0) { continue; }
                $by_order[$oid][] = $entry;
            }
            foreach ($by_order as $oid => $entriesByOrder) {
                // Sort by time ascending for clustering
                usort($entriesByOrder, function($a, $b){
                    return strtotime((string)($a['timestamp'] ?? '')) <=> strtotime((string)($b['timestamp'] ?? ''));
                });
                $cluster = [];
                $cluster_start = null;
                $flush_cluster = function() use (&$cluster, &$consolidated_entries, &$fallback_groups_used) {
                    if (count($cluster) > 1) {
                        $first = $cluster[0];
                        $pid = sprintf('odcm:orderwin:%d:%s', (int)($first['order_id'] ?? 0), substr(md5((string)$first['timestamp']), 0, 8));
                        $consolidated_entries[] = $this->create_consolidated_entry($cluster, $pid);
                        $fallback_groups_used++;
                    } else {
                        // Keep singletons for normal output
                    }
                    $cluster = [];
                };
                foreach ($entriesByOrder as $e) {
                    $t = strtotime((string)($e['timestamp'] ?? ''));
                    if ($cluster_start === null) {
                        $cluster[] = $e;
                        $cluster_start = $t;
                        continue;
                    }
                    if (($t - $cluster_start) <= ($time_window_minutes * 60)) {
                        $cluster[] = $e;
                    } else {
                        // flush previous cluster and start a new one
                        $flush_cluster();
                        $cluster[] = $e;
                        $cluster_start = $t;
                    }
                }
                // flush remaining
                $flush_cluster();
            }
            // Remove any entries that became part of fallback consolidated groups
            if ($fallback_groups_used > 0) {
                // Build a flat list of event IDs/timestamps used in consolidated_entries to filter duplicates
                $used_keys = [];
                foreach ($consolidated_entries as $ce) {
                    $timeline = isset($ce['consolidation_data']['timeline_events']) && is_array($ce['consolidation_data']['timeline_events'])
                        ? $ce['consolidation_data']['timeline_events']
                        : [];
                    foreach ($timeline as $te) {
                        $used_keys[] = (string)($te['id'] ?? ($te['timestamp'] ?? '')); // fallback to timestamp as key
                    }
                }
                $individual_entries = array_values(array_filter($individual_entries, function($ie) use ($used_keys){
                    $k = (string)($ie['id'] ?? ($ie['timestamp'] ?? ''));
                    return !in_array($k, $used_keys, true);
                }));
            }
        }

        // Merge and sort by timestamp (desc for stream list)
        $all_entries = array_merge($consolidated_entries, $individual_entries);
        usort($all_entries, function($a, $b) {
            return strtotime((string)($b['timestamp'] ?? '')) <=> strtotime((string)($a['timestamp'] ?? ''));
        });

        // Compute top group sizes for diagnostics
        $group_sizes = [];
        foreach ($consolidated_groups as $pid => $entriesByPid) {
            $group_sizes[$pid] = count($entriesByPid);
        }
        arsort($group_sizes);
        $sample_group_sizes = array_slice($group_sizes, 0, 5, true);

        // Store diagnostics for optional API exposure
        $this->last_diag = [
            'enabled' => true,
            'lifecycle_types' => $lifecycle_types,
            'total_input' => $total_input,
            'candidate_by_type' => $candidate_by_type,
            'eligible_with_process_id' => $eligible_with_process_id,
            'missing_process_id' => $missing_process_id,
            'groups_count' => $groups_count,
            'consolidated_entries' => count($consolidated_entries),
            'non_grouped_candidates' => $non_grouped_candidates,
            'unknown_type_count' => max(0, $total_input - $candidate_by_type),
            'sample_unknown_types' => array_slice(array_values(array_unique($unknown_types_list)), 0, 5),
            'sample_process_ids' => array_slice(array_keys($consolidated_groups), 0, 5),
            'sample_group_sizes' => $sample_group_sizes,
            'fallback_grouping_used' => $fallback_groups_used,
            'fallback_basis' => $fallback_groups_used > 0 ? 'order_id+time_window' : null,
        ];

        return $all_entries;
    }

    /**
     * Create a consolidated entry from multiple related entries
     *
     * @param array<int,array<string,mixed>> $entries
     * @param string $process_id
     * @return array<string,mixed>
     */
    private function create_consolidated_entry(array $entries, string $process_id): array
    {
        // Sort entries by timestamp (oldest first for timeline)
        usort($entries, function($a, $b) {
            return strtotime((string)($a['timestamp'] ?? '')) <=> strtotime((string)($b['timestamp'] ?? ''));
        });

        $first_entry = $entries[0];
        $last_entry = $entries[count($entries) - 1];
        $order_id = isset($first_entry['order_id']) ? (int) $first_entry['order_id'] : 0;

        // Use a real log id for click-through compatibility (last entry)
        $click_id = isset($last_entry['id']) ? (int) $last_entry['id'] : (isset($first_entry['id']) ? (int) $first_entry['id'] : 0);

        return [
            'id' => $click_id, // real log id to keep existing detail rendering flow
            'process_id' => $process_id,
            'summary' => sprintf('Order #%d lifecycle process (%d steps)', $order_id, count($entries)),
            'event_type' => 'order_lifecycle_consolidated',
            'status' => $this->determine_overall_status($entries),
            'order_id' => $order_id,
            'timestamp' => $first_entry['timestamp'], // Use start time for sorting/labeling
            // Preserve source if available on last entry for filters
            'source' => $last_entry['source'] ?? ($first_entry['source'] ?? 'system'),
            // Pass-through fields for downstream consumers
            'process_id_display' => $process_id,
            'consolidation_data' => [
                'is_consolidated' => true,
                'entry_count' => count($entries),
                'duration' => $this->calculate_duration((string)$first_entry['timestamp'], (string)$last_entry['timestamp']),
                'timeline_events' => $entries,
            ],
        ];
    }

    /**
     * Determine overall status from multiple entries
     *
     * @param array<int,array<string,mixed>> $entries
     * @return string
     */
    private function determine_overall_status(array $entries): string
    {
        $statuses = array_map(function($e){ return (string)($e['status'] ?? 'info'); }, $entries);

        if (in_array('error', $statuses, true)) return 'error';
        if (in_array('warning', $statuses, true)) return 'warning';
        if (in_array('success', $statuses, true)) return 'success';

        return 'info';
    }

    /**
     * Calculate duration between start and end
     *
     * @param string $start_time
     * @param string $end_time
     * @return array{seconds:int,human_readable:string}
     */
    private function calculate_duration(string $start_time, string $end_time): array
    {
        $start = strtotime($start_time);
        $end = strtotime($end_time);
        $duration_seconds = max(0, $end - $start);

        return [
            'seconds' => $duration_seconds,
            'human_readable' => $this->format_duration($duration_seconds),
        ];
    }

    /**
     * Format duration for display
     */
    private function format_duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }

    /**
     * Get last diagnostics snapshot
     *
     * @return array<string,mixed>|null
     */
    public function get_last_diag(): ?array
    {
        return $this->last_diag;
    }

    /**
     * Consolidate cross-entity events for universal event processing.
     * 
     * This method handles consolidation of events that span multiple entities
     * (orders, subscriptions, customers) and different gateways.
     *
     * @param array<int,array<string,mixed>> $log_entries
     * @return array<int,array<string,mixed>>
     */
    public function consolidate_cross_entity_events(array $log_entries): array
    {
        $discovery = ProcessLifecycleDiscovery::instance();
        $families = $discovery->get_process_families();

        // Get families that support cross-entity consolidation
        $cross_entity_families = array_filter($families, function($family) {
            return !empty($family['cross_entity']) && !empty($family['consolidate_ui']);
        });

        if (empty($cross_entity_families)) {
            return $log_entries;
        }

        $cross_entity_groups = [];
        $remaining_entries = [];

        // Group entries by cross-entity relationships
        foreach ($log_entries as $entry) {
            $event_type = isset($entry['event_type']) ? (string) $entry['event_type'] : '';
            $is_cross_entity_candidate = false;

            // Check if this entry belongs to a cross-entity family
            foreach ($cross_entity_families as $family) {
                $process_types = $family['process_types'] ?? [];
                if (in_array($event_type, $process_types, true)) {
                    $is_cross_entity_candidate = true;
                    break;
                }
            }

            if ($is_cross_entity_candidate) {
                $cross_entity_key = $this->generate_cross_entity_key($entry);
                if ($cross_entity_key) {
                    $cross_entity_groups[$cross_entity_key][] = $entry;
                } else {
                    $remaining_entries[] = $entry;
                }
            } else {
                $remaining_entries[] = $entry;
            }
        }

        // Create consolidated entries for cross-entity groups
        $consolidated_entries = [];
        foreach ($cross_entity_groups as $key => $group_entries) {
            if (count($group_entries) > 1) {
                $process_id = $this->generate_universal_process_id($group_entries, $key);
                $consolidated_entries[] = $this->create_cross_entity_consolidated_entry($group_entries, $process_id);
            } else {
                $remaining_entries[] = $group_entries[0];
            }
        }

        // Merge and sort results
        $all_entries = array_merge($consolidated_entries, $remaining_entries);
        usort($all_entries, function($a, $b) {
            return strtotime((string)($b['timestamp'] ?? '')) <=> strtotime((string)($a['timestamp'] ?? ''));
        });

        return $all_entries;
    }

    /**
     * Generate universal process ID for cross-entity events.
     * 
     * Implements the cross-entity process ID strategy:
     * - Single entity: "odcm:lifecycle:order:123:timestamp"
     * - Multi-entity: "odcm:universal:order123+sub456:payment_flow:timestamp"
     * - Cross-gateway: "odcm:payment_flow:order:123:gateway_sequence:timestamp"
     *
     * @param array<int,array<string,mixed>> $entries
     * @param string $cross_entity_key
     * @return string
     */
    public function generate_universal_process_id(array $entries, string $cross_entity_key): string
    {
        if (empty($entries)) {
            return 'odcm:universal:empty:' . uniqid();
        }

        $first_entry = $entries[0];
        $timestamp = substr(md5((string)($first_entry['timestamp'] ?? '')), 0, 8);

        // Extract entity information
        $order_ids = [];
        $subscription_ids = [];
        $gateways = [];

        foreach ($entries as $entry) {
            if (!empty($entry['order_id'])) {
                $order_ids[] = (int) $entry['order_id'];
            }
            if (!empty($entry['primary_object_id']) && ($entry['primary_object_type'] ?? '') === 'subscription') {
                $subscription_ids[] = (int) $entry['primary_object_id'];
            }
            if (!empty($entry['secondary_object_id']) && ($entry['secondary_object_type'] ?? '') === 'subscription') {
                $subscription_ids[] = (int) $entry['secondary_object_id'];
            }
            if (!empty($entry['gateway_name'])) {
                $gateways[] = (string) $entry['gateway_name'];
            }
        }

        $order_ids = array_unique($order_ids);
        $subscription_ids = array_unique($subscription_ids);
        $gateways = array_unique($gateways);

        // Determine process ID strategy
        if (count($gateways) > 1) {
            // Cross-gateway scenario (check this first)
            $primary_order = !empty($order_ids) ? $order_ids[0] : 0;
            return sprintf('odcm:payment_flow:order:%d:gateway_sequence:%s', $primary_order, $timestamp);
        } elseif (count($order_ids) === 1 && empty($subscription_ids)) {
            // Single order entity
            return sprintf('odcm:lifecycle:order:%d:%s', $order_ids[0], $timestamp);
        } elseif (count($subscription_ids) === 1 && empty($order_ids)) {
            // Single subscription entity
            return sprintf('odcm:lifecycle:subscription:%d:%s', $subscription_ids[0], $timestamp);
        } elseif (!empty($order_ids) && !empty($subscription_ids)) {
            // Multi-entity (order + subscription)
            $entity_part = sprintf('order%s+sub%s', 
                implode(',', $order_ids), 
                implode(',', $subscription_ids)
            );
            return sprintf('odcm:universal:%s:payment_flow:%s', $entity_part, $timestamp);
        } else {
            // Fallback to generic universal ID
            return sprintf('odcm:universal:%s:%s', $cross_entity_key, $timestamp);
        }
    }

    /**
     * Generate cross-entity grouping key for related events.
     *
     * @param array<string,mixed> $entry
     * @return string|null
     */
    private function generate_cross_entity_key(array $entry): ?string
    {
        $key_parts = [];

        // Primary grouping by order ID
        if (!empty($entry['order_id'])) {
            $key_parts[] = 'order:' . (int) $entry['order_id'];
        }

        // Secondary grouping by subscription ID
        if (!empty($entry['primary_object_id']) && ($entry['primary_object_type'] ?? '') === 'subscription') {
            $key_parts[] = 'sub:' . (int) $entry['primary_object_id'];
        }
        if (!empty($entry['secondary_object_id']) && ($entry['secondary_object_type'] ?? '') === 'subscription') {
            $key_parts[] = 'sub:' . (int) $entry['secondary_object_id'];
        }

        // Tertiary grouping by transaction ID for payment flows
        if (!empty($entry['transaction_id'])) {
            $key_parts[] = 'txn:' . sanitize_text_field((string) $entry['transaction_id']);
        }

        // Quaternary grouping by gateway for cross-gateway scenarios
        if (!empty($entry['gateway_name'])) {
            $key_parts[] = 'gw:' . sanitize_text_field((string) $entry['gateway_name']);
        }

        return !empty($key_parts) ? implode('|', $key_parts) : null;
    }

    /**
     * Create consolidated entry for cross-entity events.
     *
     * @param array<int,array<string,mixed>> $entries
     * @param string $process_id
     * @return array<string,mixed>
     */
    private function create_cross_entity_consolidated_entry(array $entries, string $process_id): array
    {
        // Sort entries by timestamp (oldest first for timeline)
        usort($entries, function($a, $b) {
            return strtotime((string)($a['timestamp'] ?? '')) <=> strtotime((string)($b['timestamp'] ?? ''));
        });

        $first_entry = $entries[0];
        $last_entry = $entries[count($entries) - 1];

        // Extract entity information for summary
        $order_ids = [];
        $subscription_ids = [];
        $gateways = [];

        foreach ($entries as $entry) {
            if (!empty($entry['order_id'])) {
                $order_ids[] = (int) $entry['order_id'];
            }
            if (!empty($entry['primary_object_id']) && ($entry['primary_object_type'] ?? '') === 'subscription') {
                $subscription_ids[] = (int) $entry['primary_object_id'];
            }
            if (!empty($entry['secondary_object_id']) && ($entry['secondary_object_type'] ?? '') === 'subscription') {
                $subscription_ids[] = (int) $entry['secondary_object_id'];
            }
            if (!empty($entry['gateway_name'])) {
                $gateways[] = (string) $entry['gateway_name'];
            }
        }

        $order_ids = array_unique($order_ids);
        $subscription_ids = array_unique($subscription_ids);
        $gateways = array_unique($gateways);

        // Generate appropriate summary
        $summary = $this->generate_cross_entity_summary($order_ids, $subscription_ids, $gateways, count($entries));

        // Use a real log id for click-through compatibility
        $click_id = isset($last_entry['id']) ? (int) $last_entry['id'] : (isset($first_entry['id']) ? (int) $first_entry['id'] : 0);

        return [
            'id' => $click_id,
            'process_id' => $process_id,
            'summary' => $summary,
            'event_type' => 'cross_entity_consolidated',
            'status' => $this->determine_overall_status($entries),
            'order_id' => !empty($order_ids) ? $order_ids[0] : null,
            'timestamp' => $first_entry['timestamp'],
            'source' => $last_entry['source'] ?? ($first_entry['source'] ?? 'system'),
            'gateway_name' => !empty($gateways) ? $gateways[0] : null,
            'primary_object_type' => !empty($order_ids) ? 'order' : (!empty($subscription_ids) ? 'subscription' : null),
            'primary_object_id' => !empty($order_ids) ? $order_ids[0] : (!empty($subscription_ids) ? $subscription_ids[0] : null),
            'process_id_display' => $process_id,
            'consolidation_data' => [
                'is_consolidated' => true,
                'is_cross_entity' => true,
                'entry_count' => count($entries),
                'duration' => $this->calculate_duration((string)$first_entry['timestamp'], (string)$last_entry['timestamp']),
                'timeline_events' => $entries,
                'entity_summary' => [
                    'orders' => $order_ids,
                    'subscriptions' => $subscription_ids,
                    'gateways' => $gateways,
                ],
            ],
        ];
    }

    /**
     * Generate summary for cross-entity consolidated entries.
     *
     * @param array<int> $order_ids
     * @param array<int> $subscription_ids
     * @param array<string> $gateways
     * @param int $event_count
     * @return string
     */
    private function generate_cross_entity_summary(array $order_ids, array $subscription_ids, array $gateways, int $event_count): string
    {
        $parts = [];

        if (!empty($gateways)) {
            $gateway_part = count($gateways) === 1 ? $gateways[0] : 'Multi-gateway';
            $parts[] = ucfirst($gateway_part);
        }

        if (!empty($order_ids) && !empty($subscription_ids)) {
            $parts[] = sprintf('Order #%s + Subscription #%s', 
                implode(',', $order_ids), 
                implode(',', $subscription_ids)
            );
        } elseif (!empty($order_ids)) {
            $parts[] = sprintf('Order #%s', implode(',', $order_ids));
        } elseif (!empty($subscription_ids)) {
            $parts[] = sprintf('Subscription #%s', implode(',', $subscription_ids));
        }

        $parts[] = sprintf('(%d events)', $event_count);

        return implode(' ', $parts);
    }
}
