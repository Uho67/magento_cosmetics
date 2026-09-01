<?php

declare(strict_types=1);

namespace Uho\Catalog\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * "нове" / "розпродаж" flags for the product listing badge strip
 * (Magento_Catalog::product/list.phtml override in the seed-store theme).
 *
 * "сезонне" is not wired here - it needs a dedicated attribute or a
 * category-based rule that doesn't exist yet (Phase 4 explicitly leaves
 * attribute creation for later); the .-seasonal CSS modifier is ready for
 * whichever source of truth gets picked.
 */
class ProductBadges implements ArgumentInterface
{
    public function __construct(
        private readonly DateTime $dateTime,
    ) {
    }

    public function isNew(Product $product): bool
    {
        $from = $product->getNewsFromDate();
        if (!$from) {
            return false;
        }

        $to = $product->getNewsToDate();
        $now = $this->dateTime->gmtDate();

        return $from <= $now && (!$to || $to >= $now);
    }

    public function isOnSale(Product $product): bool
    {
        $special = $product->getSpecialPrice();

        return $special !== null && (float) $special < (float) $product->getPrice();
    }
}
