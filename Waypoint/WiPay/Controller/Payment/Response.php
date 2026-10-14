<?php
/**
 * Handles the redirect WiPay sends the shopper back to.
 *
 * Nothing here trusts the query string. The order is resolved by the increment
 * id that was sent to WiPay, then every stored reference is re-checked: the
 * transaction must match the one recorded when checkout started, the pinned
 * total must still equal the order total, the order must still be awaiting
 * payment, and the documented hash must verify server-side before an invoice
 * is registered.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Waypoint\WiPay\Gateway\Request\PaymentRequest;
use Waypoint\WiPay\Model\Platform;
use Waypoint\WiPay\Model\Secrets;
use Waypoint\WiPay\Model\Signature;

class Response extends Action implements HttpGetActionInterface
{
    /** Statuses that mean the attempt definitively did not succeed. */
    private const FAILURE_STATUSES = ['failed', 'cancelled', 'canceled', 'error'];

    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private InvoiceService $invoiceService;
    private TransactionFactory $transactionFactory;
    private Signature $signature;
    private Secrets $secrets;
    private Platform $platform;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        Signature $signature,
        Secrets $secrets,
        Platform $platform
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->signature = $signature;
        $this->secrets = $secrets;
        $this->platform = $platform;
    }

    public function execute(): ResultInterface
    {
        $incrementId = trim((string) $this->getRequest()->getParam('order_id', ''));
        $transactionId = trim((string) $this->getRequest()->getParam('transaction_id', ''));
        $status = strtolower(trim((string) $this->getRequest()->getParam('status', '')));
        $hash = strtolower(trim((string) $this->getRequest()->getParam('hash', '')));

        if ($incrementId === '' || $transactionId === '') {
            return $this->fail(__('This payment could not be matched to an order.'));
        }

        $order = $this->loadByIncrementId($incrementId);

        if ($order === null
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== Start::METHOD_CODE
        ) {
            return $this->fail(__('This payment could not be matched to an order.'));
        }

        $payment = $order->getPayment();

        $started = $payment->getAdditionalInformation(PaymentRequest::KEY_STARTED);
        $expectedTransaction = (string) $payment->getAdditionalInformation(PaymentRequest::KEY_TRANSACTION);
        $pinnedTotal = (string) $payment->getAdditionalInformation(PaymentRequest::KEY_PINNED_TOTAL);
        $environment = (string) $payment->getAdditionalInformation(PaymentRequest::KEY_ENVIRONMENT);

        // A returned transaction that differs from the recorded one is not ours.
        if (empty($started)
            || $expectedTransaction === ''
            || !hash_equals($expectedTransaction, $transactionId)
        ) {
            return $this->reject($order, 'Transaction reference did not match this order.');
        }

        // Repeated returns for an order already paid are idempotent.
        if ($this->isPaid($order)) {
            return $this->redirect('checkout/onepage/success');
        }

        if ($status !== 'success') {
            /*
             * Only an explicit failure status cancels anything. A missing or
             * unknown status may just mean the shopper closed the tab, so the
             * order stays awaiting payment and can be retried.
             */
            if (in_array($status, self::FAILURE_STATUSES, true)) {
                $this->cancel($order, (string) __('WiPay reported that this payment attempt did not succeed.'));
                return $this->fail(__('WiPay did not complete this payment. Your order has not been paid.'));
            }

            return $this->fail(__('No payment result was received yet. Your order has not been paid.'));
        }

        if ($pinnedTotal === '') {
            return $this->reject($order, 'No pinned total was recorded for this attempt.');
        }

        $secret = $environment === Platform::ENVIRONMENT_LIVE
            ? $this->secrets->liveApiKey()
            : Signature::SANDBOX_KEY;

        if (!$this->signature->verify($transactionId, $pinnedTotal, $secret, $hash)) {
            return $this->reject($order, 'WiPay response failed hash verification.');
        }

        // The amount we pinned must still be the amount on the order.
        $orderTotal = $this->signature->formatTotal((float) $order->getGrandTotal());
        if ($orderTotal !== $this->signature->formatTotal($pinnedTotal)) {
            return $this->reject($order, 'Pinned total no longer matches the order total.');
        }

        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return $this->reject($order, 'Order was no longer awaiting payment.');
        }

        $this->markPaid($order, $transactionId);

        return $this->redirect('checkout/onepage/success');
    }

    /**
     * @return Order|null
     */
    private function loadByIncrementId(string $incrementId): ?Order
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->create();

        $items = $this->orderRepository->getList($searchCriteria)->getItems();

        if (empty($items)) {
            return null;
        }

        $order = array_values($items)[0];

        return $order instanceof Order ? $order : null;
    }

    private function isPaid(Order $order): bool
    {
        return in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true);
    }

    /**
     * Register the invoice now that payment is verified server-side.
     */
    private function markPaid(Order $order, string $transactionId): void
    {
        $payment = $order->getPayment();
        $payment->setLastTransId($transactionId);
        $payment->setTransactionId($transactionId);

        $invoice = $this->invoiceService->prepareInvoice($order);

        if ($invoice !== null && $invoice->getTotalQty() > 0) {
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $invoice->getOrder()->setCustomerNoteNotify(false);

            $transaction = $this->transactionFactory->create()
                ->addObject($invoice)
                ->addObject($invoice->getOrder());
            $transaction->save();
        }

        if ($order->getState() !== Order::STATE_PROCESSING) {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus('processing');
        }

        $order->addCommentToStatusHistory(
            (string) __('WiPay payment verified for transaction %1.', $transactionId)
        );
        $this->orderRepository->save($order);
    }

    private function cancel(Order $order, string $reason): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            return;
        }

        try {
            $order->registerCancellation($reason);
            $this->orderRepository->save($order);
        } catch (LocalizedException $e) {
            $order->addCommentToStatusHistory($e->getMessage());
            $this->orderRepository->save($order);
        }
    }

    /**
     * Record why verification failed, leave the order unpaid, and tell the shopper.
     */
    private function reject(Order $order, string $reason): Redirect
    {
        $order->addCommentToStatusHistory(
            (string) __('WiPay return rejected: %1 The order remains unpaid.', $reason)
        );
        $this->orderRepository->save($order);

        return $this->fail(__('This payment could not be verified. Your order has not been paid.'));
    }

    private function fail(Phrase $message): Redirect
    {
        $this->messageManager->addErrorMessage($message);

        return $this->redirect('checkout/cart');
    }

    private function redirect(string $route): Redirect
    {
        return $this->resultRedirectFactory->create()->setPath($route);
    }
}
