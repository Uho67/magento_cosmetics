<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy;

/**
 * Always returns 00000.
 *
 * It satisfies Magento's UA validation (^[0-9]{5}$) while being unambiguously
 * synthetic, so nothing downstream mistakes it for a genuine postal index.
 * Nova Poshta routes by warehouse ref, not by index.
 */
class SentinelStrategy implements PostcodeStrategyInterface
{
    public const string POSTCODE = '00000';

    public function getPostcode(string $regionCode): string
    {
        return self::POSTCODE;
    }
}
