<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Eav\Model\Attribute\Data\AbstractData;

/**
 * Core validates a saved customer address (account address form, address book update at checkout, order placement) and a saved
 * customer (account information form, registration, admin) and requires every required attribute. A required attribute that a
 * dependency rule or a customer group restriction hides has no value by design (a hidden field is disabled in the browser and
 * not submitted), so it is not required while the entity is validated.
 */
class EavDataRequiredForHiddenAttribute
{
    private const ENTITY_TYPES = ['customer' => EntityDefinitions::CUSTOMER, 'customer_address' => EntityDefinitions::ADDRESS];

    public function __construct(
        private readonly AddressAttributeProvider $addressAttributes,
        private readonly CustomerAttributeProvider $customerAttributes,
        private readonly VisibilityService $visibility,
        private readonly ValueNormalizer $normalizer
    ) {
    }

    /**
     * @param string|array|bool|null $value false when the attribute was not submitted at all (a hidden field is disabled in the
     *                                      browser, so the form does not send it)
     * @return bool|array|string[]
     */
    public function aroundValidateValue(AbstractData $subject, callable $proceed, $value)
    {
        $attribute = $subject->getAttribute();
        $entityKey = self::ENTITY_TYPES[(string) $attribute->getEntityType()->getEntityTypeCode()] ?? null;
        if ($entityKey === null || !$attribute->getIsRequired() || ($value !== null && $value !== '' && $value !== [] && $value !== false)) {
            return $proceed($value);
        }
        $code = (string) $attribute->getAttributeCode();
        $attributes = $entityKey === EntityDefinitions::CUSTOMER
            ? $this->customerAttributes->getUserDefined()
            : $this->addressAttributes->getUserDefined();
        if (!isset($attributes[$code]) || $this->isVisible($subject, $entityKey, $attributes, $code)) {
            return $proceed($value);
        }

        $required = $attribute->getIsRequired();
        $attribute->setIsRequired(false);
        try {
            return $proceed($value);
        } finally {
            $attribute->setIsRequired($required);
        }
    }

    /**
     * @param array<string, \Magento\Eav\Model\Entity\Attribute\AbstractAttribute> $attributes the user-defined attributes of the entity
     */
    private function isVisible(AbstractData $subject, string $entityKey, array $attributes, string $code): bool
    {
        $data = (array) $subject->getExtractedData();
        $entity = $subject->getEntity();
        $groupId = null;
        if (is_object($entity) && method_exists($entity, 'getData')) {
            $data += (array) $entity->getData();
            try {
                // a customer has its own group; an address belongs to a customer
                $customer = $entityKey === EntityDefinitions::CUSTOMER
                    ? $entity
                    : (method_exists($entity, 'getCustomer') ? $entity->getCustomer() : null);
                $groupId = $customer && method_exists($customer, 'getGroupId') && $customer->getGroupId() !== null ? (int) $customer->getGroupId() : null;
            } catch (\Throwable) {
                $groupId = null;
            }
        }

        $values = [];
        foreach ($attributes as $attributeCode => $userDefined) {
            $values[$attributeCode] = $this->normalizer->normalizeForInput((string) $userDefined->getFrontendInput(), $data[$attributeCode] ?? null);
        }
        return in_array($code, $this->visibility->getVisibleCodes($entityKey, array_keys($values), $values, $groupId), true);
    }
}
