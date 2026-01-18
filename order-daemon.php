<?php
declare(strict_types=1);

/**
 * Plugin Name: Order Daemon for WooCommerce
 * Plugin URI: https://orderdaemon.com/docs
 * Description: Automate WooCommerce order completion with intelligent rule-based processing. The free version includes basic triggers, conditions, and actions.
 * Version: 2.0.1
 * Author: Order Daemon
 * Author URI: https://www.orderdaemon.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: order-daemon
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Declare HPOS compatibility before WooCommerce initialization
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', 
            __FILE__, 
            true
        );
    }
});

// Autoload classes
require_once __DIR__ . '/vendor/autoload.php';

// Include the config class before using it
require_once __DIR__ . '/src/Includes/class-odcm-config.php';

// Import classes
use OrderDaemon\CompletionManager\Core\ManualStatusTracker;
use OrderDaemon\CompletionManager\Plugin;
use OrderDaemon\CompletionManager\Includes\Odcm_Config;

// Define plugin constants
// Define plugin version constant, used for database versioning and asset cache-busting.
if ( ! defined( 'ODCM_VERSION' ) ) {
    define('ODCM_VERSION', '2.0.1');
}
define('ODCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ODCM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ODCM_PLUGIN_FILE', __FILE__);
// Handle ODCM_DEBUG constant definition carefully to avoid "already defined" warnings
// Read the user's debug preference from database to sync with dashboard setting
if ( ! defined( 'ODCM_DEBUG' ) ) {
    // Check user's debug preference from Insight Dashboard setting
    // Using logical 'odcm_debug' option name (standardized across codebase)
    $odcm_user_debug_setting = get_option('odcm_debug', false);
    define('ODCM_DEBUG', (bool) $odcm_user_debug_setting);
}
// If ODCM_DEBUG is already defined (e.g., in wp-config.php), respect that setting
// This allows developers to override the database setting
elseif (defined('ODCM_DEBUG') && ODCM_DEBUG) {
    // Constant already defined and enabled - no action needed
}
// If ODCM_DEBUG is defined but false, also respect that setting
elseif (defined('ODCM_DEBUG') && !ODCM_DEBUG) {
    // Constant already defined but disabled - no action needed
}
define('ODCM_IS_CORE', true);

// Define plugin links using configuration class
define('ODCM_AUTHOR_URL', Odcm_Config::$author_uri);
define('ODCM_COMPANY_URL', Odcm_Config::$author_uri);
define('ODCM_DETAILS_URL', Odcm_Config::$plugin_uri);
// Define the base URL for documentation links used throughout the UI.
if ( ! defined( 'ODCM_DOCS_URL' ) ) {
    define('ODCM_DOCS_URL', Odcm_Config::$docs_uri);
}
define('ODCM_SUPPORT_URL', Odcm_Config::$plugin_uri . '/support');

// Include global helper functions
require_once __DIR__ . '/src/Includes/functions.php';

// Include Utils functions
require_once __DIR__ . '/src/Includes/Utils/sanitization.php';

// Include global action functions
require_once __DIR__ . '/src/Includes/actions.php';

// Include log system registries
require_once __DIR__ . '/src/Core/LogRegistries.php';


// Correctly include the Installer class before registering the activation hook.
require_once __DIR__ . '/src/Includes/Installer.php';

// Register the activation hook to create the audit log table.
register_activation_hook(__FILE__, ['OrderDaemon\CompletionManager\Includes\Installer', 'activate']);

// Initialize the plugin on the plugins_loaded hook
add_action('plugins_loaded', function() {
    // Bootstrap the plugin components
    Plugin::instance()->bootstrap();
    
    // Initialize manual status tracking for chain of custody logging
    ManualStatusTracker::init();
});
