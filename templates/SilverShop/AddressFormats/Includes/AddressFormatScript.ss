<style>
.addressformat-hidden,
.field.addressformat-hidden { display: none !important; }
</style>
<script type="application/json" class="addressformat-rules" data-addressformat-country="$FixedCountry.ATT">$RulesJson.RAW</script>
<script>
(function () {
    if (window.__addressFormatInit) { return; }
    window.__addressFormatInit = true;

    function holder(el) { return el.closest('.field') || el.parentElement; }

    // The country <select> within a scope. On the single-page checkout the address fields are
    // component-namespaced (e.g. "…ShippingAddress_Country"), so match a plain "Country" OR any
    // "<ns>_Country"; on the account address book the names are plain.
    function countryIn(scope) {
        return scope.querySelector('[name="Country"], [name$="_Country"]');
    }

    // The element whose direct children are the field holders (the responsive address grid on the checkout).
    function layoutContainer(scope) {
        if (scope.classList && scope.classList.contains('silvershop-address-fields')) { return scope; }
        return scope.querySelector('.silvershop-address-fields') || scope;
    }

    function fieldHolderIn(container, name) {
        var input = container.querySelector('[data-addressformat-field="' + name + '"]');
        if (!input) { return null; }
        var box = holder(input);
        return (box && box.parentNode === container) ? box : null;
    }

    // Pair configured fields onto shared flex rows (e.g. NL: street + narrow house number, then narrow postcode +
    // city). The checkout component flattens any server-built composites, so the rows are built here from the rules
    // JSON, keyed on the surviving data-addressformat-field attributes. Idempotent + reversible: the original child
    // order is snapshotted once and restored before each (re)grouping, so switching country cleanly undoes it.
    function applyLayout(container, groups, narrow) {
        if (!container) { return; }
        narrow = narrow || [];

        if (!container.__afOrder) {
            container.__afOrder = Array.prototype.slice.call(container.children);
        }

        // Unwrap any existing row groups (move their children back out, drop the wrapper).
        container.querySelectorAll('.addressformat-field-group').forEach(function (row) {
            while (row.firstChild) { container.appendChild(row.firstChild); }
            if (row.parentNode) { row.parentNode.removeChild(row); }
        });
        // Restore the original child order, then clear narrow flags.
        container.__afOrder.forEach(function (el) {
            if (el && el.parentNode === container) { container.appendChild(el); }
        });
        container.querySelectorAll('.addressformat-narrow').forEach(function (el) {
            el.classList.remove('addressformat-narrow');
        });

        // Build each configured group (needs at least two present fields).
        (groups || []).forEach(function (group) {
            var members = [];
            (group || []).forEach(function (name) {
                var box = fieldHolderIn(container, name);
                if (box) { members.push({ name: name, el: box }); }
            });
            if (members.length < 2) { return; }

            var row = document.createElement('div');
            row.className = 'addressformat-field-group';
            container.insertBefore(row, members[0].el);
            members.forEach(function (item) {
                if (narrow.indexOf(item.name) !== -1) { item.el.classList.add('addressformat-narrow'); }
                row.appendChild(item.el);
            });
        });
    }

    function apply(scope, rules) {
        var countryField = countryIn(scope);
        // No Country input (a shop that sells to one country): use the country the server passed.
        var code = countryField ? String(countryField.value || '').toUpperCase() : (scope.__afCountry || '');
        var rule = rules[code] || rules['default'] || { hidden: [], required: [], labels: {}, postcodePattern: '', groups: [], narrow: [] };
        var hidden = rule.hidden || [];
        var required = rule.required || [];
        var labels = rule.labels || {};

        scope.querySelectorAll('[data-addressformat-field]').forEach(function (input) {
            var name = input.getAttribute('data-addressformat-field');
            var box = holder(input);
            var isHidden = hidden.indexOf(name) !== -1;

            // SilverStripe renders the extra class on BOTH the input (which carries
            // data-addressformat-field) and its .field holder, and the CSS hides either — so toggle both.
            input.classList.toggle('addressformat-hidden', isHidden);
            if (box && box !== input) { box.classList.toggle('addressformat-hidden', isHidden); }

            if (labels[name] && box) {
                var lbl = box.querySelector('label');
                if (lbl) { lbl.textContent = labels[name]; }
            }

            // Net rule: a field is `required` iff it is actually visible. A field can be hidden two ways — this
            // country hides it (addressformat-hidden, set just above), or an outer layer collapsed its whole
            // block (the storefront "billing same as delivery" toggle sets display:none on .checkout-section--
            // billing). offsetParent is null whenever the field or any ancestor is display:none, so it catches
            // both. Never mark a hidden, un-focusable control `required`: the browser then refuses to submit the
            // form ("an invalid form control ... is not focusable"). On a VISIBLE required field we DO set native
            // HTML5 required — native constraint validation, aria-required for assistive tech, and the red
            // asterisk (Bug F, via the .required class). SilverStripe still enforces required server-side, so a
            // field hidden/cleared here can never let bad data through.
            var req = !isHidden && required.indexOf(name) !== -1;
            var visible = !!(box && box.offsetParent !== null);
            var effectiveReq = req && visible;
            input.required = effectiveReq;
            if (effectiveReq) { input.setAttribute('aria-required', 'true'); } else { input.removeAttribute('aria-required'); }
            if (box) { box.classList.toggle('required', effectiveReq); }

            if (name === 'PostalCode') {
                if (rule.postcodePattern) { input.setAttribute('pattern', rule.postcodePattern); }
                else { input.removeAttribute('pattern'); }
            }
        });

        applyLayout(layoutContainer(scope), rule.groups, rule.narrow);

        scope.dispatchEvent(new CustomEvent('addressformat:changed', { detail: { country: code }, bubbles: true }));
    }

    function wire() {
        document.querySelectorAll('script.addressformat-rules').forEach(function (s) {
            // Scope to the address block: the checkout wraps each address in .silvershop-address-fields
            // (shipping AND billing, each with its own namespaced Country), so wire per block; fall back
            // to the whole form for the account address book (a single, plain-named address).
            var scope = s.closest('.silvershop-address-fields') || s.closest('form');
            if (!scope || scope.__afWired) { return; }
            scope.__afWired = true;

            var rules;
            try { rules = JSON.parse(s.textContent); } catch (e) { return; }
            scope.__afCountry = String(s.getAttribute('data-addressformat-country') || '').toUpperCase();

            var countryField = countryIn(scope);
            if (countryField) {
                countryField.addEventListener('change', function () { apply(scope, rules); });
            }
            // Re-apply when an outer layer changes this block's visibility (e.g. the storefront
            // "billing same as delivery" toggle collapses/expands .checkout-section--billing). The
            // switcher only re-runs on country change otherwise, so without this the asterisk +
            // native `required` would not follow the block being shown/hidden. Keeps the net rule
            // (required iff visible) true whichever module shows or hides the field.
            document.addEventListener('addressformat:refresh', function () { apply(scope, rules); });
            apply(scope, rules);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
</script>
