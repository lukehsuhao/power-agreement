<?php
/**
 * Integration tests for ConsentValidator.
 *
 * @package PowerAgreement\Tests\Integration\Checkout
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Integration\Checkout;

use PowerAgreement\Checkout\ConsentValidator;
use PowerAgreement\Settings\SettingsRepository;
use WP_UnitTestCase;

final class ConsentValidatorTest extends WP_UnitTestCase {

	private SettingsRepository $repo;

	public function set_up(): void {
		parent::set_up();
		delete_option( SettingsRepository::OPTION );
		$this->repo = new SettingsRepository();
	}

	public function test_should_not_enforce_when_disabled(): void {
		$this->repo->save(
			array(
				'enabled' => false,
				'content' => '<p>Body</p>',
			)
		);
		$validator = new ConsentValidator( $this->repo );
		self::assertFalse( $validator->shouldEnforce() );
	}

	public function test_should_not_enforce_when_content_empty(): void {
		$this->repo->save(
			array(
				'enabled' => true,
				'content' => '',
			)
		);
		$validator = new ConsentValidator( $this->repo );
		self::assertFalse( $validator->shouldEnforce() );
	}

	public function test_should_enforce_when_enabled_and_content_present(): void {
		$this->repo->save(
			array(
				'enabled' => true,
				'content' => '<p>Body</p>',
			)
		);
		$validator = new ConsentValidator( $this->repo );
		self::assertTrue( $validator->shouldEnforce() );
	}

	public function test_error_message_includes_consent_text(): void {
		$this->repo->save(
			array(
				'enabled'      => true,
				'content'      => '<p>Body</p>',
				'consent_text' => 'I agree to the rules.',
			)
		);

		$validator = new ConsentValidator( $this->repo );
		$msg       = $validator->errorMessage();

		self::assertStringContainsString( 'I agree to the rules.', $msg );
	}
}
