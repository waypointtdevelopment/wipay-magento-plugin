<?php
/**
 * Creates a WiPay hosted-checkout session when the order is placed.
 *
 * Runs inside Magento's order placement. If WiPay cannot be reached or
 * returns something unusable, this throws, so the order is never placed and
 * the shopper never sees a redirect. On success the checkout URL and the
 * transaction reference are stashed on the payment for the Start controller
 * to redirect to.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Waypoint\WiPay\Gateway\Request\PaymentRequest;
use Waypoint\WiPay\Model\Http;
use Waypoint\WiPay\Model\Platform;

class PlaceOrderCommand implements CommandInterface
{
    private PaymentRequest $requestBuilder;
    private Platform $platform;
    private Http $http;

    public function __construct(
        PaymentRequest $requestBuilder,
        Platform $platform,
        Http $http
    ) {
        $this->requestBuilder = $requestBuilder;
        $this->platform = $platform;
        $this->http = $http;
    }

    /**
     * @param array{payment: PaymentDataObjectInterface} $commandSubject
     * @return void
     * @throws LocalizedException When checkout cannot be started.
     */
    public function execute(array $commandSubject)
    {
        /** @var PaymentDataObjectInterface $paymentData */
        $paymentData = $commandSubject['payment'];
        $payment = $paymentData->getPayment();
        $order = $paymentData->getOrder();

        $payload = $this->requestBuilder->build($commandSubject);

        $environment = (string) $payload['environment'];
        $country = (string) $payload['country_code'];
        $account = (int) $payload['account_number'];
        $email = (string) $payload['email'];
        $total = (float) $payload['total'];

        $endpoint = $this->platform->paymentsEndpoint($country, $environment);

        if ($endpoint === '' || $account <= 0 || $email === '') {
            throw new LocalizedException(
                __('WiPay is not configured correctly. Please contact the store.')
            );
        }

        // Carried over from the sandbox-tested gateway: TTD 5.00 floor for TT.
        if ($country === 'TT'
            && strtoupper((string) $order->getOrderCurrencyCode()) === 'TTD'
            && $total < 5.00
        ) {
            throw new LocalizedException(__('WiPay requires a minimum payment of TTD 5.00.'));
        }

        [$status, $body] = $this->http->postJson($endpoint, $payload);

        if ($status !== 200) {
            throw new LocalizedException(__('WiPay could not start checkout. Please try again.'));
        }

        $response = json_decode((string) $body, true);
        $response = is_array($response) ? $response : [];

        $url = isset($response['url']) && is_scalar($response['url']) ? (string) $response['url'] : '';
        $transactionId = isset($response['transaction_id']) && is_scalar($response['transaction_id'])
            ? (string) $response['transaction_id']
            : '';

        if ($url === '' || $transactionId === '' || !$this->platform->isValidCheckoutUrl($url)) {
            throw new LocalizedException(__('WiPay returned an invalid checkout response.'));
        }

        $payment->setAdditionalInformation(PaymentRequest::KEY_URL, $url);
        $payment->setAdditionalInformation(PaymentRequest::KEY_TRANSACTION, $transactionId);
        $payment->setAdditionalInformation(PaymentRequest::KEY_PINNED_TOTAL, (string) $payload['total']);
        $payment->setAdditionalInformation(PaymentRequest::KEY_ENVIRONMENT, $environment);
        $payment->setAdditionalInformation(PaymentRequest::KEY_STARTED, time());
    }
}
