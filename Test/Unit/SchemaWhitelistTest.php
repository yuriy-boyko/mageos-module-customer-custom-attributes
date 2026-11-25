<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Magento's declarative schema drops a table that is in the whitelist when its module is disabled and `setup:upgrade` runs. The two
 * order snapshot tables are the only copy of the values an order was placed with, so they are declared but kept out of the whitelist:
 * disabling the module never removes them. `generate-whitelist` writes them back: remove them again (see docs/ARCHITECTURE.md).
 */
class SchemaWhitelistTest extends TestCase
{
    private const SNAPSHOT_TABLES = ['mageos_cca_order_value', 'mageos_cca_order_address_value'];

    /** @return string[] */
    private function declaredTables(): array
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/etc/db_schema.xml');
        preg_match_all('/<table\s+name="([a-z0-9_]+)"/', $xml, $matches);
        return $matches[1];
    }

    /** @return string[] */
    private function whitelistedTables(): array
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/etc/db_schema_whitelist.json'), true, 512, JSON_THROW_ON_ERROR);
        return array_keys($json);
    }

    public function testTheOrderSnapshotTablesAreDeclaredButNotWhitelisted(): void
    {
        foreach (self::SNAPSHOT_TABLES as $table) {
            self::assertContains($table, $this->declaredTables(), "$table is created by the schema");
            self::assertNotContains($table, $this->whitelistedTables(), "$table must stay out of the whitelist, or disabling the module drops the orders' values");
        }
    }

    public function testEveryOtherTableIsWhitelisted(): void
    {
        $expected = array_values(array_diff($this->declaredTables(), self::SNAPSHOT_TABLES));
        $whitelisted = $this->whitelistedTables();
        sort($expected);
        sort($whitelisted);

        self::assertSame($expected, $whitelisted, 'the other tables are derived or configuration data: they may be changed and dropped by the schema');
    }
}
