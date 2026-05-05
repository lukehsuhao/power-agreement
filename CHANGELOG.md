# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
