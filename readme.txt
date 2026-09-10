=== VezmoPay for WooCommerce ===
Contributors: acceptglobal
Tags: payments, payment gateway, credit card, ach, woocommerce
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.4
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

This plugin connects your store to the VezmoPay payment platform, operated by Vezmo Technology, Inc. It communicates with the following services:

**VezmoPay API** (`https://api.vezmo.com`, or `https://api.dev.vezmo.com` in Test mode)

* Used to authenticate your merchant API key, create payment sessions and payment links, and verify payment status.
* Data sent: your API credentials (server-to-server only), the order amount, currency, an order reference/title, and — if provided at checkout — the customer's billing name, email, phone, company and country. Sent when a customer starts a VezmoPay payment and whenever the plugin verifies a payment's status.

**VezmoPay hosted checkout and payment element** (`https://user.vezmo.com` / `https://user.dev.vezmo.com`, and the `vezmo.js` script plus payment iframe served from the API host)

* The customer's browser loads VezmoPay-hosted payment pages/scripts so that card details are entered directly with VezmoPay and never touch your store. Loaded on the pay page (element/iframe modes) or after redirect (hosted mode).

VezmoPay is operated by Vezmo Technology, Inc.: [https://vezmo.com](https://vezmo.com) — see the site for terms of service and privacy policy.

== Changelog ==

= 0.3.4 =

* Fixed a declined card being hidden instead of shown. Twenty seconds after a decline the reason was replaced by "this is taking longer than usual" and a link to the VezmoPay page — so the customer never saw why their card was refused, and the link took them to another payment form with no explanation.
* Fixed being unable to retry after a decline. The cart was emptied when the order was created, before the card was charged, so placing the order again answered "your session has expired" or "your cart is empty". The declined payment could not be charged again either, so the checkout redirected to a second payment form. A decline now shows the reason next to the card fields, loads a fresh payment form in place, and the retry completes on the checkout page without leaving it.
* If the customer does end up on the pay page after a failed attempt, that page now says "Your payment was not completed" and offers a new payment, instead of silently showing the payment form again.
* Fixed a payment error appearing on the order-received page of an order that was paid. WooCommerce shows "your order cannot be processed as the originating bank/merchant has declined your transaction" for any failed order, and the plugin only re-checked orders still awaiting payment — so an order marked failed by an earlier decline kept that message after the retry succeeded. The payment is now verified before the page is built. An order that really did fail still shows the message.
* A 3-D Secure challenge is no longer sent repeated charge requests while the customer is completing it.

= 0.3.3 =

* Hosted checkout can now be switched on from the settings screen. VezmoPay still cannot confirm whether an account is activated for payment links, so hosted mode falls back to the embedded form — but if payment links already work on your account you can tick "My VezmoPay account is activated for payment links" instead of editing code.
* Fixed the Checkout URL setting bypassing the safety check the API URL setting has. It decides which address may report a payment result to your checkout, so it is now required to be an https VezmoPay address, is rejected with a visible error if it is not, and falls back to the shipped default rather than being used.
* Fixed hosted mode showing "Loading secure payment fields…" forever on the classic checkout. The plugin read your chosen mode before its settings were loaded, so it always believed it was in inline mode and rendered payment fields hosted mode does not have.
* Tightened the payment form's origin check: once the form has identified itself, only that address is accepted for the rest of the page.

= 0.3.2 =

* Fixed hosted checkout still sending customers to a page that could not take their money. VezmoPay does not report whether an account is activated to receive payments — the payment-methods list only says which methods you have switched on — so the plugin was reading "card is enabled" as "this account can be paid". Hosted mode now runs only when VezmoPay confirms it, and otherwise serves the embedded payment form. If your account IS activated and you want the redirect, use the new `vezmopay_force_hosted_mode` filter.
* Fixed Secure iframe mode: the payment form is loaded from one VezmoPay address and redirected to another, and the plugin was only listening to the first — so the form's own messages were ignored. That left a large empty panel below the card fields AND could stop the payment completing. The form is now sized to its content and its messages are received, while still refusing anything that did not come from the payment form itself.

= 0.3.1 =

* Fixed inline and iframe payments freezing on "Processing your payment…". The VezmoPay payment form ignores a charge request until Stripe's card fields have finished loading inside it — and it says nothing when it does, while already reporting itself ready. On a slow connection, or where js.stripe.com is blocked by an extension or network, the charge was simply never started and the checkout waited forever. The checkout now waits for the form to acknowledge the charge and asks again until it does (up to eight times over twenty seconds), so the payment goes through as soon as the form is ready.
* If the form never loads, the customer is told plainly that their card was not charged and offered the VezmoPay page instead of being left on a spinner.

= 0.3.0 =

Security and reliability release. Update promptly — this fixes two ways an order could be completed without the right money being taken, and closes an unauthenticated endpoint.

* Payments can no longer be reused. A second cart of the same value in one shopping session could receive the first cart's already-captured payment and complete without a new charge. Each payment session is now unique, and a payment that has already been paid can never be attached to another order.
* Payment results from the payment form are now verified strictly. A misconfigured session could switch the browser's origin check off, which let anything else on the checkout page claim a payment had succeeded or failed.
* Amounts are checked against the currency, and an answer the store cannot read is treated as a mismatch instead of a pass. Payment links are checked the same way and are re-created when an order's total changes, so a stale link cannot pay an order in full at the old price.
* Your webhook secret is now enforced. With a secret saved, deliveries without a valid signature are rejected — previously the signature header could simply be left out.
* An order can no longer be completed twice by two things checking it at once (duplicate emails, notes and stock reductions).
* Hosted checkout is no longer offered by an account that cannot accept payment-link payments; those customers were sent to a page they could not pay on, and the order was stranded.
* Checkout no longer gets stuck: a second payment attempt, an expired security token, a backgrounded tab during bank verification, and a form that unmounts mid-charge all now resolve instead of leaving a spinner.
* Plugin updates are only downloaded from GitHub over https, and are checked against a digest published with the release before anything is installed.
* Faster admin and front end: no blocking GitHub request on ordinary page loads, the settings account panel is cached, and the gateway registry is no longer built on every request.
* Accessibility: the payment form's frame is now labelled for screen readers in the default mode (WCAG 4.1.2).
* Card details typed into the payment form are no longer discarded when the checkout totals refresh.
* Refunds: WooCommerce now shows why refunds cannot be issued from the store instead of silently offering nothing.
* Housekeeping: API host validation, a rate limit on payment-session creation, complete uninstall cleanup, 28 newly translatable strings, and corrected developer documentation.

= 0.2.19 =
* Fixed a successful payment not showing the order-received page. The checkout asked the store to confirm the payment first, and if that one request failed — a slow response, or a security token retired because checkout created an account mid-flow — the customer was left waiting on the checkout page even though they had paid. A successful payment now always reaches the order-received page, which verifies the payment with VezmoPay as it loads.
* The success and pay-page addresses now come from WooCommerce itself rather than being assembled by the checkout, so stores with plain permalinks or translated checkout URLs land in the right place.
* A payment that stalls without ever succeeding still goes to the pay page, where it can be completed — the two cases no longer share one fallback.

= 0.2.18 =
* A payment that stalls no longer leaves the customer watching a spinner. After 20 seconds they are told it is taking longer than usual and offered a link to finish the payment on the VezmoPay page, and after 75 seconds they are moved to the pay page automatically, which keeps checking and can complete the order.
* Extra bank verification (3-D Secure) now says what it is doing instead of showing a generic message.
* With the gateway's Debug setting on, the checkout traces the payment to the browser console and every status check to your store's log (WooCommerce → Status → Logs → vezmopay), so a payment that will not settle can be diagnosed instead of guessed at.

= 0.2.17 =
* Fixed a payment that could sit on "Processing your payment…" forever. The checkout was waiting for the payment form to report back, and that message does not always arrive — some stores send no referrer, which is what the form needs to identify your site before it can talk to it. The checkout now also asks your store, which asks VezmoPay directly, so a payment is confirmed (or shown as failed) either way.
* Cancelled, expired and already-paid payments now end with a clear message instead of a spinner.
* If nothing settles after three minutes the customer is moved to the pay page, which keeps checking and can finish the payment, rather than being left waiting.

= 0.2.16 =
* Added a Pay button under the payment fields on the checkout page, showing the order total. The embedded VezmoPay form hides its own submit button, which left the payment area looking unfinished — this places the order, exactly as WooCommerce's own "Place order" button does. Both buttons work; use whichever your customers reach first.
* The payment fields are taller by default, so the card and bank options are no longer scrolled inside a short box.

= 0.2.15 =
* Inline and iframe modes now work the way Stripe's plugin does: the VezmoPay form appears in the payment section of the checkout page itself, and the customer pays with WooCommerce's own "Place order" button. No second page, no second button.
* Works on both the block checkout and the classic (shortcode) checkout.
* A declined card keeps the customer on the checkout page with the reason shown, so they can correct the card and try again without starting over.
* Your order is always created before the card is charged, and the store confirms the payment with VezmoPay server-side before marking it paid — the browser is never taken at its word.
* If the payment fields cannot load (JavaScript blocked, an extension in the way), checkout falls back to the previous pay-page flow, which needs no JavaScript.
* Changing the cart total mid-checkout (shipping, a coupon) rebuilds the payment session, so the amount charged always matches the order.

= 0.2.14 =
* All three integration modes are back and each now does its own thing: Inline payment element hands the form to the VezmoPay SDK (auto-sizing and payment events), Secure iframe embeds the same page and confirms by polling, and Hosted checkout redirects to the VezmoPay paylink page.
* Each mode also copes on its own when it cannot run as chosen: inline drops to the embedded iframe if the SDK is unavailable, and both embedded modes send the shopper to the VezmoPay secure page — never an empty frame — until your store is registered as a VezmoPay trusted origin. The mode the shopper actually got is recorded on the order.

= 0.2.13 =
* Integration mode is now two choices instead of three. "Inline payment element" and "Secure iframe" opened the same VezmoPay page and differed only in what drove the frame, which the plugin now decides for itself — they are one "Embedded on your store" option. Stores set to either keep working with no change.
* "Payments secured by VezmoPay" now sits below the Pay button, where it reassures at the moment of paying, instead of above it inside the form.

= 0.2.12 =
* Fixed the Pay button appearing unstyled on some stores. The plugin's stylesheet was cached against the version number alone, so a store that updated between releases kept serving the old CSS. Asset URLs now change whenever the file changes.
* Fixed the embedded payment form rendering its narrow phone layout on desktop when a theme gives the pay page a cramped content column: the form is centred on the screen at a usable width instead, and still fills the column on a phone.
* The embedded form no longer collapses when a theme places it in a flex or grid container.

= 0.2.11 =
* The embedded payment form sizes itself to its content and lays out correctly from phone to desktop, in light and dark checkout themes, with no horizontal scrolling.
* The embedded payment form now has a Pay button. When the VezmoPay form is embedded it hides its own submit button by design and waits for the store to trigger the charge, so inline/iframe mode showed a card form the shopper could not submit. The button carries the order total and hands itself back if a card is declined.
* Inline payment element and Secure iframe modes now really keep the customer on your store's pay page, instead of quietly redirecting to VezmoPay like Hosted mode did. Embedding needs your store to be one of your VezmoPay trusted origins — "Connect with VezmoPay" registers it — and when it is not, shoppers are still sent to the VezmoPay secure page rather than an empty frame, with the reason shown on the settings screen.
* At checkout the VezmoPay mark now appears to the left of the payment method name in the block checkout, matching the classic checkout.

= 0.2.10 =
* The gateway settings screen is branded: the VezmoPay lockup, a tagline, and a chip that states plainly whether you are in Test or Live mode.
* The payment method at checkout now shows a smaller, cleaner VezmoPay icon, aligned with its label.
* Company name updated to Vezmo Technology, Inc. throughout, and plugin-directory icon/banner artwork added.

= 0.2.9 =
* After paying (or a failed/cancelled payment) on the VezmoPay page, the shopper is now returned to your store automatically — to the order-received page on success, or back to a "try again" screen on failure. The order-received page verifies the payment on arrival so it shows as paid immediately.

= 0.2.8 =
* Checkout now sends the shopper straight to the VezmoPay secure page (which renders its own card form and Pay button) instead of embedding it in an iframe. This avoids the frame-ancestors restriction entirely and removes the extra button/click. If the order-pay page is opened directly, it shows a brief branded "Taking you to secure checkout" screen and forwards automatically.

= 0.2.7 =
* The pay page now always shows a working "Pay securely on the VezmoPay page" button. It opens the secure VezmoPay checkout in a new tab via top-level navigation, which is never blocked by the embed frame-ancestors CSP — so customers can always pay even before the store origin is added to trusted origins. The page keeps polling and forwards to the thank-you page once payment is confirmed.

= 0.2.6 =
* Connect with VezmoPay now also registers your store as a VezmoPay trusted origin (required for the embedded payment form to render at all). If it cannot be added automatically, the settings screen shows the exact manual step.

= 0.2.5 =
* Fix: payment creation failed with 400 Bad Request — the VezmoPay API now requires client.postalCode (and country) when customer details are sent. The plugin now sends the full billing address, and omits the customer block entirely if the required fields are missing so a bare checkout can never be blocked.
* API validation errors now surface their field-level details instead of a bare "Bad Request".

= 0.2.4 =
* Store managers now see the real API error inline in the checkout error message when a payment fails to start (the Block checkout only displays error notices, so the separate manager notice was invisible there). Customers still see the generic message.

= 0.2.3 =
* Fix: VezmoPay now appears in the WooCommerce Block checkout. The Blocks payment-method registration listened for woocommerce_blocks_loaded, which fires before the plugin boots — so the client-side method was never registered and the checkout showed "no payment methods available" even though the Store API offered vezmopay. Verified against a live store.

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

== Updates ==

New releases are published on GitHub and appear on your WordPress Plugins screen like any other update — update with one click, or use the Plugins screen's native "Enable auto-updates" toggle for automatic background updates.

Distribution note: the plugin checks the GitHub Releases API. If the source repository is private, set a read-only token via the `VEZMOPAY_GITHUB_TOKEN` constant in wp-config.php (or the `vezmopay_github_token` filter) so update checks can authenticate; a public repository needs no token.
