# AGENTS.md

Instructions for AI coding tools and contributors working on `MageOS_CustomerCustomAttributes`. [CLAUDE.md](CLAUDE.md) holds the
same rules for Claude Code.

Read in this order: [README.md](README.md) (features), [docs/guide/](docs/guide/README.md) (user guide, keep it in step with behaviour), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (code map, data flow, the
Magento and Hyvä behaviours the code depends on), [docs/TESTING.md](docs/TESTING.md) (how to test, full test list),
[docs/STATUS.md](docs/STATUS.md) (what exists and what is open).

## Rules
- PHP 8.3+, `declare(strict_types=1)`, constructor promotion with `readonly`, namespace `MageOS\CustomerCustomAttributes`, keep the
  licence header comment of the existing files.
- Logic goes into pure classes in `Service/` with a unit test in `Test/Unit/`; Magento adapters stay thin.
- Schema only through `etc/db_schema.xml`; regenerate `etc/db_schema_whitelist.json` after every change, then remove the two order
  snapshot tables from it again (`SchemaWhitelistTest`, see ARCHITECTURE.md). Order tables are snapshots with no foreign key to
  `eav_attribute`.
- Never import, extend or implement `Hyva\Checkout\*` classes in this module's PHP (they belong to the companion module
  `mage-os/module-customer-custom-attributes-hyva-checkout`), or `setup:di:compile` breaks on stores without Hyvä Checkout. A plugin
  on a Hyvä class declared in `etc/di.xml` is fine, as long as the plugin class does not type-hint Hyvä classes (see ARCHITECTURE.md).
- The companion package has the same version as this module: bump and tag both together, and keep both changelogs in step.
- Never copy code from commercial modules; never commit Hyvä Checkout sources, `vendor/`, `node_modules/`, credentials or anything
  from a Magento install. Never leave `vendor/` or `node_modules/` inside the module folder (`setup:di:compile` scans it).
- New user-visible text: `__()` and regenerate `i18n/en_US.csv`. Changes to behaviour: update README, `docs/` and, from the second version on, `CHANGELOG.md` (the initial version 0.1.0 has no entries).
- Keep this repository free of local environment details (hosts, credentials, order numbers, session logs).
- Automated browser, REST and database tests are in `dev/e2e/`: run `dev/e2e/run-all.sh` and read its short summary (`last-run.md`). Read `dev/e2e/README.md` before writing a test. If a script prints `ACTION NEEDED`, stop and give the block to the user: it needs root rights or a manual step. Never work around it.

## Checks before every commit
```bash
dev/check.sh                    # PHP lint, XML, JSON, PHPUnit (no Magento needed)
```
Inside a Magento install also run `bin/magento setup:di:compile` and
`vendor/bin/phpstan analyse -c <module>/phpstan.neon.dist`, and exercise the change in the browser (see docs/TESTING.md).
