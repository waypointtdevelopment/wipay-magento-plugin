<?php
/**
 * Submit a full refund request to WiPay when a credit memo is refunded.
 *
 * WiPay documents no amount parameter, so a partial refund is refused rather
 * than silently processed as a full one. Throwing here aborts the credit
 * memo, which is the correct outcome: nothing was refunded at WiPay.
 *
 * The response confirms only that a request was created. The order note must
 * say so, because WiPay reports acceptance or rejection later.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Gateway\Command;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Waypoint\WiPay\Gateway\Request\PaymentRequest;
use Waypoint\WiPay\Model\Platform;
use Waypoint\WiPay\Model\Wapi;

class RefundCommand implements CommandInterface
{
    private const TOLERANCE = 0.01;

    private Wapi $wapi;
    private Platform $platform;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        Wapi $wapi,
        Platform $platform,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->wapi = $wapi;
        $this->platform = $platform;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param array{
     *     payment: PaymentDataObjectInterface,
     *     amount?: float,
     *     creditmemo?: \Magento\Sales\Model\Order\Creditmemo
     * } $commandSubject
     * @return void
     * @throws LocalizedException When the refund cannot be submitted.
     */
    public function execute(array $commandSubject)
    {
        /** @var PaymentDataObjectInterface $paymentData */
        $paymentData = $commandSubject['payment'];
        $payment = $paymentData->getPayment();
        $order = $paymentData->getOrder();

        $transactionId = (string) $payment->getAdditionalInformation(PaymentRequest::KEY_TRANSACTION);

        if ($transactionId === '') {
            throw new LocalizedException(__('This order has no WiPay transaction to refund.'));
        }

        $amount = isset($commandSubject['amount']) ? (float) $commandSubject['amount'] : null;
        $orderTotal = (float) $order->getGrandTotal();

        if ($amount !== null && abs($amount - $orderTotal) > self::TOLERANCE) {
            throw new LocalizedException(
                __(
                    'WiPay supports full order refunds only. Refund the whole order, or record a '
                    . 'partial adjustment outside Magento.'
                )
            );
        }

        $environment = (string) $payment->getAdditionalInformation(PaymentRequest::KEY_ENVIRONMENT);
        if (!in_array($environment, [Platform::ENVIRONMENT_LIVE, Platform::ENVIRONMENT_SANDBOX], true)) {
            $environment = Platform::ENVIRONMENT_SANDBOX;
        }

        $country = strtoupper(
            (string) $this->scopeConfig->getValue('payment/waypoint_wipay/country_code')
        );

        $this->wapi->refund($transactionId, $country, $environment);
    }
}
