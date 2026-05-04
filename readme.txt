=== Power Agreement ===
Contributors: zenbuapps
Tags: woocommerce, checkout, agreement, consent, terms, gdpr
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a per-store agreement consent block to WooCommerce checkout (Classic + Block) and saves an immutable order-level snapshot for legal evidence.

== Description ==

Power Agreement injects a collapsible agreement section with a required consent checkbox just above the place-order button on every WooCommerce checkout page. Once a customer ticks the box and submits, the plugin records four pieces of evidence on the order itself:

* `_power_agreement_html` — the exact agreement HTML that was shown
* `_power_agreement_hash` — SHA-256 of the HTML, for integrity verification
* `_power_agreement_agreed_at` — ISO 8601 timestamp (UTC)
* `_power_agreement_ip` — the customer's IP address at submit time

All four meta entries are written through the WooCommerce Order API so they are HPOS-safe.

The plugin supports both **Classic Checkout** (shortcode-based) and **Block Checkout** (the default in modern WooCommerce installations).

== Features ==

* Single global agreement, edited via the admin UI
* Collapsible accordion that defaults to closed
* Server-side validation that blocks order placement if the box is unticked
* Order admin metabox showing the consent record
* Full HPOS compatibility
* Translation-ready (English defaults + Traditional Chinese po file)

== Frequently Asked Questions ==

= Can I have different agreements per product? =

Not in this version. The current scope is a single store-wide agreement; per-product binding is on the roadmap.

= Are existing orders affected if I change the agreement text? =

No. The HTML is captured at the moment of order creation; old orders continue to display their original snapshot.

= Does uninstalling delete my legal records? =

No. Uninstall only removes the plugin's settings option. Order meta (the actual consent records) is preserved.

== Changelog ==

= 0.1.0 =
* Initial release.
