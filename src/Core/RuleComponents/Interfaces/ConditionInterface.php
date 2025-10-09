<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces;

use WC_Order;

/**
 * Interface for a rule Condition component.
 *
 * Extends the base ComponentInterface to add the evaluation logic
 * specific to conditions.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces
 * @since   1.0.0
 */
interface ConditionInterface extends ComponentInterface
{
    /**
     * Evaluate the condition against the given order and settings.
     *
     * @param WC_Order $order The WooCommerce order object.
     * @param array    $settings The settings for this specific condition instance,
     *                         as defined by its settings schema.
     * @return bool True if the condition is met, false otherwise.
     */
    public function evaluate(WC_Order $order, array $settings): bool;
}
