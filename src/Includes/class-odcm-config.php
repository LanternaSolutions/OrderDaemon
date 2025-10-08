<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

/**
 * Configuration class for the plugin.
 * 
 * Contains all the static configuration values used throughout the plugin.
 */
final class Odcm_Config {
    /** @var string The full, official plugin name for repositories and headers. */
    public static $plugin_name_full = 'Order Daemon for WooCommerce';

    /** @var string The short, common-use name for UI and general text. */
    public static $plugin_name_short = 'Order Daemon';

    /** @var string The main settings page menu title. */
    public static $menu_title = 'Order Daemon';
    
    /** @var string The plugin's text domain for all l10n functions. */
    public static $text_domain = 'order-daemon';

    /** @var string The primary slug for admin pages and assets. */
    public static $plugin_slug = 'order-daemon';

    /** @var string The official Plugin URI for the WP.org repo. */
    public static $plugin_uri = 'https://orderdaemon.com';

    /** @var string The official Author URI. */
    public static $author_uri = 'https://orderdaemon.com';
    
    /** @var string The base URL for all documentation links. */
    public static $docs_uri = 'https://orderdaemon.com/docs';

}