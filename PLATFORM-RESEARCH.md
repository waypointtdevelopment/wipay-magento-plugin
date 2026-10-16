# Platform research: Magento / Adobe Commerce

## Status
**Implemented as a clean-room Magento 2.4.x module.** `Waypoint/WiPay` now covers order placement, the checkout redirect, server-side return verification and WAPI refund requests. The legacy magento.zip remains at the repository root as reference only and was **not** used as a source. Sandboxing on a real Magento instance is outstanding - see TESTING.md.

The license/provenance question that previously blocked this repository is resolved: because the implementation was written from WiPay's published Payments API documentation rather than derived from the archived OSL-3.0 extension, reuse rights are not implicated.

## Legacy package facts
The archive README identifies version 1.1, Magento 2.3.x, requires at least 2.3.0, and says it was tested through 2.3.3. It contains separate Wipay Credit and Wipay Voucher modules and reports OSL-3.0. Treat it as an old reference, not a release candidate. Its embedded sandbox credentials and test instructions are legacy and must not be reused without verification.

## Current integration direction
WiPay's current Payments API redirects shoppers to its hosted payment page. The store should not collect card data. On return, the server must verify WiPay's response hash and compare the verified amount with the amount requested for that order.

Magento / Adobe Commerce supports payment-provider gateway modules. Before implementation, choose supported versions and checkout surfaces, then verify the legacy license/provenance and obtain WiPay's current API support guidance.

## Decisions taken
- **Supported versions:** Magento Open Source / Adobe Commerce 2.4.x on PHP 8.1+.
- **Integration shape:** Payment Gateway framework (`Magento\Payment\Model\Method\Adapter`) with a custom `order` command; `AbstractMethod` is deprecated as of 100.0.6 and was not used.
- **Redirect:** the checkout renderer sets `redirectAfterPlaceOrder: false` and navigates to a Magento-owned Start controller, because Magento's checkout does not support server-driven browser redirects from the payment method.
- **Trust boundary:** the shopper return resolves the order by the increment id that was sent to WiPay, then re-checks the recorded transaction, the pinned total, the order state and the response hash. Nothing in the query string is trusted on its own.
- **Order lifecycle:** `pending_payment` before the shopper leaves; `processing` with a registered invoice only after verification; `canceled` only for an explicit failure status, so an abandoned return never cancels an order.
- **Refunds:** full order only, via WAPI, because WiPay documents no amount parameter.

## Questions
- ~~Re-confirm the JM/BB/GY/GD hostnames~~ **Closed 15 October 2026:** all five live/sandbox pairs (BB, GD, GY, JM, TT) match WiPay's Platforms and Environments page, and all ten hostnames returned HTTP 200 when probed over HTTPS.
- ~~Confirm the TTD 5.00 minimum~~ **Closed 15 October 2026:** WiPay's Get Transaction Metadata endpoint returns `totals.TTD` as `{"minimum": 5, "maximum": 20400}` (USD is `{"minimum": 0.74, "maximum": 3000}`), so the floor in `PlaceOrderCommand` is documented rather than merely inherited.
- Webhooks and transaction reconciliation are not implemented here; decide whether to port them from the WordPress gateway.
- Validate the order-state transitions against a real instance, including HPOS.

## References
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [WiPay API FAQ](https://docs.wipayfinancial.com/payments-api/faq)
- [Adobe Commerce payment integrations](https://developer.adobe.com/commerce/php/development/payments-integrations/)
- [WiPay Magento integration page](https://wipaycaribbean.com/integrations/magento)
