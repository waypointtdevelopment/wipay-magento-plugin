<?php
/**
 * Redirects the shopper from checkout to WiPay's hosted checkout page.
 *
 * The WiPay URL itself never comes from the request: it was stored server-side
 * on the order payment when the order was placed, and is re-validated here
 * before the browser is sent anywhere.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Waypoint\WiPay\Gateway\Request\PaymentRequest;
use Waypoint\WiPay\Model\Platform;

class Start extends Action implements HttpGetActionInterface
{
    public const METHOD_CODE = 'waypoint_wipay';

    private CheckoutSession $checkoutSession;
    private OrderRepositoryInterface $orderRepository;
    private Platform $platform;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        Platform $platform
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->platform = $platform;
    }

    public function execute(): ResultInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order instanceof Order
            || !$order->getId()
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== self::METHOD_CODE
        ) {
            return $this->fail(__('Your order could not be sent to WiPay. Please try again.'));
        }

        $payment = $order->getPayment();
        $url = (string) $payment->getAdditionalInformation(PaymentRequest::KEY_URL);
        $transactionId = (string) $payment->getAdditionalInformation(PaymentRequest::KEY_TRANSACTION);

        if ($transactionId === '' || !$this->platform->isValidCheckoutUrl($url)) {
            return $this->fail(__('WiPay checkout is not available for this order.'));
        }

        /*
         * Make the awaiting state explicit before the shopper leaves the store,
         * so the return controller has a known starting point and an abandoned
         * attempt stays visible to the merchant instead of looking paid.
         */
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus('pending_payment');
            $order->addCommentToStatusHistory(
                (string) __('Awaiting WiPay payment. Transaction %1.', $transactionId)
            );
            $this->orderRepository->save($order);
        }

        return $this->resultRedirectFactory->create()->setUrl($url);
    }

    /**
     * @param \Magento\Framework\Phrase|string $message
     */
    private function fail($message): Redirect
    {
        $this->messageManager->addErrorMessage($message);

        return $this->resultRedirectFactory->create()->setPath('checkout');
    }
}
