# Testing

How to test `MageOS_CustomerCustomAttributes`, and the full test list with what has been verified and what is still open.
Read [ARCHITECTURE.md](ARCHITECTURE.md) first if you need to know where the code lives.

## 1. Automated checks (no Magento needed)

```bash
dev/check.sh
```

Runs PHP syntax checks, XML well-formedness, JSON validity and the PHPUnit suite (`Test/Unit`, pure PHP, no Magento classes).
PHPUnit is installed outside the module (`CCA_DEV_VENDOR`, default `/tmp/mageos-cca-dev-vendor`) so that no `vendor/` folder
ever sits inside the module (`setup:di:compile` scans the module folder and would try to compile it). Set `PHP_OPTS` to pass
extra flags to the PHP syntax check (for example `PHP_OPTS=-n` when `php -l` crashes with your ini files).

The plugins and observers that carry logic have adapter tests in `Test/Magento`: they mock Magento classes, so they run inside a
Magento install with its PHPUnit, from the Magento root (CI runs them in its Mage-OS job):

```bash
vendor/bin/phpunit -c <module path>/phpunit.magento.xml.dist
```

Rules for new code: put logic into pure classes in `Service/` and cover it with a unit test; keep Magento adapters thin, and give an
adapter with a decision in it (what is dropped, kept or required) an adapter test.

## 2. Static checks (inside a Magento install)

```bash
bin/magento setup:di:compile
vendor/bin/phpstan analyse -c <path to module>/phpstan.neon.dist
```

`phpstan.neon.dist` runs level 5 and ignores Magento's own typing habits (documented in the file). After `setup:di:compile`
on a development install, delete `generated/code/*` and `generated/metadata/*` and flush the cache to return to on-demand
generation. The main module must compile without Hyvä Checkout installed: only the companion module
(`mage-os/module-customer-custom-attributes-hyva-checkout`) may reference Hyvä Checkout classes.

`i18n/en_US.csv` is generated with `bin/magento i18n:collect-phrases -o <module>/i18n/en_US.csv <module>` (use absolute paths).

## 3. Environment for the manual and browser tests

- Magento Open Source 2.4.9 or Mage-OS, PHP 8.3 to 8.5, Hyvä Theme and Hyvä Checkout (one-page variant for the main run), the
  module and the companion module installed and enabled, `bin/magento setup:upgrade` run.
- Run `bin/magento hyva:config:generate` and the Tailwind build of the theme after installing.
- A second website or store view with another locale and currency is needed for the website-scope and date tests.
- Hyvä Checkout enforces a strict Content Security Policy: any inline script must be registered with
  `$hyvaCsp->registerInlineScript()` or it silently does not run. Check the browser console on every checkout test.
- Use the test cards of your payment provider and only inside the shop's own checkout.

### Test fixtures and automated runs

`dev/e2e/run-all.sh` runs the automated checks in one command and prints a short summary (see `dev/e2e/README.md`); rows it proves are
marked `automated` in the lists below. The tests of the multi-step and mobile checkouts run only with `run-all.sh --variants`
(rows CHK10, CHK16, CHK20, SAV7, CCO19, CCO20 and the variant parts of CCO2, DEP11, RAD8); a default run leaves those rows as they are.
`--quick` runs the main paths only (about 20 browser tests). The scripts it uses:

`dev/e2e/fixtures.php seed` creates a ready set of test attributes (customer attributes at every checkout position, address
attributes of every input type, two relations, a test customer) and `teardown` removes them again, so a run starts from a known
state and leaves nothing behind. See [`dev/e2e/README.md`](../dev/e2e/README.md). The manual list below can be created by hand
instead.

`dev/e2e/assertions.php` runs the read-only database checks (orphans, system attributes, snapshot rows) and prints an order's
snapshot, a customer's stored values or a quote's values as JSON; use it after each scenario. `dev/e2e/playwright/` holds the browser tests (run with `dev/e2e/run-playwright.sh`; rows marked "automated" name their test). `dev/e2e/api.php` is the REST suite
(31 cases: validation, order values and labels, rules, logged-in customer). Same README.

### Test attributes

Create these in the admin (Stores → Attributes → Customer Address Attributes), assigned to "Registration Address Form",
"Address Book (Customer Account)", "Hyvä Checkout Address Forms" and "Address Text":

| Code | Input | Required | Notes |
|---|---|---|---|
| `cca_text` | text field | no | maximum length 255 |
| `cca_req` | text field | yes | required check |
| `cca_area` | text area | no | long text |
| `cca_select` | dropdown | no | 3 options (A, B, C) |
| `cca_multi` | multiple select | no | 4 options |
| `cca_bool` | yes/no | no | |
| `cca_date` | date | no | date format follows the locale |

For the customer attribute tests create a text, a dropdown and a date attribute under Customer Attributes (registration and
account edit forms).

Attribute rows can be removed with `DELETE FROM eav_attribute WHERE attribute_code LIKE 'cca\_%'` (cascades), or in the admin.

## 4. Useful SQL

```sql
-- quote side (latest quote)
SELECT a.address_id, a.address_type, v.attribute_id, ea.attribute_code, v.value
FROM quote_address a
JOIN mageos_cca_quote_address_value v ON v.quote_address_id = a.address_id
JOIN eav_attribute ea ON ea.attribute_id = v.attribute_id
WHERE a.quote_id = (SELECT MAX(entity_id) FROM quote) ORDER BY a.address_id, ea.attribute_code;

-- order side (latest order)
SELECT oa.address_type, v.attribute_code, v.attribute_label, v.input_type, v.value, v.value_label
FROM sales_order_address oa
JOIN mageos_cca_order_address_value v ON v.order_address_id = oa.entity_id
WHERE oa.parent_id = (SELECT MAX(entity_id) FROM sales_order) ORDER BY oa.address_type, v.attribute_code;

-- customer order snapshot (customer attributes)
SELECT * FROM mageos_cca_order_value WHERE order_id = (SELECT MAX(entity_id) FROM sales_order);

-- orphans and duplicates (both must return nothing)
SELECT COUNT(*) FROM mageos_cca_quote_address_value v LEFT JOIN quote_address a ON a.address_id = v.quote_address_id WHERE a.address_id IS NULL;
SELECT quote_address_id, attribute_id, COUNT(*) c FROM mageos_cca_quote_address_value GROUP BY 1,2 HAVING c > 1;
```

## 5. Test list

Status: ✅ verified in a reference run, ☐ open (run when the module is complete), **manual** = needs a person with a real
browser, a second store or a card flow, ❌ fails. After each checkout case run the SQL checks above and look at the
`var/log` files and the browser console.

### INS. Install, schema, uninstall
| ID | Test | Expected | Status |
|---|---|---|---|
| INS1 | `composer require`, `module:enable`, `setup:upgrade` | enabled, no errors | ✅ · partly: `dev/e2e/check-modules.sh` enables the modules again and runs `setup:upgrade` without errors |
| INS2 | Tables `mageos_cca_quote_value`, `_quote_address_value`, `_order_value`, `_order_address_value`, `_relation`, `_relation_detail`, `_attribute_group` exist | present | ✅ · automated: assertions.php (in every run-all.sh run: the tables declared in db_schema.xml, and no other mageos_cca table) |
| INS3 | Foreign keys: quote tables cascade to quote/quote address and `eav_attribute`; order tables cascade only to the sales tables and have no FK to `eav_attribute` | as listed | ✅ · automated: assertions.php (the foreign keys and delete rules equal db_schema.xml; no key from an order table to eav_attribute; the quote tables cascade) |
| INS4 | Generated whitelist equals `etc/db_schema_whitelist.json`, except the two order snapshot tables (kept out of it on purpose); a second `setup:upgrade` changes nothing | yes | ✅ · automated: `dev/e2e/check-install.sh` (on demand: the whitelist is regenerated and compared, `setup:db:status` is up to date; checked with a changed whitelist); unit test `Test/Unit/SchemaWhitelistTest.php` keeps the two tables out of the whitelist and every other table in it |
| INS5 | `setup:di:compile` | no errors | ✅ · automated: `dev/e2e/check-modules.sh` (on demand; the generated code is deleted afterwards) |
| INS6 | Main module classes load with Hyvä classes blocked from autoloading | all load | ✅ · automated: `dev/e2e/check-install.sh` (`check-classes.php`; checked with another blocked prefix to prove it can fail) |
| INS7 | `setup:di:compile` with Hyvä Checkout, its dependants and the companion module disabled | no errors | ✅ (the Hyvä files stay on disk, so a fully Hyvä-free codebase is not proven) · automated: `dev/e2e/check-modules.sh` (on demand; Hyva_Checkout, Hyva_CheckoutStripe and the companion disabled) |
| INS8 | PHPStan level 5 | no errors | ✅ · automated: `dev/e2e/check-install.sh` (on demand) |
| INS9 | `module:disable` + `setup:upgrade`: storefront, checkout and admin order view still work; re-enable keeps the order snapshots | pages work, an order can be placed; the five whitelisted tables (quote values, relations, group restrictions) are dropped by `setup:upgrade` and come back empty (Magento's declarative schema); **the two order snapshot tables are kept with all their rows** (not in the whitelist), documented in the guide | ✅ · automated: `dev/e2e/check-modules.sh --only ins9` (on demand; checks that five tables are dropped and the two snapshot tables keep their rows, an order is placed and the admin order view opens with the module off, the tables come back and the snapshot rows are still there, an order carries its values again) |
| INS10 | Disable the modules, `module:uninstall -r` | seven tables and the two form assignments removed, registry and Composer entries removed; EAV attributes stay | ✅ |
| INS11 | Static content deploy and Hyvä Tailwind build | no errors | ✅ checked by `dev/e2e/check-deploy.sh` (run on demand, not part of run-all): `hyva:config:generate` lists the module and its companion, `npm run build` in the theme succeeds, the 14 Tailwind-looking classes of the templates have a rule in the compiled stylesheet (an empty stylesheet fails the same check), `setup:static-content:deploy -f` for the storefront theme and the admin theme succeeds without errors and deploys the compiled stylesheet; the files it touches are put back |
| INS12 | Companion module disabled, main module on | no customer attribute sections; a required checkout customer attribute does not block the order | ✅ automated: `dev/e2e/check-modules.sh --only ins12` (on demand; changes app/etc/config.php while it runs and restores it byte for byte) |
| INS13 | Hyvä Checkout 3.x (3.0.2, with and without `Hyva_CheckoutV1bc`) | rules, customer sections, notice and address text work as on 1.3; no deprecated-hook warnings from the companion | ✅ checked once in a test store on 3.0.2 (all browser specs, one-page, multi-step and mobile; the checkout specs also with v1bc disabled); not part of `run-all.sh`, which runs against the installed Checkout version. The specs touched by the 3.x fixes pass on 1.3.15 as well |

### REG. Attribute registration
| ID | Test | Expected | Status |
|---|---|---|---|
| REG1 | No custom attribute is enabled: checkout and orders behave like core | no custom fields, order placed, no module rows, no errors | ✅ (all custom attributes switched off; not deleted) · automated: playwright/tests/reg-cus-book.spec.ts |
| REG2 | Create the test attributes | providers list all of them; the quote address attribute list contains them | ✅ · automated: playwright/tests/reg-cus-book.spec.ts |
| REG3 | Delete an attribute | gone from the lists; quote rows cascade away | ✅ · automated: playwright/tests/reg-cus-book.spec.ts |
| REG4 | System attributes (firstname, street, …) | never picked up | ✅ automated: playwright/tests/reg4-system-attributes.spec.ts (the codes the module works with are exactly the user defined attributes of the two entities; no system code among them) |
| REG5 | Attribute not assigned to any form | not shown; a value sent through REST is dropped from the order | ✅ automated: api.php, playwright/tests/reg5-attribute-without-form.spec.ts |

### CHK. Hyvä Checkout: values reach the quote address
Main run on the one-page variant. Cases marked ★ are repeated on the other variants (manual).

| ID | Test | Expected | Status |
|---|---|---|---|
| CHK1 ★ | Guest fills all seven fields in the shipping address | rows for the shipping quote address | ✅ · automated: playwright/tests/chk-addresses.spec.ts |
| CHK2 ★ | Billing same as shipping | billing quote address has the values too | ✅ · automated: playwright/tests/chk-addresses.spec.ts |
| CHK3 ★ | Different billing address with different values | each address keeps its own | ✅ · automated: playwright/tests/chk-addresses.spec.ts |
| CHK4 | Change a value and continue again | row updated, no duplicates | ✅ · automated: playwright/tests/chk-values.spec.ts (one-page, multi-step and mobile) |
| CHK5 | Clear an optional value | row deleted, no empty string | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts (CCO5) |
| CHK6 | Text `0` and yes/no "No" | stored as `0`, not treated as empty | ✅ (text `0` in unit tests) · automated: playwright/tests/chk-values.spec.ts (one-page, multi-step and mobile) |
| CHK7 | Multiselect with 0, 1, several options | none = no row; several = comma separated ids | ✅ · automated: playwright/tests/chk-values.spec.ts (one-page, multi-step and mobile) |
| CHK8 | Required attribute empty | blocked in the browser | ✅ · automated: playwright/tests/cco-guest-checkout.spec.ts (a required attribute left empty blocks the order), playwright/tests/val6-required-address-book-only.spec.ts |
| CHK9 | Quotes, `<script>`, emoji, accents, Japanese, 250+ characters | stored exactly, escaped on output | ✅ (Hyvä Checkout limits text to the attribute's maximum length, 255 by default) · automated: playwright/tests/chk9-odd-characters.spec.ts |
| CHK10 | Reload the page at each step | values still present | ✅ · automated: playwright/tests/chk-addresses.spec.ts |
| CHK11 ★ | Logged-in customer, new address | same as CHK1 | ✅ · automated: playwright/tests/chk-addresses.spec.ts |
| CHK12 ★ | Logged-in customer selects a saved address without values | no stale values from the previous address | ✅ · automated: playwright/tests/chk-addresses.spec.ts |
| CHK13 | Saved address with values | values on the quote and in the form | ✅ · automated: playwright/tests/reg-cus-book.spec.ts (BOOK3), playwright/tests/rad4-saved-address-groups-checkout.spec.ts |
| CHK14 | Date typed in a store with another locale | stored consistently, shown correctly | ✅ automated: playwright/tests/chk-steps-tabs-locale.spec.ts (Italy store: customer and address date stored as typed with 00:00:00, label `YYYY-MM-DD`, the admin order view shows the same day) |
| CHK15 | Virtual-only cart (billing only) | billing values stored | ✅ (a temporary virtual product; sections at shipping positions are not shown) · automated: playwright/tests/val7-virtual-cart.spec.ts |
| CHK16 | Back from payment to shipping and edit | values reload from the quote | ✅ automated: playwright/tests/chk-steps-tabs-locale.spec.ts (multi-step checkout: back button, values reload, edit kept, payment value kept, order carries them) |
| CHK17 | Two tabs edit the same quote | last write wins, no duplicates | ✅ automated: playwright/tests/chk-steps-tabs-locale.spec.ts (two tabs of one session: the second tab's write is on the quote, the first tab keeps working on the same quote, one row per attribute, and the order carries what the quote holds) |
| CHK18 | Old quote created before the attributes existed | no errors | ✅ register page and checkout (typing, Magewire calls 200): no console errors; admin shows only core's ViewTransition warning · automated: playwright/tests/chk18-old-quote.spec.ts (a guest cart with an address, then a new address attribute and a new customer attribute for the checkout are created: the reloaded checkout shows the empty new fields without script errors, CSP violations or failed Magewire calls, keeps the old values, takes the new ones and the order carries all of them) |
| CHK19 | Guest cart merged into the customer cart at login | customer cart keeps its values, nothing orphaned | ✅ (the guest cart's typed values go with the guest cart when the customer already has an active cart) · automated: playwright/tests/cco10-guest-logs-in.spec.ts |
| CHK20 | Default (multi-step) and mobile checkout variants, second store view | same results as the one-page run | ✅ automated in parts: dep11-variants-guest-store.spec.ts, rad8-address-groups-checkout.spec.ts, cco2-positions.spec.ts, smoke-variants.spec.ts, sav-saved-address-notice.spec.ts, chk-values.spec.ts (multi-step and mobile: sections per step, rules, groups, order values, saved address notice, the value cases CHK4/CHK6/CHK7; Italy store: DEP11, CHK14, WEB) |
| CHK21 | Saved address with a value for an attribute in no storefront form, or disabled for the website | the value reaches neither the quote nor the order | ✅ automated: playwright/tests/chk21-values-outside-checkout.spec.ts |

### ORD. Order address snapshot
| ID | Test | Expected | Status |
|---|---|---|---|
| ORD1 ★ | Guest order | one row per filled attribute for billing and shipping with label, input type, value, value label | ✅ · automated: playwright/tests/chk-addresses.spec.ts |
| ORD2 | Select, multiselect, yes/no | value = ids, value label = readable text | ✅ · automated: playwright/tests/cco-guest-checkout.spec.ts |
| ORD3 | Logged-in order | same | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts, playwright/tests/cus9-logged-in-order-rows.spec.ts |
| ORD4 | Card payment, 3D Secure COMPLETE and FAIL | FAIL: no order, no rows, quote values intact; COMPLETE: as ORD1 | ✅ · automated: playwright/tests/ord4-card-3ds.spec.ts (Stripe test mode with the published 3D Secure test card; Complete places the order with its values, Fail leaves no order and keeps the quote values; needs the network and creates test payments in the Stripe test account; the one-pixel data: image of Stripe's dialog that the store CSP blocks is ignored in these tests) |
| ORD5 | Declined payment or place-order exception | no orphan rows, quote values remain | ✅ · automated: playwright/tests/ord4-card-3ds.spec.ts (the test card Stripe always declines), playwright/tests/ord5-order-refused.spec.ts (the product sells out before Place Order) |
| ORD6 | Order with no custom values | no rows, no errors | ✅ · automated: playwright/tests/reg-cus-book.spec.ts (every attribute off: no rows), playwright/tests/snap-order-snapshots.spec.ts (only required values: no empty rows) |
| ORD7 | Order grid and order list pages | no extra query per row | ✅ automated: playwright/tests/snap-ord-guards.spec.ts |

### BOOK. Address book
| ID | Test | Expected | Status |
|---|---|---|---|
| BOOK1 | New address, "save in address book" ticked | customer address holds all values | ✅ · automated: playwright/tests/book-address-book.spec.ts |
| BOOK2 | Same, not ticked | no customer address; order rows exist | ✅ automated: playwright/tests/book-address-book.spec.ts |
| BOOK3 | Later order with the saved address | values on the quote and the order | ✅ · automated: playwright/tests/reg-cus-book.spec.ts |
| BOOK4 | Admin customer edit of an address value | saved by core EAV | ✅ automated: playwright/tests/cus-admin-customer.spec.ts |
| BOOK5 | Account Add/Edit Address form | shows and saves the attributes | ✅ · automated: playwright/tests/reg-cus-book.spec.ts |

### SNAP. Snapshot stability
| ID | Test | Expected | Status |
|---|---|---|---|
| SNAP1 ★ | Delete an attribute | order rows unchanged, quote rows gone | ✅ · automated: playwright/tests/reg-cus-book.spec.ts |
| SNAP2 ★ | Change the label | order label unchanged | ✅ · automated: playwright/tests/cus7-order-survives-attribute-changes.spec.ts |
| SNAP3 | Rename or delete an option | order value label unchanged | ✅ · automated: playwright/tests/snap-order-snapshots.spec.ts (an option deleted and an option renamed after the order) |
| SNAP4 | New attribute with the code of a deleted one | old orders keep the old label | ✅ (in a rolled-back transaction) · automated: playwright/tests/snap-order-snapshots.spec.ts |
| SNAP5 | Change the input type | old rows keep the old type | ✅ (same) · automated: playwright/tests/snap-order-snapshots.spec.ts |
| SNAP6 | Delete the order | order rows cascade away | ✅ · automated: assertions.php |
| SNAP7 | Delete the quote | quote rows cascade away | ✅ · automated: playwright/tests/snap-order-snapshots.spec.ts |
| SNAP8 | Save a quote address twice with identical values | second save writes nothing | ✅ automated: playwright/tests/snap-ord-guards.spec.ts |
| SNAP9 | Order of a store view with its own option labels, placed or edited in the admin | the snapshot has the store view's option label, like the attribute label | ✅ adapter test Test/Magento/Model/OptionLabelsTest (found and fixed: the admin wrote the admin option label) |
| SNAP10 | Saved address chosen by Hyvä Checkout at checkout start (no default address) | its values reach the quote | ✅ in the test store the values were kept already; the plugin also reads the flat values of the address object Hyvä builds (adapter test Test/Magento/Plugin/QuoteAddressCustomerAddressConversionTest) |

### ADM. Admin attribute screens
| ID | Test | Expected | Status |
|---|---|---|---|
| ADM1 | Stores → Attributes lists both entities | both grids open | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts |
| ADM2 | Grid filters, sorting, pagination | work; system attributes not listed | ✅ automated: playwright/tests/adm-grid.spec.ts |
| ADM3 | Create dropdown with 3 options, text with max length, date with min/max | saved with the right rules | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts |
| ADM4 | Entity type, backend type, `is_user_defined`, form assignments in the database | as expected | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts |
| ADM5 | Input type switch shows/hides default value, validation rows, options | per type | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts |
| ADM6 | Edit: code and input type read-only; options and values reload | yes | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts (found and fixed: Magento 2.4.9 appends a script to the tab that unlocks the input type of a saved attribute and offers compatible types; the tab no longer adds it, the save ignored a posted type anyway) |
| ADM7 | Change, add, delete options; change default option | saved | ✅ automated: playwright/tests/adm-attribute-edit.spec.ts |
| ADM8 | Manage Labels per store view | `eav_attribute_label` rows | ✅ automated: playwright/tests/adm-attribute-edit.spec.ts |
| ADM9 | Storefront Properties: forms, status, sort order | updated | ✅ automated: playwright/tests/adm-attribute-edit.spec.ts |
| ADM10 | Delete with confirmation | deleted, message | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts |
| ADM11 | Validation: duplicate, reserved (also codes that become a `customer_*` column of the quote or the order: note, comment, is_guest …), malformed code, missing label, unsupported type, min > max, dropdown without options | clear message each | ✅ automated: playwright/tests/adm-attribute-validation.spec.ts |
| ADM12 | Failed save keeps the entered data | yes | ✅ automated: playwright/tests/adm-attribute-edit.spec.ts |
| ADM13 | URL tampering (`entity=foo`, id of another area, system attribute) | refused or fallback | ✅ automated: playwright/tests/adm-attribute-validation.spec.ts |
| ADM14 | Code length limits (51 customer, 60 address) | enforced | ✅ automated: playwright/tests/adm-attribute-edit.spec.ts |
| ADM15 | Mass enable, disable, delete (address grid) | messages, grid updated | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts |
| ADM16 | Mass actions on the customer grid; Select All with a filter; nothing selected; system id posted by hand | filtered rows only; "Please select attributes."; skipped | ✅ automated: playwright/tests/adm-grid.spec.ts |
| ADM17 | Attribute shown in the admin customer and address edit forms | field present | ✅ automated: playwright/tests/adm-entity-forms.spec.ts |
| ADM18 | Form assignment written by core or another module, then the attribute saved | kept | ✅ automated: playwright/tests/adm18-save-guards.spec.ts |
| ADM19 | Option ids of another attribute posted with the attribute form | refused, the other attribute unchanged | ✅ automated: playwright/tests/adm18-save-guards.spec.ts |

### ADM20. Grid column "Shown in"
| ID | Test | Expected | Status |
|---|---|---|---|
| ADM20 | Customer and address grid with attributes in different forms | the "Shown in" column lists the storefront forms (account forms in bold), "+N other" for forms of other modules, "Admin only" for none; admin forms are not listed | ✅ automated: playwright/tests/adm20-grid-forms-column.spec.ts; unit test FormColumnTest |

### SNAP11, SEC. Values on the order, values only the admin may change
| ID | Test | Expected | Status |
|---|---|---|---|
| SNAP11 | "Save on Orders" off for a customer attribute | the screen stores `mageos_order_snapshot` = 0 (no key when on); a logged-in order has a row for the attribute that is on and none for the one that is off, the account keeps both values; an attribute that is off but required in the checkout is still checked and not saved on the order | ✅ automated: playwright/tests/snap11-save-on-orders.spec.ts; unit tests ValidateRulesBuilderTest; adapter test Test/Magento/Observer/QuoteSubmitBeforeTest.php |
| ACC1 | "Also Save to Customer Account" for a logged-in checkout | typed value of the attribute with the setting is on the account, one without it is not; an empty field keeps the stored value; typed then cleared keeps it | ✅ automated: playwright/tests/acc1-save-to-account.spec.ts; unit AccountSaveDeciderTest; adapter SaveCheckoutValuesToAccountTest |
| EML1 | "Show in Order Email" | the screen stores `mageos_email` and refuses the combinations that cannot work; the new order email has an "Additional Information" section below the payment and shipping method with the attribute that is on (escaped), none for the one that is off; no flagged attribute, no section; an email sent again from the admin order has the section; a guest order shows the value typed in the checkout (escaped) and changes no account | ✅ automated: playwright/tests/eml1-order-email-section.spec.ts; unit AdditionalInformationTest, EmailTemplateInserterTest, SettingConflictsTest; adapter AddAdditionalInformationToOrderEmailTest, OrderEmailTemplateTest |
| SEC2 | Customer data sent with the cart API (`PUT /V1/carts/mine`, customer token), then the snapshot code run on the stored quote; an attribute whose code is a `customer_*` column of the quote (`note`, made like a data patch does); the grid | the value the customer has is kept for an ordinary attribute; an attribute with the code `note` does not take the cart note the customer typed (the snapshot reads `customer_<code>` from the quote in the admin area only); the customer grid warns about an attribute with a reserved code | ✅ automated: playwright/tests/sec2-quote-customer-key.spec.ts; adapter test Test/Magento/Observer/QuoteSubmitBeforeTest.php (admin order create still reads its values: aoc-admin-order.spec.ts) |
| SEC1 | "Customer Cannot Change This Value" on a customer attribute | the screen stores `mageos_admin_only`, refuses it together with required in the account forms; the account edit page does not print the field and a posted value is ignored; `PUT /V1/customers/me` with a customer token and `POST /V1/customers` without a token cannot set it, an admin token can; an ordinary attribute saves as before | ✅ automated: playwright/tests/sec1-admin-only.spec.ts; unit tests AdminOnlyAccessTest, ValidateRulesBuilderTest; adapter test Test/Magento/Plugin/CustomerAdminOnlyValuesTest.php |

### SRC. Options from a developer's source model
Attributes made by a data patch with a source model of their own (here Magento's Yes/No source, whose "No" is the value 0).

| ID | Test | Expected | Status |
|---|---|---|---|
| SRC1 | Edit page of a dropdown with a developer source, and of a normal dropdown | the developer one lists value and label read-only (no option table), saves without options, keeps its source model and writes no option rows; the normal one keeps its option table | ✅ automated: playwright/tests/src-developer-source.spec.ts; adapter tests Test/Magento/Model/Attribute/FormProcessorDeveloperOptionsTest.php and Test/Magento/Block/Adminhtml/Attribute/Edit/OptionsTest.php (escaping, fallback to the standard block) |
| SRC2 | Required dropdown of a developer source in the checkout, "No" (0) chosen | the value 0 is on the quote, the order and the snapshot, with its label | ✅ automated: playwright/tests/src-developer-source.spec.ts |

### ACL. Permissions
| ID | Test | Expected | Status |
|---|---|---|---|
| ACL1 | Role with only Customer Address Attributes | only that menu entry | ✅ automated: playwright/tests/acl-permissions.spec.ts |
| ACL2 | Same role, `entity=customer` for index, new, save | 403, nothing created | ✅ automated: playwright/tests/acl-permissions.spec.ts |
| ACL3 | Role with only Customer Attributes, `entity=address` | 403 | ✅ automated: playwright/tests/acl-permissions.spec.ts |
| ACL4 | Role with neither | no menu entries, 403 | ✅ automated: playwright/tests/acl-permissions.spec.ts |
| ACL5 | Attribute Relations screen with a restricted role | 403 without the resource | ✅ automated: playwright/tests/acl-permissions.spec.ts |

### DSP. Display and account forms
| ID | Test | Expected | Status |
|---|---|---|---|
| DSP1 | Show in Forms lists four options; unticking "Hyvä Checkout Address Forms" removes the field from checkout | yes | ✅ · automated: playwright/tests/dsp-address-text.spec.ts |
| DSP2 | Address text on checkout saved-address cards, account dashboard, customer order page, admin order, invoice, shipment | custom lines after the standard lines | ✅ · automated: playwright/tests/dsp-address-text.spec.ts (customer order page, admin order, invoice, shipment, account dashboard, address book), playwright/tests/rad4-saved-address-groups-checkout.spec.ts (checkout saved-address card) |
| DSP3 | Order text after renaming the attribute | snapshot label and value | ✅ (deleted attribute in unit logic) · automated: playwright/tests/cus7-order-survives-attribute-changes.spec.ts |
| DSP4 | "Address Text" off | line hidden for customer addresses | ✅ · automated: playwright/tests/dsp-address-text.spec.ts (saved addresses and the order address text) |
| DSP5 | Formats html, text, pdf, oneline; html escaped | correct | ✅ · automated: playwright/tests/dsp-address-text.spec.ts |
| DSP6 | Dates without time in the store format; select/multiselect/yes-no as labels | yes | ✅ · automated: playwright/tests/dsp-address-text.spec.ts |
| DSP7 | Order emails, real PDF files, credit memo page | lines after the address | ✅ · automated: playwright/tests/dsp7-emails-and-pdf.spec.ts (the order, invoice, shipment and credit memo e-mails are read from the bench mail catcher; the invoice, shipment and credit memo PDF files are downloaded from the admin and their text is read; the credit memo page is checked). Found and fixed: the order e-mail of a checkout order lacked the lines |
| DSP8 | Account address form: saved values, required block, rules (email, length, numeric, date range), new address defaults, server error keeps values | works with Hyvä styling | ✅ · automated: playwright/tests/dsp8-account-address-form.spec.ts |
| DSP9 | Tailwind build includes the module's templates | classes present | ✅ · automated: `dev/e2e/check-deploy.sh` (the Tailwind classes of the templates have a rule in the compiled stylesheet) |
| DSP10 | Address text in Hyvä Checkout's own address formats (`hyva_checkout_shipping`, `hyva_checkout_billing`) | escaped, one line per attribute | ✅ automated: playwright/tests/dsp-address-text.spec.ts, unit tests (found and fixed: these formats got unescaped text lines) |

### API. Extension attributes
| ID | Test | Expected | Status |
|---|---|---|---|
| API1 | Order get, getList, shipping assignments, order address repository | `custom_attribute_values` and `customer_attribute_values` present | ✅ · automated: api.php (order get, order list, shipping assignment and order address repository carry the same values) |
| API2 | Real REST call with an admin token | same data | ✅ `GET /V1/orders/:id` and `GET /V1/orders` (searchCriteria) with an admin token return `customer_attribute_values` and, on the billing and shipping assignment addresses, `custom_attribute_values` (code, label, input type, value, value label; the date label was a full timestamp, now "YYYY-MM-DD" for new orders); a guest order has no customer values; the order address repository has no REST route · automated: api.php |
| API3 | Order address read through REST and sent back unchanged; `OrderAddressRepositoryInterface::getList()` | accepted, snapshot unchanged; the list carries the values | ✅ automated: api.php (found and fixed: HTTP 400 because the value objects had no setters; a TypeError in getList) |

### CUS. Customer attributes
| ID | Test | Expected | Status |
|---|---|---|---|
| CUS1 | Account Information form shows the attributes assigned to it | saved values | ✅ · automated: playwright/tests/reg-cus-book.spec.ts |
| CUS2 | Save the account form | stored (date with time, emoji intact) | ✅ · automated: playwright/tests/reg-cus-book.spec.ts |
| CUS3 | Registration form shows and saves them | new customer has the values | ✅ · automated: playwright/tests/cus12-registration-form.spec.ts |
| CUS4 | Logged-in order | `mageos_cca_order_value` rows | ✅ · automated: playwright/tests/cus9-logged-in-order-rows.spec.ts |
| CUS5 | Admin order view, Account Information | one line per value | ✅ · automated: playwright/tests/cus9-logged-in-order-rows.spec.ts (one line per value in the admin order view) |
| CUS6 | Order API `customer_attribute_values` | rows present | ✅ · automated: api.php (customer_attribute_values in the order API output) |
| CUS7 | Rename or delete the attribute after the order | order rows unchanged | ✅ automated: playwright/tests/cus7-order-survives-attribute-changes.spec.ts |
| CUS8 | Required customer attribute empty | inline message, nothing saved | ✅ (found and fixed: the account information form could not be saved while a required customer attribute was hidden by a dependency rule; `EavDataRequiredForHiddenAttribute` now covers customer attributes too) · automated: playwright/tests/cus8-account-forms.spec.ts |
| CUS9 | Order placed in the Hyvä Checkout by a logged-in customer | same rows | ✅ automated: playwright/tests/cus9-logged-in-order-rows.spec.ts (one row per value the customer has, with labels; none for an attribute without a value; account-form-only attributes come from the customer; the admin order view lists them; the account is unchanged) |
| CUS10 | Guest checkout | no customer rows, no errors | ✅ automated: playwright/tests/val7-virtual-cart.spec.ts |
| CUS11 | Value editable in the admin customer form | saved | ✅ automated: playwright/tests/cus-admin-customer.spec.ts |
| CUS12 | Groups (radio/checkbox) and dependency rules in registration and account edit forms | render, save, hide | ✅ automated: playwright/tests/cus8-account-forms.spec.ts (registration: a required customer attribute that is only in the checkout forms blocks every registration in core, so the test makes it optional) |

### AOC. Admin order create and order address edit
| ID | Test | Expected | Status |
|---|---|---|---|
| AOC1 | Edit Order Address shows the order's values | prefilled | ✅ automated: playwright/tests/aoc-admin-order.spec.ts |
| AOC2 | Save changed, cleared, locale-date and multiselect values | snapshot updated, cleared row removed, labels kept | ✅ automated: playwright/tests/aoc-admin-order.spec.ts |
| AOC3 | Order create: Account Information shows customer attributes with the customer's values | yes | ✅ automated: playwright/tests/aoc-admin-order.spec.ts |
| AOC4 | Pick a saved address | custom fields filled | ✅ automated: playwright/tests/aoc-admin-order.spec.ts |
| AOC5 | Edit values, same as billing, place the order | both addresses carry the typed values | ✅ automated: playwright/tests/aoc-admin-order.spec.ts |
| AOC6 | "Add to address book" ticked | customer address gets the values | ✅ automated: playwright/tests/aoc-admin-order-more.spec.ts |
| AOC7 | New customer | no errors, form values on the order | ✅ automated: playwright/tests/aoc-admin-order-more.spec.ts (needs the required account-only attribute made optional: core refuses to create the customer otherwise) |
| AOC8 | Reorder from the admin | values copied | ✅ automated: playwright/tests/aoc-admin-order-more.spec.ts, aoc8-reorder-more.spec.ts (also after an address attribute was deleted: its value is left out and the old order keeps it; reorder of a guest order and of an Italy store order; found and fixed: core copies only the standard address fields, so the custom values were lost; `Plugin/AdminOrderReorderAddressValues` now carries them from the order's snapshot rows; a value hidden by a rule is not on the old order, so a required dependent is asked again) |
| AOC9 | Other store view locale, date parsing | correct | ✅ automated: playwright/tests/aoc9-italy-store-view.spec.ts (admin order for the Italy store view with an Italian address: `5/6/2026` is 6 May, values on the order, shown in the admin view) |
| AOC10 | Required address attribute empty | admin validation message | ✅ automated: playwright/tests/aoc-admin-order-more.spec.ts |
| AOC11 | Admin forms with attributes shown as radio/checkbox or hidden by rules | keep the dropdown/list, show every attribute | ✅ automated: playwright/tests/aoc-admin-order-more.spec.ts |
| AOC12 | Reorder (and Edit Order) of an order with customer attribute values | the values are in "Account Information" and on the new order | ✅ automated: playwright/tests/aoc8-reorder-more.spec.ts (found and fixed: only the address values were carried) |

### VAL. Server-side validation
| ID | Test | Expected | Status |
|---|---|---|---|
| VAL1 | Too short text, invalid email, non-numeric, date out of range, unknown option, yes/no 7 | order rejected, message names the address and attribute | ✅ · automated: api.php (too short and too long text, invalid e-mail, non-numeric, date after the maximum, unknown option, yes/no 7: refused with the attribute named) |
| VAL2 | Required attribute empty | rejected | ✅ · automated: api.php |
| VAL3 | Valid values | placed | ✅ · automated: api.php (valid values of every kind accepted, on the order) |
| VAL4 | Admin Edit Order Address with invalid values | not saved, message | ✅ (script) · automated in the browser: playwright/tests/val4-edit-order-address.spec.ts (the form refuses, the server refuses when the browser check is bypassed, nothing saved; a valid value is saved) |
| VAL5 | Rejection message on the Hyvä Checkout page | readable, values kept | ✅ automated: playwright/tests/val5-rejection-message-checkout.spec.ts (a text longer than its rule, sent past the browser's checks: a message at the top of the page and one at the field naming the attribute and the limit; typed values, shipping and payment choice kept; the corrected order is placed. The top message is core's "Please shorten your address..." and does not name the attribute) |
| VAL6 | Required attribute only in the address book form | core blocks the checkout (the Hyvä Checkout form does not show it); the admin warns on the attribute's edit page; adding it to "Hyvä Checkout Address Forms" fixes it | ✅ automated: playwright/tests/val6-required-address-book-only.spec.ts |
| VAL7 | Virtual cart: shipping values not validated | placed | ✅ automated: playwright/tests/val7-virtual-cart.spec.ts |
| VAL8 | Multiselect with an unknown id | rejected | ✅ automated: api.php |
| VAL9 | Admin Edit Order Address and order create with a required attribute hidden by its rule | not required by the server; its value is removed | ✅ automated (Edit Order Address and order create): playwright/tests/val4-edit-order-address.spec.ts (the admin form itself applies no rules in the browser and still asks for the field) |

### DEP. Dependencies and customer groups
| ID | Test | Expected | Status |
|---|---|---|---|
| DEP1 | Relations screen: list, new, save, edit, delete | saved, delete removes rules | ✅ · automated: playwright/tests/dep3-relation-validation.spec.ts (new, save and continue, delete; saved with Save and shown in the list with entity and rule count, opened from the list, renamed, option changed and a rule added, stored as changed, and the checkout follows the changed rules) |
| DEP2 | Circular rules | rejected | ✅ · automated: playwright/tests/dep3-relation-validation.spec.ts (the relations screen refuses a circle) |
| DEP3 | Rule validation messages (unknown option, text parent, self rule, duplicate) | rejected with the rule number | ✅ (unit) · automated in the browser: playwright/tests/dep3-relation-validation.spec.ts (empty name, no rule, unknown option, text parent, self rule on rule 2, duplicate on rule 2) |
| DEP4 | Order with a hidden required attribute | placed, no row | ✅ · automated: api.php (a required attribute hidden by its rule does not block, no row) |
| DEP5 | Parent option chosen, dependent empty and required | rejected | ✅ · automated: api.php (parent option chosen, required dependent empty: refused with the attribute named; given: placed) |
| DEP6 | Value of a hidden or group-restricted attribute sent | not kept | ✅ · automated: api.php |
| DEP7 | Customer Groups saved and shown again | rows stored and shown | ✅ automated: playwright/tests/dep7-customer-groups-shown-again.spec.ts (selected, saved, selected again after the save and after opening from the grid, changed, cleared = every group) |
| DEP8 | Account address form: group-restricted hidden, dependent shown for its option | works, hidden fields disabled | ✅ · automated: playwright/tests/dep8-account-address-groups-restriction.spec.ts |
| DEP9 | Hyvä Checkout (one-page, logged in): live hide and show | instant, order without the hidden value | ✅ · automated: playwright/tests/dep10-new-address-parent-changes.spec.ts |
| DEP10 | Hand-typed new address: change the parent several times, "Ship here", place the order | order has the parent and visible dependents only | ✅ automated: playwright/tests/dep10-new-address-parent-changes.spec.ts (logged-in customer, radio parent; found and fixed: the radio and checkbox groups of address attributes in Hyvä Checkout lost every `wire:` attribute, so their values never reached the quote or the order; checked: both tests fail without the fix) |
| DEP11 | Default and mobile variants, billing form, guest, second store | works | ✅ automated: playwright/tests/dep11-variants-guest-store.spec.ts (multi-step and mobile with a guest, one-page guest, the billing form with its own parent and dependent, the Italy store) |
| DEP12 | Multiselect parent in the checkout | dependent follows | ✅ automated: playwright/tests/dep12-multiselect-parent.spec.ts (fails when the rule is broken, checked) |
| DEP13 | Required dependent shown and left empty | place order blocked with the field message | ✅ automated: playwright/tests/dep13-required-dependent.spec.ts |
| DEP14 | Account address form saved with a hidden required attribute | saves | ✅ (found and fixed: the account form did not send the hidden field and the plugin treated that as a value; `EavDataRequiredForHiddenAttribute` now handles `false`) · automated: playwright/tests/dep14-account-address-hidden-required.spec.ts |
| DEP15 | Nested rules (A → B → C) | C only when A and B match | ✅ automated: playwright/tests/dep15-nested-rules.spec.ts (fails when the B to C rule is broken, checked) |
| DEP16 | Parent option deleted later; attribute deleted | rule never matches; rules and group rows disappear | ✅ automated: playwright/tests/dep16-deleted-option-and-attribute.spec.ts |
| DEP17 | A value posted for a field hidden by a rule or the customer group (account and address book forms) | not saved; the stored value stays | ✅ automated: playwright/tests/dep14-account-address-hidden-required.spec.ts (found and fixed: only the browser hid it) |
| DEP18 | A dependent field hidden and shown again before the next update | its old value is not sent | ✅ automated: playwright/tests/dep10-new-address-parent-changes.spec.ts (found and fixed: the cleared field kept its value in Magewire) |
| DEP19 | Browser rule scripts (account forms, checkout address forms) against `Service/VisibilityResolver` | the same fields shown for every case (nested rules, multiple select parent, two rules, group restriction, circle) | ✅ automated: playwright/tests/dep19-browser-rules-agree.spec.ts with the cases of dev/e2e/rule-cases.json (fails when a script drifts, checked) |

### RAD. Radio buttons and checkboxes
| ID | Test | Expected | Status |
|---|---|---|---|
| RAD1 | Admin "Display As" per input type | only dropdown, multiple select, yes/no | ✅ · automated: playwright/tests/adm-display-as.spec.ts |
| RAD2 | Account address form groups | saved values checked, "None" when optional, saves | ✅ automated: playwright/tests/rad2-account-address-groups.spec.ts |
| RAD3 | Rules with radio and checkbox parents | instant show/hide | ✅ · automated: playwright/tests/sav6-cco-more.spec.ts |
| RAD4 | Hyvä Checkout one-page: render, saved values, instant visibility, order | works | ✅ automated (address attributes): playwright/tests/rad4-saved-address-groups-checkout.spec.ts (the saved address card shows the groups and the order carries them; a new address in the dialog starts empty and leaves the saved address alone), rad8-address-groups-checkout.spec.ts, dep10 and dep11 · automated: playwright/tests/rad4-saved-address-groups-checkout.spec.ts |
| RAD5 | Core metadata and REST output | standard input type plus rule `mageos_display` | ✅ · automated: api.php (standard input type plus the rule `mageos_display`, none for a list) |
| RAD6 | Required checkbox group with nothing ticked | blocked (server) | ✅ automated: playwright/tests/rad6-required-checkbox-group.spec.ts |
| RAD7 | Customer attributes as groups in registration and account edit | render and save | ✅ automated: playwright/tests/cus8-account-forms.spec.ts |
| RAD8 | Other checkout variants, hand-typed address, billing form | works | ✅ automated: playwright/tests/rad8-address-groups-checkout.spec.ts (found and fixed: a checkbox group of an address attribute sent a single true instead of the ticked options, so every order with a ticked box was refused with "has an invalid value"; its starting value is now a list) |
| RAD9 | Keyboard use and screen reader labels | usable | ✅ keyboard and accessible names automated: playwright/tests/rad9-keyboard-accessibility.spec.ts (arrow keys, Space and Tab in the checkout and the account address form; radio buttons and checkboxes announced with their label; the group has a role and the label of the attribute as its name, added after this test showed it was missing). **Not automated**: what a real screen reader says |
| RAD10 | Click on the label of a radio or checkbox group | selects no option | ✅ automated: playwright/tests/rad9-keyboard-accessibility.spec.ts (found and fixed: the label pointed at the first option) |

### REST. REST API
| ID | Test | Expected | Status |
|---|---|---|---|
| REST1 | Guest cart: shipping-information and payment-information with `custom_attributes` | order with the values, readable by `GET /orders/{id}` | ✅ · automated: api.php |
| REST2 | Too short value, unknown option, missing required | 400, nothing placed | ✅ · automated: api.php |
| REST3 | Dependency rule: hidden required missing, hidden value sent, shown required missing | placed, dropped, rejected | ✅ · automated: api.php (hidden required missing: placed; hidden value sent: dropped; shown required missing: refused) |
| REST4 | Customer token: `GET`/`PUT customers/me`, bad address value | read, update, 400 | ✅ · automated: api.php (customers/me returns the custom attributes; an address that breaks a rule is refused and names the attribute) |
| REST5 | `carts/mine` with a saved address and with a new address | order values | ✅ · automated: api.php (saved address read from `customers/me`, new address) |
| REST6 | Error message shown to an API client | readable | ✅ the message of a refused address names the attribute · automated: api.php |
| REST7 | Second website (`/rest/<store>/V1/...`) | website values apply | ✅ automated: api.php (`/rest/ita/V1`, required override, order number prefix, disabled attribute not offered) |

### WEB. Per-website scope
| ID | Test | Expected | Status |
|---|---|---|---|
| WEB1 | Websites tab: Enabled, Required, Default per website, reopen | kept; "Use Default" shows the global value | ✅ · automated: playwright/tests/web1-websites-tab.spec.ts |
| WEB2 | Attribute disabled for one website | absent from its account form and guest checkout, present on the other | ✅ · automated: playwright/tests/web-scope.spec.ts |
| WEB3 | Required for one website only | required there (form, API metadata, checkout), optional elsewhere | ✅ form and checkout · automated: playwright/tests/web-scope.spec.ts (Italy: empty blocked, filled placed; main optional) |
| WEB4 | Default value per website (text, text area, yes/no) | used for a new address in the account form | ✅ account form (text, text area, yes/no; Hyvä Checkout applies no text default) · automated: playwright/tests/web-scope.spec.ts |
| WEB5 | Address text where the attribute is disabled | no line; old orders keep theirs | ✅ automated: playwright/tests/web-scope.spec.ts |
| WEB6 | Logged-in customer on the second website, account address form | follows that website | ✅ automated: playwright/tests/web-scope.spec.ts |
| WEB7 | Mass enable/disable | changes the global flag, overrides kept | ✅ automated: playwright/tests/web-scope.spec.ts |
| WEB8 | Customer (non-address) attribute with a website override | same | ✅ automated: playwright/tests/web-scope.spec.ts |

### SAV. Saved address missing a required attribute
| ID | Test | Expected | Status |
|---|---|---|---|
| SAV1 | Attribute becomes required; checkout with a saved address lacking it | notice with an edit link under the address list | ✅ · automated: playwright/tests/sav-saved-address-notice.spec.ts |
| SAV2 | Place the order anyway | message names the address type and the field | ✅ · automated: playwright/tests/sav-saved-address-notice.spec.ts |
| SAV3 | Follow the link, fill the field, save, return, order | placed | ✅ · automated: playwright/tests/sav-saved-address-notice.spec.ts |
| SAV4 | Switch between complete and incomplete saved addresses | notice appears and disappears | ✅ · automated: playwright/tests/sav-saved-address-notice.spec.ts |
| SAV5 | Attribute hidden by rule or group | no notice, no block | ✅ · automated: playwright/tests/sav-saved-address-notice.spec.ts (hidden by a rule; a group that cannot see it, with the allowed group getting the notice as negative control; disabled for the website) |
| SAV6 | Several missing fields, several addresses, different billing and shipping | one field named, several give a generic text, one notice each | ✅ · automated: playwright/tests/sav6-cco-more.spec.ts |
| SAV7 | Default and mobile variants | notice in place | ✅ automated: playwright/tests/sav-saved-address-notice.spec.ts |
| SAV8 | Return to the checkout in the same tab | updated after a reload only | ✅ known limitation, characterised: the open tab keeps the old notice until a reload · automated: playwright/tests/sav6-cco-more.spec.ts |
| SAV9 | A required attribute in the checkout but not in the address book form | notice without a link, asks to type a new address; the place-order message says the same | ✅ automated: playwright/tests/sav-saved-address-notice.spec.ts; the admin warns about such an attribute |

### CCO. Customer attributes in the Hyvä Checkout
Use customer attributes (text, dropdown as radio buttons, date) ticked for "Hyvä Checkout" in Show in Forms. The one-page, the
multi-step (default) and the mobile checkout were tested; the sections use the containers all variants share.

| ID | Test | Expected | Status |
|---|---|---|---|
| CCO1 | Admin: position, sort order and prefill are always listed and greyed out while "Hyvä Checkout" is not ticked; saved in the rules | stored as `mageos_checkout_*` | ✅ · automated: playwright/tests/cco1-admin-checkout-settings.spec.ts |
| CCO2 | Sections render at their positions | at the right containers | ✅ automated: playwright/tests/cco2-positions.spec.ts (all seven positions on the one-page, multi-step and mobile checkouts, each on the right step) |
| CCO3 | Prefill on: logged-in customer sees the saved value; prefill off: empty (default value only) | as set | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO4 | Typing a value | stored on the quote, customer account unchanged | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO5 | Clearing a prefilled value | stored as empty (no row on the quote), the account keeps its value, no refill until the next page load | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO6 | Order placed | snapshot rows carry the typed values; attributes not shown in the checkout still come from the customer | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO7 | Required attribute empty | the order is blocked with "…is a required value." | ✅ · automated: playwright/tests/cco-guest-checkout.spec.ts |
| CCO8 | Dependency rule across two positions (dropdown above shipping address shows the text below) | instant show and hide; hidden value dropped on the server; hidden required value does not block; order without it | ✅ · automated: playwright/tests/cco-guest-checkout.spec.ts |
| CCO9 | Guest: fields shown, value saved on the guest quote, guest order | yes | ✅ (multi-step and mobile guest orders) · automated: playwright/tests/cco-guest-checkout.spec.ts |
| CCO10 | Guest logs in after typing | customer without an active cart: typed values kept (prefill only fills empty ones); customer with an active cart: that cart and its values win, the guest's values fill the attributes that cart has no value for | ✅ · automated: playwright/tests/cco10-guest-logs-in.spec.ts |
| CCO22 | Required customer attribute placed at a shipping position, virtual-only cart | order not blocked | ✅ (found and fixed: shipping-position attributes are skipped for virtual carts) · automated: playwright/tests/val7-virtual-cart.spec.ts |
| CCO11 | Customer group restriction hides the section for other groups | hidden | ✅ attributes limited to Wholesale: hidden for a General customer (the order has no value for them although the account holds one); a Wholesale customer sees them · automated: playwright/tests/sav6-cco-more.spec.ts |
| CCO12 | Website scope (disabled or required for one website) | follows the website | ✅ website 2 only: attribute disabled (field absent on the Italy checkout), required (radio without "None", `required` set); main website unchanged; server-side required check on the Italy site not placed · automated: playwright/tests/web-scope.spec.ts |
| CCO13 | Radio and checkbox display, multiselect, date, text area at checkout | work and save | ✅ radio, checkbox group (multiselect), date, text area and text saved on the quote and the order; the date label is "YYYY-MM-DD" · automated: playwright/tests/cco-guest-checkout.spec.ts |
| CCO14 | Shipping method or address change refreshes nothing wrongly (values kept) | values kept | ✅ changing the shipping method and the postcode keeps every value in the page and on the quote · automated: playwright/tests/sav6-cco-more.spec.ts |
| CCO15 | Order placed by REST with a required checkout attribute | not blocked | ✅ guest REST order (guest-carts, shipping-information, payment-information) with required customer attributes empty: placed, no customer value rows · automated: api.php |
| CCO16 | Attribute ticked only for "Hyvä Checkout" and the admin form (order attribute) | value on the order, never on the account | ✅ attribute in the checkout and admin forms only: value on the order, no account form, account values unchanged · automated: playwright/tests/cco-guest-checkout.spec.ts (value on the order), playwright/tests/cus8-account-forms.spec.ts (not in the account form) |
| CCO17 | Default (multi-step) and mobile variants | same results | ✅ guest orders on both with values from every step (values kept between steps) · automated: playwright/tests/cco-steps-rules.spec.ts |
| CCO19 | Rule whose parent is on another step (multi-step and mobile) | the dependent field keeps the state the server rendered, no wrong hiding | ✅ (fixed: the script used to hide it because the parent was not on the page) · automated: playwright/tests/cco-steps-rules.spec.ts |
| CCO20 | Rule across positions on the multi-step variant (several steps) and mobile (three steps) | show/hide and order values correct | ✅ · automated: playwright/tests/cco-steps-rules.spec.ts |
| CCO21 | Rapid toggling of a parent | ends in the right state (a late response may flicker) | ✅ · automated: playwright/tests/cco-steps-rules.spec.ts |
| CCO18 | Strict CSP and console on every checkout test | no errors | ✅ no console or CSP errors across the one-page, mobile and Italy checkouts of this run · automated: playwright/tests/rob-console-csp.spec.ts |

### REL. Relation warnings
| ID | Test | Expected | Status |
|---|---|---|---|
| REL1 | Dependent shown in a form where the parent is not (checkout) | warning on the edit page and after saving | ✅ · automated: playwright/tests/rel-warnings.spec.ts |
| REL2 | Both attributes in the same forms | no warning | ✅ (unit) · automated: playwright/tests/rel-warnings.spec.ts |
| REL3 | Several forms and several rules; address relations | one warning per rule and form | ✅ (unit) · automated: playwright/tests/rel-warnings.spec.ts (after Save and Continue the page lists each warning twice: once from the save, once from the edit page) |
| REL4 | Form assignment changed later | warning appears when the relation is opened again | ✅ automated: playwright/tests/rel-warnings.spec.ts |

### ROB. Robustness
| ID | Test | Expected | Status |
|---|---|---|---|
| ROB1 | `var/log/exception.log`, `system.log`, `debug.log` after all runs | no module errors | ✅ no module errors; the only exceptions are the intended validation failures of earlier tests · automated: assertions.php |
| ROB2 | Browser console and network tab at checkout and in the admin | no errors | ✅ automated: playwright/tests/rob-console-csp.spec.ts (storefront, Italy, customer pages, admin screens; third-party Stripe/PayPal/Amazon/Google messages ignored) |
| ROB3 | 50 attributes, 5 addresses | queries stay bounded (batched loads) | ✅ automated: playwright/tests/rob3-bounded-queries.spec.ts (found and fixed: the address text read the options of every dropdown from the database once per address; now once per request) |
| ROB4 | Request count and time compared with the module disabled | small difference | ✅ measured with `dev/e2e/compare-module-off.sh` (module and companion disabled, then enabled; run on demand, not in run-all): first-party requests identical on every page (checkout 11/11, home 24/24, others 8/8); DOM ready: home +17 ms, registration +72, account edit +3, new address +99, guest checkout +878 ms (1633 → 2511; an earlier run +445 ms), which includes rendering the ~45 custom fields the module adds there. Second run (2026-11-16, now with a logged-in checkout, the admin order create page and time to first byte; developer mode, caches warm, 7 loads, first dropped): home +10 ms, registration -19, account edit -56, new address -25, guest checkout +42, logged-in checkout -19, admin order create -122; first byte on/off: guest checkout 825/748 ms, logged-in checkout 858/764 ms, others within 100 ms; first-party requests identical on all 7 pages. The differences are inside the run-to-run noise of the bench (the first run's +445 to +878 ms for the guest checkout did not repeat). Production mode (`dev/e2e/compare-production.sh`: `deploy:mode:set production`, the configuration compiled again without the module for the second run; module on/off, DOM ready): home +32 ms, registration +20, account edit -9, new address +38, guest checkout +143 (871 → 1014; first byte 691 → 856), logged-in checkout +139 (866 → 1005), admin order create +69; first-party requests identical (checkout 11/11, home 24/24, others 8/8, admin 170/171). **Not measured**: a cold cache, the cost per field (ROB3 covers the SQL statements per address) |
| ROB5 | CSP: no inline `style`/`on*` attributes or unregistered inline scripts; checkout, account and registration forms, and the admin relation list/edit page, with the browser console open | no CSP violations, relation rows still open on click | ✅ automated: playwright/tests/rob-console-csp.spec.ts (raw server HTML of checkout, registration, login, account edit, address book, new address, order list, Italy pages: no `on*`/`style` on the module's elements and no nonce-less inline script of the module; no CSP violation on those pages and the admin screens) |
| ROB6 | Database statements of the module per page (home, registration, account edit, new address, guest and logged-in checkout) | within the limits of the spec | ✅ measured with `dev/e2e/count-queries.sh` (Magento's query log with call stacks; a statement counts when module code is in its stack; on demand, not in run-all; changes app/etc/env.php while it runs and restores it byte for byte): home 0, registration 6, account edit 6, new address 13, guest checkout 15, logged-in checkout 16. Negative control: the code before the checkout query reduction gives 55 and 56 on the two checkout pages and fails |
| ROB7 | Idle checkout with dependency rules, with and without the saved-address notice | the module's scripts do not change the page | ✅ automated: playwright/tests/rob7-idle-checkout.spec.ts (found and fixed: the visibility script and the notice rewrote classes and text on every pass and woke themselves up again, about 6,000 changes a second) |

## 6. Known gaps (by decision, not bugs)
Multiline, file and image input types, GraphQL, an "Update my account" button for values typed in the checkout, per-option control of dependent
options, an admin warning and back-fill tool for attributes that become required after addresses exist, and an admin grid
column or filter for the values are not part of this version. A customer attribute cannot be required in the checkout only (see
"Future improvements" in STATUS.md).
