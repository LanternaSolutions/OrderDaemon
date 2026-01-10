<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View;

use OrderDaemon\CompletionManager\View\PayloadRenderer\PayloadComponentRenderer;

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
     * Analyze Payload and Return Component Array
     *
     * This is the primary method of the PayloadAnalyzer. It takes a raw payload
     * and decomposes it into an array of component objects ready for rendering.
     *
     * ANALYSIS PROCESS:
     * ================
     * 
     * 1. **Input Validation**: Ensures payload is valid and processable
     * 2. **Cache Check**: Checks for previously analyzed identical payloads
     * 3. **Component Detection**: Identifies all component types present
     * 4. **Data Extraction**: Extracts relevant data for each component
     * 5. **Priority Ordering**: Sorts components by rendering priority
     * 6. **Result Caching**: Caches results for future identical requests
     * 
     * COMPONENT STRUCTURE:
     * ===================
     * 
     * Each returned component contains:
     * ```php
     * [
     *     'id' => 'component_id',
     *     'type' => 'component_type',
     *     'label' => 'Human Readable Label',
     *     'renderer_class' => 'RendererClassName',
     *     'css_class' => 'css-class-name',
     *     'icon' => 'dashicons-icon',
     *     'priority' => 10,
     *     'data' => [...] // Component-specific data
     * ]
     * ```
     * 
     * FALLBACK BEHAVIOR:
     * =================
     * 
     * If no specific components are detected, the analyzer will:
     * - Return a single fallback component containing all payload data
     * - Use the FallbackRenderer for generic rendering
     * - Maintain the existing rendering behavior for compatibility
     *
     * NOTE: This uses the legacy renderer system (FallbackRenderer) which is deprecated.
     * Future migration should update this to use the DisplayAdapter system.
     * @see \OrderDaemon\CompletionManager\API\Timeline\DisplayAdapter
     *
     * @since 1.0.0
     *
     * @param array $payload The raw payload data from the audit log entry.
     *                       Can be any structure - JSON decoded arrays, objects, etc.
     *
     * @return array<array> Array of component objects ready for rendering.
     *                      Each component contains metadata and data for rendering.
     *
     * @throws \InvalidArgumentException If payload data is invalid or malformed.
     * @throws \RuntimeException If component analysis fails due to system issues.
     *
     * @example
     * ```php
     * $analyzer = new PayloadAnalyzer();
     * 
     * // Analyze a complex payload
     * $payload = [
     *     'api_request' => ['url' => 'https://api.example.com'],
     *     'error' => ['message' => 'Connection failed'],
     *     'performance' => ['execution_time' => 1.5]
     * ];
     * 
     * $components = $analyzer->analyze($payload);
     * // Returns array with 3 components: error_details, api_call, performance_metrics
     * 
     * foreach ($components as $component) {
     *     $renderer_class = $component['renderer_class'];
     *     $renderer = new $renderer_class();
     *     echo $renderer->render($component['data']);
     * }
     * ```
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

        // Perform component analysis
        $components = $this->performComponentAnalysis($payload, $event_type);

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
     * Perform Component Analysis on Payload - Multi-Stage Detection Strategy
     *
     * Implements an optimized three-stage detection strategy for maximum performance:
     * 1. Fast Path: Registry-based detection using detection_keys (O(1) lookups)
     * 2. Smart Path: Event type mapping for intelligent component selection
     * 3. Safety Net: Renderer canHandle() methods for complex detection logic
     *
     * This approach minimizes expensive canHandle() calls while ensuring comprehensive
     * component detection for all payload types.
     *
     * @since 1.0.0
     *
     * @param array $payload The payload data to analyze.
     * @param string $event_type Optional event type for intelligent detection.
     * @return array<array> Array of detected components.
     */
    private function performComponentAnalysis(array $payload, string $event_type = ''): array
    {
        // Load component registry
        if (!function_exists('odcm_get_payload_component_types')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }

        $component_types = \odcm_get_payload_component_types_by_priority();
        $detected_components = [];
        $payload_keys = array_keys($payload);

        // === STAGE 1: FAST PATH - Registry-based detection ===
        // Use detection_keys for O(1) component identification
        $fast_path_detected = $this->fastPathDetection($payload, $payload_keys, $component_types);
        $detected_components = array_merge($detected_components, $fast_path_detected);

        // === STAGE 2: SMART PATH - Event type mapping ===
        // Use event type for intelligent component selection
        if (!empty($event_type)) {
            $smart_path_detected = $this->smartPathDetection($payload, $event_type, $component_types, $detected_components);
            $detected_components = array_merge($detected_components, $smart_path_detected);
        }

        // === STAGE 3: SAFETY NET - Renderer-based detection ===
        // Use canHandle() methods only for unmatched data
        $remaining_data = $this->extractRemainingData($payload, $detected_components);
        if (!empty($remaining_data)) {
            $safety_net_detected = $this->safetyNetDetection($remaining_data, $component_types, $detected_components);
            $detected_components = array_merge($detected_components, $safety_net_detected);
        }

        // Build final component objects
        return $this->buildComponentObjects($detected_components, $component_types);
    }

    /**
     * Fast Path Detection - Registry-based Key Matching
     *
     * Uses detection_keys from the component registry for O(1) component identification.
     * This is the fastest detection method and handles the majority of common cases.
     *
     * @since 1.0.0
     *
     * @param array $payload Full payload data.
     * @param array $payload_keys Array of payload keys for efficient intersection.
     * @param array $component_types Component type definitions from registry.
     * @return array<string, array> Detected components with their data.
     */
    private function fastPathDetection(array $payload, array $payload_keys, array $component_types): array
    {
        $detected = [];

        foreach ($component_types as $component_id => $component_def) {
            // Skip fallback component in fast path detection
            if ($component_id === 'fallback') {
                continue;
            }

            $detection_keys = $component_def['detection_keys'] ?? [];
            if (empty($detection_keys)) {
                continue;
            }

            // Fast intersection check for matching keys
            $matching_keys = array_intersect($payload_keys, $detection_keys);
            if (!empty($matching_keys)) {
                // Extract only the relevant data for this component
                $component_data = [];
                foreach ($matching_keys as $key) {
                    $component_data[$key] = $payload[$key];
                }
                $detected[$component_id] = $component_data;
            }
        }

        return $detected;
    }

    /**
     * Smart Path Detection - Event Type Mapping
     *
     * Uses event type mappings to intelligently select appropriate components
     * based on the log event context. Only processes components not already detected.
     *
     * @since 1.0.0
     *
     * @param array $payload Full payload data.
     * @param string $event_type Log event type.
     * @param array $component_types Component type definitions.
     * @param array $already_detected Components already detected in previous stages.
     * @return array<string, array> Newly detected components with their data.
     */
    private function smartPathDetection(array $payload, string $event_type, array $component_types, array $already_detected): array
    {
        $detected = [];

        // Load event type mappings
        if (!function_exists('odcm_get_event_type_component_mappings')) {
            return $detected;
        }

        $event_mappings = \odcm_get_event_type_component_mappings();
        $normalized_event_type = $this->normalizeEventType($event_type);

        // Check for direct event type mapping
        if (isset($event_mappings[$normalized_event_type])) {
            $component_ids = $event_mappings[$normalized_event_type];

            foreach ($component_ids as $component_id) {
                // Skip if already detected or component doesn't exist
                if (isset($already_detected[$component_id]) || !isset($component_types[$component_id])) {
                    continue;
                }

                // Extract relevant data for this component type
                $component_data = $this->extractDataForComponent($payload, $component_types[$component_id]);

                // Only include if meaningful data was found
                if (!empty($component_data)) {
                    $detected[$component_id] = $component_data;
                }
            }
        }

        return $detected;
    }

    /**
     * Safety Net Detection - Renderer canHandle() Methods
     *
     * Uses renderer canHandle() methods as a last resort for complex detection logic.
     * Only processes remaining unmatched data to minimize expensive method calls.
     *
     * @since 1.0.0
     *
     * @param array $remaining_data Unmatched payload data.
     * @param array $component_types Component type definitions.
     * @param array $already_detected Components already detected in previous stages.
     * @return array<string, array> Newly detected components with their data.
     */
    private function safetyNetDetection(array $remaining_data, array $component_types, array $already_detected): array
    {
        $detected = [];

        foreach ($component_types as $component_id => $component_def) {
            // Skip fallback and already detected components
            if ($component_id === 'fallback' || isset($already_detected[$component_id])) {
                continue;
            }

            $renderer = $this->getRenderer($component_def['renderer_class']);
            if ($renderer && $renderer->canHandle($remaining_data)) {
                $detected[$component_id] = $remaining_data;
                // Note: Don't break here - allow multiple components to handle the same data
            }
        }

        return $detected;
    }

    /**
     * Build Component Objects from Detection Results
     *
     * Converts detection results into complete component objects ready for rendering.
     *
     * @since 1.0.0
     *
     * @param array $detected_components Detection results from all stages.
     * @param array $component_types Component type definitions.
     * @return array<array> Complete component objects.
     */
    private function buildComponentObjects(array $detected_components, array $component_types): array
    {
        $components = [];

        foreach ($detected_components as $component_id => $component_data) {
            if (isset($component_types[$component_id])) {
                $component_def = $component_types[$component_id];
                $components[] = $this->buildComponentObject($component_def, $component_data);
            }
        }

        return $components;
    }

    /**
     * Detect Components by Event Type Mapping
     *
     * Uses the event type mappings from the component registry to identify components
     * based on the log event type. This is the highest priority detection method.
     *
     * @since 1.0.0
     *
     * @param array $payload The full payload data.
     * @param string $event_type The log event type.
     * @param array $component_types Component type definitions.
     * @return array<string, array> Detected components with their data.
     */
    private function detectByEventType(array $payload, string $event_type, array $component_types): array
    {
        $detected = [];

        // Load event type mappings
        if (!function_exists('odcm_get_event_type_component_mappings')) {
            return $detected; // No mappings available
        }

        $event_mappings = \odcm_get_event_type_component_mappings();
        
        // Normalize event type for lookup
        $normalized_event_type = $this->normalizeEventType($event_type);
        
        // Check for direct mapping
        if (isset($event_mappings[$normalized_event_type])) {
            $component_ids = $event_mappings[$normalized_event_type];
            
            foreach ($component_ids as $component_id) {
                // Verify component exists in registry
                if (isset($component_types[$component_id])) {
                    // Extract relevant data for this specific component type
                    $component_data = $this->extractDataForComponent($payload, $component_types[$component_id]);
                    
                    // Only include component if it has meaningful data
                    if (!empty($component_data)) {
                        $detected[$component_id] = $component_data;
                    }
                }
            }
        }

        return $detected;
    }

    /**
     * Normalize Event Type for Mapping Lookup
     *
     * Converts various event type formats to standardized lookup keys.
     *
     * @since 1.0.0
     *
     * @param string $event_type Raw event type string.
     * @return string Normalized event type.
     */
    private function normalizeEventType(string $event_type): string
    {
        // Convert to lowercase and replace spaces/special chars with underscores
        $normalized = strtolower($event_type);
        $normalized = preg_replace('/[^a-z0-9_]/', '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized);
        $normalized = trim($normalized, '_');
        
        // Common mappings for existing event types
        $mappings = [
            'rule_evaluation_success' => 'rule_evaluation_success',
            'action_execution_success' => 'action_execution_success',
            'error_critical' => 'error_critical',
            'api_response_error' => 'api_response_error',
            'system_debug_info' => 'system_debug_info',
            'dev_toolbar_debug_toggle' => 'dev_toolbar_debug_toggle',
            'dev_toolbar_version_toggle' => 'dev_toolbar_version_toggle',
            'sample_generation_scheduled' => 'sample_generation_scheduled',
            'comprehensive_sample_generation' => 'comprehensive_sample_generation',
            'engine_triggered' => 'engine_triggered',
            'load_test' => 'load_test',
            'order_completed' => 'order_completed',
            'process_order_check_start' => 'process_order_check_start',
        ];
        
        return $mappings[$normalized] ?? $normalized;
    }

    /**
     * Detect Components by Explicit Key Matching
     *
     * Uses the detection_keys from the component registry to identify components
     * based on the presence of specific payload keys.
     *
     * @since 1.0.0
     *
     * @param array $payload The full payload data.
     * @param array $payload_keys Array of payload keys.
     * @param array $component_types Component type definitions.
     * @return array<string, array> Detected components with their data.
     */
    private function detectByExplicitKeys(array $payload, array $payload_keys, array $component_types): array
    {
        $detected = [];

        foreach ($component_types as $component_id => $component_def) {
            // Skip fallback component in explicit detection
            if ($component_id === 'fallback') {
                continue;
            }

            $detection_keys = $component_def['detection_keys'] ?? [];
            if (empty($detection_keys)) {
                continue;
            }

            // Check for matching keys
            $matching_keys = array_intersect($payload_keys, $detection_keys);
            if (!empty($matching_keys)) {
                // Extract data for this component
                $component_data = [];
                foreach ($matching_keys as $key) {
                    $component_data[$key] = $payload[$key];
                }
                $detected[$component_id] = $component_data;
            }
        }

        return $detected;
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
     *
     * @since 1.0.0
     *
     * @param array $payload The payload data to include in fallback.
     * @return array<array> Array containing single fallback component.
     */
    private function createFallbackComponent(array $payload): array
    {
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(__DIR__) . '/Core/PayloadComponentRegistry.php';
        }

        $fallback_def = \odcm_get_payload_component_type('fallback');
        if (!$fallback_def) {
            // Emergency fallback if registry is unavailable
            return [[
                'id' => 'fallback',
                'type' => 'fallback',
                'label' => 'Additional Data',
                'renderer_class' => 'OrderDaemon\\CompletionManager\\View\\PayloadRenderer\\FallbackRenderer',
                'css_class' => 'odcm-fallback-payload',
                'icon' => 'dashicons-text-page',
                'priority' => 99,
                'data' => $payload,
            ]];
        }

        return [$this->buildComponentObject($fallback_def, $payload)];
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
}
