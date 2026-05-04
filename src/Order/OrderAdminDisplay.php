<?php
/**
 * Displays the agreement consent record on the order admin page.
 *
 * @package PowerAgreement\Order
 */

declare(strict_types=1);

namespace PowerAgreement\Order;

use WC_Order;

/**
 * Renders a small read-only block on the order edit screen showing
 * the four consent fields (timestamp, IP, hash preview, full HTML
 * snapshot) for both HPOS and legacy storage.
 */
final class OrderAdminDisplay {

	public function register(): void {
		add_action(
			'woocommerce_admin_order_data_after_order_details',
			array( $this, 'render' )
		);
	}

	public function render( WC_Order $order ): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$snap = AgreementSnapshot::readFrom( $order );
		if ( null === $snap ) {
			?>
			<div class="order_data_column power-agreement-record">
				<h4><?php esc_html_e( 'Agreement Consent', 'power-agreement' ); ?></h4>
				<p>
					<em><?php esc_html_e( 'No agreement consent record on this order.', 'power-agreement' ); ?></em>
				</p>
			</div>
			<?php
			return;
		}

		$short_hash = substr( $snap->hash, 0, 16 );
		?>
		<div class="order_data_column power-agreement-record">
			<h4><?php esc_html_e( 'Agreement Consent', 'power-agreement' ); ?></h4>
			<ul style="list-style: disc; margin-left: 1.25em;">
				<li>
					<strong><?php esc_html_e( 'Agreed at:', 'power-agreement' ); ?></strong>
					<?php echo esc_html( $snap->agreedAt ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Customer IP:', 'power-agreement' ); ?></strong>
					<?php echo esc_html( $snap->ip ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'SHA-256:', 'power-agreement' ); ?></strong>
					<code><?php echo esc_html( $short_hash ); ?>…</code>
				</li>
			</ul>
			<details>
				<summary><?php esc_html_e( 'View captured agreement HTML', 'power-agreement' ); ?></summary>
				<div class="power-agreement-snapshot" style="border:1px solid #ddd;padding:8px;margin-top:8px;background:#fafafa;max-height:320px;overflow:auto;">
					<?php echo wp_kses_post( $snap->html ); ?>
				</div>
			</details>
		</div>
		<?php
	}
}
