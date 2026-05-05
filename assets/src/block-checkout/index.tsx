/**
 * Power Agreement — Block Checkout integration entry point.
 *
 * Registers a checkout block whose React component renders either the
 * accordion UI or the inline-scroll preview + modal UI (depending on
 * the merchant's `display_mode` setting), and pushes consent state into
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

type DisplayMode = 'accordion' | 'inline_scroll';

type Settings = {
	enabled: boolean;
	title: string;
	content: string;
	consent_text: string;
	display_mode: DisplayMode;
};

const useConsentSync = (consent: boolean, settings: Settings) => {
	const lastSent = useRef<boolean | null>(null);
	useEffect(() => {
		if (!settings.enabled || !settings.content) {
			return;
		}
		if (lastSent.current === consent) {
			return;
		}
		lastSent.current = consent;
		extensionCartUpdate({
			namespace: NAMESPACE,
			data: { consent },
		});
	}, [consent, settings.enabled, settings.content]);
};

const ConsentLabel = ({
	consent,
	setConsent,
	text,
}: {
	consent: boolean;
	setConsent: (next: boolean) => void;
	text: string;
}) => (
	<label className="power-agreement__consent">
		<input
			type="checkbox"
			name="power_agreement_consent"
			checked={consent}
			onChange={(event) => setConsent(event.target.checked)}
		/>
		<span>{text}</span>
	</label>
);

const AccordionView = ({ settings }: { settings: Settings }) => {
	const [consent, setConsent] = useState(false);
	const [open, setOpen] = useState(false);
	useConsentSync(consent, settings);

	return (
		<div className="power-agreement power-agreement--accordion wp-block-power-agreement" data-power-agreement>
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
			<ConsentLabel consent={consent} setConsent={setConsent} text={settings.consent_text} />
		</div>
	);
};

const InlineScrollView = ({ settings }: { settings: Settings }) => {
	const [consent, setConsent] = useState(false);
	const dialogRef = useRef<HTMLDialogElement | null>(null);
	useConsentSync(consent, settings);

	const openDialog = () => {
		const el = dialogRef.current;
		if (!el) return;
		if (typeof el.showModal === 'function') {
			try {
				el.showModal();
				return;
			} catch {
				/* fall through */
			}
		}
		el.setAttribute('open', '');
	};

	const closeDialog = () => {
		const el = dialogRef.current;
		if (!el) return;
		if (typeof el.close === 'function' && el.open) {
			try {
				el.close();
				return;
			} catch {
				/* fall through */
			}
		}
		el.removeAttribute('open');
	};

	const expandLabel = __('Expand to view', 'power-agreement');
	const closeAriaLabel = __('Close', 'power-agreement');
	const confirmLabel = __('Confirm', 'power-agreement');

	return (
		<div className="power-agreement power-agreement--inline-scroll wp-block-power-agreement" data-power-agreement>
			<div className="power-agreement__compact-card">
				<span className="power-agreement__title">{settings.title}</span>
				<button
					type="button"
					className="power-agreement__expand-btn"
					aria-haspopup="dialog"
					onClick={openDialog}
					data-power-agreement-open
				>
					{expandLabel}
				</button>
			</div>
			<ConsentLabel consent={consent} setConsent={setConsent} text={settings.consent_text} />
			<dialog
				ref={dialogRef}
				className="power-agreement__modal"
				data-power-agreement-modal
				aria-labelledby="power-agreement-modal-title"
			>
				<div className="power-agreement__modal-header">
					<h2 id="power-agreement-modal-title" className="power-agreement__modal-title">
						{settings.title}
					</h2>
					<button
						type="button"
						className="power-agreement__modal-icon-close"
						aria-label={closeAriaLabel}
						onClick={closeDialog}
					>
						×
					</button>
				</div>
				<div
					className="power-agreement__modal-body"
					dangerouslySetInnerHTML={{ __html: settings.content }}
				/>
				<div className="power-agreement__modal-footer">
					<label className="power-agreement__consent power-agreement__consent--inner">
						<input
							type="checkbox"
							checked={consent}
							onChange={(event) => setConsent(event.target.checked)}
						/>
						<span>{settings.consent_text}</span>
					</label>
					<button
						type="button"
						className="power-agreement__confirm-btn"
						onClick={closeDialog}
					>
						{confirmLabel}
					</button>
				</div>
			</dialog>
		</div>
	);
};

const PowerAgreementBlock = () => {
	const settings = (getSetting(`${NAMESPACE}_data`, {
		enabled: false,
		title: '',
		content: '',
		consent_text: '',
		display_mode: 'inline_scroll',
	}) as unknown) as Settings;

	if (!settings.enabled || !settings.content) {
		return null;
	}

	return settings.display_mode === 'accordion'
		? <AccordionView settings={settings} />
		: <InlineScrollView settings={settings} />;
};

registerCheckoutBlock({
	metadata,
	component: PowerAgreementBlock,
} as never);

export default PowerAgreementBlock;
// Mark as having translations consumer (no-op for build).
__('Power Agreement', 'power-agreement');
