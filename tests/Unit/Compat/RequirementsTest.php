<?php
/**
 * Unit tests for Requirements helper.
 *
 * @package PowerAgreement\Tests\Unit\Compat
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;
use PowerAgreement\Compat\Requirements;

final class RequirementsTest extends TestCase {

	public function testReturnsTrueWhenAllVersionsSatisfied(): void {
		$result = Requirements::check(
			array(
				'php' => array(
					'actual'   => '8.1.0',
					'required' => '8.1',
				),
				'wp'  => array(
					'actual'   => '6.5.0',
					'required' => '6.5',
				),
				'wc'  => array(
					'actual'   => '8.3.0',
					'required' => '8.3',
				),
			)
		);

		self::assertTrue( $result );
	}

	public function testReturnsListOfFailuresWhenPhpTooOld(): void {
		$result = Requirements::check(
			array(
				'php' => array(
					'actual'   => '8.0.30',
					'required' => '8.1',
				),
				'wp'  => array(
					'actual'   => '6.5.0',
					'required' => '6.5',
				),
			)
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'php', $result );
		self::assertStringContainsString( '8.1', $result['php'] );
	}

	public function testReturnsFailureForMissingActual(): void {
		// Simulates "WooCommerce not installed at all".
		$result = Requirements::check(
			array(
				'wc' => array(
					'actual'   => null,
					'required' => '8.3',
				),
			)
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'wc', $result );
	}

	public function testReturnsAllFailuresAtOnce(): void {
		$result = Requirements::check(
			array(
				'php' => array(
					'actual'   => '7.4.0',
					'required' => '8.1',
				),
				'wp'  => array(
					'actual'   => '6.4.0',
					'required' => '6.5',
				),
				'wc'  => array(
					'actual'   => '8.0.0',
					'required' => '8.3',
				),
			)
		);

		self::assertIsArray( $result );
		self::assertCount( 3, $result );
	}

	public function testHandlesEmptyInputAsTrue(): void {
		self::assertTrue( Requirements::check( array() ) );
	}
}
