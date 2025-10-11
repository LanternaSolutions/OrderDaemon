<?php
declare(strict_types=1);

/**
 * Plugin Name: Order Daemon for WooCommerce
 * Plugin URI: https://orderdaemon.com/docs
 * Description: Automate WooCommerce order completion with intelligent rule-based processing. The free version includes basic triggers, conditions, and actions.
 * Version: 1.1.7
 * Author: Order Daemon
 * Author URI: https://www.orderdaemon.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: order-daemon
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Autoload classes
require_once __DIR__ . '/vendor/autoload.php';

// Include the config class before using it
require_once __DIR__ . '/src/Includes/class-odcm-config.php';

// Include the strings class
require_once __DIR__ . '/src/Includes/class-odcm-strings.php';

// Import classes
use OrderDaemon\CompletionManager\Plugin;
use OrderDaemon\CompletionManager\Includes\Odcm_Config;

// Define plugin constants
// Define plugin version constant, used for database versioning and asset cache-busting.
if ( ! defined( 'ODCM_VERSION' ) ) {
    define('ODCM_VERSION', '1.1.7');
}
define('ODCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ODCM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ODCM_PLUGIN_FILE', __FILE__);
if ( ! defined( 'ODCM_DEBUG' ) ) {
    define('ODCM_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
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
define('ODCM_PREMIUM_URL', Odcm_Config::$plugin_uri . '/pricing');

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
    \OrderDaemon\CompletionManager\Core\ManualStatusTracker::init();
});
