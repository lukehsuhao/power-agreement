<?php
/**
 * Integration tests for ClassicCheckout.
 *
 * Validates:
 * - the accordion HTML is injected on woocommerce_review_order_before_submit
 * - missing consent triggers wc_add_notice('error', ...)
 * - successful consent persists the four meta keys via woocommerce_checkout_create_order
 * - disabling the agreement is a transparent no-op everywhere
 *
 * @package PowerAgreement\Tests\Integration\Checkout
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Integration\Checkout;

use PowerAgreement\Checkout\ClassicCheckout;
use PowerAgreement\Checkout\ConsentValidator;
use PowerAgreement\Order\AgreementSnapshot;
use PowerAgreement\Order\OrderMetaWriter;
use PowerAgreement\Settings\SettingsRepository;
use WC_Order;
use WP_UnitTestCase;

final class ClassicCheckoutTest extends WP_UnitTestCase {

	private SettingsRepository $repo;

	public function set_up(): void {
		parent::set_up();
		delete_option( SettingsRepository::OPTION );
		WC()->session = WC()->session ?? new \WC_Session_Handler();
		WC()->session->init();
		wc_clear_notices();
		$this->repo = new SettingsRepository();
		$_POST      = array();
	}

	public function tear_down(): void {
		$_POST = array();
		wc_clear_notices();
		parent::tear_down();
	}

	private function makeIntegration(): ClassicCheckout {
		return new ClassicCheckout(
			$this->repo,
			new ConsentValidator( $this->repo ),
			new OrderMetaWriter( $this->repo )
		);
	}

	private function enableAgreement( string $body = '<p>Body</p>' ): void {
		$this->repo->save(
			array(
				'enabled'      => true,
				'title'        => 'Service Agreement',
				'content'      => $body,
				'consent_text' => 'I agree.',
			)
		);
	}

	public function test_register_hooks_into_classic_checkout_lifecycle(): void {
		$integration = $this->makeIntegration();
		$integration->register();

		self::assertNotFalse(
			has_action( 'woocommerce_review_order_before_submit', array( $integration, 'render' ) )
		);
		self::assertNotFalse(
			has_action( 'woocommerce_checkout_process', array( $integration, 'validate' ) )
		);
		self::assertNotFalse(
			has_action( 'woocommerce_checkout_create_order', array( $integration, 'persist' ) )
		);
	}

	public function test_render_outputs_inline_scroll_by_default(): void {
		$this->enableAgreement( '<p>Read me carefully.</p>' );

		ob_start();
		$this->makeIntegration()->render();
		$html = (string) ob_get_clean();

		// Common contract elements (both modes)
		self::assertStringContainsString( 'data-power-agreement', $html );
		self::assertStringContainsString( 'Service Agreement', $html );
		self::assertStringContainsString( 'Read me carefully.', $html );
		self::assertStringContainsString( 'name="power_agreement_consent"', $html );
		self::assertStringContainsString( 'I agree.', $html );

		// Inline-scroll specific markers (default mode for new installs):
		// compact one-line card with an explicit "Expand to view" button
		// that opens a <dialog> modal containing the full content.
		self::assertStringContainsString( 'power-agreement--inline-scroll', $html );
		self::assertStringContainsString( 'power-agreement__compact-card', $html );
		self::assertStringContainsString( 'power-agreement__expand-btn', $html );
		self::assertStringContainsString( 'data-power-agreement-open', $html );
		self::assertStringContainsString( '<dialog', $html );
		self::assertStringContainsString( 'data-power-agreement-modal', $html );

		// The 240px scrollable preview is gone — assert we don't render it.
		self::assertStringNotContainsString( 'power-agreement__preview-body', $html );
		self::assertStringNotContainsString( 'role="button"', $html );
	}

	public function test_render_outputs_accordion_when_mode_is_accordion(): void {
		$this->repo->save(
			array(
				'enabled'      => true,
				'title'        => 'Service Agreement',
				'content'      => '<p>Read me carefully.</p>',
				'consent_text' => 'I agree.',
				'display_mode' => SettingsRepository::DISPLAY_MODE_ACCORDION,
			)
		);

		ob_start();
		$this->makeIntegration()->render();
		$html = (string) ob_get_clean();

		// Common contract elements
		self::assertStringContainsString( 'data-power-agreement', $html );
		self::assertStringContainsString( 'Service Agreement', $html );
		self::assertStringContainsString( 'Read me carefully.', $html );
		self::assertStringContainsString( 'name="power_agreement_consent"', $html );
		self::assertStringContainsString( 'I agree.', $html );

		// Accordion-specific markers
		self::assertStringContainsString( 'power-agreement--accordion', $html );
		self::assertStringContainsString( 'data-power-agreement-toggle', $html );
		self::assertStringContainsString( 'aria-expanded="false"', $html );

		// Must NOT have inline-scroll / dialog markers
		self::assertStringNotContainsString( 'power-agreement--inline-scroll', $html );
		self::assertStringNotContainsString( '<dialog', $html );
	}

	public function test_render_outputs_nothing_when_disabled(): void {
		$this->repo->save(
			array(
				'enabled' => false,
				'content' => '<p>Body</p>',
			)
		);

		ob_start();
		$this->makeIntegration()->render();
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	public function test_render_outputs_nothing_when_content_empty(): void {
		$this->repo->save(
			array(
				'enabled' => true,
				'content' => '',
			)
		);

		ob_start();
		$this->makeIntegration()->render();
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	public function test_validate_adds_error_notice_when_consent_missing(): void {
		$this->enableAgreement();
		$_POST = array(); // no power_agreement_consent

		$this->makeIntegration()->validate();

		$notices = wc_get_notices( 'error' );
		self::assertNotEmpty( $notices, 'Expected at least one error notice when consent is missing.' );

		$messages = array_column( $notices, 'notice' );
		$joined   = implode( ' ', $messages );
		self::assertStringContainsString( 'I agree.', $joined );
	}

	public function test_validate_adds_no_notice_when_consent_present(): void {
		$this->enableAgreement();
		$_POST = array( 'power_agreement_consent' => '1' );

		$this->makeIntegration()->validate();

		self::assertEmpty( wc_get_notices( 'error' ) );
	}

	public function test_validate_no_op_when_disabled(): void {
		$this->repo->save(
			array(
				'enabled' => false,
				'content' => '<p>Body</p>',
			)
		);
		$_POST = array(); // would normally fail

		$this->makeIntegration()->validate();

		self::assertEmpty( wc_get_notices( 'error' ) );
	}

	public function test_persist_writes_full_snapshot_when_enforced(): void {
		$this->enableAgreement( '<p>Persist me.</p>' );

		$order = new WC_Order();
		$order->save();

		$this->makeIntegration()->persist( $order, array() );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		$snap = AgreementSnapshot::readFrom( $reloaded );
		self::assertNotNull( $snap );
		self::assertStringContainsString( 'Persist me.', $snap->html );
		self::assertNotSame( '', $snap->ip );
	}

	public function test_persist_skips_meta_when_disabled(): void {
		$this->repo->save(
			array(
				'enabled' => false,
				'content' => '<p>Body</p>',
			)
		);

		$order = new WC_Order();
		$order->save();

		$this->makeIntegration()->persist( $order, array() );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		self::assertSame( '', (string) $reloaded->get_meta( '_power_agreement_html' ) );
	}
}
