<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces;

/**
 * Interface for a rule Trigger component.
 *
 * This interface extends the base ComponentInterface and defines methods
 * for trigger evaluation. Simple triggers can provide default implementations,
 * while complex triggers like AnyStatusChangeTrigger can provide custom logic.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces
 * @since   1.0.0
 */
interface TriggerInterface extends ComponentInterface
{
    /**
     * Check if this trigger should fire for the given context.
     * 
     * Simple triggers that don't need custom evaluation logic can return true.
     * Complex triggers can implement custom evaluation based on their settings.
     *
     * @param array $context Context information about the trigger event.
     * @param array $settings The trigger settings from the rule.
     * @return bool True if the trigger should fire, false otherwise.
     */
    public function should_trigger(array $context, array $settings = []): bool;
}
