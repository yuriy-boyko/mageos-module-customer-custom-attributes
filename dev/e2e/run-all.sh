#!/usr/bin/env bash
# Runs the whole end-to-end suite and prints a short summary:
#
#   dev/e2e/run-all.sh [--quick] [--variants] [--only api,assert,playwright] [--keep] [--no-relax] [--update-docs] [-- <playwright arguments>]
#
#   --quick         the browser tests tagged @quick only (the main paths, about a tenth of the suite), with the REST suite and the database
#                   checks; for everyday changes. Run the full suite before a release.
#   --variants      also run the tests of the multi-step and mobile checkouts (tag @variants; left out by default, the one-page checkout is
#                   the main target). Only them: `--variants -- --grep @variants`
#   --only          run only some suites (api = REST, assert = database checks, playwright = browser)
#   --keep          do not remove the fixtures and test orders afterwards (to look at the store after a failure)
#   --no-relax      do not make foreign required attributes optional (the preflight then stops when there are some)
#   --update-docs   write the result of every automated row into docs/TESTING.md
#   -- ...          everything after `--` goes to Playwright, e.g. `-- tests/dep12-multiselect-parent.spec.ts` or `-- --grep @DEP12`
#
# Steps: preflight (baseline check) -> clean slate -> seed fixtures -> cache flush -> suites -> database checks -> clean up and restore
# (always, also after a failure). Settings are read from dev/e2e/local.env (see local.env.example). Raw output of every step is kept in
# $CCA_E2E_HOME/run (default ~/.cache/mageos-cca-e2e/run); the summary is also in $CCA_E2E_HOME/last-run.md and last-run.json.
#
# Exit code: 0 all passed, 1 a test failed, 2 a setup problem, 3 the user has to do something (the block "ACTION NEEDED" says what;
# an AI agent must show it to the user and stop).
set -uo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

only="api,assert,playwright"
keep=0
relax=1
update_docs=0
quick=0
variants=0
pw_args=()
while [ $# -gt 0 ]; do
    case "$1" in
        --only) only="${2:-}"; shift 2 ;;
        --keep) keep=1; shift ;;
        --no-relax) relax=0; shift ;;
        --update-docs) update_docs=1; shift ;;
        --quick) quick=1; shift ;;
        --variants) variants=1; shift ;;
        --) shift; pw_args=("$@"); break ;;
        *) e2e_fail "Unknown option $1 (see the header of $0)." ;;
    esac
done
wants() { case ",$only," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }

e2e_load_config
# Node is needed for the summary and the browser tests: use nvm when it is not on the PATH yet.
if ! command -v node >/dev/null && [ -f "$HOME/.nvm/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$HOME/.nvm/nvm.sh"
    (cd "$e2e_here/playwright" && nvm use >/dev/null 2>&1) || true
fi
command -v node >/dev/null || e2e_fail "Node.js is not installed (nvm install 24)."
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"
run="$CCA_E2E_HOME/run"
rm -rf "$run"; mkdir -p "$run"
started="$(date '+%Y-%m-%d %H:%M')"; t0=$SECONDS
step() { printf '%s\n' "$*"; }
# seconds per phase, for the summary: `mark <name>` closes the phase that started at the previous mark
last_mark=$SECONDS
mark() { printf '%s %s\n' "$1" "$((SECONDS - last_mark))" >> "$run/phases.txt"; last_mark=$SECONDS; }

# the fixture customer's password: generated once and kept in local.env
if [ -z "${E2E_CUSTOMER_PASSWORD:-}" ]; then
    E2E_CUSTOMER_PASSWORD="E2e$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 14)!7"
    if grep -q '^E2E_CUSTOMER_PASSWORD=' "$e2e_config"; then
        sed -i "s|^E2E_CUSTOMER_PASSWORD=.*|E2E_CUSTOMER_PASSWORD=$E2E_CUSTOMER_PASSWORD|" "$e2e_config"
    else
        printf 'E2E_CUSTOMER_PASSWORD=%s\n' "$E2E_CUSTOMER_PASSWORD" >> "$e2e_config"
    fi
    step "generated a password for the fixture customer (stored in $e2e_config)"
fi
export E2E_CUSTOMER_PASSWORD

original_variant="$(e2e_magento config:show hyva_themes_checkout/general/checkout 2>/dev/null | tr -d '\r\n ' || true)"
case "$original_variant" in onepage|default|mobile) ;; *) original_variant="" ;; esac
relaxed=0

cleanup() {
    if [ "$keep" -eq 1 ]; then
        step "kept: fixtures and test orders stay (teardown: dev/e2e/fixtures.php teardown, then purge)"
    else
        E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php purge >"$run/purge.txt" 2>&1 || true
        E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php teardown >"$run/teardown.txt" 2>&1 || true
    fi
    [ "$relaxed" -eq 1 ] && e2e_php preflight.php restore >"$run/restore.txt" 2>&1
    if [ -n "$original_variant" ]; then
        "$e2e_here/set-variant.sh" "$original_variant" >/dev/null 2>&1 || true
    else
        e2e_magento cache:flush >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# 1. preflight
E2E_PHP_ENV="E2E_SKU" e2e_php preflight.php check >"$run/preflight.txt" 2>&1
if grep -q '^BLOCK' "$run/preflight.txt"; then
    if [ "$relax" -eq 1 ] && grep -q 'required custom attributes' "$run/preflight.txt"; then
        e2e_php preflight.php relax >>"$run/preflight.txt" 2>&1 && relaxed=1
        E2E_PHP_ENV="E2E_SKU" e2e_php preflight.php check >"$run/preflight2.txt" 2>&1
    fi
    if grep -q '^BLOCK' "$run/preflight2.txt" 2>/dev/null || { [ "$relax" -eq 0 ] && grep -q '^BLOCK' "$run/preflight.txt"; }; then
        echo "Preflight failed:" >&2
        grep -h '^BLOCK\|^ ' "$run/preflight2.txt" "$run/preflight.txt" 2>/dev/null >&2
        exit 2
    fi
fi
grep -h '^WARN' "$run/preflight.txt" "$run/preflight2.txt" 2>/dev/null || true
step "preflight ok$([ "$relaxed" -eq 1 ] && echo ' (foreign required attributes relaxed, restored at the end)')"

# 2. clean slate and fixtures
E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php purge >"$run/purge.txt" 2>&1 || true
E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php teardown >"$run/teardown.txt" 2>&1 || true
if ! E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php seed >"$run/seed.txt" 2>&1; then
    echo "Seeding the fixtures failed:" >&2; tail -8 "$run/seed.txt" >&2; exit 2
fi
# test orders use up stock: make sure the test products have enough before every run
e2e_php fixtures.php ensure-stock >"$run/stock.txt" 2>&1 || true
grep -h '^raised' "$run/stock.txt" || true
e2e_magento cache:flush >/dev/null 2>&1
[ -n "$original_variant" ] && echo "$original_variant" > "$CCA_E2E_HOME/.variant" || rm -f "$CCA_E2E_HOME/.variant"
step "fixtures seeded"; mark setup

# the size of the Magento logs now: ROB1 looks at what the suites add
log_dir="$E2E_MAGENTO_ROOT/var/log"
declare -A log_before=()
for log_name in exception system debug; do
    log_before[$log_name]=$(stat -c %s "$log_dir/$log_name.log" 2>/dev/null || echo 0)
done

# 3. suites
if wants api; then
    api_url="${E2E_API_BASE_URL:-$E2E_BASE_URL}"
    E2E_PHP_ENV="E2E_BASE_URL E2E_HOST_HEADER E2E_ADMIN_USER E2E_ADMIN_PASSWORD E2E_CUSTOMER_PASSWORD E2E_SKU E2E_INSECURE" \
        E2E_BASE_URL="$api_url" E2E_HOST_HEADER="${E2E_API_HOST_HEADER:-}" E2E_INSECURE=1 \
        e2e_php api.php >"$run/api.txt" 2>&1
    step "api suite done"; mark api
fi
if wants playwright; then
    [ "$quick" -eq 1 ] && pw_args=(--grep @quick ${pw_args[@]+"${pw_args[@]}"})
    E2E_VARIANTS="$variants" E2E_TIMINGS="$run/timings.jsonl" E2E_TERSE=1 E2E_PW_JSON="$run/pw.json" E2E_VARIANT_CMD="$e2e_here/set-variant.sh" E2E_FIXTURE_CMD="$e2e_here/fixture.sh" E2E_ASSERT_CMD="$e2e_here/assert.sh" "$e2e_here/run-playwright.sh" ${pw_args[@]+"${pw_args[@]}"} >"$run/pw.txt" 2>&1
    rc=$?
    if [ "$rc" -eq 3 ]; then
        cat "$run/pw.txt" >&2
        exit 3
    fi
    if [ "$rc" -gt 1 ] && [ ! -f "$run/pw.json" ]; then
        echo "The browser suite could not run:" >&2; tail -15 "$run/pw.txt" >&2
        exit 2
    fi
    step "browser suite done"; mark playwright
fi
if wants assert; then
    e2e_php assertions.php check >"$run/assert.txt" 2>&1
    step "database checks done"; mark assertions
fi

# ROB1: the log entries the suites added to var/log/exception.log, system.log and debug.log: none may be an error or exception raised in the
# module's code (an entry whose first line holds "MageOS" at level ERROR or worse). A refusal of a value (an order rejected for a missing or
# invalid value) is logged by core and by Hyvä Checkout and is expected: those messages are left out.
log_problems=0
log_detail=""
for log_name in exception system debug; do
    file="$log_dir/$log_name.log"
    [ -f "$file" ] || continue
    from=$(( ${log_before[$log_name]} + 1 ))
    [ "$(stat -c %s "$file")" -lt "${log_before[$log_name]}" ] && from=1   # the log was rotated: read all of it
    found=$(tail -c +"$from" "$file" | grep -E '^\[[0-9-]+T[0-9:.+-]+\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY)' | grep -i 'mageos' | grep -viE 'is a required value|has an invalid value|contains invalid data|is not a valid|is not an option|cannot be longer|cannot be shorter|needs the field' | head -3 | cut -c1-240 || true)
    if [ -n "$found" ]; then log_problems=$((log_problems + 1)); log_detail="$log_detail\n      $log_name.log: $found"; fi
done
if [ "$log_problems" -eq 0 ]; then
    echo "ok    [ROB1] the suites added no error or exception from the module to var/log (exception.log, system.log, debug.log)" >>"$run/assert.txt"
else
    printf 'FAIL  [ROB1] the suites added errors from the module to var/log%b\n' "$log_detail" >>"$run/assert.txt"
fi

# 4. summary
printf '{"started":"%s","seconds":%s,"variant":"%s","quick":%s,"variants":%s}\n' "$started" "$((SECONDS - t0))" "$original_variant" "$quick" "$variants" > "$run/meta.json"
docs_args=()
[ "$update_docs" -eq 1 ] && docs_args=(--docs "$(cd "$e2e_here/../.." && pwd)/docs/TESTING.md" --update-docs)
echo
node "$e2e_here/summarize.mjs" --run-dir "$run" ${docs_args[@]+"${docs_args[@]}"}
exit $?
