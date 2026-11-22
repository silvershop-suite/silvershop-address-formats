<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats;

/**
 * The address field labels a country format can choose, translated into the shop's language.
 *
 * A format names a label concept (`labels: {PostalCode: ZipCode}`): the country decides which term fits (ZIP code,
 * Eircode, Postcode / PLZ / CAP, Province, Prefecture, ...), the shop's locale decides the language. So an English shop
 * shows "Street" / "House no." for a Dutch address and a Dutch shop shows "Straat" / "Huisnr.", instead of every country
 * being labelled in its own language. A value that isn't a known concept is shown as written, so labels a project
 * configured as plain text keep working.
 */
class AddressLabels
{
    /** Label concept => English default. */
    public const DEFAULTS = [
        'Street' => 'Street',
        'HouseNumber' => 'House no.',
        'Postcode' => 'Postcode',
        'PostalCode' => 'Postal code',
        'ZipCode' => 'ZIP code',
        'Eircode' => 'Eircode',
        'PinCode' => 'PIN code',
        'City' => 'City',
        'TownCity' => 'Town / City',
        'PostTown' => 'Post town',
        'Suburb' => 'Suburb',
        'SuburbTown' => 'Suburb / Town',
        'CityWard' => 'City / Ward',
        'Townland' => 'Townland',
        'VillageTownship' => 'Village / Township',
        'State' => 'State',
        'StateTerritory' => 'State / Territory',
        'Province' => 'Province',
        'Prefecture' => 'Prefecture',
        'County' => 'County',
        'Department' => 'Department',
        'District' => 'District',
        'DoSi' => 'Do / Si',
        'Emirate' => 'Emirate',
        'Island' => 'Island',
        'Oblast' => 'Oblast',
        'Parish' => 'Parish',
        'Area' => 'Area',
    ];

    public static function isConcept(string $label): bool
    {
        return isset(self::DEFAULTS[$label]);
    }

    /**
     * The label in the current locale: a known concept is translated, anything else is returned as written.
     */
    public static function translate(string $label): string
    {
        if (!self::isConcept($label)) {
            return $label;
        }

        return _t(self::class . '.' . $label, self::DEFAULTS[$label]);
    }
}
