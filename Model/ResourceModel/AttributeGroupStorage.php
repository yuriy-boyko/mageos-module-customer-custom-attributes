<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Customer groups an attribute is limited to (no rows: all groups).
 */
class AttributeGroupStorage
{
    private const TABLE = 'mageos_cca_attribute_group';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return int[]
     */
    public function load(int $attributeId): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['customer_group_id'])
                ->where('attribute_id = ?', $attributeId)
                ->order('customer_group_id ASC')
        ));
    }

    /**
     * @param int[] $groupIds replaces the stored list; empty removes the restriction
     */
    public function save(int $attributeId, array $groupIds): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));

        $connection->beginTransaction();
        try {
            $connection->delete($table, ['attribute_id = ?' => $attributeId]);
            if ($groupIds !== []) {
                $connection->insertMultiple($table, array_map(
                    static fn (int $groupId): array => ['attribute_id' => $attributeId, 'customer_group_id' => $groupId],
                    $groupIds
                ));
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
