<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Uho\NovaposhtaCheckout\Api\Data\ComposedAddressInterface;

/**
 * The single point where a Nova Poshta city + warehouse pair becomes a Magento address.
 */
interface AddressComposerInterface
{
    /**
     * @param string $cityRef perspective_novaposhta_catalog_cities.ref
     * @param string $warehouseRef perspective_novaposhta_catalog_warehouse.ref
     * @throws NoSuchEntityException When either ref does not resolve to a selectable local record.
     * @throws LocalizedException When the region or postcode cannot be derived.
     */
    public function compose(string $cityRef, string $warehouseRef, ?int $storeId = null): ComposedAddressInterface;
}
