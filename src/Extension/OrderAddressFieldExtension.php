<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Extension;

use SilverShop\AddressFormats\AddressFormatter;
use SilverShop\Model\Address;
use SilverShop\ORM\FieldType\ShopCountry;
use SilverStripe\Core\Extension;

/**
 * Hooks the order-admin address card into this module's per-country {@link AddressFormatter}, from the outside:
 * it implements the `updateAddressLines` seam that `SilverShop\OrderAdmin\Form\OrderAddressField` fires, so the
 * shipping / billing cards lay the address out per country (NL drops State, US packs "City, State ZIP", ...)
 * without the order-admin depending on this module. Applied only when the order-admin module is installed
 * (see `_config/address-formats.yml`), so neither module hard-depends on the other.
 *
 * @extends Extension<\SilverStripe\Forms\FormField>
 */
class OrderAddressFieldExtension extends Extension
{
    /**
     * Replace the generic address lines with the country-correct layout for this address.
     *
     * @param list<string> $lines
     */
    public function updateAddressLines(array &$lines, Address $address): void
    {
        $country = trim((string) $address->getField('Country'));
        $countryField = $address->dbObject('Country');

        $lines = AddressFormatter::create()->lines([
            'Address' => (string) $address->Address,
            'HouseNumber' => (string) $address->getField('HouseNumber'),
            'AddressLine2' => (string) $address->AddressLine2,
            'City' => (string) $address->City,
            'State' => (string) $address->State,
            'PostalCode' => (string) $address->PostalCode,
            'Country' => $countryField instanceof ShopCountry ? $countryField->Nice() : $country,
        ], $country !== '' ? $country : null);
    }
}
