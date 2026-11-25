<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The forms (core `customer_form_attribute`) of many attributes in one query, for the attribute grid.
 */
class AttributeFormStorage
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int[] $attributeIds
     * @return array<int, string[]> attribute id => form codes
     */
    public function loadForAttributes(array $attributeIds): array
    {
        $attributeIds = array_values(array_unique(array_map('intval', $attributeIds)));
        if ($attributeIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('customer_form_attribute'), ['attribute_id', 'form_code'])
                ->where('attribute_id IN (?)', $attributeIds)
                ->order('form_code ASC')
        );
        $forms = [];
        foreach ($rows as $row) {
            $forms[(int) $row['attribute_id']][] = (string) $row['form_code'];
        }
        return $forms;
    }
}
