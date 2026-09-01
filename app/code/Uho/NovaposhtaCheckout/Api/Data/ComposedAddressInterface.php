<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Api\Data;

/**
 * Server-derived Magento address for a Nova Poshta warehouse pickup.
 *
 * Everything here is computed from the local Nova Poshta catalog tables; none of
 * it is ever accepted from the storefront.
 */
interface ComposedAddressInterface
{
    public function getCountryId(): string;

    public function getCity(): string;

    /**
     * @return string[]
     */
    public function getStreet(): array;

    /**
     * Region as free text, i.e. the Nova Poshta settlement_area_description.
     */
    public function getRegion(): string;

    public function getRegionId(): int;

    public function getPostcode(): string;

    public function getCityRef(): string;

    public function getCityName(): string;

    public function getWarehouseRef(): string;

    public function getWarehouseName(): string;

    public function getWarehouseSiteKey(): string;
}
