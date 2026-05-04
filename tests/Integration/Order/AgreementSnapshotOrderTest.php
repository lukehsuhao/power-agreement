<?php
/**
 * Integration tests for AgreementSnapshot reading/writing through WC_Order.
 *
 * Runs the same suite under HPOS-on and HPOS-off configurations.
 *
 * @package PowerAgreement\Tests\Integration\Order
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Integration\Order;

use PowerAgreement\Order\AgreementSnapshot;
use WC_Order;
use WP_UnitTestCase;

final class AgreementSnapshotOrderTest extends WP_UnitTestCase {

	private function makeOrder(): WC_Order {
		$order = new WC_Order();
		$order->save();
		return $order;
	}

	public function test_write_to_and_read_from_round_trip(): void {
		$order = $this->makeOrder();

		$snap = new AgreementSnapshot(
			'<p>Service Agreement v1</p>',
			hash( 'sha256', '<p>Service Agreement v1</p>' ),
			'2026-05-04T12:34:56+00:00',
			'192.0.2.10'
		);
		$snap->writeTo( $order );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );

		$read = AgreementSnapshot::readFrom( $reloaded );
		self::assertNotNull( $read );
		self::assertSame( $snap->html, $read->html );
		self::assertSame( $snap->hash, $read->hash );
		self::assertSame( $snap->agreedAt, $read->agreedAt );
		self::assertSame( $snap->ip, $read->ip );
	}

	public function test_read_from_returns_null_when_no_meta(): void {
		$order  = $this->makeOrder();
		$result = AgreementSnapshot::readFrom( $order );
		self::assertNull( $result );
	}

	public function test_read_from_returns_null_when_only_partial_meta(): void {
		$order = $this->makeOrder();
		$order->update_meta_data( '_power_agreement_html', '<p>partial</p>' );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		$result = AgreementSnapshot::readFrom( $reloaded );
		self::assertNull( $result );
	}

	public function test_meta_keys_match_design_specification(): void {
		$order = $this->makeOrder();
		( new AgreementSnapshot(
			'<p>x</p>',
			'h',
			'2026-05-04T00:00:00+00:00',
			'10.0.0.1'
		) )->writeTo( $order );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );

		// Underscore-prefixed protected meta keys per design §3.2.
		self::assertSame( '<p>x</p>', $reloaded->get_meta( '_power_agreement_html' ) );
		self::assertSame( 'h', $reloaded->get_meta( '_power_agreement_hash' ) );
		self::assertSame( '2026-05-04T00:00:00+00:00', $reloaded->get_meta( '_power_agreement_agreed_at' ) );
		self::assertSame( '10.0.0.1', $reloaded->get_meta( '_power_agreement_ip' ) );
	}
}
