# 💳 SynraPay for PrestaShop 8

Accept **Visa, Mastercard and American Express** on your PrestaShop store with
SynraPay. The card fields are SynraPay's own, shown **inside the checkout payment
step** (no redirect), with 3-D Secure in an overlay. Card data never touches your
server.

| | |
|---|---|
| 📦 Module | `synrapay` v1.0.0 — folder [`synrapay/`](./synrapay), zip `synrapay.zip` |
| 🛒 PrestaShop | 8.0.2 or greater |
| 🐘 PHP | 7.4 or greater |
| 🔐 Integration | SynraPay Direct API — hosted session, signature v2 |

---

## ✨ What the shopper sees

1. Picks **Credit / Debit card** on the payment step.
2. SynraPay's card fields load inside the payment option.
3. Ticks the terms and clicks **Order with an obligation to pay** → the card goes from SynraPay's frame to SynraPay, 3-D Secure opens in an overlay.
4. Lands on the normal PrestaShop **order confirmation** page. The order is created with *Payment accepted*.

---

## ✅ Before you start

You need these from the **SynraPay Merchant Portal** (sign in → pick the store → **Developers**):

| Item | Where in the portal |
|---|---|
| **Merchant ID** (`x-merchant-id`) | Developers → **API Keys** → Request Signing |
| **API Signature Key** | Developers → **API Keys** → Request Signing (Direct API grant required) |
| **Webhook signing secret** | Developers → **Webhooks** → Configuration → *Generate new secret* (shown once) |
| **Allowed origin** | Developers → **Integrations** → add your shop's origin, e.g. `https://shop.example.com` |

Sandbox and Live each have their **own portal and own keys**. Start with Sandbox.

---

## 📦 Installation

### Option A — upload the ready zip

1. Download [`dist/synrapay.zip`](./dist/synrapay.zip).
2. Back office → **Modules → Module Manager → Upload a module** → choose the zip.

### Option B — zip the folder yourself

1. Clone this repository.
2. Zip the `synrapay/` folder (the zip must contain the folder `synrapay/` at its root) → `synrapay.zip`. Or run `sh build-zip.sh`.
3. Upload it via **Modules → Module Manager → Upload a module**.

### Then configure

Modules → Module Manager → **SynraPay** → **Configure**.

---

## ⚙️ Configuration

| Setting | What to enter |
|---|---|
| 🌍 Environment | **Sandbox** while testing, **Live** when you go live (*Develop* is for SynraPay internal testing) |
| 🆔 Merchant ID | From API Keys (for the chosen environment) |
| 🔑 API Key | From API Keys. Stays on your server, never sent to the browser. Leave blank to keep the saved value |
| 📞 Fallback customer phone | SynraPay requires a phone on every order; used when the shopper's phone is missing or invalid |
| 🔔 Webhook signing secret | From Webhooks → Configuration |
| Webhook API-Key header name / value | Optional extra check on webhooks (API Keys → Webhook Verification) |

Save, then **copy three values printed on the same page**:

- 🔗 **Your Webhook URL** → Merchant Portal → Developers → Webhooks → *Update Webhook* → Endpoint URL, with **All events** subscribed.
- 🌐 **Your checkout origin** → Merchant Portal → Developers → Integrations → allowed origins.
  Without it the card form answers `ORIGIN_NOT_REGISTERED`. Live requires `https://` (SSL enabled in PrestaShop); Sandbox also accepts `http://localhost`.
- 🕒 **Re-check cron URL** → call it every 5–15 minutes from a server cron or the *Cron tasks manager* module. It settles orders left awaiting SynraPay's final confirmation; the webhook remains the primary path.

Finally, **Payment → Preferences**: tick the currencies, countries and customer groups the module may serve.

---

## 🧪 Testing

1. Set Environment = **Sandbox** with Sandbox keys, register the Sandbox webhook URL and origin.
2. Place an order with a SynraPay sandbox test card.
3. Check: an order with *Payment accepted*, the **SynraPay** card on the admin order page shows the order id and gateway reference, and the webhook arrived (Advanced Parameters → Logs, source *SynraPay*).
4. Switch Environment to **Live**, paste the Live keys, register the Live webhook URL and origin.

---

## 🔄 How a payment flows

```
payment step    POST module/synrapay/session
   shop         POST /v1/direct/create-order                merchant_order_id PS-<site>-<cart>-<time>-<rand>
   shop         POST /v1/direct/hosted-session              origin = your shop → client_token
   page         SynraPayFields.create({ clientToken })      card fields mount in the payment option

"Order with an obligation to pay"  →  the module intercepts the submit → fields.submit()   3-D Secure in an overlay
   page         POST module/synrapay/confirm (polls)
   shop         GET /v1/direct/payment/{order_id}/status
                   CAPTURED / AUTHORIZED  → validateOrder(Payment accepted) → order confirmation
                   FAILED / DECLINED …    → no order created, new card session for a retry
                   approved, not final    → validateOrder(Awaiting SynraPay confirmation) → order confirmation; webhook / cron finish it
   webhook      POST module/synrapay/webhook?token=<per-shop token>   signed (v2), source of truth; creates the order if the shopper left early
```

The PrestaShop **order exists only after SynraPay says paid** (or approved-pending), so no abandoned orders are created for declined cards.

---

## 🔒 Security

- The browser only ever holds a short-lived **client token**. Merchant ID and API key stay on your server and sign every call (`HMAC-SHA512`, signature v2).
- The shop **never trusts the browser** about the outcome: only SynraPay's order status creates or updates a PrestaShop order.
- The **amount paid must equal the cart total** (same currency) or the payment is held as a mismatch and logged.
- Webhooks are accepted only with a valid **signature + timestamp (5 min window)**, a per-shop secret token in the URL and, optionally, your API-key header. Webhooks about superseded (declined) tries are acknowledged and ignored; a cart that already has an order is never re-ordered.
- Endpoints accept only requests from your own shop's origin, scoped to the current cart, with rate limits.
- Client-token environment must match the Environment setting; Live requires SSL.
- Nothing logs card data or keys.

---

## 🛠️ Troubleshooting

| Message | Meaning / fix |
|---|---|
| `ORIGIN_NOT_REGISTERED` | Register the exact origin printed on the configure page (scheme + host + port) under Developers → Integrations. |
| `MERCHANT_NOT_IDENTIFIED` | Merchant ID is wrong or belongs to another environment. |
| `ENVIRONMENT_MISMATCH` / 409 | The keys pasted are for a different environment than the one selected. |
| Card form keeps loading | Check Advanced Parameters → Logs (source *SynraPay*); usually a missing phone (set a fallback phone) or an unregistered origin. |
| Webhook answers 401 | Signing secret differs from the portal, or the API-key header name/value does not match. |
| Order stays *Awaiting SynraPay confirmation* | Cron URL not called or webhook URL not registered. |
| Payment option not shown | Payment → Preferences: currency / country / group not ticked, or Live selected without SSL. |

---

## 📁 Repository layout

```
synrapay/
├── synrapay.php                          module: install, configure form, settle paid / awaiting / failed, hooks
├── classes/SynraPayDirectClient.php      signed Direct API client, webhook verify, phone split
├── classes/SynraPayLink.php              ps_synrapay_cart: cart ↔ SynraPay order link
├── controllers/front/                    session, confirm, webhook, recheck
├── views/js/synra-hs.js                  mounts the card fields, intercepts the payment form submit, polls confirm
└── views/templates/hook/                 payment option, hidden form, confirmation note, admin order card
build-zip.sh                              builds dist/synrapay.zip
dist/                                     ready-to-install zip
```

### 🧑‍💻 Notes for developers

- Rebuild the zip with `sh build-zip.sh` (uses `zip` or Windows `tar`; forward-slash entries).
- Configuration keys: `SYNRAPAY_ENVIRONMENT`, `SYNRAPAY_MERCHANT_ID`, `SYNRAPAY_API_KEY`, `SYNRAPAY_FALLBACK_PHONE`, `SYNRAPAY_WEBHOOK_KEY`, `SYNRAPAY_X_API_KEY_NAME`, `SYNRAPAY_X_API_KEY`, `SYNRAPAY_WEBHOOK_TOKEN`, `SYNRAPAY_CRON_TOKEN`, `SYNRAPAY_OS_AWAITING`.
- If a theme replaces PrestaShop's `payment.js`, hook its confirm button to submit `form[data-synra-form]`.
- Refunds from the back office are not wired (Direct `/refund` is still a placeholder route).
- Not included on purpose: hosted-checkout redirect mode.

---

## 📝 Changelog

- **1.0.0** — First release: hosted-session card fields on the payment step, order created only on payment, webhook, re-check cron, back-office configuration.

## 🙋 Support

Open an issue in this repository or contact your SynraPay account manager through the Merchant Portal.
