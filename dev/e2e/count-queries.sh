#!/usr/bin/env bash
# ROB6: counts the SQL statements the module causes on the pages it changes, and fails when a page goes over its limit.
#
#   dev/e2e/count-queries.sh
#
# Seeds the fixtures, switches Magento's query log on with call stacks (`bin/magento dev:query-log:enable`), runs
# playwright/tests/rob6-query-count.spec.ts and switches the log off again. A statement counts as the module's when module code is in
# its call stack, so the numbers are exact and do not depend on the speed of the machine (unlike compare-module-off.sh).
# It changes app/etc/env.php while it runs (the query log settings): the file is copied first and put back byte for byte at the end,
# also after an error or Ctrl-C; the file is never printed. An existing var/debug/db.log is kept aside and put back.
# In production mode PHP may not notice the changed env.php (opcache without timestamp checks): run it in developer mode.
# Run it on a test store only, never in parallel with other tests; it is not part of run-all.sh. The limits are in the spec.
# Exit code: 0 within the limits, 1 over a limit, 2 a setup problem.
set -uo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[ $# -eq 0 ] || e2e_fail "count-queries.sh takes no options (see the header of $0)."
e2e_load_config
if ! command -v node >/dev/null && [ -f "$HOME/.nvm/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$HOME/.nvm/nvm.sh"; (cd "$e2e_here/playwright" && nvm use >/dev/null 2>&1) || true
fi
command -v node >/dev/null || e2e_fail "Node.js is not installed (nvm install 24)."
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"
work="$CCA_E2E_HOME/rob6"; mkdir -p "$work"; rm -f "$work"/*.json "$work"/*.log
envfile="$E2E_MAGENTO_ROOT/app/etc/env.php"
dblog="$E2E_MAGENTO_ROOT/var/debug/db.log"
[ -f "$envfile" ] || e2e_fail "$envfile not found."
cp -p "$envfile" "$work/env.php.backup"
had_log=0
had_dir=0; [ -d "$(dirname "$dblog")" ] && had_dir=1
if [ -f "$dblog" ]; then cp -p "$dblog" "$work/db.log.backup"; had_log=1; fi

restore() {
    cp -p "$work/env.php.backup" "$envfile"
    if [ "$had_log" -eq 1 ]; then cp -p "$work/db.log.backup" "$dblog"; else rm -f "$dblog"; fi
    if [ "$had_dir" -eq 0 ]; then rmdir "$(dirname "$dblog")" 2>/dev/null || true; fi
    rm -f "$work/env.php.backup" "$work/db.log.backup"
}
trap restore EXIT

echo "seeding the fixtures ..."
"$e2e_here/run-all.sh" --only playwright --keep -- tests/rob6-query-count.spec.ts >"$work/seed.log" 2>&1 || true
grep -q 'fixtures seeded' "$work/seed.log" || { echo "Seeding failed, see $work/seed.log" >&2; exit 2; }

echo "switching the query log on ..."
e2e_magento dev:query-log:enable --include-all-queries=true --include-call-stack=true >"$work/enable.log" 2>&1 \
    || { cat "$work/enable.log" >&2; exit 2; }

echo "counting ..."
E2E_QUERY_LOG=1 E2E_QUERY_OUT="$work/counts.json" E2E_FIXTURE_CMD="$e2e_here/fixture.sh" E2E_ASSERT_CMD="$e2e_here/assert.sh" \
    "$e2e_here/run-playwright.sh" tests/rob6-query-count.spec.ts >"$work/count.log" 2>&1
status=$?

restore
trap - EXIT
E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php purge >/dev/null 2>&1 || true
E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php teardown >/dev/null 2>&1 || true
e2e_magento cache:flush >/dev/null 2>&1 || true

[ -f "$work/counts.json" ] || { echo "The measurement failed, see $work/count.log" >&2; exit 2; }
node -e 'for (const [page, c] of Object.entries(JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")))) console.log(`  ${page}: ${c.module} of ${c.all} statements from the module`)' "$work/counts.json"
if [ "$status" -ne 0 ]; then
    echo "ROB6: over a limit; the statements of each page are in $work/counts.json and $work/count.log" >&2
    exit 1
fi
echo "ROB6: every page within its limit (numbers in $work/counts.json); app/etc/env.php restored"
