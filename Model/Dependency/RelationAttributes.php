<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Dependency;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * The attributes of an entity that can take part in a relation: every user-defined attribute may be a dependent, the
 * dropdown, multiple select and yes/no ones may be parents (with their options).
 */
class RelationAttributes
{
    private const PARENT_INPUTS = ['select', 'multiselect', 'boolean'];

    public function __construct(
        private readonly AddressAttributeProvider $addressAttributes,
        private readonly CustomerAttributeProvider $customerAttributes,
        private readonly OptionLabels $optionLabels
    ) {
    }

    /**
     * @return array<string, AbstractAttribute> by code
     */
    public function getAttributes(string $entity): array
    {
        return match ($entity) {
            'address' => $this->addressAttributes->getUserDefined(),
            'customer' => $this->customerAttributes->getUserDefined(),
            default => [],
        };
    }

    /**
     * @return array<string, string[]> option values by code of the attributes that may be parents
     */
    public function getParentOptions(string $entity): array
    {
        $result = [];
        foreach ($this->getAttributes($entity) as $code => $attribute) {
            if (in_array($attribute->getFrontendInput(), self::PARENT_INPUTS, true)) {
                $result[$code] = array_map('strval', array_keys($this->optionLabels->forAttribute($attribute)));
            }
        }
        return $result;
    }

    /**
     * @return array<string, int> attribute id by code
     */
    public function getIds(string $entity): array
    {
        $ids = [];
        foreach ($this->getAttributes($entity) as $code => $attribute) {
            $ids[$code] = (int) $attribute->getAttributeId();
        }
        return $ids;
    }

    /**
     * Data for the admin form script.
     *
     * @return array<string, list<array{code: string, label: string, parent: bool, options: list<array{value: string, label: string}>}>>
     */
    public function getFormData(): array
    {
        $data = [];
        foreach (['address', 'customer'] as $entity) {
            $data[$entity] = [];
            foreach ($this->getAttributes($entity) as $code => $attribute) {
                $isParent = in_array($attribute->getFrontendInput(), self::PARENT_INPUTS, true);
                $options = [];
                if ($isParent) {
                    foreach ($this->optionLabels->forAttribute($attribute) as $value => $label) {
                        $options[] = ['value' => (string) $value, 'label' => $label];
                    }
                }
                $data[$entity][] = [
                    'code' => $code,
                    'label' => sprintf('%s (%s)', $attribute->getStoreLabel() ?: $code, $code),
                    'parent' => $isParent,
                    'options' => $options,
                ];
            }
        }
        return $data;
    }
}
