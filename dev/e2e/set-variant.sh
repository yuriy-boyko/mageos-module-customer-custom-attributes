#!/usr/bin/env bash
# Switches the Hyvä Checkout variant of the test store: set-variant.sh onepage|default|mobile
# Used by the Playwright tests (E2E_VARIANT_CMD) and by run-all.sh; reads local.env.
set -euo pipefail
variant="${1:-}"
case "$variant" in onepage|default|mobile) ;; *) echo "Usage: set-variant.sh onepage|default|mobile" >&2; exit 2 ;; esac
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
e2e_load_config
e2e_magento config:set hyva_themes_checkout/general/checkout "$variant" >/dev/null
e2e_magento cache:flush >/dev/null
