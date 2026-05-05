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

		// add_submenu_page() refuses to attach if (1) the current user lacks
		// the capability or (2) the parent slug isn't already in $submenu.
		// In a fresh PHPUnit run neither precondition is automatic, so we
		// arrange both explicitly. Grant manage_woocommerce directly rather
		// than relying on the role-cap mapping (WC populates that during its
		// install on first admin request, not during PHPUnit bootstrap).
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = get_user_by( 'id', $admin );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $admin );

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
