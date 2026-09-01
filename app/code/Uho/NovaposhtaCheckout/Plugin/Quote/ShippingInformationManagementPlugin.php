<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Plugin\Quote;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Uho\NovaposhtaCheckout\Model\Address\AddressExtensionRefApplier;

/**
 * Composes the Nova Poshta shipping address before Magento validates it (docs §6 B3).
 *
 * `before` (not `around`) is correct: it runs ahead of
 * Magento\Quote\Model\QuoteAddressValidator inside saveAddressInformation(), so validation sees a
 * complete, well-formed address and core validation is never relaxed. Mutating the address object
 * returned by getShippingAddress() in place is enough — ShippingInformationManagement re-reads it
 * via the same reference immediately after this plugin returns.
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

        return [$cartId, $addressInformation];
    }
}
