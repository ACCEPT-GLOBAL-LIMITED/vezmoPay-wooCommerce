# Feature mapping: Stripe-for-WooCommerce → VezmoPay for WooCommerce

Parity map against the canonical Stripe WooCommerce gateway feature set. Provider capability claims
are verified against [`VEZMOPAY-API-CONTRACT.md`](VEZMOPAY-API-CONTRACT.md) (extracted from the
platform source) — "N/A — provider lacks it" means the VezmoPay platform has no API for it today,
not that the plugin skipped it.

| Stripe-for-WooCommerce feature | VezmoPay implementation | Status |
|---|---|---|
| Hosted checkout redirect (Stripe Checkout) | `hosted` mode: creates a paylink, redirects to `{checkout_host}/checkout/payments-links/{shortCode}`; order completed by webhook + polling reconciliation. | Done (with caveat) — the platform has no return/cancel URLs, so the customer is not redirected back to the store (contract doc: "No success/cancel/return URL support"). |
| Inline element (Payment Element on checkout) | `element` mode: vezmo.js SDK mounts the VezmoPay-hosted Payment Element on the pay page; success via postMessage + AJAX confirm with API re-verification. | Done (with caveat) — renders on the order-pay page after checkout submit (no client token exists before the order), and events require the store origin in VezmoPay's trusted origins (polling fallback covers the gap). |
| Iframe / hosted fields | `iframe` mode: server-rendered iframe of the secure-payment page, status polling; works with zero client JS. | Done |
| Manual API keys | Per-environment key + secret fields (password inputs), or `VEZMOPAY_{TEST\|LIVE}_API_{KEY\|SECRET}` wp-config constants. | Done |
| Connector / OAuth onboarding | "Connect with VezmoPay" button: dashboard consent page mints a scoped API key, one-time token exchanged server-to-server (`Connect` class + `/integrations/woocommerce/*` platform endpoints). | Done (requires platform branch `fix/seed-secure-payment-permission` deployed). |
| Test/Live toggle | `environment` setting with separate credentials + base URLs per env, admin banner, checkout test badge; orders remember their environment for reconciliation. | Done (with caveat) — Test vs Live is a per-key metadata flag on the platform, not separate hosts, and is not enforced server-side (contract doc, "Hosts"). |
| Publishable/secret key split | Single key + secret pair; both stored as password fields, never exposed client-side (the browser only ever sees the per-order `clientToken`). | N/A — provider lacks it (contract doc: "there is no publishable key"). |
| Test connection button | Settings-page button → AJAX → real `POST /merchant/api-auth/login` exchange for the selected environment. | Done |
| Express wallets (Apple Pay / Google Pay) | Not offered in embedded modes. | N/A — provider lacks it (contract doc: "Payment Element without wallets; hosted vezmo-user pages have them, embed does not"). |
| Saved cards / tokenization | Not implemented; gateway `supports` only `products`. | N/A — provider lacks it (contract doc: "No SetupIntent/vault endpoints exposed"). |
| `add_payment_method` (My Account) | Not implemented. | N/A — provider lacks it (no vault, see above). |
| Authorize-then-capture | Not implemented; `AUTHORIZED` status is treated as async settlement pending (ACH), mapped to on-hold. | N/A — provider lacks it (contract doc: "No `capture_method: manual` anywhere; no capture/cancel endpoints"). |
| Full refunds from the order screen | `process_refund()` returns an explanatory `WP_Error` directing to the VezmoPay dashboard; a provider-side `REFUNDED` status found during reconciliation moves the order to refunded. | N/A — provider lacks it (contract doc: "Refund API… Internal-only; no merchant endpoint"). |
| Partial refunds | Same as above. | N/A — provider lacks it (same gap). |
| 3-D Secure / SCA | Handled entirely inside VezmoPay's hosted Stripe Payment Element (`confirmPayment({redirect:'if_required'})`); the plugin never sees a client secret. | Done — by delegation to the provider surface. |
| WooCommerce Subscriptions | Not supported; not declared in `supports`. | N/A — provider lacks it (contract doc: no off-session charging API; the platform's `subscription` module is Vezmo's own SaaS billing). |
| WooCommerce Pre-Orders | Not supported (requires charge-later / off-session). | N/A — provider lacks it (same gap as capture-later/off-session). |
| Multi-currency | `currency` passed through on every payment/paylink; no allowlist enforced. | Done (with caveat) — effective support equals the merchant's underlying Stripe account (contract doc: "No currency allowlist"). |
| Zero-decimal currencies (JPY, KRW, …) | Gateway hides itself (`is_available()` returns false) and the settings page shows an error notice for the 16 zero-decimal ISO codes. | N/A — provider lacks it (contract doc: platform's unconditional `amount * 100` corrupts them; the plugin refuses rather than mischarging). |
| Webhooks + signature verification | REST endpoint `/wp-json/vezmopay/v1/webhook`; verifies HMAC-SHA256 `X-Webhook-Signature` when present, and independently re-verifies every event via `GET /merchant/payment/:id` before touching the order. | Done (with caveat) — platform signing is currently disabled server-side (contract doc: "commented out in the delivery worker"), which is why re-verification is mandatory, not optional. |
| Webhook auto-registration | Settings page displays the exact URL + events to register manually. | N/A — provider lacks it (contract doc: endpoint registration "requires a dashboard session, not the merchant API key — the plugin cannot auto-register"). |
| Idempotency | `Idempotency-Key: wc-{order_key}-a{attempt}` on secure-payment creation, attempt bump + single retry on 409/422; token reuse prevents refresh-minted duplicates; webhook event-id dedupe. | Done |
| HPOS (custom order tables) | `custom_order_tables` compatibility declared; all order data via `WC_Order` meta APIs; webhook lookup via `wc_get_orders` meta query. | Done |
| Cart & Checkout Blocks | `cart_checkout_blocks` declared; `Blocks_Support` (`AbstractPaymentMethodType`) + `assets/js/blocks.js` register the payment method tile. | Done (with caveat) — the tile is informational (title/description/test badge); all modes finalize after the server-side redirect, so no in-form element on the Blocks checkout. |
| Debug logging | `Logger` wrapper over `WC_Logger` (source `vezmopay`); opt-in debug, always-on errors; keys/secrets/tokens redacted in both structured context and free-form strings. | Done |
| Transaction id on order | `payment_complete( $payment_id )` stores the VezmoPay payment id as the WC transaction id (also set for AUTHORIZED/ACH orders). | Done |
| Order status mapping | `CAPTURED` → processing/completed (paid); `AUTHORIZED` → on-hold (ACH pending); `FAILED` → failed; `REFUNDED` → refunded; `INITIATED` → unchanged; amount mismatch → on-hold with note. | Done |
