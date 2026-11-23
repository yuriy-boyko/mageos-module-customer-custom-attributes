<?php
declare(strict_types=1);

// Bootstrap for the adapter tests in Test/Magento: they mock Magento classes, so they run inside a Magento install with its autoloader
// and its PHPUnit, from the Magento root:
//   vendor/bin/phpunit -c <module path>/phpunit.magento.xml.dist
$dir = getcwd() ?: '.';
while (!is_file($dir . '/app/autoload.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) {
        fwrite(STDERR, "Run from a Magento root (app/autoload.php not found above " . getcwd() . ")\n");
        exit(2);
    }
    $dir = $parent;
}
require $dir . '/app/autoload.php';
