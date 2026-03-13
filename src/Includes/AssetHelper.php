<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Asset Helper Class
 *
 * Provides helper methods for script and style registration to ensure
 * consistent wp_enqueue practices across all admin classes.
 */
class AssetHelper
{
    /**
     * Helper method to register a script with standard parameters
     *
     * @param string $handle Script handle
     * @param string $path Script path (relative to assets/)
     * @param array $deps Dependencies
     * @param bool $in_footer Load in footer?
     * @param bool $register_only Only register, don't enqueue?
     */
    public static function register_script(string $handle, string $path, array $deps = [], bool $in_footer = true, bool $register_only = false): void {
        $url = ODCM_PLUGIN_URL . 'assets/' . ltrim($path, '/');
        $version = defined('ODCM_VERSION') ? ODCM_VERSION : '2.0.2';

        if ($register_only) {
            wp_register_script($handle, $url, $deps, $version, $in_footer);
        } else {
            wp_enqueue_script($handle, $url, $deps, $version, $in_footer);
        }
    }

    /**
     * Helper method to register a style with standard parameters
     *
     * @param string $handle Style handle
     * @param string $path Style path (relative to assets/)
     * @param array $deps Dependencies
     * @param bool $register_only Only register, don't enqueue?
     */
    public static function register_style(string $handle, string $path, array $deps = [], bool $register_only = false): void {
        $url = ODCM_PLUGIN_URL . 'assets/' . ltrim($path, '/');
        $version = defined('ODCM_VERSION') ? ODCM_VERSION : '2.0.2';

        if ($register_only) {
            wp_register_style($handle, $url, $deps, $version);
        } else {
            wp_enqueue_style($handle, $url, $deps, $version);
        }
    }

    /**
     * Helper method to safely add inline script
     *
     * @param string $handle Script handle to attach to
     * @param string $script JavaScript code
     * @param string $position 'before' or 'after'
     */
    public static function add_inline_script(string $handle, string $script, string $position = 'after'): void {
        if (did_action('wp_enqueue_scripts') || did_action('admin_enqueue_scripts')) {
            wp_add_inline_script($handle, $script, $position);
        } else {
            // Queue for later if hooks haven't fired yet
            add_action('admin_enqueue_scripts', function() use ($handle, $script, $position) {
                wp_add_inline_script($handle, $script, $position);
            });
        }
    }

    /**
     * Helper method to safely add inline style
     *
     * @param string $handle Style handle to attach to
     * @param string $css CSS code
     */
    public static function add_inline_style(string $handle, string $css): void {
        if (did_action('wp_enqueue_scripts') || did_action('admin_enqueue_scripts')) {
            wp_add_inline_style($handle, $css);
        } else {
            // Queue for later if hooks haven't fired yet
            add_action('admin_enqueue_scripts', function() use ($handle, $css) {
                wp_add_inline_style($handle, $css);
            });
        }
    }
}
