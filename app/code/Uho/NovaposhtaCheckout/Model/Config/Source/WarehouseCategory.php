<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Values are the distinct category_of_warehouse strings held in
 * perspective_novaposhta_catalog_warehouse, and must match it exactly.
 */
class WarehouseCategory implements OptionSourceInterface
{
    public const string BRANCH = 'Branch';
    public const string STORE = 'Store';
    public const string POSTOMAT = 'Postomat';
    public const string DROP_OFF = 'DropOff';
    public const string FULFILLMENT = 'Fulfillment';

    /**
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::BRANCH, 'label' => __('Branch')],
            ['value' => self::STORE, 'label' => __('Store')],
            ['value' => self::POSTOMAT, 'label' => __('Postomat')],
            ['value' => self::DROP_OFF, 'label' => __('Drop-off point')],
            ['value' => self::FULFILLMENT, 'label' => __('Fulfillment')],
        ];
    }
}
