<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Performance Renderer
 *
 * Renders performance metrics including execution times, memory usage,
 * and other performance-related data with proper formatting.
 *
 * This renderer focuses purely on content rendering while the base class
 * handles all structural concerns (headers, icons, component wrapper).
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.3.0
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Performance Renderer Class
 *
 * Handles rendering of performance metrics with proper formatting
 * and human-readable values.
 *
 * @since 1.0.0
 */
class PerformanceRenderer extends PayloadComponentRenderer
{
    /**
     * Get Component ID for Registry Lookup
     *
     * @since 1.0.0
     *
     * @return string Component identifier.
     */
    protected function getComponentId(): string
    {
        return 'performance_metrics';
    }

    /**
     * Render embedded content: compact inline metrics
     *
     * @param array $data Performance data
     * @return string HTML
     */
    public function renderEmbeddedContent(array $data): string
    {
        $metrics = [];

        // Execution time (milliseconds)
        $timeMs = null;
        if (isset($data['execution_time'])) {
            // could be ms or seconds; assume ms if > 10, else seconds float
            $exec = $data['execution_time'];
            if (is_numeric($exec)) {
                $val = (float)$exec;
                $timeMs = $val >= 10 ? $val : ($val * 1000.0);
            }
        } elseif (isset($data['attribution_capture_ms'])) {
            $timeMs = (float)$data['attribution_capture_ms'];
        } elseif (isset($data['duration_ms'])) {
            $timeMs = (float)$data['duration_ms'];
        }
        if ($timeMs !== null) {
            $slow = $timeMs >= 2500.0; // mark slow if >= 2.5s
            $timeLabel = $timeMs < 1000.0 ? sprintf('%dms', (int)round($timeMs)) : sprintf('%.1fs', $timeMs / 1000.0);
            $metrics[] = $timeLabel;
            $timeClass = $slow ? ' odcm-performance-slow' : '';
        } else {
            $timeClass = '';
        }

        // Memory usage (bytes)
        if (isset($data['memory_usage']) && is_numeric($data['memory_usage'])) {
            $metrics[] = $this->formatBytes((int)$data['memory_usage']);
        } elseif (isset($data['peak_memory']) && is_numeric($data['peak_memory'])) {
            $metrics[] = $this->formatBytes((int)$data['peak_memory']);
        }

        // Database queries
        if (isset($data['db_queries']) && is_numeric($data['db_queries'])) {
            $q = (int)$data['db_queries'];
            $metrics[] = sprintf(_n('%d query', '%d queries', $q, 'order-daemon'), $q);
        } elseif (isset($data['queries']) && is_numeric($data['queries'])) {
            $q = (int)$data['queries'];
            $metrics[] = sprintf(_n('%d query', '%d queries', $q, 'order-daemon'), $q);
        }

        if (empty($metrics)) {
            return parent::renderEmbeddedContent($data);
        }

        return '<span class="odcm-performance-inline' . esc_attr($timeClass) . '">' . esc_html(implode(' • ', $metrics)) . '</span>';
    }

    /**
     * Render Performance Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform complex performance data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing Alpine.js interactive features for performance analysis and monitoring
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data Performance data containing metrics and timing information.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];
        
        // === DATA ADAPTATION PHASE ===
        // Transform complex performance data into simple, clean formats using private adapters
        
        // Adapt core performance metrics
        $performance_metrics_html = $this->adaptPerformanceMetrics($data, $toolkit);
        if ($performance_metrics_html !== null) {
            $html_parts[] = $performance_metrics_html;
        }
        
        // Adapt memory usage metrics
        $memory_metrics_html = $this->adaptMemoryMetrics($data, $toolkit);
        if ($memory_metrics_html !== null) {
            $html_parts[] = $memory_metrics_html;
        }
        
        // Adapt database performance metrics
        $database_metrics_html = $this->adaptDatabaseMetrics($data, $toolkit);
        if ($database_metrics_html !== null) {
            $html_parts[] = $database_metrics_html;
        }
        
        // Adapt performance status indicators
        $status_indicators_html = $this->adaptPerformanceStatusIndicators($data, $toolkit);
        if ($status_indicators_html !== null) {
            $html_parts[] = $status_indicators_html;
        }
        
        // Adapt system resource metrics
        $system_metrics_html = $this->adaptSystemMetrics($data, $toolkit);
        if ($system_metrics_html !== null) {
            $html_parts[] = $system_metrics_html;
        }
        
        // Adapt performance timeline/history
        $timeline_html = $this->adaptPerformanceTimeline($data, $toolkit);
        if ($timeline_html !== null) {
            $html_parts[] = $timeline_html;
        }
        
        // Adapt additional/custom metrics
        $additional_metrics_html = $this->adaptAdditionalMetrics($data, $toolkit);
        if ($additional_metrics_html !== null) {
            $html_parts[] = $additional_metrics_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific performance components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        return implode('', $html_parts);
    }

    /**
     * Adapt Performance Metrics
     *
     * Transforms core performance metrics into clean key-value pairs.
     * Handles execution time, CPU usage, and general performance indicators.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for performance metrics or null if no metrics found.
     */
    private function adaptPerformanceMetrics(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $metrics = [];
        
        // Defensive programming: Check each field individually
        $execution_time = $data['execution_time'] ?? $data['time'] ?? null;
        if ($execution_time !== null && is_numeric($execution_time)) {
            $metrics['Execution Time'] = $this->formatTime((float)$execution_time);
        }
        
        $cpu_usage = $data['cpu_usage'] ?? null;
        if ($cpu_usage !== null && is_numeric($cpu_usage)) {
            $metrics['CPU Usage'] = number_format((float)$cpu_usage, 2) . '%';
        }
        
        $load_average = $data['load_average'] ?? null;
        if ($load_average !== null) {
            $metrics['Load Average'] = (string)$load_average;
        }
        
        $response_time = $data['response_time'] ?? null;
        if ($response_time !== null && is_numeric($response_time)) {
            $metrics['Response Time'] = $this->formatTime((float)$response_time);
        }
        
        // Only render if we have meaningful metrics
        if (empty($metrics)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($metrics, 'Performance Metrics');
    }

    /**
     * Adapt Memory Metrics
     *
     * Transforms memory usage data into formatted display with interactive features.
     * Handles current usage, peak usage, and memory allocation details.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for memory metrics or null if no memory data found.
     */
    private function adaptMemoryMetrics(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $memory_data = [];
        
        // Defensive programming: Check each field individually
        $memory_usage = $data['memory_usage'] ?? $data['memory'] ?? null;
        if ($memory_usage !== null && is_numeric($memory_usage)) {
            $memory_data['Current Usage'] = $this->formatBytes((int)$memory_usage);
        }
        
        $peak_memory = $data['peak_memory'] ?? $data['memory_peak'] ?? null;
        if ($peak_memory !== null && is_numeric($peak_memory)) {
            $memory_data['Peak Usage'] = $this->formatBytes((int)$peak_memory);
        }
        
        $memory_limit = $data['memory_limit'] ?? null;
        if ($memory_limit !== null && is_numeric($memory_limit)) {
            $memory_data['Memory Limit'] = $this->formatBytes((int)$memory_limit);
        }
        
        $memory_allocated = $data['memory_allocated'] ?? null;
        if ($memory_allocated !== null && is_numeric($memory_allocated)) {
            $memory_data['Allocated'] = $this->formatBytes((int)$memory_allocated);
        }
        
        // Only render if we have meaningful memory data
        if (empty($memory_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($memory_data, 'Memory Usage');
    }

    /**
     * Adapt Database Metrics
     *
     * Transforms database performance data into formatted display.
     * Handles query counts, execution times, and database-specific metrics.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for database metrics or null if no database data found.
     */
    private function adaptDatabaseMetrics(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $db_metrics = [];
        
        // Defensive programming: Check each field individually
        $db_queries = $data['db_queries'] ?? $data['query_count'] ?? null;
        if ($db_queries !== null && is_numeric($db_queries)) {
            $db_metrics['Total Queries'] = (string)$db_queries . ' queries';
        }
        
        $db_time = $data['db_time'] ?? $data['query_time'] ?? null;
        if ($db_time !== null && is_numeric($db_time)) {
            $db_metrics['Query Time'] = $this->formatTime((float)$db_time);
        }
        
        $slow_queries = $data['slow_queries'] ?? null;
        if ($slow_queries !== null && is_numeric($slow_queries)) {
            $db_metrics['Slow Queries'] = (string)$slow_queries . ' queries';
        }
        
        $cache_hits = $data['cache_hits'] ?? null;
        if ($cache_hits !== null && is_numeric($cache_hits)) {
            $db_metrics['Cache Hits'] = (string)$cache_hits;
        }
        
        $cache_misses = $data['cache_misses'] ?? null;
        if ($cache_misses !== null && is_numeric($cache_misses)) {
            $db_metrics['Cache Misses'] = (string)$cache_misses;
        }
        
        // Only render if we have meaningful database metrics
        if (empty($db_metrics)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($db_metrics, 'Database Performance');
    }

    /**
     * Adapt Performance Status Indicators
     *
     * Creates visual status indicators based on performance thresholds.
     * Maps performance values to color-coded status pills.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for status indicators or null if no performance data found.
     */
    private function adaptPerformanceStatusIndicators(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $status_pills = [];
        
        // Execution time status
        $execution_time = $data['execution_time'] ?? $data['time'] ?? null;
        if ($execution_time !== null && is_numeric($execution_time)) {
            $status = $this->getPerformanceStatus('time', (float)$execution_time);
            $status_pills[] = $toolkit->render_status_pill('SPEED: ' . $status['label'], $status['type']);
        }
        
        // Memory status
        $memory_usage = $data['memory_usage'] ?? $data['memory'] ?? null;
        if ($memory_usage !== null && is_numeric($memory_usage)) {
            $status = $this->getPerformanceStatus('memory', (int)$memory_usage);
            $status_pills[] = $toolkit->render_status_pill('MEMORY: ' . $status['label'], $status['type']);
        }
        
        // Query count status
        $db_queries = $data['db_queries'] ?? $data['query_count'] ?? null;
        if ($db_queries !== null && is_numeric($db_queries)) {
            $status = $this->getPerformanceStatus('queries', (int)$db_queries);
            $status_pills[] = $toolkit->render_status_pill('QUERIES: ' . $status['label'], $status['type']);
        }
        
        // Overall performance status
        if (!empty($status_pills)) {
            $overall_status = $this->calculateOverallPerformanceStatus($data);
            $status_pills[] = $toolkit->render_status_pill('OVERALL: ' . $overall_status['label'], $overall_status['type']);
        }
        
        // Only render if we have status indicators
        if (empty($status_pills)) {
            return null;
        }
        
        return implode('', $status_pills);
    }

    /**
     * Adapt System Metrics
     *
     * Transforms system-level performance data into formatted display.
     * Handles server resources, disk usage, and system-wide metrics.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for system metrics or null if no system data found.
     */
    private function adaptSystemMetrics(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $system_data = [];
        
        // Defensive programming: Check each field individually
        $disk_usage = $data['disk_usage'] ?? null;
        if ($disk_usage !== null && is_numeric($disk_usage)) {
            $system_data['Disk Usage'] = $this->formatBytes((int)$disk_usage);
        }
        
        $network_io = $data['network_io'] ?? null;
        if ($network_io !== null && is_numeric($network_io)) {
            $system_data['Network I/O'] = $this->formatBytes((int)$network_io);
        }
        
        $file_handles = $data['file_handles'] ?? null;
        if ($file_handles !== null && is_numeric($file_handles)) {
            $system_data['File Handles'] = (string)$file_handles;
        }
        
        $processes = $data['processes'] ?? null;
        if ($processes !== null && is_numeric($processes)) {
            $system_data['Active Processes'] = (string)$processes;
        }
        
        // Only render if we have meaningful system data
        if (empty($system_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($system_data, 'System Resources');
    }

    /**
     * Adapt Performance Timeline
     *
     * Transforms performance timeline/history data into interactive display.
     * Handles historical metrics and performance trends.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for performance timeline or null if no timeline data found.
     */
    private function adaptPerformanceTimeline(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $timeline = $data['timeline'] ?? $data['history'] ?? $data['metrics_history'] ?? null;
        
        if (!is_array($timeline) || empty($timeline)) {
            return null;
        }
        
        $json_content = json_encode($timeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Performance Timeline', $code_html, [
            'initially_expanded' => false,
            'theme' => 'performance',
            'action_buttons' => [
                [
                    'label' => 'Export Timeline',
                    'action' => 'exportPerformanceTimeline',
                    'icon' => 'dashicons-download'
                ],
                [
                    'label' => 'Analyze Trends',
                    'action' => 'analyzePerformanceTrends',
                    'icon' => 'dashicons-chart-line'
                ]
            ]
        ]);
    }

    /**
     * Adapt Additional Metrics
     *
     * Transforms any additional performance metrics not covered by core categories.
     * Handles custom metrics and vendor-specific performance data.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for additional metrics or null if no additional data found.
     */
    private function adaptAdditionalMetrics(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $core_keys = [
            'execution_time', 'time', 'memory_usage', 'memory', 'peak_memory',
            'memory_peak', 'db_queries', 'query_count', 'db_time', 'query_time',
            'cpu_usage', 'load_average', 'response_time', 'memory_limit',
            'memory_allocated', 'slow_queries', 'cache_hits', 'cache_misses',
            'disk_usage', 'network_io', 'file_handles', 'processes',
            'timeline', 'history', 'metrics_history'
        ];
        
        $additional = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $core_keys, true)) {
                $additional[$key] = $value;
            }
        }
        
        if (empty($additional)) {
            return null;
        }
        
        $json_content = json_encode($additional, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Additional Metrics', $code_html, [
            'initially_expanded' => false,
            'theme' => 'performance',
            'action_buttons' => [
                [
                    'label' => 'Copy Metrics',
                    'action' => 'copyAdditionalMetrics',
                    'icon' => 'dashicons-clipboard'
                ]
            ]
        ]);
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized performance data into JSON format as a fallback.
     * Ensures that all performance data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for fallback data display.
     */
    private function adaptFallbackData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        return $toolkit->render_code_block($json_content, 'json');
    }

    /**
     * Calculate Overall Performance Status
     *
     * Analyzes multiple performance metrics to determine overall system performance.
     * Provides a comprehensive performance assessment.
     *
     * @since 1.0.0
     *
     * @param array $data Raw performance data.
     * @return array Overall status label and type.
     */
    private function calculateOverallPerformanceStatus(array $data): array
    {
        $scores = [];
        
        // Analyze execution time
        $execution_time = $data['execution_time'] ?? $data['time'] ?? null;
        if ($execution_time !== null && is_numeric($execution_time)) {
            $time_status = $this->getPerformanceStatus('time', (float)$execution_time);
            $scores[] = $this->mapStatusToScore($time_status['type']);
        }
        
        // Analyze memory usage
        $memory_usage = $data['memory_usage'] ?? $data['memory'] ?? null;
        if ($memory_usage !== null && is_numeric($memory_usage)) {
            $memory_status = $this->getPerformanceStatus('memory', (int)$memory_usage);
            $scores[] = $this->mapStatusToScore($memory_status['type']);
        }
        
        // Analyze query count
        $db_queries = $data['db_queries'] ?? $data['query_count'] ?? null;
        if ($db_queries !== null && is_numeric($db_queries)) {
            $query_status = $this->getPerformanceStatus('queries', (int)$db_queries);
            $scores[] = $this->mapStatusToScore($query_status['type']);
        }
        
        if (empty($scores)) {
            return ['label' => 'UNKNOWN', 'type' => 'info'];
        }
        
        $average_score = array_sum($scores) / count($scores);
        
        if ($average_score >= 3.5) {
            return ['label' => 'EXCELLENT', 'type' => 'success'];
        } elseif ($average_score >= 2.5) {
            return ['label' => 'GOOD', 'type' => 'success'];
        } elseif ($average_score >= 1.5) {
            return ['label' => 'FAIR', 'type' => 'warning'];
        } else {
            return ['label' => 'POOR', 'type' => 'error'];
        }
    }

    /**
     * Map Status Type to Numeric Score
     *
     * Converts status types to numeric scores for overall performance calculation.
     *
     * @since 1.0.0
     *
     * @param string $status_type Status type.
     * @return float Numeric score.
     */
    private function mapStatusToScore(string $status_type): float
    {
        switch ($status_type) {
            case 'success':
                return 4.0;
            case 'warning':
                return 2.0;
            case 'error':
                return 1.0;
            default:
                return 2.5;
        }
    }

    /**
     * Check if this renderer can handle the provided data
     *
     * @since 1.0.0
     *
     * @param array $data Data to check.
     * @return bool True if this renderer can handle the data.
     */
    public function canHandle(array $data): bool
    {
        // Check for performance-related keys
        $performance_keys = [
            'execution_time', 'time', 'memory_usage', 'memory', 'peak_memory',
            'memory_peak', 'db_queries', 'query_count', 'db_time', 'query_time',
            'cpu_usage', 'load_average', 'performance_metrics'
        ];
        
        foreach ($performance_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract Performance Metrics
     *
     * Extracts and formats core performance metrics from data.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted performance metrics.
     */
    private function extractPerformanceMetrics(array $data): array
    {
        $metrics = [];
        
        if (isset($data['execution_time']) || isset($data['time'])) {
            $execution_time = $data['execution_time'] ?? $data['time'];
            if (is_numeric($execution_time)) {
                $metrics['Execution Time'] = $this->formatTime((float)$execution_time);
            } else {
                $metrics['Execution Time'] = (string)$execution_time;
            }
        }
        
        if (isset($data['cpu_usage'])) {
            $metrics['CPU Usage'] = $data['cpu_usage'] . '%';
        }
        
        if (isset($data['load_average'])) {
            $metrics['Load Average'] = (string)$data['load_average'];
        }
        
        return $metrics;
    }

    /**
     * Extract Memory Metrics
     *
     * Extracts and formats memory-related metrics from data.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted memory metrics.
     */
    private function extractMemoryMetrics(array $data): array
    {
        $metrics = [];
        
        if (isset($data['memory_usage']) || isset($data['memory'])) {
            $memory_usage = $data['memory_usage'] ?? $data['memory'];
            if (is_numeric($memory_usage)) {
                $metrics['Memory Usage'] = $this->formatBytes((int)$memory_usage);
            } else {
                $metrics['Memory Usage'] = (string)$memory_usage;
            }
        }
        
        if (isset($data['peak_memory']) || isset($data['memory_peak'])) {
            $peak_memory = $data['peak_memory'] ?? $data['memory_peak'];
            if (is_numeric($peak_memory)) {
                $metrics['Peak Memory'] = $this->formatBytes((int)$peak_memory);
            } else {
                $metrics['Peak Memory'] = (string)$peak_memory;
            }
        }
        
        return $metrics;
    }

    /**
     * Extract Database Metrics
     *
     * Extracts and formats database-related metrics from data.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted database metrics.
     */
    private function extractDatabaseMetrics(array $data): array
    {
        $metrics = [];
        
        if (isset($data['db_queries']) || isset($data['query_count'])) {
            $db_queries = $data['db_queries'] ?? $data['query_count'];
            $metrics['Database Queries'] = $db_queries . ' queries';
        }
        
        if (isset($data['db_time']) || isset($data['query_time'])) {
            $db_time = $data['db_time'] ?? $data['query_time'];
            if (is_numeric($db_time)) {
                $metrics['Query Time'] = $this->formatTime((float)$db_time);
            } else {
                $metrics['Query Time'] = (string)$db_time;
            }
        }
        
        return $metrics;
    }

    /**
     * Generate Performance Status Pills
     *
     * Creates status pills based on performance thresholds.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Array of status pill data.
     */
    private function generatePerformanceStatusPills(array $data): array
    {
        $pills = [];
        
        // Execution time status
        if (isset($data['execution_time']) || isset($data['time'])) {
            $execution_time = $data['execution_time'] ?? $data['time'];
            if (is_numeric($execution_time)) {
                $status = $this->getPerformanceStatus('time', (float)$execution_time);
                $pills[] = ['label' => 'SPEED: ' . $status['label'], 'type' => $status['type']];
            }
        }
        
        // Memory status
        if (isset($data['memory_usage']) || isset($data['memory'])) {
            $memory_usage = $data['memory_usage'] ?? $data['memory'];
            if (is_numeric($memory_usage)) {
                $status = $this->getPerformanceStatus('memory', (int)$memory_usage);
                $pills[] = ['label' => 'MEMORY: ' . $status['label'], 'type' => $status['type']];
            }
        }
        
        // Query count status
        if (isset($data['db_queries']) || isset($data['query_count'])) {
            $db_queries = $data['db_queries'] ?? $data['query_count'];
            if (is_numeric($db_queries)) {
                $status = $this->getPerformanceStatus('queries', (int)$db_queries);
                $pills[] = ['label' => 'QUERIES: ' . $status['label'], 'type' => $status['type']];
            }
        }
        
        return $pills;
    }

    /**
     * Extract Additional Metrics
     *
     * Extracts any additional performance metrics not covered by core categories.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Additional metrics data.
     */
    private function extractAdditionalMetrics(array $data): array
    {
        $additional = [];
        $core_keys = [
            'execution_time', 'time', 'memory_usage', 'memory', 'peak_memory',
            'memory_peak', 'db_queries', 'query_count', 'db_time', 'query_time',
            'cpu_usage', 'load_average'
        ];
        
        foreach ($data as $key => $value) {
            if (!in_array($key, $core_keys, true)) {
                $additional[$key] = $value;
            }
        }
        
        return $additional;
    }

    /**
     * Get Performance Status
     *
     * Maps performance values to status labels and types.
     *
     * @since 1.0.0
     *
     * @param string $type Metric type.
     * @param float|int $value Metric value.
     * @return array Status label and type.
     */
    private function getPerformanceStatus(string $type, $value): array
    {
        switch ($type) {
            case 'time':
                if ($value < 0.1) return ['label' => 'EXCELLENT', 'type' => 'success'];
                if ($value < 0.5) return ['label' => 'GOOD', 'type' => 'success'];
                if ($value < 1.0) return ['label' => 'FAIR', 'type' => 'warning'];
                return ['label' => 'SLOW', 'type' => 'error'];
                
            case 'memory':
                if ($value < 1048576) return ['label' => 'EXCELLENT', 'type' => 'success']; // < 1MB
                if ($value < 5242880) return ['label' => 'GOOD', 'type' => 'success'];      // < 5MB
                if ($value < 10485760) return ['label' => 'FAIR', 'type' => 'warning'];     // < 10MB
                return ['label' => 'HIGH', 'type' => 'error'];
                
            case 'queries':
                if ($value < 5) return ['label' => 'EXCELLENT', 'type' => 'success'];
                if ($value < 10) return ['label' => 'GOOD', 'type' => 'success'];
                if ($value < 20) return ['label' => 'FAIR', 'type' => 'warning'];
                return ['label' => 'HIGH', 'type' => 'error'];
                
            default:
                return ['label' => 'UNKNOWN', 'type' => 'info'];
        }
    }


    /**
     * Format time value to human readable format
     *
     * @since 1.0.0
     *
     * @param float $time Time in seconds.
     * @return string Formatted time string.
     */
    private function formatTime(float $time): string
    {
        if ($time >= 1.0) {
            return number_format($time, 3) . 's';
        } elseif ($time >= 0.001) {
            return number_format($time * 1000, 2) . 'ms';
        } else {
            return number_format($time * 1000000, 0) . 'μs';
        }
    }

    /**
     * Format bytes to human readable format
     *
     * @since 1.0.0
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' B';
    }

    /**
     * Get performance CSS class based on metric type and value
     *
     * @since 1.0.0
     *
     * @param string $type Metric type (time, memory, queries).
     * @param float|int $value Metric value.
     * @return string CSS class.
     */
    private function getPerformanceClass(string $type, $value): string
    {
        switch ($type) {
            case 'time':
                if ($value < 0.1) return 'odcm-performance-excellent';
                if ($value < 0.5) return 'odcm-performance-good';
                if ($value < 1.0) return 'odcm-performance-fair';
                return 'odcm-performance-poor';
                
            case 'memory':
                if ($value < 1048576) return 'odcm-performance-excellent'; // < 1MB
                if ($value < 5242880) return 'odcm-performance-good';      // < 5MB
                if ($value < 10485760) return 'odcm-performance-fair';     // < 10MB
                return 'odcm-performance-poor';
                
            case 'queries':
                if ($value < 5) return 'odcm-performance-excellent';
                if ($value < 10) return 'odcm-performance-good';
                if ($value < 20) return 'odcm-performance-fair';
                return 'odcm-performance-poor';
                
            default:
                return 'odcm-performance-neutral';
        }
    }
}
