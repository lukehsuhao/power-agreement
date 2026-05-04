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

$wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: ( dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit' );

if ( file_exists( $wp_phpunit_dir . '/includes/functions.php' )
	&& getenv( 'POWER_AGREEMENT_SKIP_WP_BOOTSTRAP' ) !== '1'
	&& defined( 'POWER_AGREEMENT_LOAD_WP' ) && POWER_AGREEMENT_LOAD_WP ) {
	require_once $wp_phpunit_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			require dirname( __DIR__ ) . '/power-agreement.php';
		}
	);

	require $wp_phpunit_dir . '/includes/bootstrap.php';
}
