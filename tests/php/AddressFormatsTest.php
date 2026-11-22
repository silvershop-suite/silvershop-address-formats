<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Tests;

use SilverShop\AddressFormats\AddressFormatRegistry;
use SilverShop\AddressFormats\AddressFormatter;
use SilverShop\AddressFormats\Provider\AddressingRuleProvider;
use SilverShop\Model\Address;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class AddressFormatsTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testRegistryMergesCountryOverDefault(): void
    {
        $registry = AddressFormatRegistry::singleton();

        $nl = $registry->formatFor('nl'); // lower-case tolerated
        $this->assertTrue($nl->isHidden('State'));
        $this->assertFalse($nl->isRequired('State'));
        $this->assertSame('Postcode', $nl->labelFor('PostalCode', 'Postal Code'));
        $this->assertTrue($nl->postcodeValid('1011 AB'));
        $this->assertFalse($nl->postcodeValid('ABCDE'));

        $us = $registry->formatFor('US');
        $this->assertFalse($us->isHidden('State'));
        $this->assertTrue($us->isRequired('State'));

        $nz = $registry->formatFor('NZ');
        $this->assertTrue($nz->isHidden('State'));
        $this->assertTrue($nz->postcodeValid('1010'));
        $this->assertFalse($nz->postcodeValid('10101'));

        $unknown = $registry->formatFor('ZZ');
        $this->assertSame(['Address', 'City', 'PostalCode'], $unknown->getRequired());
    }

    public function testExpandedCountryCoverage(): void
    {
        $registry = AddressFormatRegistry::singleton();

        $es = $registry->formatFor('ES');
        $this->assertSame('Postal code', $es->labelFor('PostalCode', 'x'), 'in the shop language (en here)');
        $this->assertTrue($es->isHidden('State'));
        $this->assertTrue($es->postcodeValid('28013'));

        $pl = $registry->formatFor('PL');
        $this->assertTrue($pl->postcodeValid('00-950'));
        $this->assertFalse($pl->postcodeValid('00950'));

        $ca = $registry->formatFor('CA');
        $this->assertTrue($ca->isRequired('State'));
        $this->assertSame('Province', $ca->labelFor('State', 'x'));
    }

    public function testAddressRequiredFieldsAreCountryAware(): void
    {
        $nl = Address::create(['Country' => 'NL']);
        $required = $nl->getRequiredFields();
        $this->assertArrayNotHasKey('State', $required, 'State must not be required for NL');
        $this->assertArrayHasKey('PostalCode', $required);
        $this->assertArrayHasKey('City', $required);

        $us = Address::create(['Country' => 'US']);
        $this->assertArrayHasKey('State', $us->getRequiredFields(), 'State stays required for US');
    }

    public function testFrontEndFieldsKeepAndMarkHiddenWithLiveSwitching(): void
    {
        // Default: live_country_switching on → hidden fields stay in the DOM, marked, and the switcher is injected.
        $nl = Address::create(['Country' => 'NL']);
        $fields = $nl->getFrontEndFields();

        $state = $fields->dataFieldByName('State');
        $this->assertNotNull($state, 'State kept in the DOM with live switching on');
        $this->assertStringContainsString('addressformat-hidden', (string) $state->extraClass());
        $this->assertSame('State', $state->getAttribute('data-addressformat-field'));

        $postal = $fields->dataFieldByName('PostalCode');
        $this->assertSame('Postcode', $postal->Title());
        $this->assertNotSame('', (string) $postal->getAttribute('pattern'), 'postcode pattern set for NL');

        $this->assertNotNull($fields->fieldByName('AddressFormatScript'), 'switcher script injected');
    }

    public function testFrontEndFieldsRemoveHiddenWhenLiveSwitchingOff(): void
    {
        Config::modify()->set(AddressFormatRegistry::class, 'live_country_switching', false);

        $nl = Address::create(['Country' => 'NL']);
        $fields = $nl->getFrontEndFields();

        $this->assertNull($fields->dataFieldByName('State'), 'State removed when live switching is off');
        $this->assertNull($fields->fieldByName('AddressFormatScript'), 'no switcher when off');
        $this->assertSame('Postcode', $fields->dataFieldByName('PostalCode')->Title());
    }

    public function testRulesForJs(): void
    {
        $rules = AddressFormatRegistry::singleton()->rulesForJs();

        $this->assertArrayHasKey('default', $rules);
        $this->assertArrayHasKey('NL', $rules);
        $this->assertContains('State', $rules['NL']['hidden']);
        $this->assertNotSame('', $rules['NL']['postcodePattern']);
        $this->assertSame('Postcode', $rules['NL']['labels']['PostalCode']);
        $this->assertContains('State', $rules['US']['required']);
    }

    public function testHtmlPostcodePatternStripsDelimitersAndAnchors(): void
    {
        $pattern = AddressFormatRegistry::singleton()->formatFor('NL')->htmlPostcodePattern();
        $this->assertSame('[0-9]{4}\s?[A-Za-z]{2}', $pattern);
    }

    public function testPostcodeValidationOnModel(): void
    {
        $base = ['Country' => 'NL', 'Address' => 'Straat', 'HouseNumber' => '1', 'City' => 'Amsterdam'];

        $bad = Address::create($base + ['PostalCode' => 'ABCDE']);
        $this->assertFalse($bad->validate()->isValid(), 'invalid NL postcode rejected');

        $good = Address::create($base + ['PostalCode' => '1011 AB']);
        $this->assertTrue($good->validate()->isValid(), 'valid NL postcode accepted');

        // Country without a regex (unknown) never fails on the pattern.
        $zz = Address::create(['Country' => 'ZZ', 'Address' => 'x', 'City' => 'y', 'PostalCode' => 'whatever']);
        $this->assertTrue($zz->validate()->isValid());
    }

    public function testPostcodeNormalisedOnWrite(): void
    {
        $nl = Address::create(['Country' => 'NL', 'Address' => 'Straat', 'HouseNumber' => '1', 'City' => 'Amsterdam', 'PostalCode' => '1011ab']);
        $nl->write();
        $this->assertSame('1011 AB', $nl->PostalCode, 'NL postcode normalised to 4 digits + space + upper letters');

        $ca = Address::create(['Country' => 'CA', 'Address' => '1 St', 'City' => 'Toronto', 'State' => 'ON', 'PostalCode' => 'm5h2n2']);
        $ca->write();
        $this->assertSame('M5H 2N2', $ca->PostalCode);

        $gb = Address::create(['Country' => 'GB', 'Address' => '1 St', 'City' => 'London', 'PostalCode' => 'sw1a 1aa']);
        $gb->write();
        $this->assertSame('SW1A 1AA', $gb->PostalCode);
    }

    public function testHouseNumberFieldForOptedInCountry(): void
    {
        $nl = Address::create(['Country' => 'NL']);
        $fields = $nl->getFrontEndFields();

        $hn = $fields->dataFieldByName('HouseNumber');
        $this->assertNotNull($hn, 'HouseNumber field added for NL');
        $this->assertSame('House no.', $hn->Title(), 'labels follow the shop language (en here)');
        $this->assertStringNotContainsString('addressformat-hidden', (string) $hn->extraClass(), 'shown for NL');
        $this->assertSame('Street', $fields->dataFieldByName('Address')->Title());
        $this->assertArrayHasKey('HouseNumber', $nl->getRequiredFields());

        $us = Address::create(['Country' => 'US']);
        $usFields = $us->getFrontEndFields();
        $usHn = $usFields->dataFieldByName('HouseNumber');
        $this->assertNotNull($usHn, 'HouseNumber kept in the DOM (live switching) for US');
        $this->assertStringContainsString('addressformat-hidden', (string) $usHn->extraClass(), 'hidden for US');
        $this->assertArrayNotHasKey('HouseNumber', $us->getRequiredFields());
    }

    public function testCmsFieldsHaveConventionalOrder(): void
    {
        // updateCMSFields reorders the admin Address edit form (member addresses + order-admin clone-on-write edit)
        // into a conventional sequence, instead of the raw $db order (Country/State/City first, name last, the
        // extension's HouseNumber appended at the very end).
        $address = Address::create(['Country' => 'NL']);
        $managed = [
            'FirstName', 'Surname', 'Company',
            'Address', 'HouseNumber', 'AddressLine2',
            'PostalCode', 'City', 'State', 'Country',
            'Phone',
        ];
        $fields = $address->getCMSFields();
        $rendered = array_values(array_filter(
            array_keys($fields->dataFields()),
            static fn (string $name): bool => in_array($name, $managed, true)
        ));
        $this->assertSame($managed, $rendered);

        // HouseNumber gets a translated admin-locale label, not the raw scaffold "House Number".
        $this->assertSame('House number', $fields->dataFieldByName('HouseNumber')->Title());
    }

    public function testNlHiddenFieldsAndLayoutRules(): void
    {
        // Server still hides State + AddressLine2 for NL (kept in the DOM, marked for the switcher) and tags every
        // managed field so the client switcher can relabel / hide / require / lay them out.
        $nl = Address::create(['Country' => 'NL']);
        $fields = $nl->getFrontEndFields();

        foreach (['AddressLine2', 'State'] as $name) {
            $field = $fields->dataFieldByName($name);
            $this->assertNotNull($field, "$name kept in the DOM with live switching on");
            $this->assertStringContainsString(
                'addressformat-hidden',
                (string) $field->extraClass(),
                "$name is hidden for NL"
            );
        }
        $this->assertSame('Address', $fields->dataFieldByName('Address')->getAttribute('data-addressformat-field'));
        $this->assertSame('PostalCode', $fields->dataFieldByName('PostalCode')->getAttribute('data-addressformat-field'));

        // The per-country row layout the switcher applies (the checkout component flattens server composites, so this
        // is driven client-side from rulesForJs): NL pairs street+house and postcode+city, with narrow members flagged.
        $rules = AddressFormatRegistry::singleton()->rulesForJs();
        $this->assertSame([['Address', 'HouseNumber'], ['PostalCode', 'City']], $rules['NL']['groups']);
        $this->assertSame(['HouseNumber', 'PostalCode'], $rules['NL']['narrow']);

        // Non-configured countries get no row grouping (US unaffected).
        $this->assertSame([], $rules['US']['groups']);
        $this->assertSame([], $rules['US']['narrow']);
    }

    public function testHouseNumberComposedIntoAddressString(): void
    {
        $nl = Address::create([
            'Country' => 'NL',
            'Address' => 'Voorbeeldstraat',
            'HouseNumber' => '1',
            'City' => 'Amsterdam',
            'PostalCode' => '1011 AB',
        ]);
        $this->assertStringContainsString('Voorbeeldstraat 1', $nl->toString());

        $line = AddressFormatter::singleton()->format(
            ['Address' => 'Voorbeeldstraat', 'HouseNumber' => '1', 'PostalCode' => '1011 AB', 'City' => 'Amsterdam', 'Country' => 'NL'],
            'NL'
        );
        $this->assertStringContainsString('Voorbeeldstraat 1', $line);
    }

    public function testRulesForJsHidesHouseNumberWhereUnused(): void
    {
        $rules = AddressFormatRegistry::singleton()->rulesForJs();
        $this->assertContains('HouseNumber', $rules['US']['hidden']);
        $this->assertNotContains('HouseNumber', $rules['NL']['hidden']);
    }

    public function testAddressingRuleProviderGivesGlobalCoverage(): void
    {
        if (!AddressingRuleProvider::available()) {
            $this->markTestSkipped('commerceguys/addressing is not installed');
        }

        $registry = AddressFormatRegistry::singleton();

        // Config mode (default): a country absent from YAML has no postcode rule.
        $this->assertSame('', $registry->formatFor('BR')->getPostcodeRegex());

        // Addressing mode: libaddressinput fills it in globally.
        Config::modify()->set(AddressFormatRegistry::class, 'rule_provider', 'addressing');
        $br = $registry->formatFor('BR');
        $this->assertNotSame('', $br->getPostcodeRegex(), 'BR postcode pattern from libaddressinput');
        $this->assertTrue($br->postcodeValid('01310-100'));
        $this->assertFalse($br->isHidden('State'), 'BR uses an administrative area');

        // YAML per-country overrides still win over the dataset.
        $this->assertTrue($registry->formatFor('NL')->hasHouseNumber(), 'NL YAML override applied over addressing');
    }

    public function testAddressingRuleProviderShape(): void
    {
        if (!AddressingRuleProvider::available()) {
            $this->markTestSkipped('commerceguys/addressing is not installed');
        }

        $rules = AddressingRuleProvider::singleton()->rulesFor('US');
        $this->assertNotNull($rules);
        $this->assertContains('State', $rules['required']);
        $this->assertSame('ZipCode', $rules['labels']['PostalCode'], 'a label concept, translated when shown');
        $this->assertContains('{City}, {State} {PostalCode}', $rules['format']);
    }

    public function testFormatterCountryOrdering(): void
    {
        $formatter = AddressFormatter::singleton();

        $data = [
            'Address' => 'Voorbeeldstraat 1',
            'PostalCode' => '1011 AB',
            'City' => 'Amsterdam',
            'Country' => 'NL',
        ];
        $this->assertSame(
            "Voorbeeldstraat 1\n1011 AB Amsterdam\nNL",
            $formatter->format($data, 'NL')
        );

        $us = [
            'Address' => '1 Market St',
            'City' => 'San Francisco',
            'State' => 'CA',
            'PostalCode' => '94105',
            'Country' => 'US',
        ];
        $this->assertSame(
            "1 Market St\nSan Francisco, CA 94105\nUS",
            $formatter->format($us, 'US')
        );
    }

    public function testAddressTemplateUsesTheCountryLayout(): void
    {
        $nl = Address::create([
            'FirstName' => 'Anna',
            'Surname' => 'Jansen',
            'Address' => 'Teststraat',
            'HouseNumber' => '1',
            'PostalCode' => '2611 AA',
            'City' => 'Delft',
            'State' => 'ZH',
            'Country' => 'NL',
        ]);
        $html = (string) $nl->forTemplate();

        $this->assertStringContainsString('Teststraat 1', $html, 'the house number is shown with the street');
        $this->assertStringContainsString('2611 AA Delft', $html, 'postcode and city on one line');
        $this->assertStringNotContainsString('ZH', $html, 'NL addresses have no State line');
        $this->assertLessThan(strpos($html, '2611 AA Delft'), strpos($html, 'Teststraat 1'));
        $this->assertStringContainsString('Anna Jansen', $html);

        $us = Address::create([
            'Address' => '1 Main Street',
            'PostalCode' => '94105',
            'City' => 'San Francisco',
            'State' => 'CA',
            'Country' => 'US',
        ]);
        $this->assertStringContainsString('San Francisco', (string) $us->forTemplate());
        $this->assertStringContainsString('CA', (string) $us->forTemplate(), 'countries with a State keep it');
    }
}
