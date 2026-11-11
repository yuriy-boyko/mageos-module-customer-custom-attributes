<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Dependency relations: a name and entity ("customer" or "address") with detail rows (parent attribute, option, dependent
 * attribute). Attributes are exchanged as codes.
 */
class RelationStorage
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return list<array{relation_id: int, name: string, entity_type: string, rule_count: int}>
     */
    public function getAll(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['r' => $this->resource->getTableName('mageos_cca_relation')], ['relation_id', 'name', 'entity_type'])
            ->joinLeft(['d' => $this->resource->getTableName('mageos_cca_relation_detail')], 'd.relation_id = r.relation_id', ['rule_count' => 'COUNT(d.detail_id)'])
            ->group('r.relation_id')
            ->order(['r.entity_type ASC', 'r.name ASC']);
        return array_map(static fn (array $row): array => [
            'relation_id' => (int) $row['relation_id'],
            'name' => (string) $row['name'],
            'entity_type' => (string) $row['entity_type'],
            'rule_count' => (int) $row['rule_count'],
        ], $connection->fetchAll($select));
    }

    /**
     * @return array{relation_id: int, name: string, entity_type: string, rules: list<array{parent: string, option: string, dependent: string}>}|null
     */
    public function load(int $relationId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName('mageos_cca_relation'))->where('relation_id = ?', $relationId)
        );
        if (!$row) {
            return null;
        }
        return [
            'relation_id' => (int) $row['relation_id'],
            'name' => (string) $row['name'],
            'entity_type' => (string) $row['entity_type'],
            'rules' => $this->loadRules($relationId),
        ];
    }

    /**
     * Rules of all relations of an entity, optionally without one relation (the one being edited).
     *
     * @return list<array{parent: string, option: string, dependent: string}>
     */
    public function loadRules(?int $relationId = null, ?string $entity = null, ?int $exceptRelationId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['d' => $this->resource->getTableName('mageos_cca_relation_detail')], ['option' => 'option_value'])
            ->join(['r' => $this->resource->getTableName('mageos_cca_relation')], 'r.relation_id = d.relation_id', [])
            ->join(['p' => $this->resource->getTableName('eav_attribute')], 'p.attribute_id = d.parent_attribute_id', ['parent' => 'attribute_code'])
            ->join(['c' => $this->resource->getTableName('eav_attribute')], 'c.attribute_id = d.dependent_attribute_id', ['dependent' => 'attribute_code'])
            ->order('d.detail_id ASC');
        if ($relationId !== null) {
            $select->where('d.relation_id = ?', $relationId);
        }
        if ($entity !== null) {
            $select->where('r.entity_type = ?', $entity);
        }
        if ($exceptRelationId !== null) {
            $select->where('d.relation_id <> ?', $exceptRelationId);
        }
        return array_map(static fn (array $row): array => [
            'parent' => (string) $row['parent'],
            'option' => (string) $row['option'],
            'dependent' => (string) $row['dependent'],
        ], $connection->fetchAll($select));
    }

    /**
     * @param list<array{parent: string, option: string, dependent: string}> $rules
     * @param array<string, int> $attributeIds attribute id by code for every attribute used in the rules
     * @return int the relation id
     */
    public function save(?int $relationId, string $name, string $entity, array $rules, array $attributeIds): int
    {
        $connection = $this->resource->getConnection();
        $relationTable = $this->resource->getTableName('mageos_cca_relation');
        $detailTable = $this->resource->getTableName('mageos_cca_relation_detail');

        $connection->beginTransaction();
        try {
            if ($relationId) {
                $connection->update($relationTable, ['name' => $name], ['relation_id = ?' => $relationId]);
                $connection->delete($detailTable, ['relation_id = ?' => $relationId]);
            } else {
                $connection->insert($relationTable, ['name' => $name, 'entity_type' => $entity]);
                $relationId = (int) $connection->lastInsertId($relationTable);
            }
            foreach ($rules as $rule) {
                $connection->insert($detailTable, [
                    'relation_id' => $relationId,
                    'parent_attribute_id' => $attributeIds[$rule['parent']],
                    'option_value' => $rule['option'],
                    'dependent_attribute_id' => $attributeIds[$rule['dependent']],
                ]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return (int) $relationId;
    }

    public function delete(int $relationId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName('mageos_cca_relation'),
            ['relation_id = ?' => $relationId]
        );
    }
}
