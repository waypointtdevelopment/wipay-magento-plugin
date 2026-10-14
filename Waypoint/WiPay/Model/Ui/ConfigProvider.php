<?php
/**
 * Exposes gateway presentation data and the redirect URL to checkout.
 *
 * The URL points at a Magento-owned Start controller. The actual WiPay
 * checkout URL stays server-side on the order payment; the browser only ever
 * navigates to our own origin.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model\Ui;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Checkout\Model\ConfigProviderInterface;

class ConfigProvider implements ConfigProviderInterface
{
    private ScopeConfigInterface $scopeConfig;
    private UrlInterface $urlBuilder;

    public function __construct(ScopeConfigInterface $scopeConfig, UrlInterface $urlBuilder)
    {
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig()
    {
        return [
            'payment' => [
                'waypoint_wipay' => [
                    'title' => $this->text('title', 'Credit card (WiPay)'),
                    'description' => $this->text(
                        'description',
                        'You will complete payment on WiPay’s secure hosted checkout.'
                    ),
                    'redirectUrl' => $this->urlBuilder->getUrl(
                        'waypoint/payment/start',
                        ['_secure' => true]
                    ),
                ],
            ],
        ];
    }

    private function text(string $field, string $default): string
    {
        $value = trim((string) $this->scopeConfig->getValue('payment/waypoint_wipay/' . $field));

        return $value !== '' ? $value : $default;
    }
}
