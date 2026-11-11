#!/usr/bin/env bash
# Runs the Playwright tests of dev/e2e/playwright against a store.
#
#   E2E_BASE_URL=https://shop.example dev/e2e/run-playwright.sh [playwright arguments, e.g. tests/dep12-multiselect-parent.spec.ts]
#
# Needs Node 22 or newer (nvm: `nvm use` in dev/e2e/playwright reads .nvmrc) and the fixtures (`php dev/e2e/fixtures.php seed`).
# The dependencies and the browser are installed into CCA_E2E_HOME (default ~/.cache/mageos-cca-e2e), never into the module,
# because `setup:di:compile` scans every module folder. The tests are copied there before each run.
#
# Before running, it checks the machine. When something is missing that only the user can fix (root rights, a hosts entry, a
# network or a missing tool), it stops with a block starting with "ACTION NEEDED" and exit code 3, naming the exact command. An AI
# agent running this script must stop there, show that block to the user and wait; it must not try to work around it.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
export CCA_E2E_HOME="${CCA_E2E_HOME:-$HOME/.cache/mageos-cca-e2e}"

# needs_user <what is wrong> <what the user should do> ...
needs_user() {
    {
        echo
        echo "================ ACTION NEEDED (by the user) ================"
        echo "Problem: $1"
        shift
        for line in "$@"; do echo "  $line"; done
        echo "Then run this script again."
        echo "============================================================="
    } >&2
    exit 3
}

if [ -z "${E2E_BASE_URL:-}" ]; then
    echo "Set E2E_BASE_URL, e.g. E2E_BASE_URL=https://shop.example (see dev/e2e/README.md)." >&2
    exit 2
fi

# Node: use nvm when node is not on the PATH yet.
if ! command -v node >/dev/null && [ -f "$HOME/.nvm/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$HOME/.nvm/nvm.sh"
    (cd "$here/playwright" && nvm use >/dev/null 2>&1) || true
fi
command -v node >/dev/null || needs_user "Node.js is not installed." \
    "Install nvm (https://github.com/nvm-sh/nvm), then: nvm install 24"
node_major="$(node -p 'process.versions.node.split(".")[0]')"
[ "$node_major" -ge 22 ] || needs_user "Node $(node -v) is too old (22 or newer is needed)." \
    "nvm install 24 && nvm use 24"
command -v npm >/dev/null || needs_user "npm is not available." "Reinstall Node with nvm: nvm install 24"

# The store must answer from this machine (the browser runs here).
status="$(curl -sk -o /dev/null -m 20 -w '%{http_code}' "$E2E_BASE_URL/" || true)"
case "$status" in
    2*|3*) ;;
    *) needs_user "The store at $E2E_BASE_URL does not answer from this machine (HTTP status ${status:-none})." \
        "Check that the store is running and that its host name resolves here (a line in /etc/hosts, and in the Windows hosts file for a" \
        "browser on Windows). Try: curl -skI $E2E_BASE_URL/" ;;
esac

mkdir -p "$CCA_E2E_HOME"
rm -rf "$CCA_E2E_HOME/tests"
cp -R "$here/playwright/tests" "$CCA_E2E_HOME/tests"
cp "$here/playwright/package.json" "$here/playwright/playwright.config.ts" "$here/playwright/global-setup.ts" "$CCA_E2E_HOME/"
cd "$CCA_E2E_HOME"
if [ ! -d node_modules/@playwright/test ]; then
    npm install --no-audit --no-fund || needs_user "npm install failed." \
        "Check the network connection and the npm registry access (proxy settings), then try again."
fi
if ! ls "$HOME/.cache/ms-playwright"/chromium_headless_shell-* >/dev/null 2>&1; then
    npx playwright install chromium || needs_user "The Chromium browser could not be downloaded." \
        "Check the network connection (the download comes from the Playwright CDN), then try again."
fi

# Shared libraries the browser needs but the system lacks can be unpacked (dpkg -x) into $CCA_E2E_HOME/libs without root.
if [ -d "$CCA_E2E_HOME/libs/usr/lib/x86_64-linux-gnu" ]; then
    export LD_LIBRARY_PATH="$CCA_E2E_HOME/libs/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
fi
browser="$(ls -d "$HOME"/.cache/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell 2>/dev/null | head -1 || true)"
if [ -n "$browser" ] && command -v ldd >/dev/null; then
    missing="$(ldd "$browser" 2>/dev/null | grep 'not found' | awk '{print $1}' | sort -u | tr '\n' ' ' || true)"
    if [ -n "$missing" ]; then
        needs_user "The browser needs system libraries that are not installed: $missing" \
            "Install them once (this needs root rights, so run it yourself in your terminal):" \
            "    sudo env \"PATH=\$PATH\" npx playwright install-deps chromium" \
            "or, on Debian/Ubuntu: sudo apt-get install -y libasound2t64 libnss3 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libxkbcommon0 libgbm1" \
            "(no root? download the package with apt-get download <package> and unpack it with dpkg -x into $CCA_E2E_HOME/libs)"
    fi
fi

exec npx playwright test "$@"
