<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Region\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

class SchemaLocator implements SchemaLocatorInterface
{
    private const string MODULE_NAME = 'Uho_NovaposhtaCheckout';
    private const string SCHEMA_FILE = '/np_region_map.xsd';

    private readonly string $schema;

    public function __construct(ModuleDirReader $moduleReader)
    {
        $this->schema = $moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, self::MODULE_NAME) . self::SCHEMA_FILE;
    }

    public function getSchema(): string
    {
        return $this->schema;
    }

    public function getPerFileSchema(): string
    {
        return $this->schema;
    }
}
