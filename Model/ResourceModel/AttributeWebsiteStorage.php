<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The per-website overrides of an attribute in core's table customer_eav_attribute_website (enabled, required, default
 * value). A NULL column means "use the default of the attribute". Core reads these rows when it loads the attribute for a
 * website.
 */
class AttributeWebsiteStorage
{
    private const TABLE = 'customer_eav_attribute_website';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array<int, array{is_visible: int|null, is_required: int|null, default_value: string|null}> by website id
     */
    public function load(int $attributeId): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['website_id', 'is_visible', 'is_required', 'default_value'])
                ->where('attribute_id = ?', $attributeId)
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['website_id']] = [
                'is_visible' => $row['is_visible'] === null ? null : (int) $row['is_visible'],
                'is_required' => $row['is_required'] === null ? null : (int) $row['is_required'],
                'default_value' => $row['default_value'] === null ? null : (string) $row['default_value'],
            ];
        }
        return $result;
    }

    /**
     * @param array<int, array{is_visible: int|null, is_required: int|null, default_value: string|null}> $rows by website id
     */
    public function save(int $attributeId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $data = [];
        foreach ($rows as $websiteId => $row) {
            $data[] = ['attribute_id' => $attributeId, 'website_id' => (int) $websiteId] + $row;
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            $data,
            ['is_visible', 'is_required', 'default_value']
        );
    }
}
