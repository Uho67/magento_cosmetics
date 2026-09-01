<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Api;

use Uho\NovaposhtaCheckout\Api\Data\WarehouseOptionInterface;

interface WarehouseLocatorInterface
{
    /**
     * List working warehouses of an allowed category for one city, optionally narrowed by $query.
     *
     * An unknown or malformed $cityRef yields an empty list rather than an exception;
     * $limit is clamped server-side against the configured maximum.
     *
     * @return WarehouseOptionInterface[]
     */
    public function getForCity(string $cityRef, string $query = '', ?int $limit = null): array;
}
