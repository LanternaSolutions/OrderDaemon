<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces;

use WC_Order;

/**
 * Interface for a rule Action component.
 *
 * Extends the base ComponentInterface to add the execution logic
 * specific to actions.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces
 * @since   2.0.2
 */
interface ActionInterface extends ComponentInterface
{
    /**
     * Execute the action for the given order and settings.
     *
     * @param WC_Order $order The WooCommerce order object.
     * @param array    $settings The settings for this specific action instance,
     *                         as defined by its settings schema.
     * @return void
     */
    public function execute(WC_Order $order, array $settings): void;
}
