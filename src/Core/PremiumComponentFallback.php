<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Premium Component Fallback Handler
 * 
 * This class handles scenarios where existing rules reference premium components
 * that are no longer available (e.g., when pro plugin is deactivated or when
 * components are migrated from free to pro).
 * 
 * It provides graceful degradation and user-friendly messaging for orphaned
 * premium rules, preventing errors and maintaining system stability.
 * 
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.1.1
 */
class PremiumComponentFallback
{
    /**
     * List of components that have been migrated to premium
     * 
     * @var array
     */
    private static array $migrated_components = [
        'conditions' => [
            'source_gateway' => [
                'name' => 'Source Gateway',
                'capability' => 'condition_payment_gateway',
                'migration_version' => '2.2.1'
            ],
            'event_type' => [
                'name' => 'Event Type',
                'capability' => 'trigger_premium',
                'migration_version' => '2.2.1'
            ]
        ],
        'triggers' => [
            // Future migrated triggers will be listed here
        ],
        'actions' => [
            // Future migrated actions will be listed here
        ]
    ];

    /**
     * Initialize the fallback system
     */
    public static function init(): void
    {
        // Hook into rule evaluation to handle missing components
        add_filter('odcm_rule_component_missing', [self::class, 'handle_missing_component'], 10, 3);
        
        // Hook into admin notices to warn about orphaned rules
        add_action('admin_notices', [self::class, 'show_orphaned_rules_notice']);
        
        // Hook into rule builder to show migration notices
        add_filter('odcm_rule_builder_component_status', [self::class, 'mark_migrated_components'], 10, 2);
    }

    /**
     * Handle missing premium components during rule evaluation
     * 
     * @param mixed $result Current result (null if component not found)
     * @param string $component_type Type of component (trigger, condition, action)
     * @param string $component_id ID of the missing component
     * @return mixed
     */
    public static function handle_missing_component($result, string $component_type, string $component_id)
    {
        // Check if this is a known migrated component
        if (self::is_migrated_component($component_type, $component_id)) {
            $component_info = self::get_migrated_component_info($component_type, $component_id);
            
            // Log the fallback action
            if (function_exists('odcm_log_event')) {
                odcm_log_event(
                    sprintf(
                        'Premium component "%s" not available - requires pro plugin',
                        $component_info['name']
                    ),
                    [
                        'component_type' => $component_type,
                        'component_id' => $component_id,
                        'required_capability' => $component_info['capability'],
                        'migration_version' => $component_info['migration_version']
                    ],
                    null,
                    'warning',
                    'premium_component_fallback'
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
        
        return $result;
    }

    /**
     * Show admin notice about orphaned rules
     */
    public static function show_orphaned_rules_notice(): void
    {
        // Only show on relevant admin pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-odcm_order_rule', 'odcm_order_rule'], true)) {
            return;
        }

        // Check if there are any orphaned rules
        $orphaned_rules = self::get_orphaned_rules();
        if (empty($orphaned_rules)) {
            return;
        }

        $count = count($orphaned_rules);
        $message = sprintf(
            /* translators: %d: The number of completion rules that use premium components. */
            _n(
                'Warning: %d completion rule uses premium components that require the pro plugin.',
                'Warning: %d completion rules use premium components that require the pro plugin.',
                $count,
                'order-daemon'
            ),
            $count
        );

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html($message) . '</strong></p>';
        echo '<p>' . esc_html__('These rules will not function until the pro plugin is activated or the rules are updated to use free components.', 'order-daemon') . '</p>';
        echo '</div>';
    }

    /**
     * Mark migrated components in the rule builder
     * 
     * @param array $component_status Current component status
     * @param array $component Component data
     * @return array Modified component status
     */
    public static function mark_migrated_components(array $component_status, array $component): array
    {
        $component_type = $component['type'] ?? '';
        $component_id = $component['id'] ?? '';
        
        if (self::is_migrated_component($component_type, $component_id)) {
            $component_status['is_migrated'] = true;
            $component_status['migration_notice'] = sprintf(
                /* translators: %s: The version number when the component was moved to the pro plugin. */
                __('This component has been moved to the pro plugin as of version %s.', 'order-daemon'),
                self::get_migrated_component_info($component_type, $component_id)['migration_version']
            );
        }
        
        return $component_status;
    }

    /**
     * Check if a component has been migrated to premium
     * 
     * @param string $component_type
     * @param string $component_id
     * @return bool
     */
    private static function is_migrated_component(string $component_type, string $component_id): bool
    {
        $type_key = rtrim($component_type, 's') . 's'; // Normalize to plural
        return isset(self::$migrated_components[$type_key][$component_id]);
    }

    /**
     * Get information about a migrated component
     * 
     * @param string $component_type
     * @param string $component_id
     * @return array|null
     */
    private static function get_migrated_component_info(string $component_type, string $component_id): ?array
    {
        $type_key = rtrim($component_type, 's') . 's'; // Normalize to plural
        return self::$migrated_components[$type_key][$component_id] ?? null;
    }

    /**
     * Get list of rules that use orphaned premium components
     * 
     * @return array Array of post IDs for orphaned rules
     */
    private static function get_orphaned_rules(): array
    {
        global $wpdb;
        
        $orphaned_rules = [];
        
        // Query all completion rules
        $rules = get_posts([
            'post_type' => 'odcm_order_rule',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_odcm_trigger',
                    'value' => array_keys(self::$migrated_components['triggers']),
                    'compare' => 'IN'
                ],
                [
                    'key' => '_odcm_conditions',
                    'value' => array_keys(self::$migrated_components['conditions']),
                    'compare' => 'REGEXP'
                ],
                [
                    'key' => '_odcm_actions',
                    'value' => array_keys(self::$migrated_components['actions']),
                    'compare' => 'REGEXP'
                ]
            ]
        ]);
        
        foreach ($rules as $rule) {
            $has_orphaned_components = false;
            
            // Check trigger
            $trigger = get_post_meta($rule->ID, '_odcm_trigger', true);
            if ($trigger && self::is_migrated_component('triggers', $trigger)) {
                $has_orphaned_components = true;
            }
            
            // Check conditions
            $conditions = get_post_meta($rule->ID, '_odcm_conditions', true);
            if (is_array($conditions)) {
                foreach ($conditions as $condition) {
                    if (isset($condition['type']) && self::is_migrated_component('conditions', $condition['type'])) {
                        $has_orphaned_components = true;
                        break;
                    }
                }
            }
            
            // Check actions
            $actions = get_post_meta($rule->ID, '_odcm_actions', true);
            if (is_array($actions)) {
                foreach ($actions as $action) {
                    if (isset($action['type']) && self::is_migrated_component('actions', $action['type'])) {
                        $has_orphaned_components = true;
                        break;
                    }
                }
            }
            
            if ($has_orphaned_components) {
                $orphaned_rules[] = $rule->ID;
            }
        }
        
        return $orphaned_rules;
    }
}
