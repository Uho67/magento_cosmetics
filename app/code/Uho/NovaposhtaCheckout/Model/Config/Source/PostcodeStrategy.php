<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

class PostcodeStrategy implements OptionSourceInterface
{
    public const string SENTINEL = 'sentinel';
    public const string OBLAST_CENTRE = 'oblast_centre';

    /**
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SENTINEL, 'label' => __('Sentinel (always 00000)')],
            ['value' => self::OBLAST_CENTRE, 'label' => __('Oblast centre postcode')],
        ];
    }
}
