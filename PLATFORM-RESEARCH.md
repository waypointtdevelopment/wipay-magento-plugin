# Platform research: Magento / Adobe Commerce

## Status
Research workspace only. The supplied magento.zip is stored at the repository root as legacy reference. No new code has been reviewed or implemented.

## Legacy package facts
The archive README identifies version 1.1, Magento 2.3.x, requires at least 2.3.0, and says it was tested through 2.3.3. It contains separate Wipay Credit and Wipay Voucher modules and reports OSL-3.0. Treat it as an old reference, not a release candidate. Its embedded sandbox credentials and test instructions are legacy and must not be reused without verification.

## Current integration direction
WiPay's current Payments API redirects shoppers to its hosted payment page. The store should not collect card data. On return, the server must verify WiPay's response hash and compare the verified amount with the amount requested for that order.

Magento / Adobe Commerce supports payment-provider gateway modules. Before implementation, choose supported versions and checkout surfaces, then verify the legacy license/provenance and obtain WiPay's current API support guidance.

## Open questions
- Which Magento Open Source and Adobe Commerce versions should be supported?
- Is the old extension still maintained, and may we derive from it?
- Confirm current sandbox account, endpoint/territory, API fields, hash rules, and voucher availability.
- Define callback/retry/idempotency and order-state behavior.

## References
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [WiPay API FAQ](https://docs.wipayfinancial.com/payments-api/faq)
- [Adobe Commerce payment integrations](https://developer.adobe.com/commerce/php/development/payments-integrations/)
- [WiPay Magento integration page](https://wipaycaribbean.com/integrations/magento)
