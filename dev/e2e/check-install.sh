#!/usr/bin/env bash
# INS4 and INS8: the generated whitelist equals the one in the module, a second setup:upgrade has nothing to do, and PHPStan is clean.
#
#   dev/e2e/check-install.sh [--skip-phpstan]
#
# (INS2 and INS3, the tables and foreign keys, are checked by `assertions.php check` in every run-all.sh run.)
# 1. `setup:db-declaration:generate-whitelist` writes etc/db_schema_whitelist.json of the module: the file is copied first, compared with
#    what was generated and put back byte for byte (also after an error or Ctrl-C).
# 2. `setup:db:status` must say that all modules are up to date: a second `setup:upgrade` would change nothing.
# 3. PHPStan at the level of phpstan.neon.dist (level 5) must report no errors.
# Run it on a test store; it is not part of run-all.sh. Exit code: 0 all fine, 1 a check failed, 2 a setup problem.
set -uo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

phpstan=1
for arg in "$@"; do
    case "$arg" in
        --skip-phpstan) phpstan=0 ;;
        *) e2e_fail "Unknown option $arg (see the header of $0)." ;;
    esac
done
e2e_load_config
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"
work="$CCA_E2E_HOME/ins4"; rm -rf "$work"; mkdir -p "$work"
module="$E2E_MODULE_DIR"
whitelist="$module/etc/db_schema_whitelist.json"
[ -f "$whitelist" ] || e2e_fail "$whitelist not found."
cp -p "$whitelist" "$work/whitelist.backup"
restore() { cp -p "$work/whitelist.backup" "$whitelist"; }
trap restore EXIT
failed=0
ok() { echo "ok    $*"; }
bad() { echo "FAIL  $*"; failed=$((failed + 1)); }

echo "== Declarative schema"
if e2e_magento setup:db-declaration:generate-whitelist --module-name=MageOS_CustomerCustomAttributes >"$work/whitelist.log" 2>&1; then
    if cmp -s "$work/whitelist.backup" "$whitelist"; then
        ok "[INS4] the generated whitelist equals etc/db_schema_whitelist.json"
    else
        bad "[INS4] the generated whitelist differs from etc/db_schema_whitelist.json: diff $work/whitelist.backup $whitelist"
        cp -p "$whitelist" "$work/whitelist.generated"
    fi
else
    bad "[INS4] generating the whitelist failed, see $work/whitelist.log"
fi
restore
if e2e_magento setup:db:status >"$work/status.log" 2>&1 && grep -qi "up to date" "$work/status.log"; then
    ok "[INS4] setup:db:status: all modules are up to date, a second setup:upgrade changes nothing"
else
    bad "[INS4] setup:db:status does not say up to date, see $work/status.log"
fi

if [ "$phpstan" -eq 1 ]; then
    echo "== PHPStan"
    if e2e_run vendor/bin/phpstan analyse -c "$module/phpstan.neon.dist" --no-progress --memory-limit=2G >"$work/phpstan.log" 2>&1; then
        ok "[INS8] PHPStan reports no errors"
    else
        bad "[INS8] PHPStan reports errors, see $work/phpstan.log"
    fi
fi

trap - EXIT
restore
cmp -s "$work/whitelist.backup" "$whitelist" || { echo "etc/db_schema_whitelist.json differs from its backup after the restore: $work/whitelist.backup" >&2; exit 2; }
[ "$failed" -eq 0 ] && echo "All checks passed." || echo "$failed check(s) failed."
[ "$failed" -eq 0 ]
