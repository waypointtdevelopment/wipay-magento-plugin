# Waypoint WiPay for Magento / Adobe Commerce

An independent payment integration that redirects shoppers to WiPay's hosted card checkout. It is maintained by Waypoint and is not affiliated with, sponsored by, or endorsed by WiPay.

## Project status

**Implemented for Magento 2.4.x; not yet installed or sandbox-tested.** The module in [`Waypoint/WiPay`](Waypoint/WiPay) provides the full hosted-redirect flow: order placement creates a WiPay checkout session, checkout redirects the shopper to a Magento-owned Start controller, and the return is verified server-side before an invoice is registered. Refunds are submitted through WiPay's WAPI refund request.

`tests/run-tests.php` passes 100 assertions over the signature, host map, request payload rules and the checkout/refund guards. **Nothing here has been run inside a real Magento instance, and no call has reached WiPay** — see [TESTING.md](TESTING.md) before treating it as usable.

This is a clean-room implementation written from WiPay's published Payments API documentation. The legacy `magento.zip` at the repository root (v1.1, Magento 2.3.x, OSL-3.0) was **not** used, which is why the license/provenance question in [PLATFORM-RESEARCH.md](PLATFORM-RESEARCH.md) no longer blocks this repository.

## How it works

1. **Checkout** — the renderer places the order. `PlaceOrderCommand` posts the payment request to `POST https://{country-host}/plugins/payments/request` and stores the returned checkout URL, transaction id, pinned total and environment on the order payment. If WiPay cannot be reached or returns something unusable the command throws, so **no order is placed**.
2. **Redirect** — with `redirectAfterPlaceOrder: false`, the renderer navigates to `waypoint/payment/start`. That controller reads the stored URL back off the order, re-validates it as https on a `wipayfinancial.com` host, forces the order into `pending_payment`, and only then sends the browser to WiPay. The checkout URL never comes from the browser.
3. **Return** — `waypoint/payment/response` resolves the order by the increment id that was sent to WiPay, then requires all of: a recorded started attempt, a transaction id matching the stored one, `status=success`, a verified `md5(transaction_id + pinned_total + api_key)` hash, a pinned total still equal to `order->getGrandTotal()`, and the order still in `pending_payment`. Only then is an invoice registered and the order moved to `processing`.
4. **Failure** — an explicit `failed`/`cancelled`/`error` status cancels the order. Any other status leaves it awaiting payment so it can be retried; a missing return cancels nothing.

## Safety design

- The amount is read server-side from the order and pinned before the payment request is sent; the customer-facing total returned on redirect is never used in the hash.
- Repeated returns for a paid order are idempotent — an invoice is never registered twice.
- A return whose transaction id does not match the recorded one is rejected and recorded as an order comment.
- A pinned total that no longer matches the order is rejected.
- The checkout URL is validated as https on `wipayfinancial.com` (or a subdomain) before the browser is sent anywhere; lookalike hosts are rejected.
- Secrets live in encrypted configuration and are read through a single `Model/Secrets` class. They are never rendered into browser JavaScript.

## Installation

```bash
bin/magento module:enable Waypoint_WiPay
bin/magento setup:upgrade
bin/magento cache:flush
```

Then Stores → Configuration → Sales → Payment Methods → **Waypoint WiPay**:

| Field | Notes |
|---|---|
| Environment | Leave **Sandbox** until live processing is verified |
| WiPay platform | The platform that processes your merchant account — not the shopper's billing country |
| Fee structure | `merchant_absorb`, `customer_pay` or `split` |
| Live account number | Digits only; sandbox uses WiPay's published `1234567890` |
| Live response-verification key | Encrypted; used for the live hash |
| Sandbox / Live WAPI key | Encrypted; needed for refunds. Sandbox keys are prefixed `wapi_test_` |

Sandbox verification uses WiPay's published key `123`.

## Limits

- **No webhooks.** Chargeback, refund and fraud events are not consumed here; reconcile in the WiPay dashboard. (The WordPress gateway does implement them.)
- **No reconciliation tool.** Transaction state must be checked in the WiPay dashboard.
- **Full-order refunds only.** WiPay documents no amount parameter, so a partial credit memo is refused with an error rather than processed as a full refund. A refund response confirms only that a *request* was created.
- The JM/BB/GY/GD hostnames were re-confirmed against WiPay's Platforms and Environments page on 15 October 2026; all ten live/sandbox hostnames were probed and returned HTTP 200 over HTTPS.
- HPOS: the module writes through `OrderRepositoryInterface`, but the order-state transitions in `Controller/Payment/Response.php` have only been reviewed statically.

## Development

```bash
php -l $(find Waypoint -name '*.php')     # syntax
php tests/run-tests.php                   # 100 assertions
node --check Waypoint/WiPay/view/frontend/web/js/view/payment/method-renderer/waypoint_wipay.js
```

The harness loads the module's own services against stand-ins for the framework interfaces they type-hint, so it runs without a Magento install. It does **not** exercise the controllers, the DI wiring, the Knockout renderer, or anything that reaches WiPay.

## License and disclaimer

GPL-2.0-or-later. Waypoint accepts no liability for use of this software. It is supplied without warranty. WiPay does not endorse this module.

## References

- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [Payment Request](https://docs.wipayfinancial.com/payments-api/payment-request)
- [Transaction Response and hash verification](https://docs.wipayfinancial.com/payments-api/transaction-response)
- [WAPI refund request](https://docs.wipayfinancial.com/wapi/transactions/createRefundRequest)
- [Platforms and Environments](https://docs.wipayfinancial.com/platforms-and-environments)
- [Adobe Commerce payments integrations](https://developer.adobe.com/commerce/php/development/payments-integrations/)
