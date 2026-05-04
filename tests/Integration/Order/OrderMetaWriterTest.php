<?php
/**
 * Integration tests for OrderMetaWriter.
 *
 * @package PowerAgreement\Tests\Integration\Order
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Integration\Order;

use PowerAgreement\Order\AgreementSnapshot;
use PowerAgreement\Order\OrderMetaWriter;
use PowerAgreement\Settings\SettingsRepository;
use WC_Order;
use WP_UnitTestCase;

final class OrderMetaWriterTest extends WP_UnitTestCase {

	private SettingsRepository $repo;

	public function set_up(): void {
		parent::set_up();
		delete_option( SettingsRepository::OPTION );
		$this->repo = new SettingsRepository();
	}

	private function enableAgreement( string $body ): void {
		$this->repo->save(
			array(
				'enabled' => true,
				'content' => $body,
			)
		);
	}

	public function test_write_from_request_persists_all_four_meta_entries(): void {
		$this->enableAgreement( '<p>Hello world.</p>' );

		$order = new WC_Order();
		$order->save();

		$writer = new OrderMetaWriter( $this->repo );
		$writer->writeFromRequest( $order, '203.0.113.7' );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );

		$snap = AgreementSnapshot::readFrom( $reloaded );
		self::assertNotNull( $snap );
		self::assertStringContainsString( 'Hello world.', $snap->html );
		self::assertSame( hash( 'sha256', $snap->html ), $snap->hash );
		self::assertSame( '203.0.113.7', $snap->ip );
	}

	public function test_write_from_request_falls_back_to_unknown_when_ip_missing(): void {
		$this->enableAgreement( '<p>Body</p>' );

		$order = new WC_Order();
		$order->save();

		$writer = new OrderMetaWriter( $this->repo );
		$writer->writeFromRequest( $order, '' );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		self::assertSame( 'unknown', $reloaded->get_meta( '_power_agreement_ip' ) );
	}

	public function test_write_from_request_is_idempotent_on_same_order(): void {
		$this->enableAgreement( '<p>Body</p>' );

		$order = new WC_Order();
		$order->save();

		$writer = new OrderMetaWriter( $this->repo );
		$writer->writeFromRequest( $order, '10.0.0.1' );
		$writer->writeFromRequest( $order, '10.0.0.2' ); // second call overwrites — last write wins.
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		self::assertSame( '10.0.0.2', $reloaded->get_meta( '_power_agreement_ip' ) );
	}

	public function test_write_from_request_passes_through_kses(): void {
		// Even if a malicious admin saved <script>, the stored snapshot must reflect
		// what the customer actually saw — already-sanitised content.
		$this->enableAgreement( '<p>Safe<script>nope</script></p>' );

		$order = new WC_Order();
		$order->save();

		( new OrderMetaWriter( $this->repo ) )->writeFromRequest( $order, '127.0.0.1' );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		self::assertStringNotContainsString( '<script', $reloaded->get_meta( '_power_agreement_html' ) );
	}
}
