# End-to-end test fixtures

Scripts for running the manual and automated test lists in [`docs/TESTING.md`](../../docs/TESTING.md) on a Magento / Mage-OS
store that has the module (and, for the checkout tests, Hyvä Checkout and the companion package) installed. They are for a test
store, never for production.

## Quick start: run everything

```bash
cp dev/e2e/local.env.example dev/e2e/local.env     # once; fill in the store address, the admin account and how to run PHP there
dev/e2e/run-all.sh                                  # preflight, fixtures, REST suite, browser suite, database checks, clean up
```

It prints a short summary (a few lines when everything passes; the name and detail of each failing test otherwise) and exits with
0 (all passed), 1 (a test failed), 2 (a setup problem) or 3 (the user has to do something; the block `ACTION NEEDED` says what).

```
dev/e2e/run-all.sh --only api,assert                # only some suites (api, assert, playwright)
dev/e2e/run-all.sh -- --grep @DEP12                 # everything after -- goes to Playwright (a file, --grep, --headed ...)
dev/e2e/run-all.sh --update-docs                    # write the result of every automated row into docs/TESTING.md
dev/e2e/run-all.sh --keep                           # leave the fixtures and test orders in the store to look at a failure
```

What a run does, in order, and undoes at the end (also after a failure):

1. **Preflight** (`preflight.php`): foreign required custom attributes would block the tests, so they are made optional for the run
   and restored afterwards; the checkout variant, the stock of the test product and leftovers are reported.
2. **Clean slate and fixtures**: leftover test orders, quotes and fixtures are removed, then `fixtures.php seed` and a cache flush.
3. **Suites**: the REST suite (`api.php`), the browser tests (Playwright) and the database checks (`assertions.php`). The Hyvä
   Checkout variant is switched by the tests that need another one (`set-variant.sh`) and set back at the end.
4. **Summary** (`summarize.mjs`): counts per suite, failures with detail and the path of the screenshot and trace, and the result per
   row of `docs/TESTING.md`. The raw output of every step stays in `~/.cache/mageos-cca-e2e/run/`; the summary is also in
   `last-run.md` and `last-run.json` there. To save effort, read `last-run.md` first.

`local.env` holds passwords and is ignored by git. `lib.sh` reads it and decides how PHP and `bin/magento` are run on the store's
machine (`E2E_EXEC`, for example `warden env exec -T php-fpm`, or empty for a local install).

### Rows of docs/TESTING.md

Every test names the rows it proves: a `[REST1]` in the name of an API or database check, a tag `{ tag: ['@DEP12'] }` on a Playwright
test. A trailing `~` (`[VAL1~]`, `@VAL1~`) means the test proves only part of the row. With `--update-docs` a row whose full tests all
pass becomes `✅ automated: <file>` (an earlier note stays), a failing one `❌ automated failing: <file> · was: <old status>`; partial
tests never change a row. Running it again changes nothing.

### Writing a browser test

Import `test`, `expect` and the helpers from `./helpers` (not from `@playwright/test`), so the test also fails on a CSP violation, an
uncaught script error or a failed Magewire call:

| Helper | Use |
|---|---|
| `openCheckoutAsGuest(page)` | add the test product to a fresh guest cart and open the checkout |
| `field(page, code)` | the input of an attribute (`e2e_a_text`) in the checkout |
| `requireFixtures(page, [codes])` | fail with a clear message when the fixtures are not seeded |
| `magewire.idle()` (fixture) | wait until no Magewire request is in flight |
| `customerPage`, `adminPage` (fixtures) | a page already signed in as the fixture customer / an admin (the test is skipped without credentials) |
| `adminOpen(page, 'mageos_cca/relation')` | open an admin page through its menu link (admin URLs carry a secret key) |
| `useVariant('mobile')` | run the tests of this `describe` on another Hyvä Checkout variant (`onepage`, `default`, `mobile`) |
| `sections(page)`, `walkSteps(page, magewire)` | the visible customer attribute sections (position -> field codes) and the sections of every step of a step variant |
| `pickRadio`, `tickCheckbox`, `optionOf`, `sendMagewireValue` | choose options of a customer attribute section by label; send a value to a section as a hand-made request would |
| `accountValues(email)`, `quoteValues(email \| '@latest')`, `setAccountValues`, `dropQuotes`, `loginAs`, `customerEmail()` | read the account or the quote from the database, set account values, give the customer no active cart, sign in. A guest quote has no e-mail until the order is placed: use `'@latest'` |
| `customerPage` / `customerMagewire` | the page of the signed-in fixture customer and its Magewire idle wait |
| `fillCheckout(page, magewire, overrides?)`, `chooseShippingAndPayment`, `placeOrder` | fill the guest shipping address with valid values (and the always-required fixtures), pick shipping and Check / Money order, click Place Order |
| `placedOrderSnapshot(page)` | the order number from the success page and the module's snapshot of that order (customer attributes, billing, shipping by code), read from the database |
| `openNewAttribute`, `openAttribute`, `attributeTab`, `fillAttributeMain`, `addOption`, `optionRows`, `saveAttribute`, `attributeRow`, `deleteCreated` | the admin attribute edit page: open, fill, save, read the stored attribute back, delete what a test created |
| `openGrid`, `gridFilter`, `gridColumn`, `gridMassAction`, `createAttributes`, `openCustomerEdit`, `customerTab` | the admin attribute grid (filter, sort, mass actions) and the customer edit page |
| `setAclAdmin`, `deleteAclAdmin`, `signInAclAdmin`, `aclMenu`, `expectForbidden` | a restricted test admin (own role) and the checks of its menu and 403 answers |
| `benchCommand('E2E_FIXTURE_CMD' \| 'E2E_ASSERT_CMD', args)` | run `fixtures.php` or `assertions.php` on the store (the test is skipped without `run-all.sh`) |
| `uniqueEmail()` | an e-mail no earlier run used; `fixtures.php purge` removes the orders and quotes of such e-mails |

Use `[name="code"]` locators and `expect(...).toBeVisible()` style assertions (they wait); avoid fixed pauses. Prove that a new test can
fail: break the thing it checks (for example a rule) and see it go red.

## fixtures.php

Creates and removes the test data, so every run starts from a known state and leaves nothing behind.

```bash
# from the Magento root (inside the PHP container if you use one); run-all.sh does this for you

E2E_CUSTOMER_PASSWORD='<a password for the test customer>' \
  php -d xdebug.mode=off -d opcache.enable_cli=0 <module path>/dev/e2e/fixtures.php seed
php ... fixtures.php status
php ... fixtures.php teardown
php ... fixtures.php delete-option <code> <label>   # delete an option of a fixture attribute, like the admin (used by DEP16)
php ... fixtures.php delete-attribute <code>         # delete a fixture attribute, like the admin; prints the rules and group rows left
php ... fixtures.php create-attributes <customer|address> <code>...   # plain optional text attributes (e2e_ codes) for admin tests; delete with delete-created
php ... fixtures.php set-admin <e2e_acl...> <resource,...>   # a test admin whose role allows only these ACL resources (delete-admin removes it; purge removes leftovers)
php ... fixtures.php secret-key <route/controller/action> <form key>   # the admin URL key of an action for a session
php ... fixtures.php set-address <email> <code=value>...   # one saved address (default billing and shipping) with custom values; `--delete` removes the saved addresses
php ... fixtures.php delete-created <code>           # delete an attribute a test created through the admin (code starts with e2e_), no error when absent
php ... fixtures.php set-attribute <code> required=0|1 forms=a,b   # change whether a fixture attribute is required and its forms (VAL6)
php ... fixtures.php set-customer <email> <code=value>...  # set account values of the fixture customer (select: option label, several: a|b)
php ... fixtures.php drop-quotes <email>             # give a customer no active cart
php ... fixtures.php purge        # delete the orders and quotes of test e-mails (containing e2e, ending @example.com)
bin/magento cache:flush
```

- `seed` creates what is missing and leaves what exists (running it twice is safe). The test customer is created first, because a
  required customer attribute would block creating an account without a value.
- `purge` deletes the orders and quotes whose e-mail matches `%e2e%@example.com` (every test e-mail follows that pattern).
- `teardown` removes the relations, the attributes (and so their values on customers, quotes and form assignments) and the test
  customer. Test orders and quotes are removed by `purge`.
- Everything is defined in [`fixtures.json`](fixtures.json): attribute codes all start with `e2e_`, so teardown touches nothing else.
- Attributes are created through the module's own form processor, so they get the same rules and validation as in the admin.
- The password comes from the environment and is never stored. Do not commit it.

### What is seeded

| Kind | Attributes |
|---|---|
| Customer, in the checkout | text, required text, dropdown (radio), date, multiple select (checkboxes), text area, yes/no (radio), a text field and a required text field that depend on the dropdown (Gold, Silver), one at each of the seven checkout positions |
| Customer, other | order-only (checkout and admin form only), account-only (no checkout) |
| Address | text, required text, a text field that depends on a multiple select (Red or Blue), a required field that depends on a dropdown (Show), a three-level nested chain (root On, middle Yes, leaf), a dropdown/dependent pair for deleting an option and one with group restrictions for deleting an attribute, text with length rules, dropdown (radio), multiple select, date with a maximum, text area, yes/no, a text field that depends on the dropdown |
| Relations | one for customer and one for address attributes (dropdown option shows the dependent field) |
| Customer, account forms | `e2e_c_areq` (required, only on the account forms), `e2e_c_rmulti` (required checkbox group, shown for Silver) |
| Address, forms | `e2e_a_noform` (in no storefront form), `e2e_a_warn` (optional; VAL6 makes it required and address-book-only for the length of a test and puts it back) |
| Product | `e2e-virtual-product` (virtual, in stock) for carts without shipping |
| Customer | `e2e.customer@example.com`, group General |

Relation rules are given with the parent's option label; `fixtures.php` stores the option id.

Settings that need a matrix (customer group restrictions, website scope, other checkout variants) are applied by the tests that
need them, through the admin or the `groups` and `websites` keys of an attribute in `fixtures.json`, and removed again with `teardown`.

## assertions.php

Read-only database assertions; it never changes data.

```bash
php dev/e2e/assertions.php check                   # invariants that must always hold, exit code 1 when one fails
php dev/e2e/assertions.php order <increment_id>    # the order's snapshot rows (customer attributes and both addresses) as JSON
php dev/e2e/assertions.php customer <email>        # all custom customer and address attribute values stored on the account, as JSON
php dev/e2e/assertions.php quote <email>           # the module's values on the customer's latest quote, as JSON
php dev/e2e/assertions.php attribute <customer|address> <code>   # how an attribute is stored (labels, options, default, forms, rules), as JSON
```

`check` covers: no orphan rows (quote, quote address, order and order address values, relation rules, group restrictions),
relation rules that point to themselves, use a parent that is not a dropdown, multiple select or yes/no, or mix customer and
address attributes, system attributes in quote or snapshot rows, a shipping address snapshot on a virtual order, empty or unlabelled
snapshot rows, duplicate rows, and checkout settings on address attributes. Run it after every scenario and at the end of a run.

To prove that a checkout did not change the customer's account, save `customer <email>` before and compare after:

```bash
php dev/e2e/assertions.php customer a@example.com > before.json
# ... place the order in the checkout ...
php dev/e2e/assertions.php customer a@example.com | diff before.json -
```

`order` and `quote` give a test the exact values to compare with what the scenario typed.

## api.php

REST API suite: places orders through the REST API of a running store (guest carts and a logged-in customer) and checks the
module's data. It needs the fixtures (`fixtures.php seed`) and no Magento bootstrap, only PHP with curl.

```bash
E2E_BASE_URL=https://shop.example E2E_ADMIN_USER=<admin> E2E_ADMIN_PASSWORD=<password> \
E2E_CUSTOMER_PASSWORD=<test customer password> php dev/e2e/api.php
```

Optional: `E2E_CUSTOMER_EMAIL`, `E2E_SKU` (a simple in-stock product, default `24-MB04`), `E2E_INSECURE=1` (self-signed certificate),
`E2E_HOST_HEADER` (reach the store by another address, for example from inside a container). Credentials come from the
environment and are never printed. It prints `ok` or `FAIL` per case and exits 1 when one fails.

Cases (31): orders without a token are refused; a missing required address attribute, a too short value, an unknown option id and a
date after the maximum are refused; a guest order with values on both addresses carries them with the right labels (dropdown, multiple
select, date without time, yes/no) and is returned by the order endpoints; an order is placed although a required checkout customer
attribute is empty; a dependent value is kept when its parent option matches and dropped when it does not; a logged-in customer's
order carries the account-only attribute but not the checkout-only ones.

The orders stay in the store until `fixtures.php purge` removes them (e-mail `api.e2e@example.com`). The store must not have other required address
attributes, because the cases send values for the fixture attributes only. A required custom customer attribute also has to be filled when
a customer is saved through the admin or the API, because the admin customer form always includes every custom customer attribute.

## Playwright (browser tests)

Browser tests of the Hyvä Checkout, in [`playwright/tests`](playwright/tests). They need Hyvä Checkout and the companion package on the
test store, the fixtures (`fixtures.php seed`, then `bin/magento cache:flush`) and Node 24 (`nvm use` reads `playwright/.nvmrc`).

```bash
E2E_BASE_URL=https://shop.example dev/e2e/run-playwright.sh                       # all tests
E2E_BASE_URL=https://shop.example dev/e2e/run-playwright.sh tests/dep12-multiselect-parent.spec.ts
E2E_HEADED=1 ...                                                                  # show the browser
```

- Optional: `E2E_PRODUCT_URL` (path of a simple in-stock product page, default `/strive-shoulder-pack.html`).
- The tests run from the machine that can open the store in a browser (the host, not the PHP container).
- `run-playwright.sh` copies the tests into `$CCA_E2E_HOME` (default `~/.cache/mageos-cca-e2e`), installs Playwright and Chromium there and runs
  them. Nothing is installed into the module, because `setup:di:compile` scans every module folder. Traces and screenshots of failed
  tests are written to `$CCA_E2E_HOME/test-results`.
- **Preflight.** Before running, `run-playwright.sh` checks Node (22 or newer), that the store answers from this machine, the npm and
  browser downloads, and that the browser's system libraries exist. When something is missing that only the user can fix (root rights,
  a hosts entry, a missing tool, the network) it stops with a block starting with `ACTION NEEDED (by the user)`, names the exact
  command and exits with code 3. For missing libraries that is `sudo env "PATH=$PATH" npx playwright install-deps chromium`; without
  root, `apt-get download <package>` and `dpkg -x` into `$CCA_E2E_HOME/libs` also works (the script adds that folder to the library path).
- **For AI agents:** when the script prints `ACTION NEEDED`, stop, show the block to the user and wait. Do not try to work around it
  (no `sudo`, no editing system files). A test that fails with "The checkout has no field ..." means the fixtures are not seeded or
  the cache was not flushed: run the fixtures script and `bin/magento cache:flush`.
- The tests assume the checkout variant configured on the store (one-page unless a test says otherwise) and a guest session.
- Every test also fails when the page logs a Content Security Policy violation or an uncaught script error, or a Magewire request fails
  (see `tests/helpers.ts`).

| Test | Covers |
|---|---|
| `smoke-variants.spec.ts` | the checkout of each variant (one-page, multi-step, mobile) opens and shows the module's fields; proves the variant switch |
| `cco2-positions.spec.ts` | CCO2: the seven customer attribute positions on the right step of each variant (one-page: all on the page; multi-step: shipping positions on the first step, payment positions on the payment step, summary on both; mobile: three steps) |
| `cco-guest-checkout.spec.ts` | CCO9 guest values at every position saved on the quote and on the order (labels for dropdown, checkbox, date, yes/no); CCO7 a required customer attribute empty blocks the order; CCO8 a rule across two positions (instant show/hide, a hand-sent value for a hidden attribute is dropped by the server, a hidden required attribute does not block) |
| `cco-logged-in-checkout.spec.ts` | CCO3 prefill on/off, CCO4 typing stores on the quote and leaves the account unchanged, CCO5 clearing a prefilled value, CCO6 the order carries the typed values and the account-only values |
| `cco10-guest-logs-in.spec.ts` | CCO10 a guest signs in during the checkout: without an active cart the typed values are kept and empty fields are prefilled; with an active cart that cart's values win |
| `val7-virtual-cart.spec.ts` | VAL7 / CCO22 / CUS10: a guest buys only a virtual product: no shipping step, the required attribute at a shipping position does not block, the order has billing values only and no customer rows |
| `cus8-account-forms.spec.ts` | CUS8 a required customer attribute left empty is not saved on the account information form; RAD7 radio buttons and checkboxes render with saved values and save; CUS12 (part) dependency rules in the account form |
| `val6-required-address-book-only.spec.ts` | VAL6: the admin warns when an address attribute is required and only in the address book form; such an attribute blocks the guest checkout until it is added to the Hyvä Checkout address forms |
| `rad6-required-checkbox-group.spec.ts` | RAD6: a required checkbox group with nothing ticked blocks the order |
| `reg5-attribute-without-form.spec.ts` | REG5: an attribute in no storefront form is not shown in the checkout or the account address form (the REST suite proves its submitted values still work) |
| `rad2-account-address-groups.spec.ts` | RAD2: radio and checkbox groups in the account address form: save, saved values shown as checked, "None" clears |
| `sav-saved-address-notice.spec.ts` | SAV1-SAV5, SAV7: a saved address lacking an attribute that became required: the notice with the edit link, the refused order and its message, the fix, the notice following the selected address, a rule-hidden attribute giving none, the multi-step and mobile checkout |
| `chk-steps-tabs-locale.spec.ts` | CHK14 a date in the Italy store; CHK16 back from payment to shipping in the multi-step checkout; CHK17 two tabs edit the same quote |
| `reg4-system-attributes.spec.ts` | REG4: the module picks up the user defined attributes only (no browser) |
| `val4-edit-order-address.spec.ts` | VAL4: Edit Order Address refuses an invalid value (browser and server) and saves a valid one |
| `aoc9-italy-store-view.spec.ts` | AOC9: an admin order for the Italy store view, date `5/6/2026` read as 6 May |
| `rad4-saved-address-groups-checkout.spec.ts` | RAD4: a customer with a saved address that has a checkbox group and radio buttons: the address card shows them, the order carries them, the "New Address" dialog starts empty and the saved address is left alone |
| `rad8-address-groups-checkout.spec.ts` | RAD8: radio and checkbox groups of address attributes in the checkout: customer and guest, multi-step and mobile, the billing form, nothing ticked |
| `val5-rejection-message-checkout.spec.ts` | VAL5: a value the server rejects gives a readable message at the top and at the field, the typed values are kept, the corrected order is placed |
| `dep3-relation-validation.spec.ts` | DEP3: the relations screen refuses a rule that cannot work (empty name, no rule, unknown option, text parent, self rule, duplicate) with the rule number; a valid relation is saved and deleted |
| `dep7-customer-groups-shown-again.spec.ts` | DEP7: the customer groups of an attribute are saved, shown again, changed and cleared |
| `cus9-logged-in-order-rows.spec.ts` | CUS9: a logged-in Hyvä Checkout order has one row per value with labels, the admin order view lists them, the account is unchanged |
| `dep10-new-address-parent-changes.spec.ts` | DEP10: a logged-in customer types a new address and changes the radio parent several times; the dependent follows, the order has the parent and only the visible dependent |
| `dep11-variants-guest-store.spec.ts` | DEP11: the same rule as a guest in the multi-step, mobile and one-page checkout, in the billing form (own parent and dependent) and in the Italy store |
| `dep12-multiselect-parent.spec.ts` | DEP12: a dependent address field follows a multiple select parent (shows for any matching option, hides and clears otherwise) |
| `dep13-required-dependent.spec.ts` | DEP13: a shown required dependent left empty blocks the order (field marked, message names it), a hidden one does not; the order is placed once it is filled |
| `dep14-account-address-hidden-required.spec.ts` | DEP14: the account address form saves with a required attribute hidden by a rule (it is disabled and not sent), and blocks when it is shown and empty |
| `dep15-nested-rules.spec.ts` | DEP15: nested rules A, B, C in the checkout (C only when A and B match, values cleared, cascade) and the order snapshot of a complete and a broken chain |
| `dep16-deleted-option-and-attribute.spec.ts` | DEP16: after a parent option is deleted the rule never matches; after an attribute is deleted its rules and group restrictions are gone and the checkout still works |

## Adding a fixture

Add an entry to `fixtures.json` (keep the `e2e_` prefix) and describe it here. Keys: `entity` (`customer` or `address`), `code`,
`label`, `input`, `required`, `display`, `options`, `rules`, `forms` (replaces the default form list), `checkout`
(`position`, `sort`, `prefill`), `groups` (customer group ids), `websites` (rows by website id).

Admin order helpers (`helpers.ts`): `startAdminOrder`, `addAdminOrderProduct`, `fillOrderAddress`, `fillOrderValues`, `chooseAdminShipping`, `chooseAdminPayment`, `submitAdminOrder`, `completeAdminOrder(page, billingValues)`, `orderField(page, area, code)`, `openOrderAddressEdit`, `orderViewAddresses`, `snapshotOf(incrementId)`, `setSavedAddress`, `deleteSavedAddresses`, `chosenLabels`, `adminOrderIdle`.

More bench commands: `fixtures.php quote-address-saves` (SNAP8: four saves of a throw-away quote address, reports the writes of the value table), `fixtures.php create-attributes <entity> --forms=a,b <code>...` (created attributes in chosen forms; the address text form `mageos_address_text` decides what the order view shows), `assertions.php queries` (ORD7: SQL statements of loading order and order address collections of 2, 20 and 100 rows).

Website commands: `fixtures.php set-website <code> <website id> enabled=|required=|default=` (`-` = use the default) and `fixtures.php create-customer <email> <website id>` (WEB specs; the Italy storefront is `ITA` in `helpers.ts`, an account lookup tries every website, `assertions.php attribute` also returns the website overrides and `assertions.php order` the order id).

More: `fixtures.php bulk-attributes|bulk-addresses|bulk-clean` and `assertions.php perf` (ROB3); `dev/e2e/compare-module-off.sh` (ROB4: disables the module and its companion, measures seven pages (home, registration, account edit, new address, guest and logged-in checkout, admin order create) with time to DOM ready, time to first byte and first-party request count, restores app/etc/config.php byte for byte; on demand only, not part of run-all.sh); `api.php` also covers REST5/REST7 and calls `fixtures.php set-website` for the Italy cases.

`dev/e2e/check-deploy.sh` (INS11): Hyvä config generation, Tailwind build and static content deploy with the module installed; it backs up and restores `app/etc/hyva-themes.json`, the theme's `web/css/styles.css` and `web/tailwind/generated`, and removes the deployed `pub/static/frontend` and `adminhtml` (a developer-mode store rebuilds them on demand); on demand only, not part of run-all.sh. `check-tailwind-classes.mjs` is its helper. `create-attributes` also takes `--position=<checkout position>` for a customer attribute in the checkout.
