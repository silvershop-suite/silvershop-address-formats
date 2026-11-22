<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Tests;

use SilverShop\AddressFormats\AddressFormatRegistry;
use SilverShop\AddressFormats\AddressLabels;
use SilverShop\Model\Address;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;

/**
 * Field labels follow the shop's language, not the address's country: a country only chooses the term (Postcode /
 * ZIP code / Eircode, Province, ...). Before, every country was labelled in its own language, so an English shop showed
 * "Straat" / "Plaats" for a Dutch address and a Dutch shop showed "Straße" / "PLZ" for a German one.
 */
class LabelLanguageTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @return array<string, string>
     */
    private function labels(string $country, string $locale): array
    {
        return i18n::with_locale($locale, function () use ($country): array {
            $fields = Address::create(['Country' => $country])->getFrontEndFields();
            $labels = [];
            foreach (['Address', 'HouseNumber', 'City', 'PostalCode', 'State'] as $name) {
                $field = $fields->dataFieldByName($name);
                if ($field) {
                    $labels[$name] = (string) $field->Title();
                }
            }

            return $labels;
        });
    }

    public function testADutchAddressInAnEnglishShop(): void
    {
        $labels = $this->labels('NL', 'en_US');

        $this->assertSame('Street', $labels['Address']);
        $this->assertSame('House no.', $labels['HouseNumber']);
        $this->assertSame('City', $labels['City']);
        $this->assertSame('Postcode', $labels['PostalCode']);
    }

    public function testADutchAddressInADutchShop(): void
    {
        $labels = $this->labels('NL', 'nl_NL');

        $this->assertSame('Straat', $labels['Address']);
        $this->assertSame('Huisnr.', $labels['HouseNumber']);
        $this->assertSame('Plaats', $labels['City']);
        $this->assertSame('Postcode', $labels['PostalCode']);
    }

    public function testAGermanAddressInADutchShop(): void
    {
        $labels = $this->labels('DE', 'nl_NL');

        $this->assertSame('Straat', $labels['Address']);
        $this->assertSame('Huisnr.', $labels['HouseNumber']);
        $this->assertSame('Plaats', $labels['City']);
    }

    public function testTheCountryChoosesTheTerm(): void
    {
        $this->assertSame('ZIP code', $this->labels('US', 'en_US')['PostalCode']);
        $this->assertSame('PLZ', $this->labels('DE', 'de_DE')['PostalCode']);
        $this->assertSame('Province', $this->labels('CA', 'en_US')['State']);
    }

    public function testEveryConceptIsTranslatedInEveryModuleLanguage(): void
    {
        foreach (['en', 'nl', 'de', 'fr', 'it', 'es'] as $language) {
            $file = dirname(__DIR__, 2) . '/lang/' . $language . '.yml';
            $this->assertFileExists($file);
            $yaml = (string) file_get_contents($file);
            foreach (array_keys(AddressLabels::DEFAULTS) as $concept) {
                $this->assertMatchesRegularExpression(
                    '/^    ' . $concept . ': /m',
                    $yaml,
                    $language . ' translates ' . $concept
                );
            }
        }
    }

    public function testAPlainTextLabelFromProjectConfigIsShownAsWritten(): void
    {
        Config::modify()->merge(AddressFormatRegistry::class, 'formats', [
            'NL' => ['labels' => ['City' => 'Woonplaats (gemeente)']],
        ]);

        $this->assertSame('Woonplaats (gemeente)', $this->labels('NL', 'en_US')['City']);
    }
}
