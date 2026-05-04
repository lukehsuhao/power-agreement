<?php
/**
 * Classic Checkout integration.
 *
 * @package PowerAgreement\Checkout
 */

declare(strict_types=1);

namespace PowerAgreement\Checkout;

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

		wp_enqueue_style(
			'power-agreement-frontend',
			$base . 'assets/src/frontend/accordion.css',
			array(),
			\PowerAgreement\Plugin::version()
		);
		wp_enqueue_script(
			'power-agreement-frontend',
			$base . 'assets/src/frontend/accordion.js',
			array(),
			\PowerAgreement\Plugin::version(),
			true
		);
	}

	public function render(): void {
		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}

		$title        = $this->repo->title();
		$content      = $this->repo->content();
		$consent_text = $this->repo->consentText();

		?>
		<div class="power-agreement" data-power-agreement>
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

	public function validate(): void {
		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WC checkout itself.
		$consent = isset( $_POST[ self::FIELD_NAME ] ) ? wp_unslash( $_POST[ self::FIELD_NAME ] ) : '';
		if ( '1' !== (string) $consent ) {
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
