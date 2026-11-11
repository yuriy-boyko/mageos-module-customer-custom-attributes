<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Order snapshot of the customer attribute values (sales connection). Rows hold code, label, input type, value and the
 * displayed value as they were when the order was placed.
 */
class OrderValueStorage
{
    private const TABLE = 'mageos_cca_order_value';
    private const CONNECTION = 'sales';
    private const COLUMNS = ['attribute_code', 'attribute_label', 'input_type', 'value', 'value_label'];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int[] $orderIds
     * @return array<int, list<array<string, string|null>>> rows by order id
     */
    public function load(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE, self::CONNECTION), array_merge(['order_id'], self::COLUMNS))
            ->where('order_id IN (?)', $orderIds)
            ->order('value_id ASC');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $orderId = (int) $row['order_id'];
            unset($row['order_id']);
            $result[$orderId][] = $row;
        }
        return $result;
    }

    /**
     * @param list<array<string, string|null>> $rows
     */
    public function save(int $orderId, array $rows): void
    {
        if ($orderId <= 0 || $rows === []) {
            return;
        }
        $data = [];
        foreach ($rows as $row) {
            $data[] = ['order_id' => $orderId] + array_intersect_key($row, array_flip(self::COLUMNS));
        }
        $this->resource->getConnection(self::CONNECTION)->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE, self::CONNECTION),
            $data,
            ['value', 'value_label']
        );
    }
}
