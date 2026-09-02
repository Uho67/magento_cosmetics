<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\BillingAddressManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Uho\NovaposhtaCheckout\Model\Address\AddressExtensionRefApplier;

/**
 * Composes the Nova Poshta billing address before Magento\Quote\Model\BillingAddressManagement
 * validates and saves it (docs §6 B3).
 *
 * Magento_Checkout/js/view/billing-address.js posts to this endpoint (`/billing-address` /
 * `/guest-carts/:cartId/billing-address`) on its own, independently of the shipping step and the
 * final "Place order" submission, whenever the payment step renders and
 * `isAddressSameAsShipping` is true — i.e. on every Nova Poshta checkout, since
 * billing-address-mixin.js forces that flag. The address it sends is the same uncomposed
 * client-side clone of the shipping address described in ShippingInformationManagementPlugin and
 * PaymentInformationManagementPlugin (refs present, city/street empty).
 *
 * Magento\Quote\Model\GuestCart\GuestBillingAddressManagement::assign() delegates to this same
 * interface (constructor-injected), so guest carts are covered without a second plugin — unlike
 * the payment-information pair, where the guest service duplicates the logic instead of
 * delegating.
 */
class BillingAddressManagementPlugin
{
    public function __construct(
        private readonly AddressExtensionRefApplier $addressExtensionRefApplier,
    ) {
    }

    /**
     * @param BillingAddressManagementInterface $subject
     * @return array{0: int, 1: AddressInterface, 2: bool}
     * @throws LocalizedException
     */
    public function beforeAssign(
        BillingAddressManagementInterface $subject,
        int $cartId,
        AddressInterface $address,
        bool $useForShipping = false,
    ): array {
        $this->addressExtensionRefApplier->apply($address);

        return [$cartId, $address, $useForShipping];
    }
}
