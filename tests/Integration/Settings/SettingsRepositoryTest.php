<?php
/**
 * Integration tests for SettingsRepository.
 *
 * Runs inside the wp-env "tests" container against a live WordPress + WC.
 *
 * @package PowerAgreement\Tests\Integration\Settings
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Integration\Settings;

use PowerAgreement\Settings\SettingsRepository;
use WP_UnitTestCase;

final class SettingsRepositoryTest extends WP_UnitTestCase {

	private SettingsRepository $repo;

	public function set_up(): void {
		parent::set_up();
		delete_option( SettingsRepository::OPTION );
		$this->repo = new SettingsRepository();
	}

	public function test_defaults_returns_disabled_with_localised_strings(): void {
		$defaults = SettingsRepository::defaults();

		self::assertArrayHasKey( 'enabled', $defaults );
		self::assertArrayHasKey( 'title', $defaults );
		self::assertArrayHasKey( 'content', $defaults );
		self::assertArrayHasKey( 'consent_text', $defaults );

		self::assertFalse( $defaults['enabled'] );
		self::assertSame( '', $defaults['content'] );
		self::assertNotSame( '', $defaults['title'] );
		self::assertNotSame( '', $defaults['consent_text'] );
	}

	public function test_settings_falls_back_to_defaults_when_option_missing(): void {
		$settings = $this->repo->settings();

		self::assertSame( SettingsRepository::defaults()['title'], $settings['title'] );
		self::assertFalse( $settings['enabled'] );
	}

	public function test_save_persists_clean_payload_and_round_trips(): void {
		$ok = $this->repo->save(
			array(
				'enabled'      => true,
				'title'        => 'Service Agreement',
				'content'      => '<p>Please read carefully.</p>',
				'consent_text' => 'I accept the terms.',
			)
		);

		self::assertTrue( $ok );
		self::assertTrue( $this->repo->isEnabled() );
		self::assertSame( 'Service Agreement', $this->repo->title() );
		self::assertSame( 'I accept the terms.', $this->repo->consentText() );
		self::assertStringContainsString( 'Please read carefully.', $this->repo->content() );
	}

	public function test_save_strips_script_tags_via_kses_post_but_keeps_anchor(): void {
		$payload = array(
			'enabled' => true,
			'content' => '<p>Hello <a href="https://example.com">link</a><script>alert(1)</script></p>',
		);
		$this->repo->save( $payload );

		$content = $this->repo->content();
		self::assertStringContainsString( '<a href="https://example.com">link</a>', $content );
		// The <script> tag itself must be removed.
		self::assertStringNotContainsString( '<script', $content );
		self::assertStringNotContainsString( '</script>', $content );
		// (kses keeps the inner text — that's accepted behaviour for a stored agreement.)
	}

	public function test_save_truncates_overlong_title(): void {
		$too_long = str_repeat( 'A', 250 );
		$this->repo->save(
			array(
				'enabled' => true,
				'title'   => $too_long,
			)
		);

		self::assertSame( 100, mb_strlen( $this->repo->title() ) );
	}

	public function test_save_truncates_overlong_consent_text(): void {
		$too_long = str_repeat( 'B', 500 );
		$this->repo->save(
			array(
				'enabled'      => true,
				'consent_text' => $too_long,
			)
		);

		self::assertSame( 200, mb_strlen( $this->repo->consentText() ) );
	}

	public function test_save_normalises_truthy_strings_to_bool(): void {
		$this->repo->save( array( 'enabled' => '1' ) );
		self::assertTrue( $this->repo->isEnabled() );

		$this->repo->save( array( 'enabled' => 'false' ) );
		self::assertFalse( $this->repo->isEnabled() );
	}

	public function test_save_strips_html_tags_from_title(): void {
		$this->repo->save(
			array(
				'enabled' => true,
				'title'   => '<b>Bold</b> Title <script>x</script>',
			)
		);
		self::assertStringNotContainsString( '<', $this->repo->title() );
		self::assertStringContainsString( 'Bold', $this->repo->title() );
		self::assertStringContainsString( 'Title', $this->repo->title() );
	}

	public function test_settings_merges_partial_persisted_values(): void {
		update_option(
			SettingsRepository::OPTION,
			array(
				'enabled' => true,
				// Intentionally omit "title", "content", "consent_text".
			)
		);

		$settings = $this->repo->settings();
		self::assertTrue( $settings['enabled'] );
		self::assertSame( SettingsRepository::defaults()['title'], $settings['title'] );
		self::assertSame( '', $settings['content'] );
	}
}
