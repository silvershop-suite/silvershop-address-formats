<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Tests;

use SilverShop\Model\Address;
use SilverShop\Page\CheckoutPageController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;

/**
 * During a checkout submit the required address fields follow the country the customer chose on the form, not the one
 * saved on the address (a country switch must not require fields that are hidden for the new country).
 */
class SubmittedCountryTest extends SapphireTest
{
    protected $usesDatabase = true;

    private const NS = 'SilverShop-Checkout-Component-';

    /**
     * @param array<string, string> $post
     * @return array<string, string>
     */
    private function requiredDuring(Address $address, array $post, string $method = 'POST', ?Controller $controller = null): array
    {
        $request = new HTTPRequest($method, '/checkout/OrderForm', [], $post);
        $request->setSession(new Session([]));
        $controller ??= CheckoutPageController::create();
        $controller->setRequest($request);
        $controller->pushCurrent();
        try {
            return $address->getRequiredFields();
        } finally {
            $controller->popCurrent();
        }
    }

    private function address(string $country): Address
    {
        return Address::create(['Country' => $country]);
    }

    public function testSwitchingToACountryWithoutHouseNumbersDoesNotRequireOne(): void
    {
        $required = $this->requiredDuring($this->address('NL'), [
            self::NS . 'ShippingAddress_Country' => 'BE',
            self::NS . 'BillingAddress_Country' => 'BE',
        ]);

        $this->assertArrayNotHasKey('HouseNumber', $required, 'BE has no house-number field');
        $this->assertArrayHasKey('PostalCode', $required);
    }

    public function testSwitchingToACountryWithoutStateDoesNotRequireIt(): void
    {
        $required = $this->requiredDuring($this->address('US'), [self::NS . 'ShippingAddress_Country' => 'NL']);

        $this->assertArrayNotHasKey('State', $required);
        $this->assertArrayHasKey('HouseNumber', $required, "the new country's own fields are required");
    }

    public function testTheSteppedCheckoutAddressStepPostsCountry(): void
    {
        $required = $this->requiredDuring($this->address('BE'), ['Country' => 'NL']);

        $this->assertArrayHasKey('HouseNumber', $required);
    }

    public function testWithoutASubmitTheSavedCountryApplies(): void
    {
        $required = $this->requiredDuring($this->address('NL'), [], 'GET');

        $this->assertArrayHasKey('HouseNumber', $required);
    }

    public function testDifferentCountriesForAnUnsavedAddressKeepTheSavedCountry(): void
    {
        $required = $this->requiredDuring($this->address('NL'), [
            self::NS . 'ShippingAddress_Country' => 'BE',
            self::NS . 'BillingAddress_Country' => 'US',
        ]);

        $this->assertArrayHasKey('HouseNumber', $required, 'cannot tell which one applies, so the saved NL rules stay');
    }

    public function testOtherControllersAreIgnored(): void
    {
        $required = $this->requiredDuring($this->address('NL'), ['Country' => 'BE'], 'POST', Controller::create());

        $this->assertArrayHasKey('HouseNumber', $required);
    }
}
