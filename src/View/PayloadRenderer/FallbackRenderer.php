<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Fallback Renderer
 *
 * Renders any payload data that doesn't match specific component types.
 * Provides a generic JSON display with proper formatting.
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
 * Fallback Renderer Class
 *
 * Handles rendering of generic payload data with JSON formatting.
 *
 * @since 1.0.0
 */
class FallbackRenderer extends PayloadComponentRenderer
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
        return 'fallback';
    }

    /**
     * Render Fallback Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform generic data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing Alpine.js interactive features for generic data exploration
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data Generic payload data.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];
        
        // === DATA ADAPTATION PHASE ===
        // Transform generic data into appropriate display formats using private adapters
        
        // Handle empty data case
        if (empty($data)) {
            return $this->adaptEmptyData($toolkit);
        }
        
        // Adapt simple key-value data
        $simple_html = $this->adaptSimpleKeyValueData($data, $toolkit);
        if ($simple_html !== null) {
            $html_parts[] = $simple_html;
        }
        
        // Adapt URL data
        $url_html = $this->adaptUrlData($data, $toolkit);
        if ($url_html !== null) {
            $html_parts[] = $url_html;
        }
        
        // Adapt JSON string data
        $json_string_html = $this->adaptJsonStringData($data, $toolkit);
        if ($json_string_html !== null) {
            $html_parts[] = $json_string_html;
        }
        
        // Adapt complex nested data
        $complex_html = $this->adaptComplexData($data, $toolkit);
        if ($complex_html !== null) {
            $html_parts[] = $complex_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific adaptations were applied, render as raw JSON
        if (empty($html_parts)) {
            $fallback_html = $this->adaptRawData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        return implode('', $html_parts);
    }

    /**
     * Adapt Empty Data
     *
     * Handles the case when no data is available.
     * Provides a user-friendly message for empty payloads.
     *
     * @since 1.0.0
     *
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for empty data display.
     */
    private function adaptEmptyData(PayloadComponentUIToolkit $toolkit): string
    {
        return $toolkit->render_text_block('No additional data available');
    }

    /**
     * Adapt Simple Key-Value Data
     *
     * Transforms simple key-value pairs into formatted display.
     * Handles basic data types with appropriate formatting.
     *
     * @since 1.0.0
     *
     * @param array $data Raw data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for simple data or null if data is not simple.
     */
    private function adaptSimpleKeyValueData(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        if (!$this->isSimpleKeyValueData($data)) {
            return null;
        }
        
        $adapted_data = [];
        
        foreach ($data as $key => $value) {
            // Defensive programming: Handle various key formats
            $formatted_key = ucwords(str_replace(['_', '-'], ' ', (string)$key));
            
            // Defensive programming: Handle various value types
            if ($value === null) {
                $adapted_data[$formatted_key] = 'null';
            } elseif (is_bool($value)) {
                $adapted_data[$formatted_key] = $value ? 'true' : 'false';
            } elseif (is_numeric($value)) {
                $adapted_data[$formatted_key] = (string)$value;
            } elseif (is_string($value)) {
                // Don't process URLs or JSON strings here - they have dedicated adapters
                if (!$this->isUrl($value) && !$this->isJsonString($value)) {
                    $adapted_data[$formatted_key] = $value;
                }
            } else {
                $adapted_data[$formatted_key] = (string)$value;
            }
        }
        
        // Only render if we have meaningful simple data
        if (empty($adapted_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($adapted_data, 'Additional Data');
    }

    /**
     * Adapt URL Data
     *
     * Transforms URL values into interactive links.
     * Handles various URL formats and provides click-to-open functionality.
     *
     * @since 1.0.0
     *
     * @param array $data Raw data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for URL data or null if no URLs found.
     */
    private function adaptUrlData(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $url_data = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->isUrl($value)) {
                $formatted_key = ucwords(str_replace(['_', '-'], ' ', (string)$key));
                $url_data[$formatted_key] = $this->formatUrl($value);
            }
        }
        
        // Only render if we have URL data
        if (empty($url_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($url_data, 'URLs & Links');
    }

    /**
     * Adapt JSON String Data
     *
     * Transforms JSON string values into formatted, interactive JSON displays.
     * Handles embedded JSON with syntax highlighting and expansion.
     *
     * @since 1.0.0
     *
     * @param array $data Raw data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for JSON string data or null if no JSON strings found.
     */
    private function adaptJsonStringData(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $json_sections = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->isJsonString($value)) {
                $formatted_key = ucwords(str_replace(['_', '-'], ' ', (string)$key));
                $decoded = json_decode($value, true);
                $formatted_json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
                $code_html = $toolkit->render_code_block($formatted_json, 'json');
                
                $json_sections[] = $toolkit->render_interactive_section($formatted_key, $code_html, [
                    'initially_expanded' => false,
                    'theme' => 'fallback',
                    'action_buttons' => [
                        [
                            'label' => 'Copy JSON',
                            'action' => 'copyJsonData',
                            'icon' => 'dashicons-clipboard'
                        ],
                        [
                            'label' => 'Validate JSON',
                            'action' => 'validateJsonData',
                            'icon' => 'dashicons-yes'
                        ]
                    ]
                ]);
            }
        }
        
        // Only render if we have JSON string data
        if (empty($json_sections)) {
            return null;
        }
        
        return implode('', $json_sections);
    }

    /**
     * Adapt Complex Data
     *
     * Transforms complex nested data structures into interactive displays.
     * Handles arrays, objects, and mixed data types.
     *
     * @since 1.0.0
     *
     * @param array $data Raw data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for complex data or null if data is not complex.
     */
    private function adaptComplexData(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $complex_sections = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $formatted_key = ucwords(str_replace(['_', '-'], ' ', (string)$key));
                $json_content = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
                $code_html = $toolkit->render_code_block($json_content, 'json');
                
                $complex_sections[] = $toolkit->render_interactive_section($formatted_key, $code_html, [
                    'initially_expanded' => false,
                    'theme' => 'fallback',
                    'action_buttons' => [
                        [
                            'label' => 'Copy Data',
                            'action' => 'copyComplexData',
                            'icon' => 'dashicons-clipboard'
                        ],
                        [
                            'label' => 'Export JSON',
                            'action' => 'exportComplexData',
                            'icon' => 'dashicons-download'
                        ]
                    ]
                ]);
            }
        }
        
        // Only render if we have complex data
        if (empty($complex_sections)) {
            return null;
        }
        
        return implode('', $complex_sections);
    }

    /**
     * Adapt Raw Data
     *
     * Transforms any remaining data into raw JSON format as final fallback.
     * Ensures all data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for raw data display.
     */
    private function adaptRawData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Raw Data', $code_html, [
            'initially_expanded' => true, // Show raw data expanded by default
            'theme' => 'fallback',
            'action_buttons' => [
                [
                    'label' => 'Copy All',
                    'action' => 'copyRawData',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Export JSON',
                    'action' => 'exportRawData',
                    'icon' => 'dashicons-download'
                ],
                [
                    'label' => 'Pretty Print',
                    'action' => 'prettyPrintData',
                    'icon' => 'dashicons-editor-code'
                ]
            ]
        ]);
    }

    /**
     * Format URL
     *
     * Formats URL values into clickable links with proper escaping.
     *
     * @since 1.0.0
     *
     * @param string $url URL to format.
     * @return string Formatted URL HTML.
     */
    private function formatUrl(string $url): string
    {
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
    }

    /**
     * Check if this renderer can handle the provided data
     *
     * @since 1.0.0
     *
     * @param array $data Data to check.
     * @return bool Always returns true as this is the fallback renderer.
     */
    public function canHandle(array $data): bool
    {
        // Fallback renderer can handle any data
        return true;
    }

    /**
     * Check if data is simple key-value pairs
     *
     * @since 1.0.0
     *
     * @param array $data Data to check.
     * @return bool True if data is simple key-value pairs.
     */
    private function isSimpleKeyValueData(array $data): bool
    {
        foreach ($data as $value) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Adapt Simple Data
     *
     * Transforms simple key-value data into a format suitable for the UIToolkit.
     * Handles different value types appropriately.
     *
     * @since 1.0.0
     *
     * @param array $data Simple data array.
     * @return array Adapted data for UIToolkit consumption.
     */
    private function adaptSimpleData(array $data): array
    {
        $adapted = [];
        $toolkit = new PayloadComponentUIToolkit();
        
        foreach ($data as $key => $value) {
            $formatted_key = ucwords(str_replace('_', ' ', $key));
            
            // Handle different value types
            if (is_bool($value)) {
                $adapted[$formatted_key] = $value ? 'true' : 'false';
            } elseif (is_numeric($value)) {
                $adapted[$formatted_key] = (string) $value;
            } elseif (is_string($value) && $this->isUrl($value)) {
                $adapted[$formatted_key] = '<a href="' . esc_url($value) . '" target="_blank">' . esc_html($value) . '</a>';
            } elseif (is_string($value) && $this->isJsonString($value)) {
                $decoded = json_decode($value, true);
                $formatted_json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                // Use UIToolkit for proper Prism.js integration instead of manual HTML
                $adapted[$formatted_key] = $toolkit->render_code_block($formatted_json, 'json');
            } else {
                $adapted[$formatted_key] = (string) $value;
            }
        }
        
        return $adapted;
    }

    /**
     * Check if string is a valid URL
     *
     * @since 1.0.0
     *
     * @param string $value String to check.
     * @return bool True if valid URL.
     */
    private function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check if string is valid JSON
     *
     * @since 1.0.0
     *
     * @param string $value String to check.
     * @return bool True if valid JSON.
     */
    private function isJsonString(string $value): bool
    {
        if (!is_string($value) || strlen($value) < 2) {
            return false;
        }
        
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
