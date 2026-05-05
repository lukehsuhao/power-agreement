<?php
/**
 * Plugin Name: Power Agreement
 * Plugin URI: https://github.com/zenbuapps/power-agreement
 * Description: Adds a configurable agreement consent block to WooCommerce checkout (Classic + Block) and stores a per-order snapshot for legal evidence.
 * Version: 0.3.2
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

// --- Self-hosted update checker (GitHub Releases) -------------------------
//
// We're not on wp.org, so WordPress's built-in update channel doesn't see us.
// `yahnis-elsts/plugin-update-checker` polls a GitHub repo's Releases feed
// and feeds matching releases into WordPress's normal "Plugins → Update"
// flow. Trigger: when a new tag like vX.Y.Z is published with a
// `power-agreement-X.Y.Z.zip` asset attached, every installed copy will
// see the update prompt within the standard WP transient TTL (12h, or
// immediately when the user visits the Plugins page after a manual
// "Check Again" click).
if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	$power_agreement_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/lukehsuhao/power-agreement/',
		POWER_AGREEMENT_FILE,
		'power-agreement'
	);
	// `buildUpdateChecker` returns a Vcs\PluginUpdateChecker for GitHub URLs,
	// which is a subclass that supports release-asset selection and branch
	// pinning. PHPStan widens the return type to a union so we narrow it
	// behind method_exists() checks (also defends against future signature
	// changes in the library).
	if ( method_exists( $power_agreement_update_checker, 'getVcsApi' ) ) {
		$power_agreement_vcs_api = $power_agreement_update_checker->getVcsApi();
		if ( is_object( $power_agreement_vcs_api ) && method_exists( $power_agreement_vcs_api, 'enableReleaseAssets' ) ) {
			$power_agreement_vcs_api->enableReleaseAssets();
		}
	}
	if ( method_exists( $power_agreement_update_checker, 'setBranch' ) ) {
		$power_agreement_update_checker->setBranch( 'main' );
	}
}

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
