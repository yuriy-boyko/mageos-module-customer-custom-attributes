<?php
/**
 * INS6: every class of the main module loads while the Hyvä classes cannot be autoloaded.
 *
 *   php dev/e2e/check-classes.php        (from the Magento root, or set MAGENTO_ROOT; dev/e2e/check-install.sh runs it)
 *
 * An autoloader in front of Composer's refuses every class in the namespace Hyva\ (it throws). Then each PHP file of the module (without
 * Test/, dev/ and the registration file) is loaded by its class name; a class that extends or implements a Hyvä class, or loads one while
 * it is declared, fails. Type hints and `instanceof` do not load a class and are fine. Exit code 0 all load, 1 some do not, 2 setup problem.
 * This file declares no class, so `setup:di:compile` ignores it.
 */
declare(strict_types=1);

$root = rtrim((string) (getenv('MAGENTO_ROOT') ?: getcwd()), '/');
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run from the Magento root or set MAGENTO_ROOT (vendor/autoload.php not found in $root).\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

$blocked = [];
$prefix = (string) (getenv('CCA_BLOCK_PREFIX') ?: 'Hyva\\'); // another prefix only to prove that the check can fail
spl_autoload_register(static function (string $class) use (&$blocked, $prefix): void {
    if (str_starts_with($class, $prefix)) {
        $blocked[$class] = true;
        throw new RuntimeException("autoloading $class is blocked");
    }
}, true, true);

$module = dirname(__DIR__, 2);
$namespace = 'MageOS\\CustomerCustomAttributes\\';
$failed = [];
$count = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    $relative = substr($path, strlen($module) + 1);
    if ($file->getExtension() !== 'php' || preg_match('#^(Test|dev|docs|\.git|vendor|node_modules)/#', $relative) || $relative === 'registration.php' || str_starts_with($relative, 'view/')) {
        continue;
    }
    $class = $namespace . str_replace('/', '\\', substr($relative, 0, -4));
    $count++;
    try {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
            $failed[$class] = 'not found by the autoloader';
        }
    } catch (Throwable $e) {
        $failed[$class] = $e->getMessage();
    }
}
if ($failed === []) {
    echo "ok    [INS6] all $count classes of the module load with the Hyva classes blocked from autoloading\n";
    exit(0);
}
foreach ($failed as $class => $message) {
    echo "FAIL  [INS6] $class: $message\n";
}
exit(1);
