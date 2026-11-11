#!/usr/bin/env bash
# Runs an assertions.php command on the test store (used by the Playwright tests: E2E_ASSERT_CMD): assert.sh order <increment_id>
set -euo pipefail
# shellcheck disable=SC1091
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
e2e_load_config
e2e_php assertions.php "$@"
