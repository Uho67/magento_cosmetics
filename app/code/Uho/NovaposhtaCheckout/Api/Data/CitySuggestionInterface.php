<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Api\Data;

/**
 * Read-only projection of a row of perspective_novaposhta_catalog_cities,
 * as offered to the storefront city typeahead.
 */
interface CitySuggestionInterface
{
    public function getRef(): string;

    public function getLabel(): string;
}
