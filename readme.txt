=== VezmoPay for WooCommerce ===
Contributors: acceptglobal
Tags: payments, payment gateway, credit card, ach, woocommerce
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through VezmoPay — hosted checkout, inline payment element, or secure iframe. Card data never touches your server.

== Description ==

VezmoPay for WooCommerce connects your store to the VezmoPay payment platform. Customers pay by card or US bank account (ACH); 3-D Secure / SCA is handled entirely on VezmoPay-hosted surfaces.

= Three integration modes =

* **Inline payment element** — the vezmo.js SDK mounts a VezmoPay-hosted payment form on your pay page. The order is finalized from the SDK's success event, with a status-polling fallback if events are blocked.
* **Secure iframe** — the VezmoPay payment page is embedded directly in an iframe on your pay page; the plugin polls VezmoPay for the payment status. Works even if JavaScript on your page fails.
* **Hosted checkout** — the customer is redirected to a VezmoPay paylink page. The order is completed via webhook (see limitations below).

= Built for modern WooCommerce =

* Compatible with High-Performance Order Storage (HPOS) and the Cart & Checkout Blocks.
* Separate Test and Live environments, each with its own API key, secret and base URL, plus a one-click **Test connection** button and an unmistakable test-mode banner.
* Webhook receiver at `/wp-json/vezmopay/v1/webhook` that verifies HMAC signatures when present and — regardless of the signature — **independently re-verifies every event against the VezmoPay API** before an order is touched. Duplicate deliveries are deduplicated, and an amount-mismatch guard puts suspicious orders on hold instead of completing them.
* SAQ-A PCI scope in every mode: card fields are always rendered by VezmoPay, never by your store.
* Idempotent payment creation (Idempotency-Key per order attempt), so refreshes and retries never create duplicate charges.
* Optional debug logging to WooCommerce → Status → Logs with API keys, secrets and tokens redacted.
* API credentials can be kept out of the database entirely via `wp-config.php` constants.

= Pricing =

VezmoPay uses transparent per-transaction pricing — no monthly fee, no setup fee, no minimum volume, no lock-in ([full pricing](https://vezmo.com/pricing/vezmopay)):

* **Cards:** 2.79% + $0.29 per successful charge (American Express 2.89% + $0.49; international cards +1%; currency conversion +1%)
* **Bank payments (ACH):** 0.9% + $1.00 per successful payment, capped at $10.00
* **Payouts:** 0.1% + $0.30 per withdrawal
* Fraud screening / 3-D Secure: $0.10 per card charge; disputed payments $20.00 (refunded if you win); returned bank payments $5.00
* Refunds return your processing fee proportionally. Custom rates are available for higher volumes; exact rates are confirmed at account approval.

= Current limitations =

These reflect the VezmoPay platform as it exists today (verified against the platform API — see the plugin's `docs/VEZMOPAY-API-CONTRACT.md`), not missing plugin work:

* **No refund API.** Refunds must be issued from the VezmoPay dashboard. Attempting a refund from the WooCommerce order screen shows an explanatory error; the order is marked refunded the next time the plugin verifies the payment.
* **No saved cards / tokenization.** Customers enter payment details on each purchase.
* **No off-session charging**, so WooCommerce Subscriptions renewals are not supported.
* **No authorize-then-capture.** Payments are captured immediately.
* **Hosted checkout does not redirect the customer back to your store** — VezmoPay has no return-URL support yet. The order is completed by webhook (with polling reconciliation as backup), and the customer receives the order confirmation email as usual.
* **Zero-decimal currencies (JPY, KRW, VND, …) are refused.** The platform currently mishandles them, so the gateway hides itself rather than charging wrong amounts.
* Express wallets (Apple Pay / Google Pay) are not available in the embedded modes.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` (or install via Plugins → Add New) and activate it. WooCommerce 8.0+ must be active.
2. In your **VezmoPay dashboard**, create an API key (Settings → API Keys) with these permissions: `secure-payment.create`, `paylink.create`, `paylink.read`, `payment.read`, plus `account.read` and `account.update` for the in-plugin account settings panel. Note that creating a new key deactivates your previous key.
3. In WordPress, go to **WooCommerce → Settings → Payments → VezmoPay**. Choose your environment (Test or Live), paste the API key and secret, save, and click **Test connection**.
   * Alternatively, define `VEZMOPAY_TEST_API_KEY`, `VEZMOPAY_TEST_API_SECRET`, `VEZMOPAY_LIVE_API_KEY` and `VEZMOPAY_LIVE_API_SECRET` in `wp-config.php` to keep secrets out of the database.
4. In the VezmoPay dashboard, register a **webhook endpoint** pointing at `https://your-store.example/wp-json/vezmopay/v1/webhook`, subscribed to the `payment.success` and `payment.failed` events. Copy the `whsec_…` secret (shown only once) into the plugin's **Webhook secret** field and save.
5. If you use the **inline payment element** mode, add your store's origin (e.g. `https://your-store.example`) to the **trusted origins** list in the VezmoPay dashboard (merchant settings) so the payment element can send success events to your page. If you skip this, payments still complete via the plugin's polling fallback — just slightly slower.
6. Pick your integration mode, enable the gateway, and place a test order.

== Frequently Asked Questions ==

= How do I refund an order? =

VezmoPay does not currently expose a refund API to merchants, so refunds cannot be started from WooCommerce. Issue the refund from your VezmoPay dashboard; the WooCommerce order will be marked refunded the next time the plugin verifies that payment against the API. Partial refunds are likewise dashboard-only.

= How do I test before going live? =

Set the Environment to **Test** and use a VezmoPay API key that was created as a Test key. The settings screen and the checkout show clear TEST MODE banners, and no real money moves. VezmoPay's embedded payment form runs on Stripe in test mode, so the standard Stripe test cards work (for example 4242 4242 4242 4242 for success).

= Is this PCI compliant? What is my scope? =

In all three modes the card form is served by VezmoPay (a Stripe Payment Element inside a VezmoPay-hosted page or iframe). Card numbers never touch your server or your page's DOM, which keeps a typical store at SAQ-A, the lightest PCI SAQ level. 3-D Secure challenges also run inside the VezmoPay-hosted surface.

= Are webhooks secure? I read that VezmoPay signing is disabled. =

Correct — the platform does not currently sign webhook deliveries. The plugin is designed so this does not matter for order integrity: it never trusts webhook payload data. Every incoming event is only used as a hint; the plugin re-fetches the payment from the VezmoPay API (authenticated with your credentials) and updates the order from that authoritative record. It also deduplicates event ids and holds any order whose paid amount does not match the order total. If VezmoPay enables signing, the plugin verifies the `X-Webhook-Signature` HMAC automatically using your saved `whsec_` secret.

= Why doesn't the gateway show for my JPY (or KRW, VND, …) store? =

The VezmoPay platform currently converts amounts in a way that corrupts zero-decimal currencies (a ¥1,000 charge would become ¥100,000). Rather than risk wrong charges, the plugin refuses to offer the gateway for those currencies and shows a notice on the settings screen. This will be lifted once the platform fixes the conversion.

= The customer paid on the hosted checkout but wasn't redirected back. Is that a bug? =

No — VezmoPay's hosted paylink pages do not yet support return/cancel URLs, so the customer stays on the VezmoPay success page. The order is completed automatically by webhook (or by status polling as a backup) and the customer receives the standard WooCommerce order confirmation email. If you want customers to stay on your site, use the inline element or iframe mode instead.

= Does it work with WooCommerce Subscriptions or Pre-Orders? =

Not yet. VezmoPay has no saved-payment-method or off-session charging API, which those extensions require. The gateway declares support for one-time `products` purchases only.

= Where are the logs? =

Enable **Debug logging** in the gateway settings, then look under WooCommerce → Status → Logs, source `vezmopay`. API keys, secrets, bearer tokens and webhook secrets are redacted before anything is written.

== External services ==

This plugin connects your store to the VezmoPay payment platform, operated by ACCEPT GLOBAL LIMITED. It communicates with the following services:

**VezmoPay API** (`https://api.vezmo.com`, or `https://api.dev.vezmo.com` in Test mode)

* Used to authenticate your merchant API key, create payment sessions and payment links, and verify payment status.
* Data sent: your API credentials (server-to-server only), the order amount, currency, an order reference/title, and — if provided at checkout — the customer's billing name, email, phone, company and country. Sent when a customer starts a VezmoPay payment and whenever the plugin verifies a payment's status.

**VezmoPay hosted checkout and payment element** (`https://user.vezmo.com` / `https://user.dev.vezmo.com`, and the `vezmo.js` script plus payment iframe served from the API host)

* The customer's browser loads VezmoPay-hosted payment pages/scripts so that card details are entered directly with VezmoPay and never touch your store. Loaded on the pay page (element/iframe modes) or after redirect (hosted mode).

VezmoPay is operated by ACCEPT GLOBAL LIMITED: [https://vezmo.com](https://vezmo.com) — see the site for terms of service and privacy policy.

== Changelog ==

= 0.2.2 =
* Fix: the native Enable/Disable auto-updates toggle stays available after connecting. The in-plugin "Automatic updates" force-setting was removed — it made WordPress replace the toggle with static "Auto-updates enabled" text once settings were first saved. Auto-updates are now controlled solely by the Plugins screen toggle, like any other plugin.

= 0.2.1 =
* Fix: gateway now appears in the WooCommerce Block checkout (Blocks availability no longer depends on the WC gateway registry, which is empty during the Store API request).
* Fix: the native Enable/Disable auto-updates link now shows in the Plugins list (plugin is injected into the update_plugins transient on every read).
* blocks.js hardened against missing dependencies so it can never break the checkout payment step.

= 0.2.0 =
* Correct test/live host (user.dev.vezmo.com); self-heal old saved value.
* Auto-register webhook on Connect; auto-fill the signing secret.
* Request-production-access link; account settings panel; light/dark/auto checkout theme.
* Native auto-updates toggle via the Update URI hostname filter.
* Settings screen now explains exactly why the gateway is hidden at checkout.

= 0.1.0 =
* Initial release.
* Three integration modes: inline payment element (vezmo.js), secure iframe, and hosted checkout (paylink redirect).
* Test/Live environments with separate credentials, base URLs and a Test connection button.
* Webhook receiver with signature verification (when available), event deduplication, and mandatory API re-verification of every event.
* HPOS and Cart & Checkout Blocks compatibility.
* Idempotent payment creation, amount-mismatch guard, zero-decimal currency guard, redacted debug logging.

= Updates =

New releases are published on GitHub and appear on your WordPress Plugins screen like any other update — update with one click, or use the Plugins screen's native "Enable auto-updates" toggle for automatic background updates.

Distribution note: the plugin checks the GitHub Releases API. If the source repository is private, set a read-only token via the `VEZMOPAY_GITHUB_TOKEN` constant in wp-config.php (or the `vezmopay_github_token` filter) so update checks can authenticate; a public repository needs no token.
