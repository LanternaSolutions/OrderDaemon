<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Interface for extracting components from raw payload data
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
interface ComponentExtractorInterface
{
    /**
     * Extract normalized components from raw payload data
     * 
     * @param array $rawPayload Raw payload data from database
     * @param bool $includeDebug Whether to include debug-level components
     * @return array Array of normalized component arrays
     */
    public function extractComponents(array $rawPayload, bool $includeDebug): array;
    
    /**
     * Check if payload contains ProcessLogger format data
     * 
     * @param array $rawPayload Raw payload data
     * @return bool True if payload is ProcessLogger format
     */
    public function isProcessLoggerFormat(array $rawPayload): bool;
    
    /**
     * Create synthetic component from legacy or empty payload
     * 
     * @param array $logEntry Complete log entry from database
     * @return array Single component array
     */
    public function createSyntheticComponent(array $logEntry): array;
}
