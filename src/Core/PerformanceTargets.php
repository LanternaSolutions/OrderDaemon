<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Performance Targets Configuration
 *
 * This class defines centralized performance targets for the Order Daemon plugin.
 * It serves as the single source of truth for all performance-related thresholds
 * and benchmarks used throughout the system.
 *
 * ARCHITECTURAL ROLE:
 * ==================
 * 
 * This configuration class provides:
 * - Centralized performance target definitions
 * - Consistent benchmarks across all components
 * - Easy maintenance and updates of performance criteria
 * - Integration with monitoring and alerting systems
 * 
 * USAGE PATTERNS:
 * ==============
 * 
 * 1. Query Performance Monitoring:
 *    if ($execution_time > PerformanceTargets::QUERY_EXECUTION_TARGET) {
 *        // Log slow query alert
 *    }
 * 
 * 2. Cache Effectiveness Validation:
 *    if ($cache_hit_ratio < PerformanceTargets::CACHE_HIT_RATIO_TARGET) {
 *        // Optimize caching strategy
 *    }
 * 
 * 3. Page Load Performance:
 *    if ($page_load_time > PerformanceTargets::PAGE_LOAD_TARGET) {
 *        // Investigate performance bottlenecks
 *    }
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
 */
final class PerformanceTargets
{
    /**
     * Maximum acceptable page load time in seconds.
     * 
     * This target applies to admin pages including the audit trail interface.
     * Pages exceeding this threshold should trigger performance optimization.
     */
    public const PAGE_LOAD_TARGET = 2.0;

    /**
     * Maximum acceptable filter response time in seconds.
     * 
     * This target applies to AJAX requests for filter processing and
     * real-time search functionality in the audit trail.
     */
    public const FILTER_RESPONSE_TARGET = 0.5;

    /**
     * Maximum acceptable database query execution time in seconds.
     * 
     * Individual database queries exceeding this threshold are considered
     * slow and should trigger optimization alerts.
     */
    public const QUERY_EXECUTION_TARGET = 0.1;

    /**
     * Maximum acceptable slow query threshold in seconds.
     * 
     * Queries exceeding this threshold trigger slow query alerts and
     * are logged for performance analysis.
     */
    public const SLOW_QUERY_THRESHOLD = 0.5;

    /**
     * Minimum acceptable cache hit ratio (0.0 to 1.0).
     * 
     * Cache hit ratios below this threshold indicate ineffective caching
     * and should trigger cache strategy optimization.
     */
    public const CACHE_HIT_RATIO_TARGET = 0.8;

    /**
     * Maximum acceptable memory usage in MB for single operations.
     * 
     * Operations exceeding this memory threshold should be optimized
     * or broken into smaller chunks.
     */
    public const MEMORY_USAGE_TARGET = 64;

    /**
     * Maximum number of database queries per page load.
     * 
     * Pages exceeding this query count may have N+1 query problems
     * or inefficient data loading patterns.
     */
    public const MAX_QUERIES_PER_PAGE = 20;

    /**
     * Cache duration for audit log queries in seconds.
     * 
     * Short-lived cache to balance real-time data with performance.
     */
    public const AUDIT_CACHE_DURATION = 30;

    /**
     * Maximum number of log entries to process in a single batch.
     * 
     * Larger batches may cause memory or timeout issues.
     */
    public const MAX_BATCH_SIZE = 1000;

    /**
     * Target response time for CLI commands in seconds.
     * 
     * CLI operations exceeding this threshold should provide
     * progress feedback to users.
     */
    public const CLI_RESPONSE_TARGET = 5.0;

    /**
     * Maximum acceptable export file generation time in seconds.
     * 
     * Export operations exceeding this threshold should use
     * background processing.
     */
    public const EXPORT_GENERATION_TARGET = 10.0;

    /**
     * Get all performance targets as an associative array.
     * 
     * Useful for configuration displays, monitoring dashboards,
     * and automated performance testing.
     *
     * @return array<string, float|int> Array of target names and values
     */
    public static function getAllTargets(): array
    {
        return [
            'page_load_target' => self::PAGE_LOAD_TARGET,
            'filter_response_target' => self::FILTER_RESPONSE_TARGET,
            'query_execution_target' => self::QUERY_EXECUTION_TARGET,
            'slow_query_threshold' => self::SLOW_QUERY_THRESHOLD,
            'cache_hit_ratio_target' => self::CACHE_HIT_RATIO_TARGET,
            'memory_usage_target' => self::MEMORY_USAGE_TARGET,
            'max_queries_per_page' => self::MAX_QUERIES_PER_PAGE,
            'audit_cache_duration' => self::AUDIT_CACHE_DURATION,
            'max_batch_size' => self::MAX_BATCH_SIZE,
            'cli_response_target' => self::CLI_RESPONSE_TARGET,
            'export_generation_target' => self::EXPORT_GENERATION_TARGET,
        ];
    }

    /**
     * Check if a given execution time meets the target for a specific operation.
     *
     * @param float  $execution_time The actual execution time in seconds
     * @param string $operation_type The type of operation ('query', 'filter', 'page_load', etc.)
     * @return bool True if the execution time meets the target, false otherwise
     */
    public static function meetsTarget(float $execution_time, string $operation_type): bool
    {
        switch ($operation_type) {
            case 'query':
                return $execution_time <= self::QUERY_EXECUTION_TARGET;
            case 'filter':
                return $execution_time <= self::FILTER_RESPONSE_TARGET;
            case 'page_load':
                return $execution_time <= self::PAGE_LOAD_TARGET;
            case 'cli':
                return $execution_time <= self::CLI_RESPONSE_TARGET;
            case 'export':
                return $execution_time <= self::EXPORT_GENERATION_TARGET;
            default:
                return $execution_time <= self::QUERY_EXECUTION_TARGET; // Default to query target
        }
    }

    /**
     * Get the target threshold for a specific operation type.
     *
     * @param string $operation_type The type of operation
     * @return float The target threshold in seconds
     */
    public static function getTarget(string $operation_type): float
    {
        switch ($operation_type) {
            case 'query':
                return self::QUERY_EXECUTION_TARGET;
            case 'filter':
                return self::FILTER_RESPONSE_TARGET;
            case 'page_load':
                return self::PAGE_LOAD_TARGET;
            case 'cli':
                return self::CLI_RESPONSE_TARGET;
            case 'export':
                return self::EXPORT_GENERATION_TARGET;
            case 'slow_query':
                return self::SLOW_QUERY_THRESHOLD;
            default:
                return self::QUERY_EXECUTION_TARGET;
        }
    }

    /**
     * Format execution time with performance status indicator.
     *
     * @param float  $execution_time The actual execution time in seconds
     * @param string $operation_type The type of operation
     * @return string Formatted string with performance indicator
     */
    public static function formatWithStatus(float $execution_time, string $operation_type): string
    {
        $target = self::getTarget($operation_type);
        $meets_target = $execution_time <= $target;
        $status = $meets_target ? '✅' : '❌';
        
        return sprintf(
            '%s %.3fs (target: %.3fs)',
            $status,
            $execution_time,
            $target
        );
    }

    /**
     * Get performance grade based on execution time vs target.
     *
     * @param float  $execution_time The actual execution time in seconds
     * @param string $operation_type The type of operation
     * @return string Performance grade (A, B, C, D, F)
     */
    public static function getPerformanceGrade(float $execution_time, string $operation_type): string
    {
        $target = self::getTarget($operation_type);
        $ratio = $execution_time / $target;

        if ($ratio <= 0.5) {
            return 'A'; // Excellent - 50% or better than target
        } elseif ($ratio <= 0.8) {
            return 'B'; // Good - 80% or better than target
        } elseif ($ratio <= 1.0) {
            return 'C'; // Acceptable - meets target
        } elseif ($ratio <= 1.5) {
            return 'D'; // Poor - 50% over target
        } else {
            return 'F'; // Failing - more than 50% over target
        }
    }
}
