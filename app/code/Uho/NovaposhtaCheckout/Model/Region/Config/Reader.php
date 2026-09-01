<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Region\Config;

use Magento\Framework\Config\Dom;
use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\Reader\Filesystem;
use Magento\Framework\Config\ValidationStateInterface;

class Reader extends Filesystem
{
    /**
     * @var array<string, string>
     */
    protected $_idAttributes = [
        '/config/areas/area' => 'name',
        '/config/cityOverrides/cityOverride' => 'cityRef',
    ];

    /**
     * @param array<string, string> $idAttributes
     */
    public function __construct(
        FileResolverInterface $fileResolver,
        Converter $converter,
        SchemaLocator $schemaLocator,
        ValidationStateInterface $validationState,
        string $fileName = 'np_region_map.xml',
        array $idAttributes = [],
        string $domDocumentClass = Dom::class,
        string $defaultScope = 'global',
    ) {
        parent::__construct(
            $fileResolver,
            $converter,
            $schemaLocator,
            $validationState,
            $fileName,
            $idAttributes,
            $domDocumentClass,
            $defaultScope,
        );
    }
}
