/**
 * Registers the Waypoint WiPay method renderer with checkout.
 *
 * Referenced from the layout "renders" group. This component only pushes onto
 * the renderer list; the actual method UI lives in the renderer it names.
 *
 * @license GPL-2.0-or-later
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'waypoint_wipay',
        component: 'Waypoint_WiPay/js/view/payment/method-renderer/waypoint_wipay'
    });

    return Component.extend({});
});
