<?php
/**
 * Immutable agreement snapshot value object.
 *
 * @package PowerAgreement\Order
 */

declare(strict_types=1);

namespace PowerAgreement\Order;

use WC_Order;

/**
 * Captures the customer's consent at the moment an order is placed.
 *
 * Four scalar fields are stored together so the bound between "what was
 * shown" and "who agreed when from where" is preserved and can be
 * roundtripped through HPOS-safe order meta.
 *
 * Marked readonly so callers cannot accidentally mutate evidence after
 * capture.
 */
final class AgreementSnapshot {

	public const META_HTML      = '_power_agreement_html';
	public const META_HASH      = '_power_agreement_hash';
	public const META_AGREED_AT = '_power_agreement_agreed_at';
	public const META_IP        = '_power_agreement_ip';

	public readonly string $html;
	public readonly string $hash;
	public readonly string $agreedAt;
	public readonly string $ip;

	public function __construct( string $html, string $hash, string $agreedAt, string $ip ) {
		$this->html     = $html;
		$this->hash     = $hash;
		$this->agreedAt = $agreedAt;
		$this->ip       = $ip;
	}

	/**
	 * Build a snapshot for "right now".
	 */
	public static function captureNow( string $html, string $ip ): self {
		return new self(
			$html,
			hash( 'sha256', $html ),
			gmdate( 'c' ),
			$ip === '' ? 'unknown' : $ip
		);
	}

	/**
	 * Persist the four scalar fields as protected order meta.
	 *
	 * The caller is responsible for calling $order->save() afterwards
	 * so all writes are flushed in a single transaction.
	 */
	public function writeTo( WC_Order $order ): void {
		$order->update_meta_data( self::META_HTML, $this->html );
		$order->update_meta_data( self::META_HASH, $this->hash );
		$order->update_meta_data( self::META_AGREED_AT, $this->agreedAt );
		$order->update_meta_data( self::META_IP, $this->ip );
	}

	/**
	 * Read a snapshot back from an order.
	 *
	 * Returns null if any of the four fields are missing — partial data
	 * means the order pre-dates this plugin (or a write failed mid-way)
	 * and should never be presented as a complete consent record.
	 */
	public static function readFrom( WC_Order $order ): ?self {
		$html      = (string) $order->get_meta( self::META_HTML );
		$hash      = (string) $order->get_meta( self::META_HASH );
		$agreed_at = (string) $order->get_meta( self::META_AGREED_AT );
		$ip        = (string) $order->get_meta( self::META_IP );

		if ( '' === $html || '' === $hash || '' === $agreed_at || '' === $ip ) {
			return null;
		}

		return new self( $html, $hash, $agreed_at, $ip );
	}
}
