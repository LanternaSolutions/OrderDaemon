<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Payload Component UI Toolkit
 *
 * Central presentation layer for audit log payload rendering. This class provides
 * a clean API for generating consistent HTML components with proper escaping and
 * semantic structure. It enforces strict separation of concerns by handling only
 * presentation logic while remaining completely agnostic to business data.
 *
 * ARCHITECTURAL PHILOSOPHY:
 * ========================
 * 
 * This class implements the "Presentation Layer" of the new Adapter Pattern
 * architecture. It has ZERO knowledge of business logic, WooCommerce objects,
 * or complex data structures. Its sole responsibility is to generate safe,
 * consistent HTML from simple, clean data inputs.
 * 
 * DESIGN PRINCIPLES:
 * =================
 * 
 * - Single Responsibility: Only generates HTML, never processes business data
 * - Security First: All output is properly escaped using WordPress functions
 * - Consistency: Provides uniform HTML structure across all components
 * - Maintainability: Changes to HTML structure happen in one place
 * - Extensibility: Easy to add new UI patterns without affecting existing code
 * 
 * USAGE PATTERN:
 * =============
 * 
 * PayloadRenderer classes act as "Data Adapters" that:
 * 1. Receive complex, real-world data (WP_Error, API responses, etc.)
 * 2. Transform it into simple arrays and strings
 * 3. Pass clean data to UIToolkit methods
 * 4. Assemble the final component using render_component_shell()
 * 
 * Example:
 * ```php
 * $toolkit = new PayloadComponentUIToolkit();
 * $error_data = ['message' => $error->get_error_message(), 'code' => $error->get_error_code()];
 * $content = $toolkit->render_key_value_list($error_data, 'Error Details');
 * return $toolkit->render_component_shell('Error Information', 'error', $content);
 * ```
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
 * Payload Component UI Toolkit Class
 *
 * Provides a library of methods for rendering common UI patterns in audit log
 * components. All methods handle proper escaping and generate semantic HTML.
 *
 * @since 1.0.0
 */
class PayloadComponentUIToolkit
{
    /**
     * Render Key-Value List Component
     *
     * Generates a compact grid-based key-value list with same-row layout for optimal
     * space efficiency. Uses the three-tier CSS system for consistent theming.
     * Commonly used for displaying structured data like error details, API parameters,
     * or configuration settings.
     *
     * GENERATED STRUCTURE:
     * ===================
     * 
     * ```html
     * <div class="odcm-section">
     *     <div class="odcm-section-title">{title}</div>  <!-- if title provided -->
     *     <dl class="odcm-key-value-list">
     *         <dt class="odcm-key">{key1}</dt>
     *         <dd class="odcm-value">{value1}</dd>
     *         <dt class="odcm-key">{key2}</dt>
     *         <dd class="odcm-value">{value2}</dd>
     *     </dl>
     * </div>
     * ```
     *
     * @since 1.0.0
     *
     * @param array  $data  Associative array of key-value pairs to display.
     * @param string $title Optional section title. If empty, no title is rendered.
     * @return string Escaped HTML output ready for display.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * $data = ['Status' => 'Failed', 'Code' => '404', 'Message' => 'Not Found'];
     * echo $toolkit->render_key_value_list($data, 'API Response Details');
     * ```
     */
    public function render_key_value_list(array $data, string $title = ''): string
    {
        $output = '<div class="odcm-section">';
        
        // Add optional title with 'str' font styling
        if (!empty($title)) {
            $output .= '<div class="odcm-section-title">' . esc_html($title) . '</div>';
        }
        
        // Generate compact grid-based definition list
        $output .= '<dl class="odcm-key-value-list">';
        foreach ($data as $key => $value) {
            $output .= '<dt class="odcm-key">' . esc_html((string)$key) . '</dt>';
            $output .= '<dd class="odcm-value">' . esc_html((string)$value) . '</dd>';
        }
        $output .= '</dl>';
        
        $output .= '</div>';
        return $output;
    }

    /**
     * Render Expandable Section Component
     *
     * Creates a collapsible details/summary element for long content like stack traces,
     * large JSON responses, or verbose log output. The content is initially collapsed
     * to save space but can be expanded by the user.
     *
     * GENERATED STRUCTURE:
     * ===================
     * 
     * ```html
     * <details class="odcm-expandable-section">
     *     <summary class="odcm-expandable-title">{title}</summary>
     *     <div class="odcm-expandable-content">
     *         {content_html}
     *     </div>
     * </details>
     * ```
     *
     * @since 1.0.0
     *
     * @param string $title        Section title displayed in the summary element.
     * @param string $content_html Pre-rendered HTML content (should already be escaped).
     * @return string Complete expandable section HTML.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * $stack_trace = $toolkit->render_code_block($trace_data, 'none');
     * echo $toolkit->render_expandable_section('Stack Trace', $stack_trace);
     * ```
     */
    public function render_expandable_section(string $title, string $content_html): string
    {
        $output = '<details class="odcm-expandable-section">';
        $output .= '<summary class="odcm-expandable-title">' . esc_html($title) . '</summary>';
        $output .= '<div class="odcm-expandable-content">';
        $output .= $content_html; // Content should already be escaped by calling method
        $output .= '</div>';
        $output .= '</details>';
        
        return $output;
    }

    /**
     * Render Code Block Component
     *
     * Generates a syntax-highlighted code block using Prism.js classes. Handles
     * various programming languages and provides proper formatting for code content
     * like JSON responses, SQL queries, or stack traces.
     *
     * IMPORTANT: This method provides unescaped content to Prism.js for proper
     * tokenization. Prism.js will handle escaping during the highlighting process.
     *
     * GENERATED STRUCTURE:
     * ===================
     * 
     * ```html
     * <pre class="odcm-code-block">
     *     <code class="language-{language}">{unescaped_code}</code>
     * </pre>
     * ```
     *
     * @since 1.0.0
     *
     * @param string $code     Code content to display. Should be clean, trusted content.
     * @param string $language Language hint for Prism.js syntax highlighting.
     *                        Common values: 'json', 'sql', 'php', 'javascript', 'none'.
     * @return string HTML code block ready for Prism.js highlighting.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * $json_data = json_encode($api_response, JSON_PRETTY_PRINT);
     * echo $toolkit->render_code_block($json_data, 'json');
     * ```
     */
    public function render_code_block(string $code, string $language): string
    {
        // Sanitize language parameter for CSS class
        $safe_language = preg_replace('/[^a-z0-9\-]/', '', strtolower($language));
        
        // Basic security: strip any potential script tags from code content
        // while preserving the structure needed for syntax highlighting
        $safe_code = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '[SCRIPT_REMOVED]', $code);
        
        $output = '<pre class="odcm-code-block">';
        $output .= '<code class="language-' . esc_attr($safe_language) . '">';
        // Provide unescaped content to Prism.js - it will handle proper escaping during tokenization
        $output .= $safe_code;
        $output .= '</code>';
        $output .= '</pre>';
        
        return $output;
    }

    /**
     * Render Status Pill Component
     *
     * Creates a styled status indicator pill for displaying states like success,
     * error, warning, etc. Uses the existing CSS status pill system with proper
     * theming support.
     *
     * GENERATED STRUCTURE:
     * ===================
     * 
     * ```html
     * <span class="odcm-status-pill odcm-status-pill--{status_type}">{label}</span>
     * ```
     *
     * @since 1.0.0
     *
     * @param string $label       Display text for the status pill.
     * @param string $status_type Status type for CSS theming. Common values:
     *                           'critical', 'error', 'warning', 'success', 'info', 'debug'.
     * @return string Escaped HTML status pill element.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * echo $toolkit->render_status_pill('FAILED', 'error');
     * echo $toolkit->render_status_pill('SUCCESS', 'success');
     * ```
     */
    public function render_status_pill(string $label, string $status_type): string
    {
        // Map semantic types to existing pill variants
        $pill_variant_map = [
            'error' => 'error',
            'warning' => 'warning',
            'success' => 'success',
            'woocommerce' => 'woocommerce',
            'completion' => 'completed', // Use existing 'completed' variant
            'critical' => 'critical',
            'info' => 'info',
            'debug' => 'debug',
            'pending' => 'pending',
            'skipped' => 'skipped',
            'notice' => 'notice'
        ];
        
        // Get the appropriate pill variant, default to 'info' for unknown types
        $pill_class = $pill_variant_map[strtolower($status_type)] ?? 'info';
        
        $output = '<span class="odcm-status-pill odcm-status-pill--' . esc_attr($pill_class) . '">';
        $output .= esc_html($label);
        $output .= '</span>';
        
        return $output;
    }

    /**
     * Render Text Block Component
     *
     * Wraps plain text content in a paragraph element with proper escaping.
     * Used for simple text content that doesn't require special formatting.
     *
     * GENERATED STRUCTURE:
     * ===================
     * 
     * ```html
     * <p class="odcm-text-block">{escaped_text}</p>
     * ```
     *
     * @since 1.0.0
     *
     * @param string $text Plain text content to display.
     * @return string Escaped HTML paragraph element.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * echo $toolkit->render_text_block('Order processing completed successfully.');
     * ```
     */
    public function render_text_block(string $text): string
    {
        return '<p class="odcm-text-block">' . esc_html($text) . '</p>';
    }

    /**
     * Render Warning Message Component
     *
     * Creates a styled warning message with appropriate visual indicators.
     * Used for displaying automation bypass warnings, important notices, or
     * other warning-level information that requires user attention.
     *
     * GENERATED STRUCTURE:
     * ===================
     * 
     * ```html
     * <div class="odcm-warning-message">
     *     <span class="dashicons dashicons-warning"></span>
     *     <span class="odcm-warning-text">{escaped_text}</span>
     * </div>
     * ```
     *
     * @since 1.0.0
     *
     * @param string $text Warning message text to display.
     * @return string Escaped HTML warning message element.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * echo $toolkit->render_warning_message('This manual change may have bypassed automatic completion rules.');
     * ```
     */
    public function render_warning_message(string $text): string
    {
        $output = '<div class="odcm-warning-message">';
        $output .= '<span class="dashicons dashicons-warning"></span>';
        $output .= '<span class="odcm-warning-text">' . esc_html($text) . '</span>';
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Render Component Shell
     *
     * Creates the complete component wrapper with header and body content.
     * This is the master method that assembles the final component HTML using
     * the existing component structure and theming system.
     *
     * GENERATED STRUCTURE:
     * ===================
     * 
     * ```html
     * <div class="odcm-component odcm-component--{theme}">
     *     <div class="odcm-component__header">
     *         <span class="dashicons {icon}"></span>
     *         {header_title}
     *         {status_pill} <!-- Optional, based on registry or explicit override -->
     *     </div>
     *     <div class="odcm-component__body">
     *         {body_html}
     *     </div>
     * </div>
     * ```
     *
     * STATUS PILL PRIORITY LOGIC:
     * ==========================
     * 
     * 1. Explicit status pill (options['status_pill']) - highest priority
     * 2. Registry default status pill for the component type - medium priority
     * 3. No status pill - lowest priority (default behavior)
     *
     * @since 1.0.0
     *
     * @param string $header_title Component header title displayed in the header bar.
     * @param string $theme        Theme identifier for CSS styling (e.g., 'error', 'api', 'database').
     * @param string $body_html    Pre-rendered body content HTML.
     * @param array  $options {
     *     Optional configuration for the component shell.
     *     
     *     @type array $status_pill {
     *         Explicit status pill configuration that overrides registry defaults.
     *         
     *         @type string $label Status pill display text.
     *         @type string $type  Status pill type for CSS theming.
     *     }
     *     @type string $additional_classes Additional CSS classes to add to the component wrapper.
     *     @type string $component_id Component ID for registry lookup of status pills.
     *     @type mixed  $timestamp Raw timestamp value to format and display in bottom row.
     * }
     * @return string Complete component HTML ready for display.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * $content = $toolkit->render_key_value_list($error_data, 'Error Details');
     * 
     * // Basic usage (uses registry default status pill if available)
     * echo $toolkit->render_component_shell('Error Information', 'error', $content);
     * 
     * // With explicit status pill override
     * $options = [
     *     'status_pill' => [
     *         'label' => 'CRITICAL',
     *         'type' => 'error'
     *     ]
     * ];
     * echo $toolkit->render_component_shell('Error Information', 'error', $content, $options);
     * ```
     */
    public function render_component_shell(string $header_title, string $theme, string $body_html, array $options = []): string
    {
        // Sanitize theme for CSS class
        $safe_theme = preg_replace('/[^a-z0-9\-]/', '', strtolower($theme));
        
        // Get icon from registry based on theme
        $icon = $this->get_theme_icon($safe_theme);
        
        // Determine status pill to render (priority: explicit > registry > none)
        $status_pill_html = $this->resolve_status_pill($safe_theme, $options);
        
        // Build component wrapper classes
        $wrapper_classes = ['odcm-component', 'odcm-component--' . $safe_theme];
        
        // Add additional classes if provided
        if (isset($options['additional_classes']) && !empty($options['additional_classes'])) {
            $additional_classes = is_array($options['additional_classes']) 
                ? $options['additional_classes'] 
                : explode(' ', (string)$options['additional_classes']);
            
            // Sanitize and add each additional class
            foreach ($additional_classes as $class) {
                $sanitized_class = preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($class));
                if (!empty($sanitized_class)) {
                    $wrapper_classes[] = $sanitized_class;
                }
            }
        }
        
        $output = '<div class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';
        
        // Component header with new layout structure
        $output .= '<div class="odcm-component__header">';
        
        // Top row: icon + title + status pill
        $output .= '<div class="odcm-component__header-top">';
        $output .= '<div class="odcm-component__header-left">';
        $output .= '<span class="dashicons ' . esc_attr($icon) . '"></span>';
        $output .= '<span class="odcm-component__title">' . esc_html($header_title) . '</span>';
        $output .= '</div>';
        
        // Add status pill if one was resolved
        if (!empty($status_pill_html)) {
            $output .= $status_pill_html;
        }
        
        $output .= '</div>';
        
        // Second row: timestamp (aligned with icon)
        $output .= '<div class="odcm-component__header-bottom">';
        
        // Check if timestamp is provided in options
        $timestamp_html = '';
        if (isset($options['timestamp']) && $options['timestamp'] !== null) {
            // Format timestamp with millisecond precision for timeline verification
            $formatted_timestamp = $this->format_timestamp($options['timestamp'], [
                'include_milliseconds' => true,
                'fallback_format' => 'M j, Y g:i:s A' // Fallback without milliseconds for compatibility
            ]);
            $timestamp_html = esc_html($formatted_timestamp);
        } else {
            $timestamp_html = '<!-- Timestamp will be added by JavaScript -->';
        }
        
        $output .= '<span class="odcm-component__ts">' . $timestamp_html . '</span>';
        
        // Add event_type for debugging purposes
        if (isset($options['event_type']) && !empty($options['event_type'])) {
            $output .= '<span class="odcm-component__event-type" style="color: #666; font-size: 0.85em; margin-left: 8px;">(' . esc_html($options['event_type']) . ')</span>';
        }
        
        // Append optional timestamp metadata if provided
        $timestamp_field = $options['ts'] ?? null;
        if (!empty($timestamp_field)) {
            $started_fmt = $this->format_timestamp($timestamp_field, [
                'include_milliseconds' => false,
                'fallback_format' => 'M j, Y g:i:s A'
            ]);
            $meta_text = sprintf(__(' | Started: %s', 'order-daemon'), $started_fmt);
            if (!empty($options['trigger'])) {
                $meta_text .= ' ' . sprintf(__('(Trigger: %s)', 'order-daemon'), (string)$options['trigger']);
            }
            $output .= '<span class="odcm-component__ts">' . esc_html($meta_text) . '</span>';
        }
        $output .= '</div>';
        
        $output .= '<button class="odcm-component-expand-toggle" type="button" aria-label="' . esc_attr__('Toggle component expansion', 'order-daemon') . '">';
        $output .= '<span class="icon-expand dashicons dashicons-editor-expand"></span>';
        $output .= '<span class="icon-collapse dashicons dashicons-editor-contract"></span>';
        $output .= '</button>';
        $output .= '</div>';
        
        // Component content
        $output .= '<div class="odcm-component__body">';
        $output .= $body_html; // Content should already be escaped by calling methods
        $output .= '</div>';
        
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Render Interactive Section Component
     *
     * Creates an Alpine.js-powered interactive section using existing CSS classes
     * from the expandable section pattern. This method generates HTML pre-wired 
     * with Alpine.js directives for immediate interactivity while using only
     * existing CSS classes to avoid styling issues.
     *
     * ALPINE.JS INTEGRATION:
     * ======================
     * 
     * The generated HTML includes Alpine.js directives for:
     * - x-data: Component state management
     * - x-show: Conditional visibility
     * - @click: Event handling
     * - :class: Dynamic CSS classes
     * - x-transition: Smooth animations
     * 
     * GENERATED STRUCTURE (Using Existing CSS Classes):
     * ================================================
     * 
     * ```html
     * <details class="odcm-expandable-section" x-data="{ expanded: false }" :open="expanded">
     *     <summary class="odcm-expandable-title" @click.prevent="expanded = !expanded">
     *         {title}
     *     </summary>
     *     <div class="odcm-expandable-content" x-show="expanded" x-transition>
     *         {content_html}
     *     </div>
     * </details>
     * ```
     * 
     * DEFENSIVE PROGRAMMING:
     * =====================
     * 
     * - Null coalescing operators (??) for safe data access
     * - Input validation and sanitization
     * - Uses existing CSS classes to avoid styling issues
     * - Graceful degradation if Alpine.js is unavailable
     * - Proper escaping for all user-provided content
     *
     * @since 1.0.0
     *
     * @param string $title Section title displayed in the header.
     * @param string $content_html Pre-rendered content HTML (should already be escaped).
     * @param array $options {
     *     Optional configuration for the interactive section.
     *     
     *     @type bool   $initially_expanded Whether section starts expanded. Default false.
     *     @type string $theme             Theme identifier for styling. Default 'default'.
     *     @type bool   $component_wrapper Whether this is being used as a component wrapper. Default false.
     * }
     * @return string Complete interactive section HTML with Alpine.js directives.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * $content = $toolkit->render_code_block($json_data, 'json');
     * $options = [
     *     'initially_expanded' => false,
     *     'theme' => 'api'
     * ];
     * echo $toolkit->render_interactive_section('API Response', $content, $options);
     * ```
     */
    public function render_interactive_section(string $title, string $content_html, array $options = []): string
    {
        // Defensive programming: Use null coalescing for safe option access
        $initially_expanded = $options['initially_expanded'] ?? false;
        $theme = $options['theme'] ?? 'default';
        $component_wrapper = $options['component_wrapper'] ?? false;
        
        // If this is being used as a component wrapper, delegate to render_component_shell
        if ($component_wrapper) {
            return $this->render_component_shell($title, $theme, $content_html);
        }
        
        // Build Alpine.js data object
        $alpine_data_string = '{ expanded: ' . ($initially_expanded ? 'true' : 'false') . ' }';
        
        // Use existing expandable section structure with Alpine.js enhancements
        $output = '<details class="odcm-expandable-section" x-data="' . esc_attr($alpine_data_string) . '" :open="expanded">';
        
        // Use existing expandable title class with Alpine.js click handler
        $output .= '<summary class="odcm-expandable-title" @click.prevent="expanded = !expanded">';
        $output .= esc_html($title);
        $output .= '</summary>';
        
        // Use existing expandable content class with Alpine.js transitions
        $output .= '<div class="odcm-expandable-content" x-show="expanded" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 transform scale-95" x-transition:enter-end="opacity-100 transform scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 transform scale-100" x-transition:leave-end="opacity-0 transform scale-95">';
        
        // Main content
        $output .= $content_html; // Content should already be escaped by calling method
        
        $output .= '</div>'; // Close content
        $output .= '</details>'; // Close section
        
        return $output;
    }


    /**
     * Format Timestamp for Display
     *
     * Formats timestamps according to WordPress site's date and time format settings.
     * Handles various input formats and provides proper localization and timezone handling.
     *
     * SUPPORTED INPUT FORMATS:
     * =======================
     * 
     * - ISO8601 strings (e.g., '2024-01-15T10:30:00Z')
     * - MySQL datetime strings (e.g., '2024-01-15 10:30:00')
     * - Unix timestamps (numeric)
     * - Any string parseable by strtotime()
     * 
     * WORDPRESS INTEGRATION:
     * =====================
     * 
     * - Uses get_option('date_format') and get_option('time_format') for site settings
     * - Leverages wp_date() for proper localization and timezone handling
     * - Respects WordPress timezone settings
     * - Provides fallback formatting if site settings are unavailable
     *
     * @since 1.0.0
     *
     * @param mixed $timestamp Timestamp value in various formats.
     * @param array $options {
     *     Optional formatting configuration.
     *     
     *     @type bool   $include_time Whether to include time portion. Default true.
     *     @type bool   $include_date Whether to include date portion. Default true.
     *     @type bool   $include_milliseconds Whether to include millisecond precision. Default true.
     *     @type string $fallback_format Fallback format if WordPress settings unavailable. Default 'Y-m-d H:i:s'.
     * }
     * @return string Formatted timestamp according to site settings.
     *
     * @example
     * ```php
     * $toolkit = new PayloadComponentUIToolkit();
     * echo $toolkit->format_timestamp('2024-01-15T10:30:00Z'); // Uses site's date + time format
     * echo $toolkit->format_timestamp(1705312200, ['include_time' => false]); // Date only
     * ```
     */
    public function format_timestamp($timestamp, array $options = []): string
    {
        // Handle null or empty timestamps
        if ($timestamp === null || $timestamp === '') {
            return '';
        }
        
        // Extract options with defaults
        $include_time = $options['include_time'] ?? true;
        $include_date = $options['include_date'] ?? true;
        $include_milliseconds = $options['include_milliseconds'] ?? true;
        $fallback_format = $options['fallback_format'] ?? 'Y-m-d H:i:s';
        
        // Handle millisecond precision for original timestamp
        $milliseconds = '';
        if ($include_milliseconds && is_string($timestamp)) {
            // Extract milliseconds from ISO8601 or similar formats
            if (preg_match('/\.(\d{3})/', $timestamp, $matches)) {
                $milliseconds = '.' . $matches[1];
            } elseif (preg_match('/\.(\d{1,6})/', $timestamp, $matches)) {
                // Handle microseconds, truncate to milliseconds
                $milliseconds = '.' . substr($matches[1], 0, 3);
            }
        }
        
        // Convert timestamp to Unix timestamp for consistent processing
        $unix_timestamp = $this->parseTimestampToUnix($timestamp);
        
        if ($unix_timestamp === false) {
            // If parsing fails, return original value as string
            return (string)$timestamp;
        }
        
        // Get WordPress site's date and time format settings
        $date_format = get_option('date_format', 'F j, Y');
        $time_format = get_option('time_format', 'g:i a');
        
        // Build format string based on options
        $format_parts = [];
        if ($include_date) {
            $format_parts[] = $date_format;
        }
        if ($include_time) {
            $format_parts[] = $time_format;
            // Add seconds for more precision when including milliseconds
            if ($include_milliseconds) {
                $format_parts[] = ':s';
            }
        }
        
        $format = !empty($format_parts) ? implode(' ', $format_parts) : $fallback_format;
        
        // Use wp_date() for proper localization and timezone handling
        $formatted_time = '';
        if (function_exists('wp_date')) {
            $formatted_time = wp_date($format, $unix_timestamp);
        } else {
            // Fallback to PHP date() if wp_date() is not available
            $formatted_time = date($format, $unix_timestamp);
        }
        
        // Append milliseconds if available and requested
        if ($include_milliseconds && !empty($milliseconds)) {
            $formatted_time .= $milliseconds;
        }
        
        return $formatted_time;
    }

    /**
     * Parse Timestamp to Unix Timestamp
     *
     * Converts various timestamp formats to Unix timestamp for consistent processing.
     * Handles ISO8601, MySQL datetime, Unix timestamps, and other parseable formats.
     *
     * @since 1.0.0
     *
     * @param mixed $timestamp Input timestamp in various formats.
     * @return int|false Unix timestamp or false if parsing fails.
     */
    private function parseTimestampToUnix($timestamp)
    {
        // Handle numeric timestamps (Unix timestamps from optimized envelope structure)
        if (is_numeric($timestamp)) {
            $unix_ts = (int)$timestamp;
            // Validate reasonable timestamp range (after 1970, before year 2100)
            if ($unix_ts > 0 && $unix_ts < 4102444800) {
                return $unix_ts;
            }
        }
        
        // Handle string timestamps (legacy ISO 8601 format or other parseable formats)
        if (is_string($timestamp) && !empty($timestamp)) {
            // Check if it's a string representation of a Unix timestamp
            if (ctype_digit($timestamp)) {
                $unix_ts = (int)$timestamp;
                if ($unix_ts > 0 && $unix_ts < 4102444800) {
                    return $unix_ts;
                }
            }
            
            // Try to parse ISO 8601 or other formats with strtotime()
            $parsed = strtotime($timestamp);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        
        return false;
    }

    /**
     * Resolve Status Pill for Component
     *
     * Implements priority logic for determining which status pill to render:
     * 1. Explicit status pill from options (highest priority)
     * 2. Registry default status pill for component type (medium priority)
     * 3. No status pill (lowest priority)
     *
     * @since 1.0.0
     *
     * @param string $theme   Sanitized theme identifier.
     * @param array  $options Component options that may contain explicit status pill and component_id.
     * @return string Status pill HTML or empty string if no pill should be rendered.
     */
    private function resolve_status_pill(string $theme, array $options): string
    {
        // Priority 1: Explicit status pill from options
        if (isset($options['status_pill']) && is_array($options['status_pill'])) {
            $explicit_pill = $options['status_pill'];
            if (isset($explicit_pill['label']) && isset($explicit_pill['type'])) {
                return $this->render_status_pill($explicit_pill['label'], $explicit_pill['type']);
            }
        }
        
        // Priority 2: Registry default status pill for component type
        if (function_exists('odcm_get_payload_component_type')) {
            try {
                // Use component_id if provided, otherwise fall back to theme
                $lookup_id = isset($options['component_id']) ? $options['component_id'] : $theme;
                $component_metadata = odcm_get_payload_component_type($lookup_id);
                
                if ($component_metadata && isset($component_metadata['status_pill']) && is_array($component_metadata['status_pill'])) {
                    $registry_pill = $component_metadata['status_pill'];
                    if (isset($registry_pill['label']) && isset($registry_pill['type'])) {
                        return $this->render_status_pill($registry_pill['label'], $registry_pill['type']);
                    }
                }
            } catch (\Exception $e) {
                // Silently handle registry errors - component should still render without status pill
                $lookup_id = isset($options['component_id']) ? $options['component_id'] : $theme;
                error_log('PayloadComponentUIToolkit: Failed to retrieve registry metadata for component "' . $lookup_id . '": ' . $e->getMessage());
            }
        }
        
        // Priority 3: No status pill
        return '';
    }

    /**
     * Get Theme Icon
     *
     * Maps theme identifiers to appropriate Dashicons for component headers.
     * Provides fallback icon for unknown themes.
     *
     * @since 1.0.0
     *
     * @param string $theme Theme identifier.
     * @return string Dashicons CSS class.
     */
    private function get_theme_icon(string $theme): string
    {
        $icon_map = [
            'error'           => 'dashicons-warning',
            'api'             => 'dashicons-cloud',
            'database'        => 'dashicons-database',
            'performance'     => 'dashicons-performance',
            'woocommerce'     => 'dashicons-cart',
            'rule'            => 'dashicons-admin-settings',
            'rule-management' => 'dashicons-edit',
            'system'          => 'dashicons-admin-tools',
            'payment'         => 'dashicons-money-alt',
            'fallback'        => 'dashicons-text-page',
            'default'         => 'dashicons-admin-generic',
        ];
        
        return $icon_map[$theme] ?? 'dashicons-admin-generic';
    }

    /**
     * Render Expandable Key-Value Section (Smart Renderer)
     *
     * Creates a nested, expandable key-value section that intelligently handles
     * various data structures to create a clean, readable, and robust debug view.
     *
     * RENDERING LOGIC:
     * ================
     * - Top-level keys with scalar values are rendered as a standard key-value pair.
     * - Top-level keys with array values are rendered as their own titled sub-section.
     * - Deeper nested objects/arrays are rendered as pretty-printed JSON code blocks.
     *
     * @since 1.0.0
     *
     * @param string $title The main title for the expandable section.
     * @param array  $data  The associative array of data to render.
     * @return string The complete HTML for the expandable section.
     */
    public function render_expandable_key_value_section(string $title, array $data): string
    {
        $content_html = '';

        foreach ($data as $key => $value) {
            $section_title = ucwords(str_replace('_', ' ', (string)$key));

            if (is_array($value)) {
                // If the value is an array with only scalar values, render as a simple list.
                if ($this->is_simple_key_value_array($value)) {
                    $content_html .= $this->render_key_value_list($value, $section_title);
                } else {
                    // Otherwise, render as a pretty-printed JSON block inside its own section.
                    $json_content = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $code_block = $this->render_code_block($json_content, 'json');
                    $content_html .= $this->render_key_value_list([], $section_title); // Title only
                    $content_html .= $code_block;
                }
            } elseif (is_scalar($value) || is_null($value)) {
                // Render simple key-value pairs at the top-level
                $content_html .= $this->render_key_value_list([$section_title => (string)$value]);
            }
        }

        if (empty(trim($content_html))) {
            return '';
        }

        return $this->render_expandable_section($title, $content_html);
    }

    /**
     * Helper to check if an array is a simple key-value list (no nested arrays).
     *
     * @param array $arr The array to check.
     * @return bool True if the array contains only scalar values.
     */
    private function is_simple_key_value_array(array $arr): bool
    {
        foreach ($arr as $value) {
            if (is_array($value)) {
                return false;
            }
        }
        return true;
    }
}
