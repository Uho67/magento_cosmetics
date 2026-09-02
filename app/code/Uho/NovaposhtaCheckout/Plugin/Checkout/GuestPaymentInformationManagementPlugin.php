<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Plugin\Checkout;

use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Uho\NovaposhtaCheckout\Model\Address\AddressExtensionRefApplier;

/**
 * Guest-checkout twin of PaymentInformationManagementPlugin (docs §6 B3).
 *
 * Magento\Checkout\Model\GuestPaymentInformationManagement::savePaymentInformation() does NOT
 * delegate to the injected Magento\Checkout\Api\PaymentInformationManagementInterface — it
 * duplicates that class's validation and `$quote->setBillingAddress($billingAddress)` logic
 * inline. So plugging PaymentInformationManagementInterface alone (as
 * PaymentInformationManagementPlugin does) leaves the guest "Place order" journey
 * (`/guest-carts/:cartId/payment-information`) uncomposed and failing the same
 * "Місто"/"Адреса" required-value validation.
 */
class GuestPaymentInformationManagementPlugin
{
    public function __construct(
        private readonly AddressExtensionRefApplier $addressExtensionRefApplier,
    ) {
    }

    /**
     * @param GuestPaymentInformationManagementInterface $subject
     * @param string $cartId
     * @param string $email
     * @param PaymentInterface $paymentMethod
     * @param AddressInterface|null $billingAddress
     * @return array{0: string, 1: string, 2: PaymentInterface, 3: AddressInterface|null}
     * @throws LocalizedException
     */
    public function beforeSavePaymentInformation(
        GuestPaymentInformationManagementInterface $subject,
        $cartId,
        $email,
        PaymentInterface $paymentMethod,
        ?AddressInterface $billingAddress = null,
    ): array {
        if ($billingAddress !== null) {
            $this->addressExtensionRefApplier->apply($billingAddress);
        }

        return [$cartId, $email, $paymentMethod, $billingAddress];
    }
}
