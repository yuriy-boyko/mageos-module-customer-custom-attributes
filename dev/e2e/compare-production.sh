#!/usr/bin/env bash
# ROB4 in production mode: the same comparison as compare-module-off.sh, on a store switched to production mode.
#
#   dev/e2e/compare-production.sh [options of compare-module-off.sh]
#
# `bin/magento deploy:mode:set production` compiles the dependency injection configuration and deploys the static content, so the
# measurement has no on-the-fly code generation or static file linking. For the run without the module, the configuration is compiled again
# after the module is disabled. THIS CHANGES THE STORE: app/etc/env.php (the mode), app/etc/config.php, generated/, pub/static/ and
# var/view_preprocessed/ are copied first and put back at the end (also after an error or Ctrl-C), the mode is set back to what it was, and
# the caches are flushed. Run it on a test store only, never in parallel with other tests; it is not part of run-all.sh. It takes a long time
# (two compilations and a static content deployment). The numbers are not mixed with the ones of developer mode.
# Exit code: 0 within the limits, 1 over a limit, 2 a setup problem.
set -uo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
e2e_load_config
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"
work="$CCA_E2E_HOME/rob4-production"; rm -rf "$work"; mkdir -p "$work"
root="$E2E_MAGENTO_ROOT"
env_file="$root/app/etc/env.php"
config="$root/app/etc/config.php"
[ -f "$env_file" ] && [ -f "$config" ] || e2e_fail "app/etc/env.php or config.php not found in $root."

mode_before=$(e2e_magento deploy:mode:show 2>/dev/null | tr -d '\r' | sed -n 's/^Current application mode: \([a-z]*\).*/\1/p')
[ "$mode_before" = developer ] || e2e_fail "The store is in '$mode_before' mode; this script starts from developer mode and puts it back."
# env.php holds credentials: it is copied, never printed
cp -p "$env_file" "$work/env.php.backup"
cp -p "$config" "$work/config.php.backup"
(cd "$root" && tar czf "$work/files.tgz" pub/static var/view_preprocessed generated)

restore() {
    echo "putting the store back to $mode_before mode ..."
    cp -p "$work/env.php.backup" "$env_file"
    cp -p "$work/config.php.backup" "$config"
    (cd "$root" && rm -rf pub/static/* var/view_preprocessed/* generated/* 2>/dev/null; tar xzf "$work/files.tgz")
    e2e_magento cache:flush >/dev/null 2>&1 || true
}
trap restore EXIT

echo "switching to production mode (compiles and deploys static content) ..."
if ! e2e_magento deploy:mode:set production >"$work/mode-production.log" 2>&1; then
    tail -8 "$work/mode-production.log" >&2
    echo "deploy:mode:set production failed" >&2
    exit 2
fi
e2e_magento cache:flush >/dev/null 2>&1 || true
"$e2e_here/compare-module-off.sh" --recompile "$@"
code=$?
cp "$CCA_E2E_HOME/rob4/on.json" "$work/on.json" 2>/dev/null; cp "$CCA_E2E_HOME/rob4/off.json" "$work/off.json" 2>/dev/null

trap - EXIT
restore
cmp -s "$work/env.php.backup" "$env_file" && cmp -s "$work/config.php.backup" "$config" || { echo "app/etc/env.php or config.php differs from its backup: $work" >&2; exit 2; }
mode_after=$(e2e_magento deploy:mode:show 2>/dev/null | tr -d '\r' | sed -n 's/^Current application mode: \([a-z]*\).*/\1/p')
[ "$mode_after" = "$mode_before" ] || { echo "the mode is '$mode_after', expected '$mode_before'" >&2; exit 2; }
echo "back in $mode_after mode, app/etc/env.php and config.php restored"
exit "$code"
