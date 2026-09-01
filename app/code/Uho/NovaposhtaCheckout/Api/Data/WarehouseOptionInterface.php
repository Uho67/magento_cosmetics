<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Api\Data;

/**
 * Read-only projection of a row of perspective_novaposhta_catalog_warehouse,
 * as offered to the storefront warehouse selector.
 */
interface WarehouseOptionInterface
{
    public function getRef(): string;

    public function getLabel(): string;

    public function getNumber(): string;

    public function getSiteKey(): string;
}
