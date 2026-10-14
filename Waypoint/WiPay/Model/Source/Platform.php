<?php
/**
 * WiPay platform dropdown for the admin configuration.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Waypoint\WiPay\Model\Platform as PlatformModel;

class Platform implements OptionSourceInterface
{
    private PlatformModel $platform;

    public function __construct(PlatformModel $platform)
    {
        $this->platform = $platform;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray()
    {
        $options = [];

        foreach ($this->platform->labels() as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return $options;
    }
}
