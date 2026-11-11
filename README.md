# Paddle Subscription for WemX

Accept recurring payments via [Paddle](https://paddle.com) (Merchant of Record) in WemX — card, PayPal, and local payment methods, with Paddle handling global tax/VAT compliance and recurring billing for you.

Pairs well with (but doesn't require) [EWANZO101/Paddle-Checkout](https://github.com/EWANZO101/Paddle-Checkout) for one-time payments — both share the same Paddle.js checkout page.

## Installation

Run this from your WemX installation root:

```bash
curl -fsSL https://raw.githubusercontent.com/EWANZO101/Paddle-Subscription/main/install.sh | bash
```

Then configure the gateway in **Admin → Gateways → Configs** — see [INSTALL.md](INSTALL.md) for that step and the full manual install if you'd rather not pipe a script into bash.

## How it works

- Creates a Paddle transaction for a recurring (non-catalog) price when a customer subscribes — Paddle itself creates the underlying subscription once that first payment completes.
- Redirects the customer to a dedicated checkout page that loads Paddle.js and opens Paddle's secure payment overlay.
- Paddle's webhooks (`subscription.created`, `.activated`, `.updated`, `.past_due`, `.paused`, `.resumed`, `.canceled`) keep WemX's subscription status in sync automatically.
- Supports WemX's periodic subscription status check and customer-initiated cancellation (cancels immediately at Paddle, so billing and WemX's local state never drift apart).

## Requirements

- A Paddle account (sandbox or live).
- WemX v3.
