<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Test\Unit\Controller\Ajax;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Uho\NovaposhtaCheckout\Api\WarehouseLocatorInterface;
use Uho\NovaposhtaCheckout\Controller\Ajax\WarehouseList;
use Uho\NovaposhtaCheckout\Model\Locator\WarehouseOption;

class WarehouseListTest extends TestCase
{
    private const string KYIV_CITY_REF = '8d5a980d-391c-11dd-90d9-001a92567626';

    private RequestInterface&MockObject $request;
    private JsonFactory&MockObject $resultJsonFactory;
    private WarehouseLocatorInterface&MockObject $warehouseLocator;
    private LoggerInterface&MockObject $logger;
    private WarehouseList $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->resultJsonFactory = $this->createMock(JsonFactory::class);
        $this->warehouseLocator = $this->createMock(WarehouseLocatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new WarehouseList(
            $this->request,
            $this->resultJsonFactory,
            $this->warehouseLocator,
            $this->logger,
        );
    }

    public function testPassesCityRefQueryAndLimitThroughToTheLocator(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['cityRef', '', self::KYIV_CITY_REF],
            ['q', '', 'Хрещатик'],
            ['limit', null, '10'],
        ]);

        $this->warehouseLocator->expects($this->once())
            ->method('getForCity')
            ->with(self::KYIV_CITY_REF, 'Хрещатик', 10)
            ->willReturn([new WarehouseOption('wh-ref', 'Відділення №1', '1', '20405')]);

        $json = $this->createMock(Json::class);
        $json->expects($this->once())
            ->method('setData')
            ->with([['ref' => 'wh-ref', 'label' => 'Відділення №1', 'number' => '1', 'siteKey' => '20405']])
            ->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller->execute();
    }

    public function testAnEmptyCityRefNeverReachesTheLocator(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['cityRef', '', ''],
            ['q', '', ''],
            ['limit', null, null],
        ]);

        $this->warehouseLocator->expects($this->never())->method('getForCity');

        $json = $this->createMock(Json::class);
        $json->expects($this->once())->method('setData')->with([])->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller->execute();
    }

    public function testALocatorFailureIsLoggedAndYieldsAnEmptyListRatherThanAFatalError(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['cityRef', '', self::KYIV_CITY_REF],
            ['q', '', ''],
            ['limit', null, null],
        ]);

        $this->warehouseLocator->method('getForCity')->willThrowException(new RuntimeException('db down'));
        $this->logger->expects($this->once())->method('error');

        $json = $this->createMock(Json::class);
        $json->expects($this->once())->method('setData')->with([])->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller->execute();
    }
}
