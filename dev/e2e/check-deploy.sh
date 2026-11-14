#!/usr/bin/env bash
# INS11: the Hyvä Tailwind build and the static content deploy work with the module installed.
#
#   dev/e2e/check-deploy.sh [--skip-tailwind] [--skip-deploy]
#
# 1. Tailwind: `bin/magento hyva:config:generate` must list the module and its companion, `npm run build` in the Hyvä theme must succeed,
#    and the compiled stylesheet must hold a rule for every Tailwind-looking class of the module's templates (check-tailwind-classes.mjs,
#    with an empty stylesheet as the negative control).
# 2. Static content deploy: `setup:static-content:deploy -f` for the storefront theme and the admin theme must succeed without errors, and the
#    deployed stylesheet must be the theme's compiled one.
#
# It changes files while it runs and puts them back (also after an error or Ctrl-C): app/etc/hyva-themes.json, the theme's web/css/styles.css
# and web/tailwind/generated, and it removes the deployed pub/static/frontend and pub/static/adminhtml, which a developer-mode store builds
# again on demand. Run it on a test store only, never in parallel with other tests; it is not part of run-all.sh.
# Exit code: 0 all fine, 1 a check failed, 2 a setup problem.
set -uo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

tailwind=1; deploy=1
for arg in "$@"; do
    case "$arg" in
        --skip-tailwind) tailwind=0 ;;
        --skip-deploy) deploy=0 ;;
        *) e2e_fail "Unknown option $arg (see the header of $0)." ;;
    esac
done
e2e_load_config
if ! command -v node >/dev/null && [ -f "$HOME/.nvm/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$HOME/.nvm/nvm.sh"; (cd "$e2e_here/playwright" && nvm use >/dev/null 2>&1) || true
fi
command -v node >/dev/null || e2e_fail "Node.js is not installed on this machine (nvm install 24): the class check runs here."
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"
work="$CCA_E2E_HOME/ins11"; rm -rf "$work"; mkdir -p "$work"
root="$E2E_MAGENTO_ROOT"
failed=0
ok() { echo "ok    $*"; }
bad() { echo "FAIL  $*"; failed=$((failed + 1)); }

package="$(find "$root/app/design/frontend" -path '*/web/tailwind/package.json' -not -path '*/node_modules/*' 2>/dev/null | head -1)"
[ -n "$package" ] || e2e_fail "No Hyvä theme with web/tailwind found under app/design/frontend."
tailwind_dir="$(dirname "$package")"; theme_web="$(dirname "$tailwind_dir")"
tailwind_rel="${tailwind_dir#"$root"/}"
themes_json="$root/app/etc/hyva-themes.json"
styles="$theme_web/css/styles.css"
module_templates="$e2e_here/../../view/frontend/templates"
companion_templates="$e2e_here/../../../mageos-module-customer-custom-attributes-hyva-checkout/view/frontend/templates"

# backups of everything the checks touch
cp -p "$themes_json" "$work/hyva-themes.json" 2>/dev/null || touch "$work/no-hyva-themes-json"
cp -p "$styles" "$work/styles.css"
cp -a "$tailwind_dir/generated" "$work/generated" 2>/dev/null || true
( cd "$root/pub/static" && ls -A ) > "$work/static-before.txt"

restore() {
    if [ -f "$work/hyva-themes.json" ]; then cp -p "$work/hyva-themes.json" "$themes_json"; else rm -f "$themes_json"; fi
    cp -p "$work/styles.css" "$styles"
    if [ -d "$work/generated" ]; then rm -rf "$tailwind_dir/generated"; cp -a "$work/generated" "$tailwind_dir/generated"; fi
    rm -rf "$root/pub/static/frontend" "$root/pub/static/adminhtml" "$root/pub/static/deployed_version.txt" "$root/var/view_preprocessed"
    e2e_magento cache:flush >/dev/null 2>&1 || true
}
trap restore EXIT

if [ "$tailwind" -eq 1 ]; then
    echo "== Tailwind"
    e2e_magento hyva:config:generate >"$work/generate.log" 2>&1
    if grep -q 'packages/mageos-module-customer-custom-attributes"\|module-customer-custom-attributes"' "$themes_json" \
        && grep -q 'custom-attributes-hyva-checkout"' "$themes_json"; then
        ok "hyva:config:generate lists the module and its companion"
    else
        bad "hyva:config:generate does not list the module and its companion (see $themes_json)"
    fi
    if e2e_run bash -c "cd '$tailwind_rel' && npm run build" >"$work/build.log" 2>&1; then
        ok "npm run build in the Hyvä theme"
    else
        bad "npm run build failed, see $work/build.log"
        tail -8 "$work/build.log"
    fi
    if grep -qiE '(^|[^a-z])error(:|\b)' "$work/build.log"; then bad "the Tailwind build printed errors, see $work/build.log"; else ok "no errors in the Tailwind output"; fi
    if node "$e2e_here/check-tailwind-classes.mjs" "$styles" "$module_templates" "$companion_templates" >"$work/classes.log" 2>&1; then
        ok "$(head -1 "$work/classes.log")"
    else
        bad "classes of the templates without a rule"; cat "$work/classes.log"
    fi
    : > "$work/empty.css"
    if node "$e2e_here/check-tailwind-classes.mjs" "$work/empty.css" "$module_templates" "$companion_templates" >/dev/null 2>&1; then
        bad "negative control: an empty stylesheet passed the class check"
    else
        ok "negative control: an empty stylesheet fails the class check"
    fi
    if cmp -s "$styles" "$work/styles.css"; then echo "info  the rebuilt stylesheet is identical to the one before"; else echo "info  the rebuilt stylesheet differs from the one before ($(stat -c %s "$work/styles.css") -> $(stat -c %s "$styles") bytes); the original is put back"; fi
fi

if [ "$deploy" -eq 1 ]; then
    echo "== Static content deploy"
    # the compiled stylesheet of the store as it is (not the rebuilt one) is what the deploy must carry
    cp -p "$work/styles.css" "$styles"
    rm -rf "$root/pub/static/frontend" "$root/pub/static/adminhtml" "$root/var/view_preprocessed"
    if e2e_magento setup:static-content:deploy -f -j 2 en_US --area frontend --theme MageOS/hyva >"$work/scd-frontend.log" 2>&1; then
        ok "static content deploy, storefront theme"
    else
        bad "static content deploy (storefront) failed, see $work/scd-frontend.log"; tail -8 "$work/scd-frontend.log"
    fi
    if e2e_magento setup:static-content:deploy -f -j 2 en_US --area adminhtml --theme Magento/backend >"$work/scd-admin.log" 2>&1; then
        ok "static content deploy, admin theme"
    else
        bad "static content deploy (admin) failed, see $work/scd-admin.log"; tail -8 "$work/scd-admin.log"
    fi
    if grep -qiE 'exception|fatal|\berror\b' "$work/scd-frontend.log" "$work/scd-admin.log"; then bad "the deploy printed errors:"; grep -hiE 'exception|fatal|\berror\b' "$work/scd-frontend.log" "$work/scd-admin.log" | head -5; else ok "no errors in the deploy output"; fi
    deployed="$root/pub/static/frontend/$(basename "$(dirname "$theme_web")")/$(basename "$theme_web")/en_US/css/styles.css"
    # the theme folder is app/design/frontend/<Vendor>/<theme>/web: <Vendor>/<theme> are the two names above web
    vendor_theme="$(basename "$(dirname "$theme_web")")"
    deployed="$root/pub/static/frontend/$(basename "$(dirname "$(dirname "$theme_web")")")/$vendor_theme/en_US/css/styles.css"
    if [ -f "$deployed" ] && cmp -s "$deployed" "$work/styles.css"; then ok "the deployed stylesheet is the theme's compiled one"; else bad "the deployed stylesheet is missing or differs from the theme's ($deployed)"; fi
fi

restore
trap - EXIT
# the store must serve its pages again (developer mode builds the static files on demand)
status="$(curl -sk -o /dev/null -m 120 -w '%{http_code}' "$E2E_BASE_URL/" || true)"
case "$status" in 200) ok "the storefront answers after the clean-up (HTTP $status)" ;; *) bad "the storefront answers HTTP ${status:-none} after the clean-up" ;; esac
( cd "$root/pub/static" && ls -A ) > "$work/static-after.txt"
[ "$failed" -eq 0 ] && echo "All checks passed." || echo "$failed check(s) failed."
exit $((failed == 0 ? 0 : 1))
