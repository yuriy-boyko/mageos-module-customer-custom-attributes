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
| `fillCheckout(page, magewire, overrides?)`, `chooseShippingAndPayment`, `placeOrder` | fill the guest shipping address with valid values (and the always-required fixtures), pick shipping and Check / Money order, click Place Order |
| `placedOrderSnapshot(page)` | the order number from the success page and the module's snapshot of that order (customer attributes, billing, shipping by code), read from the database |
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
| Customer, in the checkout | text, required text, dropdown (radio), date, multiple select (checkboxes), text area, yes/no (radio), a text field that depends on the dropdown, one position per checkout position |
| Customer, other | order-only (checkout and admin form only), account-only (no checkout) |
| Address | text, required text, a text field that depends on a multiple select (Red or Blue), a required field that depends on a dropdown (Show), a three-level nested chain (root On, middle Yes, leaf), a dropdown/dependent pair for deleting an option and one with group restrictions for deleting an attribute, text with length rules, dropdown (radio), multiple select, date with a maximum, text area, yes/no, a text field that depends on the dropdown |
| Relations | one for customer and one for address attributes (dropdown option shows the dependent field) |
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
| `dep12-multiselect-parent.spec.ts` | DEP12: a dependent address field follows a multiple select parent (shows for any matching option, hides and clears otherwise) |
| `dep13-required-dependent.spec.ts` | DEP13: a shown required dependent left empty blocks the order (field marked, message names it), a hidden one does not; the order is placed once it is filled |
| `dep14-account-address-hidden-required.spec.ts` | DEP14: the account address form saves with a required attribute hidden by a rule (it is disabled and not sent), and blocks when it is shown and empty |
| `dep15-nested-rules.spec.ts` | DEP15: nested rules A, B, C in the checkout (C only when A and B match, values cleared, cascade) and the order snapshot of a complete and a broken chain |
| `dep16-deleted-option-and-attribute.spec.ts` | DEP16: after a parent option is deleted the rule never matches; after an attribute is deleted its rules and group restrictions are gone and the checkout still works |

## Adding a fixture

Add an entry to `fixtures.json` (keep the `e2e_` prefix) and describe it here. Keys: `entity` (`customer` or `address`), `code`,
`label`, `input`, `required`, `display`, `options`, `rules`, `forms` (replaces the default form list), `checkout`
(`position`, `sort`, `prefill`), `groups` (customer group ids), `websites` (rows by website id).
