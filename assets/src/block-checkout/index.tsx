/**
 * Power Agreement — Block Checkout integration entry point.
 *
 * Registers a checkout block whose React component renders the same
 * accordion UI as the Classic checkout and pushes consent state into
 * the cart via `extensionCartUpdate()` so it round-trips through the
 * Store API contract registered server-side by BlockCheckout.php.
 */
// @ts-ignore — provided by WooCommerce Blocks at runtime.
import { registerCheckoutBlock } from '@woocommerce/blocks-checkout';
// @ts-ignore — provided by WooCommerce Blocks at runtime.
import { extensionCartUpdate } from '@woocommerce/blocks-checkout-utils';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';

import metadata from './block.json';

const NAMESPACE = 'power-agreement';

type Settings = {
	enabled: boolean;
	title: string;
	content: string;
	consent_text: string;
};

const PowerAgreementBlock = () => {
	const settings = (getSetting(`${NAMESPACE}_data`, {
		enabled: false,
		title: '',
		content: '',
		consent_text: '',
	}) as unknown) as Settings;

	const [open, setOpen] = useState(false);
	const [consent, setConsent] = useState(false);
	const lastSent = useRef<boolean | null>(null);

	useEffect(() => {
		if (!settings.enabled || !settings.content) {
			return;
		}
		// Avoid bouncy updates when user toggles rapidly.
		if (lastSent.current === consent) {
			return;
		}
		lastSent.current = consent;
		extensionCartUpdate({
			namespace: NAMESPACE,
			data: { consent },
		});
	}, [consent, settings.enabled, settings.content]);

	if (!settings.enabled || !settings.content) {
		return null;
	}

	return (
		<div className="power-agreement wp-block-power-agreement" data-power-agreement>
			<button
				type="button"
				className="power-agreement__toggle"
				aria-expanded={open}
				aria-controls="power-agreement-content"
				onClick={() => setOpen((value) => !value)}
				data-power-agreement-toggle
			>
				<span className="power-agreement__title">{settings.title}</span>
				<span className="power-agreement__chevron" aria-hidden="true">
					{'\u25BE'}
				</span>
			</button>
			<div
				id="power-agreement-content"
				className={`power-agreement__content${open ? ' is-open' : ''}`}
				role="region"
				hidden={!open}
				dangerouslySetInnerHTML={{ __html: settings.content }}
			/>
			<label className="power-agreement__consent">
				<input
					type="checkbox"
					name="power_agreement_consent"
					checked={consent}
					onChange={(event) => setConsent(event.target.checked)}
				/>
				<span>{settings.consent_text}</span>
			</label>
		</div>
	);
};

registerCheckoutBlock({
	metadata,
	component: PowerAgreementBlock,
} as never);

export default PowerAgreementBlock;
// Mark as having translations consumer (no-op for build).
__('Power Agreement', 'power-agreement');
