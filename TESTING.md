# Staging test plan — Waypoint WiPay for Magento 2.4.x

`tests/run-tests.php` proves the signature, host map, payload rules and the
checkout/refund guards. It does **not** run Magento, so everything below still
has to be exercised on a real instance.

**Store setup:** Magento 2.4.x (PHP matching your exact patch), HTTPS enabled,
one simple product with stock, and a sandbox WiPay account.

```bash
php tests/run-tests.php          # must print RESULT: PASSED first
bin/magento module:enable Waypoint_WiPay
bin/magento setup:upgrade && bin/magento cache:flush
```

**Keep Environment set to Sandbox.**

## 1. Module and configuration

| # | Step | Expected |
|---|---|---|
| 1.1 | `bin/magento module:status Waypoint_WiPay` | Enabled |
| 1.2 | Admin → Stores → Configuration → Sales → Payment Methods | Waypoint WiPay panel present |
| 1.3 | Save with an invalid platform written directly to `payment/waypoint_wipay/country_code` | Payment method **absent** from checkout, not broken mid-order |
| 1.4 | Enable Diagnostics/logs and check `var/log` | No keys, no request bodies, no card data |
| 1.5 | Inspect page source at checkout | No WAPI key, response key or account number in JS |

## 2. Checkout happy path

| # | Step | Expected |
|---|---|---|
| 2.1 | Select **Credit card (WiPay)** and place the order | Browser lands on a `wipayfinancial.com` checkout page over **https** |
| 2.2 | Order in admin | State **Pending payment**, with a comment naming the transaction id |
| 2.3 | `sales_order_payment.additional_information` | `wipay_url`, `wipay_transaction_id`, `wipay_pinned_total`, `wipay_environment`, `wipay_started` all present |
| 2.4 | Pay with Visa `4242424242424242`, future expiry, CVV `123` | Returns to the Magento success page |
| 2.5 | Order after payment | State **Processing**, invoice registered, transaction id recorded |
| 2.6 | Reload the return URL | Still one invoice — never registered twice |
| 2.7 | Pay with `4000000000000002` | Order **Canceled** with an explanatory comment |

Use the TTD-specific test cards from WiPay's testing docs when the platform is TT in TTD.

## 3. The redirect is server-owned

| # | Step | Expected |
|---|---|---|
| 3.1 | `view-source` during checkout | No `wipayfinancial.com` URL anywhere in the page |
| 3.2 | Visit `/waypoint/payment/start` with an empty session | Redirect to checkout with an error — no crash, no redirect off-site |
| 3.3 | Tamper `wipay_url` in `additional_information` to `https://evil.example.com/` | Start refuses and stays on the store |
| 3.4 | Same tamper but on `http://tt.wipayfinancial.com/` | Refused (not https) |

## 4. Return verification — these must fail closed

Take a real success return URL from step 2.1 and change **one** parameter at a time. Each must leave the order unpaid and write a "WiPay return rejected" comment.

| # | Tamper | Expected |
|---|---|---|
| 4.1 | `hash` → 32 `a`s | Rejected |
| 4.2 | `hash` removed | Rejected |
| 4.3 | `transaction_id` → another value | Rejected (does not match the recorded transaction) |
| 4.4 | `order_id` → a different order's increment id | Rejected / no order matched |
| 4.5 | `status` → `pending` | No cancellation, no payment; message says not paid |
| 4.6 | `status` → `failed` | Order canceled |
| 4.7 | Edit `wipay_pinned_total` on the payment, then send a valid hash | Rejected — pinned total no longer matches the order |
| 4.8 | Move the order to Processing, then replay a valid return | Rejected — no longer pending payment |
| 4.9 | Replay a completed success return repeatedly | Idempotent; exactly one invoice |

## 5. Guards

| # | Step | Expected |
|---|---|---|
| 5.1 | TT platform, TTD currency, total below 5.00 | Order placement refused **before** any WiPay call |
| 5.2 | Same total in USD | Not blocked |
| 5.3 | Live environment with no account number or no response key | Payment method hidden (availability validator) |
| 5.4 | Unknown country code in config | Payment method hidden |

## 6. Refunds

| # | Step | Expected |
|---|---|---|
| 6.1 | Credit memo for a **partial** amount | Refused: "full order refunds only"; no credit memo saved; no HTTP call |
| 6.2 | Credit memo for the **full** amount, no WAPI key | Refused with a message naming the configuration field |
| 6.3 | Full amount with sandbox key set | Succeeds; request is `POST https://{host}/wapi/transactions/{txn}/refund` with `X-WAPI-Key` |
| 6.4 | Confirm the credit memo comment | Says a **request** was created, not that funds returned |
| 6.5 | Sandbox key against live, or vice versa | Rejected by WiPay; message surfaced in admin |

## 7. Regression

| # | Step | Expected |
|---|---|---|
| 7.1 | Another payment method on the same store | Still works |
| 7.2 | Guest checkout | Works end to end |
| 7.3 | Billing address absent (virtual product) | Payload omits `addr1`/`addr2` rather than sending empty values |
| 7.4 | Order success page reachable after payment | Renders (session intact) |
| 7.5 | Disable the module | Method disappears; existing orders remain readable |

## Sign-off

Release only when every row passes and the host mapping note in [README.md](README.md) has been re-confirmed against WiPay's Platforms and Environments page.
