<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Dependency;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Loads the dependency rules and the customer group restrictions of an entity ("customer" or "address") from the
 * database, once per request.
 */
class RuleProvider implements ResetAfterRequestInterface
{
    private const ENTITY_TYPE_CODES = ['customer' => 'customer', 'address' => 'customer_address'];

    /** @var array<string, list<array{parent: string, option: string, dependent: string}>> */
    private array $rules = [];

    /** @var array<string, array<string, int[]>> */
    private array $groups = [];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return list<array{parent: string, option: string, dependent: string}>
     */
    public function getRules(string $entity): array
    {
        if (!isset($this->rules[$entity])) {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(['d' => $this->resource->getTableName('mageos_cca_relation_detail')], ['option' => 'option_value'])
                ->join(['r' => $this->resource->getTableName('mageos_cca_relation')], 'r.relation_id = d.relation_id', [])
                ->join(['p' => $this->resource->getTableName('eav_attribute')], 'p.attribute_id = d.parent_attribute_id', ['parent' => 'attribute_code'])
                ->join(['c' => $this->resource->getTableName('eav_attribute')], 'c.attribute_id = d.dependent_attribute_id', ['dependent' => 'attribute_code'])
                ->where('r.entity_type = ?', $entity);
            $this->rules[$entity] = array_map(
                static fn (array $row): array => [
                    'parent' => (string) $row['parent'],
                    'option' => (string) $row['option'],
                    'dependent' => (string) $row['dependent'],
                ],
                $connection->fetchAll($select)
            );
        }
        return $this->rules[$entity];
    }

    /**
     * @return array<string, int[]> allowed customer group ids by attribute code
     */
    public function getGroupRestrictions(string $entity): array
    {
        if (!isset($this->groups[$entity])) {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(['g' => $this->resource->getTableName('mageos_cca_attribute_group')], ['customer_group_id'])
                ->join(['a' => $this->resource->getTableName('eav_attribute')], 'a.attribute_id = g.attribute_id', ['attribute_code'])
                ->join(['t' => $this->resource->getTableName('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
                ->where('t.entity_type_code = ?', self::ENTITY_TYPE_CODES[$entity] ?? $entity);
            $groups = [];
            foreach ($connection->fetchAll($select) as $row) {
                $groups[(string) $row['attribute_code']][] = (int) $row['customer_group_id'];
            }
            $this->groups[$entity] = $groups;
        }
        return $this->groups[$entity];
    }

    public function _resetState(): void
    {
        $this->rules = [];
        $this->groups = [];
    }
}
