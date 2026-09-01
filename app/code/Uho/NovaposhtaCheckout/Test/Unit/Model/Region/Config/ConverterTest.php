<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Test\Unit\Model\Region\Config;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Uho\NovaposhtaCheckout\Model\Region\Config\Converter;

class ConverterTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testConvertsAreasAndCityOverrides(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML(
            <<<XML
            <?xml version="1.0"?>
            <config>
                <areas>
                    <area name="Київська" regionCode="UA-32"/>
                    <area name="Львівська" regionCode="UA-46"/>
                </areas>
                <cityOverrides>
                    <cityOverride cityRef="8D5A980D-391C-11DD-90D9-001A92567626" regionCode="UA-30"/>
                </cityOverrides>
            </config>
            XML
        );

        $result = $this->converter->convert($dom);

        $this->assertSame(
            ['Київська' => 'UA-32', 'Львівська' => 'UA-46'],
            $result[Converter::KEY_AREAS]
        );
        $this->assertSame(
            ['8d5a980d-391c-11dd-90d9-001a92567626' => 'UA-30'],
            $result[Converter::KEY_CITY_OVERRIDES],
            'City refs must be normalised to lower case so the lookup is case-insensitive.'
        );
    }

    public function testReturnsEmptyStructureForAnEmptyConfig(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML('<?xml version="1.0"?><config/>');

        $this->assertSame(
            [Converter::KEY_AREAS => [], Converter::KEY_CITY_OVERRIDES => []],
            $this->converter->convert($dom)
        );
    }
}
