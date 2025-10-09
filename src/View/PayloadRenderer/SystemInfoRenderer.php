<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * System Info Renderer
 *
 * Renders system information including PHP version, WordPress version,
 * server details, and other system-related data.
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

class SystemInfoRenderer extends PayloadComponentRenderer
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
        return 'system_info';
    }

    /**
     * Render System Info Content - Data Adapter Pattern Implementation
     *
     * This method implements the pure Data Adapter Pattern by:
     * 1. Using private adapt*() methods to transform complex system data into simple arrays/strings
     * 2. Delegating ALL HTML generation to PayloadComponentUIToolkit
     * 3. Implementing defensive programming with null coalescing operators
     * 4. Providing Alpine.js interactive features for system analysis and diagnostics
     *
     * The method acts as a pure orchestrator that coordinates data adaptation
     * and delegates presentation concerns to the centralized UI toolkit.
     *
     * @since 1.0.0
     *
     * @param array $data System information data.
     * @return string Content HTML for the component body.
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $html_parts = [];
        
        // === DATA ADAPTATION PHASE ===
        // Transform complex system data into simple, clean formats using private adapters
        
        // Adapt PHP information
        $php_html = $this->adaptPhpInformation($data, $toolkit);
        if ($php_html !== null) {
            $html_parts[] = $php_html;
        }
        
        // Adapt WordPress information
        $wp_html = $this->adaptWordPressInformation($data, $toolkit);
        if ($wp_html !== null) {
            $html_parts[] = $wp_html;
        }
        
        // Adapt server information
        $server_html = $this->adaptServerInformation($data, $toolkit);
        if ($server_html !== null) {
            $html_parts[] = $server_html;
        }
        
        // Adapt plugin information
        $plugin_html = $this->adaptPluginInformation($data, $toolkit);
        if ($plugin_html !== null) {
            $html_parts[] = $plugin_html;
        }
        
        // Adapt environment information
        $env_html = $this->adaptEnvironmentInformation($data, $toolkit);
        if ($env_html !== null) {
            $html_parts[] = $env_html;
        }
        
        // Adapt system health/status indicators
        $health_html = $this->adaptSystemHealth($data, $toolkit);
        if ($health_html !== null) {
            $html_parts[] = $health_html;
        }
        
        // Adapt configuration details
        $config_html = $this->adaptConfigurationDetails($data, $toolkit);
        if ($config_html !== null) {
            $html_parts[] = $config_html;
        }
        
        // Adapt additional system data
        $additional_html = $this->adaptAdditionalSystemData($data, $toolkit);
        if ($additional_html !== null) {
            $html_parts[] = $additional_html;
        }
        
        // === FALLBACK HANDLING ===
        // If no specific system components were found, render raw data
        if (empty($html_parts)) {
            $fallback_html = $this->adaptFallbackData($data, $toolkit);
            $html_parts[] = $fallback_html;
        }
        
        return implode('', $html_parts);
    }

    /**
     * Adapt PHP Information
     *
     * Transforms PHP configuration and version data into clean key-value pairs.
     * Handles PHP version, memory limits, execution time, and other PHP settings.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for PHP information or null if no PHP data found.
     */
    private function adaptPhpInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $php_info = [];
        
        // Defensive programming: Extract PHP data from various possible keys
        $php = $data['php'] ?? null;
        $php_version = $data['php_version'] ?? null;
        
        if (is_array($php)) {
            // Extract PHP details from php object
            foreach ($php as $key => $value) {
                if (!empty($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $php_info[$formatted_key] = (string)$value;
                }
            }
        } elseif ($php_version !== null) {
            $php_info['PHP Version'] = (string)$php_version;
        }
        
        // Extract additional PHP fields from root level
        $memory_limit = $data['memory_limit'] ?? null;
        if ($memory_limit !== null && !empty($memory_limit)) {
            $php_info['Memory Limit'] = (string)$memory_limit;
        }
        
        $max_execution_time = $data['max_execution_time'] ?? null;
        if ($max_execution_time !== null && !empty($max_execution_time)) {
            $php_info['Max Execution Time'] = (string)$max_execution_time . 's';
        }
        
        $upload_max_filesize = $data['upload_max_filesize'] ?? null;
        if ($upload_max_filesize !== null && !empty($upload_max_filesize)) {
            $php_info['Upload Max Filesize'] = (string)$upload_max_filesize;
        }
        
        $post_max_size = $data['post_max_size'] ?? null;
        if ($post_max_size !== null && !empty($post_max_size)) {
            $php_info['Post Max Size'] = (string)$post_max_size;
        }
        
        // Only render if we have meaningful PHP data
        if (empty($php_info)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($php_info, 'PHP Information');
    }

    /**
     * Adapt WordPress Information
     *
     * Transforms WordPress configuration and version data into formatted display.
     * Handles WordPress version, theme, multisite, and other WP settings.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for WordPress information or null if no WP data found.
     */
    private function adaptWordPressInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $wp_info = [];
        
        // Defensive programming: Extract WordPress data from various possible keys
        $wordpress = $data['wordpress'] ?? null;
        $wp_version = $data['wp_version'] ?? null;
        
        if (is_array($wordpress)) {
            // Extract WordPress details from wordpress object
            foreach ($wordpress as $key => $value) {
                if (!empty($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    if (is_bool($value)) {
                        $wp_info[$formatted_key] = $value ? 'Yes' : 'No';
                    } else {
                        $wp_info[$formatted_key] = (string)$value;
                    }
                }
            }
        } elseif ($wp_version !== null) {
            $wp_info['WordPress Version'] = (string)$wp_version;
        }
        
        // Extract additional WordPress fields from root level
        $theme = $data['theme'] ?? $data['active_theme'] ?? null;
        if ($theme !== null && !empty($theme)) {
            $wp_info['Active Theme'] = (string)$theme;
        }
        
        $multisite = $data['multisite'] ?? null;
        if ($multisite !== null) {
            $wp_info['Multisite'] = is_bool($multisite) ? ($multisite ? 'Yes' : 'No') : (string)$multisite;
        }
        
        $debug = $data['wp_debug'] ?? $data['debug'] ?? null;
        if ($debug !== null) {
            $wp_info['Debug Mode'] = is_bool($debug) ? ($debug ? 'Enabled' : 'Disabled') : (string)$debug;
        }
        
        // Only render if we have meaningful WordPress data
        if (empty($wp_info)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($wp_info, 'WordPress Information');
    }

    /**
     * Adapt Server Information
     *
     * Transforms server configuration and environment data into formatted display.
     * Handles server software, OS, database, and other server details.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for server information or null if no server data found.
     */
    private function adaptServerInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $server_info = [];
        
        // Defensive programming: Extract server data from various possible keys
        $server = $data['server'] ?? $data['server_info'] ?? null;
        
        if (is_array($server)) {
            // Extract server details from server object
            foreach ($server as $key => $value) {
                if (!empty($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $server_info[$formatted_key] = (string)$value;
                }
            }
        } elseif ($server !== null) {
            $server_info['Server'] = (string)$server;
        }
        
        // Extract additional server fields from root level
        $os = $data['os'] ?? $data['operating_system'] ?? null;
        if ($os !== null && !empty($os)) {
            $server_info['Operating System'] = (string)$os;
        }
        
        $database = $data['database'] ?? $data['db_version'] ?? null;
        if ($database !== null && !empty($database)) {
            $server_info['Database'] = (string)$database;
        }
        
        $web_server = $data['web_server'] ?? null;
        if ($web_server !== null && !empty($web_server)) {
            $server_info['Web Server'] = (string)$web_server;
        }
        
        // Only render if we have meaningful server data
        if (empty($server_info)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($server_info, 'Server Information');
    }

    /**
     * Adapt Plugin Information
     *
     * Transforms plugin configuration and version data into formatted display.
     * Handles plugin version, status, and other plugin-specific details.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for plugin information or null if no plugin data found.
     */
    private function adaptPluginInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $plugin_info = [];
        
        // Defensive programming: Extract plugin data from various possible keys
        $plugin = $data['plugin'] ?? null;
        $plugin_version = $data['plugin_version'] ?? null;
        
        if (is_array($plugin)) {
            // Extract plugin details from plugin object
            foreach ($plugin as $key => $value) {
                if (!empty($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    if (is_bool($value)) {
                        $plugin_info[$formatted_key] = $value ? 'Yes' : 'No';
                    } else {
                        $plugin_info[$formatted_key] = (string)$value;
                    }
                }
            }
        } elseif ($plugin_version !== null) {
            $plugin_info['Plugin Version'] = (string)$plugin_version;
        }
        
        // Extract additional plugin fields from root level
        $plugin_name = $data['plugin_name'] ?? null;
        if ($plugin_name !== null && !empty($plugin_name)) {
            $plugin_info['Plugin Name'] = (string)$plugin_name;
        }
        
        $plugin_status = $data['plugin_status'] ?? null;
        if ($plugin_status !== null && !empty($plugin_status)) {
            $plugin_info['Status'] = ucfirst((string)$plugin_status);
        }
        
        // Only render if we have meaningful plugin data
        if (empty($plugin_info)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($plugin_info, 'Plugin Information');
    }

    /**
     * Adapt Environment Information
     *
     * Transforms environment configuration data into formatted display.
     * Handles environment type, debug settings, and other environment details.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for environment information or null if no environment data found.
     */
    private function adaptEnvironmentInformation(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $env_info = [];
        
        // Defensive programming: Extract environment data from various possible keys
        $environment = $data['environment'] ?? $data['env'] ?? null;
        
        if (is_array($environment)) {
            // Extract environment details from environment object
            foreach ($environment as $key => $value) {
                if (!empty($value) || is_bool($value)) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    if (is_bool($value)) {
                        $env_info[$formatted_key] = $value ? 'Yes' : 'No';
                    } else {
                        $env_info[$formatted_key] = (string)$value;
                    }
                }
            }
        } elseif ($environment !== null) {
            $env_info['Environment'] = (string)$environment;
        }
        
        // Extract additional environment fields from root level
        $env_type = $data['env_type'] ?? null;
        if ($env_type !== null && !empty($env_type)) {
            $env_info['Environment Type'] = ucfirst((string)$env_type);
        }
        
        // Only render if we have meaningful environment data
        if (empty($env_info)) {
            return null;
        }
        
        return $toolkit->render_key_value_list($env_info, 'Environment Information');
    }

    /**
     * Adapt System Health
     *
     * Creates system health status indicators based on various system metrics.
     * Maps system values to visual health indicators.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for system health or null if no health data found.
     */
    private function adaptSystemHealth(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $health_pills = [];
        
        // PHP version health
        $php_version = $data['php_version'] ?? null;
        if ($php_version !== null) {
            $health_status = $this->getPhpVersionHealth($php_version);
            $health_pills[] = $toolkit->render_status_pill('PHP: ' . $health_status['label'], $health_status['type']);
        }
        
        // Memory limit health
        $memory_limit = $data['memory_limit'] ?? null;
        if ($memory_limit !== null) {
            $health_status = $this->getMemoryLimitHealth($memory_limit);
            $health_pills[] = $toolkit->render_status_pill('MEMORY: ' . $health_status['label'], $health_status['type']);
        }
        
        // Debug mode status
        $debug = $data['wp_debug'] ?? $data['debug'] ?? null;
        if ($debug !== null) {
            $debug_status = is_bool($debug) ? $debug : (strtolower((string)$debug) === 'true');
            $status_type = $debug_status ? 'warning' : 'success';
            $status_label = $debug_status ? 'DEBUG ON' : 'DEBUG OFF';
            $health_pills[] = $toolkit->render_status_pill($status_label, $status_type);
        }
        
        // Only render if we have health indicators
        if (empty($health_pills)) {
            return null;
        }
        
        return implode('', $health_pills);
    }

    /**
     * Adapt Configuration Details
     *
     * Transforms detailed configuration data into interactive display.
     * Handles complex configuration objects and settings.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for configuration details or null if no config data found.
     */
    private function adaptConfigurationDetails(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $config = $data['config'] ?? $data['configuration'] ?? $data['settings'] ?? null;
        
        if (!is_array($config) || empty($config)) {
            return null;
        }
        
        $json_content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Configuration Details', $code_html, [
            'initially_expanded' => false,
            'theme' => 'system',
            'action_buttons' => [
                [
                    'label' => 'Copy Config',
                    'action' => 'copySystemConfig',
                    'icon' => 'dashicons-clipboard'
                ],
                [
                    'label' => 'Export Config',
                    'action' => 'exportSystemConfig',
                    'icon' => 'dashicons-download'
                ]
            ]
        ]);
    }

    /**
     * Adapt Additional System Data
     *
     * Transforms any additional system data not covered by core categories.
     * Handles custom system metrics and vendor-specific data.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string|null HTML for additional data or null if no additional data found.
     */
    private function adaptAdditionalSystemData(array $data, PayloadComponentUIToolkit $toolkit): ?string
    {
        $core_keys = [
            'php_version', 'php', 'wp_version', 'wordpress', 'server',
            'server_info', 'plugin_version', 'plugin', 'environment', 'env',
            'memory_limit', 'max_execution_time', 'theme', 'multisite',
            'upload_max_filesize', 'post_max_size', 'active_theme', 'wp_debug',
            'debug', 'os', 'operating_system', 'database', 'db_version',
            'web_server', 'plugin_name', 'plugin_status', 'env_type',
            'config', 'configuration', 'settings'
        ];
        
        $additional = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $core_keys, true)) {
                $additional[$key] = $value;
            }
        }
        
        if (empty($additional)) {
            return null;
        }
        
        $json_content = json_encode($additional, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        $code_html = $toolkit->render_code_block($json_content, 'json');
        
        return $toolkit->render_interactive_section('Additional System Data', $code_html, [
            'initially_expanded' => false,
            'theme' => 'system',
            'action_buttons' => [
                [
                    'label' => 'Copy Data',
                    'action' => 'copyAdditionalSystemData',
                    'icon' => 'dashicons-clipboard'
                ]
            ]
        ]);
    }

    /**
     * Adapt Fallback Data
     *
     * Transforms any unrecognized system data into JSON format as a fallback.
     * Ensures that all system data is displayed even if not specifically handled.
     *
     * @since 1.0.0
     *
     * @param array $data Raw system data.
     * @param PayloadComponentUIToolkit $toolkit UI toolkit instance.
     * @return string HTML for fallback data display.
     */
    private function adaptFallbackData(array $data, PayloadComponentUIToolkit $toolkit): string
    {
        $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?? '{}';
        return $toolkit->render_code_block($json_content, 'json');
    }

    /**
     * Get PHP Version Health
     *
     * Analyzes PHP version and returns health status.
     *
     * @since 1.0.0
     *
     * @param string $version PHP version string.
     * @return array Health status label and type.
     */
    private function getPhpVersionHealth(string $version): array
    {
        $version_number = (float)$version;
        
        if ($version_number >= 8.1) {
            return ['label' => 'EXCELLENT', 'type' => 'success'];
        } elseif ($version_number >= 7.4) {
            return ['label' => 'GOOD', 'type' => 'success'];
        } elseif ($version_number >= 7.0) {
            return ['label' => 'OUTDATED', 'type' => 'warning'];
        } else {
            return ['label' => 'CRITICAL', 'type' => 'error'];
        }
    }

    /**
     * Get Memory Limit Health
     *
     * Analyzes memory limit and returns health status.
     *
     * @since 1.0.0
     *
     * @param string $memory_limit Memory limit string.
     * @return array Health status label and type.
     */
    private function getMemoryLimitHealth(string $memory_limit): array
    {
        $memory_bytes = $this->parseMemoryLimit($memory_limit);
        
        if ($memory_bytes >= 512 * 1024 * 1024) { // 512MB
            return ['label' => 'EXCELLENT', 'type' => 'success'];
        } elseif ($memory_bytes >= 256 * 1024 * 1024) { // 256MB
            return ['label' => 'GOOD', 'type' => 'success'];
        } elseif ($memory_bytes >= 128 * 1024 * 1024) { // 128MB
            return ['label' => 'LOW', 'type' => 'warning'];
        } else {
            return ['label' => 'CRITICAL', 'type' => 'error'];
        }
    }

    /**
     * Parse Memory Limit
     *
     * Converts memory limit string to bytes.
     *
     * @since 1.0.0
     *
     * @param string $memory_limit Memory limit string.
     * @return int Memory limit in bytes.
     */
    private function parseMemoryLimit(string $memory_limit): int
    {
        $memory_limit = trim($memory_limit);
        $last_char = strtolower($memory_limit[strlen($memory_limit) - 1]);
        $number = (int)$memory_limit;
        
        switch ($last_char) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
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
        // Check for system-related keys
        $system_keys = [
            'php_version', 'php', 'wp_version', 'wordpress', 'server',
            'server_info', 'plugin_version', 'plugin', 'environment', 'env',
            'system_info', 'system'
        ];
        
        foreach ($system_keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract PHP Info
     *
     * Extracts and formats PHP information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted PHP information.
     */
    private function extractPhpInfo(array $data): array
    {
        $php_info = [];
        
        if (isset($data['php_version'])) {
            $php_info['PHP Version'] = $data['php_version'];
        } elseif (isset($data['php'])) {
            if (is_string($data['php'])) {
                $php_info['PHP Version'] = $data['php'];
            } elseif (is_array($data['php'])) {
                foreach ($data['php'] as $key => $value) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $php_info[$formatted_key] = $value;
                }
            }
        }
        
        if (isset($data['memory_limit'])) {
            $php_info['Memory Limit'] = $data['memory_limit'];
        }
        
        if (isset($data['max_execution_time'])) {
            $php_info['Max Execution Time'] = $data['max_execution_time'] . 's';
        }
        
        return $php_info;
    }

    /**
     * Extract WordPress Info
     *
     * Extracts and formats WordPress information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted WordPress information.
     */
    private function extractWordPressInfo(array $data): array
    {
        $wp_info = [];
        
        if (isset($data['wp_version'])) {
            $wp_info['WordPress Version'] = $data['wp_version'];
        } elseif (isset($data['wordpress'])) {
            if (is_string($data['wordpress'])) {
                $wp_info['WordPress Version'] = $data['wordpress'];
            } elseif (is_array($data['wordpress'])) {
                foreach ($data['wordpress'] as $key => $value) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $wp_info[$formatted_key] = $value;
                }
            }
        }
        
        if (isset($data['theme'])) {
            $wp_info['Active Theme'] = $data['theme'];
        }
        
        if (isset($data['multisite'])) {
            $wp_info['Multisite'] = $data['multisite'] ? 'Yes' : 'No';
        }
        
        return $wp_info;
    }

    /**
     * Extract Server Info
     *
     * Extracts and formats server information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted server information.
     */
    private function extractServerInfo(array $data): array
    {
        $server_info = [];
        
        if (isset($data['server'])) {
            if (is_array($data['server'])) {
                foreach ($data['server'] as $key => $value) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $server_info[$formatted_key] = $value;
                }
            } else {
                $server_info['Server'] = $data['server'];
            }
        }
        
        if (isset($data['server_info'])) {
            if (is_array($data['server_info'])) {
                foreach ($data['server_info'] as $key => $value) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $server_info[$formatted_key] = $value;
                }
            } else {
                $server_info['Server Info'] = $data['server_info'];
            }
        }
        
        return $server_info;
    }

    /**
     * Extract Plugin Info
     *
     * Extracts and formats plugin information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted plugin information.
     */
    private function extractPluginInfo(array $data): array
    {
        $plugin_info = [];
        
        if (isset($data['plugin_version'])) {
            $plugin_info['Plugin Version'] = $data['plugin_version'];
        } elseif (isset($data['plugin'])) {
            if (is_string($data['plugin'])) {
                $plugin_info['Plugin Version'] = $data['plugin'];
            } elseif (is_array($data['plugin'])) {
                foreach ($data['plugin'] as $key => $value) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    $plugin_info[$formatted_key] = $value;
                }
            }
        }
        
        return $plugin_info;
    }

    /**
     * Extract Environment Info
     *
     * Extracts and formats environment information from various data structures.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Formatted environment information.
     */
    private function extractEnvironmentInfo(array $data): array
    {
        $env_info = [];
        
        if (isset($data['environment'])) {
            if (is_array($data['environment'])) {
                foreach ($data['environment'] as $key => $value) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    if (is_bool($value)) {
                        $env_info[$formatted_key] = $value ? 'Yes' : 'No';
                    } else {
                        $env_info[$formatted_key] = $value;
                    }
                }
            } else {
                $env_info['Environment'] = $data['environment'];
            }
        }
        
        if (isset($data['env'])) {
            if (is_array($data['env'])) {
                foreach ($data['env'] as $key => $value) {
                    $formatted_key = ucwords(str_replace('_', ' ', $key));
                    if (is_bool($value)) {
                        $env_info[$formatted_key] = $value ? 'Yes' : 'No';
                    } else {
                        $env_info[$formatted_key] = $value;
                    }
                }
            } else {
                $env_info['Environment'] = $data['env'];
            }
        }
        
        return $env_info;
    }

    /**
     * Extract Additional System Data
     *
     * Extracts any additional system data not covered by core categories.
     *
     * @since 1.0.0
     *
     * @param array $data Full data array.
     * @return array Additional system data.
     */
    private function extractAdditionalSystemData(array $data): array
    {
        $additional = [];
        $core_keys = [
            'php_version', 'php', 'wp_version', 'wordpress', 'server',
            'server_info', 'plugin_version', 'plugin', 'environment', 'env',
            'memory_limit', 'max_execution_time', 'theme', 'multisite'
        ];
        
        foreach ($data as $key => $value) {
            if (!in_array($key, $core_keys, true)) {
                $additional[$key] = $value;
            }
        }
        
        return $additional;
    }

}
