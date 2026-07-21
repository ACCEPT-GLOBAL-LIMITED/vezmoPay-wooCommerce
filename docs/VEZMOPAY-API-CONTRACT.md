# VezmoPay API Contract (as implemented by this plugin)

Extracted from the `velzovo-api` source (NestJS + Prisma) on 2026-07-15. Every endpoint below was
verified against controllers/DTOs in that repo. Items marked **NOT AVAILABLE** do not exist in the
platform today — the plugin stubs or omits them (see `docs/FEATURE-MAPPING.md`).

## Hosts

| Role | Live | Test/Staging |
|---|---|---|
| API | `https://api.vezmo.com` | `https://api.dev.vezmo.com` |
| Hosted checkout (vezmo-user app) | `https://user.vezmo.com` | `https://user.dev.vezmo.com` |

- Global route prefix: `/api/v1` (no other versioning).
- ⚠️ The public docs page inside vezmo-user references `api.vezmo.app`; all real configs use
  `vezmo.com`. The plugin defaults to `vezmo.com` hosts but exposes base-URL settings.
- Test vs Live is **not** separate hosts or key prefixes — it is a per-key metadata flag
  (`ApiKeyEnv TEST|LIVE`, not enforced server-side). The plugin keeps separate key sets and
  base URLs per environment anyway, so the platform can split hosts later without plugin changes.

## Response envelope

Every JSON response is wrapped:

```json
{ "success": true, "message": "…", "data": <payload>, "meta": {}, "requestId": "…", "timestamp": "…" }
```

Errors: `{ "success": false, "message": "…", "errors": [...], "data": null }`. Unwrap `data`.

## Authentication (two-legged)

1. `POST /api/v1/merchant/api-auth/login` — public. Headers: `x-api-key: vzm_…`, `x-api-secret: …`.
   Returns `data.accessToken.token` (JWT, **30 min TTL**) and `data.accessToken.refreshToken` (7 days).
2. All `/api/v1/merchant/*` calls: `Authorization: Bearer <token>`.
3. `POST /api/v1/merchant/api-auth/refresh-token` — body `{ "refreshToken": "…" }` → new access token.

- Key format: `vzm_` + 16 random chars. Single key+secret pair — there is **no publishable key**.
- Per-key scopes enforced per route. Required for this plugin:
  `secure-payment.create`, `paylink.create`, `paylink.read`, `payment.read`.
  ⚠️ `secure-payment.create` is missing from the platform permission seeder — it must be granted
  to the merchant key by a platform admin or every secure-payments call 403s.
- Creating a new key deactivates the merchant's previous keys (one active key per user).
- OAuth / connect-style onboarding: **NOT AVAILABLE**. Keys are created in the Vezmo dashboard
  and pasted into the plugin.
- Test connection = the login call itself.

## Secure payments (element/iframe modes)

`POST /api/v1/merchant/secure-payments` — Bearer auth, scope `secure-payment.create`, throttled 60/min.
Optional header `Idempotency-Key` (1–255 printable ASCII; same key + different body → 422).

Request body:

| field | required | notes |
|---|---|---|
| `title` | yes | ≤255 chars |
| `amount` | yes | **major units**, max 2 decimals, 0.01–1,000,000 |
| `currency` | no | ≤8 chars, default `USD` |
| `description` | no | ≤2000 |
| `client` | no | `{ name*, email*, phone?, company?, country? }` |
| `ttlMinutes` | no | 5–1440, default 30 |
| `iframe` | no | `{ width?, height? }` |

Response `data`:

```json
{
  "payment": { "id": "…", "amount": 0, "currency": "USD", "status": "INITIATED", "title": "…" },
  "securePayment": { "clientToken": "<jwt>", "url": "…", "sdkUrl": "…", "html": "<iframe …>", "expiresAt": "…" }
}
```

- `url` = `{API}/api/v1/secure-payments/{clientToken}` — the hosted Stripe Payment Element page (iframe src).
- `sdkUrl` = `{API}/api/v1/vezmo.js`.
- Card entry, 3DS/SCA, and confirmation all happen **inside** the Vezmo-hosted iframe
  (Stripe Payment Element, `confirmPayment({redirect:'if_required'})`). The plugin never sees
  card data or a Stripe client secret.

### vezmo.js SDK

- `GET /api/v1/vezmo.js` — vanilla IIFE defining `window.Vezmo`.
- `var v = new Vezmo({ apiBase }); v.mount(el, { clientToken, width, height }); v.on(event, cb);`
- Events (postMessage type prefix `vezmo:secure-payment:`): `ready`, `success` (`paymentId`,
  `paymentIntentId`), `pending` (ACH processing), `error` (`message`), `already-paid`, `expired`.
- ⚠️ The iframe only posts events to parent origins in the merchant's **trusted origins** list
  (managed in the Vezmo dashboard → merchant settings, max 20, https-only). The store origin must
  be registered there or the plugin's JS falls back to status polling.

## Paylinks (hosted redirect mode)

- `POST /api/v1/merchant/paylinks` — scope `paylink.create`. Body: `title*` (≤120), `amount*`,
  `currency?`, `description?`, `dueDate?`, `clientId?`. Returns paylink incl. `shortCode`.
- Customer checkout page: `{CHECKOUT_HOST}/checkout/payments-links/{shortCode}`.
- `GET /api/v1/merchant/paylinks/:code` — scope `paylink.read` — resolve status.
- ⚠️ **No success/cancel/return URL support** — the customer is not redirected back to the store.
  A `CreatePaylinkCheckoutDto` with successUrl/cancelUrl exists in the API repo but is wired to
  nothing. The plugin compensates with webhook + polling reconciliation and an order-received
  fallback, but the redirect-back UX gap is a platform limitation. **Flagged.**

## Payment records

- `GET /api/v1/merchant/payment/:id` — scope `payment.read`. `status` ∈
  `INITIATED | AUTHORIZED | CAPTURED | FAILED | REFUNDED`. This is the plugin's source of truth.
- `GET /api/v1/merchant/payment` — list (scope `payment.read`).

## Webhooks (outbound, platform → store)

- Registered in the Vezmo dashboard (`POST /api/v1/webhook-endpoints` requires a **dashboard
  session**, not the merchant API key — the plugin cannot auto-register). Store the `whsec_…`
  secret shown once at creation.
- Envelope: `{ "id": "evt_<endpoint>_<event>_<resourceId>", "event": "payment.success", "data": {…} }`.
  Headers: `Content-Type: application/json`, `X-Webhook-Event`. No timestamp field.
- Events actually emitted: `payment.success`, `payment.failed` (plus invoice/proposal events).
  `data` includes `id` (payment id) / `paymentId`, `amount`, `currency`, `status`,
  `type` (`secure-payment` | `paylink` | `manual` | ach flows), `payment.failed` adds `reason`.
- Retries: 4 attempts at +0h/+6h/+12h/+24h, 5s timeout, no ordering guarantee. Dedupe on envelope `id`.
- ⚠️ **Signing is currently DISABLED in the platform** (`X-Webhook-Signature` HMAC-SHA256-hex code
  is commented out in the delivery worker). The plugin verifies the signature when the header is
  present, but **always** re-fetches the payment via `GET /merchant/payment/:id` before touching
  an order. Never trust webhook payloads alone. **Flagged.**
- Refund / dispute / payout / subscription webhooks: **NOT AVAILABLE**.

## Amounts & currencies

- Amounts are decimal **major units** (e.g. `199.99`), not cents.
- ⚠️ The platform converts with an unconditional `amount * 100` — **zero-decimal currencies
  (JPY, KRW, VND, …) are mishandled** (¥1000 becomes ¥100,000). The plugin refuses to offer the
  gateway for zero-decimal currencies until the platform fixes this. **Flagged.**
- No currency allowlist; effective support = the merchant's Stripe account. USD gets
  card + US bank (ACH, async → `pending`); other currencies get Stripe automatic payment methods.

## NOT AVAILABLE (platform gaps — flagged, not guessed)

| Capability | Status in velzovo-api |
|---|---|
| Refund API (full/partial) | Internal-only; no merchant endpoint. Refund from the Vezmo/Stripe dashboard. |
| Authorize-then-capture / void | No `capture_method: manual` anywhere; no capture/cancel endpoints. |
| Tokenization / saved cards / customer vault | No SetupIntent/vault endpoints exposed. |
| Off-session charging (WC Subscriptions renewals) | No API; `subscription` module is Vezmo's own SaaS billing. |
| OAuth / connect onboarding | Only human social login; no app-authorization grant. |
| Hosted-checkout return/cancel URLs | DTO exists, unused. |
| Webhook signature (active) | Designed (`whsec_`+HMAC-SHA256 hex) but commented out. |
| Wallet buttons in the embed iframe | Payment Element without wallets; hosted vezmo-user pages have them, embed does not. |
| Zero-decimal currency handling | Broken (×100 unconditionally). |
| Publishable/public key | Single key+secret pair only. |
