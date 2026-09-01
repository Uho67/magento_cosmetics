<?php

declare(strict_types=1);

namespace Uho\NovaposhtaShipping\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * Nova Poshta manual (offline) carrier.
 *
 * Offers exactly one shipping method: pick-up from a Nova Poshta warehouse. The waybill (TTN) is
 * created by hand in the Nova Poshta business account, therefore this carrier neither tracks
 * shipments nor talks to any remote service.
 *
 * GUARDRAIL: this class must never gain an HTTP client, a cURL adapter or any other API-capable
 * dependency. It is deliberately limited to the injected configuration and rate factories so that
 * calling api.novaposhta.ua / novapost.com is structurally impossible.
 */
class NovaposhtaManual extends AbstractCarrier implements CarrierInterface
{
    public const string CARRIER_CODE = 'uho_novaposhta';

    public const string METHOD_CODE = 'pickup';

    /**
     * @var string
     */
    protected $_code = self::CARRIER_CODE;

    /**
     * Rates are not derived from weight or dimensions.
     *
     * @var bool
     */
    protected $_isFixed = true;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        array $data = [],
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request): Result|false
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();
        $result->append($this->createMethod($request));

        return $result;
    }

    /**
     * Get the single shipping method this carrier offers.
     *
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [self::METHOD_CODE => (string) $this->getConfigData('name')];
    }

    /**
     * Tracking is unavailable: the waybill is created manually in the Nova Poshta account.
     */
    public function isTrackingAvailable(): bool
    {
        return false;
    }

    private function createMethod(RateRequest $request): Method
    {
        $price = $this->resolvePrice($request);

        /** @var Method $method */
        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $method->setMethod(self::METHOD_CODE);
        $method->setMethodTitle($this->getConfigData('name'));
        $method->setPrice($price);
        $method->setCost($price);

        return $method;
    }

    /**
     * Resolve the shipping price, honouring cart price rules and the free shipping threshold.
     *
     * @param RateRequest $request
     * @return float
     */
    private function resolvePrice(RateRequest $request): float
    {
        if ($request->getFreeShipping() || $this->isFreeShippingThresholdReached($request)) {
            return 0.0;
        }

        return (float) $this->getFinalPriceWithHandlingFee((float) $this->getConfigData('price'));
    }

    private function isFreeShippingThresholdReached(RateRequest $request): bool
    {
        if (!$this->getConfigFlag('free_shipping_enable')) {
            return false;
        }

        return (float) $request->getPackageValueWithDiscount()
            >= (float) $this->getConfigData('free_shipping_subtotal');
    }
}
