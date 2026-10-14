/**
 * Waypoint WiPay checkout renderer.
 *
 * Places the order, then navigates to Magento's own Start controller, which
 * redirects the browser to the WiPay checkout URL that was stored server-side
 * when the order was placed. The browser never receives that URL from this
 * component.
 *
 * @license GPL-2.0-or-later
 */
define([
    'Magento_Checkout/js/view/payment/default'
], function (Component) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Waypoint_WiPay/payment/waypoint_wipay'
        },

        /**
         * Magento must not send the shopper to the success page: the payment
         * has not happened yet. afterPlaceOrder() owns the navigation.
         */
        redirectAfterPlaceOrder: false,

        initialize: function () {
            this._super();

            var config = this.getMethodConfig();
            this.description = config.description || '';

            return this;
        },

        /**
         * @returns {Object}
         */
        getMethodConfig: function () {
            if (window.checkoutConfig
                && window.checkoutConfig.payment
                && window.checkoutConfig.payment.waypoint_wipay
            ) {
                return window.checkoutConfig.payment.waypoint_wipay;
            }

            return {};
        },

        /**
         * @returns {String} URL of our own Start controller.
         */
        getRedirectUrl: function () {
            return this.getMethodConfig().redirectUrl || '/checkout/';
        },

        /**
         * Runs after the place-order call succeeds. With
         * redirectAfterPlaceOrder false, this is the only navigation.
         *
         * @returns {Void}
         */
        afterPlaceOrder: function () {
            window.location.assign(this.getRedirectUrl());
        }
    });
});
