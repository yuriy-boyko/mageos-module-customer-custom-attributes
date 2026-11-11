#!/usr/bin/env bash
# Runs a fixtures.php command on the test store (used by the Playwright tests: E2E_FIXTURE_CMD): fixture.sh delete-option <code> <label>
set -euo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
e2e_load_config
E2E_PHP_ENV="E2E_CUSTOMER_PASSWORD" e2e_php fixtures.php "$@"
