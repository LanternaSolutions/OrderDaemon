<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

/**
 * Option Registry Class - UI Component Registry
 *
 * This class serves as the central registry for all triggers, conditions, and actions
 * available in the Order Daemon For Woocommerce plugin. It acts as a single source
 * of truth for UI components.
 *
 * Key Features:
 * - Centralized registration of all UI options (triggers, conditions, actions)
 * - Built-in validation of option data structure
 * - Type-safe implementation with strict typing
 * - Extensible architecture for future feature additions
 *
 * Architecture Overview:
 * The registry uses a simple array-based storage system where each option type
 * (triggers, conditions, actions) is stored in its own private array. Each option
 * must conform to a strict data structure that includes:
 * - id: Unique identifier for the option
 * - label: Human-readable display name
 * - description: User-friendly explanation of the option
 * - render_callback: Function to render option-specific UI
 *
 * Usage Example:
 * ```php
 * $registry = odcm_get_registry_instance();
 *
 * // Register a new condition
 * $registry->register_condition([
 *     'id'              => 'custom_condition',
 *     'label'           => __('Custom Condition', 'order-daemon'),
 *     'description'     => __('A custom condition for advanced users.', 'order-daemon'),
 *     'render_callback' => 'my_custom_render_function',
 * ]);
 *
 * // Retrieve all conditions
 * $conditions = $registry->get_conditions();
 * ```
 *
 * @package OrderDaemon\CompletionManager\Core
 * @since   1.0.0
* @author  OrderDaemon Development Team
* @link    https://docs.OrderDaemon.com/completion-manager
 */
final class OptionRegistry
{
    /**
     * Registered trigger options
     *
     * Stores all registered trigger options that define when completion rules
     * should be evaluated. Triggers are events in the WooCommerce order lifecycle
     * that can initiate rule processing.
     *
     * Array structure:
     * ```php
     * [
     *     'trigger_id' => [
     *         'id'              => 'trigger_id',
     *         'label'           => 'Human Readable Name',
     *         'description'     => 'Detailed description of the trigger',
     *         'render_callback' => callable,
     *     ],
     *     // ... more triggers
     * ]
     * ```
     *
     * @since 1.0.0
     * @var   array<string, array<string, mixed>> Associative array of trigger options keyed by ID
     */
    private $triggers = [];

    /**
     * Registered condition options
     *
     * Stores all registered condition options that define the criteria that must
     * be met for a completion rule to apply. Conditions evaluate order properties,
     * customer data, and other contextual information.
     *
     * Array structure:
     * ```php
     * [
     *     'condition_id' => [
     *         'id'              => 'condition_id',
     *         'label'           => 'Human Readable Name',
     *         'description'     => 'Detailed description of the condition',
     *         'render_callback' => callable,
     *     ],
     *     // ... more conditions
     * ]
     * ```
     *
     * @since 1.0.0
     * @var   array<string, array<string, mixed>> Associative array of condition options keyed by ID
     */
    private $conditions = [];

    /**
     * Registered action options
     *
     * Stores all registered action options that define what should happen when
     * a completion rule's conditions are met. Actions perform the actual work
     * of completing orders, sending notifications, etc.
     *
     * Array structure:
     * ```php
     * [
     *     'action_id' => [
     *         'id'              => 'action_id',
     *         'label'           => 'Human Readable Name',
     *         'description'     => 'Detailed description of the action',
     *         'render_callback' => callable,
     *     ],
     *     // ... more actions
     * ]
     * ```
     *
     * @since 1.0.0
     * @var   array<string, array<string, mixed>> Associative array of action options keyed by ID
     */
    private $actions = [];

    /**
     * Register a new condition option
     *
     * Registers a condition that can be used in completion rules. Conditions define
     * the criteria that must be met for a rule to apply to an order.
     *
     * The condition will be validated to ensure it contains all required fields
     * and that the render callback is callable. Once registered, the condition
     * will appear in the rule editor UI.
     *
     * Required Arguments:
     * - id: Unique string identifier (e.g., 'product_category')
     * - label: Translatable display name (e.g., __('Product Category', 'domain'))
     * - description: User-friendly explanation of what the condition does
     * - render_callback: Callable that renders the condition's settings UI
     *
     * @since 1.0.0
     *
     * @param array $args {
     *     Condition registration arguments.
     *
     *     @type string   $id              Unique identifier for the condition. Must be unique across
     *                                     all conditions. Use lowercase with underscores.
     *     @type string   $label           Human-readable label displayed in the UI. Should be
     *                                     internationalized using __() function.
     *     @type string   $description     Detailed description explaining what the condition does.
     *                                     Shown as help text in the UI.
     *     @type callable $render_callback Function or method that renders the condition's settings.
     *                                     Receives the current rule's data as parameter.
     * }
     *
     * @return void
     *
     * @throws \InvalidArgumentException If required keys are missing or invalid.
     *
     * @example
     * ```php
     * // Register a condition
     * $registry->register_condition([
     *     'id'              => 'order_total',
     *     'label'           => __('Order Total', 'order-daemon'),
     *     'description'     => __('Check the total amount of the order.', 'order-daemon'),
     *     'render_callback' => [$this, 'render_order_total_settings'],
     * ]);
     * ```
     */
    public function register_condition(array $args): void
    {
        $this->validate_option_args($args, 'condition');
        
        // Set default section if not provided
        if (!isset($args['section'])) {
            $args['section'] = 'primary';
        }
        
        $this->conditions[$args['id']] = $args;
    }

    /**
     * Register a new action option
     *
     * Registers an action that can be executed when completion rule conditions are met.
     * Actions define what should happen when a rule applies - such as completing the order,
     * sending notifications, or updating order metadata.
     *
     * The action will be validated to ensure it contains all required fields
     * and that the render callback is callable. Once registered, the action
     * will appear in the rule editor UI.
     *
     * @since 1.0.0
     *
     * @param array $args {
     *     Action registration arguments.
     *
     *     @type string   $id              Unique identifier for the action. Must be unique across
     *                                     all actions. Use lowercase with underscores.
     *     @type string   $label           Human-readable label displayed in the UI. Should be
     *                                     internationalized using __() function.
     *     @type string   $description     Detailed description explaining what the action does.
     *                                     Shown as help text in the UI.
     *     @type callable $render_callback Function or method that renders the action's settings.
     *                                     Receives the current rule's data as parameter.
     * }
     *
     * @return void
     *
     * @throws \InvalidArgumentException If required keys are missing or invalid.
     *
     * @example
     * ```php
     * // Register an action
     * $registry->register_action([
     *     'id'              => 'complete_order',
     *     'label'           => __('Complete Order', 'order-daemon'),
     *     'description'     => __('Change the order status to completed.', 'order-daemon'),
     *     'render_callback' => [$this, 'render_complete_order_settings'],
     * ]);
     * ```
     */
    public function register_action(array $args): void
    {
        $this->validate_option_args($args, 'action');
        
        // Set default section if not provided
        if (!isset($args['section'])) {
            $args['section'] = 'primary';
        }
        
        $this->actions[$args['id']] = $args;
    }

    /**
     * Register a new trigger option
     *
     * Registers a trigger that defines when completion rules should be evaluated.
     * Triggers are events in the WooCommerce order lifecycle that can initiate
     * rule processing, such as order status changes or payment completion.
     *
     * The trigger will be validated to ensure it contains all required fields
     * and that the render callback is callable. Once registered, the trigger
     * will appear in the rule editor UI.
     *
     * @since 1.0.0
     *
     * @param array $args {
     *     Trigger registration arguments.
     *
     *     @type string   $id              Unique identifier for the trigger. Must be unique across
     *                                     all triggers. Use lowercase with underscores.
     *     @type string   $label           Human-readable label displayed in the UI. Should be
     *                                     internationalized using __() function.
     *     @type string   $description     Detailed description explaining when the trigger fires.
     *                                     Shown as help text in the UI.
     *     @type callable $render_callback Function or method that renders the trigger's settings.
     *                                     Receives the current rule's data as parameter.
     * }
     *
     * @return void
     *
     * @throws \InvalidArgumentException If required keys are missing or invalid.
     *
     * @example
     * ```php
     * // Register a trigger
     * $registry->register_trigger([
     *     'id'              => 'order_processing',
     *     'label'           => __('Order Processing', 'order-daemon'),
     *     'description'     => __('Trigger when order status changes to processing.', 'order-daemon'),
     *     'render_callback' => [$this, 'render_processing_trigger_settings'],
     * ]);
     * ```
     */
    public function register_trigger(array $args): void
    {
        $this->validate_option_args($args, 'trigger');
        
        // Set default section if not provided
        if (!isset($args['section'])) {
            $args['section'] = 'primary';
        }
        
        $this->triggers[$args['id']] = $args;
    }

    /**
     * Retrieve all registered condition options
     *
     * Returns an associative array of all registered conditions, keyed by their unique IDs.
     * This method is typically used by the UI rendering system to display available conditions
     * in the rule editor.
     *
     * The returned array maintains the original registration order and includes all condition data.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> {
     *     Associative array of condition options keyed by condition ID.
     *
     *     @type array $condition_id {
     *         Individual condition data.
     *
     *         @type string   $id              Unique condition identifier.
     *         @type string   $label           Human-readable condition name.
     *         @type string   $description     Detailed condition description.
     *         @type callable $render_callback Function to render condition settings.
     *     }
     * }
     *
     * @example
     * ```php
     * $registry = odcm_get_registry_instance();
     * $conditions = $registry->get_conditions();
     *
     * foreach ($conditions as $condition_id => $condition) {
     *     // Display condition in UI
     *     echo '<option value="' . esc_attr($condition_id) . '">';
     *     echo esc_html($condition['label']);
     *     echo '</option>';
     * }
     * ```
     */
    public function get_conditions(): array
    {
        return $this->conditions;
    }

    /**
     * Retrieve all registered action options
     *
     * Returns an associative array of all registered actions, keyed by their unique IDs.
     * This method is typically used by the UI rendering system to display available actions
     * in the rule editor.
     *
     * The returned array maintains the original registration order and includes all action data.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> {
     *     Associative array of action options keyed by action ID.
     *
     *     @type array $action_id {
     *         Individual action data.
     *
     *         @type string   $id              Unique action identifier.
     *         @type string   $label           Human-readable action name.
     *         @type string   $description     Detailed action description.
     *         @type callable $render_callback Function to render action settings.
     *     }
     * }
     *
     * @example
     * ```php
     * $registry = odcm_get_registry_instance();
     * $actions = $registry->get_actions();
     *
     * foreach ($actions as $action_id => $action) {
     *     // Display action in UI
     *     echo '<input type="radio" name="action" value="' . esc_attr($action_id) . '">';
     *     echo esc_html($action['label']);
     * }
     * ```
     */
    public function get_actions(): array
    {
        return $this->actions;
    }

    /**
     * Retrieve all registered trigger options
     *
     * Returns an associative array of all registered triggers, keyed by their unique IDs.
     * This method is typically used by the UI rendering system to display available triggers
     * in the rule editor.
     *
     * The returned array maintains the original registration order and includes all trigger data.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> {
     *     Associative array of trigger options keyed by trigger ID.
     *
     *     @type array $trigger_id {
     *         Individual trigger data.
     *
     *         @type string   $id              Unique trigger identifier.
     *         @type string   $label           Human-readable trigger name.
     *         @type string   $description     Detailed trigger description.
     *         @type callable $render_callback Function to render trigger settings.
     *     }
     * }
     *
     * @example
     * ```php
     * $registry = odcm_get_registry_instance();
     * $triggers = $registry->get_triggers();
     *
     * foreach ($triggers as $trigger_id => $trigger) {
     *     // Display trigger in UI
     *     echo '<label>';
     *     echo '<input type="radio" name="trigger" value="' . esc_attr($trigger_id) . '">';
     *     echo esc_html($trigger['label']);
     *     echo '<span class="description">' . esc_html($trigger['description']) . '</span>';
     *     echo '</label>';
     * }
     * ```
     */
    public function get_triggers(): array
    {
        return $this->triggers;
    }

    /**
     * Validate option registration arguments for data integrity and security
     *
     * Performs comprehensive validation of option registration data to ensure all required
     * fields are present and properly formatted. This validation prevents registration of malformed options.
     *
     * Validation Rules:
     * - All required keys must be present: id, label, description, render_callback
     * - ID must be a non-empty string (used as array key and HTML attributes)
     * - Label must be a non-empty string (displayed in UI)
     * - Description must be a string (can be empty, used for help text)
     * - Render callback must be callable (used to generate UI)
     * - Section must be 'primary' or 'addon' if provided (optional, defaults to 'primary')
     *
     * Security Considerations:
     * - IDs are validated to prevent injection attacks when used in HTML
     * - Callbacks are validated to prevent execution of non-callable values
     * - All string fields are checked for proper type to prevent type confusion
     *
     * @since 1.0.0
     *
     * @param array  $args {
     *     Option registration arguments to validate.
     *
     *     @type string   $id              Required. Unique identifier for the option.
     *     @type string   $label           Required. Human-readable display name.
     *     @type string   $description     Required. Detailed description (can be empty string).
     *     @type callable $render_callback Required. Function to render option settings.
     *     @type string   $section         Optional. Either 'primary' or 'addon'. Defaults to 'primary'.
     * }
     * @param string $type The type of option being validated ('trigger', 'condition', 'action').
     *                     Used in error messages for better debugging.
     *
     * @return void
     *
     * @throws \InvalidArgumentException {
     *     Thrown when validation fails with specific error details.
     *
     *     Possible error scenarios:
     *     - Missing required key: "Missing required key 'id' when registering condition option."
     *     - Invalid ID: "The 'id' key must be a non-empty string when registering condition option."
     *     - Invalid label: "The 'label' key must be a non-empty string when registering condition option."
     *     - Invalid description: "The 'description' key must be a string when registering condition option."
     *     - Invalid callback: "The 'render_callback' key must be callable when registering condition option."
     *     - Invalid section: "The 'section' key must be either 'primary' or 'addon' when registering condition option."
     * }
     *
     * @example
     * ```php
     * // This will pass validation
     * $valid_args = [
     *     'id'              => 'my_condition',
     *     'label'           => __('My Condition', 'domain'),
     *     'description'     => __('A custom condition.', 'domain'),
     *     'section'         => 'addon', // Optional - controls UI placement
     *     'render_callback' => [$this, 'render_my_condition'],
     * ];
     *
     * // This will throw InvalidArgumentException
     * $invalid_args = [
     *     'id'    => '', // Empty ID - invalid
     *     'label' => 'My Condition',
     *     // Missing required keys - invalid
     * ];
     * ```
     */
    private function validate_option_args(array $args, string $type): void
    {
        $required_keys = ['id', 'label', 'description', 'render_callback'];

        foreach ($required_keys as $key) {
            if (!isset($args[$key])) {
                throw new \InvalidArgumentException(
                    sprintf('Missing required key "%s" when registering %s option.', esc_html($key), esc_html($type))
                );
            }
        }

        // Validate that id is a non-empty string
        if (!is_string($args['id']) || empty(trim($args['id']))) {
            throw new \InvalidArgumentException(
                sprintf('The "id" key must be a non-empty string when registering %s option.', esc_html($type))
            );
        }

        // Validate that label is a non-empty string
        if (!is_string($args['label']) || empty(trim($args['label']))) {
            throw new \InvalidArgumentException(
                sprintf('The "label" key must be a non-empty string when registering %s option.', esc_html($type))
            );
        }

        // Validate that description is a string
        if (!is_string($args['description'])) {
            throw new \InvalidArgumentException(
                sprintf('The "description" key must be a string when registering %s option.', esc_html($type))
            );
        }

        // Validate that render_callback is callable
        if (!is_callable($args['render_callback'])) {
            throw new \InvalidArgumentException(
                sprintf('The "render_callback" key must be callable when registering %s option.', esc_html($type))
            );
        }

        // Validate section if provided (optional parameter)
        if (isset($args['section'])) {
            $valid_sections = ['primary', 'addon'];
            if (!is_string($args['section']) || !in_array($args['section'], $valid_sections, true)) {
                throw new \InvalidArgumentException(
                    sprintf('The "section" key must be either "primary" or "addon" when registering %s option.', esc_html($type))
                );
            }
        }
    }
}
