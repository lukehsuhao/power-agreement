<?php
/**
 * PHPUnit bootstrap for Power Agreement.
 *
 * Two modes:
 *   - Unit suite (tests/Unit): pure PHP, no WordPress. Just composer autoload.
 *   - Integration suite (tests/Integration): requires wp-phpunit + WC.
 *
 * The integration bootstrap is loaded only when WP_PHPUNIT__DIR is set,
 * so unit tests can run on a developer machine without Docker.
 *
 * @package PowerAgreement\Tests
 */

declare(strict_types=1);

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` before running tests.\n" );
	exit( 1 );
}
require $autoload;

// Yoast/PHPUnit-Polyfills (declared as composer dep, autoloaded above).
if ( ! class_exists( '\Yoast\PHPUnitPolyfills\Autoload' ) ) {
	fwrite( STDERR, "yoast/phpunit-polyfills missing — run `composer install`.\n" );
	exit( 1 );
}

$pa_wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $pa_wp_phpunit_dir || ! is_dir( $pa_wp_phpunit_dir ) ) {
	$pa_wp_phpunit_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

$pa_load_wp = ( defined( 'POWER_AGREEMENT_LOAD_WP' ) && POWER_AGREEMENT_LOAD_WP )
	|| getenv( 'POWER_AGREEMENT_LOAD_WP' ) === '1';

if ( $pa_load_wp && file_exists( $pa_wp_phpunit_dir . '/includes/functions.php' ) ) {
	require_once $pa_wp_phpunit_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			// WooCommerce is mounted by wp-env; load it before our plugin.
			$wc = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			if ( file_exists( $wc ) ) {
				require $wc;
			}
			require dirname( __DIR__ ) . '/power-agreement.php';
		}
	);

	// After WP is bootstrapped but before any test runs, ensure WC tables exist.
	tests_add_filter(
		'setup_theme',
		static function (): void {
			if ( ! class_exists( 'WC_Install' ) ) {
				return;
			}
			\WC_Install::install();
			if ( method_exists( 'WC_Install', 'create_tables' ) ) {
				\WC_Install::create_tables();
			}

			// Optionally toggle HPOS based on env variable so the same suite can
			// run twice (HPOS on / off) without code duplication.
			$hpos_mode = getenv( 'POWER_AGREEMENT_HPOS' );
			if ( 'on' === $hpos_mode || '1' === $hpos_mode ) {
				update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
				update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
				if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer' ) ) {
					update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
				}
			} elseif ( 'off' === $hpos_mode || '0' === $hpos_mode ) {
				update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
				update_option( 'woocommerce_feature_custom_order_tables_enabled', 'no' );
			}
		}
	);

	require $pa_wp_phpunit_dir . '/includes/bootstrap.php';
}
