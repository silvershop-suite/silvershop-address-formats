<?php

declare(strict_types=1);

namespace SilverShop\AddressFormats\Extension;

use SilverShop\AddressFormats\AddressFormat;
use SilverShop\AddressFormats\AddressFormatRegistry;
use SilverShop\AddressFormats\AddressFormatter;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\Order;
use SilverShop\Page\CheckoutPageController;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Applied to {@link \SilverShop\Model\Address}. Makes the customer address form country-aware: relabels and hides
 * fields, drives the required fields from {@link AddressFormatRegistry} (fixing the State-always-required core
 * default), enforces the per-country postcode pattern, and normalises the postcode on save. When
 * `live_country_switching` is on (default) the form keeps every field and ships a small client script that
 * re-labels / shows-hides / re-requires fields as the customer changes Country.
 *
 * @property \SilverShop\Model\Address $owner
 */
class CustomerAddressExtension extends Extension
{
    /** Address fields this extension manages on the front-end form (HouseNumber is created by this extension). */
    private const MANAGED_FIELDS = ['Address', 'HouseNumber', 'AddressLine2', 'City', 'State', 'PostalCode'];

    /**
     * Optional separate house-number field, used by countries configured with `house_number: true` (e.g. NL, DE).
     * Nullable + off by default, so existing installs are unaffected until a country opts in.
     */
    private static array $db = [
        'HouseNumber' => 'Varchar(20)',
    ];

    /**
     * Relabel / hide address fields per the address's country. With live switching on, hidden fields are kept in the
     * DOM (marked, not removed) and the client switcher script is injected so the form adapts without a round-trip.
     */
    public function updateFormFields(FieldList $fields): void
    {
        $format = AddressFormatRegistry::singleton()->formatFor($this->formCountry());
        $live = (bool) AddressFormatRegistry::config()->get('live_country_switching');

        // Core doesn't provide a HouseNumber field; add it next to Address when any country could use it.
        if (!$fields->dataFieldByName('HouseNumber')
            && $fields->dataFieldByName('Address')
            && ($live || $format->hasHouseNumber())
        ) {
            $fields->insertAfter('Address', TextField::create('HouseNumber', $format->labelFor('HouseNumber', 'No.')));
        }

        foreach (self::MANAGED_FIELDS as $name) {
            $field = $fields->dataFieldByName($name);
            if (!$field) {
                continue;
            }

            // HouseNumber is shown only where the country opts in (inverse of the explicit `hidden` list).
            $hidden = $name === 'HouseNumber' ? !$format->hasHouseNumber() : $format->isHidden($name);

            if (!$live && $hidden) {
                $fields->removeByName($name);
                continue;
            }

            // With live switching, keep the generic label on the input: the switcher restores it for a country that
            // doesn't relabel the field (else a switch from NL to BE would keep "Straat").
            if ($live) {
                $field->setAttribute('data-addressformat-label', (string) $field->Title());
            }
            $field->setTitle($format->labelFor($name, (string) $field->Title()));

            // Core's hint, e.g. "street / thoroughfare number, name, and type or P.O. Box" under Address, doesn't fit a
            // country with a separate house-number field. With live switching the text is kept on the input, so the
            // switcher can bring it back when the customer picks a country without one.
            $description = (string) $field->getDescription();
            if ($description !== '') {
                if ($live) {
                    $field->setAttribute('data-addressformat-description', $description);
                }
                if ($format->hidesDescription($name)) {
                    $field->setDescription('');
                }
            }

            if (!$live) {
                continue;
            }

            $field->setAttribute('data-addressformat-field', $name);
            if ($hidden) {
                $field->addExtraClass('addressformat-hidden');
            }
            if ($name === 'PostalCode' && ($pattern = $format->htmlPostcodePattern()) !== '') {
                $field->setAttribute('pattern', $pattern);
            }
        }

        // NB: the per-country field order + row grouping (e.g. NL: street+house, postcode+city, narrow members) are
        // NOT built here. SilverShop's Checkout\Component\Address re-wraps/namespaces these fields for the checkout
        // form, which discards any server-built CompositeField wrappers and re-ordering before render. So the row
        // layout is driven entirely client-side by the switcher (from AddressFormatRegistry::rulesForJs()'s
        // `groups`/`narrow`), keyed on the `data-addressformat-field` attributes set above — which survives both the
        // component flattening and live country switching.
        if ($live) {
            $fields->push($this->switcherField());
        }
    }

    /**
     * Give the Address CMS edit form a conventional field order: name, company, street + house number, address line 2,
     * postcode + city, state, country, phone. Core scaffolds the raw $db order (Country / State / City first, name
     * last) and the extension's HouseNumber lands at the very end, which reads oddly for an address form. The field
     * instances are moved (removed + re-added), not recreated, so any decoration (Fluent, etc.) is preserved. Applies
     * everywhere Address::getCMSFields() is used — member addresses and the order-admin clone-on-write edit.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $order = [
            'FirstName', 'Surname', 'Company',
            'Address', 'HouseNumber', 'AddressLine2',
            'PostalCode', 'City', 'State', 'Country',
            'Phone',
        ];

        $ordered = [];
        foreach ($order as $name) {
            $field = $fields->dataFieldByName($name);
            if ($field) {
                $ordered[] = $field;
                $fields->removeByName($name);
            }
        }

        foreach ($ordered as $field) {
            $fields->addFieldToTab('Root.Main', $field);
        }

        // Core scaffolds HouseNumber (added as $db by this extension) with the raw "House Number" label; give it a
        // translated admin-locale title like every other address field. (The front-end uses the country-specific
        // labelFor(); the CMS form is a single static form, so an admin-locale label is the right fit here.)
        $houseNumber = $fields->dataFieldByName('HouseNumber');
        if ($houseNumber) {
            $houseNumber->setTitle(_t(self::class . '.HouseNumber', 'House number'));
        }
    }

    /**
     * Replace the per-country address requirements: drop State where it isn't used, and ensure the country's required
     * address fields are present. Non-address requirements set elsewhere are left untouched.
     *
     * @param array<string, string> $fields combined (key == value) required-field map, by reference
     */
    public function updateRequiredFields(array &$fields): void
    {
        // During a checkout submit, follow the country the customer chose on the form, not the one saved on the address:
        // otherwise a country switch requires fields that are hidden for the new country (NL house number after
        // switching to BE), and the new country's own fields are only checked when the address is written.
        // A shop that sells to one country has no Country input (core shows it read-only and sets it on save), so
        // nothing is posted and the address may not have a country yet: use the shop's country, as the form does.
        $submitted = $this->submittedCheckoutCountry();
        $format = AddressFormatRegistry::singleton()->formatFor($submitted ?? $this->formCountry());

        if (!$format->isRequired('State') || $format->isHidden('State')) {
            unset($fields['State']);
        }
        foreach ($format->getRequired() as $name) {
            $fields[$name] = $name;
        }
        if (!$format->hasHouseNumber()) {
            unset($fields['HouseNumber']);
        }
    }

    /**
     * Compose the separate house number back into the address line for single-string rendering (toString/getTitle),
     * so stored/displayed addresses stay whole for countries that split it.
     *
     * @param array<int, string|int|null> $fields the flat address values core joins into a string, by reference
     */
    public function updateToString(array &$fields): void
    {
        $owner = $this->getOwner();
        $houseNumber = trim((string) $owner->HouseNumber);
        $street = (string) $owner->Address;
        if ($houseNumber === '' || $street === '' || !$this->formatFor()->hasHouseNumber()) {
            return;
        }

        foreach ($fields as $i => $value) {
            if ((string) $value === $street) {
                $fields[$i] = trim($street . ' ' . $houseNumber);
                break;
            }
        }
    }

    /**
     * Enforce the per-country postcode pattern on the model (mirrors the client-side `pattern`). Presence is handled
     * by the required-fields rules, so an empty postcode is left to those.
     */
    public function updateValidate(ValidationResult $result): void
    {
        $postcode = (string) $this->getOwner()->PostalCode;
        if (trim($postcode) === '' || $this->formatFor()->postcodeValid($postcode)) {
            return;
        }

        $result->addFieldError(
            'PostalCode',
            _t(self::class . '.InvalidPostcode', 'Please enter a valid postcode for the selected country.')
        );
    }

    /**
     * Normalise the postcode to the country's canonical form on save (e.g. NL "1234ab" → "1234 AB").
     */
    public function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        $postcode = (string) $owner->PostalCode;
        if (trim($postcode) === '') {
            return;
        }

        $normalised = $this->formatFor()->normalisePostcode($postcode);
        if ($normalised !== $postcode) {
            $owner->PostalCode = $normalised;
        }
    }

    /**
     * The address body in this country's layout (e.g. NL "Straat 1" / "1234 AB Plaats"), one item per line with a
     * `Line` value. Used by this module's `SilverShop/Model/Address.ss`, which replaces core's field-by-field template
     * (core shows no house number and puts city and postcode on separate lines).
     */
    public function FormattedAddressLines(): ArrayList
    {
        $lines = ArrayList::create();
        foreach (AddressFormatter::create()->linesFor($this->getOwner()) as $line) {
            $lines->push(ArrayData::create(['Line' => $line]));
        }

        return $lines;
    }

    /**
     * The country posted for this address by the checkout form being submitted, or null (not a checkout POST, no country
     * posted, or delivery and billing posted different countries and this address can't be told apart).
     *
     * Single-page checkout posts namespaced fields (`…ShippingAddress_Country`, `…BillingAddress_Country`), the stepped
     * checkout's address steps post `Country`.
     */
    private function submittedCheckoutCountry(): ?string
    {
        $controller = Controller::curr();
        if (!$controller instanceof CheckoutPageController) {
            return null;
        }
        $request = $controller->getRequest();
        if (!$request->isPOST()) {
            return null;
        }

        $countries = [];
        foreach ((array) $request->postVars() as $name => $value) {
            if (!is_string($name) || !is_string($value) || trim($value) === '') {
                continue;
            }
            if ($name === 'Country' || str_ends_with($name, 'Address_Country')) {
                $countries[$name] = strtoupper(trim($value));
            }
        }
        if ($countries === []) {
            return null;
        }
        if (count(array_unique($countries)) === 1) {
            return (string) reset($countries);
        }

        $type = $this->addressTypeInCart();
        if ($type !== null) {
            foreach ($countries as $name => $code) {
                if (str_ends_with($name, $type . 'Address_Country')) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * 'Shipping' or 'Billing' when this (saved) address is only one of the two on the current cart, else null.
     */
    private function addressTypeInCart(): ?string
    {
        $id = (int) $this->getOwner()->ID;
        $order = ShoppingCart::curr();
        if ($id <= 0 || !$order instanceof Order) {
            return null;
        }
        $shipping = (int) $order->getField('ShippingAddressID') === $id;
        $billing = (int) $order->getField('BillingAddressID') === $id;
        if ($shipping === $billing) {
            return null;
        }

        return $shipping ? 'Shipping' : 'Billing';
    }

    /**
     * The format of the address's own country, or of the shop's single country while the address has none yet.
     */
    private function formatFor(): AddressFormat
    {
        $country = trim((string) $this->getOwner()->Country);

        return AddressFormatRegistry::singleton()->formatFor($country !== '' ? $country : (string) $this->singleCountry());
    }

    /**
     * The country the front-end form is for: the shop's single country when it sells to one (core then has no Country
     * input and sets that country on save), otherwise the address's own country.
     */
    private function formCountry(): string
    {
        return $this->singleCountry() ?? trim((string) $this->getOwner()->Country);
    }

    private function singleCountry(): ?string
    {
        $country = SiteConfig::current_site_config()->getSingleCountry();

        return is_string($country) && $country !== '' ? strtoupper($country) : null;
    }

    /**
     * The injected rules JSON + client switcher (style + script). Overridable by a project/theme template at
     * `SilverShop/AddressFormats/Includes/AddressFormatScript.ss`.
     */
    private function switcherField(): LiteralField
    {
        $json = json_encode(
            AddressFormatRegistry::singleton()->rulesForJs(),
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // Used by the script only when the form has no Country input (a single-country shop).
        $html = (string) ArrayData::create([
            'RulesJson' => $json,
            'FixedCountry' => $this->formCountry(),
            'LayoutCss' => (bool) AddressFormatRegistry::config()->get('layout_css'),
        ])->renderWith('SilverShop/AddressFormats/Includes/AddressFormatScript');

        return LiteralField::create('AddressFormatScript', $html);
    }
}
