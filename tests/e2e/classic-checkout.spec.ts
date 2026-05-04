import { expect, test } from '@playwright/test';
import {
	ensureSimpleProduct,
	setAgreement,
	setClassicCheckoutPage,
	setStorefrontTheme,
} from './utils/wp-cli';

test.describe('Classic checkout — agreement consent', () => {
	test.beforeAll(async () => {
		setStorefrontTheme();
		setClassicCheckoutPage();
		ensureSimpleProduct('Power Agreement Test Product');
		setAgreement({
			enabled: true,
			title: 'Service Agreement',
			content: '<p>By placing your order you agree to our terms.</p>',
			consent_text: 'I agree to the agreement.',
		});
	});

	test('places order only when the consent box is ticked', async ({ page, request }) => {
		// 1. Add product to cart via storefront URL trick.
		const productId = ensureSimpleProduct('Power Agreement Test Product');
		await page.goto(`/?add-to-cart=${productId}`);
		await expect(page).toHaveURL(/.*/);

		// 2. Fill checkout form.
		await page.goto('/checkout/');
		await page.locator('#billing_first_name').fill('John');
		await page.locator('#billing_last_name').fill('Doe');
		await page.locator('#billing_address_1').fill('123 Test St');
		await page.locator('#billing_city').fill('Testville');
		await page.locator('#billing_postcode').fill('90210');
		await page.locator('#billing_phone').fill('5551234567');
		await page.locator('#billing_email').fill('test-john@example.org');
		// Billing state is a required field. WC ships a select2-enhanced select; we
		// pick a value via the underlying <select>.
		const stateSelect = page.locator('select#billing_state');
		if (await stateSelect.count() > 0) {
			await stateSelect.selectOption('CA');
		}

		// 3. Confirm accordion is rendered.
		await expect(page.locator('[data-power-agreement]')).toBeVisible();
		await expect(page.locator('.power-agreement__title')).toContainText('Service Agreement');

		// 4. Submit without consent → expect error notice.
		await page.locator('#place_order').click();
		await expect(page.locator('.woocommerce-error, .wc-block-components-notice-banner')).toContainText(
			'I agree to the agreement.'
		);

		// 5. Tick consent and submit → order success.
		await page.locator(`input[name="power_agreement_consent"]`).check();
		await page.locator('#place_order').click();

		await page.waitForURL(/order-received/, { timeout: 30_000 });
		await expect(page.locator('h1, h2').first()).toContainText(/order received|thank you/i);
	});
});
