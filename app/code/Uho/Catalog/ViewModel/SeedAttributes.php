<?php

declare(strict_types=1);

namespace Uho\Catalog\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * PDP icon+label mini-blocks for seed-specific facts. Reads products by
 * attribute code, so this stays inert (renders nothing) until those EAV
 * attributes actually exist - Phase 4 confirmed the set and codes below
 * (2026-09-01) but explicitly left creating the attributes for later.
 *
 * Create these as text/varchar (or a short select for sowing_period) product
 * attributes with these exact codes and the mini-blocks activate with no
 * further theme changes:
 *   harvest_days      Строк дозрівання   e.g. "60-70 днів"
 *   sowing_depth      Глибина посіву     e.g. "1-2 см"
 *   sowing_period     Період посіву      e.g. "квітень-травень"
 *   germination_rate  Схожість           e.g. "85%"
 *   pack_weight       Вага пакета        e.g. "1 г"
 */
class SeedAttributes implements ArgumentInterface
{
    private const array ATTRIBUTES = [
        ['code' => 'harvest_days', 'icon' => 'timer', 'label' => 'Строк дозрівання'],
        ['code' => 'sowing_depth', 'icon' => 'depth', 'label' => 'Глибина посіву'],
        ['code' => 'sowing_period', 'icon' => 'calendar', 'label' => 'Період посіву'],
        ['code' => 'germination_rate', 'icon' => 'check-circle', 'label' => 'Схожість'],
        ['code' => 'pack_weight', 'icon' => 'weight', 'label' => 'Вага пакета'],
    ];

    public function getAttributes(Product $product): array
    {
        $result = [];

        foreach (self::ATTRIBUTES as $attribute) {
            $value = $product->getData($attribute['code']);
            if ($value === null || $value === '') {
                continue;
            }

            $result[] = $attribute + ['value' => $value];
        }

        return $result;
    }
}
