<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Tests;

use SilverShop\AddressFormats\AddressFormatRegistry;
use SilverShop\Model\Address;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Core's street hint ("street / thoroughfare number, name, and type or P.O. Box") is hidden for countries with a
 * separate house-number field, and the switcher ships a default row layout.
 */
class StreetHintAndLayoutTest extends SapphireTest
{
    protected $usesDatabase = true;

    private const CORE_HINT = 'street / thoroughfare number, name, and type or P.O. Box';

    private function fields(string $country): FieldList
    {
        return Address::create(['Country' => $country])->getFrontEndFields(['addfielddescriptions' => true]);
    }

    public function testTheStreetHintIsHiddenWhereTheHouseNumberIsSeparate(): void
    {
        $street = $this->fields('NL')->dataFieldByName('Address');

        $this->assertSame('', (string) $street->getDescription());
        $this->assertSame(self::CORE_HINT, $street->getAttribute('data-addressformat-description'), 'kept for switching');
    }

    public function testTheStreetHintStaysForOtherCountries(): void
    {
        $street = $this->fields('BE')->dataFieldByName('Address');

        $this->assertSame(self::CORE_HINT, (string) $street->getDescription());
        $this->assertSame(self::CORE_HINT, $street->getAttribute('data-addressformat-description'));
    }

    public function testOtherHintsAreUntouched(): void
    {
        $this->assertNotSame('', (string) $this->fields('NL')->dataFieldByName('City')->getDescription());
    }

    public function testTheGenericLabelIsKeptForSwitchingBack(): void
    {
        $street = $this->fields('NL')->dataFieldByName('Address');

        $this->assertSame('Straat', (string) $street->Title());
        $this->assertNotSame('', (string) $street->getAttribute('data-addressformat-label'));
        $this->assertNotSame('Straat', $street->getAttribute('data-addressformat-label'));
    }

    public function testWithoutLiveSwitchingTheHintIsSimplyLeftOut(): void
    {
        Config::modify()->set(AddressFormatRegistry::class, 'live_country_switching', false);
        $street = $this->fields('NL')->dataFieldByName('Address');

        $this->assertSame('', (string) $street->getDescription());
        $this->assertNull($street->getAttribute('data-addressformat-description'));
    }

    public function testTheSwitcherRulesListTheHiddenHints(): void
    {
        $rules = AddressFormatRegistry::singleton()->rulesForJs();

        $this->assertSame(['Address'], $rules['NL']['noDescription']);
        $this->assertSame([], $rules['default']['noDescription']);
    }

    public function testTheDefaultRowLayoutCanBeTurnedOff(): void
    {
        $this->assertStringContainsString(':where(.addressformat-field-group)', $this->switcherHtml());

        Config::modify()->set(AddressFormatRegistry::class, 'layout_css', false);
        $this->assertStringNotContainsString(':where(.addressformat-field-group)', $this->switcherHtml());
    }

    private function switcherHtml(): string
    {
        SiteConfig::current_site_config()->write();
        $script = Address::create()->getFrontEndFields()->fieldByName('AddressFormatScript');
        $this->assertNotNull($script);

        return (string) $script->getContent();
    }
}
