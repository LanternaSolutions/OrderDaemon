<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * Database Query Renderer
 *
 * Renders database query information including SQL statements, execution times,
 * and query metadata with proper syntax highlighting.
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
 * Database Query Renderer Class
 *
 * Handles rendering of database query data with SQL syntax highlighting
 * and performance metrics.
 *
 * @since 1.0.0
 */
class DatabaseQueryRenderer extends PayloadComponentRenderer
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
        return 'database_query';
    }

    /**
     * Render Database Query Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform complex database data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing Alpine.js interactive features for SQL queries and performance analysis
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data Database query data.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];
        
        // === DATA ADAPTATION PHASE ===
        // Transform complex database data into simple, clean formats using private adapters
        
        // Adapt SQL query with interactive features
        $sql_query_html = $this->adaptSqlQuery($data, $toolkit);
        if ($sql_query_html !== null) {
            $html_parts[] = $sql_query_html;
        }
        
        // Adapt query metadata and performance metrics
        $query_metadata_html = $this->adaptQueryMetadata($data, $toolkit);
        if ($query_metadata_html !== null) {
            $html_parts[] = $query_metadata_html;
        }
        
        // Adapt performance status indicator
        $performance_status_html = $this->adaptPerformanceStatus($data, $toolkit);
        if ($performance_status_html !== null) {
            $html_parts[] = $performance_status_html;
        }
        
        // Adapt query parameters
        $parameters_html = $this->adaptQueryParameters($data, $toolkit);
        if ($parameters_html !== null) {
            $html_parts[] = $parameters_html;
        }
        
        // Adapt query results/output
        $results_html = $this->adaptQueryResults($data, $toolkit);
        if ($results_html !== null) {
            $html_parts[] = $results_html;
        }
        
        // Adapt database connection info
        $connection_html = $this->adaptConnectionInfo($data, $toolkit);
        if ($connection_html !== null) {
            $html_parts[] = $connection_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific database components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        return implode('', $html_parts);
    }

    /**
     * Adapt SQL Query Data
     *
     * Transforms SQL query data into interactive code blocks with formatting and copy features.
     * Handles various SQL statement types with appropriate syntax highlighting.
     *
     * @since 1.0.0
     *
     * @param array $data Raw database data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for SQL query or null if no query found.
     */
    private function adaptSqlQuery(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $query = $data['query'] ?? $data['sql'] ?? null;
        
        if (!is_string($query) || empty(trim($query))) {
            return null;
        }
        
        $formatted_query = $this->formatSqlQuery($query);
        $code_html = $toolkit->render_code_block($formatted_query, 'sql');
        
        // Use interactive section with SQL-specific actions
        return $toolkit->render_interactive_section('SQL Query', $code_html, [
            'initially_expanded' => true, // SQL queries are often the main focus
            'theme' => 'database',
            'action_buttons' => [
                [
                    'label' => 'Copy SQL',
                    'action' => 'copySqlQuery',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Format SQL',
                    'action' => 'formatSqlQuery',
                    'icon' => 'dashicons-editor-code'
                ],
                [
                    'label' => 'Explain Query',
                    'action' => 'explainSqlQuery',
                    'icon' => 'dashicons-info'
                ]
            ]
        ]);
    }

    /**
     * Adapt Query Metadata
     *
     * Transforms query execution metadata into clean key-value pairs.
     * Handles execution time, affected rows, query type, and other metadata.
     *
     * @since 1.0.0
     *
     * @param array $data Raw database data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for query metadata or null if no metadata found.
     */
    private function adaptQueryMetadata(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $query_details = [];
        
        // Defensive programming: Check each field individually
        $execution_time = $data['execution_time'] ?? $data['time'] ?? null;
        if ($execution_time !== null && is_numeric($execution_time)) {
            $formatted_time = $this->formatTime((float)$execution_time);
            $query_details['Execution Time'] = $formatted_time;
        }
        
        $affected_rows = $data['affected_rows'] ?? $data['rows'] ?? null;
        if ($affected_rows !== null) {
            $query_details['Affected Rows'] = (string)$affected_rows . ' rows';
        }
        
        $query_type = $data['query_type'] ?? $data['type'] ?? null;
        if ($query_type !== null) {
            $query_details['Query Type'] = strtoupper((string)$query_type);
        }
        
        $database = $data['database'] ?? $data['db'] ?? null;
        if (is_string($database) && !empty($database)) {
            $query_details['Database'] = $database;
        }
        
        $table = $data['table'] ?? null;
        if (is_string($table) && !empty($table)) {
            $query_details['Table'] = $table;
        }
        
        // Only render if we have meaningful metadata
        if (empty($query_details)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($query_details, 'Query Details');
    }

    /**
     * Adapt Performance Status
     *
     * Creates performance status indicators based on execution time.
     * Maps execution time ranges to visual performance indicators.
     *
     * @since 1.0.0
     *
     * @param array $data Raw database data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for performance status or null if no timing data found.
     */
    private function adaptPerformanceStatus(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $execution_time = $data['execution_time'] ?? $data['time'] ?? null;
        
        if ($execution_time === null || !is_numeric($execution_time)) {
            return null;
        }
        
        $performance_status = $this->getPerformanceStatus((float)$execution_time);
        return $toolkit->render_status_pill($performance_status['label'], $performance_status['type']);
    }

    /**
     * Adapt Query Parameters
     *
     * Transforms query parameters into formatted display.
     * Handles both simple key-value parameters and complex parameter objects.
     *
     * @since 1.0.0
     *
     * @param array $data Raw database data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for query parameters or null if no parameters found.
     */
    private function adaptQueryParameters(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $parameters = $data['parameters'] ?? $data['params'] ?? $data['bindings'] ?? null;
        
        if ($parameters === null || (is_array($parameters) && empty($parameters))) {
            return null;
        }
        
        // Handle simple key-value parameters
        if (is_array($parameters) && $this->isSimpleKeyValueArray($parameters)) {
            return $toolkit->render_key_value_list($parameters, 'Query Parameters');
        }
        
        // Handle complex parameters as JSON
        $json_content = json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Query Parameters', $code_html, [
            'initially_expanded' => false,
            'theme' => 'database',
            'action_buttons' => [
                [
                    'label' => 'Copy Parameters',
                    'action' => 'copyQueryParameters',
                    'icon' => 'dashicons-clipboard'
                ]
            ]
        ]);
    }

    /**
     * Adapt Query Results
     *
     * Transforms query result data into formatted display.
     * Handles result sets, error messages, and query output.
     *
     * @since 1.0.0
     *
     * @param array $data Raw database data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for query results or null if no results found.
     */
    private function adaptQueryResults(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $results = $data['results'] ?? $data['result'] ?? $data['output'] ?? null;
        
        if ($results === null) {
            return null;
        }
        
        // Handle error results
        if (isset($data['error']) || isset($data['mysql_error'])) {
            $error_message = $data['error'] ?? $data['mysql_error'];
            return $toolkit->render_text_block('Error: ' . (string)$error_message);
        }
        
        // Handle result sets as JSON
        $json_content = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Query Results', $code_html, [
            'initially_expanded' => false,
            'theme' => 'database',
            'action_buttons' => [
                [
                    'label' => 'Copy Results',
                    'action' => 'copyQueryResults',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Export CSV',
                    'action' => 'exportResultsCsv',
                    'icon' => 'dashicons-download'
                ]
            ]
        ]);
    }

    /**
     * Adapt Connection Info
     *
     * Transforms database connection information into display format.
     * Handles connection details, server info, and database metadata.
     *
     * @since 1.0.0
     *
     * @param array $data Raw database data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for connection info or null if no connection data found.
     */
    private function adaptConnectionInfo(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $connection_data = [];
        
        // Defensive programming: Check each field individually
        $host = $data['host'] ?? $data['server'] ?? null;
        if (is_string($host) && !empty($host)) {
            $connection_data['Host'] = $host;
        }
        
        $port = $data['port'] ?? null;
        if ($port !== null) {
            $connection_data['Port'] = (string)$port;
        }
        
        $charset = $data['charset'] ?? $data['character_set'] ?? null;
        if (is_string($charset) && !empty($charset)) {
            $connection_data['Charset'] = $charset;
        }
        
        $engine = $data['engine'] ?? $data['storage_engine'] ?? null;
        if (is_string($engine) && !empty($engine)) {
            $connection_data['Engine'] = $engine;
        }
        
        $version = $data['version'] ?? $data['mysql_version'] ?? null;
        if (is_string($version) && !empty($version)) {
            $connection_data['Version'] = $version;
        }
        
        // Only render if we have meaningful connection data
        if (empty($connection_data)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($connection_data, 'Connection Details');
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized database data into JSON format as a fallback.
     * Ensures that all database data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw database data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for fallback data display.
     */
    private function adaptFallbackData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        return $toolkit->render_expandable_section('Raw Database Data', $code_html);
    }

    /**
     * Check if Array is Simple Key-Value
     *
     * Determines if an array contains only simple string/numeric values
     * suitable for key-value list display.
     *
     * @since 1.0.0
     *
     * @param array $array Array to check.
     * @return bool True if array is simple key-value.
     */
    private function isSimpleKeyValueArray(array $array): bool
    {
        foreach ($array as $value) {
            if (!is_string($value) && !is_numeric($value) && !is_bool($value) && $value !== null) {
                return false;
            }
        }
        return true;
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
        // Check for database-related keys
        $db_keys = [
            'query', 'sql', 'database_query', 'db_query', 'mysql_query',
            'execution_time', 'affected_rows', 'query_type'
        ];
        
        foreach ($db_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get Performance Status
     *
     * Maps execution time to performance status for status pill display.
     *
     * @since 1.0.0
     *
     * @param float $time Execution time in seconds.
     * @return array Status label and type.
     */
    private function getPerformanceStatus(float $time): array
    {
        if ($time < 0.01) {
            return ['label' => 'EXCELLENT', 'type' => 'success'];
        } elseif ($time < 0.1) {
            return ['label' => 'GOOD', 'type' => 'success'];
        } elseif ($time < 0.5) {
            return ['label' => 'FAIR', 'type' => 'warning'];
        } else {
            return ['label' => 'SLOW', 'type' => 'error'];
        }
    }


    /**
     * Format SQL query for better readability
     *
     * @since 1.0.0
     *
     * @param string $query SQL query string.
     * @return string Formatted SQL query.
     */
    private function formatSqlQuery(string $query): string
    {
        // Basic SQL formatting - add line breaks after major keywords
        $keywords = ['SELECT', 'FROM', 'WHERE', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT'];
        
        $formatted = $query;
        foreach ($keywords as $keyword) {
            $formatted = preg_replace('/\b' . $keyword . '\b/i', "\n" . $keyword, $formatted);
        }
        
        return trim($formatted);
    }

    /**
     * Format time value to human readable format
     *
     * @since 1.0.0
     *
     * @param float $time Time in seconds.
     * @return string Formatted time string.
     */
    private function formatTime(float $time): string
    {
        if ($time >= 1.0) {
            return number_format($time, 3) . 's';
        } elseif ($time >= 0.001) {
            return number_format($time * 1000, 2) . 'ms';
        } else {
            return number_format($time * 1000000, 0) . 'μs';
        }
    }

    /**
     * Get performance CSS class based on execution time
     *
     * @since 1.0.0
     *
     * @param float $time Execution time in seconds.
     * @return string CSS class.
     */
    private function getPerformanceClass(float $time): string
    {
        if ($time < 0.01) return 'odcm-performance-excellent';
        if ($time < 0.1) return 'odcm-performance-good';
        if ($time < 0.5) return 'odcm-performance-fair';
        return 'odcm-performance-poor';
    }

    /**
     * Get CSS class for query type
     *
     * @since 1.0.0
     *
     * @param mixed $query_type Query type.
     * @return string CSS class.
     */
    private function getQueryTypeClass($query_type): string
    {
        $type_lower = strtolower((string)$query_type);
        
        switch ($type_lower) {
            case 'select':
                return 'odcm-query-type-select';
            case 'insert':
                return 'odcm-query-type-insert';
            case 'update':
                return 'odcm-query-type-update';
            case 'delete':
                return 'odcm-query-type-delete';
            case 'create':
            case 'alter':
            case 'drop':
                return 'odcm-query-type-ddl';
            default:
                return 'odcm-query-type-unknown';
        }
    }
}
