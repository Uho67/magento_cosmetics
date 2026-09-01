<?php

declare(strict_types=1);

namespace Uho\Store\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The removed perspectiveteam/module-novaposhtashipping package left product attributes
 * whose backend_model points at classes that no longer exist, which makes setup:upgrade
 * fail during "data recurring" with:
 *
 *   Class "Perspective\NovaposhtaShipping\Model\Product\Attribute\Backend\Width" does not exist
 *
 * The attributes themselves are harmless (decimal, user-defined, no stored values), so the
 * backend_model reference is cleared rather than dropping the attributes and touching
 * product data.
 */
class RemoveOrphanedNovaposhtaAttributeBackendModels implements DataPatchInterface
{
    private const string ORPHANED_BACKEND_MODEL_PATTERN = '%NovaposhtaShipping%';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();

        $connection->update(
            $this->moduleDataSetup->getTable('eav_attribute'),
            ['backend_model' => null],
            $connection->quoteInto('backend_model LIKE ?', self::ORPHANED_BACKEND_MODEL_PATTERN),
        );

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
