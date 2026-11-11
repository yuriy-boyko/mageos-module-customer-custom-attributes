<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and writes the customer attribute values typed into the checkout (table mageos_cca_quote_value, checkout connection).
 * They live on the quote only; the customer account is not changed. Writes only what changed, empty values delete their row.
 */
class QuoteValueStorage
{
    private const TABLE = 'mageos_cca_quote_value';
    private const CONNECTION = 'checkout';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array<int, string> attribute id => value
     */
    public function load(int $quoteId): array
    {
        if ($quoteId <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE, self::CONNECTION), ['attribute_id', 'value'])
            ->where('quote_id = ?', $quoteId);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int) $row['attribute_id']] = (string) $row['value'];
        }
        return $result;
    }

    /**
     * @param array<int, string|null> $values attribute id => value, null deletes the row
     */
    public function save(int $quoteId, array $values): void
    {
        if ($quoteId <= 0 || $values === []) {
            return;
        }
        $existing = $this->load($quoteId);

        $upsert = [];
        $delete = [];
        foreach ($values as $attributeId => $value) {
            $attributeId = (int) $attributeId;
            if ($value === null || $value === '') {
                if (array_key_exists($attributeId, $existing)) {
                    $delete[] = $attributeId;
                }
                continue;
            }
            if (($existing[$attributeId] ?? null) !== $value) {
                $upsert[] = ['quote_id' => $quoteId, 'attribute_id' => $attributeId, 'value' => $value];
            }
        }

        $connection = $this->resource->getConnection(self::CONNECTION);
        $table = $this->resource->getTableName(self::TABLE, self::CONNECTION);
        if ($upsert !== []) {
            $connection->insertOnDuplicate($table, $upsert, ['value']);
        }
        if ($delete !== []) {
            $connection->delete($table, ['quote_id = ?' => $quoteId, 'attribute_id IN (?)' => $delete]);
        }
    }
}
