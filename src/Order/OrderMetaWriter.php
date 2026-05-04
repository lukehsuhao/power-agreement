<?php
/**
 * Writes per-order agreement metadata in an HPOS-safe way.
 *
 * @package PowerAgreement\Order
 */

declare(strict_types=1);

namespace PowerAgreement\Order;

use PowerAgreement\Settings\SettingsRepository;
use WC_Order;

/**
 * Bridges the live agreement settings with the order being created.
 *
 * Reads the *current* agreement HTML from {@see SettingsRepository},
 * captures a fresh snapshot, and writes it onto the order. The body is
 * passed through wp_kses_post() one more time at write-time as a defence
 * against future KSES rule changes that might tighten what is allowed.
 */
final class OrderMetaWriter {

	private SettingsRepository $repo;

	public function __construct( SettingsRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Capture the current agreement and stamp it onto the given order.
	 *
	 * Caller is responsible for $order->save() to flush the meta writes
	 * (this is the contract WooCommerce expects for create_order hooks).
	 *
	 * @param WC_Order $order The order being created.
	 * @param string   $ip    Customer IP, e.g. WC_Geolocation::get_ip_address().
	 *                        Empty string is treated as "unknown".
	 */
	public function writeFromRequest( WC_Order $order, string $ip ): void {
		$html_to_store = wp_kses_post( $this->repo->content() );
		$snap          = AgreementSnapshot::captureNow( $html_to_store, $ip );
		$snap->writeTo( $order );
	}
}
