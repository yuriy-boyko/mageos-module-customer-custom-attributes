# MageOS_CustomerCustomAttributes

Open-source custom **customer** and **customer address** attributes for Magento Open Source and Mage-OS, built
**Hyvä Theme and Hyvä Checkout first**.

> Version 0.1.0: initial version. See [docs/STATUS.md](docs/STATUS.md) for what is built and verified and the [user guide](docs/guide/README.md).

## Goal

Let a store admin define custom customer and customer address attributes, show them in Hyvä Theme and Hyvä Checkout
forms, and keep their values through quote, order and address book. No runtime schema changes, and no dependency on
Knockout or RequireJS on the storefront.

## Admin

**Stores → Attributes → Customer Attributes** and **Customer Address Attributes** (own ACL resource each).

- Grid of the user-defined attributes with filters and sorting: code, default label, input type, required, enabled, sort order.
- Create, edit, delete. Mass actions: enable, disable, delete selected attributes. System attributes are not listed and cannot be changed here.
- Input types: text field, text area, date, yes/no, dropdown, multiple select. Dropdown and yes/no attributes can be shown as **radio buttons** and multiple select attributes as **checkboxes** ("Display As" on the Advanced tab); the data and the API stay those of a standard dropdown or multiple select.
- **Properties** tab: default label, attribute code, input type, required, default value (text, text area, yes/no), options for
  dropdown and multiple select (per store view labels, default option, drag to sort), input validation.
- Input validation: letters/numbers, numbers, letters, URL, email or length only; minimum and maximum text length; minimum and
  maximum date for date attributes.
- **Manage Labels** tab: label per store view. **Storefront Properties** tab: enabled, sort order and the forms the attribute
  is shown in (customer: registration, account edit, admin order create; address: checkout and registration address forms,
  address book). The admin forms always include the attribute.
- AJAX validation before saving: required label, code format (lowercase letters, digits, underscores, starts with a letter,
  at most 51 characters for customer and 60 for address attributes), duplicate code, codes that collide with columns of the
  customer, quote or order tables, valid input type, option values required and unique, consistent length and date ranges.
- The input type and the code cannot be changed after the attribute is created.

### Websites
The **Websites** tab of an existing attribute lets each website override three things: **Enabled**, **Values Required** and the **Default
Value** (text, text area, yes/no). "Use Default" keeps the value of the Properties tab. The overrides use the website scope that Magento
core already provides for customer attributes (`customer_eav_attribute_website`), so forms, the API and validation follow the website
the customer is on. Everything else (code, input type, options, rules, forms) is shared by all websites. The admin order and customer
forms use the default values.

### Dependencies and customer groups
- **Attribute Relations** (Stores → Attributes): a named relation for customer or address attributes with rules "show attribute X when
  attribute P has option O". P must be a dropdown, multiple select or yes/no attribute. Rules nest (X can be the parent of another
  rule) and circular rules are rejected. An attribute without rules is always visible.
- The relation screen warns when a dependent attribute is shown in a storefront form where its parent is not (it would stay hidden there).
- **Customer Groups** (Storefront Properties tab of an attribute): show the attribute only to the selected groups; empty means all.
- The same rules run in the browser (the Hyvä account forms hide and disable the field; in Hyvä Checkout the dependent fields are shown and hidden instantly as the customer changes the parent field, and the server clears the values of hidden fields) and on the server: when an order is placed the
  values of hidden attributes are not required and are not kept.

### Show in Forms (customer attributes)
Customer attributes can be shown in the registration form, the account edit form, the admin order create form and, with **Hyvä
Checkout**, in their own sections of the checkout (needs the companion package `mage-os/module-customer-custom-attributes-hyva-checkout`):

| Setting (greyed out until "Hyvä Checkout" is ticked) | Effect |
|---|---|
| Position at Checkout Step | Above/Below Shipping Address, Above/Below Shipping Methods, Above/Below Payment Method, Order Summary |
| Checkout Sort Order | order inside a position |
| Prefill from Customer | fills an empty field with the logged-in customer's saved value |

Values typed in the checkout are kept on the quote and the order snapshot only: **the customer account is never changed from the
checkout**, and an empty account value stays empty. Guests see the fields too. A required attribute is enforced in the checkout
and blocks placing the order (not for REST orders); with prefill off the customer types it on every order. Dependency rules,
customer group restrictions and website scope apply, and fields show and hide instantly. An attribute ticked only for "Hyvä
Checkout" (and the admin order form) behaves like an order attribute: the value exists on the order only.

### Show in Forms (address attributes)
Each address attribute has switches that decide where it appears:

| Option | Effect |
|---|---|
| Registration Address Form | the core registration address form (`customer_register_address`) |
| Address Book (Customer Account) | the Hyvä account "Add/Edit Address" form (`customer_address_edit`) |
| Hyvä Checkout Address Forms | the shipping and billing address forms of Hyvä Checkout (code `mageos_hyva_checkout`; user-defined attributes follow this option instead of `customer_register_address`) |
| Address Text | the value is added to the address text everywhere an address is displayed (code `mageos_address_text`) |

The admin address forms always include the attribute.

## Storefront (Hyvä)

Hyvä Theme only; Luma is not supported. Layout files use the `hyva_` prefix so other themes ignore them.

- **Hyvä Checkout:** the attributes ticked for "Hyvä Checkout Address Forms" appear in the shipping and billing address forms (a plugin
  on Hyvä's address attribute filter), and are saved on the quote, the order and, when "save in address book" is ticked, the customer address.
- **Address text:** the value lines ("Label: value", dates without time, option labels) are appended after the standard address text
  by a plugin on the core address renderer. This covers the Hyvä Checkout saved-address cards, the account dashboard and address
  book cards, the customer order page, order emails, PDFs and the admin order, invoice and shipment views. An order shows what was
  ordered (its own snapshot), even when the attribute was renamed or deleted later; attributes switched off for "Address Text" are hidden.
- **Account address form:** the attributes ticked for "Address Book (Customer Account)" are added to the Add/Edit Address form with
  native browser validation derived from the attribute's rules. Saving uses the core form handling.
- **Tailwind:** the module registers itself for the Hyvä modules configuration. After installing, run `bin/magento hyva:config:generate`
  and the Tailwind build of the theme (`npm run build` in the theme's `web/tailwind`).
- **Customer attributes in the checkout:** see "Show in Forms (customer attributes)" above.
- **Customer attributes:** the attributes ticked for "Registration" or "Account Edit" appear in the Hyvä registration and Account
  Information forms (native validation from the attribute's rules) and are saved by core. When a logged-in customer places an order, their
  values are copied to the order as a snapshot and listed in the admin order view under "Account Information".
- **Admin orders:** the admin order create form shows the customer attributes (assigned to the admin order create form) in "Account
  Information" and the address attributes in the billing and shipping forms; saved customer addresses fill the fields. The values typed
  there are stored on the order. "Edit Order Address" shows and updates the order's values.
- **Dependencies:** see above; hidden attributes are not required and are not stored on the order.
- **Saved addresses:** when an attribute becomes required after a customer saved an address, the Hyvä Checkout shows a notice next to the address list ("Your saved address needs the field ... Click here to edit it", a link to the account address form; with several missing fields a generic text) and the order message names the address and, for one field, the field.
- **Validation:** the attribute rules (required, text length, numeric/letters/letters and numbers/email/URL, date range, allowed
  options) are enforced on the server when an order is placed (billing and shipping address) and when an order address is edited in the
  admin, not only in the browser. A rejected order names the address and the attribute.
- **REST:** `custom_attributes` on the shipping and billing address of the guest and customer cart endpoints are stored on the quote and the order; the customer API (`customers/me`) reads and updates customer and address attributes.
- **API:** order addresses carry the ordered values in the extension attribute `custom_attribute_values` (code, label, input type, value,
  value label) in the order and order address repositories and their REST endpoints.

## Design in short

- Attribute **definitions** are core EAV attributes (`customer`, `customer_address`), so core, GraphQL and other
  modules see them. Which form shows an attribute is the core `customer_form_attribute` mapping
  (Hyvä Checkout reads the `customer_register_address` form).
- Customer and address **values** use the core EAV value tables.
- **Quote and order values** are stored in fixed-schema value tables (one row per parent and attribute), declared in
  `etc/db_schema.xml`. No column is ever added or dropped at runtime.
- **Orders keep a snapshot** (code, label, input type, value, displayed value) with no foreign key to
  `eav_attribute`. Changing or deleting an attribute later never changes an existing order.
- Core's quote address drops unknown custom attributes; this module registers the address attributes through
  `Magento\Quote\Model\Quote\Address\CustomAttributeListInterface` so Hyvä Checkout's `populateWithArray` keeps them.

### Tables

| Table | Connection | Key | Notes |
|---|---|---|---|
| `mageos_cca_quote_address_value` | checkout | quote address + attribute id | FK to `quote_address` and `eav_attribute`, cascade |
| `mageos_cca_relation`, `mageos_cca_relation_detail` | default | relation, parent attribute + option + dependent attribute | dependency rules, FK cascade to the attributes |
| `mageos_cca_attribute_group` | default | attribute + customer group | group restriction, FK cascade |
| `mageos_cca_quote_value` | checkout | quote + attribute id | customer attributes (reserved, not used by storefront code yet) |
| `mageos_cca_order_address_value` | sales | order address + attribute code | snapshot, FK to `sales_order_address` only |
| `mageos_cca_order_value` | sales | order + attribute code | snapshot, customer attributes |

### Flow (address attributes)

1. `Model/Quote/Address/CustomAttributeList` makes the quote address keep the submitted values.
2. `Plugin/QuoteAddressSaveValues` (on the quote address resource's `save()`) and `Observer/QuoteAddressLoadAfter` store and reload them (`mageos_cca_quote_address_value`).
3. `Plugin/QuoteAddressCustomerAddressConversion` keeps them when core converts a quote address to or from a customer address (same-as-billing copy, guest validation).
   `Plugin/ConvertQuoteAddressToOrderAddress` carries them to the new order address.
4. `Observer/OrderAddressSaveAfter` writes the snapshot rows; `OrderAddressLoadAfter` exposes them as the
   `mageos_cca_attributes` data on the order address (display reads only this).

## Requirements

Magento Open Source 2.4.9 (or Mage-OS), PHP 8.3, 8.4 or 8.5. Hyvä Theme and Hyvä Checkout are the target storefront.

For Hyvä Checkout (live hiding by dependency rules and customer group, radio/checkbox display) also install
`mage-os/module-customer-custom-attributes-hyva-checkout`; it is separate so this module compiles without Hyvä Checkout.

`bin/magento module:uninstall -r` drops the module's tables; the attributes themselves stay (delete them in the admin first).

## Documentation

- [docs/guide/](docs/guide/README.md): the user guide, one page per topic (overview, installation, attributes, customer and address
  attributes, dependencies, orders and data, API, troubleshooting).
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): code map, data flow, Magento and Hyvä behaviours the code depends on, decisions.
- [docs/TESTING.md](docs/TESTING.md): how to test, test data, SQL checks, the full test list.
- [docs/STATUS.md](docs/STATUS.md): what is built, verified and open. [CHANGELOG.md](CHANGELOG.md).
- [AGENTS.md](AGENTS.md) / [CLAUDE.md](CLAUDE.md): rules for AI coding tools and contributors.

## Development

```bash
dev/check.sh   # PHP lint, XML, JSON, PHPUnit (Magento-free tests in Test/Unit)
```

`phpstan.neon.dist` configures PHPStan (run it inside a Magento install, see the file header).
PHPUnit is installed outside the module (`CCA_DEV_VENDOR`, default `/tmp/mageos-cca-dev-vendor`), never inside it.
Set `PHP_OPTS` to pass extra flags to the PHP syntax check.

## Credits and licence

Written by Yuriy Boyko, <info@yuriy-boyko.com>.

Licensed under the Open Software License 3.0 (see [LICENSE.txt](LICENSE.txt)); `composer.json` also lists AFL-3.0 like
Magento core modules.
