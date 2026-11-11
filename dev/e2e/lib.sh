# Shared by run-all.sh and set-variant.sh: reads local.env and defines how to run PHP and bin/magento on the store's machine.
# Sourced, not executed.

e2e_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
e2e_config="${E2E_CONFIG:-$e2e_here/local.env}"

e2e_fail() { echo "$*" >&2; exit 2; }

e2e_load_config() {
    [ -f "$e2e_config" ] || e2e_fail "Create $e2e_config from local.env.example (it holds the store address and passwords and is ignored by git)."
    set -a
    # shellcheck disable=SC1090
    . "$e2e_config"
    set +a
    [ -n "${E2E_BASE_URL:-}" ] || e2e_fail "E2E_BASE_URL is empty in $e2e_config."
    read -r -a E2E_EXEC_ARR <<< "${E2E_EXEC:-}"
    if [ -z "${E2E_MAGENTO_ROOT:-}" ]; then
        dir="$e2e_here"
        while [ "$dir" != "/" ] && [ ! -f "$dir/app/bootstrap.php" ]; do dir="$(dirname "$dir")"; done
        [ -f "$dir/app/bootstrap.php" ] || e2e_fail "Set E2E_MAGENTO_ROOT in $e2e_config (a folder with app/bootstrap.php)."
        E2E_MAGENTO_ROOT="$dir"
    fi
    if [ -z "${E2E_MODULE_DIR:-}" ]; then
        if [ ${#E2E_EXEC_ARR[@]} -gt 0 ]; then
            e2e_fail "Set E2E_MODULE_DIR in $e2e_config (the module's path as seen by: ${E2E_EXEC})."
        fi
        E2E_MODULE_DIR="$(cd "$e2e_here/../.." && pwd)"
    fi
    cd "$E2E_MAGENTO_ROOT"
}

# e2e_run <command...>: runs a command on the store's machine (through E2E_EXEC when set)
e2e_run() { ${E2E_EXEC_ARR[@]+"${E2E_EXEC_ARR[@]}"} "$@"; }

# e2e_magento <bin/magento arguments...>
e2e_magento() { e2e_run bin/magento "$@"; }

# e2e_php <script in dev/e2e> [arguments]: runs one of the PHP scripts; variables named in E2E_PHP_ENV (space separated) are passed on
e2e_php() {
    local script="$1"; shift
    local -a assignments=()
    local name
    for name in ${E2E_PHP_ENV:-}; do assignments+=("$name=${!name-}"); done
    e2e_run env ${assignments[@]+"${assignments[@]}"} php -d xdebug.mode=off -d opcache.enable_cli=0 "$E2E_MODULE_DIR/dev/e2e/$script" "$@"
}
