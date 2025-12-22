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
        public array $components,
        public readonly array $metadata = []
    ) {
        if (!in_array($type, [self::TYPE_INDIVIDUAL, self::TYPE_PROCESS_GROUP], true)) {
            throw new \InvalidArgumentException("Invalid timeline type: " . esc_html($type));
        }

        if ($logId <= 0) {
            throw new \InvalidArgumentException('Log ID must be positive integer');
        }

        // Create a new array to store fixed components
        // We can't use references with a readonly property because the final assignment
        // must be of a completely separate value
        $fixedComponents = [];

        // REPLACEMENT: Validate components structure with lenient validation for all events
        foreach ($components as $index => $component) {
            // Enhanced logging for component validation if in debug mode
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                if (isset($component['event_type']) &&
                    (strpos($component['event_type'], 'order_') !== false ||
                     strpos($component['event_type'], 'checkout') !== false ||
                     strpos($component['event_type'], 'completion') !== false)) {
                    error_log('ODCM DEBUG: TimelineData validating order event component: ' . json_encode($component));
                }
            }

            // Create a new component entry for fixing
            $fixedComponent = $component;

            // Basic type validation with auto-fix instead of exception
            if (!is_array($fixedComponent)) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: TimelineData - Component at index ' . $index . ' is not an array, type: ' . gettype($fixedComponent));
                    error_log('ODCM DEBUG: TimelineData - Auto-fixing by converting to array');
                }
                // Auto-fix: Convert non-array to array with original as value
                $fixedComponent = ['event_type' => 'unknown', 'data' => ['value' => $fixedComponent]];
            }

            // Event type validation with auto-fix
            if (!isset($fixedComponent['event_type'])) {
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: TimelineData - Component at index ' . $index . ' missing event_type field');
                    error_log('ODCM DEBUG: Component keys: ' . implode(', ', array_keys($fixedComponent)));
                    error_log('ODCM DEBUG: TimelineData - Auto-fixing by adding default event_type');
                }
                // Auto-fix: Add a default event type
                $fixedComponent['event_type'] = 'unknown';
            }

            // Data validation with auto-fix for ALL components
            if (!isset($fixedComponent['data']) || !is_array($fixedComponent['data'])) {
                // Log the issue for debugging but DO NOT throw an exception
                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: TimelineData - Component at index ' . $index . ' missing or invalid data field. Auto-fixing.');
                    error_log('ODCM DEBUG: Component keys: ' . implode(', ', array_keys($fixedComponent)));
                }

                // If 'data' is missing, use the whole component as a fallback
                // This handles the legacy structure where the component itself might contain all necessary data
                $originalComponent = $fixedComponent;

                // Auto-fix: For flat component structure, wrap the whole component as data
                if (isset($fixedComponent['summary']) || isset($fixedComponent['label']) || isset($fixedComponent['status'])) {
                    // This might be a flat component structure - save original properties
                    $temp = [];
                    foreach ($fixedComponent as $key => $value) {
                        if ($key !== 'data' && $key !== 'event_type') {
                            $temp[$key] = $value;
                        }
                    }
                    // Only add non-empty data if we have something to add
                    if (!empty($temp)) {
                        $fixedComponent['data'] = $temp;
                    } else {
                        // If we couldn't extract fields, use the original component as data but without circular references
                        $componentAsFallbackData = $originalComponent;
                        unset($componentAsFallbackData['data']); // Avoid circular references
                        $fixedComponent['data'] = $componentAsFallbackData;
                    }
                } else {
                    // Simple case: use the original component as data but without circular references
                    $componentAsFallbackData = $originalComponent;
                    unset($componentAsFallbackData['data']); // Avoid circular references
                    $fixedComponent['data'] = $componentAsFallbackData;
                }

                if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                    error_log('ODCM DEBUG: TimelineData - After auto-fix, component data: ' . json_encode($fixedComponent['data']));
                }
            }

            // Add the fixed component to our new array
            $fixedComponents[] = $fixedComponent;
        }

        // Replace the original components with our fixed ones
        // Important: components is intentionally NOT readonly to allow normalization here
        $this->components = $fixedComponents;
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
