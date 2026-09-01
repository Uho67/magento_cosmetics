<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Test\Unit\Model\Address;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Perspective\NovaposhtaCatalog\Api\CityRepositoryInterface;
use Perspective\NovaposhtaCatalog\Model\City\City;
use Perspective\NovaposhtaCatalog\Model\ResourceModel\Warehouse\Warehouse\Collection as WarehouseCollection;
use Perspective\NovaposhtaCatalog\Model\ResourceModel\Warehouse\Warehouse\CollectionFactory
    as WarehouseCollectionFactory;
use Uho\NovaposhtaCheckout\Api\Data\ComposedAddressInterface;
use Uho\NovaposhtaCheckout\Model\Address\ComposedAddress;
use Uho\NovaposhtaCheckout\Model\Address\ComposedAddressFactory;
use Uho\NovaposhtaCheckout\Model\Address\Composer;
use Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy\OblastCentreStrategy;
use Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy\SentinelStrategy;
use Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy\StrategyPool;
use Uho\NovaposhtaCheckout\Model\Config;
use Uho\NovaposhtaCheckout\Model\Config\Source\PostcodeStrategy as PostcodeStrategySource;
use Uho\NovaposhtaCheckout\Model\Region\Resolver as RegionResolver;

class ComposerTest extends TestCase
{
    private const string KYIV_CITY_REF = '8d5a980d-391c-11dd-90d9-001a92567626';
    private const string KYIV_WAREHOUSE_REF = '1ec09d88-e1c2-11e3-8c4a-0050568002cf';
    private const string VILLAGE_CITY_REF = 'e71c2a15-4b33-11e4-ab6d-005056801329';
    private const string VILLAGE_WAREHOUSE_REF = '7b422fbe-e1b8-11e3-8c4a-0050568002cf';

    private CityRepositoryInterface&MockObject $cityRepository;
    private WarehouseCollectionFactory&MockObject $warehouseCollectionFactory;
    private RegionResolver&MockObject $regionResolver;
    private Config&MockObject $config;
    private StrategyPool $strategyPool;
    private Composer $composer;

    protected function setUp(): void
    {
        $this->cityRepository = $this->createMock(CityRepositoryInterface::class);
        $this->warehouseCollectionFactory = $this->createMock(WarehouseCollectionFactory::class);
        $this->regionResolver = $this->createMock(RegionResolver::class);
        $this->config = $this->createMock(Config::class);
        $this->strategyPool = new StrategyPool([
            PostcodeStrategySource::SENTINEL => new SentinelStrategy(),
            PostcodeStrategySource::OBLAST_CENTRE => new OblastCentreStrategy(),
        ]);

        $this->config->method('getPostcodeStrategy')->willReturn(PostcodeStrategySource::SENTINEL);
        $this->config->method('getWarehouseCategories')->willReturn(['Branch', 'Store', 'Postomat', 'DropOff']);

        $this->composer = new Composer(
            $this->cityRepository,
            $this->warehouseCollectionFactory,
            $this->regionResolver,
            $this->strategyPool,
            $this->config,
            $this->createComposedAddressFactory(),
        );
    }

    public function testComposesAKyivWarehouseAddressWithTheCityOverrideRegion(): void
    {
        $this->givenCity(self::KYIV_CITY_REF, 4016, 'Київ');
        $this->givenWarehouse([
            'id' => 17222,
            'site_key' => '105',
            'description_ua' => 'Відділення №1: вул. Пирогівський шлях, 135',
            'city_description_ua' => 'Київ',
            'settlement_area_description' => 'Київська',
        ]);

        $this->regionResolver->expects($this->once())
            ->method('resolveRegionCode')
            ->with('Київська', self::KYIV_CITY_REF, 17222)
            ->willReturn('UA-30');
        $this->regionResolver->method('resolveRegionId')->with('UA-30')->willReturn(1110);

        $address = $this->composer->compose(self::KYIV_CITY_REF, self::KYIV_WAREHOUSE_REF);

        $this->assertSame('UA', $address->getCountryId());
        $this->assertSame('Київ', $address->getCity());
        $this->assertSame(['Відділення №1: вул. Пирогівський шлях, 135'], $address->getStreet());
        $this->assertSame('Київська', $address->getRegion());
        $this->assertSame(1110, $address->getRegionId());
        $this->assertSame('00000', $address->getPostcode());
        $this->assertSame(self::KYIV_CITY_REF, $address->getCityRef());
        $this->assertSame('Київ', $address->getCityName());
        $this->assertSame(self::KYIV_WAREHOUSE_REF, $address->getWarehouseRef());
        $this->assertSame('Відділення №1: вул. Пирогівський шлях, 135', $address->getWarehouseName());
        $this->assertSame('105', $address->getWarehouseSiteKey());
    }

    public function testComposesAVillageAddressWithAPlainOblastRegion(): void
    {
        $this->givenCity(self::VILLAGE_CITY_REF, 9001, 'Гайове');
        $this->givenWarehouse([
            'id' => 40551,
            'site_key' => '108587',
            'description_ua' => 'Поштомат "Нова Пошта" №44666: вул. Білоуська, 4А',
            'city_description_ua' => 'Гайове',
            'settlement_area_description' => 'Чернігівська',
        ]);

        $this->regionResolver->method('resolveRegionCode')
            ->with('Чернігівська', self::VILLAGE_CITY_REF, 40551)
            ->willReturn('UA-74');
        $this->regionResolver->method('resolveRegionId')->with('UA-74')->willReturn(1086);

        $address = $this->composer->compose(self::VILLAGE_CITY_REF, self::VILLAGE_WAREHOUSE_REF);

        $this->assertSame('Гайове', $address->getCity());
        $this->assertSame(['Поштомат "Нова Пошта" №44666: вул. Білоуська, 4А'], $address->getStreet());
        $this->assertSame('Чернігівська', $address->getRegion());
        $this->assertSame(1086, $address->getRegionId());
        $this->assertSame('00000', $address->getPostcode());
        $this->assertSame(
            '108587',
            $address->getWarehouseSiteKey(),
            'A six-digit site_key must survive verbatim; it is deliberately not used as a postcode.'
        );
    }

    public function testFallsBackToTheWarehouseCityNameAndRussianDescription(): void
    {
        $this->givenCity(self::VILLAGE_CITY_REF, 9001, '');
        $this->givenWarehouse([
            'id' => 5,
            'site_key' => '1',
            'description_ua' => '',
            'description_ru' => 'Отделение №5',
            'short_address_ua' => 'вул. Коротка, 5',
            'city_description_ua' => 'Гайове',
            'settlement_area_description' => 'Сумська',
        ]);

        $this->regionResolver->method('resolveRegionCode')->willReturn('UA-59');
        $this->regionResolver->method('resolveRegionId')->willReturn(1102);

        $address = $this->composer->compose(self::VILLAGE_CITY_REF, self::VILLAGE_WAREHOUSE_REF);

        $this->assertSame('Гайове', $address->getCity());
        $this->assertSame(['Отделение №5'], $address->getStreet());
    }

    public function testAnUnmappedAreaRejectsTheSelection(): void
    {
        $this->givenCity(self::VILLAGE_CITY_REF, 9001, 'Гайове');
        $this->givenWarehouse([
            'id' => 777,
            'site_key' => '9',
            'description_ua' => 'Відділення №9',
            'city_description_ua' => 'Гайове',
            'settlement_area_description' => 'Новоземельська',
        ]);

        $this->regionResolver->method('resolveRegionCode')
            ->willThrowException(new LocalizedException(__('unmapped')));

        $this->expectException(LocalizedException::class);

        $this->composer->compose(self::VILLAGE_CITY_REF, self::VILLAGE_WAREHOUSE_REF);
    }

    public function testUsesTheConfiguredOblastCentreStrategyWhenSelected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getPostcodeStrategy')->willReturn(PostcodeStrategySource::OBLAST_CENTRE);
        $config->method('getWarehouseCategories')->willReturn([]);

        $composer = new Composer(
            $this->cityRepository,
            $this->warehouseCollectionFactory,
            $this->regionResolver,
            $this->strategyPool,
            $config,
            $this->createComposedAddressFactory(),
        );

        $this->givenCity(self::KYIV_CITY_REF, 4016, 'Київ');
        $this->givenWarehouse([
            'id' => 17222,
            'site_key' => '105',
            'description_ua' => 'Відділення №1',
            'city_description_ua' => 'Київ',
            'settlement_area_description' => 'Київська',
        ]);
        $this->regionResolver->method('resolveRegionCode')->willReturn('UA-30');
        $this->regionResolver->method('resolveRegionId')->willReturn(1110);

        $this->assertSame('01001', $composer->compose(self::KYIV_CITY_REF, self::KYIV_WAREHOUSE_REF)->getPostcode());
    }

    /**
     * @param string $ref
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRefProvider')]
    public function testRejectsRefsThatAreNotNovaPoshtaGuids(string $ref): void
    {
        $this->cityRepository->expects($this->never())->method('getCityByCityRef');
        $this->warehouseCollectionFactory->expects($this->never())->method('create');

        $this->expectException(NoSuchEntityException::class);

        $this->composer->compose($ref, self::KYIV_WAREHOUSE_REF);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidRefProvider(): array
    {
        return [
            'empty' => [''],
            'vendor error pseudo option' => ['-502'],
            'sql injection attempt' => ["' OR '1'='1"],
            'truncated guid' => ['8d5a980d-391c-11dd-90d9'],
        ];
    }

    public function testRejectsAWarehouseRefThatIsNotAGuid(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->composer->compose(self::KYIV_CITY_REF, '-502');
    }

    public function testRejectsACityRefThatDoesNotExistLocally(): void
    {
        $city = $this->createMock(City::class);
        $city->method('getId')->willReturn(null);
        $this->cityRepository->method('getCityByCityRef')->willReturn($city);

        $this->expectException(NoSuchEntityException::class);

        $this->composer->compose(self::KYIV_CITY_REF, self::KYIV_WAREHOUSE_REF);
    }

    public function testRejectsAWarehouseThatDoesNotBelongToTheCity(): void
    {
        $this->givenCity(self::KYIV_CITY_REF, 4016, 'Київ');
        $this->givenWarehouse([]);

        $this->expectException(NoSuchEntityException::class);

        $this->composer->compose(self::KYIV_CITY_REF, self::KYIV_WAREHOUSE_REF);
    }

    public function testWarehouseQueryIsScopedByCityStatusAndCategory(): void
    {
        $this->givenCity(self::KYIV_CITY_REF, 4016, 'Київ');

        $filters = [];
        $collection = $this->createMock(WarehouseCollection::class);
        $collection->method('addFieldToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')
            ->willReturnCallback(static function ($field, $condition) use (&$filters, $collection) {
                $filters[$field] = $condition;

                return $collection;
            });
        $collection->expects($this->once())->method('setPageSize')->with(1)->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(new DataObject([
            'id' => 17222,
            'site_key' => '105',
            'description_ua' => 'Відділення №1',
            'city_description_ua' => 'Київ',
            'settlement_area_description' => 'Київська',
        ]));

        $this->warehouseCollectionFactory->method('create')->willReturn($collection);
        $this->regionResolver->method('resolveRegionCode')->willReturn('UA-30');
        $this->regionResolver->method('resolveRegionId')->willReturn(1110);

        $this->composer->compose(self::KYIV_CITY_REF, self::KYIV_WAREHOUSE_REF);

        $this->assertSame(self::KYIV_CITY_REF, $filters['city_ref']);
        $this->assertSame(self::KYIV_WAREHOUSE_REF, $filters['ref']);
        $this->assertSame('Working', $filters['warehouse_status']);
        $this->assertSame(['in' => ['Branch', 'Store', 'Postomat', 'DropOff']], $filters['category_of_warehouse']);
    }

    private function givenCity(string $ref, int $id, string $descriptionUa): void
    {
        $city = $this->createMock(City::class);
        $city->method('getId')->willReturn($id);
        $city->method('getRef')->willReturn($ref);
        $city->method('getDescriptionUa')->willReturn($descriptionUa);

        $this->cityRepository->method('getCityByCityRef')->with($ref)->willReturn($city);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function givenWarehouse(array $data): void
    {
        $collection = $this->createMock(WarehouseCollection::class);
        $collection->method('addFieldToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(new DataObject($data));

        $this->warehouseCollectionFactory->method('create')->willReturn($collection);
    }

    private function createComposedAddressFactory(): ComposedAddressFactory&MockObject
    {
        $factory = $this->createMock(ComposedAddressFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): ComposedAddressInterface => new ComposedAddress(
                $data['countryId'],
                $data['city'],
                $data['street'],
                $data['region'],
                $data['regionId'],
                $data['postcode'],
                $data['cityRef'],
                $data['cityName'],
                $data['warehouseRef'],
                $data['warehouseName'],
                $data['warehouseSiteKey'],
            )
        );

        return $factory;
    }
}
