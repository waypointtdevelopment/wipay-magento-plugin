<?php
/**
 * Builds the WiPay payment request payload for an order.
 *
 * Mirrors the field rules proven by the sandbox-tested WooCommerce gateway:
 * dependent fields (lname requires fname, addr2 requires addr1) are omitted
 * unless their parent is present, and every value is length-capped to the
 * documented limits.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Gateway\Request;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Waypoint\WiPay\Model\Platform;
use Waypoint\WiPay\Model\Secrets;

class PaymentRequest implements BuilderInterface
{
    /** Payment additional-information keys written by PlaceOrderCommand. */
    public const KEY_URL = 'wipay_url';
    public const KEY_TRANSACTION = 'wipay_transaction_id';
    public const KEY_PINNED_TOTAL = 'wipay_pinned_total';
    public const KEY_STARTED = 'wipay_started';
    public const KEY_ENVIRONMENT = 'wipay_environment';

    private const VERSION = '1.0.0';
    private const ORIGIN = 'WaypointM2';

    private Platform $platform;
    private Secrets $secrets;
    private ScopeConfigInterface $scopeConfig;
    private UrlInterface $urlBuilder;

    public function __construct(
        Platform $platform,
        Secrets $secrets,
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder
    ) {
        $this->platform = $platform;
        $this->secrets = $secrets;
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @param array{payment: PaymentDataObjectInterface, order?: OrderInterface} $commandSubject
     * @return array<string, mixed>
     */
    public function build(array $commandSubject)
    {
        /** @var PaymentDataObjectInterface $paymentData */
        $paymentData = $commandSubject['payment'];
        $order = $paymentData->getOrder();

        $environment = $this->environment();
        $country = $this->country();
        $billing = $order->getBillingAddress();

        $firstName = $billing !== null
            ? trim((string) $billing->getFirstname())
            : trim((string) $order->getCustomerFirstname());
        $lastName = $billing !== null
            ? trim((string) $billing->getLastname())
            : trim((string) $order->getCustomerLastname());

        $street = $billing !== null && is_array($billing->getStreet()) ? $billing->getStreet() : [];
        $address1 = trim((string) ($street[0] ?? ''));
        $address2 = trim((string) ($street[1] ?? ''));

        $payload = [
            'account_number' => (int) $this->accountNumber($environment),
            'country_code' => $country,
            'currency' => strtoupper((string) $order->getOrderCurrencyCode()),
            'environment' => $environment,
            'fee_structure' => $this->feeStructure(),
            'method' => 'credit_card_co',
            // Increment id: alphanumeric, stable, and resolvable on return.
            'order_id' => (string) $order->getIncrementId(),
            'origin' => self::ORIGIN,
            'response_url' => $this->urlBuilder->getUrl('waypoint/payment/response', ['_secure' => true]),
            // Grand total, not base: it must match the currency sent above.
            'total' => $this->formatTotal((float) $order->getGrandTotal()),
            'email' => $this->cap((string) $order->getCustomerEmail(), 50),
            'version' => self::VERSION,
        ];

        $optional = [
            'fname' => [$firstName, 30],
            'lname' => [$lastName, 30],
            'addr1' => [$address1, 50],
            'addr2' => [$address2, 50],
            'city' => [$billing !== null ? trim((string) $billing->getCity()) : '', 30],
            'zipcode' => [$billing !== null ? trim((string) $billing->getPostcode()) : '', 10],
            'country' => [
                $billing !== null ? strtoupper(trim((string) $billing->getCountryId())) : '',
                2,
            ],
        ];

        foreach ($optional as $field => [$value, $limit]) {
            // WiPay rejects a dependent value sent without its parent.
            if ($field === 'lname' && $firstName === '') {
                continue;
            }
            if ($field === 'addr2' && $address1 === '') {
                continue;
            }
            if ($value !== '') {
                $payload[$field] = $this->cap($value, $limit);
            }
        }

        $phone = $billing !== null ? preg_replace('/[^+0-9]/', '', (string) $billing->getTelephone()) : '';
        if ($phone !== '' && preg_match('/^\+[1-9][0-9]{1,14}$/', $phone)) {
            $payload['phone'] = $phone;
        }

        return $payload;
    }

    public function environment(): string
    {
        $value = (string) $this->scopeConfig->getValue('payment/waypoint_wipay/environment');

        return in_array($value, [Platform::ENVIRONMENT_LIVE, Platform::ENVIRONMENT_SANDBOX], true)
            ? $value
            : Platform::ENVIRONMENT_SANDBOX;
    }

    public function country(): string
    {
        return strtoupper((string) $this->scopeConfig->getValue('payment/waypoint_wipay/country_code'));
    }

    public function feeStructure(): string
    {
        $value = (string) $this->scopeConfig->getValue('payment/waypoint_wipay/fee_structure');

        return in_array($value, ['merchant_absorb', 'customer_pay', 'split'], true)
            ? $value
            : 'merchant_absorb';
    }

    /**
     * Sandbox always uses WiPay's published test account.
     */
    public function accountNumber(string $environment): string
    {
        if ($environment === Platform::ENVIRONMENT_SANDBOX) {
            return Platform::SANDBOX_ACCOUNT;
        }

        return $this->secrets->liveAccountNumber();
    }

    /**
     * The response-verification key. Sandbox uses WiPay's published value;
     * live reads the encrypted merchant key from configuration.
     */
    public function apiKey(string $environment): string
    {
        if ($environment === Platform::ENVIRONMENT_SANDBOX) {
            return \Waypoint\WiPay\Model\Signature::SANDBOX_KEY;
        }

        return $this->secrets->liveApiKey();
    }

    public function formatTotal(float $total): string
    {
        return number_format($total, 2, '.', '');
    }

    private function cap(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit);
        }

        return substr($value, 0, $limit);
    }
}
