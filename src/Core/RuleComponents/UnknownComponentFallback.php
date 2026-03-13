<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponent;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Unknown Component Fallback Handler
 *
 * This class handles scenarios where existing rules reference components
 * that are not registered in the current plugin version. It provides graceful
 * degradation by safely handling unknown components without breaking rule execution.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponent
 * @since   1.1.1
 */
class UnknownComponentFallback
{
    /**
     * Initialize the fallback system
     */
    public static function init(): void
    {
        // Hook into rule evaluation to handle missing components
        add_filter('odcm_rule_component_missing', [self::class, 'handle_missing_component'], 10, 3);

        // Hook into validation to allow unknown components to be saved neutrally
        add_filter('odcm_allow_unknown_component', [self::class, 'allow_unknown_component'], 10, 3);
    }

    /**
     * Handle missing components during rule evaluation
     *
     * @param mixed $result Current result (null if component not found)
     * @param string $component_type Type of component (trigger, condition, action)
     * @param string $component_id ID of the missing component
     * @return mixed
     */
    public static function handle_missing_component($result, string $component_type, string $component_id)
    {
        // Log that an unknown component was encountered
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('Unknown component "%s" of type "%s" encountered', $component_id, $component_type),
                [
                    'component_type' => $component_type,
                    'component_id' => $component_id,
                    'context' => 'component_fallback'
                ],
                null,
                'info',
                'component_fallback'
            );
        }

        // Return appropriate fallback based on component type
        switch ($component_type) {
            case 'condition':
                // For conditions, return false (condition not met)
                return false;

            case 'trigger':
                // For triggers, return null (don't process)
                return null;

            case 'action':
                // For actions, return false (action not executed)
                return false;

            default:
                return $result;
        }
    }

    /**
     * Allow unknown components to be saved neutrally
     *
     * This method determines whether unknown components (presumably from extensions) should be
     * allowed to be saved without throwing validation errors.
     *
     * @param bool $allow_default Default allow value
     * @param string $component_type Type of component (trigger, condition, action)
     * @param string $component_id ID of the missing component
     * @return bool True if unknown component should be allowed, false otherwise
     */
    public static function allow_unknown_component(bool $allow_default, string $component_type, string $component_id): bool
    {
        // Allow unknown components to be saved neutrally
        // This preserves rules that contain unknown components when they are inactive or removed.
        return true;
    }
}
