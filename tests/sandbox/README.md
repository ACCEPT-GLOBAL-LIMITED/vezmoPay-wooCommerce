# Sandbox — what `[S]` in the test checklist means

`docs/TEST-CHECKLIST.md` marks a case **[S]** when it can be proven here: WordPress +
WooCommerce with this plugin bind-mounted read-only, against a mock VezmoPay API. It is not a unit
test suite — it drives the real checkout in a real browser, because every defect this plugin has
shipped lived in the seam between the store, the browser and the provider, and none of them would
have been caught by a unit test.

```bash
cd tests/sandbox
docker compose up -d
./setup.sh
node regress.mjs box element success      # surface, mode, outcome
```

`regress.mjs` needs `playwright-core` on the machine (any checkout that has it will do —
`node regress.mjs` takes the path at the top of the file).

## Surfaces

| Argument | What it drives |
|---|---|
| `box` | The payment box on the checkout page (`checkout-inline.js`) |
| `receipt` | `/checkout/order-pay/{id}/?key=…` — the receipt page (`pay-attempt.js` + a driver) |
| `payform` | `/checkout/order-pay/{id}/?pay_for_order=true&key=…` — My Account and the invoice email |

## Outcomes the mock checkout form can produce

| Outcome | What the fixture does |
|---|---|
| `success` | Captures, then reports success |
| `decline` | Reports the failure to the API **and** posts `error` |
| `decline_silentapi` | Posts `error`, leaves the payment INITIATED — what a real decline does |
| `decline_then_success` | Declines once, then succeeds — the retry-in-place case |
| `nothing` | Accepts the card and reports **nothing**, ever — the D-01 platform gap |
| `nothing_then_success` | Nothing, then succeeds on the second press |
| `requires_action` | Reports `requires_action` and stops — a live 3-D Secure challenge |
| `wallet` | Charges inside the frame with no submit — the wallet hazard |

Set one with `wp option update mockpay_outcome <name>`; `regress.mjs` does it for you.

## Why the mock is faithful where it matters

- Creation is idempotent on `Idempotency-Key`, so a replayed request returns the first payment —
  this is what caught two carts of the same value sharing one capture.
- `GET /merchant/payment/{id}` reads INITIATED until the form actually charges, so a decline looks
  exactly as it does in production: **no terminal state at all**.
- The frame posts the same `vezmo:secure-payment:*` messages, from a different origin to the store
  (`127.0.0.1` vs `localhost`), so the origin checks are exercised rather than bypassed.
- `mock-vezmo.js` relays only the eight event names the real `vezmo.js` relays — it deliberately
  drops `requires_action`, because the real SDK does, and that gap once hid a live defect.

The rig declares `WP_ENVIRONMENT_TYPE = local` and allow-lists its own host
(`mu-plugins/rig-hosts.php`); without that the plugin correctly refuses the mock's plain-http URLs.
