<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\RuleComponents;

use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ComponentInterface;
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\TriggerInterface;
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ActionInterface;

/**
 * Auto-discovering registry for Rule Builder components.
 *
 * This class scans specified directories for component classes, instantiates them,
 * and makes them available to the rest of the application. This allows for a
 * "pluggable" architecture where new components can be added simply by creating
 * a new class file.
 *
 * @package OrderDaemon\CompletionManager\Core\RuleComponents
 * @since   1.0.0
 */
final class RuleComponentRegistry
{
    /**
     * @var TriggerInterface[]
     */
    private array $triggers = [];

    /**
     * @var ConditionInterface[]
     */
    private array $conditions = [];

    /**
     * @var ActionInterface[]
     */
    private array $actions = [];

    private bool $is_loaded = false;

    /**
     * @var RuleComponentRegistry|null
     */
    private static ?RuleComponentRegistry $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return RuleComponentRegistry
     */
    public static function instance(): RuleComponentRegistry
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get all registered trigger components.
     *
     * @return TriggerInterface[]
     */
    public function get_triggers(): array
    {
        $this->load_components();
        
        // Apply filter to allow extension plugins to add triggers
        $triggers = apply_filters('odcm_rule_builder_triggers', $this->triggers);
        
        // Ensure we only have valid trigger interfaces
        return array_filter($triggers, function($trigger) {
            return $trigger instanceof TriggerInterface;
        });
    }

    /**
     * Get all registered condition components.
     *
     * @return ConditionInterface[]
     */
    public function get_conditions(): array
    {
        $this->load_components();
        
        // Apply filter to allow extension plugins to add conditions
        $conditions = apply_filters('odcm_rule_builder_conditions', $this->conditions);
        
        // Ensure we only have valid condition interfaces
        return array_filter($conditions, function($condition) {
            return $condition instanceof ConditionInterface;
        });
    }

    /**
     * Get all registered action components.
     *
     * @return ActionInterface[]
     */
    public function get_actions(): array
    {
        $this->load_components();
        
        // Apply filter to allow extension plugins to add actions
        $actions = apply_filters('odcm_rule_builder_actions', $this->actions);
        
        // Ensure we only have valid action interfaces
        return array_filter($actions, function($action) {
            return $action instanceof ActionInterface;
        });
    }

    /**
     * Get a specific trigger component by ID.
     *
     * @param string $trigger_id The trigger component ID.
     * @return TriggerInterface|null The trigger component or null if not found.
     */
    public function get_trigger(string $trigger_id): ?TriggerInterface
    {
        $this->load_components();
        return $this->triggers[$trigger_id] ?? null;
    }

    /**
     * Get a specific condition component by ID.
     *
     * @param string $condition_id The condition component ID.
     * @return ConditionInterface|null The condition component or null if not found.
     */
    public function get_condition(string $condition_id): ?ConditionInterface
    {
        $this->load_components();
        return $this->conditions[$condition_id] ?? null;
    }

    /**
     * Get a specific action component by ID.
     *
     * @param string $action_id The action component ID.
     * @return ActionInterface|null The action component or null if not found.
     */
    public function get_action(string $action_id): ?ActionInterface
    {
        $this->load_components();
        return $this->actions[$action_id] ?? null;
    }

    /**
     * Scans directories and loads all found components.
     *
     * This method is called lazily only when components are first requested.
     * It scans the pre-defined component directories, includes the files,
     * and attempts to register any classes that implement the correct interfaces.
     */
    private function load_components(): void
    {
        if ($this->is_loaded) {
            if (function_exists('odcm_log_message') && apply_filters('odcm_debug_enabled', defined('ODCM_DEBUG') && ODCM_DEBUG)) {
                odcm_log_message('Components already loaded, skipping', 'debug');
            }
            return;
        }

        if (function_exists('odcm_log_message') && apply_filters('odcm_debug_enabled', defined('ODCM_DEBUG') && ODCM_DEBUG)) {
            odcm_log_message('Starting component loading...', 'debug');
            $base_path = dirname(__FILE__);
            odcm_log_message('Base path: ' . $base_path, 'debug');
        }
        $base_path = dirname(__FILE__);
        
        $component_types = ['RuleTriggers', 'RuleConditions', 'RuleActions'];
        $debug_enabled = function_exists('odcm_log_message') && apply_filters('odcm_debug_enabled', defined('ODCM_DEBUG') && ODCM_DEBUG);

        foreach ($component_types as $type) {
            $path = $base_path . '/' . $type;
            if ($debug_enabled) {
                odcm_log_message("Checking directory: {$path}", 'debug');
            }
            
            if (!is_dir($path)) {
                if ($debug_enabled) {
                    odcm_log_message("Directory {$path} does not exist, skipping", 'debug');
                }
                continue;
            }

            $files = glob($path . '/*.php');
            if ($debug_enabled) {
                odcm_log_message("Found " . count($files) . " files in {$type}", 'debug');
            }
            
            foreach ($files as $file) {
                if ($debug_enabled) {
                    odcm_log_message("Processing file: {$file}", 'debug');
                }
                
                try {
                    require_once $file;
                    $class = $this->get_class_from_file($file, $type);
                    
                    if ($debug_enabled) {
                        odcm_log_message("Extracted class name: {$class}", 'debug');
                    }

                    if ($class && class_exists($class)) {
                        if ($debug_enabled) {
                            odcm_log_message("Class {$class} exists", 'debug');
                        }
                        
                        if (!$this->is_abstract($class)) {
                            if ($debug_enabled) {
                                odcm_log_message("Class {$class} is not abstract, instantiating...", 'debug');
                            }
                            
                            // Try to instantiate with error handling
                            $instance = new $class();
                            
                            if ($debug_enabled) {
                                odcm_log_message("Successfully instantiated {$class}", 'debug');
                            }
                            
                            $this->register_component($instance);
                            
                            if ($debug_enabled) {
                                odcm_log_message("Successfully registered {$class} with ID: " . $instance->get_id(), 'debug');
                            }
                        } else if ($debug_enabled) {
                            odcm_log_message("Class {$class} is abstract, skipping", 'debug');
                        }
                    } else if ($debug_enabled) {
                        odcm_log_message("Class {$class} does not exist or could not be loaded", 'debug');
                    }
                } catch (\Throwable $e) {
                    // Log the error but continue loading other components
                    if (function_exists('odcm_log_message')) {
                        odcm_log_message("Failed to load component from {$file}: " . $e->getMessage(), 'error');
                        if ($debug_enabled) {
                            odcm_log_message("Stack trace: " . $e->getTraceAsString(), 'debug');
                        }
                    }
                    continue;
                }
            }
        }

        if ($debug_enabled) {
            odcm_log_message('Component loading complete. Loaded: ' . 
                  count($this->triggers) . ' triggers, ' . 
                  count($this->conditions) . ' conditions, ' . 
                  count($this->actions) . ' actions', 'debug');
        }

        // Allow external plugins to register additional components via the extension API
        do_action('odcm_register_components', $this);

        $this->is_loaded = true;
    }

    /**
     * Registers a single component instance.
     *
     * @param ComponentInterface $component The component to register.
     */
    private function register_component(ComponentInterface $component): void
    {
        if ($component instanceof TriggerInterface) {
            $this->triggers[$component->get_id()] = $component;
        } elseif ($component instanceof ConditionInterface) {
            $this->conditions[$component->get_id()] = $component;
        } elseif ($component instanceof ActionInterface) {
            $this->actions[$component->get_id()] = $component;
        }
    }

    /**
     * Register a trigger component.
     *
     * This public method allows external plugins to register additional triggers with the
     * rule component registry.
     *
     * @param TriggerInterface $trigger The trigger component to register.
     *
     * @example
     * ```php
     * // In a third-party extension:
     * $registry = RuleComponentRegistry::instance();
     * $registry->register_trigger(new MyCustomTrigger());
     * ```
     *
     * @since 2.0.0
     */
    public function register_trigger(TriggerInterface $trigger): void
    {
        $this->triggers[$trigger->get_id()] = $trigger;
    }

    /**
     * Register a condition component.
     *
     * This public method allows external plugins to register additional conditions with the
     * rule component registry.
     *
     * @param ConditionInterface $condition The condition component to register.
     *
     * @example
     * ```php
     * // In a third-party extension:
     * $registry = RuleComponentRegistry::instance();
     * $registry->register_condition(new MyCustomCondition());
     * ```
     *
     * @since 2.0.0
     */
    public function register_condition(ConditionInterface $condition): void
    {
        $this->conditions[$condition->get_id()] = $condition;
    }

    /**
     * Register an action component.
     *
     * This public method allows external plugins to register additional actions
     * with the rule component registry.
     *
     * @param ActionInterface $action The action component to register.
     *
     * @example
     * ```php
     * // In a third-party extension:
     * $registry = RuleComponentRegistry::instance();
     * $registry->register_action(new MyCustomAction());
     * ```
     *
     * @since 2.0.0
     */
    public function register_action(ActionInterface $action): void
    {
        $this->actions[$action->get_id()] = $action;
    }

    /**
     * Extracts the fully qualified class name from a file path.
     *
     * @param string $file The full path to the PHP file.
     * @param string $type The component type directory (e.g., 'Conditions').
     * @return string|null The FQCN or null on failure.
     */
    private function get_class_from_file(string $file, string $type): ?string
    {
        $class_name = basename($file, '.php');
        return "OrderDaemon\\CompletionManager\\Core\\RuleComponents\\{$type}\\{$class_name}";
    }
    
    /**
     * Checks if a class is abstract using reflection.
     *
     * @param string $class The fully qualified class name.
     * @return bool True if the class is abstract, false otherwise.
     */
    private function is_abstract(string $class): bool
    {
        try {
            $reflection = new \ReflectionClass($class);
            return $reflection->isAbstract();
        } catch (\ReflectionException $e) {
            return true; // Treat as abstract if reflection fails
        }
    }
}
