<%-- silvershop-suite/address-formats: core's address template, with the address itself in the country's own layout
     (street + house number, postcode + city, ...) instead of one field per line. Name, company and phone as in core. --%>
<% if $Name %><span class="silvershop-address__line silvershop-address__line--name">$Name</span><br/><% end_if %>
<% if $Company %><span class="silvershop-address__line silvershop-address__line--company">$Company</span><br/><% end_if %>
<% loop $FormattedAddressLines %><span class="silvershop-address__line silvershop-address__line--address">$Line</span><br/><% end_loop %>
<% if $Phone %><span class="silvershop-address__line silvershop-address__line--phone">$Phone</span><% end_if %>
