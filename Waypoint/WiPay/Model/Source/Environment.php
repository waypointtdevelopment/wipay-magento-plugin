<?php
/**
 * Environment dropdown for the admin configuration.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Waypoint\WiPay\Model\Platform;

class Environment implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => Platform::ENVIRONMENT_SANDBOX,
                'label' => (string) __('Sandbox (testing only)'),
            ],
            [
                'value' => Platform::ENVIRONMENT_LIVE,
                'label' => (string) __('Live (requires verified live setup)'),
            ],
        ];
    }
}
