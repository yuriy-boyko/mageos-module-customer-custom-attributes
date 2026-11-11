<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Relation;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Relation\Edit as EditController;
use MageOS\CustomerCustomAttributes\Model\Dependency\RelationAttributes;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\RelationStorage;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * Data for the relation list and edit templates.
 */
class Relation extends Template
{
    public function __construct(
        Context $context,
        private readonly RelationStorage $storage,
        private readonly RelationAttributes $attributes,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<array{relation_id: int, name: string, entity_type: string, rule_count: int}>
     */
    public function getRelations(): array
    {
        return $this->storage->getAll();
    }

    /**
     * @return array{relation_id: int, name: string, entity_type: string, rules: list<array{parent: string, option: string, dependent: string}>}
     */
    public function getRelation(): array
    {
        return (array) $this->registry->registry(EditController::REGISTRY_KEY);
    }

    public function getAttributeDataJson(): string
    {
        return (string) json_encode($this->attributes->getFormData(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public function getEntityLabel(string $entity): string
    {
        return $entity === 'customer' ? (string) __('Customer') : (string) __('Customer Address');
    }
}
