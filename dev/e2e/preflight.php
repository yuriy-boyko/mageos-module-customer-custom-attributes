<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

/**
 * Checks and prepares the store before an end-to-end run, so the tests start from a known baseline.
 *
 *   php dev/e2e/preflight.php check      report what would disturb the tests (exit 1 when something blocks them)
 *   php dev/e2e/preflight.php relax      make the blocking attributes optional (remembered in var/e2e-state.json)
 *   php dev/e2e/preflight.php restore    put them back as they were
 *
 * What blocks the tests: a custom attribute that is not a fixture (its code does not start with e2e_) and is required, because the
 * tests send values for the fixture attributes only. Reported but not blocking: the configured Hyvä Checkout variant, the stock of the
 * test product (E2E_SKU, default 24-MB04, at least 20 units are wanted), and fixtures left over from an earlier run.
 * Run it from the Magento root (or set MAGENTO_ROOT). This file declares no class, so `setup:di:compile` ignores it.
 */

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$command = $argv[1] ?? '';
if (!in_array($command, ['check', 'relax', 'restore'], true)) {
    fwrite(STDERR, "Usage: php dev/e2e/preflight.php check|relax|restore\n");
    exit(2);
}
$root = rtrim((string) (getenv('MAGENTO_ROOT') ?: getcwd()), '/');
if (!is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Run from the Magento root or set MAGENTO_ROOT (app/bootstrap.php not found in $root).\n");
    exit(2);
}
require $root . '/app/bootstrap.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$resource = $objectManager->get(ResourceConnection::class);
$db = $resource->getConnection();
$table = static fn (string $name): string => $resource->getTableName($name);
$stateFile = BP . '/var/e2e-state.json';

/** @return list<array{attribute_id: string, attribute_code: string, entity: string}> required custom attributes that are not fixtures */
$blocking = static function () use ($db, $table): array {
    return $db->fetchAll(
        'SELECT a.attribute_id, a.attribute_code, t.entity_type_code AS entity FROM ' . $table('eav_attribute') . ' a
         JOIN ' . $table('eav_entity_type') . " t ON t.entity_type_id = a.entity_type_id AND t.entity_type_code IN ('customer', 'customer_address')
         WHERE a.is_user_defined = 1 AND a.is_required = 1 AND a.attribute_code NOT LIKE 'e2e\\_%'
         AND EXISTS (SELECT 1 FROM " . $table('customer_form_attribute') . ' f WHERE f.attribute_id = a.attribute_id)
         ORDER BY a.attribute_code'
    );
};

if ($command === 'relax') {
    $rows = $blocking();
    if ($rows === []) {
        echo "nothing to relax\n";
        exit(0);
    }
    $previous = is_file($stateFile) ? (array) json_decode((string) file_get_contents($stateFile), true) : [];
    $previous['relaxed'] = array_values(array_unique(array_merge((array) ($previous['relaxed'] ?? []), array_column($rows, 'attribute_id'))));
    file_put_contents($stateFile, json_encode($previous));
    $db->update($table('eav_attribute'), ['is_required' => 0], ['attribute_id IN (?)' => array_column($rows, 'attribute_id')]);
    echo 'relaxed  ' . implode(', ', array_column($rows, 'attribute_code')) . "\n";
    exit(0);
}

if ($command === 'restore') {
    if (!is_file($stateFile)) {
        echo "nothing to restore\n";
        exit(0);
    }
    $state = (array) json_decode((string) file_get_contents($stateFile), true);
    $ids = array_map('intval', (array) ($state['relaxed'] ?? []));
    if ($ids !== []) {
        $db->update($table('eav_attribute'), ['is_required' => 1], ['attribute_id IN (?)' => $ids]);
        echo 'restored ' . count($ids) . " required attribute(s)\n";
    }
    unlink($stateFile);
    exit(0);
}

// check
$problems = 0;
$rows = $blocking();
if ($rows !== []) {
    $problems++;
    echo "BLOCK  required custom attributes that are not fixtures (the tests do not send values for them): "
        . implode(', ', array_map(static fn (array $row): string => $row['attribute_code'], $rows)) . "\n";
    echo "       run `php dev/e2e/preflight.php relax` (undone by `restore`) or make them optional\n";
} else {
    echo "ok     no foreign required custom attributes\n";
}
if (is_file($stateFile)) {
    echo "note   var/e2e-state.json exists: an earlier run relaxed attributes and did not restore them (`restore` puts them back)\n";
}

$variant = (string) $db->fetchOne('SELECT value FROM ' . $table('core_config_data') . " WHERE path = 'hyva_themes_checkout/general/checkout' AND scope = 'default'");
echo 'info   checkout variant: ' . ($variant !== '' ? $variant : '(default, not set)') . "\n";

$leftover = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $table('eav_attribute') . " WHERE attribute_code LIKE 'e2e\\_%'");
echo 'info   fixture attributes present: ' . $leftover . ($leftover > 0 ? " (seed leaves existing ones as they are; teardown removes them)\n" : "\n");

$sku = (string) (getenv('E2E_SKU') ?: '24-MB04');
try {
    $qty = (float) $objectManager->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class)->getStockItemBySku($sku)->getQty();
    echo ($qty >= 20 ? 'ok     ' : 'WARN   ') . "stock of $sku: " . (int) $qty . ($qty >= 20 ? "\n" : " (add stock and reindex: each run places a few orders)\n");
} catch (\Throwable $e) {
    $problems++;
    echo "BLOCK  the test product $sku was not found\n";
}

exit($problems === 0 ? 0 : 1);
