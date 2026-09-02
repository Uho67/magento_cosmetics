<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Plugin\Checkout;

use Magento\Checkout\Api\PaymentInformationManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Uho\NovaposhtaCheckout\Model\Address\AddressExtensionRefApplier;

/**
 * Composes the Nova Poshta billing address before Magento validates it (docs §6 B3).
 *
 * The storefront's "Place order" button (Magento_Checkout/js/action/place-order) never goes
 * through Magento\Quote\Model\ShippingAddressManagement or
 * Magento\Quote\Model\BillingAddressManagement — it posts straight to
 * `/carts/mine/payment-information`, i.e. PaymentInformationManagement::
 * savePaymentInformationAndPlaceOrder(), which calls savePaymentInformation() and then
 * `$quote->setBillingAddress($billingAddress)` directly, validating it via
 * QuoteAddressValidationService before either of ShippingInformationManagementPlugin or
 * ShippingAddressManagementPlugin ever runs. Since billing-address-mixin.js forces billing to be
 * a client-side clone of the (still uncomposed) shipping address, that billing address reaches
 * the server with the uho_np_city_ref/uho_np_warehouse_ref extension attributes but empty
 * city/street — which is what core validation was rejecting.
 *
 * `before` on savePaymentInformation() (not on savePaymentInformationAndPlaceOrder()) is enough:
 * the latter calls the former on `$this`, and plugins intercept that self-call too because `$this`
 * is the generated interceptor instance.
 */
class PaymentInformationManagementPlugin
{
    public function __construct(
        private readonly AddressExtensionRefApplier $addressExtensionRefApplier,
    ) {
    }

    /**
     * @param PaymentInformationManagementInterface $subject
     * @param int $cartId
     * @param PaymentInterface $paymentMethod
     * @param AddressInterface|null $billingAddress
     * @return array{0: int, 1: PaymentInterface, 2: AddressInterface|null}
     * @throws LocalizedException
     */
    public function beforeSavePaymentInformation(
        PaymentInformationManagementInterface $subject,
        $cartId,
        PaymentInterface $paymentMethod,
        ?AddressInterface $billingAddress = null,
    ): array {
        if ($billingAddress !== null) {
            $this->addressExtensionRefApplier->apply($billingAddress);
        }

        return [$cartId, $paymentMethod, $billingAddress];
    }
}
