<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Payload Component Renderer Abstract Base Class
 *
 * This abstract class provides the foundational architecture for all payload component 
 * renderers in the Order Daemon audit log system. It centralizes structural concerns
 * while allowing individual renderers to focus purely on content-specific logic.
 *
 * ARCHITECTURAL OVERVIEW:
 * ======================
 * 
 * The PayloadComponentRenderer base class implements a Template Method pattern that:
 * - Handles all HTML structure generation consistently across components
 * - Manages error handling and validation centrally
 * - Integrates with the PayloadComponentRegistry for metadata
 * - Provides robust fallback behavior for rendering failures
 * - Enforces consistent CSS class usage and component structure
 * 
 * RENDERER RESPONSIBILITIES:
 * =========================
 * 
 * Base Class Handles:
 * - Complete HTML structure (.odcm-component, headers, etc.)
 * - Component metadata retrieval from registry
 * - Input validation and error handling
 * - Consistent CSS class application
 * - Error fallback rendering
 * 
 * Individual Renderers Handle:
 * - Content-specific rendering logic (.odcm-component__body only)
 * - Component ID identification
 * - Data compatibility detection (canHandle method)
 * 
 * IMPLEMENTATION PATTERN:
 * ======================
 * 
 * Each renderer extends this base class and implements:
 * ```php
 * class ApiCallRenderer extends PayloadComponentRenderer {
 *     protected function getComponentId(): string {
 *         return 'api_call';
 *     }
 *     
 *     protected function renderContent(array $data): string {
 *         // Only content logic - no structure
 *         return '<div>API-specific content</div>';
 *     }
 *     
 *     public function canHandle(array $data): bool {
 *         return isset($data['api_request']);
 *     }
 * }
 * ```
 * 
 * ERROR HANDLING STRATEGY:
 * =======================
 * 
 * The base class provides comprehensive error handling:
 * - Input validation before rendering
 * - Exception catching during content rendering
 * - Graceful fallback with error details and raw data
 * - Consistent error component structure
 * 
 * REGISTRY INTEGRATION:
 * ====================
 * 
 * Component metadata (icons, labels, CSS classes) is automatically retrieved
 * from the PayloadComponentRegistry using the component ID, ensuring consistency
 * and centralized configuration management.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 * @author  OrderDaemon Development Team
 * @link    https://docs.OrderDaemon.com/completion-manager/payload-rendering-system
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Abstract base class for payload component renderers with dual-mode support.
 * 
 * This class implements the Template Method pattern and provides two rendering modes:
 * 
 * 1. Standalone Mode: Complete timeline items with shell (icon, header, timestamp)
 *    - Use renderTimelineItem() method
 *    - For primary events and standalone components
 * 
 * 2. Embedded Mode: Content only, without shell elements
 *    - Use renderEmbeddedContent() method  
 *    - For context data embedded within other timeline items
 * 
 * The dual-mode approach enables timeline consolidation where context data
 * (attribution, performance metrics, etc.) can be embedded within primary
 * events rather than appearing as separate timeline items.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   2.0.1
 */
abstract class PayloadComponentRenderer
{
    /**
     * Optional process timeline metadata for header rendering.
     * @var string|null ISO8601 started timestamp
     */
    private ?string $timelineStartedAt = null;

    /**
     * Optional trigger hook string for header rendering.
     * @var string|null
     */
    private ?string $timelineTrigger = null;
    /**
     * Narrative timeline context: optional overrides for header/title and timestamp/level
     * when rendering timeline items.
     *
     * @var string|null
     */
    private ?string $timelineLabel = null;

    /**
     * @var string|null ISO8601 timestamp to display in header
     */
    private ?string $timelineTimestamp = null;

    /**
     * @var string|null info|warning|error|debug for potential styling hooks
     */
    private ?string $timelineLevel = null;
    /**
     * Override component ID for registry lookups when rendering narrative kinds.
     *
     * @var string|null
     */
    private ?string $overrideComponentId = null;

    /**
     * Render Component Data to HTML
     *
     * This is the main entry point for component rendering. The base class handles
     * all structural concerns while delegating content rendering to the specific
     * renderer implementation.
     *
     * RENDERING PROCESS:
     * =================
     * 
     * 1. **Input Validation**: Validates the provided data array
     * 2. **Metadata Retrieval**: Gets component metadata from registry
     * 3. **Content Rendering**: Calls the renderer-specific renderContent() method
     * 4. **Structure Building**: Uses UIToolkit for consistent component structure with status pills
     * 5. **Error Handling**: Provides fallback rendering if any step fails
     * 
     * GENERATED STRUCTURE:
     * ===================
     * 
     * Uses PayloadComponentUIToolkit.render_component_shell() for consistent structure
     * and automatic status pill integration from registry.
     *
     * @since 1.0.0
     *
     * @param array $data The payload data to render.
     * @return string Complete HTML component ready for display.
     */
    final public function render(array $data): string
    {
        try {
            $this->validateData($data);
            $metadata = $this->getMetadataFromRegistry();
            $content = $this->renderContent($data);
            
            // Use UIToolkit for consistent component structure with status pill support
            $toolkit = new PayloadComponentUIToolkit();
            $theme = $this->extractThemeFromRegistryCssClass($metadata['css_class']);
            $label = $this->timelineLabel !== null ? $this->timelineLabel : $metadata['label'];
            
            // Build options for timeline functionality and level styling
            $options = [];
            
            // Pass component ID for proper registry lookup of status pills
            $options['component_id'] = $this->getCurrentComponentId();
            
            // Pass timestamp to UIToolkit for proper bottom row placement
            if ($this->timelineTimestamp !== null) {
                $options['timestamp'] = $this->timelineTimestamp;
            }
            
            // Add level class for styling if available
            if ($this->timelineLevel !== null) {
                $level_class_map = [
                    'info' => 'odcm-level-info',
                    'warning' => 'odcm-level-warning', 
                    'error' => 'odcm-level-error',
                    'debug' => 'odcm-level-debug',
                ];
                $level_class = $level_class_map[$this->timelineLevel] ?? 'odcm-level-info';
                $options['additional_classes'] = $level_class;
            }
            
            // Pass process metadata to header if available
                        if ($this->timelineStartedAt !== null) {
                            $options['started_at'] = $this->timelineStartedAt;
                        }
                        if ($this->timelineTrigger !== null) {
                            $options['trigger'] = $this->timelineTrigger;
                        }
                        return $toolkit->render_component_shell($label, $theme, $content, $options);
            
        } catch (Exception $e) {
            return $this->renderErrorComponent($e, $data);
        }
    }

    /**
     * Render using a specific component ID (narrative kind).
     * Temporarily overrides the component ID used for registry metadata lookup.
     *
     * @param string $component_id Narrative kind to use for metadata and theming.
     * @param array  $data         Payload data for the renderer.
     * @return string HTML output.
     */
    public function renderWithComponentId(string $component_id, array $data): string
    {
        $previous = $this->overrideComponentId;
        $this->overrideComponentId = sanitize_key($component_id);
        try {
            return $this->render($data);
        } finally {
            $this->overrideComponentId = $previous;
        }
    }

    /**
     * Render a full timeline item (header + content) with narrative context.
     * This ensures dashicon, title, and timestamp are part of the renderer output
     * rather than being composed externally by the endpoint.
     *
     * @param string      $component_id Narrative kind / component id.
     * @param string      $label        Human readable title from the timeline item.
     * @param string|null $timestamp    ISO8601 timestamp (may be empty/null).
     * @param string      $level        info|warning|error|debug (styling hint).
     * @param array       $data         Payload data for the renderer.
     * @return string HTML
     */
    public function renderTimelineItem(string $component_id, string $label, ?string $timestamp, string $level, array $data): string
    {
        $prevId      = $this->overrideComponentId;
        $prevLabel   = $this->timelineLabel;
        $prevTs      = $this->timelineTimestamp;
        $prevLevel   = $this->timelineLevel;

        $this->overrideComponentId = sanitize_key($component_id);
        $this->timelineLabel       = sanitize_text_field($label);
        $this->timelineTimestamp   = is_string($timestamp) ? sanitize_text_field($timestamp) : null;
        $this->timelineLevel       = sanitize_key($level);
        try {
            return $this->render($data);
        } finally {
            $this->overrideComponentId = $prevId;
            $this->timelineLabel       = $prevLabel;
            $this->timelineTimestamp   = $prevTs;
            $this->timelineLevel       = $prevLevel;
        }
    }

    /**
     * Render embedded content in dual-mode contexts.
     *
     * Template Method default: delegates to the main render() so that, unless
     * a renderer provides a custom compact implementation, the embedded output
     * will be the full component (header + body). This ensures standalone event
     * renderers display consistently in embedded contexts without extra work.
     *
     * Specific high-signal or context renderers may override this to provide a
     * condensed inline representation.
     *
     * @param array $data The component data to render
     * @return string HTML content suitable for embedding (full component by default)
     */
    public function renderEmbeddedContent(array $data): string
    {
        // Default to full component rendering for embedded contexts
        return $this->render($data);
    }

    /**
     * Render Component Content
     *
     * This method must be implemented by each renderer to provide component-specific
     * content rendering. The implementation should focus purely on the content that
     * goes inside the .odcm-component__body container.
     *
     * CONTENT GUIDELINES:
     * ==================
     * 
     * - Return only the inner content HTML (no component structure)
     * - Use appropriate Prism.js language classes for syntax highlighting
     * - Handle missing or malformed data gracefully
     * - Escape all output using WordPress functions (esc_html, esc_attr)
     * - Use semantic HTML structure for accessibility
     * 
     * SYNTAX HIGHLIGHTING:
     * ===================
     * 
     * For code content, use appropriate language classes:
     * - JSON: `<pre><code class="language-json">...</code></pre>`
     * - SQL: `<pre><code class="language-sql">...</code></pre>`
     * - PHP: `<pre><code class="language-php">...</code></pre>`
     * - Plain text: `<pre><code class="language-none">...</code></pre>`
     *
     * CONTEXT EMBEDDING:
     * ==================
     * 
     * This method is public to support context embedding. When called directly
     * (not through render()), it returns just the content without component wrapper,
     * allowing context data to be embedded within primary event renderers.
     *
     * @since 1.0.0
     *
     * @param array $data The validated payload data for this component.
     * @return string HTML content for the component body.
     * 
     * @throws InvalidArgumentException If data is missing required fields.
     * @throws RuntimeException If content rendering fails.
     */
    abstract public function renderContent(array $data): string;

    /**
     * Set process timeline metadata for header rendering (optional).
     *
     * @param string|null $startedAt ISO8601 started timestamp
     * @param string|null $trigger   Trigger hook string
     * @return void
     */
    public function setTimelineMeta(?string $startedAt, ?string $trigger): void
    {
        $this->timelineStartedAt = is_string($startedAt) && $startedAt !== '' ? sanitize_text_field($startedAt) : null;
        $this->timelineTrigger   = is_string($trigger) && $trigger !== '' ? sanitize_text_field($trigger) : null;
    }

    /**
     * Get Component ID
     *
     * Returns the component ID used for registry lookup and identification.
     * This ID must match an entry in the PayloadComponentRegistry.
     *
     * @since 1.0.0
     *
     * @return string The component identifier (e.g., 'api_call', 'error_details').
     */
    abstract protected function getComponentId(): string;

    /**
     * Get the currently active component ID (narrative kind) for this render cycle.
     * If renderWithComponentId() was used, returns the override; otherwise returns getComponentId().
     *
     * @since 1.0.0 Narrative-only mode
     * @return string
     */
    protected function getCurrentComponentId(): string
    {
        return $this->overrideComponentId !== null ? $this->overrideComponentId : $this->getComponentId();
    }

    /**
     * Check if Renderer Can Handle Data
     *
     * Determines whether this renderer is appropriate for the provided data.
     * Used by PayloadAnalyzer for automatic renderer selection.
     *
     * DETECTION GUIDELINES:
     * ====================
     * 
     * - Check for component-specific data keys or patterns
     * - Be conservative to avoid false positives
     * - Use efficient checks (avoid deep traversal)
     * - Return false for ambiguous cases
     *
     * @since 1.0.0
     *
     * @param array $data The payload data to analyze.
     * @return bool True if this renderer can handle the data.
     */
    abstract public function canHandle(array $data): bool;

    /**
     * Validate Input Data
     *
     * Performs basic validation on the input data to ensure it's suitable
     * for rendering. Throws exceptions for invalid data.
     *
     * @since 1.0.0
     *
     * @param array $data The data to validate.
     * @throws InvalidArgumentException If data is invalid.
     */
    private function validateData(array $data): void
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Payload data cannot be empty');
        }

        // Additional validation can be added here as needed
    }

    /**
     * Get Component Metadata from Registry
     *
     * Retrieves component metadata (icon, label, CSS class) from the
     * PayloadComponentRegistry using the component ID.
     *
     * @since 1.0.0
     *
     * @return array Component metadata with icon, label, and css_class.
     */
    private function getMetadataFromRegistry(): array
    {
        $component_id = $this->overrideComponentId !== null ? $this->overrideComponentId : $this->getComponentId();
        
        // Load registry functions if not available
        if (!function_exists('odcm_get_payload_component_type')) {
            require_once dirname(dirname(__DIR__)) . '/Core/PayloadComponentRegistry.php';
        }
        
        // Get component definition from registry
        if (function_exists('odcm_get_payload_component_type')) {
            $component_def = \odcm_get_payload_component_type($component_id);
            if ($component_def) {
                return [
                    'icon' => $component_def['icon'] ?? 'dashicons-admin-generic',
                    'label' => $component_def['label'] ?? 'Component Data',
                    'css_class' => $component_def['css_class'] ?? 'odcm-generic-component'
                ];
            }
        }
        
        // Fallback metadata if registry is unavailable
        return [
            'icon' => 'dashicons-admin-generic',
            'label' => 'Component Data',
            'css_class' => 'odcm-generic-component'
        ];
    }

    /**
     * Extract Theme from Registry CSS Class
     *
     * Properly extracts theme identifier from registry CSS class for UI toolkit.
     * This ensures registry CSS classes are actually used in rendering.
     *
     * @since 1.0.0
     *
     * @param string $css_class CSS class from registry (e.g., 'odcm-component--error').
     * @return string Theme identifier for UI toolkit (e.g., 'error').
     */
    private function extractThemeFromRegistryCssClass(string $css_class): string
    {
        // Extract theme from registry CSS class pattern: 'odcm-component--{theme}'
        if (preg_match('/^odcm-component--(.+)$/', $css_class, $matches)) {
            return $matches[1];
        }
        
        // Fallback: use component ID as theme if CSS class doesn't match expected pattern
        return $this->getCurrentComponentId();
    }



    /**
     * Render Error Component
     *
     * Creates an error component when rendering fails. Shows the error message
     * and raw data for debugging purposes. Now uses UIToolkit for proper Prism.js integration.
     *
     * @since 1.0.0
     *
     * @param Exception $e The exception that occurred.
     * @param array $data The original data that failed to render.
     * @return string Error component HTML.
     */
    private function renderErrorComponent(Exception $e, array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        
        // Prepare error details for key-value display
        $error_details = [
            'Error' => 'Failed to render component content',
            'Details' => $e->getMessage()
        ];
        
        // Prepare raw data as JSON code block
        $raw_data_json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $raw_data_code = $toolkit->render_code_block($raw_data_json, 'json');
        
        // Build error content using UIToolkit
        $content = $toolkit->render_key_value_list($error_details, 'Error Information');
        $content .= $toolkit->render_expandable_section('Raw Data', $raw_data_code);
        
        // Use UIToolkit for consistent error component structure
        return $toolkit->render_component_shell('Rendering Error', 'error', $content);
    }
}
