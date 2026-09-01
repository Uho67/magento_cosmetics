<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Locator;

use Uho\NovaposhtaCheckout\Api\Data\CitySuggestionInterface;

class CitySuggestion implements CitySuggestionInterface
{
    public function __construct(
        private readonly string $ref,
        private readonly string $label,
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
}
