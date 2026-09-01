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
use Uho\NovaposhtaCheckout\Api\CityLocatorInterface;
use Uho\NovaposhtaCheckout\Controller\Ajax\CitySearch;
use Uho\NovaposhtaCheckout\Model\Locator\CitySuggestion;

class CitySearchTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private JsonFactory&MockObject $resultJsonFactory;
    private CityLocatorInterface&MockObject $cityLocator;
    private LoggerInterface&MockObject $logger;
    private CitySearch $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->resultJsonFactory = $this->createMock(JsonFactory::class);
        $this->cityLocator = $this->createMock(CityLocatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new CitySearch(
            $this->request,
            $this->resultJsonFactory,
            $this->cityLocator,
            $this->logger,
        );
    }

    public function testPassesQueryAndClampedLimitThroughToTheLocator(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['q', '', 'Ки'],
                ['limit', null, '5'],
            ]);

        $this->cityLocator->expects($this->once())
            ->method('search')
            ->with('Ки', 5)
            ->willReturn([new CitySuggestion('ref-1', 'Київ')]);

        $json = $this->createMock(Json::class);
        $json->expects($this->once())
            ->method('setData')
            ->with([['ref' => 'ref-1', 'label' => 'Київ']])
            ->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller->execute();
    }

    public function testMissingLimitIsPassedAsNullSoTheLocatorAppliesTheConfiguredDefault(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['q', '', 'Льві'],
                ['limit', null, null],
            ]);

        $this->cityLocator->expects($this->once())
            ->method('search')
            ->with('Льві', null)
            ->willReturn([]);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller->execute();
    }

    public function testALocatorFailureIsLoggedAndYieldsAnEmptyListRatherThanAFatalError(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['q', '', 'Ки'],
            ['limit', null, null],
        ]);

        $this->cityLocator->method('search')->willThrowException(new RuntimeException('db down'));
        $this->logger->expects($this->once())->method('error');

        $json = $this->createMock(Json::class);
        $json->expects($this->once())->method('setData')->with([])->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller->execute();
    }
}
