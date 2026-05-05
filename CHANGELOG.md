# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.1] — 2026-05-05

### Fixed

- **Custom-layout compatibility.** On stock Classic Checkout the agreement renders directly above `#place_order` because `woocommerce_review_order_before_submit` (the canonical "before-submit" hook, also used by WooCommerce's built-in *Terms and conditions*) puts it there. But on a custom checkout — Elementor Pro's Checkout widget, Flexible Checkout Fields, FunnelKit, theme template overrides, etc. — the place-order button is often moved hundreds of pixels away from where the hook fires. Result: our wrapper appeared above the order summary, far from the actual submit button the customer clicks.

  Added a small `relocate.js` that runs after the page loads and after every `updated_checkout` ajax fragment refresh. It finds the form's actual submit button (preferring `[name="woocommerce_checkout_place_order"]` / `#place_order`, falling back to the last `<button|input type="submit">` in the form excluding our own modal's controls) and reinserts the wrapper as the immediate previous sibling of that button's row. A debounced MutationObserver covers themes that disable WC's standard ajax flow entirely.

  Idempotent — it short-circuits when the wrapper is already adjacent to the submit, so stock layouts see no behavioural change.

  Discovered while pairing with a merchant whose Elementor-built checkout placed the order summary on top of the page and the billing fields plus submit button at the bottom.

## [0.3.0] — 2026-05-05

### Added

- **Self-hosted update mechanism via GitHub Releases.** The plugin now uses `yahnis-elsts/plugin-update-checker` to poll the project's GitHub Releases. When a new tag with a `power-agreement-X.Y.Z.zip` asset is published, every installed copy sees the prompt in the standard `Plugins → Update` page; clicking Update installs the new version through WordPress's normal update flow. No wp.org listing required.

### Changed

- **Inline-scroll mode is now a compact one-line card** (title on the left, "Expand to view" button on the right) instead of a 240 px scrollable preview. Customers can no longer scroll the agreement in place; the full text lives only inside the modal opened by the button. This was driven by user feedback that the scrollable preview "looked already-fully-expanded" and that they wanted "a small box, click 全部展開 to expand". The accordion mode is unchanged.
- **Translation:** "全部展開" for the expand button (zh_TW).

### Removed

- The `power-agreement__preview-*` markup and its styles. The preview-body / preview-header / `role="button"` scaffolding is gone — replaced by `power-agreement__compact-card` + `power-agreement__expand-btn` (a real `<button>` element with native keyboard support, no synthetic `role`).

### Notes

- The `display_mode` setting still accepts `inline_scroll` and `accordion` — only the behaviour of `inline_scroll` changed; existing data needs no migration.
- Hook unchanged: rendering still happens via `woocommerce_review_order_before_submit`, which is WooCommerce's canonical "directly before the place-order button" hook on Classic Checkout.

## [0.2.0] — 2026-05-05

### Added

- New `display_mode` setting with two choices:
  - **Inline scrolling preview** (new default for fresh installs) — the agreement is partially visible from the start in a 240 px scrollable box; clicking the box opens a full-size native `<dialog>` modal.
  - **Accordion** (previous behaviour) — collapsed until clicked.
- Modal redesign: dedicated `power-agreement__confirm-btn` so host themes (e.g. Storefront's `.button` class) cannot break button layout. Footer now includes a mirrored consent checkbox and a "Confirm" action.
- Two-way sync between the outer (form-submitted) consent checkbox and the inner (mirror) checkbox inside the modal — Classic via document-level `change` delegation, Block via shared React state.
- Full Traditional Chinese (zh_TW) coverage for both PHP and React strings, including a JSON translation file built via `wp i18n make-json` so `wp_set_script_translations()` picks them up.

### Changed

- Inline preview flattens heading typography (`h1`–`h6` rendered at `1em` with `font-weight: 600`) so the excerpt reads as a single flat flow. The full-size modal still shows headings at their original sizes.
- `ClassicCheckout::maybeEnqueueAssets` now dispatches the matching CSS+JS pair (`accordion.{css,js}` or `inline-scroll.{css,js}`) per `display_mode`.
- Asset versioning uses `filemtime` so future edits auto-bust the browser cache.
- "Tested up to" bumped to WordPress 6.9.
- Tags trimmed to 5 (wp.org limit).

### Fixed

- `defined( 'ABSPATH' ) || exit;` guards added to all class files in `src/`.
- Explicit sanitisation of the `$_POST` consent field in `ClassicCheckout::validate()` (was implicitly safe via comparison, but Plugin Check now passes).
- Classic accordion no longer loses click handlers after WooCommerce's `updated_checkout` ajax fragment refresh — switched to `document`-level click delegation since `updated_checkout` is a jQuery custom event that does not bubble to native listeners.
- `colour-mix(currentColor, transparent)` background on the modal close button rendered as invisible white-on-white in some contexts; replaced with a fixed dark background.

### Distribution

- Added `.distignore` so the wp.org production zip excludes dev tooling, tests, specs, and source assets (the built bundle in `assets/build/` ships instead).

## [0.1.0] — 2026-05-04

Initial release.

### Added

- Single global agreement maintained via `WooCommerce → Power Agreement` admin page (title, body, consent label, enable toggle).
- Classic Checkout integration: collapsible accordion above the place-order button, server-side validation via `woocommerce_checkout_process`, snapshot persistence on `woocommerce_checkout_create_order`.
- Block Checkout integration: WC `IntegrationInterface`, Store API extension (`power-agreement` namespace) registered via the official helpers, React component renders the same accordion UI and pushes consent state into the cart via `extensionCartUpdate`. Server-side `RouteException` blocks consent-less submissions even if the front-end is bypassed.
- HPOS-safe order meta: full HTML snapshot, SHA-256 hash, ISO timestamp, customer IP. Visible on the admin order edit page.
- Traditional Chinese (`zh_TW`) translation.
- PHPUnit unit + integration test suites (HPOS on/off matrix). Playwright E2E for Classic Checkout. GitHub Actions CI.

### Compatibility

- WordPress 6.5+
- WooCommerce 8.3+ (HPOS declared)
- PHP 8.1+
