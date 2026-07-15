# VezmoPay for WooCommerce — Developer Guide

Architecture reference for contributors. The provider-side facts (endpoints, DTOs, gaps) live in
[`docs/VEZMOPAY-API-CONTRACT.md`](docs/VEZMOPAY-API-CONTRACT.md) — read that first; nothing in this
plugin assumes a capability that isn't verified there.

## Class map

| File | Class | Responsibility |
|---|---|---|
| `vezmopay-woocommerce.php` | — | Plugin header, PSR-ish autoloader for `VezmoPay\WooCommerce\*`, HPOS + Blocks compatibility declarations, boots `Plugin` on `plugins_loaded` (priority 11). |
| `includes/class-vezmopay-plugin.php` | `Plugin` | Singleton orchestrator. Registers the gateway, the REST webhook route, Blocks support, the `wc_ajax_vezmopay_confirm` / `wc_ajax_vezmopay_status` checkout AJAX endpoints (guest-safe: order key must match), the admin test-connection AJAX action, and the Settings action link. |
| `includes/class-vezmopay-gateway.php` | `Gateway` | `WC_Payment_Gateway` implementation. Mode selection, credential resolution (constants > options), availability guards, `process_payment()`, the pay-page renderer (`receipt_page`), reconciliation (`reconcile_order_with_api`, `apply_payment_state`, `mark_order_paid`), and the explanatory `process_refund()` stub. |
| `includes/class-vezmopay-api-client.php` | `Api_Client` | Thin HTTP client over `wp_remote_request`. Login, token caching, 401 retry, envelope unwrapping, and the four endpoint helpers (`create_secure_payment`, `create_paylink`, `get_paylink`, `get_payment`, plus `list_payments`). |
| `includes/class-vezmopay-settings.php` | `Settings` | Static definition of the gateway form fields, default host constants, and the `ZERO_DECIMAL_CURRENCIES` list. |
| `includes/class-vezmopay-webhook.php` | `Webhook` | REST controller for `POST /wp-json/vezmopay/v1/webhook`. Signature check (when sent), order lookup by stored meta, event-id dedupe, and delegation to `Gateway::reconcile_order_with_api()`. |
| `includes/class-vezmopay-connect.php` | `Connect` | Manual-key onboarding: the "Test connection" AJAX handler (performs the real login exchange). Placeholder home for an OAuth handshake if the platform ever ships one. |
| `includes/class-vezmopay-logger.php` | `Logger` | `WC_Logger` wrapper. Debug lines only when enabled; errors always. Redacts key/secret/token fields in context arrays and `vzm_` / `whsec_` / `Bearer` material in free-form strings. |
| `includes/blocks/class-vezmopay-blocks-support.php` | `Blocks_Support` | `AbstractPaymentMethodType` for the Checkout Block. Informational tile only — all modes finalize after a server-side redirect. |
| `assets/js/checkout-element.js` | — | Element mode: mounts vezmo.js, listens for SDK events, AJAX-confirms, and runs a parallel status poll as fallback. Degrades to a raw iframe if the SDK fails to load. |
| `assets/js/checkout-iframe.js` | — | Iframe mode: status polling only (the iframe itself is rendered server-side). |
| `assets/js/blocks.js` | — | Registers the Blocks payment method (title, description, test-mode badge). |
| `uninstall.php` | — | Deletes the settings option and cached token transients. Order meta is preserved as audit trail. |

## Authentication flow

There is one credential pair per environment: a `vzm_…` API key and a secret (no publishable key
exists on this platform).

1. `Api_Client::login()` → `POST /api/v1/merchant/api-auth/login` with `x-api-key` / `x-api-secret`
   headers. The response carries a JWT access token with a **30-minute TTL**.
2. The token is cached in a transient for **25 minutes** (`TOKEN_TTL`), keyed by
   `vezmopay_token_{env}_{sha256(key|secret|base)[:20]}` so different environments/credentials
   never collide.
3. Every `/merchant/*` call sends `Authorization: Bearer <token>`. On a `401`, the client deletes
   the cached token, performs one forced re-login, and retries the request once.
4. Idempotent `GET`s additionally get one retry on pure transport failure.
5. Credentials resolve constants-first: `VEZMOPAY_{TEST|LIVE}_API_{KEY|SECRET}` in `wp-config.php`
   override the saved options (see "Secrets via wp-config" below).

The refresh-token endpoint exists on the platform but is unused — with a 25-minute cache against a
30-minute token, a fresh login is simpler and always valid.

## The three payment flows

Mode is a gateway setting (`integration_mode`: `element` | `iframe` | `hosted`). All three start in
`Gateway::process_payment()`, which validates the amount (0.01–1,000,000, provider limit) and stamps
`_vezmopay_environment` / `_vezmopay_mode` on the order so later reconciliation uses the same
environment the payment was created in.

### 1. Element (default)

```
checkout submit
  → process_payment()
      → ensure_secure_payment(): POST /merchant/secure-payments
        (Idempotency-Key: wc-{order_key}-a{attempt}; stores payment id, clientToken,
         iframe/sdk URLs, expiry on the order)
      → redirect to the order-pay page (receipt_page)
  → receipt_page enqueues vezmo.js (provider SDK) + checkout-element.js
  → new Vezmo().mount(container, { clientToken })
  → customer pays inside the VezmoPay-hosted Stripe Payment Element (3DS handled there)
  → SDK postMessage event: success | pending | already-paid
  → checkout-element.js → wc-ajax vezmopay_confirm (nonce + order id + order key)
  → Plugin::ajax_confirm() → Gateway::reconcile_order_with_api()
      → GET /merchant/payment/{id}  ← API re-verification, never trusts the browser
      → apply_payment_state(): CAPTURED → payment_complete()
  → JS redirects to the thank-you page
```

Fallback: postMessage events only reach origins on the merchant's **trusted origins** list. A
status poll (`wc_ajax vezmopay_status`, every 4 s) runs in parallel from the start, so payment
completes even when events never arrive or the SDK fails to load (the script then swaps in a raw
iframe).

### 2. Iframe

Same server-side secure payment as element mode, but `receipt_page` prints a plain
`<iframe src="{securePayment.url}">` directly in the markup — no SDK, no dependency on our JS.
`checkout-iframe.js` polls `vezmopay_status` and forwards the customer when the API reports
`CAPTURED` (paid) or `AUTHORIZED` (ACH pending → on-hold). If JS is dead entirely, the webhook
completes the order and the customer is told to expect the confirmation email (noscript notice).

### 3. Hosted (paylink)

```
process_payment()
  → POST /merchant/paylinks (title, amount, currency, description)
  → store _vezmopay_paylink_code / _vezmopay_paylink_id, note the order,
    set status pending, reduce stock, empty cart
  → redirect to {checkout_base}/checkout/payments-links/{shortCode}
  → customer pays on the VezmoPay page — the platform has NO return-URL support,
    so the customer is NOT redirected back (platform gap, flagged in the contract doc)
  → webhook payment.success arrives → find order by paylink/payment id
    → reconcile: before a payment id is known, GET /merchant/paylinks/{code}
      (status PAID → payment_complete); afterwards GET /merchant/payment/{id}
```

The paylink is created once and reused on re-attempts (`_vezmopay_paylink_code` check), so a
customer clicking "Place order" twice pays the same link.

## Webhook trust model

Endpoint: `POST /wp-json/vezmopay/v1/webhook` (`permission_callback` is `__return_true` by design —
VezmoPay has no WP credentials; authenticity comes from the steps below).

The platform's HMAC signing is **currently disabled server-side**, so the receiver is built to be
safe with fully unsigned, attacker-forgeable payloads:

1. **Signature when present.** If `X-Webhook-Signature` is sent, it must be a valid
   HMAC-SHA256 hex of the raw body under the saved `whsec_` secret (`hash_equals` comparison);
   a signature with no configured secret, or a mismatch, is rejected with 401. Absent signature →
   logged and allowed through to step 2.
2. **Payload is only a hint.** The event is matched to an order strictly via references *this
   plugin stored* (`_vezmopay_payment_id`, `_vezmopay_paylink_id`). Unmatched events are
   acknowledged with 200 (so the platform stops retrying) and ignored.
3. **Dedupe.** The envelope `id` (`evt_…`) is checked against `_vezmopay_processed_events`
   (last 25 kept) — replays return 200 without touching the order. Needed because the platform
   retries 4× over 24 h with no ordering guarantee.
4. **Independent API re-verification.** The order is only ever updated from
   `GET /merchant/payment/{id}` (or the paylink lookup), fetched with the merchant's own
   credentials. Nothing from the webhook body reaches the order state machine.
5. **Amount guard.** In `apply_payment_state()`, if the provider-reported amount differs from the
   order total by more than 0.01, the order goes on-hold with an explanatory note instead of
   completing (`MISMATCH`).
6. A reconciliation failure returns 500 so the platform retries later.

`mark_order_paid()` is the single completion point: it no-ops on already-paid orders and stores the
VezmoPay payment id as the WooCommerce transaction id via `payment_complete( $payment_id )`.

## Order meta keys

All keys are HPOS-safe (written through `WC_Order` meta APIs; lookups use `wc_get_orders`).

| Key | Set by | Meaning |
|---|---|---|
| `_vezmopay_payment_id` | element/iframe creation; webhook (paylink, post-verification) | VezmoPay payment id — the reconciliation handle and transaction id. |
| `_vezmopay_client_token` | `ensure_secure_payment()` | JWT for mounting the payment element / building the iframe URL. |
| `_vezmopay_iframe_url` | `ensure_secure_payment()` | Hosted payment-page URL used as iframe `src`. |
| `_vezmopay_sdk_url` | `ensure_secure_payment()` | vezmo.js URL for the active environment. |
| `_vezmopay_token_expires` | `ensure_secure_payment()` | Unix expiry of the client token; live tokens are reused, expired ones re-minted on pay-page load. |
| `_vezmopay_attempt` | `ensure_secure_payment()` | Attempt counter feeding the Idempotency-Key (`wc-{order_key}-a{n}`); bumped on 409/422 (terminal previous attempt or changed body, e.g. edited cart total). |
| `_vezmopay_paylink_code` | `process_payment_hosted()` | Paylink short code — hosted checkout URL segment and status-lookup key. |
| `_vezmopay_paylink_id` | `process_payment_hosted()` | Paylink id — webhook order matching for hosted mode. |
| `_vezmopay_environment` | `process_payment()` | `test` \| `live` at payment time; reconciliation always uses this, not the current setting. |
| `_vezmopay_mode` | `process_payment()` | Integration mode used for this order. |
| `_vezmopay_processed_events` | webhook handler | Rolling list (last 25) of processed webhook envelope ids for dedupe. |

Uninstall intentionally preserves all `_vezmopay_*` order meta as financial audit trail.

## Adding a new integration mode / payment method

1. Add the mode key to the `integration_mode` options in `Settings::form_fields()` and to the
   whitelist in `Gateway::integration_mode()`.
2. Branch in `Gateway::process_payment()`. If the mode needs a new provider resource, add the
   endpoint helper to `Api_Client` (keep it a thin wrapper over `request()` so auth/retry/logging
   come free) and document the endpoint in `docs/VEZMOPAY-API-CONTRACT.md` — verified against the
   platform source, per that file's policy.
3. Render in `receipt_page()` (or redirect straight out for hosted-style flows). New meta keys use
   the `_vezmopay_` prefix; add them to the table above.
4. Make the state land in `reconcile_order_with_api()` / `apply_payment_state()` — every mode must
   be reconcilable from an API read alone, because that is all the webhook path uses.
5. If webhook matching needs a new reference, extend `Webhook::find_order()` with the stored meta
   key. Never match on customer-supplied or payload-only values.

## Secrets via wp-config constants

`Gateway::credential()` prefers constants over saved options, so keys can be kept out of the
database (and out of DB dumps/backups):

```php
define( 'VEZMOPAY_TEST_API_KEY', 'vzm_…' );
define( 'VEZMOPAY_TEST_API_SECRET', '…' );
define( 'VEZMOPAY_LIVE_API_KEY', 'vzm_…' );
define( 'VEZMOPAY_LIVE_API_SECRET', '…' );
```

When a constant is defined and non-empty, the corresponding settings field is ignored. The webhook
secret currently has no constant override.

## Coding standards

* WordPress Coding Standards (WPCS) with the WooCommerce sniffs; existing `phpcs:ignore` comments
  carry a justification — keep that habit.
* Lint before committing: `php -l` on every touched PHP file.
* Run the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin before tagging a
  release (readme, i18n, escaping, and enqueue checks).
* All user-facing strings use text domain `vezmopay-woocommerce`; regenerate
  `languages/vezmopay-woocommerce.pot` after string changes (`wp i18n make-pot . languages/vezmopay-woocommerce.pot`).
* Escaping on output (`esc_html__`, `esc_url`, `esc_attr`) everywhere; nonces + order-key checks on
  every AJAX entry point; `hash_equals` for all secret comparisons.
