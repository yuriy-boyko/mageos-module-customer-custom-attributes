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
- **Saved address missing a required attribute:** checkout notice with an edit link, clear place-order message.
- **API:** `custom_attributes` on cart addresses and `customers/me`; extension attributes `custom_attribute_values` (order addresses)
  and `customer_attribute_values` (orders).
- **Release tooling:** `dev/check.sh` (158 unit tests), `phpstan.neon.dist` (level 5), `i18n/en_US.csv`, `CHANGELOG.md`,
  `.github/workflows/ci.yml`, `Setup/Uninstall` (drops the tables and form assignments; attributes stay).

## Verified
`setup:di:compile`, PHPStan level 5, the unit tests, the install and uninstall paths, and the feature areas marked ✅ in
[TESTING.md](TESTING.md) (checkout persistence, orders, address book, snapshots, admin screens, ACL, display, API, customer
attributes, admin orders, validation, dependencies, radio/checkbox, REST, website scope, saved-address notice) on Magento 2.4.9
with Hyvä Theme and Hyvä Checkout (one-page variant).

Automated end-to-end checks (REST suite, database checks, browser tests; `dev/e2e/run-all.sh`) cover the rows marked `automated` in
TESTING.md, among them the dependency rows DEP12 to DEP16. They found one bug, now fixed: the account address form could not be saved
when a required attribute was hidden by a dependency rule (DEP14), and the same for the account information form with a required customer attribute (CUS8). A required address attribute that is only in the address book form blocks the checkout (core's behaviour, kept); the admin warns about it (VAL6).

## Open
- Performance with the module on and off: measured twice (TESTING.md, ROB4: all pages within the noise of a developer-mode test store, including the logged-in checkout and the admin order create page, same number of requests of the shop itself, time to first byte at most 100 ms more); production mode and a cold cache are not measured.
- The ☐ rows in [TESTING.md](TESTING.md), including `setup:di:compile` on a store without Hyvä Checkout.
- Customer attributes in the checkout: the open rows CCO in TESTING.md (one-page, multi-step and mobile were tested with guests and logged-in customers).
- Manual checks: other Hyvä Checkout variants (multi-step, mobile), a second store view with another locale, card payments with
  3D Secure, real order emails and PDF files, keyboard and screen reader use.

## Not built (by decision)
Multiline, file and image types, GraphQL, an "update my account" button for values typed in the checkout, per-option control of dependent options,
import/export of attribute definitions, grid columns or filters for the values, an admin warning and back-fill tool for attributes
that become required after addresses exist.
