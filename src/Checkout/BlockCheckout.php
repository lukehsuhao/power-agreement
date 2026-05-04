<?php
/**
 * Block Checkout integration (Store API + IntegrationInterface).
 *
 * @package PowerAgreement\Checkout
 */

declare(strict_types=1);

namespace PowerAgreement\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\Blocks\Package as BlocksPackage;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use PowerAgreement\Order\OrderMetaWriter;
use PowerAgreement\Settings\SettingsRepository;
use WC_Order;
use WP_REST_Request;

/**
 * Wires the agreement into the Block-based checkout via the official
 * IntegrationInterface + ExtendSchema contract.
 *
 * Two layers of defence:
 * - Front-end: a small React component renders the accordion and pushes
 *   consent state into the cart via extensionCartUpdate(). This gives
 *   the user a familiar UX matching Classic checkout.
 * - Back-end: the Store API extension validator throws RouteException
 *   when consent is missing, so a maliciously hand-crafted POST cannot
 *   bypass the gate even if the React layer is tampered with.
 */
final class BlockCheckout implements IntegrationInterface {

	public const NAMESPACE_ID   = 'power-agreement';
	private const SCRIPT_HANDLE = 'power-agreement-block-checkout';

	private SettingsRepository $repo;
	private ConsentValidator $validator;
	private OrderMetaWriter $writer;
	private string $plugin_file;

	public function __construct(
		SettingsRepository $repo,
		ConsentValidator $validator,
		OrderMetaWriter $writer,
		string $plugin_file
	) {
		$this->repo        = $repo;
		$this->validator   = $validator;
		$this->writer      = $writer;
		$this->plugin_file = $plugin_file;
	}

	// --- WordPress hook registration -------------------------------------

	/**
	 * Register every hook this integration needs.
	 *
	 * Plugin::run() also wires the registry hook independently to dodge the
	 * timing window between init and IntegrationRegistry::initialize(); calling
	 * register() from a test or bootstrap is therefore safe and idempotent
	 * (the registry refuses duplicate names with a "_doing_it_wrong" notice).
	 */
	public function register(): void {
		$self = $this;

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			static function ( $registry ) use ( $self ): void {
				$registry->register( $self );
			}
		);

		add_action( 'woocommerce_blocks_loaded', array( $this, 'extendStoreApi' ) );

		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			array( $this, 'validateConsent' ),
			10,
			2
		);

		add_action(
			'woocommerce_store_api_checkout_update_order_meta',
			array( $this, 'persistMeta' )
		);

		// Auto-inject our block into the checkout page content so React can
		// hydrate our component even when the merchant has not edited the
		// page in Site Editor. We piggy-back on the standard `the_content`
		// filter used by `do_blocks()` rather than editing the stored post.
		add_filter( 'the_content', array( $this, 'injectIntoCheckoutContent' ), 5 );

		// Register the block so do_blocks() does not strip our markup.
		add_action( 'init', array( $this, 'registerServerSideBlockType' ) );
	}

	/**
	 * Register a minimal server-side block type so do_blocks() leaves a
	 * placeholder element in the HTML for our React component to hydrate.
	 */
	public function registerServerSideBlockType(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'power-agreement/agreement' ) ) {
			return;
		}
		register_block_type(
			'power-agreement/agreement',
			array(
				'api_version'     => '3',
				'render_callback' => static fn(): string => '<div data-block-name="power-agreement/agreement"></div>',
			)
		);
	}

	// --- IntegrationInterface --------------------------------------------

	public function get_name(): string {
		return self::NAMESPACE_ID;
	}

	public function initialize(): void {
		$build_dir  = dirname( $this->plugin_file ) . '/assets/build';
		$build_url  = plugin_dir_url( $this->plugin_file ) . 'assets/build';
		$asset_file = $build_dir . '/index.asset.php';

		// When the JS bundle has not been built yet, use minimal defaults so
		// PHPUnit / static contexts don't emit warnings.
		$asset = file_exists( $asset_file )
			? include $asset_file
			: array(
				'dependencies' => array(),
				'version'      => \PowerAgreement\Plugin::version(),
			);

		// Register front-end script (built bundle, registered lazily).
		if ( file_exists( $build_dir . '/index.js' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				$build_url . '/index.js',
				$asset['dependencies'] ?? array(),
				$asset['version'] ?? \PowerAgreement\Plugin::version(),
				true
			);
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations(
					self::SCRIPT_HANDLE,
					'power-agreement',
					dirname( $this->plugin_file ) . '/languages'
				);
			}
		}
	}

	/**
	 * @return array<int, string>
	 */
	public function get_script_handles(): array {
		return file_exists( dirname( $this->plugin_file ) . '/assets/build/index.js' )
			? array( self::SCRIPT_HANDLE )
			: array();
	}

	/**
	 * @return array<int, string>
	 */
	public function get_editor_script_handles(): array {
		return $this->get_script_handles();
	}

	/**
	 * @return array{enabled: bool, title: string, content: string, consent_text: string}
	 */
	public function get_script_data(): array {
		return array(
			'enabled'      => $this->repo->isEnabled(),
			'title'        => $this->repo->title(),
			'content'      => $this->repo->content(),
			'consent_text' => $this->repo->consentText(),
		);
	}

	// --- Store API extension --------------------------------------------

	public function extendStoreApi(): void {
		// Prefer the public helper functions over reaching into the container
		// directly — they handle "ExtendSchema not registered yet" gracefully
		// and resolve to the correct StoreApi container, not BlocksPackage.
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			\woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => CheckoutSchema::IDENTIFIER,
					'namespace'       => self::NAMESPACE_ID,
					'data_callback'   => static fn(): array => array( 'consent' => false ),
					'schema_callback' => static fn(): array => array(
						'consent' => array(
							'description' => __( 'Whether the customer has accepted the agreement.', 'power-agreement' ),
							'type'        => 'boolean',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => false,
						),
					),
					'schema_type'     => ARRAY_A,
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			\woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE_ID,
					'callback'  => static function ( array $data ): void {
						unset( $data ); // No-op; consent is enforced at checkout submit.
					},
				)
			);
		}
	}

	public function validateConsent( WC_Order $order, WP_REST_Request $request ): void {
		unset( $order ); // Reserved for future per-order rules; not needed today.

		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}

		$extensions = $request->get_param( 'extensions' );
		$consent    = false;
		if ( is_array( $extensions ) && isset( $extensions[ self::NAMESPACE_ID ]['consent'] ) ) {
			$consent = (bool) $extensions[ self::NAMESPACE_ID ]['consent'];
		}

		if ( ! $consent ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error code is a constant; message is i18n'd inside errorMessage()
			throw new RouteException( ConsentValidator::ERROR_CODE, $this->validator->errorMessage(), 400 );
		}
	}

	public function persistMeta( WC_Order $order ): void {
		if ( ! $this->validator->shouldEnforce() ) {
			return;
		}
		$ip = '';
		if ( class_exists( '\WC_Geolocation' ) ) {
			$ip = (string) \WC_Geolocation::get_ip_address();
		}
		$this->writer->writeFromRequest( $order, $ip );
	}

	/**
	 * Inject the agreement block's serialized markup before the closing
	 * checkout-fields-block tag in the rendered checkout page content.
	 *
	 * Runs at `the_content` priority 5 so it executes before `do_blocks()`,
	 * which means our block goes through the standard render pipeline and
	 * hydrates via the React component registered with registerCheckoutBlock.
	 */
	public function injectIntoCheckoutContent( string $content ): string {
		if ( is_admin() ) {
			return $content;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $content;
		}
		if ( ! $this->validator->shouldEnforce() ) {
			return $content;
		}
		// Only act on the checkout block flow, not the [woocommerce_checkout] shortcode.
		if ( false === strpos( $content, '<!-- wp:woocommerce/checkout-fields-block' ) ) {
			return $content;
		}
		// Already injected? bail out.
		if ( false !== strpos( $content, 'wp:power-agreement/agreement' ) ) {
			return $content;
		}

		$marker      = '<!-- /wp:woocommerce/checkout-actions-block -->';
		$block       = '<!-- wp:power-agreement/agreement /-->';
		$replacement = $block . "\n" . $marker;
		$updated     = str_replace( $marker, $replacement, $content, $count );
		if ( $count > 0 ) {
			return $updated;
		}
		// Fallback: insert before the closing checkout-fields-block tag.
		$close = '<!-- /wp:woocommerce/checkout-fields-block -->';
		return str_replace( $close, $block . "\n" . $close, $content );
	}
}
