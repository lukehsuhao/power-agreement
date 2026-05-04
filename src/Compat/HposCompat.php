<?php
/**
 * High-Performance Order Storage compatibility helpers.
 *
 * @package PowerAgreement\Compat
 */

declare(strict_types=1);

namespace PowerAgreement\Compat;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Thin wrapper around WooCommerce's HPOS feature utilities.
 *
 * Centralises the `class_exists` defensive checks so the rest of the plugin
 * does not have to repeat them.
 */
final class HposCompat {

	/**
	 * Declare this plugin as HPOS-compatible.
	 *
	 * Must be called from inside a `before_woocommerce_init` callback.
	 *
	 * @param string $plugin_file Absolute path to the plugin's main file.
	 */
	public static function declareCompatibility( string $plugin_file ): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}
		FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true );
	}

	/**
	 * Whether HPOS is currently active for this site.
	 */
	public static function isHposEnabled(): bool {
		if ( ! class_exists( OrderUtil::class ) ) {
			return false;
		}
		if ( ! method_exists( OrderUtil::class, 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}
		return (bool) OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
