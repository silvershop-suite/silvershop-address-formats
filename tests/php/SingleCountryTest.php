<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Tests;

use SilverShop\AddressFormats\AddressFormatRegistry;
use SilverShop\Model\Address;
use SilverShop\Page\CheckoutPageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * A shop that sells to one country: core shows Country read-only (no input) and sets it on save, so a new address has
 * no country while the customer fills in the form. The form, the switcher and the required fields must all use the
 * shop's country, or the house number is hidden but still required and the address step can't be submitted.
 */
class SingleCountryTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function sellTo(string ...$countries): void
    {
        $config = SiteConfig::current_site_config();
        $config->AllowedCountries = json_encode($countries);
        $config->write();
    }

    public function testTheFormUsesTheShopsCountry(): void
    {
        $this->sellTo('NL');
        $fields = Address::create()->getFrontEndFields();

        $houseNumber = $fields->dataFieldByName('HouseNumber');
        $this->assertNotNull($houseNumber);
        $this->assertFalse($houseNumber->hasExtraClass('addressformat-hidden'), 'NL shows the house number');
        $this->assertNull($fields->dataFieldByName('Country'), 'core has no Country input for a single country');
    }

    public function testTheSwitcherGetsTheShopsCountry(): void
    {
        $this->sellTo('NL');
        $this->assertStringContainsString('data-addressformat-country="NL"', $this->switcherHtml());
    }

    public function testCheckoutRequiresTheShopsCountryFieldsWithoutAPostedCountry(): void
    {
        $this->sellTo('NL');
        $request = new HTTPRequest('POST', '/checkout/OrderForm', [], ['Address' => 'Straat', 'City' => 'Delft']);
        $request->setSession(new Session([]));
        $controller = CheckoutPageController::create();
        $controller->setRequest($request);
        $controller->pushCurrent();
        try {
            $required = Address::create()->getRequiredFields();
        } finally {
            $controller->popCurrent();
        }

        $this->assertArrayHasKey('HouseNumber', $required);
        $this->assertArrayNotHasKey('State', $required);
        $this->assertArrayNotHasKey('Country', $required, 'core never requires Country for a single country');
    }

    public function testWithoutLiveSwitchingTheHouseNumberStays(): void
    {
        $this->sellTo('NL');
        Config::modify()->set(AddressFormatRegistry::class, 'live_country_switching', false);

        $this->assertNotNull(Address::create()->getFrontEndFields()->dataFieldByName('HouseNumber'));
    }

    public function testAShopWithSeveralCountriesStillFollowsTheAddress(): void
    {
        $this->sellTo('NL', 'BE');

        $fields = Address::create()->getFrontEndFields();
        $this->assertTrue($fields->dataFieldByName('HouseNumber')->hasExtraClass('addressformat-hidden'));
        $this->assertArrayNotHasKey('HouseNumber', Address::create()->getRequiredFields());
        $this->assertStringContainsString('data-addressformat-country=""', $this->switcherHtml());

        $this->assertArrayHasKey('HouseNumber', Address::create(['Country' => 'NL'])->getRequiredFields());
    }

    private function switcherHtml(?FieldList $fields = null): string
    {
        $fields ??= Address::create()->getFrontEndFields();
        $script = $fields->fieldByName('AddressFormatScript');
        $this->assertNotNull($script);

        return (string) $script->getContent();
    }
}
