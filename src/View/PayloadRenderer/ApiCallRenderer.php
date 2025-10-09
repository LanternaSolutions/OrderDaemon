<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * API Call Renderer
 *
 * Renders API call data including request details, response information,
 * and HTTP status codes with proper syntax highlighting and formatting.
 *
 * This renderer focuses purely on content rendering while the base class
 * handles all structural concerns (headers, icons, component wrapper).
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 * @since   1.0.0
 */

// Prevent direct access to this file
if (!defined('WPINC')) {
    die;
}

/**
 * API Call Renderer Class
 *
 * Handles rendering of API call data with request/response details,
 * HTTP status codes, and proper JSON formatting.
 *
 * @since 1.0.0
 */
class ApiCallRenderer extends PayloadComponentRenderer
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
        return 'api_call';
    }

    /**
     * Render API Call Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform complex API data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing Alpine.js interactive features for better UX
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data API call data containing request/response information.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];
        
        // === DATA ADAPTATION PHASE ===
        // Transform complex API data into simple, clean formats using private adapters
        
        // Adapt API request information
        $request_html = $this->adaptApiRequest($data, $toolkit);
        if ($request_html !== null) {
            $html_parts[] = $request_html;
        }
        
        // Adapt API response information
        $response_html = $this->adaptApiResponse($data, $toolkit);
        if ($response_html !== null) {
            $html_parts[] = $response_html;
        }
        
        // Adapt connection/performance details
        $connection_html = $this->adaptConnectionDetails($data, $toolkit);
        if ($connection_html !== null) {
            $html_parts[] = $connection_html;
        }
        
        // Adapt HTTP headers
        $headers_html = $this->adaptHttpHeaders($data, $toolkit);
        if ($headers_html !== null) {
            $html_parts[] = $headers_html;
        }
        
        // Adapt alternative HTTP request formats
        $http_request_html = $this->adaptHttpRequest($data, $toolkit);
        if ($http_request_html !== null) {
            $html_parts[] = $http_request_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific API components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        return implode('', $html_parts);
    }

    /**
     * Adapt API Request Data
     *
     * Transforms API request data into clean format for UI toolkit rendering.
     * Handles request metadata and body content with defensive programming.
     *
     * @since 1.0.0
     *
     * @param array $data Raw API data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for API request or null if no request found.
     */
    private function adaptApiRequest(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $api_request = $data['api_request'] ?? null;
        
        if (!is_array($api_request) || empty($api_request)) {
            return null;
        }
        
        $html_parts = [];
        
        // Adapt request metadata
        $request_metadata = $this->adaptApiRequestMetadata($api_request);
        if (!empty($request_metadata)) {
            $html_parts[] = $toolkit->render_key_value_list($request_metadata, 'Request Details');
        }
        
        // Adapt request body with interactive features
        $request_body_html = $this->adaptRequestBody($api_request, $toolkit);
        if ($request_body_html !== null) {
            $html_parts[] = $request_body_html;
        }
        
        return empty($html_parts) ? null : implode('', $html_parts);
    }

    /**
     * Adapt API Response Data
     *
     * Transforms API response data into clean format for UI toolkit rendering.
     * Handles response metadata and body content with status indicators.
     *
     * @since 1.0.0
     *
     * @param array $data Raw API data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for API response or null if no response found.
     */
    private function adaptApiResponse(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $api_response = $data['api_response'] ?? null;
        
        if (!is_array($api_response) || empty($api_response)) {
            return null;
        }
        
        $html_parts = [];
        
        // Adapt response metadata with status pill
        $response_metadata = $this->adaptApiResponseMetadata($api_response);
        if (!empty($response_metadata)) {
            $html_parts[] = $toolkit->render_key_value_list($response_metadata, 'Response Details');
        }
        
        // Add status code pill if available
        $status_pill_html = $this->adaptStatusCodePill($api_response, $toolkit);
        if ($status_pill_html !== null) {
            $html_parts[] = $status_pill_html;
        }
        
        // Adapt response body with interactive features
        $response_body_html = $this->adaptResponseBody($api_response, $toolkit);
        if ($response_body_html !== null) {
            $html_parts[] = $response_body_html;
        }
        
        return empty($html_parts) ? null : implode('', $html_parts);
    }

    /**
     * Adapt Connection Details
     *
     * Transforms cURL info and connection data into performance metrics.
     * Provides timing and transfer information with proper formatting.
     *
     * @since 1.0.0
     *
     * @param array $data Raw API data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for connection details or null if no data found.
     */
    private function adaptConnectionDetails(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $curl_info = $data['curl_info'] ?? null;
        
        if (!is_array($curl_info) || empty($curl_info)) {
            return null;
        }
        
        $connection_data = [];
        
        // Defensive programming: Check each field individually
        if (isset($curl_info['total_time'])) {
            $connection_data['Total Time'] = number_format((float)$curl_info['total_time'], 3) . 's';
        }
        
        if (isset($curl_info['connect_time'])) {
            $connection_data['Connect Time'] = number_format((float)$curl_info['connect_time'], 3) . 's';
        }
        
        if (isset($curl_info['size_download'])) {
            $connection_data['Download Size'] = $this->formatBytes((int)$curl_info['size_download']);
        }
        
        if (isset($curl_info['speed_download'])) {
            $connection_data['Download Speed'] = $this->formatBytes((int)$curl_info['speed_download']) . '/s';
        }
        
        if (isset($curl_info['namelookup_time'])) {
            $connection_data['DNS Lookup'] = number_format((float)$curl_info['namelookup_time'], 3) . 's';
        }
        
        if (isset($curl_info['pretransfer_time'])) {
            $connection_data['Pre-transfer Time'] = number_format((float)$curl_info['pretransfer_time'], 3) . 's';
        }
        
        // Only render if we have meaningful connection data
        if (empty($connection_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($connection_data, 'Connection Performance');
    }

    /**
     * Adapt HTTP Headers
     *
     * Transforms HTTP headers into formatted display with interactive features.
     * Handles both request and response headers separately.
     *
     * @since 1.0.0
     *
     * @param array $data Raw API data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for HTTP headers or null if no headers found.
     */
    private function adaptHttpHeaders(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $html_parts = [];
        
        // Adapt request headers
        $request_headers = $data['api_request']['headers'] ?? $data['request_headers'] ?? null;
        if (is_array($request_headers) && !empty($request_headers)) {
            $headers_json = json_encode($request_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
            $code_html = $toolkit->render_code_block($headers_json, 'json');
            $html_parts[] = $toolkit->render_expandable_section('Request Headers', $code_html);
        }
        
        // Adapt response headers
        $response_headers = $data['api_response']['headers'] ?? $data['response_headers'] ?? null;
        if (is_array($response_headers) && !empty($response_headers)) {
            $headers_json = json_encode($response_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
            $code_html = $toolkit->render_code_block($headers_json, 'json');
            $html_parts[] = $toolkit->render_expandable_section('Response Headers', $code_html);
        }
        
        return empty($html_parts) ? null : implode('', $html_parts);
    }

    /**
     * Adapt HTTP Request (Alternative Format)
     *
     * Handles alternative HTTP request formats that don't follow the api_request structure.
     *
     * @since 1.0.0
     *
     * @param array $data Raw API data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for HTTP request or null if no data found.
     */
    private function adaptHttpRequest(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $http_request = $data['http_request'] ?? null;
        
        if (!is_array($http_request) || empty($http_request)) {
            return null;
        }
        
        $json_content = json_encode($http_request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_expandable_section('HTTP Request', $code_html);
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized API data into JSON format as a fallback.
     * Ensures that all API data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw API data.
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
        // Check for API-related keys
        $api_keys = ['api_request', 'api_response', 'http_request', 'curl_info', 'rest_request', 'rest_response'];
        
        foreach ($api_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Adapt API Request Data
     *
     * Transforms complex API request data into simple key-value pairs for display.
     *
     * @since 1.0.0
     *
     * @param array $request Request data.
     * @return array Adapted data for UI toolkit.
     */
    private function adaptApiRequestData(array $request): array
    {
        $adapted = [];
        
        if (isset($request['url'])) {
            $adapted['URL'] = $request['url'];
        }
        
        if (isset($request['method'])) {
            $adapted['Method'] = strtoupper($request['method']);
        }
        
        if (isset($request['timeout'])) {
            $adapted['Timeout'] = $request['timeout'] . 's';
        }
        
        if (isset($request['user_agent'])) {
            $adapted['User Agent'] = $request['user_agent'];
        }
        
        return $adapted;
    }

    /**
     * Adapt API Response Data
     *
     * Transforms complex API response data into simple key-value pairs for display.
     *
     * @since 1.0.0
     *
     * @param array $response Response data.
     * @return array Adapted data for UI toolkit.
     */
    private function adaptApiResponseData(array $response): array
    {
        $adapted = [];
        
        if (isset($response['status_code']) || isset($response['http_code'])) {
            $status_code = $response['status_code'] ?? $response['http_code'];
            $adapted['Status Code'] = $status_code;
        }
        
        if (isset($response['content_type'])) {
            $adapted['Content Type'] = $response['content_type'];
        }
        
        if (isset($response['content_length'])) {
            $adapted['Content Length'] = $this->formatBytes((int)$response['content_length']);
        }
        
        return $adapted;
    }

    /**
     * Adapt cURL Info Data
     *
     * Transforms cURL info data into simple key-value pairs for display.
     *
     * @since 1.0.0
     *
     * @param array $curl_info cURL info data.
     * @return array Adapted data for UI toolkit.
     */
    private function adaptCurlInfoData(array $curl_info): array
    {
        $adapted = [];
        
        if (isset($curl_info['total_time'])) {
            $adapted['Total Time'] = number_format((float)$curl_info['total_time'], 3) . 's';
        }
        
        if (isset($curl_info['connect_time'])) {
            $adapted['Connect Time'] = number_format((float)$curl_info['connect_time'], 3) . 's';
        }
        
        if (isset($curl_info['size_download'])) {
            $adapted['Download Size'] = $this->formatBytes((int)$curl_info['size_download']);
        }
        
        if (isset($curl_info['speed_download'])) {
            $adapted['Download Speed'] = $this->formatBytes((int)$curl_info['speed_download']) . '/s';
        }
        
        return $adapted;
    }

    /**
     * Format Request Body
     *
     * Formats request body data for code block display.
     *
     * @since 1.0.0
     *
     * @param mixed $body_data Request body data.
     * @return array Formatted content and language.
     */
    private function formatRequestBody($body_data): array
    {
        if (is_string($body_data)) {
            $decoded = json_decode($body_data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'content' => json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'language' => 'json'
                ];
            }
            return ['content' => $body_data, 'language' => 'none'];
        }
        
        return [
            'content' => json_encode($body_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'language' => 'json'
        ];
    }

    /**
     * Format Response Body
     *
     * Formats response body data for code block display.
     *
     * @since 1.0.0
     *
     * @param mixed $body_data Response body data.
     * @return array Formatted content and language.
     */
    private function formatResponseBody($body_data): array
    {
        if (is_string($body_data)) {
            $decoded = json_decode($body_data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'content' => json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'language' => 'json'
                ];
            }
            return ['content' => $body_data, 'language' => 'none'];
        }
        
        return [
            'content' => json_encode($body_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'language' => 'json'
        ];
    }


    /**
     * Adapt API Request Metadata
     *
     * Transforms API request metadata into clean key-value pairs.
     * Handles URL, method, timeout, and other request configuration.
     *
     * @since 1.0.0
     *
     * @param array $request Request data.
     * @return array Adapted metadata for UI toolkit.
     */
    private function adaptApiRequestMetadata(array $request): array
    {
        $metadata = [];
        
        // Defensive programming: Check each field individually
        if (isset($request['url']) && is_string($request['url'])) {
            $metadata['URL'] = $request['url'];
        }
        
        if (isset($request['method']) && is_string($request['method'])) {
            $metadata['Method'] = strtoupper($request['method']);
        }
        
        if (isset($request['timeout'])) {
            $metadata['Timeout'] = (string)$request['timeout'] . 's';
        }
        
        if (isset($request['user_agent']) && is_string($request['user_agent'])) {
            $metadata['User Agent'] = $request['user_agent'];
        }
        
        if (isset($request['blocking'])) {
            $metadata['Blocking'] = $request['blocking'] ? 'Yes' : 'No';
        }
        
        return $metadata;
    }

    /**
     * Adapt Request Body
     *
     * Transforms request body data into interactive code blocks.
     * Handles JSON, form data, and other body formats.
     *
     * @since 1.0.0
     *
     * @param array $request Request data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for request body or null if no body found.
     */
    private function adaptRequestBody(array $request, PayloadComponentUIToolkit $toolkit): ?string
    {
        $body_data = $request['body'] ?? $request['data'] ?? null;
        
        if ($body_data === null || (is_array($body_data) && empty($body_data))) {
            return null;
        }
        
        $formatted_body = $this->formatRequestBody($body_data);
        $code_html = $toolkit->render_code_block($formatted_body['content'], $formatted_body['language']);
        
        return $toolkit->render_expandable_section('Request Body', $code_html);
    }

    /**
     * Adapt API Response Metadata
     *
     * Transforms API response metadata into clean key-value pairs.
     * Handles status codes, content types, and response timing.
     *
     * @since 1.0.0
     *
     * @param array $response Response data.
     * @return array Adapted metadata for UI toolkit.
     */
    private function adaptApiResponseMetadata(array $response): array
    {
        $metadata = [];
        
        // Defensive programming: Check each field individually
        if (isset($response['content_type']) && is_string($response['content_type'])) {
            $metadata['Content Type'] = $response['content_type'];
        }
        
        if (isset($response['content_length'])) {
            $metadata['Content Length'] = $this->formatBytes((int)$response['content_length']);
        }
        
        if (isset($response['response_time'])) {
            $metadata['Response Time'] = number_format((float)$response['response_time'], 3) . 's';
        }
        
        if (isset($response['cookies']) && is_array($response['cookies']) && !empty($response['cookies'])) {
            $metadata['Cookies'] = count($response['cookies']) . ' cookie(s)';
        }
        
        return $metadata;
    }

    /**
     * Adapt Status Code Pill
     *
     * Creates a status pill for HTTP status codes with appropriate theming.
     * Maps status code ranges to visual indicators.
     *
     * @since 1.0.0
     *
     * @param array $response Response data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for status pill or null if no status found.
     */
    private function adaptStatusCodePill(array $response, PayloadComponentUIToolkit $toolkit): ?string
    {
        $status_code = $response['status_code'] ?? $response['http_code'] ?? null;
        
        if ($status_code === null) {
            return null;
        }
        
        $status_code = (int)$status_code;
        $status_type = $this->mapStatusCodeToType($status_code);
        
        return $toolkit->render_status_pill((string)$status_code, $status_type);
    }

    /**
     * Adapt Response Body
     *
     * Transforms response body data into interactive code blocks.
     * Handles JSON, HTML, XML, and other response formats.
     *
     * @since 1.0.0
     *
     * @param array $response Response data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for response body or null if no body found.
     */
    private function adaptResponseBody(array $response, PayloadComponentUIToolkit $toolkit): ?string
    {
        $body_data = $response['body'] ?? null;
        
        if ($body_data === null || (is_string($body_data) && empty(trim($body_data)))) {
            return null;
        }
        
        $formatted_body = $this->formatResponseBody($body_data);
        $code_html = $toolkit->render_code_block($formatted_body['content'], $formatted_body['language']);
        
        return $toolkit->render_expandable_section('Response Body', $code_html);
    }

    /**
     * Map Status Code to Type
     *
     * Maps HTTP status codes to appropriate status pill types.
     * Provides visual indicators for different response categories.
     *
     * @since 1.0.0
     *
     * @param int $status_code HTTP status code.
     * @return string Status type for UI toolkit.
     */
    private function mapStatusCodeToType(int $status_code): string
    {
        if ($status_code >= 200 && $status_code < 300) {
            return 'success';
        } elseif ($status_code >= 300 && $status_code < 400) {
            return 'info';
        } elseif ($status_code >= 400 && $status_code < 500) {
            return 'warning';
        } elseif ($status_code >= 500) {
            return 'error';
        } else {
            return 'info';
        }
    }

    /**
     * Format bytes to human readable format
     *
     * @since 1.0.0
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' B';
    }
}
