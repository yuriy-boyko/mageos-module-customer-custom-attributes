#!/usr/bin/env bash
# INS5, INS7, INS9, INS12: compile, compile without the Hyvä Checkout modules, disabling the module and disabling the companion only.
#
#   dev/e2e/check-modules.sh [--only ins5|ins7|ins9|ins12]
#
# INS5  `setup:di:compile` with everything enabled succeeds.
# INS7  `setup:di:compile` with Hyvä Checkout, its dependants (Hyva_Checkout*) and the companion module disabled succeeds. The Hyvä files stay
#       on disk, so a fully Hyvä-free codebase is not proven.
# INS9  the module and the companion disabled and `setup:upgrade`: the tables in the whitelist are dropped (Magento's declarative schema
#       drops them), the two order snapshot tables are not (they are kept out of the whitelist) and keep their rows; the storefront, the
#       checkout, an order and the admin order view still work (tests/ins9-module-off.spec.ts); enabled again with `setup:upgrade` the
#       dropped tables are back (empty), the order rows are still there and an order carries its values again.
# INS12 only the companion disabled (the main module on): the checkout has no customer attribute sections, and a required customer
#       attribute ticked for the checkout does not block the order (tests/ins12-companion-off.spec.ts).
# (INS10, `module:uninstall -r`, changes composer.json and the lock file: it is not run here.)
#
# It changes app/etc/config.php and, for the compile, the generated code while it runs: the config file is copied first and put back byte
# for byte at the end (also after an error or Ctrl-C), the generated code is deleted (developer mode regenerates it on demand) and the caches
# are flushed. composer.json and composer.lock must not change: their checksums are compared at the end. Run it on a test store only,
# never in parallel with other tests; it is not part of run-all.sh. The compile takes several minutes.
# Exit code: 0 all fine, 1 a check failed, 2 a setup problem.
set -uo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

only=all
while [ $# -gt 0 ]; do
    case "$1" in
        --only) only="$2"; shift 2 ;;
        *) e2e_fail "Unknown option $1 (see the header of $0)." ;;
    esac
done
wants() { [ "$only" = all ] || [ "$only" = "$1" ]; }
e2e_load_config
if ! command -v node >/dev/null && [ -f "$HOME/.nvm/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$HOME/.nvm/nvm.sh"; (cd "$e2e_here/playwright" && nvm use >/dev/null 2>&1) || true
fi
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"
work="$CCA_E2E_HOME/ins-modules"; rm -rf "$work"; mkdir -p "$work"
root="$E2E_MAGENTO_ROOT"
config="$root/app/etc/config.php"
[ -f "$config" ] || e2e_fail "$config not found."
cp -p "$config" "$work/config.php.backup"
sums() { (cd "$root" && md5sum composer.json composer.lock 2>/dev/null); }
sums >"$work/composer.sums"
module_off=(MageOS_CustomerCustomAttributesHyvaCheckout MageOS_CustomerCustomAttributes)
failed=0
ok() { echo "ok    $*"; }
bad() { echo "FAIL  $*"; failed=$((failed + 1)); }

relaxed=0
restore() {
    if [ "$relaxed" -eq 1 ]; then e2e_php preflight.php restore >/dev/null 2>&1 && relaxed=0; fi
    cp -p "$work/config.php.backup" "$config"
    # the compile leaves compiled metadata that developer mode must not use; the generated classes come back on demand
    rm -rf "$root"/generated/metadata/* "$root"/generated/code/* 2>/dev/null || true
    e2e_magento cache:flush >/dev/null 2>&1 || true
}
trap restore EXIT

mysql_count() { # <sql>
    warden env exec -T db mysql -umagento -pmagento magento -N -e "$1" 2>/dev/null | tr -d '\r'
}
tables() { mysql_count "SHOW TABLES LIKE 'mageos_cca%'" | wc -l; }
order_rows() {
    local total=0 table n
    for table in mageos_cca_order_value mageos_cca_order_address_value; do
        n=$(mysql_count "SELECT COUNT(*) FROM $table" || echo 0); total=$((total + ${n:-0}))
    done
    echo "$total"
}
rows() {
    local total=0 table n
    for table in mageos_cca_quote_value mageos_cca_quote_address_value mageos_cca_order_value mageos_cca_order_address_value; do
        n=$(mysql_count "SELECT COUNT(*) FROM $table" || echo 0); total=$((total + ${n:-0}))
    done
    echo "$total"
}

compile() { # <label>
    e2e_magento setup:di:compile >"$work/$1.log" 2>&1
    local code=$?
    if [ "$code" -eq 0 ] && grep -q "successfully" "$work/$1.log"; then return 0; fi
    return 1
}

if wants ins5; then
    echo "== INS5: setup:di:compile"
    if compile ins5; then ok "[INS5] setup:di:compile succeeds"; else bad "[INS5] setup:di:compile failed, see $work/ins5.log"; fi
    restore
fi

if wants ins7; then
    echo "== INS7: setup:di:compile without Hyvä Checkout and the companion"
    hyva=$(e2e_magento module:status --enabled 2>/dev/null | tr -d '\r' | grep '^Hyva_Checkout' | tr '\n' ' ')
    # shellcheck disable=SC2086
    if e2e_magento module:disable $hyva MageOS_CustomerCustomAttributesHyvaCheckout >"$work/ins7-disable.log" 2>&1; then
        if compile ins7; then ok "[INS7] setup:di:compile succeeds with $hyva and the companion disabled"; else bad "[INS7] setup:di:compile failed, see $work/ins7.log"; fi
    else
        bad "[INS7] the Hyvä Checkout modules could not be disabled, see $work/ins7-disable.log"
    fi
    restore
fi

if wants ins9; then
    echo "== INS9: module disabled"
    before_rows=$(rows)
    # an order snapshot row of its own, so the check does not depend on orders the store happens to have (removed again at the end)
    marker_order=$(mysql_count "SELECT entity_id FROM sales_order ORDER BY entity_id DESC LIMIT 1")
    if [ -z "$marker_order" ]; then e2e_fail "INS9 needs an order in the store (place one first): its snapshot tables must keep their rows."; fi
    mysql_count "DELETE FROM mageos_cca_order_value WHERE attribute_code = 'ins9_marker'; INSERT INTO mageos_cca_order_value (order_id, attribute_code, attribute_label, input_type, value, value_label) VALUES ($marker_order, 'ins9_marker', 'INS9 marker', 'text', 'kept', 'kept')" >/dev/null
    marker() { mysql_count "SELECT COUNT(*) FROM mageos_cca_order_value WHERE attribute_code = 'ins9_marker'"; }
    before_order_rows=$(order_rows)
    # a required attribute of another package (still on the store) would block the order of the browser check, as in run-all.sh
    if e2e_php preflight.php relax >"$work/ins9-relax.log" 2>&1; then relaxed=1; fi
    if e2e_magento module:disable "${module_off[@]}" >"$work/ins9-disable.log" 2>&1 && e2e_magento setup:upgrade >"$work/ins9-upgrade-off.log" 2>&1; then
        e2e_magento cache:flush >/dev/null 2>&1
        # five tables are dropped; the two order snapshot tables stay, with every row
        if [ "$(tables)" -eq 2 ]; then ok "[INS9] setup:upgrade drops the five tables in the whitelist and keeps the two order snapshot tables (the module held $before_rows value rows)"; else bad "[INS9] after disabling the module $(tables) module tables are left, expected the 2 order snapshot tables"; fi
        if [ "$(order_rows)" -eq "$before_order_rows" ] && [ "$(marker)" -eq 1 ]; then ok "[INS9] the order snapshot tables keep their $before_order_rows rows"; else bad "[INS9] order snapshot rows: $(order_rows) now, $before_order_rows before (expected the same)"; fi
        if E2E_MODULE_OFF=1 "$e2e_here/run-playwright.sh" tests/ins9-module-off.spec.ts >"$work/ins9-off.log" 2>&1; then
            ok "[INS9] with the module off the storefront, the checkout, an order and the admin order view work"
        else
            bad "[INS9] a browser check with the module off failed, see $work/ins9-off.log and $work/results-off"
        fi
        cp -r "$CCA_E2E_HOME/test-results" "$work/results-off" 2>/dev/null || true
    else
        bad "[INS9] disabling the module or setup:upgrade failed, see $work/ins9-disable.log and $work/ins9-upgrade-off.log"
    fi
    # enabled again: the config file as it was, then setup:upgrade brings the tables back
    cp -p "$work/config.php.backup" "$config"
    if e2e_magento setup:upgrade >"$work/ins9-upgrade-on.log" 2>&1; then
        e2e_magento cache:flush >/dev/null 2>&1
        if [ "$(tables)" -eq 7 ]; then ok "[INS9] enabled again: the seven tables are there (the five dropped ones are empty, $(order_rows) order snapshot rows are kept)"; else bad "[INS9] after enabling the module has $(tables) tables, expected 7"; fi
        if [ "$(order_rows)" -ge "$before_order_rows" ] && [ "$(marker)" -eq 1 ]; then ok "[INS9] enabled again: the order snapshot rows are still there"; else bad "[INS9] enabled again: $(order_rows) order snapshot rows, $before_order_rows before"; fi
        mysql_count "DELETE FROM mageos_cca_order_value WHERE attribute_code = 'ins9_marker'" >/dev/null
        if "$e2e_here/run-all.sh" --only playwright -- tests/chk-values.spec.ts -g CHK6 >"$work/ins9-on.log" 2>&1; then
            ok "[INS9] enabled again: an order carries the module's values"
        else
            bad "[INS9] enabled again, the order check failed, see $work/ins9-on.log"
        fi
    else
        bad "[INS9] setup:upgrade after enabling failed, see $work/ins9-upgrade-on.log"
    fi
    restore
fi

if wants ins12; then
    echo "== INS12: companion disabled, main module on"
    if e2e_magento module:disable MageOS_CustomerCustomAttributesHyvaCheckout >"$work/ins12-disable.log" 2>&1; then
        e2e_magento cache:flush >/dev/null 2>&1
        if E2E_COMPANION_OFF=1 "$e2e_here/run-all.sh" --only playwright -- tests/ins12-companion-off.spec.ts >"$work/ins12.log" 2>&1; then
            ok "[INS12] with the companion off a required checkout customer attribute does not block the order"
        else
            bad "[INS12] the order with the companion off failed, see $work/ins12.log"
        fi
    else
        bad "[INS12] disabling the companion failed, see $work/ins12-disable.log"
    fi
    restore
fi

trap - EXIT
restore
cmp -s "$work/config.php.backup" "$config" || { echo "app/etc/config.php differs from its backup: $work/config.php.backup" >&2; exit 2; }
if [ "$(sums)" = "$(cat "$work/composer.sums")" ]; then ok "composer.json and composer.lock unchanged, app/etc/config.php restored"; else bad "composer.json or composer.lock changed"; fi
status=$(e2e_magento module:status MageOS_CustomerCustomAttributes MageOS_CustomerCustomAttributesHyvaCheckout 2>/dev/null | tr -d '\r' | grep -ci "disabled")
[ "$status" -eq 0 ] || bad "a module of the package is disabled at the end"
echo
[ "$failed" -eq 0 ] && echo "all checks passed" || echo "$failed check(s) failed"
[ "$failed" -eq 0 ]
