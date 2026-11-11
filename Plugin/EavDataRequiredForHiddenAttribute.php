<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Eav\Model\Attribute\Data\AbstractData;

/**
 * Core validates a saved customer address (account address form, address book update at checkout, order placement) and
 * requires every required attribute. A required attribute that a dependency rule or a customer group restriction hides has
 * no value by design, so it is not required while the address is validated.
 */
class EavDataRequiredForHiddenAttribute
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly VisibilityService $visibility,
        private readonly ValueNormalizer $normalizer
    ) {
    }

    /**
     * @param string|array|bool|null $value false when the attribute was not submitted at all (a hidden field is disabled in the
     *                                      browser, so the account address form does not send it)
     * @return bool|array|string[]
     */
    public function aroundValidateValue(AbstractData $subject, callable $proceed, $value)
    {
        $attribute = $subject->getAttribute();
        $code = (string) $attribute->getAttributeCode();
        $attributes = $this->attributeProvider->getUserDefined();
        if (!isset($attributes[$code])
            || !$attribute->getIsRequired()
            || ($value !== null && $value !== '' && $value !== [] && $value !== false)
            || $this->isVisible($subject, $code)
        ) {
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

    private function isVisible(AbstractData $subject, string $code): bool
    {
        $data = (array) $subject->getExtractedData();
        $entity = $subject->getEntity();
        $groupId = null;
        if (is_object($entity) && method_exists($entity, 'getData')) {
            $data += (array) $entity->getData();
            try {
                $customer = method_exists($entity, 'getCustomer') ? $entity->getCustomer() : null;
                $groupId = $customer && $customer->getGroupId() !== null ? (int) $customer->getGroupId() : null;
            } catch (\Throwable) {
                $groupId = null;
            }
        }

        $values = [];
        foreach ($this->attributeProvider->getUserDefined() as $attributeCode => $attribute) {
            $values[$attributeCode] = $this->normalizer->normalizeForInput((string) $attribute->getFrontendInput(), $data[$attributeCode] ?? null);
        }
        return in_array($code, $this->visibility->getVisibleCodes('address', array_keys($values), $values, $groupId), true);
    }
}
