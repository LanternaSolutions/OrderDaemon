<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Adapter Registry
 *
 * Manages the collection of display adapters and provides the appropriate adapter
 * for a given event type.
 *
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.2.0
 */
class AdapterRegistry
{
    /**
     * @var array<string, DisplayAdapter> Registered adapters
     */
    private array $adapters = [];

    /**
     * @var DisplayAdapter|null Fallback adapter
     */
    private ?DisplayAdapter $fallbackAdapter = null;

    /**
     * Register an adapter for a specific event type
     *
     * @param string $eventType The event type
     * @param DisplayAdapter $adapter The adapter instance
     */
    public function registerAdapter(string $eventType, DisplayAdapter $adapter): void
    {
        $this->adapters[$eventType] = $adapter;
    }

    /**
     * Set the fallback adapter
     *
     * @param DisplayAdapter $adapter The fallback adapter
     */
    public function setFallbackAdapter(DisplayAdapter $adapter): void
    {
        $this->fallbackAdapter = $adapter;
    }

    /**
     * Get the appropriate adapter for an event
     *
     * @param string $eventType The event type
     * @param array $payload The event payload (for dynamic adapter selection)
     * @return DisplayAdapter The appropriate adapter
     */
    public function getAdapterForEvent(string $eventType, array $payload): DisplayAdapter
    {
        // Check for exact match first
        if (isset($this->adapters[$eventType])) {
            return $this->adapters[$eventType];
        }

        // Check for pattern matches (e.g., rule_* events)
        foreach ($this->adapters as $pattern => $adapter) {
            if (strpos($pattern, '*') !== false) {
                $regex = str_replace('*', '.*', $pattern);
                if (preg_match('/^' . $regex . '$/', $eventType)) {
                    return $adapter;
                }
            }
        }

        // Fallback to generic adapter if available
        if ($this->fallbackAdapter) {
            return $this->fallbackAdapter;
        }

        // If no fallback is set, use a basic generic adapter
        return new class extends DisplayAdapter {
            protected function extractSpecializedFields(array $payload): array
            {
                return [];
            }
        };
    }

    /**
     * Create a default registry with common adapters
     *
     * @return self Configured registry
     */
    public static function createDefaultRegistry(): self
    {
        $registry = new self();

        // Register rule execution adapter for rule-related events
        $ruleAdapter = new RuleExecutionAdapter();
        $registry->registerAdapter('rule_execution', $ruleAdapter);
        $registry->registerAdapter('rule_*', $ruleAdapter);

        // Set a generic fallback adapter
        $registry->setFallbackAdapter(new class extends DisplayAdapter {
            protected function extractSpecializedFields(array $payload): array
            {
                $fields = [];

                // Extract basic event information
                if (!empty($payload['event_type'])) {
                    $fields['event_type'] = [
                        'label' => 'Event Type',
                        'value' => $payload['event_type'],
                        'section' => 'main'
                    ];
                }

                // Extract status if available
                if (!empty($payload['status'])) {
                    $fields['status'] = [
                        'label' => 'Status',
                        'value' => ucfirst($payload['status']),
                        'section' => 'main'
                    ];
                }

                return $fields;
            }
        });

        return $registry;
    }
}
