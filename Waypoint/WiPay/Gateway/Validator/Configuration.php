<?php
/**
 * Validates that the module's configuration can actually reach WiPay.
 *
 * Registered as both the "availability" and "global" validator: in both cases
 * the question is the same - is this store configured for a WiPay platform
 * and environment we can safely use? When it is not, the payment method is
 * withheld from checkout instead of failing halfway through order placement.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Gateway\Validator;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Waypoint\WiPay\Model\Platform;
use Waypoint\WiPay\Model\Secrets;

class Configuration extends AbstractValidator
{
    private Platform $platform;
    private Secrets $secrets;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ResultInterfaceFactory $resultFactory,
        Platform $platform,
        Secrets $secrets,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($resultFactory);
        $this->platform = $platform;
        $this->secrets = $secrets;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param array<string, mixed> $validationSubject
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $failures = [];

        $environment = $this->environment();
        $country = strtoupper((string) $this->scopeConfig->getValue('payment/waypoint_wipay/country_code'));

        if (!$this->platform->isSupported($country)) {
            $failures[] = (string) __('The configured WiPay platform is not recognised.');
        }

        if ($environment === Platform::ENVIRONMENT_LIVE) {
            if ($this->secrets->liveAccountNumber() === '') {
                $failures[] = (string) __('A live WiPay account number is required for live checkout.');
            }
            if ($this->secrets->liveApiKey() === '') {
                $failures[] = (string) __('A live WiPay response-verification key is required for live checkout.');
            }
        }

        return $this->resultFactory->create([
            'isValid' => $failures === [],
            'failsDescription' => $failures,
            'errorCodes' => array_fill(0, count($failures), 'wipay_unconfigured'),
        ]);
    }

    private function environment(): string
    {
        $value = (string) $this->scopeConfig->getValue('payment/waypoint_wipay/environment');

        return in_array($value, [Platform::ENVIRONMENT_LIVE, Platform::ENVIRONMENT_SANDBOX], true)
            ? $value
            : Platform::ENVIRONMENT_SANDBOX;
    }
}
