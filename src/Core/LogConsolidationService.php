<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Log Consolidation Service
 * 
 * Provides intelligent grouping and consolidation of audit log entries to improve
 * the user experience in the Insight Dashboard. Groups related lifecycle events
 * by process_id and order ID with time proximity to create unified timeline views.
 * 
 * Key Features:
 * - Groups logs by process_id for lifecycle events
 * - Falls back to order_id + time window clustering
 * - Creates consolidated entries with embedded timeline data
 * - Maintains chronological order and preserves individual entries
 * - Provides debug diagnostics for troubleshooting
 * 
 * @package OrderDaemon\CompletionManager\Core
 * @since   2.2.1
 */
class LogConsolidationService
{
    /**
     * Last diagnostics snapshot from the most recent consolidation run.
     *
     * @var array<string,mixed>|null
     */
    private ?array $last_diag = null;

    /**
     * Consolidate logs for display in the Insight Dashboard
     * 
     * Takes an array of log entries and groups related lifecycle events
     * by process_id first, then by order ID and time proximity as fallback.
     * Returns a mixed array of individual and consolidated entries suitable 
     * for dashboard display.
     * 
     * @param array $logs Array of log entries from the database
     * @return array Array of individual and consolidated log entries
     */
    public function consolidate_logs_for_display(array $logs): array
    {
        $discovery = ProcessLifecycleDiscovery::instance();
        $families = $discovery->get_process_families();

        $lifecycle_family = $families['order_lifecycle'] ?? null;
        if (!$lifecycle_family || empty($lifecycle_family['consolidate_ui'])) {
            $this->last_diag = [
                'enabled' => false,
                'reason' => 'consolidate_ui_disabled',
                'total_input' => count($logs),
                'errors' => [],
            ];
            return $logs;
        }

        $lifecycle_types = array_values(array_unique(array_filter((array) ($lifecycle_family['process_types'] ?? []))));
        $consolidated_groups = [];
        $individual_entries = [];

        // Diagnostics counters
        $total_input = count($logs);
        $candidate_by_type = 0;
        $missing_process_id = 0;
        $eligible_with_process_id = 0;
        $unknown_types_list = [];
        $errors = [];

        try {
            // Group entries by process_id when lifecycle type
            foreach ($logs as $entry) {
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
                    if ($event_type !== '') { 
                        $unknown_types_list[] = $event_type; 
                    }
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
                    if (!in_array($evt, $lifecycle_types, true)) { 
                        continue; 
                    }
                    $oid = isset($entry['order_id']) ? (int)$entry['order_id'] : 0;
                    if ($oid <= 0) { 
                        continue; 
                    }
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
                'errors' => $errors,
                'output_count' => count($all_entries),
            ];

            return $all_entries;

        } catch (\Throwable $e) {
            $errors[] = 'Consolidation failed: ' . $e->getMessage();
            $this->last_diag = [
                'enabled' => true,
                'total_input' => $total_input,
                'errors' => $errors,
                'output_count' => count($logs),
            ];
            
            // Return original logs on error to maintain functionality
            return $logs;
        }
    }

    /**
     * Get diagnostics from the last consolidation operation
     * 
     * @return array Diagnostic information including timing and statistics
     */
    public function get_last_diag(): array
    {
        return $this->last_diag ?? [];
    }

    /**
     * Create a consolidated entry from multiple related entries
     *
     * @param array $entries Array of related log entries
     * @param string $process_id Process ID for the group
     * @return array Consolidated entry
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

        // Create business-relevant summary
        $summary = $this->create_business_summary($entries, $order_id);

        return [
            'id' => $click_id, // real log id to keep existing detail rendering flow
            'process_id' => $process_id,
            'summary' => $summary,
            'event_type' => 'order_lifecycle_consolidated',
            'status' => $this->determine_overall_status($entries),
            'order_id' => $order_id,
            'timestamp' => $first_entry['timestamp'], // Use start time for sorting/labeling
            'source' => $last_entry['source'] ?? ($first_entry['source'] ?? 'automation'),
            'is_test' => $this->any_test_entries($entries),
            'has_payload' => true,
            'process_id_display' => $process_id,
            'consolidation_data' => [
                'is_consolidated' => true,
                'entry_count' => count($entries),
                'duration' => $this->calculate_duration((string)$first_entry['timestamp'], (string)$last_entry['timestamp']),
                'timeline_events' => $entries,
                'consolidated_at' => current_time('mysql'),
                'consolidation_version' => '2.0',
            ],
        ];
    }

    /**
     * Create a business-relevant summary for consolidated entries
     * 
     * Analyzes the group of entries to create meaningful, user-facing summaries
     * that focus on business outcomes rather than technical consolidation details.
     * 
     * @param array $entries Array of log entries in the group
     * @param int $order_id The order ID
     * @return string Business-relevant summary text
     */
    private function create_business_summary(array $entries, int $order_id): string
    {
        $event_count = count($entries);
        
        // Analyze the group to determine the primary business activity
        $has_completion = false;
        $has_error = false;
        $has_payment = false;
        $has_shipping = false;
        $has_rule_execution = false;

        foreach ($entries as $entry) {
            $event_type = $entry['event_type'] ?? '';
            $status = $entry['status'] ?? '';
            $summary = strtolower($entry['summary'] ?? '');

            // Detect key business activities
            if (strpos($summary, 'complet') !== false || strpos($event_type, 'completion') !== false) {
                $has_completion = true;
            }
            if ($status === 'error' || strpos($summary, 'error') !== false || strpos($summary, 'fail') !== false) {
                $has_error = true;
            }
            if (strpos($summary, 'payment') !== false || strpos($summary, 'paid') !== false) {
                $has_payment = true;
            }
            if (strpos($summary, 'ship') !== false || strpos($summary, 'deliver') !== false) {
                $has_shipping = true;
            }
            if (strpos($summary, 'rule') !== false || strpos($event_type, 'rule') !== false) {
                $has_rule_execution = true;
            }
        }

        // Create business-focused summary based on detected activities
        if ($has_error) {
            return sprintf('Order #%d processing encountered issues (%d events)', $order_id, $event_count);
        }

        if ($has_completion && $has_payment) {
            return sprintf('Order #%d completed and payment processed (%d events)', $order_id, $event_count);
        }

        if ($has_completion) {
            return sprintf('Order #%d completion processing (%d events)', $order_id, $event_count);
        }

        if ($has_payment && $has_shipping) {
            return sprintf('Order #%d payment and fulfillment processing (%d events)', $order_id, $event_count);
        }

        if ($has_payment) {
            return sprintf('Order #%d payment processing (%d events)', $order_id, $event_count);
        }

        if ($has_shipping) {
            return sprintf('Order #%d fulfillment processing (%d events)', $order_id, $event_count);
        }

        if ($has_rule_execution) {
            return sprintf('Order #%d automation rules executed (%d events)', $order_id, $event_count);
        }

        // Fallback to generic business summary
        return sprintf('Order #%d lifecycle process (%d events)', $order_id, $event_count);
    }

    /**
     * Determine overall status from multiple entries
     *
     * @param array $entries Array of log entries
     * @return string Overall status
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
     * Check if any entries in the group are test entries
     * 
     * @param array $entries Array of log entries
     * @return bool True if any entry is marked as test
     */
    private function any_test_entries(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!empty($entry['is_test']) && (int)$entry['is_test'] === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calculate duration between start and end
     *
     * @param string $start_time Start timestamp
     * @param string $end_time End timestamp
     * @return array Duration information
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
     *
     * @param int $seconds Duration in seconds
     * @return string Human-readable duration
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
     * Get configuration for consolidation behavior
     * 
     * @return array Configuration settings
     */
    public function get_configuration(): array
    {
        $discovery = ProcessLifecycleDiscovery::instance();
        $families = $discovery->get_process_families();
        $lifecycle_family = $families['order_lifecycle'] ?? null;
        
        return [
            'consolidate_ui_enabled' => !empty($lifecycle_family['consolidate_ui']),
            'time_window_minutes' => isset($lifecycle_family['time_window_minutes']) ? (int)$lifecycle_family['time_window_minutes'] : 30,
            'lifecycle_types' => isset($lifecycle_family['process_types']) ? (array)$lifecycle_family['process_types'] : [],
            'lifecycle_families_enabled' => $discovery !== null,
        ];
    }
}
