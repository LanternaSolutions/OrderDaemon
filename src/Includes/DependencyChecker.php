<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

/**
 * DependencyChecker
 *
 * Provides utilities to detect optional dependencies (like the Pro add-on)
 * and to generate WordPress.org compliant, educational messaging used by
 * the core plugin for graceful degradation when optional features are missing.
 *
 * All outputs are designed to be educational (no direct purchase links)
 * and to guide users on next steps without interrupting core functionality.
 *
 * @package OrderDaemon\CompletionManager\Includes
 * @since   2.2.0
 *
 */
final class DependencyChecker
{
    /**
     * Check if the Pro add-on plugin is active.
     *
     * This method uses multiple strategies to avoid fatal errors and to work
     * in both single and multisite environments.
     *
     * @return bool True if the Pro add-on is active, false otherwise.
     */
    public static function is_pro_plugin_active(): bool
    {
        // Primary: check the active_plugins option
        $pro_basename = 'order-daemon-pro/order-daemon-pro.php';

        // Multisite network-active check
        if (is_multisite()) {
            $network_actives = (array) get_site_option('active_sitewide_plugins', []);
            if (isset($network_actives[$pro_basename])) {
                return true;
            }
        }

        $active_plugins = (array) get_option('active_plugins', []);
        if (in_array($pro_basename, $active_plugins, true)) {
            return true;
        }

        // Fallback: if the pro constant is set and explicitly marks non-core
        if (defined('ODCM_IS_CORE') && ODCM_IS_CORE === false) {
            return true;
        }

        return false;
    }

    /**
     * Get a list of missing optional dependencies.
     *
     * Currently, only the Pro add-on is considered optional. This method is
     * designed to be extensible for future dependencies.
     *
     * @return array<string, array<string,string>> A keyed array describing missing dependencies.
     *               Example: [ 'pro' => [ 'name' => 'Order Daemon Pro', 'slug' => 'order-daemon-pro' ] ]
     */
    public static function get_missing_dependencies(): array
    {
        $missing = [];
        if (!self::is_pro_plugin_active()) {
            $missing['pro'] = [
                'name' => 'Order Daemon Pro',
                'slug' => 'order-daemon-pro',
            ];
        }
        return $missing;
    }

    /**
     * Determine if upgrade prompts should be shown.
     *
     * Prompts should be shown only when the user interacts with premium UI
     * and only in admin contexts where the user has capability to manage
     * plugin settings or rules.
     *
     * @return bool True if prompts should be enabled for the current user/context.
     */
    public static function should_show_upgrade_prompts(): bool
    {
        if (self::is_pro_plugin_active()) {
            return false;
        }
        if (!is_admin()) {
            return false;
        }
        // Capability check – restrict to users who can manage plugin/rules
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return false;
        }
        return true;
    }

    /**
     * Get a WordPress.org compliant educational message for a given context.
     *
     * Contexts supported:
     * - 'insight_filters': premium filters in the insight dashboard
     * - 'rule_builder': premium components/fields in the rule builder
     * - default: generic
     *
     * The returned string is safe to render with esc_html(). Do not include
     * direct sales links. If a reference is needed, prefer documentation.
     *
     * @param string $context The UI context requesting messaging.
     * @return string A human-friendly message suitable for toasts/tooltips.
     */
    public static function get_wordpress_org_compliant_message(string $context): string
    {
        $context = sanitize_key($context);

        $messages = [
            'insight_filters' => [
                /* 1 */ __('This feature is available in the premium version.', 'order-daemon'),
                /* 2 */ __('Learn more about advanced filtering options in the documentation.', 'order-daemon'),
                /* 3 */ __('Visit our website for more information.', 'order-daemon'),
                /* 4 */ __('Upgrade to unlock additional capabilities.', 'order-daemon'),
            ],
            'rule_builder' => [
                __('This feature is available in the premium version.', 'order-daemon'),
                __('Learn more about advanced rule components in the documentation.', 'order-daemon'),
                __('Visit our website for more information.', 'order-daemon'),
                __('Upgrade to unlock additional capabilities.', 'order-daemon'),
            ],
            'default' => [
                __('This feature is available in the premium version.', 'order-daemon'),
                __('Learn more about available options in the documentation.', 'order-daemon'),
                __('Visit our website for more information.', 'order-daemon'),
                __('Upgrade to unlock additional capabilities.', 'order-daemon'),
            ],
        ];

        $set = $messages[$context] ?? $messages['default'];
        // Compose a short, educational sentence sequence.
        // Avoid links and sales language; this is informational only.
        return implode(' ', $set);
    }
}
