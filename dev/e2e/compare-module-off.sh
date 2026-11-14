#!/usr/bin/env bash
# ROB4: request count and load time of the pages the module changes, with the module and without it.
#
#   dev/e2e/compare-module-off.sh [--max-slower-percent 100] [--max-slower-ms 1000] [--max-more-requests 3]
#
# Disables the module and its Hyvä Checkout companion (`bin/magento module:disable`), measures, enables them again and measures again.
# It changes app/etc/config.php while it runs: the file is copied first and put back byte for byte at the end (also after an error or Ctrl-C),
# and the caches are flushed both times. Run it on a test store only, never in parallel with other tests; it is not part of run-all.sh.
# The default limits are generous on purpose: with the module off the checkout does not render the custom fields at all (about 45 more
# inputs on the test store), so the checkout is expected to be slower; the first-party request count must stay the same.
# Exit code: 0 within the limits, 1 over a limit, 2 a setup problem.
set -uo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

pct=100; ms=1000; more=3
while [ $# -gt 0 ]; do
    case "$1" in
        --max-slower-percent) pct="$2"; shift 2 ;;
        --max-slower-ms) ms="$2"; shift 2 ;;
        --max-more-requests) more="$2"; shift 2 ;;
        *) e2e_fail "Unknown option $1 (see the header of $0)." ;;
    esac
done
e2e_load_config
if ! command -v node >/dev/null && [ -f "$HOME/.nvm/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$HOME/.nvm/nvm.sh"; (cd "$e2e_here/playwright" && nvm use >/dev/null 2>&1) || true
fi
command -v node >/dev/null || e2e_fail "Node.js is not installed (nvm install 24)."
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"
work="$CCA_E2E_HOME/rob4"; mkdir -p "$work"; rm -f "$work"/*.json
config="$E2E_MAGENTO_ROOT/app/etc/config.php"
[ -f "$config" ] || e2e_fail "$config not found."
cp -p "$config" "$work/config.php.backup"
modules=(MageOS_CustomerCustomAttributesHyvaCheckout MageOS_CustomerCustomAttributes)

restore() {
    cp -p "$work/config.php.backup" "$config"
    e2e_magento cache:flush >/dev/null 2>&1 || true
}
trap restore EXIT

# The first measurement seeds the fixtures and keeps them; the second one only runs the browser (the bench commands need the module).
measure() { # <label> <seed|reuse>
    if [ "$2" = seed ]; then
        E2E_ROB4_OUT="$work/$1.json" "$e2e_here/run-all.sh" --only playwright --keep -- tests/rob4-timing.spec.ts >"$work/$1.log" 2>&1 || true
    else
        E2E_ROB4_OUT="$work/$1.json" E2E_ROB4_SKIP_SETUP=1 "$e2e_here/run-playwright.sh" tests/rob4-timing.spec.ts >"$work/$1.log" 2>&1 || true
    fi
    [ -f "$work/$1.json" ] || { echo "The measurement '$1' failed, see $work/$1.log" >&2; exit 2; }
}

echo "measuring with the module ..."
measure on seed
echo "disabling the module and its Hyvä Checkout companion ..."
e2e_magento module:disable "${modules[@]}" >"$work/disable.log" 2>&1 || { cat "$work/disable.log" >&2; exit 2; }
e2e_magento cache:flush >/dev/null 2>&1
echo "measuring without the module ..."
measure off reuse
restore
trap - EXIT
# the fixtures of the first measurement go now, with the module enabled again
E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php purge >/dev/null 2>&1 || true
E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php teardown >/dev/null 2>&1 || true
e2e_magento cache:flush >/dev/null 2>&1 || true
cmp -s "$work/config.php.backup" "$config" || { echo "app/etc/config.php differs from its backup after the restore: $work/config.php.backup" >&2; exit 2; }
echo "module enabled again, app/etc/config.php restored"

node "$e2e_here/compare-rob4.mjs" "$work/on.json" "$work/off.json" "$pct" "$ms" "$more"
