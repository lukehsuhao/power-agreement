<?php
/**
 * Integration tests for BlockCheckout.
 *
 * Validates the server-side Store API extension behaviour:
 *   - schema is registered under the "power-agreement" namespace
 *   - a request without consent throws RouteException(400)
 *   - a request with consent passes through and writes meta
 *
 * The React frontend is exercised by E2E (Playwright); here we focus
 * on the contract that protects against client-side bypass.
 *
 * @package PowerAgreement\Tests\Integration\Checkout
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Integration\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use PowerAgreement\Checkout\BlockCheckout;
use PowerAgreement\Checkout\ConsentValidator;
use PowerAgreement\Order\AgreementSnapshot;
use PowerAgreement\Order\OrderMetaWriter;
use PowerAgreement\Settings\SettingsRepository;
use WC_Order;
use WP_REST_Request;
use WP_UnitTestCase;

final class BlockCheckoutTest extends WP_UnitTestCase {

	private SettingsRepository $repo;

	public function set_up(): void {
		parent::set_up();
		delete_option( SettingsRepository::OPTION );
		$this->repo = new SettingsRepository();
	}

	private function makeIntegration(): BlockCheckout {
		return new BlockCheckout(
			$this->repo,
			new ConsentValidator( $this->repo ),
			new OrderMetaWriter( $this->repo ),
			__FILE__
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

	public function test_get_name_returns_unique_namespace(): void {
		$integration = $this->makeIntegration();
		self::assertSame( 'power-agreement', $integration->get_name() );
	}

	public function test_get_script_data_exposes_settings_to_frontend(): void {
		$this->enableAgreement( '<p>Hello.</p>' );
		$data = $this->makeIntegration()->get_script_data();

		self::assertArrayHasKey( 'enabled', $data );
		self::assertArrayHasKey( 'title', $data );
		self::assertArrayHasKey( 'content', $data );
		self::assertArrayHasKey( 'consent_text', $data );
		self::assertTrue( $data['enabled'] );
		self::assertSame( 'Service Agreement', $data['title'] );
		self::assertStringContainsString( 'Hello.', $data['content'] );
	}

	public function test_validate_consent_throws_when_missing(): void {
		$this->enableAgreement();
		$integration = $this->makeIntegration();

		$order   = new WC_Order();
		$order->save();
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array() ); // no power-agreement key

		$caught = null;
		try {
			$integration->validateConsent( $order, $request );
		} catch ( RouteException $e ) {
			$caught = $e;
		}

		self::assertInstanceOf( RouteException::class, $caught );
		self::assertSame( ConsentValidator::ERROR_CODE, $caught->getErrorCode() );
		self::assertSame( 400, $caught->getCode() );
	}

	public function test_validate_consent_throws_when_consent_false(): void {
		$this->enableAgreement();
		$integration = $this->makeIntegration();

		$order   = new WC_Order();
		$order->save();
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param(
			'extensions',
			array(
				'power-agreement' => array( 'consent' => false ),
			)
		);

		$this->expectException( RouteException::class );
		$integration->validateConsent( $order, $request );
	}

	public function test_validate_consent_passes_when_consent_true(): void {
		$this->enableAgreement();
		$integration = $this->makeIntegration();

		$order   = new WC_Order();
		$order->save();
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param(
			'extensions',
			array(
				'power-agreement' => array( 'consent' => true ),
			)
		);

		// Must not throw.
		$integration->validateConsent( $order, $request );
		$this->expectNotToPerformAssertions();
	}

	public function test_validate_consent_no_op_when_disabled(): void {
		$this->repo->save( array( 'enabled' => false, 'content' => '<p>Body</p>' ) );
		$integration = $this->makeIntegration();

		$order   = new WC_Order();
		$order->save();
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );

		// Must not throw even with no consent param.
		$integration->validateConsent( $order, $request );
		$this->expectNotToPerformAssertions();
	}

	public function test_persist_meta_writes_snapshot_when_enforced(): void {
		$this->enableAgreement( '<p>Block body.</p>' );
		$integration = $this->makeIntegration();

		$order = new WC_Order();
		$order->save();
		$integration->persistMeta( $order );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		$snap = AgreementSnapshot::readFrom( $reloaded );
		self::assertNotNull( $snap );
		self::assertStringContainsString( 'Block body.', $snap->html );
	}

	public function test_persist_meta_skips_when_disabled(): void {
		$this->repo->save( array( 'enabled' => false, 'content' => '<p>Body</p>' ) );
		$integration = $this->makeIntegration();

		$order = new WC_Order();
		$order->save();
		$integration->persistMeta( $order );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $reloaded );
		self::assertSame( '', (string) $reloaded->get_meta( '_power_agreement_html' ) );
	}

	public function test_extend_schema_registers_endpoint_data(): void {
		$this->enableAgreement();
		$integration = $this->makeIntegration();

		// Calling extendStoreApi() should be idempotent and side-effect free
		// in the sense that it does not throw.
		$integration->extendStoreApi();
		$this->expectNotToPerformAssertions();
	}
}
