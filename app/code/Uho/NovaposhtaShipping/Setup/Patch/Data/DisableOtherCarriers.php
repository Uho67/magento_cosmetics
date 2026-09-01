<?php

declare(strict_types=1);

namespace Uho\NovaposhtaShipping\Setup\Patch\Data;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

/**
 * Disable the offline carriers that would otherwise compete with Nova Poshta.
 *
 * Nova Poshta must be the only shipping method offered, so the rate list contains exactly one entry
 * and can be auto-selected without a radio button.
 *
 * An explicit `0` is written rather than deleting the rows: `carriers/flatrate/active` defaults to
 * `1` in Magento_OfflineShipping's config.xml, so removing the row would silently re-enable it.
 */
class DisableOtherCarriers implements DataPatchInterface
{
    /**
     * Config paths forced to `0` at default scope.
     */
    private const array DISABLED_CARRIER_PATHS = [
        'carriers/flatrate/active',
        'carriers/tablerate/active',
    ];

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
    ) {
    }

    public function apply(): self
    {
        foreach (self::DISABLED_CARRIER_PATHS as $path) {
            $this->configWriter->save(
                $path,
                '0',
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
