<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Attribute;

use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use Magento\Customer\Model\ResourceModel\Address\Attribute\CollectionFactory as AddressCollectionFactory;
use Magento\Customer\Model\ResourceModel\Attribute\Collection;
use Magento\Customer\Model\ResourceModel\Attribute\CollectionFactory as CustomerCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\RequestInterface;

/**
 * The attribute area (customer or customer address) the current admin request works on, taken from the `entity`
 * request parameter. Knows its labels, ACL resource, EAV entity type, forms and attribute collection.
 */
class EntityContext
{
    public const REQUEST_PARAM = 'entity';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly EntityDefinitions $definitions,
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly AddressCollectionFactory $addressCollectionFactory,
        private readonly EavConfig $eavConfig
    ) {
    }

    public function getKey(): string
    {
        return $this->definitions->resolveKey((string) $this->request->getParam(self::REQUEST_PARAM));
    }

    public function getEntityTypeCode(): string
    {
        return $this->definitions->get($this->getKey())['eav_entity_type'];
    }

    public function getEntityType(): Type
    {
        return $this->eavConfig->getEntityType($this->getEntityTypeCode());
    }

    public function getLabel(): string
    {
        return (string) __($this->definitions->get($this->getKey())['label']);
    }

    public function getPluralLabel(): string
    {
        return (string) __($this->definitions->get($this->getKey())['plural_label']);
    }

    public function getAclResource(): string
    {
        return $this->definitions->get($this->getKey())['acl'];
    }

    public function getMenuId(): string
    {
        return $this->definitions->get($this->getKey())['menu'];
    }

    public function getMaxCodeLength(): int
    {
        return $this->definitions->get($this->getKey())['max_code_length'];
    }

    /**
     * @return array<string, string> form code => translated label
     */
    public function getOptionalForms(): array
    {
        $forms = [];
        foreach ($this->definitions->get($this->getKey())['optional_forms'] as $code => $label) {
            $forms[$code] = (string) __($label);
        }
        return $forms;
    }

    /**
     * @param string[] $selected
     * @param string[] $stored
     * @return string[]
     */
    public function resolveForms(array $selected, array $stored = []): array
    {
        return $this->definitions->resolveForms($this->getKey(), $selected, $stored);
    }

    public function createCollection(): Collection
    {
        return $this->getKey() === EntityDefinitions::ADDRESS
            ? $this->addressCollectionFactory->create()
            : $this->customerCollectionFactory->create();
    }
}
