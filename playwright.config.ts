import { defineConfig, devices } from '@playwright/test';

// Default to wp-env's "dev" environment (port 8888). The wp-cli util in
// tests/e2e/utils/wp-cli.ts targets the same env via the `cli` container, so
// E2E setup commands and the browser see the same WordPress install.
const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:8888';

export default defineConfig({
	testDir: 'tests/e2e',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	reporter: [['list']],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
});
