# Status

Version 0.1.0 (initial version), feature-complete; open work is testing and release housekeeping. The verified and open test cases are
listed one by one in [TESTING.md](TESTING.md); the code map is in [ARCHITECTURE.md](ARCHITECTURE.md).

## Implemented
- **Admin:** Stores → Attributes → Customer Attributes and Customer Address Attributes (grid, create, edit, delete, mass enable,
  disable, delete, AJAX validation, labels per store view, options, validation rules, forms assignment, customer groups, per-website
  scope), Attribute Relations, one ACL resource per entity and for relations.
- **Input types:** text field, text area, date, yes/no, dropdown, multiple select; dropdown and yes/no as radio buttons and multiple
  select as checkboxes ("Display As").
- **Address attributes:** Hyvä Checkout address forms, quote address, order address snapshot, customer address (address book),
  address text everywhere an address is displayed (checkout cards, account, order pages, emails, PDFs, admin order, invoice, shipment),
  account Add/Edit Address form with native validation.
- **Customer attributes:** Hyvä registration and account edit forms, order snapshot, admin order view and admin order create
  ("Account Information").
- **Customer attributes in the Hyvä Checkout** (companion module): sections at seven positions, sort order, prefill from the customer,
  values kept on the quote and the order only, rules and groups, instant show and hide, required values enforced (one-page, multi-step and mobile tested).
- **Admin orders:** order create (customer and address attributes, saved address prefill), reorder (the address values are carried over) and Edit Order Address.
- **Dependencies and groups:** relations (parent option → dependent attribute, nested, cycle-safe), customer group restriction,
  enforced on the server (hidden attributes are not required and not kept) and applied instantly in the browser, in the Hyvä account
  forms and in Hyvä Checkout (companion module).
- **Server-side validation** of all values when an order is placed (checkout, REST) and when an order address is edited in the admin.
  Outside the admin only the attributes of the Hyvä Checkout address forms (enabled for the website) are kept on the quote and the
  order; values posted for hidden fields of the account forms are not saved.
- **Saved address missing a required attribute:** checkout notice with an edit link, clear place-order message.
- **API:** `custom_attributes` on cart addresses and `customers/me`; extension attributes `custom_attribute_values` (order addresses)
  and `customer_attribute_values` (orders).
- **Release tooling:** `dev/check.sh` (233 unit tests of `Service/` and the schema whitelist), `phpunit.magento.xml.dist` (adapter tests of plugins and observers, run in a
  Magento install and in CI), `phpstan.neon.dist` (level 5), `i18n/en_US.csv`, `CHANGELOG.md`,
  `.github/workflows/ci.yml`, `Setup/Uninstall` (drops the tables and form assignments; attributes stay).

## Verified
`setup:di:compile`, PHPStan level 5, the unit tests, the install and uninstall paths, and the feature areas marked ✅ in
[TESTING.md](TESTING.md) (checkout persistence, orders, address book, snapshots, admin screens, ACL, display, API, customer
attributes, admin orders, validation, dependencies, radio/checkbox, REST, website scope, saved-address notice) on Magento 2.4.9
with Hyvä Theme and Hyvä Checkout (one-page variant).

Automated end-to-end checks (REST suite, database checks, browser tests; `dev/e2e/run-all.sh`, with `--variants` for the multi-step and
mobile checkouts, `--quick` for the main paths only) cover the rows marked `automated` in
TESTING.md, among them the dependency rows DEP12 to DEP16. They found one bug, now fixed: the account address form could not be saved
when a required attribute was hidden by a dependency rule (DEP14), and the same for the account information form with a required customer attribute (CUS8). A required address attribute that is only in the address book form blocks the checkout (core's behaviour, kept); the admin warns about it (VAL6).

## Open
- Performance with the module on and off: measured twice (TESTING.md, ROB4: all pages within the noise of a developer-mode test store, including the logged-in checkout and the admin order create page, same number of requests of the shop itself, time to first byte at most 100 ms more); production mode measured once (checkout about 140 ms more with the module, same number of requests); a cold cache is not measured. These figures date from before the checkout query reduction (see
  CHANGELOG). After it, a guest checkout page runs 10 module queries instead of 38 (122 instead of 150 in total). The timings were
  not measured again. The module's statements per page are checked by `dev/e2e/count-queries.sh` (ROB6).
- The ☐ rows in [TESTING.md](TESTING.md).
- The admin forms apply no rules in the browser, so their own check still asks for a required field that a rule hides (the server
  does not require it, VAL9).
- Hyvä Checkout 3.x: run once on 3.0.2 (INS13): every browser spec passes, with `Hyva_CheckoutV1bc` enabled and disabled.
- Customer attributes in the checkout: the open rows CCO in TESTING.md (one-page, multi-step and mobile were tested with guests and logged-in customers).
- Manual checks: other Hyvä Checkout variants (multi-step, mobile), a second store view with another locale, card payments with
  3D Secure, real order emails and PDF files, keyboard and screen reader use.

- **Save to account and order email:** "Also Save to Customer Account" (logged-in checkout, per attribute) and "Show in Emails" (a multiselect per attribute: new order, invoice, shipment and credit memo emails, section "Additional Information", variable `mageos_additional_information_html` always filled; the setting "Add the Section Automatically" in Sales Emails, default Yes, puts it into the standard templates, which have the payment and shipping method cells). Verified: unit, adapter, ACC1, EML1, EML2 and EML3 browser tests (EML3: emails of invoices, shipments and credit memos created through REST, and asynchronous sending by cron; EML4: the heading in the language of the order's store view for emails sent from the admin, REST and cron).

## Not built (by decision)
Multiline, file and image types, GraphQL, per-option control of dependent options,
import/export of attribute definitions, grid columns or filters for the values, an admin warning and back-fill tool for attributes
that become required after addresses exist.

## Future improvements
- **Values on the account dashboard.** A per-attribute setting to list the value read-only on the storefront dashboard (My Account), for
  values the customer cannot see elsewhere: attributes set to "Customer Cannot Change This Value" or shown only in the checkout.
- **A change log of attribute values** (who changed a value, when, old and new value, admin / API / import) and a **REST endpoint to set one
  attribute value** of a customer without sending the whole customer.
- **Customer attribute settings not built yet:** "Show in the admin order view" (a saved row can be hidden in the admin only by not saving
  it, "Save on Orders"); "Customer Cannot Change This Value" for address attributes (customer attributes only); a command that
  removes the rows of an attribute that was switched off for orders.
- **Required in the checkout only.** A customer attribute has one "required" flag. The checkout validation, the field in the checkout
  and the admin customer form all read it, and `adminhtml_customer` is a mandatory form of every customer attribute. A shop that
  needs a value to place an order, but has existing customers without one, cannot make the attribute required: the admin could no
  longer save those customers. Today an attribute is required everywhere or optional (by design for this version): the admin fills the
  value in first, or the shop checks it at order placement in its own code.
  Possible design, not scheduled: a per-attribute setting "Required in the checkout" (key `mageos_checkout_required` in the validate rules, next to the other
  checkout settings; a missing key means off, so nothing changes for existing installs). `CustomerCheckoutAttributes::validate()` and
  the field attributes would use "required or required in the checkout"; `is_required` stays 0, so the account and admin forms
  stay optional. Rule: a visible field must have a value on the quote when the order is placed (a prefilled value counts). Tests: unit
  (flag on, empty, hidden, virtual cart), adapter (field attributes), browser (guest and logged-in checkout refuse the order; the
  admin customer save still works without the value).

