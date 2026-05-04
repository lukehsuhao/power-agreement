# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
