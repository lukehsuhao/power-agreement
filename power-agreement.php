<?php
/**
 * Plugin Name: Power Agreement
 * Plugin URI: https://github.com/zenbuapps/power-agreement
 * Description: Adds a configurable agreement consent block to WooCommerce checkout (Classic + Block) and stores a per-order snapshot for legal evidence.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.3
 * Author: Zenbu
 * Author URI: https://zenbu.tw
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: power-agreement
 * Domain Path: /languages
 *
 * @package PowerAgreement
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( defined( 'POWER_AGREEMENT_FILE' ) ) {
	return;
}
define( 'POWER_AGREEMENT_FILE', __FILE__ );
define( 'POWER_AGREEMENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'POWER_AGREEMENT_URL', plugin_dir_url( __FILE__ ) );

// --- Composer autoload ----------------------------------------------------

$power_agreement_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $power_agreement_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>'
			. esc_html__(
				'Power Agreement: Composer dependencies are missing. Run "composer install" inside the plugin directory.',
				'power-agreement'
			)
			. '</p></div>';
		}
	);
	return;
}
require $power_agreement_autoload;

// --- Environment / version gating -----------------------------------------

add_action(
	'plugins_loaded',
	static function (): void {
		global $wp_version;

		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : null;

		$check = \PowerAgreement\Compat\Requirements::check(
			array(
				'php' => array(
					'actual'   => PHP_VERSION,
					'required' => '8.1',
				),
				'wp'  => array(
					'actual'   => $wp_version,
					'required' => '6.5',
				),
				'wc'  => array(
					'actual'   => $wc_version,
					'required' => '8.3',
				),
			)
		);

		if ( $check === true ) {
			( new \PowerAgreement\Plugin( POWER_AGREEMENT_FILE ) )->run();
			return;
		}

		add_action(
			'admin_notices',
			static function () use ( $check ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				$messages = is_array( $check ) ? array_values( $check ) : array();
				echo '<div class="notice notice-error"><p><strong>'
				. esc_html__( 'Power Agreement is not active:', 'power-agreement' )
				. '</strong></p><ul style="list-style:disc;margin-left:1.5em">';
				foreach ( $messages as $msg ) {
					echo '<li>' . esc_html( (string) $msg ) . '</li>';
				}
				echo '</ul></div>';
			}
		);
	},
	20
);

// --- HPOS compatibility declaration --------------------------------------

add_action(
	'before_woocommerce_init',
	static function (): void {
		\PowerAgreement\Compat\HposCompat::declareCompatibility( POWER_AGREEMENT_FILE );
	}
);
