# CLAUDE.md

Module: `MageOS_CustomerCustomAttributes` (Composer `mage-os/module-customer-custom-attributes`), custom customer and
customer address attributes for Magento / Mage-OS, Hyvä Theme and Hyvä Checkout first. The Hyvä Checkout form modifier is in the
companion package `mage-os/module-customer-custom-attributes-hyva-checkout`.

## Read first
- `README.md`: features and design in short. `docs/guide/`: the user guide (keep it in step with behaviour changes).
- `docs/ARCHITECTURE.md`: code map, data flow, core and Hyvä behaviours the code depends on, decisions, how to add things.
- `docs/TESTING.md`: how to run the checks, test data, SQL, the full test list with verified and open cases.
- `docs/STATUS.md`: what is built, verified and open. Keep it, TESTING.md and CHANGELOG.md updated with every change.
- `AGENTS.md`: the same rules for other AI tools.

## Conventions
- PHP 8.3+, `declare(strict_types=1)`, constructor promotion with `readonly`, namespace `MageOS\CustomerCustomAttributes`.
- Author / credits: Yuriy Boyko <info@yuriy-boyko.com>. Licence OSL-3.0. Keep the file-header comment.
- No runtime schema changes: every table is declared in `etc/db_schema.xml`. Regenerate the whitelist after schema changes
  (`bin/magento setup:db-declaration:generate-whitelist --module-name=MageOS_CustomerCustomAttributes`).
- Order tables keep a snapshot and have no foreign key to `eav_attribute`: an order must render from its own rows only.
- Logic goes into pure PHP classes in `Service/` (no Magento imports) with unit tests in `Test/Unit/`; Magento adapters stay thin.
- Never import, extend or implement `Hyva\Checkout\*` classes in this module's PHP; they belong to the companion module. A plugin on
  a Hyvä class in `etc/di.xml` is fine if the plugin class does not type-hint Hyvä classes (see ARCHITECTURE.md).
- Never leave `vendor/` or `node_modules` inside the module folder (`setup:di:compile` scans it).
- Keep the repository free of local environment details (hosts, credentials, order numbers, session logs).
- Automated browser, REST and database tests are in `dev/e2e/`: run `dev/e2e/run-all.sh` and read its short summary (`last-run.md`). Read `dev/e2e/README.md` before writing a test. If a script prints `ACTION NEEDED`, stop and give the block to the user: it needs root rights or a manual step. Never work around it.

## Checks
```bash
dev/check.sh   # PHP lint, XML, JSON, PHPUnit
```
In a Magento install also `bin/magento setup:di:compile` and PHPStan (`phpstan.neon.dist`), see `docs/TESTING.md`.
