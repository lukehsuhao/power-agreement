<?php
/**
 * Integration tests for the Settings admin page registration.
 *
 * @package PowerAgreement\Tests\Integration\Settings
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Integration\Settings;

use PowerAgreement\Settings\SettingsPage;
use PowerAgreement\Settings\SettingsRepository;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SettingsRepository::OPTION );
	}

	public function test_register_attaches_admin_menu_and_init_hooks(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register();

		self::assertNotFalse( has_action( 'admin_menu', array( $page, 'add_menu' ) ) );
		self::assertNotFalse( has_action( 'admin_init', array( $page, 'register_settings' ) ) );
		self::assertNotFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue_editor' ) ) );
	}

	public function test_sanitize_callback_routes_through_repository(): void {
		$repo = new SettingsRepository();
		$page = new SettingsPage( $repo );

		$out = $page->sanitize(
			array(
				'enabled' => '1',
				'title'   => '<b>Hi</b>',
				'content' => '<p>Body<script>x</script></p>',
			)
		);

		self::assertTrue( $out['enabled'] );
		self::assertSame( 'Hi', $out['title'] );
		self::assertStringContainsString( '<p>', $out['content'] );
		self::assertStringNotContainsString( '<script', $out['content'] );
	}

	public function test_add_menu_registers_submenu_under_woocommerce(): void {
		global $submenu;

		// Prerequisite: simulate an admin user so add_submenu_page() actually registers.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// add_submenu_page() expects the parent slug ('woocommerce') to already
		// have at least one entry in $submenu, otherwise WP refuses to register
		// the child. In real admin requests WC's own admin_menu hook seeds it;
		// in PHPUnit (no admin context), seed it ourselves so the test is
		// deterministic across local + CI environments.
		if ( ! is_array( $submenu ) ) {
			$submenu = array();
		}
		if ( empty( $submenu['woocommerce'] ) ) {
			$submenu['woocommerce'] = array(
				array( 'WooCommerce', 'manage_woocommerce', 'woocommerce' ),
			);
		}

		$page = new SettingsPage( new SettingsRepository() );
		$page->add_menu();

		self::assertArrayHasKey( 'woocommerce', $submenu, 'WooCommerce submenu should exist' );

		$slugs = array_column( $submenu['woocommerce'], 2 );
		self::assertContains( 'power-agreement', $slugs );
	}
}
