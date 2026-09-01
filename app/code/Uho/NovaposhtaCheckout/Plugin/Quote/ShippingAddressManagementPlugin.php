<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Model\ShippingAddressManagementInterface;
use Uho\NovaposhtaCheckout\Model\Address\AddressExtensionRefApplier;

/**
 * Composes the Nova Poshta shipping address before Magento\Quote\Model\ShippingAddressManagement
 * validates and saves it (docs §6 B3).
 *
 * This is the estimate-rates / GraphQL / REST guest-cart path: ShippingInformationManagement
 * covers `saveAddressInformation`, but `ShippingAddressManagement::assign()` is a separate entry
 * point (used e.g. by `setShippingAddressesOnCart`) that reaches the same
 * `$quoteAddress->addData($address->getData())` merge, so it needs the same treatment.
 * `Magento\Quote\Model\GuestCart\GuestShippingAddressManagement` delegates to this same
 * interface, so guest carts are covered without a second plugin.
 */
class ShippingAddressManagementPlugin
{
    public function __construct(
        private readonly AddressExtensionRefApplier $addressExtensionRefApplier,
    ) {
    }

    /**
     * @param ShippingAddressManagementInterface $subject
     * @return array{0: int, 1: AddressInterface}
     * @throws LocalizedException
     */
    public function beforeAssign(
        ShippingAddressManagementInterface $subject,
        int $cartId,
        AddressInterface $address,
    ): array {
        $this->addressExtensionRefApplier->apply($address);

        return [$cartId, $address];
    }
}
