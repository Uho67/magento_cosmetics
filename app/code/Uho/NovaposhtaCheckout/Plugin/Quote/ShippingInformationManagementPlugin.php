<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Plugin\Quote;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Uho\NovaposhtaCheckout\Model\Address\AddressExtensionRefApplier;

/**
 * Composes the Nova Poshta shipping AND billing address before Magento validates them (docs §6
 * B3).
 *
 * `before` (not `around`) is correct: it runs ahead of
 * Magento\Quote\Model\QuoteAddressValidator inside saveAddressInformation(), so validation sees a
 * complete, well-formed address and core validation is never relaxed. Mutating the address objects
 * returned by getShippingAddress()/getBillingAddress() in place is enough —
 * ShippingInformationManagement re-reads them via the same references immediately after this
 * plugin returns.
 *
 * getBillingAddress() must be composed too: billing-address-mixin.js forces billing to mirror
 * shipping, but only as a client-side clone of the still-uncomposed shipping address model — city
 * and street are never filled in on the client, only the uho_np_city_ref/uho_np_warehouse_ref
 * extension attributes are. ShippingInformationManagement::saveAddressInformation() validates
 * getShippingAddress() and getBillingAddress() as two separate AddressInterface instances, so
 * composing only the former (as this plugin did previously) left the latter's city/street empty
 * and core's AbstractAddress::validate() rejected it as "Місто"/"Адреса" required.
 */
class ShippingInformationManagementPlugin
{
    public function __construct(
        private readonly AddressExtensionRefApplier $addressExtensionRefApplier,
    ) {
    }

    /**
     * @param ShippingInformationManagementInterface $subject
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return array{0: int, 1: ShippingInformationInterface}
     * @throws LocalizedException
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagementInterface $subject,
        $cartId,
        ShippingInformationInterface $addressInformation,
    ): array {
        $this->addressExtensionRefApplier->apply($addressInformation->getShippingAddress());

        $billingAddress = $addressInformation->getBillingAddress();
        if ($billingAddress !== null) {
            $this->addressExtensionRefApplier->apply($billingAddress);
        }

        return [$cartId, $addressInformation];
    }
}
