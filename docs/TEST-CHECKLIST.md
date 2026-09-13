# QA Test Checklist — VezmoPay for WooCommerce

Current for **0.3.6**. Run against WooCommerce 8.0+ on WordPress 6.0+, with a VezmoPay **Test**
API key holding the `secure-payment.create`, `paylink.create`, `paylink.read` and `payment.read`
scopes.

Every case has an id (`T-2.4`) — report failures by id.

| Marker | Meaning |
|---|---|
| **[P]** | Needs the real platform; a local sandbox cannot prove it. |
| **[S]** | Covered by the local WP + mock-API sandbox, so it can be re-run cheaply. |
| **[D1]** | Behaviour exists only because a declined card produces no signal — see the Declined-payment signal row in `VEZMOPAY-API-CONTRACT.md`. Delete these when the platform fixes it. |

Stripe test cards (the embed is a Stripe Payment Element in test mode):

| Card | Purpose |
|---|---|
| `4242 4242 4242 4242` | Successful payment |
| `4000 0027 6000 3184` | 3-D Secure challenge required |
| `4000 0000 0000 0002` | Declined |
| `4000 0000 0000 9995` | Declined — insufficient funds (a second, differently-worded decline) |

Any future expiry, any CVC, any postal code.

---

## 0. Before testing any failure path — two payment surfaces

The payment box on the checkout page and the order-pay page are **different code**:

| Surface | Script | How a shopper gets there |
|---|---|---|
| Checkout payment box | `checkout-inline.js` (+ `blocks.js` on the Blocks checkout) | Normal case: a cart-time payment session exists and `bind_to_order()` accepts it. |
| Order-pay page | `checkout-element.js` or `checkout-iframe.js`, both on `pay-attempt.js` | Whenever the session cannot be bound — token expired while the checkout sat open, cart total changed, JavaScript unavailable — or hosted mode, or an embed VezmoPay will not allow. |

In 0.3.4 the decline work landed only in `checkout-inline.js` and the retest was run on the pay
page, so the shopper saw no improvement at all: the code that was fixed was not the code that ran.

- [ ] **T-0.1** Every failure-path change is exercised on **both** surfaces.
- [ ] **T-0.2** The full grid: 3 cards × {element, iframe} × {checkout box, order-pay page} = 12 runs. They have not always behaved the same.

**Forcing a surface.** Checkout box: normal checkout with JS on. Order-pay page: set
`_vezmopay_token_expires` into the past (or wait out `TOKEN_TTL_MINUTES` = 60), or change the cart
total after the form mounts, or open `/checkout/order-pay/{id}/?key={key}` directly.

---

## 1. Install & activation

- [ ] **T-1.1** Activate with WooCommerce active — no notices or fatals; "VezmoPay" appears under WooCommerce → Settings → Payments.
- [ ] **T-1.2** Activate with WooCommerce **inactive** — admin notice "requires WooCommerce", no fatal, no gateway registered.
- [ ] **T-1.3** "Settings" action link on the Plugins screen opens the gateway section.
- [ ] **T-1.4** Deactivate → reactivate with orders in flight — the five-minute reconciliation cron (`vezmopay_reconcile_pending`) is scheduled exactly once.
- [ ] **T-1.5** Uninstall (delete, not deactivate) → `woocommerce_vezmopay_settings`, every `vezmopay_*` transient, and all `vezmopay_recon_*` / `vezmopay_evt_*` options are gone.

## 2. Settings & connection

- [ ] **T-2.1** Save Test key + secret → both render as password inputs and the saved values never appear in page source.
- [ ] **T-2.2** **Test connection** with valid credentials → green "Connected to VezmoPay (test environment)".
- [ ] **T-2.3** Wrong secret → red "Connection failed: …" with the API's reason; button re-enables.
- [ ] **T-2.4** Empty credentials → "Save your test API key and secret first".
- [ ] **T-2.5** Environment = Test → yellow TEST banner; Live (with live keys) → blue LIVE banner.
- [ ] **T-2.6** `VEZMOPAY_TEST_API_KEY` / `VEZMOPAY_TEST_API_SECRET` in `wp-config.php` with the fields blank → Test connection still succeeds (constants win).
- [ ] **T-2.7** Connect-with-VezmoPay flow → keys **and** the `whsec_…` webhook secret land in the settings.
- [ ] **T-2.8** **[S]** API base / Checkout base rejection: save `https://evil.example`, `http://user.vezmo.com`, `https://user.vezmo.com.evil.example` → each is refused with a visible error naming the security role; a valid `https://user.dev.vezmo.com` saves, trailing slash trimmed.
- [ ] **T-2.9** **[S]** `https://dev.vezmo.com` in the checkout base is self-healed to `https://user.dev.vezmo.com` and then accepted.
- [ ] **T-2.10** **[S]** A base that slipped through from an older version falls back to the shipped default at runtime, with an error in the log — it is never used.
- [ ] **T-2.11** `vezmopay_allowed_api_hosts` filter adds a staging host → that host is then accepted (https only).
- [ ] **T-2.12** Account panel loads the live payment-method toggles and the 3-D Secure switch; a change saves and survives a reload (cached ≤ 5 min).
- [ ] **T-2.13** Account panel with a key lacking `account.read` → the explanatory failure message, not a blank panel.
- [ ] **T-2.14** **[S]** "Hosted checkout override" checkbox is visible **only** when hosted mode is selected and VezmoPay has not confirmed payment-link capability; ticking it makes hosted mode take effect; a save while the field is hidden does **not** silently clear it.

## 3. Gateway availability

- [ ] **T-3.1** Store currency **JPY** (zero-decimal) → VezmoPay disappears from classic and Blocks checkout; the settings page shows the zero-decimal error. Back to USD → it returns.
- [ ] **T-3.2** Gateway disabled → absent from both checkouts.
- [ ] **T-3.3** Test environment → "(Test mode)" badge on the Blocks tile and the TEST MODE badge on the embed.
- [ ] **T-3.4** Order total below 0.01 or above 1,000,000 → the order is refused with "This order total cannot be processed by VezmoPay", no payment created.
- [ ] **T-3.5** Logo renders to the **left** of the title on both checkouts; the icon is `aria-hidden`.

## 4. Checkout payment box — element mode

Integration mode = **Inline payment element**, classic checkout, JS on.

- [ ] **T-4.1** Selecting VezmoPay mounts the form in the payment box; Pay button shows the order total; no redirect.
- [ ] **T-4.2** **[S]** `4242…` → "Processing your payment…" → thank-you page; order Processing/Completed; notes "Payment via VezmoPay" + "VezmoPay payment captured (Transaction ID: …)"; `_vezmopay_payment_id` set; `_vezmopay_effective_mode` = `element-inline`.
- [ ] **T-4.3** **[S]** `4000 0027 6000 3184` → challenge inside the frame; "Completing an extra verification step with your bank…"; **no timeout for at least 90 s**; completing it completes the order.
- [ ] **T-4.4** **[S]** `4000 0000 0000 0002` → the decline appears **under the form**, the form is **not** cleared (card number, expiry, CVC still there), the Pay button returns, the order stays Pending with an attempt note.
- [ ] **T-4.5** **[S]** Immediately after T-4.4, correct the card and press Pay again → the order completes on the **same** `_vezmopay_payment_id`; no second payment is created and no redirect happens.
- [ ] **T-4.6** **[S][D1]** Silent decline (frame reports nothing): after 60 s the shopper is told "VezmoPay did not report a result…", Pay returns, the form and its details are kept, the order stays Pending with the timeout note.
- [ ] **T-4.7** Change the cart total (coupon, shipping) after the form mounts → the mounted form is discarded and a new session mounts for the new amount; the old amount can never be charged.
- [ ] **T-4.8** Two different carts of the same value in one session → two **different** payment ids (no idempotent replay of the first).
- [ ] **T-4.9** Place order with the form still loading → "Please complete your card details before placing the order"; no order created.
- [ ] **T-4.10** Block `js.stripe.com` in the browser → the charge is re-sent up to 8 times over ~20 s, then "The payment form did not finish loading, so your card was not charged", with a link to the VezmoPay page.
- [ ] **T-4.11** Pay in a background tab (switch away during 3-D Secure and return) → the attempt is not timed out by wall-clock time spent hidden.

## 5. Checkout payment box — iframe mode

Integration mode = **Secure iframe**. Repeat T-4.1 – T-4.7 with these differences:

- [ ] **T-5.1** **[S]** The form is a plain iframe (no SDK); it is **sized to its content** — no dead space below the card fields, no inner scrollbar.
- [ ] **T-5.2** **[S]** `4242…`, 3-D Secure and decline all behave exactly as in section 4 (`_vezmopay_effective_mode` = `iframe-inline`).
- [ ] **T-5.3** **[S]** A forged `vezmo:secure-payment:success` posted from the page itself, or from another iframe on the checkout, is **ignored** — the order is not completed.
- [ ] **T-5.4** **[S]** After the frame's first message the trusted origin is pinned; a message from any other origin is dropped (visible in the debug log).

## 6. Blocks checkout

- [ ] **T-6.1** Tile shows logo, title, description and the test badge; the form mounts inside the payment step.
- [ ] **T-6.2** **[S]** `4242…` via the block's own **Place order** button completes the order and lands on the thank-you page.
- [ ] **T-6.3** **[S]** Decline → the reason appears in the Blocks notice area **and** under the form; the form keeps the entered card; retry succeeds in place.
- [ ] **T-6.4** **[S]** After a decline the cart is **not** emptied — "Place order" works again (no "Cannot place an order, your cart is empty").
- [ ] **T-6.5** The plugin's own Pay button and the block's Place order button do the same thing; neither double-submits.
- [ ] **T-6.6** Editor view: the tile renders in the Checkout block editor without errors.
- [ ] **T-6.7** **[S]** On the order-pay and order-received pages, `checkout-inline.js` is **not** loaded (only the pay-page driver) and `vezmopay_inline_params` is undefined there.

## 7. Order-pay page — element mode

Reach it as described in section 0.

- [ ] **T-7.1** **[S]** Form mounts, TEST badge visible, Pay button shows the order total.
- [ ] **T-7.2** **[S]** `4242…` → thank-you page; order paid; `_vezmopay_effective_mode` = `element`.
- [ ] **T-7.3** **[S]** Decline → the reason appears under the form with "You can correct your card details and try again"; the form is **not** replaced; Pay returns; order note recorded.
- [ ] **T-7.4** **[S][D1]** Silent decline → bounded at 60 s with the same treatment.
- [ ] **T-7.5** **[S]** 3-D Secure → "Completing an extra verification step…", held past 90 s, no timeout, no failure note. (`requires_action` does not travel through vezmo.js — this proves the direct listener.)
- [ ] **T-7.6** **[S]** Reload the pay page before paying → **no** new payment is minted (same `_vezmopay_payment_id`, token reused).
- [ ] **T-7.7** **[S]** Reload after the token expired → a fresh payment is minted (new id, `_vezmopay_attempt` advanced) and pays normally.
- [ ] **T-7.8** SDK `expired` mid-session → the expiry message, then an automatic reload into a fresh payment.
- [ ] **T-7.9** Block `vezmo.js` from loading (ad-blocker or DevTools) → the page degrades to a plain iframe and still pays, declines and times out correctly.
- [ ] **T-7.10** **[S]** Arrive with `?vezmopay_retry=1` → "Your payment was not completed" and a **new** payment offered, not the failed one.

## 8. Order-pay page — iframe mode

- [ ] **T-8.1** **[S]** Repeat T-7.1 – T-7.7 in iframe mode; `_vezmopay_effective_mode` = `iframe`.
- [ ] **T-8.2** **[S]** The iframe is sized to its content via the frame's resize messages.
- [ ] **T-8.3** With browser JS **disabled** → the iframe still renders server-side, the noscript notice shows, and a payment made inside it completes the order by webhook/cron.

## 9. Hosted mode

- [ ] **T-9.1** **[P]** With an account VezmoPay confirms can take payment links → order redirects to `…/checkout/payments-links/{code}`; order Pending with the paylink note; stock reduced; cart emptied.
- [ ] **T-9.2** **[P]** Paying on the hosted page completes the order via webhook/cron. The customer is **not** redirected back — a known platform gap — and still receives the WooCommerce email.
- [ ] **T-9.3** **[S]** With capability **unconfirmed**, hosted mode downgrades to the embedded form rather than sending the shopper to a page that cannot take money — unless the override (T-2.14) or `vezmopay_force_hosted_mode` is set.
- [ ] **T-9.4** Retry the same order → the **same** paylink code is reused; no duplicate paylink.
- [ ] **T-9.5** Order total changes after the paylink was created → a new paylink is issued for the new amount; the stale one can never pay the order in full.
- [ ] **T-9.6** Hosted mode renders no payment fields on either checkout (no spinner, no empty container).

## 10. Retry, recovery and the cart

- [ ] **T-10.1** **[S]** A declined card never clears the form on either surface (T-4.4, T-7.3).
- [ ] **T-10.2** **[S]** The form **is** replaced, with "Please re-enter your card details below", only when its payment cannot be charged again: the API reports FAILED, the token expired, or the form never loaded.
- [ ] **T-10.3** **[S]** A retry charges the payment already on the order — verify `_vezmopay_payment_id` is unchanged and no extra payment exists at VezmoPay.
- [ ] **T-10.4** **[S]** A captured payment is never offered for a second charge: after success, pressing Pay again (or reloading the pay page) forwards to the thank-you page instead.
- [ ] **T-10.5** **[S]** The cart survives an on-page charge and is emptied the moment the payment settles; it is also empty after the thank-you page loads.
- [ ] **T-10.6** Abandon a payment entirely and return to the checkout later → the cart is intact and a fresh session mounts.
- [ ] **T-10.7** **[S]** Session rate limit: more than 10 session requests in 10 minutes from one visitor → 429 with "Too many payment attempts", nothing minted.

## 11. Attempt bounds and timers

- [ ] **T-11.1** **[S][D1]** Checkout box: no outcome 60 s after Pay → failed attempt (message, Pay returned, note), not a spinner.
- [ ] **T-11.2** **[S][D1]** Order-pay page: same, and the 15-minute poll ceiling still exists behind it.
- [ ] **T-11.3** **[S]** A 3-D Secure challenge suspends the bound on **both** surfaces (T-4.3, T-7.5).
- [ ] **T-11.4** **[S]** After a failed attempt no timer survives: the "taking longer than usual" stall message must never appear afterwards, and the status poll must stop (watch the network tab).
- [ ] **T-11.5** **[S]** The stall offer ("Continue on the VezmoPay page") appears **only** when the form never acknowledged the card — never while a live payment intent is held.
- [ ] **T-11.6** **[S]** A payment that settles while the browser is giving up forwards the shopper to the thank-you page instead of showing a failure.

## 12. What the merchant sees on the order

- [ ] **T-12.1** **[S]** Every failed attempt leaves a note naming the payment id, the reason and the last status VezmoPay reported — a declined card is never indistinguishable from an abandoned cart.
- [ ] **T-12.2** **[S]** The note never contains raw provider error text, and never claims money did not move where the plugin cannot know it.
- [ ] **T-12.3** Repeated reports of the same attempt add **one** note, not one per poll.
- [ ] **T-12.4** `_vezmopay_environment`, `_vezmopay_mode`, `_vezmopay_effective_mode`, `_vezmopay_payment_id` and the transaction id are all readable on the order screen.
- [ ] **T-12.5** Order screen action "Check VezmoPay payment status" re-reads the API and notes the result.

## 13. Thank-you / order-received page

- [ ] **T-13.1** **[S]** An order still Pending when the page loads is verified against the API **before** the page renders, and shows as paid if it is.
- [ ] **T-13.2** **[S]** An order left **Failed** by an earlier decline but since captured shows the **normal** success page — not WooCommerce's "your order cannot be processed as the originating bank/merchant has declined your transaction".
- [ ] **T-13.3** **[S]** An order that genuinely failed still shows that message — the check is not blanket-suppressed.
- [ ] **T-13.4** An unsettled order shows "Your VezmoPay payment is being confirmed…".
- [ ] **T-13.5** Opening the thank-you URL with a wrong `key` reveals nothing and triggers no reconcile.
- [ ] **T-13.6** Works on both the classic thank-you template and the Blocks order-confirmation page.

## 14. Reconciliation

- [ ] **T-14.1** **[S]** Confirm endpoint: success event → order completed once, with one email and one stock reduction.
- [ ] **T-14.2** **[S]** Status poll settles an order when no postMessage ever arrives (store origin not in VezmoPay's trusted origins) — element and iframe.
- [ ] **T-14.3** **[S]** Concurrency: webhook + poll + cron + thank-you page hitting one order at once → exactly one completion (`LOCKED` in the log for the losers, no duplicate notes/emails/stock).
- [ ] **T-14.4** Cron (`vezmopay_reconcile_pending`, 5 min) settles an order whose browser was closed, oldest-unchecked first.
- [ ] **T-14.5** **[S]** Amount/currency mismatch → order goes **On hold** with the mismatch note and is never completed. Test all four: wrong amount, wrong currency, missing amount, missing currency.
- [ ] **T-14.6** **[S]** A response the store cannot read (no amount) is treated as a mismatch, not a pass.

## 15. Webhooks

Endpoint: `https://{store}/wp-json/vezmopay/v1/webhook`, events `payment.success` and `payment.failed`.

- [ ] **T-15.1** **[P]** Register in the dashboard, paste the `whsec_…` secret, pay with the tab closed immediately → the webhook alone completes the order.
- [ ] **T-15.2** Replay the same delivery (same envelope `id`) → 200 `duplicate: true`; no second note, email or stock move.
- [ ] **T-15.3** **[S]** With a secret saved, a **wrong** signature → 401; a **missing** signature → 401.
- [ ] **T-15.4** **[S]** With **no** secret saved, a signed delivery is processed (it cannot be verified, and refusing it only broke reconciliation) and the log carries the error telling the merchant to paste the secret.
- [ ] **T-15.5** **[S]** A forged `payment.success` naming a real but **unpaid** payment → the order is **not** completed; the API re-read decides.
- [ ] **T-15.6** Unknown payment id → 200, `handled: false`, no order touched.
- [ ] **T-15.7** Malformed JSON → 400.
- [ ] **T-15.8** `payment.failed` on a pending order → status Failed with the reported-as-failed note.
- [ ] **T-15.9** Flood the endpoint → throttled (429) rather than amplifying into API calls.
- [ ] **T-15.10** **[P]** `X-Webhook-Timestamp` is present on live deliveries (recorded in the API contract; the plugin does not yet use it).

## 16. Security

- [ ] **T-16.1** **[S]** Session endpoint rejects a bad/absent nonce outright.
- [ ] **T-16.2** **[S]** Confirm/status/failed endpoints require a matching **order key**; a wrong key returns "Invalid order" for someone else's order id.
- [ ] **T-16.3** **[S]** A stale nonce on confirm/status does not lose a payment (it proceeds on the order key and logs), but a rejected nonce mid-poll stops the poll and says so rather than looping.
- [ ] **T-16.4** **[S]** Forged postMessage results are ignored on every surface (T-5.3).
- [ ] **T-16.5** **[S]** The failure-report endpoint cannot mark an order failed on the browser's word alone — it re-reads the API, and a settled payment forwards instead.
- [ ] **T-16.6** **[S]** Free-text reasons are rejected: only the allow-listed reason codes reach an order note.
- [ ] **T-16.7** Admin-only AJAX (account panel, test connection) refuses a user without `manage_woocommerce`.
- [ ] **T-16.8** **[S]** Debug log: no `vzm_` key, API secret, `whsec_` value, Bearer token or clientToken ever appears — only `[redacted]`.
- [ ] **T-16.9** **[S]** An API error is logged even with debug off, still redacted.
- [ ] **T-16.10** Card data never reaches the store: no card fields in any request the store makes (check the network tab and the log).

## 17. Updates & release integrity

- [ ] **T-17.1** **[S]** A store on the previous version is offered the new one on the Plugins screen, from `github.com`.
- [ ] **T-17.2** **[S]** The package digest must match `vezmopay-sha256` in the release body; a tampered zip is refused with "did not match the checksum".
- [ ] **T-17.3** **[S]** A package URL on any other host — including `github.com.evil.example` — is refused, as is plain http.
- [ ] **T-17.4** Update in place → settings, keys and in-flight orders survive; the version shows the new number everywhere (header, readme, README badge, `.pot`).
- [ ] **T-17.5** WordPress's own per-plugin auto-update toggle is available for VezmoPay.

## 18. ACH / pending payments

- [ ] **T-18.1** **[P]** USD store, element mode, **US bank account** in the element → `pending`; "Your bank payment is processing…"; order **On hold** with the settlement note and the transaction id.
- [ ] **T-18.2** **[P]** Unverified bank (micro-deposits) → the "submitted, verify by email" state; order stays On hold; no failure note.
- [ ] **T-18.3** **[P]** After settlement, `payment.success` moves the order to paid exactly once.

## 19. Refunds

- [ ] **T-19.1** Refund from the WooCommerce order screen → refused with the explanation that VezmoPay exposes no refund API; order totals unchanged.
- [ ] **T-19.2** **[P]** Refund in the VezmoPay/Stripe dashboard, then reconcile → order moves to **Refunded** with the note.

## 20. Compatibility

- [ ] **T-20.1** HPOS enabled: one full payment, one webhook, one reconcile — all work; meta readable; no compatibility warning on the Features screen.
- [ ] **T-20.2** Classic (shortcode) and Blocks checkout pages both pass sections 4–6.
- [ ] **T-20.3** A theme with a narrow content column → the payment card breaks out and centres on the viewport instead of rendering the phone layout on a desktop; the page never scrolls sideways.
- [ ] **T-20.4** Real mobile viewport (375 px) → the form, Pay button and messages are usable and unclipped.
- [ ] **T-20.5** Screen reader: the embedded frame has a title; the message area announces changes politely.
- [ ] **T-20.6** Another gateway (Stripe, PayPal) active alongside → switching payment methods on the checkout mounts/unmounts cleanly and neither gateway's scripts error.
- [ ] **T-20.7** Cached checkout page (page cache or a stale asset) → versioned assets mean the shopper never gets a mismatched script/style pair.

## 21. Localization

- [ ] **T-21.1** `languages/vezmopay-woocommerce.pot` regenerates cleanly and contains every customer-facing string added this release.
- [ ] **T-21.2** With a translation installed, checkout messages, order notes and settings labels are translated; no raw HTML entities leak into the Pay button label.

---

## 22. Regression grid — run before every release

| | `4242…` | 3-D Secure | Declined | Silent (no signal) **[D1]** |
|---|---|---|---|---|
| Checkout box · element | T-4.2 | T-4.3 | T-4.4 / T-4.5 | T-4.6 |
| Checkout box · iframe | T-5.2 | T-5.2 | T-5.2 | T-5.2 |
| Order-pay · element | T-7.2 | T-7.5 | T-7.3 | T-7.4 |
| Order-pay · iframe | T-8.1 | T-8.1 | T-8.1 | T-8.1 |

Plus, every release: T-13.2 (paid order must not show the declined message), T-14.3 (no double
completion), T-15.3 – T-15.5 (webhook authenticity), T-17.1 – T-17.3 (update integrity).
