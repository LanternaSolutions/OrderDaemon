<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Admin;

/**
 * Handles the queuing and display of all admin-facing notices.
 * Supports both site-wide (transient) and user-specific (user meta) notices.
 */
final class Notices {

    /** @var string The transient key for site-wide critical notices. */
    private const TRANSIENT_KEY = 'odcm_site_wide_notices';

    /**
     * Hooks the methods into WordPress.
     */
    public function register(): void {
        add_action('admin_notices', [$this, 'display_site_wide_notices'], 10);
        add_action('wp_ajax_odcm_dismiss_site_wide_notice', [$this, 'ajax_dismiss_site_wide_notice'], 10);
        add_action('wp_ajax_nopriv_odcm_dismiss_site_wide_notice', [$this, 'ajax_dismiss_site_wide_notice'], 10);
    }

    /**
     * Queues a new SITE-WIDE notice to be displayed to ALL administrators.
     * Uses a transient for site-wide storage.
     *
     * @param string $id A unique ID for this notice.
     * @param string $type The type of notice ('error', 'warning', 'success', 'info').
     * @param string $message The message content. Supports HTML.
     * @return void
     */
    public static function add_site_wide(string $id, string $type, string $message): void {
        $notices = get_transient(self::TRANSIENT_KEY);
        if (!is_array($notices)) {
            $notices = [];
        }

        // Create a unique ID with timestamp to prevent overwriting
        $unique_id = $id . '_' . time();

        $notices[$unique_id] = [
            'id'             => $unique_id,
            'type'           => sanitize_key($type),
            'message'        => $message,
            'is_dismissible' => true, // Site-wide notices must be dismissible
            'created_at'     => time(),
        ];

        // Clean up old notices (older than 1 hour) to prevent accumulation
        $current_time = time();
        foreach ($notices as $notice_id => $notice) {
            if (isset($notice['created_at']) && ($current_time - $notice['created_at']) > HOUR_IN_SECONDS) {
                unset($notices[$notice_id]);
            }
        }

        // Store for 24 hours. The dismiss action will delete it sooner.
        set_transient(self::TRANSIENT_KEY, $notices, DAY_IN_SECONDS);
    }

    /**
     * Renders all queued site-wide notices.
     */
    public function display_site_wide_notices(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $notices = get_transient(self::TRANSIENT_KEY);
        if (empty($notices) || !is_array($notices)) {
            return;
        }

        $nonce = wp_create_nonce('odcm_dismiss_notice_nonce');

        foreach ($notices as $notice) {
            ?>
            <div id="<?php echo esc_attr($notice['id']); ?>" class="notice notice-<?php echo esc_attr($notice['type']); ?> odcm-site-wide-notice">
                <p><strong><?php
                    /* translators: Prefix text displayed before all admin notifications */
                    esc_html_e('admin.notices.prefix', 'order-daemon'); ?>:</strong> <?php echo wp_kses_post($notice['message']); ?></p>
                <button type="button" class="notice-dismiss" data-notice-id="<?php echo esc_attr($notice['id']); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                    <span class="screen-reader-text"><?php
                        /* translators: Screen reader accessibility text for the notice dismiss button */
                        esc_html_e('admin.notices.dismiss', 'order-daemon'); ?></span>
                </button>
            </div>
            <?php
        }
    }

    /**
     * AJAX handler to dismiss a specific site-wide notice.
     * This removes the notice from the site-wide transient queue.
     */
    public function ajax_dismiss_site_wide_notice(): void {
        // Always check capability and nonce first in AJAX handlers
        odcm_check_user_capability('manage_woocommerce', 'ajax');

        // Use wp_verify_nonce instead of check_ajax_referer for better compatibility
        if (empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'odcm_dismiss_notice_nonce')) {
            wp_send_json_error(['message' => __('security.check_failed', 'order-daemon')]);
        }

        if (empty($_POST['notice_id'])) {
            wp_send_json_error(['message' => __('admin.notices.error.notice_id_required', 'order-daemon')]);
        }

        $notice_id_to_dismiss = sanitize_key($_POST['notice_id']);
        $notices = get_transient(self::TRANSIENT_KEY);

        if (is_array($notices) && isset($notices[$notice_id_to_dismiss])) {
            unset($notices[$notice_id_to_dismiss]);
            // If the notices array is now empty, delete the transient. Otherwise, update it.
            if (empty($notices)) {
                delete_transient(self::TRANSIENT_KEY);
            } else {
                set_transient(self::TRANSIENT_KEY, $notices, DAY_IN_SECONDS);
            }
            wp_send_json_success(['message' => __('admin.notices.success.notice_dismissed', 'order-daemon')]);
        } else {
            wp_send_json_error(['message' => __('admin.notices.error.notice_not_found', 'order-daemon')]);
        }
    }
}
