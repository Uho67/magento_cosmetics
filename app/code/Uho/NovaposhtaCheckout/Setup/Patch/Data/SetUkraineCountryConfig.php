<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Setup\Patch\Data;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

/**
 * Nova Poshta only ever ships within Ukraine, but `general/country/default` has no row in
 * `core_config_data` and therefore falls back to Magento's own default of `US`. Left as-is,
 * checkout defaults the (hidden) country selector to the US, which is then composed over by
 * {@see \Uho\NovaposhtaCheckout\Model\Address\Composer} for the shipping address — but the
 * billing form, the country/region dropdowns Magento validates against, and the shipping
 * origin used for tax/rate calculation are unaffected by that composer and stay wrong.
 *
 * Written at default scope only. Both websites (`base` and `pr` / Проросток, store `pr_ua`)
 * were checked for existing scope-specific overrides on these paths before writing this patch:
 * neither has one, so the default-scope value already applies to both and no per-website rows
 * are needed.
 */
class SetUkraineCountryConfig implements DataPatchInterface
{
    private const string UKRAINE_COUNTRY_CODE = 'UA';

    private const array UKRAINE_COUNTRY_PATHS = [
        'general/country/default',
        'general/country/allow',
        'general/country/destinations',
        'shipping/origin/country_id',
    ];

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
    ) {
    }

    public function apply(): self
    {
        foreach (self::UKRAINE_COUNTRY_PATHS as $path) {
            $this->configWriter->save(
                $path,
                self::UKRAINE_COUNTRY_CODE,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                Store::DEFAULT_STORE_ID
            );
        }

        $this->reinitableConfig->reinit();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
