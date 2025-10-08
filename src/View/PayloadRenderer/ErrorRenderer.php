<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Error Renderer
 *
 * Renders error data including error messages, stack traces, and debugging
 * information with proper formatting and severity indicators.
 *
 * This renderer focuses purely on content rendering while the base class
 * handles all structural concerns (headers, icons, component wrapper).
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.3.0
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * Error Renderer Class
 *
 * Handles rendering of error data with proper severity indicators,
 * stack traces, and debugging information.
 *
 * @since 1.0.0
 */
class ErrorRenderer extends PayloadComponentRenderer
{
    /**
     * Get Component ID for Registry Lookup
     *
     * @since 1.0.0
     *
     * @return string Component identifier.
     */
    protected function getComponentId(): string
    {
        return 'error_details';
    }

    /**
     * Render compact embedded error summary.
     *
     * Displays severity, optional code, and primary message as a concise
     * one-line snippet for high-volume error events. Falls back to the
     * parent default (full component) if insufficient data is provided.
     *
     * @param array $data Error data
     * @return string HTML
     */
    public function renderEmbeddedContent(array $data): string
    {
        // Severity/level
        $severity = '';
        if (isset($data['severity'])) {
            $severity = sanitize_key((string)$data['severity']);
        } elseif (isset($data['level'])) {
            $severity = sanitize_key((string)$data['level']);
        }

        // Primary message
        $message = '';
        if (isset($data['error']) && is_string($data['error'])) {
            $message = sanitize_text_field($data['error']);
        } elseif (isset($data['error_message']) && is_string($data['error_message'])) {
            $message = sanitize_text_field($data['error_message']);
        } elseif (!empty($data['exception']['message']) && is_string($data['exception']['message'])) {
            $message = sanitize_text_field($data['exception']['message']);
        }

        // Error code
        $code = '';
        if (isset($data['error_code'])) {
            $code = sanitize_text_field((string)$data['error_code']);
        } elseif (isset($data['code'])) {
            $code = sanitize_text_field((string)$data['code']);
        }

        if ($message === '' && $severity === '' && $code === '') {
            return parent::renderEmbeddedContent($data);
        }

        $parts = [];
        if ($severity !== '') {
            $parts[] = strtoupper($severity);
        } else {
            $parts[] = __('ERROR', 'order-daemon');
        }
        if ($code !== '') {
            $parts[] = '(' . $code . ')';
        }
        if ($message !== '') {
            $parts[] = '– ' . $message;
        }

        $text = trim(implode(' ', $parts));

        $level_class_map = [
            'warning' => 'odcm-level-warning',
            'error'   => 'odcm-level-error',
            'debug'   => 'odcm-level-debug',
            'info'    => 'odcm-level-info',
        ];
        $level_class = $severity !== '' ? ($level_class_map[$severity] ?? 'odcm-level-error') : 'odcm-level-error';

        return '<span class="odcm-error-inline ' . esc_attr($level_class) . '">' . esc_html($text) . '</span>';
    }

    /**
     * Render Error Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform complex data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing comprehensive error handling and fallback behavior
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data Error data containing error messages and details.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];
        
        // === DATA ADAPTATION PHASE ===
        // Transform complex data into simple, clean formats using private adapters
        
        // Adapt primary error message
        $error_message_html = $this->adaptErrorMessage($data, $toolkit);
        if ($error_message_html !== null) {
            $html_parts[] = $error_message_html;
        }
        
        // Adapt exception details
        $exception_html = $this->adaptExceptionDetails($data, $toolkit);
        if ($exception_html !== null) {
            $html_parts[] = $exception_html;
        }
        
        // Adapt stack trace information
        $stack_trace_html = $this->adaptStackTrace($data, $toolkit);
        if ($stack_trace_html !== null) {
            $html_parts[] = $stack_trace_html;
        }
        
        // Adapt error metadata (code, file, line)
        $error_metadata_html = $this->adaptErrorMetadata($data, $toolkit);
        if ($error_metadata_html !== null) {
            $html_parts[] = $error_metadata_html;
        }
        
        // Adapt severity level
        $severity_html = $this->adaptSeverityLevel($data, $toolkit);
        if ($severity_html !== null) {
            $html_parts[] = $severity_html;
        }
        
        // Adapt context information
        $context_html = $this->adaptContextData($data, $toolkit);
        if ($context_html !== null) {
            $html_parts[] = $context_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific error components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        return implode('', $html_parts);
    }

    /**
     * Adapt Error Message Data
     *
     * Transforms error message data into clean format for UI toolkit rendering.
     * Handles both string messages and complex error objects with defensive programming.
     *
     * @since 1.0.0
     *
     * @param array $data Raw error data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for error message or null if no message found.
     */
    private function adaptErrorMessage(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        // Defensive programming: Use null coalescing for safe data access
        $error_message = $data['error'] ?? $data['error_message'] ?? null;
        
        if ($error_message === null) {
            return null;
        }
        
        // Handle string messages directly
        if (is_string($error_message)) {
            return $toolkit->render_text_block($error_message);
        }
        
        // Handle complex error objects as JSON
        $json_content = json_encode($error_message, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        return $toolkit->render_code_block($json_content, 'json');
    }

    /**
     * Adapt Exception Details Data
     *
     * Transforms exception object data into clean key-value pairs for display.
     * Extracts class, message, and code with defensive null checking.
     *
     * @since 1.0.0
     *
     * @param array $data Raw error data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for exception details or null if no exception found.
     */
    private function adaptExceptionDetails(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $exception = $data['exception'] ?? null;
        
        if (!is_array($exception)) {
            return null;
        }
        
        // Build clean exception data with defensive programming
        $exception_data = [];
        
        if (isset($exception['class']) && is_string($exception['class'])) {
            $exception_data['Exception Class'] = $exception['class'];
        }
        
        if (isset($exception['message']) && is_string($exception['message'])) {
            $exception_data['Message'] = $exception['message'];
        }
        
        if (isset($exception['code'])) {
            $exception_data['Code'] = (string)$exception['code'];
        }
        
        if (isset($exception['file']) && is_string($exception['file'])) {
            $exception_data['File'] = $exception['file'];
        }
        
        if (isset($exception['line'])) {
            $exception_data['Line'] = (string)$exception['line'];
        }
        
        // Only render if we have meaningful exception data
        if (empty($exception_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($exception_data, 'Exception Details');
    }

    /**
     * Adapt Stack Trace Data
     *
     * Transforms stack trace data (string or array) into formatted code blocks.
     * Handles both string traces and array-based traces with proper formatting.
     *
     * @since 1.0.0
     *
     * @param array $data Raw error data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for stack trace or null if no trace found.
     */
    private function adaptStackTrace(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $stack_trace = $data['stack_trace'] ?? $data['trace'] ?? null;
        
        if ($stack_trace === null) {
            return null;
        }
        
        $trace_content = '';
        
        if (is_string($stack_trace)) {
            $trace_content = $stack_trace;
        } elseif (is_array($stack_trace)) {
            $trace_content = $this->formatStackTraceArray($stack_trace);
        } else {
            // Handle unexpected trace types
            $trace_content = (string)$stack_trace;
        }
        
        // Only render if we have meaningful trace content
        if (empty(trim($trace_content))) {
            return null;
        }
        
        $code_html = $toolkit->render_code_block($trace_content, 'none');
        
        // Use expandable section for better UX with large stack traces
        return $toolkit->render_expandable_section('Stack Trace', $code_html);
    }

    /**
     * Adapt Error Metadata
     *
     * Transforms error metadata (code, file, line) into clean key-value pairs.
     * Provides comprehensive error location and identification information.
     *
     * @since 1.0.0
     *
     * @param array $data Raw error data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for error metadata or null if no metadata found.
     */
    private function adaptErrorMetadata(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $error_details = [];
        
        // Defensive programming: Check each field individually
        $error_code = $data['error_code'] ?? $data['code'] ?? null;
        if ($error_code !== null) {
            $error_details['Error Code'] = (string)$error_code;
        }
        
        $file = $data['file'] ?? null;
        if (is_string($file) && !empty($file)) {
            $error_details['File'] = $file;
        }
        
        $line = $data['line'] ?? null;
        if ($line !== null) {
            $error_details['Line'] = (string)$line;
        }
        
        $function = $data['function'] ?? null;
        if (is_string($function) && !empty($function)) {
            $error_details['Function'] = $function;
        }
        
        // Only render if we have meaningful metadata
        if (empty($error_details)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($error_details, 'Error Details');
    }

    /**
     * Adapt Severity Level Data
     *
     * Transforms severity level into styled status pill with appropriate theming.
     * Maps various severity formats to consistent visual representation.
     *
     * @since 1.0.0
     *
     * @param array $data Raw error data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for severity pill or null if no severity found.
     */
    private function adaptSeverityLevel(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $severity = $data['severity'] ?? $data['level'] ?? null;
        
        if ($severity === null) {
            return null;
        }
        
        $severity_string = (string)$severity;
        $status_type = $this->mapSeverityToStatusType($severity_string);
        
        return $toolkit->render_status_pill(strtoupper($severity_string), $status_type);
    }

    /**
     * Adapt Context Data
     *
     * Transforms context information into formatted JSON code blocks.
     * Provides additional debugging information in an expandable format.
     *
     * @since 1.0.0
     *
     * @param array $data Raw error data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for context data or null if no context found.
     */
    private function adaptContextData(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $context = $data['context'] ?? null;
        
        if ($context === null || (is_array($context) && empty($context))) {
            return null;
        }
        
        $context_json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($context_json, 'json');
        
        // Use expandable section for better UX with large context data
        return $toolkit->render_expandable_section('Context Information', $code_html);
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized error data into JSON format as a fallback.
     * Ensures that all error data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw error data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for fallback data display.
     */
    private function adaptFallbackData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        return $toolkit->render_code_block($json_content, 'json');
    }

    /**
     * Check if this renderer can handle the provided data
     *
     * @since 1.0.0
     *
     * @param array $data Data to check.
     * @return bool True if this renderer can handle the data.
     */
    public function canHandle(array $data): bool
    {
        // Check for error-related keys
        $error_keys = [
            'error', 'error_message', 'exception', 'stack_trace', 'trace',
            'error_code', 'code', 'severity', 'level', 'fatal_error'
        ];
        
        foreach ($error_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        return false;
    }


    /**
     * Format Stack Trace Array
     *
     * Converts an array-based stack trace into a readable string format.
     * Used by the new adapter pattern to prepare stack trace data for display.
     *
     * @since 1.0.0
     *
     * @param array $stack_trace Array-based stack trace data.
     * @return string Formatted stack trace string.
     */
    private function formatStackTraceArray(array $stack_trace): string
    {
        $formatted_lines = [];
        
        foreach ($stack_trace as $index => $frame) {
            $line = "#{$index} ";
            
            if (is_array($frame)) {
                if (isset($frame['file'])) {
                    $line .= $frame['file'];
                    if (isset($frame['line'])) {
                        $line .= ":{$frame['line']}";
                    }
                    $line .= ' ';
                }
                
                if (isset($frame['function'])) {
                    if (isset($frame['class'])) {
                        $line .= $frame['class'] . '::';
                    }
                    $line .= $frame['function'] . '()';
                }
            } else {
                $line .= (string)$frame;
            }
            
            $formatted_lines[] = $line;
        }
        
        return implode("\n", $formatted_lines);
    }

    /**
     * Map Severity to Status Type
     *
     * Maps error severity levels to appropriate status pill types for consistent
     * visual representation across the UI toolkit.
     *
     * @since 1.0.0
     *
     * @param mixed $severity Severity level.
     * @return string Status type for UI toolkit.
     */
    private function mapSeverityToStatusType($severity): string
    {
        $severity_lower = strtolower((string)$severity);
        
        switch ($severity_lower) {
            case 'critical':
            case 'fatal':
            case 'emergency':
                return 'critical';
            case 'error':
                return 'error';
            case 'warning':
            case 'warn':
                return 'warning';
            case 'notice':
            case 'info':
            case 'information':
                return 'info';
            case 'debug':
                return 'debug';
            default:
                return 'error'; // Default to error for unknown severities
        }
    }

    /**
     * Get CSS class for severity level
     *
     * @since 1.0.0
     * @deprecated 1.3.0 Use mapSeverityToStatusType() instead for new UI toolkit.
     *
     * @param mixed $severity Severity level.
     * @return string CSS class.
     */
    private function getSeverityClass($severity): string
    {
        $severity_lower = strtolower((string)$severity);
        
        switch ($severity_lower) {
            case 'critical':
            case 'fatal':
            case 'emergency':
                return 'odcm-severity-critical';
            case 'error':
                return 'odcm-severity-error';
            case 'warning':
            case 'warn':
                return 'odcm-severity-warning';
            case 'notice':
            case 'info':
            case 'information':
                return 'odcm-severity-info';
            case 'debug':
                return 'odcm-severity-debug';
            default:
                return 'odcm-severity-unknown';
        }
    }
}
