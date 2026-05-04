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
		return array( 'classic_checkout' );
	}
}
