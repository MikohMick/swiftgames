=== Wupex Gift Cards ===
Contributors: swiftovertimeenterprise
Tags: woocommerce, gift cards, psn, wupex, digital delivery
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
WC requires at least: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WooCommerce to the Wupex API for automated PSN and gift card delivery.

== Description ==

Wupex Gift Cards automates digital gift card fulfilment for Swift Overtime Enterprise.
When a customer completes a paid order, the plugin:

* Calls the Wupex API to pull the PIN codes for the ordered product(s)
* Encrypts each PIN code using AES-256 and stores it securely
* Emails the customer a secure "Reveal My Key" link
* Lets the customer reveal their code via a token-protected page

= Features =

* Settings page under WooCommerce → Wupex Gift Cards
* Sandbox / Production environment toggle (no manual URL editing)
* Configurable price markup applied automatically on product import
* Product import page with category auto-creation
* Daily stock sync via WP-Cron
* AES-256 encrypted PIN storage
* Cryptographically random reveal tokens with configurable expiry
* Refund protection — blocks refund if code was already revealed
* Admin order meta box with re-send email button
* Daily rotating log files

== Installation ==

1. Upload the `wupex-gift-cards` folder to `/wp-content/plugins/`
2. Activate via the Plugins menu in WordPress
3. Go to WooCommerce → Wupex Gift Cards and configure:
   - API Key
   - Merchant Code
   - Environment (Sandbox / Production)
   - Encryption Key (set once, do not change after codes are stored)
   - Reveal link expiry (default: 72 hours)
   - Price markup %
4. Click "Test Connection" to verify your API credentials
5. Go to Import Products to import your Wupex product catalogue
6. Add the shortcode `[wupex_reveal_code]` to a page for the reveal endpoint

== Frequently Asked Questions ==

= Which products are treated as Wupex products? =

Only WooCommerce products that have the `_wupex_sku` post meta set will trigger
Wupex API calls. Products imported via the Import Products page are set up
automatically. Other products in your store are ignored.

= Can customers reveal their code more than once? =

Yes. Reveal tokens remain valid until the `token_expiry` datetime. Each reveal
increments the `reveal_count` counter but does not invalidate the token.

= What happens if the Wupex API call fails? =

The order is NOT marked as failed (since payment was already taken). An order note
is added for the admin, and the order is flagged with `_wupex_failed = 1` for
manual review. The error is also written to the daily log file.

= Where are log files stored? =

`wp-content/uploads/wupex-logs/wupex-YYYY-MM-DD.log`

== Changelog ==

= 1.0.0 =
* Initial release
