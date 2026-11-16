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

Rules for new code: put logic into pure classes in `Service/` and cover it with a unit test; keep Magento adapters thin.

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
marked `automated` in the lists below. The scripts it uses:

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
| INS1 | `composer require`, `module:enable`, `setup:upgrade` | enabled, no errors | ✅ |
| INS2 | Tables `mageos_cca_quote_value`, `_quote_address_value`, `_order_value`, `_order_address_value`, `_relation`, `_relation_detail`, `_attribute_group` exist | present | ✅ |
| INS3 | Foreign keys: quote tables cascade to quote/quote address and `eav_attribute`; order tables cascade only to the sales tables and have no FK to `eav_attribute` | as listed | ✅ |
| INS4 | Generated whitelist equals `etc/db_schema_whitelist.json`; a second `setup:upgrade` changes nothing | yes | ✅ |
| INS5 | `setup:di:compile` | no errors | ✅ |
| INS6 | Main module classes load with Hyvä classes blocked from autoloading | all load | ✅ |
| INS7 | `setup:di:compile` with Hyvä Checkout, its dependants and the companion module disabled | no errors | ✅ (the Hyvä files stay on disk, so a fully Hyvä-free codebase is not proven) |
| INS8 | PHPStan level 5 | no errors | ✅ |
| INS9 | `module:disable` + `setup:upgrade`: storefront, checkout and admin order view still work; re-enable keeps the data | pages work, an order can be placed; **the module tables are dropped by `setup:upgrade` and come back empty** (Magento's declarative schema), documented in the guide | ✅ works, ⚠ data is not kept |
| INS10 | Disable the modules, `module:uninstall -r` | seven tables and the two form assignments removed, registry and Composer entries removed; EAV attributes stay | ✅ |
| INS11 | Static content deploy and Hyvä Tailwind build | no errors | ✅ checked by `dev/e2e/check-deploy.sh` (run on demand, not part of run-all): `hyva:config:generate` lists the module and its companion, `npm run build` in the theme succeeds, the 14 Tailwind-looking classes of the templates have a rule in the compiled stylesheet (an empty stylesheet fails the same check), `setup:static-content:deploy -f` for the storefront theme and the admin theme succeeds without errors and deploys the compiled stylesheet; the files it touches are put back |

### REG. Attribute registration
| ID | Test | Expected | Status |
|---|---|---|---|
| REG1 | No custom attribute is enabled: checkout and orders behave like core | no custom fields, order placed, no module rows, no errors | ✅ (all custom attributes switched off; not deleted) |
| REG2 | Create the test attributes | providers list all of them; the quote address attribute list contains them | ✅ |
| REG3 | Delete an attribute | gone from the lists; quote rows cascade away | ✅ |
| REG4 | System attributes (firstname, street, …) | never picked up | ✅ automated: playwright/tests/reg4-system-attributes.spec.ts (the codes the module works with are exactly the user defined attributes of the two entities; no system code among them) |
| REG5 | Attribute not assigned to any form | not shown; submitted values still work | ✅ automated: api.php, playwright/tests/reg5-attribute-without-form.spec.ts |

### CHK. Hyvä Checkout: values reach the quote address
Main run on the one-page variant. Cases marked ★ are repeated on the other variants (manual).

| ID | Test | Expected | Status |
|---|---|---|---|
| CHK1 ★ | Guest fills all seven fields in the shipping address | rows for the shipping quote address | ✅ |
| CHK2 ★ | Billing same as shipping | billing quote address has the values too | ✅ |
| CHK3 ★ | Different billing address with different values | each address keeps its own | ✅ |
| CHK4 | Change a value and continue again | row updated, no duplicates | ✅ |
| CHK5 | Clear an optional value | row deleted, no empty string | ✅ |
| CHK6 | Text `0` and yes/no "No" | stored as `0`, not treated as empty | ✅ (text `0` in unit tests) |
| CHK7 | Multiselect with 0, 1, several options | none = no row; several = comma separated ids | ✅ |
| CHK8 | Required attribute empty | blocked in the browser | ✅ |
| CHK9 | Quotes, `<script>`, emoji, accents, Japanese, 250+ characters | stored exactly, escaped on output | ✅ (Hyvä Checkout limits text to the attribute's maximum length, 255 by default) |
| CHK10 | Reload the page at each step | values still present | ✅ |
| CHK11 ★ | Logged-in customer, new address | same as CHK1 | ✅ |
| CHK12 ★ | Logged-in customer selects a saved address without values | no stale values from the previous address | ✅ |
| CHK13 | Saved address with values | values on the quote and in the form | ✅ |
| CHK14 | Date typed in a store with another locale | stored consistently, shown correctly | ✅ automated: playwright/tests/chk-steps-tabs-locale.spec.ts (Italy store: customer and address date stored as typed with 00:00:00, label `YYYY-MM-DD`, the admin order view shows the same day) |
| CHK15 | Virtual-only cart (billing only) | billing values stored | ✅ (a temporary virtual product; sections at shipping positions are not shown) |
| CHK16 | Back from payment to shipping and edit | values reload from the quote | ✅ automated: playwright/tests/chk-steps-tabs-locale.spec.ts (multi-step checkout: back button, values reload, edit kept, payment value kept, order carries them) |
| CHK17 | Two tabs edit the same quote | last write wins, no duplicates | ✅ automated: playwright/tests/chk-steps-tabs-locale.spec.ts (two tabs of one session; the quote and the order hold the last write, one row per attribute) |
| CHK18 | Old quote created before the attributes existed | no errors | ✅ register page and checkout (typing, Magewire calls 200): no console errors; admin shows only core's ViewTransition warning · automated: playwright/tests/chk18-old-quote.spec.ts (a guest cart with an address, then a new address attribute and a new customer attribute for the checkout are created: the reloaded checkout shows the empty new fields without script errors, CSP violations or failed Magewire calls, keeps the old values, takes the new ones and the order carries all of them) |
| CHK19 | Guest cart merged into the customer cart at login | customer cart keeps its values, nothing orphaned | ✅ (the guest cart's typed values go with the guest cart when the customer already has an active cart) |
| CHK20 | Default (multi-step) and mobile checkout variants, second store view | same results as the one-page run | ✅ automated in parts: dep11-variants-guest-store.spec.ts, rad8-address-groups-checkout.spec.ts, cco2-positions.spec.ts, smoke-variants.spec.ts, sav-saved-address-notice.spec.ts (multi-step and mobile: sections per step, rules, groups, order values, saved address notice; Italy store: DEP11, CHK14, WEB) |

### ORD. Order address snapshot
| ID | Test | Expected | Status |
|---|---|---|---|
| ORD1 ★ | Guest order | one row per filled attribute for billing and shipping with label, input type, value, value label | ✅ |
| ORD2 | Select, multiselect, yes/no | value = ids, value label = readable text | ✅ |
| ORD3 | Logged-in order | same | ✅ |
| ORD4 | Card payment, 3D Secure COMPLETE and FAIL | FAIL: no order, no rows, quote values intact; COMPLETE: as ORD1 | **manual** |
| ORD5 | Declined payment or place-order exception | no orphan rows, quote values remain | ✅ validation failures (declined card payments are manual) |
| ORD6 | Order with no custom values | no rows, no errors | ✅ |
| ORD7 | Order grid and order list pages | no extra query per row | ✅ automated: playwright/tests/snap-ord-guards.spec.ts |

### BOOK. Address book
| ID | Test | Expected | Status |
|---|---|---|---|
| BOOK1 | New address, "save in address book" ticked | customer address holds all values | ✅ · automated: playwright/tests/book-address-book.spec.ts |
| BOOK2 | Same, not ticked | no customer address; order rows exist | ✅ automated: playwright/tests/book-address-book.spec.ts |
| BOOK3 | Later order with the saved address | values on the quote and the order | ✅ |
| BOOK4 | Admin customer edit of an address value | saved by core EAV | ✅ automated: playwright/tests/cus-admin-customer.spec.ts |
| BOOK5 | Account Add/Edit Address form | shows and saves the attributes | ✅ |

### SNAP. Snapshot stability
| ID | Test | Expected | Status |
|---|---|---|---|
| SNAP1 ★ | Delete an attribute | order rows unchanged, quote rows gone | ✅ |
| SNAP2 ★ | Change the label | order label unchanged | ✅ |
| SNAP3 | Rename or delete an option | order value label unchanged | ✅ |
| SNAP4 | New attribute with the code of a deleted one | old orders keep the old label | ✅ (in a rolled-back transaction) |
| SNAP5 | Change the input type | old rows keep the old type | ✅ (same) |
| SNAP6 | Delete the order | order rows cascade away | ✅ · automated: assertions.php |
| SNAP7 | Delete the quote | quote rows cascade away | ✅ |
| SNAP8 | Save a quote address twice with identical values | second save writes nothing | ✅ automated: playwright/tests/snap-ord-guards.spec.ts |

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
| ADM11 | Validation: duplicate, reserved, malformed code, missing label, unsupported type, min > max, dropdown without options | clear message each | ✅ automated: playwright/tests/adm-attribute-validation.spec.ts |
| ADM12 | Failed save keeps the entered data | yes | ✅ automated: playwright/tests/adm-attribute-edit.spec.ts |
| ADM13 | URL tampering (`entity=foo`, id of another area, system attribute) | refused or fallback | ✅ automated: playwright/tests/adm-attribute-validation.spec.ts |
| ADM14 | Code length limits (51 customer, 60 address) | enforced | ✅ automated: playwright/tests/adm-attribute-edit.spec.ts |
| ADM15 | Mass enable, disable, delete (address grid) | messages, grid updated | ✅ automated: playwright/tests/adm-attribute-lifecycle.spec.ts |
| ADM16 | Mass actions on the customer grid; Select All with a filter; nothing selected; system id posted by hand | filtered rows only; "Please select attributes."; skipped | ✅ automated: playwright/tests/adm-grid.spec.ts |
| ADM17 | Attribute shown in the admin customer and address edit forms | field present | ✅ automated: playwright/tests/adm-entity-forms.spec.ts |

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
| DSP1 | Show in Forms lists four options; unticking "Hyvä Checkout Address Forms" removes the field from checkout | yes | ✅ |
| DSP2 | Address text on checkout saved-address cards, account dashboard, customer order page, admin order, invoice, shipment | custom lines after the standard lines | ✅ |
| DSP3 | Order text after renaming the attribute | snapshot label and value | ✅ (deleted attribute in unit logic) |
| DSP4 | "Address Text" off | line hidden for customer addresses | ✅ (order case in code) |
| DSP5 | Formats html, text, pdf, oneline; html escaped | correct | ✅ |
| DSP6 | Dates without time in the store format; select/multiselect/yes-no as labels | yes | ✅ |
| DSP7 | Order emails, real PDF files, credit memo page | lines after the address | **manual** |
| DSP8 | Account address form: saved values, required block, rules (email, length, numeric, date range), new address defaults, server error keeps values | works with Hyvä styling | ✅ |
| DSP9 | Tailwind build includes the module's templates | classes present | ✅ |

### API. Extension attributes
| ID | Test | Expected | Status |
|---|---|---|---|
| API1 | Order get, getList, shipping assignments, order address repository | `custom_attribute_values` and `customer_attribute_values` present | ✅ |
| API2 | Real REST call with an admin token | same data | ✅ `GET /V1/orders/:id` and `GET /V1/orders` (searchCriteria) with an admin token return `customer_attribute_values` and, on the billing and shipping assignment addresses, `custom_attribute_values` (code, label, input type, value, value label; the date label was a full timestamp, now "YYYY-MM-DD" for new orders); a guest order has no customer values; the order address repository has no REST route · automated: api.php |

### CUS. Customer attributes
| ID | Test | Expected | Status |
|---|---|---|---|
| CUS1 | Account Information form shows the attributes assigned to it | saved values | ✅ |
| CUS2 | Save the account form | stored (date with time, emoji intact) | ✅ |
| CUS3 | Registration form shows and saves them | new customer has the values | ✅ |
| CUS4 | Logged-in order | `mageos_cca_order_value` rows | ✅ |
| CUS5 | Admin order view, Account Information | one line per value | ✅ |
| CUS6 | Order API `customer_attribute_values` | rows present | ✅ |
| CUS7 | Rename or delete the attribute after the order | order rows unchanged | ✅ automated: playwright/tests/cus7-order-survives-attribute-changes.spec.ts |
| CUS8 | Required customer attribute empty | inline message, nothing saved | ✅ (found and fixed: the account information form could not be saved while a required customer attribute was hidden by a dependency rule; `EavDataRequiredForHiddenAttribute` now covers customer attributes too) · automated: playwright/tests/cus8-account-forms.spec.ts |
| CUS9 | Order placed in the Hyvä Checkout by a logged-in customer | same rows | ✅ automated: playwright/tests/cus9-logged-in-order-rows.spec.ts (one row per value the customer has, with labels; none for an attribute without a value; account-form-only attributes come from the customer; the admin order view lists them; the account is unchanged) |
| CUS10 | Guest checkout | no customer rows, no errors | ✅ automated: playwright/tests/val7-virtual-cart.spec.ts |
| CUS11 | Value editable in the admin customer form | saved | ✅ automated: playwright/tests/cus-admin-customer.spec.ts |
| CUS12 | Groups (radio/checkbox) and dependency rules in registration and account edit forms | render, save, hide | ✅ automated: playwright/tests/cus12-registration-form.spec.ts, playwright/tests/cus8-account-forms.spec.ts (registration: a required customer attribute that is only in the checkout forms blocks every registration in core, so the test makes it optional) |

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

### VAL. Server-side validation
| ID | Test | Expected | Status |
|---|---|---|---|
| VAL1 | Too short text, invalid email, non-numeric, date out of range, unknown option, yes/no 7 | order rejected, message names the address and attribute | ✅ |
| VAL2 | Required attribute empty | rejected | ✅ · automated: api.php |
| VAL3 | Valid values | placed | ✅ |
| VAL4 | Admin Edit Order Address with invalid values | not saved, message | ✅ (script) · automated in the browser: playwright/tests/val4-edit-order-address.spec.ts (the form refuses, the server refuses when the browser check is bypassed, nothing saved; a valid value is saved) |
| VAL5 | Rejection message on the Hyvä Checkout page | readable, values kept | ✅ automated: playwright/tests/val5-rejection-message-checkout.spec.ts (a text longer than its rule, sent past the browser's checks: a message at the top of the page and one at the field naming the attribute and the limit; typed values, shipping and payment choice kept; the corrected order is placed. The top message is core's "Please shorten your address..." and does not name the attribute) |
| VAL6 | Required attribute only in the address book form | core blocks the checkout (the Hyvä Checkout form does not show it); the admin warns on the attribute's edit page; adding it to "Hyvä Checkout Address Forms" fixes it | ✅ automated: playwright/tests/val6-required-address-book-only.spec.ts |
| VAL7 | Virtual cart: shipping values not validated | placed | ✅ automated: playwright/tests/val7-virtual-cart.spec.ts |
| VAL8 | Multiselect with an unknown id | rejected | ✅ automated: api.php |

### DEP. Dependencies and customer groups
| ID | Test | Expected | Status |
|---|---|---|---|
| DEP1 | Relations screen: list, new, save, edit, delete | saved, delete removes rules | ✅ · automated (new, save, edit page, delete): playwright/tests/dep3-relation-validation.spec.ts |
| DEP2 | Circular rules | rejected | ✅ |
| DEP3 | Rule validation messages (unknown option, text parent, self rule, duplicate) | rejected with the rule number | ✅ (unit) · automated in the browser: playwright/tests/dep3-relation-validation.spec.ts (empty name, no rule, unknown option, text parent, self rule on rule 2, duplicate on rule 2) |
| DEP4 | Order with a hidden required attribute | placed, no row | ✅ |
| DEP5 | Parent option chosen, dependent empty and required | rejected | ✅ |
| DEP6 | Value of a hidden or group-restricted attribute sent | not kept | ✅ |
| DEP7 | Customer Groups saved and shown again | rows stored and shown | ✅ automated: playwright/tests/dep7-customer-groups-shown-again.spec.ts (selected, saved, selected again after the save and after opening from the grid, changed, cleared = every group) |
| DEP8 | Account address form: group-restricted hidden, dependent shown for its option | works, hidden fields disabled | ✅ |
| DEP9 | Hyvä Checkout (one-page, logged in): live hide and show | instant, order without the hidden value | ✅ |
| DEP10 | Hand-typed new address: change the parent several times, "Ship here", place the order | order has the parent and visible dependents only | ✅ automated: playwright/tests/dep10-new-address-parent-changes.spec.ts (logged-in customer, radio parent; found and fixed: the radio and checkbox groups of address attributes in Hyvä Checkout lost every `wire:` attribute, so their values never reached the quote or the order; checked: both tests fail without the fix) |
| DEP11 | Default and mobile variants, billing form, guest, second store | works | ✅ automated: playwright/tests/dep11-variants-guest-store.spec.ts (multi-step and mobile with a guest, one-page guest, the billing form with its own parent and dependent, the Italy store) |
| DEP12 | Multiselect parent in the checkout | dependent follows | ✅ automated: playwright/tests/dep12-multiselect-parent.spec.ts (fails when the rule is broken, checked) |
| DEP13 | Required dependent shown and left empty | place order blocked with the field message | ✅ automated: playwright/tests/dep13-required-dependent.spec.ts |
| DEP14 | Account address form saved with a hidden required attribute | saves | ✅ (found and fixed: the account form did not send the hidden field and the plugin treated that as a value; `EavDataRequiredForHiddenAttribute` now handles `false`) · automated: playwright/tests/dep14-account-address-hidden-required.spec.ts |
| DEP15 | Nested rules (A → B → C) | C only when A and B match | ✅ automated: playwright/tests/dep15-nested-rules.spec.ts (fails when the B to C rule is broken, checked) |
| DEP16 | Parent option deleted later; attribute deleted | rule never matches; rules and group rows disappear | ✅ automated: playwright/tests/dep16-deleted-option-and-attribute.spec.ts |

### RAD. Radio buttons and checkboxes
| ID | Test | Expected | Status |
|---|---|---|---|
| RAD1 | Admin "Display As" per input type | only dropdown, multiple select, yes/no | ✅ |
| RAD2 | Account address form groups | saved values checked, "None" when optional, saves | ✅ automated: playwright/tests/rad2-account-address-groups.spec.ts |
| RAD3 | Rules with radio and checkbox parents | instant show/hide | ✅ |
| RAD4 | Hyvä Checkout one-page: render, saved values, instant visibility, order | works | ✅ automated (address attributes): playwright/tests/rad4-saved-address-groups-checkout.spec.ts (the saved address card shows the groups and the order carries them; a new address in the dialog starts empty and leaves the saved address alone), rad8-address-groups-checkout.spec.ts, dep10 and dep11 |
| RAD5 | Core metadata and REST output | standard input type plus rule `mageos_display` | ✅ |
| RAD6 | Required checkbox group with nothing ticked | blocked (server) | ✅ automated: playwright/tests/rad6-required-checkbox-group.spec.ts |
| RAD7 | Customer attributes as groups in registration and account edit | render and save | ✅ automated: playwright/tests/cus8-account-forms.spec.ts |
| RAD8 | Other checkout variants, hand-typed address, billing form | works | ✅ automated: playwright/tests/rad8-address-groups-checkout.spec.ts (found and fixed: a checkbox group of an address attribute sent a single true instead of the ticked options, so every order with a ticked box was refused with "has an invalid value"; its starting value is now a list) |
| RAD9 | Keyboard use and screen reader labels | usable | **manual** |

### REST. REST API
| ID | Test | Expected | Status |
|---|---|---|---|
| REST1 | Guest cart: shipping-information and payment-information with `custom_attributes` | order with the values, readable by `GET /orders/{id}` | ✅ · automated: api.php |
| REST2 | Too short value, unknown option, missing required | 400, nothing placed | ✅ · automated: api.php |
| REST3 | Dependency rule: hidden required missing, hidden value sent, shown required missing | placed, dropped, rejected | ✅ |
| REST4 | Customer token: `GET`/`PUT customers/me`, bad address value | read, update, 400 | ✅ |
| REST5 | `carts/mine` with a saved address and with a new address | order values | ✅ · automated: api.php (saved address read from `customers/me`, new address) |
| REST6 | Error message shown to an API client | readable | ☐ (core wraps it in a generic text) |
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
| SAV5 | Attribute hidden by rule or group | no notice, no block | ✅ · automated for a rule: playwright/tests/sav-saved-address-notice.spec.ts (group and disabled per website ☐) |
| SAV6 | Several missing fields, several addresses, different billing and shipping | one field named, several give a generic text, one notice each | ✅ |
| SAV7 | Default and mobile variants | notice in place | ✅ automated: playwright/tests/sav-saved-address-notice.spec.ts |
| SAV8 | Return to the checkout in the same tab | updated after a reload only | ☐ known |

### CCO. Customer attributes in the Hyvä Checkout
Use customer attributes (text, dropdown as radio buttons, date) ticked for "Hyvä Checkout" in Show in Forms. The one-page, the
multi-step (default) and the mobile checkout were tested; the sections use the containers all variants share.

| ID | Test | Expected | Status |
|---|---|---|---|
| CCO1 | Admin: position, sort order and prefill are always listed and greyed out while "Hyvä Checkout" is not ticked; saved in the rules | stored as `mageos_checkout_*` | ✅ |
| CCO2 | Sections render at their positions | at the right containers | ✅ automated: playwright/tests/cco2-positions.spec.ts (all seven positions on the one-page, multi-step and mobile checkouts, each on the right step) |
| CCO3 | Prefill on: logged-in customer sees the saved value; prefill off: empty (default value only) | as set | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO4 | Typing a value | stored on the quote, customer account unchanged | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO5 | Clearing a prefilled value | stored as empty (no row on the quote), the account keeps its value, no refill until the next page load | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO6 | Order placed | snapshot rows carry the typed values; attributes not shown in the checkout still come from the customer | ✅ · automated: playwright/tests/cco-logged-in-checkout.spec.ts |
| CCO7 | Required attribute empty | the order is blocked with "…is a required value." | ✅ · automated: playwright/tests/cco-guest-checkout.spec.ts |
| CCO8 | Dependency rule across two positions (dropdown above shipping address shows the text below) | instant show and hide; hidden value dropped on the server; hidden required value does not block; order without it | ✅ · automated: playwright/tests/cco-guest-checkout.spec.ts |
| CCO9 | Guest: fields shown, value saved on the guest quote, guest order | yes | ✅ (multi-step and mobile guest orders) · automated: playwright/tests/cco-guest-checkout.spec.ts |
| CCO10 | Guest logs in after typing | customer without an active cart: typed values kept (prefill only fills empty ones); customer with an active cart: that cart and its values win | ✅ · automated: playwright/tests/cco10-guest-logs-in.spec.ts |
| CCO22 | Required customer attribute placed at a shipping position, virtual-only cart | order not blocked | ✅ (found and fixed: shipping-position attributes are skipped for virtual carts) · automated: playwright/tests/val7-virtual-cart.spec.ts |
| CCO11 | Customer group restriction hides the section for other groups | hidden | ✅ attributes limited to Wholesale: hidden for a General customer (the order has no value for them although the account holds one); a Wholesale customer sees them |
| CCO12 | Website scope (disabled or required for one website) | follows the website | ✅ website 2 only: attribute disabled (field absent on the Italy checkout), required (radio without "None", `required` set); main website unchanged; server-side required check on the Italy site not placed |
| CCO13 | Radio and checkbox display, multiselect, date, text area at checkout | work and save | ✅ radio, checkbox group (multiselect), date, text area and text saved on the quote and the order; the date label is "YYYY-MM-DD" |
| CCO14 | Shipping method or address change refreshes nothing wrongly (values kept) | values kept | ✅ changing the shipping method and the postcode keeps every value in the page and on the quote |
| CCO15 | Order placed by REST with a required checkout attribute | not blocked | ✅ guest REST order (guest-carts, shipping-information, payment-information) with required customer attributes empty: placed, no customer value rows · automated: api.php |
| CCO16 | Attribute ticked only for "Hyvä Checkout" and the admin form (order attribute) | value on the order, never on the account | ✅ attribute in the checkout and admin forms only: value on the order, no account form, account values unchanged |
| CCO17 | Default (multi-step) and mobile variants | same results | ✅ guest orders on both with values from every step (values kept between steps) |
| CCO19 | Rule whose parent is on another step (multi-step and mobile) | the dependent field keeps the state the server rendered, no wrong hiding | ✅ (fixed: the script used to hide it because the parent was not on the page) |
| CCO20 | Rule across positions on the multi-step variant (several steps) and mobile (three steps) | show/hide and order values correct | ✅ |
| CCO21 | Rapid toggling of a parent | ends in the right state (a late response may flicker) | ✅ |
| CCO18 | Strict CSP and console on every checkout test | no errors | ✅ no console or CSP errors across the one-page, mobile and Italy checkouts of this run |

### REL. Relation warnings
| ID | Test | Expected | Status |
|---|---|---|---|
| REL1 | Dependent shown in a form where the parent is not (checkout) | warning on the edit page and after saving | ✅ |
| REL2 | Both attributes in the same forms | no warning | ✅ (unit) |
| REL3 | Several forms and several rules; address relations | one warning per rule and form | ✅ (unit) · automated: playwright/tests/rel-warnings.spec.ts (after Save and Continue the page lists each warning twice: once from the save, once from the edit page) |
| REL4 | Form assignment changed later | warning appears when the relation is opened again | ✅ automated: playwright/tests/rel-warnings.spec.ts |

### ROB. Robustness
| ID | Test | Expected | Status |
|---|---|---|---|
| ROB1 | `var/log/exception.log`, `system.log`, `debug.log` after all runs | no module errors | ✅ no module errors; the only exceptions are the intended validation failures of earlier tests |
| ROB2 | Browser console and network tab at checkout and in the admin | no errors | ✅ automated: playwright/tests/rob-console-csp.spec.ts (storefront, Italy, customer pages, admin screens; third-party Stripe/PayPal/Amazon/Google messages ignored) |
| ROB3 | 50 attributes, 5 addresses | queries stay bounded (batched loads) | ✅ automated: playwright/tests/rob3-bounded-queries.spec.ts (found and fixed: the address text read the options of every dropdown from the database once per address; now once per request) |
| ROB4 | Request count and time compared with the module disabled | small difference | ✅ measured with `dev/e2e/compare-module-off.sh` (module and companion disabled, then enabled; run on demand, not in run-all): first-party requests identical on every page (checkout 11/11, home 24/24, others 8/8); DOM ready: home +17 ms, registration +72, account edit +3, new address +99, guest checkout +878 ms (1633 → 2511; an earlier run +445 ms), which includes rendering the ~45 custom fields the module adds there. Second run (2026-11-16, now with a logged-in checkout, the admin order create page and time to first byte; developer mode, caches warm, 7 loads, first dropped): home +10 ms, registration -19, account edit -56, new address -25, guest checkout +42, logged-in checkout -19, admin order create -122; first byte on/off: guest checkout 825/748 ms, logged-in checkout 858/764 ms, others within 100 ms; first-party requests identical on all 7 pages. The differences are inside the run-to-run noise of the bench (the first run's +445 to +878 ms for the guest checkout did not repeat). **Not measured**: production mode (needs `setup:di:compile`, a baseline change), a cold cache, the cost per field (ROB3 covers the SQL statements per address) |
| ROB5 | CSP: no inline `style`/`on*` attributes or unregistered inline scripts; checkout, account and registration forms, and the admin relation list/edit page, with the browser console open | no CSP violations, relation rows still open on click | ✅ automated: playwright/tests/rob-console-csp.spec.ts (raw server HTML of checkout, registration, login, account edit, address book, new address, order list, Italy pages: no `on*`/`style` on the module's elements and no nonce-less inline script of the module; no CSP violation on those pages and the admin screens) |

## 6. Known gaps (by decision, not bugs)
Multiline, file and image input types, GraphQL, an "Update my account" button for values typed in the checkout, per-option control of dependent
options, an admin warning and back-fill tool for attributes that become required after addresses exist, and an admin grid
column or filter for the values are not part of this version.
