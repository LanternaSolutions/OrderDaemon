<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Log Consolidation Service
 * 
 * Provides intelligent grouping and consolidation of audit log entries to improve
 * the user experience in the Insight Dashboard. Groups related lifecycle events
 * by order ID to create unified timeline views, ensuring single entries per order.
 * 
 * Key Features:
 * - Groups ALL lifecycle events by order_id (primary strategy)
 * - Creates single consolidated entries per order regardless of process_id
 * - Maintains chronological order and preserves individual entries
 * - Generates business-friendly summaries focusing on outcomes
 * - Provides debug diagnostics for troubleshooting
 * 
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
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
     * Takes an array of log entries and groups ALL related lifecycle events
     * by order_id to ensure single consolidated entries per order.
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
            // Primary consolidation strategy: Group ALL lifecycle entries by order_id
            $time_window_minutes = isset($lifecycle_family['time_window_minutes']) ? (int)$lifecycle_family['time_window_minutes'] : 30;
            $consolidated_entries = [];
            $individual_entries = [];
            $by_order = [];
            $non_order_entries = [];
            
            // Separate lifecycle entries by order_id vs non-order entries
            foreach ($logs as $entry) {
                $event_type = isset($entry['event_type']) ? (string) $entry['event_type'] : '';
                $order_id = isset($entry['order_id']) ? (int) $entry['order_id'] : 0;

                if (in_array($event_type, $lifecycle_types, true)) {
                    $candidate_by_type++;
                    if ($order_id > 0) {
                        $eligible_with_process_id++; // Reuse counter for order-based consolidation
                        $by_order[$order_id][] = $entry;
                    } else {
                        $missing_process_id++; // Reuse counter for entries without order_id
                        $non_order_entries[] = $entry;
                    }
                } else {
                    if ($event_type !== '') { 
                        $unknown_types_list[] = $event_type; 
                    }
                    $non_order_entries[] = $entry;
                }
            }
            
            // Consolidate all entries for each order into single groups
            $order_consolidation_count = 0;
            $groups_count = 0;
            
            foreach ($by_order as $order_id => $entriesByOrder) {
                $groups_count++; // Count each order as a group
                
                if (count($entriesByOrder) <= 1) {
                    // Single entries remain individual (but this is rare for order processing)
                    $individual_entries = array_merge($individual_entries, $entriesByOrder);
                    continue;
                }
                
                // Sort by time ascending for proper timeline ordering
                usort($entriesByOrder, function($a, $b){
                    return strtotime((string)($a['timestamp'] ?? '')) <=> strtotime((string)($b['timestamp'] ?? ''));
                });
                
                // Always consolidate ALL events for the same order regardless of time span
                // This ensures we get single entries per order as requested
                $first = $entriesByOrder[0];
                $pid = sprintf('odcm:order:%d:%s', $order_id, substr(md5((string)$first['timestamp']), 0, 8));
                $consolidated_entries[] = $this->create_consolidated_entry($entriesByOrder, $pid);
                $order_consolidation_count++;
            }
            
            // Add non-order entries as individuals (system events, diagnostics, etc.)
            $individual_entries = array_merge($individual_entries, $non_order_entries);

            // Merge and sort by timestamp (desc for stream list)
            $all_entries = array_merge($consolidated_entries, $individual_entries);
            usort($all_entries, function($a, $b) {
                return strtotime((string)($b['timestamp'] ?? '')) <=> strtotime((string)($a['timestamp'] ?? ''));
            });

            // Compute diagnostics for order-based consolidation
            $sample_order_groups = [];
            $group_count = 0;
            foreach ($by_order as $order_id => $entriesByOrder) {
                $sample_order_groups["order_$order_id"] = count($entriesByOrder);
                $group_count++;
                if ($group_count >= 5) break; // Limit sample size
            }

            // Store diagnostics for optional API exposure
            $this->last_diag = [
                'enabled' => true,
                'strategy' => 'order_id_primary',
                'lifecycle_types' => $lifecycle_types,
                'total_input' => $total_input,
                'candidate_by_type' => $candidate_by_type,
                'eligible_with_order_id' => $eligible_with_process_id, // Renamed for clarity
                'missing_order_id' => $missing_process_id, // Renamed for clarity  
                'order_groups_count' => $groups_count,
                'consolidated_entries' => count($consolidated_entries),
                'individual_entries' => count($individual_entries),
                'unknown_type_count' => max(0, $total_input - $candidate_by_type),
                'sample_unknown_types' => array_slice(array_values(array_unique($unknown_types_list)), 0, 5),
                'sample_order_groups' => $sample_order_groups,
                'order_consolidation_used' => $order_consolidation_count,
                'consolidation_basis' => 'order_id_direct',
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
        
        // Extract specific business details from the entries
        $payment_amount = null;
        $payment_currency = null;
        $payment_gateway = null;
        $final_status = null;
        $has_completion = false;
        $has_error = false;
        $has_payment = false;
        $has_shipping = false;
        $has_rule_execution = false;
        $has_universal_event = false;
        $business_error_messages = [];

        // Process entries chronologically to get final state
        usort($entries, function($a, $b) {
            return strtotime((string)($a['timestamp'] ?? '')) <=> strtotime((string)($b['timestamp'] ?? ''));
        });

        foreach ($entries as $entry) {
            $event_type = $entry['event_type'] ?? '';
            $status = $entry['status'] ?? '';
            $summary = $entry['summary'] ?? '';
            $summary_lower = strtolower($summary);

            // Extract payment amount and currency (prefer the most recent/complete info)
            if (preg_match('/\b([A-Z]{3})\s*([0-9,]+\.?[0-9]*)\b/', $summary, $matches)) {
                $payment_currency = $matches[1];
                $payment_amount = $matches[2];
            }

            // Extract payment gateway (prefer the most specific info)
            if (preg_match('/\b(stripe|paypal|square|authorize\.net)\b/i', $summary, $matches)) {
                $payment_gateway = ucfirst(strtolower($matches[1]));
            }

            // Track final status from order status changes (most authoritative)
            if (preg_match('/status.*changed.*from\s+"([^"]+)"\s+to\s+"([^"]+)"/', $summary, $matches)) {
                $final_status = $matches[2]; // Keep updating to get the final status
            }

            // Detect key business activities
            if (strpos($summary_lower, 'complet') !== false || strpos($event_type, 'completion') !== false) {
                $has_completion = true;
            }
            
            // Collect business-relevant error messages (skip technical ones)
            if ($status === 'error' && !$this->is_technical_error($summary)) {
                $business_error_messages[] = $summary;
                $has_error = true;
            }
            
            if (strpos($summary_lower, 'payment') !== false || strpos($summary_lower, 'paid') !== false) {
                $has_payment = true;
            }
            if (strpos($summary_lower, 'ship') !== false || strpos($summary_lower, 'deliver') !== false) {
                $has_shipping = true;
            }
            if (strpos($summary_lower, 'rule') !== false || strpos($event_type, 'rule') !== false) {
                $has_rule_execution = true;
            }
            // Detect universal events (Stripe, PayPal, etc.)
            if (strpos($event_type, 'universal_event') !== false || 
                strpos($summary_lower, 'stripe') !== false || 
                strpos($summary_lower, 'paypal') !== false ||
                strpos($summary_lower, 'event received') !== false ||
                strpos($summary_lower, 'event processed') !== false) {
                $has_universal_event = true;
            }
        }

        // Build the business summary with proper formatting
        $summary_parts = [];
        
        // Start with order reference followed by colon
        $order_part = "Order #$order_id:";
        
        // Build main activity description
        $activities = [];
        
        // Priority 1: Handle errors first if present
        if ($has_error && !empty($business_error_messages)) {
            $activities[] = "processing errors occurred";
        }
        // Priority 2: Payment information (most important for business)
        elseif ($payment_amount && $payment_currency && $payment_gateway) {
            if ($has_completion) {
                $activities[] = "$payment_gateway payment of $payment_currency $payment_amount completed";
            } else {
                $activities[] = "$payment_gateway payment of $payment_currency $payment_amount processed";
            }
        }
        elseif ($payment_gateway && ($has_payment || $has_universal_event)) {
            $activities[] = "$payment_gateway payment processed";
        }
        elseif ($has_payment || $has_universal_event) {
            $activities[] = "payment processed";
        }
        
        // Priority 3: Status information (if we have final status and no payment info)
        if (empty($activities) && $final_status) {
            $activities[] = "status updated to \"$final_status\"";
        }
        
        // Priority 4: Other business activities
        if ($has_completion && empty($activities)) {
            $activities[] = "completion processing";
        }
        if ($has_rule_execution) {
            $activities[] = "automation executed";
        }
        if ($has_shipping) {
            $activities[] = "fulfillment processed";
        }
        
        // Fallback: Generic description
        if (empty($activities)) {
            if ($has_universal_event) {
                $activities[] = "gateway events processed";
            } else {
                $activities[] = "lifecycle processing";
            }
        }
        
        // Add final status information if we have it and it's different from the main activity
        if ($final_status && !$has_payment && !$has_error) {
            $activities[] = "status updated to \"$final_status\"";
        }
        
        // Combine order reference with activities and event count
        $activity_text = implode(', ', $activities);
        $summary = "$order_part $activity_text ($event_count events)";
        
        return $summary;
    }

    /**
     * Check if an error message is technical and should be filtered from business summaries
     * 
     * @param string $error_message The error message to check
     * @return bool True if the error is technical/internal
     */
    private function is_technical_error(string $error_message): bool
    {
        $technical_patterns = [
            'Invalid arguments',
            'Universal event processing failed',
            'Database error',
            'PHP error',
            'Class not found',
            'Method not found',
            'Undefined variable',
            'syntax error',
            'fatal error',
        ];
        
        $message_lower = strtolower($error_message);
        foreach ($technical_patterns as $pattern) {
            if (strpos($message_lower, strtolower($pattern)) !== false) {
                return true;
            }
        }
        
        return false;
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
