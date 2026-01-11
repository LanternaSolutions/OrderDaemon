<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View;

use OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentRenderer;
use OrderDaemon\CompletionManager\API\Timeline\AdapterRegistry;
use OrderDaemon\CompletionManager\API\Timeline\GenericEventAdapter;
use OrderDaemon\CompletionManager\API\Timeline\DisplayAdapter;

/**
 * Payload Analyzer - The Brain of the Composite Payload Rendering System
 *
 * This class serves as the central intelligence for decomposing raw audit log payloads
 * into renderable components. It analyzes payload structure, identifies component types,
 * and prepares data for specialized rendering by component-specific renderers.
 *
 * ANALYZER OVERVIEW:
 * =================
 * 
 * The PayloadAnalyzer implements a sophisticated analysis system that:
 * - Examines payload structure and content to identify component types
 * - Maps payload data to appropriate renderer classes
 * - Handles both explicit component identification and automatic detection
 * - Provides fallback mechanisms for unrecognized data types
 * - Optimizes component ordering for logical presentation
 * - Caches analysis results for performance optimization
 * 
 * ANALYSIS WORKFLOW:
 * =================
 * 
 * 1. **Input Validation**: Validates and normalizes input payload data
 * 2. **Component Detection**: Identifies component types using multiple strategies
 * 3. **Data Extraction**: Extracts relevant data for each identified component
 * 4. **Priority Sorting**: Orders components by priority for logical presentation
 * 5. **Result Packaging**: Packages components with metadata for rendering
 * 
 * DETECTION STRATEGIES:
 * ====================
 * 
 * The analyzer uses multiple detection strategies in order of preference:
 * 1. **Explicit Key Matching**: Direct payload key matching with component detection keys
 * 2. **Renderer Detection**: Using renderer canHandle() methods for complex detection
 * 3. **Pattern Recognition**: Analyzing data patterns and structures
 * 4. **Fallback Handling**: Default rendering for unrecognized data
 * 
 * PERFORMANCE OPTIMIZATION:
 * ========================
 * 
 * - Component analysis results are cached per request
 * - Detection strategies are ordered by efficiency
 * - Early termination for obvious matches
 * - Lazy loading of renderer classes
 * - Minimal data copying and transformation
 *
 * @package OrderDaemon\CompletionManager\View
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/payload-rendering-system
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Payload Analyzer Class
 *
 * Analyzes audit log payloads and decomposes them into renderable components
 * for the composite payload rendering system.
 *
 * @since 1.0.0
 * @deprecated 3.0.0 Narrative-only mode removes analyzer usage. Kept for docs/tests only.
 */
class PayloadAnalyzer
{
    /**
     * Analysis cache to avoid repeated processing
     *
     * @var array<string, array>
     */
    private static array $analysis_cache = [];

    /**
     * Renderer class cache to avoid repeated instantiation
     *
     * @var array<string, PayloadComponentRenderer>
     */
    private static array $renderer_cache = [];

    /**
     * Log a debug message using WordPress-compatible logging methods
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warning, error)
     * @return void
     */
    private function logDebugMessage(string $message, string $level = 'debug'): void
    {
        // Only log if debug mode is enabled
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }
        
        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($message, $level);
            return;
        }
        
        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
            return;
        }
        
        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_' . $level, $message);
            return;
        }
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
            $debug_file = WP_CONTENT_DIR . '/debug.log';
            @file_put_contents(
                $debug_file,
                '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
                FILE_APPEND
            );
            return;
        }
    }

    /**
     * Extract Data for Specific Component Type
     *
     * Extracts relevant data from the payload for a specific component type
     * based on its detection keys and data patterns.
     *
     * @since 1.0.0
     *
     * @param array $payload The full payload data.
     * @param array $component_def Component definition from registry.
     * @return array Extracted data relevant to this component.
     */
    private function extractDataForComponent(array $payload, array $component_def): array
    {
        $component_data = [];
        $detection_keys = $component_def['detection_keys'] ?? [];
        
        // If component has specific detection keys, extract only those
        if (!empty($detection_keys)) {
            foreach ($detection_keys as $key) {
                if (isset($payload[$key])) {
                    $component_data[$key] = $payload[$key];
                }
            }
        }
        
        // If no specific keys matched, check if renderer can handle the full payload
        if (empty($component_data)) {
            $renderer = $this->getRenderer($component_def['renderer_class']);
            if ($renderer && $renderer->canHandle($payload)) {
                $component_data = $payload;
            }
        }
        
        return $component_data;
    }

    /**
     * Detect Components by Renderer Methods
     *
     * Uses the canHandle() method of renderer classes to detect components
     * for data that wasn't matched by explicit key detection.
     *
     * @since 1.0.0
     *
     * @param array $remaining_data Data not yet assigned to components.
     * @param array $component_types Component type definitions.
     * @return array<string, array> Detected components with their data.
     */
    private function detectByRendererMethods(array $remaining_data, array $component_types): array
    {
        $detected = [];

        foreach ($component_types as $component_id => $component_def) {
            // Skip fallback component and already detected components
            if ($component_id === 'fallback') {
                continue;
            }

            $renderer = $this->getRenderer($component_def['renderer_class']);
            if ($renderer && $renderer->canHandle($remaining_data)) {
                $detected[$component_id] = $remaining_data;
                // REMOVED: break; // Allow multiple components to be detected
            }
        }

        return $detected;
    }

    /**
     * Extract Remaining Data
     *
     * Extracts payload data that hasn't been assigned to any detected components.
     *
     * @since 1.0.0
     *
     * @param array $payload The full payload data.
     * @param array $detected_components Already detected components.
     * @return array Remaining unassigned data.
     */
    private function extractRemainingData(array $payload, array $detected_components): array
    {
        $used_keys = [];
        foreach ($detected_components as $component_data) {
            $used_keys = array_merge($used_keys, array_keys($component_data));
        }

        $remaining = [];
        foreach ($payload as $key => $value) {
            if (!in_array($key, $used_keys, true)) {
                $remaining[$key] = $value;
            }
        }

        return $remaining;
    }

    /**
     * Build Component Object
     *
     * Creates a complete component object with metadata and data for rendering.
     *
     * @since 1.0.0
     *
     * @param array $component_def Component definition from registry.
     * @param array $component_data Data for this component.
     * @return array Complete component object.
     */
    private function buildComponentObject(array $component_def, array $component_data): array
    {
        // Check if renderer_class already has full namespace
        $renderer_class = $component_def['renderer_class'];
        if (strpos($renderer_class, '\\') === false) {
            // Add namespace only if it's not already there
            $renderer_class = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;
        }
        
        // Get the proper theme modifier class for the three-tier system
        $theme_class = $this->getThemeModifierClass($component_def['id']);
        
        return [
            'id' => $component_def['id'],
            'type' => $component_def['id'],
            'label' => $component_def['label'],
            'renderer_class' => $renderer_class,
            'css_class' => $component_def['css_class'],
            'theme_class' => $theme_class, // Use proper theme modifier class
            'icon' => $component_def['icon'],
            'priority' => $component_def['priority'],
            'data' => $component_data,
        ];
    }

    /**
     * Get Theme Modifier Class for Component Type
     *
     * Maps component types to their corresponding CSS theme modifier classes
     * from the audit-trail.css three-tier theming system.
     *
     * @since 1.0.0
     *
     * @param string $component_id Component type identifier.
     * @return string CSS theme modifier class.
     */
    private function getThemeModifierClass(string $component_id): string
    {
        $theme_mapping = [
            'error_details' => 'odcm-component--error',
            'performance_metrics' => 'odcm-component--performance',
            'woocommerce_data' => 'odcm-component--woocommerce',
            'database_query' => 'odcm-component--database',
            'rule_evaluation' => 'odcm-component--rule',
            'api_call' => 'odcm-component--api',
            'system_info' => 'odcm-component--system',
            'fallback' => 'odcm-component--fallback',
        ];

        return $theme_mapping[$component_id] ?? 'odcm-component--fallback';
    }

    /**
     * Create Fallback Component
     *
     * Creates a fallback component for data that couldn't be categorized.
     * Migrated to use GenericEventAdapter instead of FallbackRenderer.
     *
     * @since 2.0.0 (Migrated)
     *
     * @param array $payload The payload data to include in fallback.
     * @return array<array> Array containing single fallback component.
     */
    private function createFallbackComponent(array $payload): array
    {

        // Use GenericEventAdapter for modern fallback handling
        return [[
            'id' => 'fallback',
            'type' => 'fallback',
            'label' => 'Additional Data',
            'renderer_class' => GenericEventAdapter::class, // Modern adapter
            'css_class' => 'odcm-fallback-payload',
            'theme_class' => 'odcm-component--fallback',
            'icon' => 'dashicons-text-page',
            'priority' => 99,
            'data' => $payload,
        ]];
    }

    /**
     * Sort Components by Priority
     *
     * Sorts component array by priority (lower numbers first).
     *
     * @since 1.0.0
     *
     * @param array $components Array of component objects.
     * @return array Sorted component array.
     */
    private function sortComponentsByPriority(array $components): array
    {
        usort($components, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return $components;
    }

    /**
     * Get Renderer Instance
     *
     * Gets a renderer instance, using cache to avoid repeated instantiation.
     *
     * @since 1.0.0
     *
     * @param string $renderer_class The renderer class name.
     * @return PayloadComponentRenderer|null Renderer instance or null if unavailable.
     */
    private function getRenderer(string $renderer_class): ?PayloadComponentRenderer
    {
        $full_class_name = 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\' . $renderer_class;

        if (isset(self::$renderer_cache[$full_class_name])) {
            return self::$renderer_cache[$full_class_name];
        }

        if (!class_exists($full_class_name)) {
            return null;
        }

        try {
            $renderer = new $full_class_name();
            if ($renderer instanceof PayloadComponentRenderer) {
                self::$renderer_cache[$full_class_name] = $renderer;
                return $renderer;
            }
        } catch (\Throwable $e) {
            // Log error but don't fail analysis
            $this->logDebugMessage("PayloadAnalyzer: Failed to instantiate renderer {$full_class_name}: " . $e->getMessage(), 'error');
        }

        return null;
    }

    /**
     * Generate Cache Key
     *
     * Generates a cache key for the payload to enable result caching.
     *
     * @since 1.0.0
     *
     * @param array $payload The payload data.
     * @return string Cache key.
     */
    private function generateCacheKey(array $payload): string
    {
        return 'payload_analysis_' . md5(serialize($payload));
    }

    /**
     * Clear Analysis Cache
     *
     * Clears the analysis cache. Useful for testing or memory management.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$analysis_cache = [];
        self::$renderer_cache = [];
    }

    /**
     * Analyze Payload and Return Component Array
     *
     * This is the primary method of the PayloadAnalyzer. It takes a raw payload
     * and decomposes it into an array of component objects ready for rendering.
     *
     * @since 1.0.0
     *
     * @param array $payload The raw payload data from the audit log entry.
     * @param string $event_type Optional event type for intelligent detection.
     * @return array<array> Array of component objects ready for rendering.
     */
    public function analyze(array $payload, string $event_type = ''): array
    {
        // Input validation
        if (empty($payload)) {
            return $this->createFallbackComponent([]);
        }

        // Generate cache key for this payload
        $cache_key = $this->generateCacheKey($payload);

        // Check cache first
        if (isset(self::$analysis_cache[$cache_key])) {
            return self::$analysis_cache[$cache_key];
        }

        // Perform component analysis using adapter-based approach
        $components = $this->performAdapterBasedAnalysis($payload, $event_type);

        // If no components detected, use fallback
        if (empty($components)) {
            $components = $this->createFallbackComponent($payload);
        }

        // Sort components by priority
        $components = $this->sortComponentsByPriority($components);

        // Cache results
        self::$analysis_cache[$cache_key] = $components;

        return $components;
    }

    /**
     * Perform Adapter-Based Component Analysis
     *
     * Uses AdapterRegistry to get appropriate adapters for payload analysis.
     * This replaces the legacy registry-based detection system.
     *
     * @since 2.0.0 (Migrated from legacy registry system)
     *
     * @param array $payload The payload data to analyze.
     * @param string $event_type Optional event type for intelligent detection.
     * @return array<array> Array of detected components.
     */
    private function performAdapterBasedAnalysis(array $payload, string $event_type = ''): array
    {
        $components = [];
        $adapters = AdapterRegistry::getAvailableAdapters();

        foreach ($adapters as $adapterClass) {
            try {
                $adapter = new $adapterClass();
                if ($adapter->canHandlePayload($payload, $event_type)) {
                    $components[] = [
                        'id' => $adapter->getComponentId(),
                        'type' => $adapter->getComponentType(),
                        'label' => $adapter->getComponentLabel($payload),
                        'renderer_class' => GenericEventAdapter::class,
                        'css_class' => $adapter->getCssClass(),
                        'icon' => $adapter->getIcon(),
                        'priority' => $adapter->getPriority(),
                        'data' => $payload
                    ];
                }
            } catch (\Throwable $e) {
                // Log error but continue with other adapters
                $this->logDebugMessage("Failed to process adapter {$adapterClass}: " . $e->getMessage(), 'warning');
                continue;
            }
        }

        return $components;
    }
}
