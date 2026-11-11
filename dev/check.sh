#!/usr/bin/env bash
# Runs every check that works without a Magento install: PHP lint, XML, JSON, PHPUnit. Exits non-zero on the first failure.
#
# PHPUnit is installed into CCA_DEV_VENDOR (default /tmp/mageos-cca-dev-vendor), never into the module, so that
# `setup:di:compile` on a Magento install with this module symlinked never sees a vendor folder.
# PHP_OPTS (optional) is passed to the PHP syntax check, e.g. PHP_OPTS=-n.
set -euo pipefail
cd "$(dirname "$0")/.."
export CCA_DEV_VENDOR="${CCA_DEV_VENDOR:-/tmp/mageos-cca-dev-vendor}"

echo "== PHP syntax"
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php ${PHP_OPTS:-} -l >/dev/null
echo "ok"

echo "== Shell scripts and Node scripts of dev/e2e"
for f in dev/e2e/*.sh; do bash -n "$f"; done
if command -v node >/dev/null; then node --check dev/e2e/summarize.mjs; fi
echo "ok"

echo "== XML well-formedness"
if command -v xmllint >/dev/null; then
    find . -name '*.xml' -not -path './.git/*' -print0 | xargs -0 -n1 xmllint --noout
    echo "ok"
else
    php -r 'foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS)) as $f) { if (substr((string) $f, -4) === ".xml" && strpos((string) $f, "/.git/") === false) { $d = new DOMDocument(); if (!@$d->load((string) $f)) { fwrite(STDERR, "bad xml: $f\n"); exit(1); } } } echo "ok\n";'
fi

echo "== JSON validity"
php -r 'foreach (array_merge(glob("composer.json"), glob("etc/*.json"), glob("dev/*.json"), glob("dev/e2e/*.json")) as $f) { json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); echo "ok $f\n"; }'

echo "== PHPUnit"
if [ ! -x "$CCA_DEV_VENDOR/bin/phpunit" ]; then
    COMPOSER_VENDOR_DIR="$CCA_DEV_VENDOR" composer install -d dev --no-interaction --quiet
fi
"$CCA_DEV_VENDOR/bin/phpunit" -c phpunit.xml.dist
