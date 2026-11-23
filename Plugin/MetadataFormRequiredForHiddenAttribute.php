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
use Magento\Backend\Model\Session\Quote as AdminQuoteSession;
use Magento\Customer\Model\Metadata\Form\AbstractData;

/**
 * The admin order create form validates the account and address values through Magento\Customer\Model\Metadata\Form, whose
 * data models are not the EAV ones EavDataRequiredForHiddenAttribute relaxes. The same rule applies here: a required attribute
 * that a dependency rule or the order's customer group hides is not required (its value is dropped when the order is placed).
 */
class MetadataFormRequiredForHiddenAttribute
{
    private const ENTITY_TYPES = ['customer' => EntityDefinitions::CUSTOMER, 'customer_address' => EntityDefinitions::ADDRESS];

    public function __construct(
        private readonly AddressAttributeProvider $addressAttributes,
        private readonly CustomerAttributeProvider $customerAttributes,
        private readonly VisibilityService $visibility,
        private readonly ValueNormalizer $normalizer,
        private readonly AdminQuoteSession $quoteSession
    ) {
    }

    /**
     * @param mixed $value
     * @return bool|string[]
     */
    public function aroundValidateValue(AbstractData $subject, callable $proceed, $value)
    {
        $attribute = $subject->getAttribute();
        if (!$attribute->isRequired() || ($value !== null && $value !== '' && $value !== [] && $value !== false)) {
            return $proceed($value);
        }
        $entityKey = self::ENTITY_TYPES[$this->entityTypeCode($subject)] ?? null;
        if ($entityKey === null) {
            return $proceed($value);
        }
        $code = (string) $attribute->getAttributeCode();
        $attributes = $entityKey === EntityDefinitions::CUSTOMER
            ? $this->customerAttributes->getUserDefined()
            : $this->addressAttributes->getUserDefined();
        if (!isset($attributes[$code]) || $this->isVisible($subject, $entityKey, $attributes, $code)) {
            return $proceed($value);
        }

        $attribute->setIsRequired(false);
        try {
            return $proceed($value);
        } finally {
            $attribute->setIsRequired(true);
        }
    }

    /**
     * @param array<string, \Magento\Eav\Model\Entity\Attribute\AbstractAttribute> $attributes the user-defined attributes of the entity
     */
    private function isVisible(AbstractData $subject, string $entityKey, array $attributes, string $code): bool
    {
        $data = (array) $subject->getExtractedData();
        $values = [];
        foreach ($attributes as $attributeCode => $userDefined) {
            $values[$attributeCode] = $this->normalizer->normalizeForInput((string) $userDefined->getFrontendInput(), $data[$attributeCode] ?? null);
        }
        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (int) $data['group_id'] : null;
        if ($groupId === null) {
            try {
                $groupId = (int) $this->quoteSession->getQuote()->getCustomerGroupId();
            } catch (\Throwable) {
                $groupId = null;
            }
        }
        return in_array($code, $this->visibility->getVisibleCodes($entityKey, array_keys($values), $values, $groupId), true);
    }

    /**
     * The data model keeps the entity type ("customer" or "customer_address") in a protected property without a getter.
     */
    private function entityTypeCode(AbstractData $subject): string
    {
        return (string) \Closure::bind(fn () => $this->_entityTypeCode, $subject, AbstractData::class)();
    }
}
