<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Tests;

use SilverShop\AddressFormats\Extension\OrderAddressFieldExtension;
use SilverShop\Model\Address;
use SilverStripe\Dev\SapphireTest;

/**
 * The glue that formats the order-admin address cards per country. The extension method only reads the address
 * fields, so the fixtures stay in memory (no write / validation needed).
 */
class OrderAddressFieldExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testNlFormatOmitsState(): void
    {
        $lines = $this->linesFor([
            'Address' => 'Keizersgracht',
            'HouseNumber' => '123',
            'PostalCode' => '1015 CJ',
            'City' => 'Amsterdam',
            'State' => 'Noord-Holland',
            'Country' => 'NL',
        ]);

        $this->assertContains('Keizersgracht 123', $lines);
        $this->assertContains('1015 CJ Amsterdam', $lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('Noord-Holland', $line, 'The NL format drops State even when set.');
        }
    }

    public function testUsFormatPacksCityStateZip(): void
    {
        $lines = $this->linesFor([
            'Address' => '350 Fifth Avenue',
            'City' => 'New York',
            'State' => 'NY',
            'PostalCode' => '10118',
            'Country' => 'US',
        ]);

        $this->assertContains('New York, NY 10118', $lines);
    }

    /**
     * @param array<string, string> $data
     * @return list<string>
     */
    private function linesFor(array $data): array
    {
        $address = Address::create();
        foreach ($data as $field => $value) {
            $address->$field = $value;
        }

        $lines = [];
        (new OrderAddressFieldExtension())->updateAddressLines($lines, $address);

        return $lines;
    }
}
