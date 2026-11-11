<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and writes the value rows of quote addresses (table mageos_cca_quote_address_value, checkout connection).
 * Writes only what changed: unchanged values are not touched and empty values delete their row.
 */
class QuoteAddressValueStorage
{
    private const TABLE = 'mageos_cca_quote_address_value';
    private const CONNECTION = 'checkout';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int[] $addressIds
     * @return array<int, array<int, string>> quote address id => [attribute id => value]
     */
    public function load(array $addressIds): array
    {
        $addressIds = array_values(array_unique(array_map('intval', $addressIds)));
        if ($addressIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE, self::CONNECTION), ['quote_address_id', 'attribute_id', 'value'])
            ->where('quote_address_id IN (?)', $addressIds);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int) $row['quote_address_id']][(int) $row['attribute_id']] = (string) $row['value'];
        }
        return $result;
    }

    /**
     * @param array<int, string|null> $values attribute id => value, null deletes the row
     */
    public function save(int $addressId, array $values): void
    {
        if ($addressId <= 0 || $values === []) {
            return;
        }
        $existing = $this->load([$addressId])[$addressId] ?? [];

        $upsert = [];
        $delete = [];
        foreach ($values as $attributeId => $value) {
            $attributeId = (int) $attributeId;
            if ($value === null) {
                if (array_key_exists($attributeId, $existing)) {
                    $delete[] = $attributeId;
                }
                continue;
            }
            if (($existing[$attributeId] ?? null) !== $value) {
                $upsert[] = ['quote_address_id' => $addressId, 'attribute_id' => $attributeId, 'value' => $value];
            }
        }

        $connection = $this->resource->getConnection(self::CONNECTION);
        $table = $this->resource->getTableName(self::TABLE, self::CONNECTION);
        if ($upsert !== []) {
            $connection->insertOnDuplicate($table, $upsert, ['value']);
        }
        if ($delete !== []) {
            $connection->delete($table, ['quote_address_id = ?' => $addressId, 'attribute_id IN (?)' => $delete]);
        }
    }
}
