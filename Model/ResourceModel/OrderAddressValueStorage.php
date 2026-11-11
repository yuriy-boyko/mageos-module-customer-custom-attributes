<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and writes the order address snapshot rows (table mageos_cca_order_address_value, sales connection).
 *
 * A row keeps the attribute code, label, input type, raw value and displayed value as they were when the order was
 * placed. There is deliberately no link to eav_attribute: changing or deleting an attribute never changes an order.
 * When an existing row is saved again only value and value_label are updated; code, label and input type stay as
 * first stored.
 */
class OrderAddressValueStorage
{
    private const TABLE = 'mageos_cca_order_address_value';
    private const CONNECTION = 'sales';
    private const COLUMNS = ['attribute_code', 'attribute_label', 'input_type', 'value', 'value_label'];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int[] $orderAddressIds
     * @return array<int, list<array{attribute_code: string, attribute_label: ?string, input_type: ?string, value: ?string, value_label: ?string}>>
     */
    public function load(array $orderAddressIds): array
    {
        $orderAddressIds = array_values(array_unique(array_map('intval', $orderAddressIds)));
        if ($orderAddressIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE, self::CONNECTION), array_merge(['order_address_id'], self::COLUMNS))
            ->where('order_address_id IN (?)', $orderAddressIds)
            ->order('value_id ASC');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $parentId = (int) $row['order_address_id'];
            unset($row['order_address_id']);
            $result[$parentId][] = $row;
        }
        return $result;
    }

    /**
     * @param list<array{attribute_code: string, attribute_label: ?string, input_type: ?string, value: ?string, value_label: ?string}> $rows
     */
    public function save(int $orderAddressId, array $rows): void
    {
        if ($orderAddressId <= 0 || $rows === []) {
            return;
        }
        $data = [];
        foreach ($rows as $row) {
            $data[] = ['order_address_id' => $orderAddressId] + array_intersect_key($row, array_flip(self::COLUMNS));
        }
        $this->resource->getConnection(self::CONNECTION)->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE, self::CONNECTION),
            $data,
            ['value', 'value_label']
        );
    }

    /**
     * @param string[] $attributeCodes
     */
    public function remove(int $orderAddressId, array $attributeCodes): void
    {
        if ($orderAddressId <= 0 || $attributeCodes === []) {
            return;
        }
        $this->resource->getConnection(self::CONNECTION)->delete(
            $this->resource->getTableName(self::TABLE, self::CONNECTION),
            ['order_address_id = ?' => $orderAddressId, 'attribute_code IN (?)' => $attributeCodes]
        );
    }
}
