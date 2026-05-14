<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects when the site URL has changed since the plugin was first installed
 * and surfaces an admin notice with an option to acknowledge the change.
 *
 * The first time an admin page loads after activation, the current home_url()
 * is stored as the registered URL. On subsequent loads, if the stored URL
 * differs from the current home_url(), an action hook fires and a notice is
 * displayed. The Pro plugin suppresses this default notice and shows its own
 * three-button variant with license management options.
 */
final class SiteUrlMonitor {

	const OPTION_REGISTERED_URL = 'odcm_registered_site_url';

	/**
	 * Register all hooks. Call once from Plugin::initialize_admin_components().
	 */
	public static function register(): void {
		$monitor = new self();
		add_action( 'admin_init',   [ $monitor, 'maybe_detect_url_change' ], 5 );
		add_action( 'admin_notices', [ $monitor, 'display_url_change_notice' ], 5 );
		add_action( 'wp_ajax_odcm_acknowledge_url_change', [ $monitor, 'ajax_acknowledge' ] );
	}

	/**
	 * On admin_init: seed the registered URL on first run, or fire the
	 * odcm_site_url_changed action when a change is detected.
	 */
	public function maybe_detect_url_change(): void {
		$stored = self::get_registered_url();
		if ( empty( $stored ) ) {
			self::update_registered_url( home_url() );
			return;
		}
		if ( $stored !== home_url() ) {
			do_action( 'odcm_site_url_changed', $stored, home_url() );
		}
	}

	/**
	 * Render the URL-change warning notice.
	 *
	 * Skipped when:
	 * - The odcm_show_default_url_change_notice filter returns false (Pro hooks here)
	 * - No URL change is detected
	 * - The current user lacks manage_woocommerce capability
	 */
	public function display_url_change_notice(): void {
		if ( ! apply_filters( 'odcm_show_default_url_change_notice', true ) ) {
			return;
		}
		if ( ! self::has_url_changed() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$old_url  = self::get_registered_url();
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'odcm_acknowledge_url_change' );
		?>
		<div class="notice notice-warning odcm-url-change-notice">
			<p>
				<strong>Order Daemon:</strong>
				This site&rsquo;s URL has changed from
				<code><?php echo esc_html( $old_url ); ?></code> to
				<code><?php echo esc_html( home_url() ); ?></code>.
				This may affect PayPal transaction verification and other integrations.
			</p>
			<p>
				<button type="button" class="button button-primary odcm-acknowledge-url-change"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"
				        data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
					Acknowledge &amp; Update
				</button>
			</p>
		</div>
		<script>
		(function () {
			var btn = document.querySelector( '.odcm-acknowledge-url-change' );
			if ( ! btn ) return;
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				var fd = new FormData();
				fd.append( 'action', 'odcm_acknowledge_url_change' );
				fd.append( 'nonce', btn.dataset.nonce );
				fetch( btn.dataset.ajaxUrl, { method: 'POST', body: fd } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) {
						if ( r.success ) { location.reload(); } else { btn.disabled = false; }
					} )
					.catch( function () { btn.disabled = false; } );
			} );
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler: update the registered URL to the current home_url().
	 */
	public function ajax_acknowledge(): void {
		odcm_check_user_capability( 'manage_woocommerce', 'ajax' );

		if (
			empty( $_POST['nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'odcm_acknowledge_url_change' )
		) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ] );
		}

		self::update_registered_url( home_url() );
		wp_send_json_success( [ 'message' => 'Site URL updated.' ] );
	}

	// ─── Public static API ────────────────────────────────────────────────────

	public static function get_registered_url(): string {
		return (string) get_option( self::OPTION_REGISTERED_URL, '' );
	}

	public static function update_registered_url( string $url ): void {
		update_option( self::OPTION_REGISTERED_URL, $url, false );
	}

	/**
	 * Returns true when a non-empty registered URL exists and differs from home_url().
	 */
	public static function has_url_changed(): bool {
		$stored = self::get_registered_url();
		return ! empty( $stored ) && $stored !== home_url();
	}
}
