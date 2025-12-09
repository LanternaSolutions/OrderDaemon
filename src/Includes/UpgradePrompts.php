<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

/**
 * UpgradePrompts
 *
 * Provides a WordPress.org compliant, educational messaging system for the core plugin.
 * - Shows contextual, non-intrusive prompts for premium-only UI elements
 * - Offers tooltips, modal overlays, and inline help content
 * - Stores user preferences (dismissals and frequency) per-user
 * - No direct sales links or payment processing links
 *
 * All outputs are intended to be educational and helpful, not sales-focused.
 *
 * @package OrderDaemon\CompletionManager\Includes
 * @since   1.0.0
 */
final class UpgradePrompts
{
    /**
     * Option/meta key for storing per-user preferences
     */
    private const USER_META_KEY = 'odcm_upgrade_prefs';

    /**
     * Initialize hooks for admin contexts.
     *
     * @return void
     */
    public function init(): void
    {
        if (!is_admin()) {
            return;
        }

        // Enqueue assets only when prompts should be shown
        add_action('admin_enqueue_scripts', function(string $hook_suffix): void {
            if (!DependencyChecker::should_show_upgrade_prompts()) {
                return;
            }
            $this->enqueue_assets($hook_suffix);
        }, 20);

        // Render a shared modal container in admin footer so any page can use it
        add_action('admin_footer', function(): void {
            if (!DependencyChecker::should_show_upgrade_prompts()) {
                return;
            }
            $this->render_modal_container();
        }, 20);

        // AJAX endpoints for preferences
        add_action('wp_ajax_odcm_update_upgrade_prefs', [$this, 'handle_update_prefs_ajax']);
        add_action('wp_ajax_odcm_dismiss_prompt', [$this, 'handle_dismiss_prompt_ajax']);
    }

    /**
     * Enqueue scripts and styles and localize configuration for prompts.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    private function enqueue_assets(string $hook_suffix): void
    {
        $version = defined('ODCM_VERSION') ? ODCM_VERSION : '2.3.0';
        $assets_url = defined('ODCM_PLUGIN_URL') ? ODCM_PLUGIN_URL . 'assets/' : plugin_dir_url(ODCM_PLUGIN_FILE) . 'assets/';

        // Styles for modal and badges (lightweight)
        wp_enqueue_style(
            'odcm-upgrade-prompts',
            $assets_url . 'css/upgrade-prompts.css',
            [],
            $version
        );

        // Script for interaction logic
        wp_enqueue_script(
            'odcm-upgrade-prompts',
            $assets_url . 'js/upgrade-prompts.js',
            ['jquery'],
            $version,
            true
        );

        // Build localized configuration
        $user_id = get_current_user_id();
        $prefs   = $this->get_user_prefs($user_id);
        $nonce   = wp_create_nonce('odcm_upgrade_prompts');

        $website_url = defined('ODCM_WEBSITE_URL') ? esc_url_raw(constant('ODCM_WEBSITE_URL')) : '';
        $docs_url    = defined('ODCM_DOCS_URL') ? esc_url_raw(constant('ODCM_DOCS_URL')) : $website_url;

        // Feature comparison data (non-exhaustive, educational only)
        $comparison = [
            [
                'feature' => __('upgrade_prompts.comparison.advanced_filtering', 'order-daemon'),
                'core'    => __('upgrade_prompts.comparison.basic_search', 'order-daemon'),
                'pro'     => __('upgrade_prompts.comparison.advanced_search', 'order-daemon'),
            ],
            [
                'feature' => __('upgrade_prompts.comparison.rule_conditions', 'order-daemon'),
                'core'    => __('upgrade_prompts.comparison.common_conditions', 'order-daemon'),
                'pro'     => __('upgrade_prompts.comparison.extended_conditions', 'order-daemon'),
            ],
            [
                'feature' => __('upgrade_prompts.comparison.actions', 'order-daemon'),
                'core'    => __('upgrade_prompts.comparison.primary_action', 'order-daemon'),
                'pro'     => __('upgrade_prompts.comparison.secondary_actions', 'order-daemon'),
            ],
        ];

        $examples = [
            __('upgrade_prompts.message.premium_feature', 'order-daemon'),
            __('upgrade_prompts.message.filtering_options', 'order-daemon'),
            __('upgrade_prompts.message.visit_website', 'order-daemon'),
            __('upgrade_prompts.message.upgrade_capabilities', 'order-daemon'),
            __('upgrade_prompts.message.premium_possibilities', 'order-daemon'),
        ];

        wp_localize_script('odcm-upgrade-prompts', 'odcmUpgradePrompts', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => $nonce,
            'prefs'       => $prefs,
            'enabled'     => DependencyChecker::should_show_upgrade_prompts(),
            'docsUrl'     => $docs_url,
            'websiteUrl'  => $website_url,
            'examples'    => $examples,
            'comparison'  => $comparison,
            'contexts'    => [
                'rule_builder'    => [
                    'title'   => __('upgrade_prompts.modal.premium_components_title', 'order-daemon'),
                    'message' => DependencyChecker::get_wordpress_org_compliant_message('rule_builder'),
                    'promptKey' => 'rule_builder_premium',
                ],
                'insight_filters' => [
                    'title'   => __('upgrade_prompts.modal.advanced_filters_title', 'order-daemon'),
                    'message' => DependencyChecker::get_wordpress_org_compliant_message('insight_filters'),
                    'promptKey' => 'insight_filters_premium',
                ],
            ],
            'i18n'        => [
                'learnMore'    => __('upgrade_prompts.modal.learn_more', 'order-daemon'),
                'close'        => __('upgrade_prompts.modal.close', 'order-daemon'),
                'dontShow'     => __('upgrade_prompts.modal.dont_show_again', 'order-daemon'),
                'preferences'  => __('upgrade_prompts.modal.preferences', 'order-daemon'),
                'frequency'    => __('admin.insight_dashboard.settings.prompt_frequency.label', 'order-daemon'),
                'freq_normal'  => __('admin.insight_dashboard.settings.prompt_frequency.normal', 'order-daemon'),
                'freq_reduced' => __('admin.insight_dashboard.settings.prompt_frequency.reduced', 'order-daemon'),
                'freq_off'     => __('admin.insight_dashboard.settings.prompt_frequency.off', 'order-daemon'),
                'saved'        => __('upgrade_prompts.modal.saved', 'order-daemon'),
            ],
        ]);
    }

    /**
     * Renders a reusable modal container for prompts in the admin footer.
     * Output is escaped and includes no direct external links besides docs/website.
     *
     * @return void
     */
    private function render_modal_container(): void
    {
        ?>
        <div id="odcm-upgrade-modal" class="odcm-upgrade-modal" style="display:none" aria-hidden="true">
            <div class="odcm-upgrade-modal__backdrop" tabindex="-1"></div>
            <div class="odcm-upgrade-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="odcm-upgrade-modal-title">
                <button type="button" class="odcm-upgrade-modal__close" aria-label="<?php echo esc_attr__('upgrade_prompts.modal.close', 'order-daemon'); ?>">×</button>
                <div class="odcm-upgrade-modal__header">
                    <h2 id="odcm-upgrade-modal-title" class="odcm-upgrade-modal__title"></h2>
                </div>
                <div class="odcm-upgrade-modal__body">
                    <p class="odcm-upgrade-modal__message"></p>
                    <div class="odcm-upgrade-modal__comparison" aria-live="polite"></div>
                    <div class="odcm-upgrade-modal__links">
                        <a href="#" target="_blank" rel="noopener" class="odcm-upgrade-link odcm-upgrade-link--docs" style="display:none"></a>
                        <a href="#" target="_blank" rel="noopener" class="odcm-upgrade-link odcm-upgrade-link--site" style="display:none"></a>
                    </div>
                </div>
                <div class="odcm-upgrade-modal__footer">
                    <label class="odcm-upgrade-modal__dismiss">
                        <input type="checkbox" class="odcm-upgrade-modal__dont-show"> <?php echo esc_html__('upgrade_prompts.modal.dont_show_again', 'order-daemon'); ?>
                    </label>
                    <div class="odcm-upgrade-modal__actions">
                        <button type="button" class="button odcm-upgrade-modal__close-btn"><?php echo esc_html__('upgrade_prompts.modal.close', 'order-daemon'); ?></button>
                        <a class="button button-secondary odcm-upgrade-modal__learn-more" href="#" target="_blank" rel="noopener" style="display:none"><?php echo esc_html__('upgrade_prompts.modal.learn_more', 'order-daemon'); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get preferences for a user, merged with defaults.
     *
     * @param int $user_id User ID
     * @return array{frequency:string,dismissed:array<string,bool>}
     */
    private function get_user_prefs(int $user_id): array
    {
        $raw = get_user_meta($user_id, self::USER_META_KEY, true);
        $prefs = is_array($raw) ? $raw : [];
        $defaults = [
            'frequency' => 'normal', // normal|reduced|off
            'dismissed' => [],       // map of promptKey => true
        ];
        $merged = array_merge($defaults, $prefs);
        // sanitize structure
        $merged['frequency'] = in_array($merged['frequency'], ['normal', 'reduced', 'off'], true) ? $merged['frequency'] : 'normal';
        if (!is_array($merged['dismissed'])) {
            $merged['dismissed'] = [];
        }
        return $merged;
    }

    /**
     * Update preferences for a user.
     *
     * @param int   $user_id User ID
     * @param array $prefs   Preferences to save
     * @return bool
     */
    private function update_user_prefs(int $user_id, array $prefs): bool
    {
        $current = $this->get_user_prefs($user_id);
        $new = $current;
        if (isset($prefs['frequency'])) {
            $freq = sanitize_key((string)$prefs['frequency']);
            if (in_array($freq, ['normal', 'reduced', 'off'], true)) {
                $new['frequency'] = $freq;
            }
        }
        if (isset($prefs['dismissed']) && is_array($prefs['dismissed'])) {
            foreach ($prefs['dismissed'] as $key => $val) {
                $prompt_key = sanitize_key((string)$key);
                $new['dismissed'][$prompt_key] = (bool)$val;
            }
        }
        return (bool) update_user_meta($user_id, self::USER_META_KEY, $new);
    }

    /**
     * AJAX handler to update preferences (frequency and bulk dismissed map).
     *
     * @return void
     */
    public function handle_update_prefs_ajax(): void
    {
        // Capability and nonce checks
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.insufficient_permissions', 'order-daemon')], 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'odcm_upgrade_prompts')) {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.security_check_failed', 'order-daemon')], 400);
        }

        $prefs = [
            'frequency' => isset($_POST['frequency']) ? sanitize_key((string) $_POST['frequency']) : null,
        ];

        $updated = $this->update_user_prefs(get_current_user_id(), $prefs);
        if ($updated) {
            wp_send_json_success(['message' => __('upgrade_prompts.ajax.preferences_saved', 'order-daemon')]);
        }
        wp_send_json_error(['message' => __('upgrade_prompts.ajax.no_changes', 'order-daemon')]);
    }

    /**
     * AJAX handler to dismiss a single prompt by key.
     *
     * @return void
     */
    public function handle_dismiss_prompt_ajax(): void
    {
        // Capability and nonce checks
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.insufficient_permissions', 'order-daemon')], 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'odcm_upgrade_prompts')) {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.security_check_failed', 'order-daemon')], 400);
        }

        $key = isset($_POST['promptKey']) ? sanitize_key((string) $_POST['promptKey']) : '';
        if ($key === '') {
            wp_send_json_error(['message' => __('upgrade_prompts.ajax.invalid_prompt_key', 'order-daemon')], 400);
        }

        $user_id = get_current_user_id();
        $prefs = $this->get_user_prefs($user_id);
        $prefs['dismissed'][$key] = true;
        $ok = update_user_meta($user_id, self::USER_META_KEY, $prefs);

        if ($ok) {
            wp_send_json_success(['message' => __('upgrade_prompts.ajax.prompt_dismissed', 'order-daemon')]);
        }
        wp_send_json_error(['message' => __('upgrade_prompts.ajax.ate', 'order-daemon')]);
    }
}
