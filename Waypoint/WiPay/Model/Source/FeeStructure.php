<?php
/**
 * Fee structure dropdown for the admin configuration.
 *
 * Matches the three values WiPay documents on the payment request.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FeeStructure implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'merchant_absorb', 'label' => (string) __('Merchant absorbs')],
            ['value' => 'customer_pay', 'label' => (string) __('Customer pays')],
            ['value' => 'split', 'label' => (string) __('Split')],
        ];
    }
}
