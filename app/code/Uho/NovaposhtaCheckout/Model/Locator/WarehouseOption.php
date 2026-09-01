<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Locator;

use Uho\NovaposhtaCheckout\Api\Data\WarehouseOptionInterface;

class WarehouseOption implements WarehouseOptionInterface
{
    public function __construct(
        private readonly string $ref,
        private readonly string $label,
        private readonly string $number,
        private readonly string $siteKey,
    ) {
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getSiteKey(): string
    {
        return $this->siteKey;
    }
}
