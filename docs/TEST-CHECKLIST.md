# QA Test Checklist — VezmoPay for WooCommerce 0.1.0

Run against a store with WooCommerce 8.0+ on WordPress 6.0+. Unless a step says otherwise, use the
**Test** environment with a VezmoPay Test API key that has the `secure-payment.create`,
`paylink.create`, `paylink.read` and `payment.read` scopes.

The embedded payment form is a Stripe Payment Element in test mode, so Stripe test cards apply:

| Card | Purpose |
|---|---|
| `4242 4242 4242 4242` | Successful payment |
| `4000 0027 6000 3184` | 3-D Secure challenge required |
| `4000 0000 0000 0002` | Declined |

Any future expiry, any CVC, any postal code.

## 1. Settings & connection

- [ ] Fresh install: activate the plugin with WooCommerce active — no notices/fatals; "VezmoPay" appears under WooCommerce → Settings → Payments.
- [ ] Deactivate WooCommerce briefly — admin notice "requires WooCommerce" appears, no fatal.
- [ ] Save Test API key + secret; fields render as password inputs and do not echo the saved values into page source.
- [ ] Click **Test connection** with valid Test credentials → green "Connected to VezmoPay (test environment)" message.
- [ ] Click **Test connection** with a wrong secret → red "Connection failed: …" message with the API's reason; button re-enables.
- [ ] Click **Test connection** with empty credentials → "Save your test API key and secret first" message.
- [ ] Environment = Test → settings page shows the yellow **TEST mode** banner; switch to Live (with live keys) → blue LIVE banner.
- [ ] Define `VEZMOPAY_TEST_API_KEY` / `VEZMOPAY_TEST_API_SECRET` in `wp-config.php` with blank settings fields → Test connection still succeeds (constants win).
- [ ] "Settings" action link on the Plugins screen goes to the gateway settings section.

## 2. Element mode — classic checkout

- [ ] Set Integration mode = **Inline payment element**. Place an order on the classic (shortcode) checkout → redirected to the order-pay page; VezmoPay form mounts; TEST MODE badge visible.
- [ ] Pay with `4242…` → "Processing your payment…" then redirect to the thank-you page; order is Processing/Completed; order note "VezmoPay payment captured" with Transaction ID; `_vezmopay_payment_id` set.
- [ ] Pay with `4000 0027 6000 3184` → 3DS challenge appears **inside** the VezmoPay frame; complete it → order completes as above.
- [ ] Pay with `4000 0000 0000 0002` → decline message shown inside/under the element; order stays Pending; retrying with `4242…` on the same page succeeds.
- [ ] Refresh the pay page before paying → no new VezmoPay payment is created (same `_vezmopay_payment_id`, token reused).

## 3. Element mode — Blocks checkout

- [ ] With the Cart & Checkout **Blocks** pages active, VezmoPay tile appears with title, description and "(Test mode)" badge.
- [ ] Place order via the Blocks checkout → same pay-page flow as classic; payment with `4242…` completes the order.

## 4. Iframe mode — classic and Blocks checkout

- [ ] Set Integration mode = **Secure iframe**. Classic checkout → pay page shows a plain iframe of the VezmoPay payment page.
- [ ] Pay with `4242…` inside the iframe → within ~1–2 poll intervals (4 s) the page redirects to thank-you; order paid, transaction id set.
- [ ] Repeat once via the Blocks checkout.
- [ ] With browser JS disabled: iframe still renders (server-side), noscript notice shown; pay inside the iframe → order is completed by the webhook; customer gets the order email.

## 5. Hosted mode — classic and Blocks checkout

- [ ] Set Integration mode = **Hosted checkout**. Place order → redirected to the VezmoPay paylink page (`…/checkout/payments-links/{code}`); order is Pending with the "paylink created" note; stock reduced; cart emptied.
- [ ] Pay on the hosted page → order flips to paid via webhook (or polling reconciliation). Confirm the customer is **not** redirected back — expected platform limitation — and receives the WooCommerce confirmation email.
- [ ] Click "Place order" again for the same order (order-pay retry) → same paylink code reused, no duplicate paylink.
- [ ] Repeat one hosted payment starting from the Blocks checkout.

## 6. ACH / pending flow

- [ ] USD store, element mode: choose **US bank account** in the payment element and use Stripe's test bank flow → SDK reports `pending`; message "Your bank payment is processing…"; order goes **On hold** with the "authorized / bank settlement pending" note and the transaction id set.
- [ ] After Stripe test-settles the ACH payment, deliver/replay `payment.success` (or wait for it) → order moves to paid exactly once.

## 7. Webhooks

- [ ] Register the endpoint in the VezmoPay dashboard: `https://{store}/wp-json/vezmopay/v1/webhook`, events `payment.success` + `payment.failed`; paste the `whsec_` secret into the plugin settings.
- [ ] Pay an order (element mode) with the pay-page tab **closed immediately after paying** → webhook alone completes the order.
- [ ] **Replay** the same webhook delivery (same envelope `id`) → response is 200 with `duplicate: true`; order unchanged; no second "payment captured" note or email.
- [ ] **Tampered payload**: POST a hand-crafted `payment.success` with a real `_vezmopay_payment_id` of an *unpaid* payment → order is NOT completed (API re-verification finds status ≠ CAPTURED); an INITIATED/unchanged result is returned.
- [ ] Forged payload with an unknown payment id → 200, `handled: false`, no order touched.
- [ ] Malformed JSON body → 400.
- [ ] With a webhook secret saved, send a request with a **wrong** `X-Webhook-Signature` → 401 rejected. Without the header (platform default today) → processed normally.
- [ ] Amount-mismatch guard: simulate a payment record whose amount differs from the order total → order goes **On hold** with the mismatch note, not completed.
- [ ] `payment.failed` for a pending order → order status Failed with the "reported as failed" note.

## 8. Polling fallback (trusted origins NOT configured)

- [ ] Remove/omit the store origin from VezmoPay's trusted origins list. Element mode, pay with `4242…` → no postMessage event arrives, but the parallel status poll completes the order and redirects within a few poll intervals.

## 9. Token expiry on the pay page

- [ ] Create an element/iframe order, then let the secure-payment token expire (or set `_vezmopay_token_expires` to the past). Reload the pay page → a fresh secure payment is minted transparently (new `_vezmopay_payment_id`, attempt counter advanced on 409/422) and payment succeeds.
- [ ] SDK `expired` event mid-session → "session expired, reloading…" message and automatic page reload into a fresh session.

## 10. Refunds

- [ ] On a paid order, attempt a refund from the WooCommerce order screen → refund fails with the explanatory message "VezmoPay does not currently provide a refund API…"; order totals unchanged.
- [ ] Refund the payment in the VezmoPay/Stripe dashboard, then trigger reconciliation (e.g. status poll or replayed webhook) → order moves to **Refunded** with the "refund performed on the VezmoPay side" note.

## 11. Currency guard

- [ ] Switch the store currency to **JPY** → VezmoPay disappears from checkout (classic AND Blocks) and the settings page shows the zero-decimal currency error notice.
- [ ] Switch back to USD → gateway reappears.

## 12. HPOS run

- [ ] Enable High-Performance Order Storage (WooCommerce → Settings → Advanced → Features), rerun one full element-mode payment and one webhook delivery → order completes, meta (`_vezmopay_*`) readable on the order screen, webhook order lookup works, no compatibility warning on the Features screen.

## 13. Debug log redaction

- [ ] Enable Debug logging, run a full payment, then inspect WooCommerce → Status → Logs (source `vezmopay`): request/webhook activity is present, but **no** `vzm_` key value, API secret, `whsec_` value, Bearer token, or clientToken appears anywhere — only `[redacted]` placeholders.
- [ ] Trigger an API error (bad secret) → error is logged even with debug off, still redacted.
