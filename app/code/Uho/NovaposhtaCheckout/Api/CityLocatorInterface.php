<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Api;

use Uho\NovaposhtaCheckout\Api\Data\CitySuggestionInterface;

interface CityLocatorInterface
{
    /**
     * Prefix-match cities by name in the current store locale.
     *
     * Queries shorter than the minimum length yield an empty list; $limit is clamped
     * server-side against the configured maximum and is never trusted as given.
     *
     * @return CitySuggestionInterface[]
     */
    public function search(string $query, ?int $limit = null): array;
}
