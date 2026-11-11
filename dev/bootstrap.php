<?php
declare(strict_types=1);

// Bootstrap for the Magento-free unit tests. PHPUnit is installed OUTSIDE the module (see dev/check.sh), because
// `setup:di:compile` scans every module directory and must never find a vendor folder inside it.
$vendor = getenv('CCA_DEV_VENDOR') ?: '/tmp/mageos-cca-dev-vendor';
require $vendor . '/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'MageOS\\CustomerCustomAttributes\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
