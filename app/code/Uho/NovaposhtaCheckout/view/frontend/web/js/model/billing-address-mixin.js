define([
    'Magento_Checkout/js/model/quote'
], function (quote) {
    'use strict';

    /**
     * Nova Poshta is the only active carrier and the warehouse pickup address is the only address
     * the customer chooses, so billing must always mirror shipping — "Force billing = shipping,
     * hide the toggle" (docs §3 decision #1, §6 B4).
     *
     * Two things stop a naive "just set the flag" mixin from actually achieving that:
     *
     * 1. `initObservable()` in the core component does not expose `isAddressSameAsShipping` as a
     *    static default: it seeds it `false`, then a `quote.billingAddress.subscribe` handler
     *    recomputes it on every change by comparing `getCacheKey()` against the shipping address.
     *    Knockout invokes subscribers in registration order, so this mixin appends its own
     *    subscriber *after* calling `_super()` — it always runs after the core handler and
     *    re-asserts `true` on every subsequent billing-address change.
     * 2. Setting the flag alone does not copy the address. Normally a customer click on the
     *    checkbox fires `useShippingAddress()`, which is what actually calls
     *    `select-billing-address` with the shipping address. That checkbox is hidden here (CSS,
     *    see `_module.less` `.billing-address-same-as-shipping-block`), and
     *    `checkout-data-resolver.applyBillingAddress()` can independently select a *different*
     *    address — e.g. a logged-in customer's saved default billing address — before this mixin
     *    gets a chance to react. So both `quote.billingAddress` and `quote.shippingAddress` are
     *    watched, and `useShippingAddress()` is invoked directly (not just the checkbox click
     *    handler) whenever the two addresses diverge. Comparing cache keys before calling it again
     *    is what stops this from looping: `select-billing-address` clones the shipping address
     *    onto `quote.billingAddress`, so the second pass through the handler sees matching cache
     *    keys and does nothing.
     */
    return function (Component) {
        return Component.extend({
            initObservable: function () {
                this._super();

                quote.billingAddress.subscribe(this.uhoForceSameAsShipping, this);
                quote.shippingAddress.subscribe(this.uhoForceSameAsShipping, this);
                this.uhoForceSameAsShipping();

                return this;
            },

            /**
             * @private
             */
            uhoForceSameAsShipping: function () {
                var shippingAddress = quote.shippingAddress(),
                    billingAddress = quote.billingAddress();

                if (quote.isVirtual() || !shippingAddress) {
                    return;
                }

                this.isAddressSameAsShipping(true);

                if (!billingAddress || billingAddress.getCacheKey() !== shippingAddress.getCacheKey()) {
                    this.useShippingAddress();
                }
            }
        });
    };
});
