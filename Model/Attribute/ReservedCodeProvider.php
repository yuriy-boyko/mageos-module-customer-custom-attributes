<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Attribute;

use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use Magento\Framework\App\ResourceConnection;

/**
 * Codes a new attribute may not use: the column names of the tables whose objects carry the attribute values as data
 * keys (customer / address entity, quote, order).
 */
class ReservedCodeProvider
{
    /** table => connection name */
    private const TABLES = [
        EntityDefinitions::CUSTOMER => [
            'customer_entity' => ResourceConnection::DEFAULT_CONNECTION,
            'quote' => 'checkout',
            'sales_order' => 'sales',
        ],
        EntityDefinitions::ADDRESS => [
            'customer_address_entity' => ResourceConnection::DEFAULT_CONNECTION,
            'quote_address' => 'checkout',
            'sales_order_address' => 'sales',
        ],
    ];

    private const ALWAYS = ['id', 'entity_id', 'entity_type_id', 'attribute_set_id', 'increment_id', 'custom_attributes', 'extension_attributes'];

    /** @var array<string, string[]> */
    private array $cache = [];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return string[]
     */
    public function get(string $entityKey): array
    {
        if (!isset($this->cache[$entityKey])) {
            $codes = self::ALWAYS;
            foreach (self::TABLES[$entityKey] ?? [] as $table => $connectionName) {
                $connection = $this->resource->getConnection($connectionName);
                $codes = array_merge(
                    $codes,
                    array_keys($connection->describeTable($this->resource->getTableName($table, $connectionName)))
                );
            }
            $this->cache[$entityKey] = array_values(array_unique($codes));
        }
        return $this->cache[$entityKey];
    }
}
