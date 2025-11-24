<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager;

use OrderDaemon\CompletionManager\Admin\Admin;
use OrderDaemon\CompletionManager\Admin\DiagnosticDashboard;
use OrderDaemon\CompletionManager\Admin\InsightDashboard;
use OrderDaemon\CompletionManager\API\AuditLogEndpoint;
use OrderDaemon\CompletionManager\API\RuleBuilderApiController;
use OrderDaemon\CompletionManager\API\WebhookController;
use OrderDaemon\CompletionManager\Core\Core;
use OrderDaemon\CompletionManager\Core\ManualStatusTracker;
use OrderDaemon\CompletionManager\Includes\Installer;

/**
 * Main plugin class.
 *
 * This class is responsible for initializing the plugin, loading dependencies,
 * and setting up all the necessary hooks.
 *
 * @since 1.0.0
 */
final class Plugin {
	/**
	 * The single instance of the class.
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Gets the single instance of the class.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initializes the plugin.
	 *
	 * Wires up all the hooks and initializes the core components.
	 * 
	 * @deprecated Use bootstrap() directly instead to avoid double hook nesting
	 */
	public function init(): void {
		// Bootstrap components
		$this->bootstrap();
	}

	/**
	 * Bootstraps the plugin by initializing core components.
	 *
	 * This method is called on 'init' to ensure all plugins are loaded
	 * before we initialize our components.
	 * 
	 * CRITICAL: This method implements a specific initialization sequence to prevent
	 * race conditions between post type registration and admin form handlers.
     * The sequence ensures that the 'odcm_order_rule' post type is available
	 * in ALL execution contexts (admin, CLI, frontend, background processing).
	 */
	public function bootstrap(): void {
		// Run installer/upgrade routines on every load (idempotent; version-guarded)
		Installer::install();

		// Check if WooCommerce is active
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', function() {
				echo '<div class="error"><p>';
				echo esc_html__('core.plugin.dependency.woocommerce_required', 'order-daemon');
				echo '</p></div>';
			});
			return;
		}

        $this->ensure_i18n();

		/**
		 * RACE CONDITION FIX: Proper initialization sequence with specific hook priorities
		 * 
		 * The following sequence is CRITICAL to prevent the "invalid post type" error
		 * that occurs when admin_init hooks fire before post type registration:
		 * 
		 * Priority 5:  Register post type (MUST be first - needed for all contexts)
		 * Priority 6:  Load options (after post type exists)
		 * Priority 10: Initialize Core (after options, registers admin_init with priority 20)
		 * Priority 15: Initialize Admin (after core, only in admin context)
		 */
		add_action('init', [$this, 'register_post_type'], 5);
		add_action('init', [$this, 'load_options'], 6);
		add_action('init', [$this, 'initialize_core'], 10);
		add_action('init', [$this, 'initialize_admin_components'], 15);
		add_action('rest_api_init', [$this, 'initialize_api_endpoints'], 10);
	}
    
    /**
     * Ensure the text domain is loaded at the earliest valid moment.
     * - If we're already in or past 'init', load now.
     * - Otherwise, hook it to 'init' at priority 0.
     */
    private function ensure_i18n(): void {
        if (is_textdomain_loaded('order-daemon')) {
            return;
        }
        
        if (did_action('init')) {
            $this->load_text_domain();
        } else {
            add_action('init', [$this, 'load_text_domain'], 0);
        }
    }
    
    /**
     * Load translations FIRST (before any UI rendering)
     *
     * @since 1.1.23
     */
    public function load_text_domain(): void {
        error_log('load_text_domain on init');

        $rel_path = dirname(plugin_basename(ODCM_PLUGIN_FILE)) . '/languages';
        error_log('init - rel_path = ' . $rel_path);

        $load_plugin_textdomain_success = load_plugin_textdomain('order-daemon', false, $rel_path);
        error_log('init - load_plugin_text_domain loaded = ' . $load_plugin_textdomain_success);

        error_log('init - __FILE__ = ' . __FILE__ );
        error_log('init - ODCM_PLUGIN_FILE = ' . ODCM_PLUGIN_FILE);
        $wp_plugin_dir = defined('WP_PLUGIN_DIR') ? constant('WP_PLUGIN_DIR') : 'undefined';
        error_log('init - WP_PLUGIN_DIR = ' . $wp_plugin_dir);
        error_log('init - plugin_basename = ' . plugin_basename(ODCM_PLUGIN_FILE));
        error_log('init - dirname = ' . dirname(plugin_basename(ODCM_PLUGIN_FILE)));
        error_log('init - languages = ' . dirname(plugin_basename(ODCM_PLUGIN_FILE)) . '/languages');
        
        $textdomain_loaded = is_textdomain_loaded('order-daemon');
        $load_textdomain_success = false;

        error_log('locale=' . get_locale());
        error_log('user locale=' . (function_exists('get_user_locale') ? get_user_locale() : 'n/a'));
        error_log('odcm loaded=' . ($textdomain_loaded ? 'yes' : 'no'));
        
        // Fallback: load directly by absolute path if needed (helps diagnose path issues)
        if (!$textdomain_loaded) {
            error_log('loading directly by absolute path');
            $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
            $mofile = ODCM_PLUGIN_DIR . 'languages/order-daemon-' . $locale . '.mo';
            $is_readable = is_readable($mofile);
            error_log('mofile=' . $mofile);
            error_log('is_readable=' . ($is_readable ? 'yes' : 'no'));
            if ($is_readable) {
                $load_textdomain_success = load_textdomain('order-daemon', $mofile);
                error_log('odcm loaded take two=' . ($load_textdomain_success ? 'yes' : 'no'));
            }
        }
        
        $textdomain_loaded = is_textdomain_loaded('order-daemon');
        
        // Optional diagnostics while you verify
        error_log('i18n rel_path=' . $rel_path);
        error_log('load_plugin_textdomain_success=' . ($load_plugin_textdomain_success ? '1' : '0'));
        error_log('load_textdomain_success=' . ($load_textdomain_success ? '1' : '0'));
        error_log('is_textdomain_loaded=' . ($textdomain_loaded ? 'yes' : 'no'));
        
        // Enable JSON translations for JavaScript
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('order-daemon-admin-js', 'order-daemon');
            wp_set_script_translations('order-daemon-rule-builder', 'order-daemon');
            wp_set_script_translations('order-daemon-insight-dashboard', 'order-daemon');
        }
    }
    
    /**
	 * Register the custom post type globally.
	 * 
	 * CRITICAL: This method is called on 'init' hook priority 5 to ensure the
  * 'odcm_order_rule' post type is registered BEFORE any other component
	 * tries to query it. This prevents race conditions in admin_init handlers.
	 * 
	 * The post type MUST be registered in ALL contexts:
	 * - Admin interface (for rule management)
	 * - CLI context (for WP-CLI commands)
	 * - Frontend context (for Action Scheduler background processing)
	 * - Cron context (for scheduled tasks)
	 * 
	 * @since 1.0.0
	 */
	public function register_post_type(): void {
		$admin = new Admin();
		$admin->register_completion_rule_post_type();
	}

	/**
	 * Load option registrations (triggers, conditions, actions) and filter registrations.
	 *
	 * This method is called on 'init' hook priority 6, after post type registration
	 * to ensure all classes are fully loaded before options registration begins.
	 * 
	 * @since 1.0.0
	 */
	public function load_options(): void {
		// Load option registrations (triggers, conditions, actions)
		require_once ODCM_PLUGIN_DIR . 'src/Core/options.php';
		
		// Load audit log filter registrations
		require_once ODCM_PLUGIN_DIR . 'src/Core/audit-filters.php';
		
		// Load payload component registry for composite rendering
		require_once ODCM_PLUGIN_DIR . 'src/Core/PayloadComponentRegistry.php';
	}

	/**
	 * Initialize Core components.
	 * 
	 * This method is called on 'init' hook priority 10, after post type and options
	 * are loaded. The Core class registers admin_init hooks with priority 20 to ensure
	 * they run AFTER the post type is fully registered.
	 * 
	 * @since 1.0.0
	 */
	public function initialize_core(): void {
		$core = new Core();
		$core->init();
		
		// Initialize Manual Status Tracker for chain of custody logging
		ManualStatusTracker::init();

		// Initialize Premium Component Fallback System (v2.2.1+)
		$this->initialize_premium_fallback_system();

        // Initialize Security Guard System (v2.1.1+)
        $this->initialize_security_system();
	}

	/**
	 * Initialize the premium component fallback system.
	 *
	 * This method sets up the fallback system that handles scenarios where
	 * existing rules reference premium components that are no longer available
	 * (e.g., when pro plugin is deactivated or components are migrated).
	 *
	 * @since 1.1.0
	 */
	private function initialize_premium_fallback_system(): void {
		try {
			// Check if the fallback class exists
			if (!class_exists('\OrderDaemon\CompletionManager\Core\PremiumComponentFallback')) {
				return;
			}

			// Initialize the fallback system
			\OrderDaemon\CompletionManager\Core\PremiumComponentFallback::init();

		} catch (\Throwable $e) {
			// Silently fail fallback system initialization to prevent breaking the plugin
			// The fallback system is optional and shouldn't break core functionality
			error_log('ODCM: Failed to initialize premium component fallback system: ' . $e->getMessage());
		}
	}

    /**
     * Initialize the security guard system.
     *
     * This method sets up the new guard-based security architecture that provides
     * a modern, extensible security layer while maintaining full compatibility
     * with existing WordPress security patterns.
     *
     * @since 1.0.0
     */
    private function initialize_security_system(): void {
        try {
            // Check if required classes exist before instantiation
            if (!class_exists('\OrderDaemon\CompletionManager\Core\Security\GuardChecker')) {
                // Classes not available yet, skip initialization
                return;
            }

            // Initialize the guard checker service
            $guard_checker = new \OrderDaemon\CompletionManager\Core\Security\GuardChecker();

            // Store guard checker in global registry for access by other components
            $GLOBALS['odcm_guard_checker'] = $guard_checker;

            // Security system initialized
        } catch (\Throwable $e) {
            // Silently fail guard system initialization to prevent breaking the plugin
            // The guard system is optional and shouldn't break core functionality
            error_log('ODCM: Failed to initialize security guard system: ' . $e->getMessage());
        }
    }

	/**
	 * Initialize admin components.
	 *
	 * This method is called on 'init' hook priority 15, after core components
	 * are initialized. Post type registration is handled globally in register_post_type()
	 * to prevent race conditions.
	 * 
	 * @since 1.0.0
	 */
	public function initialize_admin_components(): void {
		// Initialize admin components only if in admin area
		// Post type is already registered globally in register_post_type()
		if (is_admin()) {
			$admin = new Admin();
			$admin->init();
			
			// Initialize Insight Dashboard
			$insight_dashboard = new InsightDashboard();
			$insight_dashboard->init();

			// Initialize Diagnostic Dashboard
			$diagnostic_dashboard = new DiagnosticDashboard();
			$diagnostic_dashboard->init();

			// Initialize WordPress.org compliant upgrade prompts (educational messaging)
			if (class_exists('OrderDaemon\\CompletionManager\\Includes\\UpgradePrompts')) {
				$upgrade_prompts = new \OrderDaemon\CompletionManager\Includes\UpgradePrompts();
				$upgrade_prompts->init();
			}
		}
	}

	/**
	 * Initialize REST API endpoints.
	 *
	 * This method is called on 'rest_api_init' hook to register all REST API
	 * endpoints for the insight dashboard and other API functionality.
	 * 
	 * @since 1.0.0
	 */
	public function initialize_api_endpoints(): void {
		$audit_log_endpoint = new AuditLogEndpoint();
		$audit_log_endpoint->register_routes();

		$rule_builder_api = new RuleBuilderApiController();
		$rule_builder_api->register_routes();

		// Register webhook endpoints for universal event system
		$webhook_controller = new WebhookController();
		$webhook_controller->register_routes();
	}


    /**
     * Get the global guard checker instance.
     *
     * This provides a convenient way for other components to access the
     * guard checker service without tight coupling.
     *
     * @return \OrderDaemon\CompletionManager\Core\Security\GuardChecker|null
     * @since 1.0.0
     */
    public static function get_guard_checker(): ?\OrderDaemon\CompletionManager\Core\Security\GuardChecker {
        return $GLOBALS['odcm_guard_checker'] ?? null;
    }

}
