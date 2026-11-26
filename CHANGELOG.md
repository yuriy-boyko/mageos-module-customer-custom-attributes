# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the module uses [Semantic Versioning](https://semver.org/).

For what the module does, see the [user guide](docs/guide/README.md).

## [0.1.0] - 2026-11-23

Initial version.

### Added before the first release
- Attributes with a developer's source model (a data patch with `user_defined` set): the options are shown read-only on the edit page, and
  saving no longer asks for options in the option table. See the guide, "Attributes created by a developer".

- **Shown in** column in the attribute grids: the storefront forms of every attribute (account forms in bold, "+N other" for forms of
  other modules, "Admin only" for none).
- **Save on Orders** (customer attributes, Storefront Properties, default Yes): switch it off for internal values that do not belong to
  the order history. No snapshot row is saved and the admin order view does not list it; required checks still run.
- **Customer Cannot Change This Value** (customer attributes, default No): the value of the attribute is ignored when a customer or a
  visitor sends it (registration and account edit posts, REST and GraphQL with a customer token or without a token); the admin,
  integrations, imports and the command line still write it. The field is never printed in the account forms, and the attribute
  cannot be required there.
- The two order snapshot tables are no longer in the schema whitelist: `setup:upgrade` with the module disabled keeps them, and only
  `module:uninstall -r` removes them. A schema change of these two tables needs a schema patch.

- **Also Save to Customer Account** (customer attributes shown in the checkout, default No): the value a logged-in customer types in the
  checkout is copied to the account when the order is placed. An empty value never erases the stored one.
- **Show in Emails** (customer attributes, a multiselect of New Order, Invoice, Shipment and Credit Memo, default none): an "Additional Information"
  section in those emails, below the payment and shipping method, with the values saved on the order; each email lists the attributes selected for it. Template variable
  `mageos_additional_information_html` for custom templates, and the setting "Add the Section Automatically" (Sales Emails, default Yes):
  choose No to place the variable only in the templates you want.

- Warning in the attribute grid and on the edit page for a required customer attribute that customers cannot fill in (not in the
  registration or account edit form, or "Customer Cannot Change This Value"): Magento checks every required attribute on each customer save.
- **Customer Grid** on the Storefront Properties tab: core's four customer grid flags (column, shown by default, filter, keyword search).
- An attribute with an input type of another module opens with the type shown as "(not supported)" and a note; saving keeps the type.
- Guide: installing on a store with existing attributes, a data patch recipe with the module's settings, keeping developer option values
  stable, and fields in a theme's own registration or account edit template.

### Fixed before the first release
- An order created in the admin (or through the API) now gets the account value of an attribute that is shown in the checkout. Before, only the
  storefront checkout, where the customer types the value, wrote a row, so such orders had no row and the admin order view did not list it.
- An attribute whose code is also a `customer_*` field of the quote (`note` is the cart note) no longer takes the value a customer types in
  that field through the cart API: the order snapshot reads the quote's `customer_<code>` data in the admin area only (the admin order
  create form). The attribute grid warns about attributes with a reserved code, which a data patch can create and the admin form refuses.
- Companion (Hyvä Checkout 3.x): see its CHANGELOG; this module needed no change for 3.0.2.
- The order confirmation e-mail sent by the request that places the order now has the custom address lines (the address object of that request
  carries the snapshot rows as soon as they are saved).
- Radio button and checkbox groups have an accessible name (the label of the attribute) in the checkout and in the account forms.
- Fewer database queries in Hyvä Checkout. The customer attribute sections read their form and the values stored on the quote once
  per request, and positions without attributes run no queries. The checkout address codes are read once per request, not once per
  saved address. A guest checkout page with no customer attribute in the checkout went from 38 module queries to 10.
- The Hyvä Checkout visibility script and the saved-address notice no longer run again and again on an idle page (they rewrote
  the field classes and the notice text on every pass, which counted as a change of the page). A field hidden by a rule and shown
  again sends no old value.
- Address text in escaped HTML formats with their own code (Hyvä Checkout's address summary) is escaped and has one line per
  attribute.
- Outside the admin only the attributes of the Hyvä Checkout address forms that are enabled for the website are kept on the quote
  address and the order: values sent through the REST cart endpoints for other attributes, or brought by a saved address, are
  dropped.
- Values posted for fields hidden by a rule or the customer group in the account and address book forms are not saved, and the page
  no longer lists the customer groups of restricted attributes.
- In the admin, a required attribute hidden by a rule or the customer group is not required by the server (Edit Order Address,
  order create).
- A guest who logs in while the customer has an active cart: the typed customer attribute values fill the attributes that cart has
  no value for.
- The saved-address notice links to the address book only when that form has the missing field; otherwise it asks to type a new
  address. The admin warns about a required attribute that is in the checkout but not in the address book form.
- A required customer attribute in the checkout does not block orders while the companion module is disabled.
- A click on the label of a radio or checkbox group no longer selects its first option.
- Order addresses read through the REST API can be sent back as they were read; `OrderAddressRepositoryInterface::getList()` no
  longer fails.
- Attribute codes that would become a `customer_*` column of the quote or the order (note, comment, is_guest and others) are
  reserved; saving an attribute keeps form assignments of other modules and refuses option ids of other attributes.
- The `numeric`, `alpha`, `alphanumeric` and date checks no longer accept a value with a trailing line break.
- `composer.json`: `magento/module-checkout` is required; the licence is OSL-3.0 only, like `LICENSE.txt`. The package leaves out
  `dev/`, `docs/` and the test files (`.gitattributes`).
- The checkout and account form scripts work with Hyvä theme modules older than 1.3.11 (no `$hyvaCsp` there).
- Admin Reorder and Edit Order carry the customer attribute values of the order to "Account Information" and the new order.
- Order snapshots written in the admin take the option labels of the order's store view (they took the admin values).
- A saved address that Hyvä Checkout picks at checkout start (when the customer has no default address) keeps its values also when
  the address object carries them as plain keys.
