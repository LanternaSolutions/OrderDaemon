<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics\Frontend;

use OrderDaemon\CompletionManager\Diagnostics\AbstractDiagnostic;
use OrderDaemon\CompletionManager\Diagnostics\DiagnosticResult;

/**
 * Configuration Diagnostic - Test JavaScript Configuration and Initialization
 *
 * This diagnostic addresses the console log issue:
 * "ODCM Insight Dashboard: Initialized successfully" appearing twice,
 * indicating double initialization of the dashboard.
 *
 * Tests:
 * - JavaScript configuration and localization
 * - Multiple script loading and initialization
 * - Asset dependency management
 * - WordPress script enqueueing best practices
 * - Insight dashboard specific configuration issues
 *
 * @package OrderDaemon\DevTools\Diagnostics\Frontend
 */
class ConfigDiagnostic extends AbstractDiagnostic
{
    /**
     * Get the diagnostic test name
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'Frontend Configuration & Initialization';
    }

    /**
     * Get the diagnostic test description
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Tests JavaScript configuration and initialization issues. Addresses double dashboard initialization and script loading problems.';
    }

    /**
     * Get the diagnostic category
     *
     * @return string
     */
    public function get_category(): string
    {
        return 'frontend';
    }

    /**
     * Get the priority level (frontend configuration is important)
     *
     * @return int
     */
    public function get_priority(): int
    {
        return 7;
    }

    /**
     * Execute the frontend configuration diagnostic test
     *
     * @return DiagnosticResult
     */
    protected function execute(): DiagnosticResult
    {
        $details = [];
        $recommendations = [];
        $issues_found = [];

        // Test 1: Check script registration and enqueueing
        $script_test = $this->test_script_registration();
        $details['script_registration'] = $script_test;
        if (!empty($script_test['issues'])) {
            foreach ($script_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Review script registration and enqueueing logic';
        }

        // Test 2: Check for duplicate script loading
        $duplicate_test = $this->test_duplicate_script_loading();
        $details['duplicate_scripts'] = $duplicate_test;
        if ($duplicate_test['duplicates_found']) {
            $issues_found[] = 'Duplicate script loading detected - causes double initialization';
            $recommendations[] = 'Implement proper script dependency management and conditional loading';
        }

        // Test 3: Check JavaScript localization and configuration
        $localization_test = $this->test_javascript_localization();
        $details['javascript_localization'] = $localization_test;
        if (!empty($localization_test['issues'])) {
            foreach ($localization_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Fix JavaScript localization and configuration issues';
        }

        // Test 4: Check for conflicting JavaScript initializations
        $init_test = $this->test_initialization_conflicts();
        $details['initialization_conflicts'] = $init_test;
        if (!empty($init_test['conflicts'])) {
            $issues_found[] = 'JavaScript initialization conflicts detected';
            $recommendations[] = 'Implement proper initialization guards and event handling';
        }

        // Test 5: Check asset loading order and dependencies
        $dependency_test = $this->test_asset_dependencies();
        $details['asset_dependencies'] = $dependency_test;
        if (!empty($dependency_test['issues'])) {
            foreach ($dependency_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Review and fix asset dependency order';
        }

        // Test 6: Check for proper WordPress enqueueing practices
        $wp_practices_test = $this->test_wordpress_practices();
        $details['wordpress_practices'] = $wp_practices_test;
        if (!empty($wp_practices_test['issues'])) {
            foreach ($wp_practices_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Follow WordPress best practices for asset enqueueing';
        }

        // Test 7: Check admin vs frontend loading
        $context_test = $this->test_loading_context();
        $details['loading_context'] = $context_test;
        if (!empty($context_test['issues'])) {
            foreach ($context_test['issues'] as $issue) {
                $issues_found[] = $issue;
            }
            $recommendations[] = 'Implement proper admin/frontend context checking';
        }

        // Determine overall result
        if (empty($issues_found)) {
            return DiagnosticResult::success(
                $this->get_name(),
                'Frontend configuration and initialization appear correct',
                $details
            );
        } else {
            $message = 'Frontend configuration issues detected: ' . implode('; ', array_slice($issues_found, 0, 3));
            if (count($issues_found) > 3) {
                $message .= ' and ' . (count($issues_found) - 3) . ' more issues';
            }

            return DiagnosticResult::failure(
                $this->get_name(),
                $message,
                $details,
                $recommendations
            );
        }
    }

    /**
     * Test script registration and enqueueing
     *
     * @return array Script registration test results
     */
    private function test_script_registration(): array
    {
        global $wp_scripts;
        
        $result = [
            'registered_scripts' => [],
            'enqueued_scripts' => [],
            'odcm_scripts' => [],
            'issues' => []
        ];

        if (!$wp_scripts instanceof \WP_Scripts) {
            $result['issues'][] = 'WordPress scripts object not available';
            return $result;
        }

        // Get all registered scripts
        $result['registered_scripts'] = array_keys($wp_scripts->registered);
        $result['enqueued_scripts'] = $wp_scripts->queue;

        // Find Order Daemon related scripts
        $odcm_pattern = '/^(odcm|order.?daemon)/i';
        foreach ($wp_scripts->registered as $handle => $script) {
            if (preg_match($odcm_pattern, $handle)) {
                $result['odcm_scripts'][$handle] = [
                    'src' => $script->src,
                    'deps' => $script->deps,
                    'ver' => $script->ver,
                    'enqueued' => in_array($handle, $wp_scripts->queue)
                ];
            }
        }

        // Check for potential issues
        $insight_dashboard_scripts = array_filter($result['odcm_scripts'], function($script, $handle) {
            return strpos($handle, 'insight') !== false || 
                   strpos($script['src'], 'insight') !== false;
        }, ARRAY_FILTER_USE_BOTH);

        if (count($insight_dashboard_scripts) > 1) {
            $result['issues'][] = 'Multiple insight dashboard scripts registered: ' . implode(', ', array_keys($insight_dashboard_scripts));
        }

        // Check for scripts loaded on wrong pages
        if (is_admin() && !empty($result['odcm_scripts'])) {
            foreach ($result['odcm_scripts'] as $handle => $script) {
                if ($script['enqueued'] && !$this->should_load_on_current_page($handle)) {
                    $result['issues'][] = "Script '{$handle}' loaded on inappropriate admin page";
                }
            }
        }

        return $result;
    }

    /**
     * Test for duplicate script loading
     *
     * @return array Duplicate script test results
     */
    private function test_duplicate_script_loading(): array
    {
        global $wp_scripts;
        
        $result = [
            'duplicates_found' => false,
            'duplicate_groups' => [],
            'script_sources' => []
        ];

        if (!$wp_scripts instanceof \WP_Scripts) {
            return $result;
        }

        // Group scripts by their source URLs
        $source_groups = [];
        foreach ($wp_scripts->registered as $handle => $script) {
            if (!empty($script->src)) {
                $normalized_src = $this->normalize_script_src($script->src);
                if (!isset($source_groups[$normalized_src])) {
                    $source_groups[$normalized_src] = [];
                }
                $source_groups[$normalized_src][] = $handle;
            }
        }

        // Find duplicates
        foreach ($source_groups as $src => $handles) {
            if (count($handles) > 1) {
                $result['duplicates_found'] = true;
                $result['duplicate_groups'][] = [
                    'source' => $src,
                    'handles' => $handles
                ];
            }
        }

        $result['script_sources'] = $source_groups;

        return $result;
    }

    /**
     * Test JavaScript localization and configuration
     *
     * @return array Localization test results
     */
    private function test_javascript_localization(): array
    {
        global $wp_scripts;
        
        $result = [
            'localized_scripts' => [],
            'odcm_localizations' => [],
            'issues' => []
        ];

        if (!$wp_scripts instanceof \WP_Scripts) {
            $result['issues'][] = 'WordPress scripts object not available';
            return $result;
        }

        // Check localized data
        foreach ($wp_scripts->registered as $handle => $script) {
            if (!empty($script->extra['data'])) {
                $result['localized_scripts'][$handle] = $script->extra['data'];
                
                // Check for Order Daemon localizations
                if (preg_match('/^(odcm|order.?daemon)/i', $handle)) {
                    $result['odcm_localizations'][$handle] = $script->extra['data'];
                }
            }
        }

        // Check for duplicate localizations with same object names
        $object_names = [];
        foreach ($result['localized_scripts'] as $handle => $data) {
            if (preg_match('/var\s+(\w+)\s*=/', $data, $matches)) {
                $object_name = $matches[1];
                if (!isset($object_names[$object_name])) {
                    $object_names[$object_name] = [];
                }
                $object_names[$object_name][] = $handle;
            }
        }

        foreach ($object_names as $obj_name => $handles) {
            if (count($handles) > 1) {
                // Filter out WordPress core duplications - these are known issues but not critical
                $wordpress_core_duplications = [
                    'pluploadL10n', // Known WordPress core duplication between plupload-handlers and wp-plupload
                    'swfuploadL10n', // Known WordPress core duplication
                    'thickboxL10n', // Sometimes duplicated in WordPress
                ];
                
                // Only flag non-core duplications as issues
                if (!in_array($obj_name, $wordpress_core_duplications)) {
                    $result['issues'][] = "Duplicate JavaScript object '{$obj_name}' from scripts: " . implode(', ', $handles);
                } else {
                    // Add to details for informational purposes but don't flag as an error
                    $result['wordpress_core_duplications'][$obj_name] = $handles;
                }
            }
        }

        // Check for common configuration issues
        foreach ($result['odcm_localizations'] as $handle => $data) {
            if (strpos($data, 'ajaxUrl') === false && strpos($data, 'ajax_url') === false) {
                $result['issues'][] = "Script '{$handle}' missing AJAX URL configuration";
            }
            if (strpos($data, 'nonce') === false) {
                $result['issues'][] = "Script '{$handle}' missing nonce configuration";
            }
        }

        return $result;
    }

    /**
     * Test for initialization conflicts
     *
     * @return array Initialization conflict test results
     */
    private function test_initialization_conflicts(): array
    {
        $result = [
            'conflicts' => [],
            'initialization_methods' => [],
            'event_handlers' => []
        ];

        // This is a simplified test - in a real scenario, we'd analyze the actual JavaScript files
        // For now, we'll check for common patterns that suggest initialization issues

        // Check if multiple insight dashboard related scripts are loaded
        global $wp_scripts;
        if ($wp_scripts instanceof \WP_Scripts) {
            $dashboard_scripts = [];
            foreach ($wp_scripts->registered as $handle => $script) {
                if (strpos($handle, 'insight') !== false || 
                    (isset($script->src) && is_string($script->src) && strpos($script->src, 'insight') !== false)) {
                    $dashboard_scripts[] = $handle;
                }
            }

            if (count($dashboard_scripts) > 1) {
                $result['conflicts'][] = 'Multiple insight dashboard scripts detected: ' . implode(', ', $dashboard_scripts);
            }
        }

        // Check for common jQuery ready handlers that might conflict
        $result['initialization_methods'] = [
            'document_ready_handlers' => 'Multiple $(document).ready() handlers',
            'window_load_handlers' => 'Multiple window.onload handlers',
            'immediate_execution' => 'Scripts executing immediately without ready checks'
        ];

        return $result;
    }

    /**
     * Test asset dependencies
     *
     * @return array Asset dependency test results
     */
    private function test_asset_dependencies(): array
    {
        global $wp_scripts;
        
        $result = [
            'dependency_chain' => [],
            'circular_dependencies' => [],
            'missing_dependencies' => [],
            'issues' => []
        ];

        if (!$wp_scripts instanceof \WP_Scripts) {
            $result['issues'][] = 'WordPress scripts object not available';
            return $result;
        }

        // Analyze dependency chains for Order Daemon scripts
        foreach ($wp_scripts->registered as $handle => $script) {
            if (preg_match('/^(odcm|order.?daemon)/i', $handle)) {
                $result['dependency_chain'][$handle] = $script->deps;
                
                // Check if dependencies are registered
                foreach ($script->deps as $dep) {
                    if (!isset($wp_scripts->registered[$dep])) {
                        $result['missing_dependencies'][] = "Script '{$handle}' depends on unregistered '{$dep}'";
                        $result['issues'][] = "Missing dependency '{$dep}' for script '{$handle}'";
                    }
                }
            }
        }

        // Check for common dependency issues
        foreach ($result['dependency_chain'] as $handle => $deps) {
            // Scripts that use AJAX should depend on jQuery
            if (strpos($handle, 'admin') !== false || strpos($handle, 'dashboard') !== false) {
                if (!in_array('jquery', $deps)) {
                    $result['issues'][] = "Script '{$handle}' likely needs jQuery dependency";
                }
            }
        }

        return $result;
    }

    /**
     * Test WordPress enqueueing best practices
     *
     * @return array WordPress practices test results
     */
    private function test_wordpress_practices(): array
    {
        global $wp_scripts;
        
        $result = [
            'best_practices' => [],
            'violations' => [],
            'issues' => []
        ];

        if (!$wp_scripts instanceof \WP_Scripts) {
            $result['issues'][] = 'WordPress scripts object not available';
            return $result;
        }

        foreach ($wp_scripts->registered as $handle => $script) {
            if (preg_match('/^(odcm|order.?daemon)/i', $handle)) {
                $violations = [];
                
                // Check version parameter
                if (empty($script->ver)) {
                    $violations[] = 'Missing version parameter (affects caching)';
                }
                
                // Check if script is loaded in footer (best practice for performance)
                if (empty($script->args) || $script->args !== true) {
                    $violations[] = 'Script not loaded in footer (affects page load performance)';
                }
                
                // Check source URL format
                if (!empty($script->src)) {
                    if (strpos($script->src, '//') === 0) {
                        $violations[] = 'Protocol-relative URL used (potential HTTPS issues)';
                    }
                    if (strpos($script->src, '.min.js') === false && !defined('SCRIPT_DEBUG')) {
                        $violations[] = 'Non-minified script in production';
                    }
                }
                
                if (!empty($violations)) {
                    $result['violations'][$handle] = $violations;
                    foreach ($violations as $violation) {
                        $result['issues'][] = "Script '{$handle}': {$violation}";
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Test loading context (admin vs frontend)
     *
     * @return array Loading context test results
     */
    private function test_loading_context(): array
    {
        global $wp_scripts;
        
        $result = [
            'current_context' => is_admin() ? 'admin' : 'frontend',
            'loaded_scripts' => [],
            'context_violations' => [],
            'issues' => []
        ];

        if (!$wp_scripts instanceof \WP_Scripts) {
            $result['issues'][] = 'WordPress scripts object not available';
            return $result;
        }

        $current_page = is_admin() ? $this->get_current_admin_page() : 'frontend';
        $result['current_page'] = $current_page;

        foreach ($wp_scripts->queue as $handle) {
            if (isset($wp_scripts->registered[$handle]) && 
                preg_match('/^(odcm|order.?daemon)/i', $handle)) {
                $result['loaded_scripts'][] = $handle;
                
                // Check if this script should be loaded on current page
                if (!$this->should_load_on_current_page($handle)) {
                    $result['context_violations'][] = $handle;
                    $result['issues'][] = "Script '{$handle}' loaded on inappropriate page '{$current_page}'";
                }
            }
        }

        return $result;
    }

    /**
     * Normalize script source URL for comparison
     *
     * @param string $src Script source URL
     * @return string Normalized source URL
     */
    private function normalize_script_src(string $src): string
    {
        // Remove query parameters and protocol
        $src = preg_replace('/\?.*$/', '', $src);
        $src = preg_replace('/^https?:/', '', $src);
        return trim($src, '/');
    }

    /**
     * Check if a script should be loaded on the current page
     *
     * @param string $handle Script handle
     * @return bool True if should be loaded
     */
    private function should_load_on_current_page(string $handle): bool
    {
        $current_page = is_admin() ? $this->get_current_admin_page() : 'frontend';
        
        // Define which scripts should load on which pages
        $script_contexts = [
            'insight-dashboard' => ['admin_page_order_daemon_insight'],
            'admin' => ['admin'],
            'dashboard' => ['admin_page_order_daemon_insight', 'admin_page_wc-orders'],
            'rule-builder' => ['admin_page_order_daemon_rules'],
        ];
        
        foreach ($script_contexts as $pattern => $allowed_contexts) {
            if (strpos($handle, $pattern) !== false) {
                return in_array($current_page, $allowed_contexts) || in_array('admin', $allowed_contexts);
            }
        }
        
        // Default: allow on admin pages for admin scripts
        return is_admin() || strpos($handle, 'admin') === false;
    }

    /**
     * Get current admin page identifier
     *
     * @return string Current admin page identifier
     */
    private function get_current_admin_page(): string
    {
        global $pagenow, $hook_suffix;
        
        if (!empty($_GET['page'])) {
            return 'admin_page_' . sanitize_text_field($_GET['page']);
        }
        
        if (!empty($hook_suffix)) {
            return $hook_suffix;
        }
        
        return $pagenow ?? 'unknown';
    }
}
