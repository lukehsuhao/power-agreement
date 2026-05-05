<?php
/**
 * Classic Checkout integration.
 *
 * @package PowerAgreement\Checkout
 */

declare(strict_types=1);

namespace PowerAgreement\Checkout;

defined( 'ABSPATH' ) || exit;

use PowerAgreement\Order\OrderMetaWriter;
use PowerAgreement\Settings\SettingsRepository;
use WC_Order;

/**
 * Wires the agreement accordion + consent checkbox into the
 * Classic / shortcode-based WooCommerce checkout.
 *
 * Three responsibilities:
 *   render()   — outputs the accordion above the place-order button
 *   validate() — fails the checkout when the box is unticked
 *   persist()  — captures the snapshot onto the new order
 *
 * All three are guarded by ConsentValidator::shouldEnforce() so the
 * integration is a transparent no-op when the agreement is disabled
 * or empty.
 */
final class ClassicCheckout {

	public const FIELD_NAME = 'power_agreement_consent';

	private SettingsRepository $repo;
	private ConsentValidator $validator;
	private OrderMetaWriter $writer;

	public function __construct(
		SettingsRepository $repo,
		ConsentValidator $validator,
		OrderMetaWriter $writer
	) {
		$this->repo      = $repo;
		$this->validator = $validator;
		$this->writer    = $writer;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybeEnqueueAssets' ) );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist' ), 10, 2 );
	}

	public function maybeEnqueueAssets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}

		$base = defined( 'POWER_AGREEMENT_URL' ) ? POWER_AGREEMENT_URL : plugin_dir_url( dirname( __DIR__ ) );
		$root = defined( 'POWER_AGREEMENT_DIR' ) ? POWER_AGREEMENT_DIR : trailingslashit( dirname( __DIR__, 2 ) );

		$mode = $this->repo->displayMode();
		$slug = SettingsRepository::DISPLAY_MODE_INLINE_SCROLL === $mode ? 'inline-scroll' : 'accordion';

		$css_path = $root . 'assets/src/frontend/' . $slug . '.css';
		$js_path  = $root . 'assets/src/frontend/' . $slug . '.js';

		wp_enqueue_style(
			'power-agreement-frontend',
			$base . 'assets/src/frontend/' . $slug . '.css',
			array(),
			$this->assetVersion( $css_path )
		);
		wp_enqueue_script(
			'power-agreement-frontend',
			$base . 'assets/src/frontend/' . $slug . '.js',
			array(),
			$this->assetVersion( $js_path ),
			true
		);
	}

	/**
	 * Return a cache-bustable version string for an asset.
	 *
	 * Falls back to the plugin version when the file is not readable so
	 * unit/static contexts stay deterministic; otherwise prefers filemtime
	 * which auto-busts the browser cache whenever the asset is edited.
	 */
	private function assetVersion( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return \PowerAgreement\Plugin::version();
		}
		$mtime = filemtime( $path );
		return false === $mtime
			? \PowerAgreement\Plugin::version()
			: \PowerAgreement\Plugin::version() . '.' . (string) $mtime;
	}

	public function render(): void {
		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}

		$mode = $this->repo->displayMode();
		if ( SettingsRepository::DISPLAY_MODE_INLINE_SCROLL === $mode ) {
			$this->renderInlineScroll();
			return;
		}
		$this->renderAccordion();
	}

	private function renderAccordion(): void {
		$title        = $this->repo->title();
		$content      = $this->repo->content();
		$consent_text = $this->repo->consentText();

		?>
		<div class="power-agreement power-agreement--accordion" data-power-agreement>
			<button type="button"
				class="power-agreement__toggle"
				aria-expanded="false"
				aria-controls="power-agreement-content"
				data-power-agreement-toggle>
				<span class="power-agreement__title"><?php echo esc_html( $title ); ?></span>
				<span class="power-agreement__chevron" aria-hidden="true">▾</span>
			</button>
			<div id="power-agreement-content"
				class="power-agreement__content"
				role="region"
				hidden>
				<?php echo wp_kses_post( $content ); ?>
			</div>
			<label class="power-agreement__consent">
				<input type="checkbox"
					name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
					value="1" />
				<span><?php echo esc_html( $consent_text ); ?></span>
			</label>
		</div>
		<?php
	}

	private function renderInlineScroll(): void {
		$title        = $this->repo->title();
		$content      = $this->repo->content();
		$consent_text = $this->repo->consentText();

		$expand_label = __( 'Expand to view', 'power-agreement' );

		?>
		<div class="power-agreement power-agreement--inline-scroll" data-power-agreement>
			<div class="power-agreement__compact-card">
				<span class="power-agreement__title"><?php echo esc_html( $title ); ?></span>
				<button type="button"
					class="power-agreement__expand-btn"
					aria-haspopup="dialog"
					aria-controls="power-agreement-modal"
					data-power-agreement-open>
					<?php echo esc_html( $expand_label ); ?>
				</button>
			</div>
			<label class="power-agreement__consent">
				<input type="checkbox"
					name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
					id="power-agreement-consent-outer"
					value="1"
					data-power-agreement-consent="outer" />
				<span><?php echo esc_html( $consent_text ); ?></span>
			</label>
			<dialog id="power-agreement-modal"
				class="power-agreement__modal"
				data-power-agreement-modal
				aria-labelledby="power-agreement-modal-title">
				<div class="power-agreement__modal-header">
					<h2 id="power-agreement-modal-title" class="power-agreement__modal-title">
						<?php echo esc_html( $title ); ?>
					</h2>
					<button type="button"
						class="power-agreement__modal-icon-close"
						data-power-agreement-close
						aria-label="<?php esc_attr_e( 'Close', 'power-agreement' ); ?>">×</button>
				</div>
				<div class="power-agreement__modal-body">
					<?php echo wp_kses_post( $content ); ?>
				</div>
				<div class="power-agreement__modal-footer">
					<label class="power-agreement__consent power-agreement__consent--inner">
						<input type="checkbox"
							value="1"
							data-power-agreement-consent="inner" />
						<span><?php echo esc_html( $consent_text ); ?></span>
					</label>
					<button type="button"
						class="power-agreement__confirm-btn"
						data-power-agreement-close>
						<?php esc_html_e( 'Confirm', 'power-agreement' ); ?>
					</button>
				</div>
			</dialog>
		</div>
		<?php
	}

	public function validate(): void {
		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified by WC checkout itself before this hook fires.
		$consent = isset( $_POST[ self::FIELD_NAME ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_NAME ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '1' !== $consent ) {
			wc_add_notice( $this->validator->errorMessage(), 'error' );
		}
	}

	/**
	 * @param WC_Order             $order
	 * @param array<string, mixed> $data Posted checkout fields (unused here).
	 */
	public function persist( WC_Order $order, array $data ): void {
		unset( $data ); // intentionally unused
		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}

		$ip = '';
		if ( class_exists( '\WC_Geolocation' ) ) {
			$ip = (string) \WC_Geolocation::get_ip_address();
		}
		$this->writer->writeFromRequest( $order, $ip );
	}
}
