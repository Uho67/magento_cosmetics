<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Address;

use Uho\NovaposhtaCheckout\Api\Data\ComposedAddressInterface;

class ComposedAddress implements ComposedAddressInterface
{
    /**
     * @param string[] $street
     */
    public function __construct(
        private readonly string $countryId,
        private readonly string $city,
        private readonly array $street,
        private readonly string $region,
        private readonly int $regionId,
        private readonly string $postcode,
        private readonly string $cityRef,
        private readonly string $cityName,
        private readonly string $warehouseRef,
        private readonly string $warehouseName,
        private readonly string $warehouseSiteKey,
    ) {
    }

    public function getCountryId(): string
    {
        return $this->countryId;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    /**
     * @return string[]
     */
    public function getStreet(): array
    {
        return $this->street;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getRegionId(): int
    {
        return $this->regionId;
    }

    public function getPostcode(): string
    {
        return $this->postcode;
    }

    public function getCityRef(): string
    {
        return $this->cityRef;
    }

    public function getCityName(): string
    {
        return $this->cityName;
    }

    public function getWarehouseRef(): string
    {
        return $this->warehouseRef;
    }

    public function getWarehouseName(): string
    {
        return $this->warehouseName;
    }

    public function getWarehouseSiteKey(): string
    {
        return $this->warehouseSiteKey;
    }
}
