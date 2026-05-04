# Power Agreement

WooCommerce checkout agreement consent plugin. Adds a collapsible agreement section with a required consent checkbox before the place-order button on both Classic and Block Checkout. On successful submission, persists a tamper-evident snapshot of the agreement (full HTML + SHA-256 hash + ISO timestamp + customer IP) onto the order as HPOS-safe meta.

## Targets

- WordPress **6.5+**
- WooCommerce **8.3+** (HPOS-aware; declares `custom_order_tables` compatibility)
- PHP **8.1+**

## What it does

1. Single global agreement maintained in `WooCommerce → Power Agreement`.
2. Renders a collapsed accordion with consent checkbox before the place-order button.
   - **Classic Checkout** — hooks into `woocommerce_review_order_before_submit` and validates via `woocommerce_checkout_process`.
   - **Block Checkout** — registers an `IntegrationInterface` and a Store API extension under namespace `power-agreement`, gated by `woocommerce_store_api_checkout_update_order_from_request`.
3. Writes 4 HPOS-safe order meta keys on submission:
   - `_power_agreement_html` — the full agreement body at the time of consent
   - `_power_agreement_hash` — SHA-256 of the body
   - `_power_agreement_agreed_at` — ISO 8601 (UTC) timestamp
   - `_power_agreement_ip` — customer IP via `WC_Geolocation`
4. Surfaces the consent record on the admin order edit page.

## Repository layout

```
power-agreement.php          # plugin bootstrap (header, requirements, HPOS declare)
src/
  Plugin.php                 # central hook registrar
  Settings/                  # admin settings page + repository
  Order/                     # AgreementSnapshot + OrderMetaWriter + admin display
  Checkout/                  # ClassicCheckout + BlockCheckout + ConsentValidator
  Compat/                    # version check + HPOS helpers
assets/
  src/frontend/              # vanilla CSS+JS used by Classic
  src/block-checkout/        # React component for Block Checkout
  build/                     # @wordpress/scripts build output
languages/                   # .pot + zh_TW translations
tests/
  Unit/                      # pure-PHP value object tests
  Integration/               # WP+WC PHPUnit (HPOS matrix)
  e2e/                       # Playwright E2E
specs/                       # design + plan documents
```

## Development

### Install

```bash
composer install
npm install
```

### Boot wp-env (Docker required)

```bash
npm run wp-env:start
```

This boots two WordPress installs:
- `http://localhost:8888` — dev environment used by E2E
- `http://localhost:8889` — tests environment used by PHPUnit integration suite

### Build the Block Checkout JS bundle

```bash
npm run build      # one-shot build
npm run start      # watch mode
```

### Tests

```bash
# PHP — unit suite (no WP needed)
composer test:unit

# PHP — integration suite (requires wp-env running)
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-agreement -- composer test:integration

# PHP — integration suite, HPOS on / off
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-agreement -- composer test:integration:hpos
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-agreement -- composer test:integration:legacy

# Playwright E2E (requires wp-env dev env at localhost:8888)
npm run test:e2e
```

### Lint

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-agreement -- composer lint
```

This runs PHPCS (WordPress + WooCommerce sniffs) and PHPStan level 8.

### i18n

```bash
# regenerate .pot
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-agreement -- \
  ./vendor/bin/wp i18n make-pot . languages/power-agreement.pot \
  --slug=power-agreement --domain=power-agreement \
  --exclude=node_modules,vendor,tests,assets/build,specs,test-results

# compile .po → .mo
msgfmt languages/power-agreement-zh_TW.po -o languages/power-agreement-zh_TW.mo
```

## Architecture

See `specs/2026-05-04-power-agreement-design.md` for the full design and `specs/2026-05-04-power-agreement-plan.md` for the 6-phase implementation plan.

### Why two checkout integrations?

WooCommerce supports both the classic shortcode-based checkout and the Gutenberg block-based checkout. Stores typically use one or the other, but a plugin that wants to be installable into any modern WC store must handle both. The two integrations share `ConsentValidator` and `OrderMetaWriter` so the business rules and persistence layer are defined once.

### Why store the full HTML on every order?

Agreement language changes over time. To prove what the customer actually agreed to, we capture a snapshot at the moment of consent rather than referencing a "version 3" pointer that could be reinterpreted later. The SHA-256 hash provides a quick integrity check and a search key for de-duplication if you need to audit which orders saw which agreement text.

## Security

- All admin pages are gated by `manage_woocommerce`.
- The Store API extension validator throws `RouteException(400)` when consent is missing, so a hand-crafted `POST /wc/store/v1/checkout` cannot bypass the gate even if the React layer is tampered with.
- All settings input is run through `wp_kses_post` (body) or `sanitize_text_field` + `mb_substr` (title, consent label) before persistence.
- All user-controlled output is escaped with `esc_html`, `esc_attr`, or `wp_kses_post` (the snapshot HTML, defensively re-filtered every render so KSES updates apply to legacy data too).

## License

GPL-2.0-or-later — same as WordPress and WooCommerce.
