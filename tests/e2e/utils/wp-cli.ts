import { execSync } from 'node:child_process';

/**
 * Whether to talk to the wp-env "dev" environment (port 8888) or the
 * "tests" environment (port 8889). E2E typically targets dev.
 */
const ENV_TARGET = process.env.WP_ENV_TARGET ?? 'cli';

/**
 * Run a wp-cli command inside the chosen wp-env container.
 * Returns trimmed stdout. Throws if the command fails.
 *
 * Strips wp-env's wrapper lines so callers can parse the wp output cleanly.
 */
export function wpCli(cmd: string): string {
	const raw = execSync(`npx wp-env run ${ENV_TARGET} wp ${cmd}`, {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	});
	// wp-env prints wrapper lines like "ℹ Starting ..." and "✔ Ran ...".
	// Strip them so callers see only the actual wp-cli output.
	const lines = raw.split('\n');
	return lines
		.filter((line) => {
			if (line.startsWith('ℹ Starting')) return false;
			if (line.startsWith('✔ Ran')) return false;
			if (line.startsWith('Notice:')) return false;
			return true;
		})
		.join('\n')
		.trim();
}

export function ensureSimpleProduct(name = 'Power Agreement Test Product'): number {
	// Find existing or create.
	const existing = wpCli(
		`post list --post_type=product --name="${name.replace(/"/g, '\\"')}" --field=ID --format=ids`
	);
	if (existing.trim() !== '') {
		return parseInt(existing.split(/\s+/)[0], 10);
	}
	const id = wpCli(
		`wc product create --name="${name}" --regular_price=10 --type=simple --user=admin --porcelain`
	);
	return parseInt(id, 10);
}

export function setAgreement(opts: {
	enabled: boolean;
	title?: string;
	content?: string;
	consent_text?: string;
}): void {
	const payload = {
		enabled: opts.enabled,
		title: opts.title ?? 'Service Agreement',
		content: opts.content ?? '<p>Read carefully.</p>',
		consent_text: opts.consent_text ?? 'I have read and agree to the agreement above.',
	};
	const json = JSON.stringify(payload).replace(/"/g, '\\"');
	wpCli(`option update power_agreement_settings "${json}" --format=json`);
}

export function setStorefrontTheme(): void {
	wpCli('theme activate storefront');
}

export function setClassicCheckoutPage(): void {
	// Replace the checkout page content with the classic shortcode so we test
	// the classic-checkout integration even on modern WC installs that ship
	// the block-based page by default.
	const checkoutId = wpCli('option get woocommerce_checkout_page_id');
	if (!checkoutId) {
		return;
	}
	wpCli(`post update ${checkoutId} --post_content='[woocommerce_checkout]'`);
}

export function setBlockCheckoutPage(): void {
	const checkoutId = wpCli('option get woocommerce_checkout_page_id');
	if (!checkoutId) {
		return;
	}
	// Use WC's full default block markup so the React app has the inner blocks
	// it needs to hydrate. We base64-encode the PHP snippet to avoid every
	// shell-escaping landmine (`$`, quotes, semicolons, etc.).
	const phpCode = [
		'$r=new ReflectionClass(WC_Install::class);',
		'$m=$r->getMethod("get_checkout_block_content");',
		'$m->setAccessible(true);',
		`wp_update_post(array("ID"=>${checkoutId},"post_content"=>$m->invoke(null)));`,
	].join('');
	const b64 = Buffer.from(phpCode, 'utf8').toString('base64');
	wpCli(`eval "eval(base64_decode('${b64}'));"`);
}
