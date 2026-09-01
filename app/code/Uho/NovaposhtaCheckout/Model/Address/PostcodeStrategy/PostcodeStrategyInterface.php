<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy;

use Magento\Framework\Exception\LocalizedException;

interface PostcodeStrategyInterface
{
    /**
     * @param string $regionCode ISO 3166-2:UA code already resolved for the address, e.g. "UA-32".
     * @throws LocalizedException
     */
    public function getPostcode(string $regionCode): string;
}
