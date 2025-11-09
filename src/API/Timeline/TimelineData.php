<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Immutable value object representing timeline data ready for rendering
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
final class TimelineData
{
    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_PROCESS_GROUP = 'process_group';
    
    public function __construct(
        public readonly int $logId,
        public readonly string $type,
        public readonly array $components,
        public readonly array $metadata = []
    ) {
        if (!in_array($type, [self::TYPE_INDIVIDUAL, self::TYPE_PROCESS_GROUP], true)) {
            throw new \InvalidArgumentException("Invalid timeline type: " . esc_html($type));
        }
        
        if ($logId <= 0) {
            throw new \InvalidArgumentException('Log ID must be positive integer');
        }
        
        // Validate components structure
        foreach ($components as $index => $component) {
            if (!is_array($component)) {
                throw new \InvalidArgumentException("Component at index " . esc_html($index) . " must be an array");
            }
            
            if (!isset($component['event_type'])) {
                throw new \InvalidArgumentException("Component at index " . esc_html($index) . " missing required 'event_type' field");
            }
            
            if (!isset($component['data']) || !is_array($component['data'])) {
                throw new \InvalidArgumentException("Component at index " . esc_html($index) . " missing required 'data' array");
            }
        }
    }
    
    /**
     * Check if this is an individual log entry timeline
     */
    public function isIndividual(): bool
    {
        return $this->type === self::TYPE_INDIVIDUAL;
    }
    
    /**
     * Check if this is a process group timeline
     */
    public function isProcessGroup(): bool
    {
        return $this->type === self::TYPE_PROCESS_GROUP;
    }
    
    /**
     * Get component count
     */
    public function getComponentCount(): int
    {
        return count($this->components);
    }
    
    /**
     * Check if timeline has any components
     */
    public function hasComponents(): bool
    {
        return !empty($this->components);
    }
    
    /**
     * Get metadata value with optional default
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
    
    /**
     * Create individual timeline
     */
    public static function individual(int $logId, array $components, array $metadata = []): self
    {
        return new self($logId, self::TYPE_INDIVIDUAL, $components, $metadata);
    }
    
    /**
     * Create process group timeline
     */
    public static function processGroup(int $logId, array $components, array $metadata = []): self
    {
        return new self($logId, self::TYPE_PROCESS_GROUP, $components, $metadata);
    }
}
