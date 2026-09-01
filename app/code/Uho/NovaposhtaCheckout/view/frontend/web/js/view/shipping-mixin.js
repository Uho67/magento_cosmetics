define([
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/select-shipping-method',
    'Magento_Checkout/js/checkout-data'
], function (quote, selectShippingMethodAction, checkoutData) {
    'use strict';

    /**
     * Nova Poshta is the only active carrier, so `rates()` always resolves to exactly one
     * method (or zero, for a virtual quote). Auto-selecting it removes the need for a radio
     * button; the silent shipping-method-list template (see layout XML) removes the table.
     */
    return function (Shipping) {
        return Shipping.extend({
            initialize: function () {
                this._super();

                this.rates.subscribe(this.uhoAutoSelectRate, this);
                this.uhoAutoSelectRate(this.rates());

                return this;
            },

            /**
             * `!quote.shippingMethod()` lets a restored selection (page reload, browser
             * back/forward — see `Magento_Checkout/js/checkout-data`) win, and stops this from
             * re-firing on every rate refresh triggered by `estimate-shipping-methods`.
             *
             * @param {Array} rates
             */
            uhoAutoSelectRate: function (rates) {
                if (!rates || rates.length !== 1 || quote.shippingMethod()) {
                    return;
                }

                selectShippingMethodAction(rates[0]);
                checkoutData.setSelectedShippingRate(rates[0]['carrier_code'] + '_' + rates[0]['method_code']);
            }
        });
    };
});
