var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Uho_NovaposhtaCheckout/js/view/shipping-mixin': true
            },
            'Magento_Checkout/js/view/billing-address': {
                'Uho_NovaposhtaCheckout/js/model/billing-address-mixin': true
            }
        }
    }
};
