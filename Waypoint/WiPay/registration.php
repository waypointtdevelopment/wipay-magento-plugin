<?php
/**
 * Register the Waypoint WiPay module with Magento.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Waypoint_WiPay',
    __DIR__
);
