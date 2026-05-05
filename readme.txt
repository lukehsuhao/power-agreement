=== Power Agreement ===
Contributors: zenbuapps
Tags: woocommerce, checkout, consent, terms, gdpr
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a per-store agreement consent block to WooCommerce checkout (Classic + Block) and saves an immutable order-level snapshot for legal evidence.

== Description ==

Power Agreement injects an agreement section with a required consent checkbox just above the place-order button on every WooCommerce checkout page. Once a customer ticks the box and submits, the plugin records four pieces of evidence on the order itself:

* `_power_agreement_html` — the exact agreement HTML that was shown
* `_power_agreement_hash` — SHA-256 of the HTML, for integrity verification
* `_power_agreement_agreed_at` — ISO 8601 timestamp (UTC)
* `_power_agreement_ip` — the customer's IP address at submit time

All four meta entries are written through the WooCommerce Order API so they are HPOS-safe.

The plugin supports both **Classic Checkout** (shortcode-based) and **Block Checkout** (the default in modern WooCommerce installations).

== Features ==

* Single global agreement, edited via the admin UI
* Two display modes — inline scrolling preview (default, click to enlarge in a modal) or a collapsed accordion
* Two-way synced consent checkbox between the inline preview and the modal
* Server-side validation that blocks order placement if the box is unticked
* Order admin metabox showing the consent record
* Full HPOS compatibility
* Translation-ready (English defaults + Traditional Chinese po/mo + JS json)

== Frequently Asked Questions ==

= Can I have different agreements per product? =

Not in this version. The current scope is a single store-wide agreement; per-product binding is on the roadmap.

= Are existing orders affected if I change the agreement text? =

No. The HTML is captured at the moment of order creation; old orders continue to display their original snapshot.

= Does uninstalling delete my legal records? =

No. Uninstall only removes the plugin's settings option. Order meta (the actual consent records) is preserved.

== Changelog ==

= 0.3.0 =
* Replace the inline scrolling preview with a compact one-line card (title + "Expand to view" button); the full agreement now lives only inside the modal.
* Add a self-hosted GitHub-Releases-based update mechanism: when a new tag with a power-agreement-X.Y.Z.zip asset is published, the standard "Plugins → Update" flow detects it.

= 0.2.0 =
* Add inline scrolling preview display mode (default for new installs); the agreement is partially visible from the start and clicking the box opens a full-size modal.
* Modal footer now contains a mirrored consent checkbox that two-way-syncs with the outer one.
* Replace the modal close button with a plugin-owned styled "Confirm" button so host themes cannot break its layout.
* Flatten heading typography inside the inline preview so the excerpt reads as a single flat flow; the modal keeps the original heading sizes.
* Add full Traditional Chinese (zh_TW) translation for both PHP and React strings.
* Bump "Tested up to" to 6.9.

= 0.1.0 =
* Initial release.
