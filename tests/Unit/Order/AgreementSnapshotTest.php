<?php
/**
 * Unit tests for the AgreementSnapshot value object.
 *
 * Pure-PHP, runs without WordPress.
 *
 * @package PowerAgreement\Tests\Unit\Order
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use PowerAgreement\Order\AgreementSnapshot;

final class AgreementSnapshotTest extends TestCase {

	public function test_capture_now_computes_sha256_of_html(): void {
		$html = '<p>Some agreement</p>';
		$snap = AgreementSnapshot::captureNow( $html, '203.0.113.45' );

		self::assertSame( $html, $snap->html );
		self::assertSame( hash( 'sha256', $html ), $snap->hash );
		self::assertSame( '203.0.113.45', $snap->ip );
	}

	public function test_capture_now_uses_iso_8601_utc_timestamp(): void {
		$snap = AgreementSnapshot::captureNow( '<p>x</p>', '127.0.0.1' );

		// Format should match: 2026-01-01T12:34:56+00:00 (gmdate('c')).
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
			$snap->agreedAt
		);
	}

	public function test_constructor_stores_provided_values_unchanged(): void {
		$snap = new AgreementSnapshot(
			'<p>raw</p>',
			'abc123',
			'2026-05-04T12:00:00+00:00',
			'10.0.0.1'
		);

		self::assertSame( '<p>raw</p>', $snap->html );
		self::assertSame( 'abc123', $snap->hash );
		self::assertSame( '2026-05-04T12:00:00+00:00', $snap->agreedAt );
		self::assertSame( '10.0.0.1', $snap->ip );
	}

	public function test_two_captures_of_same_html_have_same_hash(): void {
		$a = AgreementSnapshot::captureNow( '<p>identical</p>', '1.1.1.1' );
		$b = AgreementSnapshot::captureNow( '<p>identical</p>', '2.2.2.2' );

		self::assertSame( $a->hash, $b->hash );
	}
}
