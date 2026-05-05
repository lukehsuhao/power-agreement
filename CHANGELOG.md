# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] — 2026-05-05

### Added

- **Button colour setting.** New `button_color` field in `WooCommerce → Power Agreement`, rendered as WordPress's bundled `wp-color-picker` widget. Applies to the "Read agreement" CTA in the inline preview and the "Confirm" button in the modal. The colour is stored as a sanitised hex string (`sanitize_hex_color`) and falls back to the default `#1f2937` if the value is invalid.
  - Classic Checkout: injected via `wp_add_inline_style` so we don't ship a separate dynamic stylesheet.
  - Block Checkout: shipped through `IntegrationInterface::get_script_data()`; the React component reads `settings.button_color` and applies it as an inline `style={{ backgroundColor, borderColor }}` on the buttons.
  - Hover state uses CSS `filter: brightness(0.88)` so any colour the merchant picks (light blue, brand accent, etc.) gets a sensible "slightly darker on hover" automatically — no need to compute or store a second hex.

### Changed

- **Renamed the expand action from "Expand to view" → "Read agreement"** (`zh_TW`: "全部展開" → "閱讀合約"). The new label is more direct about what clicking does. The old translation entry stays in the .po as an obsolete (`#~`) marker so legacy installs that haven't pulled the new .mo yet won't crash.

## [0.3.3] — 2026-05-05

### Fixed

- **`<button>` elements stripped on heavily-customised checkouts.** A merchant site using Flexible Checkout Fields + Conditional Payments + WP Rocket reported that the `Expand to view` action and the modal's close / confirm buttons silently disappeared from the rendered DOM, while the surrounding markup (and the form's `#place_order` submit button) survived. The agreement modal became unreachable.

  Root cause: at least one of those plugins runs the order-review HTML through a sanitiser that allows form-submitting controls (`<input>`, `<button name="woocommerce_checkout_place_order">`) but strips other `<button>` elements as "untrusted". The exact culprit isn't relevant — the same pattern shows up on a long tail of WooCommerce sites with custom checkout pipelines.

  Fix: replace the `<button>` elements with `<a href="…" role="button" tabindex="0">` — the standard WAI-ARIA pattern for non-form-submit "buttons". Anchors aren't form controls, so the same sanitisers leave them alone. Click handlers `preventDefault()` on the synthetic anchor, keyboard handlers cover Enter (native to anchors) and Space (added explicitly). Visual result is identical for end users.

  Applies to both the Classic Checkout PHP render and the Block Checkout React component. Tests updated to assert the new markup includes `role="button"` and that `power-agreement__expand-btn` survives.

## [0.3.2] — 2026-05-05

### Fixed

- **Relocate helper now picks the right button on cart-on-checkout pages.**
  The 0.3.1 helper used a "last `<button|input type=\"submit\">` in the form" fallback to find the place-order button. On pages that render both the cart shortcode AND the checkout shortcode (a common Elementor / FunnelKit pattern where the basket sits above the billing form), that fallback can land on the cart's "Apply coupon" / "Update cart" button instead. Switched to anchoring strictly on `[name="woocommerce_checkout_place_order"]` / `#place_order` — the canonical attribute WooCommerce gives the actual checkout submit, regardless of which form ends up wrapping it.

- **Duplicate wrapper handling.** Doubled rendering of the order-review template (which `woocommerce_review_order_before_submit` lives in) leaves two `[data-power-agreement]` wrappers in the DOM with the same `id` attributes. The relocate helper now keeps one wrapper — preferring the one that's already inside the same `<form>` as the place-order button — and removes the duplicate so the consent checkbox stops cloning itself.

Discovered when verifying 0.3.1 on the same merchant site that drove the 0.3.1 fix.

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
