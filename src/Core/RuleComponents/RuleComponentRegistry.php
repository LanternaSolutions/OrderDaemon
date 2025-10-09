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
        return $this->triggers;
    }

    /**
     * Get all registered condition components.
     *
     * @return ConditionInterface[]
     */
    public function get_conditions(): array
    {
        $this->load_components();
        return $this->conditions;
    }

    /**
     * Get all registered action components.
     *
     * @return ActionInterface[]
     */
    public function get_actions(): array
    {
        $this->load_components();
        return $this->actions;
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
            error_log('ODCM: Components already loaded, skipping');
            return;
        }

        error_log('ODCM: Starting component loading...');
        $base_path = dirname(__FILE__);
        error_log('ODCM: Base path: ' . $base_path);
        
        $component_types = ['RuleTriggers', 'RuleConditions', 'RuleActions'];

        foreach ($component_types as $type) {
            $path = $base_path . '/' . $type;
            error_log("ODCM: Checking directory: {$path}");
            
            if (!is_dir($path)) {
                error_log("ODCM: Directory {$path} does not exist, skipping");
                continue;
            }

            $files = glob($path . '/*.php');
            error_log("ODCM: Found " . count($files) . " files in {$type}");
            
            foreach ($files as $file) {
                error_log("ODCM: Processing file: {$file}");
                try {
                    require_once $file;
                    $class = $this->get_class_from_file($file, $type);
                    error_log("ODCM: Extracted class name: {$class}");

                    if ($class && class_exists($class)) {
                        error_log("ODCM: Class {$class} exists");
                        
                        if (!$this->is_abstract($class)) {
                            error_log("ODCM: Class {$class} is not abstract, instantiating...");
                            // Try to instantiate with error handling
                            $instance = new $class();
                            error_log("ODCM: Successfully instantiated {$class}");
                            $this->register_component($instance);
                            error_log("ODCM: Successfully registered {$class} with ID: " . $instance->get_id());
                        } else {
                            error_log("ODCM: Class {$class} is abstract, skipping");
                        }
                    } else {
                        error_log("ODCM: Class {$class} does not exist or could not be loaded");
                    }
                } catch (\Throwable $e) {
                    // Log the error but continue loading other components
                    error_log("ODCM: Failed to load component from {$file}: " . $e->getMessage());
                    error_log("ODCM: Stack trace: " . $e->getTraceAsString());
                    continue;
                }
            }
        }

        error_log('ODCM: Component loading complete. Loaded: ' . 
                  count($this->triggers) . ' triggers, ' . 
                  count($this->conditions) . ' conditions, ' . 
                  count($this->actions) . ' actions');
        
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
