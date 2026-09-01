<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Test\Unit\Model\Address;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressExtensionInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Uho\NovaposhtaCheckout\Api\AddressComposerInterface;
use Uho\NovaposhtaCheckout\Api\Data\ComposedAddressInterface;
use Uho\NovaposhtaCheckout\Model\Address\AddressExtensionRefApplier;

/**
 * Security regression coverage: a client must never be able to make a submitted
 * `uho_np_city_name` / `uho_np_warehouse_name` / `uho_np_warehouse_site_key` extension attribute
 * survive onto the address, whether or not a Nova Poshta selection (refs) is present.
 */
class AddressExtensionRefApplierTest extends TestCase
{
    private const string CITY_REF = '8d5a980d-391c-11dd-90d9-001a92567626';
    private const string WAREHOUSE_REF = '1ec09d88-e1c2-11e3-8c4a-0050568002cf';

    private AddressComposerInterface&MockObject $addressComposer;
    private StoreManagerInterface&MockObject $storeManager;
    private AddressExtensionRefApplier $applier;

    protected function setUp(): void
    {
        $this->addressComposer = $this->createMock(AddressComposerInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->applier = new AddressExtensionRefApplier($this->addressComposer, $this->storeManager);
    }

    public function testDiscardsClientSuppliedSnapshotAttributesWhenNoRefsArePresent(): void
    {
        $extensionAttributes = $this->createMock(AddressExtensionInterface::class);
        $extensionAttributes->method('getUhoNpCityRef')->willReturn(null);
        $extensionAttributes->method('getUhoNpWarehouseRef')->willReturn(null);
        $extensionAttributes->expects($this->once())->method('setUhoNpCityName')->with(null);
        $extensionAttributes->expects($this->once())->method('setUhoNpWarehouseName')->with(null);
        $extensionAttributes->expects($this->once())->method('setUhoNpWarehouseSiteKey')->with(null);

        $address = $this->createMock(AddressInterface::class);
        $address->method('getExtensionAttributes')->willReturn($extensionAttributes);

        $this->addressComposer->expects($this->never())->method('compose');

        $this->applier->apply($address);
    }

    public function testDiscardsClientSuppliedSnapshotAttributesBeforeComposing(): void
    {
        $extensionAttributes = $this->createMock(AddressExtensionInterface::class);
        $extensionAttributes->method('getUhoNpCityRef')->willReturn(self::CITY_REF);
        $extensionAttributes->method('getUhoNpWarehouseRef')->willReturn(self::WAREHOUSE_REF);
        $extensionAttributes->expects($this->once())->method('setUhoNpCityName')->with(null);
        $extensionAttributes->expects($this->once())->method('setUhoNpWarehouseName')->with(null);
        $extensionAttributes->expects($this->once())->method('setUhoNpWarehouseSiteKey')->with(null);

        // A concrete Address (not a bare AddressInterface mock) is needed here: applyComposedAddress()
        // also writes the snapshot columns via setData(), which the pure interface doesn't declare.
        $address = $this->createMock(Address::class);
        $address->method('getExtensionAttributes')->willReturn($extensionAttributes);
        $address->method('setCountryId')->willReturnSelf();
        $address->method('setCity')->willReturnSelf();
        $address->method('setStreet')->willReturnSelf();
        $address->method('setRegion')->willReturnSelf();
        $address->method('setRegionId')->willReturnSelf();
        $address->method('setPostcode')->willReturnSelf();
        $address->method('setData')->willReturnSelf();

        $composed = $this->createMock(ComposedAddressInterface::class);
        $composed->method('getCountryId')->willReturn('UA');
        $composed->method('getCity')->willReturn('Київ');
        $composed->method('getStreet')->willReturn(['Відділення №1']);
        $composed->method('getRegion')->willReturn('Київська');
        $composed->method('getRegionId')->willReturn(1);
        $composed->method('getPostcode')->willReturn('00000');
        $composed->method('getCityRef')->willReturn(self::CITY_REF);
        $composed->method('getCityName')->willReturn('Київ');
        $composed->method('getWarehouseRef')->willReturn(self::WAREHOUSE_REF);
        $composed->method('getWarehouseName')->willReturn('Відділення №1');
        $composed->method('getWarehouseSiteKey')->willReturn('105');

        $this->addressComposer->expects($this->once())
            ->method('compose')
            ->with(self::CITY_REF, self::WAREHOUSE_REF, 1)
            ->willReturn($composed);

        $this->applier->apply($address);
    }

    public function testThrowsWhenOnlyOneRefIsPresent(): void
    {
        $extensionAttributes = $this->createMock(AddressExtensionInterface::class);
        $extensionAttributes->method('getUhoNpCityRef')->willReturn(self::CITY_REF);
        $extensionAttributes->method('getUhoNpWarehouseRef')->willReturn(null);
        $extensionAttributes->method('setUhoNpCityName');
        $extensionAttributes->method('setUhoNpWarehouseName');
        $extensionAttributes->method('setUhoNpWarehouseSiteKey');

        $address = $this->createMock(AddressInterface::class);
        $address->method('getExtensionAttributes')->willReturn($extensionAttributes);

        $this->addressComposer->expects($this->never())->method('compose');

        $this->expectException(LocalizedException::class);

        $this->applier->apply($address);
    }
}
