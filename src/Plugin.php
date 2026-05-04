<?php
/**
 * Power Agreement main plugin class.
 *
 * @package PowerAgreement
 */

declare(strict_types=1);

namespace PowerAgreement;

/**
 * Central registrar for all hooks.
 *
 * Receives the absolute path to the plugin's main file and lazily wires up
 * every module on `init`. Does not perform any work in the constructor so it
 * remains cheap to instantiate from tests.
 *
 * Modules are added stage by stage in {@see self::run()} as the plugin is
 * built out — see specs/2026-05-04-power-agreement-plan.md.
 */
final class Plugin {

	private const VERSION = '0.1.0';

	private string $plugin_file;

	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Register all hooks. Idempotent — safe to call once per request.
	 *
	 * @return list<string> Names of modules that were actually registered.
	 *                      Useful for tests and diagnostics.
	 */
	public function run(): array {
		$registered   = array();
		$registered[] = $this->registerTextdomain();
		$registered   = array_merge( $registered, $this->registerSettingsModule() );
		$registered   = array_merge( $registered, $this->registerOrderModule() );
		$registered   = array_merge( $registered, $this->registerCheckoutModule() );

		return array_values( array_filter( $registered ) );
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain(
			'power-agreement',
			false,
			dirname( plugin_basename( $this->plugin_file ) ) . '/languages'
		);
	}

	public function getPluginFile(): string {
		return $this->plugin_file;
	}

	public static function version(): string {
		return self::VERSION;
	}

	private function registerTextdomain(): string {
		add_action( 'init', array( $this, 'loadTextdomain' ), 5 );
		return 'textdomain';
	}

	/**
	 * @return list<string>
	 */
	private function registerSettingsModule(): array {
		if ( ! is_admin() ) {
			return array();
		}
		add_action(
			'init',
			static function (): void {
				$page = new \PowerAgreement\Settings\SettingsPage(
					new \PowerAgreement\Settings\SettingsRepository()
				);
				$page->register();
			},
			0
		);
		return array( 'settings_page' );
	}

	/**
	 * @return list<string>
	 */
	private function registerOrderModule(): array {
		if ( ! is_admin() ) {
			return array();
		}
		add_action(
			'init',
			static function (): void {
				( new \PowerAgreement\Order\OrderAdminDisplay() )->register();
			},
			0
		);
		return array( 'order_admin_display' );
	}

	/**
	 * @return list<string>
	 */
	private function registerCheckoutModule(): array {
		$plugin_file = $this->plugin_file;

		// Classic checkout uses regular WC hooks, safe to wire up on init.
		add_action(
			'init',
			static function (): void {
				$repo      = new \PowerAgreement\Settings\SettingsRepository();
				$validator = new \PowerAgreement\Checkout\ConsentValidator( $repo );
				$writer    = new \PowerAgreement\Order\OrderMetaWriter( $repo );
				( new \PowerAgreement\Checkout\ClassicCheckout( $repo, $validator, $writer ) )->register();
			},
			0
		);

		// Block checkout: the IntegrationRegistry is initialized inside the
		// constructor of WC's AbstractBlock, which runs as soon as WC blocks
		// loads — earlier than `init`. Register the hook synchronously here so
		// it is in place before WC iterates the registry.
		$integration_factory = static function () use ( $plugin_file ): \PowerAgreement\Checkout\BlockCheckout {
			$repo      = new \PowerAgreement\Settings\SettingsRepository();
			$validator = new \PowerAgreement\Checkout\ConsentValidator( $repo );
			$writer    = new \PowerAgreement\Order\OrderMetaWriter( $repo );
			return new \PowerAgreement\Checkout\BlockCheckout( $repo, $validator, $writer, $plugin_file );
		};

		// (a) register the integration with WC's checkout block registry
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			static function ( $registry ) use ( $integration_factory ): void {
				$registry->register( $integration_factory() );
			}
		);

		// (b) Register everything else from BlockCheckout itself: schema,
		//     Store API validation/persist hooks, and the_content injection.
		//     We use `init` priority 1 because by the time we are wired up
		//     via plugins_loaded(20), the woocommerce_blocks_loaded action
		//     has typically already fired.
		$wire_up_block_checkout = static function () use ( $integration_factory ): void {
			$integration = $integration_factory();
			$integration->extendStoreApi();
			add_action(
				'woocommerce_store_api_checkout_update_order_from_request',
				array( $integration, 'validateConsent' ),
				10,
				2
			);
			add_action(
				'woocommerce_store_api_checkout_update_order_meta',
				array( $integration, 'persistMeta' )
			);
			add_filter(
				'the_content',
				array( $integration, 'injectIntoCheckoutContent' ),
				5
			);
			// Register the dynamic block so do_blocks() leaves a hydration anchor.
			if ( did_action( 'init' ) ) {
				$integration->registerServerSideBlockType();
			} else {
				add_action( 'init', array( $integration, 'registerServerSideBlockType' ) );
			}
		};

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$wire_up_block_checkout();
		} else {
			add_action( 'woocommerce_blocks_loaded', $wire_up_block_checkout );
		}

		return array( 'classic_checkout', 'block_checkout' );
	}
}
